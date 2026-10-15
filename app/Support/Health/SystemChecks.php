<?php

declare(strict_types=1);

namespace App\Support\Health;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\Doctor;
use App\Setup\Doctor\DoctorContext;

/**
 * The doctor checks the System page runs on demand (RL-7): the ones that start no child process and open no network
 * connection other than the mail probe, run without a detection (RL-2). Their repairs are not offered on the page;
 * php artisan skv:doctor --fix runs them.
 */
final readonly class SystemChecks
{
    /** @var list<string> */
    public const array KEYS = [
        'php', 'gd', 'app-key', 'marker', 'database', 'migrations', 'permissions', 'organisation-admin-role',
        'super-admin', 'super-admin-badges', 'session-driver', 'authenticate-session', 'health-check-key',
        'backup-password', 'assets', 'mail', 'pg-dump', 'queue', 'failed-jobs', 'storage-link',
    ];

    /** @param  string|null  $basePath  the project whose .env the checks read; tests pass a sandbox */
    public function __construct(private ?string $basePath = null) {}

    public function doctor(): Doctor
    {
        return new Doctor(array_values(array_filter(
            new Doctor()->checks(),
            fn (Check $check): bool => in_array($check->key(), self::KEYS, true),
        )));
    }

    /** @return list<array{key: string, label: string, status: string, message: string}> in the doctor's order */
    public function rows(): array
    {
        $doctor = $this->doctor();
        $results = $doctor->run(DoctorContext::withoutDetection($this->basePath));

        return array_map(fn (Check $check): array => [
            'key' => $check->key(),
            'label' => $check->label(),
            'status' => $results[$check->key()]->status->value,
            'message' => $results[$check->key()]->message,
        ], $doctor->checks());
    }
}
