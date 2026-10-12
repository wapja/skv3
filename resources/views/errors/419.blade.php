@extends('errors.layout')

@section('code', '419')
@section('title', __('Page expired'))
@section('message', __('Your session has expired. Reload the page and try again.'))
@section('actions')
    <button type="button" onclick="location.reload()">{{ __('Reload the page') }}</button>
    <a href="{{ route('login') }}">{{ __('Log in') }}</a>
@endsection
