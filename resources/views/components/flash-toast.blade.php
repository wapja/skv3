{{-- Interim bridge (RL-11): shows a session('status') flash as a Flux toast. M5 moves it into layouts/base. --}}
@if (is_string(session('status')) && session('status') !== '')
    <div hidden data-flash-toast x-data x-init="$nextTick(() => $flux.toast({ text: {{ \Illuminate\Support\Js::from(session('status')) }} }))"></div>
@endif
