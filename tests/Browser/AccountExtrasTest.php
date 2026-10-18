<?php

declare(strict_types=1);

/** covers §6.6, §6.7, §7.2 */

use App\Authorization\Role;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Tests\Fixtures\NestUploadedFiles;

/**
 * Lets Livewire's upload endpoint read the file the in-process server keeps under `files[]` (NestUploadedFiles), and
 * fakes the disk Livewire keeps temporary uploads on while tests run, so nothing reaches storage/app.
 */
$prepareUploads = function (): void {
    /** @var list<string> $middleware */
    $middleware = config('livewire.temporary_file_upload.middleware');

    config(['livewire.temporary_file_upload.middleware' => [...$middleware, NestUploadedFiles::class]]);
    Storage::fake(FileUploadConfiguration::disk());
};

it('logs out the other devices from the sessions modal and keeps this browser signed in on a new session (§6.6, CD-18)', function (): void {
    // The kit's driver: the sessions list reads the rows the database driver writes (phpunit.xml uses the array driver).
    config(['session.driver' => 'database']);
    app(SessionManager::class)->forgetDrivers();

    $member = browserUser(Organisation::factory()->create(['slug' => 'acme']));

    $page = visit('/login')->withHost('acme.localhost')
        ->fill('email', $member->email)->fill('password', 'password')->press('@login-button')
        ->assertPathIs('/dashboard');

    $thisDevice = DB::table('sessions')->where('user_id', $member->id)->value('id');
    $otherDevice = sessionRowFor($member);

    // The security page sits behind password.confirm; the sign-in a moment ago is no confirmation.
    $page->navigate(sameOrigin($page->url(), '/settings/security'))
        ->assertPathIs('/user/confirm-password')
        ->fill('password', 'password')
        ->press('@confirm-password-button')
        ->assertPathIs('/settings/security')
        ->assertSee(__('This device'))
        ->assertSee(__(':browser on :platform', ['browser' => 'Chrome', 'platform' => 'Windows']))
        // The page holds two password fields (the password change form): every selector stays inside the modal.
        ->click('[wire\:name="pages::settings.sessions"] [data-flux-modal-trigger] button')
        ->fill('dialog[data-modal="logout-other-devices"] input[name="password"]', 'password')
        ->click('dialog[data-modal="logout-other-devices"] button[type="submit"]')
        ->assertSee(__('You were logged out on your other devices.'))
        ->assertDontSee(__(':browser on :platform', ['browser' => 'Chrome', 'platform' => 'Windows']))
        ->assertSee(__('This device'))
        ->assertNoJavaScriptErrors();

    $rows = DB::table('sessions')->where('user_id', $member->id)->pluck('id')->all();

    // T5 (CD-18): this device's old id died with the others; the browser carries the new one.
    expect(is_string($thisDevice))->toBeTrue()
        ->and($rows)->toHaveCount(1)
        ->and($rows)->not->toContain($otherDevice)
        ->and($rows)->not->toContain($thisDevice);

    $page->navigate(sameOrigin($page->url(), '/dashboard'))
        ->assertPathIs('/dashboard')
        ->assertSee($member->name);

    expect(DB::table('sessions')->where('user_id', $member->id)->pluck('id')->all())->toBe($rows);
});

it('saves a photo chosen in the free file input (§6.6, §6.7)', function () use ($prepareUploads): void {
    $prepareUploads();
    Storage::fake('public');
    $member = browserUser(Organisation::factory()->create(['slug' => 'acme']));

    $photo = storage_path('framework/testing/browser-avatar-'.Str::uuid().'.png');
    File::ensureDirectoryExists(dirname($photo));
    $image = imagecreatetruecolor(64, 64);
    imagefilledrectangle($image, 0, 0, 63, 63, (int) imagecolorallocate($image, 0, 128, 255));
    imagepng($image, $photo);

    try {
        $page = visit('/login')->withHost('acme.localhost')
            ->fill('email', $member->email)->fill('password', 'password')->press('@login-button')
            ->assertPathIs('/dashboard');

        $page->navigate(sameOrigin($page->url(), '/settings/profile'))->assertPathIs('/settings/profile');

        // Livewire dispatches livewire-upload-finish once the temporary upload is stored and set on the component.
        $page->script('window.uploadsFinished = 0; window.addEventListener("livewire-upload-finish", () => window.uploadsFinished++)');

        $page->attach('avatar', $photo)
            ->assertScript('window.uploadsFinished === 1', true)
            ->press('@save-avatar-button')
            ->assertSee(__('Profile photo saved.'))
            ->assertPresent('@remove-avatar-button')
            ->assertNoJavaScriptErrors();
    } finally {
        File::delete($photo);
    }

    $path = $member->fresh()?->avatar_path;

    expect($path)->toBeString();
    Storage::disk('public')->assertExists((string) $path);
});

it('checks pasted addresses and a CSV file, then sends the invitations (§6.6, §6.7)', function () use ($prepareUploads): void {
    $prepareUploads();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = browserUser($organisation, 'admin@example.com');
    withPermissionsTeam($organisation->id, fn (): User => $admin->assignRole(Role::OrganisationAdmin->value));

    $csv = storage_path('framework/testing/browser-invitations-'.Str::uuid().'.csv');
    File::ensureDirectoryExists(dirname($csv));
    File::put($csv, "email\nthree@example.com\n");

    try {
        $page = visit('/login')->withHost('acme.localhost')
            ->fill('email', $admin->email)->fill('password', 'password')->press('@login-button')
            ->assertPathIs('/dashboard');

        $page->navigate(sameOrigin($page->url(), '/invitations/bulk'))->assertPathIs('/invitations/bulk');
        $page->script('window.uploadsFinished = 0; window.addEventListener("livewire-upload-finish", () => window.uploadsFinished++)');

        $page->fill('addresses', "one@example.com\ntwo@example.com")
            ->attach('csv', $csv)
            ->assertScript('window.uploadsFinished === 1', true)
            ->click('[data-test="bulk-roles"] ui-checkbox[value="'.Role::templateId().'"]')
            ->press('@bulk-check')
            ->assertSee(__('Ready: :ready of :total', ['ready' => 3, 'total' => 3]))
            ->press('@bulk-send')
            ->assertSee(__('Sent: :count', ['count' => 3]))
            ->assertNoJavaScriptErrors();
    } finally {
        File::delete($csv);
    }

    $invited = fn (): array => Invitation::query()->with('user')->get()
        ->map(fn (Invitation $invitation): string => $invitation->user->email)->sort()->values()->all();

    expect(Tenant::run($organisation, $invited))->toBe(['one@example.com', 'three@example.com', 'two@example.com']);
});
