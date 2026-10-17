<?php

declare(strict_types=1);

/** covers §6.6, §4.3, §4.8, §6.7 */

use App\Actions\Organisations\ArchiveOrganisation;
use App\Actions\Organisations\PurgeOrganisation;
use App\Actions\Users\DeleteUser;
use App\Actions\Users\RemoveAvatar;
use App\Actions\Users\UpdateAvatar;
use App\Authorization\Role;
use App\Authorization\SetSuperAdmin;
use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Models\Organisation;
use App\Models\User;
use App\Support\Skv;
use App\Tenancy\Auth\Impersonation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Image\ImageException;
use Illuminate\Image\ImageManager;
use Illuminate\Image\Transformations\Cover;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('public');
    Skv::useFluxPro(false);
});

afterEach(fn () => Skv::useFluxPro(null));

/**
 * A 40 × 20 JPEG, left half red and right half blue, with an EXIF APP1 segment whose IFD0 holds one Orientation
 * tag (0x0112, SHORT): what a phone writes instead of turning the pixels. 6 means "turn 90° clockwise to show".
 */
$orientedJpeg = function (int $orientation): string {
    $image = imagecreatetruecolor(40, 20);
    imagefilledrectangle($image, 0, 0, 19, 19, (int) imagecolorallocate($image, 255, 0, 0));
    imagefilledrectangle($image, 20, 0, 39, 19, (int) imagecolorallocate($image, 0, 0, 255));
    ob_start();
    imagejpeg($image, null, 95);
    $jpeg = (string) ob_get_clean();

    // Big-endian TIFF header, one IFD0 entry, no next IFD.
    $tiff = "MM\x00\x2A\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01".pack('n', $orientation)."\x00\x00\x00\x00\x00\x00";
    $app1 = "\xFF\xE1".pack('n', 2 + 6 + strlen($tiff))."Exif\x00\x00".$tiff;

    return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
};

/** A PNG whose signature and header are valid (finfo, getimagesize) but whose pixel data is missing: it passes every rule and cannot be decoded. */
$brokenPng = function (): string {
    $image = imagecreatetruecolor(64, 64);
    ob_start();
    imagepng($image);

    return substr((string) ob_get_clean(), 0, 33).str_repeat("\x00", 64);
};

/** The red, green and blue of one pixel of an encoded image. */
$pixel = function (string $bytes, int $x, int $y): array {
    $image = imagecreatefromstring($bytes) ?: throw new LogicException('Not an image.');
    $colour = imagecolorsforindex($image, (int) imagecolorat($image, $x, $y));

    return [$colour['red'], $colour['green'], $colour['blue']];
};

/** A member of $organisation with a stored photo, as UpdateAvatar leaves it. */
$memberWithPhoto = function (Organisation $organisation): User {
    $member = User::factory()->for($organisation)->create();
    $path = "avatars/{$organisation->id}/{$member->id}-existing.webp";
    Storage::disk('public')->put($path, 'old photo');
    $member->forceFill(['avatar_path' => $path])->saveQuietly();

    return $member;
};

it('stores an uploaded photo as a 512 × 512 WebP on the public disk under the organisation (§6.6)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = asMember($organisation);

    Livewire::test('pages::settings.profile')
        ->set('avatar', UploadedFile::fake()->image('photo.jpg', 800, 600))
        ->call('saveAvatar')
        ->assertHasNoErrors()
        ->assertSet('avatar', null)
        ->assertDispatched('avatar-updated');

    $path = (string) $member->fresh()?->avatar_path;
    $size = getimagesizefromstring((string) Storage::disk('public')->get($path));
    expect($path)->toMatch('#^avatars/'.$organisation->id.'/'.$member->id.'-[A-Za-z0-9]{20}\.webp$#')
        ->and([$size[0] ?? null, $size[1] ?? null, $size['mime'] ?? null])->toBe([512, 512, 'image/webp'])
        ->and($member->fresh()?->avatarUrl())->toBe(Storage::disk('public')->url($path))
        ->and(Storage::disk('public')->allFiles())->toBe([$path]);
});

