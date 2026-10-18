<?php

declare(strict_types=1);

/** covers §10, Appendix A R2 */

use Database\Seeders\DemoSeeder;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/*
| The predecessor kit served its manual from public/handleiding.html, with the demo accounts and their password on it (Appendix A
| R2). The manual lives in docs/ (§10), nothing under public/ names a demo account, and the manual never puts a demo
| account next to the demo password. public/storage (the link to the uploads) is never read.
*/

/**
 * Whether $text names a demo account: the addresses DemoSeeder::accounts() derives, on any apex, also written with
 * the manual's `<apex>` placeholder.
 */
$namesDemoAccount = function (string $text): bool {
    foreach (DemoSeeder::accounts('apex.invalid') as $account) {
        $pattern = str_replace(preg_quote('apex.invalid', '/'), '(?:[\w-]+(?:\.[\w-]+)*|&lt;apex&gt;|<apex>)', preg_quote($account['email'], '/'));

        if (preg_match('/(?<![\w.+-])'.$pattern.'/i', $text) === 1) {
            return true;
        }
    }

    return false;
};

/** Whether $line pairs a demo account with the demo password. */
$pairsDemoCredentials = fn (string $line): bool => $namesDemoAccount($line)
    && preg_match('/\b'.preg_quote(DemoSeeder::PASSWORD, '/').'\b/i', $line) === 1;

/**
 * Whether $text holds the demo password as a token of its own: not part of a word, an option, a route name or a path
 * (`--password-stdin`, `password.confirm`, `passwords.php` and `super_admin_password` are not it).
 */
$holdsDemoPassword = fn (string $text): bool => preg_match('/(?<![\w.\/:-])'.preg_quote(DemoSeeder::PASSWORD, '/').'(?![\w.\/:-])/i', $text) === 1;

/** @return list<SplFileInfo> */
$publicFiles = fn (): array => iterator_to_array(
    Finder::create()->files()->in(public_path())->exclude('storage')->ignoreDotFiles(false)->ignoreVCS(true),
    false,
);

it('serves no manual and no HTML page from public/ (Appendix A R2)', function () use ($publicFiles): void {
    $pages = collect($publicFiles())
        ->filter(fn (SplFileInfo $file): bool => in_array(strtolower($file->getExtension()), ['html', 'htm', 'md', 'markdown', 'pdf', 'docx'], true)
            || preg_match('/handleiding|manual/i', $file->getFilename()) === 1)
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect(is_file(base_path('docs/handleiding.html')))->toBeTrue()
        ->and($pages)->toBe([]);
});

it('names no demo account in any file under public/ (Appendix A R2)', function () use ($publicFiles, $namesDemoAccount): void {
    $hits = collect($publicFiles())
        ->filter(fn (SplFileInfo $file): bool => $namesDemoAccount($file->getContents()))
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($hits)->toBe([]);
});

it('never puts a demo account next to the demo password in the manual (Appendix A R2)', function () use ($pairsDemoCredentials): void {
    $manuals = glob(base_path('docs/handleiding.*')) ?: [];
    $hits = [];

    foreach ($manuals as $manual) {
        foreach (preg_split('/\R/', (string) file_get_contents($manual)) ?: [] as $number => $line) {
            if ($pairsDemoCredentials($line)) {
                $hits[] = basename($manual).':'.($number + 1);
            }
        }
    }

    expect($manuals)->not->toBeEmpty()
        ->and($hits)->toBe([]);
});

it('never gives the demo password anywhere in a manual that names a demo account (Appendix A R2)', function () use ($namesDemoAccount, $holdsDemoPassword): void {
    $manuals = glob(base_path('docs/handleiding.*')) ?: [];
    $hits = [];

    foreach ($manuals as $manual) {
        $text = (string) file_get_contents($manual);
        // The test examples' factory logins ('password' => 'password') sit in code blocks; a line there that also names
        // a demo account is the previous test's to catch.
        $prose = (string) preg_replace('#<pre\b.*?</pre>#is', '', $text);

        // A paragraph after the accounts table can hand out the password as well as the table's own row can.
        if ($namesDemoAccount($text) && $holdsDemoPassword($prose)) {
            $hits[] = basename($manual);
        }
    }

    expect($manuals)->not->toBeEmpty()
        ->and($hits)->toBe([]);
});

it('recognises the demo password on its own and nowhere else (Appendix A R2)', function () use ($holdsDemoPassword): void {
    expect($holdsDemoPassword('logt in met het wachtwoord <code>password</code>.'))->toBeTrue()
        ->and($holdsDemoPassword('wachtwoord: password'))->toBeTrue()
        ->and($holdsDemoPassword('<code>--password-stdin</code>'))->toBeFalse()
        ->and($holdsDemoPassword('<code>security.edit, password.confirm</code>'))->toBeFalse()
        ->and($holdsDemoPassword('lang/nl/passwords.php, <code>super_admin_password</code>, <code>auth.password_reset_requested</code>'))->toBeFalse();
});

it('recognises the demo accounts and the pairs the predecessor kit published (Appendix A R2)', function () use ($namesDemoAccount, $pairsDemoCredentials): void {
    expect($namesDemoAccount('<td>superadmin@acme.test</td>'))->toBeTrue()
        ->and($namesDemoAccount('<code>admin@demo2.&lt;apex&gt;</code>'))->toBeTrue()
        ->and($namesDemoAccount('user@demo1.localhost'))->toBeTrue()
        ->and($namesDemoAccount('someone@acme.test, admin@example.com'))->toBeFalse()
        ->and($pairsDemoCredentials('<tr><td>superadmin@acme.test</td><td>password</td></tr>'))->toBeTrue()
        ->and($pairsDemoCredentials("'password' => 'password'"))->toBeFalse()
        ->and($pairsDemoCredentials('<code>user@demo1.&lt;apex&gt;</code>: het wachtwoord staat in de samenvatting'))->toBeFalse();
});
