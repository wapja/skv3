@extends('errors.layout')

@php
    // Never resolve the user here: this page can render before EnforceImpersonation has run (RL-20).
    $user = \App\Support\Locales::resolvedUser();
    $home = null;

    if ($user instanceof \App\Models\User) {
        try {
            $home = \App\Tenancy\Hosts::url(\App\Tenancy\Hosts::homeOf($user));
        } catch (\LogicException) {
            $home = null;
        }
    }
@endphp

@section('code', '403')
@section('title', __('No access'))
@section('message', __('You do not have permission to open this page.'))
@section('actions')
    <a href="{{ url()->previous() }}">{{ __('Back') }}</a>
    @if ($home !== null)
        <a href="{{ $home }}">{{ __('Go to your organisation') }}</a>
    @elseif (! $user)
        <a href="{{ route('login') }}">{{ __('Log in') }}</a>
    @endif
@endsection
