<?php

declare(strict_types=1);

namespace App\Setup\Http;

use App\Setup\SetupContext;
use App\Setup\SetupHost;
use App\Setup\Support\HerdTld;
use App\Setup\Support\Toolchain;
use App\Setup\Wizard\WizardState;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

/**
 * The host rule of the wizard and the Herd stage (RL-5, g3-25). Once begin() pinned a host, a request must come on
 * that host or its admin host; before that, on a host the wizard would pin. Under Herd, and on localhost / *.localhost
 * (which browsers resolve to this machine without DNS), a page on another name that resolves here (DNS rebinding)
 * therefore never reads the token from `/` and never drives a running setup. Without Herd a *.test name is also
 * accepted before begin(); a rebound *.test name can still reach `/` then (residual, DEC-33 (21)).
 */
final class SetupHostGuard
{
    public static function allows(Request $request, ?string $pinned): bool
    {
        if ($pinned !== null) {
            $host = SetupHost::normalise($request->getHost());

            return $host !== null && ($host === $pinned || $host === 'admin.'.$pinned);
        }

        return self::resolve($request->getHttpHost(), app(Toolchain::class), app(SetupContext::class)->folderName())['host'] !== null;
    }

    /** The host the running wizard pinned, or null before begin() or for a state file this kit did not write. */
    public static function pinnedWizardHost(string $basePath): ?string
    {
        try {
            return WizardState::load($basePath)?->host;
        } catch (JsonException|InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The host the wizard pins for a request on $httpHost (host[:port]); on refusal null, the problem to show instead
     * and the address to open, when there is one (A19, RL-3):
     * - Without Herd: a local name, and on refusal localhost on the request's port (g3-34).
     * - Under Herd: {folder}.{tld} or its admin host, and on refusal {folder}.{tld}. A folder that is no DNS label can
     *   never be that address (g3-10), and a `herd tld` without an answer is no reason to fall back to the names
     *   without Herd (g3-28); neither has an address to suggest.
     *
     * @return array{host: ?string, problem: ?string, suggestion: ?string}
     */
    public static function resolve(string $httpHost, Toolchain $toolchain, string $folder): array
    {
        if ($toolchain->herd() === null) {
            $host = SetupHost::forWizard($httpHost, null, $folder);
            $port = preg_match('/:(\d{1,5})$/', $httpHost, $matches) === 1 ? ':'.$matches[1] : '';

            if ($host === null) {
                $suggestion = 'http://localhost'.$port;

                return ['host' => null, 'problem' => __('Open the setup on :url.', ['url' => $suggestion]), 'suggestion' => $suggestion];
            }

            return ['host' => $host, 'problem' => null, 'suggestion' => null];
        }

        $folderProblem = SetupHost::folderProblem($folder);

        if ($folderProblem !== null) {
            return ['host' => null, 'problem' => $folderProblem, 'suggestion' => null];
        }

        $tld = HerdTld::read($toolchain);

        if ($tld === null) {
            return ['host' => null, 'problem' => __('Herd did not answer. Reload the page in a moment.'), 'suggestion' => null];
        }

        $host = SetupHost::forWizard($httpHost, $tld, $folder);

        if ($host === null) {
            $suggestion = 'http://'.Str::lower($folder).'.'.$tld;

            return ['host' => null, 'problem' => __('Open the setup on :url.', ['url' => $suggestion]), 'suggestion' => $suggestion];
        }

        return ['host' => $host, 'problem' => null, 'suggestion' => null];
    }
}
