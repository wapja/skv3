# M6b Setup Wizard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish M6 by building the browser setup wizard (`/welcome-setup`) and the local quick login on top of M6a's setup engine. A Herd user goes from *Open site* to a working app without a terminal.

**Architecture:**
- **Setup mode.** It is active when the app runs locally, has no install marker and has a one-time setup token. In that mode one middleware at the front of the `web` group moves the request onto a file session, a file cache and the sync queue, and the tenant resolver stands aside on every host.
- **The wizard.** It is a Livewire multi-file page behind a token gate. Each Livewire request runs one setup step through `SetupRunner::runStep()`. Long steps run in a detached `php artisan skv:run-step` process, which the page polls.
- **After the marker.** The Herd step runs detached. The page polls a status endpoint that needs a secret only the wizard's browser holds, then signs the new super admin in through the existing single-use handoff.
- **Shared engine.** The steps, `Answers`, `Marker`, `Summary` and the doctor are M6a's. This plan adds the per-step runner, a state round trip and the fixes the wizard depends on.

**Tech Stack:** PHP 8.4, Laravel 13.35, Livewire 4.4 (multi-file components), Flux 2.20 (free), Tailwind 4.3 with `@tailwindcss/vite` 4.3, Vite 8.3, laravel/prompts 0.3, Pest 5.3 with pest-plugin-browser 5.1, Larastan 3 level 8, PostgreSQL 18 (Herd), SQLite, Herd 1.30.2, Laravel Installer 5.31.1.

**Spec:** `docs/superpowers/specs/2026-10-10-skv3-design.md`. Read these before starting:
- §5.1–§5.3 (especially §5.3.4, browser wizard runtime)
- §6.2 (the route × host matrix row `/welcome-setup`)
- §6.5 (local quick login)
- §7.2 (phase-1 and wizard tests)
- §8 (e2e), §12 (success criteria 1–2), §13 (risks)

Decisions in `docs/decisions.md`:
- DEC-10, DEC-18, DEC-22 (installer facts)
- DEC-23 (S1: no PATH under php-fpm; detached processes; never trust Herd exit codes; GET after `herd secure`)
- DEC-24 (S6: forwarded headers)
- DEC-29 (19): quick login moved to M6
- DEC-30: everything M6a built; its Consequence lists what M6b must do

This plan's rulings (RL-*) and owner rulings (OD-*) are recorded as DEC-31 in Task 15.

---

## Global Constraints

- **Versions:**
  - PHP floor `^8.4`; `laravel/framework ^13.35`; Pest `^5.3`.
  - Larastan level **8**, **no baseline**, no new `ignoreErrors` entries. This includes the multi-file component `.php` files under `resources/views/**`.
- **PHP files:** every one starts with `declare(strict_types=1);`. Pint uses the `laravel` preset plus `declare_strict_types`, `strict_comparison` and `ordered_class_elements`. Run `composer fix` after editing PHP.
- **Dependencies:** M6b adds **none**, Composer or npm (OD-1). `livewire/flux-pro` and `composer.fluxui.dev` never appear in the committed `composer.json` or `composer.lock`.
- **Never touch the developer's own environment from code or tests:**
  - Tests run setup code against a sandbox: `setupSandbox()`, or `useSetupWizard()` (Task 5), which wraps it.
  - No test reads or writes the repository's `.env`, `.env.testing`, `herd.yml`, `.mcp.json`, `storage/app/skv-installed`, `storage/app/private/skv-*` or `storage/logs/skv-herd.log`.
  - Implementers never run `skv:install`, `skv:bootstrap`, `skv:run-step` or `skv:flux-pro` in the repository itself, and never open `/welcome-setup` on `skv3.test`.
  - Never run `herd link`, `unlink`, `secure`, `unsecure`, `isolate`, `unisolate`, `init`, `park` or `forget` outside the controller's Task 16. Read-only Herd commands (`tld`, `paths`, `links`, `secured`, `isolated`, `services:list --json`, `--help`) are fine.
  - Never run `php artisan serve` in the repository. The controller's phase-1 runs (Task 16) use it only inside scratch copies.
- **External commands:**
  - Every external command (`herd`, `npm`, `npx`, `git`, `claude`, `composer`, the CLI `php`) runs through `Process` with `Toolchain::environment()` (DEC-23).
  - Tests fake them with `Process::fake([...])` and assert with `Process::assertRan(...)`. No test spawns a detached process: tests bind `StepLauncher` to `Tests\Fixtures\InlineStepLauncher` (Task 8).
  - Never branch on a Herd exit code alone (§13).
- **Never print or log secrets:**
  - the setup token, the Herd-stage secret, the super-admin password and its hash
  - `APP_KEY`, `HEALTH_CHECK_KEY`, the Postgres password, Composer credentials
  - The demo password `password` is the only credential the UI shows (spec §4.12).
  - Never `composer config --global --list`; never print `auth.json`.
- **Never run against the development database:** no `migrate:fresh`, `db:wipe` or `db:seed`. `tinker`, `migrate` and `db:seed` prompt for approval.
- **Tenancy:**
  - `Tenant::bypass()` and scope removal are allowed only in `App\Setup`, `App\Console`, `App\Tenancy`, `App\Actions\Audit` and `Database\Seeders`.
  - The wizard page (`resources/views/pages/setup/**`) and controllers outside those namespaces call `App\Setup\*` services, which do the bypassing.
  - Use the existing actions: `IssueHandoff`, `SetSuperAdmin`, `CreateOrganisation`, `InviteUser`.
- **Names:** no hard-coded kit or site name (`skv`) in app-identity values. Allowed:
  - the `skv:` command namespace
  - `config/skv.php` and `config('skv.…')`
  - `Skv::` and `App\Support\Skv`
  - the state-file names, which Task 8 narrows to exactly `skv-installed`, `skv-setup-token`, `skv-setup.json`, `skv-setup/`, `skv-herd.json`, `skv-herd.exit` and `skv-herd.log`
  - Tests refer to these paths through the classes' constants, not literals.
- **Copy and lang keys:**
  - Code, comments and docs are in English. UI copy goes through `__()`, `nl` by default, with `en`.
  - Every key goes into **both** `lang/nl.json` and `lang/en.json`: identical keys, PHP `sort()` order, Dutch informal "je".
  - Glossary: Setup = Installatie, Step = Stap, Re-check = Opnieuw controleren, Super admin = Superbeheerder, Demo data = Demodata.
- **Livewire:**
  - Multi-file components only (`php artisan make:livewire <name> --no-interaction`; add `declare(strict_types=1);`), no ⚡, never `$this->view()`.
  - Authorise in `mount()` and again before every action. Here "authorise" means the setup gate, re-checked in the action because the gate is persistent middleware.
  - `#[Locked]` on every property the browser must not change.
- **Flux:**
  - Free Flux only in the setup views (DEC-02, DEC-20).
  - The setup layout uses no `@vite`, no `@fonts` and nothing from `public/build` (§5.3.4).
- **Tests:**
  - Pest functions (`it(...)`) with typed closures. Each file starts with a `/** covers §x.y */` docblock as its first statement.
  - `Event::fake()` is never called bare.
  - **No `*Test.php` file declares a class (anonymous ones included) or spells a qualified class name outside its imports** (`tests/Arch/TestFileClassesTest.php`, DEC-30 (38)). Fixtures go in `tests/Fixtures`.
- **Global test helper names already taken (never redeclare):**
  - `acceptLinkFor`, `asMember`, `asOrgAdmin`, `asSuperAdmin`, `browserUser`, `callPageAction`, `confirmationLinkFor`, `dutchTranslationKeys`, `filesMatching`, `inviteMember`, `kitSourceFiles`
  - `onAdminHost`, `onApex`, `onTenant`, `pageSnapshot`, `recordedActivity`, `sameOrigin`, `setupSandbox`, `startImpersonatingOverHttp`, `useImpersonationModule`, `usePasskeysModule`
- **Global test helper reserved for M6b:** `useSetupWizard` (Task 5, `tests/Pest.php`). Nothing else; file-local helpers are closures.
- **Gate per task:**
  - `composer check` (SQLite) green
  - `composer test:pgsql` green
  - the modules-off run: `php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser` green
  - `composer test:browser` green
  - from Task 6 on: `npm run build:setup && git diff --exit-code resources/setup/setup.css` (commit the regenerated file whenever a task changes the setup views or CSS)
  - then `php artisan config:clear`
- **Commits:** Conventional Commits, lower case, imperative, ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. The pre-commit hook (Pint + Larastan on staged files) runs; never bypass it.
- **Never** run `herd sites` or the Herd MCP `get_all_sites`, and never read `.env` files (`.env.example` is fine).

## Conventions used in this plan

- All M6b work happens on branch `m6b-setup-wizard`, created from `main` (merge `6dc448a`). The plan file is its first commit.
- Bracketed ids are traceability tags:
  - `RL-*`: this plan's rulings
  - `OD-*`: owner rulings
  - `A*`: the M6b items from the M6a ledger triage (DEC-30's open points)
  - `DEC-*`: decision-log entries
- **Setup tests** follow M6a's conventions (DEC-30 (2), (22)), unchanged:
  - Sandbox: `setupSandbox()` copies `.env.example` to `<sandbox>/.env` and creates `<sandbox>/storage/app/private`, `<sandbox>/database` and `<sandbox>/public`.
  - Every `tests/Feature/Setup/**` and `tests/Feature/Console/**` file starts with:

    ```php
    beforeEach(function (): void {
        Process::preventStrayProcesses();
        Process::fake([]);
        app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
    });
    ```

  - Process fake keys use Symfony's single-quoted command line: `"*herd' 'secured'*"`, `"*'artisan' 'migrate'*"`.
  - Tests that change `database.default` save it in `beforeEach` and restore it, with `DB::purge('setup')`, in `afterEach`.
  - The command tests use `Artisan::call()` with a `BufferedOutput`.
  - Write `new X()->run()` (PHP 8.4).
- **Wizard tests** start with `$context = useSetupWizard();` (Task 5). It sets the local environment, binds a sandbox with a setup token, and binds `SetupContext`, `SetupMode`, `SetupToken`, `Toolchain` and `StepLauncher` (the inline fixture).
  - The page is tested over HTTP: `get('/welcome-setup?token=…')`, then `pageSnapshot()` + `callPageAction()`, because `Livewire::test()` skips the gate and the setup runtime.
  - `Livewire::test()` is fine for pure rendering and validation.
- Facts verified for this plan (2026-10-13; vendor reads and a scratch build):
  - **Vite 8.3.4** builds a CSS-only entry to one file with:
    - `build.rolldownOptions.input: 'resources/css/setup.css'`
    - `rolldownOptions.output.assetFileNames: 'setup.css'`
    - `publicDir: false`, `manifest: false` and the `@tailwindcss/vite` plugin
    - Do **not** set `build.cssCodeSplit: false` (Vite then refuses a CSS input).
    - The output is deterministic: two builds gave the same sha1. With all Flux stubs as `@source` it is about 390 kB (38 kB gzip). `@import 'tailwindcss' source(none)` keeps Tailwind from scanning the whole project.
  - **php-fpm under Herd** runs as `…/Herd/bin/php84-fpm`. The CLI binary next to it is `…/Herd/bin/php84`, and `…/Herd/bin/php` is Herd's shim (DEC-23). PHP restores `putenv()` changes at the end of each request.
  - **Livewire 4.4:**
    - One update route (`default-livewire.update`, middleware `web` + `RequireLivewireHeaders`) serves every component.
    - `Livewire::addPersistentMiddleware([...])` re-applies the listed middleware of the page's original route on every update, after the `web` group.
    - `@livewireScripts`, `@livewireStyles`, `@fluxScripts` and `@fluxAppearance` are served through PHP routes, not `public/build`.
  - **Laravel's middleware sorting:** a middleware prepended to the `web` group and absent from the priority list stays ahead of `StartSession` (`bootstrap/app.php:30`, priority list `:36-41`).
  - **`IssueHandoff::handle(User $user, string $targetHost, string $path = '/dashboard'): string`** returns `Hosts::url($host, '/auth/handoff/'.$token)`.
    - The token is single-use, lives 60 s and is bound to the target host.
    - It deliberately skips the second factor.
    - It allows a super admin onto the admin host and a member onto `Hosts::homeOf($user)`.
  - **`DemoSeeder::accounts(?string $apex = null)`** returns `list<array{email, role, host}>`: `superadmin@{apex}` on `admin.{apex}`, then per `demo1` / `demo2` an `admin@` and a `user@`. `DemoSeeder::PASSWORD` is `'password'`.
  - **`.env.example`** ships `APP_ENV=local`, `APP_URL=http://localhost`, `SESSION_DRIVER=database`, `SESSION_COOKIE=app_session`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`.
  - **The kit requires `php ^8.4`** and ships Composer's platform check. Under PHP < 8.4 the app fails before the wizard renders.

## Scope

In scope (spec §11.2 M6 row: the parts M6a deferred, DEC-30 Consequence):

1. **Setup mode** (§5.3.4):
   - the one-time setup token, which `skv:bootstrap` writes (§5.2 points 5–6)
   - the setup runtime (file session, file cache, sync queue) before `StartSession`
   - the tenant resolver standing aside while setup mode is active
   - the `/` redirect to the wizard while no super admin exists
2. **The access gate:** local only, marker absent, no forwarded headers, the token checked with `hash_equals` and then kept in the (file) session.
3. **`layouts/setup` and `resources/setup/setup.css`:** the stylesheet is committed and built by `npm run build:setup`; CI checks it is current. The wizard renders without `public/build`.
4. **The wizard:**
   - the §5.3.2 questions pre-filled
   - one step per Livewire request
   - long steps detached through `skv:run-step` and polled
   - `manual()` commands with copy and *Re-check*
5. **The Herd stage:** the Herd step runs detached after the marker. A secret-bound status endpoint is polled (with a 60 s timeout), then the finish and summary page, then the single-use login (§5.3.4, DEC-23).
6. **The local quick login** for the demo accounts (§6.5, DEC-29 (19)).
7. **Engine changes the wizard needs:**
   - `SetupRunner::runStep()` and `finish()` (A12)
   - the `Answers` / `StepResult` state round trip (A11)
   - an atomic, validated `Marker` (A17)
   - the step-contract dataset (A25)
8. **DEC-30's open points:**
   - host validation and pinning (A4)
   - a Herd that hangs, and one `herd tld` rule (A5)
   - the doctor running before the Herd step (A6)
   - the test-database name (A7)
   - the HTTPS consequence without passkeys (A8, OD-2)
   - the secured host written to `APP_URL` (A20)
   - safe error messages (pgsql `DETAIL`)
   - child processes that no longer inherit `.env` secrets
9. **Tests:**
   - the §7.2 wizard tests and the browser tests (both database answers)
   - the §7.2 phase-1 tests for all four entry points, in the e2e job and `scripts/e2e-local.sh` (OD-3)
10. **The controller's real verification** on a throwaway Herd site, in both configurations (OD-4, §12 criterion 1).

Not in scope:
- **M7:** `/health-check`, the backups module, the System page, the CLI audit actor, passwords in argv, and the M3–M5 security-hardening follow-ups (limiters, Referer `back()`, passkey actions with the module off).
- **M8:** running the e2e and phase-1 jobs in CI (they need a remote), the release-check, publication, the weak-test and mutation lists of the M6a ledger, and the browser-suite Trace warning.
- `skv:install` in production (no guard is added; the wizard itself is local-only).

## Owner rulings

- **OD-1 = A:** `resources/setup/setup.css` is built by a second Vite config, `vite.setup.config.js`, with the installed `tailwindcss` / `@tailwindcss/vite` and Flux's CSS.
  - No new dependency.
  - `npm run build:setup` regenerates it; CI checks it is current.
- **OD-2 = A:** when HTTPS was chosen and the Herd step did not secure the site, the RL-7 consequence of DEC-30 (7) applies **whether or not passkeys are on**:
  - `APP_URL` stays http
  - the marker records `https: false`
  - passkeys, if they were on, are switched off
  - the summary says how to secure the site later
  - The doctor's `herd-secured` row then reports `skipped`, so §12 criterion 2 holds.
- **OD-3 = A:** the §7.2 phase-1 tests for the four entry points go into the e2e job and `scripts/e2e-local.sh`. The wizard is checked with `php artisan serve` plus `curl`. The controller runs the script locally once in scratch directories; CI runs the job from M8.
- **OD-4 = A:** the controller verifies the real wizard once per configuration on a throwaway parked site `~/Herd/m6b-e2e`, with Playwright, including a real detached `herd secure`:
  - PostgreSQL + Herd Pro + the super-admin start
  - SQLite + demo data + the quick login
  - Afterwards: `herd unsecure`, `herd unisolate` when isolated, `rm -rf` of the directory, and the databases `m6b_e2e` and `m6b_e2e_test` dropped.

## Rulings

- **RL-1 (setup mode):** `App\Setup\SetupMode::active()` is true only when **all** of these hold:
  - `app()->isLocal()`
  - the marker `storage/app/skv-installed` is absent
  - the setup token file exists

  Consequences:
  - The spec ties the resolver bypass to "marker absent" alone. The token condition keeps an existing local project that never had a marker (the owner's own `skv3.test` before M6) out of setup mode. Without a token the wizard is unreachable anyway, so bypassing the resolver there would serve nothing.
  - **Where the runtime applies.** In setup mode `App\Setup\Http\EnterSetupMode`, prepended to the `web` group ahead of `InitializeTenancy` and `StartSession`, sets `session.driver=file`, `cache.default=file` and `queue.default=sync`. It does this for **every** web request, not only the wizard's routes, because Livewire's single update endpoint carries only the `web` group. It also re-initialises the permission registrar's cache.
  - **The `setup` group.** §5.3.4's "dedicated `setup` middleware group" becomes this prepended middleware plus the gate as route middleware.
  - **The resolver.** `InitializeTenancy` skips resolution (forgets the tenant) while the request carries the `setup_mode` attribute.
- **RL-2 (the gate):** `App\Setup\Http\EnsureSetupAccess` is route middleware on the wizard and is registered as Livewire persistent middleware. It answers 404 unless all of these hold:
  - The request carries the `setup_mode` attribute.
  - No forwarded header is present: `X-Forwarded-For`, `X-Forwarded-Host`, `X-Forwarded-Proto`, `X-Original-Host`, `Forwarded`, or any header starting with `X-Expose-`. `X-Forwarded-Proto` and `Forwarded` are DEC-24's additions.
  - A valid token:
    - A `?token=` query that matches the file (`hash_equals`) stores `sha256(token)` in the session under `setup.token`. A GET is then redirected to the same path without the query, so the token leaves the address bar and the Referer.
    - Later requests need the session hash to `hash_equals` the file's sha256.
  - The request IP is not checked (§5.3.4).
  - The Livewire actions re-check the gate through `WizardRunner::authorise()` (the persistent middleware already runs on updates; the action check is the "authorise again" rule).
- **RL-3 (the host):** `Detector` receives the request host only through `App\Setup\SetupHost::forWizard(string $requestHost, ?string $herdTld, string $folder): ?string`. The result:
  - **With Herd:** the host must be `{folder}.{tld}` or `admin.{folder}.{tld}`, and the result is pinned to `{folder}.{tld}`. A tenant subdomain such as `demo1.myapp.test` is refused: after the marker the resolver runs again, a subdomain without an organisation answers 404, and the Herd stage could not finish there.
  - **Without Herd:** `localhost`, `127.0.0.1`, or a name ending in `.localhost` or `.test`, lower-cased and without the port.
  - Anything else makes the wizard show "Open the setup on :url." and refuse to start (A4, A19).
  - `SetupHost::normalise()` validates the label syntax (`[a-z0-9-]`, no leading or trailing hyphen, at most 253 characters).
- **RL-4 (execution):**
  - **One step per request.** Each wizard step is one Livewire action through `WizardRunner::advance()`, which calls `SetupRunner::runStep()` for the first of steps 1–8 without a stored result.
  - **Strict mode and timeouts.** Every action runs inside `Tenant::bypass()` (strict mode: the doctor's checks and the seeders query tenant models; `App\Setup` may bypass) with `set_time_limit(300)`.
  - **Long steps.** A step with `isLong()` (frontend, Claude) starts as `nohup … php artisan skv:run-step <key> --result=<file>` through `App\Setup\Wizard\DetachedStepLauncher`. The page polls with `wire:poll.2s`.
  - **No queue.** The wizard never uses the queue.
  - **`skv:run-step`** is a hidden command. It rebuilds the context from the wizard state (or, for the Herd step, from the Herd state) and writes the step's result as JSON. It never writes the wizard state itself, so it cannot race the page.
- **RL-5 (state files)**, all written atomically with mode 0600 and named by constants:
  - `SetupToken::PATH` = `storage/app/private/skv-setup-token`: 64 hex characters.
  - `WizardState::PATH` = `storage/app/private/skv-setup.json`:
    - holds the pinned host, the answers state (`Answers::toState()`: the public answers plus the password hash, never the plaintext), the stored results and the notes
    - step 9 deletes it
  - `WizardState::STEPS` = `storage/app/private/skv-setup/`: per long step, `{key}.json` (the result), `{key}.log` and `{key}.exit`. Step 9 deletes the directory.
  - `HerdState::PATH` = `storage/app/private/skv-herd.json`: the sha256 of the Herd-stage secret, the host, the public answers, the results and notes of steps 1–9, `started_at`, and once finished the Herd step's result.
  - `HerdState::EXIT` = `storage/app/private/skv-herd.exit`.
  - `HerdState::LOG` = `storage/logs/skv-herd.log`.
  - The Marker becomes atomic and 0600 too (A17, A22).
- **RL-6 (the Herd stage):** `WizardRunner::finish()` does three things:
  1. It runs step 9 (the marker) in-process.
  2. It writes the Herd state with a fresh 32-byte secret (only the hash is stored).
  3. It launches step 10 detached, and returns the secret to the page.

  After that the page makes no Livewire request (the gate closes with the marker).
  - **Polling.** Alpine polls `GET /welcome-setup/herd-status?secret=…` every 2 s with `redirect: 'manual'`.
    - `{"state":"done"}` means navigate to `/welcome-setup/finish?secret=…` on the same origin.
    - An `opaqueredirect` means Herd now 301s http to https, so navigate to the same path on `https://` + the host.
    - After 60 s, navigate to `/welcome-setup/finish?secret=…&timeout=1`.
  - **`setup.finish`** (GET) shows one of:
    - a "finishing" page with `<meta http-equiv="refresh" content="2">` while the Herd step has no result
    - a manual page while it timed out: `herd secure <name>` plus "Continue without HTTPS", which applies the consequence of OD-2 / RL-7 through `SetupRunner::applyHttpsConsequence()` and rewrites the marker
    - otherwise the summary from the marker
  - **`setup.login`** (GET) issues the handoff for the installed super admin to `Hosts::admin()` and redirects to it. For a demo start it redirects to the login page on the apex. Either way it deletes the Herd state, which makes the secret single-use.
  - **All three endpoints:**
    - local only, no forwarded headers
    - the secret checked with `hash_equals` against the stored hash
    - a Herd state at most 30 minutes old, else 404
  - **Why not a signed URL.** This replaces §5.3.4's "single-use signed login URL". A `URL::signedRoute` would not survive the origin change and would not be bound to the wizard's browser. The handoff token is single-use.
- **RL-7 (isolate):**
  - The wizard renders only under PHP ≥ 8.4 (platform check). So the site already runs a supported PHP when the Herd step starts from the wizard, and the step's isolate branch never fires there. No browser mode is needed (A14).
  - The CLI keeps link → isolate → secure (DEC-30 (31)).
- **RL-8 (doctor in step 8, A6):**
  - The doctor runs with the checks `herd-site`, `herd-secured` and `setup-token` deferred, reported as `skipped` ("Checked after the Herd step"), because steps 9–10 have not run yet.
  - `DoctorContext::fromSetup()` synthesises a marker from the context's answers when none exists, so the marker-gated rows run on the answers being installed.
- **RL-9 (quick login):**
  - **When it shows.** `App\Setup\QuickLogin::accounts()` lists `DemoSeeder::accounts()` on the login page only when all of these hold:
    - `app()->isLocal()`
    - the marker exists with `start_with=demo`
    - the account exists, is active and has no confirmed two-factor authentication
  - **The request.** `POST /quick-login` (`quick-login`, `throttle:10,1`) re-checks all of that plus `Hash::check(DemoSeeder::PASSWORD, …)`, so an account whose password changed stops working. It then redirects to `IssueHandoff::handle($user, $account['host'])`.
  - **The 404 rule.** Every refusal is a 404, except a changed password, which goes back to the login page with a status.
- **RL-10 (bootstrap output):** `skv:bootstrap` writes the token when the marker is absent and prints "Open :url to finish setting up in the browser, or run php artisan skv:install." with the `APP_URL` from `.env`. It does not print `/welcome-setup`: without the token that URL is a 404, and `/` hands the token over.
- **RL-11 (phase-1 clone route):**
  - The "git clone + composer setup" variant runs the commands of `composer setup` **except** the final `@php artisan skv:install`: `composer install`, the `.env` copy, `skv:bootstrap`. It then checks the wizard, because `composer setup` ends in the CLI by design (§5.3.3).
  - A tooling test pins that those commands are the prefix of `composer setup`.
- **RL-12 (child environments):**
  - `Toolchain::__construct(?string $home = null, ?string $basePath = null)`. With a base path, `environment()` also sets `false` (unset) for every key of that project's `.env` except `HOME`, `PATH`, `USER`, `LOGNAME`, `SHELL`, `TMPDIR`, `LANG`, `TERM` and `LC_*`.
  - The result: `npm ci` and its postinstall scripts, `claude` and the detached `skv:run-step` no longer inherit `APP_KEY` or `DB_PASSWORD`, and a child `php artisan` reads the current `.env` instead of the values its parent booted with.
  - The container binding passes `base_path()`; tests construct toolchains without one.
- **RL-13 (safe messages):** `App\Setup\Support\SafeMessage::of(Throwable $exception): string` returns:
  - for a `QueryException`, the previous PDO message cut before its first `DETAIL:` / `HINT:` line, because PostgreSQL's `DETAIL: Failing row contains (…)` can hold the password hash
  - otherwise the message, with every line after the first dropped when the exception is a `ProcessFailedException`

  The CLI escapes it with `OutputFormatter::escape()`; Blade escapes it with `{{ }}`.
- **RL-14 (test database name, A7):** `scripts/create-pgsql-test-database.php --print-name` prints the sanitised `<db>_test`, using `DatabaseStep::databaseName()`'s rule (lower case, runs of `[^a-z0-9_]` to `_`, trimmed, at most 50 characters). `composer test:pgsql` uses it, so `TestDatabaseCheck`, the Database step and the script agree for a folder like `my-app`.
- **RL-15 (the stylesheet):**
  - `resources/css/theme.css` holds the kit's `@custom-variant`, `@theme`, accent presets and focus ring, moved out of `app.css`. `app.css` imports it.
  - `resources/css/setup.css` is the setup source:
    - `@import 'tailwindcss' source(none)`, then Flux's CSS, then `theme.css`
    - `@source` for the setup views and Flux's stubs
    - a system font stack (no `@fonts`)
  - `vite.setup.config.js` writes `resources/setup/setup.css`, which is committed. `layouts/setup` inlines it.
- **RL-16 (Herd step `APP_URL`, A20):** after securing, the Herd step writes `APP_URL=https://{folder}.{tld}`, the name it secured, instead of `https://` plus the detected host.

## Requirement traceability

| Requirement | Task |
|---|---|
| A11 state round trip, A12 `runStep()`/`finish()`, A17 atomic marker, A25 step-contract dataset | 1 |
| OD-2 HTTPS consequence, A20 secured host, A6 doctor ordering, A7 test-database name | 2 |
| A15 validation reuse, RL-13 safe messages, preflight shared with the CLI, error names the running step | 3 |
| A4/A19 host validation and pinning (RL-3), A5/A18 Herd timeouts and one tld rule, RL-12 child environments | 4 |
| §5.2 points 5–6 token (RL-10), RL-1 setup mode and runtime, resolver bypass, `/` redirect for direct requests only, the doctor checking the project's drivers, `useSetupWizard()` | 5 |
| §5.3.4 no Vite dependency: `layouts/setup`, `setup.css`, `build:setup`, CI check (OD-1, RL-15) | 6 |
| §5.3.4 access gate (RL-2), routes, §6.2 matrix row `/welcome-setup` | 7 |
| RL-4/RL-5 wizard state, `StepLauncher`, `skv:run-step`, narrowed name-leak allow-list | 8 |
| `WizardRunner` (begin, advance, recheck, finish) | 9 |
| The wizard page (questions, steps, polling, manual + re-check, the review before step 9) and §7.2 wizard tests | 10 |
| RL-6 Herd stage, status, finish, summary, login handoff | 11 |
| §6.5 local quick login (RL-9) | 12 |
| §7.2 browser tests: setup wizard (both database answers), quick login | 13 |
| §7.2 phase-1 tests for the four entry points (OD-3, RL-11) | 14 |
| §10 docs, DEC-31, guideline, CHANGELOG, handleiding | 15 |
| Gate, phase-1 local runs, throwaway Herd site in both configurations (OD-4) | 16 |
| DEC-30 open point: `claude plugin install --scope project` with a project-only marketplace | 16 |

## File map

- `app/Setup/`:
  - **Modify:** `Answers.php` (`toState`, `fromState`), `StepResult.php` (`toArray`, `fromArray`), `Marker.php` (atomic, validated), `SetupRunner.php` (`runStep`, `finish`, OD-2), `Detector.php` (host, timeouts), `Steps/HerdSiteStep.php` (RL-16), `Steps/DoctorStep.php`, `Steps/MarkerStep.php` (constants), `Doctor/Doctor.php` (deferred checks), `Doctor/DoctorContext.php` (synthesised marker), `Doctor/Checks/SetupTokenCheck.php` (constant), `Doctor/Checks/{SessionDriverCheck,QueueCheck}.php` (the replaced drivers), `Support/Bootstrap.php` (token, `HerdTld`), `Support/Toolchain.php` (RL-12, `phpCli()`)
  - **Create:**
    - `InstallPreflight.php`, `SetupHost.php`, `SetupMode.php`, `SetupToken.php`, `QuickLogin.php`
    - `Support/SafeMessage.php`, `Support/HerdTld.php`, `Support/StateFile.php`
    - `Http/EnterSetupMode.php`, `Http/DirectRequest.php`, `Http/EnsureSetupAccess.php`
    - `Wizard/WizardState.php`, `Wizard/HerdState.php`, `Wizard/StepLauncher.php`, `Wizard/DetachedStepLauncher.php`, `Wizard/WizardRunner.php`, `Wizard/HerdStage.php`
- `app/Console/Commands/`:
  - Modify: `InstallCommand.php`, `BootstrapCommand.php`
  - Create: `RunStepCommand.php`
- `app/Http/Controllers/`: Create `Setup/SetupFinishController.php`, `Auth/QuickLoginController.php`.
- `app/Tenancy/Http/Middleware/InitializeTenancy.php`: Modify (setup-mode skip).
- `app/Actions/Auth/IssueHandoff.php`: Modify (docblock: the two local-only callers).
- `app/Providers/AppServiceProvider.php`: Modify (bindings, persistent middleware).
- `bootstrap/app.php`: Modify (prepend `EnterSetupMode`).
- `routes/web.php` (`/` redirect, `require setup.php`, quick login): Modify. `routes/setup.php`: Create.
- `resources/views/`:
  - Create:
    - `layouts/setup.blade.php`
    - `pages/setup/wizard/{wizard.php,wizard.blade.php}`
    - `setup/finish.blade.php`, `setup/finishing.blade.php`, `setup/manual.blade.php`
    - `components/quick-login.blade.php`
  - Modify: `pages/auth/login.blade.php`.
- `resources/css/`:
  - Create `theme.css`, `setup.css`; Modify `app.css`.
- `resources/setup/setup.css`: generated and committed. `vite.setup.config.js`: Create. `package.json`: Modify (`build:setup`). `.gitattributes`: Modify (`resources/setup/setup.css linguist-generated=true -diff`).
- `scripts/create-pgsql-test-database.php`, `scripts/e2e-local.sh`, `composer.json`, `.github/workflows/tests.yml`: Modify.
- `lang/nl.json`, `lang/en.json`: Modify.
- `tests/`:
  - `Pest.php` (`useSetupWizard`)
  - `Fixtures/InlineStepLauncher.php`
  - `Feature/Setup/**`, `Feature/Console/RunStepCommandTest.php`
  - `Feature/Setup/Wizard/*Test.php`
  - `Feature/Auth/QuickLoginTest.php`
  - `Browser/SetupWizardTest.php`, `Browser/QuickLoginTest.php`
  - updates to `Arch/NameLeakTest.php`, `Feature/Layouts/BaseLayoutTest.php`, `Feature/Tenancy/RouteHostMatrixTest.php`, `Feature/Tooling/{CiWorkflow,ComposerScripts,PgsqlTestDatabaseScript}Test.php`
- `docs/decisions.md` (DEC-31), `README.md`, `CHANGELOG.md`, `.ai/guidelines/skv3.md`, `docs/handleiding.html`: Modify.

---

### Task 1: The state round trip, an atomic marker and the per-step runner (A11, A12, A17, A25)

**Files:**
- Create: `app/Setup/Support/StateFile.php`
- Modify: `app/Setup/Answers.php`, `app/Setup/StepResult.php`, `app/Setup/Marker.php`, `app/Setup/SetupRunner.php`
- Test: `tests/Feature/Setup/AnswersTest.php`, `tests/Feature/Setup/StepResultTest.php`, `tests/Feature/Setup/MarkerTest.php`, `tests/Feature/Setup/SetupRunnerTest.php`, `tests/Feature/Setup/Support/StateFileTest.php`

**Interfaces:**
- Consumes: M6a's `Answers`, `StepResult`, `Marker`, `SetupRunner`, `SetupContext`.
- Produces:
  - `StateFile::writeJson(string $path, array<string, mixed> $data): void` and `StateFile::write(string $path, string $contents): void`. Both write atomically: temp file in the same directory, mode 0600, `rename()`. They create the directory with mode 0700.
  - `StateFile::readJson(string $path): ?array<string, mixed>`: null when absent. It throws `JsonException` when the file is not a JSON object.
  - `Answers::toState(): array<string, mixed>`: `toPublicArray()` plus `super_admin_password_hash`.
  - `Answers::fromState(array<string, mixed> $state): self`: trusted input from `toState()`. It throws `InvalidArgumentException` on a missing or wrongly typed key.
  - `StepResult::toArray(): array{status: string, message: string, command: ?string}` and `StepResult::fromArray(array<string, mixed>): self`. The latter throws `InvalidArgumentException` on an unknown status.
  - `SetupRunner::runStep(SetupContext $context, SetupStep $step, ?Closure $around = null): StepResult`.
  - `SetupRunner::finish(SetupContext $context, ?array<string, string> $environmentBefore): void`.

- [ ] **Step 1: Create the branch and commit the plan**

```bash
git switch -c m6b-setup-wizard
git add docs/superpowers/plans/2026-10-13-m6b-setup-wizard.md
git commit -m "docs: add the M6b setup wizard implementation plan

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

(If the controller already did this, skip the step.)

- [ ] **Step 2: Write the failing tests**

`tests/Feature/Setup/Support/StateFileTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.4 */

use App\Setup\Support\StateFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->directory = storage_path('framework/testing/setup/state-'.Str::uuid());
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

it('writes JSON atomically with mode 0600 and reads it back (RL-5)', function (): void {
    $path = $this->directory.'/nested/state.json';

    StateFile::writeJson($path, ['a' => 1]);

    expect(StateFile::readJson($path))->toBe(['a' => 1])
        ->and(fileperms($path) & 0777)->toBe(0600)
        ->and(fileperms(dirname($path)) & 0777)->toBe(0700)
        ->and(glob(dirname($path).'/.state-*'))->toBe([]);
});

it('reads null for a missing file and refuses JSON that is not an object (RL-5)', function (string $contents): void {
    $path = $this->directory.'/state.json';
    File::ensureDirectoryExists($this->directory);

    expect(StateFile::readJson($path))->toBeNull();

    file_put_contents($path, $contents);

    expect(fn (): ?array => StateFile::readJson($path))->toThrow(JsonException::class);
})->with(['truncated' => ['{"a":'], 'null' => ['null'], 'list' => ['[1,2]'], 'string' => ['"x"']]);

it('replaces a symlink instead of writing through it (RL-5)', function (): void {
    File::ensureDirectoryExists($this->directory);
    $target = $this->directory.'/elsewhere.json';
    file_put_contents($target, 'keep');
    symlink($target, $this->directory.'/state.json');

    StateFile::writeJson($this->directory.'/state.json', ['a' => 1]);

    expect(file_get_contents($target))->toBe('keep')
        ->and(is_link($this->directory.'/state.json'))->toBeFalse();
});
```

Append to `tests/Feature/Setup/AnswersTest.php`:

```php
it('round-trips its state with the password hash, never the password (A11)', function (): void {
    $answers = Answers::fromArray(
        ['start_with' => 'super_admin', 'super_admin_email' => 'Owner@Example.com', 'super_admin_password' => 'correct-horse-battery', 'modules' => ['impersonation']],
        Detection::none(),
        'my-app',
    );

    $state = $answers->toState();
    $restored = Answers::fromState(json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));

    expect($state)->not->toHaveKey('super_admin_password')
        ->and(json_encode($state, JSON_THROW_ON_ERROR))->not->toContain('correct-horse-battery')
        ->and(Hash::check('correct-horse-battery', (string) $state['super_admin_password_hash']))->toBeTrue()
        ->and($restored)->toEqual($answers);
});

