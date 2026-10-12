@extends('errors.layout')

@section('code', '404')
@section('title', __('This invitation is no longer valid'))
@section('message', __('The invitation was cancelled, or the link is incomplete. Ask the person who invited you to send a new invitation.'))
@section('actions')
    <a href="{{ route('login') }}">{{ __('Log in') }}</a>
@endsection
