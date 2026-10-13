<?php

declare(strict_types=1);

namespace App\Setup\Steps;

use App\Setup\Marker;
use App\Setup\SetupContext;
use App\Setup\SetupStep;
use App\Setup\StepResult;
use App\Setup\Summary;
use App\Setup\Support\EnvFile;
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
 * verified is returned as `manual()`. A secured site's result message starts with SECURED, which skv:install reads
 * for the passkeys consequence (RL-7).
 */
final readonly class HerdSiteStep implements SetupStep
{
    /** The prefix of the result message when the site was secured (RL-7). */
    public const string SECURED = 'secured';

    /** The PHP version the kit needs (composer.json: php ^8.4). */
    private const string PHP_VERSION = '8.4';

    private const int TIMEOUT = 120;

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

    /** Served (parked or linked) and, when HTTPS was chosen, secured; false on any process failure. */
    public function isComplete(SetupContext $context): bool
    {
        $herd = $this->toolchain->herd();

        if (! $context->detection->herd || $herd === null) {
            return false;
        }

        try {
            return $this->isServed($context, $herd)
                && (! $context->answers->https || $this->isSecured($context, $herd));
        } catch (Throwable) {
            return false;
        }
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
        $pending = "herd link {$name}";

        try {
            if (! $this->isServed($context, $herd)) {
                if ($this->herd($context, $herd, ['link', $name])->failed()) {
                    return StepResult::manual($pending, __('Herd could not link the site.'));
                }

                $linked = true;
            }

            $pending = 'herd isolate '.self::PHP_VERSION.' --site='.$name;

            if (! $this->runsSupportedPhp($context, $herd)) {
                $this->herd($context, $herd, ['isolate', self::PHP_VERSION, '--site='.$name]);

                if (! $this->runsSupportedPhp($context, $herd)) {
                    return StepResult::manual($pending, $this->withLink(__('Herd did not confirm PHP 8.4 for this site.'), $linked, $name));
                }
            }

            if (! $context->answers->https) {
                return StepResult::done($this->withLink(__('Site served by Herd'), $linked, $name));
            }

            $pending = "herd secure {$name}";

            if (! $this->isSecured($context, $herd)) {
                $this->herd($context, $herd, ['secure', $name]);

                if (! $this->isSecured($context, $herd)) {
                    return StepResult::manual($pending, $this->withLink(__('Herd did not confirm the certificate.'), $linked, $name));
                }
            }
        } catch (ProcessTimedOutException) {
            return StepResult::manual($pending, __('The command did not finish within :seconds seconds.', ['seconds' => self::TIMEOUT]));
        }

        new EnvFile($context->path('.env'))->set(['APP_URL' => 'https://'.$context->detection->host]);

        // The summary is read from the marker (§5.3.4): rewrite it so its addresses say https.
        if (Marker::exists($context)) {
            Marker::write($context, Summary::build($context));
        }

        return StepResult::done(self::SECURED.': '.$this->withLink(__('Site secured with HTTPS'), $linked, $name));
    }

    /**
     * A parked directory holds the project, or `herd links` lists the folder name.
     *
     * @phpstan-impure it asks Herd every time
     */
    private function isServed(SetupContext $context, string $herd): bool
    {
        $parkedPaths = array_map(
            fn (string $path): string => rtrim($path, '/'),
            array_filter((array) json_decode($this->herd($context, $herd, ['paths'])->output(), true), is_string(...)),
        );

        if (in_array(rtrim(dirname(realpath($context->basePath) ?: $context->basePath), '/'), $parkedPaths, true)) {
            return true;
        }

        return $this->row($this->herd($context, $herd, ['links'])->output(), $context->folderName()) !== null;
    }

    /** @phpstan-impure it asks Herd every time */
    private function isSecured(SetupContext $context, string $herd): bool
    {
        return $this->row($this->herd($context, $herd, ['secured'])->output(), $this->domain($context)) !== null;
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

    /** "{folder}.{tld}", with the tld taken from the detected host (Herd's default is "test"). */
    private function domain(SetupContext $context): string
    {
        $host = $context->detection->host;

        return $context->folderName().'.'.(str_contains($host, '.') ? Str::afterLast($host, '.') : 'test');
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
}