it('refuses a state with a missing or wrongly typed key (A11)', function (string $key, mixed $value): void {
    $state = Answers::defaults(Detection::none(), 'my-app')->toState();
    $state[$key] = $value;

    expect(fn (): Answers => Answers::fromState($state))->toThrow(InvalidArgumentException::class);
})->with([
    'database' => ['database', 'mysql'],
    'modules' => ['modules', 'passkeys'],
    'https' => ['https', 'yes'],
    'start_with' => ['start_with', null],
]);
```

(Add `use Illuminate\Support\Facades\Hash;` to the file's imports when it is missing.)

Append to `tests/Feature/Setup/StepResultTest.php`:

```php
it('round-trips through an array (A11)', function (StepResult $result): void {
    expect(StepResult::fromArray($result->toArray()))->toEqual($result);
})->with([
    'done' => [StepResult::done('ok')],
    'skipped' => [StepResult::skipped('why')],
    'manual' => [StepResult::manual('herd secure x', 'not confirmed')],
]);

it('refuses an unknown status (A11)', function (): void {
    expect(fn (): StepResult => StepResult::fromArray(['status' => 'later', 'message' => '', 'command' => null]))
        ->toThrow(InvalidArgumentException::class);
});
```

Append to `tests/Feature/Setup/MarkerTest.php` (it already starts with the process-faking `beforeEach`):

```php
it('writes the marker atomically with mode 0600 (A17, A22)', function (): void {
    $context = setupSandbox();

    Marker::write($context, ['urls' => []]);

    expect(fileperms($context->path(Marker::FILE)) & 0777)->toBe(0600)
        ->and(Marker::read($context)['summary'] ?? null)->toBe(['urls' => []]);
});

it('throws JsonException for a marker that is not a JSON object (A17)', function (string $contents): void {
    $context = setupSandbox();
    File::ensureDirectoryExists(dirname($context->path(Marker::FILE)));
    file_put_contents($context->path(Marker::FILE), $contents);

    expect(fn (): ?array => Marker::read($context))->toThrow(JsonException::class);
})->with(['truncated' => ['{"answers":'], 'null' => ['null']]);
```

Append to `tests/Feature/Setup/SetupRunnerTest.php` (it keeps its `$fakeStep` closure, which returns `Tests\Fixtures\RecordingSetupStep`):

```php
it('runs one step at a time with the same rules as a full run (A12)', function () use ($fakeStep): void {
    $context = setupSandbox();
    $ran = new ArrayObject;
    $runner = new SetupRunner;

    $skipped = $runner->runStep($context, $fakeStep('a', $ran, complete: true));
    $done = $runner->runStep($context, $fakeStep('b', $ran));

    expect($skipped->status)->toBe('skipped')
        ->and($done->status)->toBe('done')
        ->and($ran->getArrayCopy())->toBe(['b'])
        ->and(array_keys($context->results))->toBe(['a', 'b']);
});

it('hands the step to the wrapper before it checks completeness, so a throwing check is named (A12)', function (): void {
    $context = setupSandbox();
    $named = null;
    $step = new ThrowingCompletenessStep;

    // A plain closure: an arrow function would capture $named by value, so the wrapper's write would never arrive.
    $run = function () use ($step, $context, &$named): void {
        new SetupRunner([$step])->run($context, function (SetupStep $current, Closure $execute) use (&$named): StepResult {
            $named = $current->key();

            return $execute();
        });
    };

    expect($run)->toThrow(RuntimeException::class);
    expect($named)->toBe('throws-on-check');
});

it('declares which steps run detached in the browser (A25, §5.3.1)', function (string $key, bool $long): void {
    $steps = collect(app(SetupRunner::class)->steps())->keyBy(fn (SetupStep $step): string => $step->key());

    expect($steps[$key]->isLong())->toBe($long);
})->with([
    ['environment', false], ['database', false], ['herd-manifest', false], ['branding', false], ['frontend', true],
    ['git', false], ['claude', true], ['doctor', false], ['marker', false], ['herd-site', false], ['summary', false],
]);
```

Add `use Tests\Fixtures\ThrowingCompletenessStep;` (plus `use App\Setup\SetupStep;` and `use App\Setup\StepResult;` if missing) to the file's imports: `TestFileClassesTest` forbids qualified names outside the imports.

Create the fixture `tests/Fixtures/ThrowingCompletenessStep.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Setup\SetupContext;
use App\Setup\SetupStep;
use App\Setup\StepResult;
use RuntimeException;

