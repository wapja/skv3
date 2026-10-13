# M6a Setup Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the kit's setup engine and its CLI: one set of idempotent setup steps (`app/Setup/Steps/*`) driven by `php artisan skv:install`, the non-interactive `skv:bootstrap` used by the Composer and installer hooks, `skv:doctor`, the essentials and demo seeders, `skv:create-super-admin`, `skv:create-organisation` and `skv:flux-pro`. The browser wizard (M6b) later drives the same steps.

**Architecture:** A step implements `App\Setup\SetupStep` and receives a `SetupContext`: the target base path, the validated `Answers` and the `Detection` snapshot. It returns a `StepResult` (done, skipped or `manual(command, reason)`). Steps touch the outside world only through three seams:
- `EnvFile`: atomic `.env` writes.
- `Toolchain`: every child process gets a built HOME and PATH (DEC-23), so tests use `Process::fake()`.
- The database connection, configured at runtime.

`SetupRunner` runs the steps in spec order. `skv:install` wraps the runner with Laravel Prompts. `skv:doctor` runs `App\Setup\Doctor\Check` classes, which the M7 System page and `/health-check` reuse.

**Tech Stack:** PHP 8.4, Laravel 13.35 (`Illuminate\Support\Env::writeVariables`, `Process`), **laravel/prompts ^0.3 (now a direct dependency, OD-2)**, Livewire 4.4.7, Flux 2.20.1, Fortify 1.41, spatie/laravel-permission 8.3, Pest 5.3, Larastan 3 level 8, PostgreSQL 18 (Herd), SQLite, Herd 1.30.2, Laravel Installer 5.31.1, Node 22.

**Spec:** `docs/superpowers/specs/2026-10-10-skv3-design.md`. Before starting, read:
- §4.12 (seeding), §5 (all of it), §6.7 (Flux Pro layer: `skv:flux-pro`, CI step), §6.8 (modules), §7.2–§7.4, §8 (e2e job), §9, §10, §11.2 (M6 row), §12 and §13.

Decisions in `docs/decisions.md`:
- DEC-03, DEC-08, DEC-09, DEC-10, DEC-16, DEC-18
- DEC-21 (template role)
- DEC-22 (S3 installer facts)
- DEC-23 (S1: no PATH under php-fpm)
- DEC-24 (S6)
- DEC-25: its M6 follow-ups are listed in this plan as FU-*
- DEC-26 (M6 items), DEC-28 (M6 items)
- DEC-29: the "Deferred: M6" list

This plan's rulings (RL-*) and owner rulings (OD-*) are recorded as DEC-30 in Task 16.

---

## Global Constraints

- **Versions:**
  - PHP floor `^8.4`; `laravel/framework ^13.35`; Pest `^5.3`
  - Larastan level **8**, **no baseline**, no new `ignoreErrors` entries
- **PHP files:** every one starts with `declare(strict_types=1);`. Pint uses the `laravel` preset plus `declare_strict_types`, `strict_comparison` and `ordered_class_elements`. Run `composer fix` after editing PHP.
- **Dependencies:**
  - M6a adds exactly one: `laravel/prompts` `^0.3` in `require`, in Task 1 (OD-2; already in `vendor` via the framework). No other Composer or npm dependency.
  - `livewire/flux-pro` and `composer.fluxui.dev` never appear in the committed `composer.json` or `composer.lock` (`CiWorkflowTest`).
- **Never touch the developer's own environment from code or tests:**
  - Tests run every step against a **sandbox** (`setupSandbox()`, a temp directory under `storage/framework/testing/setup/`).
  - No test reads or writes the repository's `.env`, `.env.testing`, `herd.yml`, `storage/app/skv-installed` or `.mcp.json`.
  - Implementers never run `skv:install`, `skv:bootstrap` or `skv:flux-pro` in the repository itself, only in a sandbox or a scratch copy.
  - Never run `herd link`, `unlink`, `secure`, `unsecure`, `isolate`, `unisolate` or `init` outside the throwaway-site check of Task 17. Read-only Herd commands (`tld`, `paths`, `links`, `secured`, `isolated`, `services:list --json`, `--help`) are fine for checking output formats.
- **External commands:**
  - All external commands (`herd`, `npm`, `npx`, `git`, `claude`, `composer`) run through `Process` with `Toolchain::environment()` (DEC-23).
  - Tests fake them with `Process::fake([...])` and assert with `Process::assertRan(...)`. No test runs a real `herd`, `npm`, `claude` or `composer require`.
  - Never branch on a Herd exit code alone: parse output or verify (spec §13).
- **Never print or log secrets:**
  - Composer credentials: never `composer config --global --list`, never print `auth.json`.
  - Also the super-admin password, `HEALTH_CHECK_KEY`, `APP_KEY` and the Postgres password.
  - The demo password `password` is the only credential printed (setup summary, spec §4.12).
- **Never run against the development database:** `migrate:fresh`, `db:wipe` or `db:seed`. Tests use their own databases.
  - The Database step's PostgreSQL tests create and drop uniquely named `setup_probe_*` databases on the test server (only under `composer test:pgsql`).
  - `tinker`, `migrate` and `db:seed` prompt for approval.
- **Tenancy:**
  - `Tenant::bypass()` and scope removal are allowed in `App\Setup`, `App\Console`, `App\Tenancy`, `App\Actions\Audit` and `Database\Seeders` (already in `TenancyRulesTest`).
  - Use the existing actions (`SetSuperAdmin`, `CreateOrganisation`, `InviteUser`, `AcceptInvitation`, `SyncPermissions`). Never write roles, invitations or super-admin flags directly.
- **Names:** no hard-coded kit or site name (`skv`) in app-identity values. Allowed:
  - the `skv:` command namespace
  - `config/skv.php` and `config('skv.…')`
  - `Skv::` and `App\Support\Skv`
  - and, from Task 1, only these state-file names: `skv-installed`, `skv-setup-token`, `skv-setup` and `skv-herd`
  - Demo emails derive from the apex at runtime; they never contain a literal `skv` (spec §7.2).
- **Copy and lang keys:**
  - Code, comments and docs are in English. UI copy (prompts, summary, doctor messages) goes through `__()`, with `nl` default and `en`.
  - Every `__()` key goes into **both** `lang/nl.json` and `lang/en.json`: identical keys, PHP `sort()` order, Dutch informal "je".
  - Copy inside folded Flux components stays untranslated (DEC-29).
- **Tests:**
  - Pest functions (`it(...)`) with typed closures.
  - Each file starts with a `/** covers §x.y */` docblock as its first statement.
  - Follow-up tests carry their id in the test name.
  - `Event::fake()` is never called bare.
- **Global test helper names already taken (never redeclare):**
  - `acceptLinkFor`, `asMember`, `asOrgAdmin`, `asSuperAdmin`, `browserUser`, `callPageAction`, `confirmationLinkFor`, `dutchTranslationKeys`, `filesMatching`, `inviteMember`, `kitSourceFiles`
  - `onAdminHost`, `onApex`, `onTenant`, `pageSnapshot`, `recordedActivity`, `sameOrigin`, `startImpersonatingOverHttp`, `useImpersonationModule`, `usePasskeysModule`
  - and every file-local helper listed in the M5 plan's constraints
- **Global test helper reserved for M6a:** `setupSandbox` (Task 2, `tests/Pest.php`). Nothing else; file-local helpers are closures or `beforeEach`.
- **Gate per task:**
  - `composer check` green on SQLite
  - `composer test:pgsql` green on PostgreSQL
  - the modules-off run: `php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser`
  - `composer test:browser`
  - Then `php artisan config:clear`.
- **Commits:** Conventional Commits, lower case, imperative, ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **Never** run `herd sites` or the Herd MCP `get_all_sites`, and never read `.env` files (`.env.example` is fine).

## Conventions used in this plan

- All M6a work happens on branch `m6a-setup-engine`, created from `main` (merge `0b788a1`) before Task 1. The plan file is its first commit.
- Bracketed ids are traceability tags:
  - `RL-*`: rulings in this plan
  - `OD-*`: owner rulings
  - `FU*-*`: carried follow-ups
  - `DEC-*`: decision-log entries
- **Sandbox:** `setupSandbox(): SetupContext` copies `.env.example` to `<sandbox>/.env` and creates `<sandbox>/storage/app/private`, `<sandbox>/database` and `<sandbox>/public`. It returns a context whose `basePath` is the sandbox, and removes the sandbox after the test (registered on the running test case, see Task 2).
  - Steps resolve every file through the context (`$context->path('.env')`), never `base_path()`.
  - Artisan commands run through the current application. That is fine, because the sandbox only changes where files go. The database is chosen by the step's runtime connection.
- **Faking processes:**
  - **Every** `tests/Feature/Setup/**` and `tests/Feature/Console/**` file starts with this `beforeEach`, so no test can run a real command or use the developer's HOME:

    ```php
    beforeEach(function (): void {
        Process::preventStrayProcesses();
        Process::fake([]);
        app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
    });
    ```

    `preventStrayProcesses()` alone does nothing until `Process::fake()` turns recording on (`PendingProcess.php:256`). `Process::fake([])` turns it on with no handlers, so any command a test did not fake throws. A test that fakes commands calls `Process::fake([...])` again; the handlers add up.
  - **Fake keys** are matched with `Str::is()` against Symfony's command line, in which every array argument is single-quoted: `'/…/herd' 'services:list' '--json'`. Never write a bare space between two tokens.
    - Write the quoted form: `"*herd' 'secured'*"`, `"*'artisan' 'migrate'*"`, `"*'services:list' '--json'*"`, `"*git' 'init'*"`.
    - A binary passed by absolute path ends with `name'` and has no opening quote.
    - Use double-quoted PHP strings for these keys.
    - Handlers are tried in insertion order and the first match wins, so list a specific key before a catch-all `'*'`.
    - Assertions (`Process::assertRan(fn ($process) => …)`) keep using `implode(' ', (array) $process->command)`: the recorded `PendingProcess` keeps the raw array.
  - **Steps take an optional toolchain** (`public function __construct(?Toolchain $toolchain = null) { $this->toolchain = $toolchain ?? app(Toolchain::class); }`).
    - They decide "Herd present" from `$context->detection->herd` only. When that is true but `$toolchain->herd()` is null, they return `manual()`.
    - Tests that need a binary create executable stubs (`File::put($f, "#!/bin/sh\n"); chmod($f, 0755);`) in the sandbox home: `Library/Application Support/Herd/bin/{herd,php,herd-mcp.phar}`, `Library/Application Support/Herd/config/nvm/versions/node/v22.23.2/bin/{node,npm,npx}` and `.local/bin/claude`.
    - They pass `new Toolchain($home)` to the step or bind it. No step test depends on the real HOME, `/Applications/Herd.app` or the machine's PATH.
- **Database connections in tests:** a test that runs `DatabaseStep`, `SetupRunner` or `skv:install` changes `database.default`. It saves it in `beforeEach` and restores it, with `DB::purge('setup')`, in **`afterEach`**, never in `beforeApplicationDestroyed`. RefreshDatabase reads the default connection again at teardown and would roll back the wrong connection, leaving the next test in that worker inside an open transaction.
- **Larastan reads tests too** (level 8):
  - Every `json_encode()` in test code uses `JSON_THROW_ON_ERROR`.
  - Narrow `PDO::query()` results (`PDOStatement|false`) before use.
  - Use `->sole()` instead of `->first()` when a row must exist.
  - Never call TestCase methods through Pest's `test()` proxy.
- **Tests are never deleted.** An M1 test whose subject moves is rewritten in place, keeping its name (AGENTS.md: deleting tests needs approval).
- Facts verified for this plan (2026-10-12, vendor reads, `--help` output, Herd docs):
  - **`Env`:** `Illuminate\Support\Env::writeVariables(array $variables, string $pathToFile, bool $overwrite = false)` and `writeVariable(...)` exist.
    - They throw `RuntimeException` when the file is missing and quote values themselves.
    - Without `$overwrite = true`, existing keys are left alone.
    - They write in place, so `EnvFile` writes a temp copy and `rename()`s it (atomic, §5.3.3 step 1).
  - **Prompts:** `laravel/prompts` 0.3.25 provides `form(): FormBuilder` and `task(string $label, Closure $callback, ...)`. The callback receives a `Laravel\Prompts\Support\Logger`.
  - **`Process`:** `Process::start()` has no detach API. Symfony's `create_new_console` option, or `nohup … &`, detaches.
    - M6a's CLI runs every step in-process; detaching is M6b's browser concern.
  - **php-fpm under Herd has no PATH (DEC-23).** Herd's binaries live in `$HOME/Library/Application Support/Herd/bin` (`dirname(PHP_BINARY)` under Herd).
    - Node lives in `$HOME/Library/Application Support/Herd/config/nvm/versions/node/*/bin`; use the newest.
    - `claude` lives in `$HOME/.local/bin`.
    - Herd's exit codes are unreliable.
  - **Herd CLI 1.30.2 forms:**
    - `herd secure [--expireIn=368] [<domain>]`
    - `herd isolate [--site=SITE] [<phpVersion>]`
    - `herd services:list [--json]`
    - `herd paths`, `herd links`, `herd secured`
    - `herd tld` prints `test`
  - **`herd.json`:** `~/Library/Application Support/Herd/config/herd.json` has top-level keys including `mails` and `smtpPort`.
  - **`herd.yml`** (Herd docs, "Herd.yml"): top-level `name`, `php` (quoted string), `secured` (bool), `aliases` (list), and `services`. `services` maps a service to `version` and `port`, where port may be `'${DB_PORT}'`.
  - **Herd MCP** (Herd docs, "AI Integrations"): server name `herd`, `"command": "php"`, `"args": ["/Applications/Herd.app/Contents/Resources/herd-mcp.phar"]`, `"env": {"SITE_PATH": "<project base path>"}`.
  - **`boost:install`** options: `--guidelines`, `--skills`, `--mcp`, plus `--no-interaction`.
  - **The installer:**
    - It appends ` --no-ansi` / ` --quiet` to every `extra.laravel.installer.post-create-project` command.
    - It sets `LARAVEL_INSTALLER_DEFER_HOOKS=1` only for Composer's `post-create-project-cmd`.
    - It never migrates a starter kit, and leaves `DB_CONNECTION=sqlite`, `APP_URL=http://localhost:8000` (DEC-22).
  - **`CreateOrganisation`:**
    - `handle(User $actor, string $name, string $slug, ?string $description, string $adminEmail, Locale $adminLocale): Organisation`
    - It authorises with `Gate::forUser($actor)`, records `OrganisationCreated` and invites the first admin as a pending user with the template role.
    - `AcceptInvitation::handle(Invitation $invitation, string $name, string $password): User` activates them.
  - **`SyncPermissions::handle()`** calls `systemRole(Role::SuperAdmin)->syncPermissions([])`, but `SyncResult` does not count what that strips (FU2-13).

## Scope

In scope (spec §11.2 M6 row minus the browser wizard, plus carried items):

1. **The setup step contract and context:** `SetupStep`, `SetupContext`, `StepResult`, `Answers` with the `--answers` schema and its fixture, `Detection`, `Toolchain`, `EnvFile`, `Marker`.
2. **The eleven steps of §5.3.3**, in order, for the CLI:
   - environment, database, `herd.yml`, branding, frontend, git, Claude, doctor, marker, Herd site, summary
   - the passkeys/HTTPS consequence (§6.8, §5.3.4 last sentence)
   - logo validation (DEC-29)
3. `skv:bootstrap` and the Composer and installer hooks (§5.2).
4. `skv:install [--answers=JSON] [--no-interaction] [--force]` and `composer setup` (§5.3.3).
5. `skv:doctor [--fix] [--json]` and `composer doctor` (§5.4, §7.4); doctor checks for template-role and badge drift (DEC-26, DEC-28).
6. The essentials and demo seeders (§4.12): the demo has invitations and activity (DEC-28) and replaces the temporary `test@example.com` login (DEC-26).
7. `skv:create-super-admin`, `skv:create-organisation` (reusing `CreateOrganisation`, DEC-28).
8. `SyncResult` reports permissions stripped from the super-admin badge (FU2-13).
9. `skv:flux-pro` with `view:clear` (DEC-29 (30)) and the CI Flux Pro step (§6.7, §8).
10. DEC-25's M6 follow-ups: the pgsql test-database script, the pre-commit hook, the worktree and symlink cases, the Herd MCP name, a verified `herd isolate`, the bypass caveat in docs, and a tighter `NameLeakTest` allow-list.
11. **The e2e CI job (§8)**, written now and run in CI from M8; a local `scripts/e2e-local.sh` the controller runs once (OD-3).
12. A one-off **throwaway Herd site check** of the Herd steps, run by the controller in Task 17 (OD-4).

Not in scope:
- **M6b:**
  - the browser wizard: `/welcome-setup`, the access gate and token, the setup middleware group, `layouts/setup`, `resources/setup/setup.css` with `build:setup`, detached long steps and polling, the browser Herd step with its status endpoint, and the signed login URL
  - the tenant resolver's "marker absent" bypass and the `/` redirect
  - the local quick login (§6.5)
  - the wizard's browser tests
  - the §7.2 phase-1 tests for all four entry points (`laravel new` with and without `--database`, `composer create-project`, `git clone` + `composer setup`), because they assert that `GET /welcome-setup` returns 200. M6a's e2e covers `laravel new --database=sqlite` up to `skv:doctor`.
- **M7:** the backups module itself, `/health-check`, the System page. M6a only writes `MODULE_BACKUPS` and `HEALTH_CHECK_KEY`.
- **M8:** publication. The e2e job only runs once a remote exists.

## Owner rulings

- **OD-1 = A:** M6 is split into two plans:
  - M6a, the setup engine and its CLI (this plan)
  - M6b, the browser wizard and the quick login
  - Each has its own review and merge.
- **OD-2 = A:** `laravel/prompts ^0.3` becomes a direct `require` dependency (Task 1).
- **OD-3 = A:** M6a writes the §8 e2e job and `scripts/e2e-local.sh`. The controller runs the script once in a scratch directory; the job runs in CI from M8.
- **OD-4 = A:** the Herd steps are verified once, by the controller in Task 17:
  - on a throwaway parked site (`~/Herd/m6a-e2e`), including a real `herd isolate` (FU-isolate)
  - removed afterwards with `herd unsecure m6a-e2e` and its databases dropped

## Rulings

- **RL-1 (split boundary):** `skv:bootstrap` in M6a does §5.2's points 1–4:
  - `.env`
  - `APP_KEY`
  - the Herd `APP_URL` rewrite
  - the sqlite touch and migrate
  - It does **not** write the setup token or print the wizard URL; those are M6b's (§5.2 points 5–6). It prints "Run php artisan skv:install to finish setting up". M6b switches that line and adds the token.
- **RL-2 (sandboxed steps):**
  - Every step resolves files through `SetupContext::path()`, so tests and the e2e script never touch the repository.
  - The commands resolve `SetupContext` from the container. Tests bind a sandbox with `app()->instance(SetupContext::class, setupSandbox())`.
- **RL-3 (Toolchain):** `App\Setup\Support\Toolchain` applies DEC-23 for every child process:
  - HOME: `getenv('HOME')` or `posix_getpwuid(posix_geteuid())['dir']`
  - PATH: the Herd bin dir (`dirname(PHP_BINARY)` when it ends in `/Herd/bin`, else `$HOME/Library/Application Support/Herd/bin`), the newest `…/Herd/config/nvm/versions/node/*/bin`, `$HOME/.local/bin`, then `/usr/local/bin:/opt/homebrew/bin:/usr/bin:/bin:/usr/sbin:/sbin`
  - `herd()` returns the absolute binary path, or null when it is missing.
  - `which(string $binary): ?string` searches that PATH in-process; no shell.
- **RL-4 (Detection):** one immutable snapshot taken before questions:
  - Herd present
  - Herd Pro: `herd services:list --json` parses to a JSON array; never the exit code
  - Herd mail: `herd.json` has a truthy `mails` and an int `smtpPort`
  - Postgres reachable: PDO to `127.0.0.1:5432`, user `root`, empty password, database `postgres`, 2 s timeout
  - Flux Pro installed (`Skv::hasFluxPro()`)
  - `claude` on PATH
  - the CLI host: folder plus `herd tld` when Herd is present, else the `APP_URL` host
- **RL-5 (Answers):** `App\Setup\Answers` is the `--answers` schema (§5.3.2).
  - `Answers::fromArray(array, Detection)` validates with the Laravel validator and fills defaults from Detection.
  - `backups` is removed on sqlite.
  - `https` is forced `true` while `passkeys` is in `modules`.
  - `start_with=super_admin` requires `super_admin_email` and `super_admin_password`.
  - The password is hashed on entry (`super_admin_password_hash`) and the plain value is dropped, so no answer object ever holds it after validation.
  - `tests/Fixtures/e2e-answers.json` covers every key; `AnswersTest` asserts the fixture's keys equal `Answers::KEYS`.
- **RL-6 (CLI execution):**
  - `skv:install` runs every step in-process in spec order. Long steps simply run (no detach in the CLI).
  - A step returning `manual()` does not stop the run. The summary lists every manual command.
  - A thrown exception stops the run with the step's label and message. The marker is not written, so a re-run resumes, skipping complete steps unless `--force`.
- **RL-7 (HTTPS and passkeys):**
  - In the CLI, the Herd step secures the site when `https` is true.
  - When it cannot (no Herd, the step returns `manual()`, or verification fails), `skv:install` writes `MODULE_PASSKEYS=false`. The summary then says how to turn passkeys back on: `herd secure <name>`, set `MODULE_PASSKEYS=true`, then `php artisan skv:doctor --fix`.
  - This is the "HTTPS no longer forced" consequence in §6.8 and DEC-29.
- **RL-8 (demo data):** `Database\Seeders\DemoSeeder` reuses the actions. It refuses in production (`app()->isProduction()` throws `RuntimeException`).
  - It creates a super admin `superadmin@{apex}` through `User::factory()->superAdmin()` plus `SetSuperAdmin`.
  - For each of `demo1` (name "Demo 1") and `demo2` (name "Demo 2"):
    - `CreateOrganisation` with admin `admin@{slug}.{apex}`, then `AcceptInvitation` with name "Demo admin" and password `password`
    - a custom role "Member" with `Permission::UsersView`, through `CreateRole`. `InviteUser` requires at least one role (W14), and the kit's only system roles are the admin template and the unassignable badge.
    - `InviteUser` for `user@{slug}.{apex}` with that role, accepted with name "Demo user"
    - one pending invitation for `invited@{slug}.{apex}` with that role
  - That yields OrganisationCreated, RoleCreated, InvitationSent and InvitationAccepted activity (DEC-28).
  - The seeder sends its invitation mails on the **sync** queue (it sets `queue.default` to `sync` for its run and restores it). The admin and user invitations are accepted at once, so a queued mail would find its token gone and fail in the worker (`InvitationMail`).
  - `DatabaseSeeder` runs `EssentialsSeeder`, then `DemoSeeder` unless in production. The temporary `test@example.com` login goes (DEC-26).
- **RL-9 (console commands):**
  - **`skv:create-super-admin`:** `{email?} {--name=} {--password=}` prompts for whatever is missing. With `--no-interaction`, the email and password are required.
    - A new email creates an active user without an organisation and calls `SetSuperAdmin` (W8).
    - An existing email is promoted through `SetSuperAdmin`, whose guards apply. The password is never echoed.
  - **`skv:create-organisation`:** `{name?} {slug?} {admin-email?} {--description=} {--locale=nl}`.
    - The actor is the oldest active super admin (`User::query()->where('is_super_admin', true)->where('status', Active)->oldest('id')->first()`). Without one, the command fails with "Create a super admin first: php artisan skv:create-super-admin".
    - It calls `CreateOrganisation`, which sends the admin an invitation. It prints "Invitation sent to :email"; the accept URL is never printed.
- **RL-10 (FU2-13):** `SyncResult` gains `public int $badgePermissionsStripped` (permissions detached from the `super_admin` badge), and `changed()` counts it. `permissions:sync` prints every non-zero field.
- **RL-11 (doctor):** each check implements `App\Setup\Doctor\Check`:
  - `key()`, `label()`, `run(DoctorContext): CheckResult`
  - `CheckResult` carries a status (`pass|warn|fail|skipped`), a message and a fix command.
  - `fix(DoctorContext): ?CheckResult` only for safe repairs: `permissions:sync`, flag/role drift, `storage:link`, `core.hooksPath`, asset build.
  - Green = no `fail`. `--json` prints `{"checks":[{key,status,message,fix}],"green":bool}` and exits 1 when not green.
  - The checks that need modules M7 builds (`pg_dump` for backups) report `skipped` while the module is off or not installed.
- **RL-12 (Flux Pro):**
  - `skv:flux-pro` checks that credentials for `composer.fluxui.dev` exist. It runs `composer config http-basic.composer.fluxui.dev` in the base path, which reads the project `auth.json`, then the global one. It captures the output and never prints it; empty means missing.
  - It then runs `composer config repositories.flux-pro composer https://composer.fluxui.dev` and `composer require livewire/flux-pro`, then `php artisan view:clear` (DEC-29 (30)).
  - Missing credentials make it fail with the README one-liner. It never writes credentials.
  - In CI it runs after `composer install` and before any view compiles, only when the secrets are present (§6.7).
- **RL-13 (hooks):**
  - `post-create-project-cmd`: `@php artisan skv:bootstrap --no-interaction --ansi`
  - `extra.laravel.installer.post-create-project`: `["@php artisan skv:bootstrap --no-interaction"]`. Artisan accepts the installer's appended `--no-ansi`/`--quiet`.
  - `skv:bootstrap` returns early when `LARAVEL_INSTALLER_DEFER_HOOKS` is set (§5.2). DEC-22 (6) notes that the installer's own hook runs without it, so bootstrap runs once in every route.
- **RL-14 (marker):**
  - `App\Setup\Marker` reads and writes `storage/app/skv-installed` as JSON: `kit_version`, `answers` (without secrets), `start_with`, `installed_at` (ISO 8601) and `summary`.
  - `Marker::exists(SetupContext)` decides whether setup has run. The doctor and M6b read it.
- **RL-15 (schema dumps):** the Database step fails with a clear message when `database/schema/pgsql-schema.*` exists (§7.3). The kit commits none.

## Requirement traceability

