<?php

declare(strict_types=1);

use App\Models\User;

$appUrlHost = parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST);

return [

    /*
    | The apex domain: {slug}.{apex} are tenant hosts; {apex} and {admin}.{apex} are central.
    | Defaults to the APP_URL host so a fresh install needs no extra setting (§4.1).
    */
    'apex_domain' => env('TENANCY_APEX_DOMAIN') ?: (is_string($appUrlHost) && $appUrlHost !== '' ? $appUrlHost : 'localhost'),

    'admin_subdomain' => env('TENANCY_ADMIN_SUBDOMAIN', 'admin'),

    /*
    | Strict mode (§6.2): querying a tenant model with no tenant bound throws, except in the
    | console (central context) and inside Tenant::bypass().
    */
    'strict' => (bool) env('TENANCY_STRICT', false),

    'slug_cache_ttl' => 3600,

    /*
    | Models with an organisation_id. They use BelongsToOrganisation and SoftDeletes and follow
    | the organisation's soft delete and restore (§4.1).
    */
    'tenant_models' => [
        User::class,
    ],

    'reserved_slugs' => [
        'admin', 'www', 'api', 'app', 'mail', 'smtp', 'ftp', 'static', 'assets', 'cdn', 'docs',
        'help', 'status', 'support', 'dashboard', 'login', 'test',
    ],

];