/** A step whose completeness check throws, to pin which step a failure is reported under. */
final readonly class ThrowingCompletenessStep implements SetupStep
{
    public function key(): string
    {
        return 'throws-on-check';
    }

    public function label(): string
    {
        return 'Throws on check';
    }

    public function isComplete(SetupContext $context): bool
    {
        throw new RuntimeException('check failed');
    }

    public function isLong(): bool
    {
        return false;
    }

    public function run(SetupContext $context): StepResult
    {
        return StepResult::done();
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Setup/Support/StateFileTest.php tests/Feature/Setup/AnswersTest.php tests/Feature/Setup/StepResultTest.php tests/Feature/Setup/MarkerTest.php tests/Feature/Setup/SetupRunnerTest.php`

Expected: FAIL. Neither `StateFile` nor `toState`, `fromArray` or `runStep` exists; the marker is 0644; the wrapper names nothing because `isComplete()` throws before `around()` runs.

- [ ] **Step 4: Implement**

`app/Setup/Support/StateFile.php`:

```php
<?php

declare(strict_types=1);

namespace App\Setup\Support;

use JsonException;
use RuntimeException;

/**
 * The setup's small state files (RL-5): written atomically (a temp file in the same directory, then rename(), which
 * replaces a symlink instead of following it) with mode 0600, and read back as JSON objects.
 */
final class StateFile
{
    /** @param  array<string, mixed>  $data */
    public static function writeJson(string $path, array $data): void
    {
        self::write($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    public static function write(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException(__('Could not create :directory.', ['directory' => basename($directory)]));
        }

        $temporary = tempnam($directory, '.state-');

        if ($temporary === false) {
            throw new RuntimeException(__('Could not write :file.', ['file' => basename($path)]));
        }

        chmod($temporary, 0600);

        if (file_put_contents($temporary, $contents) === false || ! rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException(__('Could not write :file.', ['file' => basename($path)]));
        }
    }

    /**
     * @return array<string, mixed>|null null when the file does not exist
     *
     * @throws JsonException when the file is not a JSON object
     */
    public static function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new JsonException(__(':file is not a JSON object.', ['file' => basename($path)]));
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
```

`Answers` additions. Place them after `toPublicArray()`, ordered as Pint's `ordered_class_elements` wants: static constructors before instance methods. So `fromState()` goes next to `fromArray()`, and `toState()` next to `toPublicArray()`.

```php
    /**
     * The answers as the wizard stored them (RL-5): trusted input written by toState(), so it is checked for shape,
     * not validated again. The password hash stays a hash.
     *
     * @param  array<string, mixed>  $state
     *
     * @throws InvalidArgumentException when a key is missing or has the wrong type
     */
    public static function fromState(array $state): self
    {
        $string = fn (string $key): string => is_string($state[$key] ?? null) ? $state[$key] : throw new InvalidArgumentException("Setup state: {$key} must be a string.");
        $nullableString = fn (string $key): ?string => ($state[$key] ?? null) === null || is_string($state[$key]) ? ($state[$key] ?? null) : throw new InvalidArgumentException("Setup state: {$key} must be a string or null.");
        $bool = fn (string $key): bool => is_bool($state[$key] ?? null) ? $state[$key] : throw new InvalidArgumentException("Setup state: {$key} must be a boolean.");
        $modules = $state['modules'] ?? null;

        if (! is_array($modules) || ! array_is_list($modules) || array_diff($modules, self::MODULES) !== []) {
            throw new InvalidArgumentException('Setup state: modules must be a list of known modules.');
        }

        $database = $string('database');
        $startWith = $string('start_with');

        if (! in_array($database, ['pgsql', 'sqlite'], true) || ! in_array($startWith, ['demo', 'super_admin'], true)) {
            throw new InvalidArgumentException('Setup state: unknown database or start.');
        }

        /** @var list<string> $modules */
        return new self(
            appName: $string('app_name'),
            database: $database,
            startWith: $startWith,
            superAdminEmail: $nullableString('super_admin_email'),
            superAdminPasswordHash: $nullableString('super_admin_password_hash'),
            modules: $modules,
            https: $bool('https'),
            playwright: $bool('playwright'),
            claude: $bool('claude'),
        );
    }

    /** @return array<string, mixed> the public answers plus the password hash, for the wizard's 0600 state file only (RL-5) */
    public function toState(): array
    {
        return [...$this->toPublicArray(), 'super_admin_password_hash' => $this->superAdminPasswordHash];
    }
```

(Import `InvalidArgumentException`.)

`StepResult` additions:

```php
    /**
     * @param  array<string, mixed>  $data  a toArray() result
     *
     * @throws InvalidArgumentException on an unknown status or a malformed message
     */
    public static function fromArray(array $data): self
    {
        $message = is_string($data['message'] ?? null) ? $data['message'] : throw new InvalidArgumentException('Step result: message must be a string.');

        return match ($data['status'] ?? null) {
            'done' => self::done($message),
            'skipped' => self::skipped($message),
            'manual' => self::manual(is_string($data['command'] ?? null) ? $data['command'] : throw new InvalidArgumentException('Step result: a manual result needs a command.'), $message),
            default => throw new InvalidArgumentException('Step result: unknown status.'),
        };
    }

    /** @return array{status: string, message: string, command: ?string} */
    public function toArray(): array
    {
        return ['status' => $this->status, 'message' => $this->message, 'command' => $this->command];
    }
```

`Marker`:
- `write()` builds the same array and calls `StateFile::writeJson($context->path(self::FILE), [...])` instead of `@mkdir` and `file_put_contents`.
- `read()` returns `StateFile::readJson($context->path(self::FILE))`. Keep the `@var` shape on a local, then return it.
- `installedAt()` catches `JsonException` and returns null.

`SetupRunner`: replace the loop body of `run()` with `runStep()`, and move the post-loop part into `finish()`:

```php
    /** @param  (Closure(SetupStep, Closure(): StepResult): StepResult)|null  $around  wraps each step (the CLI's task() spinner) */
    public function run(SetupContext $context, ?Closure $around = null): SetupContext
    {
        $before = Marker::exists($context) ? new EnvFile($context->path('.env'))->all() : null;
        $context->syncRuntimeConfig();

        foreach ($this->steps() as $step) {
            $this->runStep($context, $step, $around);
        }

        $this->finish($context, $before);

        return $context;
    }

    /**
     * One step under the runner's rules (RL-6, A12): skipped when complete unless forced; after the Herd step has run,
     * the HTTPS consequence; then the runtime config follows .env. The wrapper sees the step before its completeness
     * check, so a check that throws is reported under the right step. The browser wizard calls this once per request.
     *
     * @param  (Closure(SetupStep, Closure(): StepResult): StepResult)|null  $around
     */
    public function runStep(SetupContext $context, SetupStep $step, ?Closure $around = null): StepResult
    {
        $wasComplete = false;
        $execute = function () use ($context, $step, &$wasComplete): StepResult {
            $wasComplete = ! $context->force && $step->isComplete($context);

            return $wasComplete ? StepResult::skipped(__('Already done')) : $step->run($context);
        };

        $result = $around instanceof Closure ? $around($step, $execute) : $execute();
        $context->results[$step->key()] = $result;

        // A Herd step skipped as complete found the site secured, so a resumed install never turns passkeys off.
        if ($step->key() === 'herd-site' && ! $wasComplete) {
            $this->applyHttpsConsequence($context);
        }

        $context->syncRuntimeConfig();

        return $result;
    }

    /**
     * The end of a run: a re-run names the .env keys it changed (never their values), and an existing marker is
     * rewritten with the final answers and summary (DEC-30 (14), (29)).
     *
     * @param  array<string, string>|null  $environmentBefore  .env before the run, when a marker existed
     */
    public function finish(SetupContext $context, ?array $environmentBefore): void
    {
        if ($environmentBefore !== null) {
            $after = new EnvFile($context->path('.env'))->all();
            $changed = array_keys(array_diff_assoc($after, $environmentBefore) + array_diff_assoc($environmentBefore, $after));

            if ($changed !== []) {
                $context->notes[] = __('Setup changed :keys in .env.', ['keys' => implode(', ', $changed)]);
            }
        }

        if (Marker::exists($context)) {
            Marker::write($context, Summary::build($context));
        }
    }
```

Update the class docblock's last sentence to: "The CLI runs the whole list; the browser wizard calls runStep() once per request and finish() at the end."

Check `InstallCommand::around()`: it sets `$current = $step` before calling `$execute()`, which now runs the completeness check. The command needs no change for that fix.

New lang keys:
- `Could not create :directory.` = "Kon :directory niet aanmaken."
- `Could not write :file.` = "Kon :file niet schrijven."
- `:file is not a JSON object.` = ":file is geen JSON-object."

- [ ] **Step 5: Run the tests to verify they pass**

Run: the command of Step 3, then `php artisan test --compact tests/Feature/Setup tests/Feature/Console`.

Expected: PASS.

- [ ] **Step 6: Gate and commit**

Run the gate (Global Constraints), then:

```bash
git add app/Setup tests/Feature/Setup tests/Fixtures lang
git commit -m "feat: run setup steps one at a time and round-trip the wizard state

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: The HTTPS consequence without passkeys, the secured host, the doctor's order and the test-database name (OD-2, A20, A6, A7)

**Files:**
- Modify: `app/Setup/SetupRunner.php`, `app/Setup/Steps/HerdSiteStep.php`, `app/Setup/Steps/DoctorStep.php`, `app/Setup/Doctor/Doctor.php`, `app/Setup/Doctor/DoctorContext.php`, `scripts/create-pgsql-test-database.php`, `composer.json`
- Test: `tests/Feature/Setup/SetupRunnerTest.php`, `tests/Feature/Setup/Steps/HerdSiteStepTest.php`, `tests/Feature/Setup/Steps/DoctorStepTest.php` (new), `tests/Feature/Setup/Doctor/DoctorTest.php`, `tests/Feature/Tooling/PgsqlTestDatabaseNameTest.php` (new), `tests/Feature/Tooling/ComposerScriptsTest.php`

**Interfaces:**
- Consumes: Task 1's `runStep()`.
- Produces:
  - `Doctor::run(DoctorContext $context, bool $fix = false, array $deferred = []): array<string, CheckResult>`. `$deferred` is a list of check keys reported as `skipped(__('Checked after the Herd step'))`.
  - `DoctorStep::DEFERRED = ['herd-site', 'herd-secured', 'setup-token']`.
  - `scripts/create-pgsql-test-database.php --print-name`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Setup/SetupRunnerTest.php`:

```php
it('records an unsecured site as http even without passkeys (OD-2)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['modules' => ['impersonation'], 'https' => true], Detection::none(), $context->folderName());
    $context->results['herd-site'] = StepResult::manual('herd secure x', 'not confirmed');
    Marker::write($context, []);

    new SetupRunner([])->applyHttpsConsequence($context);

    expect($context->answers->https)->toBeFalse()
        ->and(Marker::read($context)['answers']['https'] ?? null)->toBeFalse()
        ->and(new EnvFile($context->path('.env'))->get('MODULE_PASSKEYS'))->not->toBe('false')
        ->and(implode(' ', $context->notes))->toContain('herd secure '.$context->folderName());
});

it('keeps https when the site was secured (OD-2)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['modules' => ['impersonation'], 'https' => true], Detection::none(), $context->folderName());
    $context->results['herd-site'] = StepResult::done(HerdSiteStep::SECURED.': Site secured with HTTPS');

    new SetupRunner([])->applyHttpsConsequence($context);

    expect($context->answers->https)->toBeTrue()->and($context->notes)->toBe([]);
});
```

(Import `App\Setup\Steps\HerdSiteStep` and `App\Setup\Support\EnvFile` if they are not imported yet.)

Append to `tests/Feature/Setup/Steps/HerdSiteStepTest.php`, next to its existing secure test. Reuse that test's stubs and fakes, and change only the detection host:

```php
it('writes the name it secured into APP_URL, not the request host (A20, RL-16)', function (): void {
    // Arrange: copy the existing "secures" test's arrange part, with the detected host 'admin.<folder>.test'.
    // Act: run the step.
    // Assert: APP_URL is 'https://<folder>.test'.
});
```

Copy the arrange part of the file's existing "secures" test into the body above (its `Process::fake([...])` handlers for `links`, `paths`, `isolated`, `secured`, `secure` and its stub toolchain), with `Detection` host `'admin.'.$context->folderName().'.test'`. Then assert:

```php
    expect(new EnvFile($context->path('.env'))->get('APP_URL'))->toBe('https://'.$context->folderName().'.test');
```

`tests/Feature/Setup/Steps/DoctorStepTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.3, §5.4 */

use App\Setup\Answers;
use App\Setup\Detection;
use App\Enums\CheckStatus;
use App\Setup\Doctor\Doctor;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Steps\DoctorStep;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

it('defers the Herd and token checks to after the Herd step (A6, RL-8)', function (): void {
    expect(DoctorStep::DEFERRED)->toBe(['herd-site', 'herd-secured', 'setup-token']);

    $context = setupSandbox(new Detection(herd: true, herdPro: false, herdMail: false, herdSmtpPort: null, postgres: false, fluxPro: false, claude: false, host: 'x.test', scheme: 'http'));
    $results = app(Doctor::class)->run(DoctorContext::fromSetup($context, app(Toolchain::class)), deferred: DoctorStep::DEFERRED);

    foreach (DoctorStep::DEFERRED as $key) {
        expect($results[$key]->status)->toBe(CheckStatus::Skipped)
            ->and($results[$key]->message)->toBe(__('Checked after the Herd step'));
    }
});

it('checks the answers being installed when there is no marker yet (A6, RL-8)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['claude' => true], Detection::none(), $context->folderName());

    $doctor = DoctorContext::fromSetup($context, app(Toolchain::class));

    expect($doctor->answer('claude'))->toBeTrue()
        ->and($doctor->marker['start_with'] ?? null)->toBe('demo');
});
```

Create `tests/Feature/Tooling/PgsqlTestDatabaseNameTest.php` (see the note below the block for its header):

```php
it('names the test database like the Database step does (A7, RL-14)', function (string $folder): void {
    $context = setupSandbox();
    $expected = DatabaseStep::databaseName(new SetupContext(dirname($context->basePath).'/'.$folder, $context->answers, $context->detection)).'_test';

    $printed = Process::path(base_path())->env(['PWD' => '/tmp/'.$folder, 'DB_TEST_DATABASE' => false])
        ->run([PHP_BINARY, 'scripts/create-pgsql-test-database.php', '--print-name']);

    expect(trim($printed->output()))->toBe($expected);
})->with(['plain' => ['myapp3'], 'hyphen' => ['my-app'], 'case and dots' => ['My.App 2']]);
```

**Put this dataset in a new file, `tests/Feature/Tooling/PgsqlTestDatabaseNameTest.php`** (docblock `/** covers §7.4 */`), with no process fakes: it must run the real script. A fake would prove nothing. Import `App\Setup\SetupContext`, `App\Setup\Steps\DatabaseStep` and `Illuminate\Support\Facades\Process`.

Append to `tests/Feature/Tooling/ComposerScriptsTest.php`:

```php
it('runs the pgsql suite on the sanitised test database (A7, RL-14)', function (): void {
    /** @var array{scripts: array<string, list<string>>} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect(implode("\n", $composer['scripts']['test:pgsql']))
        ->toContain('DB_DATABASE=${DB_TEST_DATABASE:-$(php scripts/create-pgsql-test-database.php --print-name)}')
        ->not->toContain('$(basename \"$PWD\")_test');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter='OD-2|A20|A6|A7'`

Expected: FAIL. The consequence returns early without passkeys; `APP_URL` holds the request host; `Doctor::run()` has no `deferred` parameter; `fromSetup()` passes a null marker; the script has no `--print-name`.

- [ ] **Step 3: Implement**

`SetupRunner::applyHttpsConsequence()` (OD-2):

```php
    /**
     * §6.8, RL-7, OD-2: when HTTPS was chosen but the Herd step did not secure the site, APP_URL stays http and the
     * answers say so (the doctor then expects no certificate). Passkeys need HTTPS, so they are switched off in .env
     * and in the answers. The summary says how to secure the site later, and an existing marker is rewritten. Without
     * Herd, an APP_URL that already is https on the detected host is served over HTTPS by something else (a
     * TLS-terminating proxy), so nothing changes.
     */
    public function applyHttpsConsequence(SetupContext $context): void
    {
        $message = $context->results['herd-site']->message ?? '';

        if (! $context->answers->https || str_starts_with($message, HerdSiteStep::SECURED) || $this->servedOverHttpsWithoutHerd($context)) {
            return;
        }

        $name = $context->folderName();

        if ($context->answers->moduleEnabled('passkeys')) {
            new EnvFile($context->path('.env'))->set(['MODULE_PASSKEYS' => false]);
            $context->answers = $context->answers->withModules(array_diff($context->answers->modules, ['passkeys']));
            $context->notes[] = __('Passkeys are off because the site has no HTTPS. To turn them on: herd secure :name, set MODULE_PASSKEYS=true, then php artisan skv:doctor --fix.', ['name' => $name]);
        } else {
            $context->notes[] = __('The site has no HTTPS. To secure it: herd secure :name, then php artisan skv:install --force.', ['name' => $name]);
        }

        $context->answers = $context->answers->withHttps(false);

        if (Marker::exists($context)) {
            Marker::write($context, Summary::build($context));
        }
    }
```

`HerdSiteStep::run()`: replace `'https://'.$context->detection->host` with `'https://'.$this->domain($context)` (RL-16).

`Doctor::run()`: add a third parameter `array $deferred = []` (`@param list<string> $deferred`). In the loop, before the `try`:

```php
            if (in_array($check->key(), $deferred, true)) {
                $results[$check->key()] = CheckResult::skipped(__('Checked after the Herd step'));

                continue;
            }
```

`DoctorContext::fromSetup()`:

```php
    /** The setup's context; before the marker exists, the marker-gated checks read the answers being installed (RL-8). */
    public static function fromSetup(SetupContext $context, Toolchain $toolchain): self
    {
        $marker = Marker::read($context) ?? ['answers' => $context->answers->toPublicArray(), 'start_with' => $context->answers->startWith];

        return new self($context->basePath, $toolchain, $context->detection, $marker);
    }
```

**Check every reader of `DoctorContext::$marker`'s shape** (`answer()`, `SetupTokenCheck`): a synthesised marker has only `answers` and `start_with`. When the property's `@var` shape lists more keys as required, make them optional (`kit_version?: string, installed_at?: string, summary?: array<string, mixed>`).

`DoctorStep`:

```php
    /** The checks steps 9 and 10 make true: Herd serving and securing the site, and the token step 9 deletes (RL-8). */
    public const array DEFERRED = ['herd-site', 'herd-secured', 'setup-token'];
```

`run()` calls `app(Doctor::class)->run(DoctorContext::fromSetup($context, $this->toolchain), deferred: self::DEFERRED)`.

`scripts/create-pgsql-test-database.php`: compute the name with the Database step's rule, and print it for `--print-name`:

```php
$folder = basename((string) (getenv('PWD') ?: dirname(__DIR__)));
// The rule of App\Setup\Steps\DatabaseStep::databaseName() (RL-14); PgsqlTestDatabaseNameTest keeps them equal.
$name = trim(substr(trim((string) preg_replace('/[^a-z0-9_]+/', '_', strtolower($folder)), '_'), 0, 50), '_');
$database = getenv('DB_TEST_DATABASE') ?: $name.'_test';

if (in_array('--print-name', $argv, true)) {
    fwrite(STDOUT, $database."\n");

    exit(0);
}
```

Keep the existing connection code after this block. Update the file's header comment to say "the sanitised project folder name (like the Database step), plus `_test`".

In `composer.json`, replace `DB_DATABASE=${DB_TEST_DATABASE:-$(basename \"$PWD\")_test}` in `test:pgsql` with `DB_DATABASE=${DB_TEST_DATABASE:-$(php scripts/create-pgsql-test-database.php --print-name)}`. Then run `composer validate --no-check-publish`. Expect `composer.lock`'s `content-hash` to stay unchanged (scripts are not hashed). If it does change, run `composer update --lock` and commit the lock.

New lang keys:
- `Checked after the Herd step` = "Wordt na de Herd-stap gecontroleerd"
- `The site has no HTTPS. To secure it: herd secure :name, then php artisan skv:install --force.` = "De site heeft geen HTTPS. Beveilig hem zo: herd secure :name, daarna php artisan skv:install --force."

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Setup tests/Feature/Tooling`, then `composer test:pgsql`. The latter must still find `skv3_test`.

Expected: PASS.

- [ ] **Step 5: Gate and commit**

```bash
git add app/Setup scripts composer.json composer.lock tests lang
git commit -m "fix: record unsecured sites as http, write the secured name and defer the herd checks in step 8

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: One preflight and safe error messages for the CLI and the wizard (A15, RL-13)

**Files:**
- Create: `app/Setup/InstallPreflight.php`, `app/Setup/Support/SafeMessage.php`, `app/Setup/Support/BootstrapRunner.php`, `tests/Fixtures/FailingBootstrap.php`
- Modify: `app/Console/Commands/InstallCommand.php`, `app/Setup/Support/Bootstrap.php` (`implements BootstrapRunner`), `app/Providers/AppServiceProvider.php` (binding)
- Test: `tests/Feature/Setup/InstallPreflightTest.php`, `tests/Feature/Setup/Support/SafeMessageTest.php`, `tests/Feature/Console/InstallCommandTest.php`

**Interfaces:**
- Consumes: `Marker`, `EnvFile`, `Answers`, `DemoSeeder::accounts()`.
- Produces:
  - `new InstallPreflight(SetupContext $context)`:
    - `installedAnswers(): array<string, mixed>`: the marker's public answers with the `MODULE_*` overlay. Throws `JsonException`.
    - `refusesDemoDefault(Answers $answers, array<string, mixed> $input, array<string, mixed> $installed, Detection $detection): bool`.
  - `SafeMessage::of(Throwable $exception): string`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Setup/Support/SafeMessageTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.3 */

use App\Setup\Support\SafeMessage;
use Illuminate\Database\QueryException;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Process\FakeProcessResult;

it('shows a query error without its bindings or PostgreSQL detail (RL-13)', function (): void {
    $pdo = new PDOException("SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column \"name\"\nDETAIL:  Failing row contains (1, \$2y\$12\$hash).\nHINT: none");
    $exception = new QueryException('pgsql', 'insert into users (password) values (?)', ['$2y$12$hash'], $pdo);

    expect(SafeMessage::of($exception))
        ->toBe('SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column "name"')
        ->not->toContain('$2y$');
});

it('shows only the first line of a failed process (RL-13)', function (): void {
    $exception = new ProcessFailedException(new FakeProcessResult(command: 'npm ci', exitCode: 1, output: "line one\nsecret line", errorOutput: 'boom'));

    expect(SafeMessage::of($exception))->toBe('The command "npm ci" failed.')
        ->not->toContain('secret line');
});

it('keeps an ordinary message (RL-13)', function (): void {
    expect(SafeMessage::of(new RuntimeException('Remove the schema dump first.')))->toBe('Remove the schema dump first.');
});
```

`tests/Feature/Setup/InstallPreflightTest.php` uses the process-faking `beforeEach`. Move or copy the scenarios that `InstallCommandTest` covers today for `installedAnswers()` and the demo refusal, and assert on the service directly:

```php
it('takes the installed answers from the marker with the MODULE_* flags of .env on top (DEC-30 (29))', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['modules' => ['passkeys', 'impersonation']], Detection::none(), $context->folderName());
    Marker::write($context, []);
    // The sandbox .env is .env.example, which turns every module on: switch off the two the marker does not hold.
    new EnvFile($context->path('.env'))->set(['MODULE_PASSKEYS' => false, 'MODULE_BACKUPS' => false]);

    expect(new InstallPreflight($context)->installedAnswers()['modules'])->toBe(['impersonation']);
});

it('refuses the built-in demo default next to an own super admin (DEC-30 (29))', function (): void {
    $context = setupSandbox();
    User::factory()->superAdmin()->create(['email' => 'owner@example.com']);
    $answers = Answers::fromArray([], Detection::none(), $context->folderName());
    $preflight = new InstallPreflight($context);

    expect($preflight->refusesDemoDefault($answers, [], [], Detection::none()))->toBeTrue()
        ->and($preflight->refusesDemoDefault($answers, ['start_with' => 'demo'], [], Detection::none()))->toBeFalse();
});
```

Check the factory's super-admin state name in `database/factories/UserFactory.php`. If there is none, use the pattern `InstallCommandTest` already uses to create one.

Append to `tests/Feature/Console/InstallCommandTest.php`:

```php
it('reports a failing bootstrap as a setup stop without the process output (RL-13)', function () use ($install): void {
    app()->instance(BootstrapRunner::class, new FailingBootstrap);

    [$exitCode, $output] = $install(['--answers' => '{}', '--no-interaction' => true]);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain(__('Setup stopped at :step: :message', ['step' => __('Bootstrap'), 'message' => 'The command "php artisan migrate" failed.']))
        ->not->toContain('migrate failed')
        ->not->toContain('secret output');
});
```

Import `App\Setup\Support\BootstrapRunner` and `Tests\Fixtures\FailingBootstrap` (no qualified name in the test).

**The fixture.** `Bootstrap` is `final readonly`, so the fixture cannot extend it. Make `InstallCommand` resolve the bootstrap as a callable: `app(Bootstrap::class)->run()` stays, but the binding may be any object with `run(): list<string>`. That is not typable. Instead:
- Introduce `App\Setup\Support\BootstrapRunner`, an interface with `run(): array`.
- `Bootstrap implements BootstrapRunner`; the container binds the interface to `Bootstrap` with exactly `$this->app->bind(BootstrapRunner::class, fn (Application $app): BootstrapRunner => $app->make(Bootstrap::class));`. Never `new Bootstrap(...)` here: a test's `app()->instance(Bootstrap::class, …)` must cover the interface too, or `InstallCommandTest` would bootstrap the repository itself.
- `InstallCommand` resolves `BootstrapRunner`.
- `FailingBootstrap implements BootstrapRunner` and throws `new ProcessFailedException(new FakeProcessResult(command: 'php artisan migrate', exitCode: 1, output: "migrate failed\nsecret output"))`.

Add `app/Setup/Support/BootstrapRunner.php` to this task's files, and the binding above to `AppServiceProvider`, next to the existing `Bootstrap` binding. In `InstallCommandTest`'s `beforeEach`, after the `Bootstrap` instance line, also add `app()->instance(BootstrapRunner::class, app(Bootstrap::class));`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Setup/InstallPreflightTest.php tests/Feature/Setup/Support/SafeMessageTest.php --filter='RL-13|DEC-30'`

Expected: FAIL (classes missing; the bootstrap error escapes uncaught).

- [ ] **Step 3: Implement**

`app/Setup/Support/SafeMessage.php`:

```php
<?php

declare(strict_types=1);

namespace App\Setup\Support;

use Illuminate\Database\QueryException;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Throwable;

/**
 * An error message that is safe to show (RL-13). A query error shows the driver's message only, cut before
 * PostgreSQL's DETAIL/HINT lines: its own message carries the bindings and DETAIL repeats the failing row (a
 * password hash). A failed process shows its first line. Callers escape the result for their output.
 */
final class SafeMessage
{
    public static function of(Throwable $exception): string
    {
        if ($exception instanceof QueryException) {
            $message = $exception->getPrevious()?->getMessage() ?? __('A database query failed.');

            return trim(preg_replace('/\R(?:DETAIL|HINT):.*/s', '', $message) ?? $message);
        }

        $message = trim($exception->getMessage());

        return $exception instanceof ProcessFailedException ? trim(strtok($message, "\n") ?: $message) : $message;
    }
}
```

`app/Setup/InstallPreflight.php`: move `InstallCommand`'s private `installedAnswers()`, `isOn()` and `ownSuperAdminExists()` here unchanged, except that they take paths from the constructor's `SetupContext`. Add:

```php
    /**
     * Only the built-in default, never a chosen or installed start: demo data would add a super admin whose password
     * everyone knows next to the owner's own (DEC-30 (29)).
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $installed
     */
    public function refusesDemoDefault(Answers $answers, array $input, array $installed, Detection $detection): bool
    {
        $demoByDefault = $answers->startWith === 'demo' && ! array_key_exists('start_with', $input) && ! array_key_exists('start_with', $installed);

        return $demoByDefault && $this->ownSuperAdminExists($detection);
    }
```

**`InstallCommand`:**
- Use `$preflight = new InstallPreflight(app(SetupContext::class))` for both calls.
- Move the bootstrap loop **into** the `try` that runs the steps. Name it as the step `__('Bootstrap')` when it throws: set a `$stage = __('Bootstrap')` string before the bootstrap and `$stage = null` after it. The error line uses `$current?->label() ?? $stage ?? ''`.
- Use `OutputFormatter::escape(SafeMessage::of($exception))` instead of `messageOf()`, and delete `messageOf()`.

New lang keys:
- `A database query failed.` = "Een databasequery is mislukt."
- `Bootstrap` = "Voorbereiding"

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Setup tests/Feature/Console`

Expected: PASS, including every existing `InstallCommandTest` scenario.

- [ ] **Step 5: Gate and commit**

```bash
git add app tests lang
git commit -m "refactor: share the install preflight and safe error messages between the cli and the wizard

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Detection that never hangs, one Herd TLD rule, a pinned host and clean child environments (A4, A5, A18, A19, RL-3, RL-12)

**Files:**
- Create: `app/Setup/Support/HerdTld.php`, `app/Setup/SetupHost.php`
- Modify: `app/Setup/Detector.php`, `app/Setup/Support/Bootstrap.php`, `app/Setup/Support/Toolchain.php`, `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Setup/DetectorTest.php`, `tests/Feature/Setup/BootstrapTest.php`, `tests/Feature/Setup/ToolchainTest.php`, `tests/Feature/Setup/SetupHostTest.php`

**Interfaces:**
- Produces:
  - `HerdTld::read(Toolchain $toolchain): ?string`. It runs `herd tld` with a 5-second timeout and returns the lower-cased tld when it matches `/^[a-z0-9-]+$/`, else null. It never throws.
  - `SetupHost::normalise(string $host): ?string` (strips the port, lower-cases, validates).
  - `SetupHost::forWizard(string $requestHost, ?string $herdTld, string $folder): ?string` (RL-3).
  - `new Toolchain(?string $home = null, ?string $basePath = null)`; `Toolchain::phpCli(): ?string`.
  - `Detector::detect(?string $requestHost = null, string $scheme = 'http')`, unchanged signature. A host that fails `SetupHost::normalise()` falls back to the CLI host.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Setup/SetupHostTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.4 */

use App\Setup\SetupHost;

it('normalises a host or refuses it (A4, RL-3)', function (string $host, ?string $expected): void {
    expect(SetupHost::normalise($host))->toBe($expected);
})->with([
    'plain' => ['My-App.test', 'my-app.test'],
    'port' => ['127.0.0.1:8000', '127.0.0.1'],
    'localhost' => ['localhost', 'localhost'],
    'userinfo' => ['evil.com@my-app.test', null],
    'underscore' => ['my_app.test', null],
    'leading hyphen' => ['-app.test', null],
    'empty' => ['', null],
    'space' => ['my app.test', null],
]);

it('pins the wizard to the Herd name, or to a local name without Herd (A19, RL-3)', function (string $host, ?string $tld, ?string $expected): void {
    expect(SetupHost::forWizard($host, $tld, 'My-App'))->toBe($expected);
})->with([
    'herd apex' => ['my-app.test', 'test', 'my-app.test'],
    'herd admin' => ['admin.my-app.test', 'test', 'my-app.test'],
    'herd tenant subdomain' => ['demo1.my-app.test', 'test', null],
    'herd other site' => ['other.test', 'test', null],
    'herd look-alike' => ['xmy-app.test', 'test', null],
    'no herd localhost' => ['localhost:8000', null, 'localhost'],
    'no herd loopback' => ['127.0.0.1:8000', null, '127.0.0.1'],
    'no herd .localhost' => ['acme.localhost', null, 'acme.localhost'],
    'no herd public name' => ['example.com', null, null],
]);
```

Append to `tests/Feature/Setup/DetectorTest.php`. Its `beforeEach` sets `$this->home`, and it imports `Detector`, `Toolchain`, `File`, `Process` and `Str`:

```php
it('treats a Herd that hangs as no Herd Pro and falls back to the APP_URL host (A5, A18)', function (): void {
    $herd = $this->home.'/Library/Application Support/Herd/bin/herd';
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);
    config(['app.url' => 'http://fallback.test']);

    // A handler that throws stands in for a timeout: Detector must catch every process failure.
    Process::fake(['*' => fn () => throw new RuntimeException('timed out')]);

    $detection = new Detector(new Toolchain($this->home))->detect();

    expect($detection->herd)->toBeTrue()
        ->and($detection->herdPro)->toBeFalse()
        ->and($detection->host)->toBe('fallback.test');
});

it('uses a valid request host and ignores an invalid one (A4)', function (): void {
    config(['app.url' => 'http://fallback.test']);

    expect(new Detector(new Toolchain($this->home))->detect('My-App.test:80')->host)->toBe('my-app.test')
        ->and(new Detector(new Toolchain($this->home))->detect('evil.com@x')->host)->toBe('fallback.test');
});
```

Append to `tests/Feature/Setup/ToolchainTest.php`:

```php
it('keeps the project\'s .env values out of child processes (RL-12)', function (): void {
    $context = setupSandbox();
    new EnvFile($context->path('.env'))->set(['DB_PASSWORD' => 'secret', 'APP_KEY' => 'base64:x', 'LANG' => 'nl_NL.UTF-8']);

    $environment = new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid()), $context->basePath)->environment();

    expect($environment['DB_PASSWORD'] ?? null)->toBeFalse()
        ->and($environment['APP_KEY'] ?? null)->toBeFalse()
        ->and($environment)->not->toHaveKey('LANG')
        ->and($environment['HOME'])->toBeString();
});

it('finds the CLI php next to Herd\'s php-fpm (DEC-23)', function (): void {
    $home = storage_path('framework/testing/setup/home-'.Str::uuid());
    $bin = $home.'/Library/Application Support/Herd/bin';
    File::ensureDirectoryExists($bin);
    File::put($bin.'/php', "#!/bin/sh\n");
    chmod($bin.'/php', 0755);

    expect(new Toolchain($home)->phpCli())->toBe($bin.'/php');
});
```

Append to `tests/Feature/Setup/BootstrapTest.php`:

```php
it('survives a herd tld that times out (A5)', function (): void {
    // Arrange: copy the arrange part of this file's existing APP_URL rewrite test (the sandbox, the herd stub in the
    // sandbox home and the Toolchain), then replace its tld fake with one that throws.
    Process::fake(["*herd' 'tld'*" => fn () => throw new RuntimeException('timed out'), '*' => Process::result('')]);

    $actions = $bootstrap->run(); // the Bootstrap that the copied arrange part built

    expect($actions)->not->toContain(__('Set APP_URL to the Herd address'))
        ->and(new EnvFile($context->path('.env'))->get('APP_URL'))->toBe('http://localhost');
});
```

`$bootstrap` and `$context` come from the copied arrange part; rename them to match that test.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Setup/SetupHostTest.php tests/Feature/Setup/DetectorTest.php tests/Feature/Setup/ToolchainTest.php tests/Feature/Setup/BootstrapTest.php`

Expected: FAIL.

- [ ] **Step 3: Implement**

`app/Setup/SetupHost.php`:

```php
<?php

declare(strict_types=1);

namespace App\Setup;

use Illuminate\Support\Str;

/** The host setup may write into APP_URL and TENANCY_APEX_DOMAIN (RL-3, A4, A19). */
final class SetupHost
{
    private const string LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    /** Lower-cased, without the port; null when it is not a plain DNS name or an IPv4 address. */
    public static function normalise(string $host): ?string
    {
        $host = Str::lower(trim($host));
        $host = preg_replace('/:\d{1,5}$/', '', $host) ?? '';

        if ($host === '' || strlen($host) > 253) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $host;
        }

        return preg_match('/^'.self::LABEL.'(?:\.'.self::LABEL.')*$/', $host) === 1 ? $host : null;
    }

    /**
     * The apex the wizard sets up. Under Herd only {folder}.{tld} or admin.{folder}.{tld}, pinned to {folder}.{tld}:
     * once the marker exists a tenant subdomain without an organisation answers 404, so the Herd stage could not finish
     * there (RL-3). Without Herd only a local name: localhost, 127.0.0.1, *.localhost or *.test.
     */
    public static function forWizard(string $requestHost, ?string $herdTld, string $folder): ?string
    {
        $host = self::normalise($requestHost);

        if ($host === null) {
            return null;
        }

        if ($herdTld !== null) {
            $apex = Str::lower($folder).'.'.$herdTld;

            return $host === $apex || $host === 'admin.'.$apex ? $apex : null;
        }

        $local = in_array($host, ['localhost', '127.0.0.1'], true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.test');

        return $local ? $host : null;
    }
}
```

`app/Setup/Support/HerdTld.php`:

```php
<?php

declare(strict_types=1);

namespace App\Setup\Support;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/** `herd tld`, read one way everywhere (A5): 5 seconds at most, never throws, null unless it prints a plain label. */
final class HerdTld
{
    public static function read(Toolchain $toolchain): ?string
    {
        $herd = $toolchain->herd();

        if ($herd === null) {
            return null;
        }

        try {
            $tld = Str::lower(trim(Process::env($toolchain->environment())->timeout(5)->run([$herd, 'tld'])->output()));
        } catch (Throwable) {
            return null;
        }

        return preg_match('/^[a-z0-9-]+$/', $tld) === 1 ? $tld : null;
    }
}
```

**`Detector`:**
- `cliHost()` uses `HerdTld::read($this->toolchain)`.
- `herdPro()` wraps its process call in `try { ... } catch (Throwable) { return false; }` and uses `timeout(5)`.
- `detect()` uses `$requestHost !== null ? (SetupHost::normalise($requestHost) ?? $this->cliHost($herd)) : $this->cliHost($herd)`.

**`Bootstrap`:** replace its own `herd tld` block with:

```php
        if (preg_match('#^http://localhost(:8000)?/?$#', (string) $env->get('APP_URL')) === 1) {
            $tld = HerdTld::read($this->toolchain);

            if ($tld !== null) {
                $env->set(['APP_URL' => 'http://'.strtolower(basename($this->basePath)).'.'.$tld]);
                $actions[] = __('Set APP_URL to the Herd address');
            }
        }
```

Check `APP_URL` first, as today: `BootstrapTest` asserts that no `herd tld` runs for a custom or https `APP_URL`.

**`Toolchain`:**

```php
    /** Variables a child keeps although the project's .env names them: the session's own environment (RL-12). */
    private const array KEEP = ['HOME', 'PATH', 'USER', 'LOGNAME', 'SHELL', 'TMPDIR', 'LANG', 'TERM'];

    public function __construct(private ?string $home = null, private ?string $basePath = null) {}
```

Keep the existing `$home` handling; only add the parameter. `environment()`:

```php
    /**
     * HOME and PATH for a child (DEC-23), SHELL_VERBOSITY unset (DEC-30 (3)), and with a base path every other key of
     * that project's .env unset (RL-12): children then read the current .env themselves, and npm's install scripts or
     * claude never inherit APP_KEY or the database password.
     *
     * @return array<string, string|false>
     */
    public function environment(): array
    {
        $scrubbed = [];

        if ($this->basePath !== null) {
            foreach (array_keys(new EnvFile($this->basePath.'/.env')->all()) as $key) {
                if (! in_array($key, self::KEEP, true) && ! str_starts_with($key, 'LC_')) {
                    $scrubbed[$key] = false;
                }
            }
        }

        $directories = array_filter([$this->herdBinDirectory(), $this->nodeBinDirectory(), $this->home().'/.local/bin']);

        return [...$scrubbed, 'HOME' => $this->home(), 'PATH' => implode(':', [...$directories, self::SYSTEM_PATH]), 'SHELL_VERBOSITY' => false];
    }

    /**
     * The CLI php for a detached child (RL-4). Under php-fpm, PHP_BINARY is the fpm binary: Herd ships the CLI next to
     * it (php84-fpm → php84), and `php` on the Herd bin directory is Herd's shim.
     */
    public function phpCli(): ?string
    {
        $binary = str_ends_with(PHP_BINARY, '-fpm') ? substr(PHP_BINARY, 0, -4) : PHP_BINARY;

        return is_file($binary) && is_executable($binary) ? $binary : $this->which('php');
    }
```

**The return shape changes.** `environment()` now returns `array<string, string|false>` instead of `array{HOME, PATH, SHELL_VERBOSITY: false}`. `which()` reads `environment()['PATH']`; narrow it with `(string) ($this->environment()['PATH'] ?? '')`, and update every `@return` and `@var` shape Larastan reports.

**Testing `phpCli()`.** Under the test runner PHP_BINARY is the CLI php, so `phpCli()` returns it. The second Toolchain test of Step 1 therefore pins Herd's shim through `which('php')` and checks only that `phpCli()` returns an executable. Use this `expect` in that test:

```php
    expect(new Toolchain($home)->which('php'))->toBe($bin.'/php')
        ->and(is_executable((string) new Toolchain($home)->phpCli()))->toBeTrue();
```

**`AppServiceProvider`:** bind `Toolchain` as `new Toolchain(basePath: base_path())`. Make the binding explicit (`$this->app->bind(Toolchain::class, fn (): Toolchain => new Toolchain(basePath: base_path()));`), replacing any existing one.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Setup tests/Feature/Console`

Expected: PASS.

- [ ] **Step 5: Gate and commit**

```bash
git add app tests lang
git commit -m "fix: pin the setup host, never hang on herd and keep .env secrets out of child processes

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: The setup token, setup mode, the resolver bypass and the `/` redirect (§5.2, §5.3.4, RL-1, RL-10)

**Files:**
- Create: `app/Setup/SetupToken.php`, `app/Setup/SetupMode.php`, `app/Setup/Http/EnterSetupMode.php`, `app/Setup/Http/DirectRequest.php`
- Modify:
  - `app/Setup/Support/Bootstrap.php` (writes the token, `appUrl()`)
  - `app/Setup/Doctor/Checks/SessionDriverCheck.php` and `app/Setup/Doctor/Checks/QueueCheck.php` (read the drivers setup mode replaced)
  - `app/Console/Commands/BootstrapCommand.php` (RL-10 line)
  - `app/Tenancy/Http/Middleware/InitializeTenancy.php`
  - `bootstrap/app.php`
  - `routes/web.php`
  - `app/Providers/AppServiceProvider.php` (bindings)
  - `app/Setup/Doctor/Checks/SetupTokenCheck.php` and `app/Setup/Steps/MarkerStep.php` (use `SetupToken::PATH`)
  - `tests/Pest.php` (`useSetupWizard()`)
- Test: `tests/Feature/Setup/SetupTokenTest.php`, `tests/Feature/Setup/SetupModeTest.php`, `tests/Feature/Setup/BootstrapTest.php`, `tests/Feature/Console/BootstrapCommandTest.php`