| Requirement | Task |
|---|---|
| §5.3.1 step contract, `manual()` | 2 |
| §5.3.2 detection | 3 |
| §5.3.2 answers schema, `tests/Fixtures/e2e-answers.json` (§7.2) | 2 |
| DEC-23 PATH/HOME, absolute herd | 3 |
| FU2-13 `SyncResult` badge permissions | 4 |
| §4.12 essentials + demo seeder, DEC-26/DEC-28 demo, W8 | 5 |
| `skv:create-super-admin`, `skv:create-organisation` (DEC-28) | 5 |
| §5.2 `skv:bootstrap`, hooks, `.env.example` keys | 1, 6 |
| §5.3.3 step 1 environment, Herd mail | 7 |
| §5.3.3 step 3 `herd.yml`, step 4 branding + logo validation (DEC-29), step 6 git | 7 |
| §5.3.3 step 2 database (atomic switch, `.env.testing`), §7.3 | 8 |
| §5.3.3 step 5 frontend, step 7 Claude (Herd MCP as `herd`, DEC-25 (6); Boost constraint, DEC-22 (5)) | 9 |
| §5.3.3 steps 9–11, §6.8 HTTPS consequence (RL-7) | 10 |
| §5.4 doctor, §7.4 `composer doctor`, DEC-26/28 drift checks, step 8 | 11 |
| §5.3.3 `skv:install`, `composer setup`, RL-6, RL-7 | 12 |
| §6.7 `skv:flux-pro` + `view:clear`, §8 CI Pro step | 13 |
| DEC-25 M6 follow-ups (pgsql script, hook, worktree, symlink) | 14 |
| §8 e2e job, OD-3 local run | 15 |
| §10 README, DEC-30, CHANGELOG, guidelines, DEC-25 bypass caveat | 16 |
| OD-4 throwaway Herd site, FU-isolate | 17 |
| FU-nameleak (tighter allow-list) | 1 |

## File map

Create:
- `app/Setup/SetupStep.php`: the interface (§5.3.1)
- `app/Setup/StepResult.php`: done / skipped / manual value object
- `app/Setup/SetupContext.php`: base path, answers, detection, `path()`
- `app/Setup/Answers.php`: the schema, validation and defaults
- `app/Setup/Detection.php`: the snapshot value object
- `app/Setup/Detector.php`: builds `Detection`
- `app/Setup/Marker.php`: `storage/app/skv-installed`
- `app/Setup/SetupRunner.php`: runs the steps in order, collects results
- `app/Setup/Support/EnvFile.php`: atomic `.env` read/write
- `app/Setup/Support/Toolchain.php`: HOME/PATH, `herd()`, `which()`
- `app/Setup/Support/Bootstrap.php`: the `skv:bootstrap` logic, reused as `skv:install`'s step 0
- `app/Setup/Steps/{EnvironmentStep,DatabaseStep,HerdManifestStep,BrandingStep,FrontendStep,GitStep,ClaudeStep,DoctorStep,MarkerStep,HerdSiteStep,SummaryStep}.php`
- `app/Setup/Doctor/{Check,CheckResult,CheckStatus,DoctorContext,Doctor}.php` and `app/Setup/Doctor/Checks/*.php`
- `app/Console/Commands/{BootstrapCommand,InstallCommand,DoctorCommand,CreateSuperAdminCommand,CreateOrganisationCommand,FluxProCommand}.php`
- `database/seeders/DemoSeeder.php`
- `tests/Fixtures/e2e-answers.json`
- `tests/Feature/Setup/**`, `tests/Feature/Console/**`
- `scripts/e2e-local.sh`

Modify:
- `composer.json`/`composer.lock` (prompts, scripts, hooks)
- `.env.example`, `config/skv.php` (backups flag)
- `app/Authorization/{SyncResult,SyncPermissions}.php`, `app/Console/Commands/SyncPermissionsCommand.php`
- `database/seeders/DatabaseSeeder.php`
- `tests/Arch/NameLeakTest.php`, `tests/Pest.php`
- `.githooks/pre-commit`, `scripts/create-pgsql-test-database.php`
- `.github/workflows/tests.yml`
- `tests/Feature/Tooling/{ComposerScriptsTest,CiWorkflowTest,GitHooksTest}.php`
- `lang/{nl,en}.json`
- `README.md`, `CHANGELOG.md`, `docs/decisions.md`, `.ai/guidelines/skv3.md`, `docs/handleiding.html`

---

### Task 1: Foundation (Prompts dependency, `.env.example` keys, backups flag, tighter name-leak allow-list)

**Files:**
- Modify: `composer.json`, `composer.lock` (`laravel/prompts ^0.3` in `require`)
- Modify: `.env.example`, `config/skv.php`, `app/Support/Skv.php` (`backupsAvailable()`)
- Modify: `tests/Arch/NameLeakTest.php`
- Test: `tests/Feature/Setup/SandboxTest.php`, `tests/Feature/Support/ModulesConfigTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces:
  - `Skv::backupsAvailable(string $connection, bool $flag): bool`
  - `config('skv.modules.backups')`: bool, false on sqlite.
  - `.env.example` keys `HEALTH_CHECK_KEY=` and `MODULE_BACKUPS=true`.

- [ ] **Step 1: Create the branch and commit the plan**

```bash
git switch -c m6a-setup-engine
git add docs/superpowers/plans/2026-10-12-m6a-setup-engine.md
git commit -m "docs: add the M6a setup engine implementation plan

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

(If the controller already did this, skip the step.)

- [ ] **Step 2: Write the failing tests**

`tests/Feature/Support/ModulesConfigTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §6.8 */

it('ships every module flag and the health-check key in .env.example (§5.2)', function (): void {
    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)
        ->toContain("\nMODULE_PASSKEYS=true\n")
        ->toContain("\nMODULE_BACKUPS=true\n")
        ->toContain("\nMODULE_IMPERSONATION=true\n")
        ->toContain("\nHEALTH_CHECK_KEY=\n");
});

it('keeps the backups module off on SQLite whatever MODULE_BACKUPS says (§6.8)', function (string $connection, bool $flag, bool $expected): void {
    expect(Skv::backupsAvailable($connection, $flag))->toBe($expected);
})->with([
    'sqlite, flag on' => ['sqlite', true, false],
    'pgsql, flag on' => ['pgsql', true, true],
    'pgsql, flag off' => ['pgsql', false, false],
]);

it('reads the backups flag through that rule', function (): void {
    // base_path('config/skv.php'): the allowed config/skv.php form; NameLeakTest scans tests/ too.
    expect((string) file_get_contents(base_path('config/skv.php')))->toContain('Skv::backupsAvailable(');
});
```

Add `use App\Support\Skv;` to the test's imports. A dataset cannot flip `DB_CONNECTION` for a `require config_path(...)` read, because `phpunit.xml` pins it in `$_SERVER`. So the rule lives in one pure function that the config calls.

`tests/Arch/NameLeakTest.php`: replace the allow-list entry `'/skv-[a-z-]+/',            // storage marker / state files` with:

```php
        '/skv-(installed|setup-token|setup|herd)\b/', // the setup marker and state files (FU-nameleak, DEC-25)
```

- [ ] **Step 3: Run them to see them fail**

Run: `php artisan test --compact tests/Feature/Support/ModulesConfigTest.php`
Expected: FAIL. `.env.example` has no `MODULE_BACKUPS` or `HEALTH_CHECK_KEY`, and `Skv::backupsAvailable()` is undefined.

- [ ] **Step 4: Implement**

`.env.example`: below `MODULE_IMPERSONATION=true`, add `MODULE_BACKUPS=true`. Add `HEALTH_CHECK_KEY=` after `APP_URL=`, with the comment `# Setup writes a random 40-character key; /health-check (M7) answers 403 while it is blank.`

`config/skv.php`, in `modules`:

```php
        // §6.8: database-only backups need PostgreSQL; on SQLite the module is always off.
        'backups' => App\Support\Skv::backupsAvailable((string) env('DB_CONNECTION', 'sqlite'), (bool) env('MODULE_BACKUPS', true)),
```

`app/Support/Skv.php` (the M5 class), add:

```php
    /** §6.8: database-only backups need PostgreSQL, so the module is off on SQLite whatever MODULE_BACKUPS says. */
    public static function backupsAvailable(string $connection, bool $flag): bool
    {
        return $flag && $connection !== 'sqlite';
    }
```

Update the block comment to "Setup writes the MODULE_* keys; re-enabling = change the key and run `php artisan skv:doctor --fix`."

`composer.json`: `composer require laravel/prompts:^0.3 --no-interaction`. Expect only `composer.json`'s `require` to gain `"laravel/prompts": "^0.3"`, with the `composer.lock` content hash updated and no package version changes (check: `git diff composer.lock | grep '"version"'` prints nothing).

- [ ] **Step 5: Run the tests to see them pass**

Run: `php artisan test --compact tests/Feature/Support/ModulesConfigTest.php tests/Arch/NameLeakTest.php`
Expected: PASS. If `NameLeakTest` now flags a line that used another `skv-*` name, rename that file reference to one of the four names (none is expected today).

- [ ] **Step 6: Gate and commit**

Run the full gate (Global Constraints).

```bash
git add composer.json composer.lock .env.example config/skv.php app/Support/Skv.php tests/Feature/Support/ModulesConfigTest.php tests/Arch/NameLeakTest.php
git commit -m "feat: add the backups flag, the health-check key and laravel/prompts for the setup engine

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: The step contract, context, answers, env file and marker (§5.3.1, §5.3.2)

**Files:**
- Create: `app/Setup/SetupStep.php`, `app/Setup/StepResult.php`, `app/Setup/SetupContext.php`, `app/Setup/Answers.php`, `app/Setup/Detection.php`, `app/Setup/Marker.php`
- Create: `app/Setup/Support/EnvFile.php`
- Create: `tests/Fixtures/e2e-answers.json`
- Modify: `tests/Pest.php` (`setupSandbox()`)
- Test: `tests/Feature/Setup/AnswersTest.php`, `tests/Feature/Setup/EnvFileTest.php`, `tests/Feature/Setup/MarkerTest.php`, `tests/Feature/Setup/StepResultTest.php`

**Interfaces:**
- Consumes: `config('skv.modules.*')`.
- Produces (exact):

```php
namespace App\Setup;

interface SetupStep
{
    public function key(): string;                              // stable id
    public function label(): string;                            // translated
    public function isComplete(SetupContext $context): bool;    // idempotency check
    public function isLong(): bool;                             // detached in the browser (M6b)
    public function run(SetupContext $context): StepResult;
}

final readonly class StepResult
{
    public static function done(string $message = ''): self;
    public static function skipped(string $reason): self;
    public static function manual(string $command, string $reason): self;
    public function isManual(): bool;
    // public readonly properties: string $status ('done'|'skipped'|'manual'), string $message, ?string $command
}

final readonly class Detection
{
    public function __construct(
        public bool $herd,
        public bool $herdPro,
        public bool $herdMail,
        public ?int $herdSmtpPort,
        public bool $postgres,
        public bool $fluxPro,
        public bool $claude,
        public string $host,      // e.g. "myapp.test" or "localhost"
        public string $scheme,    // "http" until the Herd step secures the site
    ) {}
    public static function none(string $host = 'localhost'): self; // everything false, for tests
}

final class SetupContext
{
    public function __construct(public readonly string $basePath, public Answers $answers, public readonly Detection $detection, public bool $force = false) {}
    public function path(string $relative = ''): string;   // $basePath.'/'.ltrim($relative, '/')
    public function folderName(): string;                    // basename($basePath)
    /** @var array<string, StepResult> */
    public array $results = [];
}

final readonly class Answers
{
    public const array KEYS = ['app_name', 'database', 'start_with', 'super_admin_email', 'super_admin_password', 'modules', 'https', 'playwright', 'claude'];
    public static function fromArray(array $input, Detection $detection, string $folderName): self; // throws ValidationException
    public static function defaults(Detection $detection, string $folderName): self;
    /** @return array<string, mixed> without the password or its hash */
    public function toPublicArray(): array;
    // public readonly: string $appName, string $database ('pgsql'|'sqlite'), string $startWith ('demo'|'super_admin'),
    // ?string $superAdminEmail, ?string $superAdminPasswordHash, list<string> $modules, bool $https, bool $playwright, bool $claude
    public function withModules(array $modules): self; // used by RL-7 (passkeys off)
}

final class Marker
{
    public const string FILE = 'storage/app/skv-installed';
    public static function exists(SetupContext $context): bool;
    public static function write(SetupContext $context, array $summary): void;
    /** @return array{kit_version: string, answers: array<string, mixed>, start_with: string, installed_at: string, summary: array<string, mixed>}|null */
    public static function read(SetupContext $context): ?array;
}

namespace App\Setup\Support;

final class EnvFile
{
    public function __construct(private readonly string $path) {}
    public function exists(): bool;
    public function get(string $key): ?string;                        // parsed with Dotenv, null when absent
    /** @param array<string, string|bool|int|null> $variables */
    public function set(array $variables, bool $overwrite = true): void; // atomic: temp copy + rename
}
```

- `setupSandbox(): SetupContext` is a global helper in `tests/Pest.php`. It creates `storage/framework/testing/setup/<uuid>/` with `.env` copied from `.env.example`, plus `storage/app/private`, `database` and `public`. It returns `new SetupContext($dir, Answers::defaults(Detection::none(), basename($dir)), Detection::none())` and deletes the directory when the test finishes.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Setup/AnswersTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.2, §7.2 */

use App\Setup\Answers;
use App\Setup\Detection;
use Illuminate\Validation\ValidationException;

it('covers every --answers key in the e2e fixture (§7.2)', function (): void {
    /** @var array<string, mixed> $fixture */
    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/e2e-answers.json')), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($fixture))->toEqualCanonicalizing(Answers::KEYS);
    Answers::fromArray($fixture, Detection::none(), 'tmp');
});

it('defaults to the detected environment (§5.3.2)', function (): void {
    $withPostgres = new Detection(herd: true, herdPro: true, herdMail: true, herdSmtpPort: 2525, postgres: true, fluxPro: false, claude: true, host: 'my-app.test', scheme: 'http');

    $answers = Answers::defaults($withPostgres, 'my-app');

    expect($answers->appName)->toBe('My App')
        ->and($answers->database)->toBe('pgsql')
        ->and($answers->startWith)->toBe('demo')
        ->and($answers->modules)->toEqualCanonicalizing(['passkeys', 'backups', 'impersonation'])
        ->and($answers->https)->toBeTrue()
        ->and($answers->playwright)->toBeFalse()
        ->and($answers->claude)->toBeTrue();

    expect(Answers::defaults(Detection::none(), 'my-app'))
        ->database->toBe('sqlite')
        ->claude->toBeFalse()
        ->modules->toEqualCanonicalizing(['passkeys', 'impersonation']);
});

it('drops backups on SQLite and forces HTTPS while passkeys are on (§5.3.2)', function (): void {
    $answers = Answers::fromArray(['database' => 'sqlite', 'modules' => ['backups', 'passkeys'], 'https' => false], Detection::none(), 'tmp');

    expect($answers->modules)->toBe(['passkeys'])->and($answers->https)->toBeTrue();
});

it('requires the super admin credentials when starting without demo data (§5.3.2)', function (): void {
    Answers::fromArray(['start_with' => 'super_admin'], Detection::none(), 'tmp');
})->throws(ValidationException::class);

it('keeps only a hash of the super admin password (RL-5)', function (): void {
    $answers = Answers::fromArray([
        'start_with' => 'super_admin',
        'super_admin_email' => 'owner@example.com',
        'super_admin_password' => 'correct-horse-battery',
    ], Detection::none(), 'tmp');

    expect($answers->superAdminPasswordHash)->not->toBeNull()
        ->and(password_verify('correct-horse-battery', (string) $answers->superAdminPasswordHash))->toBeTrue()
        ->and(json_encode($answers->toPublicArray(), JSON_THROW_ON_ERROR))->not->toContain('correct-horse-battery')
        ->and($answers->toPublicArray())->not->toHaveKey('super_admin_password_hash');
});

it('rejects unknown keys and values (§5.3.2)', function (array $input): void {
    Answers::fromArray($input, Detection::none(), 'tmp');
})->throws(ValidationException::class)->with([
    'unknown key' => [['colour' => 'blue']],
    'unknown database' => [['database' => 'mysql']],
    'unknown module' => [['modules' => ['billing']]],
    'unknown start' => [['start_with' => 'empty']],
]);
```

`tests/Fixtures/e2e-answers.json`. The values are chosen so the e2e run needs no Herd and no secrets:

```json
{
    "app_name": "E2E App",
    "database": "sqlite",
    "start_with": "demo",
    "super_admin_email": null,
    "super_admin_password": null,
    "modules": ["impersonation"],
    "https": false,
    "playwright": false,
    "claude": false
}
```

`tests/Feature/Setup/EnvFileTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.3 */

use App\Setup\Support\EnvFile;

it('writes variables atomically and keeps the rest of the file (§5.3.3 step 1)', function (): void {
    $context = setupSandbox();
    $env = new EnvFile($context->path('.env'));

    $env->set(['APP_NAME' => 'My App', 'MODULE_BACKUPS' => false, 'NEW_KEY' => 'with space']);

    expect($env->get('APP_NAME'))->toBe('My App')
        ->and($env->get('MODULE_BACKUPS'))->toBe('false')
        ->and($env->get('NEW_KEY'))->toBe('with space')
        ->and($env->get('SESSION_COOKIE'))->toBe('app_session')
        ->and(glob($context->path('.env*.tmp')) ?: [])->toBe([]);
});

it('leaves existing values alone unless asked to overwrite', function (): void {
    $env = new EnvFile(setupSandbox()->path('.env'));

    $env->set(['APP_NAME' => 'First']);
    $env->set(['APP_NAME' => 'Second'], overwrite: false);

    expect($env->get('APP_NAME'))->toBe('First');
});
```

`tests/Feature/Setup/MarkerTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.3 */

use App\Setup\Marker;

it('records the answers without secrets and the summary in the marker (§5.3.3 step 9, RL-14)', function (): void {
    $context = setupSandbox();

    expect(Marker::exists($context))->toBeFalse();

    Marker::write($context, ['urls' => ['http://localhost']]);

    $marker = Marker::read($context);

    expect(Marker::exists($context))->toBeTrue()
        ->and($marker)->not->toBeNull()
        ->and($marker['start_with'] ?? null)->toBe('demo')
        ->and($marker['answers'] ?? [])->not->toHaveKey('super_admin_password')
        ->and($marker['summary'] ?? null)->toBe(['urls' => ['http://localhost']])
        ->and(is_file($context->path(Marker::FILE)))->toBeTrue();
});
```

`tests/Feature/Setup/StepResultTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.1 */

use App\Setup\StepResult;

it('carries the command and reason of a manual step (§5.3.1)', function (): void {
    $manual = StepResult::manual('npm ci && npm run build', 'Node is missing');

    expect($manual->isManual())->toBeTrue()
        ->and($manual->command)->toBe('npm ci && npm run build')
        ->and($manual->message)->toBe('Node is missing')
        ->and(StepResult::done()->isManual())->toBeFalse()
        ->and(StepResult::skipped('not needed')->status)->toBe('skipped');
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test --compact tests/Feature/Setup`
Expected: FAIL with "Class App\Setup\Answers not found" (and the other classes).

- [ ] **Step 3: Implement**

`app/Setup/Support/EnvFile.php`:

```php
<?php

declare(strict_types=1);

namespace App\Setup\Support;

use Dotenv\Dotenv;
use Illuminate\Support\Env;
use RuntimeException;

/** Reads and atomically writes one .env file (§5.3.3 step 1): values never leave this class except through get(). */
final class EnvFile
{
    public function __construct(private readonly string $path) {}

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function get(string $key): ?string
    {
        if (! $this->exists()) {
            return null;
        }

        $values = Dotenv::parse((string) file_get_contents($this->path));

        return array_key_exists($key, $values) ? (string) $values[$key] : null;
    }

    /** @param  array<string, string|bool|int|null>  $variables */
    public function set(array $variables, bool $overwrite = true): void
    {
        if (! $this->exists()) {
            throw new RuntimeException("The environment file [{$this->path}] does not exist.");
        }

        $temporary = $this->path.'.'.bin2hex(random_bytes(4)).'.tmp';
        copy($this->path, $temporary);

        try {
            Env::writeVariables(array_map(fn (string|bool|int|null $value): string => match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                $value === null => '',
                default => (string) $value,
            }, $variables), $temporary, $overwrite);

            rename($temporary, $this->path);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
```

`Dotenv::parse()` (vlucas/phpdotenv) returns an array of strings and handles quoting. Check the installed version's signature in `vendor/vlucas/phpdotenv/src/Dotenv.php` before relying on it. `Env::writeVariables()` writes `KEY=value` and quotes values that need it. Check `prepareQuotedValue` for how it treats `false`/`true` strings; the test pins the round-trip.

`app/Setup/StepResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Setup;

/** What a step did (§5.3.1): done, skipped, or a command the user runs themselves. */
final readonly class StepResult
{
    private function __construct(
        public string $status,
        public string $message,
        public ?string $command = null,
    ) {}

    public static function done(string $message = ''): self
    {
        return new self('done', $message);
    }

    public static function skipped(string $reason): self
    {
        return new self('skipped', $reason);
    }

    public static function manual(string $command, string $reason): self
    {
        return new self('manual', $reason, $command);
    }

    public function isManual(): bool
    {
        return $this->status === 'manual';
    }
}
```

`app/Setup/SetupStep.php`: exactly the interface in **Interfaces**, with a class docblock pointing to §5.3.1.

`app/Setup/Detection.php`: the readonly class from **Interfaces**. `none()` returns every bool false, `herdSmtpPort` null and scheme `http`.

`app/Setup/SetupContext.php`:

```php
<?php

declare(strict_types=1);

namespace App\Setup;

/** Where setup writes and what it was told (RL-2): steps resolve every file through path(), never base_path(). */
final class SetupContext
{
    /** @var array<string, StepResult> results by step key, in run order */
    public array $results = [];

    public function __construct(
        public readonly string $basePath,
        public Answers $answers,
        public readonly Detection $detection,
        public bool $force = false,
    ) {}

    public static function forApplication(Answers $answers, Detection $detection, bool $force = false): self
    {
        return new self(base_path(), $answers, $detection, $force);
    }

    public function path(string $relative = ''): string
    {
        return rtrim($this->basePath, '/').($relative === '' ? '' : '/'.ltrim($relative, '/'));
    }

    public function folderName(): string
    {
        return basename($this->basePath);
    }
}
```

`app/Setup/Answers.php`:

```php
<?php

declare(strict_types=1);

namespace App\Setup;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The setup questions and the --answers JSON schema (§5.3.2, RL-5). */
final readonly class Answers
{
    public const array KEYS = ['app_name', 'database', 'start_with', 'super_admin_email', 'super_admin_password', 'modules', 'https', 'playwright', 'claude'];

    public const array MODULES = ['passkeys', 'backups', 'impersonation'];

    /** @param  list<string>  $modules */
    private function __construct(
        public string $appName,
        public string $database,
        public string $startWith,
        public ?string $superAdminEmail,
        public ?string $superAdminPasswordHash,
        public array $modules,
        public bool $https,
        public bool $playwright,
        public bool $claude,
    ) {}

    public static function defaults(Detection $detection, string $folderName): self
    {
        return self::fromArray([], $detection, $folderName);
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function fromArray(array $input, Detection $detection, string $folderName): self
    {
        $unknown = array_diff(array_keys($input), self::KEYS);

        if ($unknown !== []) {
            throw ValidationException::withMessages(['answers' => __('Unknown setup answers: :keys', ['keys' => implode(', ', $unknown)])]);
        }

        $values = array_merge([
            'app_name' => Str::headline($folderName),
            'database' => $detection->postgres ? 'pgsql' : 'sqlite',
            'start_with' => 'demo',
            'super_admin_email' => null,
            'super_admin_password' => null,
            'modules' => self::MODULES,
            'https' => true,
            'playwright' => false,
            'claude' => $detection->claude,
        ], array_filter($input, fn (mixed $value): bool => $value !== null));

        /** @var array{app_name: string, database: string, start_with: string, super_admin_email: ?string, super_admin_password: ?string, modules: list<string>, https: bool, playwright: bool, claude: bool} $valid */
        $valid = Validator::make($values, [
            'app_name' => ['required', 'string', 'max:100'],
            'database' => ['required', Rule::in(['pgsql', 'sqlite'])],
            'start_with' => ['required', Rule::in(['demo', 'super_admin'])],
            'super_admin_email' => ['nullable', 'required_if:start_with,super_admin', 'email', 'max:255'],
            'super_admin_password' => ['nullable', 'required_if:start_with,super_admin', 'string', 'min:8'],
            'modules' => ['present', 'array'],
            'modules.*' => [Rule::in(self::MODULES)],
            'https' => ['required', 'boolean'],
            'playwright' => ['required', 'boolean'],
            'claude' => ['required', 'boolean'],
        ])->validate();

        $modules = array_values(array_unique($valid['modules']));

        if ($valid['database'] === 'sqlite') {
            $modules = array_values(array_diff($modules, ['backups']));
        }

        $usesSuperAdmin = $valid['start_with'] === 'super_admin';

        return new self(
            appName: trim($valid['app_name']),
            database: $valid['database'],
            startWith: $valid['start_with'],
            superAdminEmail: $usesSuperAdmin ? Str::lower(trim((string) $valid['super_admin_email'])) : null,
            superAdminPasswordHash: $usesSuperAdmin ? Hash::make((string) $valid['super_admin_password']) : null,
            modules: $modules,
            https: (bool) $valid['https'] || in_array('passkeys', $modules, true),
            playwright: (bool) $valid['playwright'],
            claude: (bool) $valid['claude'],
        );
    }

    /** @param  list<string>  $modules */
    public function withModules(array $modules): self
    {
        return new self($this->appName, $this->database, $this->startWith, $this->superAdminEmail, $this->superAdminPasswordHash, array_values($modules), $this->https, $this->playwright, $this->claude);
    }

    public function withHttps(bool $https): self
    {
        return new self($this->appName, $this->database, $this->startWith, $this->superAdminEmail, $this->superAdminPasswordHash, $this->modules, $https, $this->playwright, $this->claude);
    }

    public function moduleEnabled(string $module): bool
    {
        return in_array($module, $this->modules, true);
    }

    /** @return array<string, mixed> the answers safe to store in the marker: no password, no hash */
    public function toPublicArray(): array
    {
        return [
            'app_name' => $this->appName,
            'database' => $this->database,
            'start_with' => $this->startWith,
            'super_admin_email' => $this->superAdminEmail,
            'modules' => $this->modules,
            'https' => $this->https,
            'playwright' => $this->playwright,
            'claude' => $this->claude,
        ];
    }
}
```

Add the translation key `Unknown setup answers: :keys` to both JSON files (nl: `Onbekende setup-antwoorden: :keys`).

`app/Setup/Marker.php`:

