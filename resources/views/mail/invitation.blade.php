<x-mail::message>
# {{ __('You have been invited to :organisation', ['organisation' => $organisation]) }}

{{ __('You have been invited to join :organisation. Use the button below to choose your name and a password.', ['organisation' => $organisation]) }}

<x-mail::button :url="$acceptUrl">
{{ __('Accept invitation') }}
</x-mail::button>

{{ __('This invitation link expires on :date.', ['date' => $expiresOn]) }}

{{ __('If you did not expect this invitation, you can ignore this email.') }}

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
</x-mail::message>