**Interfaces:**
- Consumes: `Marker::FILE`, `StateFile`.
- Produces:
  - `DirectRequest::isDirect(Request $request): bool`: local and no proxy or tunnel header. Used here by `homeRedirect()`, and by Tasks 7 and 11.
  - `Bootstrap::appUrl(): string`: the `APP_URL` of the project Bootstrap just prepared.
  - `config('setup.replaced')`: `array{session?: string, queue?: string}`, the drivers `applyRuntime()` replaced.
  - `new SetupToken(string $basePath)`:
    - `PATH = 'storage/app/private/skv-setup-token'`
    - `path(): string`, `exists(): bool`
    - `ensure(): bool`: true when it created the token
    - `value(): ?string`: 64 lower-case hex characters, or null
    - `matches(string $candidate): bool`, `hash(): ?string` (sha256 of the value)
  - `new SetupMode(string $basePath)`:
    - `ATTRIBUTE = 'setup_mode'`
    - `active(): bool`, `applyRuntime(): void`, `token(): SetupToken`
    - `homeRedirect(Request $request): ?string`: the relative URL `/welcome-setup?token=…` for a direct request in setup mode, or null
  - `App\Setup\Http\EnterSetupMode`, the first middleware of the `web` group.
  - `useSetupWizard(?SetupContext $context = null): SetupContext` in `tests/Pest.php`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Setup/SetupTokenTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.2, §5.3.4 */

use App\Setup\Marker;
use App\Setup\SetupToken;

it('creates a 64-character token once, mode 0600, while setup has not finished (§5.2 point 5)', function (): void {
    $context = setupSandbox();
    $token = new SetupToken($context->basePath);

    expect($token->ensure())->toBeTrue()
        ->and($token->value())->toMatch('/^[0-9a-f]{64}$/')
        ->and(fileperms($token->path()) & 0777)->toBe(0600);

    $first = $token->value();

    expect($token->ensure())->toBeFalse()->and($token->value())->toBe($first);
});

it('writes no token once the marker exists (§5.3.4)', function (): void {
    $context = setupSandbox();
    Marker::write($context, []);

    expect(new SetupToken($context->basePath)->ensure())->toBeFalse()
        ->and(new SetupToken($context->basePath)->exists())->toBeFalse();
});

it('matches only the exact token (§5.3.4)', function (): void {
    $context = setupSandbox();
    $token = new SetupToken($context->basePath);
    $token->ensure();

    expect($token->matches((string) $token->value()))->toBeTrue()
        ->and($token->matches(strtoupper((string) $token->value())))->toBeFalse()
        ->and($token->matches(''))->toBeFalse()
        ->and($token->hash())->toBe(hash('sha256', (string) $token->value()));
});
```

`tests/Feature/Setup/SetupModeTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.4, §6.2 */

use App\Models\Organisation;
use App\Models\User;
use App\Setup\Marker;
use App\Setup\SetupMode;
use App\Setup\SetupToken;

it('is active only locally, without a marker and with a token (RL-1)', function (): void {
    $context = useSetupWizard();
    $mode = new SetupMode($context->basePath);

    expect($mode->active())->toBeTrue();

    app()->detectEnvironment(fn (): string => 'testing');
    expect($mode->active())->toBeFalse();

    app()->detectEnvironment(fn (): string => 'local');
    unlink(new SetupToken($context->basePath)->path());
    expect($mode->active())->toBeFalse();

    new SetupToken($context->basePath)->ensure();
    Marker::write($context, []);
    expect($mode->active())->toBeFalse();
});

it('moves every web request onto a file session, a file cache and the sync queue (§5.3.4, RL-1)', function (): void {
    useSetupWizard();
    config(['session.driver' => 'database', 'cache.default' => 'database', 'queue.default' => 'database']);

    $this->get('/login')->assertOk();

    expect(config('session.driver'))->toBe('file')
        ->and(config('cache.default'))->toBe('file')
        ->and(config('queue.default'))->toBe('sync');
});

it('lets every host through the tenant resolver in setup mode (§5.3.4)', function (): void {
    $this->get('http://nobody.localhost/login')->assertNotFound();

    useSetupWizard();

    $this->get('http://nobody.localhost/login')->assertOk();
});

it('sends / to the wizard with the token while no super admin exists (§5.3.4, §6.2)', function (): void {
    $context = useSetupWizard();
    $token = (string) new SetupToken($context->basePath)->value();

    $this->get('/')->assertRedirect('/welcome-setup?token='.$token);

    User::factory()->superAdmin()->create();

    $this->get('/')->assertRedirect(route('login'));
});

it('never hands the token to a proxied or tunnelled request (§5.3.4, DEC-24)', function (string $header): void {
    useSetupWizard();

    $this->get('/', [$header => 'x'])->assertRedirect(route('login'));
})->with(['X-Forwarded-For', 'X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Original-Host', 'Forwarded', 'X-Expose-Subdomain']);

it('leaves / alone outside setup mode (§6.2)', function (): void {
    $this->get('/')->assertRedirect(route('login'));
});

it('lets the doctor check the project drivers, not the ones setup mode put in their place (§5.3.4, §5.4)', function (): void {
    $context = useSetupWizard();
    config(['session.driver' => 'database', 'queue.default' => 'database']);

    app(SetupMode::class)->applyRuntime();
    $doctor = DoctorContext::fromSetup($context, app(Toolchain::class));

    expect(app(SessionDriverCheck::class)->run($doctor)->status)->toBe(CheckStatus::Pass)
        ->and(config('session.driver'))->toBe('file')
        ->and(config('setup.replaced'))->toBe(['session' => 'database', 'queue' => 'database']);
});

it('never resolves a tenant in setup mode, even an existing one (§5.3.4)', function (): void {
    Organisation::factory()->create(['slug' => 'acme']);
    useSetupWizard();

    $this->get('http://acme.localhost/login')->assertOk();

    expect(Tenant::check())->toBeFalse();
});
```

Add to the file's imports:
- `App\Tenancy\Facades\Tenant`
- `App\Enums\CheckStatus`
- `App\Setup\Doctor\Checks\SessionDriverCheck`
- `App\Setup\Doctor\DoctorContext`
- `App\Setup\Support\Toolchain`

The file has no process-faking `beforeEach` of its own, because `useSetupWizard()` fakes processes (below).

Append to `tests/Feature/Setup/BootstrapTest.php`:

```php
it('creates the one-time setup token (§5.2 point 5, RL-10)', function (): void {
    // Arrange like the file's simplest bootstrap test (a sandbox and a Bootstrap on it, no Herd).
    $actions = $bootstrap->run();

    expect($actions)->toContain(__('Created the one-time setup token'))
        ->and(new SetupToken($context->basePath)->value())->toMatch('/^[0-9a-f]{64}$/');
});
```

Copy the arrange lines from the file's first test; the variable names must match.

`tests/Feature/Console/BootstrapCommandTest.php`:
- Start with the process-faking `beforeEach`.
- Bind a sandbox: `app()->instance(SetupContext::class, $context)`, and the `Bootstrap` (and `BootstrapRunner`) built on it.
- Run `Artisan::call('skv:bootstrap', ['--no-interaction' => true], $output = new BufferedOutput)`.
- Assert that `$output->fetch()` contains `__('Open :url to finish setting up in the browser, or run php artisan skv:install.', ['url' => 'http://localhost'])`.
- The URL comes from the sandbox's `.env` through `Bootstrap::appUrl()`. The existing BootstrapTest command tests bind only `Bootstrap`, so the command must never resolve `SetupContext` for it: that would read the repository's own `.env`.

`tests/Pest.php`: add next to `setupSandbox()`:

```php
/**
 * Puts the app into setup mode on a sandbox (M6b, RL-1): the local environment, a setup token in the sandbox, and the
 * setup services bound to it. Processes are faked, so the wizard's Herd and database probes never reach the
 * developer's machine. The session and cache files go into the sandbox too. Task 8 adds the inline step launcher
 * here. Returns the sandbox context.
 */
function useSetupWizard(?SetupContext $context = null): SetupContext
{
    $context ??= setupSandbox();

    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid()), $context->basePath));
    app()->detectEnvironment(fn (): string => 'local');
    app()->instance(SetupContext::class, $context);
    app()->instance(SetupMode::class, new SetupMode($context->basePath));
    app()->instance(SetupToken::class, new SetupToken($context->basePath));
    config([
        'session.files' => $context->path('storage/framework/sessions'),
        'cache.stores.file.path' => $context->path('storage/framework/cache'),
    ]);
    File::ensureDirectoryExists($context->path('storage/framework/sessions'));
    File::ensureDirectoryExists($context->path('storage/framework/cache'));
    new SetupToken($context->basePath)->ensure();

    return $context;
}
```

(Add the `use` lines `App\Setup\SetupMode`, `App\Setup\SetupToken`, `App\Setup\Support\Toolchain`, `Illuminate\Support\Facades\Process` and `Illuminate\Support\Str` to `tests/Pest.php`, unless they are already there.)

A later `Process::fake([...])` in a test adds its own fakes to these (`Factory::fake()` merges), so tests that need answers from `herd` still fake them.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Setup/SetupTokenTest.php tests/Feature/Setup/SetupModeTest.php tests/Feature/Setup/BootstrapTest.php tests/Feature/Console/BootstrapCommandTest.php`

Expected: FAIL (classes missing).

- [ ] **Step 3: Implement**

`app/Setup/Http/DirectRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Setup\Http;

use Illuminate\Http\Request;

/**
 * A request straight from the local machine (§5.3.4, DEC-24): the local environment and none of the headers a proxy
 * or a tunnel such as `herd share` adds. The IP is no signal under Herd: everything arrives from 127.0.0.1.
 */
final class DirectRequest
{
    private const array FORWARDED = ['x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-original-host', 'forwarded'];

    public static function isDirect(Request $request): bool
    {
        if (! app()->isLocal()) {
            return false;
        }

        foreach (array_keys($request->headers->all()) as $name) {
            if (in_array($name, self::FORWARDED, true) || str_starts_with($name, 'x-expose-')) {
                return false;
            }
        }

        return true;
    }
}
```

`app/Setup/SetupToken.php`:

```php
<?php

declare(strict_types=1);

namespace App\Setup;

use App\Setup\Support\StateFile;

/** The one-time setup token (§5.2 point 5, §5.3.4): written by skv:bootstrap, deleted by step 9 (the marker). */
final readonly class SetupToken
{
    public const string PATH = 'storage/app/private/skv-setup-token';

    public function __construct(private string $basePath) {}

    public function path(): string
    {
        return rtrim($this->basePath, '/').'/'.self::PATH;
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /** Creates the token once, while setup has not finished: 32 random bytes as 64 hex characters, mode 0600. */
    public function ensure(): bool
    {
        if ($this->exists() || is_file(rtrim($this->basePath, '/').'/'.Marker::FILE)) {
            return false;
        }

        StateFile::write($this->path(), bin2hex(random_bytes(32)));

        return true;
    }

    public function value(): ?string
    {
        $value = $this->exists() ? trim((string) file_get_contents($this->path())) : '';

        return preg_match('/^[0-9a-f]{64}$/', $value) === 1 ? $value : null;
    }

    public function matches(string $candidate): bool
    {
        $value = $this->value();

        return $value !== null && hash_equals($value, $candidate);
    }

    public function hash(): ?string
    {
        $value = $this->value();

        return $value === null ? null : hash('sha256', $value);
    }
}
```

`app/Setup/SetupMode.php`:

```php
<?php

declare(strict_types=1);

namespace App\Setup;

use App\Models\User;
use App\Setup\Http\DirectRequest;
use App\Tenancy\Facades\Tenant;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Setup mode (RL-1, §5.3.4): the app runs locally, has no marker and still has its setup token. While it is on, the
 * web requests never depend on the database that setup changes under them, the tenant resolver stands aside, and
 * `/` leads to the wizard until a super admin exists.
 */
final readonly class SetupMode
{
    /** The request attribute EnterSetupMode sets; InitializeTenancy and the gate read it. */
    public const string ATTRIBUTE = 'setup_mode';

    public function __construct(private string $basePath) {}

    public function active(): bool
    {
        return app()->isLocal()
            && ! is_file(rtrim($this->basePath, '/').'/'.Marker::FILE)
            && $this->token()->exists();
    }

    public function token(): SetupToken
    {
        return new SetupToken($this->basePath);
    }

    /**
     * A file session, a file cache and the sync queue: drivers that already need no database (array, file, cookie,
     * sync) are kept, so tests keep theirs. The permission registrar, built at boot on the old cache store, is
     * pointed at the new one.
     */
    public function applyRuntime(): void
    {
        $runtime = [];

        if (! in_array(config('session.driver'), ['file', 'array', 'cookie'], true)) {
            $runtime['session.driver'] = 'file';
        }

        if (! in_array(config('cache.default'), ['file', 'array'], true)) {
            $runtime['cache.default'] = 'file';
        }

        if (config('queue.default') !== 'sync') {
            $runtime['queue.default'] = 'sync';
        }

        // The doctor (step 8) runs inside this request and must check the project's drivers (§5.4).
        config(['setup.replaced' => array_filter([
            'session' => isset($runtime['session.driver']) ? (string) config('session.driver') : null,
            'queue' => isset($runtime['queue.default']) ? (string) config('queue.default') : null,
        ])]);
        config($runtime);

        if (app()->resolved(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->initializeCache();
        }
    }

    /**
     * Where `/` sends a direct visitor in setup mode while no super admin exists (§5.3.4), else null. A proxied or
     * tunnelled request never gets the token (DEC-24): it would leave the machine in the tunnel's logs.
     */
    public function homeRedirect(Request $request): ?string
    {
        if ($request->attributes->get(self::ATTRIBUTE) !== true || ! DirectRequest::isDirect($request)) {
            return null;
        }

        $token = $this->token()->value();

        return $token === null || $this->superAdminExists() ? null : '/welcome-setup?token='.$token;
    }

    /** False when the database cannot be read yet (a first install): the wizard is what sets it up. */
    private function superAdminExists(): bool
    {
        try {
            return Tenant::bypass(fn (): bool => User::query()->where('is_super_admin', true)->exists());
        } catch (Throwable) {
            return false;
        }
    }
}
```

`app/Setup/Http/EnterSetupMode.php`:

```php
<?php

declare(strict_types=1);

namespace App\Setup\Http;

use App\Setup\SetupMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** First middleware of the web group, before the tenant resolver and StartSession (RL-1, §5.3.4). */
final readonly class EnterSetupMode
{
    public function __construct(private SetupMode $mode) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->mode->active()) {
            $this->mode->applyRuntime();
            $request->attributes->set(SetupMode::ATTRIBUTE, true);
        }

        return $next($request);
    }
}
```

`InitializeTenancy::handle()`, first lines:

```php
        // §5.3.4: in setup mode no organisation exists yet, and the apex is still being chosen.
        if ($request->attributes->get(SetupMode::ATTRIBUTE) === true) {
            app(CurrentTenant::class)->forget();

            return $next($request);
        }
```

**`bootstrap/app.php`:** `$middleware->web(prepend: [EnterSetupMode::class, InitializeTenancy::class], append: [...])`. Neither goes into the priority list.

**`routes/web.php`:** the `/` closure starts with:

```php
    // §5.3.4: before setup, `/` leads to the wizard.
    if (($wizard = app(SetupMode::class)->homeRedirect(request())) !== null) {
        return redirect($wizard);
    }
```

The closure's return type is already `RedirectResponse`. Update the comment above the route to mention the setup case.

**`AppServiceProvider::register()`:**
- `$this->app->bind(SetupMode::class, fn (): SetupMode => new SetupMode(base_path()));`
- `$this->app->bind(SetupToken::class, fn (): SetupToken => new SetupToken(base_path()));`

**`Bootstrap::run()`:** at the end, before `return`:

```php
        if (new SetupToken($this->basePath)->ensure()) {
            $actions[] = __('Created the one-time setup token');
        }
```

**`BootstrapCommand`:** replace the "Run php artisan skv:install…" line (RL-10) with:

```php
        $this->components->info(__('Open :url to finish setting up in the browser, or run php artisan skv:install.', ['url' => $bootstrap->appUrl()]));
```

`Bootstrap` gains:

```php
    /** The `APP_URL` of the project this Bootstrap prepares (RL-10), never the running app's own `.env`. */
    public function appUrl(): string
    {
        return (string) (new EnvFile($this->basePath.'/.env')->get('APP_URL') ?? config('app.url'));
    }
```

**`SessionDriverCheck::run()`** reads `$driver = (string) (config('setup.replaced.session') ?? config('session.driver'));`.

**`QueueCheck::run()`** reads `$name = (string) (config('setup.replaced.queue') ?? config('queue.default'));`.

The keys under `setup.replaced` hold no dots on purpose: `config()` splits a key on every dot.

Keep the existing early return under `LARAVEL_INSTALLER_DEFER_HOOKS`.

**`SetupTokenCheck` and `MarkerStep`:** replace their own `skv-setup-token` path constant with `SetupToken::PATH`.

New lang keys:
- `Created the one-time setup token` = "Eenmalige setup-token aangemaakt"
- `Open :url to finish setting up in the browser, or run php artisan skv:install.` = "Open :url om de installatie in de browser af te ronden, of draai php artisan skv:install."

Delete the old key "Run php artisan skv:install to finish setting up." from both files, if no other code uses it. `LangCoverageTest` only checks keys that are used; unused keys are allowed but stale.

- [ ] **Step 4: Run the tests to verify they pass**

Run: the command of Step 2, then `php artisan test --compact tests/Feature/Tenancy tests/Feature/Setup`. The route × host matrix must stay green: outside setup mode nothing changed.

Expected: PASS.

- [ ] **Step 5: Gate and commit**

```bash
git add app bootstrap routes tests lang
git commit -m "feat: add the setup token and setup mode with its runtime, resolver bypass and / redirect

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: `layouts/setup` and the committed `setup.css` (§5.3.4, OD-1, RL-15)

**Files:**
- Create: `resources/css/theme.css`, `resources/css/setup.css`, `vite.setup.config.js`, `resources/views/layouts/setup.blade.php`, `resources/setup/setup.css` (generated)
- Modify: `resources/css/app.css`, `package.json`, `.gitattributes`, `.github/workflows/tests.yml`
- Test: `tests/Feature/Setup/Wizard/SetupLayoutTest.php`, `tests/Feature/Layouts/BaseLayoutTest.php`, `tests/Feature/Tooling/CiWorkflowTest.php`

**Interfaces:**
- Produces:
  - `<x-layouts::setup :title="…">`: a self-contained HTML document with no Vite.
  - `npm run build:setup`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Setup/Wizard/SetupLayoutTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.4 */

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;

it('renders without Vite or public/build (§5.3.4)', function (): void {
    Vite::useManifestFilename('missing-'.Str::uuid().'.json');

    $html = Blade::render('<x-layouts::setup title="Setup"><p data-test="slot">body</p></x-layouts::setup>');

    expect($html)->toContain('<!DOCTYPE html>')
        ->toContain('<style>')
        ->toContain('[data-flux-')
        ->toContain('data-test="slot"')
        ->not->toContain('/build/')
        ->not->toContain('@vite')
        ->not->toContain('fonts.bunny.net');
});

it('builds the committed stylesheet with build:setup from the kit theme (OD-1, RL-15)', function (): void {
    /** @var array{scripts: array<string, string>} $package */
    $package = json_decode((string) file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);
    $css = (string) file_get_contents(resource_path('setup/setup.css'));

    expect($package['scripts']['build:setup'] ?? null)->toBe('vite build --config vite.setup.config.js')
        ->and((string) file_get_contents(base_path('vite.setup.config.js')))->toContain("assetFileNames: 'setup.css'")
        ->and((string) file_get_contents(resource_path('css/setup.css')))->toContain("@import './theme.css'")
        ->and((string) file_get_contents(resource_path('css/setup.css')))->toContain("@source not '../../vendor/livewire/flux-pro'")
        ->and((string) file_get_contents(resource_path('css/app.css')))->toContain("@import './theme.css'")
        ->and(strlen($css))->toBeGreaterThan(10_000)
        ->and($css)->toContain('--color-accent');
});
```

**`tests/Feature/Layouts/BaseLayoutTest.php`:** rewrite the "keeps one HTML shell" test in place and keep its name:
- `->toBe(['layouts/base.blade.php', 'layouts/setup.blade.php'])`
- the comment above it: "Only the base layout, the self-contained setup layout (§5.3.4) and the error pages are documents."
- the `!` check covers `resources/css/app.css`, `resources/css/theme.css` and `resources/css/setup.css`

Append to `tests/Feature/Tooling/CiWorkflowTest.php`:

```php
it('checks in CI that the setup stylesheet is current (OD-1)', function (): void {
    expect((string) file_get_contents(base_path('.github/workflows/tests.yml')))
        ->toContain('npm run build:setup && git diff --exit-code resources/setup/setup.css');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Setup/Wizard/SetupLayoutTest.php tests/Feature/Layouts/BaseLayoutTest.php tests/Feature/Tooling/CiWorkflowTest.php`

Expected: FAIL.

- [ ] **Step 3: Implement**

**Split `resources/css/app.css`:**
- Move everything from `@custom-variant dark` to the end of the file (the `@theme`, the dark accent, the `[data-accent=…]` presets and the focus ring) into `resources/css/theme.css`, unchanged.
- `app.css` keeps its two `@import`s and its `@source` lines, and gains `@import './theme.css';` right after the Flux import.
- Run `npm run build` and compare `public/build/assets/app-*.css` before and after (`diff` the two files): the only acceptable difference is ordering of identical rules. Record the result in the report.

`resources/css/setup.css`:

```css
/*
 * The setup wizard's stylesheet source (§5.3.4, RL-15). `npm run build:setup` compiles it to
 * resources/setup/setup.css, which layouts/setup inlines: the wizard runs before `npm run build`.
 */
@import 'tailwindcss' source(none);
@import '../../vendor/livewire/flux/dist/flux.css';
@import './theme.css';

@source '../views/layouts/setup.blade.php';
@source '../views/pages/setup';
@source '../views/setup';
@source '../../vendor/livewire/flux/stubs/resources/views/flux';
/* flux.css adds `@source "../../flux-pro/stubs"`: free Flux only (DEC-02), so the build is the same with or without Pro installed. */
@source not '../../vendor/livewire/flux-pro';

/* No @fonts in the setup layout: the system font stack. */
@theme {
    --font-sans: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
}
```

`vite.setup.config.js`:

```js
import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

// Builds resources/setup/setup.css, the stylesheet layouts/setup inlines (§5.3.4, OD-1): one CSS file, no manifest,
// no public directory. `npm run build:setup` runs it; CI checks the committed file is current.
export default defineConfig({
    publicDir: false,
    plugins: [tailwindcss()],
    build: {
        outDir: 'resources/setup',
        emptyOutDir: false,
        manifest: false,
        rolldownOptions: {
            input: 'resources/css/setup.css',
            output: { assetFileNames: 'setup.css' },
        },
    },
});
```

**`package.json`:** add `"build:setup": "vite build --config vite.setup.config.js"` to `scripts`. No dependency changes. Check `git diff package-lock.json` is empty.

**`.gitattributes`:** add `resources/setup/setup.css linguist-generated=true -diff`.

`resources/views/layouts/setup.blade.php`:

```blade
@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-accent="{{ \App\Support\Branding::accent() }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta name="robots" content="noindex" />

        <title>{{ filled($title) ? __((string) $title).' - ' : '' }}{{ __('Setup') }}</title>

        {{-- §5.3.4: no Vite and nothing from public/build. The committed stylesheet is inlined; Livewire and Flux are served through PHP routes. --}}
        <style>{!! file_get_contents(resource_path('setup/setup.css')) !!}</style>

        @fluxAppearance
        @livewireStyles
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-900 dark:text-zinc-100">
        <main class="mx-auto w-full max-w-2xl px-6 py-12">
            {{ $slot }}
        </main>

        @livewireScripts
        @fluxScripts
    </body>
</html>
```

(`layouts/base` writes `\App\Support\Branding` the same way; Blade files are outside `TestFileClassesTest`.)

**Build and commit the stylesheet:** `npm run build:setup`, then check that `resources/setup/setup.css` exists, holds the theme's `--color-accent` and that `git status` shows no other new file. Run it a second time and confirm `git diff --exit-code resources/setup/setup.css` passes (deterministic).

**`.github/workflows/tests.yml`:** in the `check` job, right after the step that runs `npm run build`, add:

```yaml
      - name: Check the setup stylesheet is current
        run: npm run build:setup && git diff --exit-code resources/setup/setup.css
```

Keep `CiWorkflowTest`'s other pins intact.

New lang key: `Setup` = "Installatie".

- [ ] **Step 4: Run the tests to verify they pass**

Run: the command of Step 2, then `npm run build && composer test:browser`. The app's own CSS must still work; the smoke test covers it.

Expected: PASS.

- [ ] **Step 5: Gate and commit**

```bash
git add resources vite.setup.config.js package.json .gitattributes .github tests lang
git commit -m "feat: add the vite-free setup layout with its committed stylesheet

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: The access gate, the wizard route and the page shell (§5.3.4, §6.2, RL-2)

**Files:**
- Create: `app/Setup/Http/EnsureSetupAccess.php`, `routes/setup.php`, `resources/views/pages/setup/wizard/wizard.php`, `resources/views/pages/setup/wizard/wizard.blade.php`
- Modify: `routes/web.php`, `app/Providers/AppServiceProvider.php` (persistent middleware), `tests/Feature/Tenancy/RouteHostMatrixTest.php`
- Test: `tests/Feature/Setup/Wizard/SetupAccessTest.php`

**Interfaces:**
- Consumes: `SetupMode::ATTRIBUTE`, `SetupToken`, `DirectRequest::isDirect()` (Task 5), `layouts::setup` (Task 6).
- Produces:
  - `EnsureSetupAccess::SESSION_KEY = 'setup.token'`
  - `EnsureSetupAccess::authorised(Request $request): bool`, used by the page's `mount()` and actions
  - the route `setup.wizard` (GET `/welcome-setup`) and the component `pages::setup.wizard`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Setup/Wizard/SetupAccessTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.4, §7.2 */

use App\Setup\Http\EnsureSetupAccess;
use App\Setup\Marker;
use App\Setup\SetupToken;

it('answers 404 without the token, with a wrong token, or outside setup mode (§7.2)', function (): void {
    $context = useSetupWizard();

    $this->get('/welcome-setup')->assertNotFound();
    $this->get('/welcome-setup?token='.str_repeat('0', 64))->assertNotFound();

    $token = (string) new SetupToken($context->basePath)->value();
    app()->detectEnvironment(fn (): string => 'production');

    $this->get('/welcome-setup?token='.$token)->assertNotFound();
});

it('answers 404 once the marker exists (§5.3.4)', function (): void {
    $context = useSetupWizard();
    $token = (string) new SetupToken($context->basePath)->value();
    Marker::write($context, []);

    $this->get('/welcome-setup?token='.$token)->assertNotFound();
});

it('answers 404 behind a proxy or tunnel (§7.2, DEC-24)', function (string $header): void {
    $context = useSetupWizard();
    $token = (string) new SetupToken($context->basePath)->value();

    $this->get('/welcome-setup?token='.$token, [$header => 'x'])->assertNotFound();
})->with(['X-Forwarded-For', 'X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Original-Host', 'Forwarded', 'X-Expose-Subdomain']);

it('keeps the token in the session and drops it from the address (RL-2)', function (): void {
    $context = useSetupWizard();
    $token = (string) new SetupToken($context->basePath)->value();

    $this->get('/welcome-setup?token='.$token)->assertRedirect(url('/welcome-setup'));

    expect(session(EnsureSetupAccess::SESSION_KEY))->toBe(hash('sha256', $token));

    $this->get('/welcome-setup')->assertOk()->assertSee('data-test="setup-wizard"', false);
});

it('refuses a Livewire update without the session token (§7.2, RL-2)', function (): void {
    $context = useSetupWizard();
    $this->get('/welcome-setup?token='.new SetupToken($context->basePath)->value());
    $snapshot = pageSnapshot(url('/welcome-setup'), 'pages::setup.wizard');

    session()->forget(EnsureSetupAccess::SESSION_KEY);

    callPageAction($snapshot, '$refresh')->assertNotFound();
});
```

