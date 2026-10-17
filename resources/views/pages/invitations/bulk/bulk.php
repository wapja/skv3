<?php

declare(strict_types=1);

use App\Actions\Invitations\InviteUser;
use App\Concerns\ChoosesInvitationOrganisation;
use App\Enums\Locale;
use App\Models\Organisation;
use App\Support\Invitations\InvitationList;
use App\Support\RateLimits\UserActionLimit;
use App\Tenancy\Auth\Impersonation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Invite several people at once (§6.6, §4.5, CD-6): paste addresses or upload a CSV file, choose the roles and the
 * default language once, "Check" the list, then "Send" it. Authorised as users.invite: on a tenant host for the
 * host's organisation, on a central host super admins only, with an organisation picker.
 *
 * "Check" parses the input with InvitationList and reviews every row: the address format, a duplicate in the list,
 * an unknown language and InviteUser's address rules. An address InviteUser would refuse gets one generic message,
 * whatever the reason, so the list never tells which addresses exist elsewhere (§4.5). The preview is #[Locked]:
 * "Send" sends only the rows the server checked, and reviews them again first. Each ready row reserves one attempt of
 * the bulk limit before the first send (UserActionLimit::bulkInvite()); the rows InviteUser refuses are given back.
 */
new #[Title('Invite several people')] class extends Component
{
    use ChoosesInvitationOrganisation, WithFileUploads;

    /** Pasted addresses: one per line, or separated by commas or semicolons. */
    public string $addresses = '';

    /** A CSV file with an email column and an optional locale column. */
    public ?TemporaryUploadedFile $csv = null;

    /** The language of every row that names none. */
    public string $locale = '';

    /** @var list<int|string> Checkbox values arrive as strings. */
    public array $roles = [];

    /** The organisation picked on a central host; a tenant host always uses its own organisation (RK-I4). */
    public ?int $organisationId = null;

    /** @var list<array{email: string, locale: string, reason: string|null}> The checked rows; the client never changes them. */
    #[Locked]
    public array $rows = [];

    /** @var array{sent: list<string>, skipped: list<array{email: string, reason: string}>}|null The outcome of the last send. */
    #[Locked]
    public ?array $summary = null;

    public function mount(): void
    {
        $this->authorizeInvitePage();

        $this->locale = Locale::default()->value;
    }

    /** A changed input makes the preview stale: it is checked again before anything is sent. */
    public function updated(string $property): void
    {
        if (in_array($property, ['addresses', 'csv', 'locale', 'organisationId'], true) || str_starts_with($property, 'roles')) {
            $this->rows = [];
        }
    }

    /**
     * A file chosen in place of another one: the earlier temporary upload, a list of addresses, is deleted. Livewire
     * would keep it until its 24-hour cleanup at a later upload.
     */
    public function updatingCsv(mixed $value): void
    {
        if ($this->csv instanceof TemporaryUploadedFile && (! $value instanceof TemporaryUploadedFile || $value->getFilename() !== $this->csv->getFilename())) {
            $this->csv->delete();
        }
    }

    public function removeCsv(): void
    {
        $this->csv?->delete();
        $this->csv = null;
        $this->rows = [];
    }

    /** Parses the input and reviews every row, one address lookup each (FM-18); nothing is written or sent. */
    public function check(InviteUser $inviteUser): void
    {
        $this->summary = null;
        $this->rows = [];
        $this->validateBatch();
        $this->validate([
            'addresses' => ['nullable', 'string', 'max:30000'],
            'csv' => ['nullable', 'file', 'mimes:csv,txt', 'max:512'],
        ]);

        $organisation = $this->organisationToInvite();
        $entries = [
            ...InvitationList::fromText($this->addresses),
            ...($this->csv instanceof TemporaryUploadedFile ? InvitationList::fromCsv((string) $this->csv->get()) : []),
        ];

        if ($entries === []) {
            throw ValidationException::withMessages(['addresses' => __('Paste addresses or choose a CSV file.')]);
        }

        if (count($entries) > InvitationList::MAX_ROWS) {
            throw ValidationException::withMessages(['addresses' => __('Add at most :max addresses at a time.', ['max' => InvitationList::MAX_ROWS])]);
        }

        // FM-18: review() looks every row up with InviteUser's address rules, so each row counts, an invalid or a
        // repeated one included, and is never given back (CD-2); during an impersonation the impersonator pays.
        // send() re-reviews only these #[Locked] rows.
        UserActionLimit::addressLookup()->attempt(Impersonation::impersonator() ?? $this->currentUser(), 'addresses', count($entries));

        $this->rows = $this->review($entries, $organisation, $inviteUser);
    }

    /**
     * Sends the checked rows that are still ready, one InviteUser call each. A row InviteUser refuses is skipped with
     * its message and gives its reserved attempt back.
     */
    public function send(InviteUser $inviteUser): void
    {
        if ($this->rows === []) {
            throw ValidationException::withMessages(['addresses' => __('Check the list first.')]);
        }

        $this->validateBatch();
        $organisation = $this->organisationToInvite();
        $actor = $this->currentUser();
        $roleIds = array_map(fn (int|string $roleId): int => (int) $roleId, $this->roles);

        // Reviewed again: the list may have changed since it was checked, by this page or another.
        $rows = $this->review($this->rows, $organisation, $inviteUser);
        $ready = [];
        $skipped = [];

        foreach ($rows as $row) {
            if ($row['reason'] === null) {
                $ready[] = $row;
            } else {
                $skipped[] = ['email' => $row['email'], 'reason' => $row['reason']];
            }
        }

        if ($ready === []) {
            $this->rows = $rows;

            throw ValidationException::withMessages(['addresses' => __('No address in this list can be invited.')]);
        }

        // A list larger than what is left of the window is refused before it counts, so a smaller one still fits;
        // reserve() then counts atomically, and refuses a parallel send that took the room meanwhile. That refused
        // list sent nothing, so its cost is given back at once (plan review K4).
        $limit = UserActionLimit::bulkInvite();
        $limit->ensureAvailable($actor, 'addresses', count($ready));

        try {
            $limit->reserve($actor, 'addresses', count($ready));
        } catch (ValidationException $exception) {
            $limit->release($actor, count($ready));

            throw $exception;
        }

        $sent = [];

        try {
            foreach ($ready as $row) {
                try {
                    $inviteUser->handle($actor, $organisation, $row['email'], $roleIds, Locale::from($row['locale']));
                    $sent[] = $row['email'];
                } catch (ValidationException $exception) {
                    $skipped[] = ['email' => $row['email'], 'reason' => $this->reasonFor($exception)];
                }
            }
        } finally {
            // Refused rows, and the rows a failure stopped before they were sent, give their attempts back.
            $unused = count($ready) - count($sent);

            if ($unused > 0) {
                $limit->release($actor, $unused);
            }
        }

        $this->rows = [];
        $this->addresses = '';
        $this->csv?->delete();
        $this->csv = null;
        $this->summary = ['sent' => $sent, 'skipped' => $skipped];
    }

    /** The preview's rows that "Send" would send. */
    #[Computed]
    public function readyCount(): int
    {
        return count(array_filter($this->rows, fn (array $row): bool => $row['reason'] === null));
    }

    /** Back to an empty form after a summary. */
    public function startOver(): void
    {
        $this->summary = null;
    }

    /**
     * The batch's language and roles; roles are required as InviteUser requires them, so the preview is not all
     * "Ready" for a list that cannot be sent.
     *
     * @throws ValidationException
     */
    private function validateBatch(): void
    {
        $this->validate([
            'locale' => ['required', 'string', Rule::enum(Locale::class)],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['integer'],
        ], [
            'roles.required' => __('Choose at least one role.'),
            'roles.min' => __('Choose at least one role.'),
        ]);
    }

    /**
     * Each row with "Ready" (reason null) or the reason it is skipped. A row without a language gets the batch's.
     *
     * @param  list<array{email: string, locale: string|null, reason?: string|null}>  $entries
     * @return list<array{email: string, locale: string, reason: string|null}>
     */
    private function review(array $entries, Organisation $organisation, InviteUser $inviteUser): array
    {
        $addressRules = $inviteUser->addressRules($organisation);
        $seen = [];
        $rows = [];

        foreach ($entries as $entry) {
            $email = $entry['email'];
            $locale = $entry['locale'] ?? $this->locale;
            $rows[] = ['email' => $email, 'locale' => $locale, 'reason' => $this->skipReason($email, $locale, isset($seen[$email]), $addressRules)];
            $seen[$email] = true;
        }

        return $rows;
    }

    /**
     * Why a row is skipped, or null when it is ready. Every refusal of InviteUser's address rules is one generic
     * message: the list never tells whether an address is a member here, invited here or used elsewhere (§4.5).
     *
     * @param  array<int, mixed>  $addressRules
     */
    private function skipReason(string $email, string $locale, bool $listedBefore, array $addressRules): ?string
    {
        if (Validator::make(['email' => $email], ['email' => ['required', 'string', 'email', 'max:255']])->fails()) {
            return __('Not a valid email address.');
        }

        if ($listedBefore) {
            return __('Listed more than once.');
        }

        if (! Locale::tryFrom($locale) instanceof Locale) {
            return __('Unknown language.');
        }

        if (Validator::make(['email' => $email], ['email' => $addressRules])->fails()) {
            return __("This address can't be invited.");
        }

        return null;
    }

    /**
     * InviteUser's refusal of one row. An address refusal is the generic message, as in the preview (§4.5); any other
     * (an organisation archived meanwhile, a role that is gone) is the action's own message.
     */
    private function reasonFor(ValidationException $exception): string
    {
        $errors = $exception->errors();
        $message = Arr::first(Arr::flatten($errors));

        return isset($errors['email']) || ! is_string($message) ? __("This address can't be invited.") : $message;
    }
};
