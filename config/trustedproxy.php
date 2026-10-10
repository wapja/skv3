<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | The addresses of the load balancers or CDNs in front of the application,
    | comma-separated, or "*" for the calling proxy. The rate limiters key on
    | $request->ip(), so a proxied production must set this, or every visitor
    | shares the proxy's address. Empty means Laravel's default: no proxies
    | are trusted, except on Laravel Cloud, Forge and Vapor hosts.
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
