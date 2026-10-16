<?php

declare(strict_types=1);

/** covers §5.3.4 */

use App\Setup\Http\SetupHostGuard;
use App\Setup\SetupHost;
use Illuminate\Http\Request;

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
    'no herd loopback' => ['127.0.0.1:8000', null, null],
    'no herd .localhost' => ['acme.localhost', null, 'acme.localhost'],
    'no herd .test' => ['myapp.test', null, 'myapp.test'],
    'no herd public name' => ['example.com', null, null],
]);

it('names a folder that can be no Herd address (g3-10)', function (string $folder, bool $refused): void {
    expect(SetupHost::folderProblem($folder) !== null)->toBe($refused);
})->with([
    'label' => ['my-app', false],
    'upper case' => ['My-App', false],
    'underscore' => ['my_app', true],
    'dot' => ['my.app', true],
    'space' => ['my app', true],
]);

it('lets a request through only on the pinned host or its admin host (RL-5)', function (string $url, bool $allowed): void {
    expect(SetupHostGuard::allows(Request::create($url), 'my-app.test'))->toBe($allowed);
})->with([
    'pinned' => ['http://my-app.test/', true],
    'pinned with a port' => ['https://my-app.test:8443/', true],
    'admin' => ['http://admin.my-app.test/', true],
    'upper case' => ['http://ADMIN.My-App.test/', true],
    'tenant subdomain' => ['http://demo1.my-app.test/', false],
    'look-alike' => ['http://xmy-app.test/', false],
    'other' => ['http://evil.example/', false],
]);
