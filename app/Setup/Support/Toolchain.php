<?php

declare(strict_types=1);

namespace App\Setup\Support;

/**
 * The environment every setup child process gets (DEC-23): php-fpm under Herd has no PATH, so HOME and PATH are
 * built here and herd is always called by its absolute path. Lookups run in-process, never through a shell.
 */
final readonly class Toolchain
{
    private const string SYSTEM_PATH = '/usr/local/bin:/opt/homebrew/bin:/usr/bin:/bin:/usr/sbin:/sbin';

    /** @param  string|null  $home  the home directory to build from; null detects it (RL-3) */
    public function __construct(private ?string $home = null) {}

    /** HOME from the argument, the environment or the password database, in that order (RL-3). */
    public function home(): string
    {
        if ($this->home !== null) {
            return $this->home;
        }

        $home = getenv('HOME');

        if (is_string($home) && $home !== '') {
            return $home;
        }

        $user = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;

        return is_array($user) ? (string) $user['dir'] : '/tmp';
    }

    /**
     * The environment for child processes: the Herd bin dir, the newest Herd node, ~/.local/bin, then the system PATH.
     * SHELL_VERBOSITY is removed (false), because Symfony Console exports it for --quiet and -v, and Symfony Console
     * children such as herd would otherwise inherit it and print nothing.
     *
     * @return array{HOME: string, PATH: string, SHELL_VERBOSITY: false}
     */
    public function environment(): array
    {
        $directories = array_filter([
            $this->herdBinDirectory(),
            $this->nodeBinDirectory(),
            $this->home().'/.local/bin',
        ]);

        return ['HOME' => $this->home(), 'PATH' => implode(':', [...$directories, self::SYSTEM_PATH]), 'SHELL_VERBOSITY' => false];
    }

    /** The absolute path of the herd binary, or null when it is not installed. */
    public function herd(): ?string
    {
        $herd = $this->herdBinDirectory().'/herd';

        return is_file($herd) && is_executable($herd) ? $herd : null;
    }

    /** The first executable named $binary on environment()['PATH'], or null. */
    public function which(string $binary): ?string
    {
        foreach (explode(':', $this->environment()['PATH']) as $directory) {
            $candidate = $directory.'/'.$binary;

            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** The bin directory of the newest node Herd installed through nvm, or null. */
    public function nodeBinDirectory(): ?string
    {
        $versions = glob($this->home().'/Library/Application Support/Herd/config/nvm/versions/node/v*/bin', GLOB_ONLYDIR) ?: [];

        usort($versions, fn (string $a, string $b): int => version_compare(
            ltrim(basename(dirname($b)), 'v'),
            ltrim(basename(dirname($a)), 'v'),
        ));

        return $versions[0] ?? null;
    }

    public function herdConfigPath(): string
    {
        return $this->home().'/Library/Application Support/Herd/config/herd.json';
    }

    /** dirname(PHP_BINARY) when PHP is Herd's own under home(), else home()'s Herd bin directory (RL-3). */
    private function herdBinDirectory(): string
    {
        $phpDirectory = dirname(PHP_BINARY);

        return str_ends_with($phpDirectory, '/Herd/bin') && str_starts_with($phpDirectory, $this->home().'/')
            ? $phpDirectory
            : $this->home().'/Library/Application Support/Herd/bin';
    }
}
