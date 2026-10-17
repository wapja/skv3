<?php

declare(strict_types=1);

/** covers §6.5 */

use App\Http\Support\ReturnUrl;
use Illuminate\Http\Request;

/** A request to acme's host with the given Referer and, when $previous is given, a started session whose previous URL it is. */
$requestOnAcme = function (?string $referer = null, ?string $previous = null): Request {
    $request = Request::create('http://acme.localhost/locale', 'POST', server: $referer === null ? [] : ['HTTP_REFERER' => $referer]);

    if ($previous !== null) {
        $session = app('session')->driver();
        $session->setPreviousUrl($previous);
        $request->setLaravelSession($session);
    }

    return $request;
};

it('keeps a page on this host as a root-relative path with its query (G2-02)', function (string $url, string $expected) use ($requestOnAcme): void {
    expect(ReturnUrl::pathOnThisHost($requestOnAcme(), $url))->toBe($expected);
})->with([
    'an absolute URL with its query' => ['http://acme.localhost/users?search=x&page=2', '/users?search=x&page=2'],
    'a root-relative path' => ['/dashboard?page=2', '/dashboard?page=2'],
    'the host in capitals' => ['HTTP://ACME.LOCALHOST/users', '/users'],
    'another port and scheme' => ['https://acme.localhost:8443/users', '/users'],
    'no path' => ['http://acme.localhost', '/'],
    'a fragment' => ['http://acme.localhost/users#top', '/users'],
    'doubled slashes after this host' => ['http://acme.localhost//evil.example/x', '/evil.example/x'],
]);

it('refuses a page on any other host, or anything that is no http(s) URL or root-relative path (G2-02)', function (string $url) use ($requestOnAcme): void {
    expect(ReturnUrl::pathOnThisHost($requestOnAcme(), $url))->toBeNull();
})->with([
    'another site' => ['https://evil.example/phish'],
    'another organisation' => ['http://beta.localhost/users'],
    'the admin host' => ['http://admin.localhost/users'],
    'a protocol-relative URL' => ['//evil.example/phish'],
    'a backslash path' => ['/\\evil.example/phish'],
    'a tab a browser drops' => ["/\t/evil.example/phish"],
    'credentials before another host' => ['http://acme.localhost@evil.example/'],
    'a javascript: URL' => ['javascript:alert(1)'],
    'another scheme on this host' => ['ftp://acme.localhost/file'],
    'a relative path' => ['users'],
    'nothing' => [''],
]);

it('goes back to the Referer, else the session\'s previous page, else the fallback, on this host only (G2-02)', function (?string $referer, ?string $previous, string $expected) use ($requestOnAcme): void {
    expect(ReturnUrl::previous($requestOnAcme($referer, $previous)))->toBe($expected);
})->with([
    'the Referer' => ['http://acme.localhost/users?search=x', 'http://acme.localhost/dashboard', '/users?search=x'],
    'a foreign Referer, then the previous page' => ['https://evil.example/phish', 'http://acme.localhost/dashboard?page=2', '/dashboard?page=2'],
    'no Referer, the previous page' => [null, 'http://acme.localhost/dashboard', '/dashboard'],
    'nothing on this host' => ['https://evil.example/phish', null, '/'],
]);

it('answers without a session and falls back to the path it is given (G2-02)', function () use ($requestOnAcme): void {
    $request = $requestOnAcme('https://evil.example/phish');

    expect($request->hasSession())->toBeFalse()
        ->and(ReturnUrl::previous($request, '/login'))->toBe('/login');
});
