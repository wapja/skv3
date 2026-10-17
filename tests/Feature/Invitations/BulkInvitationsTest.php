<?php

declare(strict_types=1);

/** covers §6.6, §4.5, §6.2 */

use App\Authorization\Permission;
use App\Authorization\Role;
use App\Enums\ActivityEvent;
use App\Enums\Locale;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Support\RateLimits\UserActionLimit;
use App\Tenancy\Facades\Tenant;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

beforeEach(function (): void {
    $this->freezeTime();
});

$templateRoleId = fn (): string => (string) RoleModel::query()->whereNull('team_id')->where('name', Role::OrganisationAdmin->value)->value('id');

// The messages the array mailer delivered (the suite's sync queue; never Mail::fake(), RK-I8).
$deliveredMails = function (): array {
    $transport = Mail::driver()->getSymfonyTransport();
    $mails = [];

    if ($transport instanceof ArrayTransport) {
        foreach ($transport->messages() as $sent) {
            $message = $sent instanceof SentMessage ? $sent->getOriginalMessage() : null;

            if ($message instanceof Email) {
                $mails[] = $message;
            }
        }
    }

    return $mails;
};

/**
 * The bulk page's state after a Livewire update over HTTP.
 *
 * @param  TestResponse<Response>  $response
 * @return array{data: array<string, mixed>, memo: array{errors?: array<string, list<string>>}}
 */
$snapshotOf = function (TestResponse $response): array {
    /** @var array{data: array<string, mixed>, memo: array{errors?: array<string, list<string>>}} $snapshot */
    $snapshot = json_decode((string) $response->assertOk()->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR);

    return $snapshot;
};

it('reviews every row: ready, invalid, listed twice, an unknown language and addresses it cannot invite (CD-6)', function () use ($templateRoleId): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $beta = Organisation::factory()->create(['slug' => 'beta']);
    User::factory()->for($acme)->create(['email' => 'member@example.com']);
    User::factory()->for($beta)->create(['email' => 'elsewhere@example.com']);
    inviteMember($acme, email: 'pending@example.com');
    asOrgAdmin($acme);

    $rows = Livewire::test('pages::invitations.bulk')
        ->set('locale', Locale::English->value)
        ->set('roles', [$templateRoleId()])
        ->set('addresses', "New@Example.com\nnot-an-address\nnew@example.com\nmember@example.com\nelsewhere@example.com\npending@example.com")
        ->set('csv', UploadedFile::fake()->createWithContent('list.csv', "email;locale\nlater@example.com;nl\nodd@example.com;fr\n"))
        ->call('check')
        ->assertHasNoErrors()
        ->get('rows');

    $cannot = __("This address can't be invited.");
    expect($rows)->toBe([
        ['email' => 'new@example.com', 'locale' => 'en', 'reason' => null],
        ['email' => 'not-an-address', 'locale' => 'en', 'reason' => __('Not a valid email address.')],
        ['email' => 'new@example.com', 'locale' => 'en', 'reason' => __('Listed more than once.')],
        // §4.5: the same message for this organisation's member, its open invitation and another organisation's user.
        ['email' => 'member@example.com', 'locale' => 'en', 'reason' => $cannot],
        ['email' => 'elsewhere@example.com', 'locale' => 'en', 'reason' => $cannot],
        ['email' => 'pending@example.com', 'locale' => 'en', 'reason' => $cannot],
        ['email' => 'later@example.com', 'locale' => 'nl', 'reason' => null],
        ['email' => 'odd@example.com', 'locale' => 'fr', 'reason' => __('Unknown language.')],
    ]);
    expect(DB::table('users')->whereIn('email', ['new@example.com', 'later@example.com'])->exists())->toBeFalse()
        ->and(Tenant::run($acme, fn (): int => Invitation::query()->count()))->toBe(1);
});

