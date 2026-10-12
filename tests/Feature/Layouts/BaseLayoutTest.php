<?php

declare(strict_types=1);

/** covers §6.5 */

use App\Enums\Appearance;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\File;

it('renders the dark class server-side only for a stored dark appearance (DEC-12)', function (Appearance $appearance, bool $dark): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $user = User::factory()->for($organisation)->create(['appearance' => $appearance]);
    $this->actingAs($user);
    onTenant($organisation);

    $html = (string) $this->get(route('dashboard'))->assertOk()->getContent();

    expect(preg_match('/<html[^>]*class="[^"]*\bdark\b/', $html))->toBe($dark ? 1 : 0)
        ->and($html)->toContain('flux.appearance');
})->with([
    'dark' => [Appearance::Dark, true],
    'light' => [Appearance::Light, false],
    'system' => [Appearance::System, false],
]);

it('writes the stored appearance into localStorage before Flux applies it, so there is no flash', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $this->actingAs(User::factory()->for($organisation)->create(['appearance' => Appearance::Light]));
    onTenant($organisation);

    $html = (string) $this->get(route('dashboard'))->assertOk()->getContent();
    $script = strpos($html, "localStorage.setItem('flux.appearance'");
    $flux = strpos($html, 'window.Flux');

    expect($script)->toBeInt()->and($flux)->toBeInt();
    expect((int) $script)->toBeLessThan((int) $flux)
        ->and($html)->toContain("const stored = 'light';");
});

it('lets guests follow the system preference', function (): void {
    onTenant(Organisation::factory()->create(['slug' => 'acme']));

    $html = (string) $this->get(route('login'))->assertOk()->getContent();

    // The script text always holds both setItem and removeItem; the rendered value is what differs.
    expect($html)->toContain('const stored = null;')
        ->and(preg_match('/<html[^>]*class="[^"]*\bdark\b/', $html))->toBe(0);
});

it('keeps one HTML shell and no starter leftovers (§6.5, DEC-25)', function (): void {
    $documents = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($file): bool => str_contains($file->getContents(), '<!DOCTYPE html>'))
        ->map(fn ($file): string => $file->getRelativePathname())
        ->values()
        ->all();

    // Only the base layout and the self-contained error pages (Task 8) are documents.
    expect(array_values(array_filter($documents, fn (string $path): bool => ! str_starts_with($path, 'errors/'))))
        ->toBe(['layouts/base.blade.php']);

    foreach (['layouts/app/sidebar.blade.php', 'layouts/app/header.blade.php', 'layouts/auth/simple.blade.php',
        'layouts/auth/card.blade.php', 'layouts/auth/split.blade.php', 'partials/head.blade.php',
        'components/placeholder-pattern.blade.php'] as $path) {
        expect(resource_path("views/{$path}"))->not->toBeFile();
    }

    expect((string) file_get_contents(resource_path('css/app.css')))->not->toContain('!')
        ->and(collect(File::allFiles(resource_path('views')))->filter(
            fn ($file): bool => str_contains($file->getContents(), 'class="dark"'),
        )->all())->toBe([]);
});

it('shows the impersonation banner inside the app layout and on auth pages (RL-4)', function (): void {
    expect((string) file_get_contents(resource_path('views/layouts/app.blade.php')))->toContain('<x-impersonation-banner />')
        ->and((string) file_get_contents(resource_path('views/layouts/auth.blade.php')))->toContain('<x-impersonation-banner :inset="false" />');
});
