<?php

declare(strict_types=1);

/*
 * Creates the PostgreSQL test database used by `composer test:pgsql`.
 * Name: $DB_TEST_DATABASE, or "<project folder>_test".
 */

$database = getenv('DB_TEST_DATABASE') ?: basename(dirname(__DIR__)).'_test';
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '5432';
$username = getenv('DB_USERNAME') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';

$pdo = new PDO("pgsql:host={$host};port={$port};dbname=postgres", $username, $password);
$exists = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
$exists->execute([$database]);

if ($exists->fetchColumn() === false) {
    $pdo->exec('CREATE DATABASE "'.str_replace('"', '""', $database).'"');
    fwrite(STDOUT, "Created database {$database}\n");
}