it('refuses an empty list, a list of more than 100 rows, no role, a file that is not a CSV and an input that is too large (CD-6)', function (string $input) use ($templateRoleId): void {
    asOrgAdmin(Organisation::factory()->create(['slug' => 'acme']));
    $page = Livewire::test('pages::invitations.bulk')->set('roles', [$templateRoleId()]);

    match ($input) {
        'nothing' => $page->set('addresses', " \n;"),
        '101 rows' => $page->set('addresses', implode("\n", array_map(fn (int $index): string => "person{$index}@example.com", range(1, 101)))),
        'no role' => $page->set('addresses', 'ada@example.com')->set('roles', []),
        'not a CSV' => $page->set('csv', UploadedFile::fake()->createWithContent('list.pdf', "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n")),
        'a pasted list over 30,000 characters' => $page->set('addresses', 'ada@example.com'.str_repeat(' ', 30000)),
        default => $page->set('csv', UploadedFile::fake()->createWithContent('list.csv', 'ada@example.com'.str_repeat("\n", 513 * 1024))), // 'a CSV file over 512 KB'
    };

    $page->call('check')
        ->assertHasErrors(match ($input) {
            'nothing' => ['addresses' => __('Paste addresses or choose a CSV file.')],
            '101 rows' => ['addresses' => __('Add at most :max addresses at a time.', ['max' => 100])],
            'no role' => ['roles' => __('Choose at least one role.')],
            // the rules: Livewire reads a message with a colon as a rule and its parameters
            'not a CSV' => ['csv' => 'mimes'],
            'a pasted list over 30,000 characters' => ['addresses' => 'max'],
            default => ['csv' => 'max'],
        })
        ->assertSet('rows', []);
})->with(['nothing', '101 rows', 'no role', 'not a CSV', 'a pasted list over 30,000 characters', 'a CSV file over 512 KB']);

it('drops the preview when the input changes, so only a checked list is sent (CD-6)', function (string $change) use ($templateRoleId): void {
    asOrgAdmin(Organisation::factory()->create(['slug' => 'acme']));
    $page = Livewire::test('pages::invitations.bulk')
        ->set('roles', [$templateRoleId()])
        ->set('addresses', 'ada@example.com')
        ->call('check')
        ->assertCount('rows', 1);

    match ($change) {
        'addresses' => $page->set('addresses', 'bob@example.com'),
        'file' => $page->set('csv', UploadedFile::fake()->createWithContent('list.csv', "bob@example.com\n")),
        'language' => $page->set('locale', Locale::Dutch->value),
        default => $page->set('roles', []), // 'roles'
    };

    $page->assertSet('rows', [])
        ->call('send')
        ->assertHasErrors(['addresses' => __('Check the list first.')]);
})->with(['addresses', 'file', 'language', 'roles']);

it('deletes Livewire\'s temporary upload of the list once it is sent, removed or replaced, and keeps it for another check (CD-6)', function (string $outcome) use ($templateRoleId): void {
    asOrgAdmin(Organisation::factory()->create(['slug' => 'acme']));
    $page = Livewire::test('pages::invitations.bulk')
        ->set('roles', [$templateRoleId()])
        ->set('csv', UploadedFile::fake()->createWithContent('list.csv', "ada@example.com\n"));
    $upload = $page->get('csv');
    expect($upload->exists())->toBeTrue();

    match ($outcome) {
        'sent' => $page->call('check')->call('send')->assertHasNoErrors()->assertSet('csv', null),
        'removed' => $page->call('removeCsv')->assertSet('csv', null),
        'replaced' => $page->set('csv', UploadedFile::fake()->createWithContent('other.csv', "bob@example.com\n")),
        default => $page->call('check')->set('locale', Locale::Dutch->value)->call('check')->assertCount('rows', 1), // 'checked twice'
    };

    expect($upload->exists())->toBe($outcome === 'checked twice');
})->with(['sent', 'removed', 'replaced', 'checked twice']);

