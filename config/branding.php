<?php

declare(strict_types=1);

return [

    /*
    | Branding (§6.5). Setup step 4 (M6) writes these keys; the accent is one of App\Support\Branding::ACCENTS
    | and the logo a path under public/ (null = the default mark).
    */
    'name' => env('APP_NAME', 'Laravel'),

    'logo' => env('BRANDING_LOGO') ?: null,

    'accent' => env('BRANDING_ACCENT', 'neutral'),

];
