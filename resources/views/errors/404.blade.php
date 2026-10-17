@extends('errors.layout')

@section('code', '404')
@section('title', __('Page not found'))
@section('message', __('This page does not exist or has moved.'))
@section('actions')
    <a href="/">{{ __('Go to the home page') }}</a>
@endsection