```php
<?php

declare(strict_types=1);

namespace App\Setup;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Date;

/** storage/app/skv-installed (§5.3.3 step 9, RL-14): written once setup has finished; its absence means "not set up". */
final class Marker
{
    public const string FILE = 'storage/app/skv-installed';

    public static function exists(SetupContext $context): bool
    {
        return is_file($context->path(self::FILE));
    }

    /** @param  array<string, mixed>  $summary */
    public static function write(SetupContext $context, array $summary): void
    {
        $path = $context->path(self::FILE);
        @mkdir(dirname($path), 0755, true);

        file_put_contents($path, json_encode([
            'kit_version' => InstalledVersions::getRootPackage()['pretty_version'],
            'answers' => $context->answers->toPublicArray(),
            'start_with' => $context->answers->startWith,
            'installed_at' => Date::now()->toIso8601String(),
            'summary' => $summary,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    /** @return array{kit_version: string, answers: array<string, mixed>, start_with: string, installed_at: string, summary: array<string, mixed>}|null */
    public static function read(SetupContext $context): ?array
    {
        if (! self::exists($context)) {
            return null;
        }

        /** @var array{kit_version: string, answers: array<string, mixed>, start_with: string, installed_at: string, summary: array<string, mixed>} $marker */
        $marker = json_decode((string) file_get_contents($context->path(self::FILE)), true, flags: JSON_THROW_ON_ERROR);

        return $marker;
    }
}
```

`tests/Pest.php`, add the helper below `sameOrigin()`, with imports for `App\Setup\{Answers,Detection,SetupContext}`:

```php
/**
 * A throwaway project directory for setup steps (RL-2): .env from .env.example, storage and database folders.
 * Removed when the test ends; never the repository itself.
 */
function setupSandbox(?Detection $detection = null): SetupContext
{
    $directory = storage_path('framework/testing/setup/'.Str::uuid());

    foreach (['storage/app/private', 'database', 'public'] as $folder) {
        File::ensureDirectoryExists($directory.'/'.$folder);
    }

    File::copy(base_path('.env.example'), $directory.'/.env');

    // Register the clean-up on the running test case; Pest's test() proxy does not expose it to Larastan.
    $test = TestSuite::getInstance()->test;

    if (! $test instanceof TestCase) {
        throw new LogicException('setupSandbox() runs inside a test only.');
    }

    Reflection::call($test, 'beforeApplicationDestroyed', [fn () => File::deleteDirectory($directory)]);

    $detection ??= Detection::none();

    return new SetupContext($directory, Answers::defaults($detection, basename($directory)), $detection);
}
```

Imports: `Pest\TestSuite`, `Pest\Support\Reflection`, `Tests\TestCase`. `TestSuite::getInstance()->test` is the running `TestCase` (`vendor/pestphp/pest/src/TestSuite.php`). `beforeApplicationDestroyed()` is protected, so `Reflection::call()` invokes it, and the callback runs before the app is torn down.

Add `storage/framework/testing/setup/` to `.gitignore` if `storage/framework/testing` is not already ignored. Check `storage/framework/.gitignore`.

- [ ] **Step 4: Run them to see them pass**

Run: `php artisan test --compact tests/Feature/Setup`
Expected: PASS.

- [ ] **Step 5: Gate and commit**

```bash
git add app/Setup tests/Feature/Setup tests/Fixtures/e2e-answers.json tests/Pest.php lang
git commit -m "feat: add the setup step contract, answers schema, env writer and setup marker

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Toolchain and detection (DEC-23, §5.3.2)

**Files:**
- Create: `app/Setup/Support/Toolchain.php`, `app/Setup/Detector.php`
- Test: `tests/Feature/Setup/ToolchainTest.php`, `tests/Feature/Setup/DetectorTest.php`

**Interfaces:**
- Consumes: `Detection` (Task 2).
- Produces:

```php
namespace App\Setup\Support;

final class Toolchain
{
    public function __construct(private readonly ?string $home = null) {} // null = detect
    public function home(): string;
    /** @return array{HOME: string, PATH: string} */
    public function environment(): array;
    public function herd(): ?string;               // absolute path to the herd binary, or null
    public function which(string $binary): ?string; // first executable on environment()['PATH']
    public function nodeBinDirectory(): ?string;   // newest Herd nvm node bin dir, or null
    public function herdConfigPath(): string;      // $HOME/Library/Application Support/Herd/config/herd.json
}

namespace App\Setup;

final class Detector
{
    public function __construct(private readonly Toolchain $toolchain) {}
    public function detect(?string $requestHost = null, string $scheme = 'http'): Detection;
}
```

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Setup/ToolchainTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.2, §5.3.4 (DEC-23) */

use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->home = storage_path('framework/testing/setup/home-'.Str::uuid());
    File::ensureDirectoryExists($this->home.'/Library/Application Support/Herd/bin');
    File::ensureDirectoryExists($this->home.'/Library/Application Support/Herd/config/nvm/versions/node/v20.19.0/bin');
    File::ensureDirectoryExists($this->home.'/Library/Application Support/Herd/config/nvm/versions/node/v22.23.2/bin');
    File::ensureDirectoryExists($this->home.'/.local/bin');
    $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->home));
});

it('builds HOME and PATH for child processes instead of trusting php-fpm (DEC-23)', function (): void {
    $environment = new Toolchain($this->home)->environment();
    $path = explode(':', $environment['PATH']);

    expect($environment['HOME'])->toBe($this->home)
        ->and($path[0])->toBe($this->home.'/Library/Application Support/Herd/bin')
        ->and($path[1])->toBe($this->home.'/Library/Application Support/Herd/config/nvm/versions/node/v22.23.2/bin')
        ->and($path[2])->toBe($this->home.'/.local/bin')
        ->and($path)->toContain('/usr/bin');
});

it('finds herd by its absolute path and only when it exists (DEC-23)', function (): void {
    expect(new Toolchain($this->home)->herd())->toBeNull();

    $herd = $this->home.'/Library/Application Support/Herd/bin/herd';
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);

    expect(new Toolchain($this->home)->herd())->toBe($herd);
});

it('looks binaries up on the built PATH without a shell', function (): void {
    $claude = $this->home.'/.local/bin/claude';
    File::put($claude, "#!/bin/sh\n");
    chmod($claude, 0755);

    expect(new Toolchain($this->home)->which('claude'))->toBe($claude)
        ->and(new Toolchain($this->home)->which('definitely-not-installed'))->toBeNull();
});
```

The test fixes `dirname(PHP_BINARY)` out of the way: `Toolchain` uses `dirname(PHP_BINARY)` only when it ends in `/Herd/bin` **and** sits under `home()`. Otherwise it uses `home().'/Library/Application Support/Herd/bin'`. This keeps the test independent of the machine's PHP.

`tests/Feature/Setup/DetectorTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.2 */

use App\Setup\Detector;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    $this->home = storage_path('framework/testing/setup/home-'.Str::uuid());
    File::ensureDirectoryExists($this->home.'/Library/Application Support/Herd/config');
    $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->home));
});

it('detects nothing on a machine without Herd', function (): void {
    $detection = new Detector(new Toolchain($this->home))->detect('localhost');

    expect($detection->herd)->toBeFalse()
        ->and($detection->herdPro)->toBeFalse()
        ->and($detection->herdMail)->toBeFalse()
        ->and($detection->host)->toBe('localhost');
});

it('reads Herd Pro from the services JSON and the mail service from herd.json, never from exit codes (§5.3.2, §13)', function (): void {
    $herd = $this->home.'/Library/Application Support/Herd/bin/herd';
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);
    File::put($this->home.'/Library/Application Support/Herd/config/herd.json', json_encode(['mails' => true, 'smtpPort' => 2525], JSON_THROW_ON_ERROR));

    Process::fake([
        "*'services:list' '--json'*" => Process::result(output: '[{"type":"postgresql","status":"active"}]', exitCode: 1),
        "*'tld'*" => Process::result(output: "test\n"),
    ]);

    $detection = new Detector(new Toolchain($this->home))->detect();

    expect($detection->herd)->toBeTrue()
        ->and($detection->herdPro)->toBeTrue()
        ->and($detection->herdMail)->toBeTrue()
        ->and($detection->herdSmtpPort)->toBe(2525)
        ->and($detection->host)->toBe(basename(base_path()).'.test');
});

it('treats unparsable Herd output as "no Herd Pro" (§13)', function (): void {
    $herd = $this->home.'/Library/Application Support/Herd/bin/herd';
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);

    Process::fake([
        "*'services:list' '--json'*" => Process::result(output: 'Herd Pro is required', exitCode: 0),
        "*'tld'*" => Process::result(output: "test\n"),
    ]);

    expect(new Detector(new Toolchain($this->home))->detect()->herdPro)->toBeFalse();
});
```

The Postgres probe is a PDO connection, not a process. The test machine may or may not have Postgres, so the tests above do not assert `postgres`. `Detector::postgresReachable()` is `protected` and has its own focused test: it returns `false` for an unreachable port, with `127.0.0.1:1` and a 1-second timeout:

```php
it('reports Postgres unreachable when nothing listens (§5.3.2)', function (): void {
    expect(new Detector(new Toolchain($this->home))->postgresReachable('127.0.0.1', 1))->toBeFalse();
});
```

Make `postgresReachable(string $host = '127.0.0.1', int $port = 5432): bool` public for that reason, and document it as "Herd's known credentials: user root, empty password".

Before writing the Herd Pro parser, run `herd services:list --json` once (it prints service names and states only; no secrets) and record the real output shape in your report. Adapt the "is a JSON array" rule if Herd prints an object.

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test --compact tests/Feature/Setup/ToolchainTest.php tests/Feature/Setup/DetectorTest.php`
Expected: FAIL (classes missing).

- [ ] **Step 3: Implement**

`app/Setup/Support/Toolchain.php`:

```php
<?php

declare(strict_types=1);

namespace App\Setup\Support;

/**
 * The environment every setup child process gets (DEC-23): php-fpm under Herd has no PATH, so HOME and PATH are
 * built here and herd is always called by its absolute path. Lookups run in-process, never through a shell.
 */
final class Toolchain
{
    private const string SYSTEM_PATH = '/usr/local/bin:/opt/homebrew/bin:/usr/bin:/bin:/usr/sbin:/sbin';

    public function __construct(private readonly ?string $home = null) {}

    public function home(): string
    {
        if ($this->home !== null) {
            return $this->home;
        }

        $home = getenv('HOME');

        if (is_string($home) && $home !== '') {
            return $home;
        }

        $user = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;

        return is_array($user) ? (string) $user['dir'] : '/tmp';
    }

    /** @return array{HOME: string, PATH: string} */
    public function environment(): array
    {
        $directories = array_filter([
            $this->herdBinDirectory(),
            $this->nodeBinDirectory(),
            $this->home().'/.local/bin',
        ]);

        return ['HOME' => $this->home(), 'PATH' => implode(':', [...$directories, self::SYSTEM_PATH])];
    }

    public function herd(): ?string
    {
        $herd = $this->herdBinDirectory().'/herd';

        return is_file($herd) && is_executable($herd) ? $herd : null;
    }