it('stores a super admin\'s photo under avatars/platform', function (): void {
    $superAdmin = asSuperAdmin();
    onAdminHost();

    Livewire::test('pages::settings.profile')
        ->set('avatar', UploadedFile::fake()->image('photo.png', 600, 600))
        ->call('saveAvatar')
        ->assertHasNoErrors();

    expect((string) $superAdmin->fresh()?->avatar_path)->toStartWith('avatars/platform/'.$superAdmin->id.'-');
});

it('turns the photo by its EXIF orientation before the square crop and drops the metadata (§6.6)', function () use ($orientedJpeg, $pixel): void {
    $member = asMember(Organisation::factory()->create(['slug' => 'acme']));

    Livewire::test('pages::settings.profile')
        ->set('avatar', UploadedFile::fake()->createWithContent('phone.jpg', $orientedJpeg(6)))
        ->call('saveAvatar')
        ->assertHasNoErrors();

    $stored = (string) Storage::disk('public')->get((string) $member->fresh()?->avatar_path);
    [$topRight, $bottomLeft] = [$pixel($stored, 448, 64), $pixel($stored, 64, 448)];

    // Turned 90° clockwise, the red left half is on top and the blue right half below; unturned it would be the reverse.
    expect($topRight[0])->toBeGreaterThan(200)->and($topRight[2])->toBeLessThan(60)
        ->and($bottomLeft[2])->toBeGreaterThan(200)->and($bottomLeft[0])->toBeLessThan(60)
        ->and(str_contains($stored, 'Exif'))->toBeFalse()
        ->and(str_contains($stored, 'EXIF'))->toBeFalse();
})->skip(fn (): bool => ! function_exists('exif_read_data'), 'reading the orientation needs ext-exif');

it('refuses a photo that is too large, of another type or with too many pixels, and stores nothing (§6.6)', function (Closure $file, string $rule): void {
    $member = asMember(Organisation::factory()->create(['slug' => 'acme']));

    Livewire::test('pages::settings.profile')
        ->set('avatar', $file())
        ->call('saveAvatar')
        ->assertHasErrors(['avatar' => $rule]);

    expect($member->fresh()?->avatar_path)->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and(recordedActivity(ActivityEvent::UserUpdated))->toBeEmpty();
})->with([
    'more than 2 MB' => [fn (): UploadedFile => UploadedFile::fake()->image('photo.jpg', 100, 100)->size(2049), 'max'],
    'a GIF' => [fn (): UploadedFile => UploadedFile::fake()->image('photo.gif', 100, 100), 'mimes'],
    'a PDF' => [fn (): UploadedFile => UploadedFile::fake()->create('photo.pdf', 10, 'application/pdf'), 'image'],
    'wider than 4096 pixels' => [fn (): UploadedFile => UploadedFile::fake()->image('photo.png', 4097, 10), 'dimensions'],
    'nothing chosen' => [fn (): null => null, 'required'],
]);

