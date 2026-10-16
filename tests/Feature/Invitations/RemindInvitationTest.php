<?php

declare(strict_types=1);

/** covers §4.5, §6.2, Appendix A W5, X11 */

use App\Actions\Invitations\RemindInvitation;
use App\Enums\ActivityEvent;
use App\Enums\Locale;
use App\Models\Organisation;
use App\Tenancy\Facades\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

beforeEach(function (): void {
    config(['queue.default' => 'database']);
});

$queuedReminders = fn (): int => DB::table('jobs')->pluck('payload')
    ->filter(fn (mixed $payload): bool => str_contains((string) $payload, 'InvitationReminderMail'))
    ->count();

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

it('renews the expiry, sets reminder_sent_at and queues a reminder (INV-10)', function () use ($queuedReminders): void {
    // Whole seconds: the datetime columns store no microseconds.
    $this->freezeSecond();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($organisation);
    $invitation = inviteMember($organisation, $admin);
    $token = $invitation->token;
    $this->travel(3)->days();

    app(RemindInvitation::class)->handle($admin, $invitation);

    expect($invitation->expires_at->equalTo(now()->addDays(7)))->toBeTrue()
        ->and($invitation->reminder_sent_at?->equalTo(now()))->toBeTrue()
        ->and($invitation->token)->toBe($token)
        ->and($queuedReminders())->toBe(1);
});

it('refuses to remind an accepted invitation and sends nothing (W5)', function () use ($queuedReminders): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($organisation);
    $invitation = inviteMember($organisation, $admin);
    $invitation->forceFill(['accepted_at' => now()])->save();

    expect(fn () => app(RemindInvitation::class)->handle($admin, $invitation))
        ->toThrow(ValidationException::class, __('This invitation has already been accepted.'));
    expect($invitation->refresh()->reminder_sent_at)->toBeNull()
        ->and($queuedReminders())->toBe(0);
});

it('sends one reminder per invitation per ten minutes, whoever sends it (CD-3, G1-01)', function () use ($queuedReminders): void {
    $this->freezeSecond();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($organisation);
    $invitation = inviteMember($organisation, $admin);
    DB::table('jobs')->delete();
    $superAdmin = asSuperAdmin();
    onTenant($organisation);

    app(RemindInvitation::class)->handle($admin, $invitation);
    $this->travel(9)->minutes();

    expect(fn () => app(RemindInvitation::class)->handle($superAdmin, $invitation))
        ->toThrow(ValidationException::class, __('This invitation was sent again less than :minutes minutes ago.', ['minutes' => 10]));
    expect($queuedReminders())->toBe(1);

    $this->travel(61)->seconds();
    app(RemindInvitation::class)->handle($superAdmin, $invitation);

    expect($queuedReminders())->toBe(2)
        ->and($invitation->refresh()->reminder_sent_at?->equalTo(now()))->toBeTrue();
});

it('reminds an expired invitation', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($organisation);
    $invitation = inviteMember($organisation, $admin);
    $this->travel(8)->days();

    expect($invitation->isExpired())->toBeTrue();

    app(RemindInvitation::class)->handle($admin, $invitation);

    expect($invitation->isExpired())->toBeFalse();
});

