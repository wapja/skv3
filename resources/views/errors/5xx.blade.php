@extends('errors.layout')

{{-- Every 5xx without a page of its own (G3-04): 502, 504 and the like, mostly from a proxy in front of the app. --}}
@section('code', (string) $exception->getStatusCode())
@section('title', __('Something went wrong'))
@section('message', __('An unexpected error occurred. Try again in a moment.'))
@section('actions')
    @include('errors.partials.try-again')
@endsection
