<?php

declare(strict_types=1);

namespace App\Setup\Steps;

use App\Setup\SetupContext;
use App\Setup\SetupStep;
use App\Setup\StepResult;
use App\Setup\Support\Toolchain;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * §5.3.3 step 5 (long): `npm ci && npm run build` when Node satisfies ^20.19 || >=22.12 and the Vite manifest is
 * missing or stale, then `npx playwright install chromium` when Playwright was chosen. A missing or old Node, or a
 * failed command, returns the command as `manual()` (§5.3.1).
 */
final readonly class FrontendStep implements SetupStep
{
    private const string BUILD_COMMAND = 'npm ci && npm run build';

    private const string PLAYWRIGHT_COMMAND = 'npx playwright install chromium';

    private Toolchain $toolchain;

    public function __construct(?Toolchain $toolchain = null)
    {
        $this->toolchain = $toolchain ?? app(Toolchain::class);
    }

    /** Whether `node -v` output satisfies Vite's engine range ^20.19 || >=22.12. */
    public static function nodeSatisfies(string $version): bool
    {
        $v = ltrim(trim($version), 'v');

        return (version_compare($v, '20.19.0', '>=') && version_compare($v, '21.0.0', '<')) || version_compare($v, '22.12.0', '>=');
    }

    public function key(): string
    {
        return 'frontend';
    }

    public function label(): string
    {
        return __('Frontend');
    }

    public function isComplete(SetupContext $context): bool
    {
        return $this->manifestIsCurrent($context)
            && (! $context->answers->playwright || is_file($context->path('node_modules/.bin/playwright')));
    }

    public function isLong(): bool
    {
        return true;
    }

    public function run(SetupContext $context): StepResult
    {
        $node = $this->toolchain->which('node');

        if ($node === null || ! self::nodeSatisfies(trim($this->process($context, 10)->run([$node, '-v'])->output()))) {
            return StepResult::manual(self::BUILD_COMMAND, __('Node ^20.19 or >=22.12 is needed to build the assets.'));
        }

        if (getenv('LARAVEL_INSTALLER_NO_NODE') !== false && getenv('LARAVEL_INSTALLER_NO_NODE') !== '') {
            return StepResult::skipped(__('Skipped: the installer was asked not to use Node.'));
        }

        $npm = $this->toolchain->which('npm') ?? 'npm';

        if (! $this->manifestIsCurrent($context)) {
            foreach ([[$npm, 'ci'], [$npm, 'run', 'build']] as $command) {
                $failure = $this->failure($context, $command);

                if ($failure !== null) {
                    return StepResult::manual(self::BUILD_COMMAND, $failure);
                }
            }
        }

        if ($context->answers->playwright) {
            $failure = $this->failure($context, [$this->toolchain->which('npx') ?? 'npx', 'playwright', 'install', 'chromium']);

            if ($failure !== null) {
                return StepResult::manual(self::PLAYWRIGHT_COMMAND, $failure);
            }
        }

        return StepResult::done();
    }

    /**
     * Runs $command in the base path and returns why it failed (the last line it printed), or null on success.
     *
     * @param  list<string>  $command
     */
    private function failure(SetupContext $context, array $command): ?string
    {
        try {
            $result = $this->process($context, 900)->run($command);
        } catch (ProcessTimedOutException) {
            return __('The command did not finish within :seconds seconds.', ['seconds' => 900]);
        }

        return $result->successful() ? null : $this->lastLine($result);
    }

    /** The last non-empty line the command printed (build output holds no secrets), or its exit code. */
    private function lastLine(ProcessResult $result): string
    {
        $lines = array_filter(array_map(trim(...), preg_split('/\R/', $result->output()."\n".$result->errorOutput()) ?: []));

        return $lines === [] ? __('The command failed (exit code :code).', ['code' => $result->exitCode()]) : end($lines);
    }

    /** The manifest exists and is newer than every file under resources/css and resources/js, and package-lock.json. */
    private function manifestIsCurrent(SetupContext $context): bool
    {
        $manifest = $context->path('public/build/manifest.json');

        if (! is_file($manifest)) {
            return false;
        }

        $sources = is_file($context->path('package-lock.json')) ? [$context->path('package-lock.json')] : [];

        foreach (['resources/css', 'resources/js'] as $directory) {
            if (is_dir($context->path($directory))) {
                foreach (File::allFiles($context->path($directory)) as $file) {
                    $sources[] = $file->getPathname();
                }
            }
        }

        $builtAt = (int) filemtime($manifest);

        return array_all($sources, fn (string $source): bool => (int) filemtime($source) <= $builtAt);
    }

    private function process(SetupContext $context, int $timeout): PendingProcess
    {
        return Process::path($context->basePath)->env($this->toolchain->environment())->timeout($timeout);
    }
}
