{{--
    "Try again" that never opens a POST-only address (G3-05). A GET reloads its own address, query string included, as a
    root-relative path, so a Host header that a misconfigured proxy rewrote never reaches the link (and a request path
    that starts with "//" falls back to "/"). A
    Livewire update shows this page in Livewire's error dialog (an iframe): reload the page around it. Any other request
    (a throttled POST /locale, a failed form) goes back to the same-host page it came from (App\Http\Support\ReturnUrl).
--}}
@php($request = request())
@if ($request->hasHeader('X-Livewire'))
    <button type="button" onclick="window.top.location.reload()">{{ __('Try again') }}</button>
@elseif ($request->isMethod('GET') || $request->isMethod('HEAD'))
    <a href="{{ \App\Http\Support\ReturnUrl::pathOnThisHost($request, $request->getRequestUri()) ?? '/' }}">{{ __('Try again') }}</a>
@else
    <a href="{{ \App\Http\Support\ReturnUrl::previous($request, '/') }}">{{ __('Try again') }}</a>
@endif
