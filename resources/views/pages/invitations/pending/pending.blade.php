<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Pending invitations') }}</flux:heading>
            <flux:subheading size="lg">{{ __('Invitations that have not been accepted yet.') }}</flux:subheading>
        </div>

        @if ($this->canInvite)
            <flux:button variant="primary" icon="envelope" :href="route('users.invite')" wire:navigate data-test="invite-user-button">
                {{ __('Invite user') }}
            </flux:button>
        @endif
    </div>

    <flux:separator variant="subtle" class="my-6" />

    <flux:error name="invitation" class="mb-4" />

    @if ($this->showsOrganisations)
        <div class="mb-6 max-w-xs">
            <flux:select wire:model.live="organisation" :label="__('Organisation')" data-test="organisation-filter">
                <flux:select.option value="">{{ __('All organisations') }}</flux:select.option>
                @foreach ($this->organisationOptions as $id => $name)
                    <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    @endif

    <flux:table :paginate="$this->invitations">
        <flux:table.columns>
            <flux:table.column>{{ __('Email address') }}</flux:table.column>
            @if ($this->showsOrganisations)
                <flux:table.column>{{ __('Organisation') }}</flux:table.column>
            @endif
            <flux:table.column>{{ __('Invited by') }}</flux:table.column>
            <flux:table.column>{{ __('Expires') }}</flux:table.column>
            <flux:table.column align="end"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->invitations as $invitation)
                <flux:table.row :key="$invitation->id">
                    <flux:table.cell variant="strong">{{ $invitation->user->email }}</flux:table.cell>

                    @if ($this->showsOrganisations)
                        <flux:table.cell>{{ $invitation->organisation?->name }}</flux:table.cell>
                    @endif

                    <flux:table.cell>{{ $this->inviterNames[$invitation->invited_by ?? 0] ?? __('Deleted user') }}</flux:table.cell>

                    <flux:table.cell>
                        {{ $invitation->expires_at->diffForHumans() }}
                        @if ($invitation->isExpired())
                            <flux:badge size="sm" color="amber" class="ms-2">{{ __('Expired') }}</flux:badge>
                        @endif
                    </flux:table.cell>

                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-2">
                            @can('remind', $invitation)
                                <flux:button size="sm" variant="ghost" icon="paper-airplane" wire:click="remind({{ $invitation->id }})" data-test="remind-{{ $invitation->id }}">
                                    {{ __('Send again') }}
                                </flux:button>
                            @endcan

                            @if (in_array($invitation->id, $this->linkableIds, true))
                                <flux:button size="sm" variant="ghost" icon="link" wire:click="showLink({{ $invitation->id }})" data-test="show-link-{{ $invitation->id }}">
                                    {{ __('Copy invite link') }}
                                </flux:button>
                            @endif

                            @can('cancel', $invitation)
                                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="confirmCancel({{ $invitation->id }})" data-test="cancel-{{ $invitation->id }}">
                                    {{ __('Cancel invitation') }}
                                </flux:button>
                            @endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5">{{ __('No open invitations.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="cancel-invitation" class="max-w-md md:min-w-md" wire:model="showCancelModal" @close="closeCancelModal">
        <div class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Cancel invitation?') }}</flux:heading>
                <flux:text>{{ __('The link stops working and :email can be invited again later.', ['email' => $cancellingEmail]) }}</flux:text>
            </div>

            <div class="flex justify-end gap-3">
                <flux:button variant="outline" wire:click="closeCancelModal">{{ __('Keep invitation') }}</flux:button>
                <flux:button variant="danger" wire:click="cancel" data-test="confirm-cancel-invitation">{{ __('Cancel invitation') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="invite-link" class="max-w-lg md:min-w-lg" wire:model="showLinkModal" @close="closeLinkModal">
        <div class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Invite link') }}</flux:heading>
                <flux:text>{{ __('Anyone with this link can accept the invitation. Share it only with the invitee.') }}</flux:text>
            </div>

            @if ($this->inviteLink !== null)
                <flux:input :value="$this->inviteLink" readonly copyable data-test="invite-link" />
            @endif

            <div class="flex justify-end">
                <flux:button variant="outline" wire:click="closeLinkModal">{{ __('Close') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