**Check how the helpers fit.** Look at the signatures of `pageSnapshot()` and `callPageAction()` in `tests/Pest.php`. If `$refresh` is not callable through `callPageAction()`, call the first public action the shell offers (Task 7's shell has none, so add `public function ping(): void {}` only if needed, and remove it in Task 10 in favour of a real action).

**`RouteHostMatrixTest`:** add `'setup.wizard'` to `$excluded` with the comment `// §5.3.4: local-only setup routes, covered by tests/Feature/Setup/Wizard`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Setup/Wizard/SetupAccessTest.php`

Expected: FAIL (route missing).

- [ ] **Step 3: Implement**

`app/Setup/Http/EnsureSetupAccess.php`:

```php
<?php

declare(strict_types=1);

namespace App\Setup\Http;

use App\Setup\SetupMode;
use App\Setup\SetupToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The wizard's gate (§5.3.4, RL-2), also Livewire persistent middleware: setup mode, a direct request, and the
 * one-time token. The token arrives once in the query; its hash then lives in the (file) session, so seeding a super
 * admin never locks the wizard out. Everything else is a 404.
 */
final readonly class EnsureSetupAccess
{
    public const string SESSION_KEY = 'setup.token';

    public function __construct(private SetupToken $token) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->attributes->get(SetupMode::ATTRIBUTE) === true && DirectRequest::isDirect($request), 404);

        $candidate = $request->query('token');

        if (is_string($candidate)) {
            abort_unless($this->token->matches($candidate), 404);
            $request->session()->put(self::SESSION_KEY, $this->token->hash());

            // Out of the address bar, the history and the Referer.
            if ($request->isMethod('GET')) {
                return redirect()->to($request->url());
            }
        }

        abort_unless(self::authorised($request), 404);

        return $next($request);
    }

    /** The session holds the current token's hash; the page re-checks this in mount() and before every action. */
    public static function authorised(Request $request): bool
    {
        $stored = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;
        $hash = app(SetupToken::class)->hash();

        return is_string($stored) && $hash !== null && hash_equals($hash, $stored)
            && $request->attributes->get(SetupMode::ATTRIBUTE) === true && DirectRequest::isDirect($request);
    }
}
```

`routes/setup.php`:

```php
<?php

declare(strict_types=1);

use App\Setup\Http\EnsureSetupAccess;
use Illuminate\Support\Facades\Route;

// §5.3.4: the browser wizard, on every host. EnsureSetupAccess answers 404 unless the app is in setup mode (RL-1),
// the request is direct and it carries the one-time token (RL-2).
Route::livewire('welcome-setup', 'pages::setup.wizard')
    ->middleware(EnsureSetupAccess::class)
    ->name('setup.wizard');
```

**`routes/web.php`:** add `require __DIR__.'/setup.php';` after `admin.php`.

**`AppServiceProvider::boot()`:** `Livewire::addPersistentMiddleware([EnsureSetupAccess::class]);`. Import `Livewire\Livewire`; check whether `TenancyServiceProvider` already calls `addPersistentMiddleware`. Calls add up.

**The page shell:** `php artisan make:livewire setup.wizard --no-interaction`. Move the generated files under `resources/views/pages/setup/wizard/` as the other pages are laid out, or pass the path the config expects. Check `config/livewire.php`'s page namespace and an existing page such as `pages/invitations/accept`. Then write `wizard.php`:

```php
<?php

declare(strict_types=1);

use App\Setup\Http\EnsureSetupAccess;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** §5.3.4: the browser wizard. Task 10 adds the questions and the steps. */
new #[Layout('layouts::setup')] #[Title('Setup')] class extends Component
{
    public function mount(): void
    {
        abort_unless(EnsureSetupAccess::authorised(request()), 404);
    }
};
```

and `wizard.blade.php`:

```blade
<div data-test="setup-wizard">
    <flux:heading size="xl" level="1">{{ __('Set up :name', ['name' => config('app.name')]) }}</flux:heading>
</div>
```

New lang key: `Set up :name` = ":name installeren".

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Setup/Wizard tests/Feature/Tenancy/RouteHostMatrixTest.php tests/Arch`

Expected: PASS.

- [ ] **Step 5: Gate and commit**

Run `npm run build:setup`: the shell adds classes. Commit `resources/setup/setup.css` if it changed.

```bash
git add app routes resources tests lang
git commit -m "feat: gate the setup wizard behind setup mode and the one-time token

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: The wizard state, the Herd state, the step launcher and `skv:run-step` (RL-4, RL-5)

**Files:**
- Create:
  - `app/Setup/Wizard/WizardState.php`, `app/Setup/Wizard/HerdState.php`
  - `app/Setup/Wizard/StepLauncher.php`, `app/Setup/Wizard/DetachedStepLauncher.php`
  - `app/Console/Commands/RunStepCommand.php`
  - `tests/Fixtures/InlineStepLauncher.php`
- Modify: `app/Setup/Steps/MarkerStep.php` (constants, no symlink following), `app/Providers/AppServiceProvider.php` (bind `StepLauncher`), `tests/Pest.php` (`useSetupWizard()` binds the inline launcher), `tests/Arch/NameLeakTest.php`
- Test: `tests/Feature/Setup/Wizard/WizardStateTest.php`, `tests/Feature/Setup/Wizard/DetachedStepLauncherTest.php`, `tests/Feature/Console/RunStepCommandTest.php`, `tests/Feature/Setup/Steps/MarkerStepTest.php`

**Interfaces:**
- Consumes: Task 1's `StateFile`, `Answers::toState()` / `fromState()`, `StepResult::toArray()` / `fromArray()`, `SetupRunner::runStep()` / `finish()`; Task 4's `Toolchain::phpCli()`.
- Produces:
  - `WizardState`:
    - `PATH = 'storage/app/private/skv-setup.json'`, `STEPS = 'storage/app/private/skv-setup'`
    - `__construct(public string $host, public Answers $answers, public array<string, StepResult> $results = [], public array<int, string> $notes = [])`
    - `static load(string $basePath): ?self` (throws `JsonException` / `InvalidArgumentException`)
    - `save(string $basePath): void`, `static forget(string $basePath): void`
    - `context(string $basePath, Detection $detection): SetupContext`, `absorb(SetupContext $context): void`
  - `HerdState`:
    - `PATH = 'storage/app/private/skv-herd.json'`, `EXIT = 'storage/app/private/skv-herd.exit'`, `LOG = 'storage/logs/skv-herd.log'`, `LIFETIME = 1800`
    - props `string $secretHash, string $host, Answers $answers, array $results, array $notes, int $startedAt, ?StepResult $result`
    - `static begin(string $basePath, SetupContext $context, string $host): array{0: self, 1: string}` (the state and the plain secret)
    - `static load(string $basePath): ?self`, `save(string $basePath): void`, `static forget(string $basePath): void`
    - `matches(string $secret): bool`, `isFresh(): bool`
    - `context(string $basePath, Detection $detection): SetupContext`, `absorb(SetupContext $context): void`
  - `StepLauncher`: `start(string $basePath, string $key, string $resultFile, string $logFile, string $exitFile): bool`. Bound to `DetachedStepLauncher`.
  - `skv:run-step {step} {--result=}` (hidden).
  - `Tests\Fixtures\InlineStepLauncher` (records `$started`; runs the command in-process).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Setup/Wizard/WizardStateTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.4 */

use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\StepResult;
use App\Setup\Wizard\HerdState;
use App\Setup\Wizard\WizardState;

it('stores the answers, results and notes in a 0600 file without the password (RL-5)', function (): void {
    $context = setupSandbox();
    $answers = Answers::fromArray(['start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com', 'super_admin_password' => 'correct-horse-battery'], Detection::none(), $context->folderName());

    new WizardState('my-app.test', $answers, ['environment' => StepResult::done('ok')], ['a note'])->save($context->basePath);
    $loaded = WizardState::load($context->basePath);

    expect($loaded?->host)->toBe('my-app.test')
        ->and($loaded?->answers)->toEqual($answers)
        ->and($loaded?->results['environment'] ?? null)->toEqual(StepResult::done('ok'))
        ->and($loaded?->notes)->toBe(['a note'])
        ->and(fileperms($context->path(WizardState::PATH)) & 0777)->toBe(0600)
        ->and((string) file_get_contents($context->path(WizardState::PATH)))->not->toContain('correct-horse-battery');
});

it('restores a context with its results and notes, and takes them back (RL-5)', function (): void {
    $context = setupSandbox();
    $state = new WizardState('my-app.test', Answers::defaults(Detection::none(), $context->folderName()), ['git' => StepResult::skipped('x')]);

    $restored = $state->context($context->basePath, Detection::none('my-app.test'));
    $restored->notes[] = 'later';
    $restored->results['claude'] = StepResult::done();
    $state->absorb($restored);

    expect(array_keys($state->results))->toBe(['git', 'claude'])->and($state->notes)->toBe(['later']);
});

it('keeps only the secret\'s hash in the Herd state and expires it after 30 minutes (RL-6)', function (): void {
    $context = setupSandbox();
    [$state, $secret] = HerdState::begin($context->basePath, $context, 'my-app.test');

    expect($secret)->toMatch('/^[0-9a-f]{64}$/')
        ->and((string) file_get_contents($context->path(HerdState::PATH)))->not->toContain($secret)
        ->and(HerdState::load($context->basePath)?->matches($secret))->toBeTrue()
        ->and($state->matches(str_repeat('0', 64)))->toBeFalse();

    $this->travel(31)->minutes();

    expect(HerdState::load($context->basePath)?->isFresh())->toBeFalse();
});
```

`tests/Feature/Setup/Wizard/DetachedStepLauncherTest.php` (process-faking `beforeEach`):

```php
it('starts skv:run-step detached with every value as its own argument (RL-4)', function (): void {
    $context = setupSandbox();
    Process::fake(["*sh' '-c'*" => Process::result('')]);

    $started = new DetachedStepLauncher(app(Toolchain::class), php: '/usr/bin/php')
        ->start($context->basePath, 'frontend', '/r.json', '/l.log', '/e.exit');

    expect($started)->toBeTrue();
    Process::assertRan(fn ($process): bool => $process->command[0] === 'sh'
        && $process->command[1] === '-c'
        && str_contains($process->command[2], 'nohup')
        && array_slice($process->command, 3) === ['sh', '/usr/bin/php', 'frontend', '/r.json', '/l.log', '/e.exit']);
});

it('reports a launch it could not start (RL-4)', function (): void {
    $context = setupSandbox();
    Process::fake(["*sh' '-c'*" => Process::result(exitCode: 127)]);

    expect(new DetachedStepLauncher(app(Toolchain::class), php: '/usr/bin/php')->start($context->basePath, 'frontend', '/r', '/l', '/e'))->toBeFalse();
});
```

(Imports: `App\Setup\Wizard\DetachedStepLauncher`, `App\Setup\Support\Toolchain`, `Illuminate\Support\Facades\Process`, `Illuminate\Support\Str`.)

`tests/Feature/Console/RunStepCommandTest.php` (process-faking `beforeEach`; it binds `app()->instance(SetupContext::class, $context)` per test):

```php
it('runs one step from the wizard state and writes its result (RL-4)', function (): void {
    $context = setupSandbox();
    app()->instance(SetupContext::class, $context);
    new WizardState('localhost', Answers::defaults(Detection::none(), $context->folderName()))->save($context->basePath);
    File::ensureDirectoryExists($context->path(WizardState::STEPS));
    $result = $context->path(WizardState::STEPS.'/git.json');
    Process::fake(["*git' 'rev-parse'*" => Process::result('true'), "*git'*" => Process::result('')]);

    $exitCode = Artisan::call('skv:run-step', ['step' => 'git', '--result' => $result]);

    expect($exitCode)->toBe(0)
        ->and(StateFile::readJson($result)['status'] ?? null)->toBeIn(['done', 'skipped', 'manual']);
});

it('refuses a result path outside storage/app/private and an unknown step (RL-5)', function (string $step, string $where): void {
    $context = setupSandbox();
    app()->instance(SetupContext::class, $context);
    new WizardState('localhost', Answers::defaults(Detection::none(), $context->folderName()))->save($context->basePath);
    File::ensureDirectoryExists($context->path(WizardState::STEPS));

    expect(Artisan::call('skv:run-step', ['step' => $step, '--result' => $context->path($where)]))->toBe(1)
        ->and(file_exists($context->path($where)))->toBeFalse();
})->with([
    'outside' => ['git', 'public/result.json'],
    'unknown step' => ['nope', 'storage/app/private/skv-setup/nope.json'],
]);

it('runs the Herd step from the Herd state, applies the HTTPS consequence and rewrites the marker (RL-6, OD-2)', function (): void {
    $context = setupSandbox();
    app()->instance(SetupContext::class, $context);
    $context->answers = Answers::fromArray(['modules' => ['impersonation'], 'https' => true], Detection::none(), $context->folderName());
    Marker::write($context, []);
    HerdState::begin($context->basePath, $context, 'localhost');

    $exitCode = Artisan::call('skv:run-step', ['step' => 'herd-site', '--result' => $context->path(HerdState::PATH)]);
    $state = HerdState::load($context->basePath);

    expect($exitCode)->toBe(0)
        ->and($state?->result?->status)->toBe('skipped')
        ->and($state?->answers->https)->toBeFalse()
        ->and(Marker::read($context)['answers']['https'] ?? null)->toBeFalse();
});

it('leaves the Herd state and the marker alone when the page moved on while Herd ran (RL-6)', function (bool $used): void {
    $context = setupSandbox();
    app()->instance(SetupContext::class, $context);
    $context->answers = Answers::fromArray(['modules' => ['impersonation'], 'https' => true], Detection::none(), $context->folderName());
    Marker::write($context, []);
    [$state] = HerdState::begin($context->basePath, $context, 'localhost');

    if ($used) {
        HerdState::forget($context->basePath);
    } else {
        $state->result = StepResult::manual('herd secure '.$context->folderName(), 'Continued without HTTPS.');
        $state->save($context->basePath);
    }

    $marker = (string) file_get_contents($context->path(Marker::FILE));

    expect(Artisan::call('skv:run-step', ['step' => 'herd-site', '--result' => $context->path(HerdState::PATH)]))->toBe(0)
        ->and(file_get_contents($context->path(Marker::FILE)))->toBe($marker)
        ->and(HerdState::load($context->basePath)?->result?->message)->toBe($used ? null : 'Continued without HTTPS.');
})->with(['login used the secret' => [true], 'continued without HTTPS' => [false]]);
```

Add `Illuminate\Support\Facades\File` and `App\Setup\StepResult` to the file's imports.

**Check the Git fakes.** The fakes above must match what `GitStep` runs. Read `app/Setup/Steps/GitStep.php` and `tests/Feature/Setup/Steps/GitStepTest.php`, and copy that test's fake keys exactly.

**The Herd step's result file.** Its `--result` is the Herd state itself: the command writes the result **into** the state (see the implementation), not to a separate file. In the test, assert on `HerdState::load()`, as written.

Append to `tests/Feature/Setup/Steps/MarkerStepTest.php`:

```php
it('deletes the wizard state and its step files, and never follows a symlinked directory (RL-5)', function (): void {
    $context = setupSandbox();
    $outside = $context->path('outside');
    File::ensureDirectoryExists($outside);
    file_put_contents($outside.'/keep', 'x');
    symlink($outside, $context->path(WizardState::STEPS));
    new WizardState('localhost', $context->answers)->save($context->basePath);

    new MarkerStep()->run($context);

    expect(file_exists($context->path(WizardState::PATH)))->toBeFalse()
        ->and(is_link($context->path(WizardState::STEPS)))->toBeFalse()
        ->and($outside.'/keep')->toBeFile();
});
```

Also rewrite every literal `skv-setup…` / `skv-installed` path in `MarkerStepTest` and `Doctor/ChecksTest` to the constants (`SetupToken::PATH`, `WizardState::PATH`, `WizardState::STEPS`, `Marker::FILE`).

**`tests/Arch/NameLeakTest.php`:** replace the allow-list entry `'/skv-(installed|setup-token|setup|herd)\b/'` with:

```php
        '#skv-(?:installed|setup-token|setup\.json|herd\.(?:json|exit|log))\b|skv-setup/#', // the setup's state files (RL-5); tests use the constants
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Setup/Wizard tests/Feature/Console/RunStepCommandTest.php tests/Feature/Setup/Steps/MarkerStepTest.php tests/Arch/NameLeakTest.php`

Expected: FAIL.

- [ ] **Step 3: Implement**

`app/Setup/Wizard/WizardState.php`:

```php
<?php

declare(strict_types=1);

namespace App\Setup\Wizard;

use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\SetupContext;
use App\Setup\StepResult;
use App\Setup\Support\StateFile;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use JsonException;

/**
 * What the browser wizard knows between requests (RL-5): the pinned host, the answers (with the password's hash,
 * never the password), the results of steps 1–8 and the notes. Step 9 deletes it, together with the per-step files
 * of the detached steps in STEPS.
 */
final class WizardState
{
    public const string PATH = 'storage/app/private/skv-setup.json';

    public const string STEPS = 'storage/app/private/skv-setup';

    /**
     * @param  array<string, StepResult>  $results
     * @param  list<string>  $notes
     */
    public function __construct(public string $host, public Answers $answers, public array $results = [], public array $notes = []) {}

    /** @throws JsonException|InvalidArgumentException when the file is not a state this class wrote */
    public static function load(string $basePath): ?self
    {
        $data = StateFile::readJson(rtrim($basePath, '/').'/'.self::PATH);

        if ($data === null) {
            return null;
        }

        if (! is_string($data['host'] ?? null) || ! is_array($data['answers'] ?? null) || ! is_array($data['results'] ?? null) || ! is_array($data['notes'] ?? null)) {
            throw new InvalidArgumentException('Setup state: unexpected shape.');
        }

        /** @var array<string, mixed> $answers */
        $answers = $data['answers'];
        $results = [];

        foreach ($data['results'] as $key => $result) {
            if (! is_array($result)) {
                throw new InvalidArgumentException('Setup state: a result must be an object.');
            }

            /** @var array<string, mixed> $result */
            $results[(string) $key] = StepResult::fromArray($result);
        }

        return new self($data['host'], Answers::fromState($answers), $results, array_values(array_map(strval(...), $data['notes'])));
    }

    public static function forget(string $basePath): void
    {
        $root = rtrim($basePath, '/');
        @unlink($root.'/'.self::PATH);
        self::removeDirectory($root.'/'.self::STEPS);
    }

    /** A symlink is removed, never followed (RL-5). */
    public static function removeDirectory(string $directory): void
    {
        if (is_link($directory)) {
            unlink($directory);
        } elseif (is_dir($directory)) {
            File::deleteDirectory($directory);
        }
    }

    public function save(string $basePath): void
    {
        StateFile::writeJson(rtrim($basePath, '/').'/'.self::PATH, [
            'host' => $this->host,
            'answers' => $this->answers->toState(),
            'results' => array_map(fn (StepResult $result): array => $result->toArray(), $this->results),
            'notes' => $this->notes,
        ]);
    }

    public function context(string $basePath, Detection $detection): SetupContext
    {
        $context = new SetupContext($basePath, $this->answers, $detection);
        $context->results = $this->results;
        $context->notes = $this->notes;

        return $context;
    }

    public function absorb(SetupContext $context): void
    {
        $this->answers = $context->answers;
        $this->results = $context->results;
        $this->notes = $context->notes;
    }
}
```

**`app/Setup/Wizard/HerdState.php`** follows the same pattern:
- `begin()` builds the state from the context: `answers` → `Answers::fromState([...$context->answers->toPublicArray(), 'super_admin_password_hash' => null])`, so the hash never reaches this file. It also copies `results` and `notes`, and sets `startedAt: time()` and `secretHash: hash('sha256', $secret)` with `$secret = bin2hex(random_bytes(32))`. It saves and returns `[$state, $secret]`.
- `matches()` uses `hash_equals($this->secretHash, hash('sha256', $secret))`.
- `isFresh()` is `time() - $this->startedAt <= self::LIFETIME`.
- `save()` writes the `result` too (`null` or `toArray()`).
- `forget()` unlinks `PATH` and `EXIT` and leaves the log.
- Its class docblock names RL-6 and says the file holds no password hash.

Use `Date::now()->getTimestamp()` rather than `time()`, so `$this->travel()` in the test moves it.

`app/Setup/Wizard/StepLauncher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Setup\Wizard;

/** Starts a setup step outside the web request (RL-4, §5.3.4); tests bind an inline launcher. */
interface StepLauncher
{
    /** Starts `skv:run-step $key --result=$resultFile`, logging to $logFile and writing its exit code to $exitFile; false when it could not start. */
    public function start(string $basePath, string $key, string $resultFile, string $logFile, string $exitFile): bool;
}
```

`app/Setup/Wizard/DetachedStepLauncher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Setup\Wizard;

use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * nohup + & (DEC-23): the child outlives the request and Herd's nginx/php-fpm restarts. Every value is its own
 * argument ("$1"…), never part of the script, so no path or key reaches the shell as code. The child gets the
 * toolchain's environment, so it reads the current .env itself (RL-12).
 */
final readonly class DetachedStepLauncher implements StepLauncher
{
    /** Outer: $1 php, $2 key, $3 result, $4 log, $5 exit; inner: $0 php, $1 key, $2 result, $3 log, $4 exit. */
    private const string SCRIPT = 'nohup sh -c \'"$0" artisan skv:run-step "$1" --no-interaction --result="$2" > "$3" 2>&1; echo $? > "$4"\' "$1" "$2" "$3" "$4" "$5" > /dev/null 2>&1 &';

    public function __construct(private Toolchain $toolchain, private ?string $php = null) {}

    public function start(string $basePath, string $key, string $resultFile, string $logFile, string $exitFile): bool
    {
        $php = $this->php ?? $this->toolchain->phpCli();

        if ($php === null) {
            return false;
        }

        @mkdir(dirname($logFile), 0700, true);

        try {
            return Process::path($basePath)->env($this->toolchain->environment())->timeout(10)
                ->run(['sh', '-c', self::SCRIPT, 'sh', $php, $key, $resultFile, $logFile, $exitFile])
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
```

**`app/Console/Commands/RunStepCommand.php`:**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Setup\Detector;
use App\Setup\SetupContext;
use App\Setup\SetupRunner;
use App\Setup\SetupStep;
use App\Setup\StepResult;
use App\Setup\Steps\SummaryStep;
use App\Setup\Support\SafeMessage;
use App\Setup\Support\StateFile;
use App\Setup\Wizard\HerdState;
use App\Setup\Wizard\WizardState;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * One setup step for the browser wizard (RL-4): the detached long steps (frontend, Claude) and, after the marker, the
 * Herd step (RL-6). The context comes from the wizard state, or for the Herd step from the Herd state; the result is
 * written as JSON (the Herd step's into the Herd state). Never writes the wizard state, which only the page writes.
 */
#[Description('Run one setup step for the browser wizard (internal)')]
#[Signature('skv:run-step {step : The step key} {--result= : Where the result goes, inside storage/app/private}')]
final class RunStepCommand extends Command
{
    protected $hidden = true;

    public function handle(SetupRunner $runner, Detector $detector): int
    {
        $basePath = app(SetupContext::class)->basePath;
        $resultFile = $this->option('result');

        if (! is_string($resultFile) || $resultFile === '') {
            $this->components->error(__('The result must go inside storage/app/private.'));

            return self::FAILURE;
        }

        $private = realpath($basePath.'/storage/app/private');
        $directory = realpath(dirname($resultFile));

        if ($private === false || $directory === false || ! str_starts_with($directory.'/', $private.'/')) {
            $this->components->error(__('The result must go inside storage/app/private.'));

            return self::FAILURE;
        }

        $step = collect($runner->steps())->first(fn (SetupStep $step): bool => $step->key() === $this->argument('step'));

        if (! $step instanceof SetupStep) {
            $this->components->error(__('Unknown setup step.'));

            return self::FAILURE;
        }

        $herd = $step->key() === 'herd-site';
        $state = $herd ? HerdState::load($basePath) : WizardState::load($basePath);

        if ($state === null) {
            $this->components->error(__('There is no setup in progress.'));

            return self::FAILURE;
        }

        $context = $state->context($basePath, $detector->detect($state->host));

        try {
            $result = $runner->runStep($context, $step);
        } catch (Throwable $exception) {
            $result = StepResult::manual($herd ? 'herd secure '.$context->folderName() : 'php artisan skv:install', SafeMessage::of($exception));
        }

        if ($state instanceof HerdState) {
            // While Herd ran, the login may have used the secret up or the user continued without HTTPS (RL-6). Writing
            // now would bring the secret back and overwrite the user's choice in the marker.
            $current = HerdState::load($basePath);

            if ($current === null || $current->result !== null) {
                return self::SUCCESS;
            }

            // A Herd step that threw never reached runStep()'s consequence: the site is not secured (OD-2).
            if (! isset($context->results[$step->key()])) {
                $context->results[$step->key()] = $result;
                $runner->applyHttpsConsequence($context);
            }

            $runner->runStep($context, app(SummaryStep::class));
            $runner->finish($context, null);
            $state->absorb($context);
            $state->result = $result;
            $state->save($basePath);

            return self::SUCCESS;
        }

        StateFile::writeJson($resultFile, [...$result->toArray(), 'finished_at' => Date::now()->toIso8601String()]);

        return self::SUCCESS;
    }
}
```

**The child process.** `realpath(dirname($resultFile))` requires the directory to exist; the launcher's caller creates `WizardState::STEPS` first (Task 9). Running in the console, the child is already central (`CommandStarting` listener), so no `Tenant::bypass()` is needed here.

**`MarkerStep`:** delete `SetupToken::PATH` and `WizardState::PATH`, and remove `WizardState::STEPS` with `WizardState::removeDirectory()`. Replace its own path constants with these.

**`tests/Fixtures/InlineStepLauncher.php`:**

```php
<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Setup\Wizard\StepLauncher;
use Illuminate\Support\Facades\Artisan;

/** Runs a "detached" step in-process and at once, so tests never spawn a process (RL-4). */
final class InlineStepLauncher implements StepLauncher
{
    /** @var list<string> */
    public array $started = [];

    public bool $refuses = false;

    public function start(string $basePath, string $key, string $resultFile, string $logFile, string $exitFile): bool
    {
        if ($this->refuses) {
            return false;
        }

        $this->started[] = $key;
        $exitCode = Artisan::call('skv:run-step', ['step' => $key, '--result' => $resultFile, '--no-interaction' => true]);
        @mkdir(dirname($exitFile), 0700, true);
        file_put_contents($exitFile, (string) $exitCode);

        return true;
    }
}
```

**`useSetupWizard()`:** add `app()->instance(StepLauncher::class, new InlineStepLauncher);` (imports `App\Setup\Wizard\StepLauncher`, `Tests\Fixtures\InlineStepLauncher`).

**`AppServiceProvider`:** `$this->app->bind(StepLauncher::class, DetachedStepLauncher::class);`.

New lang keys:
- `The result must go inside storage/app/private.` = "Het resultaat moet in storage/app/private staan."
- `Unknown setup step.` = "Onbekende installatiestap."
- `There is no setup in progress.` = "Er loopt geen installatie."

- [ ] **Step 4: Run the tests to verify they pass**

Run: the command of Step 2, then `php artisan test --compact tests/Feature/Setup tests/Feature/Console tests/Arch`.

Expected: PASS.

- [ ] **Step 5: Gate and commit**

```bash
git add app tests lang
git commit -m "feat: add the wizard and herd state files, the detached step launcher and skv:run-step

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: `WizardRunner`: begin, advance, re-check and finish (RL-3, RL-4, RL-6)

**Files:**
- Create: `app/Setup/Wizard/WizardRunner.php`
- Test: `tests/Feature/Setup/Wizard/WizardRunnerTest.php`

**Interfaces:**
- Consumes: Tasks 1–8 (`SetupRunner::runStep()`, `InstallPreflight`, `SafeMessage`, `SetupHost`, `HerdTld`, `WizardState`, `HerdState`, `StepLauncher`, `EnsureSetupAccess::authorised()`).
- Produces: `WizardRunner`, resolved from the container:
  - `PAGE_STEPS = ['environment', 'database', 'herd-manifest', 'branding', 'frontend', 'git', 'claude', 'doctor']`
  - `defaults(string $requestHost): array{answers: Answers, host: ?string, suggestion: string}`
  - `begin(array<string, mixed> $input, string $requestHost): WizardState`, which throws `ValidationException` (also while a setup is already in progress)
  - `state(): ?WizardState`: a corrupt state file is deleted and reads as null
  - `advance(): ?array{key: string, state: 'done'|'running'|'failed', message: string}`: null once steps 1–8 all have a result
  - `recheck(string $key): void`
  - `finish(): string`: the Herd-stage secret
  - `settleWithoutHttps(HerdState $herd, string $message): void`: ends the Herd stage without a secured site (OD-2). Used by `finish()` when the launcher refuses, and by `HerdStage::continueWithoutHttps()` (Task 11).
  - `progress(): list<array{key: string, label: string, status: ?string, message: string, command: ?string, long: bool}>`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Setup/Wizard/WizardRunnerTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.3, §5.3.4, §7.2 */

use App\Setup\Marker;
use App\Setup\SetupToken;
use App\Setup\Support\Toolchain;
use App\Setup\Wizard\HerdState;
use App\Setup\Wizard\StepLauncher;
use App\Setup\Wizard\WizardRunner;
use App\Setup\Wizard\WizardState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
    $this->previousConnection = config('database.default');
});

