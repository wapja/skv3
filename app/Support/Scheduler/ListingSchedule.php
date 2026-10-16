<?php

declare(strict_types=1);

namespace App\Support\Scheduler;

use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Symfony\Component\Console\Command\Command;

/**
 * The Schedule the System page lists the ops jobs on (CD-8, RL-7). It is never run. Its command() leaves the PHP
 * binary out of the command line: Schedule::command() resolves it through PhpExecutableFinder, which under php-fpm
 * (no PHP_BINARY for that SAPI, no PATH) falls back to exec('command -v -- php') and so forks a shell on every render.
 * The task names, expressions and filters stay those of OpsSchedule.
 */
final class ListingSchedule extends Schedule
{
    /**
     * @param  Command|string  $command
     * @param  array<array-key, mixed>  $parameters
     */
    public function command($command, array $parameters = []): Event
    {
        if ($command instanceof Command || class_exists($command)) {
            $instance = $command instanceof Command ? $command : Container::getInstance()->make($command);

            return $this->exec(Application::artisanBinary().' '.$instance->getName(), $parameters)
                ->description($instance->getDescription());
        }

        return $this->exec(Application::artisanBinary().' '.$command, $parameters);
    }
}