it('sends one queued mail per ready row, in the row\'s language, and summarises the skipped rows (CD-6)', function () use ($templateRoleId, $deliveredMails): void {
    $acme = Organisation::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    User::factory()->for($acme)->create(['email' => 'member@example.com']);
    $admin = asOrgAdmin($acme);

    Livewire::test('pages::invitations.bulk')
        ->set('locale', Locale::English->value)
        ->set('roles', [$templateRoleId()])
        ->set('csv', UploadedFile::fake()->createWithContent('list.csv', "\u{FEFF}ada@example.com;nl\r\nbob@example.com;\r\nmember@example.com;en\r\n"))
        ->call('check')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('rows', [])
        ->assertSet('summary', [
            'sent' => ['ada@example.com', 'bob@example.com'],
            'skipped' => [['email' => 'member@example.com', 'reason' => __("This address can't be invited.")]],
        ])
        ->assertSee(__('Sent: :count', ['count' => 2]))
        ->assertSee(__('Skipped: :count', ['count' => 1]));

    $subjects = collect($deliveredMails())->mapWithKeys(fn (Email $mail): array => [$mail->getTo()[0]->getAddress() => $mail->getSubject()])->all();
    expect($subjects)->toBe([
        'ada@example.com' => 'Je bent uitgenodigd voor Acme',
        'bob@example.com' => 'You have been invited to Acme',
    ])
        ->and(recordedActivity(ActivityEvent::InvitationSent)->pluck('causer_id')->all())->toBe([$admin->id, $admin->id])
        ->and(DB::table('users')->where('email', 'ada@example.com')->value('locale'))->toBe('nl')
        // The bulk limit counts the sent rows; the single-invite limit is not consumed.
        ->and(RateLimiter::attempts(UserActionLimit::bulkInvite()->key($admin)))->toBe(2)
        ->and(RateLimiter::attempts(UserActionLimit::invite()->key($admin)))->toBe(0);
});

