<?php

declare(strict_types=1);

namespace App\Actions\Invitations;

use App\Actions\Audit\RecordActivity;
use App\Actions\Users\UpdateUserRoles;
use App\Concerns\ProfileValidationRules;
use App\Enums\ActivityEvent;
use App\Enums\Locale;
use App\Enums\UserStatus;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use App\Tenancy\HostScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * Invites an address into an organisation (§4.5). In one transaction: the organisation is locked
 * and re-checked before the tenant is bound (RK-I11), the address must be available (W9, X2), a
 * pending user is created with an explicit organisation and never as super admin (W8), its roles
 * are attached through UpdateUserRoles so the escalation guard applies (X5) and at least one role
 * is required (W14), and the invitation keeps only a hash and an encrypted copy of the token (W13).
 * The mail is queued after commit and builds its link in the worker (W10, FU3-14). Team and tenant
 * are restored on failure (W11). Bulk invitations (M7) reuse this action.
 */
final readonly class InviteUser
{
    use ProfileValidationRules;

    public function __construct(
        private UpdateUserRoles $updateUserRoles,
        private RecordActivity $recordActivity,
    ) {}

    /**
     * @param  list<int>  $roleIds
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Organisation $organisation, string $email, array $roleIds, Locale $locale): Invitation
    {
        Gate::forUser($actor)->authorize('create', [Invitation::class, $organisation]);

        $email = Str::lower(trim($email));

        try {
            return DB::transaction(function () use ($actor, $organisation, $email, $roleIds, $locale): Invitation {
                // Before Tenant::run(): inside it the "is this the bound tenant" check would be trivially true.
                $organisation = HostScope::lockForWrite($organisation);

                return Tenant::run($organisation, fn (): Invitation => $this->invite($actor, $organisation, $email, $roleIds, $locale));
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent invite or sign-up took the address between validation and insert.
            throw ValidationException::withMessages(['email' => __("This address can't be used.")]);
        }
    }

    /**
     * @param  list<int>  $roleIds
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function invite(User $actor, Organisation $organisation, string $email, array $roleIds, Locale $locale): Invitation
    {
        Validator::make(
            ['email' => $email, 'roles' => $roleIds],
            [
                'email' => $this->emailRules(organisationId: $organisation->id),
                'roles' => ['required', 'array', 'min:1'],
            ],
            [
                'roles.required' => __('Choose at least one role.'),
                'roles.min' => __('Choose at least one role.'),
            ],
        )->validate();

        // Reset tokens are keyed by address: one issued for this address belongs to whoever held it before.
        DB::table('password_reset_tokens')->where('email', $email)->delete();

        $invitee = new User;
        $invitee->forceFill([
            'organisation_id' => $organisation->id,
            'is_super_admin' => false,
            'status' => UserStatus::PendingActivation,
            'locale' => $locale,
            'name' => $email,
            'email' => $email,
            'password' => Str::password(32),
        ])->save();

        $this->updateUserRoles->handle($actor, $invitee, $roleIds);

        $token = $this->newToken();

        $invitation = new Invitation;
        $invitation->forceFill([
            'organisation_id' => $organisation->id,
            'user_id' => $invitee->id,
            'invited_by' => $actor->id,
            'token_hash' => hash('sha256', $token),
            'token' => $token,
            'expires_at' => now()->addDays((int) config('skv.invitations.expires_in_days', 7)),
        ])->save();

        $this->recordActivity->handle(ActivityEvent::InvitationSent, $invitee, [
            'invitation_id' => $invitation->id,
            'email' => $email,
            'roles' => $this->roleNames($roleIds),
        ], causer: $actor);

        Mail::to($email)->locale($locale->value)->queue(new InvitationMail($invitation));

        return $invitation;
    }

    private function newToken(): string
    {
        return Str::random(64);
    }

    /**
     * @param  list<int>  $roleIds
     * @return list<string>
     */
    private function roleNames(array $roleIds): array
    {
        return array_values(array_map(
            fn (mixed $name): string => (string) $name,
            RoleModel::query()->whereKey($roleIds)->orderBy('name')->pluck('name')->all(),
        ));
    }
}
