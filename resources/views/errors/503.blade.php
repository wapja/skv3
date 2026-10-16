@extends('errors.layout')

@section('code', '503')
@section('title', __('Back soon'))
@section('message', __('We are doing maintenance. Try again in a moment.'))
@section('actions')
    @include('errors.partials.try-again')
@endsection
