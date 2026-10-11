<?php

declare(strict_types=1);

return [

    /*
    | Invitations (§4.5). Links stop working on the domain expiry (expires_in_days, renewed by a
    | reminder); the URL signature lives signature_grace_days longer, so a just-expired link still
    | reaches the friendly "expired" page and only older links get a 403.
    */
    'invitations' => [
        'expires_in_days' => (int) env('INVITATION_EXPIRES_IN_DAYS', 7),
        'signature_grace_days' => 30,
    ],

    /*
    | Optional modules (§6.8). Impersonation is read from M4 Task 19 on.
    */
    'modules' => [
        'impersonation' => (bool) env('MODULE_IMPERSONATION', true),
    ],

];
