<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setup;

use Symfony\Component\HttpFoundation\Response;

/** §5.3.4, RL-6: the Herd stage's finishing, manual or summary page. It answers 404 until Task 11 fills it in. */
final class SetupFinishController
{
    public function __invoke(): Response
    {
        abort(404);
    }
}
