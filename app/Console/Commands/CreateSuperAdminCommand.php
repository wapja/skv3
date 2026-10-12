<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Authorization\SetSuperAdmin;
use App\Concerns\ProfileValidationRules;
use App\Enums\Locale;
use App\Enums\UserStatus;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates a super admin, or promotes an existing user (§4.12, RL-9). A new address becomes an active user without an
 * organisation (W8); an existing one is promoted through SetSuperAdmin, whose guards apply. The password is never echoed.
 */
#[Description('Create a super admin, or promote an existing user (§4.12)')]
#[Signature('skv:create-super-admin {email? : The email address} {--name= : The name for a new user} {--password= : The password for a new user}')]
final class CreateSuperAdminCommand extends Command
{
    use ProfileValidationRules;

    public function handle(SetSuperAdmin $setSuperAdmin): int
    {
        $email = $this->argument('email') ?? ($this->input->isInteractive() ? text(__('Email address'), required: true) : null);

        if (! is_string($email) || trim($email) === '') {
            $this->components->error(__('Pass the email address: php artisan skv:create-super-admin you@example.com'));

            return self::FAILURE;
        }

        $email = mb_strtolower(trim($email));
        $existing = Tenant::bypass(fn (): ?User => User::query()->where('email', $email)->first());

        // SetSuperAdmin guards demotion only: a pending or disabled user would become a super admin who can never sign in.
        if ($existing instanceof User && $existing->status !== UserStatus::Active) {
            $this->components->error(__('Only an active user can become a super admin; :email is :status.', ['email' => $email, 'status' => $existing->status->label()]));

            return self::FAILURE;
        }

        try {
            if ($existing instanceof User) {
                $setSuperAdmin->handle($existing, true);
            } else {
                // One transaction: a new user never exists without the badge and the audit entry.
                DB::transaction(fn () => $setSuperAdmin->handleCreated($this->createUser($email)));
            }
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                $this->components->error(implode(' ', $messages));
            }

            return self::FAILURE;
        }

        $this->components->info(__(':email is a super admin.', ['email' => $email]));

        return self::SUCCESS;
    }

    /**
     * A new user carries the flag at insert: BelongsToOrganisation refuses a user without an organisation otherwise.
     * SetSuperAdmin::handleCreated() then attaches the badge and records the grant.
     *
     * @throws ValidationException
     */
    private function createUser(string $email): User
    {
        $name = $this->option('name') ?? ($this->input->isInteractive() ? text(__('Name'), required: true) : 'Super admin');
        $password = $this->option('password') ?? ($this->input->isInteractive() ? password(__('Password'), required: true) : null);

        /** @var array{email: string, name: string, password: string} $valid */
        $valid = Validator::make(['email' => $email, 'name' => trim($name), 'password' => $password], [
            'email' => $this->emailRules(),
            'name' => $this->nameRules(),
            'password' => ['required', 'string', Password::default()],
        ])->validate();

        $user = new User;
        $user->forceFill([
            'organisation_id' => null,
            'is_super_admin' => true,
            'status' => UserStatus::Active,
            'locale' => Locale::default(),
            'name' => $valid['name'],
            'email' => $valid['email'],
            'password' => $valid['password'],
        ])->save();

        return $user;
    }
}
