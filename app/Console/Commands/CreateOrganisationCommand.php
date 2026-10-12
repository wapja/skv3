<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Organisations\CreateOrganisation;
use App\Enums\Locale;
use App\Enums\UserStatus;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\text;

/**
 * Creates an organisation and invites its first admin through CreateOrganisation (§4.12, DEC-28, RL-9). The actor is
 * the oldest active super admin, never the signed-in user. The accept URL is never printed.
 */
#[Description('Create an organisation and invite its first admin (§4.12)')]
#[Signature('skv:create-organisation {name? : The organisation name} {slug? : The address (subdomain)} {admin-email? : The first admin\'s email address} {--description= : An optional description} {--locale=nl : The first admin\'s language}')]
final class CreateOrganisationCommand extends Command
{
    public function handle(CreateOrganisation $createOrganisation): int
    {
        $actor = Tenant::bypass(fn (): ?User => User::query()
            ->where('is_super_admin', true)
            ->where('status', UserStatus::Active)
            ->oldest('id')
            ->first());

        if (! $actor instanceof User) {
            $this->components->error(__('Create a super admin first: php artisan skv:create-super-admin'));

            return self::FAILURE;
        }

        $name = $this->value('name', __('Name'));
        $slug = $this->value('slug', __('Address'));
        $email = $this->value('admin-email', __('Email address'));
        $description = $this->option('description');
        $locale = $this->option('locale');

        try {
            $createOrganisation->handle(
                $actor,
                $name,
                $slug,
                is_string($description) ? $description : null,
                $email,
                (is_string($locale) ? Locale::tryFrom($locale) : null) ?? Locale::default(),
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                $this->components->error(implode(' ', $messages));
            }

            return self::FAILURE;
        } catch (AuthorizationException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(__('Invitation sent to :email.', ['email' => $email]));

        return self::SUCCESS;
    }

    /** The argument, or a prompt for it when interactive; an empty string otherwise, which validation refuses. */
    private function value(string $argument, string $label): string
    {
        $value = $this->argument($argument) ?? ($this->input->isInteractive() ? text($label, required: true) : '');

        return is_string($value) ? $value : '';
    }
}