    public function which(string $binary): ?string
    {
        foreach (explode(':', $this->environment()['PATH']) as $directory) {
            $candidate = $directory.'/'.$binary;

            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function nodeBinDirectory(): ?string
    {
        $versions = glob($this->home().'/Library/Application Support/Herd/config/nvm/versions/node/v*/bin', GLOB_ONLYDIR) ?: [];
        usort($versions, fn (string $a, string $b): int => version_compare(basename(dirname($b)), basename(dirname($a))));

        return $versions[0] ?? null;
    }

    public function herdConfigPath(): string
    {
        return $this->home().'/Library/Application Support/Herd/config/herd.json';
    }

    private function herdBinDirectory(): string
    {
        $phpDirectory = dirname(PHP_BINARY);

        return str_ends_with($phpDirectory, '/Herd/bin') && str_starts_with($phpDirectory, $this->home())
            ? $phpDirectory
            : $this->home().'/Library/Application Support/Herd/bin';
    }
}
```

`version_compare()` needs the `v` stripped: compare `ltrim(basename(dirname($x)), 'v')`. Fix that while implementing, and keep the test's two versions.

`app/Setup/Detector.php`: implement `detect()` as RL-4 says:
- `herd`: `$this->toolchain->herd() !== null`.
- `herdPro`: when Herd is present, run `Process::env($this->toolchain->environment())->timeout(10)->run([$herd, 'services:list', '--json'])`. Use `json_decode($result->output(), true)` and check `is_array(...) && array_is_list(...)`. Ignore the exit code.
- `herdMail` / `herdSmtpPort`: read `herdConfigPath()` with `json_decode`. `mails` must be truthy and `smtpPort` an int. Never print the file.
- `postgres`: `postgresReachable()`. This is `new PDO("pgsql:host={$host};port={$port};dbname=postgres", 'root', '', [PDO::ATTR_TIMEOUT => 2, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])` in a `try`, which returns false on `PDOException`.
- `fluxPro`: `Skv::hasFluxPro()`.
- `claude`: `$this->toolchain->which('claude') !== null`.
- `host`:
  - the request host when given;
  - else, when Herd is present, `basename(base_path()).'.'.trim(<herd tld output>)`;
  - else the `APP_URL` host from `parse_url((string) config('app.url'), PHP_URL_HOST)`, or `localhost`.
- `scheme`: the argument.

- [ ] **Step 4: Run them to see them pass**

Run: `php artisan test --compact tests/Feature/Setup/ToolchainTest.php tests/Feature/Setup/DetectorTest.php`
Expected: PASS.

- [ ] **Step 5: Gate and commit**

```bash
git add app/Setup tests/Feature/Setup
git commit -m "feat: detect herd, herd pro mail, postgres and claude with a php-fpm-safe toolchain

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: `SyncResult` reports permissions stripped from the super-admin badge (FU2-13)

**Files:**
- Modify: `app/Authorization/SyncResult.php`, `app/Authorization/SyncPermissions.php`, `app/Console/Commands/SyncPermissionsCommand.php`
- Test: `tests/Feature/Authorization/SyncPermissionsTest.php`

**Interfaces:**
- Produces: `SyncResult::$badgePermissionsStripped` (int, the 6th constructor argument, named `badgePermissionsStripped`). `changed()` includes it. The doctor (Task 12) and `doctor --fix` read `changed()`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Authorization/SyncPermissionsTest.php`:

```php
it('reports the permissions it strips from the super admin badge (FU2-13)', function (): void {
    $badge = RoleModel::query()->whereNull('team_id')->where('name', Role::SuperAdmin->value)->firstOrFail();
    PermissionsTeam::run(null, fn () => $badge->givePermissionTo(Permission::UsersView->value, Permission::RolesView->value));

    $result = app(SyncPermissions::class)->handle();

    expect($result->badgePermissionsStripped)->toBe(2)
        ->and($result->changed())->toBeTrue()
        ->and(app(SyncPermissions::class)->handle()->badgePermissionsStripped)->toBe(0);
});

it('lists every change it made when run as an artisan command (FU2-13)', function (): void {
    $badge = RoleModel::query()->whereNull('team_id')->where('name', Role::SuperAdmin->value)->firstOrFail();
    PermissionsTeam::run(null, fn () => $badge->givePermissionTo(Permission::UsersView->value));

    $this->artisan('permissions:sync')
        ->expectsOutputToContain('Permissions synced.')
        ->expectsOutputToContain('super admin badge permissions stripped')
        ->assertSuccessful();
});
```

Match the file's existing imports (`RoleModel`, `Role`, `Permission`, `PermissionsTeam`, `SyncPermissions`). If `PermissionsTeam` is not imported there, add `use App\Authorization\PermissionsTeam;`. Check the file's existing badge-drift test for how it attaches a permission and copy that pattern if it differs.

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test --compact tests/Feature/Authorization/SyncPermissionsTest.php`
Expected: FAIL ("Undefined property: badgePermissionsStripped").

- [ ] **Step 3: Implement**

`SyncResult`: add the constructor argument `public int $badgePermissionsStripped = 0` last (the default keeps other constructors valid; there are none outside `SyncPermissions`). Add `|| $this->badgePermissionsStripped > 0` to `changed()`.

`SyncPermissions::handle()`: replace `$this->systemRole(Role::SuperAdmin)->syncPermissions([]);` with:

```php
            $badge = $this->systemRole(Role::SuperAdmin);
            $badgePermissionsStripped = $badge->permissions()->count();
            $badge->syncPermissions([]);
```

and pass `badgePermissionsStripped: $badgePermissionsStripped` to the result.

`SyncPermissionsCommand::handle()`: after the existing loops, add one `twoColumnDetail` line per non-zero counter:

```php
        foreach ([
            'organisation admin template updated' => $result->organisationAdminChanged ? 1 : 0,
            'super-only permissions detached from team roles' => $result->superOnlyDetached,
            'super admin badges repaired' => $result->badgesRepaired,
            'super admin badge permissions stripped' => $result->badgePermissionsStripped,
        ] as $label => $count) {
            if ($count > 0) {
                $this->components->twoColumnDetail($label, (string) $count);
            }
        }
```

These are developer-facing console labels in the existing English-only style of this command, so they do not go through `__()`.

- [ ] **Step 4: Run them to see them pass**

Run: `php artisan test --compact tests/Feature/Authorization`
Expected: PASS.

- [ ] **Step 5: Gate and commit**

```bash
git add app/Authorization app/Console/Commands/SyncPermissionsCommand.php tests/Feature/Authorization/SyncPermissionsTest.php
git commit -m "feat: report the permissions permissions:sync strips from the super admin badge

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: The demo seeder and the super-admin and organisation commands (§4.12, DEC-26, DEC-28, W8)

**Files:**
- Create: `database/seeders/DemoSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Create: `app/Console/Commands/CreateSuperAdminCommand.php`, `app/Console/Commands/CreateOrganisationCommand.php`
- Test: `tests/Feature/Seeders/DemoSeederTest.php`, `tests/Feature/Console/CreateSuperAdminCommandTest.php`, `tests/Feature/Console/CreateOrganisationCommandTest.php`

**Interfaces:**
- Consumes: `SetSuperAdmin::handle(User, bool)`, `CreateOrganisation::handle(...)`, `InviteUser::handle(User $actor, Organisation, string $email, array $roleIds, Locale)`, `AcceptInvitation::handle(Invitation, string $name, string $password)`, `Hosts::apex()`, `Hosts::tenant($slug)`.
- Produces:
  - `Database\Seeders\DemoSeeder` with the constants `DemoSeeder::SLUGS = ['demo1', 'demo2']` and `DemoSeeder::PASSWORD = 'password'`.
  - `DemoSeeder::accounts(): list<array{email: string, role: string, host: string}>`, used by the summary (Task 10) and M6b's quick login.
  - `skv:create-super-admin {email?} {--name=} {--password=}`.
  - `skv:create-organisation {name?} {slug?} {admin-email?} {--description=} {--locale=nl}`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Seeders/DemoSeederTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.12 */

use App\Enums\ActivityEvent;
use App\Enums\UserStatus;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Facades\Tenant;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

it('seeds a super admin and demo1 and demo2, each with an admin, a user and a pending invitation (§4.12, DEC-28)', function (): void {
    $this->seed(DemoSeeder::class);

    $apex = config('tenancy.apex_domain');
    // Users are a tenant model (strict mode): read across organisations through the bypass.
    $superAdmin = app(CurrentTenant::class)->bypass(fn (): User => User::query()->where('email', "superadmin@{$apex}")->firstOrFail());

    expect($superAdmin->isSuperAdmin())->toBeTrue()
        ->and($superAdmin->organisation_id)->toBeNull()
        ->and(Hash::check(DemoSeeder::PASSWORD, $superAdmin->password))->toBeTrue();

    foreach (DemoSeeder::SLUGS as $slug) {
        $organisation = Organisation::query()->where('slug', $slug)->firstOrFail();

        Tenant::run($organisation, function () use ($organisation, $slug, $apex): void {
            $admin = User::query()->where('email', "admin@{$slug}.{$apex}")->firstOrFail();
            $member = User::query()->where('email', "user@{$slug}.{$apex}")->firstOrFail();

            expect($admin->status)->toBe(UserStatus::Active)
                ->and($admin->organisation_id)->toBe($organisation->id)
                ->and(Hash::check(DemoSeeder::PASSWORD, $admin->password))->toBeTrue()
                ->and($member->status)->toBe(UserStatus::Active)
                ->and(Invitation::query()->whereNull('accepted_at')->count())->toBe(1);
        });
    }

    expect(recordedActivity(ActivityEvent::OrganisationCreated))->toHaveCount(2)
        ->and(recordedActivity(ActivityEvent::InvitationAccepted))->toHaveCount(4);
});

it('is idempotent (§4.12)', function (): void {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    expect(Organisation::query()->whereIn('slug', DemoSeeder::SLUGS)->count())->toBe(2)
        ->and(app(CurrentTenant::class)->bypass(fn (): int => User::query()->where('is_super_admin', true)->count()))->toBe(1);
});

it('refuses to run in production (§4.12)', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    // --force: without it db:seed asks to confirm in production and cancels before the seeder runs.
    $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
})->throws(RuntimeException::class, 'never runs in production');

it('gives the demo user the Member role and sends the invitation mails right away (RL-8)', function (): void {
    config(['queue.default' => 'database']);

    $this->seed(DemoSeeder::class);

    $organisation = Organisation::query()->where('slug', 'demo1')->firstOrFail();
    $apex = config('tenancy.apex_domain');

    Tenant::run($organisation, function () use ($apex): void {
        expect(roleNamesOf(User::query()->where('email', "user@demo1.{$apex}")->firstOrFail()))->toContain('Member');
    });

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(config('queue.default'))->toBe('database');
});

it('lists the demo accounts for the setup summary', function (): void {
    $apex = config('tenancy.apex_domain');

    expect(array_column(DemoSeeder::accounts(), 'email'))->toBe([
        "superadmin@{$apex}",
        "admin@demo1.{$apex}", "user@demo1.{$apex}",
        "admin@demo2.{$apex}", "user@demo2.{$apex}",
    ]);
});

it('replaces the temporary development login (DEC-26)', function (): void {
    $this->seed();

    expect(app(CurrentTenant::class)->bypass(fn (): bool => User::query()->where('email', 'test@example.com')->exists()))->toBeFalse()
        ->and(Organisation::query()->whereIn('slug', DemoSeeder::SLUGS)->count())->toBe(2);
});
```

Reading users in this test: users are a tenant model (strict mode). Every `User` read outside `Tenant::run()` goes through `app(CurrentTenant::class)->bypass(...)`, as above. `roleNamesOf()` is a file-local helper in another test file. Check whether it is global (it is in M5's taken-names list); if it is not, read the role names inside `withPermissionsTeam()` the way `UpdateUserRolesTest` does.

`tests/Feature/Console/CreateSuperAdminCommandTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.12 */

use App\Authorization\Role;
use App\Enums\ActivityEvent;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Hash;

it('creates an active super admin without an organisation (§4.12, W8)', function (): void {
    $this->artisan('skv:create-super-admin', ['email' => 'owner@example.com', '--name' => 'Owner', '--password' => 'correct-horse-battery', '--no-interaction' => true])
        ->expectsOutputToContain('owner@example.com')
        ->doesntExpectOutputToContain('correct-horse-battery')
        ->assertSuccessful();

    $user = app(CurrentTenant::class)->bypass(fn (): User => User::query()->where('email', 'owner@example.com')->firstOrFail());

    expect($user->isSuperAdmin())->toBeTrue()
        ->and($user->organisation_id)->toBeNull()
        ->and(Hash::check('correct-horse-battery', $user->password))->toBeTrue()
        // A new user is inserted with the flag (BelongsToOrganisation's creating hook); SetSuperAdmin attaches the badge.
        ->and(withPermissionsTeam(null, fn (): bool => $user->hasRole(Role::SuperAdmin->value)))->toBeTrue();
});

it('promotes an existing user through SetSuperAdmin (RL-9)', function (): void {
    $member = User::factory()->for(Organisation::factory()->create(['slug' => 'acme']))->create(['email' => 'member@example.com']);

    $this->artisan('skv:create-super-admin', ['email' => 'member@example.com', '--no-interaction' => true])->assertSuccessful();

    expect($member->fresh()?->isSuperAdmin())->toBeTrue()
        ->and(recordedActivity(ActivityEvent::SuperAdminGranted))->toHaveCount(1);
});

it('refuses a user who is not active (RL-9)', function (): void {
    $pending = User::factory()->for(Organisation::factory()->create(['slug' => 'acme']))->pending()->create(['email' => 'invited@example.com']);

    $this->artisan('skv:create-super-admin', ['email' => 'invited@example.com', '--no-interaction' => true])->assertFailed();

    expect($pending->fresh()?->isSuperAdmin())->toBeFalse();
});

it('needs an email and a password without interaction', function (): void {
    $this->artisan('skv:create-super-admin', ['--no-interaction' => true])->assertFailed();
    $this->artisan('skv:create-super-admin', ['email' => 'new@example.com', '--no-interaction' => true])->assertFailed();
});
```

`tests/Feature/Console/CreateOrganisationCommandTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.12 */

use App\Enums\ActivityEvent;
use App\Models\Organisation;

it('creates an organisation and invites its first admin through CreateOrganisation (DEC-28)', function (): void {
    asSuperAdmin();

    $this->artisan('skv:create-organisation', ['name' => 'Acme', 'slug' => 'acme', 'admin-email' => 'boss@example.com', '--no-interaction' => true])
        ->expectsOutputToContain('boss@example.com')
        ->assertSuccessful();

    expect(Organisation::query()->where('slug', 'acme')->exists())->toBeTrue()
        ->and(recordedActivity(ActivityEvent::OrganisationCreated))->toHaveCount(1)
        ->and(recordedActivity(ActivityEvent::InvitationSent))->toHaveCount(1);
});

it('asks for a super admin first (RL-9)', function (): void {
    $this->artisan('skv:create-organisation', ['name' => 'Acme', 'slug' => 'acme', 'admin-email' => 'boss@example.com', '--no-interaction' => true])
        ->expectsOutputToContain('skv:create-super-admin')
        ->assertFailed();
});

it('reports validation errors without a stack trace', function (): void {
    asSuperAdmin();

    $this->artisan('skv:create-organisation', ['name' => 'Acme', 'slug' => 'admin', 'admin-email' => 'boss@example.com', '--no-interaction' => true])
        ->assertFailed();

    expect(Organisation::query()->count())->toBe(0);
});
```

`asSuperAdmin()` signs a super admin in. The command must not depend on the signed-in user: it picks the oldest active super admin (RL-9), which in that test is the same user. Invitation mail: run on the sync queue with the `array` mailer, per the kit rule (`config(['queue.default' => 'sync', 'mail.default' => 'array'])` in `beforeEach`). Check `InviteUserTest` for the exact setup and copy it.

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test --compact tests/Feature/Seeders tests/Feature/Console`
Expected: FAIL (seeder and commands missing; `DatabaseSeeder` still creates `test@example.com`).

- [ ] **Step 3: Implement**

`database/seeders/DemoSeeder.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Invitations\AcceptInvitation;
use App\Actions\Invitations\InviteUser;
use App\Actions\Organisations\CreateOrganisation;
use App\Actions\Roles\CreateRole;
use App\Authorization\Permission;
use App\Authorization\SetSuperAdmin;
use App\Enums\Locale;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use App\Tenancy\Hosts;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Optional demo data (§4.12, RL-8): a super admin and the organisations demo1 and demo2, each with an admin, a user
 * and a pending invitation, built through the real actions so the audit log has entries (DEC-28). Emails derive
 * from the apex; every demo account shares the password "password". Never in production. Idempotent.
 */
final class DemoSeeder extends Seeder
{
    public const array SLUGS = ['demo1', 'demo2'];

    public const string PASSWORD = 'password';

    /** @return list<array{email: string, role: string, host: string}> */
    public static function accounts(): array
    {
        $apex = Hosts::apex();
        $accounts = [['email' => "superadmin@{$apex}", 'role' => 'super_admin', 'host' => Hosts::admin()]];

        foreach (self::SLUGS as $slug) {
            $accounts[] = ['email' => "admin@{$slug}.{$apex}", 'role' => 'organisation_admin', 'host' => Hosts::tenant($slug)];
            $accounts[] = ['email' => "user@{$slug}.{$apex}", 'role' => 'Member', 'host' => Hosts::tenant($slug)];
        }

        return $accounts;
    }

    public function run(SetSuperAdmin $setSuperAdmin, CreateOrganisation $createOrganisation, CreateRole $createRole, InviteUser $inviteUser, AcceptInvitation $acceptInvitation): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo seeder never runs in production.');
        }

        // Invitations are accepted at once: send their mails now, while the token still exists.
        $queue = config('queue.default');
        config(['queue.default' => 'sync']);

        try {
            $superAdmin = $this->superAdmin($setSuperAdmin);

            foreach (self::SLUGS as $index => $slug) {
                if (Tenant::bypass(fn (): bool => Organisation::query()->withTrashed()->where('slug', $slug)->exists())) {
                    continue;
                }

                $apex = Hosts::apex();
                $organisation = $createOrganisation->handle($superAdmin, 'Demo '.($index + 1), $slug, null, "admin@{$slug}.{$apex}", Locale::Dutch);
                $member = [(int) $createRole->handle($superAdmin, $organisation, 'Member', [Permission::UsersView])->getKey()];

                Tenant::run($organisation, function () use ($organisation, $superAdmin, $inviteUser, $acceptInvitation, $member, $slug, $apex): void {
                    $acceptInvitation->handle($this->openInvitationFor("admin@{$slug}.{$apex}"), 'Demo admin', self::PASSWORD);
                    $acceptInvitation->handle($inviteUser->handle($superAdmin, $organisation, "user@{$slug}.{$apex}", $member, Locale::Dutch), 'Demo user', self::PASSWORD);
                    $inviteUser->handle($superAdmin, $organisation, "invited@{$slug}.{$apex}", $member, Locale::Dutch);
                });
            }
        } finally {
            config(['queue.default' => $queue]);
        }
    }

    private function superAdmin(SetSuperAdmin $setSuperAdmin): User
    {
        $email = 'superadmin@'.Hosts::apex();
        $existing = Tenant::bypass(fn (): ?User => User::query()->where('email', $email)->first());

        if ($existing instanceof User) {
            return $existing;
        }

        $user = User::factory()->superAdmin()->create(['name' => 'Demo super admin', 'email' => $email]);
        $setSuperAdmin->handle($user, true);

        return $user;
    }

    private function openInvitationFor(string $email): Invitation
    {
        return Invitation::query()->whereNull('accepted_at')->whereHas('user', fn ($query) => $query->where('email', $email))->latest('id')->firstOrFail();
    }
}
```

Verify these against the code before relying on them, and adapt (record each adaptation):
- **The `Invitation` relation names** (`user`) and the "open" columns (`accepted_at`). Check `app/Models/Invitation.php`.
- **Whether `AcceptInvitation` needs a bound tenant.** It is a session-level action. If it relies on the host, `Tenant::run()` covers it.
- **Whether `CreateOrganisation`/`InviteUser` already bind the tenant themselves.** They call `HostScope::lockForWrite()` before `Tenant::run()` (AGENTS.md), so they may refuse to run inside an outer `Tenant::run()` for another organisation. Here the outer run is the same organisation; if that still fails, call `InviteUser` outside the `Tenant::run()`.
- **`User::factory()->superAdmin()` hashes the factory password `password`.**
- **Queued mail:** the mails are queued after commit. In tests, `RefreshDatabase` plus `QUEUE_CONNECTION=sync` in `phpunit.xml` sends them through the `array` mailer. Check `phpunit.xml`'s queue and mail env; if the mailer is `log` there, that is fine too.

`database/seeders/DatabaseSeeder.php`:

```php
    public function run(): void
    {
        $this->call(EssentialsSeeder::class);

        // Demo data never runs in production (§4.12); setup's database step decides for installs.
        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
        }
    }
```

Remove the `SetSuperAdmin` parameter, the temporary-login comment and the unused imports.

`app/Console/Commands/CreateSuperAdminCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Authorization\SetSuperAdmin;
use App\Enums\UserStatus;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Description('Create a super admin, or promote an existing user (§4.12)')]
#[Signature('skv:create-super-admin {email? : The email address} {--name= : The name for a new user} {--password= : The password for a new user}')]
final class CreateSuperAdminCommand extends Command
{
    public function handle(SetSuperAdmin $setSuperAdmin): int
    {
        $email = $this->argument('email') ?? ($this->input->isInteractive() ? text(__('Email address'), required: true) : null);

        if (! is_string($email) || $email === '') {
            $this->components->error(__('Pass the email address: php artisan skv:create-super-admin you@example.com'));

            return self::FAILURE;
        }

        $email = mb_strtolower(trim($email));
        $existing = Tenant::bypass(fn (): ?User => User::query()->where('email', $email)->first());

        // SetSuperAdmin guards demotion only: a pending or disabled user would become a super admin who can never sign in.
        if ($existing instanceof User && $existing->status !== UserStatus::Active) {
            $this->components->error(__('Only an active user can become a super admin; :email is :status.', ['email' => $email, 'status' => $existing->status->label()]));

            return self::FAILURE;
        }

        try {
            $user = $existing ?? $this->createUser($email);
            $setSuperAdmin->handle($user, true);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                $this->components->error(implode(' ', $messages));
            }

            return self::FAILURE;
        }

        $this->components->info(__(':email is a super admin.', ['email' => $email]));

        return self::SUCCESS;
    }

    /** @throws ValidationException */
    private function createUser(string $email): User
    {
        $name = $this->option('name') ?? ($this->input->isInteractive() ? text(__('Name'), required: true) : 'Super admin');
        $password = $this->option('password') ?? ($this->input->isInteractive() ? password(__('Password'), required: true) : null);

        $valid = Validator::make(['email' => $email, 'name' => $name, 'password' => $password], [
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', Password::default()],
        ])->validate();

        return User::factory()->superAdmin()->create([
            'name' => $valid['name'],
            'email' => $valid['email'],
            'password' => $valid['password'],
            'status' => UserStatus::Active,
        ]);
    }
}
```

Check before relying on these:
- Using a factory in a production command is wrong: factories are dev-only (`fakerphp/faker` is `require-dev`). Create the user with `$user = new User; $user->forceFill(['organisation_id' => null, 'is_super_admin' => true, 'status' => UserStatus::Active, 'locale' => Locale::default(), 'name' => $valid['name'], 'email' => $valid['email'], 'password' => $valid['password']])->save();`.
  - A user without an organisation must carry the flag **at insert**, because `BelongsToOrganisation`'s creating hook asks `User::mayExistWithoutOrganisation()`, and that reads `is_super_admin`.
  - Then call `SetSuperAdmin::handle($user, true)`, which attaches the badge. It records `SuperAdminGranted` only when the stored flag changes, so a new super admin gets no grant entry; the test checks the badge instead.
  - Check the users table columns in the migrations (for example `email_verified_at`) and fill only what exists.
- `DemoSeeder` may keep the factory: seeders only run in local development.
- Check how the existing user-creation actions set the password (the `hashed` cast hashes on assignment).
- The name and email validation rules: reuse the kit's existing rule traits (`ProfileValidationRules` or similar under `app/Concerns`) instead of the literals above.

`app/Console/Commands/CreateOrganisationCommand.php`: same structure.
- Arguments prompt with `text()` when interactive.
- Find the actor as RL-9 says. A missing actor prints `__('Create a super admin first: php artisan skv:create-super-admin')` and fails.
- Call `CreateOrganisation::handle($actor, $name, $slug, $description, $email, Locale::tryFrom($locale) ?? Locale::default())`.
- Catch `ValidationException` and `AuthorizationException`, printing each message without a stack trace.
- On success print `__('Invitation sent to :email', ['email' => $email])`.

Add every new `__()` key to both JSON files (nl):
- `Email address` (exists?)
- `Pass the email address: php artisan skv:create-super-admin you@example.com`: "Geef het e-mailadres mee: php artisan skv:create-super-admin jij@example.com"
- `:email is a super admin.`: ":email is superbeheerder."
- `Create a super admin first: php artisan skv:create-super-admin`: "Maak eerst een superbeheerder aan: php artisan skv:create-super-admin"
- `Invitation sent to :email`: "Uitnodiging verstuurd naar :email"
- `Only an active user can become a super admin; :email is :status.`: "Alleen een actieve gebruiker kan superbeheerder worden; :email is :status."

Reuse existing keys where present (`Name`, `Password`).

- [ ] **Step 4: Run them to see them pass**

Run: `php artisan test --compact tests/Feature/Seeders tests/Feature/Console tests/Feature/LangCoverageTest.php tests/Arch`
Expected: PASS. `NameLeakTest` stays green because the seeder has no literal `skv`.

- [ ] **Step 5: Gate and commit**

```bash
git add database/seeders app/Console/Commands tests/Feature/Seeders tests/Feature/Console lang
git commit -m "feat: add the demo seeder and the create-super-admin and create-organisation commands

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: `skv:bootstrap` and the Composer and installer hooks (§5.2, RL-1, RL-13)

**Files:**
- Create: `app/Setup/Support/Bootstrap.php`, `app/Console/Commands/BootstrapCommand.php`
- Modify: `composer.json` (`post-create-project-cmd`, `extra.laravel.installer.post-create-project`)
- Modify: `tests/Feature/Tooling/ComposerScriptsTest.php`
- Test: `tests/Feature/Setup/BootstrapTest.php`

**Interfaces:**
- Consumes: `EnvFile`, `Toolchain` (Tasks 2–3).
- Produces:
  - `App\Setup\Support\Bootstrap::__construct(string $basePath, Toolchain $toolchain)`.
  - `Bootstrap::run(): list<string>` returns the human-readable actions it took. It is idempotent and never prompts.
  - Container binding: `Bootstrap::class` resolves to `new Bootstrap(base_path(), app(Toolchain::class))`. Tests rebind it to a sandbox.
  - `skv:bootstrap` (`--no-interaction`, `--ansi` and the installer's `--no-ansi`/`--quiet` all work).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Setup/BootstrapTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.2 */

use App\Setup\Support\Bootstrap;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    $this->context = setupSandbox();
    File::delete($this->context->path('.env'));
    File::copy(base_path('.env.example'), $this->context->path('.env.example'));
    // No Herd in this toolchain: a home without Herd's bin directory.
    $this->toolchain = new Toolchain($this->context->path('home'));
});

it('creates .env, an APP_KEY and the SQLite file, then migrates (§5.2 points 1, 2, 4)', function (): void {
    Process::fake(["*'artisan' 'migrate' '--graceful' '--force'*" => Process::result('Migrated')]);

    $actions = new Bootstrap($this->context->basePath, $this->toolchain)->run();
    $env = new EnvFile($this->context->path('.env'));

    expect($env->get('APP_KEY'))->toStartWith('base64:')
        ->and(is_file($this->context->path('database/database.sqlite')))->toBeTrue()
        ->and($actions)->not->toBeEmpty();

    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'migrate --graceful --force')
        && $process->path === $this->context->basePath);
});

it('never rotates an existing APP_KEY and is idempotent (§5.2)', function (): void {
    Process::fake(["*'artisan' 'migrate'*" => Process::result('Nothing to migrate')]);
    $bootstrap = new Bootstrap($this->context->basePath, $this->toolchain);

    $bootstrap->run();
    $key = new EnvFile($this->context->path('.env'))->get('APP_KEY');
    $bootstrap->run();

    expect(new EnvFile($this->context->path('.env'))->get('APP_KEY'))->toBe($key);
});

it('rewrites a localhost APP_URL to the Herd address when Herd is present (§5.2 point 3)', function (): void {
    $herd = $this->context->path('home/Library/Application Support/Herd/bin/herd');
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);

    Process::fake([
        "*herd' 'tld'*" => Process::result("test\n"),
        "*'artisan' 'migrate'*" => Process::result('Migrated'),
    ]);

    new Bootstrap($this->context->basePath, $this->toolchain)->run();

    expect(new EnvFile($this->context->path('.env'))->get('APP_URL'))->toBe('http://'.$this->context->folderName().'.test');
});

it('leaves a non-default APP_URL and a non-sqlite database alone (§5.2)', function (): void {
    File::copy(base_path('.env.example'), $this->context->path('.env'));
    $env = new EnvFile($this->context->path('.env'));
    $env->set(['APP_URL' => 'https://my-app.example', 'DB_CONNECTION' => 'pgsql']);

    Process::fake();

    new Bootstrap($this->context->basePath, $this->toolchain)->run();

    expect($env->get('APP_URL'))->toBe('https://my-app.example')
        ->and(is_file($this->context->path('database/database.sqlite')))->toBeFalse();
    Process::assertNothingRan();
});

it('returns early while the installer defers its hooks (§5.2)', function (): void {
    app()->instance(Bootstrap::class, new Bootstrap($this->context->basePath, $this->toolchain));
    putenv('LARAVEL_INSTALLER_DEFER_HOOKS=1');

    try {
        $this->artisan('skv:bootstrap', ['--no-interaction' => true])->assertSuccessful();
    } finally {
        putenv('LARAVEL_INSTALLER_DEFER_HOOKS');
    }

    expect(is_file($this->context->path('.env')))->toBeFalse();
});

it('tells the user to finish with skv:install (RL-1)', function (): void {
    Process::fake(["*'artisan' 'migrate'*" => Process::result('Migrated')]);
    app()->instance(Bootstrap::class, new Bootstrap($this->context->basePath, $this->toolchain));

    $this->artisan('skv:bootstrap', ['--no-interaction' => true, '--no-ansi' => true])
        ->expectsOutputToContain('php artisan skv:install')
        ->assertSuccessful();
});
```

The command reads `getenv('LARAVEL_INSTALLER_DEFER_HOOKS')`.

Append to `tests/Feature/Tooling/ComposerScriptsTest.php`:

```php
it('runs skv:bootstrap from the Composer and installer hooks (§5.2, RL-13)', function (): void {
    /** @var array{scripts: array<string, list<string>>, extra: array{laravel: array{installer: array{post-create-project: list<string>}}}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['post-root-package-install'])->toBe(["@php -r \"file_exists('.env') || copy('.env.example', '.env');\""])
        ->and($composer['scripts']['post-create-project-cmd'])->toBe(['@php artisan skv:bootstrap --no-interaction --ansi'])
        ->and($composer['extra']['laravel']['installer']['post-create-project'])->toBe(['@php artisan skv:bootstrap --no-interaction']);
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test --compact tests/Feature/Setup/BootstrapTest.php tests/Feature/Tooling/ComposerScriptsTest.php`
Expected: FAIL (class missing; hooks still run `key:generate`).

- [ ] **Step 3: Implement**

`app/Setup/Support/Bootstrap.php`:

```php
<?php

declare(strict_types=1);

namespace App\Setup\Support;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Process;

/**
 * The non-interactive first run (§5.2, RL-1): .env, APP_KEY, the Herd APP_URL and the SQLite file plus migrate.
 * Never chooses a database, never prompts, never caches config or routes. skv:install runs it as its step 0.
 */
final readonly class Bootstrap
{
    public function __construct(private string $basePath, private Toolchain $toolchain) {}

    /** @return list<string> */
    public function run(): array
    {
        $actions = [];
        $env = new EnvFile($this->basePath.'/.env');

        if (! $env->exists() && is_file($this->basePath.'/.env.example')) {
            copy($this->basePath.'/.env.example', $this->basePath.'/.env');
            $actions[] = __('Created .env from .env.example');
        }

        if (($env->get('APP_KEY') ?? '') === '') {
            $env->set(['APP_KEY' => 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher', 'AES-256-CBC')))]);
            $actions[] = __('Generated an application key');
        }

        $herd = $this->toolchain->herd();

        if ($herd !== null && preg_match('#^http://localhost(:8000)?/?$#', (string) $env->get('APP_URL')) === 1) {
            $tld = trim(Process::env($this->toolchain->environment())->timeout(10)->run([$herd, 'tld'])->output());

            if (preg_match('/^[a-z0-9.-]+$/', $tld) === 1) {
                $env->set(['APP_URL' => 'http://'.basename($this->basePath).'.'.$tld]);
                $actions[] = __('Set APP_URL to the Herd address');
            }
        }

        if (($env->get('DB_CONNECTION') ?? 'sqlite') === 'sqlite') {
            $database = $this->basePath.'/database/database.sqlite';

            if (! is_file($database)) {
                touch($database);
                $actions[] = __('Created the SQLite database');
            }

            Process::path($this->basePath)->env($this->toolchain->environment())->timeout(300)
                ->run([PHP_BINARY, 'artisan', 'migrate', '--graceful', '--force', '--no-interaction'])
                ->throw();
            $actions[] = __('Migrated the database');
        }

        return $actions;
    }
}
```

Migrate runs as a child process in `$basePath`, so it reads the `.env` this method just wrote: new `APP_KEY`, the sqlite path. `Process::fake()` intercepts it in tests. `Process::assertRan()` receives a `PendingProcess`; check how its `command` and `path` read in this Laravel version and adapt the assertion closure.

`app/Console/Commands/BootstrapCommand.php`:

```php
#[Description('Prepare a fresh copy of the kit: .env, key, Herd URL, SQLite (§5.2)')]
#[Signature('skv:bootstrap')]
final class BootstrapCommand extends Command
{
    public function handle(Bootstrap $bootstrap): int
    {
        if (getenv('LARAVEL_INSTALLER_DEFER_HOOKS') !== false && getenv('LARAVEL_INSTALLER_DEFER_HOOKS') !== '') {
            return self::SUCCESS;
        }

        foreach ($bootstrap->run() as $action) {
            $this->components->twoColumnDetail($action, __('done'));
        }

        $this->components->info(__('Run php artisan skv:install to finish setting up.'));

        return self::SUCCESS;
    }
}
```

Register the binding in `AppServiceProvider::register()`:

```php
        $this->app->bind(Bootstrap::class, fn (Application $app): Bootstrap => new Bootstrap($app->basePath(), $app->make(Toolchain::class)));
```

`composer.json`:
- set `post-create-project-cmd` to `["@php artisan skv:bootstrap --no-interaction --ansi"]`
- set `extra.laravel.installer.post-create-project` to `["@php artisan skv:bootstrap --no-interaction"]`
- keep `post-root-package-install` as it is

New lang keys (nl):
- `Created .env from .env.example`: ".env aangemaakt vanuit .env.example"
- `Generated an application key`: "Applicatiesleutel aangemaakt"
- `Set APP_URL to the Herd address`: "APP_URL op het Herd-adres gezet"
- `Created the SQLite database`: "SQLite-database aangemaakt"
- `Migrated the database`: "Database gemigreerd"
- `done`: "klaar"
- `Run php artisan skv:install to finish setting up.`: "Rond de installatie af met php artisan skv:install."

The `__()` calls run before a locale is applied, in `config('app.locale')` (nl by default), which is fine.

- [ ] **Step 4: Run them to see them pass**

Run: `php artisan test --compact tests/Feature/Setup/BootstrapTest.php tests/Feature/Tooling`
Expected: PASS.

- [ ] **Step 5: Gate and commit**

```bash
git add app/Setup app/Console/Commands/BootstrapCommand.php app/Providers/AppServiceProvider.php composer.json tests/Feature/Setup/BootstrapTest.php tests/Feature/Tooling/ComposerScriptsTest.php lang
git commit -m "feat: add skv:bootstrap and run it from the composer and installer hooks

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Steps 1, 3, 4 and 6: environment, `herd.yml`, branding, git (§5.3.3, DEC-29)

**Files:**
- Create: `app/Setup/Steps/EnvironmentStep.php`, `app/Setup/Steps/HerdManifestStep.php`, `app/Setup/Steps/BrandingStep.php`, `app/Setup/Steps/GitStep.php`
- Modify: `app/Support/Branding.php` (`isValidLogoPath()`, `logoUrl()` ignores invalid values)
- Test: `tests/Feature/Setup/Steps/EnvironmentStepTest.php`, `HerdManifestStepTest.php`, `BrandingStepTest.php`, `GitStepTest.php`, plus `tests/Feature/Layouts/BrandingTest.php` (logo rule)

**Interfaces:**
- Consumes: `SetupStep`, `SetupContext`, `StepResult`, `EnvFile`, `Toolchain`, `Detection`, `Answers`.
- Produces:
  - four steps with keys `environment`, `herd-manifest`, `branding`, `git`
  - `Branding::isValidLogoPath(string $path, ?string $publicPath = null): bool`: a relative path to an existing file under `public/`, with no scheme, no `//`, no `..`, and an extension in `svg|png|jpg|jpeg|webp`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Setup/Steps/EnvironmentStepTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.3 */

use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\Steps\EnvironmentStep;
use App\Setup\Support\EnvFile;

it('writes the app name, address, apex, health key, faker locale and module flags (§5.3.3 step 1)', function (): void {
    $detection = new Detection(herd: true, herdPro: false, herdMail: false, herdSmtpPort: null, postgres: false, fluxPro: false, claude: false, host: 'my-app.test', scheme: 'http');
    $context = setupSandbox($detection);
    $context->answers = Answers::fromArray(['app_name' => 'My App', 'modules' => ['impersonation'], 'https' => false], $detection, 'my-app');

    $result = new EnvironmentStep->run($context);
    $env = new EnvFile($context->path('.env'));

    expect($result->status)->toBe('done')
        ->and($env->get('APP_NAME'))->toBe('My App')
        ->and($env->get('APP_URL'))->toBe('http://my-app.test')
        ->and($env->get('TENANCY_APEX_DOMAIN'))->toBe('my-app.test')
        ->and(strlen((string) $env->get('HEALTH_CHECK_KEY')))->toBe(40)
        ->and($env->get('APP_FAKER_LOCALE'))->toBe('nl_NL')
        ->and($env->get('MODULE_IMPERSONATION'))->toBe('true')
        ->and($env->get('MODULE_PASSKEYS'))->toBe('false')
        ->and($env->get('MODULE_BACKUPS'))->toBe('false')
        ->and($env->get('MAIL_MAILER'))->toBe('log')
        ->and($result->message)->toContain(__('Mail is written to the log'));
});

it('keeps the health key and APP_KEY it finds (§5.3.3 step 1)', function (): void {
    $context = setupSandbox();
    $env = new EnvFile($context->path('.env'));
    $env->set(['HEALTH_CHECK_KEY' => str_repeat('k', 40), 'APP_KEY' => 'base64:existing']);

    new EnvironmentStep->run($context);

    expect($env->get('HEALTH_CHECK_KEY'))->toBe(str_repeat('k', 40))->and($env->get('APP_KEY'))->toBe('base64:existing');
});

it('uses Herd Pro mail unless the installer already set mail (§5.3.3 step 1)', function (bool $installerSetMail, string $expected): void {
    $detection = new Detection(herd: true, herdPro: true, herdMail: true, herdSmtpPort: 2526, postgres: false, fluxPro: false, claude: false, host: 'my-app.test', scheme: 'http');
    $context = setupSandbox($detection);
    $env = new EnvFile($context->path('.env'));

    if ($installerSetMail) {
        $env->set(['MAIL_MAILER' => 'smtp', 'MAIL_PORT' => '2525', 'MAIL_USERNAME' => 'my-app']);
    }

    new EnvironmentStep->run($context);

    expect($env->get('MAIL_MAILER'))->toBe('smtp')->and($env->get('MAIL_PORT'))->toBe($expected);
})->with([
    'fresh' => [false, '2526'],
    'installer set it' => [true, '2525'],
]);

it('is complete once the file holds the answers (§5.3.1)', function (): void {
    $context = setupSandbox();
    $step = new EnvironmentStep;

    expect($step->isComplete($context))->toBeFalse();
    $step->run($context);
    expect($step->isComplete($context))->toBeTrue();
});
```

`tests/Feature/Setup/Steps/HerdManifestStepTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.3 */

use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\Steps\HerdManifestStep;
use Symfony\Component\Yaml\Yaml;

it('writes herd.yml with the name, PHP 8.4, the HTTPS answer and PostgreSQL 18 on pgsql (§5.3.3 step 3)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['database' => 'pgsql', 'modules' => [], 'https' => false], Detection::none(), $context->folderName());

    new HerdManifestStep->run($context);
    $manifest = Yaml::parseFile($context->path('herd.yml'));

    expect($manifest['name'])->toBe($context->folderName())
        ->and($manifest['php'])->toBe('8.4')
        ->and($manifest['secured'])->toBeFalse()
        ->and($manifest['services']['postgresql']['version'] ?? null)->toStartWith('18')
        ->and($manifest['services']['postgresql']['port'] ?? null)->toBe('${DB_PORT}');
});

it('lists no services on SQLite', function (): void {
    $context = setupSandbox();

    new HerdManifestStep->run($context);

    expect(Yaml::parseFile($context->path('herd.yml')))->not->toHaveKey('services');
});
```

`symfony/yaml` arrives only as a transitive **dev** package (boost → roster), so app code must not use it. Tests may: dev packages are always installed in tests. Before choosing the service key and version string, run `herd services:list --json` and record the Postgres entry's `type`/`version` fields in your report. Herd's docs show `mysql: version: 8.0.36`, so use the exact version Herd lists for PostgreSQL 18.

`tests/Feature/Setup/Steps/BrandingStepTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.3, §6.5 (DEC-29) */

use App\Setup\Steps\BrandingStep;
use App\Setup\Support\EnvFile;
use Illuminate\Support\Facades\File;

it('keeps a valid accent and logo and writes the app name (§5.3.3 step 4)', function (): void {
    $context = setupSandbox();
    File::put($context->path('public/logo.svg'), '<svg/>');
    $env = new EnvFile($context->path('.env'));
    $env->set(['BRANDING_ACCENT' => 'indigo', 'BRANDING_LOGO' => 'logo.svg']);

    $result = new BrandingStep->run($context);

    expect($result->status)->toBe('done')
        ->and($env->get('BRANDING_ACCENT'))->toBe('indigo')
        ->and($env->get('BRANDING_LOGO'))->toBe('logo.svg');
});