it('skips a row the action refuses at send time with the generic message, sends the rest and gives its attempt back (CD-6)', function () use ($templateRoleId, $deliveredMails): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $beta = Organisation::factory()->create(['slug' => 'beta']);
    $admin = asOrgAdmin($acme);
    // A sign-up elsewhere takes the address between the review and the insert: InviteUser maps the unique violation.
    User::creating(function (User $user) use ($beta): void {
        if ($user->email === 'raced@example.com') {
            DB::table('users')->insert([
                'organisation_id' => $beta->id, 'name' => 'Racer', 'email' => 'raced@example.com', 'password' => 'x',
                'status' => 'active', 'locale' => 'en', 'is_super_admin' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    Livewire::test('pages::invitations.bulk')
        ->set('roles', [$templateRoleId()])
        ->set('addresses', 'ada@example.com, raced@example.com, cleo@example.com')
        ->call('check')
        ->assertSet('rows.1.reason', null)
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('summary', [
            'sent' => ['ada@example.com', 'cleo@example.com'],
            'skipped' => [['email' => 'raced@example.com', 'reason' => __("This address can't be invited.")]],
        ]);

    expect(collect($deliveredMails())->map(fn (Email $mail): string => $mail->getTo()[0]->getAddress())->all())->toBe(['ada@example.com', 'cleo@example.com'])
        ->and(RateLimiter::attempts(UserActionLimit::bulkInvite()->key($admin)))->toBe(2);
});

it('reviews the rows again when it sends and skips an organisation archived meanwhile with the action\'s message (CD-6)', function () use ($templateRoleId, $deliveredMails): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($acme);
    $page = Livewire::test('pages::invitations.bulk')
        ->set('roles', [$templateRoleId()])
        ->set('addresses', "ada@example.com\nbob@example.com")
        ->call('check');

    // Bob signed up in the meantime; then the organisation was archived.
    User::factory()->for($acme)->create(['email' => 'bob@example.com']);
    DB::table('organisations')->where('id', $acme->id)->update(['deleted_at' => now()]);

    $page->call('send')->assertSet('summary', [
        'sent' => [],
        'skipped' => [
            ['email' => 'bob@example.com', 'reason' => __("This address can't be invited.")],
            ['email' => 'ada@example.com', 'reason' => __('This organisation is archived.')],
        ],
    ]);

    expect($deliveredMails())->toBe([])
        ->and(RateLimiter::attempts(UserActionLimit::bulkInvite()->key($admin)))->toBe(0);
});

it('refuses a list larger than what is left of the bulk limit and sends nothing (CD-6)', function () use ($templateRoleId, $deliveredMails): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($acme);
    UserActionLimit::bulkInvite()->hit($admin, cost: 98);

    $page = Livewire::test('pages::invitations.bulk')
        ->set('roles', [$templateRoleId()])
        ->set('addresses', 'ada@example.com, bob@example.com, cleo@example.com')
        ->call('check')
        ->call('send')
        ->assertHasErrors(['addresses' => __('Too many attempts. Try again in :seconds seconds.', ['seconds' => 3600])])
        ->assertSet('summary', null);

    expect($deliveredMails())->toBe([])
        ->and(DB::table('users')->where('email', 'ada@example.com')->exists())->toBeFalse()
        // A refused list costs nothing, so a smaller one still fits.
        ->and(RateLimiter::attempts(UserActionLimit::bulkInvite()->key($admin)))->toBe(98);

    $page->set('addresses', 'ada@example.com, bob@example.com')->call('check')->call('send')->assertHasNoErrors();

    expect($deliveredMails())->toHaveCount(2)
        ->and(RateLimiter::attempts(UserActionLimit::bulkInvite()->key($admin)))->toBe(100);
});

it('counts nothing for a list larger than what is left, not even for a moment, so a parallel send still fits (CD-6)', function () use ($templateRoleId, $deliveredMails): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($acme);
    UserActionLimit::bulkInvite()->hit($admin, cost: 98);
    $page = Livewire::test('pages::invitations.bulk')
        ->set('roles', [$templateRoleId()])
        ->set('addresses', 'ada@example.com, bob@example.com, cleo@example.com')
        ->call('check');
    // A reservation that is given back still pushes the counter past the limit while it lasts; the pre-check refuses
    // first. The mock starts after the Check, whose address lookups count with increment() (FM-18).
    $limiter = Mockery::mock(CacheRateLimiter::class, [Cache::store()])->makePartial();
    $limiter->shouldNotReceive('increment');
    RateLimiter::swap($limiter);

    $page->call('send')
        ->assertHasErrors(['addresses' => __('Too many attempts. Try again in :seconds seconds.', ['seconds' => 3600])]);

    expect($deliveredMails())->toBe([])
        ->and(RateLimiter::attempts(UserActionLimit::bulkInvite()->key($admin)))->toBe(98);
});

it('gives back a reservation a parallel send outran (CD-6, plan review K4)', function () use ($templateRoleId, $deliveredMails): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($acme);
    UserActionLimit::bulkInvite()->hit($admin, cost: 98);
    // A parallel send passed the same pre-check a moment earlier: ensureAvailable() sees room, reserve() does not.
    // A partial mock built on the real cache store, so every other call still counts (a bare partialMock() has no store).
    $limiter = Mockery::mock(CacheRateLimiter::class, [Cache::store()])->makePartial();
    $limiter->shouldReceive('tooManyAttempts')->andReturnFalse();
    RateLimiter::swap($limiter);

    Livewire::test('pages::invitations.bulk')
        ->set('roles', [$templateRoleId()])
        ->set('addresses', 'ada@example.com, bob@example.com, cleo@example.com')
        ->call('check')
        ->call('send')
        ->assertHasErrors(['addresses'])
        ->assertSet('summary', null);

    expect($deliveredMails())->toBe([])
        ->and(RateLimiter::attempts(UserActionLimit::bulkInvite()->key($admin)))->toBe(98);
});

