<?php

declare(strict_types=1);

arch('app code reads configuration, never the environment')
    ->expect(['App', 'Database'])
    ->not->toUse(['env']);

arch('no debugging leftovers')
    ->expect(['App', 'Database', 'Tests'])
    ->not->toUse(['dd', 'dump', 'ray', 'var_dump']);

arch('strict types everywhere in app code')
    ->expect('App')
    ->toUseStrictTypes();