it('clears an absolute or escaping logo and resets an unknown accent (DEC-29)', function (string $logo): void {
    $context = setupSandbox();
    $env = new EnvFile($context->path('.env'));
    $env->set(['BRANDING_ACCENT' => 'chartreuse', 'BRANDING_LOGO' => $logo]);

    $result = new BrandingStep->run($context);

    expect($env->get('BRANDING_ACCENT'))->toBe('neutral')
        ->and($env->get('BRANDING_LOGO'))->toBe('')
        ->and($result->message)->toContain(__('The logo must be a file under public/'));
})->with([
    'url' => ['https://tracker.example/x.svg'],
    'protocol-relative' => ['//tracker.example/x.svg'],
    'escaping' => ['../.env'],
    'missing file' => ['missing.svg'],
    'not an image' => ['index.php'],
]);
```

Append to `tests/Feature/Layouts/BrandingTest.php`:

```php
it('ignores a logo that is not a file under public/ (DEC-29)', function (): void {
    config(['branding.logo' => 'https://tracker.example/x.svg']);

    expect(Branding::logoUrl())->toBeNull();
});
```

`tests/Feature/Setup/Steps/GitStepTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.3 */

use App\Setup\Steps\GitStep;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

it('initialises a repository and sets the hooks path (§5.3.3 step 6)', function (): void {
    $context = setupSandbox();
    Process::fake(["*git' 'init'*" => Process::result(''), "*git' 'config' 'core.hooksPath' '.githooks'*" => Process::result('')]);

    expect(new GitStep->run($context)->status)->toBe('done');

    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'git init'));
    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'core.hooksPath .githooks'));
});

it('keeps an existing repository, including a worktree .git file (DEC-25)', function (): void {
    $context = setupSandbox();
    file_put_contents($context->path('.git'), "gitdir: /elsewhere\n");
    Process::fake(["*git' 'config' 'core.hooksPath' '.githooks'*" => Process::result('')]);

    new GitStep->run($context);

    Process::assertDidntRun(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'git init'));
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test --compact tests/Feature/Setup/Steps tests/Feature/Layouts/BrandingTest.php`
Expected: FAIL (steps missing; `logoUrl()` returns the URL).

- [ ] **Step 3: Implement**

Each step is a `final class … implements SetupStep` with a class docblock naming its §5.3.3 number. Labels are `__()` keys:
- `Environment file`: "Omgevingsbestand"
- `herd.yml`
- `Branding`: "Huisstijl"
- `Git`

`EnvironmentStep::run()`:

```php
    public function run(SetupContext $context): StepResult
    {
        $env = new EnvFile($context->path('.env'));
        $answers = $context->answers;
        $host = $context->detection->host;

        // A site the Herd step already secured keeps https on a re-run (the CLI always detects http).
        $scheme = str_starts_with((string) $env->get('APP_URL'), 'https://'.$host) ? 'https' : $context->detection->scheme;

        $variables = [
            'APP_NAME' => $answers->appName,
            'APP_URL' => $scheme.'://'.$host,
            'TENANCY_APEX_DOMAIN' => $host,
            'APP_FAKER_LOCALE' => 'nl_NL',
            'MODULE_PASSKEYS' => $answers->moduleEnabled('passkeys'),
            'MODULE_BACKUPS' => $answers->moduleEnabled('backups'),
            'MODULE_IMPERSONATION' => $answers->moduleEnabled('impersonation'),
        ];

        if (($env->get('HEALTH_CHECK_KEY') ?? '') === '') {
            $variables['HEALTH_CHECK_KEY'] = Str::random(40);
        }

        $installerSetMail = ($env->get('MAIL_MAILER') ?? 'log') !== 'log';
        $note = '';

        if ($context->detection->herdMail && $context->detection->herdSmtpPort !== null && ! $installerSetMail) {
            $variables += [
                'MAIL_MAILER' => 'smtp',
                'MAIL_HOST' => '127.0.0.1',
                'MAIL_PORT' => $context->detection->herdSmtpPort,
                'MAIL_USERNAME' => '${APP_NAME}',
            ];
        } elseif (! $installerSetMail) {
            $note = __('Mail is written to the log (storage/logs/laravel.log).');
        }

        $env->set($variables);

        return StepResult::done($note);
    }
```

`MAIL_USERNAME="${APP_NAME}"` needs double quotes for Dotenv interpolation. Check how `Env::writeVariables()` quotes `${APP_NAME}`. If it writes it unquoted or single-quoted, write that one line through a small `EnvFile::setRaw(string $key, string $quotedValue)` that replaces or appends the line verbatim. Add a test assertion that `Dotenv::parse` of the written file resolves `MAIL_USERNAME` to the app name.

`isComplete()` compares every key in `$variables` with `$env->get()`, with two exceptions:
- the generated `HEALTH_CHECK_KEY` only has to be non-empty;
- `APP_URL` is compared by host only.

Add a test, "keeps an https APP_URL for the same host on a re-run": set `APP_URL=https://my-app.test`, run the step with the http detection, and expect the https value.

`HerdManifestStep::run()` writes:

```php
        file_put_contents($context->path('herd.yml'), $this->manifest($context));
    }

    /** The fixed herd.yml (§5.3.3 step 3), written by hand: symfony/yaml is no runtime dependency of the kit. */
    private function manifest(SetupContext $context): string
    {
        $lines = ['name: '.$context->folderName(), "php: '8.4'", 'secured: '.($context->answers->https ? 'true' : 'false')];

        if ($context->answers->database === 'pgsql') {
            array_push($lines, 'services:', '    postgresql:', "        version: '".self::POSTGRES_VERSION."'", "        port: '\${DB_PORT}'");
        }

        return implode("\n", $lines)."\n";
```

`self::POSTGRES_VERSION` is the exact string Herd lists, recorded in your report. The folder name is a DNS-safe slug (Herd site names are), so it needs no YAML quoting. `isComplete()`: the file exists and equals `manifest()`.

`Branding` (M5 class):

```php
    public const array LOGO_EXTENSIONS = ['svg', 'png', 'jpg', 'jpeg', 'webp'];

    /** DEC-29: a logo is a relative path to an image under public/, never a URL (it would load on every host). */
    public static function isValidLogoPath(string $path, ?string $publicPath = null): bool
    {
        $path = ltrim(trim($path), '/');

        if ($path === '' || str_contains($path, '..') || str_contains($path, '://') || str_starts_with(trim($path), '//')) {
            return false;
        }

        if (! in_array(Str::lower(pathinfo($path, PATHINFO_EXTENSION)), self::LOGO_EXTENSIONS, true)) {
            return false;
        }

        return is_file(rtrim($publicPath ?? public_path(), '/').'/'.$path);
    }
```

Check the `//` case: `ltrim` strips leading slashes, so test the raw value before trimming. Write the method so all five dataset rows fail.

`logoUrl()` returns `asset($path)` only when `isValidLogoPath()` is true. In M5's `BrandingTest`, the logo test uses a file under `public/`; if it uses a non-existent file, create it with `File::put(public_path('test-logo.svg'), ...)` and delete it afterwards.

`BrandingStep::run()`:
- Read `BRANDING_ACCENT`/`BRANDING_LOGO` from the sandbox `.env`.
- Reset an accent not in `Branding::ACCENTS` to `neutral`.
- Clear a logo for which `isValidLogoPath($logo, $context->path('public'))` is false.
- Write `APP_NAME` (again, idempotent), `BRANDING_ACCENT` and `BRANDING_LOGO`.
- Return `done()` with a message: `__('The logo must be a file under public/; it was cleared.')` when cleared, and `__('Unknown accent :accent; using neutral.')` when reset.

Add both keys (nl):
- "Het logo moet een bestand onder public/ zijn; het is gewist."
- "Onbekende accentkleur :accent; neutral wordt gebruikt."

Also add `Mail is written to the log (storage/logs/laravel.log).` (nl: "Mail wordt naar de log geschreven (storage/logs/laravel.log).").

`GitStep::run()`:
- When neither a `.git` directory nor a `.git` file exists, run `[git, 'init']` with `Process::path($context->basePath)->env($toolchain->environment())`.
- Then run `[git, 'config', 'core.hooksPath', '.githooks']`.
- The git binary is `$toolchain->which('git') ?? 'git'`.
- `isComplete()`: `.git` exists **and** `git config --get core.hooksPath` outputs `.githooks`.
- Inject `Toolchain` through the constructor, defaulting to `app(Toolchain::class)`. Tests construct the step with `new GitStep` and rely on `Process::fake()`.

- [ ] **Step 4: Run them to see them pass**

Run: `php artisan test --compact tests/Feature/Setup tests/Feature/Layouts/BrandingTest.php tests/Feature/LangCoverageTest.php`
Expected: PASS.

- [ ] **Step 5: Gate and commit**

```bash
git add app/Setup app/Support/Branding.php tests/Feature/Setup tests/Feature/Layouts/BrandingTest.php lang
git commit -m "feat: add the environment, herd.yml, branding and git setup steps

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Step 2: the database, with an atomic switch (§5.3.3 step 2, §7.3, RL-15)

**Files:**
- Create: `app/Setup/Steps/DatabaseStep.php`
- Test: `tests/Feature/Setup/Steps/DatabaseStepTest.php`

**Interfaces:**
- Consumes: `Answers` (database, start_with, super admin), `EnvFile`, `SetSuperAdmin`, `DemoSeeder`, `EssentialsSeeder`.
- Produces:
  - step key `database`
  - `DatabaseStep::databaseName(SetupContext): string`: the folder name lower-cased, runs of characters other than `[a-z0-9_]` folded to `_`, trimmed of `_`, at most 50 characters
  - After `run()`, `config('database.default')` is `setup`, a runtime connection pointing at the new database. Later steps in the same process use it (RL-6). Tests restore it.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Setup/Steps/DatabaseStepTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.3, §7.3 */

use App\Models\Organisation;
use App\Models\User;
use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\Steps\DatabaseStep;
use App\Setup\Support\EnvFile;
use App\Tenancy\CurrentTenant;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->previousConnection = config('database.default');
});

// afterEach, not beforeApplicationDestroyed: RefreshDatabase reads database.default again at teardown.
afterEach(function (): void {
    DB::purge('setup');
    config(['database.default' => $this->previousConnection]);
});

it('migrates, syncs and seeds the demo on SQLite, then writes DB_* and .env.testing (§5.3.3 step 2)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['database' => 'sqlite'], Detection::none(), $context->folderName());

    $result = new DatabaseStep->run($context);

    expect($result->status)->toBe('done')
        ->and(new EnvFile($context->path('.env'))->get('DB_CONNECTION'))->toBe('sqlite')
        ->and(new EnvFile($context->path('.env.testing'))->get('DB_DATABASE'))->toBe(':memory:')
        ->and(DB::connection('setup')->table('organisations')->whereIn('slug', DemoSeeder::SLUGS)->count())->toBe(2)
        ->and(new DatabaseStep->isComplete($context))->toBeTrue();
});

it('creates the real super admin instead of demo data when asked (§5.3.3 step 2)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray([
        'database' => 'sqlite',
        'start_with' => 'super_admin',
        'super_admin_email' => 'owner@example.com',
        'super_admin_password' => 'correct-horse-battery',
    ], Detection::none(), $context->folderName());

    new DatabaseStep->run($context);

    $owner = DB::connection('setup')->table('users')->where('email', 'owner@example.com')->sole();

    expect((bool) $owner->is_super_admin)->toBeTrue()
        ->and($owner->organisation_id)->toBeNull()
        ->and(DB::connection('setup')->table('organisations')->count())->toBe(0);
});

it('refuses a committed PostgreSQL schema dump (§7.3, RL-15)', function (): void {
    $context = setupSandbox();
    File::ensureDirectoryExists($context->path('database/schema'));
    File::put($context->path('database/schema/pgsql-schema.sql'), '-- dump');
    $context->answers = Answers::fromArray(['database' => 'pgsql', 'modules' => []], Detection::none(), $context->folderName());

    new DatabaseStep->run($context);
})->throws(RuntimeException::class, 'pgsql-schema');

it('creates the app and test databases on PostgreSQL and writes DB_* only after seeding (§5.3.3 step 2)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['database' => 'pgsql', 'modules' => []], Detection::none(), $context->folderName());
    $name = DatabaseStep::databaseName($context);
    /** @var array{host: string, port: string|int, username: string, password: string} $server */
    $server = config('database.connections.pgsql');
    $admin = new PDO("pgsql:host={$server['host']};port={$server['port']};dbname=postgres", $server['username'], $server['password']);
    // Runs after afterEach's purge of the setup connection, so no session holds the databases.
    $this->beforeApplicationDestroyed(function () use ($admin, $name): void {
        foreach ([$name, "{$name}_test"] as $database) {
            $admin->exec('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
        }
    });

    new DatabaseStep->run($context);
    $env = new EnvFile($context->path('.env'));

    expect($env->get('DB_CONNECTION'))->toBe('pgsql')
        ->and($env->get('DB_DATABASE'))->toBe($name)
        ->and(new EnvFile($context->path('.env.testing'))->get('DB_DATABASE'))->toBe("{$name}_test")
        ->and($testDatabaseExists($admin, "{$name}_test"))->toBeTrue()
        ->and(DB::connection('setup')->table('organisations')->count())->toBe(2);
})->skip(fn (): bool => config('database.default') !== 'pgsql', 'runs under composer test:pgsql only');
```

- The sandbox folder is a UUID, so `databaseName()` yields a unique name; the test drops it afterwards.
- Check the `DROP DATABASE … WITH (FORCE)` syntax for PostgreSQL 18; it exists since PG 13.
- If the parallel test runner reuses folder names, prefix the name with `setup_probe_` in tests by passing a sandbox folder name that starts with it. `setupSandbox()` can accept a `$prefix` argument for this.
- Read users through the `setup` connection with the query builder, as above. That avoids Eloquent's default connection and strict tenant mode.
- `$testDatabaseExists` is a file-local closure at the top of the file. It narrows `PDO::query()`'s `PDOStatement|false` for Larastan:

  ```php
  $testDatabaseExists = function (PDO $admin, string $database): bool {
      $statement = $admin->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
      $statement->execute([$database]);

      return $statement->fetchColumn() !== false;
  };
  ```

  The test closure uses it via `use ($testDatabaseExists)`.

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test --compact tests/Feature/Setup/Steps/DatabaseStepTest.php`
Expected: FAIL (step missing).

- [ ] **Step 3: Implement**

`DatabaseStep::run()`, in this order. Any failure throws, so `.env` keeps the old `DB_*` (the atomic switch):

1. **Schema dump:** if `glob($context->path('database/schema/pgsql-schema.*'))` is non-empty, throw `RuntimeException(__('Remove database/schema/pgsql-schema.* first: setup runs plain migrations (§7.3).'))`. The message keeps the literal `pgsql-schema`.
2. **The connection:**
   - **pgsql:**
     - Connect over PDO to the `postgres` database with the credentials of `config('database.connections.pgsql')` (host, port, username, password). Their defaults are Herd's 127.0.0.1, 5432, `root` and `''` (RL-4); CI overrides them through `DB_*`.
     - `CREATE DATABASE "<name>"` and `"<name>_test"` when `SELECT 1 FROM pg_database WHERE datname = ?` finds none. Quote identifiers with `str_replace('"', '""', ...)`.
     - Then `config(['database.connections.setup' => array_merge(config('database.connections.pgsql'), ['database' => $name])])`. Step 6 writes the host, port, username and password that were used.
   - **sqlite:** `touch($context->path('database/database.sqlite'))` when missing, then `config(['database.connections.setup' => array_merge(config('database.connections.sqlite'), ['database' => $context->path('database/database.sqlite')])])`.
3. `DB::purge('setup'); config(['database.default' => 'setup']);`
4. `Artisan::call('migrate', ['--database' => 'setup', '--force' => true])`. Then `Artisan::call('permissions:sync')`. Then `Artisan::call('db:seed', ['--class' => EssentialsSeeder::class, '--force' => true])`. Check every exit code; throw on non-zero with `Artisan::output()`.
5. **Seed the start:**
   - `demo`: `Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true])`.
   - `super_admin`: `app(CurrentTenant::class)->bypass(...)` to find an existing user with that email.
     - Otherwise insert one: `$user = new User; $user->forceFill(['organisation_id' => null, 'is_super_admin' => true, 'status' => UserStatus::Active, 'locale' => Locale::default(), 'name' => Str::headline(Str::before($email, '@')), 'email' => $email, 'password' => $hash])->save();`.
     - The flag must be set at insert (`BelongsToOrganisation`'s creating hook, see Task 5).
     - The `hashed` cast keeps an already-hashed value (it checks `Hash::isHashed()`; verify in `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Casts/` or `HasAttributes::castAttributeAsHashedString`).
     - Then `app(SetSuperAdmin::class)->handle($user, true)`.
6. **Write `DB_*` last**, through `EnvFile`:
   - pgsql: `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
   - sqlite: `DB_CONNECTION=sqlite` only, so the default path stays relative.
7. **`.env.testing`:** copy `.env` to `.env.testing` and set `APP_ENV=testing`. For pgsql set `DB_DATABASE=<name>_test`; for sqlite set `DB_DATABASE=:memory:`.
8. Return `StepResult::done(__('Database ready: :name', ['name' => $answers->database === 'pgsql' ? $name : 'SQLite']))`.

`isComplete()`:
- `.env`'s `DB_CONNECTION` equals the answer.
- `.env.testing` exists.
- On the `setup` connection, configured as above without creating anything, `migrations` and `roles` tables exist (`Schema::connection('setup')->hasTable()`).
- **And** for `demo`, both demo organisations exist; for `super_admin`, the user with that email is a super admin.
- Catch connection exceptions and return `false`.

The `DemoSeeder` and `SetSuperAdmin` run through the default connection, which is now `setup`. `CentralContext` keeps the Artisan calls central. Record and adapt if a seeder or action reads another connection.

Lang keys (nl):
- `Remove database/schema/pgsql-schema.* first: setup runs plain migrations (§7.3).`: "Verwijder eerst database/schema/pgsql-schema.*: setup draait gewone migraties (§7.3)."
- `Database ready: :name`: "Database klaar: :name"
- `Database`: label (exists?)

- [ ] **Step 4: Run them to see them pass**

Run: `php artisan test --compact tests/Feature/Setup/Steps/DatabaseStepTest.php`, then `composer test:pgsql -- --filter=DatabaseStep`. (If the script does not forward arguments, run the pgsql env line from `composer.json` with `--filter=DatabaseStep`.)
Expected: PASS on both. The pgsql-only test is skipped on SQLite.

- [ ] **Step 5: Gate and commit**

```bash
git add app/Setup/Steps/DatabaseStep.php tests/Feature/Setup/Steps/DatabaseStepTest.php lang
git commit -m "feat: add the database setup step with an atomic switch to postgresql or sqlite

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Steps 5 and 7: frontend and Claude (§5.3.3, DEC-22, DEC-25)

**Files:**
- Create: `app/Setup/Steps/FrontendStep.php`, `app/Setup/Steps/ClaudeStep.php`
- Test: `tests/Feature/Setup/Steps/FrontendStepTest.php`, `tests/Feature/Setup/Steps/ClaudeStepTest.php`

**Interfaces:**
- Consumes: `Toolchain`, `Answers` (`playwright`, `claude`), `Detection` (`herd`, `claude`).
- Produces: steps `frontend` (`isLong()` true) and `claude` (`isLong()` true).
  - `FrontendStep::nodeSatisfies(string $version): bool` (`^20.19 || >=22.12`).
  - `ClaudeStep::herdMcpPhar(): ?string` (instance method): `<toolchain herd bin dir>/herd-mcp.phar` when it exists, else `/Applications/Herd.app/Contents/Resources/herd-mcp.phar` (only without an injected toolchain), else null.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Setup/Steps/FrontendStepTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.3 */

use App\Setup\Answers;
use App\Setup\Steps\FrontendStep;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    $home = storage_path('framework/testing/setup/home-'.Str::uuid());
    $bin = $home.'/Library/Application Support/Herd/config/nvm/versions/node/v22.23.2/bin';
    File::ensureDirectoryExists($bin);

    foreach (['node', 'npm', 'npx'] as $binary) {
        File::put("{$bin}/{$binary}", "#!/bin/sh\n");
        chmod("{$bin}/{$binary}", 0755);
    }

    $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($home));
    app()->instance(Toolchain::class, new Toolchain($home));
});

it('builds the assets when the manifest is missing (§5.3.3 step 5)', function (): void {
    $context = setupSandbox();
    Process::fake([
        "*node' '-v'*" => Process::result("v22.23.2\n"),
        "*npm' 'ci'*" => Process::result('added 120 packages'),
        "*npm' 'run' 'build'*" => Process::result('built in 1s'),
    ]);

    expect(new FrontendStep->run($context)->status)->toBe('done');

    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'npm run build') || str_contains(implode(' ', (array) $process->command), 'run build'));
});

it('skips the build when the manifest is current (§5.3.3 step 5)', function (): void {
    $context = setupSandbox();
    File::ensureDirectoryExists($context->path('public/build'));
    File::put($context->path('public/build/manifest.json'), '{}');
    Process::fake(["*node' '-v'*" => Process::result("v22.23.2\n")]);

    expect(new FrontendStep->isComplete($context))->toBeTrue();
});

it('hands the user a command when Node is too old or the build fails (§5.3.3 step 5)', function (string $node, int $buildExit): void {
    $context = setupSandbox();
    Process::fake([
        "*node' '-v'*" => Process::result($node),
        "*npm' 'ci'*" => Process::result('', exitCode: $buildExit),
        "*npm' 'run' 'build'*" => Process::result('', exitCode: $buildExit),
    ]);

    $result = new FrontendStep->run($context);

    expect($result->isManual())->toBeTrue()->and($result->command)->toBe('npm ci && npm run build');
})->with([
    'node 18' => ["v18.20.0\n", 0],
    'build fails' => ["v22.23.2\n", 1],
]);

it('installs Chromium when Playwright was chosen (§5.3.3 step 5)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['playwright' => true], $context->detection, $context->folderName());
    Process::fake([
        "*node' '-v'*" => Process::result("v22.23.2\n"),
        "*npm'*" => Process::result(''),
        "*npx' 'playwright' 'install' 'chromium'*" => Process::result(''),
    ]);

    new FrontendStep->run($context);

    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'playwright install chromium'));
});

it('accepts exactly the supported Node versions', function (string $version, bool $ok): void {
    expect(FrontendStep::nodeSatisfies($version))->toBe($ok);
})->with([
    ['v20.19.0', true], ['v20.18.9', false], ['v21.7.0', false], ['v22.12.0', true], ['v22.11.0', false], ['v24.1.0', true],
]);
```

`tests/Feature/Setup/Steps/ClaudeStepTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.3, §9 (DEC-25) */

use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\Steps\ClaudeStep;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    // Stubs for herd, Herd's MCP phar and claude: the step must not look at /Applications or the real HOME.
    $this->home = storage_path('framework/testing/setup/home-'.Str::uuid());

    foreach (['Library/Application Support/Herd/bin/herd', 'Library/Application Support/Herd/bin/herd-mcp.phar', '.local/bin/claude'] as $binary) {
        File::ensureDirectoryExists(dirname($this->home.'/'.$binary));
        File::put($this->home.'/'.$binary, "#!/bin/sh\n");
        chmod($this->home.'/'.$binary, 0755);
    }

    $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->home));
    app()->instance(Toolchain::class, new Toolchain($this->home));
});

it('is skipped when Claude was not chosen', function (): void {
    expect(new ClaudeStep->run(setupSandbox())->status)->toBe('skipped');
});

it('installs Boost, registers the Herd MCP server as herd and adds boost:update once (§5.3.3 step 7, DEC-25)', function (): void {
    $detection = new Detection(herd: true, herdPro: false, herdMail: false, herdSmtpPort: null, postgres: false, fluxPro: false, claude: true, host: 'my-app.test', scheme: 'http');
    $context = setupSandbox($detection);
    $context->answers = Answers::fromArray(['claude' => true], $detection, $context->folderName());
    new EnvFile($context->path('.env'))->set(['APP_URL' => 'http://my-app.test']);
    File::copy(base_path('composer.json'), $context->path('composer.json'));
    File::put($context->path('.mcp.json'), json_encode(['mcpServers' => ['laravel-boost' => ['command' => 'php', 'args' => ['artisan', 'boost:mcp']]]], JSON_THROW_ON_ERROR));

    Process::fake([
        "*'artisan' 'boost:install'*" => Process::result('Boost installed'),
        "*claude' 'plugin'*" => Process::result('installed'),
        "*composer' 'require' '--dev' 'laravel/boost'*" => Process::result(''),
    ]);

    expect(new ClaudeStep->run($context)->status)->toBe('done');

    /** @var array{mcpServers: array<string, array{command: string, args: list<string>, env?: array<string, string>}>} $mcp */
    $mcp = json_decode((string) file_get_contents($context->path('.mcp.json')), true);
    /** @var array{scripts: array<string, list<string>>} $composer */
    $composer = json_decode((string) file_get_contents($context->path('composer.json')), true);

    expect($mcp['mcpServers'])->toHaveKeys(['laravel-boost', 'herd'])
        ->and($mcp['mcpServers']['herd']['env']['SITE_PATH'] ?? null)->toBe($context->basePath)
        ->and($mcp['mcpServers']['herd']['args'][0] ?? null)->toBe($this->home.'/Library/Application Support/Herd/bin/herd-mcp.phar')
        ->and(array_filter($composer['scripts']['post-update-cmd'], fn (string $line): bool => str_contains($line, 'boost:update')))->toHaveCount(1);

    new ClaudeStep->run($context);
    $composer = json_decode((string) file_get_contents($context->path('composer.json')), true);

    expect(array_filter($composer['scripts']['post-update-cmd'], fn (string $line): bool => str_contains($line, 'boost:update')))->toHaveCount(1);
});

it('hands over the Boost command when the address is not a .test site (§5.3.3 step 7)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['claude' => true], $context->detection, $context->folderName());
    Process::fake();

    $result = new ClaudeStep->run($context);

    expect($result->isManual())->toBeTrue()->and($result->command)->toBe('php artisan boost:install');
});

it('restores the Boost constraint the installer lowered, also when Claude was not chosen (DEC-22 rule 5, §5.2)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['claude' => false], $context->detection, $context->folderName());
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
    $composer['require-dev']['laravel/boost'] = '^2.2';
    File::put($context->path('composer.json'), json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    Process::fake(['*' => Process::result('')]);

    expect(new ClaudeStep->run($context)->status)->toBe('skipped');

    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'require --dev laravel/boost:^2.10'));
});
```