it('refuses a Check whose rows would pass the address-lookup limit before it reviews a row, and counts it (FM-18, CD-2)', function () use ($templateRoleId, $snapshotOf): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($acme);
    $lookups = UserActionLimit::addressLookup()->key($admin);
    UserActionLimit::addressLookup()->hit($admin, cost: 450);
    $snapshot = pageSnapshot(route('invitations.bulk'), 'pages::invitations.bulk');
    $hundred = implode("\n", array_map(fn (int $index): string => "person{$index}@example.com", range(1, 100)));

    $refused = $snapshotOf(callPageAction($snapshot, 'check', updates: ['addresses' => $hundred, 'roles' => [$templateRoleId()]]));

    expect($refused['memo']['errors'] ?? [])->toBe(['addresses' => [__('Too many attempts. Try again in :seconds seconds.', ['seconds' => 86400])]])
        ->and($refused['data']['rows'])->toBe([[], ['s' => 'arr']])
        // attempt() counts the refused list too, so the next Check of one row is refused as well.
        ->and(RateLimiter::attempts($lookups))->toBe(550);

    $single = $snapshotOf(callPageAction($snapshot, 'check', updates: ['addresses' => 'ada@example.com', 'roles' => [$templateRoleId()]]));

    expect($single['memo']['errors'] ?? [])->toHaveKey('addresses')
        ->and($single['data']['rows'])->toBe([[], ['s' => 'arr']]);
});

it('charges a Check one lookup per row after the list checks, and never charges the Send that follows (FM-18, CD-2)', function () use ($templateRoleId, $snapshotOf, $deliveredMails): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($acme);
    $lookups = UserActionLimit::addressLookup()->key($admin);
    $snapshot = pageSnapshot(route('invitations.bulk'), 'pages::invitations.bulk');
    $tooMany = implode("\n", array_map(fn (int $index): string => "person{$index}@example.com", range(1, 101)));

    // A list the page refuses before it reviews a row looks nothing up.
    expect($snapshotOf(callPageAction($snapshot, 'check', updates: ['addresses' => $tooMany, 'roles' => [$templateRoleId()]]))['memo']['errors'] ?? [])
        ->toBe(['addresses' => [__('Add at most :max addresses at a time.', ['max' => 100])]])
        ->and(RateLimiter::attempts($lookups))->toBe(0);

    // Every row counts, an invalid and a repeated one included.
    $checked = $snapshotOf(callPageAction($snapshot, 'check', updates: ['addresses' => 'ada@example.com, not-an-address, bob@example.com, ada@example.com', 'roles' => [$templateRoleId()]]));

    expect($checked['memo']['errors'] ?? [])->toBe([])
        ->and(RateLimiter::attempts($lookups))->toBe(4);

    // Send reviews only the rows the server checked (#[Locked]), so it looks up nothing new.
    expect($snapshotOf(callPageAction($checked, 'send'))['memo']['errors'] ?? [])->toBe([])
        ->and($deliveredMails())->toHaveCount(2)
        ->and(RateLimiter::attempts($lookups))->toBe(4)
        ->and(RateLimiter::attempts(UserActionLimit::bulkInvite()->key($admin)))->toBe(2);
});

it('sends only the rows the server checked, whatever a forged update says (CD-6)', function () use ($templateRoleId, $snapshotOf, $deliveredMails): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $beta = Organisation::factory()->create(['slug' => 'beta']);
    User::factory()->for($beta)->create(['email' => 'elsewhere@example.com']);
    asOrgAdmin($acme);
    $snapshot = pageSnapshot(route('invitations.bulk'), 'pages::invitations.bulk');

    $checked = $snapshotOf(callPageAction($snapshot, 'check', updates: ['addresses' => 'ada@example.com, elsewhere@example.com', 'roles' => [$templateRoleId()]]));
    expect($checked['memo']['errors'] ?? [])->toBe([]);
    $this->withoutExceptionHandling();

    // The preview cannot be written by the client: not before a check, and not after one.
    expect(fn (): TestResponse => callPageAction($snapshot, 'send', updates: ['rows' => [['email' => 'elsewhere@example.com', 'locale' => 'en', 'reason' => null]]]))
        ->toThrow(CannotUpdateLockedPropertyException::class)
        ->and(fn (): TestResponse => callPageAction($checked, 'send', updates: ['rows.1.reason' => null]))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect($deliveredMails())->toBe([])
        ->and(DB::table('users')->where('email', 'ada@example.com')->exists())->toBeFalse();
});

