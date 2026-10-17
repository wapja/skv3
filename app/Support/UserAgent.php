<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The browser, platform and kind of device behind a User-Agent header, for the sessions list (§6.6, CD-2). A small
 * in-house parser: it knows the browsers and platforms people sign in with and answers null for anything else
 * (curl, bots, an empty header). It is a label for people, never a security decision: the header is
 * client-chosen. An iPad in Safari's desktop mode sends a macOS header and is read as a Mac desktop.
 */
final readonly class UserAgent
{
    /** Longer headers are cut before matching; every marker sits well inside the first 512 bytes. */
    private const int MAX_LENGTH = 512;

    /**
     * Checked in order: Edge, Opera and Samsung Internet also send "Chrome/", Chrome also sends "Safari/".
     *
     * @var array<string, string>
     */
    private const array BROWSERS = [
        '/\bEdg(?:e|A|iOS)?\//' => 'Edge',
        '/\bOPR\/|\bOpera\b/' => 'Opera',
        '/\bSamsungBrowser\//' => 'Samsung Internet',
        '/\bFirefox\/|\bFxiOS\//' => 'Firefox',
        '/\bChrome\/|\bCriOS\//' => 'Chrome',
        '/\bVersion\/[\d.]+.*\bSafari\//' => 'Safari',
    ];

    /**
     * Checked in order: iOS headers say "like Mac OS X", Android headers say "Linux".
     *
     * @var array<string, string>
     */
    private const array PLATFORMS = [
        '/\b(?:iPhone|iPad|iPod)\b/' => 'iOS',
        '/\bAndroid\b/' => 'Android',
        '/\bWindows\b/' => 'Windows',
        '/\bCrOS\b/' => 'ChromeOS',
        '/\bMac OS X\b|\bMacintosh\b/' => 'macOS',
        '/\bLinux\b/' => 'Linux',
    ];

    public function __construct(
        public ?string $browser,
        public ?string $platform,
        /** @var 'desktop'|'mobile'|'tablet' */
        public string $device,
    ) {}

    public static function parse(?string $header): self
    {
        $header = substr((string) $header, 0, self::MAX_LENGTH);

        return new self(
            self::firstMatch(self::BROWSERS, $header),
            self::firstMatch(self::PLATFORMS, $header),
            self::device($header),
        );
    }

    /**
     * A tablet says so (iPad, Firefox's "Tablet"), or is an Android device without "Mobile": Android phones send
     * "Mobile", Android tablets do not.
     *
     * @return 'desktop'|'mobile'|'tablet'
     */
    private static function device(string $header): string
    {
        if (preg_match('/\biPad\b|\bTablet\b/', $header) === 1
            || (preg_match('/\bAndroid\b/', $header) === 1 && preg_match('/\bMobile\b/', $header) !== 1)) {
            return 'tablet';
        }

        return preg_match('/\bMobile\b|\biPhone\b|\biPod\b/', $header) === 1 ? 'mobile' : 'desktop';
    }

    /** @param  array<string, string>  $patterns */
    private static function firstMatch(array $patterns, string $header): ?string
    {
        foreach ($patterns as $pattern => $name) {
            if (preg_match($pattern, $header) === 1) {
                return $name;
            }
        }

        return null;
    }
}
