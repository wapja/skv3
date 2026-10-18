<?php

declare(strict_types=1);

/** covers §4.4.1, §4.11.1, §6.5 */

use App\Enums\Locale;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('gives every locale a Dutch label (OR-I9)', function (Locale $locale): void {
    // In English the strings are the translation keys themselves.
    app()->setLocale('en');

    expect(dutchTranslationKeys())->toContain($locale->label());
})->with(Locale::cases());

it('translates the locale labels', function (): void {
    app()->setLocale('nl');

    expect(Locale::Dutch->label())->toBe('Nederlands')
        ->and(Locale::English->label())->toBe('Engels');
});

it('defaults a new user to the configured default locale (RL-8)', function (string $default, Locale $expected): void {
    config(['skv.locale.default' => $default]);

    expect(Locale::default())->toBe($expected)
        ->and(User::factory()->create()->locale)->toBe($expected);
})->with([
    'Dutch default' => ['nl', Locale::Dutch],
    'English default' => ['en', Locale::English],
    'unsupported default' => ['fr', Locale::Dutch],
]);

it('fills the column with the app locale of the migration run when nothing is given (CV-21)', function (): void {
    // The suite migrates with APP_LOCALE=en (phpunit.xml), so the column default is the English locale.
    $id = DB::table('users')->insertGetId([
        'name' => 'Raw',
        'email' => 'raw@example.com',
        'password' => 'x',
        'organisation_id' => Organisation::factory()->create()->id,
    ]);

    expect(DB::table('users')->where('id', $id)->value('locale'))->toBe(Locale::English->value);
});

it('casts the stored locale to the enum', function (): void {
    $user = User::factory()->create(['locale' => Locale::Dutch]);

    expect(DB::table('users')->where('id', $user->id)->value('locale'))->toBe('nl')
        ->and($user->fresh()?->locale)->toBe(Locale::Dutch);
});
