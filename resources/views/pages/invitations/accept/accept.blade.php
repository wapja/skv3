<div class="flex flex-col gap-6">
    @switch($this->state)
        @case('accepted')
            <x-auth-header :title="__('Invitation already accepted')" :description="__('This invitation has already been accepted. Please log in.')" />

            <flux:button variant="primary" :href="route('login')" class="w-full" data-test="accepted-login-button">
                {{ __('Log in') }}
            </flux:button>
            @break

        @case('expired')
            <x-auth-header :title="__('This invitation has expired')" :description="__('Ask an administrator to send you a new invitation.')" />

            @if ($this->mayResend)
                <flux:button variant="primary" wire:click="resend" class="w-full" data-test="resend-invitation-button">
                    {{ __('Resend invitation') }}
                </flux:button>
            @endif
            @break

        @case('signed-in')
            <x-auth-header :title="__('Accept invitation')" :description="__('You are signed in as :name.', ['name' => auth()->user()?->name])" />

            <flux:text class="text-center">{{ __('Log out first to accept this invitation.') }}</flux:text>

            <form method="POST" action="{{ route('logout') }}" class="w-full">
                @csrf
                <flux:button variant="primary" type="submit" class="w-full" data-test="logout-button">
                    {{ __('Log out') }}
                </flux:button>
            </form>
            @break

        @default
            <x-auth-header :title="__('Welcome to :organisation', ['organisation' => $this->organisationName])" :description="__('Choose your name and a password to activate your account.')" />

            <form wire:submit="accept" class="flex flex-col gap-6">
                <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

                <flux:input wire:model="password" :label="__('Password')" type="password" required autocomplete="new-password" viewable />

                <flux:input wire:model="password_confirmation" :label="__('Confirm password')" type="password" required autocomplete="new-password" viewable />

                <flux:error name="invitation" />

                <flux:button variant="primary" type="submit" class="w-full" data-test="accept-invitation-button">
                    {{ __('Activate account') }}
                </flux:button>
            </form>
    @endswitch
</div>
