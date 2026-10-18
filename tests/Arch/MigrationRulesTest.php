<?php

declare(strict_types=1);

/** covers §4.4.7, §7.2 */

/*
| The migrations run on PostgreSQL and SQLite (§4.4): a uniqueness rule is a portable index, never a table constraint
| added or dropped with raw ALTER TABLE, which SQLite cannot run. Comments may name
| the banned form; only code counts.
*/

$constraintSql = '/ALTER\s+TABLE\b[^;]*\bCONSTRAINT\b|\b(?:ADD|DROP)\s+CONSTRAINT\b/i';

/** $source without its comments. */
$codeOf = fn (string $source): string => implode('', array_map(
    fn (array|string $token): string => is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token,
    token_get_all($source),
));

it('changes no table constraint with raw SQL in a migration (§4.4.7)', function () use ($constraintSql, $codeOf): void {
    $hits = array_values(array_filter(
        kitSourceFiles('database/migrations'),
        fn (string $path): bool => preg_match($constraintSql, $codeOf((string) file_get_contents(base_path($path)))) === 1,
    ));

    expect($hits)->toBe([]);
});

it('recognises the constraint statements it bans, outside comments (§4.4.7)', function () use ($constraintSql, $codeOf): void {
    $forms = [
        "<?php DB::statement('ALTER TABLE users DROP CONSTRAINT users_email_unique');",
        '<?php DB::statement("alter table users add constraint users_email_unique unique (email)");',
        "<?php DB::statement('ALTER TABLE users\n    DROP CONSTRAINT users_email_unique');",
    ];

    foreach ($forms as $form) {
        expect(preg_match($constraintSql, $codeOf($form)))->toBe(1, "The rule misses [{$form}].");
    }

    expect(preg_match($constraintSql, $codeOf("<?php DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email) WHERE deleted_at IS NULL');")))->toBe(0)
        ->and(preg_match($constraintSql, $codeOf("<?php\n// never an ALTER TABLE ... CONSTRAINT.\n")))->toBe(0);
});
