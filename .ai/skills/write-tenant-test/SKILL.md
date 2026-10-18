---
name: write-tenant-test
description: "Use when writing or reviewing a Pest test in this multi-tenant app: choosing the host (tenant, apex, admin) and the actor (member, organisation admin, super admin), HTTP requests versus Livewire::test(), forged Livewire updates, faking events without losing the tenancy events, the module switches, mail and audit assertions and the test-file rules. Use together with testing-best-practices."
---

# Write a tenant test

The helpers live in `tests/Pest.php`; the rules are in `AGENTS.md` (kit rules and *Tenancy and authorization* ›
*Tests*). This skill says which helper fits which question. Read `testing-best-practices` for test design.

## 1. The file

- Create it with `php artisan make:test --pest <Name>Test --no-interaction` in the folder of its area under
  `tests/Feature`. It starts with `declare(strict_types=1);` and a `/** covers §x.y */` docblock that names the spec
  sections it covers.
- For spec §4, the covers line cites the bullet ids (`/** covers §4.5.2, §4.5.8, §6.2 */`); a plain `§4.5` cites
  no bullet. `php scripts/traceability.php` fails while a §4 bullet has no test that cites it.
- `it(...)` functions with typed closures. No class declarations, anonymous ones included, and no qualified class name
  outside the imports (`tests/Arch/TestFileClassesTest.php`): fixtures go into `tests/Fixtures`. File-local helpers
  are closures; never redeclare a global helper.
- Feature tests get `RefreshDatabase` from `tests/Pest.php`; the suite runs on SQLite and on PostgreSQL
  (`composer test:pgsql`), so no driver-specific SQL.

## 2. Host and actor

- Pick the host first: `onTenant($organisation)`, `onApex()` or `onAdminHost()`.
- Then the actor: `asMember($organisation)`, `asOrgAdmin($organisation)` or `asSuperAdmin()`, or
  `$this->actingAs($user)` for a user a factory built (`User::factory()->for($organisation)`).
- Strict mode is on: reading a tenant model outside a request needs a bound tenant
  (`Tenant::run($organisation, fn () => …)`); a test that inspects rows across organisations reads them inside
  `Tenant::bypass()` or with `recordedActivity()` for audit entries.

## 3. HTTP or Livewire::test()

- `Livewire::test()` skips every middleware: host rules, `can:` routes, membership, limits, the second-factor rule
  and redirects are tested over HTTP. Render the page with `get(route(…))`, and send forged updates with
  `pageSnapshot($url, 'pages::…')` + `callPageAction($snapshot, 'method', params: […], updates: […])`.
- `Livewire::test()` is fine for the component's own logic once the host rules are proven elsewhere.
- A new named GET route needs its row in `tests/Feature/Tenancy/RouteHostMatrixTest.php`.

## 4. Fakes and switches

- Never a bare `Event::fake()`: `Event::fake()->except([TenantResolved::class, TenantForgotten::class])`, or the
  permissions team stays null.
- Modules: call `useImpersonationModule(true|false)`, `usePasskeysModule(true|false)` or
  `useBackupsModule(true|false)` first; CI runs the suite with the modules off as well.
- Mail: run the queue on `sync` with the `array` mailer and read the sent messages, never `Mail::fake()` (`AGENTS.md`
  › *Mails implement `ShouldQueue`*); an invitation's link comes from `acceptLinkFor()`.
- Processes: setup, console, backup, health and system tests fake every command by name; never a catch-all fake
  (`AGENTS.md` › *Setup*).
- Lock order: `lockStatements(fn () => …)` and a skip outside PostgreSQL.

## 5. Assertions that pay off

- Prove both sides of a host rule: the tenant host passes, the other organisation's host and the central hosts answer
  404 or send the user to login.
- Assert the refusal (403, 404, the validation error on the field) and that nothing changed (the row, the audit log).
- A test title promises only what the test checks. Before calling it done, break the production line it guards and
  watch it fail (copy the file aside with `cp`, never `git stash`; `php artisan view:clear` after a Blade change).

Run one file with `php artisan test --compact <path>`, then `composer check`, `composer test:pgsql` and, for a browser
test, `composer test:browser`.
