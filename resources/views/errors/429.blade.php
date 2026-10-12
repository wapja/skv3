@extends('errors.layout')

@section('code', '429')
@section('title', __('Too many requests'))
@section('message', __('Wait a minute and try again.'))
@section('actions')
    <a href="{{ url()->current() }}">{{ __('Try again') }}</a>
@endsection
