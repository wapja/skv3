@extends('errors.layout')

@section('code', '404')
@section('title', __('Organisation not found'))
@section('message', __('There is no organisation at this address. Check the link or go to the main site.'))
@section('actions')
    <a href="{{ $apexUrl }}">{{ __('Go to the main site') }}</a>
@endsection