it('builds the reminder\'s accept link on the organisation\'s host in the worker, also when a super admin reminds from the admin host (CV-12)', function () use ($deliveredMails): void {
    $organisation = Organisation::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $superAdmin = asSuperAdmin();
    onAdminHost();
    $invitation = inviteMember($organisation, $superAdmin);
    DB::table('jobs')->delete();

    app(RemindInvitation::class)->handle($superAdmin, $invitation);

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);

    // The action binds the organisation itself (Tenant::run), so the payload carries its id even when the request is on the admin host.
    expect($payload['tenant_id'] ?? null)->toBe($organisation->id);

    // The link must not depend on the worker's tenant: let the worker run the job centrally.
    DB::table('jobs')->update(['payload' => json_encode([...$payload, 'tenant_id' => null], JSON_THROW_ON_ERROR)]);

    Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true, '--tries' => 1]);

    $mail = $deliveredMails()[0] ?? throw new RuntimeException('No reminder was delivered.');
    $html = $mail->getHtmlBody();
    preg_match('#https?://[^\s"<>]+/invitations/accept/[^\s"<>]+#', html_entity_decode(is_string($html) ? $html : ''), $matches);
    $link = $matches[0] ?? throw new RuntimeException('The reminder holds no accept link.');
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

    expect($mail->getSubject())->toBe(__('Reminder: your invitation to :organisation is waiting', ['organisation' => 'Acme']))
        ->and($link)->toStartWith('http://acme.localhost/invitations/accept/')
        ->and($query['expires'] ?? null)->toBe((string) $invitation->refresh()->expires_at->copy()->addDays(30)->getTimestamp());
});

it('writes the reminder in the invitee\'s language with a localised expiry date (X11)', function () use ($deliveredMails): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-11 09:00:00'));
    $organisation = Organisation::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $admin = asOrgAdmin($organisation);
    $invitation = inviteMember($organisation, $admin);
    Tenant::run($organisation, fn (): bool => $invitation->user->forceFill(['locale' => Locale::Dutch])->save());
    // Keep only the reminder: the invitation mail was queued too.
    DB::table('jobs')->delete();

    app(RemindInvitation::class)->handle($admin, $invitation);
    Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true, '--tries' => 1]);

    $mail = $deliveredMails()[0] ?? throw new RuntimeException('No reminder was delivered.');
    $html = $mail->getHtmlBody();

    expect($mail->getSubject())->toBe('Herinnering: je uitnodiging voor Acme wacht op je')
        ->and(html_entity_decode(is_string($html) ? $html : ''))->toContain('18 oktober 2026');
});

it('refuses another organisation\'s admin', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $invitation = inviteMember($acme);
    $betaAdmin = asOrgAdmin(Organisation::factory()->create(['slug' => 'beta']));

    expect(fn () => app(RemindInvitation::class)->handle($betaAdmin, $invitation))->toThrow(AuthorizationException::class);
});

it('refuses a same-organisation user without users.invite.resend and sends nothing', function () use ($queuedReminders): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $invitation = inviteMember($organisation);
    $member = asMember($organisation);
    $this->travel(8)->days();

    expect(fn () => app(RemindInvitation::class)->handle($member, $invitation))->toThrow(AuthorizationException::class);
    expect($invitation->refresh()->reminder_sent_at)->toBeNull()
        ->and($invitation->isExpired())->toBeTrue()
        ->and($queuedReminders())->toBe(0);
});

it('refuses a forged resend on the accept page from a guest or a member (INV-11)', function (string $visitor) use ($queuedReminders): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $invitation = inviteMember($organisation);
    $token = $invitation->token ?? throw new LogicException('The invitation keeps no token.');

    if ($visitor === 'member') {
        asMember($organisation);
    } else {
        onTenant($organisation);
    }

    $this->travel(8)->days();

    Livewire::test('pages::invitations.accept', ['token' => $token])
        ->call('resend')
        ->assertForbidden();

    expect($invitation->refresh()->reminder_sent_at)->toBeNull()
        ->and($invitation->isExpired())->toBeTrue()
        ->and($queuedReminders())->toBe(0);
})->with(['guest', 'member']);

it('records invitation.reminder_sent', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($organisation);
    $invitation = inviteMember($organisation, $admin);

    app(RemindInvitation::class)->handle($admin, $invitation);

    $reminded = recordedActivity(ActivityEvent::InvitationReminderSent)->sole();

    expect($reminded->subject_id)->toBe($invitation->user_id)
        ->and($reminded->causer_id)->toBe($admin->id)
        ->and($reminded->organisation_id)->toBe($organisation->id)
        ->and($reminded->properties?->get('invitation_id'))->toBe($invitation->id);
});
