<?php

declare(strict_types=1);

namespace App\Setup\Doctor;

/**
 * Finds a PostgreSQL client binary for the `pg-dump` and `schema-tools` checks and for backup:run (§5.4, CD-6):
 * PG_DUMP_BINARY_PATH from `.env` first. Then, while the pgsql connection points at this machine (127.0.0.1,
 * localhost or ::1), Herd's newest PostgreSQL service before the toolchain PATH (DEC-23), because Herd's server is
 * then the one being dumped and an older PATH client refuses a newer server; otherwise the PATH first. A Herd
 * candidate counts only when it and every directory up to and including Herd's root (/Users/Shared/Herd, which sits
 * in the world-writable /Users/Shared) is no symlink, belongs to this user or root and is neither group- nor
 * world-writable, so no other account can plant or later swap the pg_dump that backup:run executes; it is returned
 * resolved, so no link above the root can swap it either. No version probe: the System page starts no process.
 */
final readonly class PostgresBinaries
{
    public const string HERD_SERVICES = '/Users/Shared/Herd/services/postgresql';

    /** @var list<string> the hosts that mean "this machine" (CD-6) */
    private const array LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1'];

    /**
     * @param  string  $herdServices  where Herd keeps its PostgreSQL versions, two levels below Herd's root; tests pass a sandbox
     * @param  int|null  $userId  the account a Herd candidate may belong to besides root; null is the running process's (tests pass another)
     */
    public function __construct(
        private string $herdServices = self::HERD_SERVICES,
        private ?int $userId = null,
    ) {}

    /** The absolute path of an executable $binary, or null. */
    public function find(DoctorContext $context, string $binary): ?string
    {
        $configured = $context->env()->get('PG_DUMP_BINARY_PATH') ?? '';
        $onPath = $context->toolchain->which($binary);
        $herd = $this->herdBinaries($binary);

        $candidates = [
            ...($configured !== '' ? [rtrim($configured, '/').'/'.$binary] : []),
            ...($this->serverIsLocal() ? [...$herd, $onPath] : [$onPath, ...$herd]),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return list<string> the resolved path of Herd's trusted $binary of each service version, newest first */
    private function herdBinaries(string $binary): array
    {
        $versions = array_values(array_filter(glob($this->herdServices.'/*/bin/'.$binary) ?: [], $this->isTrusted(...)));
        usort($versions, fn (string $a, string $b): int => version_compare(basename(dirname($b, 2)), basename(dirname($a, 2))));

        return array_values(array_filter(array_map(realpath(...), $versions), is_string(...)));
    }

    /** Whether the pgsql connection's host (the DB_URL host when DB_URL is set) is this machine. */
    private function serverIsLocal(): bool
    {
        $url = config('database.connections.pgsql.url');
        $host = is_string($url) && $url !== '' ? parse_url($url, PHP_URL_HOST) : config('database.connections.pgsql.host');

        return is_string($host) && in_array(strtolower(trim($host, '[]')), self::LOCAL_HOSTS, true);
    }

    /**
     * Whether $candidate sits inside Herd's root, and it and every directory up to and including that root is no
     * symlink, belongs to this user or root and only its owner can write it (CD-6, F13). The path is walked as
     * found, not resolved: a link anywhere below the root, or the root itself, could be retargeted after the check.
     */
    private function isTrusted(string $candidate): bool
    {
        $root = dirname($this->herdServices, 2);

        if (! str_starts_with($candidate, $root.'/')) {
            return false;
        }

        $owners = [0, $this->userId ?? $this->processUserId()];
        $path = $candidate;

        while (true) {
            if (is_link($path)) {
                return false;
            }

            $owner = fileowner($path);
            $mode = fileperms($path);

            if ($owner === false || $mode === false || ! in_array($owner, $owners, true) || ($mode & 0022) !== 0) {
                return false;
            }

            if ($path === $root) {
                return true;
            }

            if ($path === dirname($path)) {
                return false;
            }

            $path = dirname($path);
        }
    }

    private function processUserId(): int
    {
        if (function_exists('posix_geteuid')) {
            return posix_geteuid();
        }

        $owner = getmyuid();

        return $owner === false ? -1 : $owner;
    }
}
