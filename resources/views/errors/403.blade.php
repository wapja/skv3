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
    {{-- Only back to a page on this host (G2-02): url()->previous() would follow any Referer. --}}
    <a href="{{ \App\Http\Support\ReturnUrl::previous(request(), '/') }}">{{ __('Back') }}</a>
    @if ($home !== null)
        <a href="{{ $home }}">{{ __('Go to your organisation') }}</a>
    @elseif (! $user)
        <a href="{{ route('login') }}">{{ __('Log in') }}</a>
    @endif
@endsection
