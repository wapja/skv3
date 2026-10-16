<?php

declare(strict_types=1);

namespace App\Http\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Where a "go back" may lead (§6.5, G2-02, CD-13): only to a page on the request's own host, returned as a
 * root-relative path with its query, so the browser stays on the origin it is on and neither the Referer nor the
 * session can send a visitor to another site, another organisation's host or a javascript: URL. The kit's own back
 * links use it; Laravel's, Fortify's and laravel/passkeys' back() on CSRF-protected POSTs stay as they are (CD-13).
 */
final class ReturnUrl
{
    /**
     * The page to go back to, as url()->previous() picks it (the Referer, else the session's previous URL), but only
     * the first of the two that is on this host; else $fallback. Works without a started session (an error page
     * rendered before StartSession, a 503 in maintenance mode).
     */
    public static function previous(Request $request, string $fallback = '/'): string
    {
        $candidates = [
            $request->headers->get('referer'),
            $request->hasSession() ? $request->session()->previousUrl() : null,
        ];

        foreach ($candidates as $candidate) {
            $path = is_string($candidate) ? self::pathOnThisHost($request, $candidate) : null;

            if ($path !== null) {
                return $path;
            }
        }

        return '/'.ltrim($fallback, '/');
    }

    /**
     * The root-relative path and query of $url (the fragment dropped) when $url is a root-relative path or an
     * http(s) URL naming this host; null for anything else: another host, a protocol-relative URL, a relative
     * path, another scheme, or a backslash, space or control character, which browsers may read as "//".
     */
    public static function pathOnThisHost(Request $request, string $url): ?string
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        if (isset($parts['host'])) {
            $scheme = Str::lower($parts['scheme'] ?? '');

            if (! in_array($scheme, ['http', 'https'], true) || Str::lower($parts['host']) !== Str::lower($request->getHost())) {
                return null;
            }
        } elseif (isset($parts['scheme']) || ! str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return null;
        }

        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return '/'.ltrim($parts['path'] ?? '', '/').$query;
    }
}