The last test's `'*' => Process::result('')` fakes every command. That is fine there, because the assertion names the one that matters.

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test --compact tests/Feature/Setup/Steps/FrontendStepTest.php tests/Feature/Setup/Steps/ClaudeStepTest.php`
Expected: FAIL (steps missing).

- [ ] **Step 3: Implement**

`FrontendStep`:
- `key()` is `frontend`; the label is `__('Frontend')` (nl "Frontend"); `isLong()` is true.
- **`run()`:**
  1. `node`: `$toolchain->which('node')`. When it is missing, or `nodeSatisfies(trim(run([node, '-v'])->output()))` is false, return `manual('npm ci && npm run build', __('Node ^20.19 or >=22.12 is needed to build the assets.'))`.
  2. When `getenv('LARAVEL_INSTALLER_NO_NODE')` is set, return `skipped(__('Skipped: the installer was asked not to use Node.'))`.
  3. When the manifest is missing or stale, run `[npm, 'ci']` and then `[npm, 'run', 'build']` with `Process::path($context->basePath)->env($toolchain->environment())->timeout(900)`. Stale means `public/build/manifest.json` is older than the newest file under `resources/css` or `resources/js`, or than `package-lock.json`. A failed exit code returns the same `manual()` with the output's last line as the reason (the build output prints no secrets).
  4. When `answers->playwright`, run `[npx, 'playwright', 'install', 'chromium']` (timeout 900). A failure here returns `manual('npx playwright install chromium', …)`, but only after the build succeeded.
- **`isComplete()`:** the manifest is current **and** (Playwright not chosen, or `node_modules/.bin/playwright` exists).
- **`nodeSatisfies()`:**
  ```php
  $v = ltrim(trim($version), 'v');
  return (version_compare($v, '20.19.0', '>=') && version_compare($v, '21.0.0', '<')) || version_compare($v, '22.12.0', '>=');
  ```

`ClaudeStep`:
- `key()` is `claude`; the label is `__('Claude Code')`; `isLong()` is true.
- **`run()`:**
  0. **The Boost constraint, unconditionally** (spec §5.2: `skv:install` restores it whatever the answers): when `require-dev.laravel/boost` is not `^2.10` and `version_compare` of its numeric part is below `2.10`, run `[composer, 'require', '--dev', 'laravel/boost:^2.10', '--no-interaction']` (timeout 600). `$toolchain->which('composer')` falls back to `'composer'`. Add the single `boost:update` line (step 7 below) here too when the project's `composer.json` lists `laravel/boost`. Both rules are about the installer's `--boost` edits, not about Claude.
  1. When `! answers->claude`, return `skipped(__('Not chosen'))`.
  2. When the `.env` `APP_URL` host does not end in `.test`, return `manual('php artisan boost:install', __('Boost needs a .test address; run it after the Herd step.'))`.
  3. Run `[PHP_BINARY, 'artisan', 'boost:install', '--no-interaction', '--guidelines', '--skills', '--mcp']` in the base path (timeout 300).
  4. **The Herd MCP server:** when Herd is detected and `herdMcpPhar()` is not null, read `.mcp.json` (create `{"mcpServers":{}}` when missing). Set `mcpServers.herd = ['command' => 'php', 'args' => [$phar], 'env' => ['SITE_PATH' => $context->basePath]]`. Write it back pretty-printed, keeping the other servers. The name **must** be `herd` (DEC-25 (6)).
  5. **The plugin:** when `$toolchain->which('claude')` is not null, run `[claude, 'plugin', 'install', 'superpowers', '--scope', 'project']` with `cwd` = base path. Before coding this, check `claude plugin install --help` for the exact form (the marketplace-qualified name may be needed, as `.claude/settings.json` declares it; read that file's `enabledPlugins`). A failure returns `manual(<the exact command>, …)`, but only after steps 3–4 have run.
  6. **`.claude/settings.local.json`:** create it as `{}\n` when it is missing. It is gitignored and generated locally by setup (§9).
  7. **`post-update-cmd`:** decode the base path's `composer.json`. When no `post-update-cmd` line contains `boost:update`, append `@php artisan boost:update --ansi`, then write it back with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` and a trailing newline. Composer's 4-space indent equals PHP's pretty print.
  (The Boost constraint is step 0 above.)
- **`isComplete()`:**
  - `require-dev.laravel/boost` is absent or not below `^2.10`, **and**
  - either Claude was not chosen, or `AGENTS.md` exists and `.mcp.json` has `laravel-boost` (plus `herd` when Herd was detected) and `post-update-cmd` has exactly one `boost:update`.
- **`herdMcpPhar()`** is an instance method:
  - first `dirname((string) $this->toolchain->herd()).'/herd-mcp.phar'` when that file exists;
  - otherwise `/Applications/Herd.app/Contents/Resources/herd-mcp.phar`, but only when no toolchain was injected and that file exists;
  - else null. The constructor takes `?Toolchain $toolchain = null`, like every step.

Lang keys (nl):
- `Frontend`: "Frontend"
- `Node ^20.19 or >=22.12 is needed to build the assets.`: "Voor het bouwen van de assets is Node ^20.19 of >=22.12 nodig."
- `Skipped: the installer was asked not to use Node.`: "Overgeslagen: de installer mocht Node niet gebruiken."
- `Claude Code`
- `Not chosen`: "Niet gekozen"
- `Boost needs a .test address; run it after the Herd step.`: "Boost heeft een .test-adres nodig; draai dit na de Herd-stap."

- [ ] **Step 4: Run them to see them pass**

Run: `php artisan test --compact tests/Feature/Setup/Steps`
Expected: PASS.

- [ ] **Step 5: Gate and commit**

```bash
git add app/Setup/Steps tests/Feature/Setup/Steps lang
git commit -m "feat: add the frontend and claude setup steps with the herd mcp server named herd

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Steps 9–11: marker, Herd site, summary; the HTTPS consequence (§5.3.3, §6.8, RL-7)

**Files:**
- Create: `app/Setup/Steps/MarkerStep.php`, `app/Setup/Steps/HerdSiteStep.php`, `app/Setup/Steps/SummaryStep.php`, `app/Setup/Summary.php`
- Modify: `database/seeders/DemoSeeder.php` (`accounts(?string $apex = null)`)
- Test: `tests/Feature/Setup/Steps/MarkerStepTest.php`, `HerdSiteStepTest.php`, `SummaryStepTest.php`

**Interfaces:**
- Consumes: `Marker`, `EnvFile`, `Toolchain`, `DemoSeeder::accounts()`, `Hosts`.
- Produces:
  - Steps `marker`, `herd-site` and `summary`.
  - `App\Setup\Summary::build(SetupContext): array{urls: list<string>, logins: list<array{email: string, password: ?string, url: string}>, manual: list<array{step: string, command: string, reason: string}>, notes: list<string>}`.
  - `HerdSiteStep::SECURED = 'secured'` is the `StepResult` message prefix when the site was secured. `skv:install` uses it for RL-7.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Setup/Steps/HerdSiteStepTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.3, §6.8, §13 */

use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\Steps\HerdSiteStep;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    $this->detection = new Detection(herd: true, herdPro: false, herdMail: false, herdSmtpPort: null, postgres: false, fluxPro: false, claude: false, host: 'my-app.test', scheme: 'http');
    // Stubs for herd and php in a sandbox home: the step never sees the real Herd.
    $this->home = storage_path('framework/testing/setup/home-'.Str::uuid());

    foreach (['herd', 'php'] as $binary) {
        $path = $this->home.'/Library/Application Support/Herd/bin/'.$binary;
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "#!/bin/sh\n");
        chmod($path, 0755);
    }

    $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->home));
    app()->instance(Toolchain::class, new Toolchain($this->home));
});

it('is skipped without Herd', function (): void {
    expect(new HerdSiteStep->run(setupSandbox())->status)->toBe('skipped');
});

it('links an unparked site, secures it and rewrites APP_URL to https after verifying (§5.3.3 step 10)', function (): void {
    $context = setupSandbox($this->detection);
    $context->answers = Answers::fromArray(['https' => true], $this->detection, $context->folderName());
    $name = $context->folderName();

    Process::fake([
        "*herd' 'paths'*" => Process::result(json_encode(['/somewhere/else/'], JSON_THROW_ON_ERROR)),
        "*herd' 'links'*" => Process::result(''),
        "*herd' 'link'*" => Process::result('linked'),
        "*herd' 'secured'*" => Process::result("| Site | Expires |\n| {$name}.test | 2027-01-01 00:00:00 GMT |\n"),
        "*herd' 'secure'*" => Process::result('Secured', exitCode: 0),
        "*herd' 'isolated'*" => Process::result(''),
        "*php' '-r'*" => Process::result('8.4.13'),
    ]);

    $result = new HerdSiteStep->run($context);

    // APP_URL uses the detected host; herd commands use the folder name (in real installs they agree).
    expect($result->message)->toStartWith(HerdSiteStep::SECURED)
        ->and(new EnvFile($context->path('.env'))->get('APP_URL'))->toBe('https://my-app.test');
    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), "link {$name}") && $process->path === $context->basePath);
});

it('treats a parked directory listed with a trailing slash as parked (§5.3.3 step 10)', function (): void {
    $context = setupSandbox($this->detection);
    $context->answers = Answers::fromArray(['https' => false, 'modules' => []], $this->detection, $context->folderName());

    Process::fake([
        "*herd' 'paths'*" => Process::result(json_encode([dirname($context->basePath).'/'], JSON_THROW_ON_ERROR)),
        "*herd' 'isolated'*" => Process::result(''),
        "*php' '-r'*" => Process::result('8.4.13'),
    ]);

    new HerdSiteStep->run($context);

    Process::assertDidntRun(fn ($process): bool => str_contains(implode(' ', (array) $process->command), ' link '));
});

it('does not trust herd secure\'s exit code (§13)', function (): void {
    $context = setupSandbox($this->detection);
    $context->answers = Answers::fromArray(['https' => true], $this->detection, $context->folderName());

    Process::fake([
        "*herd' 'paths'*" => Process::result(json_encode([dirname($context->basePath)], JSON_THROW_ON_ERROR)),
        // A similar name must not count as secured: rows are matched whole.
        "*herd' 'secured'*" => Process::result("| x{$context->folderName()}.test | 2027-01-01 |\n"),
        "*herd' 'secure'*" => Process::result('Error finding executable PHP', exitCode: 0),
        "*herd' 'isolated'*" => Process::result(''),
        "*php' '-r'*" => Process::result('8.4.13'),
    ]);

    $result = new HerdSiteStep->run($context);

    expect($result->isManual())->toBeTrue()
        ->and($result->command)->toBe('herd secure '.$context->folderName())
        ->and(new EnvFile($context->path('.env'))->get('APP_URL'))->not->toStartWith('https://');
});

it('isolates PHP 8.4 only when the site would run an older PHP (§5.3.3 step 10)', function (string $global, ?string $isolatedTo, bool $isolates): void {
    $context = setupSandbox($this->detection);
    $context->answers = Answers::fromArray(['https' => false, 'modules' => []], $this->detection, $context->folderName());
    $name = $context->folderName();

    Process::fake([
        "*herd' 'paths'*" => Process::result(json_encode([dirname($context->basePath)], JSON_THROW_ON_ERROR)),
        "*herd' 'isolated'*" => Process::result($isolatedTo === null ? '' : "| Site | PHP Version |\n| {$name}.test | {$isolatedTo} |\n"),
        "*herd' 'isolate'*" => Process::result('isolated'),
        "*php' '-r'*" => Process::result($global),
    ]);

    new HerdSiteStep->run($context);

    $isolates
        ? Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'isolate 8.4 --site='.$context->folderName()))
        : Process::assertDidntRun(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'isolate 8.4'));
})->with([
    'global 8.3, not isolated' => ['8.3.20', null, true],
    'global 8.4, not isolated' => ['8.4.13', null, false],
    'global 8.4, isolated to 8.3' => ['8.4.13', '8.3', true],
    'global 8.3, isolated to 8.4' => ['8.3.20', '8.4', false],
]);
```

`tests/Feature/Setup/Steps/SummaryStepTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.3 */

use App\Setup\Answers;
use App\Setup\StepResult;
use App\Setup\Summary;
use Database\Seeders\DemoSeeder;

it('lists the addresses, the demo logins with their password and the manual commands (§5.3.3 step 11)', function (): void {
    $context = setupSandbox();
    $context->results['frontend'] = StepResult::manual('npm ci && npm run build', 'Node is missing');

    $summary = Summary::build($context);

    expect($summary['urls'])->toContain('http://'.$context->detection->host)
        ->and(array_column($summary['logins'], 'email'))->toBe(array_column(DemoSeeder::accounts(), 'email'))
        ->and(array_unique(array_column($summary['logins'], 'password')))->toBe([DemoSeeder::PASSWORD])
        ->and($summary['manual'])->toBe([['step' => 'frontend', 'command' => 'npm ci && npm run build', 'reason' => 'Node is missing']]);
});

it('names the super admin login without a password (§5.3.3 step 11)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray([
        'start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com', 'super_admin_password' => 'correct-horse-battery',
    ], $context->detection, $context->folderName());

    $summary = Summary::build($context);

    expect($summary['logins'])->toBe([['email' => 'owner@example.com', 'password' => null, 'url' => 'http://admin.'.$context->detection->host]])
        ->and(json_encode($summary, JSON_THROW_ON_ERROR))->not->toContain('correct-horse-battery');
});
```

`DemoSeeder::accounts()` reads `Hosts::apex()` from config, while the summary uses the sandbox's detected host. In `Summary::build()`, compute the demo emails from `$context->detection->host`, not from config: give `DemoSeeder::accounts(?string $apex = null)` an optional apex argument. In the test, compare with `DemoSeeder::accounts($context->detection->host)`.

`tests/Feature/Setup/Steps/MarkerStepTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.3 */

use App\Setup\Marker;
use App\Setup\Steps\MarkerStep;
use Illuminate\Support\Facades\File;

it('writes the marker and removes the setup token and state files (§5.3.3 step 9)', function (): void {
    $context = setupSandbox();
    File::put($context->path('storage/app/private/skv-setup-token'), 'token');
    File::put($context->path('storage/app/private/skv-setup.json'), '{}');

    new MarkerStep->run($context);

    expect(Marker::exists($context))->toBeTrue()
        ->and(is_file($context->path('storage/app/private/skv-setup-token')))->toBeFalse()
        ->and(is_file($context->path('storage/app/private/skv-setup.json')))->toBeFalse();
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test --compact tests/Feature/Setup/Steps`
Expected: FAIL (classes missing).

- [ ] **Step 3: Implement**

`Summary::build()`:
- **urls:**
  - the `.env` `APP_URL`, falling back to `scheme://host`
  - the admin host (`admin.{host}`, with the configured `TENANCY_ADMIN_SUBDOMAIN` from the sandbox `.env`, default `admin`)
  - and, for `demo`, `http(s)://demo1.{host}` and `http(s)://demo2.{host}`
- **logins:**
  - `demo`: every `DemoSeeder::accounts($host)` row with `password` `DemoSeeder::PASSWORD`, and `url` the row's host with the scheme
  - `super_admin`: one row with the email, `password` null and the admin URL
- **manual:** every `$context->results` entry with `isManual()`.
- **notes:** every non-empty `done()` message, such as the mail note and the RL-7 passkeys note.

`MarkerStep`:
- `key()` is `marker`; the label is `__('Finish')` (nl "Afronden").
- `run()` calls `Marker::write($context, Summary::build($context))` and deletes `storage/app/private/skv-setup-token`, `storage/app/private/skv-setup.json` and the `storage/app/private/skv-setup/` directory when present.
- `isComplete()` is `Marker::exists($context)`.

`HerdSiteStep`:
- `key()` is `herd-site`; the label is `__('Herd site')` (nl "Herd-site").
- `run()`:
  - **Without Herd:** `skipped(__('Herd is not installed'))`.
  - **Every herd call** runs with `Process::path($context->basePath)->env($toolchain->environment())`, because `herd link` and `herd isolate` act on the current directory. Herd "present" comes from `$context->detection->herd`. When the toolchain has no herd binary, return `manual('herd link', …)`.
  - **(a) Link:**
    - The site counts as parked when `in_array(rtrim(dirname(realpath($context->basePath) ?: $context->basePath), '/'), array_map(fn (string $p): string => rtrim($p, '/'), (array) json_decode($paths, true)), true)`.
      - `herd paths` prints a JSON list whose entries end in `/` (verified 2026-10-12).
      - Run `herd paths` once and record its output.
    - It counts as linked when `herd links` lists the folder name. Check its output format; it lists names and paths, no env.
    - When neither is true, run `[herd, 'link', $name]`, and mention the link in the result message, so Task 17's clean-up knows to `herd unlink`.
  - **Tables:** `herd secured` and `herd isolated` print ASCII tables. Match a row with `preg_match('/^\|\s*'.preg_quote("{$name}.{$tld}", '/').'\s*\|/mi', $output)`, never `str_contains` (which would also match `x{$name}.test`).
  - **(b) Secure (`answers->https`):**
    - When `herd secured` already lists the site, skip `herd secure`.
    - Otherwise run `[herd, 'secure', $name]`.
    - Then **verify**: `herd secured` must list the row `"{$name}.{tld}"`, with the tld taken from the detected host's suffix. Never rely on the exit code.
    - On success, rewrite `APP_URL` to `https://{host}` with `EnvFile`, and return `done(self::SECURED.': '.__('Site secured with HTTPS'))`.
    - On failure, return `manual("herd secure {$name}", __('Herd did not confirm the certificate.'))`.
  - **(c) Isolate:**
    - **The effective PHP version:**
      - When the `herd isolated` table has a row `{$name}.{$tld}`, take the PHP Version cell (split the row on `|` and trim). That is the PHP that serves the site.
      - Otherwise use Herd's global PHP: `[php, '-r', 'echo PHP_VERSION;']`, with `php` from the toolchain PATH, run in `sys_get_temp_dir()`.
    - When the effective version is below 8.4, whether the site is isolated or not, run `[herd, 'isolate', '8.4', '--site='.$name]`.
    - Verify that `herd isolated` lists the site with 8.4, or that the site is unlisted and global PHP is at least 8.4. Task 17 checks this for real (FU-isolate).
    - When the verification fails, return `manual("herd isolate 8.4 --site={$name}", …)`.
  - Without HTTPS, return `done(__('Site served by Herd'))`.
- **After securing:** rewrite the marker's summary, `Marker::write($context, Summary::build($context))`, so the URLs say https (§5.3.4: "the summary is read from the marker").
- **`isComplete()`:** parked or linked, **and** (no HTTPS, or `herd secured` lists the site). Wrap these in try/catch and return `false` on any process failure.

`SummaryStep`:
- `key()` is `summary`; the label is `__('Summary')` (nl "Samenvatting").
- `run()` returns `done()`; `skv:install` prints `Summary::build()`.
- `isComplete()` is always `false`, so it always runs.

Lang keys (nl):
- `Finish`: "Afronden"
- `Herd site`: "Herd-site"
- `Herd is not installed`: "Herd is niet geïnstalleerd"
- `Site secured with HTTPS`: "Site beveiligd met HTTPS"
- `Herd did not confirm the certificate.`: "Herd heeft het certificaat niet bevestigd."
- `Site served by Herd`: "Site wordt door Herd geserveerd"
- `Summary`: "Samenvatting"

- [ ] **Step 4: Run them to see them pass**

Run: `php artisan test --compact tests/Feature/Setup`
Expected: PASS.

- [ ] **Step 5: Gate and commit**

```bash
git add app/Setup database/seeders/DemoSeeder.php tests/Feature/Setup lang
git commit -m "feat: add the marker, herd site and summary setup steps

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: `skv:doctor` and step 8 (§5.4, §7.4, DEC-26, DEC-28, RL-11)

**Files:**
- Create: `app/Enums/CheckStatus.php` (the Laravel arch preset keeps enums in `App\Enums`)
- Create: `app/Setup/Doctor/{Check,CheckResult,DoctorContext,Doctor,DryRunSync}.php`
- Create: `app/Setup/Doctor/Checks/*.php` (one class per row of the table below)
- Create: `app/Setup/Steps/DoctorStep.php`, `app/Console/Commands/DoctorCommand.php`
- Modify: `composer.json` (`"doctor": ["@php artisan skv:doctor --ansi"]`)
- Test: `tests/Feature/Setup/Doctor/DoctorTest.php`, `tests/Feature/Setup/Doctor/ChecksTest.php`, `tests/Feature/Console/DoctorCommandTest.php`

**Interfaces:**
- Consumes: `Toolchain`, `Detector`, `Marker`, `EnvFile`, `SyncPermissions`/`SyncResult` (FU2-13).
- Produces (exact):

```php
namespace App\Enums;
enum CheckStatus: string { case Pass = 'pass'; case Warn = 'warn'; case Fail = 'fail'; case Skipped = 'skipped'; }

namespace App\Setup\Doctor;

interface Check
{
    public function key(): string;
    public function label(): string;
    public function run(DoctorContext $context): CheckResult;
    /** A safe repair (RL-11), or null when the check has none; returns the re-run result. */
    public function fix(DoctorContext $context): ?CheckResult;
}

final readonly class CheckResult
{
    public static function pass(string $message): self;
    public static function warn(string $message, ?string $fix = null): self;
    public static function fail(string $message, ?string $fix = null): self;
    public static function skipped(string $reason): self;
    // public readonly CheckStatus $status, string $message, ?string $fix
}

final class DoctorContext
{
    public function __construct(public readonly string $basePath, public readonly Toolchain $toolchain, public readonly Detection $detection, public readonly ?array $marker) {}
    public function env(): EnvFile;                  // $basePath/.env
    public function answer(string $key): mixed;      // from the marker's answers, null without a marker
    public function sync(): SyncResult;              // DryRunSync, cached until forgetSync()
    public function forgetSync(): void;              // drops the cached DryRunSync (and the cached PDO probe)
}

final class Doctor
{
    /** @return list<Check> in report order */
    public function checks(): array;
    /** @return array<string, CheckResult> keyed by check key */
    public function run(DoctorContext $context, bool $fix = false): array;
    /** @param array<string, CheckResult> $results */
    public static function isGreen(array $results): bool; // no Fail
}
```

`DryRunSync::run(): SyncResult` runs `SyncPermissions::handle()` inside `DB::beginTransaction()` and rolls back, so the doctor sees drift without repairing it. The `permissions` check's fix runs the real `handle()`.

Cached state:
- `Doctor::run()` calls `$context->forgetSync()` at the start, and again after every `fix()` that returned a result, before the next check runs.
- Every `fix()` calls `forgetSync()` before re-running its own `run()`.
- Without that, `super-admin-badges` would read the pre-repair `SyncResult` and stay `Fail` after `permissions` repaired it.

**Checks** (keys and labels exactly as listed; labels are `__()` keys). Rules marked *warn-only* never fail. Each `fix` column entry is the command printed with a non-pass result; *(fixable)* means `fix()` performs it.

| Key | Applies when | Pass | Otherwise | Fix |
|---|---|---|---|---|
| `php` | always | `PHP_VERSION` ≥ 8.4 | fail | `herd isolate 8.4` |
| `gd` | always | `extension_loaded('gd')` | fail | "Install the gd extension" |
| `app-key` | always | `.env` `APP_KEY` non-empty | fail | `php artisan key:generate` |
| `herd` | always | Herd present | warn (*warn-only*) | link to herd.laravel.com |
| `herd-pro` | Herd present | Herd Pro detected | warn (*warn-only*) | none |
| `herd-site` | Herd present | parked or linked (Task 10's helper) | fail | `herd link <name>` |
| `herd-secured` | Herd present and marker `https` true | `herd secured` lists the site | fail | `herd secure <name>` |
| `subdomains` | always | `gethostbyname("probe.{apex}")` returns an IP (not the input) | fail | without Herd: the `/etc/hosts` lines for the apex, `admin.` and every organisation slug |
| `apex` | always | `TENANCY_APEX_DOMAIN` empty or equal to the `APP_URL` host | fail | "Set TENANCY_APEX_DOMAIN to the APP_URL host" |
| `database` | always | `DB::connection()->getPdo()` succeeds | fail | "Check DB_* in .env" |
| `migrations` | database ok | no pending migration (`Migrator` repository vs files) | fail | `php artisan migrate` |
| `test-database` | `DB_CONNECTION=pgsql` | `<db>_test` exists | fail | `composer test:pgsql` creates it |
| `schema-tools` | a `database/schema/pgsql-schema.*` exists | `psql` and `pg_dump` resolvable | warn | remove the dump (§7.3) |
| `permissions` | database ok | `sync()->changed()` false | fail *(fixable)* | `php artisan permissions:sync` |
| `organisation-admin-role` | database ok | the template role exists (`Role::templateId()` does not throw) | fail *(fixable via permissions)* | `php artisan permissions:sync` |
| `super-admin` | database ok | ≥ 1 active super admin | fail | `php artisan skv:create-super-admin` |
| `super-admin-badges` | database ok | `sync()->badgesRepaired === 0 && sync()->badgePermissionsStripped === 0` | fail *(fixable)* | `php artisan permissions:sync` |
| `session-driver` | always | `config('session.driver') === 'database'` | fail | "Set SESSION_DRIVER=database" |
| `authenticate-session` | always | the `web` middleware group contains `AuthenticateSession` | fail | none (kit bug) |
| `health-check-key` | always | `HEALTH_CHECK_KEY` non-empty | fail *(fixable: writes a random 40-char key)* | `php artisan skv:doctor --fix` |
| `node` | always | node on the toolchain PATH and `FrontendStep::nodeSatisfies()` | warn | "Install Node ^20.19 or >=22.12" |
| `assets` | always | `public/build/manifest.json` current (Task 9's rule) | fail *(fixable: `npm ci && npm run build`)* | `npm ci && npm run build` |
| `mail` | always | `smtp`: `MAIL_PORT` accepts a TCP connection on `MAIL_HOST` (1 s); `log`: pass with "Mail goes to the log" | fail (smtp) | "Start Herd's mail service" |
| `pg-dump` | backups module on (`Skv::moduleEnabled('backups')`) | `pg_dump` found (PATH or `/Users/Shared/Herd/services/postgresql/*/bin`) | fail | `PG_DUMP_BINARY_PATH=…` |
| `flux-pro-credentials` | Flux Pro installed | `composer config http-basic.composer.fluxui.dev`, run in the base path (it reads the project `auth.json` and falls back to the global one), exits 0 with non-empty output (captured, never printed) | fail | the README one-liner |
| `queue` | always | the default queue connection resolves; for `database`, the `jobs` table exists | fail | `php artisan migrate` |
| `git-hooks` | `.git` exists | `git config --get core.hooksPath` is `.githooks` | warn *(fixable)* | `git config core.hooksPath .githooks` |
| `playwright` | marker `playwright` true | `node_modules/.bin/playwright` exists | warn | `npx playwright install chromium` |
| `claude-files` | marker `claude` true | `CLAUDE.md` contains `@AGENTS.md` and `AGENTS.md` exists | fail | `php artisan boost:install` |
| `mcp` | marker `claude` true | `.mcp.json` has `laravel-boost` (+ `herd` when Herd is present) | fail | `php artisan skv:install --force` |
| `superpowers` | marker `claude` true and `claude` on PATH | `.claude/settings.json` enables superpowers **and** `claude plugin list` lists it | warn (*warn-only*) | `claude plugin install superpowers --scope project` |
| `setup-token` | marker exists | `storage/app/private/skv-setup-token` absent | warn *(fixable: delete)* | `php artisan skv:doctor --fix` |
| `storage-link` | always | `public/storage` exists | warn *(fixable: `php artisan storage:link`)* | `php artisan storage:link` |

The table is the specification for each check. Implement one class per row in `app/Setup/Doctor/Checks/`, named after the key in StudlyCase plus `Check` (`AppKeyCheck`, `HerdSiteCheck`, …). A check that does not apply returns `CheckResult::skipped()` with the reason. Database checks return `skipped(__('The database is not reachable'))` when the `database` check failed. Cache the PDO probe in `DoctorContext`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Setup/Doctor/DoctorTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.4 */

