@extends('errors.layout')

@section('code', '500')
@section('title', __('Something went wrong'))
@section('message', __('An unexpected error occurred. Try again in a moment.'))
@section('actions')
    @include('errors.partials.try-again')
@endsection