// afterEach, not beforeApplicationDestroyed: RefreshDatabase reads database.default again at teardown.
afterEach(function (): void {
    DB::purge('setup');
    config(['database.default' => $this->previousConnection]);
});

it('refuses a host that does not belong to this project (A4, RL-3)', function (): void {
    useSetupWizard();

    expect(fn () => app(WizardRunner::class)->begin(['database' => 'sqlite'], 'example.com'))
        ->toThrow(ValidationException::class);
});

it('stores the answers with the password hash only (RL-5)', function (): void {
    $context = useSetupWizard();

    app(WizardRunner::class)->begin([
        'app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'super_admin',
        'super_admin_email' => 'owner@example.com', 'super_admin_password' => 'correct-horse-battery',
        'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false,
    ], 'localhost');

    expect(WizardState::load($context->basePath)?->host)->toBe('localhost')
        ->and((string) file_get_contents($context->path(WizardState::PATH)))->not->toContain('correct-horse-battery');
});

it('runs steps 1 to 8 one call at a time, the long ones detached (§5.3.4, RL-4)', function (): void {
    $context = useSetupWizard();
    $runner = app(WizardRunner::class);
    $runner->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost');
    Process::fake(['*' => Process::result('')]);

    $keys = [];

    while (($outcome = $runner->advance()) !== null) {
        expect($outcome['state'])->not->toBe('failed');
        $keys[] = $outcome['key'];
    }

    expect(array_values(array_unique($keys)))->toBe(WizardRunner::PAGE_STEPS)
        ->and(app(StepLauncher::class)->started)->toContain('frontend')
        ->and(WizardState::load($context->basePath)?->results)->toHaveKeys(WizardRunner::PAGE_STEPS);
});

