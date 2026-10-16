<?php

declare(strict_types=1);

namespace App\Setup\Wizard;

use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * nohup + & (DEC-23): the child outlives the request and Herd's nginx/php-fpm restarts. Every value is its own
 * argument ("$1"…), never part of the script, so no path or key reaches the shell as code. The child gets the
 * toolchain's environment, so it reads the current .env itself (RL-12). The inner shell writes its own pid into the
 * pid file first (RL-8) and stays until it has written the exit code, so that pid lives exactly as long as the step.
 */
final readonly class DetachedStepLauncher implements StepLauncher
{
    /**
     * Outer: $1 php, $2 key, $3 result, $4 log, $5 exit, $6 pid; inner: $0 php, $1 key, $2 result, $3 log, $4 exit,
     * $5 pid. The inner script starts with umask 077, so the redirects and `echo $? >` create the pid, log and exit
     * files 0600 (g3-16).
     */
    private const string SCRIPT = 'nohup sh -c \'umask 077; echo $$ > "$5"; "$0" artisan skv:run-step "$1" --no-interaction --result="$2" > "$3" 2>&1; echo $? > "$4"\' "$1" "$2" "$3" "$4" "$5" "$6" > /dev/null 2>&1 &';

    public function __construct(private Toolchain $toolchain, private ?string $php = null) {}

    public function start(string $basePath, string $key, string $resultFile, string $logFile, string $exitFile, ?string $pidFile = null): bool
    {
        $php = $this->php ?? $this->toolchain->phpCli();

        if ($php === null) {
            return false;
        }

        @mkdir(dirname($logFile), 0700, true);

        try {
            return Process::path($basePath)->env($this->toolchain->environment())->timeout(10)
                ->run(['sh', '-c', self::SCRIPT, 'sh', $php, $key, $resultFile, $logFile, $exitFile, $pidFile ?? '/dev/null'])
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
