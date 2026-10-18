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
    | shares the proxy's address. Empty means Laravel's default: no proxy is
    | trusted, except on Laravel Cloud and on *.on-forge.com and *.on-vapor.com
    | hosts, so a Forge or Vapor site on its own domain must set this too.
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
