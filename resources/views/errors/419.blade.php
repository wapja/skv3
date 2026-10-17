@extends('errors.layout')

@section('code', '419')
@section('title', __('Page expired'))
@section('message', __('Your session has expired. Reload the page and try again.'))
@section('actions')
    {{-- A reload of an expired form POST would send the same stale _token again: go back to the form's page instead (G3-05). --}}
    @php($request = request())
    @if ($request->hasHeader('X-Livewire') || $request->isMethod('GET') || $request->isMethod('HEAD'))
        <button type="button" onclick="window.top.location.reload()">{{ __('Reload the page') }}</button>
    @else
        <a href="{{ \App\Http\Support\ReturnUrl::previous($request, '/') }}">{{ __('Reload the page') }}</a>
    @endif
    <a href="{{ route('login') }}">{{ __('Log in') }}</a>
@endsection
