{{--
    "Try again" that never opens a POST-only address (G3-05). A GET reloads its own address, query string included. A
    Livewire update shows this page in Livewire's error dialog (an iframe): reload the page around it. Any other request
    (a throttled POST /locale, a failed form) goes back to the same-host page it came from (App\Http\Support\ReturnUrl).
--}}
@php($request = request())
@if ($request->hasHeader('X-Livewire'))
    <button type="button" onclick="window.top.location.reload()">{{ __('Try again') }}</button>
@elseif ($request->isMethod('GET') || $request->isMethod('HEAD'))
    <a href="{{ $request->fullUrl() }}">{{ __('Try again') }}</a>
@else
    <a href="{{ \App\Http\Support\ReturnUrl::previous($request, '/') }}">{{ __('Try again') }}</a>
@endif
