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
use JsonException;
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

    /**
     * @param  array<string, mixed>|null  $marker  the decoded storage/app/skv-installed, or null before setup finished (fromSetup() synthesises one)
     * @param  bool  $runtime  true only from withoutDetection(): setting() then reads the running configuration (CD-5)
     */
    public function __construct(
        public readonly string $basePath,
        public readonly Toolchain $toolchain,
        public readonly Detection $detection,
        public readonly ?array $marker,
        public readonly bool $runtime = false,
    ) {}

    /**
     * The context of the running application, or of the project at $basePath (tests pass a sandbox, L-10): a fresh
     * detection and its marker, which is null when it is absent or unreadable (A8).
     */
    public static function forApplication(Toolchain $toolchain, Detector $detector, ?string $basePath = null): self
    {
        $basePath ??= base_path();
        $detection = $detector->detect();
        $setup = new SetupContext($basePath, Answers::defaults($detection, basename($basePath)), $detection);

        return new self($basePath, $toolchain, $detection, self::readableMarker($setup));
    }

    /** The setup's context; before the marker exists, the marker-gated checks read the answers being installed (RL-8). */
    public static function fromSetup(SetupContext $context, Toolchain $toolchain): self
    {
        $marker = self::readableMarker($context) ?? ['answers' => $context->answers->toPublicArray(), 'start_with' => $context->answers->startWith];

        return new self($context->basePath, $toolchain, $context->detection, $marker);
    }

    /**
     * The running application without a detection and without a marker (RL-2): for web requests and console
     * listeners, because forApplication() starts herd processes and probes PostgreSQL. The checks that read the
     * detection or the marker are not run through it. It is a runtime context (CD-5): setting() reads the running
     * configuration, as the request or command that builds it runs with it.
     */
    public static function withoutDetection(?string $basePath = null): self
    {
        return new self($basePath ?? base_path(), app(Toolchain::class), Detection::none(), null, runtime: true);
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

    /**
     * A setting as the checks judge it (CD-5, F10): in a runtime context (withoutDetection(): web requests and
     * console listeners) the running configuration at $configKey, otherwise the project's .env at $envKey, because
     * skv:doctor and setup judge the file they are about to write. Null when it is unset, blank, or neither a string
     * nor a number: env() casts HEALTH_CHECK_KEY=true to bool true, which is no value here.
     */
    public function setting(string $envKey, string $configKey): ?string
    {
        $value = $this->runtime ? config($configKey) : $this->env()->get($envKey);

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
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

    /**
     * Whether $connection (null or '' is the default one) is the default connection and that does not open (F2):
     * the queue and failed-jobs checks then skip, and only the database check reports the outage.
     */
    public function defaultConnectionDown(?string $connection): bool
    {
        return in_array($connection, [null, '', DB::getDefaultConnection()], true) && ! $this->databaseReachable();
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

    /**
     * The marker, or null when it is absent or unreadable; the `marker` check reports an unreadable one (A8).
     *
     * @return array<string, mixed>|null
     */
    private static function readableMarker(SetupContext $context): ?array
    {
        try {
            return Marker::read($context);
        } catch (JsonException) {
            return null;
        }
    }
}
