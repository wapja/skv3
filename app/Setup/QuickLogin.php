<?php

declare(strict_types=1);

namespace App\Setup;

use App\Actions\Auth\IssueHandoff;
use App\Enums\UserStatus;
use App\Models\User;
use App\Setup\Http\DirectRequest;
use App\Tenancy\Facades\Tenant;
use Database\Seeders\DemoSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;
use JsonException;

/**
 * §6.5, RL-9: one click into a demo account, on a local demo install only, for a request straight from this machine
 * (DEC-24: not through `herd share`, a proxy or the network, g3-15). The accounts are DemoSeeder's; one that is not
 * active, has two-factor authentication or no longer uses the demo password is not offered (the handoff skips the
 * second factor by design).
 */
final readonly class QuickLogin
{
    public function __construct(private SetupContext $context) {}

    /** @return list<array{email: string, role: string, host: string}> */
    public function accounts(): array
    {
        if (! DirectRequest::isDirect(request())) {
            return [];
        }

        try {
            $marker = Marker::read($this->context);
        } catch (JsonException) {
            return [];
        }

        if (($marker['start_with'] ?? null) !== 'demo') {
            return [];
        }

        $accounts = DemoSeeder::accounts();
        $usable = Tenant::bypass(fn (): array => User::query()
            ->whereIn('email', array_column($accounts, 'email'))
            ->where('status', UserStatus::Active)
            ->whereNull('two_factor_confirmed_at')
            ->pluck('email')
            ->all());

        return array_values(array_filter($accounts, fn (array $account): bool => in_array($account['email'], $usable, true)));
    }

    /** The handoff into $email's own host; null when it is not offered (404), false when its password changed. */
    public function handoffUrl(string $email): string|false|null
    {
        $account = collect($this->accounts())->firstWhere('email', $email);

        if (! is_array($account)) {
            return null;
        }

        $user = Tenant::bypass(fn (): ?User => User::query()->where('email', $email)->first());

        if (! $user instanceof User) {
            return null;
        }

        if (! Hash::check(DemoSeeder::PASSWORD, (string) $user->getAuthPassword())) {
            return false;
        }

        // IssueHandoff refuses a user who may not enter the host (an archived or deleted organisation): RL-9 says 404.
        try {
            return app(IssueHandoff::class)->handle($user, $account['host']);
        } catch (AuthorizationException) {
            return null;
        }
    }
}