it('refuses an image it cannot read with "This image could not be processed." (§6.6)', function () use ($brokenPng): void {
    $member = asMember(Organisation::factory()->create(['slug' => 'acme']));

    Livewire::test('pages::settings.profile')
        ->set('avatar', UploadedFile::fake()->createWithContent('photo.png', $brokenPng()))
        ->call('saveAvatar')
        ->assertHasErrors(['avatar' => __('This image could not be processed.')]);

    expect($member->fresh()?->avatar_path)->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('refuses an image wider than 4096 pixels in the action itself, before decoding it', function (): void {
    $member = User::factory()->for(Organisation::factory())->create();

    expect(fn () => app(UpdateAvatar::class)->handle($member, UploadedFile::fake()->image('photo.png', 4097, 10)))
        ->toThrow(ValidationException::class, __('This image could not be processed.'));

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('raises the memory limit before it decodes a large photo (plan review K5/K8)', function (): void {
    $member = User::factory()->for(Organisation::factory())->create();
    $file = UploadedFile::fake()->image('photo.png', 4096, 4096);
    $original = ini_get('memory_limit');
    $held = memory_get_usage();
    // Enough to decode this PNG, less than the action asks for: only the action's own raise moves the limit up.
    ini_set('memory_limit', (string) ($held + 200 * 1024 * 1024));

    try {
        app(UpdateAvatar::class)->handle($member, $file);
        $limit = ini_parse_quantity(ini_get('memory_limit'));
    } finally {
        ini_set('memory_limit', $original);
    }

    // On top of what this process already holds, so the test's own usage cannot meet the floor by itself.
    expect($limit)->toBeGreaterThanOrEqual($held + 4096 * 4096 * UpdateAvatar::BYTES_PER_PIXEL)
        ->and(Storage::disk('public')->allFiles())->toHaveCount(1);
});

it('leaves a failure of the server\'s image processing a server error, not the user\'s image', function (): void {
    $member = User::factory()->for(Organisation::factory())->create();
    app(ImageManager::class)->transformUsing('gd', Cover::class, fn (): never => throw new RuntimeException('GD broke.'));

    expect(fn () => app(UpdateAvatar::class)->handle($member, UploadedFile::fake()->image('photo.png', 600, 600)))
        ->toThrow(ImageException::class, 'GD broke.');

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('deletes the previous photo once a new one is saved (§6.6)', function () use ($memberWithPhoto): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = $memberWithPhoto($organisation);
    $previous = (string) $member->avatar_path;
    $this->actingAs($member);
    onTenant($organisation);

    Livewire::test('pages::settings.profile')
        ->set('avatar', UploadedFile::fake()->image('new.png', 600, 600))
        ->call('saveAvatar')
        ->assertHasNoErrors();

    $current = (string) $member->fresh()?->avatar_path;
    expect($current)->not->toBe($previous)
        ->and(Storage::disk('public')->allFiles())->toBe([$current]);
});

it('deletes the previous photo only once the save commits (CD-7)', function () use ($memberWithPhoto): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = $memberWithPhoto($organisation);
    $previous = (string) $member->avatar_path;
    onTenant($organisation);

    // A caller's transaction rolls back after the save: the row keeps the previous path, so its file must stay.
    expect(fn () => DB::transaction(function () use ($member): never {
        app(UpdateAvatar::class)->handle($member, UploadedFile::fake()->image('new.png', 600, 600));

        throw new RuntimeException('The caller failed after the save.');
    }))->toThrow(RuntimeException::class, 'The caller failed after the save.');

    expect(DB::table('users')->where('id', $member->id)->value('avatar_path'))->toBe($previous)
        ->and(Storage::disk('public')->exists($previous))->toBeTrue();
});

it('keeps the previous photo and removes the new file when the save fails', function () use ($memberWithPhoto): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = $memberWithPhoto($organisation);
    $previous = (string) $member->avatar_path;
    onTenant($organisation);
    Activity::creating(function (): void {
        throw new RuntimeException('The audit log is unavailable.');
    });

    expect(fn () => app(UpdateAvatar::class)->handle($member, UploadedFile::fake()->image('new.png', 600, 600)))
        ->toThrow(RuntimeException::class);

    expect(DB::table('users')->where('id', $member->id)->value('avatar_path'))->toBe($previous)
        ->and(Storage::disk('public')->allFiles())->toBe([$previous]);
});

it('removes the photo and its file and falls back to the initials (§6.6)', function () use ($memberWithPhoto): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = $memberWithPhoto($organisation);
    $member->forceFill(['name' => 'Mia Member'])->saveQuietly();
    $this->actingAs($member);
    onTenant($organisation);

    Livewire::test('pages::settings.profile')
        ->call('removeAvatar')
        ->assertHasNoErrors()
        ->assertDispatched('avatar-updated');

    expect($member->fresh()?->avatar_path)->toBeNull()
        ->and($member->fresh()?->avatarUrl())->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBe([]);

    $this->get(route('profile.edit'))->assertOk()
        ->assertSee('data-test="profile-photo-preview"', false)
        ->assertSee('<span class="select-none">MM</span>', false)
        ->assertDontSee('data-test="remove-avatar-button"', false);
});

it('deletes the removed photo only once the removal commits (CD-7)', function () use ($memberWithPhoto): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = $memberWithPhoto($organisation);
    // Read before the call: the action clears the model's path.
    $path = (string) $member->avatar_path;
    onTenant($organisation);

    expect(fn () => DB::transaction(function () use ($member): never {
        app(RemoveAvatar::class)->handle($member);

        throw new RuntimeException('The caller failed after the removal.');
    }))->toThrow(RuntimeException::class, 'The caller failed after the removal.');

    expect(DB::table('users')->where('id', $member->id)->value('avatar_path'))->toBe($path)
        ->and(Storage::disk('public')->exists($path))->toBeTrue();
});

it('clears the stored path when the user it is given was loaded before a parallel save', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $path = "avatars/{$organisation->id}/{$member->id}-parallel.webp";
    Storage::disk('public')->put($path, 'photo saved by another request');
    DB::table('users')->where('id', $member->id)->update(['avatar_path' => $path]);
    onTenant($organisation);

    app(RemoveAvatar::class)->handle($member);

    expect(DB::table('users')->where('id', $member->id)->value('avatar_path'))->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('records user.updated with changes.avatar "set" or "removed" and never the path (§4.8)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = asMember($organisation);

    Livewire::test('pages::settings.profile')
        ->set('avatar', UploadedFile::fake()->image('photo.png', 600, 600))
        ->call('saveAvatar')
        ->call('removeAvatar')
        ->call('removeAvatar'); // nothing left to remove: no third entry

    $entries = recordedActivity(ActivityEvent::UserUpdated);
    expect($entries)->toHaveCount(2)
        ->and($entries->map(fn (Activity $entry): mixed => $entry->attribute_changes?->toArray())->all())->toBe([
            ['attributes' => ['avatar' => 'set']],
            ['attributes' => ['avatar' => 'removed']],
        ])
        ->and($entries->map(fn (Activity $entry): array => [$entry->subject_id, $entry->causer_id, $entry->organisation_id])->unique()->all())
        ->toBe([[$member->id, $member->id, $organisation->id]])
        ->and((string) json_encode($entries->toArray()))->not->toContain('avatars');
});

it('records nothing when only the stored path changes in a plain save (§4.8)', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    $member->forceFill(['avatar_path' => 'avatars/1/1-abc.webp'])->save();

    expect(recordedActivity(ActivityEvent::UserUpdated))->toBeEmpty();
});

