# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

@AGENTS.md

## What this is

skv3 is a multi-tenant Laravel starter kit (successor of skv2). The design spec is `docs/superpowers/specs/2026-10-10-skv3-design.md`; decisions live in `docs/decisions.md`; milestone plans in `docs/superpowers/plans/`.

## Commands

- `composer check` — Pint (test), Rector (dry run), Larastan level 8, Pest in parallel on SQLite. Must pass before any commit claims success.
- `composer test:pgsql` — the same Pest suite on PostgreSQL (Herd Pro, user `root`, empty password). Must also pass.
- `composer test:browser` — the Pest browser suite (`tests/Browser`, Chromium); needs `npm run build` and `npx playwright install chromium`. The other runs never load `tests/Browser` (`defaultTestSuite="Unit,Feature,Arch"`).
- `php artisan config:clear && vendor/bin/pest --testsuite=Browser --filter=<name>` — run one browser test (`composer test:browser -- --filter=…` cannot work: Composer appends the arguments to every line of the script).
- `composer fix` — Rector, then Pint.
- `php artisan test --compact --filter=<name>` — run one test; `php artisan test --compact tests/Arch` — arch rules only.
- `php scripts/traceability.php` — lists the spec §4 bullets no test cites (`/** covers §4.x.n */`); exits 1 while there are any.
- `composer dev` — Vite, queue, Pail and the scheduler (Herd serves the site; never run `artisan serve`).

## Conventions

- Multi-file Livewire components only, no ⚡ in paths, no `$this->view()` (enforced by `tests/Arch/LivewireConventionsTest.php`).
- `declare(strict_types=1)` everywhere; Larastan level 8 with no baseline — fix errors, never ignore them.
- No hard-coded kit or site names (`skv`) in app-identity values; derive them from `APP_URL` / the folder name.
- `livewire/flux-pro` must never be added to the committed `composer.json` or `composer.lock`; Pro features are an optional layer.
- UI copy defaults to Dutch (`nl`) with English (`en`); code, comments and docs are English.
- Kit skills for common changes live in `.ai/skills/` (`add-tenant-resource`, `add-permission`, `add-settings-page`, `write-tenant-test`); `php artisan boost:update` publishes them to `.claude/skills/`.

## Route × host matrix

- Tenant routes (`Route::tenant()`) answer 404 on central hosts (`{apex}`, `admin.{apex}`), and central routes (`Route::central()`) answer 404 on tenant hosts.
- A signed-in user who does not belong to the host is logged out and sent to login; super admins pass on every host.
- Every new named GET route goes into `matrixRoutes()` in `tests/Feature/Tenancy/RouteHostMatrixTest.php` or its exclusions.
- The detailed tenancy and authorization rules come from `@AGENTS.md`.

## Safety

- Never run `herd sites` or the Herd MCP `get_all_sites`: they print every local site's `.env`, including secrets.
- Never run `migrate:fresh` or `db:wipe` against the development database; tests use their own databases.
- The tests refuse to run unless `DB_DATABASE` is `:memory:`, a name ending in `_test`, a file under `storage/framework/testing` or the `DB_TEST_DATABASE` given to `composer test:pgsql`, and they refuse a non-empty `DB_URL` and a configuration cache (`Tests\TestCase`); never relax that guard.
- `php artisan tinker`, `migrate` and `db:seed` are not pre-approved; Claude must ask before running them.

## Superpowers

Use brainstorming / writing-plans for new features, systematic-debugging for real bugs, and verification-before-completion before committing. Skip skills for questions, small copy or formatting changes, git and read-only work.
