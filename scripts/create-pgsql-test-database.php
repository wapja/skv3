<?php

declare(strict_types=1);

/*
 * Creates the PostgreSQL test database used by `composer test:pgsql`; with --print-name it only prints its name.
 * Name: $DB_TEST_DATABASE, or the sanitised project folder name (like the Database step), plus "_test". The folder is
 * the basename of $PWD, so a symlinked checkout keeps the name of the link, as in the shell part of `composer test:pgsql`.
 */

$folder = basename((string) (getenv('PWD') ?: dirname(__DIR__)));
// The rule of App\Setup\Steps\DatabaseStep::databaseName() (RL-14); PgsqlTestDatabaseNameTest keeps them equal.
$name = trim(substr(trim((string) preg_replace('/[^a-z0-9_]+/', '_', strtolower($folder)), '_'), 0, 50), '_');
$database = getenv('DB_TEST_DATABASE') ?: $name.'_test';

if (in_array('--print-name', $argv, true)) {
    fwrite(STDOUT, $database."\n");

    exit(0);
}

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '5432';
$username = getenv('DB_USERNAME') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';

try {
    $pdo = new PDO("pgsql:host={$host};port={$port};dbname=postgres", $username, $password);
    $exists = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
    $exists->execute([$database]);

    if ($exists->fetchColumn() === false) {
        $pdo->exec('CREATE DATABASE "'.str_replace('"', '""', $database).'"');
        fwrite(STDOUT, "Created database {$database}\n");
    }
} catch (PDOException $exception) {
    fwrite(STDERR, "PostgreSQL is not reachable at {$host}:{$port} ({$exception->getCode()}). Start it in Herd, or set DB_HOST/DB_PORT.\n");

    exit(1);
}
