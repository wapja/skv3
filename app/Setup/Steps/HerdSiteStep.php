<?php

declare(strict_types=1);

namespace App\Setup\Steps;

use App\Setup\Marker;
use App\Setup\SetupContext;
use App\Setup\SetupStep;
use App\Setup\StepResult;
use App\Setup\Summary;
use App\Setup\Support\EnvFile;
use App\Setup\Support\ShellArgument;
use App\Setup\Support\Toolchain;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * §5.3.3 step 10: serves the project through Herd. It links the folder unless a parked directory already serves it,
 * isolates PHP 8.4 when the site would run an older PHP, and secures it when HTTPS was chosen (§6.8). Herd's exit
 * codes are unreliable (§13), so every change is verified by reading Herd's own listings back; what cannot be
 * verified is returned as `manual()`. A name another Herd site already uses (a link to another path, or another
 * parked directory holding the folder name) is never linked, isolated or secured. A secured site's result message
 * starts with SECURED, which the setup runner reads for the HTTPS consequence (RL-7, OD-2). APP_URL gets the name it
 * secured, {folder}.{tld}, never the request host (RL-16).
 */
final readonly class HerdSiteStep implements SetupStep
{
    /** The prefix of the result message when the site was secured (RL-7). */
    public const string SECURED = 'secured';

    /** The PHP version the kit needs (composer.json: php ^8.4). */
    private const string PHP_VERSION = '8.4';

    private const int TIMEOUT = 120;

    /** This project serves {name}.{tld}. */
    private const string SERVED = 'served';

    /** No Herd site uses the name yet. */
    private const string FREE = 'free';

    /** Another directory serves (or would serve) {name}.{tld}. */
    private const string TAKEN = 'taken';

    private Toolchain $toolchain;

    public function __construct(?Toolchain $toolchain = null)
    {
        $this->toolchain = $toolchain ?? app(Toolchain::class);
    }

    public function key(): string
    {
        return 'herd-site';
    }

    public function label(): string
    {
        return __('Herd site');
    }

    /**
     * Served (parked or linked) and, when HTTPS was chosen, secured with APP_URL on that https address (RL-16): a site
     * secured by hand, or by a detached run that died before writing APP_URL, still needs run()'s rewrite (g1-08).
     * False on any process failure.
     */
    public function isComplete(SetupContext $context): bool
    {
        $herd = $this->toolchain->herd();

        if (! $context->detection->herd || $herd === null) {
            return false;
        }

        try {
            return $this->siteState($context, $herd) === self::SERVED
                && (! $context->answers->https || ($this->appUrlIsSecured($context) && $this->isSecured($context, $herd)));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether Herd serves this project: parked, or linked to this path (the doctor's `herd-site` check). False without
     * the herd binary; a Herd command that hangs throws ProcessTimedOutException.
     */
    public function servesProject(SetupContext $context): bool
    {
        $herd = $this->toolchain->herd();

        return $herd !== null && $this->siteState($context, $herd) === self::SERVED;
    }

    /** Whether `herd secured` lists this project's site (the doctor's `herd-secured` check); false without the herd binary. */
    public function securesProject(SetupContext $context): bool
    {
        $herd = $this->toolchain->herd();

        return $herd !== null && $this->isSecured($context, $herd);
    }

    public function isLong(): bool
    {
        return false;
    }

    public function run(SetupContext $context): StepResult
    {
        if (! $context->detection->herd) {
            return StepResult::skipped(__('Herd is not installed'));
        }

        $herd = $this->toolchain->herd();

        if ($herd === null) {
            return StepResult::manual('herd link', __('The herd command was not found.'));
        }

        $name = $context->folderName();
        $linked = false;
        $pending = 'herd link '.ShellArgument::quote($name);

        try {
            $state = $this->siteState($context, $herd);

            if ($state === self::TAKEN) {
                return StepResult::manual('herd links', __('The name :name is already used by another Herd site; rename this folder.', ['name' => $name]));
            }

            if ($state === self::FREE) {
                // The exit code says nothing (§13): the link counts once `herd links` lists it with this path.
                $this->herd($context, $herd, ['link', $name]);

                if ($this->siteState($context, $herd) !== self::SERVED) {
                    return StepResult::manual($pending, __('Herd could not link the site.'));
                }

                $linked = true;
            }

            $pending = $this->isolateCommand($name);

            if (! $this->runsSupportedPhp($context, $herd)) {
                $this->herd($context, $herd, ['isolate', self::PHP_VERSION, '--site='.$name]);

                if (! $this->runsSupportedPhp($context, $herd)) {
                    return StepResult::manual($pending, $this->withLink(__('Herd did not confirm PHP 8.4 for this site.'), $linked, $name));
                }
            }

            if (! $context->answers->https) {
                return StepResult::done($this->withLink(__('Site served by Herd'), $linked, $name));
            }

            $pending = 'herd secure '.ShellArgument::quote($name);

            if (! $this->isSecured($context, $herd)) {
                $this->herd($context, $herd, ['secure', $name]);

                if (! $this->isSecured($context, $herd)) {
                    return StepResult::manual($pending, $this->withLink(__('Herd did not confirm the certificate.'), $linked, $name));
                }
            }
        } catch (ProcessTimedOutException) {
            return StepResult::manual($pending, __('The command did not finish within :seconds seconds.', ['seconds' => self::TIMEOUT]));
        }

        return $this->secured($context, $this->withLink(__('Site secured with HTTPS'), $linked, $name));
    }

    /**
     * The result run() gives when nothing is left to do: Herd already serves the project on PHP 8.4 or newer and, when
     * HTTPS was chosen, `herd secured` lists it (secured by hand, or by a detached step that did not live to write its
     * result). A secured site gets its APP_URL and marker like run(). Null when Herd confirms less (L7b-16: also a site
     * still on an older PHP), or a listing fails. Only Herd's read-only listings and the PHP version probe run, never
     * link, isolate or secure, so a web request may call it (§5.3.4, RL-6, DEC-23).
     */
    public function confirm(SetupContext $context): ?StepResult
    {
        $herd = $this->toolchain->herd();

        if (! $context->detection->herd || $herd === null) {
            return null;
        }

        try {
            if ($this->siteState($context, $herd) !== self::SERVED || ! $this->runsSupportedPhp($context, $herd)) {
                return null;
            }

            if (! $context->answers->https) {
                return StepResult::done(__('Site served by Herd'));
            }

            if (! $this->isSecured($context, $herd)) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return $this->secured($context, __('Site secured with HTTPS'));
    }

    /**
     * The one Herd command the user still has to run for this project (RL-7), in run()'s order: `herd link` while Herd
     * does not serve it, `herd isolate 8.4 --site=…` while the site runs an older PHP (L7b-16), `herd secure` when
     * HTTPS was chosen, and null without Herd or with nothing left to do, so a caller that finds none may settle the
     * step as skipped (L7b-18). A site listing that fails counts as unknown, so the command HTTPS asks for is named; a
     * PHP check that fails names `herd isolate`, which is harmless to repeat. Only Herd's read-only listings and the
     * PHP version probe run, so the wizard, the Herd stage and skv:run-step may call it from a web request (DEC-23).
     */
    public function manualCommand(SetupContext $context): ?string
    {
        $herd = $this->toolchain->herd();

        if (! $context->detection->herd || $herd === null) {
            return null;
        }

        $name = $context->folderName();
        $quoted = ShellArgument::quote($name);

        try {
            $served = $this->siteState($context, $herd) === self::SERVED;
        } catch (Throwable) {
            return $context->answers->https ? 'herd secure '.$quoted : 'herd link '.$quoted;
        }

        if (! $served) {
            return 'herd link '.$quoted;
        }

        try {
            $supported = $this->runsSupportedPhp($context, $herd);
        } catch (Throwable) {
            $supported = false;
        }

        if (! $supported) {
            return $this->isolateCommand($name);
        }

        return $context->answers->https ? 'herd secure '.$quoted : null;
    }

    /**
     * Who serves {name}.{tld}. A link wins over parked directories, so a `herd links` row for the name decides by its
     * path. Otherwise the project is served when its parent is parked, unless another parked directory also holds a
     * folder of that name; without either, the name is free.
     *
     * @phpstan-impure it asks Herd every time
     *
     * @return self::SERVED|self::FREE|self::TAKEN
     */
    private function siteState(SetupContext $context, string $herd): string
    {
        $resolved = realpath($context->basePath) ?: $context->basePath;
        $project = $this->normalise($resolved);
        $parent = $this->normalise(dirname($resolved));
        $name = $context->folderName();
        $linkPath = $this->linkPath($this->herd($context, $herd, ['links'])->output(), $name);

        if ($linkPath !== null) {
            return $linkPath !== '' && $this->normalise($linkPath) === $project ? self::SERVED : self::TAKEN;
        }

        $parked = false;

        foreach ((array) json_decode($this->herd($context, $herd, ['paths'])->output(), true) as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            if ($this->normalise($path) === $parent) {
                $parked = true;
            } elseif (is_dir(rtrim($path, '/').'/'.$name) && $this->normalise(rtrim($path, '/').'/'.$name) !== $project) {
                return self::TAKEN;
            }
        }

        return $parked ? self::SERVED : self::FREE;
    }

    /**
     * The Path cell of the `herd links` row named $name (case-insensitive), '' when the row has no usable path, or null
     * without such a row. The column is found by its "Path" header, so the header row never counts as a site and an
     * empty SSL or node cell is never read as a path; without that header every row for the name fails closed ('').
     */
    private function linkPath(string $table, string $name): ?string
    {
        $rows = [];

        foreach (preg_split('/\R/', $table) ?: [] as $line) {
            if (str_starts_with(trim($line), '|')) {
                $rows[] = array_map(trim(...), explode('|', trim(trim($line), '|')));
            }
        }

        $header = ($rows[0][0] ?? '') === 'Site' ? array_shift($rows) : [];
        $column = array_search('Path', $header, true);

        foreach ($rows as $cells) {
            if (strcasecmp($cells[0], $name) === 0) {
                $path = is_int($column) ? ($cells[$column] ?? '') : '';

                return str_starts_with($path, '/') ? $path : '';
            }
        }

        return null;
    }

    /**
     * The resolved path without a trailing slash, lower-cased: macOS volumes compare paths case-insensitively. An empty
     * path stays empty: realpath('') would return the working directory, which is the project during skv:install.
     */
    private function normalise(string $path): string
    {
        if ($path === '') {
            return '';
        }

        return Str::lower(rtrim(realpath($path) ?: $path, '/'));
    }

    /** @phpstan-impure it asks Herd every time */
    private function isSecured(SetupContext $context, string $herd): bool
    {
        return $this->row($this->herd($context, $herd, ['secured'])->output(), $this->domain($context)) !== null;
    }

    /** .env's APP_URL is the https address secured() writes (RL-16). */
    private function appUrlIsSecured(SetupContext $context): bool
    {
        return new EnvFile($context->path('.env'))->get('APP_URL') === 'https://'.$this->domain($context);
    }

    /**
     * The PHP that serves the site is 8.4 or newer: its isolated version when `herd isolated` lists it, else Herd's global PHP.
     *
     * @phpstan-impure it asks Herd every time
     */
    private function runsSupportedPhp(SetupContext $context, string $herd): bool
    {
        $row = $this->row($this->herd($context, $herd, ['isolated'])->output(), $this->domain($context));
        $version = $row === null ? $this->globalPhpVersion() : ($row[1] ?? '');

        return version_compare(trim($version), self::PHP_VERSION, '>=');
    }

    /** Herd's global PHP: its php shim picks the version per directory, so it runs outside the project. */
    private function globalPhpVersion(): string
    {
        return Process::path(sys_get_temp_dir())
            ->env($this->toolchain->environment())
            ->timeout(30)
            ->run([$this->toolchain->which('php') ?? 'php', '-r', 'echo PHP_VERSION;'])
            ->output();
    }

    /**
     * The trimmed cells of the ASCII-table row whose first cell is $site (case-insensitive), or null. Rows are matched
     * whole, so "x{$site}" never counts as $site.
     *
     * @return list<string>|null
     */
    private function row(string $table, string $site): ?array
    {
        if (preg_match('/^\|\s*'.preg_quote($site, '/').'\s*\|.*$/mi', $table, $match) !== 1) {
            return null;
        }

        return array_map(trim(...), explode('|', trim(trim($match[0]), '|')));
    }

    /**
     * "{folder}.{tld}" in lower case, as Herd lists it and as the detected host has it (so the Environment step's host
     * checks still match on a re-run), with the tld taken from the detected host (Herd's default is "test").
     */
    private function domain(SetupContext $context): string
    {
        $host = $context->detection->host;

        return Str::lower($context->folderName()).'.'.(str_contains($host, '.') ? Str::afterLast($host, '.') : 'test');
    }

    /** The end of a secured run: APP_URL gets the name Herd secured (RL-16), and the marker's addresses say https. */
    private function secured(SetupContext $context, string $message): StepResult
    {
        new EnvFile($context->path('.env'))->set(['APP_URL' => 'https://'.$this->domain($context)]);

        // The summary is read from the marker (§5.3.4): rewrite it so its addresses say https.
        if (Marker::exists($context)) {
            Marker::write($context, Summary::build($context));
        }

        return StepResult::done(self::SECURED.': '.$message);
    }

    /** Names a link this step created, so whoever removes the site knows to run `herd unlink`. */
    private function withLink(string $message, bool $linked, string $name): string
    {
        return $linked ? $message.' ('.__('linked as :name', ['name' => $name]).')' : $message;
    }

    /**
     * Runs herd in the base path: `herd link` and `herd isolate` act on the current directory.
     *
     * @param  list<string>  $arguments
     */
    private function herd(SetupContext $context, string $herd, array $arguments): ProcessResult
    {
        return Process::path($context->basePath)
            ->env($this->toolchain->environment())
            ->timeout(self::TIMEOUT)
            ->run([$herd, ...$arguments]);
    }

    /** The `herd isolate` command the step prints for a site that runs an older PHP, built in one place. */
    private function isolateCommand(string $name): string
    {
        return 'herd isolate '.self::PHP_VERSION.' '.ShellArgument::quote('--site='.$name);
    }
}
