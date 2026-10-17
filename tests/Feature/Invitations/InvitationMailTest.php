<?php

declare(strict_types=1);

/** covers §4.5.1, §4.5.3, §4.5.5, §6.2, Appendix A W10, X11 */

use App\Actions\Invitations\AcceptInvitation;
use App\Actions\Invitations\CancelInvitation;
use App\Actions\Invitations\InviteUser;
use App\Actions\Invitations\RemindInvitation;
use App\Authorization\Role;
use App\Authorization\SetSuperAdmin;
use App\Enums\Locale;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role as RoleModel;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

beforeEach(function (): void {
    config(['queue.default' => 'database']);
});

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

$htmlOf = function (Email $mail): string {
    $html = $mail->getHtmlBody();

    return html_entity_decode(is_string($html) ? $html : '');
};

it('builds a signed accept link on the organisation\'s host when the worker sends it (TEN-6, FU3-14)', function () use ($deliveredMails, $htmlOf): void {
    $organisation = Organisation::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $invitation = inviteMember($organisation, asOrgAdmin($organisation));
    $token = $invitation->token ?? throw new LogicException('The invitation keeps no token.');

    // The queued job carries no link and no token: the worker builds the link (FU3-14).
    expect((string) DB::table('jobs')->value('payload'))->not->toContain($token)->not->toContain('signature');

    onAdminHost();
    Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true, '--tries' => 1]);

    $mails = $deliveredMails();
    expect($mails)->toHaveCount(1)
        ->and($mails[0]->getTo()[0]->getAddress())->toBe('invitee@example.com');

    preg_match('#https?://[^\s"<>]+/invitations/accept/[^\s"<>]+#', $htmlOf($mails[0]), $matches);
    $link = $matches[0] ?? throw new RuntimeException('The mail holds no accept link.');
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

    expect($link)->toStartWith('http://acme.localhost/invitations/accept/'.$token.'?')
        ->and($query['expires'] ?? null)->toBe((string) $invitation->expires_at->copy()->addDays(30)->getTimestamp())
        ->and($query)->toHaveKey('signature');
});

it('writes the mail in the invitee\'s language with a localised expiry date (X11)', function (Locale $locale, string $subject, string $date) use ($deliveredMails, $htmlOf): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-11 09:00:00'));
    $organisation = Organisation::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $superAdmin = User::factory()->superAdmin()->create();
    app(SetSuperAdmin::class)->handle($superAdmin, true);
    $templateId = (int) RoleModel::query()->whereNull('team_id')->where('name', Role::OrganisationAdmin->value)->value('id');

    app(InviteUser::class)->handle($superAdmin, $organisation, 'invitee@example.com', [$templateId], $locale);
    Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true, '--tries' => 1]);

    $mail = $deliveredMails()[0] ?? throw new RuntimeException('No mail was delivered.');

    expect($mail->getSubject())->toBe($subject)
        ->and($htmlOf($mail))->toContain($subject)->toContain($date);
})->with([
    'Dutch' => [Locale::Dutch, 'Je bent uitgenodigd voor Acme', '18 oktober 2026'],
    'English' => [Locale::English, 'You have been invited to Acme', 'October 18, 2026'],
]);

/** An invitation with its invitation mail and a reminder queued for the worker (G4-05). */
$queueBothMails = function (): Invitation {
    $organisation = Organisation::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $admin = asOrgAdmin($organisation);
    $invitation = inviteMember($organisation, $admin);
    app(RemindInvitation::class)->handle($admin, $invitation);

    expect(DB::table('jobs')->count())->toBe(2);

    return $invitation;
};

it('delivers both queued invitation mails while the invitation is open (W10)', function () use ($queueBothMails, $deliveredMails): void {
    $queueBothMails();

    // One --once run per queued job: the daemon loop (--stop-when-empty alone) installs SIGINT, SIGTERM and SIGQUIT
    // handlers and an alarm it never restores, which then hold for the rest of this test process.
    foreach (range(1, 2) as $job) {
        Artisan::call('queue:work', ['--once' => true, '--sleep' => 0, '--tries' => 1]);
    }

    // inviteMember() invites in the default language; the worker renders each mail in it.
    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(array_map(fn (Email $mail): ?string => $mail->getSubject(), $deliveredMails()))->toBe([
            __('You have been invited to :organisation', ['organisation' => 'Acme'], Locale::default()->value),
            __('Reminder: your invitation to :organisation is waiting', ['organisation' => 'Acme'], Locale::default()->value),
        ]);
});

it('drops a queued invitation mail without a failed job once the invitation is gone, accepted or expired (G4-05)', function (string $outcome) use ($queueBothMails, $deliveredMails): void {
    $invitation = $queueBothMails();

    if ($outcome === 'cancelled') {
        app(CancelInvitation::class)->handle(User::query()->findOrFail((int) $invitation->invited_by), $invitation);
    } elseif ($outcome === 'accepted') {
        app(AcceptInvitation::class)->handle($invitation, 'Ann Invitee', 'secret-password');
    } else {
        $this->travel(8)->days();
    }

    // One --once run per queued job, never the daemon loop (see above).
    foreach (range(1, 2) as $job) {
        Artisan::call('queue:work', ['--once' => true, '--sleep' => 0, '--tries' => 1]);
    }

    // A failed job would warn on the health report and the System page, and queue:retry would only fail it again.
    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($deliveredMails())->toBe([]);
})->with(['cancelled', 'accepted', 'expired']);
