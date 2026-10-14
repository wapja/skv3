<?php

declare(strict_types=1);

namespace App\Setup\Wizard;

/** Starts a setup step outside the web request (RL-4, §5.3.4); tests bind an inline launcher. */
interface StepLauncher
{
    /** Starts `skv:run-step $key --result=$resultFile`, logging to $logFile and writing its exit code to $exitFile; false when it could not start. */
    public function start(string $basePath, string $key, string $resultFile, string $logFile, string $exitFile): bool;
}