use App\Enums\CheckStatus;
use App\Setup\Detection;
use App\Setup\Doctor\Doctor;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    // A catch-all is fine here: these tests assert statuses, not commands. ChecksTest must NOT copy it,
    // because the first matching handler wins and a catch-all would shadow its specific fakes.
    Process::fake(['*' => Process::result('')]);
    $this->sandbox = setupSandbox();
    $this->context = new DoctorContext($this->sandbox->basePath, new Toolchain($this->sandbox->path('home')), Detection::none(), null);
});

it('runs every check in the documented order (§5.4)', function (): void {
    expect(array_keys(app(Doctor::class)->run($this->context)))->toBe([
        'php', 'gd', 'app-key', 'herd', 'herd-pro', 'herd-site', 'herd-secured', 'subdomains', 'apex', 'database', 'migrations',
        'test-database', 'schema-tools', 'permissions', 'organisation-admin-role', 'super-admin', 'super-admin-badges',
        'session-driver', 'authenticate-session', 'health-check-key', 'node', 'assets', 'mail', 'pg-dump', 'flux-pro-credentials',
        'queue', 'git-hooks', 'playwright', 'claude-files', 'mcp', 'superpowers', 'setup-token', 'storage-link',
    ]);
});

it('is green when nothing fails, whatever warns (§5.4)', function (): void {
    $results = app(Doctor::class)->run($this->context);

    expect(Doctor::isGreen($results))->toBe(collect($results)->every(fn ($result): bool => $result->status !== CheckStatus::Fail));
});

it('skips the checks that do not apply without Herd or a marker (§5.4)', function (): void {
    $results = app(Doctor::class)->run($this->context);

    expect($results['herd']->status)->toBe(CheckStatus::Warn)
        ->and($results['herd-site']->status)->toBe(CheckStatus::Skipped)
        ->and($results['playwright']->status)->toBe(CheckStatus::Skipped)
        ->and($results['setup-token']->status)->toBe(CheckStatus::Skipped);
});

it('reports drift without repairing it, and repairs it with --fix (DEC-26, DEC-28, FU2-13)', function (): void {
    $badge = Spatie\Permission\Models\Role::query()->whereNull('team_id')->where('name', App\Authorization\Role::SuperAdmin->value)->firstOrFail();
    App\Authorization\PermissionsTeam::run(null, fn () => $badge->givePermissionTo(App\Authorization\Permission::UsersView->value));

    $before = app(Doctor::class)->run($this->context);
    $stillDrifting = $badge->fresh()?->permissions()->count();
    $after = app(Doctor::class)->run($this->context, fix: true);

    expect($before['super-admin-badges']->status)->toBe(CheckStatus::Fail)
        ->and($stillDrifting)->toBe(1)
        ->and($after['super-admin-badges']->status)->toBe(CheckStatus::Pass)
        ->and($badge->fresh()?->permissions()->count())->toBe(0);
});
```

`tests/Feature/Setup/Doctor/ChecksTest.php` holds one `it()` per check with at least one pass and one non-pass case. Build the situation in the sandbox `.env`, the toolchain home or the database, and assert status, message and fix. Examples you must include:

```php
it('fails without an APP_KEY and names the command (§5.4)', function (): void {
    new EnvFile($this->sandbox->path('.env'))->set(['APP_KEY' => '']);

    $result = new AppKeyCheck->run($this->context);

    expect($result->status)->toBe(CheckStatus::Fail)->and($result->fix)->toBe('php artisan key:generate');
});

it('writes a health-check key with --fix (§5.4)', function (): void {
    new EnvFile($this->sandbox->path('.env'))->set(['HEALTH_CHECK_KEY' => '']);

    $result = new HealthCheckKeyCheck->fix($this->context);

    expect($result?->status)->toBe(CheckStatus::Pass)
        ->and(strlen((string) new EnvFile($this->sandbox->path('.env'))->get('HEALTH_CHECK_KEY')))->toBe(40);
});

it('prints the /etc/hosts lines when subdomains do not resolve without Herd (§5.4)', function (): void {
    Organisation::factory()->create(['slug' => 'acme']);
    config(['tenancy.apex_domain' => 'unresolvable.invalid']);

    $result = new SubdomainsCheck->run($this->context);

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->fix)->toContain('127.0.0.1 unresolvable.invalid')
        ->and($result->fix)->toContain('127.0.0.1 admin.unresolvable.invalid')
        ->and($result->fix)->toContain('127.0.0.1 acme.unresolvable.invalid');
});

it('never prints the Flux Pro credentials it checks (§5.4)', function (): void {
    Skv::useFluxPro(true);
    Process::fake(["*composer' 'config' 'http-basic.composer.fluxui.dev'*" => Process::result('{"username":"me@example.com","password":"secret-key"}')]);

    $result = new FluxProCredentialsCheck->run($this->context);

    expect($result->status)->toBe(CheckStatus::Pass)
        ->and($result->message)->not->toContain('secret-key')
        ->and($result->message)->not->toContain('me@example.com');
});
```

`.invalid` is reserved (RFC 2606) and never resolves. `Skv::useFluxPro()` is M5's test override; reset it in `afterEach` (`Skv::useFluxPro(null)`).

`tests/Feature/Console/DoctorCommandTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.4 */

use App\Setup\Detection;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake(['*' => Process::result('')]);
    // Never the repository's own .env, marker or .mcp.json: every DoctorCommand test runs on a sandbox.
    $sandbox = setupSandbox();
    app()->instance(DoctorContext::class, new DoctorContext($sandbox->basePath, new Toolchain($sandbox->path('home')), Detection::none(), null));
});

it('prints JSON and exits non-zero when a check fails (§5.4, RL-11)', function (): void {
    config(['session.driver' => 'file']);

    $this->artisan('skv:doctor', ['--json' => true])
        ->expectsOutputToContain('"key": "session-driver"')
        ->expectsOutputToContain('"green": false')
        ->assertFailed();
});

it('lists each check with its status and fix command (§5.4)', function (): void {
    $this->artisan('skv:doctor')->expectsOutputToContain(__('Session driver'))->run();
});

it('runs as a composer script (§7.4)', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['doctor'] ?? null)->toBe(['@php artisan skv:doctor --ansi']);
});
```

`DoctorCommand` resolves `DoctorContext` from the container. Every `DoctorCommand` test binds a sandbox context, as above. The default binding (`base_path()`, the `Detector`, `Marker::read()` of the application context) is used only outside tests.

`DoctorStep` (key `doctor`, label `__('Doctor')`, nl "Controle"):
- Runs `Doctor::run()` on a `DoctorContext` built from the `SetupContext`.
- Returns `done()` when green, else `manual('php artisan skv:doctor', __(':count checks failed', ['count' => $failed]))`.
- Add one test in `ChecksTest` or a `DoctorStepTest` for both paths.

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test --compact tests/Feature/Setup/Doctor tests/Feature/Console/DoctorCommandTest.php`
Expected: FAIL (classes missing).

- [ ] **Step 3: Implement**

The contract classes as in **Interfaces**. `DoctorCommand`:
- **`skv:doctor {--fix} {--json}`.** Without `--json`, print one `$this->components->twoColumnDetail($result->label…, <coloured status>)` line per check, followed by the message and, when not pass, the fix command.
- **With `--json`,** print:
  ```php
  json_encode(['checks' => array_values(array_map(fn ($key, $r) => ['key' => $key, 'status' => $r->status->value, 'message' => $r->message, 'fix' => $r->fix], array_keys($results), $results)), 'green' => Doctor::isGreen($results)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
  ```
- Return `FAILURE` when not green.
- Print `__('All checks pass.')` / `__(':count checks failed.')` at the end in plain mode.

Every label, message and fix text goes through `__()`. Commands and `/etc/hosts` lines inside a message are `:placeholders`, so they are not translated. Add all keys to both JSON files; Dutch uses the informal "je" ("Zet SESSION_DRIVER=database in je .env").

- [ ] **Step 4: Run them to see them pass**

Run: `php artisan test --compact tests/Feature/Setup tests/Feature/Console tests/Feature/LangCoverageTest.php tests/Arch`
Expected: PASS.

- [ ] **Step 5: Gate and commit**

```bash
git add app/Enums/CheckStatus.php app/Setup app/Console/Commands/DoctorCommand.php composer.json tests/Feature/Setup tests/Feature/Console lang
git commit -m "feat: add skv:doctor with fixable checks for drift, hosts, assets and tooling

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: The setup runner, `skv:install` and `composer setup` (§5.3.3, RL-6, RL-7)

**Files:**
- Create: `app/Setup/SetupRunner.php`, `app/Console/Commands/InstallCommand.php`
- Modify: `composer.json` (`setup` script), `app/Providers/AppServiceProvider.php` (bindings)
- Modify: `tests/Feature/Tooling/ComposerScriptsTest.php`
- Test: `tests/Feature/Setup/SetupRunnerTest.php`, `tests/Feature/Console/InstallCommandTest.php`

**Interfaces:**
- Consumes: every step (Tasks 7–11), `Bootstrap`, `Detector`, `Answers`, `Summary`, `Marker`.
- Produces:

```php
namespace App\Setup;

final class SetupRunner
{
    /** @param  list<SetupStep>|null  $steps  null = the eleven steps of §5.3.3 (tests pass their own list) */
    public function __construct(private readonly ?array $steps = null) {}
    /** @return list<SetupStep> the given steps, or the eleven steps of §5.3.3, in order */
    public function steps(): array;
    /** @param (Closure(SetupStep, Closure(): StepResult): StepResult)|null $around wraps each step (the CLI's task() spinner) */
    public function run(SetupContext $context, ?Closure $around = null): SetupContext;
}
```

The step order is `environment`, `database`, `herd-manifest`, `branding`, `frontend`, `git`, `claude`, `doctor`, `marker`, `herd-site`, `summary`. M6b drives the same list one step per request.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Setup/SetupRunnerTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.3 */

use App\Setup\SetupRunner;
use App\Setup\SetupStep;
use App\Setup\SetupContext;
use App\Setup\StepResult;
use App\Setup\Steps\HerdSiteStep;
use App\Setup\Support\EnvFile;

it('runs the eleven steps in spec order (§5.3.3)', function (): void {
    expect(array_map(fn (SetupStep $step): string => $step->key(), app(SetupRunner::class)->steps()))->toBe([
        'environment', 'database', 'herd-manifest', 'branding', 'frontend', 'git', 'claude', 'doctor', 'marker', 'herd-site', 'summary',
    ]);
});

it('skips complete steps unless forced, and keeps going after a manual step (RL-6)', function () use ($fakeStep): void {
    $context = setupSandbox();
    $ran = new ArrayObject;
    $runner = new SetupRunner([$fakeStep('a', $ran, complete: true), $fakeStep('b', $ran, StepResult::manual('do-it', 'why')), $fakeStep('c', $ran)]);

    $runner->run($context);

    expect($ran->getArrayCopy())->toBe(['b', 'c'])
        ->and($context->results['a']->status)->toBe('skipped')
        ->and($context->results['b']->isManual())->toBeTrue();
});

it('turns passkeys off when HTTPS was wanted but the site could not be secured (§6.8, RL-7)', function (): void {
    $context = setupSandbox();
    $context->results['herd-site'] = StepResult::manual('herd secure x', 'not confirmed');

    app(SetupRunner::class)->applyHttpsConsequence($context);

    expect(new EnvFile($context->path('.env'))->get('MODULE_PASSKEYS'))->toBe('false')
        ->and($context->answers->moduleEnabled('passkeys'))->toBeFalse()
        ->and(implode(' ', App\Setup\Summary::build($context)['notes']))->toContain('MODULE_PASSKEYS=true');
});
```

The fake step is a file-local closure at the top of the file, not a global helper. It records every key it runs in the `ArrayObject`:

```php
/** @param  ArrayObject<int, string>  $ran */
$fakeStep = fn (string $key, ArrayObject $ran, ?StepResult $result = null, bool $complete = false): SetupStep => new class($key, $ran, $result ?? StepResult::done(), $complete) implements SetupStep
{
    /** @param  ArrayObject<int, string>  $ran */
    public function __construct(private string $stepKey, private ArrayObject $ran, private StepResult $result, private bool $complete) {}

    public function key(): string { return $this->stepKey; }
    public function label(): string { return $this->stepKey; }
    public function isComplete(SetupContext $context): bool { return $this->complete; }
    public function isLong(): bool { return false; }
    public function run(SetupContext $context): StepResult { $this->ran->append($this->stepKey); return $this->result; }
};
```

The test closures use it via `use ($fakeStep)`. `SetupRunner::steps()` returns `$this->steps ?? [app(EnvironmentStep::class), app(DatabaseStep::class), …]` in §5.3.3 order. `app(SetupRunner::class)` passes the null default.

`applyHttpsConsequence(SetupContext)` is public on `SetupRunner`. `run()` calls it right after the `herd-site` step, **only when that step actually ran**. A step skipped as complete means the site is secured (its `isComplete()` checks `herd secured`), so a resumed install never turns passkeys off.
- When `answers->moduleEnabled('passkeys')` is true and `herd-site`'s result message does not start with `HerdSiteStep::SECURED`, it writes `MODULE_PASSKEYS=false`.
- It sets `$context->answers = $context->answers->withModules(<without passkeys>)->withHttps(false)`.
- It records the note `__('Passkeys are off because the site has no HTTPS. To turn them on: herd secure :name, set MODULE_PASSKEYS=true, then php artisan skv:doctor --fix.', ['name' => $context->folderName()])`. Store notes on `SetupContext` (`/** @var list<string> */ public array $notes = []`), and have `Summary::build()` merge them into `notes`.
- When it changed anything and `Marker::exists($context)`, it finishes with `Marker::write($context, Summary::build($context))`. The marker's answers (https false, passkeys off) and notes then match `.env`, and the doctor's `herd-secured` row no longer applies. Without that rewrite, `skv:doctor` would report a fail after a degraded but working setup (§12 criterion 2).

Extend the RL-7 test:
- First `Marker::write($context, [])`, then call `applyHttpsConsequence`.
- Expect `Marker::read($context)['answers']['https'] ?? null` to be `false`, and `implode(' ', Marker::read($context)['summary']['notes'] ?? [])` to contain `MODULE_PASSKEYS=true`.

Add a test, "keeps passkeys on when the Herd step was already complete (RL-7)": a runner whose `herd-site` fake step is complete leaves `MODULE_PASSKEYS` as it was.

`tests/Feature/Console/InstallCommandTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.3, §5.3.2 */

use App\Setup\Detector;
use App\Setup\Marker;
use App\Setup\SetupContext;
use App\Setup\Support\Bootstrap;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake(['*' => Process::result('')]);
    $this->sandbox = setupSandbox();
    app()->instance(SetupContext::class, $this->sandbox);
    app()->instance(Bootstrap::class, new Bootstrap($this->sandbox->basePath, new Toolchain($this->sandbox->path('home'))));
    app()->instance(Detector::class, new Detector(new Toolchain($this->sandbox->path('home'))));
    $this->previousConnection = config('database.default');
});

// afterEach, not beforeApplicationDestroyed: RefreshDatabase reads database.default again at teardown.
afterEach(function (): void {
    DB::purge('setup');
    config(['database.default' => $this->previousConnection]);
});

it('installs non-interactively from the e2e answers and writes the marker (§5.3.3, §7.2)', function (): void {
    $answers = (string) file_get_contents(base_path('tests/Fixtures/e2e-answers.json'));

    $this->artisan('skv:install', ['--answers' => $answers, '--no-interaction' => true])
        ->expectsOutputToContain(__('Summary'))
        ->expectsOutputToContain('password')
        ->assertSuccessful();

    expect(Marker::exists($this->sandbox))->toBeTrue()
        ->and(new EnvFile($this->sandbox->path('.env'))->get('APP_NAME'))->toBe('E2E App');
});

it('fails before any step when the super admin credentials are missing (§5.3.2)', function (): void {
    $this->artisan('skv:install', ['--answers' => json_encode(['start_with' => 'super_admin'], JSON_THROW_ON_ERROR), '--no-interaction' => true])
        ->assertFailed();

    expect(Marker::exists($this->sandbox))->toBeFalse()
        ->and(new EnvFile($this->sandbox->path('.env'))->get('APP_NAME'))->toBe('Laravel');
});

it('rejects answers that are not JSON (§5.3.2)', function (): void {
    $this->artisan('skv:install', ['--answers' => '{not json', '--no-interaction' => true])->assertFailed();
});

it('never prints the super admin password (RL-5)', function (): void {
    $this->artisan('skv:install', ['--answers' => json_encode([
        'database' => 'sqlite', 'start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com',
        'super_admin_password' => 'correct-horse-battery', 'modules' => [], 'https' => false, 'claude' => false,
    ], JSON_THROW_ON_ERROR), '--no-interaction' => true])
        ->doesntExpectOutputToContain('correct-horse-battery')
        ->assertSuccessful();
});
```

`InstallCommand` resolves the `SetupContext` from the container only as the **base path and detection source**. It builds its own context with the validated answers, from `app(SetupContext::class)->basePath`. Wire the default binding in `AppServiceProvider::register()`:

```php
$this->app->bind(SetupContext::class, fn (Application $app): SetupContext => SetupContext::forApplication(
    Answers::defaults(Detection::none(), basename($app->basePath())), Detection::none(),
));
```

The command always detects with `app(Detector::class)->detect()`; there is no test-only branch in production code. The test file's `beforeEach` binds `app()->instance(Detector::class, new Detector(new Toolchain($this->sandbox->path('home'))))`. With no Herd and no `claude` in that home, only the Postgres probe can find something, and every test here passes `database` explicitly.

Append to `tests/Feature/Tooling/ComposerScriptsTest.php`, replacing the M1 `setup` assertions that no longer hold (keep the "never rotates APP_KEY" intent):

```php
it('sets up through skv:bootstrap and skv:install (§5.3.3)', function (): void {
    /** @var array{scripts: array{setup: list<string>}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['setup'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        'composer install',
        "@php -r \"file_exists('.env') || copy('.env.example', '.env');\"",
        '@php artisan skv:bootstrap --no-interaction',
        '@php artisan skv:install',
    ]);
});
```

Do **not** delete the M1 tests "never rotates an existing APP_KEY during setup" and "generates the Boost guidelines during setup" (AGENTS.md: deleting tests needs approval). Rewrite them in place, keeping their names:

```php
it('never rotates an existing APP_KEY during setup', function (): void {
    /** @var array{scripts: array{setup: list<string>}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    // skv:bootstrap keeps an existing key (BootstrapTest); setup never calls key:generate itself.
    expect($composer['scripts']['setup'])->not->toContain('@php artisan key:generate --ansi')
        ->toContain('@php artisan skv:bootstrap --no-interaction');
});

it('generates the Boost guidelines during setup', function (): void {
    /** @var array{scripts: array{setup: list<string>}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    // The Claude step runs boost:install (ClaudeStepTest).
    expect($composer['scripts']['setup'])->toContain('@php artisan skv:install');
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test --compact tests/Feature/Setup/SetupRunnerTest.php tests/Feature/Console/InstallCommandTest.php tests/Feature/Tooling/ComposerScriptsTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

`SetupRunner::run()`:

```php
    public function run(SetupContext $context, ?Closure $around = null): SetupContext
    {
        foreach ($this->steps() as $step) {
            $wasComplete = ! $context->force && $step->isComplete($context);
            $execute = fn (): StepResult => $wasComplete ? StepResult::skipped(__('Already done')) : $step->run($context);

            $context->results[$step->key()] = $around instanceof Closure ? $around($step, $execute) : $execute();

            if ($step->key() === 'herd-site' && ! $wasComplete) {
                $this->applyHttpsConsequence($context);
            }
        }

        return $context;
    }
```

`InstallCommand` (`skv:install {--answers= : JSON answers (§5.3.2)} {--force : Re-run complete steps}`). Answers are built and validated **before** anything is written. Spec §5.3.2: "the command fails before any step runs", and bootstrap counts as a step (step 0).
1. Build the answers:
   - `--answers` given: `json_decode(..., true, flags: JSON_THROW_ON_ERROR)`; a `JsonException` means fail with `__('The --answers value is not valid JSON.')`.
   - Interactive: Laravel Prompts' `form()`, with the fields in the §5.3.2 order and the defaults pre-filled from `Answers::defaults()`. Ask the super-admin email and password only when `start_with` is `super_admin` (use `form()->...->addIf(...)`, or a second `form()`; check the 0.3.25 `FormBuilder` API). `modules` is `multiselect`; https, playwright and claude are `confirm`.
   - `--no-interaction` without `--answers`: `[]`, so all defaults apply.
2. `Answers::fromArray($input, $detection, $folder)`. A `ValidationException` prints every message and returns `FAILURE` before any step.
3. `app(Bootstrap::class)->run()` (step 0). Print its actions.
4. Build the context: `new SetupContext($basePath, $answers, $detection, (bool) $this->option('force'))`.
5. Run the steps with `$around`:
   - interactive: `fn ($step, $execute) => task($step->label(), $execute)`
   - otherwise: `fn ($step, $execute) => tap($execute(), fn (StepResult $r) => $this->components->twoColumnDetail($step->label(), $r->status))`
6. A step that throws: print `__('Setup stopped at :step: :message', [...])` and return `FAILURE`. The marker is not written (RL-6).
7. Print the summary:
   - `$this->components->info(__('Summary'))`
   - the URLs
   - a table of logins (email, password or `—`, URL)
   - the manual commands with reasons
   - the notes
   Return `SUCCESS`.

`composer.json` `setup`: exactly the list in the test (spec §5.3.3).

Lang keys (nl):
- `Already done`: "Al gedaan"
- `The --answers value is not valid JSON.`: "De waarde van --answers is geen geldige JSON."
- `Setup stopped at :step: :message`: "Setup gestopt bij :step: :message"
- `Passkeys are off because the site has no HTTPS. …`: "Passkeys staan uit omdat de site geen HTTPS heeft. Zet ze aan met: herd secure :name, zet MODULE_PASSKEYS=true, en draai php artisan skv:doctor --fix."
- the prompt labels:
  - `App name`: "Naam van de app"
  - `Database`
  - `Start with`: "Begin met"
  - `Demo data`: "Demodata"
  - `My own super admin`: "Mijn eigen superbeheerder"
  - `Modules`
  - `Use HTTPS`: "HTTPS gebruiken"
  - `Install Playwright`: "Playwright installeren"
  - `Set up Claude Code`: "Claude Code instellen"
  - `Super admin email`: "E-mailadres superbeheerder"
  - `Super admin password`: "Wachtwoord superbeheerder"

- [ ] **Step 4: Run them to see them pass**

Run: `php artisan test --compact tests/Feature/Setup tests/Feature/Console tests/Feature/Tooling`
Expected: PASS.

- [ ] **Step 5: Gate and commit**

```bash
git add app/Setup app/Console/Commands/InstallCommand.php app/Providers/AppServiceProvider.php composer.json tests lang
git commit -m "feat: add skv:install with the setup runner, prompts and the https consequence

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 13: `skv:flux-pro` and the CI Flux Pro step (§6.7, §8, DEC-29, RL-12)

**Files:**
- Create: `app/Console/Commands/FluxProCommand.php`
- Modify: `.github/workflows/tests.yml` (check job)
- Modify: `tests/Feature/Tooling/CiWorkflowTest.php`
- Test: `tests/Feature/Console/FluxProCommandTest.php`

**Interfaces:**
- Consumes: `Toolchain`, `Skv::hasFluxPro()`.
- Produces: `skv:flux-pro` (`--no-interaction` safe).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Console/FluxProCommandTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §6.7 */

use App\Support\Skv;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    // CI may have Pro installed before composer check: force the free branch unless a test says otherwise.
    Skv::useFluxPro(false);
    $this->probe = config('view.compiled').'/flux-pro-probe-'.getmypid().'.php';
    File::put($this->probe, '<?php');
});

afterEach(function (): void {
    Skv::useFluxPro(null);
    File::delete($this->probe);
});

it('adds the repository, requires Flux Pro and clears compiled views (§6.7, DEC-29)', function (): void {
    Process::fake([
        "*composer' 'config' 'http-basic.composer.fluxui.dev'*" => Process::result('{"username":"me@example.com","password":"secret-key"}'),
        "*composer' 'config' 'repositories.flux-pro' 'composer' 'https://composer.fluxui.dev'*" => Process::result(''),
        "*composer' 'require' 'livewire/flux-pro'*" => Process::result('Installed'),
    ]);
    $this->artisan('skv:flux-pro', ['--no-interaction' => true])
        ->doesntExpectOutputToContain('secret-key')
        ->doesntExpectOutputToContain('me@example.com')
        ->assertSuccessful();

    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'require livewire/flux-pro'));
    expect(is_file($this->probe))->toBeFalse();
});

it('only clears compiled views when Flux Pro is already installed (DEC-29)', function (): void {
    Skv::useFluxPro(true);

    $this->artisan('skv:flux-pro', ['--no-interaction' => true])
        ->expectsOutputToContain(__('Flux Pro is already installed.'))
        ->assertSuccessful();

    Process::assertDidntRun(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'require livewire/flux-pro'));
    expect(is_file($this->probe))->toBeFalse();
});

