<?php

declare(strict_types=1);

namespace App\Setup\Doctor;

use App\Authorization\SyncResult;
use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\Detector;
use App\Setup\Marker;
use App\Setup\SetupContext;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * What the doctor examines (§5.4): the project's base path, the toolchain for child processes, the detection and
 * the marker. Files resolve through path(), never base_path(), so tests and setup step 8 point it at any project.
 * The database probe and the dry-run sync are cached until forgetSync(), because several checks read them.
 */
final class DoctorContext
{
    private ?SyncResult $sync = null;

    private ?bool $databaseReachable = null;

    /** @param  array<string, mixed>|null  $marker  the decoded storage/app/skv-installed, or null before setup finished (fromSetup() synthesises one) */
    public function __construct(
        public readonly string $basePath,
        public readonly Toolchain $toolchain,
        public readonly Detection $detection,
        public readonly ?array $marker,
    ) {}

    /** The context of the running application: base_path(), a fresh detection and its marker. */
    public static function forApplication(Toolchain $toolchain, Detector $detector): self
    {
        $detection = $detector->detect();
        $setup = SetupContext::forApplication(Answers::defaults($detection, basename(base_path())), $detection);

        return new self(base_path(), $toolchain, $detection, Marker::read($setup));
    }

    /** The setup's context; before the marker exists, the marker-gated checks read the answers being installed (RL-8). */
    public static function fromSetup(SetupContext $context, Toolchain $toolchain): self
    {
        $marker = Marker::read($context) ?? ['answers' => $context->answers->toPublicArray(), 'start_with' => $context->answers->startWith];

        return new self($context->basePath, $toolchain, $context->detection, $marker);
    }

    /**
     * The running application without a detection and without a marker (RL-2): for web requests and console
     * listeners, because forApplication() starts herd processes and probes PostgreSQL. The checks that read the
     * detection or the marker are not run through it.
     */
    public static function withoutDetection(?string $basePath = null): self
    {
        return new self($basePath ?? base_path(), app(Toolchain::class), Detection::none(), null);
    }

    public function path(string $relative = ''): string
    {
        return rtrim($this->basePath, '/').($relative === '' ? '' : '/'.ltrim($relative, '/'));
    }

    public function folderName(): string
    {
        return basename($this->basePath);
    }

    public function env(): EnvFile
    {
        return new EnvFile($this->path('.env'));
    }

    /** A marker answer (§5.3.2 keys), or null without a marker or without that answer. */
    public function answer(string $key): mixed
    {
        $answers = $this->marker['answers'] ?? null;

        return is_array($answers) ? ($answers[$key] ?? null) : null;
    }

    public function sync(): SyncResult
    {
        return $this->sync ??= app(DryRunSync::class)->run();
    }

    /** Drops the cached dry-run sync and the cached database probe, so the next read sees repairs. */
    public function forgetSync(): void
    {
        $this->sync = null;
        $this->databaseReachable = null;
    }

    /** Whether the default connection opens (cached until forgetSync()). */
    public function databaseReachable(): bool
    {
        if ($this->databaseReachable === null) {
            try {
                DB::connection()->getPdo();
                $this->databaseReachable = true;
            } catch (Throwable) {
                $this->databaseReachable = false;
            }
        }

        return $this->databaseReachable;
    }

    /** This project as a SetupContext with default answers, so a check can reuse a setup step's own logic. */
    public function setupContext(): SetupContext
    {
        return new SetupContext($this->basePath, Answers::defaults($this->detection, $this->folderName()), $this->detection);
    }

    /** A child process in the base path with the toolchain's HOME and PATH (DEC-23). */
    public function process(int $timeout = 30): PendingProcess
    {
        return Process::path($this->basePath)->env($this->toolchain->environment())->timeout($timeout);
    }
}