it('hides the photo section during an impersonation and refuses its actions with 403 (§4.7)', function () use ($memberWithPhoto): void {
    useImpersonationModule(true);
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn (): User => $admin->assignRole(Role::OrganisationAdmin->value));
    $member = $memberWithPhoto($organisation);
    $path = $member->avatar_path;
    onTenant($organisation);
    startImpersonatingOverHttp($admin, $member);

    $this->get(route('profile.edit'))->assertOk()->assertDontSee('data-test="profile-photo"', false);
    $snapshot = pageSnapshot(route('profile.edit'), 'pages::settings.profile');

    callPageAction($snapshot, 'removeAvatar')->assertForbidden();
    callPageAction($snapshot, 'saveAvatar')->assertForbidden();

    expect($member->fresh()?->avatar_path)->toBe($path)
        ->and(Storage::disk('public')->exists((string) $path))->toBeTrue()
        ->and(recordedActivity(ActivityEvent::UserUpdated))->toBeEmpty();
});

it('refuses both actions themselves while the session impersonates', function () use ($memberWithPhoto): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = $memberWithPhoto($organisation);
    onTenant($organisation);
    Session::put(Impersonation::SESSION_KEY, $member->id + 1);

    expect(fn () => app(UpdateAvatar::class)->handle($member, UploadedFile::fake()->image('new.png', 600, 600)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RemoveAvatar::class)->handle($member))->toThrow(AuthorizationException::class)
        ->and($member->fresh()?->avatar_path)->not->toBeNull();
});

it('renders the free file input with the accepted types and no Pro upload', function (): void {
    asMember(Organisation::factory()->create(['slug' => 'acme']));

    $this->get(route('profile.edit'))->assertOk()
        ->assertSee('data-test="profile-photo"', false)
        ->assertSee('accept="image/jpeg,image/png,image/webp"', false)
        ->assertSee('wire:model="avatar"', false)
        ->assertDontSee('data-flux-file-upload', false);
});