it('stops with the README one-liner when no credentials are configured (RL-12)', function (): void {
    Process::fake(["*composer' 'config' 'http-basic.composer.fluxui.dev'*" => Process::result('', exitCode: 1)]);

    $this->artisan('skv:flux-pro', ['--no-interaction' => true])
        ->expectsOutputToContain('composer config --global http-basic.composer.fluxui.dev')
        ->assertFailed();

    Process::assertDidntRun(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'require livewire/flux-pro'));
});
```

`FluxProCommand` clears views with `$this->callSilently('view:clear')`. The tests prove it with the probe file instead of mocking the Artisan kernel, which `$this->artisan()` itself uses.

Append to `tests/Feature/Tooling/CiWorkflowTest.php`:

```php
it('enables Flux Pro in CI only when the secrets exist, before any view compiles (§6.7, §8)', function (): void {
    $workflow = (string) file_get_contents(base_path('.github/workflows/tests.yml'));

    expect($workflow)
        ->toContain('FLUX_USERNAME: ${{ secrets.FLUX_USERNAME }}')
        ->toContain('FLUX_LICENSE_KEY: ${{ secrets.FLUX_LICENSE_KEY }}')
        ->toContain("if: env.FLUX_USERNAME != '' && matrix.db == 'pgsql'")
        ->toContain('composer config --global http-basic.composer.fluxui.dev "$FLUX_USERNAME" "$FLUX_LICENSE_KEY"')
        ->toContain('php artisan skv:flux-pro --no-interaction')
        ->and(strpos($workflow, 'skv:flux-pro'))->toBeLessThan(strpos($workflow, 'composer check'));
});
```

Change the existing test "never references Flux Pro in the committed composer files" to read the **committed** files: `git show HEAD:composer.json` and `git show HEAD:composer.lock` through `Process::run(['git', 'show', 'HEAD:composer.json'])->output()`, falling back to the working-tree file when `.git` is absent. A CI job that ran `skv:flux-pro` has `flux-pro` in its working-tree `composer.json`, so the old test would fail exactly where Pro is enabled. Keep its name and add "(committed)".

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test --compact tests/Feature/Console/FluxProCommandTest.php tests/Feature/Tooling/CiWorkflowTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

`FluxProCommand` (`skv:flux-pro`):
1. When `Skv::hasFluxPro()` is already true, print `__('Flux Pro is already installed.')` and run `view:clear` anyway (DEC-29). Return `SUCCESS`.
2. **Credentials:** run `[composer, 'config', 'http-basic.composer.fluxui.dev']` with `Process::path(base_path())`, which reads the project `auth.json` and falls back to the global one. Capture the result and **never print it**. Empty output or a non-zero exit means missing: print `__('Add your Flux Pro credentials first: composer config --global http-basic.composer.fluxui.dev <email> <license-key>')` and return `FAILURE`.
3. Run `[composer, 'config', 'repositories.flux-pro', 'composer', 'https://composer.fluxui.dev']` in `base_path()`.
4. Run `[composer, 'require', 'livewire/flux-pro', '--no-interaction']` in `base_path()` (timeout 900). On failure, print the last output line; Composer prints no credentials. Return `FAILURE`.
5. `$this->callSilently('view:clear')`, then print `__('Flux Pro installed. Its components are available after this step; compiled views were cleared.')`.

`composer` resolves through `$toolchain->which('composer') ?? 'composer'`; every process gets the toolchain env.

`.github/workflows/tests.yml`, check job:
- Add `FLUX_USERNAME: ${{ secrets.FLUX_USERNAME }}` and `FLUX_LICENSE_KEY: ${{ secrets.FLUX_LICENSE_KEY }}` to the job `env`.
- After the `Install` step and before `Audit dependencies` / `Check`, add:

```yaml
      - name: Enable Flux Pro
        if: env.FLUX_USERNAME != '' && matrix.db == 'pgsql'
        run: |
          composer config --global http-basic.composer.fluxui.dev "$FLUX_USERNAME" "$FLUX_LICENSE_KEY"
          php artisan skv:flux-pro --no-interaction
```

`--global` writes the runner's `COMPOSER_HOME/auth.json`, which is never inside the checkout. Only the main (PostgreSQL) row enables Pro; `sqlite-free` and `php85` stay on free Flux (§8). Fork PRs have no secrets, so the step is skipped and no job is skipped (§6.7). Never reference `secrets` in an `if:`: map them to job `env` and test `env.*`.

Lang keys (nl):
- `Flux Pro is already installed.`: "Flux Pro is al geïnstalleerd."
- `Add your Flux Pro credentials first: …`: "Voeg eerst je Flux Pro-gegevens toe: composer config --global http-basic.composer.fluxui.dev <e-mail> <licentiesleutel>"
- `Flux Pro installed. …`: "Flux Pro is geïnstalleerd. De componenten zijn nu beschikbaar; gecompileerde views zijn gewist."

- [ ] **Step 4: Run them to see them pass**

Run: `php artisan test --compact tests/Feature/Console tests/Feature/Tooling`
Expected: PASS.

- [ ] **Step 5: Gate and commit**

```bash
git add app/Console/Commands/FluxProCommand.php .github/workflows/tests.yml tests lang
git commit -m "feat: add skv:flux-pro and enable flux pro in ci when the secrets exist

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 14: Tooling follow-ups (DEC-25 M6)

**Files:**
- Modify: `scripts/create-pgsql-test-database.php`, `.githooks/pre-commit`, `composer.json` (`test:pgsql`)
- Modify: `tests/Feature/Tooling/GitHooksTest.php`, `tests/Feature/Tooling/ComposerScriptsTest.php`
- Test: `tests/Feature/Tooling/PgsqlTestDatabaseScriptTest.php`

**Interfaces:** none new.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Tooling/PgsqlTestDatabaseScriptTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §7.4 */

use Illuminate\Support\Facades\Process;

it('explains an unreachable PostgreSQL instead of throwing (DEC-25)', function (): void {
    $result = Process::path(base_path())->env(['DB_PORT' => '1', 'DB_TEST_DATABASE' => 'unused_test'])->run([PHP_BINARY, 'scripts/create-pgsql-test-database.php']);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('PostgreSQL is not reachable')
        ->and($result->errorOutput())->not->toContain('PDOException')
        ->and($result->errorOutput())->not->toContain('Stack trace');
});

it('names the test database after $PWD, like the test:pgsql script (DEC-25)', function (): void {
    expect((string) file_get_contents(base_path('scripts/create-pgsql-test-database.php')))->toContain("getenv('PWD')");
});
```

`tests/Feature/Tooling/GitHooksTest.php`, add:

```php
it('passes staged file names safely and refuses partially staged PHP files (DEC-25)', function (): void {
    $hook = (string) file_get_contents(base_path('.githooks/pre-commit'));

    expect($hook)->toContain('-z')
        ->toContain('xargs -0')
        ->toContain('git diff --name-only')
        ->not->toContain(' $FILES');
});
```

`tests/Feature/Tooling/ComposerScriptsTest.php`, add:

```php
it('sets the hooks path in a git worktree too (DEC-25)', function (): void {
    /** @var array{scripts: array<string, list<string>>} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect(implode("\n", $composer['scripts']['setup']))->not->toContain("is_dir('.git')");
});
```

The hooks path now lives in the Git step (Task 7), which already treats a `.git` file as a repository. This test guards that `setup` keeps no `is_dir('.git')` shortcut.

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test --compact tests/Feature/Tooling`
Expected: the pgsql-script and hook tests FAIL. The worktree test already passes after Task 12; keep it as a guard.

- [ ] **Step 3: Implement**

`scripts/create-pgsql-test-database.php`:
- Derive the name from `getenv('DB_TEST_DATABASE') ?: basename((string) (getenv('PWD') ?: dirname(__DIR__))).'_test'`. That is the same folder name `$(basename "$PWD")` gives the shell part of `test:pgsql`, also under a symlink.
- Wrap the PDO connect and queries in `try { … } catch (PDOException $exception)`. In the catch, print to STDERR: `"PostgreSQL is not reachable at {$host}:{$port} ({$exception->getCode()}). Start it in Herd, or set DB_HOST/DB_PORT.\n"` and `exit(1)`.

`.githooks/pre-commit`:

```sh
#!/bin/sh
# Fast checks on staged PHP files (< 10 s). The full suite runs in CI (composer check).
set -e

STAGED=$(git diff --cached --name-only --diff-filter=ACMR -z -- '*.php' | tr '\0' '\n' | grep -v '\.blade\.php$' || true)
[ -z "$STAGED" ] && exit 0

# The tools read the working tree: refuse files with unstaged changes, so what is checked is what is committed.
PARTIAL=$(git diff --name-only -- $(printf '%s\n' "$STAGED" | sed "s/'/'\\\\''/g; s/.*/'&'/") 2>/dev/null || true)
if [ -n "$PARTIAL" ]; then
    echo "pre-commit: stage or stash the unstaged changes in:" >&2
    echo "$PARTIAL" >&2
    exit 1
fi

git diff --cached --name-only --diff-filter=ACMR -z -- '*.php' | grep -zv '\.blade\.php$' | xargs -0 vendor/bin/pint --test
git diff --cached --name-only --diff-filter=ACMR -z -- '*.php' | grep -zv '\.blade\.php$' | xargs -0 vendor/bin/phpstan analyse --no-progress --memory-limit=1G
```

- The partial-stage line above is fragile. Write it so file names with spaces work, using `git diff --name-only -z` intersected with the staged list in a `while IFS= read -r -d ''` loop under `bash`. If you switch the shebang to `#!/usr/bin/env bash`, keep `GitHooksTest`'s executable check.
- `grep -z` is GNU-only; macOS's BSD grep supports `-z` too (check `grep --version`). Otherwise filter Blade files in the loop.
- Test the hook by hand in a scratch clone with a file named `a b.php`, and record the result in your report.

- [ ] **Step 4: Run them to see them pass**

Run: `php artisan test --compact tests/Feature/Tooling`
Expected: PASS.

- [ ] **Step 5: Gate and commit**

```bash
git add scripts/create-pgsql-test-database.php .githooks/pre-commit composer.json tests/Feature/Tooling
git commit -m "fix: harden the pgsql test-database script and the pre-commit hook

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 15: The e2e job and its local counterpart (§8, OD-3)

**Files:**
- Modify: `.github/workflows/tests.yml` (`e2e` job, `schedule` and `workflow_dispatch` triggers)
- Create: `scripts/e2e-local.sh`
- Modify: `tests/Feature/Tooling/CiWorkflowTest.php`

**Interfaces:**
- Consumes: `tests/Fixtures/e2e-answers.json`, `skv:install`, `skv:doctor --json`.
- Produces: `scripts/e2e-local.sh <target-dir> [--https]`, run by the controller in Task 17.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Tooling/CiWorkflowTest.php`:

```php
it('runs the installer end to end in an e2e job (§8)', function (): void {
    // Needs `use Illuminate\Support\Str;` at the top of CiWorkflowTest.php.
    $workflow = (string) file_get_contents(base_path('.github/workflows/tests.yml'));
    // Derived from composer.json: NameLeakTest scans tests/, so this file holds no kit name literal.
    $package = (string) json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR)['name'];

    expect($workflow)
        ->toContain('workflow_dispatch:')
        ->toContain('schedule:')
        ->toContain('  e2e:')
        ->toContain('fetch-depth: 0')
        ->toContain('git switch -C ci')
        ->toContain('composer config -g repositories.'.Str::after($package, '/').' vcs "$GITHUB_WORKSPACE"')
        ->toContain("--using={$package}:dev-ci")
        ->toContain('--no-interaction --no-ansi --database=sqlite --npm')
        ->toContain('skv:install --no-interaction --answers=')
        ->toContain('skv:doctor --json')
        ->toContain("flags: '--pest --git --boost'")
        ->toContain("flags: '--phpunit --git --no-boost'");
});

it('ships a local e2e script that never touches global Composer config (OD-3)', function (): void {
    $script = (string) file_get_contents(base_path('scripts/e2e-local.sh'));

    expect(is_executable(base_path('scripts/e2e-local.sh')))->toBeTrue()
        ->and($script)->toContain('COMPOSER_HOME=')
        ->and($script)->toContain('skv:install --no-interaction --answers=')
        ->and($script)->not->toContain('composer config --global --list');
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Tooling/CiWorkflowTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

`.github/workflows/tests.yml`:
- Add `workflow_dispatch:` and `schedule: [{cron: '17 3 * * *'}]` to `on:`. The other jobs keep running on those events too, which is fine nightly.
- Add an `e2e` job with `name: e2e (${{ matrix.name }})` and `runs-on: ubuntu-latest`.
- The matrix has three rows, each with `php: '8.4'` through `matrix.php` (never a literal in `php-version`):
  - `{name: default, flags: '--pest --git --boost', db: sqlite}`
  - `{name: phpunit-no-boost, flags: '--phpunit --git --no-boost', db: sqlite}`
  - `{name: postgres-pro, flags: '--pest --git --boost', db: pgsql}`
- For `postgres-pro`, add a `services: postgres` block on the job with image `postgres:18`, env `POSTGRES_USER: root` and `POSTGRES_HOST_AUTH_METHOD: trust`, port `5432:5432`, and `options: >- --health-cmd "pg_isready -U root" --health-interval 5s --health-timeout 5s --health-retries 10`. Herd's credentials are user `root` with an empty password (RL-4).
- **Job env and toolchain:**
  - The job has `env: { FLUX_USERNAME: ${{ secrets.FLUX_USERNAME }}, FLUX_LICENSE_KEY: ${{ secrets.FLUX_LICENSE_KEY }} }`.
  - setup-php uses `extensions: pdo_pgsql, pdo_sqlite, gd` and `tools: composer:v2`, like the other jobs (the doctor's `gd` check fails without gd).
  - Change the workflow `concurrency` group to `ci-${{ github.event_name }}-${{ github.ref }}`, so a push to main no longer cancels a nightly or dispatched run.

Steps (each `run:` block in bash):
1. **Changes gate:** `actions/checkout` (pinned SHA, `fetch-depth: 0`). Then a step `id: changes`:
   - On `schedule`/`workflow_dispatch` it outputs `run=true`.
   - On a PR, `run` is true when `git diff --name-only origin/${{ github.base_ref }}...HEAD` touches `composer.json`, `.env.example`, `app/Setup/` or `app/Console/Commands/`.
   - Otherwise it is false.
   - Every later step has `if: steps.changes.outputs.run == 'true'`, so the job never fails for skipping.
2. **Toolchain:** setup-php (`${{ matrix.php }}`) and setup-node (22), both with pinned SHAs like the other jobs.
3. `git switch -C ci` and `composer config -g repositories.skv3 vcs "$GITHUB_WORKSPACE"`.
4. `composer global require laravel/installer --no-interaction` and `echo "$(composer global config bin-dir --absolute)" >> "$GITHUB_PATH"`.
5. **The installer:**

   ```bash
   cd "$RUNNER_TEMP" && laravel new tmp --using=frankcornet/skv3:dev-ci --no-interaction --no-ansi --database=sqlite --npm ${{ matrix.flags }} 2>&1 | tee install.log
   ```

6. **Phase-1 assertions** (§7.2, §8), each a bash line with an explicit `exit 1`:
   - `grep -c 'skv:install' install.log` equals 1; bootstrap ran once.
   - `grep '^DB_CONNECTION=sqlite' tmp/.env`.
   - `php artisan migrate:status` shows no `Pending`.
   - `test -f tmp/public/build/manifest.json`.
   - `diff "$GITHUB_WORKSPACE/.github/workflows/tests.yml" tmp/.github/workflows/tests.yml`.
   - `diff "$GITHUB_WORKSPACE/tests/Pest.php" tmp/tests/Pest.php` (only for the `--pest` rows).
   - `diff -r "$GITHUB_WORKSPACE/tests" tmp/tests` (only for the `--pest` rows; DEC-25 M8).
   - `php -r` decoding `tmp/composer.json` asserts at most one `post-update-cmd` entry contains `boost:update`.
7. **Phase 2:**
   - For `postgres-pro`, rewrite the fixture with `jq '.database="pgsql" | .modules=["passkeys","backups","impersonation"] | .https=true'` (no Herd in CI, so RL-7 turns passkeys off).
   - For `postgres-pro` only, when `env.FLUX_USERNAME != ''`, run the same "Enable Flux Pro" lines as the check job inside `tmp/`.
   - For the `--boost` rows, assert `tmp/composer.json` `require-dev.laravel/boost` is `^2.10` after `skv:install` (DEC-22 rule 5).
   - Then:

   ```bash
   cd "$RUNNER_TEMP/tmp" && sed -i 's#^APP_URL=.*#APP_URL=http://localhost#' .env && php artisan skv:install --no-interaction --answers="$(cat "$GITHUB_WORKSPACE/tests/Fixtures/e2e-answers.json")" && php artisan skv:doctor --json && composer check
   ```

   `skv:doctor --json` exits non-zero on any `fail` (RL-11). On a GitHub runner without Herd, checks like `subdomains` may fail for `localhost` subdomains. If so, run the job with `TENANCY_APEX_DOMAIN=localhost`; Chromium and glibc resolve `*.localhost`, which `gethostbyname` may not. Make the `subdomains` check pass for an apex ending in `localhost`: RFC 6761 reserves it for loopback. Record that ruling in DEC-30.

`scripts/e2e-local.sh` (bash, `set -euo pipefail`, executable). Arguments: `$1` is the target directory, defaulting to `$HOME/Herd/m6a-e2e`; `--https` is optional.
1. **Refuse an existing target** (never delete what is not yours).
2. `REPO=$(git rev-parse --show-toplevel)` and `BRANCH=$(git rev-parse --abbrev-ref HEAD)`.
3. **An isolated Composer home**, so the global config is never touched:

   ```bash
   CACHE_DIR="$(composer config --global cache-dir)"
   export COMPOSER_HOME="$(mktemp -d)"
   export COMPOSER_CACHE_DIR="$CACHE_DIR"
   trap 'rm -rf "$COMPOSER_HOME"' EXIT
   composer config -g repositories.skv3 vcs "$REPO"
   ```

   Read the real cache dir **before** switching `COMPOSER_HOME`, otherwise it points into the temp home. `-g` then writes to the temp home. `cache-dir` prints a path only.
4. Run the installer: `cd "$(dirname "$TARGET")" && laravel new "$(basename "$TARGET")" --using="frankcornet/skv3:dev-$BRANCH" --no-interaction --no-ansi --database=sqlite --npm --pest --git --boost`. Use Herd's `laravel` binary through PATH.
5. Run the same phase-1 assertions as CI, printing `PASS`/`FAIL` per line.
6. Install: `php artisan skv:install --no-interaction --answers="$(cat "$REPO/tests/Fixtures/e2e-answers.json")"`. With `--https`, first set `.https=true` via `php -r` (no `jq` dependency).
7. `php artisan skv:doctor --json`, then print the summary and the cleanup commands. **Do not run them:**

   ```bash
   herd unsecure <name>
   rm -rf "$TARGET"
   ```

   Add `herd unisolate --site=<name>` when the site is isolated (`unisolate` takes no positional argument), and `herd unlink <name>` only when the run linked the site. The controller runs the cleanup in Task 17.

- [ ] **Step 4: Run it to see it pass**

Run: `php artisan test --compact tests/Feature/Tooling/CiWorkflowTest.php tests/Arch/NameLeakTest.php` and `bash -n scripts/e2e-local.sh`
Expected: PASS and no syntax errors. Do not widen the `NameLeakTest` allow-list. Do **not** run the script; the controller does that in Task 17. Validate the workflow YAML with `php -r 'var_dump(Symfony\Component\Yaml\Yaml::parseFile(".github/workflows/tests.yml") !== null);'` after `require 'vendor/autoload.php';`.

- [ ] **Step 5: Gate and commit**

```bash
git add .github/workflows/tests.yml scripts/e2e-local.sh tests/Feature/Tooling/CiWorkflowTest.php
git commit -m "ci: add the installer e2e job and a local e2e script

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 16: Documentation, decisions and agent guidelines (§10, DEC-30)

**Files:**
- Modify: `README.md`, `CHANGELOG.md`, `docs/decisions.md` (DEC-30), `.ai/guidelines/skv3.md`, `docs/handleiding.html`

**Interfaces:** none.

- [ ] **Step 1: README (§10)**

Replace "The Herd one-click setup arrives in M6." with a quickstart for the four entry points, CLI first for now. The browser wizard arrives in M6b; say so in one line. For each route:
- `laravel new myapp --using=frankcornet/skv3` (Herd's *New site → Custom Starter Kit* runs the same), then `php artisan skv:install`
- `composer create-project frankcornet/skv3 myapp`, then `php artisan skv:install`
- `git clone … && composer setup`

Then add these sections:
- **Commands:** `skv:install [--answers=JSON] [--no-interaction] [--force]`, `skv:doctor [--fix] [--json]`, `skv:create-super-admin`, `skv:create-organisation`, `skv:flux-pro`, `permissions:sync`, `composer doctor`.
- **The answers schema:** a table identical to spec §5.3.2.
- **Demo accounts:** emails derived from the apex, password `password`, demo seeder only, never in production. Name the risk (R2): never publish them.
- **Flux Pro:** the one-liner, `composer config --global http-basic.composer.fluxui.dev <email> <license-key> && php artisan skv:flux-pro`.
- **Local subdomains without Herd:** the `/etc/hosts` lines that `skv:doctor` prints, or dnsmasq, plus `TENANCY_APEX_DOMAIN`.
- **Production checklist:**
  - `permissions:sync` on deploy
  - `HEALTH_CHECK_KEY`
  - `SESSION_DRIVER=database`
  - no demo seeder
  - a queue worker (`php artisan queue:work`), because invitation mails are queued
  - a cron entry running `php artisan schedule:run` every minute
- **AI section:**
  - A Bash deny rule in `.claude/settings.json` cannot stop every bypass (DEC-25).
  - The Herd MCP server is registered as `herd`, so the `get_all_sites` deny applies.
  - Project plugins (superpowers) activate only after you accept Claude Code's workspace-trust dialog (§9).

- [ ] **Step 2: DEC-30**

Append `### DEC-30 — Setup engine build rulings (M6a)` with **Question / Answer / Consequence**, like DEC-29:
- **Answer:** OD-1…OD-4, RL-1…RL-15, plus every "Deviations from the brief" the task reports recorded. The controller passes them in the ledger.
- **Consequence:**
  - **Deferred to M6b:** the wizard, the gate and token, the setup layout and CSS, detached steps, the resolver bypass, the `/` redirect, the quick login and the wizard's browser tests.
  - **Deferred to M7:** the backups module, `/health-check`, the System page.
  - **Deferred to M8:** the e2e job runs once a remote exists.

- [ ] **Step 3: CHANGELOG, guidelines, handleiding**

`CHANGELOG.md`, under **Added**:
- the setup engine (`skv:install`, `skv:bootstrap`, `skv:doctor`, `skv:create-*`, `skv:flux-pro`)
- the demo seeder
- the e2e job

Under **Changed**: `composer setup` runs `skv:bootstrap` + `skv:install`; the temporary development login is gone.

`.ai/guidelines/skv3.md`, add a "Setup" block:
- Setup steps resolve files through `SetupContext::path()`.
- External commands run through `Process` with `Toolchain::environment()`, and herd by its absolute path.
- Never branch on a Herd exit code alone.
- The demo seeder never runs in production.
- New doctor checks are one class per check under `App\Setup\Doctor\Checks`.
- Done criteria: also `composer test:browser` (M5 follow-up).

Then run `php artisan boost:update` so `AGENTS.md` picks it up. `AGENTS.md` is gitignored and need not be committed; `AiConfigTest` reads `.ai/guidelines`.

`docs/handleiding.html`:
- Replace the "M5 is af op de branch m5-ui" lines with the current state (M5 merged, M6a on its branch).
- Add a Dutch section "Installeren en controleren" covering `skv:install`, `skv:doctor` and the demo accounts. It must not show the demo password next to a public URL (R2): say "het wachtwoord staat in de setup-samenvatting".

- [ ] **Step 4: Gate and commit**

```bash
git add README.md CHANGELOG.md docs/decisions.md .ai/guidelines/skv3.md docs/handleiding.html
git commit -m "docs: document the setup engine, record DEC-30 and update the manual

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 17: Milestone gate, local e2e and the throwaway Herd site (OD-3, OD-4)

This task is the controller's. The workflow's final review and gate cover Step 1. Steps 2–4 change the owner's Herd setup, so the controller runs them after the workflow, one command at a time, reporting each result.

- [ ] **Step 1: Full verification (superpowers:verification-before-completion)**

```bash
composer check
composer test:pgsql
php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser
composer test:browser
git status --short
git show HEAD:composer.json | grep -c flux-pro; git show HEAD:composer.lock | grep -c flux-pro
grep -L "covers §" $(git diff --name-only --diff-filter=A main -- 'tests/**/*.php' | grep -v -e Fixtures -e Support)
```

Expected: four green suites, a clean tree, `0` and `0`, and an empty `grep -L`.

- [ ] **Step 2: Local e2e (OD-3)**

```bash
scripts/e2e-local.sh "$HOME/Herd/m6a-e2e"
```

`~/Herd` is parked, so no `herd link` happens. The fixture has `https: false`, so no `herd secure` either. Expected: every phase-1 line `PASS`, `skv:install` succeeds, and `skv:doctor --json` reports `"green": true`. Then open `http://m6a-e2e.test` and `http://demo1.m6a-e2e.test` with Playwright headless, log in as `admin@demo1.m6a-e2e.test`, and expect the dashboard.

- [ ] **Step 3: The Herd steps for real (OD-4, FU-isolate)**

In `~/Herd/m6a-e2e`:
1. `php artisan skv:install --force --no-interaction --answers='{"https":true,"modules":["passkeys","impersonation"],"database":"sqlite","claude":false}'`.
2. Expect the Herd step to secure the site and `herd secured` to list `m6a-e2e.test`.
3. Expect `APP_URL` to be `https://m6a-e2e.test` and passkeys to stay on.
4. `curl -sI http://m6a-e2e.test` must answer `301`.
5. Isolate: `herd isolate 8.3 --site=m6a-e2e`, then `skv:install --force` again. Expect the step to re-isolate 8.4, and record what `herd isolated` printed.
6. Record every output in the ledger.

- [ ] **Step 4: Clean up**

```bash
herd unsecure m6a-e2e
herd unisolate --site=m6a-e2e
rm -rf "$HOME/Herd/m6a-e2e"
```

Check `herd unisolate --help` and `herd unlink --help` first. Run `herd unlink m6a-e2e` only if the ledger shows the Herd step linked the site; `~/Herd` is parked, so it should not have. Expect `herd secured`, `herd isolated` and `herd links` no longer to list the site. The site used SQLite, so no database is left behind.

- [ ] **Step 5: Final review and hand-off**

Run the final review (superpowers:requesting-code-review) over `main..m6a-setup-engine`. Fix what it finds. Stop before merging: the owner merges. Update the memory notes (M6a done, M6b plan next, deferred minors).
