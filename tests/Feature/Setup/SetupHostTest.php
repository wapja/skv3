<?php

declare(strict_types=1);

/** covers §5.3.4 */

use App\Setup\SetupHost;

it('normalises a host or refuses it (A4, RL-3)', function (string $host, ?string $expected): void {
    expect(SetupHost::normalise($host))->toBe($expected);
})->with([
    'plain' => ['My-App.test', 'my-app.test'],
    'port' => ['127.0.0.1:8000', '127.0.0.1'],
    'localhost' => ['localhost', 'localhost'],
    'userinfo' => ['evil.com@my-app.test', null],
    'underscore' => ['my_app.test', null],
    'leading hyphen' => ['-app.test', null],
    'empty' => ['', null],
    'space' => ['my app.test', null],
]);

it('pins the wizard to the Herd name, or to a local name without Herd (A19, RL-3)', function (string $host, ?string $tld, ?string $expected): void {
    expect(SetupHost::forWizard($host, $tld, 'My-App'))->toBe($expected);
})->with([
    'herd apex' => ['my-app.test', 'test', 'my-app.test'],
    'herd admin' => ['admin.my-app.test', 'test', 'my-app.test'],
    'herd tenant subdomain' => ['demo1.my-app.test', 'test', null],
    'herd other site' => ['other.test', 'test', null],
    'herd look-alike' => ['xmy-app.test', 'test', null],
    'no herd localhost' => ['localhost:8000', null, 'localhost'],
    'no herd loopback' => ['127.0.0.1:8000', null, '127.0.0.1'],
    'no herd .localhost' => ['acme.localhost', null, 'acme.localhost'],
    'no herd public name' => ['example.com', null, null],
]);