it('serves organisation admins on their host and refuses members (§6.2)', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    asOrgAdmin($acme);

    $this->get(route('invitations.bulk'))->assertOk()->assertSee(__('Invite several people'))->assertDontSee('data-test="bulk-organisation"', false);

    asMember($acme);
    $this->get(route('invitations.bulk'))->assertForbidden();

    // users.create without roles.assign may not invite (OR-M7).
    $creator = User::factory()->for($acme)->create();
    $role = RoleModel::query()->create(['name' => 'creator', 'guard_name' => 'web', 'team_id' => $acme->id]);
    $role->givePermissionTo([Permission::UsersView->value, Permission::UsersCreate->value]);
    withPermissionsTeam($acme->id, fn () => $creator->assignRole($role));
    $this->actingAs($creator);
    $this->get(route('invitations.bulk'))->assertForbidden();
});

it('lets a super admin on a central host invite after picking an organisation (§6.2, INV-17)', function () use ($templateRoleId): void {
    $acme = Organisation::factory()->create(['name' => 'Acme Corp', 'slug' => 'acme']);
    asSuperAdmin();
    onAdminHost();

    $this->get(route('invitations.bulk'))->assertOk()->assertSee('data-test="bulk-organisation"', false)->assertSee('Acme Corp');

    $page = Livewire::test('pages::invitations.bulk')
        ->set('addresses', 'first@example.com')
        ->set('roles', [$templateRoleId()])
        ->call('check')
        ->assertHasErrors(['organisationId' => __('Choose an organisation first.')]);

    // A new pick clears the roles and the preview.
    $page->set('organisationId', $acme->id)
        ->assertSet('roles', [])
        ->set('roles', [$templateRoleId()])
        ->call('check')
        ->call('send')
        ->assertHasNoErrors();

    expect(Tenant::run($acme, fn (): int => Invitation::query()->count()))->toBe(1);
});

it('refuses a signed-in user who is not a super admin on a central host (§6.2)', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    asOrgAdmin($acme);
    onApex();

    // Over HTTP the membership middleware signs them out first; the page refuses them itself too.
    $this->get(route('invitations.bulk'))->assertRedirect(route('login'));

    asOrgAdmin($acme);
    onApex();
    Livewire::test('pages::invitations.bulk')->assertForbidden();
});

it('ignores a forged organisation id on a tenant host', function () use ($templateRoleId): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $beta = Organisation::factory()->create(['slug' => 'beta']);
    asOrgAdmin($acme);

    Livewire::test('pages::invitations.bulk')
        ->set('organisationId', $beta->id)
        ->set('roles', [$templateRoleId()])
        ->set('addresses', 'someone@example.com')
        ->call('check')
        ->call('send')
        ->assertHasNoErrors();

    expect(DB::table('invitations')->where('organisation_id', $acme->id)->count())->toBe(1)
        ->and(DB::table('invitations')->where('organisation_id', $beta->id)->count())->toBe(0);
});

it('offers "Invite several people" on the pending list only to users who may invite (OR-M7)', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    asOrgAdmin($acme);

    $this->get(route('invitations.pending'))->assertOk()
        ->assertSee('data-test="bulk-invite-button"', false)
        ->assertSee('href="'.route('invitations.bulk').'"', false);

    $viewer = User::factory()->for($acme)->create();
    $role = RoleModel::query()->create(['name' => 'viewer', 'guard_name' => 'web', 'team_id' => $acme->id]);
    $role->givePermissionTo([Permission::UsersView->value, Permission::UsersCreate->value]);
    withPermissionsTeam($acme->id, fn () => $viewer->assignRole($role));
    $this->actingAs($viewer);

    $this->get(route('invitations.pending'))->assertOk()->assertDontSee('data-test="bulk-invite-button"', false);
});
