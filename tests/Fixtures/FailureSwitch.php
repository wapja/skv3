<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/** A mutable on/off flag a test closure captures, so the test can make a listener fail and then succeed. */
final class FailureSwitch
{
    public function __construct(public bool $fails) {}
}
