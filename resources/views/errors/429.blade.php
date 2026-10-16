@extends('errors.layout')

@section('code', '429')
@section('title', __('Too many requests'))
@section('message', __('Wait a minute and try again.'))
@section('actions')
    @include('errors.partials.try-again')
@endsection
