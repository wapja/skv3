@extends('errors.layout')

{{-- Every 4xx without a page of its own (G3-04): 400, 405 on a POST-only route, 413 for an oversized POST, … --}}
@section('code', (string) $exception->getStatusCode())
@section('title', __('Something is wrong with this request'))
@section('message', __('This page cannot handle the request. Go to the home page and try again.'))
@section('actions')
    <a href="/">{{ __('Go to the home page') }}</a>
@endsection