it('shows the photo in the user menu, the users index and the profile preview, else the initials (§6.6)', function () use ($memberWithPhoto): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $withPhoto = $memberWithPhoto($organisation);
    $withPhoto->forceFill(['name' => 'Pia Photo'])->saveQuietly();
    $withoutPhoto = User::factory()->for($organisation)->create(['name' => 'Ivo Initials']);
    $url = (string) $withPhoto->avatarUrl();
    $admin = User::factory()->for($organisation)->create(['name' => 'Ada Admin']);
    withPermissionsTeam($organisation->id, fn (): User => $admin->assignRole(Role::OrganisationAdmin->value));
    $admin->unsetRelation('roles');
    onTenant($organisation);

    $this->actingAs($admin)->get(route('users.index'))->assertOk()
        ->assertSee('src="'.$url.'"', false)
        ->assertSee('<span class="select-none">II</span>', false)
        ->assertSee('<span class="select-none">AA</span>', false);

    $this->actingAs($withPhoto)->get(route('dashboard'))->assertOk()
        ->assertSee('data-flux-sidebar-profile', false)
        ->assertSee('src="'.$url.'"', false)
        ->assertDontSee('<span class="select-none">PP</span>', false);

    $this->actingAs($withPhoto)->get(route('profile.edit'))->assertOk()
        ->assertSee('src="'.$url.'"', false)
        ->assertSee('data-test="remove-avatar-button"', false);

    $this->actingAs($withoutPhoto)->get(route('dashboard'))->assertOk()
        ->assertSee('<span class="select-none">II</span>', false)
        ->assertDontSee('src="'.$url.'"', false);
});

it('re-renders the user menu when the photo changes', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $this->actingAs($member);
    onTenant($organisation);
    $menu = Livewire::test('user-menu');
    $path = "avatars/{$organisation->id}/{$member->id}-later.webp";
    $member->forceFill(['avatar_path' => $path])->saveQuietly();

    $menu->dispatch('avatar-updated')->assertSee('src="'.Storage::disk('public')->url($path).'"', false);
});

it('deletes the purged users\' photo files after commit and keeps a detached super admin\'s (§4.3)', function () use ($memberWithPhoto): void {
    $superAdmin = asSuperAdmin();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = $memberWithPhoto($organisation);
    $deleted = $memberWithPhoto($organisation);
    $deleted->delete();
    $promoted = $memberWithPhoto($organisation);
    app(SetSuperAdmin::class)->handle($promoted, true);
    app(ArchiveOrganisation::class)->handle($superAdmin, $organisation);

    app(PurgeOrganisation::class)->handle($superAdmin, Organisation::withTrashed()->findOrFail($organisation->id), 'acme');

    expect(Storage::disk('public')->exists((string) $member->avatar_path))->toBeFalse()
        ->and(Storage::disk('public')->exists((string) $deleted->avatar_path))->toBeFalse()
        ->and(Storage::disk('public')->allFiles())->toBe([(string) $promoted->avatar_path]);
});

it('keeps the users\' photo files when the purge does not commit (§4.3)', function () use ($memberWithPhoto): void {
    $superAdmin = asSuperAdmin();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = $memberWithPhoto($organisation);
    $path = (string) $member->avatar_path;
    app(ArchiveOrganisation::class)->handle($superAdmin, $organisation);

    expect(fn () => DB::transaction(function () use ($superAdmin, $organisation): never {
        app(PurgeOrganisation::class)->handle($superAdmin, Organisation::withTrashed()->findOrFail($organisation->id), 'acme');

        throw new RuntimeException('The caller failed after the purge.');
    }))->toThrow(RuntimeException::class, 'The caller failed after the purge.');

    expect(DB::table('users')->where('id', $member->id)->value('avatar_path'))->toBe($path)
        ->and(Storage::disk('public')->exists($path))->toBeTrue();
});

it('keeps the photo file of a deleted user, who can be restored (§4.4)', function () use ($memberWithPhoto): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = $memberWithPhoto($organisation);
    $admin = asOrgAdmin($organisation);

    app(DeleteUser::class)->handle($admin, $member);

    expect(Storage::disk('public')->exists((string) $member->avatar_path))->toBeTrue();
});
