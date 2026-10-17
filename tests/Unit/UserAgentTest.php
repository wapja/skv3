<?php

declare(strict_types=1);

/** covers §6.6 */

use App\Support\UserAgent;

it('reads the browser, platform and device of the headers people sign in with (CD-2)', function (string $header, ?string $browser, ?string $platform, string $device): void {
    $agent = UserAgent::parse($header);

    expect([$agent->browser, $agent->platform, $agent->device])->toBe([$browser, $platform, $device]);
})->with([
    'Chrome on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', 'Chrome', 'Windows', 'desktop'],
    'Chrome on macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', 'Chrome', 'macOS', 'desktop'],
    'Chrome on Linux' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', 'Chrome', 'Linux', 'desktop'],
    'Chrome on ChromeOS' => ['Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', 'Chrome', 'ChromeOS', 'desktop'],
    'Chrome on an Android phone' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36', 'Chrome', 'Android', 'mobile'],
    'Chrome on an Android tablet' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', 'Chrome', 'Android', 'tablet'],
    'Chrome on an iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/130.0.6723.90 Mobile/15E148 Safari/604.1', 'Chrome', 'iOS', 'mobile'],
    'Edge on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.2849.68', 'Edge', 'Windows', 'desktop'],
    'Edge on macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.2849.68', 'Edge', 'macOS', 'desktop'],
    'Edge on an Android phone' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36 EdgA/130.0.0.0', 'Edge', 'Android', 'mobile'],
    'Edge on an iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 EdgiOS/130.2849.80 Mobile/15E148 Safari/605.1.15', 'Edge', 'iOS', 'mobile'],
    'Firefox on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0', 'Firefox', 'Windows', 'desktop'],
    'Firefox on macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:132.0) Gecko/20100101 Firefox/132.0', 'Firefox', 'macOS', 'desktop'],
    'Firefox on Linux' => ['Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0', 'Firefox', 'Linux', 'desktop'],
    'Firefox on an Android phone' => ['Mozilla/5.0 (Android 14; Mobile; rv:132.0) Gecko/132.0 Firefox/132.0', 'Firefox', 'Android', 'mobile'],
    'Firefox on an Android tablet' => ['Mozilla/5.0 (Android 14; Tablet; rv:132.0) Gecko/132.0 Firefox/132.0', 'Firefox', 'Android', 'tablet'],
    'Firefox on an iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/132.0 Mobile/15E148 Safari/605.1.15', 'Firefox', 'iOS', 'mobile'],
    'Safari on macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15', 'Safari', 'macOS', 'desktop'],
    'Safari on an iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1', 'Safari', 'iOS', 'mobile'],
    'Safari on an iPad' => ['Mozilla/5.0 (iPad; CPU OS 17_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.7 Mobile/15E148 Safari/604.1', 'Safari', 'iOS', 'tablet'],
    'Samsung Internet on a phone' => ['Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36', 'Samsung Internet', 'Android', 'mobile'],
    'Samsung Internet on a tablet' => ['Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Safari/537.36', 'Samsung Internet', 'Android', 'tablet'],
    'Opera on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 OPR/114.0.0.0', 'Opera', 'Windows', 'desktop'],
    'Opera on an Android phone' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36 OPR/85.0.0.0', 'Opera', 'Android', 'mobile'],
    'curl' => ['curl/8.7.1', null, null, 'desktop'],
    'a crawler' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', null, null, 'desktop'],
    'an empty header' => ['', null, null, 'desktop'],
]);

it('reads a missing header as an unknown desktop (CD-2)', function (): void {
    expect(UserAgent::parse(null))->toEqual(new UserAgent(null, null, 'desktop'));
});

it('reads only the start of an oversized header (CD-2)', function (): void {
    $agent = UserAgent::parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64)'.str_repeat(' padding', 100).' Firefox/132.0');

    expect([$agent->browser, $agent->platform])->toBe([null, 'Windows']);
});
