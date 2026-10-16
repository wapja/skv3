<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Audit\RecordActivity;
use App\Authorization\SetSuperAdmin;
use App\Concerns\ProfileValidationRules;
use App\Console\Concerns\ReadsStandardInput;
use App\Enums\ActivityEvent;
use App\Enums\UserStatus;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates a super admin, or promotes an existing user (§4.12, RL-9). A new address becomes an active user without an
 * organisation (W8); an existing one is promoted through SetSuperAdmin, whose guards apply, and a --name, or a
 * password given with --password-stdin, replaces an existing user's own, as skv:install does with a given password.
 * The password never travels in argv (CD-8) and is never echoed.
 */
#[Description('Create a super admin, or promote an existing user (§4.12)')]
#[Signature('skv:create-super-admin {email? : The email address} {--name= : The name (replaces an existing user\'s)} {--password-stdin : Read the password from the first line of standard input (replaces an existing user\'s)}')]
final class CreateSuperAdminCommand extends Command
{
    use ProfileValidationRules;
    use ReadsStandardInput;

    private ?string $password = null;

    private bool $passwordRead = false;

    public function handle(SetSuperAdmin $setSuperAdmin): int
    {
        // Artisan keeps one instance of the command per process: a second run must read its own standard input.
        $this->password = null;
        $this->passwordRead = false;

        $email = $this->argument('email') ?? ($this->input->isInteractive() ? text(__('Email address'), required: true) : null);

        if (! is_string($email) || trim($email) === '') {
            $this->components->error(__('Pass the email address: php artisan skv:create-super-admin you@example.com'));

            return self::FAILURE;
        }

        if ($this->option('password-stdin') && $this->givenPassword() === null) {
            $this->components->error(__('No password on standard input.'));

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
                $this->promote($setSuperAdmin, $existing);
            } else {
                $setSuperAdmin->createNew($this->newUserAttributes($email));
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
     * Promotes an existing user and applies a given name and password, validated before anything changes. A new
     * password changes the remember token and is recorded (§4.8), and AuthenticateSession signs the user's other
     * sessions out; UserActivityObserver records a new name.
     *
     * @throws ValidationException
     */
    private function promote(SetSuperAdmin $setSuperAdmin, User $user): void
    {
        $name = $this->option('name');

        /** @var array{name?: string, password?: string} $changes */
        $changes = Validator::make(array_filter(['name' => is_string($name) ? trim($name) : null, 'password' => $this->givenPassword()], fn (mixed $value): bool => $value !== null), [
            'name' => ['sometimes', ...$this->nameRules()],
            'password' => ['sometimes', 'required', 'string', Password::default()],
        ])->validate();

        DB::transaction(function () use ($setSuperAdmin, $user, $changes): void {
            $setSuperAdmin->handle($user, true);

            if ($changes === []) {
                return;
            }

            $user->forceFill(array_filter([
                'name' => $changes['name'] ?? null,
                'password' => $changes['password'] ?? null,
                'remember_token' => isset($changes['password']) ? Str::random(60) : null,
            ], fn (?string $value): bool => $value !== null))->save();

            if (isset($changes['password'])) {
                app(RecordActivity::class)->handle(ActivityEvent::PasswordChanged, $user);
            }
        });
    }

    /**
     * The name, address and password of a new super admin, validated before anything is written.
     * SetSuperAdmin::createNew() inserts the user with the flag and completes it in one transaction (RL-9).
     *
     * @return array{email: string, name: string, password: string}
     *
     * @throws ValidationException
     */
    private function newUserAttributes(string $email): array
    {
        $name = $this->option('name') ?? ($this->input->isInteractive() ? text(__('Name'), required: true) : 'Super admin');
        $password = $this->givenPassword() ?? ($this->input->isInteractive() ? password(__('Password'), required: true) : null);

        /** @var array{email: string, name: string, password: string} $valid */
        $valid = Validator::make(['email' => $email, 'name' => trim($name), 'password' => $password], [
            'email' => $this->emailRules(),
            'name' => $this->nameRules(),
            'password' => ['required', 'string', Password::default()],
        ])->validate();

        return $valid;
    }

    /** The password given on standard input with --password-stdin (CD-8), read once; null without the flag. */
    private function givenPassword(): ?string
    {
        if (! $this->option('password-stdin')) {
            return null;
        }

        if (! $this->passwordRead) {
            $this->password = $this->firstLineOfStandardInput();
            $this->passwordRead = true;
        }

        return $this->password;
    }
}