it('reports a failing step without losing the earlier results, then retries it (§5.3.1)', function (): void {
    // Arrange: begin() with pgsql while no Postgres answers here, so the database step throws.
    $context = useSetupWizard();
    $runner = app(WizardRunner::class);
    $runner->begin(['app_name' => 'Acme', 'database' => 'pgsql', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost');
    config(['database.connections.pgsql.port' => 1]);

    expect($runner->advance()['key'] ?? null)->toBe('environment');

    $failed = $runner->advance();

    expect($failed['key'] ?? null)->toBe('database')
        ->and($failed['state'] ?? null)->toBe('failed')
        ->and(WizardState::load($context->basePath)?->results)->toHaveKey('environment')->not->toHaveKey('database');
});

it('marks a detached step that could not start as a manual command (RL-4)', function (): void {
    useSetupWizard();
    app(StepLauncher::class)->refuses = true;
    $runner = app(WizardRunner::class);
    $runner->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost');
    Process::fake(['*' => Process::result('')]);

    while (($outcome = $runner->advance()) !== null && $outcome['key'] !== 'frontend') {
    }

    expect($runner->state()?->results['frontend']->status ?? null)->toBe('manual');
});

it('finishes with the marker, a Herd state holding the secret\'s hash and the Herd step started (RL-6)', function (): void {
    $context = useSetupWizard();
    $runner = app(WizardRunner::class);
    $runner->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost');
    Process::fake(['*' => Process::result('')]);

    while ($runner->advance() !== null) {
    }

    $secret = $runner->finish();

    expect(Marker::exists($context))->toBeTrue()
        ->and(new SetupToken($context->basePath)->exists())->toBeFalse()
        ->and(WizardState::load($context->basePath))->toBeNull()
        ->and(HerdState::load($context->basePath)?->matches($secret))->toBeTrue()
        ->and(app(StepLauncher::class)->started)->toContain('herd-site');
});

it('refuses a second start while a setup is in progress (§5.3.4)', function (): void {
    $context = useSetupWizard();
    $runner = app(WizardRunner::class);
    $runner->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost');

    expect(fn () => $runner->begin(['app_name' => 'Other', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => [], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost'))
        ->toThrow(ValidationException::class)
        ->and(WizardState::load($context->basePath)?->answers->appName)->toBe('Acme');
});

it('applies the HTTPS consequence when the Herd step cannot start (OD-2, RL-6)', function (): void {
    $context = useSetupWizard();
    $runner = app(WizardRunner::class);
    $runner->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['passkeys', 'impersonation'], 'https' => true, 'playwright' => false, 'claude' => false], 'localhost');
    Process::fake(['*' => Process::result('')]);

    while ($runner->advance() !== null) {
    }

    app(StepLauncher::class)->refuses = true;
    $runner->finish();

    expect(Marker::read($context)['answers']['https'] ?? null)->toBeFalse()
        ->and(HerdState::load($context->basePath)?->result?->status)->toBe('manual')
        ->and((string) json_encode(Marker::read($context)))->toContain('herd secure '.$context->folderName());
});
```

`app(StepLauncher::class)` is the `InlineStepLauncher` that `useSetupWizard()` binds; `->started` and `->refuses` are its public properties. Larastan sees `StepLauncher`. Narrow the type with a local `/** @var InlineStepLauncher $launcher */`, or `expect(app(StepLauncher::class))->toBeInstanceOf(InlineStepLauncher::class)` first, and import `Tests\Fixtures\InlineStepLauncher`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Setup/Wizard/WizardRunnerTest.php`

Expected: FAIL (class missing).

- [ ] **Step 3: Implement**

`app/Setup/Wizard/WizardRunner.php`:

```php
<?php

declare(strict_types=1);

namespace App\Setup\Wizard;

use App\Setup\Answers;
use App\Setup\Detector;
use App\Setup\InstallPreflight;
use App\Setup\SetupContext;
use App\Setup\SetupHost;
use App\Setup\SetupRunner;
use App\Setup\SetupStep;
use App\Setup\StepResult;
use App\Setup\Steps\MarkerStep;
use App\Setup\Support\HerdTld;
use App\Setup\Support\SafeMessage;
use App\Setup\Support\StateFile;
use App\Setup\Support\Toolchain;
use App\Tenancy\Facades\Tenant;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Drives the setup steps for the browser wizard (§5.3.4, RL-4): one step per request, the long ones detached through
 * the launcher, every result kept in the wizard state, every call inside Tenant::bypass() (strict mode: the doctor
 * and the seeders read tenant models). Steps 9–11 follow in finish() and the Herd stage (RL-6).
 */
final readonly class WizardRunner
{
    /** @var list<string> */
    public const array PAGE_STEPS = ['environment', 'database', 'herd-manifest', 'branding', 'frontend', 'git', 'claude', 'doctor'];

    /** A detached step whose log has not moved for this long is reported as stuck. */
    private const int STALL_SECONDS = 1800;

    public function __construct(
        private SetupRunner $runner,
        private Detector $detector,
        private StepLauncher $launcher,
        private Toolchain $toolchain,
    ) {}

    /** @return array{answers: Answers, host: ?string, suggestion: string} the form's defaults, the pinned host (null: refused) and the URL to suggest */
    public function defaults(string $requestHost): array
    {
        [$host, $suggestion] = $this->host($requestHost);
        $context = app(SetupContext::class);

        return [
            'answers' => Answers::fromArray([], $this->detector->detect($host ?? 'localhost'), $context->folderName(), $this->installed($context)),
            'host' => $host,
            'suggestion' => $suggestion,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function begin(array $input, string $requestHost): WizardState
    {
        // A second tab or browser never resets a running setup: its answers and results stay (§5.3.4).
        if ($this->state() !== null) {
            throw ValidationException::withMessages(['host' => __('Setup is already running.')]);
        }

        [$host, $suggestion] = $this->host($requestHost);

        if ($host === null) {
            throw ValidationException::withMessages(['host' => __('Open the setup on :url.', ['url' => $suggestion])]);
        }

        $context = app(SetupContext::class);
        $detection = $this->detector->detect($host);
        $installed = $this->installed($context);
        $answers = Answers::fromArray($input, $detection, $context->folderName(), $installed);

        if (Tenant::bypass(fn (): bool => new InstallPreflight($context)->refusesDemoDefault($answers, $input, $installed, $detection))) {
            throw ValidationException::withMessages(['start_with' => __('A super admin already exists, so setup does not add the demo data by default. Pass "start_with": "demo" to add it anyway, or "super_admin" with your own login.')]);
        }

        $state = new WizardState($host, $answers);
        $state->save($context->basePath);

        return $state;
    }

    public function state(): ?WizardState
    {
        $basePath = app(SetupContext::class)->basePath;

        try {
            return WizardState::load($basePath);
        } catch (JsonException|InvalidArgumentException) {
            WizardState::forget($basePath);

            return null;
        }
    }

    /** @return array{key: string, state: 'done'|'running'|'failed', message: string}|null */
    public function advance(): ?array
    {
        set_time_limit(300);

        return Tenant::bypass(function (): ?array {
            $state = $this->state() ?? throw new RuntimeException(__('There is no setup in progress.'));
            $basePath = app(SetupContext::class)->basePath;
            $context = $state->context($basePath, $this->detector->detect($state->host));
            $context->syncRuntimeConfig();
            $step = $this->nextStep($state);

            if (! $step instanceof SetupStep) {
                return null;
            }

            if ($step->isLong()) {
                return $this->advanceLong($state, $context, $step);
            }

            try {
                $result = $this->runner->runStep($context, $step);
            } catch (Throwable $exception) {
                return ['key' => $step->key(), 'state' => 'failed', 'message' => SafeMessage::of($exception)];
            }

            $state->absorb($context);
            $state->save($basePath);

            return ['key' => $step->key(), 'state' => 'done', 'message' => $result->message];
        });
    }

    /** Forgets a step's result, so the next advance() runs it again (the page's "Re-check"). */
    public function recheck(string $key): void
    {
        $state = $this->state();

        if ($state === null || ! in_array($key, self::PAGE_STEPS, true)) {
            return;
        }

        unset($state->results[$key]);
        $basePath = app(SetupContext::class)->basePath;

        foreach (['json', 'log', 'exit', 'started'] as $extension) {
            @unlink($this->stepFile($basePath, $key, $extension));
        }

        $state->save($basePath);
    }

    /** Step 9 in this request, then step 10 detached (RL-6); returns the secret the page polls with. */
    public function finish(): string
    {
        return Tenant::bypass(function (): string {
            $state = $this->state() ?? throw new RuntimeException(__('There is no setup in progress.'));

            if ($this->nextStep($state) instanceof SetupStep) {
                throw new RuntimeException(__('Finish the earlier steps first.'));
            }

            $basePath = app(SetupContext::class)->basePath;
            $context = $state->context($basePath, $this->detector->detect($state->host));
            $context->syncRuntimeConfig();
            $this->runner->runStep($context, app(MarkerStep::class));

            [$herd, $secret] = HerdState::begin($basePath, $context, $state->host);
            $started = $this->launcher->start($basePath, 'herd-site', $basePath.'/'.HerdState::PATH, $basePath.'/'.HerdState::LOG, $basePath.'/'.HerdState::EXIT);

            if (! $started) {
                $this->settleWithoutHttps($herd, __('The Herd step could not start in the background.'));
            }

            return $secret;
        });
    }

    /**
     * Ends the Herd stage without a secured site (OD-2, RL-6): the Herd step could not start, or the user continued
     * without HTTPS. The HTTPS consequence applies, and the marker is rewritten with the manual `herd secure`.
     */
    public function settleWithoutHttps(HerdState $herd, string $message): void
    {
        Tenant::bypass(function () use ($herd, $message): void {
            $basePath = app(SetupContext::class)->basePath;
            $context = $herd->context($basePath, $this->detector->detect($herd->host));
            $context->results['herd-site'] = StepResult::manual('herd secure '.$context->folderName(), $message);
            $this->runner->applyHttpsConsequence($context);
            $this->runner->finish($context, null);
            $herd->absorb($context);
            $herd->result = $context->results['herd-site'];
            $herd->save($basePath);
        });
    }

    /** @return list<array{key: string, label: string, status: ?string, message: string, command: ?string, long: bool}> */
    public function progress(): array
    {
        $state = $this->state();
        $rows = [];

        foreach ($this->runner->steps() as $step) {
            if (! in_array($step->key(), self::PAGE_STEPS, true)) {
                continue;
            }

            $result = $state?->results[$step->key()] ?? null;
            $rows[] = [
                'key' => $step->key(),
                'label' => $step->label(),
                'status' => $result?->status,
                'message' => $result->message ?? '',
                'command' => $result?->command,
                'long' => $step->isLong(),
            ];
        }

        return $rows;
    }

    private function nextStep(WizardState $state): ?SetupStep
    {
        foreach ($this->runner->steps() as $step) {
            if (in_array($step->key(), self::PAGE_STEPS, true) && ! isset($state->results[$step->key()])) {
                return $step;
            }
        }

        return null;
    }

    /** @return array{key: string, state: 'done'|'running'|'failed', message: string} */
    private function advanceLong(WizardState $state, SetupContext $context, SetupStep $step): array
    {
        $basePath = $context->basePath;
        $key = $step->key();
        $resultFile = $this->stepFile($basePath, $key, 'json');

        if (is_file($resultFile)) {
            try {
                $result = StepResult::fromArray(StateFile::readJson($resultFile) ?? []);
            } catch (Throwable) {
                $result = StepResult::manual($this->fallbackCommand($key), __('The step wrote no readable result.'));
            }

            return $this->record($state, $context, $key, $result);
        }

        if (is_file($this->stepFile($basePath, $key, 'exit'))) {
            return $this->record($state, $context, $key, StepResult::manual($this->fallbackCommand($key), __('The step stopped without a result; see :log.', ['log' => WizardState::STEPS.'/'.$key.'.log'])));
        }

        $started = $this->stepFile($basePath, $key, 'started');

        if (is_file($started)) {
            $log = $this->stepFile($basePath, $key, 'log');
            $lastActivity = max((int) @filemtime($started), (int) @filemtime($log));

            return time() - $lastActivity > self::STALL_SECONDS
                ? $this->record($state, $context, $key, StepResult::manual($this->fallbackCommand($key), __('The step has not finished; see :log.', ['log' => WizardState::STEPS.'/'.$key.'.log'])))
                : ['key' => $key, 'state' => 'running', 'message' => ''];
        }

        if (! $context->force && $step->isComplete($context)) {
            return $this->record($state, $context, $key, StepResult::skipped(__('Already done')));
        }

        StateFile::write($started, (string) time());

        if (! $this->launcher->start($basePath, $key, $resultFile, $this->stepFile($basePath, $key, 'log'), $this->stepFile($basePath, $key, 'exit'))) {
            return $this->record($state, $context, $key, StepResult::manual($this->fallbackCommand($key), __('The step could not start in the background.')));
        }

        return ['key' => $key, 'state' => 'running', 'message' => ''];
    }

    /** @return array{key: string, state: 'done', message: string} */
    private function record(WizardState $state, SetupContext $context, string $key, StepResult $result): array
    {
        $state->results[$key] = $result;
        $state->save($context->basePath);

        return ['key' => $key, 'state' => 'done', 'message' => $result->message];
    }

    private function stepFile(string $basePath, string $key, string $extension): string
    {
        return rtrim($basePath, '/').'/'.WizardState::STEPS.'/'.$key.'.'.$extension;
    }

    private function fallbackCommand(string $key): string
    {
        return $key === 'frontend' ? 'npm ci && npm run build' : 'php artisan skv:install';
    }

    /** @return array{0: ?string, 1: string} the pinned host or null, and the address to suggest */
    private function host(string $requestHost): array
    {
        $folder = app(SetupContext::class)->folderName();
        $tld = HerdTld::read($this->toolchain);

        return [SetupHost::forWizard($requestHost, $tld, $folder), 'http://'.($tld === null ? 'localhost' : Str::lower($folder).'.'.$tld)];
    }

    /** @return array<string, mixed> */
    private function installed(SetupContext $context): array
    {
        try {
            return new InstallPreflight($context)->installedAnswers();
        } catch (JsonException) {
            return [];
        }
    }
}
```

**Notes for the implementer:**
- **Use the date facade.** Replace `time()` with `Date::now()->getTimestamp()` throughout the class so tests can travel.
- **The page passes the request host.** `begin()` takes the host of the page's Livewire request (`request()->getHost()`).
- **Reuse the existing key.** The demo-refusal message is the CLI's (DEC-30 (29)); no new copy.
- **`RuntimeException` gets no catch here.** The page turns one into a failure message.

New lang keys:
- `Open the setup on :url.` = "Open de installatie op :url."
- `Finish the earlier steps first.` = "Rond eerst de eerdere stappen af."
- `Setup is already running.` = "De installatie loopt al."
- `The Herd step could not start in the background.` = "De Herd-stap kon niet op de achtergrond starten."
- `The step could not start in the background.` = "De stap kon niet op de achtergrond starten."
- `The step wrote no readable result.` = "De stap schreef geen leesbaar resultaat."
- `The step stopped without a result; see :log.` = "De stap stopte zonder resultaat; zie :log."
- `The step has not finished; see :log.` = "De stap is niet klaar; zie :log."

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Setup/Wizard`, then `composer test:pgsql`.

Expected: PASS.

- [ ] **Step 5: Gate and commit**

```bash
git add app tests lang
git commit -m "feat: drive the setup steps one request at a time for the browser wizard

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: The wizard page and the §7.2 wizard tests (§5.3.2, §5.3.4, §7.2)

**Files:**
- Modify: `resources/views/pages/setup/wizard/wizard.php`, `resources/views/pages/setup/wizard/wizard.blade.php`, `resources/setup/setup.css` (regenerated), `routes/setup.php`, `tests/Feature/Tenancy/RouteHostMatrixTest.php`
- Create: `app/Http/Controllers/Setup/SetupFinishController.php` (a stub Task 11 fills)
- Test: `tests/Feature/Setup/Wizard/WizardPageTest.php`

**Interfaces:**
- Consumes: `WizardRunner` (Task 9), `EnsureSetupAccess::authorised()` (Task 7).
- Produces: the page `pages::setup.wizard`, in four stages:
  - **questions:** the form; action `start()`
  - **running:** steps 1–8; actions `advance()` (polled), `recheck(string $key)`
  - **review:** steps 1–8 all have a result and at least one is `manual`: the commands stay on screen with their "Re-check" until the user clicks "Finish setup" (`complete()`), because step 9 closes the gate (§5.3.1)
  - **herd:** the Alpine poll of RL-6; no Livewire requests
  - Markers: `data-test="setup-wizard"`, `setup-questions`, `setup-step-{key}`, `setup-manual-{key}`, `setup-failure`, `setup-review`, `setup-complete`, `setup-herd-stage`, and for the browser tests `setup-database-{pgsql,sqlite}`, `setup-start-{demo,super_admin}`, `setup-start-button`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Setup/Wizard/WizardPageTest.php`. It starts with the same `beforeEach` / `afterEach` as `WizardRunnerTest` (process faking, sandbox toolchain, `database.default` saved and restored). Then:

```php
/** Opens the wizard over HTTP with the token, as a browser would, and returns its snapshot. */
$open = function (SetupContext $context): array {
    get('/welcome-setup?token='.new SetupToken($context->basePath)->value());

    return pageSnapshot(url('/welcome-setup'), 'pages::setup.wizard');
};

/**
 * The component's next snapshot and HTML from a Livewire update response.
 *
 * @return array{0: array<string, mixed>, 1: string}
 */
$next = function (TestResponse $response): array {
    /** @var array{components: list<array{snapshot: string, effects: array{html?: string}}>} $body */
    $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    /** @var array<string, mixed> $snapshot */
    $snapshot = json_decode($body['components'][0]['snapshot'], true, flags: JSON_THROW_ON_ERROR);

    return [$snapshot, $body['components'][0]['effects']['html'] ?? ''];
};

it('pre-fills the §5.3.2 questions (§5.3.2)', function (): void {
    $context = useSetupWizard();
    $this->get('/welcome-setup?token='.new SetupToken($context->basePath)->value());

    $this->get('/welcome-setup')->assertOk()
        ->assertSee('data-test="setup-questions"', false)
        ->assertSee(Str::headline($context->folderName()))
        // The Herd stage arrives through a morph, which runs no script: its function must come with the first GET.
        ->assertSee('function setupHerdStage', false);
});

it('shows the validation errors of the answers (A15)', function () use ($open, $next): void {
    $snapshot = $open(useSetupWizard());

    [, $html] = $next(callPageAction($snapshot, 'start', updates: ['startWith' => 'super_admin', 'superAdminEmail' => 'not-an-email'])->assertOk());

    expect($html)->toContain('data-test="setup-questions"')->toContain('email');
});

it('completes on SQLite with QUEUE_CONNECTION=database and no worker (§7.2)', function () use ($open, $next): void {
    $context = useSetupWizard();
    new EnvFile($context->path('.env'))->set(['QUEUE_CONNECTION' => 'database']);
    Process::fake(['*' => Process::result('')]);

    [$snapshot, $html] = $next(callPageAction($open($context), 'start', updates: [
        'appName' => 'Acme', 'database' => 'sqlite', 'startWith' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'claude' => false,
    ])->assertOk());

    // The page polls advance() every 2 s; the test calls it until the Herd stage renders. The sandbox has no node, so
    // the frontend step ends manual and the page stops at the review: the user clicks "Finish setup" there.
    for ($i = 0; $i < 20 && ! str_contains($html, 'data-test="setup-herd-stage"'); $i++) {
        $action = str_contains($html, 'data-test="setup-complete"') ? 'complete' : 'advance';
        [$snapshot, $nextHtml] = $next(callPageAction($snapshot, $action)->assertOk());
        $html = $nextHtml !== '' ? $nextHtml : $html;
    }

    expect($html)->toContain('data-test="setup-herd-stage"')
        ->and(Marker::exists($context))->toBeTrue()
        ->and(config('queue.default'))->toBe('sync');
});
```

Imports for this file: `App\Setup\{Marker, SetupContext, SetupToken}`, `App\Setup\Wizard\WizardState`, `App\Setup\Http\EnsureSetupAccess`, `App\Setup\Support\{EnvFile, Toolchain}`, `App\Setup\Detector`, `Illuminate\Support\Facades\{DB, Process}`, `Illuminate\Support\Str`, `Illuminate\Testing\TestResponse`, and `function Pest\Laravel\get;` (as `tests/Pest.php` uses it). Skipped steps render no `effects.html` when nothing changed: when `$html` comes back empty, keep the previous one, as the loop above does.

Add to the file:
- **"keeps the session across the environment step, so a new APP_NAME causes no 419 (§7.2)":** after the `environment` step, the next `advance()` answers 200, not 419. `SESSION_COOKIE` is fixed in `.env.example`; assert `new EnvFile($context->path('.env'))->get('SESSION_COOKIE')` is unchanged after the step.
- **"completes on PostgreSQL without a 500 or 419 (§7.2)":** use `->skip(! app(Detector::class)->postgresReachable(), 'PostgreSQL is not reachable')`. Make the sandbox with `setupSandbox(prefix: 'setup_probe_')`, pass it to `useSetupWizard()`, and drop the probe databases in `afterEach` as `DatabaseStepTest` does: copy its `$postgres` closure and cleanup.
- **"shows a manual command with a re-check (§5.3.1)":** a sandbox with no node, so the frontend step returns `manual`. The page shows `data-test="setup-manual-frontend"` with the command. `recheck('frontend')` clears it, and the next `advance()` sets it again. After the last step the page shows `setup-review` with `setup-manual-frontend` still on it, and no `setup-herd-stage` (no marker yet) until `complete` is called.
- **"turns a lost setup state into a failure message, not a 500 (§5.3.1)":** after `start`, delete `$context->path(WizardState::PATH)`. `advance` answers 200, and its HTML contains `data-test="setup-failure"`.
- **"ignores a second start (§5.3.4)":** after `start`, call `start` again on the new snapshot with another `appName`. The stored state keeps the first answers.
- **"refuses every action without the session token (RL-2)":** `session()->forget(EnsureSetupAccess::SESSION_KEY)`, then `callPageAction($snapshot, 'advance')->assertNotFound()`.
- **"never stores the plaintext password (RL-5)":** after `start()` with `superAdminPassword`, neither the state file nor the response snapshot contains it. The property is reset.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Setup/Wizard/WizardPageTest.php`

Expected: FAIL (the shell has no form or actions).

- [ ] **Step 3: Implement**

`resources/views/pages/setup/wizard/wizard.php`:

```php
<?php

declare(strict_types=1);

use App\Setup\Answers;
use App\Setup\Http\EnsureSetupAccess;
use App\Setup\Support\SafeMessage;
use App\Setup\Wizard\WizardRunner;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * §5.3.4: the browser wizard. Questions, then steps 1–8 one request at a time (the long ones polled), then step 9 and
 * the Herd stage, which runs without Livewire because the gate closes with the marker (RL-6).
 */
new #[Layout('layouts::setup')] #[Title('Setup')] class extends Component
{
    public string $appName = '';

    public string $database = 'sqlite';

    public string $startWith = 'demo';

    public string $superAdminEmail = '';

    public string $superAdminPassword = '';

    /** @var list<string> */
    public array $modules = [];

    public bool $https = true;

    public bool $playwright = false;

    public bool $claude = false;

    /** questions | running | review | herd */
    #[Locked]
    public string $stage = 'questions';

    #[Locked]
    public ?string $hostProblem = null;

    #[Locked]
    public ?string $failure = null;

    #[Locked]
    public ?string $herdSecret = null;

    public function mount(WizardRunner $runner): void
    {
        $this->authorise();

        if ($runner->state() !== null) {
            $this->stage = 'running';

            return;
        }

        $defaults = $runner->defaults(request()->getHost());
        $this->hostProblem = $defaults['host'] === null ? __('Open the setup on :url.', ['url' => $defaults['suggestion']]) : null;
        $this->fill($this->formOf($defaults['answers']));
    }

    public function start(WizardRunner $runner): void
    {
        $this->authorise();

        if ($this->stage !== 'questions') {
            return;
        }

        try {
            $runner->begin([
                'app_name' => $this->appName,
                'database' => $this->database,
                'start_with' => $this->startWith,
                'super_admin_email' => $this->startWith === 'super_admin' ? $this->superAdminEmail : null,
                'super_admin_password' => $this->startWith === 'super_admin' && $this->superAdminPassword !== '' ? $this->superAdminPassword : null,
                'modules' => $this->modules,
                'https' => $this->https,
                'playwright' => $this->playwright,
                'claude' => $this->claude,
            ], request()->getHost());
        } finally {
            $this->superAdminPassword = '';
        }

        $this->stage = 'running';
    }

    public function advance(WizardRunner $runner): void
    {
        $this->authorise();

        if ($this->stage !== 'running') {
            return;
        }

        try {
            $outcome = $runner->advance();
        } catch (RuntimeException $exception) {
            $this->failure = SafeMessage::of($exception);

            return;
        }

        if ($outcome === null) {
            // A manual command stays on screen with its Re-check until the user finishes: step 9 closes the gate (§5.3.1).
            if (collect($runner->progress())->contains('status', 'manual')) {
                $this->stage = 'review';

                return;
            }

            $this->finishSetup($runner);

            return;
        }

        $this->failure = $outcome['state'] === 'failed' ? $outcome['message'] : null;
    }

    /** "Finish setup" on the review: step 9, then the Herd stage. */
    public function complete(WizardRunner $runner): void
    {
        $this->authorise();

        if ($this->stage === 'review') {
            $this->finishSetup($runner);
        }
    }

    public function retry(WizardRunner $runner): void
    {
        $this->failure = null;
        $this->stage === 'review' ? $this->complete($runner) : $this->advance($runner);
    }

    public function recheck(WizardRunner $runner, string $key): void
    {
        $this->authorise();

        if (! in_array($this->stage, ['running', 'review'], true)) {
            return;
        }

        $runner->recheck($key);
        $this->failure = null;
        $this->stage = 'running';
    }

    /** @return list<array{key: string, label: string, status: ?string, message: string, command: ?string, long: bool}> */
    #[Computed]
    public function steps(): array
    {
        return app(WizardRunner::class)->progress();
    }

    private function finishSetup(WizardRunner $runner): void
    {
        try {
            $this->herdSecret = $runner->finish();
            $this->stage = 'herd';
        } catch (RuntimeException $exception) {
            $this->failure = SafeMessage::of($exception);
        }
    }

    /** The gate again, also on every update (the persistent middleware covers routing; this covers the action). */
    private function authorise(): void
    {
        abort_unless(EnsureSetupAccess::authorised(request()), 404);
    }

    /** @return array<string, mixed> */
    private function formOf(Answers $answers): array
    {
        return [
            'appName' => $answers->appName,
            'database' => $answers->database,
            'startWith' => $answers->startWith,
            'superAdminEmail' => (string) $answers->superAdminEmail,
            'modules' => $answers->modules,
            'https' => $answers->https,
            'playwright' => $answers->playwright,
            'claude' => $answers->claude,
        ];
    }
};
```

**Mind the action signature.** `recheck(WizardRunner $runner, string $key)` puts the injected service before the parameter. Livewire resolves class-typed parameters from the container and passes the call's arguments for the rest. Check Livewire 4's method dependency injection order in `vendor/livewire/livewire/src/Mechanisms/HandleComponents` (look for `ImplicitlyBoundMethod` / `BoundMethod`). If it does not support this, resolve the runner inside the method with `app(WizardRunner::class)` for every action, and take only scalar parameters.

**Errors from `start()`.** A `ValidationException` thrown inside it reaches Livewire, which fills the error bag. Map the `Answers` error keys to the form's property names, so `<flux:error name="appName" />` shows them: catch `ValidationException`, rebuild it with `ValidationException::withMessages()`, and pass the keys through `['app_name' => 'appName', 'database' => 'database', 'start_with' => 'startWith', 'super_admin_email' => 'superAdminEmail', 'super_admin_password' => 'superAdminPassword', 'modules' => 'modules', 'https' => 'https', 'playwright' => 'playwright', 'claude' => 'claude', 'answers' => 'host', 'host' => 'host']` (a key like `modules.0` maps through its prefix).

`resources/views/pages/setup/wizard/wizard.blade.php`:

```blade
<div data-test="setup-wizard" class="space-y-8">
    {{-- Outside every @if: the Herd stage arrives through a Livewire morph, and Livewire never runs a <script> that a morph inserts. --}}
    <script>
        function setupHerdStage(statusUrl, finishUrl, secret) {
            return {
                async poll() {
                    const query = '?secret=' + encodeURIComponent(secret);
                    const started = Date.now();

                    while (Date.now() - started < 60000) {
                        try {
                            const response = await fetch(statusUrl + query, { redirect: 'manual', headers: { Accept: 'application/json' } });

                            // Herd now answers http with a 301 to https (DEC-23): continue on the https origin.
                            if (response.type === 'opaqueredirect') {
                                window.location.href = 'https://' + window.location.host + finishUrl + query;
                                return;
                            }

                            if (response.ok && (await response.json()).state === 'done') {
                                window.location.href = finishUrl + query;
                                return;
                            }
                        } catch (error) {
                            // nginx is restarting; try again.
                        }

                        await new Promise((resolve) => setTimeout(resolve, 2000));
                    }

                    window.location.href = finishUrl + query + '&timeout=1';
                },
            };
        }
    </script>

    <div class="space-y-1">
        <flux:heading size="xl" level="1">{{ __('Set up :name', ['name' => $appName !== '' ? $appName : config('app.name')]) }}</flux:heading>
        <flux:text>{{ __('Answer a few questions; setup does the rest. You can change everything later.') }}</flux:text>
    </div>

    @if ($stage === 'questions')
        <form wire:submit="start" data-test="setup-questions" class="space-y-6">
            @if ($hostProblem !== null)
                <flux:callout variant="warning" icon="exclamation-triangle" :heading="$hostProblem" />
            @endif
            <flux:error name="host" />

            <flux:input wire:model="appName" :label="__('App name')" required />

            <flux:radio.group wire:model="database" :label="__('Database')">
                <flux:radio value="pgsql" label="PostgreSQL" data-test="setup-database-pgsql" />
                <flux:radio value="sqlite" label="SQLite" data-test="setup-database-sqlite" />
            </flux:radio.group>

            <flux:radio.group wire:model.live="startWith" :label="__('Start with')">
                <flux:radio value="demo" :label="__('Demo data')" data-test="setup-start-demo" />
                <flux:radio value="super_admin" :label="__('My own super admin')" data-test="setup-start-super_admin" />
            </flux:radio.group>

            @if ($startWith === 'super_admin')
                <flux:input wire:model="superAdminEmail" type="email" :label="__('Super admin email')" required />
                <flux:input wire:model="superAdminPassword" type="password" :label="__('Super admin password')" viewable />
            @endif

            <flux:checkbox.group wire:model="modules" :label="__('Modules')">
                <flux:checkbox value="passkeys" :label="__('Passkeys')" />
                <flux:checkbox value="backups" :label="__('Backups')" />
                <flux:checkbox value="impersonation" :label="__('Impersonation')" />
            </flux:checkbox.group>

            <flux:switch wire:model="https" :label="__('Use HTTPS')" />
            <flux:switch wire:model="playwright" :label="__('Install Playwright')" />
            <flux:switch wire:model="claude" :label="__('Set up Claude Code')" />

            <flux:button type="submit" variant="primary" :disabled="$hostProblem !== null" data-test="setup-start-button">{{ __('Start setup') }}</flux:button>
        </form>
    @elseif (in_array($stage, ['running', 'review'], true))
        {{-- keep-alive: Livewire drops 95% of polls in a background tab, and every step waits for one. --}}
        <div @if ($stage === 'running' && $failure === null) wire:poll.2s.keep-alive="advance" @endif class="space-y-3">
            @foreach ($this->steps as $step)
                <div data-test="setup-step-{{ $step['key'] }}" class="flex items-start justify-between gap-4 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <div class="space-y-1">
                        <flux:heading size="sm">{{ $step['label'] }}</flux:heading>
                        @if ($step['message'] !== '')
                            <flux:text size="sm">{{ $step['message'] }}</flux:text>
                        @endif
                        @if ($step['status'] === 'manual')
                            <div data-test="setup-manual-{{ $step['key'] }}" class="flex items-center gap-2" x-data>
                                <code class="rounded bg-zinc-100 px-2 py-1 text-sm dark:bg-zinc-800">{{ $step['command'] }}</code>
                                <flux:button size="xs" x-on:click="navigator.clipboard.writeText(@js($step['command']))">{{ __('Copy') }}</flux:button>
                                <flux:button size="xs" wire:click="recheck(@js($step['key']))">{{ __('Re-check') }}</flux:button>
                            </div>
                        @endif
                    </div>
                    <flux:badge size="sm" :color="match ($step['status']) { 'done' => 'green', 'skipped' => 'zinc', 'manual' => 'amber', default => 'sky' }">
                        {{ match ($step['status']) { 'done' => __('done'), 'skipped' => __('skipped'), 'manual' => __('manual'), default => __('waiting') } }}
                    </flux:badge>
                </div>
            @endforeach
        </div>

        @if ($stage === 'review')
            <div data-test="setup-review" class="space-y-3">
                <flux:text>{{ __('Run the commands above and re-check them, or finish setup now and run them later.') }}</flux:text>
                <flux:button variant="primary" wire:click="complete" data-test="setup-complete">{{ __('Finish setup') }}</flux:button>
            </div>
        @endif

        @if ($failure !== null)
            <flux:callout data-test="setup-failure" variant="danger" icon="x-circle" :heading="__('Setup stopped')" :text="$failure">
                <x-slot name="actions">
                    <flux:button wire:click="retry">{{ __('Try again') }}</flux:button>
                </x-slot>
            </flux:callout>
        @endif
    @else
        <div data-test="setup-herd-stage" class="space-y-3"
             x-data="setupHerdStage(@js(route('setup.herd-status', absolute: false)), @js(route('setup.finish', absolute: false)), @js($herdSecret))"
             x-init="poll()">
            <flux:heading size="lg">{{ __('Finishing the Herd site…') }}</flux:heading>
            <flux:text>{{ __('Herd may restart; this page continues by itself.') }}</flux:text>
        </div>
    @endif
</div>
```

**Notes for the implementer:**
- **Route order.** `setup.herd-status` and `setup.finish` come from Task 11. Until then, guard the herd branch with `@if (Route::has('setup.finish'))`, or do Task 11's route registration first in this task: add the three routes now, pointing at the controller that Task 11 fills in. Pick the latter: in this task, append Task 11's three routes to `routes/setup.php` (copy them from Task 11, Step 3), create `app/Http/Controllers/Setup/SetupFinishController.php` with the three methods `status`, `finish` and `login` that each `abort(404)`, and add the three route names to `RouteHostMatrixTest`'s `$excluded` (Task 11's comment). Task 11 then fills the controller and does not add the routes again.
- **Check the Flux components.** Use only free components, and check each one's props in `vendor/livewire/flux/stubs/resources/views/flux/`: `radio.group`, `checkbox.group`, `switch`, `callout` (its `actions` slot), `badge` colours, `input viewable`. `FluxProLayerTest` must stay green.
- **Escaping in `x-data`.** `@js()` produces safe JavaScript literals, and the secret is hex.
- **No `<script>` inside a stage branch.** The review and herd branches only ever reach the browser through a Livewire morph, and Livewire never executes a `<script>` that a morph inserts (it builds the new nodes with `template.innerHTML`). `setupHerdStage` therefore sits at the top of the root `<div>`, outside every `@if`, so the first full GET runs it.
- **`data-test` on Flux radios.** Check that `<flux:radio>` passes `data-test` through to the element it renders (`vendor/livewire/flux/stubs/resources/views/flux/radio/variants/default.blade.php`). If it does not, wrap each radio in a `<div data-test="…">`; the browser test clicks the selector.

Then run `npm run build:setup` and commit the regenerated `resources/setup/setup.css`.

New lang keys:
- `Answer a few questions; setup does the rest. You can change everything later.` = "Beantwoord een paar vragen; de installatie doet de rest. Je kunt alles later nog wijzigen."
- `Start setup` = "Installatie starten"
- `Copy` = "Kopiëren", if not present yet
- `Re-check` = "Opnieuw controleren"
- `Finish setup` = "Installatie afronden"
- `Run the commands above and re-check them, or finish setup now and run them later.` = "Voer de commando's hierboven uit en controleer ze opnieuw, of rond de installatie nu af en voer ze later uit."
- `waiting` = "wacht"
- `Setup stopped` = "Installatie gestopt"
- `Try again` = "Opnieuw proberen", if not present yet
- `Finishing the Herd site…` = "De Herd-site wordt afgerond…"
- `Herd may restart; this page continues by itself.` = "Herd kan herstarten; deze pagina gaat vanzelf verder."

Reuse the CLI's keys for the questions.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Setup/Wizard tests/Arch`, `composer test:pgsql`.

Expected: PASS.

- [ ] **Step 5: Gate and commit**

```bash
git add resources app routes tests lang
git commit -m "feat: add the setup wizard page with its questions, steps and polling

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: The Herd stage: status, finish, summary and the login handoff (§5.3.4, RL-6, OD-2)

**Files:**
- Create: `app/Setup/Wizard/HerdStage.php`, `resources/views/setup/finish.blade.php`, `resources/views/setup/finishing.blade.php`, `resources/views/setup/manual.blade.php`
- Modify: `app/Http/Controllers/Setup/SetupFinishController.php` (Task 10's stub; Task 10 already registered the routes and their matrix exclusions)
- Test: `tests/Feature/Setup/Wizard/HerdStageTest.php`

**Interfaces:**
- Consumes: `HerdState`, `WizardRunner::settleWithoutHttps()` (Task 9), `Marker`, `IssueHandoff`, `DirectRequest`.
- Produces: the routes `setup.herd-status`, `setup.finish` and `setup.login`. `HerdStage` provides:
  - `state(Request $request): HerdState`, which aborts with 404
  - `continueWithoutHttps(HerdState $state): void`
  - `summary(): array`, the marker's summary
  - `loginUrl(HerdState $state): string`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Setup/Wizard/HerdStageTest.php`. It uses the process-faking `beforeEach`; every test calls `$context = useSetupWizard();` and then writes the marker and a Herd state as `finish()` would:

```php
/** The state finish() leaves: the marker, and a Herd state; returns the secret. */
$afterStepNine = function (SetupContext $context, string $startWith = 'demo'): string {
    $context->answers = Answers::fromArray(
        $startWith === 'demo' ? ['modules' => ['impersonation'], 'https' => true] : ['start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com', 'super_admin_password' => 'correct-horse-battery', 'modules' => ['impersonation'], 'https' => true],
        Detection::none(),
        $context->folderName(),
    );
    Marker::write($context, Summary::build($context));
    @unlink(new SetupToken($context->basePath)->path());

    return HerdState::begin($context->basePath, $context, 'localhost')[1];
};

it('reports running until the Herd step has a result, then done (RL-6)', function () use ($afterStepNine): void {
    $secret = $afterStepNine(useSetupWizard());

    $this->getJson('/welcome-setup/herd-status?secret='.$secret)->assertOk()->assertExactJson(['state' => 'running']);

    $state = HerdState::load(app(SetupContext::class)->basePath) ?? throw new LogicException('No Herd state.');
    $state->result = StepResult::skipped('Herd is not installed');
    $state->save(app(SetupContext::class)->basePath);

    $this->getJson('/welcome-setup/herd-status?secret='.$secret)->assertExactJson(['state' => 'done']);
});

it('answers 404 to a wrong or stale secret, or behind a proxy (RL-6)', function () use ($afterStepNine): void {
    $secret = $afterStepNine(useSetupWizard());

    $this->getJson('/welcome-setup/herd-status?secret='.str_repeat('0', 64))->assertNotFound();
    $this->getJson('/welcome-setup/herd-status?secret='.$secret, ['X-Forwarded-For' => '1.2.3.4'])->assertNotFound();

    $this->travel(31)->minutes();

    $this->getJson('/welcome-setup/herd-status?secret='.$secret)->assertNotFound();
});

it('shows a finishing page while the Herd step runs, and the manual command after a timeout (RL-6)', function () use ($afterStepNine): void {
    $context = useSetupWizard();
    $secret = $afterStepNine($context);

    $this->get('/welcome-setup/finish?secret='.$secret)->assertOk()->assertHeader('Refresh', '2');
    $this->get('/welcome-setup/finish?secret='.$secret.'&timeout=1')->assertOk()
        ->assertSee('herd secure '.$context->folderName());
});

it('continues without HTTPS: APP_URL stays http and the marker says so (OD-2, RL-6)', function () use ($afterStepNine): void {
    $context = useSetupWizard();
    $secret = $afterStepNine($context);

    $this->get('/welcome-setup/finish?secret='.$secret.'&without_https=1')->assertOk()->assertSee('data-test="setup-summary"', false);

    expect(Marker::read($context)['answers']['https'] ?? null)->toBeFalse();
});

it('signs the new super admin in once through a handoff to the admin host (RL-6)', function () use ($afterStepNine): void {
    $context = useSetupWizard();
    $secret = $afterStepNine($context, 'super_admin');
    User::factory()->superAdmin()->create(['email' => 'owner@example.com']);
    $state = HerdState::load($context->basePath) ?? throw new LogicException('No Herd state.');
    $state->result = StepResult::skipped('Herd is not installed');
    $state->save($context->basePath);

    $location = (string) $this->get('/welcome-setup/login?secret='.$secret)->assertRedirect()->headers->get('Location');

    expect($location)->toStartWith(Hosts::url(Hosts::admin(), '/auth/handoff/'))
        ->and(HerdState::load($context->basePath))->toBeNull();

    $this->get('/welcome-setup/login?secret='.$secret)->assertNotFound();
});

it('sends a demo install to the login page (RL-6)', function () use ($afterStepNine): void {
    $context = useSetupWizard();
    $secret = $afterStepNine($context);
    $state = HerdState::load($context->basePath) ?? throw new LogicException('No Herd state.');
    $state->result = StepResult::skipped('Herd is not installed');
    $state->save($context->basePath);

    $this->get('/welcome-setup/finish?secret='.$secret)->assertOk()->assertSee('data-test="setup-summary"', false)->assertSee('password');
    $this->get('/welcome-setup/login?secret='.$secret)->assertRedirect(Hosts::url(Hosts::apex(), '/login'));
});
```

**Imports:** `App\Models\User`, `App\Setup\{Answers, Detection, Marker, SetupContext, SetupToken, StepResult, Summary}`, `App\Setup\Wizard\HerdState`, `App\Tenancy\Hosts`, `LogicException`.

Also add **"keeps the first answer when Herd finished while the user chose (RL-6)":** after `$afterStepNine`, give the state a `skipped` result and save it. Then `/welcome-setup/finish?secret=…&without_https=1` shows the summary, and the marker's `answers.https` is still `true`.

**Run the requests outside setup mode.** After `$afterStepNine` the marker exists, so the request runs in the normal web stack with the test's array session and cache. That is correct: it is the state after step 9.

**`RouteHostMatrixTest`:** Task 10 already added `'setup.herd-status'`, `'setup.finish'` and `'setup.login'` to `$excluded`; check they are there.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Setup/Wizard/HerdStageTest.php`

Expected: FAIL.

- [ ] **Step 3: Implement**

`app/Setup/Wizard/HerdStage.php`:

```php
<?php

declare(strict_types=1);

namespace App\Setup\Wizard;

use App\Actions\Auth\IssueHandoff;
use App\Models\User;
use App\Setup\Http\DirectRequest;
use App\Setup\Marker;
use App\Setup\SetupContext;
use App\Tenancy\Facades\Tenant;
use App\Tenancy\Hosts;
use Illuminate\Http\Request;
use InvalidArgumentException;
use JsonException;

/**
 * The wizard after step 9 (RL-6): the Herd step runs detached, the page polls with the secret only its browser holds,
 * and the new super admin signs in once through a handoff. Every endpoint is local, direct and secret-bound.
 */
final readonly class HerdStage
{
    public function __construct(private WizardRunner $wizard) {}

    public function state(Request $request): HerdState
    {
        abort_unless(DirectRequest::isDirect($request), 404);

        try {
            $state = HerdState::load(app(SetupContext::class)->basePath);
        } catch (JsonException|InvalidArgumentException) {
            abort(404);
        }

        $secret = $request->query('secret');
        abort_unless($state instanceof HerdState && is_string($secret) && $state->isFresh() && $state->matches($secret), 404);

        return $state;
    }

    /** "Continue without HTTPS": the Herd step gave no answer in time, so the HTTPS consequence applies now (OD-2). */
    public function continueWithoutHttps(HerdState $state): void
    {
        // The detached Herd step may have finished since this request loaded the state: its answer stands (RL-6).
        $current = HerdState::load(app(SetupContext::class)->basePath);

        if ($current === null || $current->result !== null) {
            return;
        }

        $this->wizard->settleWithoutHttps($current, __('Continued without HTTPS.'));
    }

    /** @return array<string, mixed> the marker's summary (§5.3.4: the summary is read from the marker) */
    public function summary(): array
    {
        return Marker::read(app(SetupContext::class))['summary'] ?? [];
    }

    /** The handoff for the installed super admin, or the login page for a demo; the secret is used up (RL-6). */
    public function loginUrl(HerdState $state): string
    {
        HerdState::forget(app(SetupContext::class)->basePath);

        if ($state->answers->startWith !== 'super_admin' || $state->answers->superAdminEmail === null) {
            return Hosts::url(Hosts::apex(), '/login');
        }

        $user = Tenant::bypass(fn (): ?User => User::query()->where('email', $state->answers->superAdminEmail)->first());
        abort_unless($user instanceof User && $user->isSuperAdmin(), 404);

        return app(IssueHandoff::class)->handle($user, Hosts::admin(), '/');
    }
}
```

`app/Http/Controllers/Setup/SetupFinishController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setup;

use App\Setup\SetupContext;
use App\Setup\Wizard\HerdStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

/** §5.3.4, RL-6: the Herd stage's endpoints; HerdStage holds the rules. */
final class SetupFinishController
{
    public function status(Request $request, HerdStage $stage): JsonResponse
    {
        return response()->json(['state' => $stage->state($request)->result === null ? 'running' : 'done']);
    }

    public function finish(Request $request, HerdStage $stage): Response|View
    {
        $state = $stage->state($request);
        $secret = (string) $request->query('secret');

        if ($state->result === null && $request->boolean('without_https')) {
            $stage->continueWithoutHttps($state);
        } elseif ($state->result === null) {
            return $request->boolean('timeout')
                ? view('setup.manual', [
                    'command' => 'herd secure '.app(SetupContext::class)->folderName(),
                    'continueUrl' => route('setup.finish', ['secret' => $secret, 'without_https' => 1], absolute: false),
                ])
                : response()->view('setup.finishing')->header('Refresh', '2');
        }

        return view('setup.finish', [
            'summary' => $stage->summary(),
            'loginUrl' => route('setup.login', ['secret' => $secret], absolute: false),
            'superAdminEmail' => $state->answers->startWith === 'super_admin' ? $state->answers->superAdminEmail : null,
        ]);
    }

    public function login(Request $request, HerdStage $stage): RedirectResponse
    {
        $state = $stage->state($request);
        abort_if($state->result === null, 404);

        return redirect()->away($stage->loginUrl($state));
    }
}
```

`routes/setup.php` (Task 10 appended these; they are shown here as the reference):

```php
// RL-6: the Herd stage, after the marker; each endpoint needs the secret only the wizard's browser holds.
Route::get('welcome-setup/herd-status', [SetupFinishController::class, 'status'])->middleware('throttle:120,1')->name('setup.herd-status');
Route::get('welcome-setup/finish', [SetupFinishController::class, 'finish'])->middleware('throttle:60,1')->name('setup.finish');
Route::get('welcome-setup/login', [SetupFinishController::class, 'login'])->middleware('throttle:10,1')->name('setup.login');
```

**The views.** All three use `<x-layouts::setup>`, free Flux and no Livewire component.

`setup/finishing.blade.php`:
- a heading `__('Finishing the Herd site…')` and `__('Herd may restart; this page continues by itself.')`
- no meta tags; the controller sends `Refresh: 2`

`setup/manual.blade.php`:
- `data-test="setup-manual-herd"`
- `__('Herd did not confirm HTTPS in time.')`
- the `$command` in a `<code>` with a copy button
- `__('Run it in a terminal, then reload this page, or continue without HTTPS (passkeys are then switched off).')`
- a link-button to `$continueUrl`: `__('Continue without HTTPS')`
- a reload link: the same URL without `timeout`, `__('Reload')`

`setup/finish.blade.php`:
- wrapper `data-test="setup-summary"`
- `__('Setup is done.')`
- the `$summary['urls']` as links
- the logins table (`email`, `password ?? '—'`, `url`)
- `$summary['manual']` (command with copy button, and reason)
- `$summary['notes']`
- one primary button to `$loginUrl`, labelled `__('Log in as :email', ['email' => $superAdminEmail])` when `$superAdminEmail` is set, else `__('Go to the login page')`

Escape everything with `{{ }}`. The manual reasons can hold process output (A22, A23).

Then `npm run build:setup` and commit `resources/setup/setup.css`.

New lang keys:
- `Continued without HTTPS.` = "Doorgegaan zonder HTTPS."
- `Herd did not confirm HTTPS in time.` = "Herd heeft HTTPS niet op tijd bevestigd."
- `Run it in a terminal, then reload this page, or continue without HTTPS (passkeys are then switched off).` = "Draai het in een terminal en laad deze pagina opnieuw, of ga verder zonder HTTPS (passkeys gaan dan uit)."
- `Continue without HTTPS` = "Verder zonder HTTPS"
- `Reload` = "Opnieuw laden"
- `Setup is done.` = "De installatie is klaar."
- `Log in as :email` = "Inloggen als :email"
- `Go to the login page` = "Naar de inlogpagina"

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Setup/Wizard tests/Feature/Tenancy/RouteHostMatrixTest.php`

Expected: PASS.

- [ ] **Step 5: Gate and commit**

```bash
git add app routes resources tests lang
git commit -m "feat: finish the wizard with a secret-bound herd stage, the summary and a single-use login

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: The local quick login (§6.5, DEC-29 (19), RL-9)

**Files:**
- Create: `app/Setup/QuickLogin.php`, `app/Http/Controllers/Auth/QuickLoginController.php`, `resources/views/components/quick-login.blade.php`
- Modify: `resources/views/pages/auth/login.blade.php`, `routes/web.php`, `app/Providers/AppServiceProvider.php` (binding), `app/Actions/Auth/IssueHandoff.php` (docblock only)
- Test: `tests/Feature/Auth/QuickLoginTest.php`

**Interfaces:**
- Consumes: `Marker::read()`, `DemoSeeder::accounts()`, `DemoSeeder::PASSWORD`, `IssueHandoff`.
- Produces:
  - `QuickLogin::accounts(): list<array{email: string, role: string, host: string}>`
  - `QuickLogin::handoffUrl(string $email): string|false|null`: null = refused (404); false = the password changed
  - `POST /quick-login`, named `quick-login`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Auth/QuickLoginTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §6.5 */

use App\Models\Organisation;
use App\Models\User;
use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\Marker;
use App\Setup\SetupContext;
use App\Setup\SetupMode;
use App\Setup\SetupToken;
use App\Tenancy\Hosts;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** A local demo install: the marker says demo and the demo accounts exist (password: the factory's "password"). */
$demoInstall = function (string $startWith = 'demo'): SetupContext {
    $context = setupSandbox();
    app()->instance(SetupContext::class, $context);
    // Setup mode reads the sandbox's marker and token, never the repository's own (the sandbox has a marker: mode off).
    app()->instance(SetupMode::class, new SetupMode($context->basePath));
    app()->instance(SetupToken::class, new SetupToken($context->basePath));
    app()->detectEnvironment(fn (): string => 'local');
    $context->answers = Answers::fromArray($startWith === 'demo' ? [] : ['start_with' => 'super_admin', 'super_admin_email' => 'o@example.com', 'super_admin_password' => 'correct-horse-battery'], Detection::none(), $context->folderName());
    Marker::write($context, []);

    foreach (DemoSeeder::SLUGS as $slug) {
        $organisation = Organisation::factory()->create(['slug' => $slug]);
        User::factory()->for($organisation)->create(['email' => "admin@{$slug}.".Hosts::apex()]);
    }

    return $context;
};

it('lists the demo accounts on the login page of a local demo install (§6.5)', function () use ($demoInstall): void {
    $demoInstall();

    $this->get(route('login'))->assertOk()
        ->assertSee('data-test="quick-login"', false)
        ->assertSee('admin@demo1.'.Hosts::apex());
});

it('shows no quick login outside a local demo install (§6.5, RL-9)', function (string $case) use ($demoInstall): void {
    $demoInstall($case === 'super admin install' ? 'super_admin' : 'demo');

    if ($case === 'not local') {
        app()->detectEnvironment(fn (): string => 'production');
    }

    if ($case === 'no marker') {
        unlink(app(SetupContext::class)->path(Marker::FILE));
    }

    $this->get(route('login'))->assertOk()->assertDontSee('data-test="quick-login"', false);
})->with(['not local', 'no marker', 'super admin install']);

it('hides an account with confirmed two-factor authentication or that is not active (RL-9)', function () use ($demoInstall): void {
    $demoInstall();
    // The query builder: User is a tenant model, and strict mode throws outside a tenant.
    DB::table('users')->where('email', 'admin@demo1.'.Hosts::apex())->update(['two_factor_confirmed_at' => now()]);

    $this->get(route('login'))->assertDontSee('admin@demo1.'.Hosts::apex());
});

it('hands a demo account over to its own host (§6.5, RL-9)', function () use ($demoInstall): void {
    $demoInstall();

    $location = (string) $this->post(route('quick-login'), ['email' => 'admin@demo1.'.Hosts::apex()])->assertRedirect()->headers->get('Location');

    expect($location)->toStartWith(Hosts::url(Hosts::tenant('demo1'), '/auth/handoff/'));
});

it('refuses an account whose password changed, and any other email (RL-9)', function () use ($demoInstall): void {
    $demoInstall();
    DB::table('users')->where('email', 'admin@demo2.'.Hosts::apex())->update(['password' => Hash::make('something-else')]);

    $this->post(route('quick-login'), ['email' => 'admin@demo2.'.Hosts::apex()])
        ->assertRedirect(route('login'))->assertSessionHas('status', __('This demo account no longer uses the demo password.'));
    $this->post(route('quick-login'), ['email' => 'someone@example.com'])->assertNotFound();
});

it('answers 404, not 403, to an account that may no longer enter its host (RL-9)', function () use ($demoInstall): void {
    $demoInstall();
    Organisation::query()->where('slug', 'demo1')->delete();

    $this->post(route('quick-login'), ['email' => 'admin@demo1.'.Hosts::apex()])->assertNotFound();
});

it('answers 404 to the quick login outside a local demo install (RL-9)', function () use ($demoInstall): void {
    $demoInstall();
    app()->detectEnvironment(fn (): string => 'production');

    $this->post(route('quick-login'), ['email' => 'admin@demo1.'.Hosts::apex()])->assertNotFound();
});
```

**The handoff URL in tests.** `Hosts::tenant('demo1')` must exist (check `Hosts` for the method name, else build `'demo1.'.Hosts::apex()`). `IssueHandoff` builds the URL from the current request's scheme and port; in tests that is `http://`, so `Hosts::url()` gives the same prefix.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Auth/QuickLoginTest.php`

Expected: FAIL.

- [ ] **Step 3: Implement**

`app/Setup/QuickLogin.php`:

```php
<?php

declare(strict_types=1);

namespace App\Setup;

use App\Actions\Auth\IssueHandoff;
use App\Enums\UserStatus;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Hash;
use JsonException;

/**
 * §6.5, RL-9: one click into a demo account, on a local demo install only. The accounts are DemoSeeder's; one that
 * is not active, has two-factor authentication or no longer uses the demo password is not offered (the handoff
 * skips the second factor by design).
 */
final readonly class QuickLogin
{
    public function __construct(private SetupContext $context) {}

    /** @return list<array{email: string, role: string, host: string}> */
    public function accounts(): array
    {
        if (! app()->isLocal()) {
            return [];
        }

        try {
            $marker = Marker::read($this->context);
        } catch (JsonException) {
            return [];
        }

        if (($marker['start_with'] ?? null) !== 'demo') {
            return [];
        }

        $accounts = DemoSeeder::accounts();
        $usable = Tenant::bypass(fn (): array => User::query()
            ->whereIn('email', array_column($accounts, 'email'))
            ->where('status', UserStatus::Active)
            ->whereNull('two_factor_confirmed_at')
            ->pluck('email')
            ->all());

        return array_values(array_filter($accounts, fn (array $account): bool => in_array($account['email'], $usable, true)));
    }

    /** The handoff into $email's own host; null when it is not offered (404), false when its password changed. */
    public function handoffUrl(string $email): string|false|null
    {
        $account = collect($this->accounts())->firstWhere('email', $email);

        if (! is_array($account)) {
            return null;
        }

        $user = Tenant::bypass(fn (): ?User => User::query()->where('email', $email)->first());

        if (! $user instanceof User) {
            return null;
        }

        if (! Hash::check(DemoSeeder::PASSWORD, (string) $user->getAuthPassword())) {
            return false;
        }

        // IssueHandoff refuses a user who may not enter the host (an archived or deleted organisation): RL-9 says 404.
        try {
            return app(IssueHandoff::class)->handle($user, $account['host']);
        } catch (AuthorizationException) {
            return null;
        }
    }
}
```

Add `use Illuminate\Auth\Access\AuthorizationException;` to the imports. `Organisation` is a central model, so the test's `Organisation::query()->…->delete()` needs no tenant; check it soft-deletes, and that `IssueHandoff::mayEnter()` then refuses.

**`IssueHandoff`'s docblock** says callers pass only the authenticated actor (FU3-1). Extend it: "Local-only exceptions: `App\Setup\QuickLogin` (a demo install, the demo password verified, no confirmed second factor) and `App\Setup\Wizard\HerdStage` (the installed super admin, bound to the Herd-stage secret)." Task 15 lists both in DEC-31.

**The binding.** It needs no binding beyond the container's: `SetupContext` is already bound. **Check** the `two_factor_confirmed_at` column name in the users migration and `UserStatus::Active`.

`app/Http/Controllers/Auth/QuickLoginController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Setup\QuickLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** §6.5, RL-9: the local quick login. */
final class QuickLoginController
{
    public function __invoke(Request $request, QuickLogin $quickLogin): RedirectResponse
    {
        $url = $quickLogin->handoffUrl($request->string('email')->toString());

        abort_if($url === null, 404);

        if ($url === false) {
            return to_route('login')->with('status', __('This demo account no longer uses the demo password.'));
        }

        return redirect()->away($url);
    }
}
```

**`routes/web.php`:** next to the handoff routes, add:

```php
// §6.5: the local quick login for the demo accounts (RL-9); QuickLogin decides, so it answers 404 elsewhere.
Route::post('quick-login', QuickLoginController::class)->middleware(['guest', 'throttle:10,1'])->name('quick-login');
```

`resources/views/components/quick-login.blade.php`:

```blade
@php($accounts = app(\App\Setup\QuickLogin::class)->accounts())

@if ($accounts !== [])
    <div data-test="quick-login" class="space-y-2 rounded-lg border border-dashed border-zinc-300 p-4 dark:border-zinc-600">
        <flux:heading size="sm">{{ __('Quick login (local only)') }}</flux:heading>
        @foreach ($accounts as $account)
            <form method="POST" action="{{ route('quick-login') }}">
                @csrf
                <input type="hidden" name="email" value="{{ $account['email'] }}" />
                <flux:button type="submit" size="sm" variant="ghost" class="w-full justify-start" data-test="quick-login-{{ str_replace(['@', '.'], '-', $account['email']) }}">
                    {{ $account['email'] }}
                    <span class="text-zinc-500">· {{ \App\Authorization\Role::tryFrom($account['role'])?->label() ?? $account['role'] }}</span>
                </flux:button>
            </form>
        @endforeach
    </div>
@endif
```

**`pages/auth/login.blade.php`:** render `<x-quick-login />` after the login form. **Check `Role::tryFrom()`:** the `Role` enum must be backed by these values (`super_admin`, `organisation_admin`). The demo's custom "Member" role is not a case; it then shows its name.

New lang keys:
- `Quick login (local only)` = "Snel inloggen (alleen lokaal)"
- `This demo account no longer uses the demo password.` = "Dit demo-account gebruikt het demowachtwoord niet meer."

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Auth tests/Feature/Tenancy`

Expected: PASS. `POST` routes stay out of the GET matrix.

- [ ] **Step 5: Gate and commit**

```bash
git add app routes resources tests lang
git commit -m "feat: add the local quick login for the demo accounts

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 13: The wizard and quick-login browser tests (§7.2)

**Files:**
- Create: `tests/Browser/SetupWizardTest.php`, `tests/Browser/QuickLoginTest.php`
- Modify: `tests/Pest.php` only if the browser binding needs the setup-mode hooks. Prefer not to.

**Interfaces:**
- Consumes: everything above; `useSetupWizard()`, `sameOrigin()`, `InlineStepLauncher`.

The browser suite serves the app in-process from the test's own container (`tests/Pest.php:71-80`). So the sandbox binding, `Process::fake()`, the inline launcher and a `database.default` switched by the Database step all hold inside the browser's requests. The host is `localhost` (`->withHost('localhost')`), so the wizard pins the apex `localhost` and the demo tenants become `demo1.localhost`. Chromium resolves those itself (M5, RL-2).

- [ ] **Step 1: Write the tests**

`tests/Browser/SetupWizardTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.3.4, §7.2 */

use App\Setup\Detector;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake(['*' => Process::result('')]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
    $this->previousConnection = config('database.default');
});

afterEach(function (): void {
    DB::purge('setup');
    config(['database.default' => $this->previousConnection]);
});

it('sets a demo install up on SQLite in the browser and signs in with the quick login (§7.2)', function (): void {
    useSetupWizard();

    $page = visit('/')->withHost('localhost');

    $page->assertSee(__('Start setup'))
        ->fill('appName', 'Acme')
        ->click('@setup-database-sqlite')
        ->click('@setup-start-button');

    // waitForText() takes no timeout: it retries for Playwright's 5 s. The wizard needs about ten polls, so wait in
    // steps while the in-process server answers them. The sandbox has no node: the page stops at the review.
    for ($i = 0; $i < 45 && ! str_contains($page->content(), 'data-test="setup-summary"'); $i++) {
        if (str_contains($page->content(), 'data-test="setup-complete"')) {
            $page->click('@setup-complete');
        }

        $page->wait(2);
    }

    $page->assertSee(__('Setup is done.'))
        ->assertSee('admin@demo1.localhost')
        ->click(__('Go to the login page'))
        ->assertSee(__('Quick login (local only)'))
        // GuessLocator strips every '@', so the selector holds none after the first.
        ->press('@quick-login-admin-demo1-localhost')
        ->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();
});

it('sets a super-admin install up on PostgreSQL and signs the owner in once (§7.2)', function (): void {
    $context = useSetupWizard(setupSandbox(prefix: 'setup_probe_'));

    $page = visit('/')->withHost('localhost');

    $page->fill('appName', 'Acme')
        ->click('@setup-database-pgsql')
        ->click('@setup-start-super_admin')
        ->fill('superAdminEmail', 'owner@example.com')
        ->fill('superAdminPassword', 'correct-horse-battery')
        ->click('@setup-start-button');

    for ($i = 0; $i < 60 && ! str_contains($page->content(), 'data-test="setup-summary"'); $i++) {
        if (str_contains($page->content(), 'data-test="setup-complete"')) {
            $page->click('@setup-complete');
        }

        $page->wait(2);
    }

    $page->assertSee(__('Setup is done.'))
        ->click(__('Log in as :email', ['email' => 'owner@example.com']))
        ->assertPathIs('/')
        ->assertNoJavaScriptErrors();
})->skip(fn (): bool => ! app(Detector::class)->postgresReachable(), 'PostgreSQL is not reachable');
```

**Fit the plugin's API.** Verified in `vendor/pestphp/pest-plugin-browser/src/Api/`: `fill`, `click`, `press`, `content()`, `wait(seconds)`, `assertPathIs` exist; `waitForText(string $text)` takes one argument and retries only for `Playwright::timeout()` (5 000 ms), so never pass it a timeout. `radio()` needs a `name` or `id`, which a Flux radio does not render: click the `data-test` selectors Task 10 added instead. `GuessLocator` strips every `@` from a selector. Check that `wait()` lets the in-process server answer the page's polls; if it blocks the server, use the plugin's own waiting helper instead and say so in the commit.

**The PostgreSQL test:**
- It drops its `setup_probe_*` databases in `afterEach`; copy `DatabaseStepTest`'s cleanup closure.
- After the handoff the owner lands on the admin host's `/`, which sends a super admin to the organisations index. So assert `assertPathIs('/organisations')`, or the path `routeMatrixOutcome()` gives a super admin on the admin host.
- `->skip()` with a closure is evaluated at runtime. If Pest 5 only accepts a bool there, compute it in `beforeEach` and call `$this->markTestSkipped()`.

`tests/Browser/QuickLoginTest.php`:
- the docblock `/** covers §6.5 */`
- a sandbox marker with `start_with=demo`, the local environment, and `demo1` with `admin@demo1.localhost` (as `QuickLoginTest` in Task 12 builds it, inline, including the `SetupMode` and `SetupToken` bindings on the sandbox)
- `visit('/login')->withHost('localhost')`, `->press('@quick-login-admin-demo1-localhost')`, then assert path `/dashboard` and the host `demo1.localhost` (`->url()`), with `assertNoJavaScriptErrors()`

- [ ] **Step 2: Run them**

Run: `npm run build && composer test:browser`

Expected: PASS. The PostgreSQL test passes locally with Herd's PostgreSQL, and is skipped where it is not reachable (the CI browser job).

- [ ] **Step 3: Gate and commit**

```bash
git add tests resources
git commit -m "test: drive the setup wizard and the quick login in the browser

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 14: The §7.2 phase-1 tests for the four entry points (OD-3, RL-11)

**Files:**
- Modify: `scripts/e2e-local.sh`, `.github/workflows/tests.yml`
- Test: `tests/Feature/Tooling/CiWorkflowTest.php`, `tests/Feature/Tooling/ComposerScriptsTest.php`

**Interfaces:**
- Produces: `scripts/e2e-local.sh [<target-dir>] [--https] [--entry=herd|terminal|terminal-nodb|composer|clone] [--phase1-only]`. The default `herd` keeps today's behaviour, then the wizard check, then phase 2 unless `--phase1-only`. The other entries always run phase 1 only. The controller's real-wizard run (Task 16) uses `--entry=herd --phase1-only`, so the wizard, not `skv:install`, does phase 2.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Tooling/ComposerScriptsTest.php`:

```php
it('ends composer setup with the CLI after the commands the clone route repeats (RL-11, §5.3.3)', function (): void {
    /** @var array{scripts: array{setup: list<string>}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $setup = $composer['scripts']['setup'];

    expect(array_slice($setup, 1, 3))->toBe([
        'composer install',
        "@php -r \"file_exists('.env') || copy('.env.example', '.env');\"",
        '@php artisan skv:bootstrap --no-interaction',
    ])->and(end($setup))->toBe('@php artisan skv:install');
});
```

Copy the exact `composer setup` strings from `composer.json`; the test pins today's value.

Append to `tests/Feature/Tooling/CiWorkflowTest.php`:

```php
it('checks the wizard after every entry point in the phase-1 job (§7.2, OD-3)', function (): void {
    $workflow = (string) file_get_contents(base_path('.github/workflows/tests.yml'));
    $script = (string) file_get_contents(base_path('scripts/e2e-local.sh'));

    expect($workflow)->toContain('  phase1:')
        ->toContain('entry: [terminal, terminal-nodb, composer, clone]')
        ->toContain('scripts/e2e-local.sh "$RUNNER_TEMP/phase1-app" --entry=${{ matrix.entry }}')
        ->and($script)->toContain('--entry=')
        ->toContain('--phase1-only')
        ->toContain('php artisan serve')
        ->toContain('/welcome-setup')
        ->toContain('skv-setup-token')
        ->not->toContain('composer config --global --list');
});
```

**Mind the name-leak allow-list.** `skv-setup-token` in the script is allowed: the narrowed allow-list of Task 8 covers it, and NameLeakTest scans `tests/`, not `scripts/`. Read the token path in the script from `php -r 'require "vendor/autoload.php"; echo App\Setup\SetupToken::PATH;'`. That pins it to the constant. Then assert `->toContain('SetupToken::PATH')` instead of the literal.

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test --compact tests/Feature/Tooling`

Expected: the CI and script assertions FAIL; the composer one passes.

- [ ] **Step 3: Implement**

**`scripts/e2e-local.sh`:**
- Add the `--entry=` option (default `herd`) to the argument loop. Validate it against the five names.
- For every entry except `herd`, the target may be any directory; the default stays `$HOME/Herd/m6a-e2e` only for `herd`. Use `$HOME/Herd/m6b-e2e` as the new default for `herd`, matching OD-4, and keep the `m6a` name working when passed explicitly.
- **Install per entry**, all with the temporary `COMPOSER_HOME` and the `vcs` repository the script already configures:
  - `herd`: today's `laravel new … --database=sqlite --npm --pest --git --boost` (Herd's flags).
  - `terminal`: `laravel new "$NAME" --using="$PACKAGE:dev-$BRANCH" --no-interaction --no-ansi --database=sqlite`.
  - `terminal-nodb`: the same without `--database`.
  - `composer`: `composer create-project "$PACKAGE:dev-$BRANCH" "$TARGET" --stability=dev --no-interaction`.
  - `clone` (RL-11): `git clone --quiet --branch "$BRANCH" "$REPO" "$TARGET"`, then in `$TARGET`: `composer install --no-interaction`, the `.env` copy of `composer setup`, and `php artisan skv:bootstrap --no-interaction`.
- **The phase-1 wizard check**, a function `wizard_check <base-url>`, runs for **every** entry:
  - `.env` exists and has an `APP_KEY=base64:` line (`grep -q`, never print it).
  - The token file exists. Read its path from `SetupToken::PATH` through `php -r`, and never print the token.
  - `curl -s -o /dev/null -w '%{redirect_url}' "$BASE/"` ends in `/welcome-setup?token=…`, compared without printing the token.
  - `curl -s -c "$JAR" -b "$JAR" -o /dev/null -w '%{http_code}' "$BASE/welcome-setup?token=$TOKEN"` is `302`.
  - `curl -s -c "$JAR" -b "$JAR" "$BASE/welcome-setup"` answers `200` and contains `data-test="setup-wizard"`.
  - For `composer` and `clone`, `public/build/manifest.json` must be absent before the check (the wizard renders without a build, §7.2). For the others, also run the check once with `public/build` moved aside and restored afterwards.
- **The base URL:**
  - `herd`: `http://$NAME.test`, Herd serves it.
  - Every other entry: start `php artisan serve --host=127.0.0.1 --port=$PORT` in the background in `$TARGET` on a free port (`php -r '$s=stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s,false))[1];'`). Wait until it answers, then use `http://127.0.0.1:$PORT`. Kill it in the script's one existing `EXIT` trap; a second `trap … EXIT` would replace the cleanup of the temporary `COMPOSER_HOME`. Extend it to `trap 'kill "${SERVE_PID:-}" 2>/dev/null || true; rm -rf "$COMPOSER_HOME" "$WORK/expected"' EXIT` (keep whatever it removes today).
- `herd` then continues with today's phase 2 (`skv:install`, the Boost check, `skv:doctor`) unless `--phase1-only` is given. The other entries end after phase 1, and print the cleanup line `rm -rf "$TARGET"`. With `--phase1-only` the script leaves the site as phase 1 left it: token present, no marker.
- `pass`/`fail` lines keep the `PASS: …` / `FAIL: …` format, so the summary logic stays.

**`.github/workflows/tests.yml`:** add a job `phase1`, placed **before** `e2e:`, with the same triggers, permissions and concurrency as `e2e`. Copy e2e's `runs-on`, `timeout-minutes`, `defaults` and these steps: Checkout, Changes, Setup PHP (with `php-version: '8.4'`, because the phase1 matrix has no `php`), Setup Node, "Serve the commit under test as dev-ci" (its `git switch -C ci` satisfies the script's branch check) and "Install the Laravel installer" (the script needs `laravel` on PATH). Do **not** copy the `services` block or the "Enable Flux Pro" step: `CiWorkflowTest` counts `secrets.FLUX_LICENSE_KEY }}` in the whole workflow and inside everything after `  e2e:`. Its `strategy.matrix` is `entry: [terminal, terminal-nodb, composer, clone]`, and its run step is:

```yaml
      - name: Phase 1 (${{ matrix.entry }})
        run: scripts/e2e-local.sh "$RUNNER_TEMP/phase1-app" --entry=${{ matrix.entry }}
```

`herd` is not a CI entry (no Herd on the runner). Keep `CiWorkflowTest`'s existing e2e pins green.

- [ ] **Step 4: Run them to verify they pass**

Run: `php artisan test --compact tests/Feature/Tooling` and `bash -n scripts/e2e-local.sh`.

Do **not** run the script; the controller does that in Task 16. Validate the workflow YAML with `php -r 'require "vendor/autoload.php"; var_dump(Symfony\Component\Yaml\Yaml::parseFile(".github/workflows/tests.yml") !== null);'`.

Expected: PASS.

- [ ] **Step 5: Gate and commit**

```bash
git add scripts .github tests
git commit -m "ci: check the setup wizard after each of the four entry points

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 15: Documentation, decisions and agent guidelines (§10, DEC-31)

**Files:**
- Modify: `README.md`, `CHANGELOG.md`, `docs/decisions.md` (DEC-31), `.ai/guidelines/skv3.md`, `docs/handleiding.html`

- [ ] **Step 1: README**
- **Quickstart.** Replace the "browser wizard arrives in M6b" line with the Herd route: *New site* → *Custom Starter Kit* → *Open site* → the wizard. Also describe the CLI route (`php artisan skv:install`) and the other entry points.
- **What the wizard does:**
  - the questions
  - the long steps running in the background
  - `manual()` commands with *Re-check*
  - the Herd stage that secures the site and signs the owner in
  - "Continue without HTTPS"
- **Local quick login:** the demo accounts on the login page, local demo installs only. An account whose password you changed drops out.
- **The setup files** under `storage/app/private` and `storage/logs/skv-herd.log`, with what deletes each.
- **`npm run build:setup`** and when to run it (after changing the setup views or the theme).
- **The doctor's `setup-token` fix** deletes the token (DEC-30 minor).
- Never print a token or a secret in the README examples.

- [ ] **Step 2: DEC-31 in `docs/decisions.md`**

Write DEC-31 "Setup wizard build rulings (M6b)" in the Question / Answer / Evidence / Consequence form of DEC-30:
- **Answer:**
  - OD-1…OD-4 and RL-1…RL-16 as built.
  - Every "Deviations from the brief" the task reports recorded. The controller passes them in the ledger.
  - The resolution of DEC-30's M6b open points: A4/A19 (RL-3), A5/A18 (`HerdTld`, timeouts), A6 (RL-8), A7 (RL-14), A8 (OD-2), A11/A12/A17/A25 (Task 1), A14 (RL-7), A20 (RL-16) and A22/A23 (escaped output, 0600 files).
  - **The deviations from the spec**, each with its reason:
    - setup mode also needs the token (RL-1)
    - the runtime applies to every web request (RL-1)
    - the secret-bound handoff replaces a signed URL (RL-6)
    - bootstrap prints `APP_URL`, not `/welcome-setup` (RL-10)
    - the clone route's phase-1 check stops before `skv:install` (RL-11)
    - two local-only exceptions to `IssueHandoff`'s rule that callers pass only the authenticated actor (FU3-1): `QuickLogin` (a demo install, the demo password verified, no confirmed second factor) and `HerdStage` (the installed super admin, bound to the Herd-stage secret) (RL-6, RL-9)
    - the wizard host is `{folder}.{tld}` or `admin.{folder}.{tld}` only, not any subdomain (RL-3)
    - steps 1–8 wait in a review when one ended manual, so its "Re-check" stays usable before step 9 closes the gate (§5.3.1)
  - The outcome of OD-4's check of `claude plugin install … --scope project` with a marketplace declared only in the project settings (Task 16), closing DEC-30's last open point.
- **Consequence:** what is deferred to M7 and M8. The *Scope* section's "Not in scope" lists it.

Also update DEC-30's Consequence: replace "Deferred to M6b: …" with "Built in M6b (DEC-31)".

- [ ] **Step 3: Guideline and changelog**

`.ai/guidelines/skv3.md`, Setup block:
- The wizard and the CLI drive the same steps through `SetupRunner::runStep()`. A new step implements `SetupStep` and sets `isLong()` honestly: a long step runs detached in the browser.
- Wizard code that reads tenant models lives in `App\Setup` (strict mode, `Tenant::bypass()`); the page calls services.
- Never write the setup state files by hand. Use `StateFile` and the classes' constants.
- Wizard tests start with `useSetupWizard()` and never spawn a process; the inline launcher runs detached steps.
- Run `npm run build:setup` after changing `resources/views/{layouts/setup.blade.php,pages/setup,setup}` or `resources/css/{theme,setup}.css`.

Then regenerate `AGENTS.md` the way Task 16 of M6a did: `php artisan boost:update --no-discover --ignore-skills --no-interaction`; it is gitignored.

`CHANGELOG.md` (Unreleased):
- the wizard
- the quick login
- setup mode
- `skv:run-step`
- `npm run build:setup`
- the HTTPS consequence without passkeys (OD-2)
- `APP_URL` with the secured name (RL-16)
- the test-database name for hyphenated folders (RL-14)
- child processes no longer inheriting `.env` (RL-12)

- [ ] **Step 4: `docs/handleiding.html`**

Update its install steps for the wizard, and its quick-login and status lines. Name the demo emails but never their password (DEC-30 (36)).

- [ ] **Step 5: Gate and commit**

```bash
git add README.md CHANGELOG.md docs .ai
git commit -m "docs: document the setup wizard and the quick login, and record DEC-31

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 16: Milestone gate, the phase-1 runs and the throwaway Herd site (OD-3, OD-4)

This task is the controller's. The workflow's final review and gate cover Step 1. Steps 2–4 change the owner's Herd setup and create PostgreSQL databases, so the controller runs them after the workflow, one command at a time, reporting each result.

- [ ] **Step 1: Full verification (superpowers:verification-before-completion)**

```bash
composer check
composer test:pgsql
php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser
composer test:browser
npm run build:setup && git diff --exit-code resources/setup/setup.css
git status --short
git show HEAD:composer.json | grep -c flux-pro; git show HEAD:composer.lock | grep -c flux-pro
grep -L "covers §" $(git diff --name-only --diff-filter=A main -- 'tests/**/*.php' | grep -v -e Fixtures -e Support)
```

Expected:
- four green suites
- an unchanged stylesheet
- a clean tree
- `0` and `0`
- an empty `grep -L`

- [ ] **Step 2: Phase 1 for the four entry points (OD-3)**

Run each entry once in the scratchpad, never in the repository:
- `scripts/e2e-local.sh "$SCRATCH/phase1-terminal" --entry=terminal`
- then `terminal-nodb`, `composer` and `clone` the same way

Each must print only `PASS` lines. Record the outputs in the ledger, then `rm -rf` each target directory.

- [ ] **Step 3: The real wizard on a throwaway Herd site (OD-4, §12 criteria 1 and 2)**

Configuration A, PostgreSQL + Herd Pro + super admin + HTTPS:
1. `scripts/e2e-local.sh "$HOME/Herd/m6b-e2e" --entry=herd --phase1-only`: phase 1 and the wizard check, with no `skv:install`.
2. Drive the wizard with Playwright headless (a script in the scratchpad, as in M5's smoke check). Open `http://m6b-e2e.test`, expect the wizard, and answer:
   - database PostgreSQL
   - start with super admin `owner@m6b-e2e.test` with a generated password, recorded in the scratchpad only
   - HTTPS on
   - Claude Code on, when `claude` is installed
3. Start. Expect every step `klaar`/`overgeslagen`, or a listed manual command.
4. Expect the Herd stage to continue by itself to `https://m6b-e2e.test/welcome-setup/finish`. The detached `herd secure` must have run, and `herd secured` must list `m6b-e2e.test`.
5. Click *Log in as*. Expect the admin host's organisations index on https.
6. Then: `php artisan skv:doctor --json` in `~/Herd/m6b-e2e` reports `"green": true`; `curl -sI http://m6b-e2e.test` answers `301`; `storage/app/private` holds no `skv-setup*` and no `skv-herd.json`.
   - The Claude step (DEC-30's open point): in `~/Herd/m6b-e2e`, `claude plugin list --json` shows the kit's plugin at project scope, and `.mcp.json` names `herd` and `laravel-boost`. If the install failed, the summary must list the manual `claude plugin install` command. Record which of the two happened, for DEC-31.
7. Record the timing from start to dashboard (§12 criterion 1), and every output, in the ledger.

Clean up A:
1. `herd unsecure m6b-e2e`
2. `herd unisolate --site=m6b-e2e`, only if `herd isolated` lists it
3. `rm -rf "$HOME/Herd/m6b-e2e"`
4. drop `m6b_e2e` and `m6b_e2e_test` with a one-off PHP PDO script on Herd's PostgreSQL (`root`, empty password), after checking that `pg_database` lists exactly those two names

Configuration B, SQLite + demo + HTTPS: repeat 1–7 with database SQLite, demo data and HTTPS on. After the summary, click *Go to the login page*, expect the quick login, sign in as `admin@demo1.m6b-e2e.test`, and expect the demo1 dashboard. Clean up as above (no databases to drop).

- [ ] **Step 4: Final review and hand-off**

The workflow's final review covers `main..m6b-setup-wizard`. Fix what Steps 2–3 find, test-first, then re-run Step 1.

Stop before merging: the owner merges. Update the memory notes: M6b done, M7 plan next, deferred minors.

---
