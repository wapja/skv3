# M7b Setup Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the follow-ups that M6a, M6b and M7a left for M7b. These cover:
- the setup engine, the wizard and its access gate
- the quick login and the Herd stage
- the setup's state files and the toolchain
- the doctor, `/health-check`, the backups, the scheduler and the System page
- the tests that cannot fail today

**Architecture:** M7b adds no new feature. Each task hardens one area and pins the hardening with a test that fails before the change. A few small shared pieces are introduced and reused across tasks:
- an atomic project-file writer
- a Detector probe seam for tests
- one ops-cache accessor
- one health-key rule
- a command-quoting helper
- an ops schedule class that the System page can read without booting the console kernel

Documentation, copy and DEC-33 come last. The controller then makes one real wizard run on a throwaway Herd site.

**Tech Stack:** PHP 8.4, Laravel 13.35, Livewire 4.4 (multi-file components), Flux 2.20 (free), spatie/laravel-backup 10.3, Pest 5.3 with pest-plugin-browser 5.1, Larastan 3 level 8, PostgreSQL 18 (Herd), SQLite. **No new dependency.**

**Spec:** `docs/superpowers/specs/2026-10-10-skv3-design.md`. Read these before starting:
- §5.2–§5.4 (setup, the wizard, the doctor)
- §4.9 (ops)
- §6.2 (route × host matrix)
- §6.5 (the System page)
- §7.2 (tests)
- §12 (acceptance)

Decisions in `docs/decisions.md`:
- DEC-24 (the direct-request rule)
- DEC-25 (3) (`composer setup` and Boost)
- DEC-29 (Flux Pro)
- DEC-30 and DEC-31 (the setup engine and the wizard)
- DEC-32 (M7a ops, CD-1…CD-10)

The rulings (RL-*) and the controller's decisions (CD-*) below are recorded as DEC-33 in Task 12. The owner delegated every non-destructive M7 decision to the controller on 2026-10-15. The owner can overrule any CD.

## Global Constraints

- **Versions:**
  - PHP floor `^8.4`; `laravel/framework ^13.35`; Pest `^5.3`.
  - Larastan level **8**, **no baseline**, no new `ignoreErrors` entries. This includes the multi-file component `.php` files under `resources/views/**`.
- **PHP files:** every one starts with `declare(strict_types=1);`. Pint uses the `laravel` preset plus `declare_strict_types`, `strict_comparison` and `ordered_class_elements`. Run `composer fix` after editing PHP.
- **Dependencies:**
  - M7b adds **none**, PHP or npm.
  - `livewire/flux-pro` and `composer.fluxui.dev` never appear in the committed `composer.json` or `composer.lock`.
- **Never touch the developer's own environment from code or tests:**
  - No test reads or writes the repository's `.env`, `.env.testing`, `herd.yml`, `.mcp.json`, `storage/app/skv-installed`, `storage/app/private/skv-*`, `storage/app/backups` or `storage/app/backup-temp`.
  - No test opens a network connection to the developer's PostgreSQL unless it is an explicit PostgreSQL test that skips when the server is unreachable (Task 1 adds the probe seam that keeps the rest off it).
  - Implementers never run `skv:install`, `skv:bootstrap`, `skv:run-step`, `skv:flux-pro`, `backup:run` or `backup:clean` in the repository itself.
  - Never run a mutating Herd command; read-only ones (`herd tld`, `herd secured`, `herd isolated`, `--help`) are fine.
- **External commands:**
  - Every external command the kit starts runs through `Process` with `Toolchain::environment()` (DEC-23), and `herd` by its absolute path.
  - Never `exec()`, `shell_exec()`, `system()`, `passthru()`, `proc_open()` or backticks, in `app/` or in `tests/` (Task 10 adds the arch rule).
  - Tests fake commands with `Process::preventStrayProcesses()` plus `Process::fake([...])` with named patterns. **Never** `Process::fake(['*' => …])` (Task 11 adds the arch rule).
- **Never print or log secrets:**
  - `HEALTH_CHECK_KEY`, `BACKUP_ARCHIVE_PASSWORD`, a super-admin password and its hash, `APP_KEY`, the Postgres password, the setup token and the Herd-stage secret, Composer credentials
  - Secrets never travel in argv (DEC-32 CD-8).
- **Never run against the development database:** no `migrate:fresh`, `db:wipe` or `db:seed`. `tinker`, `migrate` and `db:seed` prompt for approval.
- **Tenancy:**
  - `Tenant::bypass()` and scope removal only in `App\Setup`, `App\Console`, `App\Tenancy`, `App\Actions\Audit`, `Database\Seeders` and `app/Http/Controllers/HealthCheckController.php`.
  - Livewire pages never bypass.
- **Setup rules (`AGENTS.md` › Setup):**
  - Steps resolve files through `SetupContext::path()`.
  - Setup state files are written only through `App\Setup\Support\StateFile` and the classes' constants.
  - Wizard tests start with `useSetupWizard()` and go over HTTP (`pageSnapshot()` + `callPageAction()`); forged updates send `X-CSRF-TOKEN`.
  - After changing `resources/views/{layouts/setup.blade.php,pages/setup,setup}` or `resources/css/{theme,setup}.css`, run `npm run build:setup` and commit `resources/setup/setup.css`.
- **Ops rules (`AGENTS.md` › Ops):**
  - Web code builds doctor contexts with `DoctorContext::withoutDetection()`.
  - Ops caches go through the ops store (Task 8: `App\Support\Health\OpsCache::store()`).
  - Backup tests use `Storage::fake('backups')` and a sandbox `backup.backup.temporary_directory`.
  - Tests that assert command listeners use `WithConsoleEvents`.
- **Routes:**
  - Central pages use `Route::central()`; never `Route::domain()`.
  - Every new named GET route goes into `matrixRoutes()` in `tests/Feature/Tenancy/RouteHostMatrixTest.php` or its exclusions.
  - M7b adds no named GET route.
- **Authorization:**
  - Use the `App\Authorization\Permission` enum, never a permission string.
  - The System page authorises in `mount()` and in every action.
- **Names:**
  - No hard-coded kit or site name (`skv`) in app-identity values.
  - The allowed forms and the state-file names are listed in `tests/Arch/NameLeakTest.php`; Task 11 tightens it to exact names.
  - Tests set every `skv.*` key in its own `config(['skv.…' => …]);` call (one skv key per call, written first). No line under `tests/` or `app/` names `skv.` in any other form, apart from the allowed forms in `tests/Arch/NameLeakTest.php` (today `config('skv.…')` / `config(['skv.…` at the start of the call; Task 7 adds `->setting('X', 'skv.…')`).
- **Copy and lang keys:**
  - Code, comments and docs are in English. UI copy goes through `__()`, `nl` by default, with `en`.
  - Every key goes into **both** `lang/nl.json` and `lang/en.json`: identical keys, the same order (PHP `sort()` order, byte-wise), Dutch informal "je".
  - Glossary: Setup = Installatie, token = token (het), System = Systeem, Health = Gezondheid, Backup = Back-up, Check = Controle, Doctor = Diagnose (CD-12).
- **Livewire:**
  - Multi-file components only, no ⚡, never `$this->view()`.
  - Authorise in `mount()` and in every action. `#[Locked]` on every property the browser must not change.
- **Tests:**
  - Pest functions (`it(...)`) with typed closures. Each file starts with a `/** covers §x.y */` docblock as its first statement.
  - `Event::fake()` is never called bare.
  - **No `*Test.php` file declares a class (anonymous ones included) or spells a qualified class name outside its imports** (`tests/Arch/TestFileClassesTest.php`). Fixtures go in `tests/Fixtures`.
  - Tests are rewritten, never deleted (deleting a test needs the owner's approval). A test whose title promises more than it checks is either strengthened or retitled.
- **Global test helper names already taken (never redeclare):**
  - `acceptLinkFor`, `asMember`, `asOrgAdmin`, `asSuperAdmin`, `browserUser`, `callPageAction`, `confirmationLinkFor`, `dutchTranslationKeys`, `filesMatching`, `inviteMember`, `kitSourceFiles`
  - `onAdminHost`, `onApex`, `onTenant`, `pageSnapshot`, `recordedActivity`, `sameOrigin`, `setupSandbox`, `startImpersonatingOverHttp`, `useBackupsModule`, `useImpersonationModule`, `usePasskeysModule`, `useSetupWizard`
- **Global test helper reserved for M7b:** `withoutPostgresProbe` (Task 1, `tests/Pest.php`). Nothing else; file-local helpers are closures.
- **Gate per task:**
  - `composer check` (SQLite) green
  - `composer test:pgsql` green
  - the modules-off run: `php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser` green
  - `npm run build && composer test:browser` green
  - `npm run build:setup && git diff --exit-code resources/setup/setup.css` (only Tasks 2–4 may change the setup views; then commit the rebuilt stylesheet)
  - then `php artisan config:clear`
- **Commits:** Conventional Commits, lower case, imperative, ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. The pre-commit hook (Pint + Larastan on staged files) runs; never bypass it.
- **Never** run `herd sites` or the Herd MCP `get_all_sites`, and never read `.env` files (`.env.example` is fine).

## Conventions used in this plan

- All M7b work happens on branch `m7b-setup-hardening`, created from `main` (merge `2473d50`, "Merge M7a: ops"). The plan file, `docs/superpowers/plans/2026-10-16-m7b-setup-hardening.md`, is its first commit.
- Bracketed ids are traceability tags:
  - `RL-*`: this plan's rulings
  - `CD-*`: the controller's decisions, taken under the owner's delegation
  - follow-up ids (CD-1): `g1-NN`/`g2-NN` (M6a), `g3-NN`/`g4-NN` (M6b), `F1`–`F28`/`O1`–`O3` (the M7a final review), `L-NN` (the M7a build ledger). The triage table at the end of this section lists every id with its destination.
  - `DEC-*`: decision-log entries
- **Setup and console tests** start like `tests/Feature/Setup/Doctor/ChecksTest.php`:

  ```php
  beforeEach(function (): void {
      Process::preventStrayProcesses();
      Process::fake([]);
      $this->sandbox = setupSandbox();
      app()->instance(Toolchain::class, new Toolchain($this->sandbox->path('home')));
  });
  ```

  From Task 11 on, `tests/Pest.php` also calls `Process::preventStrayProcesses()` and `Process::fake([])` for every test under `Feature/Setup`, `Feature/Console`, `Feature/Backups`, `Feature/Health` and `Feature/System` (RL-12).
- **Process fake keys** use Symfony's single-quoted command line: `"*herd' 'secured'*"`, `"*'git' 'init'*"`. Bare spaces never match.
- **Doctor tests** build checks directly (`new MailCheck()->run($context)`) against `new DoctorContext($sandbox->basePath, new Toolchain($sandbox->path('home')), Detection::none(), null)`.
- **Wizard tests** use `useSetupWizard()`, `pageSnapshot()` and `callPageAction()` over HTTP, as `tests/Feature/Setup/Wizard/WizardPageTest.php` does.
- **Mutation check:** where a step says "verify by mutation", the implementer reverts the production change locally, confirms the new test fails, restores the change and confirms it passes. The commit contains the fixed code only. The task report names each mutation and its result.

## Scope

In scope: every follow-up the triage below sends to M7b, grouped into these tasks:

1. **Machine probes and state files:** a toolchain that survives a broken `.env` and parses it once; an injectable php-fpm mapping; a Detector probe seam for tests; state files that refuse symlinks and unreadable files; atomic project-file writes; private detached-step files.
2. **The setup gate and hosts:** loopback only; any `X-Forwarded-*`; the pinned host on every request; session regeneration; no token in `_previous.url`; a failing `herd tld`; folder names that are not DNS labels; IP hosts; the APP_URL port; the Referrer-Policy; a single title.
3. **Quick login and the Herd stage:** the array-email 500; the direct-request rule; second-factor accounts; the lock left behind; a launch failure that reaches the manual page; one manual Herd command; a stale exit file; quoted commands; the race guards' tests.
4. **The wizard runner:** the exit file first; a stalled step whose child still runs; detection once per setup; the remaining 500s; finish after the marker; the default demo choice; the tests of retry, poll and failure paths.
5. **Setup steps and seeders:** an unchanged password not re-applied; a secured site with an http APP_URL; `Password::default()`; one super-admin creator; translated step errors; one generated-secrets helper; the step contract dataset and the missing refusal tests.
6. **Install, bootstrap and the CLI:** environment allowlists; a non-string inline password; no password echo on a terminal; `answers.json` ignored; the command tests that cannot fail today.
7. **The doctor:** checks during a database outage; runtime settings for the web; the health-key rule; `apex` on the System page; pg_dump preference and ownership; the doctor command and core tests.
8. **`/health-check` and the ops store:** one ops-store accessor; split throttle buckets; 429 headers; a store that cannot be written; `/up`; the remaining endpoint and health tests.
9. **The scheduler and the System page:** an ops schedule class (no console kernel on web renders); an inert mutex for `active`; a page that survives a failing ops store or disk; an intl-free size; rate-limited actions; the remaining page tests.
10. **Backups:** the source connection default; `PGSSLMODE`; a private temp directory and a cleanup of stale dumps; a 12-hour overlap lock; a clean refusal line; no raw `exec()`; the vacuous and shadowable tests.
11. **Test hygiene and arch guards:** no catch-all process fakes; a directory-level stray-process guard; `NameLeakTest` over `app/` with exact names; setup pages without a Vite manifest; the e2e script; the browser wizard tests; the Flux Pro committed-files test in generated apps.
12. **Documentation, copy and DEC-33:** README, handleiding, CHANGELOG, the kit guideline (and `AGENTS.md`), the Dutch glossary drift, DEC-30/DEC-32 corrections and DEC-33.
13. **The controller's real verification:** one wizard run on a throwaway Herd site, then cleanup.

Not in scope:
- **M7c:** the §6.6 extras and the security hardening already listed in DEC-32's Consequence, plus four items from the M7a ledger:
  - L-46 (a `$member->exists` assertion that cannot fail)
  - L-48 (a queue worker keeping the console tag, untested)
  - L-49 (an untyped closure)
  - L-50 (jobs dispatched during a web request's `Artisan::call()` tagged as console)
- **M8:**
  - g1-24 (the e2e `tests/` diff for the `--phpunit` row)
  - g1-30 (the Herd deep link in the README)
  - g2-01 (CI's global Flux credentials)
  - g3-05 / L-59 (the browser suite's Trace warning)
  - g3-39 (the PostgreSQL browser wizard in CI)
  - L-06 (a PostgreSQL 18 client in CI)
- **Won't fix (CD-2):** g3-03, g3-04, O2, L-12, L-53, with the reasons in the triage table.

## Controller decisions (owner delegation, 2026-10-15)

- **CD-1, a fresh triage:** DEC-32 named the M7b scope as "A12–A56, A59, A61–A63, A65–A79 and T1–T8", but that numbering lived only in a working report that was lost when the session's context was compacted. M7b therefore re-triaged the source lists against the code on `main` `2473d50`:
  - the M6a and M6b final-review memory lists
  - the M7a final review (F1–F28, O1–O3)
  - the M7a build ledger's 66 deferred minors

  Six read-only agents verified every item: 221 entries after splitting bundled lines. 48 were already fixed (most by M6b's fix waves and M7a), 1 was obsolete and 15 duplicated another item. Of the rest, 141 are in scope here, 4 go to M7c, 7 to M8 and 5 are won't-fix. The ids in the triage table replace the A/T numbering. DEC-33 records the mapping.
- **CD-2, won't fix:**
  - **g3-03, `?secret=` in the Herd-stage URLs.** DEC-31 (6) keeps it there: single use, 30 minutes, local only. A cookie cannot cross the http→https origin change. The Referer leak is closed by g3-13.
  - **g3-04, a stale Claude plugin-registry entry.** It is Claude Code's machine state, not something the kit writes; the owner removes it by hand.
  - **O2, `/health-check` on tenant hosts and the SMTP address in its JSON.** Both are behind the key by design (DEC-32 RL-5). F15 hardens the key.
  - **L-12, a crashed repair's trace may log 15 characters of `.env`.** That needs `zend.exception_ignore_args=Off` plus a failing `EnvFile`, and the logs stay local.
  - **L-53, a duplicated test closure.** It is file-local by the M7a brief; two copies.
- **CD-3, where install and bootstrap run (L-60, L-61):**
  - `skv:install` runs only while `app()->environment()` is `local`, `testing` or `staging`, and refuses every other name with "skv:install runs only in the local, testing and staging environments (APP_ENV=:env)." This replaces DEC-32 CD-9's denylist on the literal `production`.
  - `skv:bootstrap` takes its production half when `.env` **or** the process environment (`getenv('APP_ENV')`, `$_SERVER['APP_ENV']`) says `production`.
- **CD-4, the health key and the throttle (F14, F15, L-28):**
  - **The key rule.** `App\Support\Health\HealthCheckKey` holds it, with `MIN_LENGTH = 32`. A configured key shorter than that counts as not configured: the endpoint answers 403. The doctor's `health-check-key` row fails ("HEALTH_CHECK_KEY is too short"), and its repair replaces the short key with `Str::random(40)`.
  - **The throttle** checks the key first:
    - Requests without a matching key count in one global bucket, `health-check:denied`, at 300 a minute, with 429 above that.
    - Requests with the matching key count per key hash, `health-check:ok:<hash('sha256', key)>`, at 60 a minute (`sha1` is banned in `App` by the security preset).
    - So the monitor is never locked out by strangers, and key-less traffic leaves one cache entry instead of one per address.
  - `EnvironmentStep::missingSecrets()` (RL-10) treats a `HEALTH_CHECK_KEY` that fails `HealthCheckKey::isUsable()` as missing, so setup replaces a short key too (Task 7 amends the helper from Task 5).
- **CD-5, runtime settings (F10):**
  - `DoctorContext` gets `public bool $runtime`, true only from `withoutDetection()`.
  - `DoctorContext::setting(string $envKey, string $configKey): ?string` reads the running configuration in a runtime context and `.env` otherwise.
  - `MailCheck` and `HealthCheckKeyCheck` use it. `skv:doctor` and setup keep reading `.env`, because they judge the file they are about to write.
- **CD-6, which `pg_dump` (F5, F13):**
  - `PG_DUMP_BINARY_PATH` still wins.
  - Otherwise, Herd's newest service binary is preferred over the toolchain PATH when the `pgsql` connection's host is `127.0.0.1`, `localhost` or `::1` and Herd's services exist, because then Herd's server is the one being dumped.
  - A Herd-glob candidate is accepted only when it and every directory up to `/Users/Shared/Herd` are owned by the current user or root and are not group- or world-writable.
  - No version probe: the System page must not start a process.
- **CD-7, a clean refusal (O1):** the app registers `App\Console\Commands\BackupRunCommand`, which extends spatie's `BackupCommand` under the same `backup:run` signature. The kernel's discovered commands register after the package's, so the app command wins the name.
  - It first resolves a `--config` option (`$this->config = $this->resolveConfig()`): the package swaps `config('backup')` for that configuration only inside `parent::handle()`, so the check below judges the configuration this run will use, and `backup:run --config=<other>` cannot write a plaintext archive.
  - While `BackupEncryption::available()` is false, it prints one error line (the M7a refusal text) and returns 1.
  - Otherwise it calls `parent::handle()`.
  - `RefuseUnencryptedBackups` stays as the backstop. `CommandStarting` fires before `handle()`, so the listener steps aside while `Artisan::findCommand('backup:run')` is the app's `BackupRunCommand`, and still throws when the package's command holds the name.
  - `BackupRunCommand` carries no `#[AsCommand]` attribute: a lazily registered command would lose the name to the package's eagerly added one. It inherits `$signature`.
- **CD-8, the ops schedule (F1, F8, L-39):**
  - `App\Support\Scheduler\OpsSchedule::define(Schedule $schedule): void` holds the ops events: eight after Task 9, nine once Task 10 adds `backup-temp-cleanup`. `routes/console.php` calls it.
  - `ScheduledTasks` builds a fresh `Schedule`, calls `define()` and evaluates each event's filters after `preventOverlapsUsing(new InertEventMutex())`. These are copies, not the events the scheduler runs. So a System page render never boots the console kernel and never touches a real overlap lock.
- **CD-9, sizes without intl (F20):** `App\Support\ByteSize::format(int|float $bytes): string` (1024-based, `B`, `KB`, `MB`, `GB`, `TB`, at most one decimal) replaces `Number::fileSize()`. An arch rule bans `Illuminate\Support\Number` (and `Number::`) in `app/` and `resources/views`, comments included.
- **CD-10, stale dumps and the overlap lock (F6, F18, L-07):**
  - `backup:run`, `backup:clean` and `backup:monitor` use `withoutOverlapping(720)`, so a killed run's lock has expired by the next night.
  - A `CommandStarting` listener for `backup:run`, `App\Support\Backups\PrivateBackupTemp`, creates the temporary directory with mode 0700, or chmods an existing one to 0700.
  - A scheduled closure `backup-temp-cleanup` runs hourly at :15 (`hourlyAt(15)`), also while the module is off, so a dump left behind before the module was switched off still goes (F18). It removes only files older than six hours under the package's working directory, `<temporary_directory>/temp`, where every file the package writes goes; files only, never directories, and nothing else in the temporary directory. Without that directory it does nothing. No new health check.
  - The cleanup runs on every server, without `onOneServer()`, because each server has its own temporary directory.
  - `PrivateBackupTemp` never changes the mode of, or empties, the system temporary directory, a sticky directory or one another account owns.
- **CD-11, AGENTS.md without Claude (g1-25, g1-26):** DEC-25 (3) is superseded. `laravel/boost` stays in `require-dev`, but `boost:install`, which writes `AGENTS.md`, runs only in setup's Claude step, so `AGENTS.md` exists only with the Claude answer. The CHANGELOG line is corrected; no code change.
- **CD-12, copy:**
  - Every Dutch "setup"/"Setup-token" in `lang/nl.json` becomes "installatie"/"installatietoken", with the het-form ("Eenmalig installatietoken aangemaakt").
  - "Doctor" becomes "Diagnose", so it no longer reads the same as "Check" ("Controle").
- **CD-13, the Flux Pro committed-files test in generated apps (g1-16):**
  - `skv:flux-pro` sets `extra.skv.flux-pro: true` in `composer.json` (through `composer config … --json`).
  - The test "never references Flux Pro (committed)" skips when the **committed** `composer.json` carries that flag ("this app committed Flux Pro on purpose with skv:flux-pro"). The kit never commits it, so the kit's own run still checks.
- **CD-14, second-factor accounts after setup (g4-03):** when the answered super admin has `two_factor_confirmed_at`, the Herd stage's login sends the browser to the apex login page instead of issuing a handoff, as a demo install already does.
- **CD-15, detection once (g3-29):**
  - `WizardRunner::begin()` detects once and stores the `Detection` in `WizardState` (`Detection::toArray()` / `fromArray()`, added in Task 4).
  - `advance()`, `finish()`, `settleWithoutHttps()` and `confirmHerd()` rebuild their context from that snapshot.
  - The Herd stage keeps its own copy in `HerdState`.
  - Without Herd, the snapshot also carries the request's port (`Detection::$port`, null under Herd or on the default port), so the environment step writes `APP_URL=http://localhost:8000` for a wizard opened on port 8000 (g1-17).
- **CD-16, System page limits (L-45):** `refreshHealth` and `runDoctor` allow six calls a minute per user each (`RateLimiter::attempt('system:<action>:<user id>', 6, …)` on the ops store). Above that, the page keeps its rows and shows "Too many attempts. Try again in a minute."

## Rulings

- **RL-1, `ProjectFile` (Task 1, g2-07, g2-08):** `App\Setup\Support\ProjectFile::write(string $path, string $contents): void` writes through `StateFile::write($path, $contents, $mode)`, which gains `int $mode = 0600`. The mode is the existing regular file's mode, else 0644. A symlink at `$path` is replaced, not followed.
  - These callers use it: `herd.yml` (HerdManifestStep), `.mcp.json`, `composer.json` and `.claude/settings.local.json` (ClaudeStep), and `skv:flux-pro`'s composer edit.
  - `.env.testing` (DatabaseStep) is written through `StateFile::write($path, $contents, fileperms(.env) & 0777)`; Bootstrap's `.env` copy takes the mode of `.env.example`.
  - `skv:flux-pro` never writes `composer.json` itself (Composer does); CD-13's flag is set with `composer config extra.skv.flux-pro true --json`.
- **RL-2, `StateFile::readJson()` (Task 1, g3-07, L-09):** a symlink at `$path` throws `JsonException(":file is a symbolic link.")`. A file that exists but cannot be read throws `JsonException(":file is not readable.")`. Callers that catch `JsonException` (DoctorContext, MarkerCheck, WizardState, HerdState) therefore treat both like a damaged file.
- **RL-3, the probe seam (Task 1, g1-13, g4-08):**
  - `Detector::__construct(Toolchain $toolchain, ?Closure $postgresProbe = null)`. A given probe `fn (string $host, int $port): bool` replaces the PDO probe.
  - `withoutPostgresProbe(bool $reachable = false): void` in `tests/Pest.php` binds `new Detector(app(Toolchain::class), fn (): bool => $reachable)`.
  - `useSetupWizard()` calls it, so wizard tests never open a socket. Tests that need the real probe (the PostgreSQL wizard cases) rebind `Detector` themselves.
- **RL-4, `DirectRequest` (Task 2, g4-01, g3-17):** a request is direct only when every one of these holds:
  - the app is local
  - `REMOTE_ADDR` is in `127.0.0.0/8` or is `::1`
  - no header name starts with `x-forwarded-` or `x-expose-`, and no `Forwarded` or `X-Original-Host` header is present

  `herd share` traffic arrives from loopback too, so the header rule stays.
- **RL-5, the pinned host (Task 2, g3-25):** `App\Setup\Http\SetupHostGuard::allows(Request $request, ?string $pinned): bool`:
  - **With a pinned host:** true when `SetupHost::normalise($request->getHost())` equals `$pinned` or `admin.$pinned`.
  - **With no pinned host (before `begin()`):** true when `SetupHost::forWizard()` accepts the request host.

  It is used by `SetupMode::homeRedirect()` and `HerdStage::state()` in both forms. `EnsureSetupAccess::authorised()` applies it only once a `WizardState` has pinned a host: before `begin()` the page must still render the host-problem callout (g4-15), and only `/` hands out the token, which is guarded. The pinned host is `WizardState::$host` or `HerdState::$host`.
  - **`/` on a refused host before `begin()`:** never the token, but never a silent login page either. `SetupHostGuard::resolve()` also returns a `suggestion`, and `SetupMode::homeHostProblem()` hands it out with the problem. `/` then redirects token-less to the suggestion (`http://localhost[:port]` without Herd, `http://{folder}.{tld}` under Herd, g3-34). When there is none (a folder that is no DNS label, g3-10; a failed `herd tld`, g3-28), it renders `setup.host-problem` in `layouts::setup` with status 409. After `begin()`, a host other than the pinned one still lands on the login page.
- **RL-6, `ShellArgument` (Task 3, g2-05):** `App\Setup\Support\ShellArgument::quote(string $value): string` returns `$value` unchanged when it matches `/^[A-Za-z0-9._\/:=@%+-]+$/`, else `escapeshellarg($value)`. It is used for every command the kit *prints* (manual and fix commands). Process argv is unchanged.
- **RL-7, the manual Herd command (Task 3, g3-23):** `HerdSiteStep::manualCommand(SetupContext $context): ?string` returns:
  - `null` without Herd
  - `herd link <name>` when the site is not served
  - `herd secure <name>` when HTTPS was chosen
  - else `null`

  The wizard runner, `SetupFinishController` and `RunStepCommand` use it.
- **RL-8, the long step's process (Task 4, g3-12):**
  - `StepLauncher::start()` gains `?string $pidFile = null`. The detached inner `sh -c` writes its own `$$` into `<step>.started`, runs php and then writes the exit code, so the pid lives exactly as long as the step (no `exec`, which would leave nothing to write the exit code). `WizardRunner` writes `<step>.started` empty first; its mtime still marks the start.
  - `WizardRunner::recheck()` refuses with "This step is still running." while `posix_kill($pid, 0)` reports the process alive.
  - The 30-minute stall rule reports a live pid as still running, for at most four hours after the step started (`WizardRunner::LIVE_PID_SECONDS = 14400`). A shell that died without writing the exit file (a reboot, an OOM kill) leaves a pid that another process of the same user can get later; after the bound the stall rule decides again, and Re-check works.
- **RL-9, one super-admin creator (Task 5, g2-11, g2-13):** `App\Authorization\SetSuperAdmin::createNew(array $attributes): User`. In one `DB::transaction()`, it inserts a user with `is_super_admin = true`, `organisation_id = null` and status Active, then calls `handleCreated()`. Callers: DatabaseStep, `skv:create-super-admin` and DemoSeeder.
- **RL-10, the generated secrets (Task 5, L-56):** `EnvironmentStep::missingSecrets(EnvFile $env): array<string, string>` returns a fresh random value (with the `GENERATED` length) for every blank `GENERATED` key. EnvironmentStep, Bootstrap and BackupPasswordCheck use it.
- **RL-11, the ops store (Task 8, F7):** `App\Support\Health\OpsCache::store(): Illuminate\Contracts\Cache\Repository` returns `Cache::store((string) config('skv.ops_cache_store', 'file'))`. HealthReport, Heartbeat, ThrottleHealthChecks and the System page's limiter use it.
- **RL-12, the stray-process guard (Task 11, g4-09):** `preventStrayProcesses()` refuses nothing until `Process::fake()` is recording, so `tests/Pest.php` adds `pest()->beforeEach(function (): void { Process::preventStrayProcesses(); Process::fake([]); })->in('Feature/Setup', 'Feature/Console', 'Feature/Backups', 'Feature/Health', 'Feature/System')`. A new arch test fails on a catch-all fake anywhere under `tests/`: a `'*'` key anywhere in a fake's array, a bare `Process::fake()` and closure forms. The scan reads raw text, so no comment under `tests/` may spell those forms either. A test that must run a real process (only `BackupRunTest`'s real `pg_dump`) opts out explicitly.

## Requirement traceability

| Requirement | Task |
|---|---|
| g3-08, g3-09, g4-18, g1-13, g4-08 (RL-3), g3-07, L-09, F3 (4), g2-07, g2-08 (RL-1, RL-2), L-10, L-13, g3-06 remainder, g3-16 | 1 |
| g4-01, g3-17 (RL-4), g3-25 (RL-5), g3-26, g3-27, g3-28, g3-10, g3-34, g1-17, g3-13, g3-35, g4-17, g4-15, g3-33 | 2 |
| g3-14, g3-15, g4-03 (CD-14), g3-02, g3-22, g3-23 (RL-7), g3-24, g4-12, g2-05 (RL-6) | 3 |
| g3-11, g3-12 (RL-8), g3-29 (CD-15), g3-30, g3-31, g3-32, g4-04, g4-13, g4-14, g4-19, g4-20, g4-21 | 4 |
| g1-07, g1-08, g1-32, g2-11, g2-13 (RL-9), g2-15, L-56 (RL-10), g1-23, g2-18, g2-19, g2-26, g2-28 | 5 |
| L-60, L-61 (CD-3), L-55, L-54, F16, F24, F25, L-51, L-52, L-57, L-58, g2-21 | 6 |
| F2, F10 (CD-5), F15 (CD-4, the key rule), F12, F5, F13 (CD-6), L-02, g2-20, g2-22, g2-25, L-08, L-11 | 7 |
| F7 (RL-11), F14, L-28, L-29 (CD-4, the throttle), L-27, L-30, L-16, L-17, L-23 (2), L-26, F9, L-19 | 8 |
| F1, F8, L-39 (CD-8), F4, F20 (CD-9), L-31, L-32, L-40, L-42, L-44, L-45 (CD-16), F26, F27 | 9 |
| L-01, L-03, L-05, L-07, F6, F18 (CD-10), O1 (CD-7), F19, F23, F28, L-23 (1) | 10 |
| g2-10, g4-09 (RL-12), g2-12, g4-05, g4-06, g2-23, g4-16, g1-14, g4-25, g4-22, g4-23, g4-24, g1-16 (CD-13) | 11 |
| F3 (1, 2, 3, 5), F17, F21, F22, g4-07, g4-10, g4-11 (CD-12), g3-18, g3-19, g3-20, g1-25, g1-26 (CD-11), the README/CHANGELOG/handleiding for every behaviour change, DEC-32 (24) correction, DEC-33 | 12 |
| The real wizard run on a throwaway Herd site | 13 |

## Follow-up triage (CD-1)

Sources:
- `g1`/`g2` are the M6a final-review list.
- `g3`/`g4` are the M6b lists.
- `F`/`O` are the M7a final review.
- `L` is the M7a build ledger.

"Fixed" names the commit or milestone that fixed the item. Items marked M7b point to their task.

| Id | Item | Destination |
|---|---|---|
| g1-01…06 | M6a open points: host pinning, hung Herd, doctor before Herd, test-database name, HTTPS consequence, Claude marketplace | fixed (M6b, DEC-31) |
| g1-07 | DatabaseStep re-applies an unchanged super-admin password | Task 5 |
| g1-08 | HerdSiteStep complete while APP_URL is http | Task 5 |
| g1-09, 10, 12, 15, 18–22, 27–29, 31 | marker summary, state round trip, "Setup stopped at", test-database name, repair report, health-key repair, next steps, pg-dump row, `.env.example` keys, README §10 items, production guard | fixed (M6b, M7a) |
| g1-11 | boost:update in ClaudeStep | obsolete (spec §5.2 changed) |
| g1-13 | InstallCommandTest reaches the real PostgreSQL and PATH | Task 1 |
| g1-14 | e2e-local.sh hides a `mktemp` failure | Task 11 |
| g1-16 | Flux Pro committed-files test in generated apps | Task 11 (CD-13) |
| g1-17 | APP_URL port dropped without Herd | Task 2 |
| g1-23 | step contract dataset | Task 5 |
| g1-24 | e2e `tests/` diff on the `--phpunit` row | M8 |
| g1-25, g1-26 | `composer setup` and boost:update, stale CHANGELOG line | Task 12 (CD-11) |
| g1-30 | Herd deep link in the README | M8 |
| g1-32 | setup password `min:8` instead of `Password::default()` | Task 5 |
| g2-01 | CI keeps Flux credentials in the global auth.json | M8 |
| g2-02, 03, 04, 06, 09, 14, 16, 17, 24, 27 | child environment, demo guard, pgsql DETAIL, atomic marker, bootstrap migrate, raw status, `--locale`, Dutch demo locale, seeder guard test, active actor test | fixed (M6b, M7a) |
| g2-05 | printed commands interpolate the folder unquoted | Task 3 (RL-6) |
| g2-07, g2-08 | project files and `.env.testing` written in place | Task 1 (RL-1) |
| g2-10 | catch-all `Process::fake(['*' => …])` | Task 11 |
| g2-11, g2-13 | demo super admin outside a transaction; three creators | Task 5 (RL-9) |
| g2-12 | NameLeakTest does not scan `app/` | Task 11 |
| g2-15 | untranslated step and EnvFile messages | Task 5 |
| g2-18, 19, 26, 28 | non-active refusal, schema-dump config, generated-secret dataset, DEC-30 demo exemption tests | Task 5 |
| g2-20, 22, 25 | `skv:doctor --fix`, green `--json` exit, `isGreen` tautology | Task 7 |
| g2-21 | `skv:install --force` untested | Task 6 |
| g2-23 | ClaudeStepTest fake key never matches | Task 11 |
| g3-01, 06, 21, 36–38 | lock close-on-exec, marker JsonException, CHANGELOG doctor line, resume, deferred rows, long-step branches | fixed (M6b) |
| g3-02 | late lock recreates the deleted lock | Task 3 |
| g3-03 | `?secret=` in the Herd-stage URLs | won't fix (CD-2) |
| g3-04 | stale Claude plugin-registry entry | won't fix (CD-2) |
| g3-05, L-59 | browser Trace warning | M8 |
| g3-07, g3-08, g3-09 | symlinked state read; broken `.env`; `.env` parsed per call | Task 1 |
| g3-10, 13, 17, 25–28, 33–35 | folder labels, Referrer-Policy, forwarded headers, host on every request, session fixation, token in `_previous.url`, failing tld, title, IP apex, any-host test | Task 2 |
| g3-11, 12, 29–32 | exit before result, live child, detection per poll, remaining 500s, finish after the marker | Task 4 |
| g3-14, 15, 22–24 | quick-login 500 and proxy rule, launch failure, manual command, leftover exit | Task 3 |
| g3-16 | detached files with the umask | Task 1 |
| g3-18, 19, 20 | README quick-login and setup-files claims, DEC-30 wording | Task 12 |
| g3-39 | PostgreSQL browser wizard in CI | M8 |
| g4-01 | the gate ignores REMOTE_ADDR | Task 2 (RL-4) |
| g4-02 | Herd stage single use | fixed (ab1fb19) |
| g4-03 | handoff skips a confirmed second factor | Task 3 (CD-14) |
| g4-04, 13, 14, 19, 20, 21 | default demo choice, retry, poll stop, RunStepCommandTest status, retry title, unbounded loops | Task 4 |
| g4-05, 06, 09, 16, 22–25 | NameLeak `\b`, setup pages without a manifest, preamble, timeouts, browser sleeps/double click/text click, clone route | Task 11 |
| g4-07 | build:setup triggers omit dependency bumps | Task 12 |
| g4-08 | useSetupWizard() docblock and the PostgreSQL probe | Task 1 (RL-3) |
| g4-10, g4-11 | Dutch "setup" glossary drift and het-form | Task 12 (CD-12) |
| g4-12 | Herd-stage race re-checks untested | Task 3 |
| g4-15, g4-17 | hostProblem callout, `.test` dataset row | Task 2 |
| g4-18 | php-fpm → php CLI never exercised | Task 1 |
| F1, F8, L-39 | `active` takes the real mutex; console kernel per render | Task 9 (CD-8) |
| F2, F5, F10, F12, F13, F15 | outage misreport, pg_dump choice, runtime mail settings, `apex` row, world-writable glob, short keys | Task 7 |
| F3 | (1) handleiding install line, (2) `--fix` list, (3) README *Systeem*, (5) handleiding schedule line | Task 12 |
| F3 (4) | unreadable marker still crashes the doctor | Task 1 (RL-2) |
| F4, F20, F26, F27 | page 500s, ext-intl, nav on tenant host, browser fakes | Task 9 |
| F6, F18, F19, F23, F28, O1 | overlap lock, stale dumps, raw exec, vacuous leak check, shadowable env, refusal trace | Task 10 |
| F7, F9, F14 | ops store duplicated, no `/up` test, per-address throttle files | Task 8 |
| F11, O3, L-62 | 503 until the first backup | fixed (345994d) |
| F16 | `answers.json` not git-ignored | Task 6 |
| F17, F21, F22 | curl key in argv, ext-zip, guideline line 51 | Task 12 |
| F24, F25 | stale `passwordRead`, `--answers=-` password | Task 6 |
| O2, L-19 | tenant hosts and the SMTP address | won't fix (CD-2); L-19's docblock wording in Task 8 |
| L-01, 03, 05, 07, L-23 (1) | backup source default, source-text tests, sslmode, temp mode, no-AES seam | Task 10 |
| L-02, L-08, L-11 | Herd-service test, test writes laravel.log, "keeps the first result" | Task 7 |
| L-04, 18, 20–22, 24, 36, 41, 47, 66 | fixed in M7a's fix waves | fixed (M7a) |
| L-06 | PostgreSQL 18 client in CI | M8 |
| L-10, L-13 | `forApplication()` A8 test, setup-token gate | Task 1 |
| L-12, L-53 | report() trace, duplicated closure | won't fix (CD-2) |
| L-14, 15, 25, 32 (store), 33–35, 37, 38, 43, 63–65 | duplicates of F2, F7, F6, F4, F8, F3 | with their F item |
| L-16, 17, 23 (2), 26, 27, 28, 29, 30 | cache expiry, LastBackupCheck branches, ops-store pin, resolved report, 429 header, buckets, wrong-key test, unwritable store | Task 8 |
| L-31, L-32 (TTL), L-40, L-42, L-44, L-45 | duplicate jobs, heartbeat TTL, backup cells, schedule cells, Diagnose, rate limit | Task 9 |
| L-46, L-48, L-49, L-50 | create-organisation test and console-tag items | M7c |
| L-51, 52, 54, 55, 57, 58, 60, 61 | CLI tests and guards | Task 6 |
| L-56 | generated-secret loop duplicated | Task 5 (RL-10) |

## Cross-task notes from planning

The task writers verified the code on `main` and recorded where it differs from the decisions above. The header already reflects the corrections; these notes keep the reasons and the cross-task dependencies. Where a note and a task disagree, the task's code is the one to follow.

#### Notes from planning Tasks 1–2

These are facts from the code on `2473d50` that change or narrow what part0.md says. Tasks 1 and 2 below already follow the code.

1. **RL-5 and the host-problem callout (g4-15) conflict.** RL-5 says `EnsureSetupAccess::authorised()` lets a request through before `begin()` only when `SetupHost::forWizard()` accepts its host. But `mount()` shows `hostProblem` exactly when `forWizard()` refuses the host, so under that rule the callout (and g4-15's test) could never render: the page would answer 404 instead. Task 2 therefore applies the rule like this:
   - `SetupMode::homeRedirect()` (the token leak) uses `SetupHostGuard::allows($request, $pinned)` with the pinned host, or with null before `begin()`. This follows RL-5 exactly.
   - `HerdStage::state()` uses `HerdState::$host`. This also follows RL-5 exactly.
   - `authorised()` applies the guard only once a `WizardState` has pinned a host. Before that, the page renders the problem and `begin()` refuses to pin.
   - `/` on a host `homeRedirect()` refuses goes through `SetupMode::homeHostProblem()` (RL-5's last bullet): a token-less redirect to the suggestion, or the problem with 409. Without it, a user who opens `http://127.0.0.1:8000` (what `php artisan serve` prints) would land on the login page with no hint, since the callout on `/welcome-setup` needs the token.

   Nothing leaks before `begin()`: the page itself never shows the token, and a token can be had only from the file or from `homeRedirect()`.
2. **RL-1 "skv:flux-pro's composer edit" has no PHP write today.** `FluxProCommand` runs `composer config repositories.flux-pro …` and `composer require`, and Composer writes `composer.json` itself. Task 1 therefore changes nothing in that command. Task 11 (CD-13, writing `extra.skv.flux-pro: true`) should write through `ProjectFile::write()`, or run `composer config extra.skv.flux-pro true --json`.
3. **RL-1, Bootstrap's `.env` copy.** RL-1 says the copy gets `fileperms(.env) & 0777`, but no `.env` exists when Bootstrap copies one. Task 1 uses the mode of the source `.env.example`. Today `copy()` creates the file with the umask's mode.
4. **RL-3, `withoutPostgresProbe()`.**
   - The helper binds lazily: `app()->bind(Detector::class, fn () => new Detector(app(Toolchain::class), postgresProbe: …))`, not `app()->instance(...)`. Tests that rebind `Toolchain` after `useSetupWizard()` (HerdStageTest's `$withHerd`, the new Herd cases) then still get a Detector on the toolchain they bound.
   - The probe is passed by name. Until `Detector` accepts it, every test that resolves a Detector therefore fails with "Unknown named parameter $postgresProbe". It does not silently fall back to the real PDO probe; that fallback would let InstallCommandTest default to pgsql and create databases on the developer's server.
   - The PostgreSQL wizard cases (WizardPageTest and the browser test) need the real probe only in their `skip()` condition, because their bodies answer `database: pgsql` explicitly. Their skip closures now build `new Detector(new Toolchain())` directly instead of resolving the container.
   - g1-13's PATH half stays: `which('claude')` still scans `/usr/local/bin:/opt/homebrew/bin`, a read-only `stat()`. RL-3 covers the probe only.
5. **`Toolchain` stops being a `readonly` class.** PHP forbids static properties in readonly classes ("Static property … cannot be readonly"), and g3-09's memo needs one. Its two constructor properties stay `private readonly`.
6. **g3-27's proposed fix does not work.** A middleware calling `setPreviousUrl()` gets overwritten: `StartSession::storeCurrentUrl()` stores `$request->fullUrl()` after the route middleware has returned, and `fullUrl()` reads `server['QUERY_STRING']`. Task 2 therefore removes `token` from both the query bag and `QUERY_STRING` before it redirects.
7. **g3-34 plus RL-5 break `scripts/e2e-local.sh` for the entries that do not use Herd.** Those entries open `http://127.0.0.1:$PORT`. On a machine with Herd installed, the wizard pins only `{folder}.{tld}`, even for a site that `php artisan serve` serves (this is A19 as it is today). After RL-5, `/` there no longer hands out the token. Task 2 makes the script reach `artisan serve` through `curl --resolve`: under `{folder}.{tld}` (asking `herd tld`, read-only) when Herd is installed, else under `localhost`. CI's phase1 job runs on Linux without Herd, so it uses `localhost:$PORT`. RL-5's token-less redirect on a refused host does not make the wrapper unnecessary: the script checks that the first `Location` from `/` carries the token, and the suggested name must still reach this `artisan serve`.
8. **RL-4 keeps `X-Original-Host`.** RL-4 lists the `x-forwarded-` and `x-expose-` prefixes plus `Forwarded`. `DirectRequest` also refuses `X-Original-Host` today, and SetupAccessTest/SetupModeTest have a dataset row for it, so Task 2 keeps it.
9. **g1-17 is only half fixed.** Task 2 keeps the port of the current `APP_URL` when its host is the detected host and Herd is absent. The wizard half ("the request's port when Herd is absent") is not done: it needs a `port` in `Detection`. That port would come from the request host in `begin()` and from the stored snapshot in `advance()`, where step 1 runs, which is CD-15's `Detection::toArray()` in Task 4. With g3-34 now suggesting `http://localhost:8000`, a wizard run on `localhost:8000` with `APP_URL=http://localhost` still writes `http://localhost`. If the controller wants that half, Task 4 should:
   - add `public ?int $port = null` to `Detection` and `toArray()`
   - have `begin()` detect with the request port
   - have `EnvironmentStep` prefer `$detection->port`
10. **g3-10's CLI half lives in `InstallCommand`, not in `InstallPreflight`.** `Detection::$host` comes from `basename(base_path())`, so the check reads the setup context's folder. Under Herd, a folder that is not one DNS label is refused before anything runs.
11. **Docs for Task 12** (not edited here, per part0's split):
    - README:47 (now true: any `X-Forwarded-*`, plus loopback only)
    - README:49 and handleiding:639 (no `127.0.0.1` without Herd; `localhost` on the same port). "On any other host the wizard names the address to use" now holds from `/` too: it redirects to that address without the token, or shows the folder or `herd tld` problem (409) when there is no address (RL-5).
    - README:73 (the detached `.log`/`.exit` files are now 0600, but not atomic)
    - a DEC-33 sentence for RL-4 (loopback `REMOTE_ADDR`) and RL-5 (the pinned host; the guard waits for the pin, see note 1; `/` on a refused host redirects token-less or answers 409), with its residual: without Herd, before `begin()`, a DNS-rebinding page on a `*.test` name can still be handed the token by `/`
12. **`MarkerCheck` gets a third message**, "The install marker :file cannot be read", for a symlinked or unreadable marker (RL-2, F3 (4)). "is not valid JSON" would misdescribe both.

---

#### Notes from planning Tasks 3–4

These facts from the code on `2473d50` change or narrow what part0.md says. Tasks 3 and 4 below follow the code. Both tasks were built in a scratch clone of `2473d50`, with small stand-ins for what Tasks 1 and 2 produce: the Detector probe seam, `withoutPostgresProbe()`, DirectRequest's loopback rule, `umask 077` and Task 2's `EnvironmentStep` port line. In that clone:
- every test below failed first, for the reason named, and then passed
- the full suite was green on SQLite (1878 tests) and with `MODULE_IMPERSONATION=false MODULE_PASSKEYS=false`
- Pint, Rector and Larastan level 8 were clean on every touched file
- `npm run build:setup` left `resources/setup/setup.css` unchanged

Neither PostgreSQL nor the browser suite ran there.

1. **RL-8 says "`echo $$ > …` before `exec php …`". No `exec`.** With `exec`, the shell would be replaced by php, and nothing would be left to write the exit code (`echo $? > "$4"`), which `advanceLong()` and `isOverdue()` rely on. Instead, the inner `sh -c` writes its own `$$` first and waits for php. Its pid therefore lives exactly as long as the step plus one `echo`. A real `sh` run in a scratch directory confirmed this: the pid was alive during the step, gone afterwards, and the exit code was written.
2. **RL-8 needs the launcher to know the pid file.**
   - `StepLauncher::start()` gains `?string $pidFile = null` as its last parameter, and so do `DetachedStepLauncher` and `Tests\Fixtures\InlineStepLauncher`. The inline launcher writes no pid, so a test's own process never counts as a running step.
   - `WizardRunner` now writes `<step>.started` empty instead of with a timestamp. Only its mtime was ever read. The shell then overwrites it with its pid. A pid of 0 or 1 never counts.
3. **RL-7 runs Herd's read-only listings (`herd links`, `herd paths`).** `confirm()` already does the same from web requests. When a listing fails, the command HTTPS asks for is named (`herd secure` with HTTPS chosen, else `herd link`).
   - Where RL-7 gives null and a result is still needed (`settleWithoutHttps()`, `RunStepCommand`'s catch, the g3-32 stranding), the result is `skipped`, because `StepResult::manual()` needs a command.
   - `SetupFinishController` gets the command through a new `HerdStage::manualCommand(HerdState)`.
4. **g3-22: a failed launch still settles at once when RL-7 names no command,** meaning without Herd. Without Herd the detached step would itself have returned `skipped` with the HTTPS consequence, so settling is the same outcome. With a command, `finish()` writes `HerdState::EXIT` (`127`), so the next poll goes to the manual page.
   - The manual page hides its code block when there is no command.
   - It has a heading for `https: false`: one new key, "Herd did not confirm the site in time."
5. **g3-02 is fixed in the three late lock holders**, not inside `SetupLock`: `WizardRunner::confirmHerd()`, `HerdStage::continueWithoutHttps()` and `RunStepCommand`. Each one removes the lock file when it finds no Herd state under the lock.
6. **g4-04 design.**
   - The page sends `start_with` only when the user picked the radio (`updatedStartWith()` sets a `#[Locked]` `$startWithChosen`) or when it is not `demo`.
   - A refusal counts as the pick, so clicking Start a second time adds the demo data. Otherwise, a user who wants demo data could not choose the radio that is already selected.
   - The wizard's refusal gets its own key ("Click Start setup again …"). The CLI key, which says `Pass "start_with": "demo"`, stays for `skv:install`.
7. **g3-32 design.**
   - `finish()` catches anything that is thrown after `MarkerStep`. It removes the Herd state, records the Herd step in the marker (RL-7's command, or `skipped`) together with the HTTPS consequence, and then rethrows.
   - The page then switches to a new stage, `stranded`. That stage links to the apex login page instead of offering "Try again", which would meet the closed gate.
   - The tests put a directory where `HerdState::PATH` goes, which works for root too. `rename()` onto a directory raises an `ErrorException` through Laravel's error handler, so the page shows PHP's rename warning, paths included. This is a local page only.
8. **g3-30.** The questions stage renders no failure callout, so a failing `start()` puts its message on the `host` field, which already has `<flux:error name="host" />`. The test puts a directory where `SetupLock::PATH` goes.
9. **CD-15.**
   - **Old state files are refused cleanly.** A wizard or Herd state without `detection` throws `InvalidArgumentException`, so:
     - `WizardRunner::state()` deletes the wizard state, and the page starts at the questions again
     - `HerdStage::state()` answers 404
     - `RunStepCommand` exits 1, as it does for any damaged state
     These files live for minutes, and a state written before M7b can only come from an aborted wizard.
   - **`RunStepCommand` also uses the stored snapshot.** CD-15 does not list it, but without it every detached step would detect again. It loses its `Detector` parameter.
   - **Snapshotting is safe.** `Detection::$postgres` only feeds the default database answer. `DatabaseStep` never reads it, so a server started after `begin()` is still used.
10. **The controller's port addition (CD-15, g1-17's second half).**
    - `Detection::$port` (`?int`) is the last constructor parameter and defaults to null, so every `new Detection(...)` and `Detection::none()` call stays valid.
    - `Detector::detect()` takes a third parameter, `?int $requestPort = null`. The port is kept only without Herd and when it is not the scheme's default port.
    - `begin()` parses the port from the `getHttpHost()` string that Task 2 passes in.
    - `EnvironmentStep` prefers `$detection->port` over Task 2's "the port of an APP_URL on this host".
    - `DoctorContext` and `skv:install` pass no port, so it stays null there.
11. **g3-11 has no deterministic red test.** The window between the two `is_file()` calls cannot be hit from a test. The new row pins the branch it ends in ("exit + json → the json's result") and passes before the change.
12. **g4-12 guard tests pass before the change**, because the guards already exist. They are verified by mutation, and all three mutations were run in the clone (Task 3, Step 6).
13. **Task 1's `withoutPostgresProbe()` binds lazily** (task1-2.md note 4). HerdStageTest's `$withHerd` therefore needs nothing more to give the Detector the Herd stub.
14. **Rector's `AddArrowFunctionReturnTypeRector` wants return types on new `fn () =>` closures in tests.** It writes them as fully qualified names, which `TestFileClassesTest` forbids. The tests below write them with imported names. Larastan reports `nullsafe.neverNull` after a `WizardState::load(...) ?? throw` in the same test, so those lines use `->`.

---

#### Notes from planning Tasks 5–6

These are facts from the code on `2473d50` that part0 does not state, and the choices Tasks 5 and 6 make because of them.

1. **g2-15 and Task 1 overlap.** The untranslated `'Could not write .env.testing.'` (`DatabaseStep::writeEnvironment()`, line 328) belongs to the `copy()` + `chmod()` pair that Task 1 (RL-1) replaces with `StateFile::write($testing, …, fileperms($env) & 0777)`. `StateFile`'s messages are translated already. So Task 5 does not edit `writeEnvironment()`. It only checks with `grep` that the literal is gone. If Task 1 kept an untranslated message there, Task 5 wraps it in `__()` with the key given in Step 9.
2. **g1-07 also changes `DatabaseStep::isComplete()`.** The verified fix touches only `createSuperAdmin()`. But `isComplete()` says "a password in the answers is applied on every run", so a wizard re-check with the same hash would still run the whole step: migrate, sync and seed. Task 5 makes both use one rule, `passwordIsCurrent()`. It holds when no hash is given, or when the account already holds exactly that hash. This rewrites one expectation in `DatabaseStepTest` 'creates the real super admin…', which today expects incomplete with the same answers. The test is rewritten, not deleted. A fresh password from a new CLI run is a new hash, so it is still applied, as documented.
3. **RL-9 order.** `SetSuperAdmin::createNew()` forces `organisation_id`, `is_super_admin` and `status` after the caller's attributes. `locale` defaults to `Locale::default()` before them. DemoSeeder no longer uses `User::factory()`, which also removes a dev-only factory call from a seeder.
4. **L-56 and CD-4 (Task 7).** `EnvironmentStep::missingSecrets()` treats only a *blank* key as missing, as the code does today. CD-4 makes a `HEALTH_CHECK_KEY` shorter than `HealthCheckKey::MIN_LENGTH` count as not configured. Task 7 may want `missingSecrets()` to count such a key as missing too, so that setup and the production bootstrap replace it as the doctor's repair does. Task 5 leaves that to Task 7.
5. **L-54 seam.** A memory stream is never a TTY, so the refusal needs an injectable check. Task 6 adds a one-method interface, `App\Console\Support\Terminal`, with the default `StreamTerminal` (`stream_isatty()`), bound in `AppServiceProvider::register()` like `StepLauncher`. The test fixture `Tests\Fixtures\AlwaysTerminal` answers true. Only `--password-stdin` refuses a terminal. `skv:install --answers=-` on a terminal still waits for end-of-file. It is not refused, because its answers need not hold a password. Raise it if the owner wants the same refusal there.
6. **CD-3 text.** The new refusal "skv:install runs only in the local, testing and staging environments (APP_ENV=:env)." replaces the M7a key "skv:install sets up a development install and does not run in production. Follow the production checklist in the README.". Task 6 removes the old key from both lang files. The pointer to the README checklist is gone from the message. Task 12 owns the README, handleiding and DEC-32 CD-9 wording.
7. **Detector binding in `ProductionGuardsTest`.** Line 31 binds `new Detector(new Toolchain(…))`, whose PDO probe reaches the developer's PostgreSQL. Task 1 (g1-13) may already have replaced it with `withoutPostgresProbe()`. Task 6 Step 1 makes sure it has, because L-57 drops the catch-all fake and relies on a detector that starts no process and opens no socket.
8. **g2-10 (Task 11) overlap.** Task 6 removes the catch-all in `ProductionGuardsTest` (L-57), so Task 11 finds that file clean. `InstallCommandTest`'s `beforeEach` catch-all stays for Task 11. Task 6's new full-install cases (g2-21, F25) run under whatever fakes that `beforeEach` provides, like the existing full-install cases.
9. **g1-23 scope.**
   - The order test already pins the eleven keys exactly.
   - The new label test reads `lang/nl.json` at run time, so CD-12's "Doctor" → "Diagnose" (Task 12) does not break it.
   - "Complete right after done" is checked for the four steps that run in a sandbox without a process: environment, herd-manifest, branding and marker.
   - `doctor` and `summary` are never complete by design.
   - `database` and `herd-site` have their own after-run completeness tests.
   - `frontend`, `git` and `claude` need real tool output, so they stay out of that dataset.
10. **g2-15 extra.** `DatabaseStep::artisan()`'s `"[{$command}] failed."` also reaches "Setup stopped at …" untranslated. Task 5 wraps it too, although the verified list did not name it.

---

#### Notes from planning Tasks 7–8

Five facts from the code on `main` `2473d50` and from the controller's rulings shape these tasks. Each task below follows them; DEC-33 (Task 12) should record them.

1. **The per-key bucket uses sha256.** Part0's CD-4 already says so: the bucket is `health-check:ok:<hash('sha256', key)>`, because `tests/Arch/PresetsTest.php`'s security preset bans `sha1` in `App`. Task 8 follows it.
2. **The suite's health key is shorter than the new minimum.** `tests/Feature/Health/HealthCheckEndpointTest.php` (beforeEach) and `tests/Feature/System/SystemPageTest.php:121` use `'a-long-random-health-check-key'`, which is 30 characters, below `MIN_LENGTH = 32`.
   - Task 8 changes the endpoint test's key to `'a-long-random-health-check-key-for-tests'` (40 characters).
   - Task 7 rewrites the SystemPageTest case anyway, because under CD-5 the runtime `health-check-key` row reads the configuration, not the sandbox `.env`.
3. **F12 puts `apex` in the wrong place.** g5's F12 says to add it "after `gd`, following `Doctor::checks()` order". In `Doctor::checks()`, `apex` comes after `marker` (php, gd, app-key, marker, …, subdomains, apex, database), so `SystemChecks::KEYS` lists it after `marker`. The page's rows already follow the doctor's order.
4. **How CD-6 is implemented:**
   - *Herd's root.* `PostgresBinaries` treats Herd's root as two levels above `$herdServices`: `/Users/Shared/Herd` for the default, `<sandbox>/herd` for a sandbox `herd/services/postgresql`. This needs no new constructor argument for the root. The existing sandbox tests move to that layout.
   - *Owner seam.* The ownership rule gets a test seam: `?int $userId = null` (null means the process's `posix_geteuid()`).
   - *Symlinks.* A candidate is resolved with `realpath()`, and one that leaves Herd's root is refused. This is stricter than CD-6's wording, with the same intent.
   - *Local host.* The "local server" test reads `config('database.connections.pgsql.host')`, or the host of `database.connections.pgsql.url` when that is set. It does not read `.env`, in either context.
5. **Scope of CD-5:** it changes only `MailCheck` and `HealthCheckKeyCheck`.
   - `ApexCheck`, `BackupPasswordCheck` and `PostgresBinaries` (`PG_DUMP_BINARY_PATH`) keep reading `.env` in every context.
   - So the SystemPageTest assertion "the rows read the sandbox's `.env`" now uses the new `apex` row.
   - A config value of `true` (from `HEALTH_CHECK_KEY=true`) counts as empty for `setting()`.

Smaller points:
- **L-08** gets the narrow fix: `Exceptions::fake()` in the test. phpunit.xml gets no `LOG_CHANNEL=null`.
- **Catch-all process fakes left in place.** `DoctorTest` and `DoctorCommandTest` keep their `Process::fake(['*' => …])` beforeEach, which is Task 11's (g2-10). The new tests in those files bind a `Doctor` with fixture or file-only checks, so they do not depend on the catch-all.
- **Setup replaces a short key too (CD-4, controller ruling).** Task 7 amends Task 5's `EnvironmentStep::missingSecrets()`: a `HEALTH_CHECK_KEY` that fails `HealthCheckKey::isUsable()` counts as missing. The consequences:
  - `EnvironmentStep::isComplete()` is false while the key is short.
  - `EnvironmentStep::run()` and the production `Bootstrap` write a new 40-character key.
  - `skv:doctor --fix` does the same through `HealthCheckKeyCheck::fix()`.
  - `BackupPasswordCheck::fix()` reads only `BACKUP_ARCHIVE_PASSWORD` from the helper, so this change does not affect it.
- **Docs for Task 12:** `DB_CONNECT_TIMEOUT` (README and handleiding, next to the database keys), and the new wording "a selection of the checks that start no process" for the System page.
- **Rule for later tasks.** Task 8 adds a source scan: only `app/Support/Health/OpsCache.php` may name `skv.ops_cache_store` in `app/`, `resources/views` or `routes/`. Task 9 (the System page's limiter, CD-16) must therefore use `OpsCache::store()`, as RL-11 already says.

---

#### Notes from planning Tasks 9–10

Facts checked against `main` `2473d50` and `vendor/` while writing Tasks 9 and 10. Where one changes what part0 says, the tasks follow the code.

1. **CD-7 needs one more step than part0 says (O1).** Laravel 13 dispatches `CommandStarting` from Symfony's `ConsoleEvents::COMMAND` (`Foundation/Console/Kernel::rerouteSymfonyCommandEvents()`). That event fires after the command is resolved but before its `handle()`. With only CD-7's text, `RefuseUnencryptedBackups` would still throw first, and the trace would still print. Task 10 therefore makes the listener step aside while `Artisan::findCommand('backup:run')` is the app's `BackupRunCommand`. It still throws when the package's command holds the name (a test registers it to prove this). DEC-33 should record this.
2. **`BackupRunCommand` must not carry `#[AsCommand]` (or a `#[Signature]` that registers lazily).** `Illuminate\Console\Application::resolve()` puts `AsCommand` classes into the lazy command map. Symfony checks its eagerly added commands first, so the package's `backup:run` would win. The class inherits `$signature`. The CD-7 claim "discovered commands register after the package's" holds: `discoverCommands()` runs after `bootstrapWith()`, so its `Artisan::starting` callbacks queue after the providers' callbacks.
3. **PGSSLMODE (L-05) is set in three places.** These are `putenv()`, `$_SERVER` and `$_ENV`. The reason is Symfony `Process::getDefaultEnv()` (`vendor/symfony/process/Process.php:1713`): it keeps `getenv()` keys only when `$_SERVER` also has them, and it adds every `$_ENV` key. db-dumper passes only `PGPASSFILE`/`PGDATABASE`. The source connection's `sslmode` always wins over a `PGSSLMODE` the operator exported, because it is what the app's own connection uses.
   - Tests that fire `CommandStarting('backup:run')` either set `backup.backup.source.databases => []` or restore `PGSSLMODE` in `afterEach`.
   - `ConfigurePgDumpTest` also gets a sandbox `backup.backup.temporary_directory`. Its listener test fires every `backup:run` listener, and the new `PrivateBackupTemp` would otherwise create the repository's `storage/app/backup-temp`.
4. **Additions to CD-10:**
   - `backup-temp-cleanup` has no `onOneServer()`, because the temporary directory is local to each server.
   - `PrivateBackupTemp` never chmods or empties the system temp directory, a sticky directory, or a directory owned by another account. This is a guard against a `temporary_directory` that points at `/tmp`.
   - The cleanup removes files only, not directories, and scans only `<temporary_directory>/temp`, never the rest of a `temporary_directory` that may be shared. The package's `force()->create()` recreates `temp/` at the next run.
   - A refused `backup:run` still runs the other `CommandStarting` listeners (`ConfigurePgDump`, `PrivateBackupTemp`) before `handle()` refuses. This is harmless.
5. **Task 8 dependency (F4).** Task 9's test with a broken ops store (`skv.ops_cache_store = 'no-such-store'`) assumes two things:
   - `HealthReport::cached()` and `Heartbeat::last()` still throw when `OpsCache::store()` cannot resolve the store. Task 8's L-30 guards only `refresh()`'s `put()`.
   - Task 8's version of `refresh()` may also guard the store resolution. The page catches around `refresh()` anyway.

   If Task 8 also made `cached()` fail soft, the "unavailable" assertion in that test must follow Task 8's behaviour.
6. **L-23 (1) seam.** `BackupEncryption::available(?bool $zipHasAes256 = null)` is a parameter, not static state. A `readonly` class cannot have static properties, and the seam can only narrow the answer: the package's own `shouldEncrypt()` still has to pass. Some new assertions assume this PHP's zip has AES-256, as the suite already does.
7. **F19 under Task 11's RL-12.** `BackupRunTest` keeps using the `Process` facade for real. `preventStrayProcesses()` only throws once `Process::fake()` is recording (`PendingProcess.php:256`), and this file fakes nothing.
8. **Task 7 overlap.** Task 7 adds `apex` to `SystemChecks::KEYS` and edits `SystemPageTest`'s literal key list. Task 9 edits other tests in that file (targeted edits, not a full-file replacement).
   - `BackupRunTest` keeps `new DoctorContext(base, toolchain, Detection::none(), null)`. If Task 7's CD-5 `$runtime` parameter has no default, add `false`.
   - If Task 7 changes `PostgresBinaries::find()`'s signature, follow it.
9. **F4/L-40: an unreachable destination's count is now `null` (shown as "—").** Before, it was `0`. `ByteSize` uses `.` as the decimal separator in both locales, as `Number::fileSize()` did (the `Number` locale default is `en`).
10. **Mockery spy of the console kernel (F8 test).** `$this->spy(Kernel::class)` replaces the contract for one HTTP request. If anything else in that request calls a method that is not on the contract, the implementer replaces the spy with `$this->mock(Kernel::class)->shouldNotReceive('bootstrap', 'all')` plus `shouldIgnoreMissing()`, and records it.
11. **Task 12 documentation for these behaviour changes:**
    - README:115 says "schedules the ops jobs in `routes/console.php`". The jobs now live in `App\Support\Scheduler\OpsSchedule`, and the heartbeat and `backup-temp-cleanup` do not run under the one-server/no-overlap guards.
    - The 12-hour lock on the backup jobs, `PGSSLMODE`, and the one-line `backup:run` refusal.
    - handleiding:1265 `<strong>Controle.</strong>` becomes `Diagnose` (L-44).
    - DEC-32 (30)'s "`ScheduledTasks` calls the console kernel's `bootstrap()`" is superseded by CD-8.
    - `RefuseUnencryptedBackups`' docblock and DEC-32 (10) now describe the backstop role.
12. **Counts that Task 10 changes in Task 9's tests (as asked):**
    - `ScheduleTest` 'schedules exactly…' goes from 8 to 9 identifiers.
    - `ScheduleTest`'s timing dataset gains a row and two columns.
    - `ScheduleTest`'s module test ('runs the backup jobs only while the backups module is on') does not list `backup-temp-cleanup`, which runs while the module is off too (CD-10).
    - `ScheduledTasksTest`'s expected list goes from 8 to 9 tasks.

    No `SystemPageTest` assertion counts the tasks.

---

#### Notes from planning Tasks 11–12

Facts from the code on `m7b-setup-hardening` (= `main` `2473d50`) that contradict or sharpen part0. Tasks 11 and 12 follow the code.

1. **RL-12 as written guards nothing.** `PendingProcess::run()` and `start()` refuse a stray process only while `$this->factory->isRecording()`, and recording starts with `Process::fake()` (vendor `Illuminate/Process/PendingProcess.php:252-256`, `Factory::fake()`). So `Process::preventStrayProcesses()` alone is a no-op in a file that never fakes. Today that is `SetupHostTest`, `CheckStatusTest`, `SetupLayoutTest`, `BackupConfigTest`, `RefuseUnencryptedBackupsTest` and `BackupRunTest`. Task 11's hook therefore calls `Process::preventStrayProcesses()` **and** `Process::fake([])`. `BackupRunTest` needs an opt-out for its real `pg_dump --version` probe if Task 10 (F19) moved that probe onto the `Process` facade. spatie's dumper uses Symfony's `Process` directly, so the facade fake never touches `backup:run`.
2. **Two more catch-alls.** Besides the 18 `'*'` keys in 10 files (one at `BootstrapTest.php:151`, not first in its array), `Process::fake()` with no argument installs a `'*'` handler too (`Factory::fake()` with `null`). There are two: `BootstrapTest.php:93` and `ClaudeStepTest.php:120`. The arch rule bans the bare form, closure forms and `'*'` keys. Its regex was validated against today's tree: 20 hits in 10 files, and no false hit on keys such as `"*'services:list'*"`.
3. **g4-22's suggested fix does not work.** `Playwright::usingTimeout()` sets only the client's request timeout. `AwaitableWebpage::__call()` retries an assertion through `Execution::waitForExpectation()`, which reads `Playwright::timeout()`, the static assertion timeout (5 000 ms by default), and runs each attempt under `usingTimeout(1_000)`. So `usingTimeout(120_000, fn () => $page->assertPresent(…))` still gives up after 5 s. Task 11 raises `Playwright::setTimeout()` for one wait and restores it. Checked in vendor `pest-plugin-browser` v5.1.2; Larastan level 8 accepts the `@internal` class from tests (probed on a scratch file).
4. **CD-13's mark goes through Composer, not `ProjectFile`.**
   - `skv:flux-pro` writes the mark with `composer config extra.skv.flux-pro true --json`. Composer then keeps the file's format, and the tests fake the command, so no test writes the repository's `composer.json`. `FluxProCommand` uses `base_path()` and has no seam for a sandbox.
   - The mark goes in **before** `composer require`. Composer's lock content hash covers `extra`, so a later write would leave the lock stale.
   - `composer config --unset extra.skv` removes it after a failed install, restoring the file byte for byte. Checked with Composer 2.10.2 on a scratch copy of `composer.json`. `--unset extra.skv.flux-pro` would leave `"skv": {}` and reformat an array.
   - RL-1 lists "skv:flux-pro's composer edit" as a `ProjectFile` caller. There is none now; DEC-33 (13) says so.
5. **NameLeakTest today.** The current allow-list over `app/`, `resources/views` and `lang/` leaves 5 hits:
   - `AppServiceProvider.php:56` (`alias('Skv', …)`)
   - `Skv.php:11` and `:13` (the docblock and `final class Skv`)
   - `WizardState.php:25` (`'storage/app/private/skv-setup'`, without the slash the old rule wanted)
   - `LastBackupCheck.php:17` (a bare `skv.health_check…` key in a docblock)

   With Task 11's rules (exact state-file names `(?![\w.-])`, the alias and class entries) only `LastBackupCheck.php:17` is left; Task 11 rewrites that docblock to the allowed `config('skv.…')` form. CD-13 adds `extra.skv(.flux-pro)` as an allowed form. g2-12's third hit (`InstallPreflight.php:74`) no longer appears.
6. **The DEC shape** in `docs/decisions.md` is **Question / Answer / Evidence / Consequence**, not Context / Decision. DEC-33 copies the real shape.
7. **CD-2 cites the wrong item.** CD-2 says DEC-31 (35) keeps `?secret=` in the URL. DEC-31 (35) is Task 12's quick-login deviations; the secret-bound Herd-stage URLs are DEC-31 (6), and DEC-31 (23) records the stage replacing the signed URL. DEC-33 cites (6).
8. **RL-4 drops `X-Original-Host`.** Spec §5.3.4 (line 292), DEC-31 (2) and today's `DirectRequest::FORWARDED` all list it. Task 12's docs and DEC-33 (20) keep it refused. Confirm Task 2 kept it; if not, either restore it (spec) or drop it from the README, the handleiding and DEC-33 (20).
9. **Nine ops events, not eight.** CD-8 says `OpsSchedule::define()` holds "the eight ops events", but CD-10 adds a ninth, `backup-temp-cleanup`. The docs say nine. Task 12 Step 2 tells the implementer to read `OpsSchedule::define()` for which events use `onOneServer()` / `withoutOverlapping()`.
10. **CD-11's wording.** "`laravel/boost` is installed only by setup's Claude step" is inaccurate: `laravel/boost` is in the kit's `require-dev`. What runs only in the Claude step is `boost:install`, which writes `AGENTS.md`. DEC-33 (11) says so.
11. **A residual with no triage id.** DEC-32's Consequence carries "`LastBackupCheck`'s message not naming encryption `none`". L-20 fixed the guard, but the message (`LastBackupCheck.php:42`) still names only a blank password or a zip without AES-256. DEC-33's Consequence defers it to M7c.
12. **DEC-31's Consequence carries "the `phase1` job's path filter"**, which no triage id covers. The DEC-31 amendment does not claim it.
13. **The plan file name is assumed** to be `docs/superpowers/plans/2026-10-16-m7b-setup-hardening.md` (DEC-33 cites it). Adjust if the plan file is named differently.
14. **Overlaps with Task 6.**
    - `ProductionGuardsTest.php:56` is L-57 (Task 6). Task 11 handles it only if it is still there.
    - Task 6's L-54 (`--password-stdin` on a terminal) may refuse or prompt hidden; Task 12 gives the doc text for both and picks by Task 6's commit.
15. **Machine-dependent commands.** `Toolchain::which()` scans the real system PATH (`/usr/local/bin:/opt/homebrew/bin:/usr/bin:/bin…`). Doctor and install runs therefore start `node -v` where Node is installed (this machine: `/opt/homebrew/bin/node`), `claude plugin list` where Claude Code is on that PATH, and `composer config http-basic…` where Flux Pro is installed (CI's main job runs `skv:flux-pro` before `composer check`). Task 11's patterns cover each, so a test behaves the same everywhere.

---

#### Notes from the plan review

1. **`NameLeakTest` already scans `tests/`.** `tests/Arch/NameLeakTest.php` covers `base_path('tests')` today (minus itself), and a config key passes only as `config('skv.…` or `config(['skv.…`, with the key right after the opening; any `skv` left on a line once the allowed forms are stripped (case-insensitive) is a hit. So every task's test code must pass it at that task's own gate, not only after Task 11 widens the scan to `app/`. Follow the Names rule in Global Constraints: one `skv.*` key per `config([...])` call, written first. Task 7 adds the `->setting('X', 'skv.` allowed form for `DoctorContext::setting()` (CD-5), and Task 11 carries it into its rewritten `$allowed`.
2. **Runtime rows are set with `config()`.** After Task 7 (CD-5), the System page and `/health-check` read `MAIL_*` and `HEALTH_CHECK_KEY` from the running configuration, not from `.env`. Tests of those rows (Tasks 7–9) set them with `config(['skv.health_check.key' => …])` / `config(['mail.…' => …])`, never by editing a sandbox `.env`, and never depend on the developer's own `.env`.
3. **spatie's `Config` is a cached object.** spatie/laravel-backup 10 resolves `Spatie\Backup\Config\Config` once. Tests that change `backup.*` after boot (Task 10's `BackupRunCommandTest` and `RefuseUnencryptedBackupsTest`) call `Config::rebind()` after their `config([...])` call, or the command reads the boot-time configuration.
4. **No interactive git.** `git add -p` and other interactive commands do not work in an agent shell. Where two commits of a task touch the same file, fold the overlap into the later commit.

---

### Task 1: Machine probes and state files (g3-08, g3-09, g4-18, g1-13, g4-08, g3-07, L-09, F3 (4), g2-07, g2-08, L-10, L-13, g3-06, g3-16; RL-1, RL-2, RL-3)

**Files:**
- Create:
  - `app/Setup/Support/ProjectFile.php`
  - `tests/Feature/Setup/Support/ProjectFileTest.php`
- Modify:
  - `app/Setup/Support/StateFile.php` (`write()` gains `$mode`; `readJson()` refuses symlinks and unreadable files)
  - `app/Setup/Support/Toolchain.php` (no class-level `readonly`; memoised, crash-free scrub list; `phpCli(?string $binary)`)
  - `app/Setup/Detector.php` (the probe seam)
  - `app/Setup/Doctor/DoctorContext.php` (`forApplication(..., ?string $basePath)`)
  - `app/Setup/Doctor/Checks/MarkerCheck.php` (cannot be read)
  - `app/Setup/Doctor/Checks/SetupTokenCheck.php` (gate on the marker file)
  - `app/Setup/Wizard/HerdStage.php` (`summary()` tolerates a damaged marker)
  - `app/Setup/Wizard/DetachedStepLauncher.php` (`umask 077`)
  - `app/Setup/Steps/HerdManifestStep.php`, `app/Setup/Steps/ClaudeStep.php` (`ProjectFile`)
  - `app/Setup/Steps/DatabaseStep.php`, `app/Setup/Support/Bootstrap.php` (`StateFile::write(..., $mode)`)
  - `lang/nl.json`, `lang/en.json`
  - `tests/Pest.php` (`withoutPostgresProbe()`; `useSetupWizard()` calls it and gets a corrected docblock)
- Test:
  - `tests/Feature/Setup/Support/StateFileTest.php`, `tests/Feature/Setup/Support/ProjectFileTest.php` (new)
  - `tests/Feature/Setup/ToolchainTest.php`, `tests/Feature/Setup/DetectorTest.php`
  - `tests/Feature/Setup/Doctor/DoctorTest.php`, `tests/Feature/Setup/Doctor/ChecksTest.php`
  - `tests/Feature/Setup/Wizard/HerdStageTest.php`, `tests/Feature/Setup/Wizard/DetachedStepLauncherTest.php`, `tests/Feature/Setup/Wizard/WizardPageTest.php`
  - `tests/Feature/Setup/Steps/HerdManifestStepTest.php`, `tests/Feature/Setup/Steps/ClaudeStepTest.php`, `tests/Feature/Setup/Steps/DatabaseStepTest.php`
  - `tests/Feature/Setup/BootstrapTest.php`
  - `tests/Feature/Console/InstallCommandTest.php`, `tests/Feature/Console/ProductionGuardsTest.php`
  - `tests/Browser/SetupWizardTest.php` (the skip condition only)

**Interfaces:**
- Consumes:
  - `EnvFile::all(): array<string, string>`, which throws `Dotenv\Exception\ExceptionInterface` on a file Dotenv cannot parse
  - `Marker::FILE`, `Marker::read(SetupContext)` (throws `JsonException`)
  - `SetupToken::PATH`
  - `setupSandbox(?Detection, string $prefix = '')`, `useSetupWizard(?SetupContext)`
- Produces:
  - `StateFile::write(string $path, string $contents, int $mode = 0600): void`
  - `StateFile::readJson(string $path): ?array`, which throws `JsonException(":file is a symbolic link.")` for a symlink and `JsonException(":file is not readable.")` for a file it cannot read (RL-2)
  - `App\Setup\Support\ProjectFile::write(string $path, string $contents): void`: atomic; the existing regular file's mode, else 0644; a symlink is replaced (RL-1)
  - `Toolchain::environment()`: never throws for a broken `.env`, and parses each `.env` once per (mtime, size, inode)
  - `Toolchain::phpCli(?string $binary = null): ?string`
  - `Detector::__construct(Toolchain $toolchain, ?Closure $postgresProbe = null)`; the probe is `Closure(string $host, int $port): bool`
  - `DoctorContext::forApplication(Toolchain $toolchain, Detector $detector, ?string $basePath = null): self`
  - `withoutPostgresProbe(bool $reachable = false): void` (global test helper in `tests/Pest.php`)

- [ ] **Step 1: Write the failing tests**

`tests/Pest.php`: add `use App\Setup\Detector;` to the imports, and add this helper right before `useSetupWizard()`:

```php
/**
 * Pins the PostgreSQL probe (RL-3): every Detector the container builds answers $reachable instead of opening a
 * connection to 127.0.0.1:5432. Bound lazily, so the Detector uses whichever Toolchain the test binds afterwards; the
 * probe goes by name, so a Detector without the seam fails loudly instead of probing the machine.
 */
function withoutPostgresProbe(bool $reachable = false): void
{
    app()->bind(Detector::class, fn (): Detector => new Detector(app(Toolchain::class), postgresProbe: fn (): bool => $reachable));
}
```

Replace the docblock and body of `useSetupWizard()`:

```php
/**
 * Puts the app into setup mode on a sandbox (M6b, RL-1): the local environment, a setup token in the sandbox, and the
 * setup services bound to it. Processes are faked and the PostgreSQL probe answers "unreachable" (withoutPostgresProbe(),
 * RL-3), so the wizard's Herd and database probes never start a process or open a connection on the developer's
 * machine; a PostgreSQL case answers database=pgsql itself. The session and cache files go into the sandbox too, and
 * the wizard's detached steps run in-process through the inline step launcher (RL-4). Returns the sandbox context.
 */
function useSetupWizard(?SetupContext $context = null): SetupContext
{
    $context ??= setupSandbox();

    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid()), $context->basePath));
    withoutPostgresProbe();
    app()->detectEnvironment(fn (): string => 'local');
    app()->instance(SetupContext::class, $context);
    app()->instance(SetupMode::class, new SetupMode($context->basePath));
    app()->instance(SetupToken::class, new SetupToken($context->basePath));
    app()->instance(StepLauncher::class, new InlineStepLauncher);
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

`tests/Feature/Setup/Support/StateFileTest.php`: add:

```php
it('writes with the mode it is given (RL-1)', function (): void {
    $path = $this->directory.'/herd.yml';

    StateFile::write($path, "name: x\n", mode: 0644);

    expect(file_get_contents($path))->toBe("name: x\n")
        ->and(fileperms($path) & 0777)->toBe(0644);
});

it('refuses to read a symlinked state file (RL-2, g3-07)', function (): void {
    File::ensureDirectoryExists($this->directory);
    $target = $this->directory.'/elsewhere.json';
    file_put_contents($target, '{"a":1}');
    symlink($target, $this->directory.'/state.json');

    expect(fn (): ?array => StateFile::readJson($this->directory.'/state.json'))
        ->toThrow(JsonException::class, __(':file is a symbolic link.', ['file' => 'state.json']));
});

it('refuses a state file it cannot read like a damaged one (RL-2, L-09)', function (): void {
    File::ensureDirectoryExists($this->directory);
    $path = $this->directory.'/state.json';
    file_put_contents($path, '{"a":1}');
    chmod($path, 0000);

    expect(fn (): ?array => StateFile::readJson($path))
        ->toThrow(JsonException::class, __(':file is not readable.', ['file' => 'state.json']));
})->skip(fn (): bool => posix_geteuid() === 0, 'root reads every file');
```

`tests/Feature/Setup/Support/ProjectFileTest.php` (new):

```php
<?php

declare(strict_types=1);

/** covers §5.3.3 */

use App\Setup\Support\ProjectFile;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
    $this->directory = storage_path('framework/testing/setup/project-'.Str::uuid());
    File::ensureDirectoryExists($this->directory);
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

it('keeps the mode of the file it replaces and gives a new file 0644, atomically (RL-1, g2-07)', function (): void {
    $existing = $this->directory.'/composer.json';
    File::put($existing, '{}');
    chmod($existing, 0640);

    ProjectFile::write($existing, "{\"a\":1}\n");
    ProjectFile::write($this->directory.'/herd.yml', "name: x\n");

    expect(file_get_contents($existing))->toBe("{\"a\":1}\n")
        ->and(fileperms($existing) & 0777)->toBe(0640)
        ->and(fileperms($this->directory.'/herd.yml') & 0777)->toBe(0644)
        ->and(glob($this->directory.'/.state-*'))->toBe([]);
});

it('replaces a symlink instead of writing through it (RL-1, g2-07)', function (): void {
    $outside = $this->directory.'/outside.json';
    File::put($outside, 'keep');
    symlink($outside, $this->directory.'/.mcp.json');

    ProjectFile::write($this->directory.'/.mcp.json', "{}\n");

    expect(file_get_contents($outside))->toBe('keep')
        ->and(is_link($this->directory.'/.mcp.json'))->toBeFalse()
        ->and(file_get_contents($this->directory.'/.mcp.json'))->toBe("{}\n")
        ->and(fileperms($this->directory.'/.mcp.json') & 0777)->toBe(0644);
});
```

`tests/Feature/Setup/ToolchainTest.php`: add:

```php
it('scrubs the keys of an .env that Dotenv cannot parse, without throwing (g3-08, RL-12)', function (): void {
    $context = setupSandbox();
    File::put($context->path('.env'), "APP_KEY=base64:x\nDB_PASSWORD=secret\nBROKEN=a b\n");

    $environment = new Toolchain($this->home, $context->basePath)->environment();

    expect($environment['APP_KEY'] ?? null)->toBeFalse()
        ->and($environment['DB_PASSWORD'] ?? null)->toBeFalse()
        ->and($environment['BROKEN'] ?? null)->toBeFalse()
        ->and($environment['HOME'])->toBe($this->home);
});

it('parses an unchanged .env once, and again once it changes (g3-09)', function (): void {
    $context = setupSandbox();
    $path = $context->path('.env');
    File::put($path, "FIRST_KEY=1\n");
    $toolchain = new Toolchain($this->home, $context->basePath);

    $first = $toolchain->environment();
    $mtime = (int) filemtime($path);
    // Same size, inode and mtime: the cached names stand, so this edit is not read yet.
    File::put($path, "OTHER_KEY=1\n");
    touch($path, $mtime);
    $cached = $toolchain->environment();
    touch($path, $mtime + 5);
    $changed = $toolchain->environment();

    expect($first)->toHaveKey('FIRST_KEY')
        ->and($cached)->toHaveKey('FIRST_KEY')->not->toHaveKey('OTHER_KEY')
        ->and($changed)->toHaveKey('OTHER_KEY')->not->toHaveKey('FIRST_KEY');
});

it('maps Herd\'s php-fpm to the CLI php next to it (g4-18, DEC-23)', function (): void {
    $bin = $this->home.'/Library/Application Support/Herd/bin';

    foreach (['php84-fpm', 'php84'] as $name) {
        File::put($bin.'/'.$name, "#!/bin/sh\n");
        chmod($bin.'/'.$name, 0755);
    }

    expect(new Toolchain($this->home)->phpCli(binary: $bin.'/php84-fpm'))->toBe($bin.'/php84')
        ->and(new Toolchain($this->home)->phpCli(binary: $bin.'/php84'))->toBe($bin.'/php84');
});
```

`tests/Feature/Setup/DetectorTest.php`: replace the whole file. Every `detect()` gets a pinned probe; only the explicit port-1 case keeps the real PDO probe.

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
    app()->instance(Toolchain::class, new Toolchain($this->home));
    File::ensureDirectoryExists($this->home.'/Library/Application Support/Herd/config');
    $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->home));
});

/** A Detector whose PostgreSQL probe answers "unreachable" without opening a connection (RL-3, g1-13). */
$detector = fn (Toolchain $toolchain): Detector => new Detector($toolchain, postgresProbe: fn (): bool => false);

it('detects nothing on a machine without Herd', function () use ($detector): void {
    $detection = $detector(new Toolchain($this->home))->detect('localhost');

    expect($detection->herd)->toBeFalse()
        ->and($detection->herdPro)->toBeFalse()
        ->and($detection->herdMail)->toBeFalse()
        ->and($detection->host)->toBe('localhost');
});

it('reads Herd Pro from the services JSON and the mail service from herd.json, never from exit codes (§5.3.2, §13)', function () use ($detector): void {
    $herd = $this->home.'/Library/Application Support/Herd/bin/herd';
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);
    File::put($this->home.'/Library/Application Support/Herd/config/herd.json', json_encode(['mails' => true, 'smtpPort' => 2525], JSON_THROW_ON_ERROR));

    Process::fake([
        "*'services:list' '--json'*" => Process::result(output: '[{"type":"postgresql","status":"active"}]', exitCode: 1),
        "*'tld'*" => Process::result(output: "test\n"),
    ]);

    $detection = $detector(new Toolchain($this->home))->detect();

    expect($detection->herd)->toBeTrue()
        ->and($detection->herdPro)->toBeTrue()
        ->and($detection->herdMail)->toBeTrue()
        ->and($detection->herdSmtpPort)->toBe(2525)
        ->and($detection->host)->toBe(basename(base_path()).'.test');
});

it('treats unparsable Herd output as "no Herd Pro" (§13)', function () use ($detector): void {
    $herd = $this->home.'/Library/Application Support/Herd/bin/herd';
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);

    Process::fake([
        "*'services:list' '--json'*" => Process::result(output: 'Herd Pro is required', exitCode: 0),
        "*'tld'*" => Process::result(output: "test\n"),
    ]);

    expect($detector(new Toolchain($this->home))->detect()->herdPro)->toBeFalse();
});

it('reports Postgres unreachable when nothing listens (§5.3.2)', function (): void {
    // The real PDO probe, on port 1: nothing listens there, so it never reaches the developer's server.
    expect(new Detector(new Toolchain($this->home))->postgresReachable('127.0.0.1', 1))->toBeFalse();
});

it('asks the given probe instead of opening a connection (RL-3, g1-13)', function (): void {
    $asked = [];
    $probed = new Detector(new Toolchain($this->home), postgresProbe: function (string $host, int $port) use (&$asked): bool {
        $asked[] = $host.':'.$port;

        return true;
    });

    expect($probed->detect('localhost')->postgres)->toBeTrue()
        ->and($probed->postgresReachable('10.0.0.1', 6543))->toBeTrue()
        ->and($asked)->toBe(['127.0.0.1:5432', '10.0.0.1:6543']);
});

it('pins the wizard\'s PostgreSQL probe, so no setup test reaches the machine\'s server (RL-3, g4-08)', function (): void {
    useSetupWizard();

    $unreachable = app(Detector::class)->detect('localhost')->postgres;
    withoutPostgresProbe(true);

    expect($unreachable)->toBeFalse()
        ->and(app(Detector::class)->detect('localhost')->postgres)->toBeTrue();
});

it('treats a Herd that hangs as no Herd Pro and falls back to the APP_URL host (A5, A18)', function () use ($detector): void {
    $herd = $this->home.'/Library/Application Support/Herd/bin/herd';
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);
    config(['app.url' => 'http://fallback.test']);

    // A handler that throws stands in for a timeout: Detector must catch every process failure.
    Process::fake(['*' => fn () => throw new RuntimeException('timed out')]);

    $detection = $detector(new Toolchain($this->home))->detect();

    expect($detection->herd)->toBeTrue()
        ->and($detection->herdPro)->toBeFalse()
        ->and($detection->host)->toBe('fallback.test');
});

it('uses a valid request host and ignores an invalid one (A4)', function () use ($detector): void {
    config(['app.url' => 'http://fallback.test']);

    expect($detector(new Toolchain($this->home))->detect('My-App.test:80')->host)->toBe('my-app.test')
        ->and($detector(new Toolchain($this->home))->detect('evil.com@x')->host)->toBe('fallback.test');
});
```

> The `['*' => …]` fake in "treats a Herd that hangs" is unchanged here; Task 11 replaces the catch-alls.

`tests/Feature/Setup/Doctor/DoctorTest.php`: add `use App\Setup\Detector;`, then:

```php
it('treats a marker it cannot read as no marker, in setup and in the running application (L-09, L-10, A8)', function (): void {
    $marker = $this->sandbox->path(Marker::FILE);
    File::put($marker, '{}');
    chmod($marker, 0000);
    $toolchain = new Toolchain($this->sandbox->path('home'));

    $setup = DoctorContext::fromSetup($this->sandbox, $toolchain);
    $application = DoctorContext::forApplication($toolchain, new Detector($toolchain, postgresProbe: fn (): bool => false), basePath: $this->sandbox->basePath);

    expect($setup->marker)->toBe([
        'answers' => $this->sandbox->answers->toPublicArray(),
        'start_with' => $this->sandbox->answers->startWith,
    ])
        ->and($application->basePath)->toBe($this->sandbox->basePath)
        ->and($application->marker)->toBeNull();
})->skip(fn (): bool => posix_geteuid() === 0, 'root reads every file');
```

> The named arguments (`postgresProbe:`, `basePath:`) make the red run fail with "Unknown named parameter". Positional arguments would be silently ignored, and `forApplication()` would then read the repository's own marker.

`tests/Feature/Setup/Doctor/ChecksTest.php`:
- Rewrite the test 'warns about a leftover setup token and deletes it with --fix (§5.4)'. It used `$withMarker`, a context with a marker array but no marker file, and the check now looks at the file (L-13):

```php
it('warns about a leftover setup token and deletes it with --fix (§5.4)', function (): void {
    File::put($this->sandbox->path(SetupToken::PATH), Str::random(64));
    $before = new SetupTokenCheck()->run($this->context);

    Marker::write($this->sandbox, []);
    $leftover = new SetupTokenCheck()->run($this->context);
    $fixed = new SetupTokenCheck()->fix($this->context);

    expect($before->status)->toBe(CheckStatus::Skipped)
        ->and($leftover->status)->toBe(CheckStatus::Warn)
        ->and($leftover->fix)->toBe('php artisan skv:doctor --fix')
        ->and($fixed?->status)->toBe(CheckStatus::Pass)
        ->and(is_file($this->sandbox->path(SetupToken::PATH)))->toBeFalse();
});
```

- Then add:

```php
it('checks the setup token whenever the marker file exists, also when it does not read back (L-13)', function (): void {
    File::put($this->sandbox->path(Marker::FILE), '{"answers":');
    File::put($this->sandbox->path(SetupToken::PATH), Str::random(64));

    $leftover = new SetupTokenCheck()->run($this->context);
    $fixed = new SetupTokenCheck()->fix($this->context);

    expect($leftover->status)->toBe(CheckStatus::Warn)
        ->and($fixed?->status)->toBe(CheckStatus::Pass)
        ->and(is_file($this->sandbox->path(SetupToken::PATH)))->toBeFalse();
});

it('fails the marker row for a marker that is a symlink or cannot be read (F3, L-09, RL-2)', function (): void {
    $file = $this->sandbox->path(Marker::FILE);
    Marker::write($this->sandbox, []);
    rename($file, $file.'.real');
    symlink($file.'.real', $file);
    $linked = new MarkerCheck()->run($this->context);

    unlink($file);
    rename($file.'.real', $file);
    chmod($file, 0000);
    $unreadable = new MarkerCheck()->run($this->context);
    chmod($file, 0600);

    $message = __('The install marker :file cannot be read', ['file' => Marker::FILE]);

    expect($linked->status)->toBe(CheckStatus::Fail)
        ->and($linked->message)->toBe($message)
        ->and($unreadable->status)->toBe(CheckStatus::Fail)
        ->and($unreadable->message)->toBe($message);
})->skip(fn (): bool => posix_geteuid() === 0, 'root reads every file');
```

`tests/Feature/Setup/Wizard/HerdStageTest.php`: add:

```php
it('shows the finish page without a summary when the marker no longer reads back (g3-06)', function () use ($afterStepNine): void {
    $context = useSetupWizard();
    $secret = $afterStepNine($context);
    $state = HerdState::load($context->basePath) ?? throw new LogicException('No Herd state.');
    $state->result = StepResult::skipped('Herd is not installed');
    $state->save($context->basePath);
    File::put($context->path(Marker::FILE), 'null');

    $this->get('/welcome-setup/finish?secret='.$secret)->assertOk()
        ->assertSee('data-test="setup-summary"', false)
        ->assertSee('data-test="setup-login"', false)
        ->assertDontSee('data-test="setup-next-steps"', false);
});
```

`tests/Feature/Setup/Wizard/DetachedStepLauncherTest.php`: add `use Illuminate\Process\PendingProcess;`, then:

```php
it('creates the child\'s log and exit files private: umask 077 before any redirect (g3-16)', function (): void {
    $context = setupSandbox();
    Process::fake(["*sh' '-c'*" => Process::result('')]);

    new DetachedStepLauncher(app(Toolchain::class), php: '/usr/bin/php')->start($context->basePath, 'frontend', '/r.json', '/l.log', '/e.exit');

    Process::assertRan(function (PendingProcess $process): bool {
        $script = ((array) $process->command)[2] ?? null;

        return is_string($script) && str_starts_with($script, "nohup sh -c 'umask 077; ");
    });
});
```

`tests/Feature/Setup/Wizard/WizardPageTest.php`, the PostgreSQL case (line 185): the container's Detector is the probe-pinned one once `useSetupWizard()` runs, so the skip condition builds the real one itself:

```php
})->skip(fn (): bool => ! new Detector(new Toolchain())->postgresReachable(), 'PostgreSQL is not reachable');
```

`tests/Browser/SetupWizardTest.php` line 96 gets the same change. `Toolchain` is already imported in both files.

`tests/Feature/Setup/Steps/HerdManifestStepTest.php`: add `use Illuminate\Support\Facades\File;`, then:

```php
it('replaces a symlinked herd.yml instead of writing through it (RL-1, g2-07)', function (): void {
    $context = setupSandbox();
    File::put($context->path('elsewhere.yml'), 'keep');
    symlink($context->path('elsewhere.yml'), $context->path('herd.yml'));

    new HerdManifestStep()->run($context);

    expect(file_get_contents($context->path('elsewhere.yml')))->toBe('keep')
        ->and(is_link($context->path('herd.yml')))->toBeFalse()
        ->and(new HerdManifestStep()->isComplete($context))->toBeTrue();
});
```

`tests/Feature/Setup/Steps/ClaudeStepTest.php`: add:

```php
it('replaces symlinked project files instead of writing through them (RL-1, g2-07)', function (): void {
    $detection = new Detection(herd: true, herdPro: false, herdMail: false, herdSmtpPort: null, postgres: false, fluxPro: false, claude: true, host: 'my-app.test', scheme: 'http');
    $context = setupSandbox($detection);
    $context->answers = Answers::fromArray(['claude' => true], $detection, $context->folderName());
    new EnvFile($context->path('.env'))->set(['APP_URL' => 'http://my-app.test']);
    $outside = $this->home.'/outside';
    File::ensureDirectoryExists($outside);
    File::copy(base_path('composer.json'), $outside.'/composer.json');
    File::put($outside.'/mcp.json', '{"mcpServers":{}}');
    File::ensureDirectoryExists($context->path('.claude'));
    symlink($outside.'/composer.json', $context->path('composer.json'));
    symlink($outside.'/mcp.json', $context->path('.mcp.json'));
    // Dangling: the step creates settings.local.json when it is missing.
    symlink($outside.'/settings.local.json', $context->path('.claude/settings.local.json'));
    $composer = (string) file_get_contents($outside.'/composer.json');

    Process::fake([
        "*'artisan' 'boost:install'*" => Process::result('Boost installed'),
        "*claude' 'plugin'*" => Process::result('installed'),
    ]);

    expect(new ClaudeStep()->run($context)->status)->toBe('done')
        ->and(file_get_contents($outside.'/composer.json'))->toBe($composer)
        ->and(file_get_contents($outside.'/mcp.json'))->toBe('{"mcpServers":{}}')
        ->and($outside.'/settings.local.json')->not->toBeFile()
        ->and(array_filter(['composer.json', '.mcp.json', '.claude/settings.local.json'], fn (string $file): bool => is_link($context->path($file))))->toBe([])
        ->and((string) file_get_contents($context->path('composer.json')))->toContain('boost:update');
});
```

`tests/Feature/Setup/Steps/DatabaseStepTest.php`: add:

```php
it('writes .env.testing atomically with the mode of .env, replacing a symlink (RL-1, g2-08)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['database' => 'sqlite'], Detection::none(), $context->folderName());
    chmod($context->path('.env'), 0640);
    File::put($context->path('elsewhere.env'), 'keep');
    symlink($context->path('elsewhere.env'), $context->path('.env.testing'));

    expect(new DatabaseStep()->run($context)->status)->toBe('done')
        ->and(file_get_contents($context->path('elsewhere.env')))->toBe('keep')
        ->and(is_link($context->path('.env.testing')))->toBeFalse()
        ->and(fileperms($context->path('.env.testing')) & 0777)->toBe(0640)
        ->and(new EnvFile($context->path('.env.testing'))->get('DB_DATABASE'))->toBe(':memory:');
});
```

`tests/Feature/Setup/BootstrapTest.php`: add:

```php
it('copies .env.example atomically with its mode, replacing a symlinked .env (RL-1, g2-08)', function (): void {
    Process::fake(["*'artisan' 'migrate' '--graceful' '--force'*" => Process::result('Migrated')]);
    chmod($this->context->path('.env.example'), 0640);
    // Dangling: Bootstrap sees no .env and copies the example.
    symlink($this->context->path('elsewhere.env'), $this->context->path('.env'));

    new Bootstrap($this->context->basePath, $this->toolchain)->run();

    expect($this->context->path('elsewhere.env'))->not->toBeFile()
        ->and(is_link($this->context->path('.env')))->toBeFalse()
        ->and(fileperms($this->context->path('.env')) & 0777)->toBe(0640)
        ->and(new EnvFile($this->context->path('.env'))->get('APP_KEY'))->toStartWith('base64:');
});
```

`tests/Feature/Console/InstallCommandTest.php` and `tests/Feature/Console/ProductionGuardsTest.php` (g1-13):
- In each `beforeEach`, replace `app()->instance(Detector::class, new Detector(new Toolchain($this->sandbox->path('home'))));` with `withoutPostgresProbe();`.
- Remove `use App\Setup\Detector;`; it is no longer used.
- In `InstallCommandTest.php`, add:

```php
it('defaults to SQLite whatever PostgreSQL the machine runs (g1-13, RL-3)', function () use ($install): void {
    /** @var array<string, mixed> $answers */
    $answers = json_decode((string) file_get_contents(base_path('tests/Fixtures/e2e-answers.json')), true, flags: JSON_THROW_ON_ERROR);
    unset($answers['database']);

    [$exitCode] = $install(['--answers' => json_encode($answers, JSON_THROW_ON_ERROR), '--no-interaction' => true]);

    expect($exitCode)->toBe(0)
        ->and(new EnvFile($this->sandbox->path('.env'))->get('DB_CONNECTION'))->toBe('sqlite');
});
```

> **Never run this test with the probe seam reverted.** Without the seam, a machine with Herd's PostgreSQL would default to pgsql and create databases there. With the seam missing, `beforeEach` fails first ("Unknown named parameter $postgresProbe"), so the red run is safe.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Setup tests/Feature/Console/InstallCommandTest.php tests/Feature/Console/ProductionGuardsTest.php`

Expected: FAIL, as follows:
- Every test that resolves a Detector (all wizard tests, the install command, DetectorTest) fails with "Unknown named parameter $postgresProbe".
- `StateFile::write(..., mode:)` and `phpCli(binary:)` fail with "Unknown named parameter".
- `ProjectFile` does not exist.
- The symlinked state file reads back `['a' => 1]`, and the unreadable one throws `ErrorException`.
- The broken `.env` throws `InvalidFileException`.
- The cached `.env` already shows `OTHER_KEY`.
- The marker row passes for a symlink and throws `ErrorException` for an unreadable marker.
- The setup token is skipped behind an unreadable marker.
- The finish page answers 500 with a `null` marker.
- The script has no `umask 077`.
- The symlinked `herd.yml`, `.mcp.json`, `composer.json`, `.env.testing` and `.env` are written through (the outside files change), and `.env` and `.env.testing` have the umask's mode.

- [ ] **Step 3: State files and project files (RL-1, RL-2)**

`app/Setup/Support/StateFile.php`: replace the class docblock, `write()` and `readJson()`:

```php
/**
 * The setup's small state files (RL-5): written atomically (a temp file in the same directory, then rename(), which
 * replaces a symlink instead of following it) with mode 0600 unless the caller names another (RL-1: ProjectFile and
 * the .env copies), and read back as JSON objects. A read refuses a symlink and a file it cannot read as it refuses a
 * file that is no JSON object, so every caller that catches JsonException treats all three as damaged (RL-2).
 */
final class StateFile
{
    /** @param  array<string, mixed>  $data */
    public static function writeJson(string $path, array $data): void
    {
        self::write($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    public static function write(string $path, string $contents, int $mode = 0600): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException(__('Could not create :directory.', ['directory' => basename($directory)]));
        }

        // A random name created exclusively ('x' refuses an existing file or symlink); the security preset bans tempnam().
        $temporary = $directory.'/.state-'.bin2hex(random_bytes(8));
        $handle = @fopen($temporary, 'x');

        if ($handle === false) {
            throw new RuntimeException(__('Could not write :file.', ['file' => basename($path)]));
        }

        // The mode before any content goes in, so a secret is never readable at the umask's mode.
        chmod($temporary, $mode);
        $written = fwrite($handle, $contents) === strlen($contents);
        fclose($handle);

        if (! $written || ! rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException(__('Could not write :file.', ['file' => basename($path)]));
        }
    }

    /**
     * @return array<string, mixed>|null null when the file does not exist
     *
     * @throws JsonException when the file is a symlink, cannot be read or is not a JSON object (RL-2)
     */
    public static function readJson(string $path): ?array
    {
        if (is_link($path)) {
            throw new JsonException(__(':file is a symbolic link.', ['file' => basename($path)]));
        }

        if (! is_file($path)) {
            return null;
        }

        $contents = is_readable($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new JsonException(__(':file is not readable.', ['file' => basename($path)]));
        }

        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new JsonException(__(':file is not a JSON object.', ['file' => basename($path)]));
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
```

`app/Setup/Support/ProjectFile.php` (new):

```php
<?php

declare(strict_types=1);

namespace App\Setup\Support;

/**
 * The project files setup edits: herd.yml, .mcp.json, composer.json and .claude/settings.local.json (RL-1, g2-07).
 * They are written through StateFile, atomically, so a crash never leaves half a composer.json, and a symlink at the
 * path is replaced, never followed. An existing regular file keeps its mode; a new file, or one that was a symlink,
 * gets 0644.
 */
final class ProjectFile
{
    public static function write(string $path, string $contents): void
    {
        $mode = is_file($path) && ! is_link($path) ? fileperms($path) & 0777 : 0644;

        StateFile::write($path, $contents, $mode);
    }
}
```

`app/Setup/Steps/HerdManifestStep.php`:
- add `use App\Setup\Support\ProjectFile;`
- in `run()`, replace `file_put_contents($context->path('herd.yml'), $this->manifest($context));` with:

```php
        ProjectFile::write($context->path('herd.yml'), $this->manifest($context));
```

`app/Setup/Steps/ClaudeStep.php`: add `use App\Setup\Support\ProjectFile;`, then make three replacements.

In `run()`:

```php
        if (! is_file($context->path('.claude/settings.local.json'))) {
            if (! is_dir($context->path('.claude'))) {
                mkdir($context->path('.claude'), 0755, true);
            }

            ProjectFile::write($context->path('.claude/settings.local.json'), "{}\n");
        }
```

In `registerHerdMcp()`:

```php
        ProjectFile::write($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
```

In `addBoostUpdate()`:

```php
        ProjectFile::write($context->path('composer.json'), json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
```

`app/Setup/Steps/DatabaseStep.php`:
- add `use App\Setup\Support\StateFile;`
- in `writeEnvironment()`, replace:

```php
        if (! copy($env, $testing)) {
            throw new RuntimeException('Could not write .env.testing.');
        }

        chmod($testing, fileperms($env) & 0777);
```

with:

```php
        // Atomically with .env's mode: .env.testing never sits at the umask's mode, and a symlink is replaced (RL-1).
        StateFile::write($testing, (string) file_get_contents($env), fileperms($env) & 0777);
```

`RuntimeException` stays imported; the class throws it elsewhere.

`app/Setup/Support/Bootstrap.php`: replace:

```php
        if (! $env->exists() && is_file($this->basePath.'/.env.example')) {
            copy($this->basePath.'/.env.example', $this->basePath.'/.env');
            $actions[] = __('Created .env from .env.example');
        }
```

with:

```php
        if (! $env->exists() && is_file($this->basePath.'/.env.example')) {
            $example = $this->basePath.'/.env.example';
            // Atomically, with the example's mode: a symlinked .env is replaced, never written through (RL-1).
            StateFile::write($this->basePath.'/.env', (string) file_get_contents($example), fileperms($example) & 0777);
            $actions[] = __('Created .env from .env.example');
        }
```

`StateFile` lives in the same namespace, so no import is needed.

- [ ] **Step 4: The toolchain, the probe seam, the doctor and the Herd stage**

`app/Setup/Support/Toolchain.php`: replace the whole file:

```php
<?php

declare(strict_types=1);

namespace App\Setup\Support;

use Dotenv\Exception\ExceptionInterface;

/**
 * The environment every setup child process gets (DEC-23): php-fpm under Herd has no PATH, so HOME and PATH are
 * built here and herd is always called by its absolute path. Lookups run in-process, never through a shell. Not a
 * readonly class: the parsed .env names live in a static cache (g3-09), which PHP allows only outside one.
 */
final class Toolchain
{
    private const string SYSTEM_PATH = '/usr/local/bin:/opt/homebrew/bin:/usr/bin:/bin:/usr/sbin:/sbin';

    /** Variables a child keeps although the project's .env names them: the session's own environment (RL-12). */
    private const array KEEP = ['HOME', 'PATH', 'USER', 'LOGNAME', 'SHELL', 'TMPDIR', 'LANG', 'TERM'];

    /** One assignment per line, for a .env that Dotenv cannot parse (g3-08): its names are still scrubbed. */
    private const string ASSIGNMENT = '/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/m';

    /** @var array<string, array{stamp: string, keys: list<string>}> each .env's variable names by path, kept while its stamp stays (g3-09) */
    private static array $envKeys = [];

    /**
     * @param  string|null  $home  the home directory to build from; null detects it (RL-3)
     * @param  string|null  $basePath  the project whose .env keys a child must not inherit; null scrubs nothing (RL-12)
     */
    public function __construct(private readonly ?string $home = null, private readonly ?string $basePath = null) {}

    /** HOME from the argument, the environment or the password database, in that order (RL-3). */
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

    /**
     * The environment for child processes: the Herd bin dir, the newest Herd node, ~/.local/bin, then the system PATH
     * (DEC-23). SHELL_VERBOSITY is removed (false), because Symfony Console exports it for --quiet and -v, and Symfony
     * Console children such as herd would otherwise inherit it and print nothing (DEC-30 (3)). With a base path every
     * other key of that project's .env is removed too (RL-12): children then read the current .env themselves, and
     * npm's install scripts or claude never inherit APP_KEY or the database password. A .env that Dotenv cannot parse
     * is scrubbed by its assignment lines instead of crashing detection or the doctor (g3-08).
     *
     * @return array{HOME: string, PATH: string, SHELL_VERBOSITY: false, ...<string, string|false>}
     */
    public function environment(): array
    {
        $scrubbed = [];

        if ($this->basePath !== null) {
            foreach ($this->projectEnvKeys($this->basePath.'/.env') as $key) {
                if (! in_array($key, self::KEEP, true) && ! str_starts_with($key, 'LC_')) {
                    $scrubbed[$key] = false;
                }
            }
        }

        $directories = array_filter([
            $this->herdBinDirectory(),
            $this->nodeBinDirectory(),
            $this->home().'/.local/bin',
        ]);

        return ['HOME' => $this->home(), 'PATH' => implode(':', [...$directories, self::SYSTEM_PATH]), 'SHELL_VERBOSITY' => false] + $scrubbed;
    }

    /** The absolute path of the herd binary, or null when it is not installed. */
    public function herd(): ?string
    {
        $herd = $this->herdBinDirectory().'/herd';

        return is_file($herd) && is_executable($herd) ? $herd : null;
    }

    /** The first executable named $binary on environment()['PATH'], or null. */
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

    /**
     * The CLI php for a detached child (RL-4). Under php-fpm, PHP_BINARY is the fpm binary: Herd ships the CLI next to
     * it (php84-fpm → php84), and `php` on the Herd bin directory is Herd's shim. $binary defaults to PHP_BINARY;
     * tests pass an fpm path (g4-18).
     */
    public function phpCli(?string $binary = null): ?string
    {
        $binary ??= PHP_BINARY;
        $cli = str_ends_with($binary, '-fpm') ? substr($binary, 0, -4) : $binary;

        return is_file($cli) && is_executable($cli) ? $cli : $this->which('php');
    }

    /** The bin directory of the newest node Herd installed through nvm, or null. */
    public function nodeBinDirectory(): ?string
    {
        $versions = glob($this->home().'/Library/Application Support/Herd/config/nvm/versions/node/v*/bin', GLOB_ONLYDIR) ?: [];

        usort($versions, fn (string $a, string $b): int => version_compare(
            ltrim(basename(dirname($b)), 'v'),
            ltrim(basename(dirname($a)), 'v'),
        ));

        return $versions[0] ?? null;
    }

    public function herdConfigPath(): string
    {
        return $this->home().'/Library/Application Support/Herd/config/herd.json';
    }

    /** dirname(PHP_BINARY) when PHP is Herd's own under home(), else home()'s Herd bin directory (RL-3). */
    private function herdBinDirectory(): string
    {
        $phpDirectory = dirname(PHP_BINARY);

        return str_ends_with($phpDirectory, '/Herd/bin') && str_starts_with($phpDirectory, $this->home().'/')
            ? $phpDirectory
            : $this->home().'/Library/Application Support/Herd/bin';
    }

    /**
     * The variable names of a project's .env, parsed once while its mtime, size and inode stay the same (g3-09), since
     * which() calls environment() for every lookup. EnvFile writes by rename(), so every write gets a new inode. A
     * file Dotenv cannot parse still gives its names line by line (g3-08).
     *
     * @return list<string>
     */
    private function projectEnvKeys(string $path): array
    {
        clearstatcache(true, $path);

        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $stamp = implode(':', [(int) filemtime($path), (int) filesize($path), (int) fileinode($path)]);

        if ((self::$envKeys[$path]['stamp'] ?? null) === $stamp) {
            return self::$envKeys[$path]['keys'];
        }

        try {
            $keys = array_keys(new EnvFile($path)->all());
        } catch (ExceptionInterface) {
            preg_match_all(self::ASSIGNMENT, (string) file_get_contents($path), $matches);
            $keys = array_values(array_unique($matches[1]));
        }

        self::$envKeys[$path] = ['stamp' => $stamp, 'keys' => $keys];

        return $keys;
    }
}
```

`app/Setup/Detector.php`:
- add `use Closure;`
- replace the constructor and the first lines of `postgresReachable()`:

```php
    /**
     * @param  (Closure(string, int): bool)|null  $postgresProbe  replaces the PDO probe (RL-3): tests pass one, so no
     *                                                           test opens a connection to the developer's PostgreSQL
     */
    public function __construct(private Toolchain $toolchain, private ?Closure $postgresProbe = null) {}
```

```php
    /** Whether Postgres answers with Herd's known credentials: user root, empty password, database postgres. */
    public function postgresReachable(string $host = '127.0.0.1', int $port = 5432): bool
    {
        if ($this->postgresProbe instanceof Closure) {
            return ($this->postgresProbe)($host, $port);
        }

        try {
            new PDO("pgsql:host={$host};port={$port};dbname=postgres", 'root', '', [
```

The rest of the method is unchanged. The container still autowires `Detector`: an unresolvable `?Closure` parameter falls back to its default `null`.

`app/Setup/Doctor/DoctorContext.php`: replace `forApplication()`:

```php
    /**
     * The context of the running application, or of the project at $basePath (tests pass a sandbox, L-10): a fresh
     * detection and its marker, which is null when it is absent or unreadable (A8).
     */
    public static function forApplication(Toolchain $toolchain, Detector $detector, ?string $basePath = null): self
    {
        $basePath ??= base_path();
        $detection = $detector->detect();
        $setup = new SetupContext($basePath, Answers::defaults($detection, basename($basePath)), $detection);

        return new self($basePath, $toolchain, $detection, self::readableMarker($setup));
    }
```

`AppServiceProvider`'s binding (two arguments) is unchanged. `readableMarker()` already catches `JsonException`, which now also covers a symlinked or unreadable marker (RL-2).

`app/Setup/Doctor/Checks/MarkerCheck.php`: replace `run()`:

```php
    public function run(DoctorContext $context): CheckResult
    {
        $path = $context->path(Marker::FILE);

        if (! is_file($path)) {
            return CheckResult::skipped(__('The app has not been set up yet'));
        }

        // RL-2: a symlink is refused and an unreadable file is not "not valid JSON" (F3 (4), L-09).
        if (is_link($path) || ! is_readable($path)) {
            return CheckResult::fail(
                __('The install marker :file cannot be read', ['file' => Marker::FILE]),
                __('Restore it from a backup, or delete it and run php artisan skv:install'),
            );
        }

        try {
            Marker::read($context->setupContext());
        } catch (JsonException) {
            return CheckResult::fail(
                __('The install marker :file is not valid JSON', ['file' => Marker::FILE]),
                __('Restore it from a backup, or delete it and run php artisan skv:install'),
            );
        }

        return CheckResult::pass(__('The install marker reads back'));
    }
```

`app/Setup/Doctor/Checks/SetupTokenCheck.php`: add `use App\Setup\Marker;`, then gate on the marker file instead of the parsed marker (L-13):

```php
/**
 * §5.4, §5.3.4: once setup has finished, the one-time setup token is gone; the fix deletes it. "Finished" is the
 * marker file, as for SetupMode, not the parsed marker: an unreadable marker must not hide a leftover token (L-13).
 */
final readonly class SetupTokenCheck implements Check
{
    public function key(): string
    {
        return 'setup-token';
    }

    public function label(): string
    {
        return __('Setup token');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if (! is_file($context->path(Marker::FILE))) {
            return CheckResult::skipped(__('Setup has not finished yet'));
        }

        if (! file_exists($context->path(SetupToken::PATH))) {
            return CheckResult::pass(__('The setup token is gone'));
        }

        return CheckResult::warn(__('The setup token is still there'), 'php artisan skv:doctor --fix');
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        if (! is_file($context->path(Marker::FILE))) {
            return null;
        }

        File::delete($context->path(SetupToken::PATH));
        $context->forgetSync();

        return $this->run($context);
    }
}
```

`app/Setup/Wizard/HerdStage.php`: replace `summary()`. `JsonException` is already imported.

```php
    /**
     * @return array<string, mixed> the marker's summary (§5.3.4: the summary is read from the marker); none when the
     *                              marker no longer reads back, so the finish page and its login stay usable (g3-06)
     */
    public function summary(): array
    {
        try {
            return Marker::read(app(SetupContext::class))['summary'] ?? [];
        } catch (JsonException) {
            return [];
        }
    }
```

`app/Setup/Wizard/DetachedStepLauncher.php`: replace the constant and its comment:

```php
    /**
     * Outer: $1 php, $2 key, $3 result, $4 log, $5 exit; inner: $0 php, $1 key, $2 result, $3 log, $4 exit. The inner
     * script starts with umask 077, so the redirect and `echo $? >` create the log and the exit file 0600 (g3-16).
     */
    private const string SCRIPT = 'nohup sh -c \'umask 077; "$0" artisan skv:run-step "$1" --no-interaction --result="$2" > "$3" 2>&1; echo $? > "$4"\' "$1" "$2" "$3" "$4" "$5" > /dev/null 2>&1 &';
```

- [ ] **Step 5: Copy**

Lang keys, in both files, at their sorted positions (PHP `sort()` order):

| Key | nl | Position |
|---|---|---|
| `:file is a symbolic link.` | `:file is een symbolische link.` | right after `:email is a super admin.` |
| `:file is not readable.` | `:file is niet leesbaar.` | right after `:file is not a JSON object.` |
| `The install marker :file cannot be read` | `De installatiemarkering :file is niet te lezen` | right before `The install marker :file is not valid JSON` |

In `en.json` each value equals its key.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Setup tests/Feature/Console tests/Feature/LangFilesTest.php tests/Feature/LangCoverageTest.php`

Expected: PASS. If another test builds a context with `$withMarker` and expects the setup-token row to Warn, give it a real marker file (`Marker::write($this->sandbox, [])`) the way the rewritten token test does.

- [ ] **Step 7: Verify by mutation**

Revert each production change locally, confirm the named test fails, restore it, and confirm it passes. Name each mutation and its result in the task report.
1. In `Toolchain::projectEnvKeys()`, drop the `self::$envKeys` lookup. Expect 'parses an unchanged .env once, and again once it changes' to fail on `$cached`.
2. Drop `umask 077; ` from `DetachedStepLauncher::SCRIPT`. Expect 'creates the child's log and exit files private' to fail.
3. In `withoutPostgresProbe()`, replace `app()->bind(...)` with `app()->instance(Detector::class, new Detector(app(Toolchain::class), postgresProbe: …))`. Expect HerdStageTest 'settles a site secured by hand…' to fail. Its `$withHerd` rebinds `Toolchain` after `useSetupWizard()`, so the Detector instance would keep the toolchain without Herd. `HerdSiteStep::confirm()` then sees `detection->herd === false` and returns null, and the page stays on the manual command.
4. In `ProjectFile::write()`, replace the mode expression with `0600`. Expect 'keeps the mode of the file it replaces' to fail.

- [ ] **Step 8: The gate, then commit**

Run the Global Constraints gate:
- `composer check`
- `composer test:pgsql`
- the modules-off run
- `npm run build && composer test:browser`
- `npm run build:setup && git diff --exit-code resources/setup/setup.css` (Task 1 changes no setup view, so expect no diff)
- `php artisan config:clear`

```bash
git add app/Setup/Support/StateFile.php app/Setup/Support/ProjectFile.php app/Setup/Support/Toolchain.php app/Setup/Support/Bootstrap.php \
    app/Setup/Detector.php app/Setup/Doctor/DoctorContext.php app/Setup/Doctor/Checks/MarkerCheck.php app/Setup/Doctor/Checks/SetupTokenCheck.php \
    app/Setup/Steps/HerdManifestStep.php app/Setup/Steps/ClaudeStep.php app/Setup/Steps/DatabaseStep.php \
    app/Setup/Wizard/HerdStage.php app/Setup/Wizard/DetachedStepLauncher.php \
    lang/nl.json lang/en.json tests/Pest.php tests/Feature/Setup tests/Feature/Console/InstallCommandTest.php \
    tests/Feature/Console/ProductionGuardsTest.php tests/Browser/SetupWizardTest.php
git commit -m "fix: harden the setup's machine probes and state files

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: The setup gate and hosts (g4-01, g3-17, g3-25, g3-26, g3-27, g3-28, g3-10, g3-34, g1-17, g3-13, g3-35, g4-17, g4-15, g3-33; RL-4, RL-5)

**Files:**
- Create:
  - `app/Setup/Http/SetupHostGuard.php`
  - `resources/views/setup/host-problem.blade.php` (`/` on a refused host with no address to suggest)
- Modify:
  - `app/Setup/Http/DirectRequest.php` (RL-4)
  - `app/Setup/Http/EnsureSetupAccess.php` (session regeneration, no token in `_previous.url`, the pinned host)
  - `app/Setup/SetupMode.php` (`homeRedirect()` only on an allowed host; `homeHostProblem()` for the others)
  - `routes/web.php` (`/` on a refused host: a token-less redirect to the suggestion, or the problem with 409)
  - `app/Setup/SetupHost.php` (no IP without Herd; `folderProblem()`)
  - `app/Setup/Wizard/HerdStage.php` (`state()` checks the pinned host)
  - `app/Setup/Wizard/WizardRunner.php` (`defaults()`, `begin()` and `host()` through `SetupHostGuard::resolve()`)
  - `resources/views/pages/setup/wizard/wizard.php` (the request port, the problem text, no `#[Title]`)
  - `routes/setup.php` (`SetReferrerPolicy`)
  - `app/Setup/Steps/EnvironmentStep.php` (the `APP_URL` port)
  - `app/Console/Commands/InstallCommand.php` (the folder under Herd)
  - `scripts/e2e-local.sh` (the entries without Herd reach the wizard under a host it pins)
  - `lang/nl.json`, `lang/en.json`
  - `resources/setup/setup.css`, after `npm run build:setup` (the new view sits under `resources/views/setup`)
- Test:
  - `tests/Feature/Setup/Wizard/SetupAccessTest.php`, `tests/Feature/Setup/Wizard/HerdStageTest.php`
  - `tests/Feature/Setup/SetupModeTest.php`, `tests/Feature/Setup/SetupHostTest.php`
  - `tests/Feature/Setup/Wizard/WizardRunnerTest.php`, `tests/Feature/Setup/Wizard/WizardPageTest.php`
  - `tests/Feature/Setup/Steps/EnvironmentStepTest.php`
  - `tests/Feature/Console/InstallCommandTest.php`

**Interfaces:**
- Consumes:
  - `HerdTld::read(Toolchain): ?string`, `Toolchain::herd(): ?string`
  - `WizardState::load(string): ?WizardState` (throws `JsonException|InvalidArgumentException`), `WizardState::$host`, `HerdState::$host`
  - `App\Http\Middleware\SetReferrerPolicy`
  - `withoutPostgresProbe()` from Task 1
  - `pageSnapshot()`, `callPageAction(..., ?string $host)`, `Hosts::url()`, `Hosts::admin()`, `Hosts::tenant()`
- Produces:
  - `DirectRequest::isDirect(Request $request): bool`: local, a loopback `REMOTE_ADDR` (127.0.0.0/8, ::1), and no header named `x-forwarded-*`, `x-expose-*`, `forwarded` or `x-original-host` (RL-4)
  - `App\Setup\Http\SetupHostGuard`:
    - `allows(Request $request, ?string $pinned): bool` (RL-5)
    - `pinnedWizardHost(string $basePath): ?string`
    - `resolve(string $httpHost, Toolchain $toolchain, string $folder): array{host: ?string, problem: ?string, suggestion: ?string}`. `suggestion` is the address to open on refusal: `http://localhost[:port]` without Herd, `http://{folder}.{tld}` under Herd, null for a folder that is no DNS label or a failed `herd tld`.
  - `SetupMode::homeHostProblem(Request $request): ?array` (`array{suggestion: ?string, problem: string}|null`): non-null only in setup mode, for a direct request, with a token, while no super admin exists and no host is pinned, when `resolve()` refuses the host
  - `/` on such a host: a token-less `redirect()->away()` to the suggestion, or the `setup.host-problem` view (in `layouts::setup`) with status 409 when there is none
  - `SetupHost::folderProblem(string $folder): ?string`
  - `SetupHost::forWizard()` without Herd: `localhost`, `*.localhost` or `*.test`, never an IP address (g3-34)
  - `WizardRunner::defaults(string $requestHost): array{answers: Answers, host: ?string, problem: ?string}`. The page passes `getHttpHost()` (host[:port]) to `defaults()` and `begin()`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Setup/Wizard/SetupAccessTest.php`:
- Add the imports:
  - `use App\Setup\Wizard\WizardState;`
  - `use App\Tenancy\Hosts;`
  - `use Illuminate\Support\Str;`
- Add `'X-Forwarded-Port'` and `'X-Forwarded-Prefix'` to the 'answers 404 behind a proxy or tunnel' dataset (g3-17):

```php
})->with(['X-Forwarded-For', 'X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Forwarded-Port', 'X-Forwarded-Prefix', 'X-Original-Host', 'Forwarded', 'X-Expose-Subdomain']);
```

- Add:

```php
it('answers 404 to a request from another machine and never hands it the token (RL-4, g4-01)', function (string $address): void {
    $context = useSetupWizard();
    $token = (string) new SetupToken($context->basePath)->value();
    $this->withServerVariables(['REMOTE_ADDR' => $address]);

    $home = (string) $this->get('/')->assertRedirect()->headers->get('Location');

    expect($home)->not->toContain('token=');
    $this->get('/welcome-setup?token='.$token)->assertNotFound();
})->with(['LAN' => '192.168.1.20', 'public' => '203.0.113.7', 'IPv6' => '2001:db8::1']);

it('lets every loopback address through (RL-4)', function (string $address): void {
    $context = useSetupWizard();
    $this->withServerVariables(['REMOTE_ADDR' => $address]);

    $this->get('/welcome-setup?token='.new SetupToken($context->basePath)->value())->assertRedirect(url('/welcome-setup'));
})->with(['127.0.0.1', '127.0.0.2', '::1']);

it('issues a new session id when it accepts the token, so a planted id never becomes authorised (g3-26)', function (): void {
    $context = useSetupWizard();
    $planted = Str::random(40);

    $this->withCookie((string) config('session.cookie'), $planted)
        ->get('/welcome-setup?token='.new SetupToken($context->basePath)->value())
        ->assertRedirect(url('/welcome-setup'));

    expect(session()->getId())->not->toBe($planted)
        ->and(session(EnsureSetupAccess::SESSION_KEY))->toBe(app(SetupToken::class)->hash())
        ->and((string) session()->getHandler()->read($planted))->not->toContain((string) app(SetupToken::class)->hash());
});

it('never stores the token in the session, not even as the previous URL (g3-27)', function (): void {
    $context = useSetupWizard();
    $token = (string) new SetupToken($context->basePath)->value();

    $this->get('/welcome-setup?token='.$token)->assertRedirect(url('/welcome-setup'));

    expect((string) session()->previousUrl())->not->toContain($token)
        ->and(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain($token);
});

it('sends no referrer from the wizard, whose first URL carries the token (g3-13)', function (): void {
    $context = useSetupWizard();

    $this->get('/welcome-setup?token='.new SetupToken($context->basePath)->value())->assertRedirect()->assertHeader('Referrer-Policy', 'no-referrer');
    $this->get('/welcome-setup')->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
});

it('answers only on the pinned host and its admin host once setup has started (RL-5, g3-25)', function (): void {
    $context = useSetupWizard();
    $this->get('/welcome-setup?token='.new SetupToken($context->basePath)->value());
    $snapshot = pageSnapshot(url('/welcome-setup'), 'pages::setup.wizard');
    $this->withHeader('X-CSRF-TOKEN', session()->token());
    callPageAction($snapshot, 'start', updates: [
        'appName' => 'Acme', 'database' => 'sqlite', 'startWith' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'claude' => false,
    ])->assertOk();

    expect(WizardState::load($context->basePath)?->host)->toBe('localhost');
    callPageAction($snapshot, '$refresh', host: Hosts::admin())->assertOk();
    callPageAction($snapshot, 'advance', host: Hosts::tenant('acme'))->assertNotFound();
    $this->get(Hosts::url(Hosts::tenant('acme'), '/welcome-setup'))->assertNotFound();
    $this->get(Hosts::url(Hosts::apex(), '/welcome-setup'))->assertOk();
});

it('opens on the apex, the admin host and a tenant host until the marker exists (§6.2, g3-35)', function (string $host): void {
    $context = useSetupWizard();
    $token = (string) new SetupToken($context->basePath)->value();

    $this->get(Hosts::url($host, '/welcome-setup?token='.$token))->assertRedirect(Hosts::url($host, '/welcome-setup'));
    $this->get(Hosts::url($host, '/welcome-setup'))->assertOk()->assertSee('data-test="setup-wizard"', false);

    Marker::write($context, []);

    $this->get(Hosts::url($host, '/welcome-setup'))->assertNotFound();
})->with([
    'apex' => fn (): string => Hosts::apex(),
    'admin' => fn (): string => Hosts::admin(),
    'tenant' => fn (): string => Hosts::tenant('acme'),
]);
```

> After a request to another host, the test client resolves a relative URI against that host: `url()` follows the last request. So in 'answers only on the pinned host…', every request after the cross-host one spells its host with `Hosts::url()`; a bare `'/welcome-setup'` there would go to `acme.localhost` and get the guard's 404.

`tests/Feature/Setup/Wizard/HerdStageTest.php`: add `use App\Models\Organisation;`, then:

- Rewrite 'settles a site secured by hand once herd secured lists it, when the manual page is reloaded'. It pins `{folder}.test` but sent its requests to `localhost`, and the Herd stage now answers only on the pinned host (RL-5). After the marker the tenant resolver runs, so the apex follows the pinned host, as `.env` does after the Environment step:

```php
it('settles a site secured by hand once herd secured lists it, when the manual page is reloaded (§5.3.4, RL-6)', function () use ($afterStepNine, $withHerd): void {
    $context = useSetupWizard();
    $host = $context->folderName().'.test';
    $secret = $afterStepNine($context, 'demo', $host);
    config(['tenancy.apex_domain' => $host]);
    $withHerd($context);
    Process::fake([
        "*herd' 'services:list'*" => Process::result(''),
        "*herd' 'links'*" => Process::result(''),
        "*herd' 'paths'*" => Process::result(json_encode([dirname($context->basePath)], JSON_THROW_ON_ERROR)),
        // Not secured when the poll timed out; listed after the user ran `herd secure` by hand.
        "*herd' 'secured'*" => Process::sequence()->push(Process::result(''))
            ->whenEmpty(Process::result("| Site | Expires |\n| {$context->folderName()}.test | 2027-01-01 00:00:00 GMT |\n")),
    ]);
    $this->travel(HerdState::POLL_SECONDS + 1)->seconds();

    $this->get('http://'.$host.'/welcome-setup/finish?secret='.$secret.'&timeout=1')->assertSee('data-test="setup-manual-herd"', false);
    $this->get('http://'.$host.'/welcome-setup/finish?secret='.$secret)->assertOk()->assertSee('data-test="setup-summary"', false);

    $state = HerdState::load($context->basePath) ?? throw new LogicException('No Herd state.');
    expect($state->result?->message)->toStartWith(HerdSiteStep::SECURED)
        ->and(new EnvFile($context->path('.env'))->get('APP_URL'))->toBe('https://'.$context->folderName().'.test')
        ->and(Marker::read($context)['answers']['https'] ?? null)->toBeTrue();
    // Only Herd's listings ran in the web request: never link, isolate or secure.
    Process::assertDidntRun(fn ($process): bool => preg_match("/herd' '(link|isolate|secure)'/", implode(' ', (array) $process->command)) === 1);
});
```

- Add:

```php
it('answers 404 on a host other than the pinned one or its admin host (RL-5, g3-25)', function () use ($afterStepNine): void {
    $context = useSetupWizard();
    $secret = $afterStepNine($context);
    // An organisation, so the tenant resolver lets its host through to the Herd stage.
    Organisation::factory()->create(['slug' => 'acme']);

    $this->getJson(Hosts::url(Hosts::admin(), '/welcome-setup/herd-status?secret='.$secret))->assertOk();
    $this->getJson(Hosts::url(Hosts::tenant('acme'), '/welcome-setup/herd-status?secret='.$secret))->assertNotFound();
    $this->get(Hosts::url(Hosts::tenant('acme'), '/welcome-setup/finish?secret='.$secret))->assertNotFound();
});

it('answers 404 to a Herd-stage request from another machine (RL-4, g4-01)', function () use ($afterStepNine): void {
    $secret = $afterStepNine(useSetupWizard());

    $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.20'])
        ->getJson('/welcome-setup/herd-status?secret='.$secret)->assertNotFound();
});

it('sends Referrer-Policy: no-referrer from every Herd-stage endpoint, whose URLs carry the secret (g3-13)', function () use ($afterStepNine): void {
    $context = useSetupWizard();
    $secret = $afterStepNine($context);

    $this->getJson('/welcome-setup/herd-status?secret='.$secret)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
    $this->get('/welcome-setup/finish?secret='.$secret)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');

    $state = HerdState::load($context->basePath) ?? throw new LogicException('No Herd state.');
    $state->result = StepResult::skipped('Herd is not installed');
    $state->save($context->basePath);

    $this->get('/welcome-setup/finish?secret='.$secret)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
    $this->get('/welcome-setup/login?secret='.$secret)->assertRedirect()->assertHeader('Referrer-Policy', 'no-referrer');
});
```

`tests/Feature/Setup/SetupModeTest.php`:
- Add `use App\Setup\SetupContext;` and `use Illuminate\Support\Facades\File;`.
- After the `beforeEach`, add the file-local Herd stub:

```php
// Herd installed in the sandbox home; `herd tld` answers "test", or fails with $tldExitCode.
$withHerd = function (SetupContext $context, int $tldExitCode = 0): void {
    $herd = $context->path('home/Library/Application Support/Herd/bin/herd');
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);
    app()->instance(Toolchain::class, new Toolchain($context->path('home'), $context->basePath));
    Process::fake([
        "*herd' 'tld'*" => Process::result($tldExitCode === 0 ? "test\n" : '', exitCode: $tldExitCode),
        "*herd' 'services:list' '--json'*" => Process::result(''),
    ]);
};
```

- Add:

```php
it('hands the token out only on a host the wizard can pin (RL-5, g3-25)', function (): void {
    $context = useSetupWizard();
    $token = (string) new SetupToken($context->basePath)->value();

    $refused = (string) $this->get('http://example.com/')->assertRedirect()->headers->get('Location');

    expect($refused)->not->toContain('token=');
    $this->get('http://acme.localhost/')->assertRedirectContains('/welcome-setup?token='.$token);
});

it('sends / on a host the wizard refuses to the address to use, without the token (§5.3.4, g3-34)', function (): void {
    useSetupWizard();

    $location = (string) $this->get('http://127.0.0.1:8000/')->assertRedirect('http://localhost:8000/')->headers->get('Location');

    expect($location)->not->toContain('token=');
});

it('shows the folder problem on / under Herd, where no address would work (§5.3.4, g3-10)', function () use ($withHerd): void {
    $context = useSetupWizard(setupSandbox(prefix: 'my_app_'));
    $withHerd($context);

    $this->get('/')->assertStatus(409)
        ->assertSee(__('The folder name :folder is not a valid web address under Herd. Rename the folder to :name, then start the setup again.', [
            'folder' => $context->folderName(),
            'name' => Str::slug($context->folderName()),
        ]))
        ->assertDontSee((string) new SetupToken($context->basePath)->value());
});

it('shows on / that Herd did not answer while herd tld fails (§5.3.4, g3-28)', function () use ($withHerd): void {
    $context = useSetupWizard();
    $withHerd($context, tldExitCode: 1);

    $this->get('/')->assertStatus(409)
        ->assertSee(__('Herd did not answer. Reload the page in a moment.'))
        ->assertDontSee((string) new SetupToken($context->basePath)->value());
});
```

> 'hands the token out only on a host the wizard can pin' keeps passing after `homeHostProblem()`: `example.com` now redirects to `http://localhost/`, still without a token. The two Herd cases assert the token's value, not `token=`, because the setup layout's Livewire and Flux tags are not under this test's control.

`tests/Feature/Setup/SetupHostTest.php`:
- Add `use App\Setup\Http\SetupHostGuard;` and `use Illuminate\Http\Request;`.
- In the `forWizard` dataset, change the loopback row and add the `.test` row (g3-34, g4-17):

```php
    'no herd loopback' => ['127.0.0.1:8000', null, null],
    'no herd .localhost' => ['acme.localhost', null, 'acme.localhost'],
    'no herd .test' => ['myapp.test', null, 'myapp.test'],
```

- Add:

```php
it('names a folder that can be no Herd address (g3-10)', function (string $folder, bool $refused): void {
    expect(SetupHost::folderProblem($folder) !== null)->toBe($refused);
})->with([
    'label' => ['my-app', false],
    'upper case' => ['My-App', false],
    'underscore' => ['my_app', true],
    'dot' => ['my.app', true],
    'space' => ['my app', true],
]);

it('lets a request through only on the pinned host or its admin host (RL-5)', function (string $url, bool $allowed): void {
    expect(SetupHostGuard::allows(Request::create($url), 'my-app.test'))->toBe($allowed);
})->with([
    'pinned' => ['http://my-app.test/', true],
    'pinned with a port' => ['https://my-app.test:8443/', true],
    'admin' => ['http://admin.my-app.test/', true],
    'upper case' => ['http://ADMIN.My-App.test/', true],
    'tenant subdomain' => ['http://demo1.my-app.test/', false],
    'look-alike' => ['http://xmy-app.test/', false],
    'other' => ['http://evil.example/', false],
]);
```

`tests/Feature/Setup/Wizard/WizardRunnerTest.php`: add:

```php
it('refuses every host while herd tld gives no answer, never falling back to the names without Herd (g3-28)', function (): void {
    $context = useSetupWizard();
    $herd = $context->path('home/Library/Application Support/Herd/bin/herd');
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);
    app()->instance(Toolchain::class, new Toolchain($context->path('home'), $context->basePath));
    Process::fake([
        "*herd' 'tld'*" => Process::result(exitCode: 1),
        "*herd' 'services:list' '--json'*" => Process::result(''),
    ]);

    expect(fn () => app(WizardRunner::class)->begin(['database' => 'sqlite'], 'admin.'.Str::lower($context->folderName()).'.test'))
        ->toThrow(ValidationException::class, __('Herd did not answer. Reload the page in a moment.'))
        ->and($context->path(WizardState::PATH))->not->toBeFile();
});
```

`tests/Feature/Setup/Wizard/WizardPageTest.php`: add `use Dom\HTMLDocument;`, then:

```php
it('titles the page once (g3-33)', function (): void {
    $context = useSetupWizard();
    $this->get('/welcome-setup?token='.new SetupToken($context->basePath)->value());

    $this->get('/welcome-setup')->assertOk()->assertSee('<title>'.__('Setup').'</title>', false);
});

it('names the address to use and disables the start on a host the wizard refuses (§5.3.4, g4-15)', function (): void {
    $context = useSetupWizard();
    $this->get('http://example.com/welcome-setup?token='.new SetupToken($context->basePath)->value());

    $html = (string) $this->get('http://example.com/welcome-setup')->assertOk()
        ->assertSee(__('Open the setup on :url.', ['url' => 'http://localhost']))
        ->getContent();
    $button = HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector('[data-test="setup-start-button"]');

    expect($button?->hasAttribute('disabled'))->toBeTrue();
});

it('refuses 127.0.0.1 without Herd and suggests localhost on the same port (g3-34)', function (): void {
    $context = useSetupWizard();
    $this->get('http://127.0.0.1:8000/welcome-setup?token='.new SetupToken($context->basePath)->value());

    $this->get('http://127.0.0.1:8000/welcome-setup')->assertOk()
        ->assertSee(__('Open the setup on :url.', ['url' => 'http://localhost:8000']));
});

it('asks to rename a folder that can be no Herd address, instead of suggesting one (g3-10)', function (): void {
    $context = useSetupWizard(setupSandbox(prefix: 'my_app_'));
    $herd = $context->path('home/Library/Application Support/Herd/bin/herd');
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);
    app()->instance(Toolchain::class, new Toolchain($context->path('home'), $context->basePath));
    Process::fake([
        "*herd' 'tld'*" => Process::result("test\n"),
        "*herd' 'services:list' '--json'*" => Process::result(''),
    ]);
    $this->get('/welcome-setup?token='.new SetupToken($context->basePath)->value());

    $this->get('/welcome-setup')->assertOk()
        ->assertSee(__('The folder name :folder is not a valid web address under Herd. Rename the folder to :name, then start the setup again.', [
            'folder' => $context->folderName(),
            'name' => Str::slug($context->folderName()),
        ]))
        ->assertDontSee('http://'.Str::lower($context->folderName()).'.test');
});
```

> Flux renders `:disabled="true"` as a `disabled` attribute on the `<button>`. If it renders it on a wrapper instead, query that element; the assertion is that the start control is disabled.

`tests/Feature/Setup/Steps/EnvironmentStepTest.php`: add:

```php
it('keeps the port of an APP_URL on the same host without Herd (g1-17)', function (): void {
    $context = setupSandbox();
    new EnvFile($context->path('.env'))->set(['APP_URL' => 'http://localhost:8000']);

    new EnvironmentStep()->run($context);

    expect(new EnvFile($context->path('.env'))->get('APP_URL'))->toBe('http://localhost:8000');
});
```

`tests/Feature/Console/InstallCommandTest.php`: add `use Illuminate\Support\Facades\File;`, then:

```php
it('refuses a folder name that can be no Herd address before anything runs (g3-10)', function () use ($install): void {
    $sandbox = setupSandbox(prefix: 'my_app_');
    $herd = $sandbox->path('home/Library/Application Support/Herd/bin/herd');
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);
    app()->instance(Toolchain::class, new Toolchain($sandbox->path('home')));
    app()->instance(SetupContext::class, $sandbox);
    $before = (string) file_get_contents($sandbox->path('.env'));

    [$exitCode, $output] = $install(['--answers' => (string) file_get_contents(base_path('tests/Fixtures/e2e-answers.json')), '--no-interaction' => true]);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain(Str::slug($sandbox->folderName()))
        ->and(Marker::exists($sandbox))->toBeFalse()
        ->and(file_get_contents($sandbox->path('.env')))->toBe($before);
});
```

> The Detector comes from Task 1's lazy `withoutPostgresProbe()`, so it sees this test's toolchain with Herd. The file's catch-all fake answers `services:list` and `tld`; the folder check needs neither.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Setup tests/Feature/Console/InstallCommandTest.php`

Expected: FAIL, as follows:
- **SetupAccessTest:**
  - `X-Forwarded-Port` and `X-Forwarded-Prefix` answer 302 instead of 404.
  - A remote `REMOTE_ADDR` gets the token redirect and a 302.
  - The planted session id is kept.
  - `_previous.url` holds the token.
  - There is no `Referrer-Policy` header.
  - The tenant-host `advance` answers 200.
- **HerdStageTest:** the tenant host and the remote address answer 200, and there is no `Referrer-Policy` header.
- **SetupModeTest:**
  - `example.com` gets the token.
  - `127.0.0.1:8000` gets the token redirect instead of `http://localhost:8000/`.
  - The `my_app_` folder and the failing `herd tld` get the token redirect, not a 409 with the problem.
- **SetupHostTest:** the loopback row gives `127.0.0.1`, and `folderProblem()` and `SetupHostGuard` do not exist.
- **WizardRunnerTest:** `begin()` accepts `admin.<folder>.test` when `herd tld` fails.
- **WizardPageTest:**
  - The title is "Setup - Setup".
  - `127.0.0.1:8000` shows no callout.
  - The `my_app_` folder gets "Open the setup on http://my_app_….test."
- **EnvironmentStepTest:** `APP_URL` loses `:8000`.
- **InstallCommandTest:** the install runs (exit 0).

The g3-35 dataset, the g4-15 test, the `.test` row and the rewritten HerdStageTest case pass already; Step 7 mutation-checks them.

- [ ] **Step 3: The direct-request rule and the host guard (RL-4, RL-5)**

`app/Setup/Http/DirectRequest.php`: replace the whole file:

```php
<?php

declare(strict_types=1);

namespace App\Setup\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * A request straight from the local machine (§5.3.4, DEC-24, RL-4): the local environment, a loopback REMOTE_ADDR
 * (127.0.0.0/8 or ::1) read raw, never through the trusted proxies, and none of the headers a proxy or a tunnel adds:
 * no X-Forwarded-* of any kind, no X-Expose-*, no Forwarded and no X-Original-Host. The address alone is not enough,
 * because `herd share` traffic arrives from loopback too; the headers alone are not enough, because `php artisan
 * serve --host=0.0.0.0` lets the LAN in without any (g4-01).
 */
final class DirectRequest
{
    private const array LOOPBACK = ['127.0.0.0/8', '::1'];

    private const array HEADERS = ['forwarded', 'x-original-host'];

    private const array PREFIXES = ['x-forwarded-', 'x-expose-'];

    public static function isDirect(Request $request): bool
    {
        if (! app()->isLocal()) {
            return false;
        }

        $address = $request->server('REMOTE_ADDR');

        if (! is_string($address) || ! IpUtils::checkIp($address, self::LOOPBACK)) {
            return false;
        }

        return array_all(array_keys($request->headers->all()), fn (string $name): bool => ! in_array($name, self::HEADERS, true)
            && ! array_any(self::PREFIXES, fn (string $prefix): bool => str_starts_with($name, $prefix)));
    }
}
```

`app/Setup/SetupHost.php`: replace the whole file:

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
     * there (RL-3). Without Herd only a local name: localhost, *.localhost or *.test. Never an IP address (g3-34): the
     * tenant hosts and passkeys need a name, and *.localhost resolves to this machine in browsers (DEC-29).
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

        $local = $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.test');

        return $local ? $host : null;
    }

    /**
     * Why the folder can be no Herd address, or null (g3-10): under Herd the folder is the site name and the address
     * is {folder}.{tld}, so a folder that is not one DNS label (my_app, my.app) gives an address nobody can open.
     */
    public static function folderProblem(string $folder): ?string
    {
        if (preg_match('/^'.self::LABEL.'$/', Str::lower($folder)) === 1) {
            return null;
        }

        return __('The folder name :folder is not a valid web address under Herd. Rename the folder to :name, then start the setup again.', [
            'folder' => $folder,
            'name' => Str::slug($folder),
        ]);
    }
}
```

`app/Setup/Http/SetupHostGuard.php` (new):

```php
<?php

declare(strict_types=1);

namespace App\Setup\Http;

use App\Setup\SetupContext;
use App\Setup\SetupHost;
use App\Setup\Support\HerdTld;
use App\Setup\Support\Toolchain;
use App\Setup\Wizard\WizardState;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

/**
 * The host rule of the wizard and the Herd stage (RL-5, g3-25). Once begin() pinned a host, a request must come on
 * that host or its admin host; before that, on a host the wizard would pin. Under Herd, and on localhost / *.localhost
 * (which browsers resolve to this machine without DNS), a page on another name that resolves here (DNS rebinding)
 * therefore never reads the token from `/` and never drives a running setup. Without Herd a *.test name is also
 * accepted before begin(); a rebound *.test name can still reach `/` then (residual, DEC-33 (21)).
 */
final class SetupHostGuard
{
    public static function allows(Request $request, ?string $pinned): bool
    {
        if ($pinned !== null) {
            $host = SetupHost::normalise($request->getHost());

            return $host !== null && ($host === $pinned || $host === 'admin.'.$pinned);
        }

        return self::resolve($request->getHttpHost(), app(Toolchain::class), app(SetupContext::class)->folderName())['host'] !== null;
    }

    /** The host the running wizard pinned, or null before begin() or for a state file this kit did not write. */
    public static function pinnedWizardHost(string $basePath): ?string
    {
        try {
            return WizardState::load($basePath)?->host;
        } catch (JsonException|InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The host the wizard pins for a request on $httpHost (host[:port]); on refusal null, the problem to show instead
     * and the address to open, when there is one (A19, RL-3):
     * - Without Herd: a local name, and on refusal localhost on the request's port (g3-34).
     * - Under Herd: {folder}.{tld} or its admin host, and on refusal {folder}.{tld}. A folder that is no DNS label can
     *   never be that address (g3-10), and a `herd tld` without an answer is no reason to fall back to the names
     *   without Herd (g3-28); neither has an address to suggest.
     *
     * @return array{host: ?string, problem: ?string, suggestion: ?string}
     */
    public static function resolve(string $httpHost, Toolchain $toolchain, string $folder): array
    {
        if ($toolchain->herd() === null) {
            $host = SetupHost::forWizard($httpHost, null, $folder);
            $port = preg_match('/:(\d{1,5})$/', $httpHost, $matches) === 1 ? ':'.$matches[1] : '';

            if ($host === null) {
                $suggestion = 'http://localhost'.$port;

                return ['host' => null, 'problem' => __('Open the setup on :url.', ['url' => $suggestion]), 'suggestion' => $suggestion];
            }

            return ['host' => $host, 'problem' => null, 'suggestion' => null];
        }

        $folderProblem = SetupHost::folderProblem($folder);

        if ($folderProblem !== null) {
            return ['host' => null, 'problem' => $folderProblem, 'suggestion' => null];
        }

        $tld = HerdTld::read($toolchain);

        if ($tld === null) {
            return ['host' => null, 'problem' => __('Herd did not answer. Reload the page in a moment.'), 'suggestion' => null];
        }

        $host = SetupHost::forWizard($httpHost, $tld, $folder);

        if ($host === null) {
            $suggestion = 'http://'.Str::lower($folder).'.'.$tld;

            return ['host' => null, 'problem' => __('Open the setup on :url.', ['url' => $suggestion]), 'suggestion' => $suggestion];
        }

        return ['host' => $host, 'problem' => null, 'suggestion' => null];
    }
}
```

- [ ] **Step 4: The gate, `/`, the Herd stage and the routes**

`app/Setup/Http/EnsureSetupAccess.php`: replace the whole file:

```php
<?php

declare(strict_types=1);

namespace App\Setup\Http;

use App\Setup\SetupContext;
use App\Setup\SetupMode;
use App\Setup\SetupToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * The wizard's gate (§5.3.4, RL-2), also Livewire persistent middleware. It requires:
 * - setup mode
 * - a direct request (RL-4)
 * - the one-time token
 * - once begin() pinned a host, that host or its admin host (RL-5)
 *
 * The token arrives once in the query, and its hash then lives in a new (file) session. Seeding a super admin
 * therefore never locks the wizard out, and a session id planted beforehand never becomes authorised (g3-26).
 * Everything else is a 404.
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
            $request->session()->regenerate();
            // StartSession stores fullUrl() as _previous.url after this returns, and fullUrl() reads QUERY_STRING (g3-27).
            $request->query->remove('token');
            $request->server->set('QUERY_STRING', Arr::query($request->query->all()));

            // Out of the address bar, the history and the Referer.
            if ($request->isMethod('GET')) {
                return redirect()->to($request->url());
            }
        }

        abort_unless(self::authorised($request), 404);

        return $next($request);
    }

    /**
     * The session holds the current token's hash, and once begin() pinned a host the request comes on it or on its
     * admin host (RL-5); the page re-checks this in mount() and before every action. Before begin() the page opens on
     * any host, so it can name the address to use, and begin() refuses to pin a host the wizard does not accept.
     */
    public static function authorised(Request $request): bool
    {
        $stored = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;
        $hash = app(SetupToken::class)->hash();

        if (! is_string($stored) || $hash === null || ! hash_equals($hash, $stored)
            || $request->attributes->get(SetupMode::ATTRIBUTE) !== true || ! DirectRequest::isDirect($request)) {
            return false;
        }

        $pinned = SetupHostGuard::pinnedWizardHost(app(SetupContext::class)->basePath);

        return $pinned === null || SetupHostGuard::allows($request, $pinned);
    }
}
```

`app/Setup/SetupMode.php`:
- add `use App\Setup\Http\SetupHostGuard;` and `use App\Setup\Support\Toolchain;`
- replace `homeRedirect()`, and add `homeHostProblem()` right after it:

```php
    /**
     * Where `/` sends a direct visitor in setup mode while no super admin exists (§5.3.4), else null. A proxied or
     * tunnelled request never gets the token (DEC-24): it would leave the machine in the tunnel's logs. Nor does a
     * host the wizard cannot pin (RL-5): a page on such a name could read the token from the redirect.
     */
    public function homeRedirect(Request $request): ?string
    {
        if ($request->attributes->get(self::ATTRIBUTE) !== true || ! DirectRequest::isDirect($request)) {
            return null;
        }

        $token = $this->token()->value();

        if ($token === null || $this->superAdminExists()) {
            return null;
        }

        return SetupHostGuard::allows($request, SetupHostGuard::pinnedWizardHost($this->basePath)) ? '/welcome-setup?token='.$token : null;
    }

    /**
     * Why `/` hands a direct visitor no token before begin() (§5.3.4, RL-5): the host is one the wizard cannot pin.
     * `suggestion` is the address to open instead (g3-34: localhost on the request's port without Herd; {folder}.{tld}
     * under Herd), or null when no address would do (g3-10: the folder; g3-28: `herd tld`). Null when `/` has nothing
     * to explain: not setup mode, not direct, no token, a super admin exists, a host is pinned, or the host is fine.
     *
     * @return array{suggestion: ?string, problem: string}|null
     */
    public function homeHostProblem(Request $request): ?array
    {
        if ($request->attributes->get(self::ATTRIBUTE) !== true || ! DirectRequest::isDirect($request)
            || $this->token()->value() === null || $this->superAdminExists()
            || SetupHostGuard::pinnedWizardHost($this->basePath) !== null) {
            return null;
        }

        $resolved = SetupHostGuard::resolve($request->getHttpHost(), app(Toolchain::class), app(SetupContext::class)->folderName());

        return $resolved['host'] === null ? ['suggestion' => $resolved['suggestion'], 'problem' => (string) $resolved['problem']] : null;
    }
```

`routes/web.php`:
- add `use Illuminate\Http\Response;`
- replace the comment above `/` and its closure's opening, up to the `$user = auth()->user();` line:

```php
// §6.2: guests sign in; super admins on a central host start at the organisations index (TEN-1);
// everyone else (and super admins on a tenant host) lands on the dashboard. In setup mode a direct visitor goes
// to the wizard, with the token, until a super admin exists (§5.3.4); on a host the wizard cannot pin, `/` names
// the address to use instead, never with the token (RL-5).
Route::get('/', function (): RedirectResponse|Response {
    $setup = app(SetupMode::class);

    // §5.3.4: before setup, `/` leads to the wizard.
    if (($wizard = $setup->homeRedirect(request())) !== null) {
        return redirect($wizard);
    }

    // g3-34: a token-less redirect to the address the wizard pins; g3-10, g3-28: no address would do, so say why.
    if (($refused = $setup->homeHostProblem(request())) !== null) {
        return $refused['suggestion'] !== null
            ? redirect()->away(rtrim($refused['suggestion'], '/').'/')
            : response()->view('setup.host-problem', ['problem' => $refused['problem']], 409);
    }

```

  The rest of the closure stays as it is.

`resources/views/setup/host-problem.blade.php` (new). It never carries the token:

```blade
{{-- §5.3.4, RL-5: `/` on a host the wizard cannot pin and no address to suggest (g3-10, g3-28). Never the token. --}}
<x-layouts::setup>
    <div data-test="setup-host-problem" class="space-y-4">
        <flux:heading size="xl" level="1">{{ __('The setup cannot start on this address.') }}</flux:heading>

        <flux:callout variant="warning" icon="exclamation-triangle" :heading="$problem" />
    </div>
</x-layouts::setup>
```

`app/Setup/Wizard/HerdStage.php`:
- add `use App\Setup\Http\SetupHostGuard;`
- replace `state()`:

```php
    /**
     * The Herd state for this request's secret. Anything else is a 404 (RL-4, RL-5, RL-6): not local, not direct, a
     * host other than the pinned one or its admin host, or a wrong or stale secret.
     */
    public function state(Request $request): HerdState
    {
        abort_unless(DirectRequest::isDirect($request), 404);

        try {
            $state = HerdState::load(app(SetupContext::class)->basePath);
        } catch (JsonException|InvalidArgumentException) {
            abort(404);
        }

        $secret = $request->query('secret');
        abort_unless($state instanceof HerdState && SetupHostGuard::allows($request, $state->host) && is_string($secret)
            && $state->isFresh() && $state->matches($secret), 404);

        return $state;
    }
```

`routes/setup.php`: add `use App\Http\Middleware\SetReferrerPolicy;`, then put the policy outermost on all four routes (g3-13), so a redirect and a 404 carry it too:

```php
// §5.3.4: the browser wizard, on every host. EnsureSetupAccess answers 404 unless the app is in setup mode (RL-1),
// the request is direct and it carries the one-time token (RL-2). The token's URL sends no referrer (g3-13).
Route::livewire('welcome-setup', 'pages::setup.wizard')
    ->middleware([SetReferrerPolicy::class, EnsureSetupAccess::class])
    ->name('setup.wizard');

// RL-6: the Herd stage, after the marker; each endpoint needs the secret only the wizard's browser holds, so none
// sends a referrer (g3-13).
Route::get('welcome-setup/herd-status', SetupHerdStatusController::class)->middleware([SetReferrerPolicy::class, 'throttle:120,1'])->name('setup.herd-status');
Route::get('welcome-setup/finish', SetupFinishController::class)->middleware([SetReferrerPolicy::class, 'throttle:60,1'])->name('setup.finish');
Route::get('welcome-setup/login', SetupLoginController::class)->middleware([SetReferrerPolicy::class, 'throttle:10,1'])->name('setup.login');
```

The Livewire persistent middleware list (`AppServiceProvider::configureSetupWizard()`) keeps only `EnsureSetupAccess`.

- [ ] **Step 5: The wizard runner, the page, the Environment step and the CLI**

`app/Setup/Wizard/WizardRunner.php`:
- Imports:
  - add `use App\Setup\Http\SetupHostGuard;`
  - remove `use App\Setup\SetupHost;`, `use App\Setup\Support\HerdTld;` and `use Illuminate\Support\Str;` (they are used only in `host()`)
- Replace `defaults()`:

```php
    /**
     * @param  string  $requestHost  the request's host with its port, when it has one (getHttpHost())
     * @return array{answers: Answers, host: ?string, problem: ?string} the form's defaults, the pinned host (null: refused) and why it is refused
     */
    public function defaults(string $requestHost): array
    {
        ['host' => $host, 'problem' => $problem] = $this->host($requestHost);
        $context = app(SetupContext::class);

        return [
            'answers' => Answers::fromArray([], $this->detector->detect($host ?? 'localhost'), $context->folderName(), $this->installed($context)),
            'host' => $host,
            'problem' => $problem,
        ];
    }
```

- In `begin()`, change the `@param` block to name `$requestHost` the same way:

```php
    /**
     * @param  array<string, mixed>  $input
     * @param  string  $requestHost  the request's host with its port, when it has one (getHttpHost())
     *
     * @throws ValidationException
     */
```

- In `begin()`, replace

```php
            [$host, $suggestion] = $this->host($requestHost);

            if ($host === null) {
                throw ValidationException::withMessages(['host' => __('Open the setup on :url.', ['url' => $suggestion])]);
            }
```

with:

```php
            ['host' => $host, 'problem' => $problem] = $this->host($requestHost);

            if ($host === null) {
                throw ValidationException::withMessages(['host' => (string) $problem]);
            }
```

- Replace `host()`:

```php
    /**
     * The host to pin for $requestHost (host[:port]) and, when it is refused, why and where to go instead
     * (SetupHostGuard::resolve()); the page and begin() show only the problem.
     *
     * @return array{host: ?string, problem: ?string, suggestion: ?string}
     */
    private function host(string $requestHost): array
    {
        return SetupHostGuard::resolve($requestHost, $this->toolchain, app(SetupContext::class)->folderName());
    }
```

`resources/views/pages/setup/wizard/wizard.php`:
- Drop `#[Title('Setup')]` and `use Livewire\Attributes\Title;`; the setup layout already prints the title (g3-33):

```php
new #[Layout('layouts::setup')] class extends Component
```

- In `mount()`, replace

```php
        $defaults = $runner->defaults(request()->getHost());

        if ($defaults['host'] === null) {
            $this->hostProblem = __('Open the setup on :url.', ['url' => $defaults['suggestion']]);
        }
```

with:

```php
        // The port too: without Herd, the suggestion is localhost on the port this request came in on (g3-34).
        $defaults = $runner->defaults(request()->getHttpHost());
        $this->hostProblem = $defaults['problem'];
```

  The following `$this->fill($this->formOf($defaults['answers']));` stays as it is.

- In `start()`, pass `request()->getHttpHost()` instead of `request()->getHost()` as `begin()`'s second argument.

`app/Setup/Steps/EnvironmentStep.php`: in `variables()`, replace

```php
        // A site the Herd step already secured keeps https on a re-run (the CLI always detects http).
        $current = (string) $env->get('APP_URL');
        $secured = parse_url($current, PHP_URL_SCHEME) === 'https' && parse_url($current, PHP_URL_HOST) === $host;

        $variables = [
            'APP_NAME' => $answers->appName,
            'APP_URL' => ($secured ? 'https' : $detection->scheme).'://'.$host,
```

with:

```php
        // A site the Herd step already secured keeps https on a re-run (the CLI always detects http).
        $current = (string) $env->get('APP_URL');
        $currentHost = parse_url($current, PHP_URL_HOST);
        $secured = parse_url($current, PHP_URL_SCHEME) === 'https' && $currentHost === $host;
        // Without Herd the app may run on a port (php artisan serve): an APP_URL on this host keeps it (g1-17).
        $port = ! $detection->herd && $currentHost === $host ? parse_url($current, PHP_URL_PORT) : null;

        $variables = [
            'APP_NAME' => $answers->appName,
            'APP_URL' => ($secured ? 'https' : $detection->scheme).'://'.$host.(is_int($port) ? ':'.$port : ''),
```

`isComplete()` already compares the host only, so a kept port never makes the step look incomplete.

`app/Console/Commands/InstallCommand.php`:
- add `use App\Setup\SetupHost;`
- right after `$detection = $detector->detect();`, add:

```php
        // g3-10: under Herd the folder is the site name and the address; one that is no DNS label gives an address
        // nobody can open, so nothing is written.
        $folderProblem = $detection->herd ? SetupHost::folderProblem($folder) : null;

        if ($folderProblem !== null) {
            $this->components->error(OutputFormatter::escape($folderProblem));

            return self::FAILURE;
        }
```

`scripts/e2e-local.sh`, so that the entries without Herd still meet RL-5 and g3-34:
1. Replace the comment at line 16:

```bash
# Every entry but herd serves the app with php artisan serve on a free port of 127.0.0.1 for the wizard check, and
# reaches it under the host the wizard pins (RL-5): {name}.{tld} where Herd is installed, else localhost. Each entry
# ends after phase 1. Only the committed state of the current branch is installed (as dev-<branch>). Composer runs
```

The next two comment lines stay.
2. Right above `home_redirects() {`, add the curl wrapper:

```bash
# RL-5: curl reaches artisan serve under the host the wizard pins, whatever that name resolves to (empty: no override).
RESOLVE=""
web() { if [ -n "$RESOLVE" ]; then curl --resolve "$RESOLVE" "$@"; else curl "$@"; fi; }
```

3. In `home_redirects`, `token_accepted`, `wizard_renders` and `serving`, replace `curl` with `web`. The arguments stay as they are.
4. Replace the entries-without-Herd branch:

```bash
else
    echo
    echo "Phase 1 (§7.2): $ENTRY"
    PORT="$(free_port)"
    # RL-5, g3-34: the wizard gives its token only on a host it can pin. Where Herd is installed that is {name}.{tld},
    # also for a site Herd does not serve; elsewhere localhost. `herd tld` only reads Herd's configuration.
    HERD_BIN="$HOME/Library/Application Support/Herd/bin/herd"
    if [ -x "$HERD_BIN" ]; then
        SETUP_HOST="$(printf '%s' "$NAME" | tr '[:upper:]' '[:lower:]').$("$HERD_BIN" tld)"
    else
        SETUP_HOST=localhost
    fi
    RESOLVE="$SETUP_HOST:$PORT:127.0.0.1"
    URL="http://$SETUP_HOST:$PORT"
    (cd "$TARGET" && exec php artisan serve --host=127.0.0.1 --port="$PORT" > /dev/null 2>&1) &
    SERVE_PID=$!
    check "php artisan serve answers on $URL" wait_until_serving "$URL"
fi
```

Run `bash -n scripts/e2e-local.sh` to check the syntax. `CiWorkflowTest` still finds `php artisan serve`, `/welcome-setup` and `SetupToken::PATH` in the script.

The `curl --resolve` wrapper stays necessary with `homeHostProblem()`: on `127.0.0.1`, `/` now redirects to the suggested address without the token, but `home_redirects` checks that the first `Location` carries the token, and the suggested `localhost:$PORT` or `{name}.{tld}` must still reach this `artisan serve`.

- [ ] **Step 6: Copy and the setup stylesheet**

Lang keys, in both files, at their sorted positions:

| Key | nl | Position |
|---|---|---|
| `Herd did not answer. Reload the page in a moment.` | `Herd gaf geen antwoord. Laad de pagina zo opnieuw.` | right after `Herd could not link the site.` |
| `The folder name :folder is not a valid web address under Herd. Rename the folder to :name, then start the setup again.` | `De mapnaam :folder is onder Herd geen geldig webadres. Hernoem de map naar :name en start de installatie daarna opnieuw.` | right before `The folder name gives no usable PostgreSQL database name (":name"); rename the folder or choose SQLite.` |
| `The setup cannot start on this address.` | `De installatie kan op dit adres niet starten.` | right after `The session driver is :driver` |

In `en.json` each value equals its key. `Open the setup on :url.` already exists.

`resources/views/pages/setup/wizard/wizard.php` sits under `resources/views/pages/setup` and the new `host-problem.blade.php` under `resources/views/setup`, so run `npm run build:setup` and commit `resources/setup/setup.css` with this task when it changes. The new view uses only classes the setup pages already have (`space-y-4`, as `manual.blade.php`), so no diff is expected; the gate's `git diff --exit-code` decides.

- [ ] **Step 7: Run the tests to verify they pass, then verify by mutation**

Run: `php artisan test --compact tests/Feature/Setup tests/Feature/Console tests/Feature/Tenancy/RouteHostMatrixTest.php tests/Feature/Auth tests/Feature/LangFilesTest.php tests/Feature/LangCoverageTest.php tests/Feature/Tooling`

Expected: PASS. `tests/Feature/Auth` covers the quick login, which also uses `DirectRequest`. Test requests come from `127.0.0.1` by default, as do the browser suite's in-process requests.

Verify by mutation:
1. Wrap the `setup.wizard` route in `Route::tenant(function (): void { … })`. Expect all three rows of 'opens on the apex, the admin host and a tenant host until the marker exists' to fail. (`Route::central()` cannot be observed in setup mode: no tenant is ever bound, so every row still passes. Record that in the report instead of running it as a mutation.)
2. Remove `:disabled="$hostProblem !== null"` from `wizard.blade.php`. Expect 'names the address to use and disables the start' to fail.
3. Remove `|| str_ends_with($host, '.test')` from `SetupHost::forWizard()`. Expect the `no herd .test` row to fail.
4. Remove `$request->session()->regenerate();`. Expect 'issues a new session id when it accepts the token' to fail.
5. Remove the two `QUERY_STRING`/query lines. Expect 'never stores the token in the session' to fail.
6. Remove the `REMOTE_ADDR` check. Expect 'answers 404 to a request from another machine' (both files) to fail.
7. Remove the `homeHostProblem()` branch from the `/` closure. Expect the three new `/` cases in `SetupModeTest` (the `127.0.0.1:8000` redirect, the folder problem, `herd tld`) to fail: each lands on the login page.

The commit contains the fixed code only. Name each mutation and its result in the report.

- [ ] **Step 8: The gate, then commit**

Run the Global Constraints gate:
- `composer check`
- `composer test:pgsql`
- the modules-off run
- `npm run build && composer test:browser`
- `npm run build:setup && git diff --exit-code resources/setup/setup.css` (if it differs, stage the rebuilt file)
- `php artisan config:clear`

```bash
git add app/Setup/Http app/Setup/SetupHost.php app/Setup/SetupMode.php app/Setup/Wizard/HerdStage.php app/Setup/Wizard/WizardRunner.php \
    app/Setup/Steps/EnvironmentStep.php app/Console/Commands/InstallCommand.php resources/views/pages/setup/wizard/wizard.php \
    resources/views/setup/host-problem.blade.php routes/web.php routes/setup.php scripts/e2e-local.sh lang/nl.json lang/en.json \
    tests/Feature/Setup tests/Feature/Console/InstallCommandTest.php
git add resources/setup/setup.css   # only when build:setup changed it
git commit -m "fix: pin the setup gate to loopback, direct requests and the pinned host

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 3: Quick login and the Herd stage (g3-14, g3-15, g4-03, g3-02, g3-22, g3-23, g3-24, g4-12, g2-05; CD-14, RL-6, RL-7)

**Files:**
- Create:
  - `app/Setup/Support/ShellArgument.php`
  - `tests/Feature/Setup/Support/ShellArgumentTest.php`
- Modify:
  - `app/Http/Controllers/Auth/QuickLoginController.php` (g3-14)
  - `app/Setup/QuickLogin.php` (g3-15)
  - `app/Setup/Wizard/HerdStage.php` (g4-03, g3-02, `manualCommand()`)
  - `app/Setup/Wizard/HerdState.php` (g3-24)
  - `app/Setup/Wizard/WizardRunner.php` (g3-22, g3-02, RL-7)
  - `app/Setup/Steps/HerdSiteStep.php` (RL-7, RL-6)
  - `app/Setup/Doctor/Checks/HerdSiteCheck.php`, `app/Setup/Doctor/Checks/HerdSecuredCheck.php`, `app/Setup/SetupRunner.php` (RL-6)
  - `app/Console/Commands/RunStepCommand.php` (RL-7, g3-02)
  - `app/Http/Controllers/Setup/SetupFinishController.php` (RL-7)
  - `resources/views/setup/manual.blade.php` (RL-7), then `resources/setup/setup.css` (`npm run build:setup`)
  - `scripts/e2e-local.sh` (RL-6)
  - `lang/nl.json`, `lang/en.json`
- Test:
  - `tests/Feature/Auth/QuickLoginTest.php`
  - `tests/Feature/Setup/Wizard/HerdStageTest.php`, `tests/Feature/Setup/Wizard/WizardRunnerTest.php`
  - `tests/Feature/Console/RunStepCommandTest.php`
  - `tests/Feature/Setup/Steps/HerdSiteStepTest.php`, `tests/Feature/Setup/Doctor/ChecksTest.php`, `tests/Feature/Setup/SetupRunnerTest.php`
  - `tests/Feature/Tooling/CiWorkflowTest.php`

**Interfaces:**
- Consumes:
  - from Task 1: `withoutPostgresProbe()` (lazy bind) and `Detector::__construct(Toolchain, ?Closure $postgresProbe = null)`
  - from Task 2:
    - `DirectRequest::isDirect(Request): bool` (RL-4: local, loopback `REMOTE_ADDR`, no forwarding headers)
    - `HerdStage::state()` with `SetupHostGuard::allows($request, $state->host)` (RL-5)
    - Task 2's rewrite of HerdStageTest 'settles a site secured by hand…'
  - existing: `SetupLock::run()/forget()`, `HerdState::begin()/load()/forget()`, `StateFile::write()`, `StepResult`, `Hosts::url()`, `Hosts::apex()`, `IssueHandoff`, `Tests\Fixtures\InlineStepLauncher::$refuses`, `Tests\Fixtures\CallbackSetupStep`
- Produces:
  - `App\Setup\Support\ShellArgument::quote(string $value): string`: `$value` when it matches `/^[A-Za-z0-9._\/:=@%+-]+$/`, else `escapeshellarg($value)` (RL-6)
  - `HerdSiteStep::manualCommand(SetupContext $context): ?string`: null without Herd; `herd link <name>` while not served; `herd secure <name>` when HTTPS was chosen; else null. The name is quoted (RL-7, RL-6).
  - `HerdStage::manualCommand(HerdState $state): ?string` (used by `SetupFinishController`)
  - `HerdStage::loginUrl()` returns the apex `/login` for a super admin with `two_factor_confirmed_at` (CD-14)
  - `HerdState::begin()` removes `HerdState::EXIT` first (g3-24)
  - `WizardRunner::finish()` writes `HerdState::EXIT` (`127`) when the Herd step cannot start and RL-7 names a command (g3-22)
  - the `setup.manual` view takes `command: ?string` and `https: bool`
  - new lang key: "Herd did not confirm the site in time."

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Setup/Support/ShellArgumentTest.php` (new):

```php
<?php

declare(strict_types=1);

/** covers §5.3.1, §5.4 */

use App\Setup\Support\ShellArgument;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

it('leaves a value no shell treats specially as it is, so ordinary names stay readable (RL-6)', function (string $value): void {
    expect(ShellArgument::quote($value))->toBe($value);
})->with(['my-app', 'MyApp_2', 'v1.2.3', '--site=my-app', '/Users/me/Herd/my-app', 'admin@example.com', 'a+b%c:d']);

it('single-quotes a value with a space or a shell character (RL-6, g2-05)', function (string $value, string $quoted): void {
    expect(ShellArgument::quote($value))->toBe($quoted);
})->with([
    'a space' => ['my app', "'my app'"],
    'a command' => ['app;rm -rf ~', "'app;rm -rf ~'"],
    'a variable' => ['$HOME', "'\$HOME'"],
    'a single quote' => ["it's", "'it'\\''s'"],
    'nothing' => ['', "''"],
]);
```

`tests/Feature/Auth/QuickLoginTest.php`: add `use Tests\TestCase;` to the imports, then append:

```php
it('answers 404, not 500, to an email that is not a string (g3-14)', function (string $environment) use ($demoInstall): void {
    $demoInstall();
    app()->detectEnvironment(fn (): string => $environment);

    $this->post(route('quick-login'), ['email' => ['admin@demo1.'.Hosts::apex()]])->assertNotFound();
})->with(['local', 'production']);

it('offers no quick login to a request that is not direct, and answers 404 to it (DEC-24, g3-15)', function (Closure $notDirect) use ($demoInstall): void {
    $demoInstall();
    $notDirect($this);

    $this->get(route('login'))->assertOk()->assertDontSee('data-test="quick-login"', false);
    $this->post(route('quick-login'), ['email' => 'admin@demo1.'.Hosts::apex()])->assertNotFound();
})->with([
    'through herd share or a proxy' => [fn (TestCase $test): TestCase => $test->withHeader('X-Forwarded-For', '203.0.113.7')],
    'from another machine' => [fn (TestCase $test): TestCase => $test->withServerVariables(['REMOTE_ADDR' => '192.168.1.20'])],
]);
```

`tests/Feature/Setup/Steps/HerdSiteStepTest.php`: append (the file already imports `Answers`, `SetupContext`, `ProcessTimedOutException`, `SymfonyTimeout` and `SymfonyProcess`, and its `beforeEach` puts `herd` and `php` stubs in a sandbox home):

```php
it('names the one Herd command left to run: herd link while unserved, herd secure while HTTPS is missing (RL-7)', function (bool $served, bool $https, ?string $command): void {
    $context = setupSandbox($this->detection);
    $context->answers = Answers::fromArray(['modules' => ['impersonation'], 'https' => $https], $this->detection, $context->folderName());
    Process::fake([
        "*herd' 'links'*" => Process::result(''),
        "*herd' 'paths'*" => Process::result(json_encode([$served ? dirname($context->basePath) : '/somewhere/else'], JSON_THROW_ON_ERROR)),
    ]);

    expect(new HerdSiteStep()->manualCommand($context))->toBe($command === null ? null : sprintf($command, $context->folderName()));
})->with([
    'not served, HTTPS chosen' => [false, true, 'herd link %s'],
    'not served, no HTTPS' => [false, false, 'herd link %s'],
    'served, HTTPS chosen' => [true, true, 'herd secure %s'],
    'served, no HTTPS' => [true, false, null],
]);

it('names no Herd command without Herd (RL-7)', function (): void {
    expect(new HerdSiteStep()->manualCommand(setupSandbox()))->toBeNull();
});

it('names the command HTTPS asks for when Herd\'s listings fail (RL-7)', function (bool $https, string $command): void {
    $context = setupSandbox($this->detection);
    $context->answers = Answers::fromArray(['modules' => ['impersonation'], 'https' => $https], $this->detection, $context->folderName());
    Process::fake([
        "*herd' 'links'*" => fn () => throw new ProcessTimedOutException(new SymfonyTimeout(new SymfonyProcess(['herd']), SymfonyTimeout::TYPE_GENERAL), Process::result('')),
    ]);

    expect(new HerdSiteStep()->manualCommand($context))->toBe(sprintf($command, $context->folderName()));
})->with([
    'HTTPS chosen' => [true, 'herd secure %s'],
    'no HTTPS' => [false, 'herd link %s'],
]);

it('quotes a folder name with a space in the commands it prints, never in the argv it runs (RL-6, g2-05)', function (Closure $listings, string $command): void {
    $context = setupSandbox($this->detection, 'my app ');
    $context->answers = Answers::fromArray(['https' => true], $this->detection, $context->folderName());
    $name = $context->folderName();
    Process::fake($listings($context));

    $result = new HerdSiteStep()->run($context);

    expect($result->status)->toBe('manual')
        ->and($result->command)->toBe(str_replace('{name}', $name, $command));
    Process::assertRan(fn ($process): bool => in_array($name, (array) $process->command, true) || in_array('--site='.$name, (array) $process->command, true));
})->with([
    'herd link' => [fn (SetupContext $context): array => [
        "*herd' 'paths'*" => Process::result(json_encode(['/somewhere/else'], JSON_THROW_ON_ERROR)),
        "*herd' 'links'*" => Process::result(''),
        "*herd' 'link'*" => Process::result('linked'),
    ], "herd link '{name}'"],
    'herd isolate' => [fn (SetupContext $context): array => [
        "*herd' 'paths'*" => Process::result(json_encode([dirname($context->basePath)], JSON_THROW_ON_ERROR)),
        "*herd' 'links'*" => Process::result(''),
        "*herd' 'isolated'*" => Process::result(''),
        "*herd' 'isolate'*" => Process::result(''),
        "*php' '-r'*" => Process::result('8.3.12'),
    ], "herd isolate 8.4 '--site={name}'"],
    'herd secure' => [fn (SetupContext $context): array => [
        "*herd' 'paths'*" => Process::result(json_encode([dirname($context->basePath)], JSON_THROW_ON_ERROR)),
        "*herd' 'links'*" => Process::result(''),
        "*herd' 'isolated'*" => Process::result(''),
        "*php' '-r'*" => Process::result('8.4.13'),
        "*herd' 'secured'*" => Process::result(''),
        "*herd' 'secure'*" => Process::result(''),
    ], "herd secure '{name}'"],
]);
```

> `"*herd' 'link'*"` never matches `'herd' 'links'`: the pattern needs the quote right after `link`.

`tests/Feature/Setup/Doctor/ChecksTest.php`: insert before 'prints the /etc/hosts lines when subdomains do not resolve without Herd (§5.4)':

```php
it('quotes a folder name with a space in the Herd fix commands (RL-6, g2-05)', function () use ($stub, $withMarker): void {
    $stub($this->home.'/Library/Application Support/Herd/bin/herd');
    $spaced = setupSandbox(prefix: 'my app ');
    $name = $spaced->folderName();
    $context = $withMarker(new DoctorContext($spaced->basePath, $this->context->toolchain, $this->herd, null), ['https' => true]);
    Process::fake([
        "*herd' 'links'*" => Process::result(''),
        "*herd' 'paths'*" => Process::result(json_encode(['/somewhere/else'], JSON_THROW_ON_ERROR)),
        "*herd' 'secured'*" => Process::result(''),
    ]);

    expect(new HerdSiteCheck()->run($context)->fix)->toBe("herd link '{$name}'")
        ->and(new HerdSecuredCheck()->run($context)->fix)->toBe("herd secure '{$name}'");
});
```

`tests/Feature/Setup/SetupRunnerTest.php`: insert before 'names a re-run that turns https back on over the marker answers (OD-2)':

```php
it('quotes a folder name with a space in the commands of the HTTPS notes (RL-6, g2-05)', function (array $modules): void {
    $context = setupSandbox(prefix: 'my app ');
    $context->answers = Answers::fromArray(['modules' => $modules, 'https' => true], Detection::none(), $context->folderName());
    $context->results['herd-site'] = StepResult::manual('herd secure x', 'not confirmed');

    new SetupRunner([])->applyHttpsConsequence($context);

    expect(implode(' ', $context->notes))->toContain("herd secure '{$context->folderName()}'");
})->with(['with passkeys' => [['passkeys', 'impersonation']], 'without passkeys' => [['impersonation']]]);
```

`tests/Feature/Tooling/CiWorkflowTest.php`: insert before 'checks in CI that the setup stylesheet is current (OD-1)' (`Str` is already imported):

```php
it('quotes the names in the cleanup commands the local e2e script prints (RL-6, g2-05)', function (): void {
    $script = (string) file_get_contents(base_path('scripts/e2e-local.sh'));
    $cleanup = Str::after($script, 'Clean up when you are done');

    expect($cleanup)->toContain("printf '  herd unsecure %q\\n' \"\$NAME\"")
        ->toContain("printf '  herd unisolate %q\\n' \"--site=\$NAME\"")
        ->toContain("printf '  herd unlink %q\\n' \"\$NAME\"")
        ->toContain("printf '  rm -rf %q\\n' \"\$TARGET\"")
        // An echo would print the name as it is, so a folder with a space or a quote gives a broken command.
        ->and(preg_match('/echo "  (herd|rm) /', $cleanup))->toBe(0);
});
```

`tests/Feature/Setup/Wizard/HerdStageTest.php`:

- Add the imports `use App\Setup\Wizard\SetupLock;`, `use App\Setup\Wizard\StepLauncher;`, `use App\Setup\Wizard\WizardRunner;`, `use App\Setup\Wizard\WizardState;` and `use Tests\Fixtures\InlineStepLauncher;`.
- Replace the `$afterStepNine` closure and add two helpers after it. `$afterStepNine` takes the detection the stage should keep. The Herd state stores it from Task 4 on (CD-15), so the Herd cases pass a Herd detection from now on:

```php
/**
 * The state finish() leaves: the marker, and a Herd state; returns the secret. The Herd state keeps the context's
 * detection (CD-15), so a test that gives the stage Herd passes a detection that says so.
 */
$afterStepNine = function (SetupContext $context, string $startWith = 'demo', string $host = 'localhost', ?Detection $detection = null, bool $https = true): string {
    $detected = $detection ?? Detection::none();
    $stage = new SetupContext($context->basePath, Answers::fromArray(
        $startWith === 'demo' ? ['modules' => ['impersonation'], 'https' => $https] : ['start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com', 'super_admin_password' => 'correct-horse-battery', 'modules' => ['impersonation'], 'https' => $https],
        $detected,
        $context->folderName(),
    ), $detected);
    $context->answers = $stage->answers;
    Marker::write($stage, Summary::build($stage));
    @unlink(new SetupToken($context->basePath)->path());

    return HerdState::begin($context->basePath, $stage, $host)[1];
};

/** What the Detector finds next to a Herd stub: Herd, on the pinned host. */
$herdDetection = fn (string $host = 'localhost'): Detection => new Detection(herd: true, herdPro: false, herdMail: false, herdSmtpPort: null, postgres: false, fluxPro: false, claude: false, host: $host, scheme: 'http');

/**
 * Herd's read-only listings: the project parked (served) or not, and `herd secured` printing $secured.
 *
 * @return array<string, mixed>
 */
$herdListings = fn (SetupContext $context, bool $served, string $secured = ''): array => [
    "*herd' 'services:list'*" => Process::result(''),
    "*herd' 'links'*" => Process::result(''),
    "*herd' 'paths'*" => Process::result(json_encode([$served ? dirname($context->basePath) : '/somewhere/else'], JSON_THROW_ON_ERROR)),
    "*herd' 'secured'*" => Process::result($secured),
];
```

- In 'settles a site secured by hand once herd secured lists it, when the manual page is reloaded' (as Task 2 rewrote it), add `$herdDetection` to the closure's `use (...)` list, and change its `$afterStepNine` call to:

```php
    $secret = $afterStepNine($context, 'demo', $host, $herdDetection($host));
```

- Replace 'shows a finishing page while the Herd step runs, and the manual command after a timeout (RL-6)'. Without Herd, RL-7 names no command, so the case moves under Herd. Then add the new cases after it:

```php
it('shows a finishing page while the Herd step runs, and the manual command after a timeout (RL-6, RL-7)', function () use ($afterStepNine, $withHerd, $herdDetection, $herdListings): void {
    // Arrange: Herd serves the site but has not secured it, so the command left is `herd secure`.
    $context = useSetupWizard();
    $secret = $afterStepNine($context, detection: $herdDetection());
    $withHerd($context);
    Process::fake($herdListings($context, served: true));

    $this->get('/welcome-setup/finish?secret='.$secret)->assertOk()->assertHeader('Refresh', '2');
    $this->get('/welcome-setup/finish?secret='.$secret.'&timeout=1')->assertOk()
        ->assertSee('herd secure '.$context->folderName())
        ->assertSee(__('Herd did not confirm HTTPS in time.'));
});

it('offers herd link, not herd secure, while Herd does not serve the site (RL-7, g3-23)', function (bool $https, string $heading) use ($afterStepNine, $withHerd, $herdDetection, $herdListings): void {
    $context = useSetupWizard();
    $secret = $afterStepNine($context, detection: $herdDetection(), https: $https);
    $withHerd($context);
    Process::fake($herdListings($context, served: false));

    $this->get('/welcome-setup/finish?secret='.$secret.'&timeout=1')->assertOk()
        ->assertSee('data-test="setup-manual-herd"', false)
        ->assertSee('herd link '.$context->folderName())
        ->assertDontSee('herd secure')
        ->assertSee(__($heading));
})->with([
    'HTTPS chosen' => [true, 'Herd did not confirm HTTPS in time.'],
    'no HTTPS' => [false, 'Herd did not confirm the site in time.'],
]);

it('shows no command on the manual page without Herd (RL-7)', function () use ($afterStepNine): void {
    $context = useSetupWizard();
    $secret = $afterStepNine($context);

    $this->get('/welcome-setup/finish?secret='.$secret.'&timeout=1')->assertOk()
        ->assertSee('data-test="setup-manual-herd"', false)
        ->assertDontSee('herd secure')
        ->assertDontSee('<code', false);
});

it('is not overdue at the start when an earlier stage left its exit file (g3-24)', function () use ($afterStepNine): void {
    $context = useSetupWizard();
    File::put($context->path(HerdState::EXIT), "0\n");

    $secret = $afterStepNine($context);

    $this->getJson('/welcome-setup/herd-status?secret='.$secret)->assertExactJson(['state' => 'running']);
});

it('offers the manual page when the Herd step cannot start, instead of settling at once (g3-22)', function () use ($withHerd, $herdDetection, $herdListings): void {
    // Arrange: steps 1–8 are done under Herd, which serves the site without securing it.
    $context = useSetupWizard();
    $withHerd($context);
    Process::fake($herdListings($context, served: true));
    $answers = Answers::fromArray(['modules' => ['impersonation'], 'https' => true], $herdDetection(), $context->folderName());
    new WizardState('localhost', $answers, array_fill_keys(WizardRunner::PAGE_STEPS, StepResult::done('ok')))->save($context->basePath);
    /** @var InlineStepLauncher $launcher */
    $launcher = app(StepLauncher::class);
    $launcher->refuses = true;

    $secret = app(WizardRunner::class)->finish();

    expect(HerdState::load($context->basePath)?->result)->toBeNull()
        ->and(Marker::read($context)['answers']['https'] ?? null)->toBeTrue();
    $this->getJson('/welcome-setup/herd-status?secret='.$secret)->assertExactJson(['state' => 'timeout']);
    $this->get('/welcome-setup/finish?secret='.$secret)->assertOk()
        ->assertSee('data-test="setup-manual-herd"', false)
        ->assertSee('herd secure '.$context->folderName());
});
```

> Every new case pins the stage to `localhost` and requests it on `localhost`, so Task 2's host guard (RL-5) lets it through. The Detection's host only gives `HerdSiteStep::domain()` its tld, which is `test` for a host without a dot.

- Append:

```php
it('sends a super admin with a confirmed second factor to the login page, not through a handoff (CD-14, g4-03)', function () use ($afterStepNine): void {
    $context = useSetupWizard();
    $secret = $afterStepNine($context, 'super_admin');
    User::factory()->superAdmin()->withTwoFactor()->create(['email' => 'owner@example.com']);
    $state = HerdState::load($context->basePath) ?? throw new LogicException('No Herd state.');
    $state->result = StepResult::skipped('Herd is not installed');
    $state->save($context->basePath);

    $this->get('/welcome-setup/login?secret='.$secret)->assertRedirect(Hosts::url(Hosts::apex(), '/login'));

    expect(HandoffToken::query()->count())->toBe(0)
        ->and(is_file($context->path(HerdState::PATH)))->toBeFalse();
});

it('leaves no lock file when the Herd stage acts after the login used the secret (g3-02)', function (Closure $late) use ($afterStepNine): void {
    // Arrange: the login deleted the Herd state and the lock; a request that loaded the state before arrives late.
    $context = useSetupWizard();
    $afterStepNine($context);
    $state = HerdState::load($context->basePath) ?? throw new LogicException('No Herd state.');
    app(HerdStage::class)->loginUrl($state);

    $late($state);

    expect($context->path(SetupLock::PATH))->not->toBeFile();
})->with([
    'the server-side confirmation' => [fn (HerdState $state): mixed => app(WizardRunner::class)->confirmHerd($state)],
    'continue without HTTPS' => [function (HerdState $state): void {
        app(HerdStage::class)->continueWithoutHttps($state);
    }],
]);

/** What happens to the Herd state between a request's load and its lock (g4-12). */
$movedOn = [
    'the Herd step answered meanwhile' => [function (SetupContext $context): void {
        $current = HerdState::load($context->basePath) ?? throw new LogicException('No Herd state.');
        $current->result = StepResult::skipped('Herd is not installed');
        $current->save($context->basePath);
    }],
    'another finish replaced the state' => [function (SetupContext $context) use ($afterStepNine, $herdDetection): void {
        $afterStepNine($context, detection: $herdDetection());
    }],
];

it('leaves a Herd state that changed after this request loaded it on continue without HTTPS (RL-6, g4-12)', function (Closure $moveOn) use ($afterStepNine, $herdDetection): void {
    $context = useSetupWizard();
    $afterStepNine($context, detection: $herdDetection());
    $stale = HerdState::load($context->basePath) ?? throw new LogicException('No Herd state.');
    $moveOn($context);
    $before = (string) file_get_contents($context->path(HerdState::PATH));

    app(HerdStage::class)->continueWithoutHttps($stale);

    expect((string) file_get_contents($context->path(HerdState::PATH)))->toBe($before)
        ->and(Marker::read($context)['answers']['https'] ?? null)->toBeTrue();
})->with($movedOn);

it('leaves a Herd state that changed after this request loaded it on the server-side confirmation (RL-6, g4-12)', function (Closure $moveOn) use ($afterStepNine, $withHerd, $herdDetection, $herdListings): void {
    // Arrange: Herd now serves and secures the site, so a confirmation without its guards would settle the stage.
    $context = useSetupWizard();
    $afterStepNine($context, detection: $herdDetection());
    $withHerd($context);
    Process::fake($herdListings($context, served: true, secured: "| Site | Expires |\n| {$context->folderName()}.test | 2027-01-01 00:00:00 GMT |\n"));
    $stale = HerdState::load($context->basePath) ?? throw new LogicException('No Herd state.');
    $moveOn($context);
    $before = (string) file_get_contents($context->path(HerdState::PATH));

    app(WizardRunner::class)->confirmHerd($stale);

    expect((string) file_get_contents($context->path(HerdState::PATH)))->toBe($before)
        ->and(new EnvFile($context->path('.env'))->get('APP_URL'))->not->toStartWith('https://');
})->with($movedOn);
```

`tests/Feature/Setup/Wizard/WizardRunnerTest.php`:
- Add `use App\Setup\Answers;` and `use App\Setup\Detection;`.
- Replace 'applies the HTTPS consequence when the Herd step cannot start (OD-2, RL-6)'. The test is rewritten, not deleted: its no-Herd case now settles at once (note 4), and the Herd case moved to HerdStageTest above. The rewrite also drops the test's unbounded loop and its catch-all fake:

```php
it('settles at once without HTTPS when the Herd step cannot start without Herd (OD-2, RL-7, g3-22)', function (): void {
    // Arrange: steps 1–8 are done and there is no Herd, so no Herd command is left for the user to run.
    $context = useSetupWizard();
    $answers = Answers::fromArray(['modules' => ['passkeys', 'impersonation'], 'https' => true], Detection::none(), $context->folderName());
    new WizardState('localhost', $answers, array_fill_keys(WizardRunner::PAGE_STEPS, StepResult::done('ok')))->save($context->basePath);
    /** @var InlineStepLauncher $launcher */
    $launcher = app(StepLauncher::class);
    $launcher->refuses = true;

    app(WizardRunner::class)->finish();

    expect(Marker::read($context)['answers']['https'] ?? null)->toBeFalse()
        ->and(HerdState::load($context->basePath)?->result?->toArray())->toBe(['status' => 'skipped', 'message' => __('The Herd step could not start in the background.'), 'command' => null])
        ->and($context->path(HerdState::EXIT))->not->toBeFile();
});
```

`tests/Feature/Console/RunStepCommandTest.php`:
- Add `use App\Setup\Wizard\HerdStage;` and `use App\Setup\Wizard\SetupLock;`.
- Replace the head of 'applies the HTTPS consequence when the Herd step throws (OD-2, RL-6)', up to and including its `$context->answers = …` line; the rest of the test stays. RL-7 names `herd secure` only when Herd is present and serves the site:

```php
it('applies the HTTPS consequence when the Herd step throws, with the Herd command left to run (OD-2, RL-6, RL-7)', function (): void {
    // Arrange: HTTPS and passkeys chosen under Herd, which serves the site; `herd secure` then hangs and the step throws.
    $detection = new Detection(herd: true, herdPro: false, herdMail: false, herdSmtpPort: null, postgres: false, fluxPro: false, claude: false, host: 'localhost', scheme: 'http');
    $context = setupSandbox($detection);
    $herd = $context->path('home/Library/Application Support/Herd/bin/herd');
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);
    app()->instance(Toolchain::class, new Toolchain($context->path('home')));
    withoutPostgresProbe();
    Process::fake([
        "*herd' 'services:list'*" => Process::result(''),
        "*herd' 'links'*" => Process::result(''),
        "*herd' 'paths'*" => Process::result(json_encode([dirname($context->basePath)], JSON_THROW_ON_ERROR)),
    ]);
    app()->instance(SetupContext::class, $context);
    $context->answers = Answers::fromArray(['modules' => ['passkeys', 'impersonation'], 'https' => true], $detection, $context->folderName());
```

- Append:

```php
it('leaves no lock file when the login used the secret while Herd ran (g3-02)', function (): void {
    $context = setupSandbox();
    app()->instance(SetupContext::class, $context);
    $context->answers = Answers::fromArray(['modules' => ['impersonation'], 'https' => true], Detection::none(), $context->folderName());
    Marker::write($context, []);
    [$state] = HerdState::begin($context->basePath, $context, 'localhost');
    app()->instance(SetupRunner::class, new SetupRunner([
        new CallbackSetupStep('herd-site', function () use ($state): void {
            app(HerdStage::class)->loginUrl($state);
        }, StepResult::done(HerdSiteStep::SECURED.': late')),
    ]));

    expect(Artisan::call('skv:run-step', ['step' => 'herd-site', '--result' => $context->path(HerdState::PATH)]))->toBe(0)
        ->and($context->path(SetupLock::PATH))->not->toBeFile();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Auth/QuickLoginTest.php tests/Feature/Setup tests/Feature/Console/RunStepCommandTest.php tests/Feature/Tooling/CiWorkflowTest.php`

Expected: FAIL.
- **ShellArgumentTest:** class `App\Setup\Support\ShellArgument` not found.
- **QuickLoginTest:**
  - an array email answers 500 ("Array to string conversion")
  - a forwarded or remote request still sees the quick-login block
- **HerdSiteStepTest:**
  - `manualCommand()` is undefined
  - the spaced folder gives `herd link my app …` unquoted
- **ChecksTest and SetupRunnerTest:** the fix and the note print the spaced name unquoted.
- **CiWorkflowTest:** the script still uses `echo "  herd unsecure $NAME"`.
- **HerdStageTest:**
  - 'offers herd link…' shows `herd secure`
  - 'shows no command…' still renders a `<code>` block with `herd secure`
  - 'is not overdue…' answers `timeout`
  - 'offers the manual page…' finds a settled `manual` result
  - CD-14 redirects to `admin.localhost/auth/handoff/…`
  - both g3-02 rows find the lock file
- **WizardRunnerTest:** the no-Herd launch failure records `manual` instead of `skipped`.
- **RunStepCommandTest:** g3-02 finds the lock file.

The two g4-12 tests and the rewritten 'shows a finishing page…' pass already: the guards exist, and the old code always printed `herd secure`. Step 6 mutation-checks the guards.

- [ ] **Step 3: Implement the quoting helper and RL-7**

`app/Setup/Support/ShellArgument.php`:

```php
<?php

declare(strict_types=1);

namespace App\Setup\Support;

/**
 * One value in a command the kit prints for the user to run (RL-6, g2-05): a manual step command, a doctor fix, a
 * cleanup line. A value of characters no shell treats specially stays as it is, so ordinary folder names stay
 * readable; anything else is single-quoted by escapeshellarg(). Process argv never goes through here: it passes every
 * value as its own argument.
 */
final class ShellArgument
{
    private const string PLAIN = '/^[A-Za-z0-9._\/:=@%+-]+$/';

    public static function quote(string $value): string
    {
        return preg_match(self::PLAIN, $value) === 1 ? $value : escapeshellarg($value);
    }
}
```

`app/Setup/Steps/HerdSiteStep.php`:
- Add `use App\Setup\Support\ShellArgument;`.
- In `run()`, quote the three pending commands. The argv passed to `$this->herd(...)` stays as it is:

```php
        $name = $context->folderName();
        $linked = false;
        $pending = 'herd link '.ShellArgument::quote($name);
```

```php
            $pending = 'herd isolate '.self::PHP_VERSION.' '.ShellArgument::quote('--site='.$name);
```

```php
            $pending = 'herd secure '.ShellArgument::quote($name);
```

- Add `manualCommand()` right before the `siteState()` docblock ("Who serves {name}.{tld}. …"). `Throwable` is already imported:

```php
    /**
     * The one Herd command the user still has to run for this project (RL-7): `herd link` while Herd does not serve
     * it, `herd secure` when HTTPS was chosen, and null without Herd or with nothing left to do. A listing that fails
     * counts as unknown, so the command HTTPS asks for is named. Only Herd's read-only listings run, so the wizard,
     * the Herd stage and skv:run-step may call it from a web request (DEC-23).
     */
    public function manualCommand(SetupContext $context): ?string
    {
        $herd = $this->toolchain->herd();

        if (! $context->detection->herd || $herd === null) {
            return null;
        }

        $name = ShellArgument::quote($context->folderName());

        try {
            $served = $this->siteState($context, $herd) === self::SERVED;
        } catch (Throwable) {
            return $context->answers->https ? 'herd secure '.$name : 'herd link '.$name;
        }

        if (! $served) {
            return 'herd link '.$name;
        }

        return $context->answers->https ? 'herd secure '.$name : null;
    }
```

`app/Setup/Doctor/Checks/HerdSiteCheck.php`: add `use App\Setup\Support\ShellArgument;` and change the fix:

```php
        $command = 'herd link '.ShellArgument::quote($context->folderName());
```

`app/Setup/Doctor/Checks/HerdSecuredCheck.php`: add `use App\Setup\Support\ShellArgument;` and change the fix:

```php
        $command = 'herd secure '.ShellArgument::quote($context->folderName());
```

`app/Setup/SetupRunner.php`: add `use App\Setup\Support\ShellArgument;`. In `applyHttpsConsequence()`, the `:name` of both notes is a command argument:

```php
        $name = ShellArgument::quote($context->folderName());
```

`scripts/e2e-local.sh`: replace the cleanup block at the end of the script, from the `echo "Clean up when you are done …"` line through the second `rm -rf` line. `printf %q` is a bash builtin, and the script runs under `#!/usr/bin/env bash`:

```bash
echo "Clean up when you are done (this script runs none of these):"
# %q quotes a name or path the shell would split or expand, so every printed line runs as it is (RL-6).
if [ "$ENTRY" = herd ]; then
    printf '  herd unsecure %q\n' "$NAME"
    if herd_lists isolated; then printf '  herd unisolate %q\n' "--site=$NAME"; fi
    if [ "$LINKED_BEFORE" = false ] && herd_lists links; then printf '  herd unlink %q\n' "$NAME"; fi
fi
printf '  rm -rf %q\n' "$TARGET"
printf '  rm -rf %q\n' "$WORK"
```

Run `bash -n scripts/e2e-local.sh`.

- [ ] **Step 4: Implement the quick login, the login and the Herd stage**

`app/Http/Controllers/Auth/QuickLoginController.php`: in `__invoke()`, replace the `$url = …` line:

```php
        // A forged form can send email[]=…: anything but a string is no offered account (g3-14).
        $email = $request->input('email');
        abort_unless(is_string($email), 404);

        $url = $quickLogin->handoffUrl($email);
```

`app/Setup/QuickLogin.php`:
- Add `use App\Setup\Http\DirectRequest;`.
- Replace the class docblock:

```php
/**
 * §6.5, RL-9: one click into a demo account, on a local demo install only, for a request straight from this machine
 * (DEC-24: not through `herd share`, a proxy or the network, g3-15). The accounts are DemoSeeder's; one that is not
 * active, has two-factor authentication or no longer uses the demo password is not offered (the handoff skips the
 * second factor by design).
 */
```

- At the top of `accounts()`, replace `if (! app()->isLocal()) {` with the direct-request rule. It includes the local check, and `handoffUrl()` goes through `accounts()`, so the POST answers 404 too:

```php
        if (! DirectRequest::isDirect(request())) {
            return [];
        }
```

`app/Setup/Wizard/HerdState.php`: in `begin()`, extend the docblock's first sentence and remove an old exit file before the state is saved (g3-24):

```php
    /**
     * Starts the Herd stage for the context of a finished step 9 and saves it. An exit file an earlier stage left
     * would make this one overdue from its first poll (g3-24), so it goes first.
     *
     * @return array{0: self, 1: string} the state and the plain secret, which only the page receives
     */
```

```php
            startedAt: Date::now()->getTimestamp(),
        );
        @unlink(rtrim($basePath, '/').'/'.self::EXIT);
        $state->save($basePath);
```

`app/Setup/Wizard/HerdStage.php`:
- Add `use App\Setup\Detector;` and `use App\Setup\Steps\HerdSiteStep;`.
- In `continueWithoutHttps()`, split the guard. A request that finds no state removes the lock it recreated (g3-02):

```php
            $current = HerdState::load($basePath);

            // The login used the state up and removed the lock: this late request removes the lock it recreated (g3-02).
            if (! $current instanceof HerdState) {
                SetupLock::forget($basePath);

                return;
            }

            if (! hash_equals($state->secretHash, $current->secretHash) || $current->result instanceof StepResult) {
                return;
            }

            $this->wizard->settleWithoutHttps($current, __('Continued without HTTPS.'));
```

- Add, right before `summary()`. Task 4 replaces the Detector call with the stored snapshot:

```php
    /** The Herd command the manual page offers (RL-7), or null when there is none to run. */
    public function manualCommand(HerdState $state): ?string
    {
        $basePath = app(SetupContext::class)->basePath;

        return app(HerdSiteStep::class)->manualCommand($state->context($basePath, app(Detector::class)->detect($state->host)));
    }
```

- In `loginUrl()`, replace the docblock and send a confirmed second factor to the login page (CD-14):

```php
    /**
     * The handoff for the installed super admin, or the login page for a demo or for a super admin with a confirmed
     * second factor, which a handoff would skip (CD-14); the secret is used up (RL-6). The state is claimed under the
     * SetupLock, so two logins never both pass and a late Herd step never writes it back.
     */
```

```php
        abort_unless($user instanceof User && $user->isSuperAdmin(), 404);

        if ($user->two_factor_confirmed_at !== null) {
            return Hosts::url(Hosts::apex(), '/login');
        }

        return app(IssueHandoff::class)->handle($user, Hosts::admin(), '/');
```

`app/Setup/Wizard/WizardRunner.php`:
- In `finish()`, replace the `settleWithoutHttps(...)` call inside `if (! $started) {`:

```php
            if (! $started) {
                $this->launchFailed($herd, $context, $root);
            }
```

- Replace `settleWithoutHttps()`'s docblock and its result line (RL-7):

```php
    /**
     * Ends the Herd stage without a secured site (OD-2, RL-6): the Herd step could not start without Herd, or the user
     * continued without HTTPS. The HTTPS consequence applies, and the marker is rewritten with the Herd command left to
     * run (RL-7), or as skipped when there is none. The callers hold the SetupLock.
     */
    public function settleWithoutHttps(HerdState $herd, string $message): void
    {
        Tenant::bypass(function () use ($herd, $message): void {
            $basePath = app(SetupContext::class)->basePath;
            $context = $herd->context($basePath, $this->detector->detect($herd->host));
            $command = app(HerdSiteStep::class)->manualCommand($context);
            $context->results['herd-site'] = $command === null ? StepResult::skipped($message) : StepResult::manual($command, $message);
            $this->runner->applyHttpsConsequence($context);
```

  (The rest of the method stays.)
- In `confirmHerd()`, split the first guard (g3-02):

```php
            $current = HerdState::load($basePath);

            // The login used the state up and removed the lock: this late request removes the lock it recreated (g3-02).
            if (! $current instanceof HerdState) {
                SetupLock::forget($basePath);

                return null;
            }

            if (! hash_equals($herd->secretHash, $current->secretHash)) {
                return null;
            }
```

- Add this private method right after `progress()`. Pint's `ordered_class_elements` keeps it among the private methods:

```php
    /**
     * The Herd step could not start in the background (g3-22). With a Herd command left to run, the exit file makes
     * the answer overdue at once, so the page offers the manual page with Reload and Continue without HTTPS; without
     * one (no Herd), the stage ends as the step itself would have ended it.
     */
    private function launchFailed(HerdState $herd, SetupContext $context, string $root): void
    {
        if (app(HerdSiteStep::class)->manualCommand($context) === null) {
            $this->settleWithoutHttps($herd, __('The Herd step could not start in the background.'));

            return;
        }

        StateFile::write($root.'/'.HerdState::EXIT, "127\n");
    }
```

`app/Console/Commands/RunStepCommand.php`:
- Add `use App\Setup\Steps\HerdSiteStep;`.
- Replace the catch around `runStep()`:

```php
        } catch (Throwable $exception) {
            $command = $herd ? app(HerdSiteStep::class)->manualCommand($context) : 'php artisan skv:install';
            $result = $command === null ? StepResult::skipped(SafeMessage::of($exception)) : StepResult::manual($command, SafeMessage::of($exception));
        }
```

- Inside the `SetupLock::run()` callback, split the guard (g3-02):

```php
                $current = HerdState::load($basePath);

                // The login used the state up and removed the lock: this late child removes the lock it recreated (g3-02).
                if (! $current instanceof HerdState) {
                    SetupLock::forget($basePath);

                    return;
                }

                if (! hash_equals($state->secretHash, $current->secretHash) || $current->result instanceof StepResult) {
                    return;
                }
```

`app/Http/Controllers/Setup/SetupFinishController.php`:
- Remove `use App\Setup\SetupContext;`; it is no longer used.
- In the `setup.manual` view data, replace the `'command' => …` line:

```php
                return view('setup.manual', [
                    'command' => $stage->manualCommand($state),
                    'https' => $state->answers->https,
                    'continueUrl' => route('setup.finish', ['secret' => $secret, 'without_https' => 1], absolute: false),
                    'reloadUrl' => route('setup.finish', ['secret' => $secret], absolute: false),
                ]);
```

`resources/views/setup/manual.blade.php` (whole file):

```blade
{{-- RL-6, RL-7, OD-2: the Herd step gave no answer within the poll's 60 seconds; $command is the Herd command left to run, or null. --}}
<x-layouts::setup>
    <div data-test="setup-manual-herd" class="space-y-4">
        <flux:heading size="xl" level="1">{{ $https ? __('Herd did not confirm HTTPS in time.') : __('Herd did not confirm the site in time.') }}</flux:heading>

        @if ($command !== null)
            <div class="flex items-center gap-2" x-data>
                <code class="rounded bg-zinc-100 px-2 py-1 text-sm dark:bg-zinc-800">{{ $command }}</code>
                <flux:button size="xs" x-on:click="navigator.clipboard?.writeText(@js($command)).catch(() => {})">{{ __('Copy') }}</flux:button>
            </div>
        @endif

        <flux:text>{{ __('Run it in a terminal, then reload this page, or continue without HTTPS (passkeys are then switched off).') }}</flux:text>

        <div class="flex items-center gap-3">
            <flux:button :href="$reloadUrl">{{ __('Reload') }}</flux:button>
            <flux:button variant="primary" :href="$continueUrl" data-test="setup-continue-without-https">{{ __('Continue without HTTPS') }}</flux:button>
        </div>
    </div>
</x-layouts::setup>
```

Lang key, in both files at its `sort()` position:

| Key | nl |
|---|---|
| `Herd did not confirm the site in time.` | `Herd heeft de site niet op tijd bevestigd.` |

In `en.json` the value equals the key.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Auth tests/Feature/Setup tests/Feature/Console tests/Feature/Tooling/CiWorkflowTest.php tests/Feature/LangCoverageTest.php`

Expected: PASS.

- [ ] **Step 6: Verify by mutation**

Revert each change locally, run the named test, and expect it to fail. Then restore the change.

1. **`HerdStage::continueWithoutHttps()`:** change the second guard to `if (false && $current->result instanceof StepResult) {`. Expect both rows of '… on continue without HTTPS (RL-6, g4-12)' to fail.
2. **`WizardRunner::confirmHerd()`:** change `if ($current->result instanceof StepResult) {` to `if (false) {`. Expect the row 'the Herd step answered meanwhile' of '… on the server-side confirmation' to fail.
3. **`WizardRunner::confirmHerd()`:** change `if (! hash_equals($herd->secretHash, $current->secretHash)) {` to `if (false) {`. Expect the row 'another finish replaced the state' to fail.
4. **`QuickLogin::accounts()`:** put `if (! app()->isLocal())` back. Expect both rows of 'offers no quick login to a request that is not direct…' to fail.

- [ ] **Step 7: The gate, then commit**

```bash
composer fix
composer check
composer test:pgsql
php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser
npm run build && composer test:browser
npm run build:setup && git diff --exit-code resources/setup/setup.css
php artisan config:clear
```

`resources/views/setup/manual.blade.php` sits under `resources/views/setup`, so the stylesheet is rebuilt. The new markup uses classes the page already used, so no diff is expected. If `resources/setup/setup.css` changes anyway, add it to the commit.

```bash
git add app/Setup/Support/ShellArgument.php app/Setup/Steps/HerdSiteStep.php app/Setup/Doctor/Checks/HerdSiteCheck.php \
    app/Setup/Doctor/Checks/HerdSecuredCheck.php app/Setup/SetupRunner.php app/Setup/QuickLogin.php \
    app/Setup/Wizard/HerdStage.php app/Setup/Wizard/HerdState.php app/Setup/Wizard/WizardRunner.php \
    app/Console/Commands/RunStepCommand.php app/Http/Controllers/Auth/QuickLoginController.php \
    app/Http/Controllers/Setup/SetupFinishController.php resources/views/setup/manual.blade.php resources/setup/setup.css \
    scripts/e2e-local.sh lang/nl.json lang/en.json tests/Feature/Auth/QuickLoginTest.php tests/Feature/Setup \
    tests/Feature/Console/RunStepCommandTest.php tests/Feature/Tooling/CiWorkflowTest.php
git commit -m "fix: harden the quick login and the herd stage, and quote printed commands

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: The wizard runner (g3-11, g3-12, g3-29, g3-30, g3-31, g3-32, g4-04, g4-13, g4-14, g4-19, g4-20, g4-21, g1-17's second half; RL-8, CD-15)

**Files:**
- Modify:
  - `app/Setup/Detection.php` (`$port`, `toArray()`, `fromArray()`)
  - `app/Setup/Detector.php` (`$requestPort`)
  - `app/Setup/Wizard/WizardState.php`, `app/Setup/Wizard/HerdState.php` (the snapshot)
  - `app/Setup/Wizard/WizardRunner.php` (CD-15, RL-8, g3-11, g3-31, g3-32, the wizard's refusal message)
  - `app/Setup/Wizard/HerdStage.php` (`manualCommand()` on the snapshot)
  - `app/Setup/Wizard/StepLauncher.php`, `app/Setup/Wizard/DetachedStepLauncher.php` (RL-8)
  - `app/Console/Commands/RunStepCommand.php` (CD-15)
  - `app/Setup/Steps/EnvironmentStep.php` (the request port)
  - `resources/views/pages/setup/wizard/wizard.php`, `resources/views/pages/setup/wizard/wizard.blade.php` (g3-30, g3-31, g3-32, g4-04), then `resources/setup/setup.css` (`npm run build:setup`)
  - `tests/Fixtures/InlineStepLauncher.php`
  - `lang/nl.json`, `lang/en.json`
- Test:
  - `tests/Feature/Setup/Wizard/WizardRunnerTest.php`, `tests/Feature/Setup/Wizard/WizardPageTest.php`, `tests/Feature/Setup/Wizard/WizardStateTest.php`
  - `tests/Feature/Setup/Wizard/DetachedStepLauncherTest.php`, `tests/Feature/Setup/Wizard/HerdStageTest.php`
  - `tests/Feature/Setup/DetectorTest.php`, `tests/Feature/Setup/Steps/MarkerStepTest.php`
  - `tests/Feature/Console/RunStepCommandTest.php`

**Interfaces:**
- Consumes:
  - from Task 1: `withoutPostgresProbe()`; `Detector::__construct(Toolchain, ?Closure $postgresProbe = null)`; `DetachedStepLauncher::SCRIPT` starting `nohup sh -c 'umask 077; …`
  - from Task 2:
    - `begin(array $input, string $requestHost)` receives `getHttpHost()` (host[:port]) and pins through `SetupHostGuard::resolve()`
    - `EnvironmentStep::variables()`' port line (task1-2.md, Task 2, Step 5)
    - the page passes `request()->getHttpHost()`
  - from Task 3: `HerdSiteStep::manualCommand()`, `WizardRunner::launchFailed()`, `HerdStage::manualCommand()`
- Produces:
  - **Detection (CD-15):**
    - `Detection::__construct(…, string $scheme, ?int $port = null)`
    - `Detection::toArray(): array{herd: bool, herd_pro: bool, herd_mail: bool, herd_smtp_port: int|null, postgres: bool, flux_pro: bool, claude: bool, host: string, scheme: string, port: int|null}`
    - `Detection::fromArray(array $data): self`, which throws `InvalidArgumentException`
    - `Detector::detect(?string $requestHost = null, string $scheme = 'http', ?int $requestPort = null): Detection`. Port is null under Herd or on 80 (http) or 443 (https).
  - **The state classes (CD-15):**
    - `WizardState::__construct(string $host, Answers $answers, Detection $detection, array $results = [], array $notes = [])` and `WizardState::context(string $basePath): SetupContext`
    - `HerdState::__construct(string $secretHash, string $host, Answers $answers, Detection $detection, array $results, array $notes, int $startedAt, ?StepResult $result = null)` and `HerdState::context(string $basePath): SetupContext`
    - both files store `detection`, and a file without it throws `InvalidArgumentException`
  - **The launcher (RL-8):**
    - `StepLauncher::start(string $basePath, string $key, string $resultFile, string $logFile, string $exitFile, ?string $pidFile = null): bool`
    - the detached shell writes its `$$` into `$pidFile`, or into `/dev/null` when there is none
  - **The runner (RL-8, g3-11, g3-31, g3-32):**
    - `WizardRunner::recheck()` throws `RuntimeException(__('This step is still running.'))` while the step's pid lives
    - the stall rule reports a live pid as running, for at most `LIVE_PID_SECONDS` (14400, four hours) after the step started
    - `advanceLong()` reads the exit file before the result
    - `advance()` reports anything a long step throws as `failed`
    - `finish()` records the Herd step and the HTTPS consequence in the marker when anything after step 9 throws, then rethrows
  - **The page (g4-04, g3-32):** a `stranded` stage with `data-test="setup-stranded"` and a `data-test="setup-login"` link, plus `#[Locked] $startWithChosen` and `#[Locked] $loginUrl`
  - **New lang keys:** "This step is still running.", "Setup is done, but the Herd step could not start." and the wizard's demo refusal

- [ ] **Step 1: Write the failing tests**

**The constructor call sites.** Each of these takes the detection now (CD-15):
- `tests/Feature/Setup/Steps/MarkerStepTest.php`: `new WizardState('localhost', $context->answers, $context->detection)->save($context->basePath);`
- `tests/Feature/Console/RunStepCommandTest.php`, both `new WizardState('localhost', Answers::defaults(Detection::none(), $context->folderName()))` lines: add `, Detection::none()` as the third argument.
- `tests/Feature/Setup/Wizard/WizardRunnerTest.php`, 'settles at once without HTTPS when the Herd step cannot start without Herd' (Task 3): `new WizardState('localhost', $answers, Detection::none(), array_fill_keys(WizardRunner::PAGE_STEPS, StepResult::done('ok')))`.
- `tests/Feature/Setup/Wizard/HerdStageTest.php`, 'offers the manual page when the Herd step cannot start…' (Task 3): `new WizardState('localhost', $answers, $herdDetection(), array_fill_keys(WizardRunner::PAGE_STEPS, StepResult::done('ok')))`.
- `tests/Feature/Setup/Wizard/WizardStateTest.php`:
  - 'stores the answers, results and notes in a 0600 file without the password': `new WizardState('my-app.test', $answers, Detection::none('my-app.test'), ['environment' => StepResult::done('ok')], ['a note'])`
  - 'restores a context with its results and notes, and takes them back': replace the body's first three lines and its expectation:

```php
    $state = new WizardState('my-app.test', Answers::defaults(Detection::none(), $context->folderName()), Detection::none('my-app.test'), ['git' => StepResult::skipped('x')]);

    $restored = $state->context($context->basePath);
```

```php
    expect(array_keys($state->results))->toBe(['git', 'claude'])->and($state->notes)->toBe(['later'])
        ->and($restored->detection)->toEqual(Detection::none('my-app.test'));
```

`tests/Feature/Setup/Wizard/WizardStateTest.php`: add `use App\Setup\SetupContext;` and `use App\Setup\Wizard\WizardRunner;`, then append:

```php
it('keeps the detection snapshot of the start in the wizard state and the Herd state (CD-15)', function (): void {
    $context = setupSandbox();
    $detection = new Detection(herd: false, herdPro: false, herdMail: false, herdSmtpPort: null, postgres: true, fluxPro: false, claude: true, host: 'localhost', scheme: 'http', port: 8000);
    $herdContext = new SetupContext($context->basePath, $context->answers, $detection);

    new WizardState('localhost', $context->answers, $detection)->save($context->basePath);
    HerdState::begin($context->basePath, $herdContext, 'localhost');

    expect(WizardState::load($context->basePath)?->detection)->toEqual($detection)
        ->and(WizardState::load($context->basePath)?->context($context->basePath)->detection)->toEqual($detection)
        ->and(HerdState::load($context->basePath)?->detection)->toEqual($detection)
        ->and(Detection::fromArray($detection->toArray()))->toEqual($detection);
});

/** A snapshot as Detection::toArray() writes it. */
$snapshot = ['herd' => false, 'herd_pro' => false, 'herd_mail' => false, 'herd_smtp_port' => null, 'postgres' => false, 'flux_pro' => false, 'claude' => false, 'host' => 'localhost', 'scheme' => 'http', 'port' => null];

it('reads back the snapshot it writes (CD-15)', function () use ($snapshot): void {
    expect(Detection::none()->toArray())->toBe($snapshot)
        ->and(Detection::fromArray($snapshot))->toEqual(Detection::none());
});

it('refuses a detection snapshot of the wrong shape (CD-15)', function (array $broken): void {
    expect(fn (): Detection => Detection::fromArray($broken))->toThrow(InvalidArgumentException::class);
})->with([
    'a missing key' => [array_diff_key($snapshot, ['herd' => true])],
    'a string for a boolean' => [[...$snapshot, 'postgres' => 'yes']],
    'a string port' => [[...$snapshot, 'herd_smtp_port' => '2525']],
    'no host' => [[...$snapshot, 'host' => null]],
    'a string request port' => [[...$snapshot, 'port' => '8000']],
]);

it('refuses a state file written before the detection snapshot, and starts over (CD-15)', function (): void {
    $context = setupSandbox();
    app()->instance(SetupContext::class, $context);
    $state = ['host' => 'localhost', 'answers' => $context->answers->toState(), 'results' => [], 'notes' => []];
    StateFile::writeJson($context->path(WizardState::PATH), $state);
    StateFile::writeJson($context->path(HerdState::PATH), [...$state, 'secret_hash' => str_repeat('0', 64), 'started_at' => time(), 'result' => null]);

    expect(fn (): ?WizardState => WizardState::load($context->basePath))->toThrow(InvalidArgumentException::class)
        ->and(fn (): ?HerdState => HerdState::load($context->basePath))->toThrow(InvalidArgumentException::class)
        ->and(app(WizardRunner::class)->state())->toBeNull()
        ->and($context->path(WizardState::PATH))->not->toBeFile();
});
```

> The snapshot is a literal array, not `Detection::none()->toArray()`. A dataset built from a method that does not exist yet would stop the whole run at collection time ("no dataset was provided"), instead of failing this test.

`tests/Feature/Setup/DetectorTest.php` (as Task 1 left it): append:

```php
it('keeps the request\'s port without Herd, never the scheme\'s default port (CD-15, g1-17)', function (string $scheme, ?int $requestPort, ?int $port): void {
    $detection = new Detector(new Toolchain($this->home), postgresProbe: fn (): bool => false)->detect('localhost', $scheme, $requestPort);

    expect($detection->port)->toBe($port);
})->with([
    'a port' => ['http', 8000, 8000],
    'http on 80' => ['http', 80, null],
    'https on 443' => ['https', 443, null],
    'no request' => ['http', null, null],
]);

it('keeps no port under Herd, which serves every site on the default port (CD-15)', function (): void {
    $herd = $this->home.'/Library/Application Support/Herd/bin/herd';
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);
    Process::fake(["*'services:list' '--json'*" => Process::result('')]);

    expect(new Detector(new Toolchain($this->home), postgresProbe: fn (): bool => false)->detect('my-app.test', 'http', 8000)->port)->toBeNull();
});
```

`tests/Feature/Setup/Wizard/DetachedStepLauncherTest.php`: replace 'starts skv:run-step detached with every value as its own argument (RL-4)' and add a case:

```php
it('starts skv:run-step detached with every value as its own argument, its shell writing its pid first (RL-4, RL-8)', function (): void {
    $context = setupSandbox();
    Process::fake(["*sh' '-c'*" => Process::result('')]);

    $started = new DetachedStepLauncher(app(Toolchain::class), php: '/usr/bin/php')
        ->start($context->basePath, 'frontend', '/r.json', '/l.log', '/e.exit', '/s.started');

    expect($started)->toBeTrue();
    Process::assertRan(fn ($process): bool => $process->command[0] === 'sh'
        && $process->command[1] === '-c'
        && str_contains($process->command[2], 'nohup')
        && str_contains($process->command[2], 'echo $$ > "$5"; "$0" artisan skv:run-step')
        && array_slice($process->command, 3) === ['sh', '/usr/bin/php', 'frontend', '/r.json', '/l.log', '/e.exit', '/s.started']);
});

it('writes the pid of a launch without a pid file to /dev/null (RL-8)', function (): void {
    $context = setupSandbox();
    Process::fake(["*sh' '-c'*" => Process::result('')]);

    new DetachedStepLauncher(app(Toolchain::class), php: '/usr/bin/php')->start($context->basePath, 'herd-site', '/r.json', '/l.log', '/e.exit');

    Process::assertRan(fn ($process): bool => array_slice($process->command, -1) === ['/dev/null']);
});
```

`tests/Feature/Setup/Wizard/WizardRunnerTest.php`:
- Add `use App\Setup\Detector;` and `use App\Setup\Support\EnvFile;` (`Answers` and `Detection` came with Task 3).
- **g4-21: bound the three remaining loops.**
  - In 'runs steps 1 to 8 one call at a time, the long ones detached':

```php
    // Bounded, so a step that keeps answering "running" fails the test instead of hanging the worker (g4-21).
    for ($i = 0; $i < 50 && ($outcome = $runner->advance()) !== null; $i++) {
        expect($outcome['state'])->not->toBe('failed');
        $keys[] = $outcome['key'];
    }

    expect($i)->toBeLessThan(50)
        ->and(array_values(array_unique($keys)))->toBe(WizardRunner::PAGE_STEPS)
```

    (The expectation chain continues as before.)
  - In 'marks a detached step that could not start as a manual command':

```php
    for ($i = 0; $i < 50 && ($outcome = $runner->advance()) !== null && $outcome['key'] !== 'frontend'; $i++) {
    }

    expect($i)->toBeLessThan(50)
        ->and($runner->state()?->results['frontend']->status ?? null)->toBe('manual');
```

  - In 'finishes with the marker, a Herd state holding the secret's hash and the Herd step started':

```php
    for ($i = 0; $i < 50 && $runner->advance() !== null; $i++) {
    }

    $secret = $runner->finish();

    expect($i)->toBeLessThan(50)
        ->and(Marker::exists($context))->toBeTrue()
```

    (The expectation chain continues as before.)
- **g4-20:** in 'reports a failing step without losing the earlier results, then retries it', append after the existing expectation:

```php
    // Act: the cause is fixed (SQLite instead of the server that does not answer) and the page asks again (g4-20).
    $state = WizardState::load($context->basePath) ?? throw new LogicException('No setup state.');
    $state->answers = Answers::fromState([...$state->answers->toState(), 'database' => 'sqlite']);
    $state->save($context->basePath);
    $retried = $runner->advance();

    expect($retried['key'] ?? null)->toBe('database')
        ->and($retried['state'] ?? null)->toBe('done')
        ->and(WizardState::load($context->basePath)->results)->toHaveKeys(['environment', 'database']);
```

- Append:

```php
/** A wizard state whose steps 1–4 are done, so the frontend step (long) comes next; returns the steps directory. */
$beforeFrontend = function (SetupContext $context): string {
    $state = app(WizardRunner::class)->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost');
    $state->results = array_fill_keys(['environment', 'database', 'herd-manifest', 'branding'], StepResult::done('ok'));
    $state->save($context->basePath);
    File::ensureDirectoryExists($context->path(WizardState::STEPS));

    return $context->path(WizardState::STEPS);
};

it('records the result of a detached step that exited (RL-4, g3-11)', function () use ($beforeFrontend): void {
    // advanceLong() reads the exit file before the result: the child writes its result first, so a result written
    // between the two reads is never taken for "stopped without a result". That race cannot be made deterministic
    // here; this pins the branch it ends in.
    $context = useSetupWizard();
    $steps = $beforeFrontend($context);
    File::put($steps.'/frontend.json', json_encode([...StepResult::done('built')->toArray(), 'finished_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR));
    File::put($steps.'/frontend.exit', "0\n");

    expect(app(WizardRunner::class)->advance())->toBe(['key' => 'frontend', 'state' => 'done', 'message' => 'built'])
        ->and(app(WizardRunner::class)->state()?->results['frontend']->status ?? null)->toBe('done');
});

it('keeps reporting a quiet detached step as running while its process lives (RL-8, g3-12)', function () use ($beforeFrontend): void {
    // Arrange: this test's own pid stands for the launcher's shell, which is still alive.
    $context = useSetupWizard();
    $steps = $beforeFrontend($context);
    File::put($steps.'/frontend.started', getmypid()."\n");
    File::put($steps.'/frontend.log', 'npm ci');
    $this->travel(31)->minutes();

    expect(app(WizardRunner::class)->advance())->toBe(['key' => 'frontend', 'state' => 'running', 'message' => ''])
        ->and(app(WizardRunner::class)->state()?->results)->not->toHaveKey('frontend');
});

it('refuses a re-check while the step\'s process lives, so no second one starts (RL-8, g3-12)', function () use ($beforeFrontend): void {
    $context = useSetupWizard();
    $steps = $beforeFrontend($context);
    $state = app(WizardRunner::class)->state() ?? throw new LogicException('No setup state.');
    $state->results['frontend'] = StepResult::manual('npm ci && npm run build', 'The step has not finished.');
    $state->save($context->basePath);
    File::put($steps.'/frontend.started', getmypid()."\n");
    File::put($steps.'/frontend.log', 'npm ci');

    expect(fn () => app(WizardRunner::class)->recheck('frontend'))->toThrow(RuntimeException::class, __('This step is still running.'))
        ->and($steps.'/frontend.started')->toBeFile()
        ->and($steps.'/frontend.log')->toBeFile()
        ->and(app(WizardRunner::class)->state()->results)->toHaveKey('frontend');
});

it('stops trusting the pid four hours after the step started, so a reused pid never wedges the step (RL-8)', function () use ($beforeFrontend): void {
    // Arrange: the shell died without writing the exit file, and its pid now belongs to another live process
    // (this test's own); the step started five hours ago and its log is gone.
    $context = useSetupWizard();
    $steps = $beforeFrontend($context);
    File::put($steps.'/frontend.started', getmypid()."\n");
    touch($steps.'/frontend.started', time() - 5 * 3600);

    expect(app(WizardRunner::class)->advance())->toBe([
        'key' => 'frontend',
        'state' => 'done',
        'message' => __('The step has not finished; see :log.', ['log' => WizardState::STEPS.'/frontend.log']),
    ])->and(app(WizardRunner::class)->state()?->results['frontend']->status ?? null)->toBe('manual');

    app(WizardRunner::class)->recheck('frontend');

    expect($steps.'/frontend.started')->not->toBeFile()
        ->and(app(WizardRunner::class)->state()?->results)->not->toHaveKey('frontend');
});

it('detects the machine once per setup, not on every poll (CD-15, g3-29)', function (): void {
    // Arrange: count the Detector's PostgreSQL probes, one per detect().
    $context = useSetupWizard();
    $probes = 0;
    app()->instance(Detector::class, new Detector(app(Toolchain::class), postgresProbe: function () use (&$probes): bool {
        $probes++;

        return false;
    }));
    $runner = app(WizardRunner::class);

    $runner->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost');
    $runner->advance();
    $runner->advance();
    $runner->advance();

    expect($probes)->toBe(1)
        ->and(WizardState::load($context->basePath)?->results)->toHaveKeys(['environment', 'database', 'herd-manifest']);
});

it('reports a long step whose completion check throws as failed, not a 500 (§5.3.1, g3-31)', function (): void {
    // Arrange: steps 1–6 are done, so the Claude step (long) comes next, and its check reads a broken composer.json.
    $context = useSetupWizard();
    /** @var InlineStepLauncher $launcher */
    $launcher = app(StepLauncher::class);
    $runner = app(WizardRunner::class);
    $state = $runner->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost');
    $state->results = array_fill_keys(['environment', 'database', 'herd-manifest', 'branding', 'frontend', 'git'], StepResult::done('ok'));
    $state->save($context->basePath);
    File::put($context->path('composer.json'), '{');

    $outcome = $runner->advance();

    expect($outcome['key'] ?? null)->toBe('claude')
        ->and($outcome['state'] ?? null)->toBe('failed')
        ->and($runner->state()?->results)->not->toHaveKey('claude')
        ->and($launcher->started)->toBe([]);
});

it('records what the Herd step did not do in the marker when the Herd stage cannot start after step 9 (g3-32)', function (): void {
    // Arrange: steps 1–8 are done, and a directory stands where the Herd state goes, so finishing fails after the marker.
    $context = useSetupWizard();
    new EnvFile($context->path('.env'))->set(['MODULE_PASSKEYS' => true]);
    $answers = Answers::fromArray(['modules' => ['passkeys', 'impersonation'], 'https' => true], Detection::none(), $context->folderName());
    new WizardState('localhost', $answers, Detection::none(), array_fill_keys(WizardRunner::PAGE_STEPS, StepResult::done('ok')))->save($context->basePath);
    File::ensureDirectoryExists($context->path(HerdState::PATH));

    $thrown = null;

    try {
        app(WizardRunner::class)->finish();
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(Throwable::class)
        ->and(Marker::read($context)['answers']['https'] ?? null)->toBeFalse()
        ->and(new EnvFile($context->path('.env'))->get('MODULE_PASSKEYS'))->toBe('false');
});

it('writes the port the wizard was opened on into APP_URL without Herd (CD-15, g1-17)', function (): void {
    // Arrange: .env still has the APP_URL of .env.example, without a port.
    $context = useSetupWizard();
    $runner = app(WizardRunner::class);
    $runner->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost:8000');

    expect($runner->advance()['key'] ?? null)->toBe('environment')
        ->and(WizardState::load($context->basePath)?->detection->port)->toBe(8000)
        ->and(new EnvFile($context->path('.env'))->get('APP_URL'))->toBe('http://localhost:8000');
});
```

> The g3-32 case catches the exception by hand, because Pest's `toThrow(Throwable::class)` treats an interface name as a message. What is thrown depends on `StateFile::write()`: an `ErrorException` from `rename()` onto a directory, or a `RuntimeException` if Task 1 made the rename quiet.

`tests/Feature/Setup/Wizard/WizardPageTest.php`:
- Add the imports:
  - `use App\Models\User;`, `use App\Setup\Answers;`, `use App\Setup\Detection;`, `use App\Setup\StepResult;`
  - `use App\Setup\Wizard\HerdState;`, `use App\Setup\Wizard\WizardRunner;`, `use App\Tenancy\Hosts;`
- Append:

```php
it('turns a start that cannot take the setup lock into an error on the form, not a 500 (g3-30)', function () use ($open, $next, $answers): void {
    // Arrange: a directory stands where the setup lock goes, so opening it fails as a read-only disk would.
    $context = useSetupWizard();
    $snapshot = $open($context);
    File::ensureDirectoryExists($context->path(SetupLock::PATH));

    [, $html] = $next(callPageAction($snapshot, 'start', updates: $answers)->assertOk());

    expect($html)->toContain('data-test="setup-questions"')
        ->toContain(__('Could not write :file.', ['file' => basename(SetupLock::PATH)]))
        ->and($context->path(WizardState::PATH))->not->toBeFile();
});

it('refuses the default demo data next to an own super admin, and adds it when Start is clicked again (DEC-30, g4-04)', function () use ($open, $next, $answers): void {
    $context = useSetupWizard();
    User::factory()->superAdmin()->create(['email' => 'owner@example.com']);
    // The user leaves the "Start with" radio alone: no update for startWith, so the page sends no start_with.
    $untouched = array_diff_key($answers, ['startWith' => true]);

    [$snapshot, $html] = $next(callPageAction($open($context), 'start', updates: $untouched)->assertOk());

    expect($html)->toContain(__('A super admin already exists, so setup does not add the demo data by default. Click Start setup again to add it anyway, or choose My own super admin.'))
        ->and($context->path(WizardState::PATH))->not->toBeFile();

    callPageAction($snapshot, 'start')->assertOk();

    expect(WizardState::load($context->basePath)?->answers->startWith)->toBe('demo');
});

it('stops polling on a failure, and Try again runs the step again (§5.3.1, g4-13, g4-14)', function () use ($open, $next, $answers): void {
    // Arrange: PostgreSQL chosen while no server answers on this port, so the database step fails.
    $context = useSetupWizard();
    config(['database.connections.pgsql.port' => 1]);
    [$snapshot] = $next(callPageAction($open($context), 'start', updates: [...$answers, 'database' => 'pgsql'])->assertOk());
    [$snapshot] = $next(callPageAction($snapshot, 'advance')->assertOk());
    [$snapshot, $html] = $next(callPageAction($snapshot, 'advance')->assertOk());

    expect($html)->toContain('data-test="setup-failure"')->toContain('wire:click="retry"')->not->toContain('wire:poll');

    // Act: the cause is fixed (SQLite instead) and the user clicks Try again.
    $state = WizardState::load($context->basePath) ?? throw new LogicException('No setup state.');
    $state->answers = Answers::fromState([...$state->answers->toState(), 'database' => 'sqlite']);
    $state->save($context->basePath);
    [, $html] = $next(callPageAction($snapshot, 'retry')->assertOk());

    expect($html)->not->toContain('data-test="setup-failure"')->toContain('wire:poll')
        ->and(WizardState::load($context->basePath)->results)->toHaveKey('database');
});

it('runs Finish setup again from the review when Try again is clicked (§5.3.1, g4-13)', function () use ($open, $next): void {
    // Arrange: steps 1–8 have results, one of them a manual command, so the page stops at the review.
    $context = useSetupWizard();
    $answers = Answers::fromArray(['modules' => ['impersonation'], 'https' => false], Detection::none(), $context->folderName());
    $results = [...array_fill_keys(WizardRunner::PAGE_STEPS, StepResult::done('ok')), 'frontend' => StepResult::manual('npm ci && npm run build', 'node is missing')];
    new WizardState('localhost', $answers, Detection::none(), $results)->save($context->basePath);
    [$snapshot, $html] = $next(callPageAction($open($context), 'advance')->assertOk());

    expect($html)->toContain('data-test="setup-review"');

    // Another request holds the setup lock, so the first Finish setup fails before the marker.
    $lock = fopen($context->path(SetupLock::PATH), 'c') ?: throw new LogicException('No lock file.');
    flock($lock, LOCK_EX);
    [$snapshot, $html] = $next(callPageAction($snapshot, 'complete')->assertOk());
    flock($lock, LOCK_UN);

    expect($html)->toContain('data-test="setup-failure"')->toContain(__('Setup is busy in another tab.'))
        ->and(Marker::exists($context))->toBeFalse();

    [, $html] = $next(callPageAction($snapshot, 'retry')->assertOk());

    expect($html)->toContain('data-test="setup-herd-stage"')
        ->and(Marker::exists($context))->toBeTrue();
});

it('offers the login page instead of Try again when the Herd stage cannot start after step 9 (g3-32)', function () use ($open, $next): void {
    // Arrange: steps 1–8 are done, and a directory stands where the Herd state goes, so finishing fails after the marker.
    $context = useSetupWizard();
    $answers = Answers::fromArray(['modules' => ['impersonation'], 'https' => false], Detection::none(), $context->folderName());
    new WizardState('localhost', $answers, Detection::none(), array_fill_keys(WizardRunner::PAGE_STEPS, StepResult::done('ok')))->save($context->basePath);
    File::ensureDirectoryExists($context->path(HerdState::PATH));

    [, $html] = $next(callPageAction($open($context), 'advance')->assertOk());

    expect($html)->toContain('data-test="setup-stranded"')
        ->toContain('href="'.Hosts::url(Hosts::apex(), '/login').'"')
        ->not->toContain('wire:click="retry"')
        ->and(Marker::exists($context))->toBeTrue();
});
```

`tests/Feature/Console/RunStepCommandTest.php`:
- **g4-19:** retitle 'runs one step from the wizard state and writes its result (RL-4)' to '… (RL-4, g4-19)' and replace its expectation:

```php
    // A step that crashed would also write a result, as manual: only "done" and the git commands prove it ran.
    expect($exitCode)->toBe(0)
        ->and(StateFile::readJson($result)['status'] ?? null)->toBe('done');
    Process::assertRan(fn ($process): bool => array_slice((array) $process->command, -1) === ['init']);
    Process::assertRan(fn ($process): bool => array_slice((array) $process->command, -3) === ['config', 'core.hooksPath', '.githooks']);
```

  > `$process->command` is the argv array: the Symfony-quoted form exists only for the fake's patterns.
- Append:

```php
it('runs a step with the detection the wizard stored at the start, never a new one (CD-15)', function (): void {
    // Arrange: the stored snapshot says Herd, while this machine (the sandbox toolchain) has none.
    $detection = new Detection(herd: true, herdPro: false, herdMail: false, herdSmtpPort: null, postgres: false, fluxPro: false, claude: false, host: 'localhost', scheme: 'http');
    $context = setupSandbox($detection);
    app()->instance(SetupContext::class, $context);
    $context->answers = Answers::fromArray(['modules' => ['impersonation'], 'https' => false], $detection, $context->folderName());
    Marker::write($context, []);
    HerdState::begin($context->basePath, $context, 'localhost');
    withoutPostgresProbe();

    expect(Artisan::call('skv:run-step', ['step' => 'herd-site', '--result' => $context->path(HerdState::PATH)]))->toBe(0)
        ->and(HerdState::load($context->basePath)?->result?->toArray())->toBe(['status' => 'manual', 'message' => __('The herd command was not found.'), 'command' => 'herd link']);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Setup tests/Feature/Console/RunStepCommandTest.php`

Expected: FAIL.
- **The constructor sites:** every rewritten `new WizardState(...)` passes a `Detection` where the results array goes, so it fails with a `TypeError`. `context()` gets too few arguments.
- **WizardStateTest:** `toArray()`/`fromArray()` are undefined, and an old state file still loads.
- **DetectorTest:** unknown named parameter `$requestPort` in `detect()`.
- **DetachedStepLauncherTest:** the argv has no pid file and the script no `echo $$`.
- **WizardRunnerTest:**
  - the live-pid stall is recorded as manual ("The step has not finished")
  - the re-check deletes the files and throws nothing
  - the probe counts 4, not 1
  - the broken composer.json escapes as a `JsonException`
  - the g3-32 marker still says `https: true`
  - the port test fails on the `WizardState` constructor
  - 'records the result of a detached step that exited' passes already: note 11
  - 'stops trusting the pid four hours after the step started' passes already, because today's stall rule ignores the pid; Step 8 mutation-checks the bound
- **WizardPageTest:**
  - the locked start answers 500 ("Could not write skv-setup.lock.")
  - the untouched radio still sends `start_with`, so a state file appears
  - the stranded case shows "Try again"
  - the two retry cases fail on the `WizardState` constructor
- **RunStepCommandTest:** the Herd step detects again and is `skipped` instead of `manual` `herd link`.

- [ ] **Step 3: Implement the snapshot (CD-15)**

`app/Setup/Detection.php`:
- Add `use InvalidArgumentException;`.
- Replace the class docblock:

```php
/**
 * The environment as setup found it before asking anything (§5.3.2, RL-4): one immutable snapshot. The browser
 * wizard takes it once, in begin(), and keeps it in its state files (CD-15).
 */
```

- Extend the constructor's `@param` block and add the last parameter:

```php
     * @param  string  $scheme  "http" until the Herd step secures the site
     * @param  int|null  $port  the wizard request's port without Herd, null under Herd or on the scheme's default
     *                          port, so APP_URL keeps a `php artisan serve` port (CD-15, g1-17)
     */
    public function __construct(
        public bool $herd,
        public bool $herdPro,
        public bool $herdMail,
        public ?int $herdSmtpPort,
        public bool $postgres,
        public bool $fluxPro,
        public bool $claude,
        public string $host,
        public string $scheme,
        public ?int $port = null,
    ) {}
```

- Add after `none()`:

```php
    /**
     * A snapshot toArray() wrote (CD-15): checked for shape, not detected again.
     *
     * @param  array<array-key, mixed>  $data
     *
     * @throws InvalidArgumentException when a key is missing or has the wrong type
     */
    public static function fromArray(array $data): self
    {
        $bool = fn (string $key): bool => is_bool($data[$key] ?? null) ? $data[$key] : throw new InvalidArgumentException("Detection: {$key} must be a boolean.");
        $string = fn (string $key): string => is_string($data[$key] ?? null) ? $data[$key] : throw new InvalidArgumentException("Detection: {$key} must be a string.");
        $int = fn (string $key): ?int => ($data[$key] ?? null) === null || is_int($data[$key]) ? ($data[$key] ?? null) : throw new InvalidArgumentException("Detection: {$key} must be an integer or null.");

        return new self(
            herd: $bool('herd'),
            herdPro: $bool('herd_pro'),
            herdMail: $bool('herd_mail'),
            herdSmtpPort: $int('herd_smtp_port'),
            postgres: $bool('postgres'),
            fluxPro: $bool('flux_pro'),
            claude: $bool('claude'),
            host: $string('host'),
            scheme: $string('scheme'),
            port: $int('port'),
        );
    }

    /**
     * The snapshot as WizardState and HerdState store it (CD-15).
     *
     * @return array{herd: bool, herd_pro: bool, herd_mail: bool, herd_smtp_port: int|null, postgres: bool, flux_pro: bool, claude: bool, host: string, scheme: string, port: int|null}
     */
    public function toArray(): array
    {
        return [
            'herd' => $this->herd,
            'herd_pro' => $this->herdPro,
            'herd_mail' => $this->herdMail,
            'herd_smtp_port' => $this->herdSmtpPort,
            'postgres' => $this->postgres,
            'flux_pro' => $this->fluxPro,
            'claude' => $this->claude,
            'host' => $this->host,
            'scheme' => $this->scheme,
            'port' => $this->port,
        ];
    }
```

`app/Setup/Detector.php`: `detect()` takes the request's port:

```php
     * @param  string  $scheme  the scheme the site is served over now
     * @param  int|null  $requestPort  the port of the current request; kept without Herd unless it is the scheme's
     *                                 default (CD-15, g1-17)
     */
    public function detect(?string $requestHost = null, string $scheme = 'http', ?int $requestPort = null): Detection
    {
```

```php
            host: ($requestHost !== null ? SetupHost::normalise($requestHost) : null) ?? $this->cliHost(),
            scheme: $scheme,
            port: $herd === null && $requestPort !== ($scheme === 'https' ? 443 : 80) ? $requestPort : null,
        );
```

`app/Setup/Wizard/WizardState.php`:
- Replace the class docblock:

```php
/**
 * What the browser wizard knows between requests (RL-5): the pinned host, the answers (with the password's hash,
 * never the password), the detection snapshot begin() took (CD-15), the results of steps 1–8 and the notes. Step 9
 * deletes it, together with the per-step files of the detached steps in STEPS. A file without the snapshot (written
 * before M7b) is not a state this class reads: the runner deletes it and the wizard starts at the questions again.
 */
```

- Constructor:

```php
    public function __construct(public string $host, public Answers $answers, public Detection $detection, public array $results = [], public array $notes = []) {}
```

- In `load()`, replace the shape check and the `return`:

```php
        if (! is_string($data['host'] ?? null) || ! is_array($data['answers'] ?? null) || ! is_array($data['detection'] ?? null)
            || ! is_array($data['results'] ?? null) || ! is_array($data['notes'] ?? null)) {
            throw new InvalidArgumentException('Setup state: unexpected shape.');
        }

        /** @var array<string, mixed> $answers */
        $answers = $data['answers'];

        return new self($data['host'], Answers::fromState($answers), Detection::fromArray($data['detection']), self::resultsFromState($data['results']), self::notesFromState($data['notes']));
```

- In `save()`, add `'detection' => $this->detection->toArray(),` right after `'answers' => $this->answers->toState(),`.
- Replace `context()`:

```php
    /** The setup context for the next step, on the snapshot begin() took (CD-15): no step request detects again. */
    public function context(string $basePath): SetupContext
    {
        $context = new SetupContext($basePath, $this->answers, $this->detection);
        $context->results = $this->results;
        $context->notes = $this->notes;

        return $context;
    }
```

`app/Setup/Wizard/HerdState.php`:
- Replace the class docblock's first sentence:

```php
/**
 * The Herd stage after the marker (RL-6): the sha256 of the one-time secret only the wizard's browser holds, the
 * pinned host, the public answers, the wizard's detection snapshot (CD-15), the results and notes of steps 1–9, when
 * the stage started, and once the detached Herd step has finished its result. The file holds no password hash
 * (RL-5): the super admin exists by now.
 */
```

- Add `public Detection $detection,` to the constructor right after `public Answers $answers,`.
- In `begin()`, add `detection: $context->detection,` after the `answers:` argument.
- In `load()`, add `|| ! is_array($data['detection'] ?? null)` to the shape check:

```php
        if (! is_string($data['secret_hash'] ?? null) || ! is_string($data['host'] ?? null) || ! is_array($data['answers'] ?? null)
            || ! is_array($data['detection'] ?? null) || ! is_array($data['results'] ?? null) || ! is_array($data['notes'] ?? null) || ! is_int($data['started_at'] ?? null)
            || (($data['result'] ?? null) !== null && ! is_array($data['result']))) {
```

  Then add `detection: Detection::fromArray($data['detection']),` after `answers: Answers::fromState($answers),`.
- In `save()`, add `'detection' => $this->detection->toArray(),` after the `'answers'` entry.
- Replace `context()`:

```php
    /** The setup context of the Herd stage, on the wizard's detection snapshot (CD-15). */
    public function context(string $basePath): SetupContext
    {
        $context = new SetupContext($basePath, $this->answers, $this->detection);
        $context->results = $this->results;
        $context->notes = $this->notes;

        return $context;
    }
```

`app/Setup/Wizard/HerdStage.php`: remove `use App\Setup\Detector;`. Task 3's `manualCommand()` now returns:

```php
        return app(HerdSiteStep::class)->manualCommand($state->context($basePath));
```

`app/Console/Commands/RunStepCommand.php`:
- Remove `use App\Setup\Detector;`.
- The signature becomes `public function handle(SetupRunner $runner): int`.
- Replace the context line:

```php
        // The detection the wizard took at the start (CD-15): a detached step never detects again.
        $context = $state->context($basePath);
```

`app/Setup/Steps/EnvironmentStep.php`: in `variables()`, replace Task 2's port line:

```php
        // Without Herd the app may run on a port (php artisan serve): the port the wizard was opened on, else the one
        // of an APP_URL on this host (g1-17).
        $port = $detection->port ?? (! $detection->herd && $currentHost === $host ? parse_url($current, PHP_URL_PORT) : null);
```

- [ ] **Step 4: Implement the launcher's pid (RL-8)**

`app/Setup/Wizard/StepLauncher.php`:

```php
    /**
     * Starts `skv:run-step $key --result=$resultFile`, logging to $logFile and writing its exit code to $exitFile;
     * false when it could not start. A launcher that runs a real process writes its pid into $pidFile first (RL-8).
     */
    public function start(string $basePath, string $key, string $resultFile, string $logFile, string $exitFile, ?string $pidFile = null): bool;
```

`tests/Fixtures/InlineStepLauncher.php`:
- The docblock becomes `/** Runs a "detached" step in-process and at once, so tests never spawn a process (RL-4); it writes no pid (RL-8). */`.
- `start()` takes the same parameters as the interface, `?string $pidFile = null` last. The body does not change.

`app/Setup/Wizard/DetachedStepLauncher.php`: replace the class docblock, the constant as Task 1 left it, the signature and the `run()` argv:

```php
/**
 * nohup + & (DEC-23): the child outlives the request and Herd's nginx/php-fpm restarts. Every value is its own
 * argument ("$1"…), never part of the script, so no path or key reaches the shell as code. The child gets the
 * toolchain's environment, so it reads the current .env itself (RL-12). The inner shell writes its own pid into the
 * pid file first (RL-8) and stays until it has written the exit code, so that pid lives exactly as long as the step.
 */
final readonly class DetachedStepLauncher implements StepLauncher
{
    /** Outer: $1 php, $2 key, $3 result, $4 log, $5 exit, $6 pid; inner: $0 php, $1 key, $2 result, $3 log, $4 exit, $5 pid. */
    private const string SCRIPT = 'nohup sh -c \'umask 077; echo $$ > "$5"; "$0" artisan skv:run-step "$1" --no-interaction --result="$2" > "$3" 2>&1; echo $? > "$4"\' "$1" "$2" "$3" "$4" "$5" "$6" > /dev/null 2>&1 &';
```

```php
    public function start(string $basePath, string $key, string $resultFile, string $logFile, string $exitFile, ?string $pidFile = null): bool
```

```php
                ->run(['sh', '-c', self::SCRIPT, 'sh', $php, $key, $resultFile, $logFile, $exitFile, $pidFile ?? '/dev/null'])
```

> Keep Task 1's `umask 077; ` at the start of the inner script, and keep its comment on g3-16 if it added one. The constant is in single quotes, so `$$` reaches the inner shell literally. Task 1's g3-16 test checks `str_starts_with($script, "nohup sh -c 'umask 077; ")`, which still holds.

- [ ] **Step 5: Implement the runner**

`app/Setup/Wizard/WizardRunner.php`:
- Extend the class docblock's last sentence:

```php
 * runs under the SetupLock, so a reload or a second tab never runs a step twice (§5.3.4). begin() detects the machine
 * once; every later request works on that snapshot from the state (CD-15).
```

- **`begin()`:** detect once, with the port. Replace the `$detection = …` line and the state's construction, and the wizard's own refusal message (g4-04):

```php
            $context = app(SetupContext::class);
            // The one detection of this setup (CD-15), with the port the wizard was opened on (g1-17).
            $port = parse_url('http://'.$requestHost, PHP_URL_PORT);
            $detection = $this->detector->detect($host, requestPort: is_int($port) ? $port : null);
```

```php
                throw ValidationException::withMessages(['start_with' => __('A super admin already exists, so setup does not add the demo data by default. Click Start setup again to add it anyway, or choose My own super admin.')]);
```

```php
            $state = new WizardState($host, $answers, $detection);
            $state->save($context->basePath);
```

- **`advance()`:** use the snapshot, and run the long step inside the try (g3-31):

```php
            $state = $this->current() ?? throw new RuntimeException(__('There is no setup in progress.'));
            $context = $state->context($basePath);
            $context->syncRuntimeConfig();
            $step = $this->nextStep($state);

            if (! $step instanceof SetupStep) {
                return null;
            }

            try {
                // A long step's completeness check runs here too, so whatever it throws is a failure, never a 500 (g3-31).
                if ($step->isLong()) {
                    return $this->advanceLong($state, $context, $step);
                }

                $result = $this->runner->runStep($context, $step);
            } catch (Throwable $exception) {
                return ['key' => $step->key(), 'state' => 'failed', 'message' => SafeMessage::of($exception)];
            }
```

- **`recheck()`:** refuse while the step's process lives. Insert after `if (! $state instanceof WizardState) { return; }`:

```php
            // RL-8: deleting the files under a live child would start a second one on the next poll.
            if ($this->isRunning($basePath, $key)) {
                throw new RuntimeException(__('This step is still running.'));
            }
```

- **`finish()`:** new docblock; the snapshot; everything after step 9 recorded before it is thrown (g3-32):

```php
    /**
     * Step 9 in this request, then step 10 detached (RL-6); returns the secret the page polls with. Once step 9 wrote
     * the marker the gate is closed, so what fails after it is recorded in the marker before it is thrown (g3-32).
     */
```

```php
            $context = $state->context($basePath);
            $context->syncRuntimeConfig();
            $this->runner->runStep($context, app(MarkerStep::class));

            try {
                [$herd, $secret] = HerdState::begin($basePath, $context, $state->host);
                $root = rtrim($basePath, '/');
                $started = $this->launcher->start($basePath, 'herd-site', $root.'/'.HerdState::PATH, $root.'/'.HerdState::LOG, $root.'/'.HerdState::EXIT);

                if (! $started) {
                    $this->launchFailed($herd, $context, $root);
                }
            } catch (Throwable $exception) {
                $this->strand($context, $exception);

                throw $exception;
            }

            return $secret;
```

- **`settleWithoutHttps()` and `confirmHerd()`:** `$context = $herd->context($basePath);` and `$context = $current->context($basePath);`.
- **`strand()`:** add after `launchFailed()` (Task 3):

```php
    /**
     * g3-32: the Herd stage could not begin after step 9 closed the gate, so the page cannot retry. The Herd stage's
     * files go, and the marker says what the Herd step did not do: its command left to run and the HTTPS consequence.
     */
    private function strand(SetupContext $context, Throwable $exception): void
    {
        HerdState::forget($context->basePath);
        $command = app(HerdSiteStep::class)->manualCommand($context);
        $message = SafeMessage::of($exception);
        $context->results['herd-site'] = $command === null ? StepResult::skipped($message) : StepResult::manual($command, $message);
        $this->runner->applyHttpsConsequence($context);
        $this->runner->finish($context, null);
    }
```

- **`advanceLong()`:** the exit file first (g3-11), a live pid keeps the step running (RL-8), and the pid file goes to the launcher. The new parts:

```php
        $basePath = $context->basePath;
        $key = $step->key();
        $resultFile = $this->stepFile($basePath, $key, 'json');
        // The exit file before the result (g3-11): the child writes its result first, so a result that appears
        // between the two reads is still read, never taken for a step that stopped without one.
        $exited = is_file($this->stepFile($basePath, $key, 'exit'));

        if (is_file($resultFile)) {
```

  (The result branch stays.)

```php
        if ($exited) {
            return $this->record($state, $context, $key, StepResult::manual($this->fallbackCommand($key), __('The step stopped without a result; see :log.', ['log' => WizardState::STEPS.'/'.$key.'.log'])));
        }

        $started = $this->stepFile($basePath, $key, 'started');

        if (is_file($started)) {
            // RL-8: a step whose process lives is running, however long its log has been quiet.
            if ($this->isRunning($basePath, $key)) {
                return ['key' => $key, 'state' => 'running', 'message' => ''];
            }

            $log = $this->stepFile($basePath, $key, 'log');
```

  (The stall rule and the completeness check stay.)

```php
        // Its mtime says when the step started; the launcher's shell writes its pid into it (RL-8).
        StateFile::write($started, '');

        if (! $this->launcher->start($basePath, $key, $resultFile, $this->stepFile($basePath, $key, 'log'), $this->stepFile($basePath, $key, 'exit'), $started)) {
```

- **`LIVE_PID_SECONDS`:** add it right after `STALL_SECONDS`:

```php
    /** RL-8: a pid in `<step>.started` older than four hours is no longer trusted; the stall rule decides again. */
    private const int LIVE_PID_SECONDS = 14400;
```

- **`isRunning()`:** add it right before `record()`:

```php
    /**
     * RL-8: whether the process the launcher's shell wrote into `<step>.started` still runs. It does not once the exit
     * file exists, when the file holds no pid (the inline launcher, or a shell that never started), or without posix.
     * Nor once the step started LIVE_PID_SECONDS ago: a shell that died without writing the exit file (a reboot, an
     * OOM kill) leaves its pid behind, and another process of this user can get that pid later. The shell's `echo $$`
     * refreshes the file's mtime at launch, so the bound counts from the step's start.
     */
    private function isRunning(string $basePath, string $key): bool
    {
        $started = $this->stepFile($basePath, $key, 'started');

        if (! is_file($started) || is_file($this->stepFile($basePath, $key, 'exit')) || ! function_exists('posix_kill')) {
            return false;
        }

        if (Date::now()->getTimestamp() - (int) @filemtime($started) >= self::LIVE_PID_SECONDS) {
            return false;
        }

        $pid = (int) strtok((string) @file_get_contents($started), "\n");

        // 0 would signal this process group, and 1 is launchd or init: neither is a step.
        return $pid > 1 && posix_kill($pid, 0);
    }
```

> `Date` is used by the stall rule and the live-pid bound. The live-pid test travels 31 minutes, far below `LIVE_PID_SECONDS`. The existing dataset rows that write `(string) time()` into `.started` stay valid, because a Unix timestamp is far above any pid limit (99998 on macOS, at most 2^22 on Linux), so `posix_kill()` reports no such process.

- [ ] **Step 6: Implement the page**

`resources/views/pages/setup/wizard/wizard.php` (as Task 2 left it):
- Add `use App\Setup\Marker;`, `use App\Setup\SetupContext;` and `use App\Tenancy\Hosts;`.
- Replace the `$stage` property and add two properties after it:

```php
    /** questions | running | review | herd | stranded (step 9 is done, the Herd stage could not begin: g3-32) */
    #[Locked]
    public string $stage = 'questions';

    /**
     * Whether the user picked "Start with" (g4-04). Only then does the page send start_with, so begin() can refuse the
     * built-in demo default next to an own super admin (DEC-30 (29)); a refusal counts as the pick for the next try.
     */
    #[Locked]
    public bool $startWithChosen = false;

    /** The login page a stranded setup offers instead of "Try again", which would meet the closed gate (g3-32). */
    #[Locked]
    public ?string $loginUrl = null;
```

- Add after `mount()`:

```php
    public function updatedStartWith(): void
    {
        $this->startWithChosen = true;
    }
```

- Replace the body of `start()` after its `stage` guard:

```php
        $input = [
            'app_name' => $this->appName,
            'database' => $this->database,
            'super_admin_email' => $this->startWith === 'super_admin' ? $this->superAdminEmail : null,
            'super_admin_password' => $this->startWith === 'super_admin' && $this->superAdminPassword !== '' ? $this->superAdminPassword : null,
            'modules' => $this->modules,
            'https' => $this->https,
            'playwright' => $this->playwright,
            'claude' => $this->claude,
        ];

        // The preselected "Demo data" is no choice: without start_with, begin() applies DEC-30 (29) (g4-04).
        if ($this->startWithChosen || $this->startWith !== 'demo') {
            $input['start_with'] = $this->startWith;
        }

        try {
            $runner->begin($input, request()->getHttpHost());
        } catch (ValidationException $exception) {
            $this->startWithChosen = $this->startWithChosen || array_key_exists('start_with', $exception->errors());

            throw ValidationException::withMessages($this->formErrors($exception->errors()));
        } catch (Throwable $exception) {
            // A lock or state file that cannot be written is a message on the form, not a 500 (g3-30).
            $this->addError('host', SafeMessage::of($exception));

            return;
        } finally {
            $this->superAdminPassword = '';
        }

        $this->stage = 'running';
```

- In `advance()` and `recheck()`, catch `Throwable` instead of `RuntimeException` (g3-31).
- Replace `finishSetup()`:

```php
    private function finishSetup(WizardRunner $runner): void
    {
        try {
            $this->herdSecret = $runner->finish();
            $this->stage = 'herd';
        } catch (Throwable $exception) {
            $this->failure = SafeMessage::of($exception);

            // Step 9 wrote the marker and closed the gate: "Try again" would answer 404, so the login page is offered.
            if (Marker::exists(app(SetupContext::class))) {
                $this->stage = 'stranded';
                $this->loginUrl = Hosts::url(Hosts::apex(), '/login');
            }
        }
    }
```

> A non-namespaced component file needs no `use RuntimeException;` or `use Throwable;`. Do not add them: they raise "The use statement with non-compound name … has no effect". Hosts::apex() reads the configuration that `finish()` synced from `.env`, so the link goes to the apex the setup wrote.

`resources/views/pages/setup/wizard/wizard.blade.php`: insert a branch between the running/review branch's failure callout and the `@else` of the Herd stage:

```blade
        @if ($failure !== null)
            <flux:callout data-test="setup-failure" variant="danger" icon="x-circle" :heading="__('Setup stopped')" :text="$failure">
                <x-slot name="actions">
                    <flux:button wire:click="retry">{{ __('Try again') }}</flux:button>
                </x-slot>
            </flux:callout>
        @endif
    @elseif ($stage === 'stranded')
        {{-- g3-32: the marker exists, so this page answers no further update; it links out instead. --}}
        <div data-test="setup-stranded" class="space-y-4">
            <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Setup is done, but the Herd step could not start.')" :text="$failure" />
            <flux:text>{{ __('Run php artisan skv:doctor whenever you want to check the installation.') }}</flux:text>
            <flux:button variant="primary" :href="$loginUrl" data-test="setup-login">{{ __('Go to the login page') }}</flux:button>
        </div>
    @else
```

Lang keys, in both files at their `sort()` positions. "Run php artisan skv:doctor whenever you want to check the installation." and "Go to the login page" exist already:

| Key | nl |
|---|---|
| `A super admin already exists, so setup does not add the demo data by default. Click Start setup again to add it anyway, or choose My own super admin.` | `Er bestaat al een superbeheerder, dus de installatie voegt de demodata niet standaard toe. Klik nogmaals op Installatie starten om ze toch toe te voegen, of kies Mijn eigen superbeheerder.` |
| `Setup is done, but the Herd step could not start.` | `De installatie is klaar, maar de Herd-stap kon niet starten.` |
| `This step is still running.` | `Deze stap loopt nog.` |

In `en.json` each value equals its key. The nl values use CD-12's "installatie", "Installatie starten" and "Mijn eigen superbeheerder" as `lang/nl.json` already has them.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Setup tests/Feature/Console tests/Feature/Auth tests/Feature/LangCoverageTest.php tests/Arch`

Expected: PASS. If another test still builds a `WizardState` or `HerdState` the old way, or calls `context($basePath, $detection)`, give it the detection the same way (`grep -rn "new WizardState\|->context(" tests app`).

- [ ] **Step 8: Verify by mutation**

Revert each change locally, run the named test, and expect it to fail. Then restore the change.

1. **`advanceLong()`:** drop the `isRunning()` branch. Expect 'keeps reporting a quiet detached step as running…' to fail.
2. **`recheck()`:** drop the `isRunning()` refusal. Expect 'refuses a re-check while the step's process lives…' to fail.
3. **`advance()`:** put the long branch back outside the try. Expect 'reports a long step whose completion check throws as failed' to fail.
4. **`finish()`:** remove `$this->strand($context, $exception);`. Expect both g3-32 cases to fail (the marker keeps `https: true`; the page shows "Try again").
5. **`start()`:** always send `start_with`. Expect the g4-04 page case to fail.
6. **`GitStep::run()`:** make it throw first. Expect 'runs one step from the wizard state and writes its result (RL-4, g4-19)' to fail; before this task it passed with `manual`.
7. **`EnvironmentStep`:** drop `$detection->port ??`. Expect 'writes the port the wizard was opened on into APP_URL without Herd' to fail.
8. **`isRunning()`:** drop the `LIVE_PID_SECONDS` check. Expect 'stops trusting the pid four hours after the step started' to fail: `advance()` reports `running` and `recheck()` throws "This step is still running.".

- [ ] **Step 9: The gate, then commit**

```bash
composer fix
composer check
composer test:pgsql
php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser
npm run build && composer test:browser
npm run build:setup && git diff --exit-code resources/setup/setup.css
php artisan config:clear
```

The wizard's Blade view changed, so the stylesheet is rebuilt. The stranded branch uses classes the page already has; in the scratch clone, `build:setup` left `resources/setup/setup.css` unchanged. If it changes, add it to the commit. `composer test:browser` covers the wizard on SQLite. g4-04 does not change either browser path:
- The demo case leaves the radio alone, but its database has no super admin, so `begin()` does not refuse the default.
- The PostgreSQL case clicks `@setup-start-super_admin`, which sends `start_with`.

```bash
git add app/Setup/Detection.php app/Setup/Detector.php app/Setup/Wizard app/Setup/Steps/EnvironmentStep.php \
    app/Console/Commands/RunStepCommand.php resources/views/pages/setup/wizard resources/setup/setup.css \
    tests/Fixtures/InlineStepLauncher.php lang/nl.json lang/en.json tests/Feature/Setup tests/Feature/Console/RunStepCommandTest.php
git commit -m "fix: detect once per wizard run, track live steps and keep the wizard usable after a failure

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 5: Setup steps and seeders (g1-07, g1-08, g1-32, g2-11, g2-13, g2-15, L-56, g1-23, g2-18, g2-19, g2-26, g2-28)

**Files:**
- Modify:
  - `app/Authorization/SetSuperAdmin.php` (`createNew()`, RL-9)
  - `app/Setup/Steps/DatabaseStep.php` (g1-07, RL-9, g2-15)
  - `app/Console/Commands/CreateSuperAdminCommand.php` (RL-9)
  - `database/seeders/DemoSeeder.php` (g2-11, RL-9)
  - `app/Setup/Steps/HerdSiteStep.php` (g1-08)
  - `app/Setup/Answers.php` (g1-32)
  - `app/Setup/Steps/EnvironmentStep.php` (`missingSecrets()`, RL-10)
  - `app/Setup/Support/Bootstrap.php`, `app/Setup/Doctor/Checks/BackupPasswordCheck.php` (RL-10)
  - `app/Setup/Support/EnvFile.php` (g2-15)
  - `lang/nl.json`, `lang/en.json`
- Test:
  - `tests/Feature/Authorization/SetSuperAdminTest.php`, `tests/Feature/Seeders/DemoSeederTest.php`
  - `tests/Feature/Setup/Steps/DatabaseStepTest.php`, `tests/Feature/Console/CreateSuperAdminCommandTest.php`
  - `tests/Feature/Setup/Steps/HerdSiteStepTest.php`, `tests/Feature/Setup/AnswersTest.php`
  - `tests/Feature/Setup/Steps/EnvironmentStepTest.php`, `tests/Feature/Setup/Doctor/ChecksTest.php`
  - `tests/Feature/Setup/EnvFileTest.php`, `tests/Feature/Setup/SetupRunnerTest.php`, `tests/Feature/Setup/InstallPreflightTest.php`

**Interfaces:**
- Consumes:
  - `SetSuperAdmin::handleCreated(User $user): void`, `SetSuperAdmin::handle(User $user, bool $isSuperAdmin): void`
  - `EnvironmentStep::GENERATED` (`['HEALTH_CHECK_KEY' => 40, 'BACKUP_ARCHIVE_PASSWORD' => 32]`)
  - `EnvFile::all(): array<string, string>`, `EnvFile::set(array $variables, bool $overwrite = true): void`
  - `DoctorContext::env(): EnvFile`
  - from Task 1: `StateFile::write(string $path, string $contents, int $mode = 0600)` in `DatabaseStep::writeEnvironment()`, and `ProjectFile::write()` in `HerdManifestStep`. Task 5 leaves both alone.
  - test helpers: `setupSandbox()`, `recordedActivity()`, `badgeRows()` (file-local in `SetSuperAdminTest`), `Tests\Fixtures\FailureSwitch`
- Produces:
  - `SetSuperAdmin::createNew(array{name: string, email: string, password: string, locale?: Locale} $attributes): User`. In one `DB::transaction()`, it inserts the user with `organisation_id = null`, `is_super_admin = true` and `status = Active`, then calls `handleCreated()`. Callers: `DatabaseStep`, `skv:create-super-admin` and `DemoSeeder` (RL-9).
  - `EnvironmentStep::missingSecrets(EnvFile $env): array<string, string>`: a fresh `Str::random(<length>)` for every blank `GENERATED` key. It never writes. Used by `EnvironmentStep::isComplete()`/`run()`, `Bootstrap::run()` (production half) and `BackupPasswordCheck::fix()` (RL-10).
  - `HerdSiteStep::isComplete()`: with HTTPS chosen, it also requires `.env` `APP_URL === 'https://{folder}.{tld}'`.
  - `DatabaseStep`:
    - private `passwordIsCurrent(Answers, mixed $stored): bool`
    - `isComplete()` is true for a super-admin start when the account exists with the flag and either no hash is given or it already holds that hash
    - `createSuperAdmin()` never re-applies that hash
  - `Answers` validates `super_admin_password` with `Password::default()`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Authorization/SetSuperAdminTest.php`: add the imports `App\Enums\Locale`, `App\Models\Activity`, `App\Tenancy\Facades\Tenant`, `Illuminate\Support\Facades\Hash` and `Tests\Fixtures\FailureSwitch`. `ActivityEvent`, `UserStatus` and `User` are imported already. Never write `use RuntimeException;`. Then add:

```php
it('creates a new super admin with the badge and one grant, or nothing at all (RL-9, DEC-30 (21))', function (): void {
    // A holder with a typed property: PHPStan would type a captured `true` as always true.
    $audit = new FailureSwitch(fails: true);
    Activity::creating(function (Activity $activity) use ($audit): void {
        if ($audit->fails && $activity->event === ActivityEvent::SuperAdminGranted->value) {
            throw new RuntimeException('The audit log is unavailable.');
        }
    });
    $attributes = ['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'correct-horse-battery'];

    expect(fn (): User => app(SetSuperAdmin::class)->createNew($attributes))
        ->toThrow(RuntimeException::class, 'The audit log is unavailable.');
    expect(Tenant::bypass(fn (): bool => User::query()->where('email', 'owner@example.com')->exists()))->toBeFalse();

    $audit->fails = false;
    $user = app(SetSuperAdmin::class)->createNew($attributes);

    expect($user->isSuperAdmin())->toBeTrue()
        ->and($user->organisation_id)->toBeNull()
        ->and($user->status)->toBe(UserStatus::Active)
        ->and($user->locale)->toBe(Locale::default())
        ->and(Hash::check('correct-horse-battery', $user->password))->toBeTrue()
        ->and(badgeRows($user))->toBe([null])
        ->and(recordedActivity(ActivityEvent::SuperAdminGranted)->sole()->subject_id)->toBe($user->id);
});
```

`tests/Feature/Seeders/DemoSeederTest.php`: import `App\Models\Activity`. Then add:

```php
it('creates the demo super admin with its badge and grant, or not at all (DEC-30 (21), RL-9, g2-11)', function (): void {
    Activity::creating(function (Activity $activity): void {
        if ($activity->event === ActivityEvent::SuperAdminGranted->value) {
            throw new RuntimeException('The audit log is unavailable.');
        }
    });
    $apex = config('tenancy.apex_domain');

    expect(fn (): int => Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]))
        ->toThrow(RuntimeException::class, 'The audit log is unavailable.');
    expect(Tenant::bypass(fn (): bool => User::query()->where('email', "superadmin@{$apex}")->exists()))->toBeFalse();
});
```

`tests/Feature/Setup/Steps/DatabaseStepTest.php`:
- import `App\Enums\UserStatus`
- in 'creates the real super admin instead of demo data when asked (§5.3.3 step 2)', **rewrite** the tail from the comment "Answers that still carry a password…" (lines ~116–121). The same answers now find the step complete, a new password does not, and a re-run without one still does:

```php
        ->and(DB::connection('setup')->table('activity_log')->where('event', 'user.super_admin_granted')->where('subject_id', $owner->id)->count())->toBe(1)
        // The same answers carry the hash the account holds now (a wizard re-check): nothing is left to apply (g1-07).
        ->and(new DatabaseStep()->isComplete($context))->toBeTrue();

    $installed = $context->answers->toPublicArray();
    $context->answers = Answers::fromArray([
        'database' => 'sqlite', 'start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com', 'super_admin_password' => 'another-horse-battery',
    ], Detection::none(), $context->folderName());

    // A new password is a new hash: it still has to be applied.
    expect(new DatabaseStep()->isComplete($context))->toBeFalse();

    $context->answers = Answers::fromArray(['database' => 'sqlite'], Detection::none(), $context->folderName(), $installed);

    expect(new DatabaseStep()->isComplete($context))->toBeTrue();
});
```

- add, after 'replaces the password of an existing account when one is given':

```php
it('never applies the same password hash twice: one PasswordChanged, the remember token kept (§5.3.3 step 2, g1-07)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['database' => 'sqlite'], Detection::none(), $context->folderName());
    new DatabaseStep()->run($context);
    $email = 'superadmin@'.Hosts::apex();
    // One Answers object, as a wizard re-check or a resumed run has: the state file keeps the hash, not the password.
    $context->answers = Answers::fromArray([
        'database' => 'sqlite', 'start_with' => 'super_admin', 'super_admin_email' => $email, 'super_admin_password' => 'correct-horse-battery',
    ], Detection::none(), $context->folderName());

    new DatabaseStep()->run($context);
    $afterFirst = DB::connection('setup')->table('users')->where('email', $email)->sole();
    $completeAfterFirst = new DatabaseStep()->isComplete($context);
    new DatabaseStep()->run($context);
    $afterSecond = DB::connection('setup')->table('users')->where('email', $email)->sole();

    expect($completeAfterFirst)->toBeTrue()
        ->and($afterSecond->password)->toBe($afterFirst->password)
        ->and($afterSecond->remember_token)->toBe($afterFirst->remember_token)
        ->and(DB::connection('setup')->table('activity_log')->where('event', 'security.password_changed')->where('subject_id', $afterSecond->id)->count())->toBe(1);
});
```

- **rewrite** 'refuses to make a pending user the super admin (§5.3.3 step 2)' as a dataset over both inactive statuses (g2-18). The demo's invitee is pending; its member is disabled here:

```php
it('refuses to make a user who is not active the super admin (§5.3.3 step 2, g2-18)', function (string $local, UserStatus $status): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['database' => 'sqlite'], Detection::none(), $context->folderName());
    new DatabaseStep()->run($context);
    $email = $local.'@demo1.'.Hosts::apex();
    DB::connection('setup')->table('users')->where('email', $email)->update(['status' => $status->value]);
    $context->answers = Answers::fromArray([
        'database' => 'sqlite',
        'start_with' => 'super_admin',
        'super_admin_email' => $email,
        'super_admin_password' => 'correct-horse-battery',
    ], Detection::none(), $context->folderName());

    expect(fn (): mixed => new DatabaseStep()->run($context))
        ->toThrow(RuntimeException::class, __('Only an active user can become a super admin; :email is :status.', ['email' => $email, 'status' => $status->label()]))
        ->and((bool) DB::connection('setup')->table('users')->where('email', $email)->value('is_super_admin'))->toBeFalse();
})->with([
    'pending' => ['invited', UserStatus::PendingActivation],
    'disabled' => ['user', UserStatus::Disabled],
]);
```

- In 'refuses a committed PostgreSQL schema dump and leaves .env alone (§7.3, RL-15)' (g2-19), add this line right after the `$before = …` line. A regressed guard then fails fast on a refused connection instead of creating `<uuid>` databases on the developer's server:

```php
    // An unreachable server: a regressed guard fails on the connection instead of creating databases (g2-19).
    config(['database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => 1]);
```

`tests/Feature/Console/CreateSuperAdminCommandTest.php`: import `App\Enums\UserStatus`, then **rewrite** 'refuses a user who is not active (RL-9)' as a dataset (g2-18):

```php
it('refuses a user who is not active (RL-9, g2-18)', function (UserStatus $status): void {
    $user = User::factory()->for(Organisation::factory()->create(['slug' => 'acme']))->create(['email' => 'inactive@example.com', 'status' => $status]);

    $exit = Artisan::call('skv:create-super-admin', ['email' => 'inactive@example.com', '--no-interaction' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain(__('Only an active user can become a super admin; :email is :status.', ['email' => 'inactive@example.com', 'status' => $status->label()]))
        ->and($user->fresh()?->isSuperAdmin())->toBeFalse();
})->with([
    'pending' => [UserStatus::PendingActivation],
    'disabled' => [UserStatus::Disabled],
]);
```

`tests/Feature/Setup/Steps/HerdSiteStepTest.php`:
- **rewrite** 'is complete once served and secured, and not when herd fails (§5.3.1)' so that each row also sets APP_URL (g1-08):

```php
it('is complete once served, secured and on the https APP_URL, and not when herd fails (§5.3.1, g1-08)', function (string $secured, bool $fails, string $appUrl, bool $complete): void {
    $context = setupSandbox($this->detection);
    $context->answers = Answers::fromArray(['https' => true], $this->detection, $context->folderName());
    new EnvFile($context->path('.env'))->set(['APP_URL' => str_replace('{name}', Str::lower($context->folderName()), $appUrl)]);

    Process::fake([
        "*herd' 'links'*" => Process::result(''),
        "*herd' 'paths'*" => $fails
            ? fn () => throw new ProcessTimedOutException(new SymfonyTimeout(new SymfonyProcess(['herd']), SymfonyTimeout::TYPE_GENERAL), Process::result(''))
            : Process::result(json_encode([dirname($context->basePath)], JSON_THROW_ON_ERROR)),
        "*herd' 'secured'*" => Process::result(str_replace('{name}', $context->folderName(), $secured)),
    ]);

    expect(new HerdSiteStep()->isComplete($context))->toBe($complete);
})->with([
    'secured' => ["| {name}.test | 2027-01-01 |\n", false, 'https://{name}.test', true],
    'secured, APP_URL still http' => ["| {name}.test | 2027-01-01 |\n", false, 'http://{name}.test', false],
    'not secured' => ['', false, 'https://{name}.test', false],
    'herd times out' => ["| {name}.test | 2027-01-01 |\n", true, 'https://{name}.test', false],
]);
```

- add:

```php
it('rewrites an http APP_URL of a site Herd already secured instead of skipping (g1-08, RL-16)', function (): void {
    $context = setupSandbox($this->detection);
    $context->answers = Answers::fromArray(['https' => true], $this->detection, $context->folderName());
    $name = $context->folderName();
    // Secured by hand, or by a detached run that died before it wrote APP_URL.
    new EnvFile($context->path('.env'))->set(['APP_URL' => "http://{$name}.test"]);

    Process::fake([
        "*herd' 'links'*" => Process::result(''),
        "*herd' 'paths'*" => Process::result(json_encode([dirname($context->basePath)], JSON_THROW_ON_ERROR)),
        "*herd' 'secured'*" => Process::result("| {$name}.test | 2027-01-01 |\n"),
        "*herd' 'isolated'*" => Process::result(''),
        "*php' '-r'*" => Process::result('8.4.13'),
    ]);

    $completeBefore = new HerdSiteStep()->isComplete($context);
    $result = new HerdSiteStep()->run($context);

    expect($completeBefore)->toBeFalse()
        ->and($result->message)->toStartWith(HerdSiteStep::SECURED)
        ->and(new EnvFile($context->path('.env'))->get('APP_URL'))->toBe("https://{$name}.test")
        ->and(new HerdSiteStep()->isComplete($context))->toBeTrue();
    Process::assertDidntRun(fn ($process): bool => str_contains(implode(' ', (array) $process->command), "secure {$name}"));
});
```

> The sandbox folder is a UUID, which is lower case already. `HerdSiteStep::domain()` lower-cases it anyway, so `"https://{$name}.test"` is the address `secured()` writes.

`tests/Feature/Setup/AnswersTest.php`: import `Illuminate\Validation\Rules\Password`, then add (g1-32):

```php
it('applies the app\'s password defaults to the super admin password (§5.3.2, g1-32)', function (): void {
    // AppServiceProvider sets the defaults again when the next test boots the app.
    Password::defaults(fn (): Password => Password::min(12));
    $answers = fn (string $password): Answers => Answers::fromArray([
        'start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com', 'super_admin_password' => $password,
    ], Detection::none(), 'my-app');

    expect(fn (): Answers => $answers('abcdefgh'))->toThrow(ValidationException::class, 'at least 12 characters')
        ->and($answers('abcdefghijkl')->superAdminPasswordHash)->not->toBeNull();
});
```

`tests/Feature/Setup/Steps/EnvironmentStepTest.php`:
- **rewrite** 'keeps an existing archive password and is incomplete while it is blank (§4.9)' as a dataset over every generated secret (g2-26):

```php
it('keeps an existing generated secret, is incomplete while it is blank and generates it again (§4.9, g2-26)', function (string $key, int $length): void {
    $context = setupSandbox();
    $env = new EnvFile($context->path('.env'));
    $env->set([$key => str_repeat('p', $length)]);

    new EnvironmentStep()->run($context);

    expect($env->get($key))->toBe(str_repeat('p', $length))
        ->and(new EnvironmentStep()->isComplete($context))->toBeTrue();

    $env->set([$key => '']);

    expect(new EnvironmentStep()->isComplete($context))->toBeFalse();

    new EnvironmentStep()->run($context);

    expect(strlen((string) $env->get($key)))->toBe($length)
        ->and($env->get($key))->not->toBe(str_repeat('p', $length))
        ->and(new EnvironmentStep()->isComplete($context))->toBeTrue();
})->with(fn (): array => collect(EnvironmentStep::GENERATED)->map(fn (int $length, string $key): array => [$key, $length])->all());
```

- add (RL-10):

```php
it('generates a value only for the blank secrets, at their length, and writes nothing itself (RL-10, L-56)', function (): void {
    $env = new EnvFile(setupSandbox()->path('.env'));
    $env->set(['HEALTH_CHECK_KEY' => str_repeat('k', 40), 'BACKUP_ARCHIVE_PASSWORD' => '']);

    $first = EnvironmentStep::missingSecrets($env);
    $second = EnvironmentStep::missingSecrets($env);

    expect(array_keys($first))->toBe(['BACKUP_ARCHIVE_PASSWORD'])
        ->and(strlen($first['BACKUP_ARCHIVE_PASSWORD'] ?? ''))->toBe(32)
        ->and($second['BACKUP_ARCHIVE_PASSWORD'] ?? null)->not->toBe($first['BACKUP_ARCHIVE_PASSWORD'] ?? null)
        ->and($env->get('BACKUP_ARCHIVE_PASSWORD'))->toBe('')
        ->and($env->get('HEALTH_CHECK_KEY'))->toBe(str_repeat('k', 40));
});
```

`tests/Feature/Setup/Doctor/ChecksTest.php`: in 'needs an archive password while the backups module is on, and writes one with --fix (CD-10)', make the repair's scope explicit. It writes the archive password only, not the blank `HEALTH_CHECK_KEY` that `.env.example` ships (L-56):
- replace `new EnvFile($this->sandbox->path('.env'))->set(['BACKUP_ARCHIVE_PASSWORD' => '']);` with `new EnvFile($this->sandbox->path('.env'))->set(['BACKUP_ARCHIVE_PASSWORD' => '', 'HEALTH_CHECK_KEY' => '']);`
- append `->and(new EnvFile($this->sandbox->path('.env'))->get('HEALTH_CHECK_KEY'))->toBe('')` to its `expect()` chain

`tests/Feature/Setup/EnvFileTest.php`: add (g2-15):

```php
it('words its refusals in the active language (g2-15)', function (string $file, array $variables, string $dutch): void {
    $path = setupSandbox()->path($file);
    app()->setLocale('nl');

    expect(fn () => new EnvFile($path)->set($variables))->toThrow(RuntimeException::class, str_replace('{path}', $path, $dutch));
})->with([
    'a missing file' => ['missing.env', ['APP_NAME' => 'x'], 'Het omgevingsbestand [{path}] bestaat niet.'],
    'a bad name' => ['.env', ['app_name' => 'x'], 'De naam van een omgevingsvariabele mag alleen hoofdletters, cijfers en underscores bevatten.'],
    'a line break' => ['.env', ['APP_NAME' => "Shop\nX"], 'De waarde voor [APP_NAME] bevat een regeleinde of een NUL-byte.'],
]);
```

`tests/Feature/Setup/SetupRunnerTest.php`: add three tests next to 'declares which steps run detached in the browser (A25, §5.3.1)' (g1-23):

```php
it('gives every step a key a step file may carry and a translated label (§5.3.1, g1-23)', function (string $key): void {
    /** @var array<string, string> $dutch */
    $dutch = json_decode((string) file_get_contents(lang_path('nl.json')), true, flags: JSON_THROW_ON_ERROR);
    $step = collect(app(SetupRunner::class)->steps())->firstOrFail(fn (SetupStep $step): bool => $step->key() === $key);

    app()->setLocale('en');
    $english = $step->label();
    app()->setLocale('nl');

    // WizardRunner names a step's files after its key and refuses anything else (stepFile()).
    expect($step->key())->toMatch('/^[a-z][a-z-]*$/')
        ->and(array_key_exists($english, $dutch))->toBeTrue()
        ->and($step->label())->toBe($dutch[$english] ?? null);
})->with(['environment', 'database', 'herd-manifest', 'branding', 'frontend', 'git', 'claude', 'doctor', 'marker', 'herd-site', 'summary']);

it('is complete right after a run that returned done, so a re-run skips it (§5.3.1, g1-23)', function (string $key): void {
    $context = setupSandbox();
    $step = collect(app(SetupRunner::class)->steps())->firstOrFail(fn (SetupStep $step): bool => $step->key() === $key);

    $result = $step->run($context);

    expect($result->status)->toBe('done')
        ->and($step->isComplete($context))->toBeTrue();
})->with(['environment', 'herd-manifest', 'branding', 'marker']);

it('never counts the doctor and the summary as complete: they report on every run (§5.3.1, g1-23)', function (string $key): void {
    $step = collect(app(SetupRunner::class)->steps())->firstOrFail(fn (SetupStep $step): bool => $step->key() === $key);

    expect($step->isComplete(setupSandbox()))->toBeFalse();
})->with(['doctor', 'summary']);
```

> `database` and `herd-site` are pinned by their own after-run tests (`DatabaseStepTest` 'migrates, syncs and seeds the demo on SQLite…' and `HerdSiteStepTest` 'rewrites an http APP_URL…' above). `frontend`, `git` and `claude` need real tool output and stay out of the "complete after done" dataset (notes, item 9).

`tests/Feature/Setup/InstallPreflightTest.php`: add (g2-28):

```php
it('never refuses the demo start an earlier install chose (DEC-30, g2-28)', function (): void {
    $context = setupSandbox();
    User::factory()->superAdmin()->create(['email' => 'owner@example.com']);
    $installed = ['start_with' => 'demo'];
    $answers = Answers::fromArray([], Detection::none(), $context->folderName(), $installed);

    expect($answers->startWith)->toBe('demo')
        ->and(new InstallPreflight($context)->refusesDemoDefault($answers, [], $installed, Detection::none()))->toBeFalse();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Authorization/SetSuperAdminTest.php tests/Feature/Seeders/DemoSeederTest.php tests/Feature/Setup tests/Feature/Console/CreateSuperAdminCommandTest.php`

Expected: FAIL.
- `createNew()` and `missingSecrets()` are undefined.
- The demo seeder leaves `superadmin@` behind after a failed grant.
- The same hash rotates the remember token and logs a second PasswordChanged.
- `isComplete()` is false with the same answers.
- `HerdSiteStep::isComplete()` is true while APP_URL is http.
- An 8-character password passes `Password::min(12)`.
- The Dutch EnvFile messages are English.

These pass already, because they are new coverage of existing behaviour; Step 11 mutates them:
- the g2-18 disabled rows
- the g2-19 guard test
- the g2-26 dataset
- the ChecksTest `HEALTH_CHECK_KEY` assertion
- the g1-23 tests
- the g2-28 test

- [ ] **Step 3: One super-admin creator (RL-9, g2-11, g2-13)**

`app/Authorization/SetSuperAdmin.php`:
- import `App\Enums\Locale` and `App\Enums\UserStatus`
- replace the last two lines of the class docblock ("A user without an organisation carries the flag at insert (W8), so handle() sees no change for it: handleCreated() attaches that new user's badge and records the grant.") with:

```php
 * A user without an organisation carries the flag at insert (W8), so handle() sees no change for it: createNew()
 * inserts a new super admin and completes it through handleCreated(), which attaches the badge and records the grant.
```

- add, right after `handle()`:

```php
    /**
     * Inserts a new super admin and completes it (RL-9, DEC-30 (21)). The flag, no organisation (W8) and the Active
     * status are forced, and the locale defaults to the app's. The insert and handleCreated() run in one
     * transaction, so the user never exists without the badge and the audit entry. The password may be plain or
     * a hash already; the hashed cast keeps a hash.
     *
     * @param  array{name: string, email: string, password: string, locale?: Locale}  $attributes
     */
    public function createNew(array $attributes): User
    {
        return DB::transaction(function () use ($attributes): User {
            $user = new User;
            $user->forceFill([
                'locale' => Locale::default(),
                ...$attributes,
                'organisation_id' => null,
                'is_super_admin' => true,
                'status' => UserStatus::Active,
            ])->save();

            $this->handleCreated($user);

            return $user;
        });
    }
```

`database/seeders/DemoSeeder.php`, replace `superAdmin()`:

```php
    private function superAdmin(SetSuperAdmin $setSuperAdmin): User
    {
        $email = 'superadmin@'.Hosts::apex();
        $existing = Tenant::bypass(fn (): ?User => User::query()->where('email', $email)->first());

        if ($existing instanceof User) {
            return $existing;
        }

        // One transaction with the badge and the grant (DEC-30 (21), RL-9): a failed grant leaves no half super admin.
        return $setSuperAdmin->createNew(['name' => 'Demo super admin', 'email' => $email, 'password' => self::PASSWORD]);
    }
```

`app/Console/Commands/CreateSuperAdminCommand.php`:
- in `handle()`, replace the `else` branch:

```php
            if ($existing instanceof User) {
                $this->promote($setSuperAdmin, $existing);
            } else {
                $setSuperAdmin->createNew($this->newUserAttributes($email));
            }
```

- replace `createUser()`:

```php
    /**
     * The name, address and password of a new super admin, validated before anything is written.
     * SetSuperAdmin::createNew() inserts the user with the flag and completes it in one transaction (RL-9).
     *
     * @return array{email: string, name: string, password: string}
     *
     * @throws ValidationException
     */
    private function newUserAttributes(string $email): array
    {
        $name = $this->option('name') ?? ($this->input->isInteractive() ? text(__('Name'), required: true) : 'Super admin');
        $password = $this->givenPassword() ?? ($this->input->isInteractive() ? password(__('Password'), required: true) : null);

        /** @var array{email: string, name: string, password: string} $valid */
        $valid = Validator::make(['email' => $email, 'name' => trim($name), 'password' => $password], [
            'email' => $this->emailRules(),
            'name' => $this->nameRules(),
            'password' => ['required', 'string', Password::default()],
        ])->validate();

        return $valid;
    }
```

- remove the now unused `use App\Enums\Locale;`. `DB` and `UserStatus` are still used.

`app/Setup/Steps/DatabaseStep.php`: Step 4 replaces `createSuperAdmin()`.

- [ ] **Step 4: The same password hash is applied once (g1-07)**

`app/Setup/Steps/DatabaseStep.php`:
- in `isComplete()`, replace everything from `// A password in the answers is applied on every run…` to the end of the `try` block:

```php
            if ($answers->startWith === 'demo') {
                return DB::connection(self::CONNECTION)->table('organisations')->whereIn('slug', DemoSeeder::SLUGS)->count() === count(DemoSeeder::SLUGS);
            }

            $superAdmin = DB::connection(self::CONNECTION)->table('users')
                ->where('email', $answers->superAdminEmail)
                ->where('is_super_admin', true)
                ->first(['password']);

            // A password in the answers is applied unless the account holds exactly its hash already (g1-07).
            return $superAdmin !== null && self::passwordIsCurrent($answers, $superAdmin->password);
```

- replace `createSuperAdmin()`, and add `passwordIsCurrent()` right after it:

```php
    /**
     * The real super admin (§5.3.3 step 2). An existing user is promoted. A new one is created by
     * SetSuperAdmin::createNew() (RL-9), so it never exists without the badge and the grant. A password given for an
     * existing account replaces its own, a demo account's shared one too. Its remember token changes,
     * PasswordChanged is recorded, and AuthenticateSession signs its other sessions out. A hash the account holds
     * already is never applied again: a wizard re-check or a resumed run carries the earlier run's hash (g1-07).
     */
    private function createSuperAdmin(Answers $answers): void
    {
        $email = (string) $answers->superAdminEmail;
        $setSuperAdmin = app(SetSuperAdmin::class);
        $existing = Tenant::bypass(fn (): ?User => User::query()->where('email', $email)->first());

        if ($existing instanceof User) {
            // SetSuperAdmin guards demotion only: a pending or disabled user could never sign in.
            if ($existing->status !== UserStatus::Active) {
                throw new RuntimeException(__('Only an active user can become a super admin; :email is :status.', ['email' => $email, 'status' => $existing->status->label()]));
            }

            DB::transaction(function () use ($answers, $existing, $setSuperAdmin): void {
                $setSuperAdmin->handle($existing, true);

                if (! self::passwordIsCurrent($answers, $existing->password)) {
                    // The hashed cast keeps a value that is already a hash (Answers hashes the password on entry, RL-5).
                    $existing->forceFill(['password' => $answers->superAdminPasswordHash, 'remember_token' => Str::random(60)])->save();
                    app(RecordActivity::class)->handle(ActivityEvent::PasswordChanged, $existing);
                }
            });

            return;
        }

        // A re-run keeps the earlier install's super admin without asking its password again (Answers, DEC-30).
        if ($answers->superAdminPasswordHash === null) {
            throw new RuntimeException(__('The super admin :email does not exist; pass super_admin_password to create it.', ['email' => $email]));
        }

        $setSuperAdmin->createNew([
            'name' => Str::headline(Str::before($email, '@')),
            'email' => $email,
            // The hashed cast keeps a value that is already a hash (Answers hashes the password on entry, RL-5).
            'password' => $answers->superAdminPasswordHash,
        ]);
    }

    /** No password to apply, or the account holds exactly the answers' hash already, applied by an earlier run (g1-07). */
    private static function passwordIsCurrent(Answers $answers, mixed $stored): bool
    {
        return $answers->superAdminPasswordHash === null
            || (is_string($stored) && hash_equals($stored, $answers->superAdminPasswordHash));
    }
```

- remove `use App\Enums\Locale;`, which only the old insert used. `UserStatus`, `Str` and `DB` stay.
- in `artisan()` (g2-15):

```php
        if (Artisan::call($command, $parameters) !== 0) {
            throw new RuntimeException(trim(__('[:command] failed.', ['command' => $command]).' '.Artisan::output()));
        }
```

- run `grep -n "Could not write .env.testing" app/Setup/Steps/DatabaseStep.php`. Expected: no output, because Task 1 (RL-1) replaced the `copy()`. If it still prints the line, change it to `throw new RuntimeException(__('Could not write :file.', ['file' => '.env.testing']));`. That key exists already.

- [ ] **Step 5: A secured site needs an https APP_URL (g1-08)**

`app/Setup/Steps/HerdSiteStep.php`:
- replace `isComplete()`:

```php
    /**
     * Served (parked or linked) and, when HTTPS was chosen, secured with APP_URL on that https address (RL-16): a site
     * secured by hand, or by a detached run that died before writing APP_URL, still needs run()'s rewrite (g1-08).
     * False on any process failure.
     */
    public function isComplete(SetupContext $context): bool
    {
        $herd = $this->toolchain->herd();

        if (! $context->detection->herd || $herd === null) {
            return false;
        }

        try {
            return $this->siteState($context, $herd) === self::SERVED
                && (! $context->answers->https || ($this->appUrlIsSecured($context) && $this->isSecured($context, $herd)));
        } catch (Throwable) {
            return false;
        }
    }
```

- add, right after `isSecured()`:

```php
    /** .env's APP_URL is the https address secured() writes (RL-16). */
    private function appUrlIsSecured(SetupContext $context): bool
    {
        return new EnvFile($context->path('.env'))->get('APP_URL') === 'https://'.$this->domain($context);
    }
```

- [ ] **Step 6: The app's password defaults (g1-32)**

`app/Setup/Answers.php`:
- import `Illuminate\Validation\Rules\Password`
- in the rules, replace `'string', 'min:8'],` on the `super_admin_password` line with `'string', Password::default()],`:

```php
            'super_admin_password' => ['nullable', Rule::requiredIf($values['start_with'] === 'super_admin' && ! $isInstalledSuperAdmin), 'string', Password::default()],
```

- [ ] **Step 7: One generated-secrets helper (RL-10, L-56)**

`app/Setup/Steps/EnvironmentStep.php`:
- add, right after the `GENERATED` constant:

```php
    /**
     * A fresh random value, of its GENERATED length, for every generated secret that is blank in $env (RL-10). Setup,
     * the production bootstrap and the backup-password repair fill the keys the same way. It never writes, and never
     * replaces a value: an existing one is what a monitor or the archives use already.
     *
     * @return array<string, string>
     */
    public static function missingSecrets(EnvFile $env): array
    {
        $values = $env->all();
        $secrets = [];

        foreach (self::GENERATED as $key => $length) {
            if (($values[$key] ?? '') === '') {
                $secrets[$key] = Str::random($length);
            }
        }

        return $secrets;
    }
```

- in `isComplete()`, replace the `foreach (array_keys(self::GENERATED) as $key) { … }` loop with:

```php
        if (self::missingSecrets($env) !== []) {
            return false;
        }
```

- in `run()`, replace `$variables = $this->variables($context, $env);` and the `foreach (self::GENERATED …)` loop after it with:

```php
        $variables = $this->variables($context, $env) + self::missingSecrets($env);
```

`app/Setup/Support/Bootstrap.php`, inside `if ($this->isProduction()) {`, replace the `foreach (EnvironmentStep::GENERATED as $key => $length) { … }` loop:

```php
        if ($this->isProduction()) {
            $secrets = EnvironmentStep::missingSecrets($env);

            if ($secrets !== []) {
                $env->set($secrets);
            }

            foreach (array_keys($secrets) as $key) {
                $actions[] = __('Generated :key', ['key' => $key]);
            }

            return $actions;
        }
```

Remove `use Illuminate\Support\Str;` if nothing else in the file uses it (`composer fix` reports it).

`app/Setup/Doctor/Checks/BackupPasswordCheck.php`:
- replace the body of `fix()` above `$context->forgetSync();`:

```php
        // Only the archive password: this repair never writes the other generated secrets.
        $password = EnvironmentStep::missingSecrets($context->env())['BACKUP_ARCHIVE_PASSWORD'] ?? null;

        if ($password !== null) {
            $context->env()->set(['BACKUP_ARCHIVE_PASSWORD' => $password]);

            if (! app()->configurationIsCached()) {
                config(['backup.backup.password' => $password]);
            }
        }
```

- remove `use Illuminate\Support\Str;`

- [ ] **Step 8: EnvFile speaks the active language (g2-15)**

`app/Setup/Support/EnvFile.php`. Every message keeps today's English text, so existing assertions stay green. Paths and keys are placeholders:

```php
        if (! $this->exists()) {
            throw new RuntimeException(__('The environment file [:path] does not exist.', ['path' => $this->path]));
        }
```

```php
            if (preg_match('/\A[A-Z][A-Z0-9_]*\z/', $key) !== 1) {
                throw new RuntimeException(__('An environment variable name must be upper case letters, digits and underscores.'));
            }

            if (preg_match('/[\r\n\0]/', $value) === 1) {
                throw new RuntimeException(__('The value for [:key] contains a line break or a NUL byte.', ['key' => $key]));
            }
```

```php
        if ($handle === false) {
            throw new RuntimeException(__('Could not create a temporary copy of [:path].', ['path' => $this->path]));
        }
```

```php
        } catch (ExceptionInterface) {
            throw new RuntimeException(__('Writing [:keys] would leave [:path] unreadable; the file was not changed.', ['keys' => $keys, 'path' => $this->path]));
        }

        foreach ($values as $key => $value) {
            $keptOld = ! $overwrite && array_key_exists($key, $before) && ($after[$key] ?? null) === $before[$key];

            if (($after[$key] ?? null) !== $value && ! $keptOld) {
                throw new RuntimeException(__('The value for [:key] would not read back unchanged; [:path] was not changed.', ['key' => $key, 'path' => $this->path]));
            }
        }

        if (array_diff_key($after, $values) !== array_diff_key($before, $values)) {
            throw new RuntimeException(__('Writing [:keys] would change other variables in [:path]; the file was not changed.', ['keys' => $keys, 'path' => $this->path]));
        }
```

- [ ] **Step 9: Copy**

Lang keys, in both files, inserted in PHP `sort()` (byte-wise) order. In `en.json` each value equals its key.

| Key | nl |
|---|---|
| `[:command] failed.` | `[:command] is mislukt.` |
| `An environment variable name must be upper case letters, digits and underscores.` | `De naam van een omgevingsvariabele mag alleen hoofdletters, cijfers en underscores bevatten.` |
| `Could not create a temporary copy of [:path].` | `Kon geen tijdelijke kopie van [:path] maken.` |
| `The environment file [:path] does not exist.` | `Het omgevingsbestand [:path] bestaat niet.` |
| `The value for [:key] contains a line break or a NUL byte.` | `De waarde voor [:key] bevat een regeleinde of een NUL-byte.` |
| `The value for [:key] would not read back unchanged; [:path] was not changed.` | `De waarde voor [:key] zou niet ongewijzigd teruglezen; [:path] is niet gewijzigd.` |
| `Writing [:keys] would change other variables in [:path]; the file was not changed.` | `Door [:keys] te schrijven zouden andere variabelen in [:path] veranderen; het bestand is niet gewijzigd.` |
| `Writing [:keys] would leave [:path] unreadable; the file was not changed.` | `Door [:keys] te schrijven zou [:path] onleesbaar worden; het bestand is niet gewijzigd.` |

- [ ] **Step 10: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Authorization tests/Feature/Seeders tests/Feature/Setup tests/Feature/Console tests/Feature/LangFilesTest.php tests/Feature/LangCoverageTest.php`

Expected: PASS. Also run `composer test:pgsql -- --filter='DatabaseStep|SetSuperAdmin|DemoSeeder'` once: the rollback test then runs on PostgreSQL savepoints too.

- [ ] **Step 11: Verify by mutation**

Revert each production change locally, run the named test, see it fail, restore it and see it pass. Name each mutation and its result in the task report.

| Mutation | Test that must fail |
|---|---|
| `createNew()` without `DB::transaction()` (insert, then `handleCreated()`) | SetSuperAdminTest 'creates a new super admin…', DemoSeederTest 'creates the demo super admin…' |
| `createSuperAdmin()` applies the hash unconditionally (`if (true)`) | DatabaseStepTest 'never applies the same password hash twice…' |
| `HerdSiteStep::isComplete()` without `$this->appUrlIsSecured($context) &&` | HerdSiteStepTest 'secured, APP_URL still http' row and 'rewrites an http APP_URL…' |
| `Answers` back to `'min:8'` | AnswersTest 'applies the app\'s password defaults…' |
| `!== UserStatus::Active` → `=== UserStatus::PendingActivation` in DatabaseStep and CreateSuperAdminCommand | both 'disabled' rows (g2-18) |
| delete the `glob(…pgsql-schema.*)` guard in `DatabaseStep::run()` | DatabaseStepTest 'refuses a committed PostgreSQL schema dump…' fails with a refused connection, and no database is created (g2-19) |
| `EnvironmentStep::isComplete()` without the `missingSecrets()` check | both rows of the g2-26 dataset |
| `BackupPasswordCheck::fix()` writes all of `missingSecrets()` | ChecksTest 'needs an archive password…' (`HEALTH_CHECK_KEY` written) |
| `InstallPreflight::refusesDemoDefault()` without `! array_key_exists('start_with', $installed)` | InstallPreflightTest 'never refuses the demo start an earlier install chose…' (g2-28) |
| `HerdManifestStep::run()` writes `$this->manifest($context)."\n"` | SetupRunnerTest 'is complete right after a run that returned done…' `herd-manifest` row (g1-23) |
| `EnvFile`'s line-break message back to a plain English string | EnvFileTest 'a line break' row (g2-15) |

- [ ] **Step 12: The gate, then commit**

Run the per-task gate from the Global Constraints:
- `composer check`
- `composer test:pgsql`
- the modules-off run
- `npm run build && composer test:browser`
- `npm run build:setup && git diff --exit-code resources/setup/setup.css`. Expected: no diff; Task 5 changes no setup view.
- `php artisan config:clear`

```bash
git add app/Authorization/SetSuperAdmin.php app/Setup app/Console/Commands/CreateSuperAdminCommand.php database/seeders/DemoSeeder.php lang \
  tests/Feature/Authorization/SetSuperAdminTest.php tests/Feature/Seeders/DemoSeederTest.php tests/Feature/Setup tests/Feature/Console/CreateSuperAdminCommandTest.php
git commit -m "fix: create super admins in one place and harden the setup steps

One creator inserts and completes every new super admin in a transaction,
setup never re-applies a password hash, a secured site needs an https
APP_URL, the generated secrets come from one helper, and the env file's
errors are translated.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Install, bootstrap and the CLI (L-60, L-61, L-55, L-54, F16, F24, F25, L-51, L-52, L-57, L-58, g2-21)

**Files:**
- Create:
  - `app/Console/Support/Terminal.php` (interface), `app/Console/Support/StreamTerminal.php` (L-54)
  - `tests/Fixtures/AlwaysTerminal.php`, `tests/Feature/Tooling/GitIgnoreTest.php` (F16)
- Modify:
  - `app/Console/Commands/InstallCommand.php` (CD-3, L-55)
  - `app/Setup/Support/Bootstrap.php` (`isProduction()`, CD-3)
  - `app/Console/Concerns/ReadsStandardInput.php`, `app/Console/Commands/CreateSuperAdminCommand.php` (L-54)
  - `app/Providers/AppServiceProvider.php` (the `Terminal` binding)
  - `.gitignore` (F16), `lang/nl.json`, `lang/en.json`
- Test:
  - `tests/Feature/Console/ProductionGuardsTest.php` (L-60, L-61, L-57, L-58)
  - `tests/Feature/Console/InstallCommandTest.php` (L-55, F25, L-52, g2-21)
  - `tests/Feature/Console/CreateSuperAdminCommandTest.php` (L-54, F24, L-51)

**Interfaces:**
- Consumes:
  - `withoutPostgresProbe(bool $reachable = false): void` (Task 1, `tests/Pest.php`)
  - `Tests\Fixtures\FailingBootstrap`
  - the file-local `$install`, `$answersFile` and `$withStdin` closures of `InstallCommandTest`, and `$withStdin` of `CreateSuperAdminCommandTest`
  - `ReadsStandardInput::standardInput()` (private)
  - `EnvironmentStep::missingSecrets()` (Task 5)
- Produces:
  - `skv:install` runs only while `app()->environment(['local', 'testing', 'staging'])`. Every other name is refused with "skv:install runs only in the local, testing and staging environments (APP_ENV=:env)." before detection, answers or bootstrap (CD-3).
  - `Bootstrap::isProduction(): bool` is true when `.env`, `getenv('APP_ENV')` or `$_SERVER['APP_ENV']` says `production` (CD-3).
  - An inline `--answers` that holds any `super_admin_password` other than `null` or `''` is refused (L-55).
  - `interface App\Console\Support\Terminal { public function isTerminal(mixed $stream): bool; }` and `final readonly class StreamTerminal implements Terminal`, bound in `AppServiceProvider::register()`.
  - `ReadsStandardInput::standardInputIsTerminal(): bool`.
  - `skv:create-super-admin --password-stdin` refuses a terminal before it reads (L-54).
  - `.gitignore` lists `/answers.json` (F16).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Console/ProductionGuardsTest.php`:
- In `beforeEach`, the Detector must start no process and open no socket. If line 31 still reads `app()->instance(Detector::class, new Detector(new Toolchain($this->sandbox->path('home'))));`, replace it with `withoutPostgresProbe();`. Keep it after the `Toolchain` binding, because the helper reads `app(Toolchain::class)`. Drop the `Detector` import if it becomes unused.
- import `Tests\Fixtures\FailingBootstrap`.
- **Rewrite** 'refuses skv:install in production before anything is written (CD-9, A57)' (L-61):

```php
it('refuses skv:install outside local, testing and staging before anything is written (CD-3, L-61)', function (string $environment): void {
    app()['env'] = $environment;
    $before = (string) file_get_contents($this->sandbox->path('.env'));

    $exit = Artisan::call('skv:install', ['--no-interaction' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain(__('skv:install runs only in the local, testing and staging environments (APP_ENV=:env).', ['env' => $environment]))
        ->and(file_get_contents($this->sandbox->path('.env')))->toBe($before);
})->with(['production', 'prod', 'live']);
```

- **Rewrite** 'refuses the demo data outside local development before anything is written (CD-9)' (L-57). Delete its `Process::fake(['*' => Process::result('')]);` line and the comment above it, and put this comment in their place:

```php
    // The beforeEach fakes no command and the detector starts none: a regressed guard trips the stray-process
    // check in the bootstrap's migrate, after it has written APP_KEY (L-57).
```

- add (L-58):

```php
it('lets a staging install with its own super admin past the guards (CD-3, CD-9, L-58)', function (): void {
    app()->instance(BootstrapRunner::class, new FailingBootstrap);
    app()['env'] = 'staging';
    $answers = $this->sandbox->path('answers.json');
    file_put_contents($answers, json_encode([
        'database' => 'sqlite', 'start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com',
        'super_admin_password' => 'correct-horse-battery', 'modules' => [], 'https' => false, 'claude' => false,
    ], JSON_THROW_ON_ERROR));

    $exit = Artisan::call('skv:install', ['--answers' => '@'.$answers, '--no-interaction' => true]);
    $output = Artisan::output();

    // Past both refusals, the run reaches the bootstrap, which the fixture fails before anything is written.
    expect($exit)->toBe(1)
        ->and($output)->toContain(__('Setup stopped at :step: :message', ['step' => __('Bootstrap'), 'message' => 'The command "php artisan migrate" failed.']))
        ->not->toContain(__('Demo data runs only in local development. Pass "start_with": "super_admin" with your own login.'))
        ->not->toContain(__('skv:install runs only in the local, testing and staging environments (APP_ENV=:env).', ['env' => 'staging']));
});
```

- add (L-60):

```php
it('takes the production half when the process environment says production, whatever .env says (CD-3, L-60)', function (string $source): void {
    $previousEnv = getenv('APP_ENV');
    $previousServer = $_SERVER['APP_ENV'] ?? null;
    $this->beforeApplicationDestroyed(function () use ($previousEnv, $previousServer): void {
        putenv($previousEnv === false ? 'APP_ENV' : 'APP_ENV='.$previousEnv);

        if ($previousServer === null) {
            unset($_SERVER['APP_ENV']);
        } else {
            $_SERVER['APP_ENV'] = $previousServer;
        }
    });
    new EnvFile($this->sandbox->path('.env'))->set(['APP_ENV' => 'local', 'APP_KEY' => '', 'HEALTH_CHECK_KEY' => '', 'BACKUP_ARCHIVE_PASSWORD' => '']);

    if ($source === 'getenv') {
        putenv('APP_ENV=production');
    } else {
        $_SERVER['APP_ENV'] = 'production';
    }

    $bootstrap = new Bootstrap($this->sandbox->basePath, new Toolchain($this->sandbox->path('home')));
    $actions = $bootstrap->run();

    expect($bootstrap->isProduction())->toBeTrue()
        ->and($actions)->toBe([
            __('Generated an application key'),
            __('Generated :key', ['key' => 'HEALTH_CHECK_KEY']),
            __('Generated :key', ['key' => 'BACKUP_ARCHIVE_PASSWORD']),
        ])
        ->and(is_file($this->sandbox->path(SetupToken::PATH)))->toBeFalse()
        ->and(is_file($this->sandbox->path('database/database.sqlite')))->toBeFalse();
    Process::assertNothingRan();
})->with(['the process environment' => 'getenv', 'the server variables' => 'server']);
```

`tests/Feature/Console/InstallCommandTest.php`:
- **Tighten** 'reads the answers from a file with --answers=@path (RL-11)' and 'reads the answers from standard input with --answers=- (RL-11)' (L-52). In both, replace `->toContain('database')` with:

```php
->toContain(__('validation.in', ['attribute' => 'database']))
```

- add:

```php
it('rejects an empty standard input for --answers=- as no JSON (RL-11, L-52)', function () use ($withStdin): void {
    $before = (string) file_get_contents($this->sandbox->path('.env'));

    [$exit, $output] = $withStdin('skv:install', ['--answers' => '-', '--no-interaction' => true], '');

    expect($exit)->toBe(1)
        ->and($output)->toContain(__('The --answers value is not valid JSON.'))
        ->and(file_get_contents($this->sandbox->path('.env')))->toBe($before);
});

it('refuses a password of any type inside an inline --answers (CD-8, L-55)', function (mixed $password) use ($install): void {
    $before = (string) file_get_contents($this->sandbox->path('.env'));

    [$exit, $output] = $install(['--answers' => json_encode(['start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com', 'super_admin_password' => $password], JSON_THROW_ON_ERROR), '--no-interaction' => true]);

    expect($exit)->toBe(1)
        ->and($output)->toContain(__('Pass super_admin_password in a file (--answers=@answers.json) or on standard input (--answers=-), never on the command line.'))
        ->and(file_get_contents($this->sandbox->path('.env')))->toBe($before);
})->with([
    'a number' => [12345678],
    'a list' => [['correct-horse-battery']],
    'true' => [true],
]);

it('takes the super admin password from standard input with --answers=- (CD-8, RL-11, F25)', function () use ($withStdin): void {
    [$exit, $output] = $withStdin('skv:install', ['--answers' => '-', '--no-interaction' => true], json_encode([
        'database' => 'sqlite', 'start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com',
        'super_admin_password' => 'correct-horse-battery', 'modules' => [], 'https' => false, 'claude' => false,
    ], JSON_THROW_ON_ERROR));

    $owner = DB::connection(DatabaseStep::CONNECTION)->table('users')->where('email', 'owner@example.com')->sole();

    expect($exit)->toBe(0)
        ->and($output)->not->toContain(__('Pass super_admin_password in a file (--answers=@answers.json) or on standard input (--answers=-), never on the command line.'))
        ->not->toContain('correct-horse-battery')
        ->and(Hash::check('correct-horse-battery', (string) $owner->password))->toBeTrue();
});

it('re-runs a complete step only with --force (RL-6, g2-21)', function () use ($install): void {
    $answers = (string) file_get_contents(base_path('tests/Fixtures/e2e-answers.json'));
    // The herd.yml step's line in the non-interactive output: "herd.yml ....... <status>".
    $status = fn (string $output): ?string => preg_match('/^\s*'.preg_quote(__('herd.yml'), '/').'\s+\.+\s+(\S+)\s*$/m', $output, $match) === 1 ? $match[1] : null;

    [$firstExit, $first] = $install(['--answers' => $answers, '--no-interaction' => true]);
    [$plainExit, $plain] = $install(['--no-interaction' => true]);
    [$forcedExit, $forced] = $install(['--no-interaction' => true, '--force' => true]);

    expect([$firstExit, $plainExit, $forcedExit])->toBe([0, 0, 0])
        ->and([$status($first), $status($plain), $status($forced)])->toBe([__('done'), __('skipped'), __('done')]);
});
```

> `Hash` and `DatabaseStep` are imported already. The `validation.in` message comes from the framework's `en` validation file, because tests run with `APP_LOCALE=en`.

`tests/Feature/Console/CreateSuperAdminCommandTest.php`: import `App\Console\Support\Terminal` and `Tests\Fixtures\AlwaysTerminal`. Then:
- **Rewrite** 'keeps an existing user\'s password when the new one is too weak (RL-9)' (L-51). It destructures the output and expects the password rule's message, so a crash before the write no longer passes:

```php
it('keeps an existing user\'s password when the new one is too weak (RL-9, L-51)', function () use ($withStdin): void {
    $member = User::factory()->for(Organisation::factory()->create(['slug' => 'acme']))->create(['email' => 'member@example.com', 'password' => 'old-password-123']);

    [$exit, $output] = $withStdin('skv:create-super-admin', ['email' => 'member@example.com', '--password-stdin' => true, '--no-interaction' => true], "short\n");

    // Password::default() is min(8) outside production (AppServiceProvider).
    expect($exit)->toBe(1)
        ->and($output)->toContain(__('validation.min.string', ['attribute' => 'password', 'min' => 8]));

    expect($member->fresh()?->isSuperAdmin())->toBeFalse()
        ->and(Hash::check('old-password-123', (string) $member->fresh()?->password))->toBeTrue();
});
```

- **Rewrite** 'reads standard input afresh on every run in the same process (RL-10)' (F24). The second run now has a real password, so a stale `passwordRead` flag (exit 1) or a stale password (the first run's) fails it. 'refuses an empty standard input for --password-stdin (CD-8)' keeps the empty case:

```php
it('reads standard input afresh on every run in the same process (RL-10, F24)', function () use ($withStdin): void {
    [$firstExit] = $withStdin('skv:create-super-admin', ['email' => 'owner@example.com', '--password-stdin' => true, '--no-interaction' => true], "correct-horse-battery\n");
    [$secondExit] = $withStdin('skv:create-super-admin', ['email' => 'second@example.com', '--password-stdin' => true, '--no-interaction' => true], "battery-staple-horse\n");

    $second = Tenant::bypass(fn (): ?User => User::query()->where('email', 'second@example.com')->first());

    expect([$firstExit, $secondExit])->toBe([0, 0])
        ->and(Hash::check('battery-staple-horse', (string) $second?->password))->toBeTrue()
        ->and(Hash::check('correct-horse-battery', (string) $second?->password))->toBeFalse();
});
```

- add (L-54):

```php
it('refuses --password-stdin on a terminal, where the password would show, before reading it (CD-8, L-54)', function () use ($withStdin): void {
    app()->instance(Terminal::class, new AlwaysTerminal);

    [$exit, $output] = $withStdin('skv:create-super-admin', ['email' => 'owner@example.com', '--password-stdin' => true, '--no-interaction' => true], "correct-horse-battery\n");

    expect($exit)->toBe(1)
        ->and($output)->toContain(__('Standard input is a terminal, where the password would show. Pipe it in: printf \'%s\n\' "$PASSWORD" | php artisan skv:create-super-admin you@example.com --password-stdin'))
        ->not->toContain('correct-horse-battery')
        ->and(Tenant::bypass(fn (): bool => User::query()->where('email', 'owner@example.com')->exists()))->toBeFalse();
});
```

> Every other `--password-stdin` case in the file runs on a `php://memory` stream, which `StreamTerminal` reports as no terminal. If the default binding answered true, all of them would fail.

`tests/Fixtures/AlwaysTerminal.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Console\Support\Terminal;

/** A terminal for every stream, so a test reaches the terminal refusal of --password-stdin (L-54): php://memory never is one. */
final readonly class AlwaysTerminal implements Terminal
{
    public function isTerminal(mixed $stream): bool
    {
        return true;
    }
}
```

`tests/Feature/Tooling/GitIgnoreTest.php` (F16):

```php
<?php

declare(strict_types=1);

/** covers §5.3.2 */
it('keeps an answers file in the project root out of git, because it may hold the super admin password (F16)', function (): void {
    $patterns = file(base_path('.gitignore'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    expect($patterns)->toContain('/answers.json');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Console tests/Feature/Tooling/GitIgnoreTest.php`

Expected: FAIL.
- `prod` and `live` reach detection, because only `production` is refused.
- The L-60 rows take the development half: an APP_URL check, the SQLite file, and the stray `migrate` process.
- An inline non-string password gets the validator's "must be a string" instead of the refusal.
- `Terminal` and `AlwaysTerminal` do not exist.
- The second F24 run already passes, because both fields are reset; Step 6 mutates it.
- `.gitignore` has no `/answers.json`.

These pass already, because they are new coverage of existing behaviour; Step 6 mutates them:
- L-52, F25 and g2-21
- the L-51 message
- L-57 and L-58

- [ ] **Step 3: Where install and bootstrap run (CD-3, L-60, L-61)**

`app/Console/Commands/InstallCommand.php`, the first lines of `handle()`:

```php
        // CD-3: setup writes .env, the database and the marker of a local or staging install; any other environment
        // follows the README's production checklist. A fresh server still runs it, because .env.example ships
        // APP_ENV=local. An allowlist, so a production install named `prod` or `live` is refused too.
        if (! app()->environment(['local', 'testing', 'staging'])) {
            $this->components->error(__('skv:install runs only in the local, testing and staging environments (APP_ENV=:env).', [
                'env' => OutputFormatter::escape((string) app()->environment()),
            ]));

            return self::FAILURE;
        }
```

`app/Setup/Support/Bootstrap.php`, replace `isProduction()`:

```php
    /**
     * Whether the project this Bootstrap prepares is a production install (CD-3). Its .env says APP_ENV=production, or
     * the process environment does: a server's own APP_ENV overrides the file, and the running app's loaded .env
     * shows there too.
     */
    public function isProduction(): bool
    {
        return new EnvFile($this->basePath.'/.env')->get('APP_ENV') === 'production'
            || getenv('APP_ENV') === 'production'
            || ($_SERVER['APP_ENV'] ?? null) === 'production';
    }
```

Update the class docblock's "In production it only creates…" sentence to "In production (its `.env` or the process environment says so) it only creates…".

- [ ] **Step 4: A password inline is refused whatever its type (L-55)**

`app/Console/Commands/InstallCommand.php`, in `rawAnswers()`:

```php
            // CD-8, RL-11: a password on the command line ends up in the process list and the shell history, whatever
            // its JSON type (L-55).
            if ($inline && array_key_exists('super_admin_password', $input) && ! in_array($input['super_admin_password'], [null, ''], true)) {
                throw new InvalidArgumentException(__('Pass super_admin_password in a file (--answers=@answers.json) or on standard input (--answers=-), never on the command line.'));
            }
```

- [ ] **Step 5: `--password-stdin` refuses a terminal (L-54)**

`app/Console/Support/Terminal.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Support;

/**
 * Tells whether a stream is a terminal (L-54): a secret typed there shows on the screen, so --password-stdin refuses
 * it. Tests bind Tests\Fixtures\AlwaysTerminal, because the memory streams they pass never are one.
 */
interface Terminal
{
    /** @param  resource  $stream */
    public function isTerminal(mixed $stream): bool;
}
```

`app/Console/Support/StreamTerminal.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Support;

/** The real check: stream_isatty() on the command's input stream (L-54). */
final readonly class StreamTerminal implements Terminal
{
    public function isTerminal(mixed $stream): bool
    {
        return is_resource($stream) && stream_isatty($stream);
    }
}
```

`app/Providers/AppServiceProvider.php`:
- import `App\Console\Support\StreamTerminal` and `App\Console\Support\Terminal`
- in `register()`, right after the `StepLauncher` binding:

```php
        // --password-stdin refuses a terminal (L-54); tests bind one that always is.
        $this->app->bind(Terminal::class, StreamTerminal::class);
```

`app/Console/Concerns/ReadsStandardInput.php`:
- import `App\Console\Support\Terminal`
- add, after `allOfStandardInput()`:

```php
    /** Whether standard input is a terminal (L-54): what someone types there shows on the screen. */
    protected function standardInputIsTerminal(): bool
    {
        $stream = $this->standardInput();

        return $stream !== null && app(Terminal::class)->isTerminal($stream);
    }
```

`app/Console/Commands/CreateSuperAdminCommand.php`, in `handle()`, right before the `if ($this->option('password-stdin') && $this->givenPassword() === null)` check. Reading would block, and what the owner types would show:

```php
        if ($this->option('password-stdin') && $this->standardInputIsTerminal()) {
            $this->components->error(__('Standard input is a terminal, where the password would show. Pipe it in: printf \'%s\n\' "$PASSWORD" | php artisan skv:create-super-admin you@example.com --password-stdin'));

            return self::FAILURE;
        }
```

- [ ] **Step 6: `.gitignore` (F16)**

Add `/answers.json` to `.gitignore`, on the line after `/auth.json`. Task 12 changes the README and handleiding examples.

- [ ] **Step 7: Copy**

In both files, in PHP `sort()` order. `en.json` values equal their keys.

| Key | nl |
|---|---|
| `skv:install runs only in the local, testing and staging environments (APP_ENV=:env).` | `skv:install draait alleen in de omgevingen local, testing en staging (APP_ENV=:env).` |
| `Standard input is a terminal, where the password would show. Pipe it in: printf '%s\n' "$PASSWORD" \| php artisan skv:create-super-admin you@example.com --password-stdin` | `Standaardinvoer is een terminal, waar het wachtwoord zichtbaar zou worden. Geef het via een pipe door: printf '%s\n' "$PASSWORD" \| php artisan skv:create-super-admin jij@example.com --password-stdin` |

The second key holds a literal backslash-n and double quotes. In the JSON files it reads exactly:

```json
    "Standard input is a terminal, where the password would show. Pipe it in: printf '%s\\n' \"$PASSWORD\" | php artisan skv:create-super-admin you@example.com --password-stdin": "Standaardinvoer is een terminal, waar het wachtwoord zichtbaar zou worden. Geef het via een pipe door: printf '%s\\n' \"$PASSWORD\" | php artisan skv:create-super-admin jij@example.com --password-stdin",
```

Remove `skv:install sets up a development install and does not run in production. Follow the production checklist in the README.` from both files. Nothing uses it any more (`grep -rn "does not run in production" app tests lang` prints nothing).

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Console tests/Feature/Setup tests/Feature/Tooling tests/Feature/LangFilesTest.php tests/Feature/LangCoverageTest.php tests/Arch`

Expected: PASS. `tests/Arch` covers the fixture (it declares a class in `tests/Fixtures`, not in a `*Test.php` file) and the presets.

- [ ] **Step 9: Verify by mutation**

| Mutation | Test that must fail |
|---|---|
| `InstallCommand` back to `if (app()->isProduction())` | ProductionGuardsTest 'refuses skv:install outside local…' rows `prod` and `live` (L-61) |
| `Bootstrap::isProduction()` back to `.env` only | ProductionGuardsTest 'takes the production half…' both rows (L-60) |
| the L-55 condition back to `is_string(…) && … !== ''` | InstallCommandTest 'refuses a password of any type…' all rows |
| delete the terminal check in `CreateSuperAdminCommand::handle()` | CreateSuperAdminCommandTest 'refuses --password-stdin on a terminal…' (L-54) |
| delete `$this->passwordRead = false;` in `handle()` | CreateSuperAdminCommandTest 'reads standard input afresh…' (F24) |
| `$inline = ! str_starts_with($value, '@');` | InstallCommandTest 'takes the super admin password from standard input…' (F25) |
| `throw new LogicException('crash');` as the first line of `promote()` | CreateSuperAdminCommandTest 'keeps an existing user\'s password when the new one is too weak' (L-51) |
| delete the demo guard (`if ($answers->startWith === 'demo' && …)`) | ProductionGuardsTest 'refuses the demo data outside local development…' (L-57) |
| widen the demo guard to `if (! app()->environment(['local', 'testing']))` | ProductionGuardsTest 'lets a staging install with its own super admin past the guards' (L-58) |
| `new SetupContext($basePath, $answers, $detection, false)` (ignore `--force`) | InstallCommandTest 're-runs a complete step only with --force' (g2-21) |
| remove `/answers.json` from `.gitignore` | GitIgnoreTest (F16) |

- [ ] **Step 10: The gate, then commit**

Run the per-task gate from the Global Constraints:
- `composer check`
- `composer test:pgsql`
- the modules-off run
- `npm run build && composer test:browser`
- `npm run build:setup && git diff --exit-code resources/setup/setup.css`. Expected: no diff.
- `php artisan config:clear`

```bash
git add .gitignore app/Console app/Setup/Support/Bootstrap.php app/Providers/AppServiceProvider.php lang \
  tests/Fixtures/AlwaysTerminal.php tests/Feature/Console tests/Feature/Tooling/GitIgnoreTest.php
git commit -m "fix: run skv:install only in local, testing and staging, and close the cli secret gaps

skv:install refuses every environment but local, testing and staging;
skv:bootstrap also trusts the process environment for production; an
inline password of any type is refused; --password-stdin refuses a
terminal; answers.json is git-ignored.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 7: The doctor (F2, F10, F15, F12, F5, F13, L-02, g2-20, g2-22, g2-25, L-08, L-11; CD-4, CD-5, CD-6)

**Files:**
- Create:
  - `app/Support/Health/HealthCheckKey.php`
  - `tests/Feature/Health/HealthCheckKeyTest.php`
  - `tests/Feature/Setup/Doctor/PostgresBinariesTest.php`
  - `tests/Fixtures/FailingCheckWithThrowingFix.php`
- Modify:
  - `app/Setup/Doctor/DoctorContext.php` (`$runtime`, `setting()`, `defaultConnectionDown()`)
  - `app/Setup/Doctor/Checks/{MailCheck,HealthCheckKeyCheck,QueueCheck,FailedJobsCheck}.php`
  - `app/Setup/Steps/EnvironmentStep.php` (`missingSecrets()` from Task 5: a short `HEALTH_CHECK_KEY` counts as missing)
  - `app/Setup/Doctor/PostgresBinaries.php`
  - `app/Support/Health/SystemChecks.php`
  - `app/Support/Backups/ConfigurePgDump.php` (docblock only)
  - `config/database.php` (pgsql `options`), `.env.example` (`DB_CONNECT_TIMEOUT`)
  - `lang/nl.json`, `lang/en.json`
  - `tests/Arch/NameLeakTest.php` (`$allowed`: the `->setting(` form, CD-5)
- Test:
  - `tests/Feature/Health/HealthChecksTest.php`, `tests/Feature/Health/HealthReportTest.php`
  - `tests/Feature/Setup/Doctor/ChecksTest.php`, `tests/Feature/Setup/Doctor/DoctorTest.php`
  - `tests/Feature/Console/DoctorCommandTest.php`
  - `tests/Feature/Setup/Steps/EnvironmentStepTest.php`
  - `tests/Feature/Backups/ConfigurePgDumpTest.php`
  - `tests/Feature/System/SystemPageTest.php`

**Interfaces:**
- Consumes:
  - `Check`, `CheckResult`, `Doctor::run()` / `Doctor::isGreen()`
  - `DoctorContext::withoutDetection(?string $basePath)` / `env()` / `databaseReachable()` / `forgetSync()`. Task 1 may have changed `forApplication()` (L-10) and the marker read (RL-2); this task touches neither.
  - `EnvironmentStep::GENERATED` (`HEALTH_CHECK_KEY` => 40), `EnvFile`, `Toolchain::which()`
  - `EnvironmentStep::missingSecrets(EnvFile $env): array<string, string>` (Task 5, RL-10), used by `EnvironmentStep::isComplete()` / `run()`, `Bootstrap::run()` and `BackupPasswordCheck::fix()`
  - `Tests\Fixtures\{FixedStatusCheck,ThrowingCheck}`, `setupSandbox()`, `useBackupsModule()`
- Produces:
  - `DoctorContext::__construct(string $basePath, Toolchain $toolchain, Detection $detection, ?array $marker, bool $runtime = false)`, with `public readonly bool $runtime`. Only `withoutDetection()` passes `runtime: true` (CD-5).
  - `DoctorContext::setting(string $envKey, string $configKey): ?string`:
    - in a runtime context, the running configuration
    - otherwise `.env`
    - null when unset, blank, or neither a string nor a number
  - `DoctorContext::defaultConnectionDown(?string $connection): bool`: true when `$connection` (null or `''` mean the default) is the default connection and it does not open (F2).
  - `App\Support\Health\HealthCheckKey` (CD-4):
    - `MIN_LENGTH = 32`, `HEADER = 'X-Health-Check-Key'`
    - `isUsable(mixed $key): bool`, with `@phpstan-assert-if-true =string $key` (narrows on true only, so callers that pass a string stay clean at level 8)
    - `configured(): ?string`
    - `matches(mixed $given): bool` (`hash_equals()`, never true without a usable configured key)
  - `HealthCheckKeyCheck`:
    - fails with `HEALTH_CHECK_KEY is empty` or `HEALTH_CHECK_KEY is too short`
    - `fix()` replaces a blank or short `.env` key with `Str::random(EnvironmentStep::GENERATED['HEALTH_CHECK_KEY'])`
  - `MailCheck` reads `mail.default`, `mail.mailers.smtp.host` and `mail.mailers.smtp.port` in a runtime context (CD-5).
  - `QueueCheck` and `FailedJobsCheck` return `skipped('The database is not reachable')` while their table's connection is the default one and it is down (F2).
  - `PostgresBinaries::__construct(string $herdServices = self::HERD_SERVICES, ?int $userId = null)`, with the CD-6 order and the trust rule.
  - `SystemChecks::KEYS` gains `'apex'` after `'marker'` (F12).
  - `config('database.connections.pgsql.options')` is `[PDO::ATTR_TIMEOUT => (int) env('DB_CONNECT_TIMEOUT', 5)]`; `.env.example` gets `DB_CONNECT_TIMEOUT=5`.
  - `EnvironmentStep::missingSecrets()` also returns a fresh `HEALTH_CHECK_KEY` when the present one fails `HealthCheckKey::isUsable()` (CD-4). So `isComplete()` is false while the key is short, and `run()` and the production bootstrap replace it.
  - `Tests\Fixtures\FailingCheckWithThrowingFix` (key `failing`).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Health/HealthCheckKeyTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.9 */

use App\Setup\Steps\EnvironmentStep;
use App\Support\Health\HealthCheckKey;

it('counts only a string of at least 32 characters as a configured key (CD-4, F15)', function (mixed $configured, bool $usable): void {
    config(['skv.health_check.key' => $configured]);

    expect(HealthCheckKey::configured())->toBe($usable ? $configured : null)
        ->and(HealthCheckKey::isUsable($configured))->toBe($usable);
})->with([
    'unset' => [null, false],
    'blank' => ['', false],
    'cast from HEALTH_CHECK_KEY=true' => [true, false],
    'a word' => ['monitor', false],
    'one short' => [str_repeat('k', 31), false],
    'the minimum' => [str_repeat('k', 32), true],
    'as setup writes it' => [str_repeat('k', 40), true],
]);

it('matches only the configured key, and nothing while that key is too short (CD-4)', function (): void {
    config(['skv.health_check.key' => str_repeat('k', 40)]);

    expect(HealthCheckKey::matches(str_repeat('k', 40)))->toBeTrue()
        ->and(HealthCheckKey::matches(str_repeat('k', 39)))->toBeFalse()
        ->and(HealthCheckKey::matches(null))->toBeFalse()
        ->and(HealthCheckKey::matches([str_repeat('k', 40)]))->toBeFalse();

    config(['skv.health_check.key' => 'monitor']);

    expect(HealthCheckKey::matches('monitor'))->toBeFalse();
});

it('generates keys that the rule accepts (CD-4)', function (): void {
    expect(EnvironmentStep::GENERATED['HEALTH_CHECK_KEY'])->toBeGreaterThanOrEqual(HealthCheckKey::MIN_LENGTH);
});
```

`tests/Feature/Setup/Doctor/PostgresBinariesTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §5.4 */

use App\Setup\Detection;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Doctor\PostgresBinaries;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    $this->sandbox = setupSandbox();
    $this->home = $this->sandbox->path('home');
    app()->instance(Toolchain::class, new Toolchain($this->home));
    $this->context = new DoctorContext($this->sandbox->basePath, new Toolchain($this->home), Detection::none(), null);
    // As /Users/Shared/Herd and /Users/Shared/Herd/services/postgresql: the root is two levels above the services (CD-6).
    $this->root = $this->sandbox->path('herd');
    $this->services = $this->root.'/services/postgresql';
    config(['database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.url' => null]);
});

/** An executable stub at $path; returns the path. */
$stub = function (string $path): string {
    File::ensureDirectoryExists(dirname($path));
    File::put($path, "#!/bin/sh\nexit 0\n");
    chmod($path, 0755);

    return $path;
};

it('prefers Herd\'s newest service over the PATH only while the server is on this machine (CD-6, F5)', function (string $host, ?string $url, bool $herdFirst) use ($stub): void {
    config(['database.connections.pgsql.host' => $host, 'database.connections.pgsql.url' => $url]);
    // ~/.local/bin is on the toolchain PATH before the system directories, so this is the PATH's pg_dump.
    $onPath = $stub($this->home.'/.local/bin/pg_dump');
    $herd = $stub($this->services.'/18/bin/pg_dump');

    expect(new PostgresBinaries($this->services)->find($this->context, 'pg_dump'))->toBe($herdFirst ? $herd : $onPath);
})->with([
    '127.0.0.1' => ['127.0.0.1', null, true],
    'localhost' => ['localhost', null, true],
    '::1' => ['::1', null, true],
    'a remote server' => ['db.example.com', null, false],
    'DB_URL on this machine' => ['db.example.com', 'pgsql://app:secret@[::1]:5432/app', true],
    'DB_URL on a remote server' => ['127.0.0.1', 'pgsql://app:secret@db.example.com:5432/app', false],
]);

it('still prefers PG_DUMP_BINARY_PATH over Herd and the PATH (CD-6)', function () use ($stub): void {
    $configured = $stub($this->sandbox->path('pg/bin/pg_dump'));
    $stub($this->home.'/.local/bin/pg_dump');
    $stub($this->services.'/18/bin/pg_dump');
    new EnvFile($this->sandbox->path('.env'))->set(['PG_DUMP_BINARY_PATH' => dirname($configured)]);

    expect(new PostgresBinaries($this->services)->find($this->context, 'pg_dump'))->toBe($configured);
});

it('takes the newest Herd service by version, not by name (L-02)', function () use ($stub): void {
    foreach (['9.6', '10', '18'] as $version) {
        $stub($this->services.'/'.$version.'/bin/pg_dump');
    }

    expect(new PostgresBinaries($this->services)->find($this->context, 'pg_dump'))->toBe($this->services.'/18/bin/pg_dump');
});

it('ignores a Herd binary that another account could replace (CD-6, F13)', function (string $writable, int $mode) use ($stub): void {
    $herd = $stub($this->services.'/18/bin/pg_dump');
    $binaries = new PostgresBinaries($this->services);
    $trusted = $binaries->find($this->context, 'pg_dump');

    chmod($writable === '' ? $this->root : $this->root.'/'.$writable, $mode);
    $found = $binaries->find($this->context, 'pg_dump');

    // A pg_dump on the system PATH may still be found; never the writable one under Herd's root.
    expect($trusted)->toBe($herd)
        ->and(str_starts_with((string) $found, $this->root.'/'))->toBeFalse();
})->with([
    'the binary, world-writable' => ['services/postgresql/18/bin/pg_dump', 0777],
    'its version directory, world-writable' => ['services/postgresql/18', 0777],
    'its version directory, group-writable' => ['services/postgresql/18', 0775],
    'the services directory, world-writable' => ['services', 0777],
    'Herd\'s root, world-writable' => ['', 0777],
]);

it('ignores a Herd binary owned by another account (CD-6, F13)', function () use ($stub): void {
    $stub($this->services.'/18/bin/pg_dump');

    $found = new PostgresBinaries($this->services, posix_geteuid() + 1)->find($this->context, 'pg_dump');

    expect(str_starts_with((string) $found, $this->root.'/'))->toBeFalse();
})->skip(fn (): bool => posix_geteuid() === 0, 'Running as root: root may own every candidate.');

it('ignores a Herd binary that links outside Herd\'s root (CD-6)', function () use ($stub): void {
    $outside = $stub($this->sandbox->path('elsewhere/pg_dump'));
    File::ensureDirectoryExists($this->services.'/18/bin');
    symlink($outside, $this->services.'/18/bin/pg_dump');

    $found = new PostgresBinaries($this->services)->find($this->context, 'pg_dump');

    expect(str_starts_with((string) $found, $this->root.'/'))->toBeFalse()
        ->and($found)->not->toBe($outside);
});
```

`tests/Fixtures/FailingCheckWithThrowingFix.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use RuntimeException;

/** A doctor check that fails with its own message and fix command, and whose repair throws (A9, L-11). */
final readonly class FailingCheckWithThrowingFix implements Check
{
    public function key(): string
    {
        return 'failing';
    }

    public function label(): string
    {
        return 'Failing';
    }

    public function run(DoctorContext $context): CheckResult
    {
        return CheckResult::fail('first', 'first-fix');
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        throw new RuntimeException('secret-binding');
    }
}
```

`tests/Feature/Setup/Doctor/DoctorTest.php`:
- Imports: add `App\Setup\Doctor\CheckResult`, `App\Setup\Support\EnvFile` and `Tests\Fixtures\FailingCheckWithThrowingFix`.
- Replace 'is green when nothing fails, whatever warns (§5.4)' (g2-25: the old body recomputed the rule it tested) with:

```php
it('is green when nothing fails, whatever warns or skips (§5.4)', /** @param  array<string, CheckResult>  $results */ function (array $results, bool $green): void {
    expect(Doctor::isGreen($results))->toBe($green);
})->with([
    'pass, warn and skipped' => [['a' => CheckResult::pass('fine'), 'b' => CheckResult::warn('odd'), 'c' => CheckResult::skipped('later')], true],
    'one failure among passes' => [['a' => CheckResult::pass('fine'), 'b' => CheckResult::fail('broken')], false],
    'only a failure' => [['a' => CheckResult::fail('broken')], false],
    'no checks' => [[], true],
]);
```

Add `Exceptions::fake();` as the first statement of 'names only the exception class of a check or a fix that throws, never its message (§5.4, RL-11)' (L-08). The "with fix" row calls `report()`, which today writes a stack trace to the repository's `storage/logs/laravel.log`.

Replace 'reports a repair that throws, and keeps the first result (A9)' (L-11: `ThrowingCheck`'s first result is only the generic crash text, so the old test passed with any failed result) with:

```php
it('reports a repair that throws, and keeps the first result with its fix command (A9, L-11)', function (): void {
    Exceptions::fake();

    $result = new Doctor([new FailingCheckWithThrowingFix])->run($this->context, fix: true)['failing'];

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toBe('first')
        ->and($result->fix)->toBe('first-fix');
    Exceptions::assertReported(RuntimeException::class);
});
```

Then add at the end:

```php
it('reads a setting from the running configuration only in a runtime context (CD-5, F10)', function (): void {
    app()->instance(Toolchain::class, new Toolchain($this->sandbox->path('home')));
    new EnvFile($this->sandbox->path('.env'))->set(['MAIL_HOST' => 'from-env.test', 'MAIL_PORT' => '']);
    config(['skv.health_check.key' => true]);
    config(['mail.mailers.smtp.host' => 'from-config.test', 'mail.mailers.smtp.port' => 2525]);
    $runtime = DoctorContext::withoutDetection($this->sandbox->basePath);

    expect($this->context->runtime)->toBeFalse()
        ->and(DoctorContext::fromSetup($this->sandbox, new Toolchain($this->sandbox->path('home')))->runtime)->toBeFalse()
        ->and($runtime->runtime)->toBeTrue()
        ->and($this->context->setting('MAIL_HOST', 'mail.mailers.smtp.host'))->toBe('from-env.test')
        ->and($this->context->setting('MAIL_PORT', 'mail.mailers.smtp.port'))->toBeNull()
        ->and($runtime->setting('MAIL_HOST', 'mail.mailers.smtp.host'))->toBe('from-config.test')
        ->and($runtime->setting('MAIL_PORT', 'mail.mailers.smtp.port'))->toBe('2525')
        // env() casts HEALTH_CHECK_KEY=true to bool true: no value.
        ->and($runtime->setting('HEALTH_CHECK_KEY', 'skv.health_check.key'))->toBeNull();
});
```

`tests/Feature/Console/DoctorCommandTest.php`:
- Imports: add `App\Enums\CheckStatus`, `App\Setup\Doctor\Checks\HealthCheckKeyCheck`, `App\Setup\Doctor\Doctor`, `App\Setup\Support\EnvFile` and `Tests\Fixtures\FixedStatusCheck`.
- The beforeEach keeps the sandbox on the test case:

```php
beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake(['*' => Process::result('')]);
    // Never the repository's own .env, marker or .mcp.json: every DoctorCommand test runs on a sandbox.
    $this->sandbox = setupSandbox();
    app()->instance(Toolchain::class, new Toolchain($this->sandbox->path('home')));
    app()->instance(DoctorContext::class, new DoctorContext($this->sandbox->basePath, new Toolchain($this->sandbox->path('home')), Detection::none(), null));
});
```

Add after 'lists each check with its status and fix command (§5.4)':

```php
it('repairs with --fix and only with --fix (§5.4, RL-11, g2-20)', function (): void {
    // A doctor of one file-only check: --fix on the kit's whole list would run npm, git and artisan repairs.
    app()->instance(Doctor::class, new Doctor([new HealthCheckKeyCheck]));
    $env = new EnvFile($this->sandbox->path('.env'));
    $env->set(['HEALTH_CHECK_KEY' => '']);

    $plain = Artisan::call('skv:doctor', ['--json' => true]);
    $untouched = $env->get('HEALTH_CHECK_KEY');
    $fixed = Artisan::call('skv:doctor', ['--json' => true, '--fix' => true]);
    /** @var array{checks: list<array{key: string, status: string, message: string, fix: ?string}>, green: bool} $report */
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($plain)->toBe(1)
        ->and($untouched)->toBe('')
        ->and($fixed)->toBe(0)
        ->and($report['green'])->toBeTrue()
        ->and($report['checks'][0]['key'])->toBe('health-check-key')
        ->and($report['checks'][0]['status'])->toBe('pass')
        ->and(strlen((string) $env->get('HEALTH_CHECK_KEY')))->toBe(40);
});

it('exits zero when nothing fails, also with --json (§5.4, RL-11, g2-22)', function (): void {
    app()->instance(Doctor::class, new Doctor([new FixedStatusCheck(CheckStatus::Pass, 'probe'), new FixedStatusCheck(CheckStatus::Warn, 'odd')]));

    $json = Artisan::call('skv:doctor', ['--json' => true]);
    /** @var array{green: bool} $report */
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $text = Artisan::call('skv:doctor');

    expect($json)->toBe(0)
        ->and($report['green'])->toBeTrue()
        ->and($text)->toBe(0)
        ->and(Artisan::output())->toContain(__('All checks pass.'));
});
```

`tests/Feature/Setup/Steps/EnvironmentStepTest.php`, add after 'keeps the health key and APP_KEY it finds (§5.3.3 step 1)':

```php
it('replaces a health key too short for /health-check, and is incomplete until then (§5.3.3 step 1, CD-4)', function (): void {
    $context = setupSandbox();
    $env = new EnvFile($context->path('.env'));
    $env->set(['HEALTH_CHECK_KEY' => str_repeat('k', 10)]);

    $before = new EnvironmentStep()->isComplete($context);
    new EnvironmentStep()->run($context);
    $written = (string) $env->get('HEALTH_CHECK_KEY');

    expect($before)->toBeFalse()
        ->and(strlen($written))->toBe(40)
        ->and($written)->not->toBe(str_repeat('k', 10))
        ->and(new EnvironmentStep()->isComplete($context))->toBeTrue();
});
```

> The existing 'keeps the health key and APP_KEY it finds' (a 40-character key) and Task 5's g2-26 dataset (40 characters for `HEALTH_CHECK_KEY`) still hold: a usable key is never replaced.

`tests/Feature/Setup/Doctor/ChecksTest.php`:
- Import `App\Support\Health\HealthCheckKey`.
- Replace 'writes a health-check key with --fix (§5.4)' with:

```php
it('fails a blank or short health-check key and writes a new one with --fix (§5.4, CD-4, F15)', function (string $key, string $message): void {
    new EnvFile($this->sandbox->path('.env'))->set(['HEALTH_CHECK_KEY' => $key]);

    $before = new HealthCheckKeyCheck()->run($this->context);
    $result = new HealthCheckKeyCheck()->fix($this->context);
    $written = (string) new EnvFile($this->sandbox->path('.env'))->get('HEALTH_CHECK_KEY');

    expect($before->status)->toBe(CheckStatus::Fail)
        ->and($before->message)->toBe(__($message))
        ->and($before->fix)->toBe('php artisan skv:doctor --fix')
        ->and($result->status)->toBe(CheckStatus::Pass)
        ->and($result->message)->not->toContain($written)
        ->and(strlen($written))->toBe(40);
})->with([
    'blank' => ['', 'HEALTH_CHECK_KEY is empty'],
    'one short of the minimum' => [str_repeat('k', 31), 'HEALTH_CHECK_KEY is too short'],
]);

it('keeps a health-check key of the minimum length, which a monitor may use (§5.4, CD-4)', function (): void {
    $key = str_repeat('k', HealthCheckKey::MIN_LENGTH);
    new EnvFile($this->sandbox->path('.env'))->set(['HEALTH_CHECK_KEY' => $key]);

    $result = new HealthCheckKeyCheck()->fix($this->context);

    expect($result->status)->toBe(CheckStatus::Pass)
        ->and(new EnvFile($this->sandbox->path('.env'))->get('HEALTH_CHECK_KEY'))->toBe($key);
});

it('judges the running configuration\'s health-check key in a runtime context, and .env otherwise (CD-5)', function (): void {
    new EnvFile($this->sandbox->path('.env'))->set(['HEALTH_CHECK_KEY' => str_repeat('k', 40)]);
    config(['skv.health_check.key' => 'short']);

    $runtime = new HealthCheckKeyCheck()->run(DoctorContext::withoutDetection($this->sandbox->basePath));

    expect(new HealthCheckKeyCheck()->run($this->context)->status)->toBe(CheckStatus::Pass)
        ->and($runtime->status)->toBe(CheckStatus::Fail)
        ->and($runtime->message)->toBe(__('HEALTH_CHECK_KEY is too short'));
});
```

In 'warns when a schema dump exists and the PostgreSQL tools are missing (§5.4, §7.3)' and in 'looks for pg_dump only while the backups module is on (§5.4, RL-11)', replace `$herdServices = $this->sandbox->path('herd-services');` with:

```php
    // Herd's layout: the trust rule walks up to the root two levels above the services (CD-6).
    $herdServices = $this->sandbox->path('herd/services/postgresql');
```

Keep both tests' skip conditions: with a PATH pg_dump, the "missing" half cannot fail.

`tests/Feature/Backups/ConfigurePgDumpTest.php`: replace "finds Herd's newest PostgreSQL service when nothing else is configured (RL-4)" (L-02: one version and "not empty" could not fail) with:

```php
it('finds Herd\'s newest PostgreSQL service when nothing else is configured (RL-4, CD-6, L-02)', function () use ($starting): void {
    // CD-6: for a server on this machine Herd's services come before the PATH, so no PATH pg_dump hides the order.
    config(['database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.url' => null]);
    $services = $this->sandbox->path('herd/services/postgresql');

    foreach (['9.6', '10', '18'] as $version) {
        File::ensureDirectoryExists($services.'/'.$version.'/bin');
        File::copy($this->bin.'/pg_dump', $services.'/'.$version.'/bin/pg_dump');
        chmod($services.'/'.$version.'/bin/pg_dump', 0755);
    }

    new ConfigurePgDump($services, $this->sandbox->basePath)($starting('backup:run'));

    expect(config('database.connections.pgsql.dump.dump_binary_path'))->toBe($services.'/18/bin');
});
```

`tests/Feature/Health/HealthChecksTest.php`:
- Imports: add `App\Support\Health\HealthReport` and `Illuminate\Support\Arr`.
- beforeEach: add `$this->defaultConnection = config('database.default');` as its last line.
- Add after the beforeEach:

```php
afterEach(function (): void {
    // RefreshDatabase rolls back the default connection: give it back before the application is torn down.
    config(['database.default' => $this->defaultConnection]);
    DB::purge('health_unreachable');
});
```

Add at the end:

```php
it('skips the queue and failed-jobs checks while the default database is down, so only the database check fails (F2)', function (): void {
    useBackupsModule(false);
    config([
        'database.connections.health_unreachable' => ['driver' => 'sqlite', 'database' => '/nonexistent/'.Str::uuid().'/database.sqlite', 'prefix' => ''],
        'database.default' => 'health_unreachable',
        'queue.default' => 'database',
        'queue.connections.database.connection' => null,
        'queue.failed.driver' => 'database-uuids',
        'queue.failed.database' => 'health_unreachable',
    ]);

    $report = new HealthReport(basePath: $this->sandbox->basePath)->run();
    $skipped = ['status' => CheckStatus::Skipped->value, 'message' => __('The database is not reachable')];

    expect($report['healthy'])->toBeFalse()
        ->and($report['checks']['database']['status'])->toBe(CheckStatus::Fail->value)
        ->and(Arr::only($report['checks']['queue'], ['status', 'message']))->toBe($skipped)
        ->and(Arr::only($report['checks']['failed-jobs'], ['status', 'message']))->toBe($skipped);
});

it('gives the pgsql connection a connect timeout from DB_CONNECT_TIMEOUT, 5 seconds by default (F2)', function (): void {
    $original = [
        'getenv' => getenv('DB_CONNECT_TIMEOUT'),
        'server' => $_SERVER['DB_CONNECT_TIMEOUT'] ?? null,
        'env' => $_ENV['DB_CONNECT_TIMEOUT'] ?? null,
    ];
    $timeout = function (): mixed {
        /** @var array{connections: array{pgsql: array<string, mixed>}} $config */
        $config = require config_path('database.php');
        $options = $config['connections']['pgsql']['options'] ?? [];

        return is_array($options) ? ($options[PDO::ATTR_TIMEOUT] ?? null) : null;
    };

    try {
        putenv('DB_CONNECT_TIMEOUT');
        unset($_SERVER['DB_CONNECT_TIMEOUT'], $_ENV['DB_CONNECT_TIMEOUT']);
        $default = $timeout();

        putenv('DB_CONNECT_TIMEOUT=2');
        $_SERVER['DB_CONNECT_TIMEOUT'] = $_ENV['DB_CONNECT_TIMEOUT'] = '2';
        $configured = $timeout();
    } finally {
        putenv($original['getenv'] === false ? 'DB_CONNECT_TIMEOUT' : 'DB_CONNECT_TIMEOUT='.$original['getenv']);
        unset($_SERVER['DB_CONNECT_TIMEOUT'], $_ENV['DB_CONNECT_TIMEOUT']);

        if ($original['server'] !== null) {
            $_SERVER['DB_CONNECT_TIMEOUT'] = $original['server'];
        }

        if ($original['env'] !== null) {
            $_ENV['DB_CONNECT_TIMEOUT'] = $original['env'];
        }
    }

    expect($default)->toBe(5)
        ->and($configured)->toBe(2)
        ->and((string) file_get_contents(base_path('.env.example')))->toMatch('/^DB_CONNECT_TIMEOUT=5$/m');
});
```

`tests/Feature/Health/HealthReportTest.php`: import `App\Setup\Doctor\Checks\MailCheck` and `Illuminate\Support\Str`, then add:

```php
it('probes the mail server the running configuration names, not the one in .env (CD-5, F10)', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    assert($server !== false);
    $port = (int) Str::afterLast((string) stream_socket_get_name($server, false), ':');
    // The sandbox .env is .env.example, which says MAIL_MAILER=log.
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => $port]);

    try {
        $report = new HealthReport([new MailCheck], $this->sandbox->basePath)->run();
    } finally {
        fclose($server);
    }

    expect($report['checks']['mail']['status'])->toBe(CheckStatus::Pass->value)
        ->and($report['checks']['mail']['message'])->toBe(__('The mail server answers at :address', ['address' => '127.0.0.1:'.$port]));
});
```

`tests/Feature/System/SystemPageTest.php`: replace 'runs only the web-safe doctor checks on the sandbox, starting no process (RL-2, RL-7)' with:

```php
it('runs only the web-safe doctor checks on the sandbox, starting no process (RL-2, RL-7, F12)', function (): void {
    // CD-5: the page's health-check-key row judges the running configuration, whatever the sandbox's .env holds.
    config(['skv.health_check.key' => '']);
    new EnvFile($this->sandbox->path('.env'))->set([
        'HEALTH_CHECK_KEY' => str_repeat('k', 40),
        'APP_URL' => 'http://my-app.test',
        'TENANCY_APEX_DOMAIN' => 'other.test',
    ]);
    asSuperAdmin();
    onAdminHost();

    $page = Livewire::test('pages::system.index')->call('runDoctor');
    /** @var list<array{key: string, label: string, status: string, message: string}> $rows */
    $rows = $page->get('doctorRows');
    $crashed = Str::before(__('The check could not run (:exception).'), '(');

    expect(array_column($rows, 'key'))->toBe([
        'php', 'gd', 'app-key', 'marker', 'apex', 'database', 'migrations', 'permissions', 'organisation-admin-role',
        'super-admin', 'super-admin-badges', 'session-driver', 'authenticate-session', 'health-check-key',
        'backup-password', 'assets', 'mail', 'pg-dump', 'queue', 'failed-jobs', 'storage-link',
    ])
        ->and(array_filter($rows, fn (array $row): bool => str_starts_with($row['message'], $crashed)))->toBe([])
        ->and(array_column($rows, 'status', 'key')['health-check-key'])->toBe(CheckStatus::Fail->value)
        // F12: the apex row reads the sandbox's .env, not the repository's.
        ->and(array_column($rows, 'status', 'key')['apex'])->toBe(CheckStatus::Fail->value);
    expect(array_diff(SystemChecks::KEYS, array_map(fn (Check $check): string => $check->key(), new Doctor()->checks())))->toBe([]);

    config(['skv.health_check.key' => str_repeat('k', 40)]);
    /** @var list<array{key: string, label: string, status: string, message: string}> $again */
    $again = $page->call('runDoctor')->get('doctorRows');

    expect(array_column($again, 'status', 'key')['health-check-key'])->toBe(CheckStatus::Pass->value);
    Process::assertNothingRan();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Health tests/Feature/Setup/Doctor tests/Feature/Setup/Steps/EnvironmentStepTest.php tests/Feature/Console/DoctorCommandTest.php tests/Feature/Backups/ConfigurePgDumpTest.php tests/Feature/System/SystemPageTest.php`

Expected FAIL:
- `HealthCheckKeyTest`: the class `HealthCheckKey` does not exist.
- `PostgresBinariesTest`:
  - "prefers Herd's newest service": returns `~/.local/bin/pg_dump` for the local hosts.
  - "ignores a Herd binary that another account could replace", "…owned by another account" and "…that links outside": return the Herd candidate. With a pg_dump on the system PATH, these three may already pass today; the first one fails either way.
- `DoctorTest`: "reads a setting" (no `runtime` property, no `setting()`).
- `ChecksTest`:
  - the short-key row passes as "is set" today
  - the runtime health-key case reads `.env` today
- `EnvironmentStepTest`: "replaces a health key too short…": `isComplete()` is true with the 10-character key, and `run()` keeps it.
- `HealthChecksTest`: queue and failed-jobs are `fail` ("table is missing"), and the timeout is `null`.
- `HealthReportTest`: the mail row says "Mail goes to the log".
- `SystemPageTest`: no `apex` row, and the key row passes from the sandbox `.env`.

These pass today, and their mutation checks are in Step 5:
- the DoctorTest isGreen dataset and the L-11 case
- the two new DoctorCommandTest cases
- the L-02 sort case in ConfigurePgDumpTest

- [ ] **Step 3: Implement the context and the key rule**

`app/Support/Health/HealthCheckKey.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Health;

/**
 * The /health-check key rule (CD-4, F15): HEALTH_CHECK_KEY counts only as a string of at least MIN_LENGTH bytes
 * (Str::random() writes one byte per character). A shorter one counts as not configured: the endpoint answers 403
 * and the doctor's health-check-key check fails until skv:doctor --fix replaces it. env() casts HEALTH_CHECK_KEY=true
 * to bool true, which is no key either.
 */
final readonly class HealthCheckKey
{
    public const int MIN_LENGTH = 32;

    public const string HEADER = 'X-Health-Check-Key';

    /** @phpstan-assert-if-true =string $key */
    public static function isUsable(mixed $key): bool
    {
        return is_string($key) && strlen($key) >= self::MIN_LENGTH;
    }

    /** The configured key, or null while it is unset, blank, not a string or shorter than MIN_LENGTH. */
    public static function configured(): ?string
    {
        $key = config('skv.health_check.key');

        return self::isUsable($key) ? $key : null;
    }

    /** Whether $given is the configured key, compared with hash_equals(); never true without a usable configured key. */
    public static function matches(mixed $given): bool
    {
        $key = self::configured();

        return $key !== null && is_string($given) && hash_equals($key, $given);
    }
}
```

`app/Setup/Doctor/DoctorContext.php`. Replace the constructor and its docblock:

```php
    /**
     * @param  array<string, mixed>|null  $marker  the decoded storage/app/skv-installed, or null before setup finished (fromSetup() synthesises one)
     * @param  bool  $runtime  true only from withoutDetection(): setting() then reads the running configuration (CD-5)
     */
    public function __construct(
        public readonly string $basePath,
        public readonly Toolchain $toolchain,
        public readonly Detection $detection,
        public readonly ?array $marker,
        public readonly bool $runtime = false,
    ) {}
```

In `withoutDetection()`:
- add `runtime: true` as the last argument of its `new self(...)`, for example `return new self($basePath ?? base_path(), app(Toolchain::class), Detection::none(), null, runtime: true);`
- keep whatever Task 1 put in the other arguments
- extend its docblock with: "It is a runtime context (CD-5): setting() reads the running configuration, as the request or command that builds it runs with it."

Add after `env()`:

```php
    /**
     * A setting as the checks judge it (CD-5, F10): in a runtime context (withoutDetection(): web requests and
     * console listeners) the running configuration at $configKey, otherwise the project's .env at $envKey, because
     * skv:doctor and setup judge the file they are about to write. Null when it is unset, blank, or neither a string
     * nor a number: env() casts HEALTH_CHECK_KEY=true to bool true, which is no value here.
     */
    public function setting(string $envKey, string $configKey): ?string
    {
        $value = $this->runtime ? config($configKey) : $this->env()->get($envKey);

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
```

Add after `databaseReachable()`:

```php
    /**
     * Whether $connection (null or '' is the default one) is the default connection and that does not open (F2):
     * the queue and failed-jobs checks then skip, and only the database check reports the outage.
     */
    public function defaultConnectionDown(?string $connection): bool
    {
        return in_array($connection, [null, '', DB::getDefaultConnection()], true) && ! $this->databaseReachable();
    }
```

`app/Setup/Doctor/Checks/HealthCheckKeyCheck.php`:

```php
<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Steps\EnvironmentStep;
use App\Support\Health\HealthCheckKey;
use Illuminate\Support\Str;

/**
 * §5.4, CD-4, CD-5: HEALTH_CHECK_KEY is a usable key of at least HealthCheckKey::MIN_LENGTH characters, because
 * /health-check refuses a shorter one. A runtime context (the System page) judges the running configuration;
 * skv:doctor and setup judge .env. The fix writes a random key of EnvironmentStep::GENERATED's length. The key is
 * never shown.
 */
final readonly class HealthCheckKeyCheck implements Check
{
    public function key(): string
    {
        return 'health-check-key';
    }

    public function label(): string
    {
        return __('Health check key');
    }

    public function run(DoctorContext $context): CheckResult
    {
        $key = $context->setting('HEALTH_CHECK_KEY', 'skv.health_check.key');

        if ($key === null) {
            return CheckResult::fail(__('HEALTH_CHECK_KEY is empty'), 'php artisan skv:doctor --fix');
        }

        if (! HealthCheckKey::isUsable($key)) {
            return CheckResult::fail(__('HEALTH_CHECK_KEY is too short'), 'php artisan skv:doctor --fix');
        }

        return CheckResult::pass(__('HEALTH_CHECK_KEY is set'));
    }

    /**
     * Writes a key when .env has none or a short one. A usable key is never replaced, because a monitor may use it;
     * a short one cannot be in use, since the endpoint refuses it (CD-4).
     */
    public function fix(DoctorContext $context): CheckResult
    {
        if (! HealthCheckKey::isUsable($context->env()->get('HEALTH_CHECK_KEY'))) {
            $context->env()->set(['HEALTH_CHECK_KEY' => Str::random(EnvironmentStep::GENERATED['HEALTH_CHECK_KEY'])]);
        }

        $context->forgetSync();

        return $this->run($context);
    }
}
```

`tests/Arch/NameLeakTest.php` already scans `tests/`, and Task 11 widens it to `app/`. `DoctorContext::setting()` names its config key as its second argument (HealthCheckKeyCheck above, DoctorTest's 'reads a setting…'), a form the list does not allow yet. Add it to `$allowed`, right after the `config(` entry:

```php
        "/->setting\\('[A-Z_]+', 'skv\\./", // DoctorContext::setting()'s config key (CD-5)
```

Every other `skv.` key in a test stays in its own `config(['skv.…' => …]);` call, written first (Global Constraints).

`app/Setup/Doctor/Checks/MailCheck.php`: replace the docblock and `run()`:

```php
/**
 * §5.4, CD-5: with the smtp mailer, something accepts a TCP connection on the SMTP host and port within a second. A
 * runtime context (/health-check, the System page) reads the running configuration (mail.default and
 * mail.mailers.smtp.*); skv:doctor and setup read .env, the file they judge.
 */
```

```php
    public function run(DoctorContext $context): CheckResult
    {
        $mailer = $context->setting('MAIL_MAILER', 'mail.default') ?? (string) config('mail.default');

        if ($mailer === 'log') {
            return CheckResult::pass(__('Mail goes to the log'));
        }

        if ($mailer !== 'smtp') {
            return CheckResult::pass(__('Mail uses the :mailer mailer', ['mailer' => $mailer]));
        }

        $host = $context->setting('MAIL_HOST', 'mail.mailers.smtp.host') ?? '127.0.0.1';
        $address = $host.':'.(int) $context->setting('MAIL_PORT', 'mail.mailers.smtp.port');
        $socket = @stream_socket_client('tcp://'.$address, $code, $error, 1.0);

        if ($socket === false) {
            return CheckResult::fail(__('Nothing accepts mail at :address', ['address' => $address]), __("Start Herd's mail service"));
        }

        fclose($socket);

        return CheckResult::pass(__('The mail server answers at :address', ['address' => $address]));
    }
```

- [ ] **Step 4: The database outage, pg_dump and the System page's keys**

`app/Setup/Doctor/Checks/QueueCheck.php`:
- Docblock: `/** §5.4, F2: the default queue connection resolves and, for the database driver, its jobs table exists; skipped while that table's connection is the default one and the database is down. */`
- In `run()`, replace from `$table = …` to the end of the method with:

```php
        $table = (string) (config("queue.connections.{$name}.table") ?? 'jobs');
        $connection = config("queue.connections.{$name}.connection");
        $connection = is_string($connection) ? $connection : null;

        // F2: an outage is the database check's failure; a "missing table" with a migrate fix would mislead.
        if ($context->defaultConnectionDown($connection)) {
            return CheckResult::skipped(__('The database is not reachable'));
        }

        try {
            $exists = Schema::connection($connection)->hasTable($table);
        } catch (Throwable) {
            $exists = false;
        }

        return $exists
            ? CheckResult::pass(__('The :name queue works', ['name' => $name]))
            : CheckResult::fail(__('The :table table of the database queue is missing', ['table' => $table]), 'php artisan migrate');
```

`app/Setup/Doctor/Checks/FailedJobsCheck.php`:
- Docblock: append "Skipped while the failer's connection is the default one and the database is down (F2): the database check reports the outage."
- Replace the `database` / `database-uuids` branch with:

```php
        if (in_array($driver, ['database', 'database-uuids'], true)) {
            $table = (string) config('queue.failed.table', 'failed_jobs');
            $connection = config('queue.failed.database');
            $connection = is_string($connection) ? $connection : null;

            if ($context->defaultConnectionDown($connection)) {
                return CheckResult::skipped(__('The database is not reachable'));
            }

            try {
                $exists = Schema::connection($connection)->hasTable($table);
            } catch (Throwable) {
                $exists = false;
            }

            if (! $exists) {
                return CheckResult::fail(__('The :table table is missing', ['table' => $table]), 'php artisan migrate');
            }
        }
```

`config/database.php`, in `pgsql`, right after `'sslmode' => env('DB_SSLMODE', 'prefer'),`:

```php
            // F2: pdo_pgsql's connect_timeout (30 s by default). A blackholed host then costs /health-check and the
            // System page DB_CONNECT_TIMEOUT seconds once per run, because DoctorContext caches the probe.
            'options' => [
                PDO::ATTR_TIMEOUT => (int) env('DB_CONNECT_TIMEOUT', 5),
            ],
```

> The file has no namespace: write `PDO::ATTR_TIMEOUT` as is. A `use PDO;` in the global namespace is a PHP warning. Laravel's `PostgresConnector::getOptions()` merges `options` with its defaults.

`.env.example`, right after `# DB_PASSWORD=`:

```dotenv
# PostgreSQL: seconds to wait for the server before /health-check reports the database down.
DB_CONNECT_TIMEOUT=5
```

`app/Setup/Doctor/PostgresBinaries.php`:

```php
<?php

declare(strict_types=1);

namespace App\Setup\Doctor;

/**
 * Finds a PostgreSQL client binary for the `pg-dump` and `schema-tools` checks and for backup:run (§5.4, CD-6):
 * PG_DUMP_BINARY_PATH from `.env` first. Then, while the pgsql connection points at this machine (127.0.0.1,
 * localhost or ::1), Herd's newest PostgreSQL service before the toolchain PATH (DEC-23), because Herd's server is
 * then the one being dumped and an older PATH client refuses a newer server; otherwise the PATH first. A Herd
 * candidate counts only when it and every directory up to Herd's root (/Users/Shared/Herd, which sits in the
 * world-writable /Users/Shared) belong to this user or root and are neither group- nor world-writable, so no other
 * account can plant the pg_dump that backup:run executes. No version probe: the System page starts no process.
 */
final readonly class PostgresBinaries
{
    public const string HERD_SERVICES = '/Users/Shared/Herd/services/postgresql';

    /** @var list<string> the hosts that mean "this machine" (CD-6) */
    private const array LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1'];

    /**
     * @param  string  $herdServices  where Herd keeps its PostgreSQL versions, two levels below Herd's root; tests pass a sandbox
     * @param  int|null  $userId  the account a Herd candidate may belong to besides root; null is the running process's (tests pass another)
     */
    public function __construct(
        private string $herdServices = self::HERD_SERVICES,
        private ?int $userId = null,
    ) {}

    /** The absolute path of an executable $binary, or null. */
    public function find(DoctorContext $context, string $binary): ?string
    {
        $configured = $context->env()->get('PG_DUMP_BINARY_PATH') ?? '';
        $onPath = $context->toolchain->which($binary);
        $herd = $this->herdBinaries($binary);

        $candidates = [
            ...($configured !== '' ? [rtrim($configured, '/').'/'.$binary] : []),
            ...($this->serverIsLocal() ? [...$herd, $onPath] : [$onPath, ...$herd]),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return list<string> Herd's trusted $binary of each service version, newest first */
    private function herdBinaries(string $binary): array
    {
        $versions = array_values(array_filter(glob($this->herdServices.'/*/bin/'.$binary) ?: [], $this->isTrusted(...)));
        usort($versions, fn (string $a, string $b): int => version_compare(basename(dirname($b, 2)), basename(dirname($a, 2))));

        return $versions;
    }

    /** Whether the pgsql connection's host (the DB_URL host when DB_URL is set) is this machine. */
    private function serverIsLocal(): bool
    {
        $url = config('database.connections.pgsql.url');
        $host = is_string($url) && $url !== '' ? parse_url($url, PHP_URL_HOST) : config('database.connections.pgsql.host');

        return is_string($host) && in_array(strtolower(trim($host, '[]')), self::LOCAL_HOSTS, true);
    }

    /**
     * Whether $candidate resolves inside Herd's root, and it and every directory up to that root belong to this user
     * or root and only their owner can write them (CD-6, F13).
     */
    private function isTrusted(string $candidate): bool
    {
        $root = realpath(dirname($this->herdServices, 2));
        $path = realpath($candidate);

        if ($root === false || $path === false || ! str_starts_with($path, $root.'/')) {
            return false;
        }

        $owners = [0, $this->userId ?? $this->processUserId()];

        while (true) {
            $owner = fileowner($path);
            $mode = fileperms($path);

            if ($owner === false || $mode === false || ! in_array($owner, $owners, true) || ($mode & 0022) !== 0) {
                return false;
            }

            if ($path === $root) {
                return true;
            }

            $path = dirname($path);
        }
    }

    private function processUserId(): int
    {
        if (function_exists('posix_geteuid')) {
            return posix_geteuid();
        }

        $owner = getmyuid();

        return $owner === false ? -1 : $owner;
    }
}
```

> The loop ends: `$path` starts with `$root.'/'`, so `dirname()` reaches `$root`. PHP's `chmod()` clears the stat cache, so the tests' second `find()` sees the new mode.

`app/Support/Backups/ConfigurePgDump.php`, docblock only:

```php
/**
 * Before backup:run, fills an empty `database.connections.pgsql.dump.dump_binary_path` with the directory of the
 * pg_dump the doctor finds (RL-4, CD-6): PG_DUMP_BINARY_PATH, then for a server on this machine Herd's newest trusted
 * PostgreSQL service before the toolchain PATH, else the PATH first (PostgresBinaries). A cron job has no Herd PATH,
 * so without this the nightly backup would not find Herd's pg_dump.
 */
```

`app/Support/Health/SystemChecks.php`, docblock and `KEYS`:

```php
/**
 * The doctor checks the System page runs on demand (RL-7, F12): a selection of the checks that start no child process
 * and open no network connection other than the mail probe. The ones about the development machine (Herd, Node,
 * Playwright, Claude, the test database, the schema tools and the like) are left out. They run without a detection
 * (RL-2) and judge the running configuration where a setting has one (CD-5). Their repairs are not offered on the
 * page; php artisan skv:doctor --fix runs them.
 */
final readonly class SystemChecks
{
    /** @var list<string> in the doctor's order */
    public const array KEYS = [
        'php', 'gd', 'app-key', 'marker', 'apex', 'database', 'migrations', 'permissions', 'organisation-admin-role',
        'super-admin', 'super-admin-badges', 'session-driver', 'authenticate-session', 'health-check-key',
        'backup-password', 'assets', 'mail', 'pg-dump', 'queue', 'failed-jobs', 'storage-link',
    ];
```

`app/Setup/Steps/EnvironmentStep.php`. Amend Task 5's `missingSecrets()`:
- import `App\Support\Health\HealthCheckKey`
- replace the docblock and body:

```php
    /**
     * A fresh random value, of its GENERATED length, for every generated secret that is missing in $env (RL-10): blank,
     * or for HEALTH_CHECK_KEY shorter than HealthCheckKey::MIN_LENGTH, because /health-check refuses such a key, so no
     * monitor can be using it (CD-4). Setup, the production bootstrap and the backup-password repair fill the keys the
     * same way. It never writes, and never replaces a usable value: that is what a monitor or the archives use already.
     *
     * @return array<string, string>
     */
    public static function missingSecrets(EnvFile $env): array
    {
        $values = $env->all();
        $secrets = [];

        foreach (self::GENERATED as $key => $length) {
            $value = $values[$key] ?? '';

            if ($value === '' || ($key === 'HEALTH_CHECK_KEY' && ! HealthCheckKey::isUsable($value))) {
                $secrets[$key] = Str::random($length);
            }
        }

        return $secrets;
    }
```

> `isComplete()` (`self::missingSecrets($env) !== []`), `run()` (`$this->variables($context, $env) + self::missingSecrets($env)`) and `Bootstrap::run()` already go through the helper since Task 5, so they need no edit.

Lang keys, in both files, at their `sort()` position (right after `HEALTH_CHECK_KEY is set`):

| Key | nl | en |
|---|---|---|
| `HEALTH_CHECK_KEY is too short` | `HEALTH_CHECK_KEY is te kort` | `HEALTH_CHECK_KEY is too short` |

`The database is not reachable` already exists.

- [ ] **Step 5: Run the tests to verify they pass, then the mutation checks**

Run: `php artisan test --compact tests/Feature/Health tests/Feature/Setup tests/Feature/Console/DoctorCommandTest.php tests/Feature/Console/ProductionGuardsTest.php tests/Feature/Backups tests/Feature/System tests/Feature/Support tests/Feature/LangFilesTest.php tests/Feature/LangCoverageTest.php tests/Arch`

Expected: PASS.

L-08 check: run `shasum storage/logs/laravel.log 2>/dev/null`, then `php artisan test --compact --filter='names only the exception class'`, then the same `shasum` again. The two hashes are identical (or the file stays absent).

Verify by mutation (revert each locally, confirm the named test fails, restore, confirm it passes; report each):
1. **Outage skip (F2):** remove the `defaultConnectionDown()` guard from `QueueCheck`, then from `FailedJobsCheck`. "skips the queue and failed-jobs checks…" fails on each.
2. **Runtime flag (CD-5):** drop `runtime: true` from `withoutDetection()`. These fail:
   - "reads a setting…"
   - "judges the running configuration's health-check key…"
   - "probes the mail server the running configuration names…"
   - the SystemPageTest case
3. **Minimum length (CD-4):** `HealthCheckKey::isUsable()` → `is_string($key) && $key !== ''`. These fail:
   - `HealthCheckKeyTest`
   - the ChecksTest short-key row
4. **Version sort (L-02):** in `herdBinaries()`, replace the `usort` comparator with `strcmp($b, $a)`. "takes the newest Herd service by version" and the ConfigurePgDumpTest case fail (9.6 sorts first).
5. **Trust filter (F13):** remove `$this->isTrusted(...)` from the `array_filter`. The F13 dataset, the owner case and the symlink case fail.
6. **Local-host preference (F5):** make `serverIsLocal()` return `false`. "prefers Herd's newest service…" fails for the local rows.
7. **`--fix` (g2-20):** in `DoctorCommand`, pass `fix: false`. "repairs with --fix and only with --fix" fails.
8. **Green JSON exit (g2-22):** in `DoctorCommand`'s `--json` branch, `return self::FAILURE;`. "exits zero when nothing fails" fails.
9. **isGreen (g2-25):** `Doctor::isGreen()` → `return true;`. The isGreen dataset fails on its two false rows.
10. **First result kept (L-11):** in `Doctor::run()`'s fix catch, set `$fixed = CheckResult::fail(__('The check could not run (:exception).', ['exception' => 'x']));`. "keeps the first result with its fix command" fails.
11. **Short key in setup (CD-4):** in `missingSecrets()`, drop the `|| ($key === 'HEALTH_CHECK_KEY' && …)` clause. "replaces a health key too short for /health-check…" fails.

On PostgreSQL (`composer test:pgsql`), the backups module is on and the sandbox's `.env` has a blank `BACKUP_ARCHIVE_PASSWORD`. That changes no expectation above: the new DoctorCommandTest cases bind their own `Doctor`.

- [ ] **Step 6: The gate, then commit**

Run the per-task gate from the Global Constraints. This task changes no setup view, so `git diff --exit-code resources/setup/setup.css` stays clean.

```bash
git add app/Support/Health/HealthCheckKey.php app/Support/Health/SystemChecks.php app/Support/Backups/ConfigurePgDump.php \
  app/Setup/Doctor/DoctorContext.php app/Setup/Doctor/PostgresBinaries.php \
  app/Setup/Doctor/Checks/MailCheck.php app/Setup/Doctor/Checks/HealthCheckKeyCheck.php \
  app/Setup/Doctor/Checks/QueueCheck.php app/Setup/Doctor/Checks/FailedJobsCheck.php app/Setup/Steps/EnvironmentStep.php \
  config/database.php .env.example lang/nl.json lang/en.json \
  tests/Fixtures/FailingCheckWithThrowingFix.php tests/Feature/Health tests/Feature/Setup/Doctor \
  tests/Feature/Setup/Steps/EnvironmentStepTest.php \
  tests/Feature/Console/DoctorCommandTest.php tests/Feature/Backups/ConfigurePgDumpTest.php tests/Feature/System/SystemPageTest.php \
  tests/Arch/NameLeakTest.php
git commit -m "fix: keep the doctor honest during outages, on the web and with herd's pg_dump

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: `/health-check` and the ops store (F7, F14, L-28, L-29, L-27, L-30, L-16, L-17, L-23 (2), L-26, F9, L-19; RL-11, CD-4)

**Files:**
- Create: `app/Support/Health/OpsCache.php`, `tests/Feature/Health/OpsCacheTest.php`, `tests/Fixtures/UnwritableCacheStore.php`
- Modify:
  - `app/Support/Health/HealthReport.php`, `app/Support/Scheduler/Heartbeat.php`
  - `app/Http/Middleware/ThrottleHealthChecks.php`, `app/Http/Controllers/HealthCheckController.php`
- Test: `tests/Feature/Health/HealthCheckEndpointTest.php` (rewritten), `tests/Feature/Health/HealthReportTest.php`, `tests/Feature/Health/HealthChecksTest.php`

**Interfaces:**
- Consumes:
  - `HealthCheckKey::{HEADER, MIN_LENGTH, configured(), matches()}` (Task 7)
  - `HealthReport::{cached(), refresh(), run(), checks(), key()}` and its Report shape
  - `Heartbeat::{beat(), last(), CACHE_KEY}`
  - `config('skv.ops_cache_store')`, `config('skv.health_check.cache_seconds')`
  - `App\Tenancy\Hosts::{apex(), admin(), tenant(), url()}`
  - `LastBackupCheck`, `BackupDestinations`
- Produces:
  - `App\Support\Health\OpsCache::store(): Illuminate\Contracts\Cache\Repository` (RL-11). It is the only reader of `skv.ops_cache_store` in `app/`, `resources/views` and `routes/` (Task 9's System page limiter uses it too).
  - `ThrottleHealthChecks` (CD-4):
    - `PER_MINUTE = 60` and `DENIED_PER_MINUTE = 300`
    - `DENIED_BUCKET = 'health-check:denied'` and `KEY_BUCKET_PREFIX = 'health-check:ok:'`, so the key bucket is `KEY_BUCKET_PREFIX.hash('sha256', key)`
    - a 429 carries `Retry-After` and `Cache-Control: no-store`
  - `HealthCheckController` authorises with `HealthCheckKey::matches()`: a key shorter than 32 characters answers 403.
  - `HealthReport::refresh()` returns the report when the store cannot be written, and reports the error (L-30).
  - `Tests\Fixtures\UnwritableCacheStore`: an `ArrayStore` whose `put`, `forever` and `increment` throw.

- [ ] **Step 1: Write the failing tests**

`tests/Fixtures/UnwritableCacheStore.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Cache\ArrayStore;
use RuntimeException;

/** A cache store that reads (always a miss) but cannot be written: a full disk or a read-only replica (L-30). */
final class UnwritableCacheStore extends ArrayStore
{
    /**
     * @param  string  $key
     * @param  mixed  $value
     * @param  int  $seconds
     */
    public function put($key, $value, $seconds): never
    {
        throw new RuntimeException('The cache store cannot be written.');
    }

    /**
     * @param  string  $key
     * @param  mixed  $value
     */
    public function forever($key, $value): never
    {
        throw new RuntimeException('The cache store cannot be written.');
    }

    /**
     * @param  string  $key
     * @param  mixed  $value
     */
    public function increment($key, $value = 1): never
    {
        throw new RuntimeException('The cache store cannot be written.');
    }
}
```

`tests/Feature/Health/OpsCacheTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.9 */

use App\Enums\CheckStatus;
use App\Http\Middleware\ThrottleHealthChecks;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Support\Toolchain;
use App\Support\Health\HealthCheckKey;
use App\Support\Health\HealthReport;
use App\Support\Health\OpsCache;
use App\Support\Scheduler\Heartbeat;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Tests\Fixtures\FixedStatusCheck;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    $this->sandbox = setupSandbox();
    app()->instance(Toolchain::class, new Toolchain($this->sandbox->path('home')));
    app()->bind(function (): DoctorContext {
        throw new LogicException('Web code builds its doctor context with DoctorContext::withoutDetection().');
    });
    app()->instance(HealthReport::class, new HealthReport([new FixedStatusCheck(CheckStatus::Pass, 'database')], $this->sandbox->basePath));
});

it('keeps the health report, the throttle and the heartbeat on the ops store, never the default store (RL-11, F7)', function (): void {
    // A named store distinct from the default one: the suite's CACHE_STORE and OPS_CACHE_STORE are both `array`.
    $key = str_repeat('k', 40);
    config(['skv.ops_cache_store' => 'ops-test']);
    config(['skv.health_check.key' => $key]);
    config(['cache.stores.ops-test' => ['driver' => 'array']]);
    $bucket = ThrottleHealthChecks::KEY_BUCKET_PREFIX.hash('sha256', $key);
    $ops = Cache::store('ops-test');
    $default = Cache::store();

    app(Heartbeat::class)->beat();
    $report = app(HealthReport::class);
    $report->refresh();
    $this->withHeaders([HealthCheckKey::HEADER => $key])->getJson('/health-check')->assertOk();

    expect(OpsCache::store()->getStore())->toBe($ops->getStore())
        ->and($ops->get(Heartbeat::CACHE_KEY))->not->toBeNull()
        ->and($default->get(Heartbeat::CACHE_KEY))->toBeNull()
        ->and($ops->get($report->key()))->not->toBeNull()
        ->and($default->get($report->key()))->toBeNull()
        ->and(new RateLimiter($ops)->attempts($bucket))->toBe(1)
        ->and(new RateLimiter($default)->attempts($bucket))->toBe(0);
});

it('names the ops store in one place only (RL-11, F7)', function (): void {
    expect(filesMatching(kitSourceFiles('app', 'resources/views', 'routes'), '/ops_cache_store/'))->toBe(['app/Support/Health/OpsCache.php']);
});

it('runs the suite on a named array ops store, so no test writes the developer\'s cache (L-23)', function (): void {
    $store = config('skv.ops_cache_store');

    expect($store)->toBe('array')
        ->and(config('cache.stores.'.$store.'.driver'))->toBe('array');
});
```

`tests/Feature/Health/HealthCheckEndpointTest.php`, the whole file:

```php
<?php

declare(strict_types=1);

/** covers §4.9 */

use App\Enums\CheckStatus;
use App\Http\Middleware\ThrottleHealthChecks;
use App\Setup\Doctor\Check;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Support\Toolchain;
use App\Support\Health\HealthCheckKey;
use App\Support\Health\HealthReport;
use App\Support\Health\OpsCache;
use App\Tenancy\Hosts;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\FixedStatusCheck;
use Tests\Fixtures\UnwritableCacheStore;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    $this->sandbox = setupSandbox();
    app()->instance(Toolchain::class, new Toolchain($this->sandbox->path('home')));
    // RL-2: forApplication() starts herd processes and reads this repository's .env; web code never resolves it.
    app()->bind(function (): DoctorContext {
        throw new LogicException('Web code builds its doctor context with DoctorContext::withoutDetection().');
    });
    app()->instance(HealthReport::class, new HealthReport([new FixedStatusCheck(CheckStatus::Pass, 'database')], $this->sandbox->basePath));
    // 40 characters, as setup writes it: CD-4 refuses a key shorter than 32.
    $this->key = 'a-long-random-health-check-key-for-tests';
    config(['skv.health_check.key' => $this->key]);
});

it('answers 403 to a missing or wrong key, and never echoes it (§4.9)', function (?string $header): void {
    $response = $this->withHeaders($header === null ? [] : [HealthCheckKey::HEADER => $header])->getJson('/health-check');

    $response->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
    expect((string) $response->getContent())->not->toContain('a-long-random');
})->with([
    'no header' => null,
    'empty header' => '',
    'wrong key' => 'not-the-key',
    'a prefix of the key' => 'a-long-random',
]);

it('always answers 403 while the configured key is blank (§4.9)', function (?string $configured): void {
    config(['skv.health_check.key' => $configured]);

    $this->withHeaders([HealthCheckKey::HEADER => ''])->getJson('/health-check')->assertForbidden();
})->with(['empty' => '', 'unset' => null]);

it('never accepts a configured key that env() cast from HEALTH_CHECK_KEY=true (§4.9)', function (): void {
    config(['skv.health_check.key' => true]);

    $this->withHeaders([HealthCheckKey::HEADER => '1'])->getJson('/health-check')->assertForbidden();
});

it('treats a configured key shorter than 32 characters as no key, even when the header matches it (CD-4, F15)', function (string $configured, int $status): void {
    config(['skv.health_check.key' => $configured]);

    $this->withHeaders([HealthCheckKey::HEADER => $configured])->getJson('/health-check')->assertStatus($status);
})->with([
    'a word' => ['monitor', 403],
    'one short of the minimum' => [str_repeat('k', HealthCheckKey::MIN_LENGTH - 1), 403],
    'the minimum' => [str_repeat('k', HealthCheckKey::MIN_LENGTH), 200],
]);

it('answers 200 with the cached report when every check passes (§4.9, RL-3)', function (): void {
    $response = $this->withHeaders([HealthCheckKey::HEADER => $this->key])->getJson('/health-check');

    $response->assertOk()
        ->assertJsonPath('healthy', true)
        ->assertJsonPath('checks.database.status', 'pass')
        ->assertJsonStructure(['checked_at', 'healthy', 'checks' => ['database' => ['label', 'status', 'message']]]);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('answers 503 when a check fails (§4.9)', function (): void {
    app()->instance(HealthReport::class, new HealthReport([new FixedStatusCheck(CheckStatus::Fail, 'database')], $this->sandbox->basePath));

    $this->withHeaders([HealthCheckKey::HEADER => $this->key])->getJson('/health-check')
        ->assertStatus(503)
        ->assertJsonPath('healthy', false);
});

it('resolves the kit\'s own health report from the container (L-26)', function (): void {
    app()->forgetInstance(HealthReport::class);

    $checks = app(HealthReport::class)->checks();

    expect(array_map(fn (Check $check): string => $check->key(), $checks))->toBe(['database', 'queue', 'failed-jobs', 'mail', 'last-backup']);
});

it('runs outside the web group: no session, no cookies, no tenant (RL-5)', function (): void {
    $route = Route::getRoutes()->getByName('health-check');
    $onUnknownTenant = $this->withHeaders([HealthCheckKey::HEADER => $this->key])
        ->getJson('http://no-such-organisation.'.Hosts::apex().'/health-check');

    expect($route?->gatherMiddleware())->toBe([ThrottleHealthChecks::class])
        ->and($onUnknownTenant->status())->toBe(200)
        ->and($onUnknownTenant->headers->getCookies())->toBe([]);
});

it('throttles the configured key to 60 requests a minute, whatever the address, with a no-store 429 (CD-4, L-27)', function (): void {
    $this->withHeaders([HealthCheckKey::HEADER => $this->key]);

    for ($i = 1; $i <= ThrottleHealthChecks::PER_MINUTE; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$i])->getJson('/health-check')->assertOk();
    }

    $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->getJson('/health-check');

    $response->assertStatus(429)->assertExactJson(['message' => 'Too Many Attempts.']);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('counts requests without the key in one shared bucket, never one per address (CD-4, F14)', function (): void {
    $limiter = new RateLimiter(OpsCache::store());

    foreach (range(1, 20) as $host) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$host])->getJson('/health-check')->assertForbidden();
    }

    expect($limiter->attempts(ThrottleHealthChecks::DENIED_BUCKET))->toBe(20)
        ->and(array_filter(range(1, 20), fn (int $host): bool => $limiter->attempts('health-check:203.0.113.'.$host) !== 0))->toBe([]);
});

it('answers 429 to wrong keys once 300 came in a minute, and still lets the monitor in (CD-4, L-28, L-29)', function (): void {
    $limiter = new RateLimiter(OpsCache::store());

    for ($i = 1; $i < ThrottleHealthChecks::DENIED_PER_MINUTE; $i++) {
        $limiter->hit(ThrottleHealthChecks::DENIED_BUCKET, 60);
    }

    $last = $this->withHeaders([HealthCheckKey::HEADER => 'not-the-key'])->getJson('/health-check');
    $over = $this->withHeaders([HealthCheckKey::HEADER => 'not-the-key'])->getJson('/health-check');
    $monitor = $this->withHeaders([HealthCheckKey::HEADER => $this->key])->getJson('/health-check');

    $last->assertForbidden();
    $over->assertStatus(429)->assertExactJson(['message' => 'Too Many Attempts.']);
    expect($over->headers->get('Cache-Control'))->toContain('no-store');
    $monitor->assertOk();
});

it('answers 503 with JSON, never a 500, while the ops cache store is broken (CD-5)', function (): void {
    Exceptions::fake();
    config(['skv.ops_cache_store' => 'no-such-store']);

    // No Accept: application/json, as a plain uptime monitor sends it.
    $response = $this->withHeaders([HealthCheckKey::HEADER => $this->key])->get('/health-check');

    $response->assertStatus(503)->assertExactJson(['healthy' => false, 'message' => 'The health report is unavailable.']);
    expect($response->headers->get('Content-Type'))->toContain('application/json')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    Exceptions::assertReported(InvalidArgumentException::class);
});

it('still answers 403 to a wrong key while the ops cache store is broken (CD-5)', function (): void {
    Exceptions::fake();
    config(['skv.ops_cache_store' => 'no-such-store']);

    $this->withHeaders([HealthCheckKey::HEADER => 'not-the-key'])->get('/health-check')
        ->assertForbidden()
        ->assertExactJson(['message' => 'Forbidden.']);
});

it('answers with the fresh report while the ops store reads but cannot be written (L-30)', function (): void {
    Exceptions::fake();
    Cache::extend('unwritable', fn (): Repository => Cache::repository(new UnwritableCacheStore));
    config(['skv.ops_cache_store' => 'unwritable']);
    config(['cache.stores.unwritable' => ['driver' => 'unwritable']]);

    $this->withHeaders([HealthCheckKey::HEADER => $this->key])->getJson('/health-check')
        ->assertOk()
        ->assertJsonPath('healthy', true)
        ->assertJsonPath('checks.database.status', 'pass');
    Exceptions::assertReported(RuntimeException::class);
});

it('answers /up on the apex, the admin host and an organisation host, without a cookie (F9)', function (string $host): void {
    $response = $this->get(Hosts::url($host, '/up'));

    $response->assertOk();
    expect($response->headers->getCookies())->toBe([]);
})->with([
    'apex' => fn (): string => Hosts::apex(),
    'admin host' => fn (): string => Hosts::admin(),
    'organisation host' => fn (): string => Hosts::tenant('acme'),
]);

it('answers 500 on /up when a health listener fails (F9)', function (): void {
    Exceptions::fake();
    config(['app.debug' => false]);
    Event::listen(DiagnosingHealth::class, function (): never {
        throw new RuntimeException('The cache is down.');
    });

    $this->get(Hosts::url(Hosts::apex(), '/up'))->assertStatus(500);
    Exceptions::assertReported(RuntimeException::class);
});
```

> Kept from the old file:
> - the four 403 cases, the blank and `true` key cases, 200/503, the web-group case and both broken-store cases (with `$this->key`)
> - the throttle case, retitled because it now counts per key (CD-4): "throttles to 60 requests a minute per address" becomes "throttles the configured key to 60 requests a minute, whatever the address…"
>
> `/up` is not a named route, so `RouteHostMatrixTest` needs no entry.

`tests/Feature/Health/HealthReportTest.php`:
- Import `App\Support\Health\OpsCache`.
- In 'caches the report on the ops store until it is refreshed (CD-5)' and 'caches one report per language…', replace each `Cache::store((string) config('skv.ops_cache_store'))` with `OpsCache::store()`, and drop the `Cache` import if it becomes unused.
- Add:

```php
it('runs the checks again once the cached report is older than cache_seconds (CD-5, L-16)', function (): void {
    $report = new HealthReport([new FixedStatusCheck(CheckStatus::Pass, 'fine')], $this->sandbox->basePath);
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    $first = $report->cached();

    $this->travelTo(Carbon::parse('2026-10-15 10:00:29'));
    $within = $report->cached();
    $this->travelTo(Carbon::parse('2026-10-15 10:00:31'));
    $expired = $report->cached();

    expect(config('skv.health_check.cache_seconds'))->toBe(30)
        ->and($within['checked_at'])->toBe($first['checked_at'])
        ->and($expired['checked_at'])->toBe(Carbon::parse('2026-10-15 10:00:31')->toIso8601String());
});
```

`tests/Feature/Health/HealthChecksTest.php`. In 'fails without a backup or with one older than a day, and passes with a fresh one (CD-4)', extend the expectation chain right after `->and($stale->status)->toBe(CheckStatus::Fail)` with:

```php
        ->and($stale->message)->toBe(__('The last backup on :disk is from :time', ['disk' => 'backups', 'time' => Carbon::parse('2026-10-13 02:00:00')->diffForHumans()]))
        ->and($stale->fix)->toBe('php artisan backup:run --only-db')
```

Add at the end:

```php
it('fails without a configured backup disk (CD-4, L-17)', function (): void {
    useBackupsModule(true);
    config(['backup.backup.destination.disks' => []]);

    $result = new LastBackupCheck()->run($this->context);

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toBe(__('No backup disk is configured'))
        ->and($result->fix)->toBe('config/backup.php');
});

it('fails while a backup disk cannot be reached (CD-4, L-17)', function (): void {
    useBackupsModule(true);
    // An undefined disk: BackupDestination::create() catches the filesystem error and the destination is unreachable.
    config(['backup.backup.destination.disks' => ['no-such-disk']]);

    $result = new LastBackupCheck()->run($this->context);

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toBe(__('The backup disk :disk cannot be reached', ['disk' => 'no-such-disk']))
        ->and($result->fix)->toBe('config/filesystems.php');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Health`

Expected FAIL:
- `OpsCacheTest`:
  - `OpsCache` does not exist
  - three files name `ops_cache_store`
- `HealthCheckEndpointTest`:
  - "treats a configured key shorter than 32…" (the `monitor` and 31-character rows answer 200)
  - "throttles the configured key…" (per address, so the 61st request from a new address gets 200)
  - "counts requests without the key in one shared bucket" (each address has 1, the denied bucket 0)
  - "answers 429 to wrong keys…" (`$over` is 403)
  - "…cannot be written" (503 "unavailable")

These cover existing behaviour and pass already, so Step 4 checks them by mutation:
- HealthCheckEndpointTest: the L-26 case and both F9 cases
- HealthReportTest: the L-16 case
- HealthChecksTest: the L-17 cases

- [ ] **Step 3: Implement**

`app/Support/Health/OpsCache.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Health;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * The ops layer's cache store (RL-11, DEC-32 CD-5): the health report, the /health-check throttle, the scheduler
 * heartbeat and the System page's limiter. Not the default database store, so /health-check still answers while the
 * database is down. The only place that reads config('skv.ops_cache_store').
 */
final readonly class OpsCache
{
    public static function store(): Repository
    {
        return Cache::store((string) config('skv.ops_cache_store', 'file'));
    }
}
```

`app/Support/Scheduler/Heartbeat.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Scheduler;

use App\Support\Health\OpsCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * The scheduler's heartbeat (RL-8): the schedule stores the time every minute on the ops store (OpsCache), and the
 * System page shows it, so a missing cron entry is visible.
 */
final readonly class Heartbeat
{
    public const string CACHE_KEY = 'scheduler:heartbeat';

    public function beat(): void
    {
        OpsCache::store()->forever(self::CACHE_KEY, Date::now()->toIso8601String());
    }

    public function last(): ?CarbonImmutable
    {
        $beat = OpsCache::store()->get(self::CACHE_KEY);

        return is_string($beat) ? CarbonImmutable::parse($beat) : null;
    }
}
```

`app/Support/Health/HealthReport.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Health;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\Checks\DatabaseCheck;
use App\Setup\Doctor\Checks\FailedJobsCheck;
use App\Setup\Doctor\Checks\LastBackupCheck;
use App\Setup\Doctor\Checks\MailCheck;
use App\Setup\Doctor\Checks\QueueCheck;
use App\Setup\Doctor\Doctor;
use App\Setup\Doctor\DoctorContext;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * The health results of §4.9 (RL-1, RL-3): the doctor's database, queue, failed-jobs and mail checks plus the last
 * backup, run against a runtime context without detection (RL-2, CD-5) and cached briefly on the ops store
 * (OpsCache), once per locale because labels and messages are translated when it runs. /health-check and the System
 * page show the same report. It leaves the server, so it carries no fix commands. Its messages stay: the mail row
 * names the SMTP host and port, which only a holder of the key or a super admin sees (DEC-32 RL-5; O2 kept, CD-2).
 *
 * @phpstan-type Report array{checked_at: string, healthy: bool, checks: array<string, array{label: string, status: string, message: string}>}
 */
final readonly class HealthReport
{
    public const string CACHE_KEY = 'health:report';

    /**
     * @param  list<Check>|null  $checks  the checks to run; null runs the kit's (tests pass their own)
     * @param  string|null  $basePath  the project whose .env the checks read; tests pass a sandbox
     */
    public function __construct(private ?array $checks = null, private ?string $basePath = null) {}

    /** @return list<Check> */
    public function checks(): array
    {
        return $this->checks ?? [new DatabaseCheck, new QueueCheck, new FailedJobsCheck, new MailCheck, new LastBackupCheck];
    }

    /** @return Report */
    public function run(): array
    {
        $checks = $this->checks();
        $results = new Doctor($checks)->run(DoctorContext::withoutDetection($this->basePath));
        $rows = [];

        foreach ($checks as $check) {
            $result = $results[$check->key()];
            $rows[$check->key()] = ['label' => $check->label(), 'status' => $result->status->value, 'message' => $result->message];
        }

        return [
            'checked_at' => Date::now()->toIso8601String(),
            'healthy' => Doctor::isGreen($results),
            'checks' => $rows,
        ];
    }

    /** @return Report the cached report, or a fresh one that is then cached; throws when the store cannot be read */
    public function cached(): array
    {
        $cached = OpsCache::store()->get($this->key());

        if (is_array($cached) && isset($cached['checked_at'], $cached['healthy'], $cached['checks'])) {
            /** @var Report $cached */
            return $cached;
        }

        return $this->refresh();
    }

    /**
     * @return Report a fresh report, cached for the next requests. A store that cannot be written (a full disk, a
     *                read-only replica) is reported and the report still returned (L-30), so key holders get the
     *                real 200 or 503; the checks then run on each such request.
     */
    public function refresh(): array
    {
        $report = $this->run();

        try {
            OpsCache::store()->put($this->key(), $report, max(1, (int) config('skv.health_check.cache_seconds', 30)));
        } catch (Throwable $exception) {
            report($exception);
        }

        return $report;
    }

    /** The cache key of the current locale's report: labels and messages are translated when the report runs. */
    public function key(): string
    {
        return self::CACHE_KEY.':'.app()->getLocale();
    }
}
```

> Keep the Larastan-driven shapes as they are: `cached()` narrows with `is_array()` and `isset()` before the `@var` (Task 3 of M7a).

`app/Http/Middleware/ThrottleHealthChecks.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Health\HealthCheckKey;
use App\Support\Health\OpsCache;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Throttles /health-check on the ops store (OpsCache), not the default database store the `throttle` middleware uses,
 * so a database outage is reported as a 503 instead of failing the limiter. The key decides the bucket (CD-4):
 * requests with the configured key count per key, so strangers never lock the monitor out; all other requests share
 * one bucket, so key-less traffic leaves one cache entry instead of one per address. A broken ops store (an unknown
 * OPS_CACHE_STORE, redis down, a full disk) is reported and lets the request through, so it never locks monitoring
 * out; the controller then answers 503 with JSON, never a 500.
 */
final readonly class ThrottleHealthChecks
{
    /** Requests a minute with the configured key, counted per key. */
    public const int PER_MINUTE = 60;

    /** Requests a minute without a matching key, counted together. */
    public const int DENIED_PER_MINUTE = 300;

    public const string DENIED_BUCKET = 'health-check:denied';

    /** Followed by the SHA-256 of the key: the key itself never becomes a cache key. */
    public const string KEY_BUCKET_PREFIX = 'health-check:ok:';

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $limiter = new RateLimiter(OpsCache::store());
            $key = HealthCheckKey::configured();
            [$bucket, $limit] = $key !== null && HealthCheckKey::matches($request->header(HealthCheckKey::HEADER))
                ? [self::KEY_BUCKET_PREFIX.hash('sha256', $key), self::PER_MINUTE]
                : [self::DENIED_BUCKET, self::DENIED_PER_MINUTE];

            if ($limiter->tooManyAttempts($bucket, $limit)) {
                return new JsonResponse(['message' => 'Too Many Attempts.'], 429, [
                    'Retry-After' => (string) $limiter->availableIn($bucket),
                    'Cache-Control' => 'no-store',
                ]);
            }

            $limiter->hit($bucket, 60);
        } catch (Throwable $exception) {
            report($exception);
        }

        return $next($request);
    }
}
```

> `hash('sha256', …)` as CD-4 says: the security preset (`tests/Arch/PresetsTest.php`) bans `sha1` in `App`.

`app/Http/Controllers/HealthCheckController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Health\HealthCheckKey;
use App\Support\Health\HealthReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * GET /health-check (§4.9, RL-5): authorised only by the X-Health-Check-Key header, compared with hash_equals()
 * (HealthCheckKey). A configured key that is blank or shorter than 32 characters always answers 403 (CD-4). Returns
 * the cached health report: 200 when healthy, 503 when a check fails. Registered outside the web group, so it
 * carries no session, cookie, tenant or locale. When the ops store cannot be read (CD-5), the error is reported and
 * the endpoint answers 503 with JSON, never a 500. A store that reads but cannot be written still gets the fresh
 * report (HealthReport::refresh(), L-30).
 */
final readonly class HealthCheckController
{
    public function __invoke(Request $request, HealthReport $report): JsonResponse
    {
        if (! HealthCheckKey::matches($request->header(HealthCheckKey::HEADER))) {
            return new JsonResponse(['message' => 'Forbidden.'], 403, ['Cache-Control' => 'no-store']);
        }

        try {
            $result = $report->cached();
        } catch (Throwable $exception) {
            report($exception);

            return new JsonResponse(['healthy' => false, 'message' => 'The health report is unavailable.'], 503, ['Cache-Control' => 'no-store']);
        }

        return new JsonResponse($result, $result['healthy'] ? 200 : 503, ['Cache-Control' => 'no-store']);
    }
}
```

> The `Tenant::bypass()` allow-list entry for this controller (`tests/Arch/TenancyRulesTest.php`) stays. No lang keys: the endpoint's messages are fixed English JSON.

- [ ] **Step 4: Run the tests to verify they pass, then the mutation checks**

Run: `php artisan test --compact tests/Feature/Health tests/Feature/Scheduler tests/Feature/System tests/Feature/Tenancy/RouteHostMatrixTest.php tests/Arch`

Expected: PASS.

Verify by mutation (revert each locally, confirm the named test fails, restore, confirm it passes; report each):
1. **Ops store (F7):** `OpsCache::store()` → `return Cache::store();`. "keeps the health report, the throttle and the heartbeat on the ops store" fails.
2. **Key first (F14):** in the middleware, replace the ternary with `[$bucket, $limit] = [self::DENIED_BUCKET, self::DENIED_PER_MINUTE];`. "answers 429 to wrong keys…" fails (the monitor gets 429, not 200), and "throttles the configured key…" fails (the 61st key request gets 200, not 429).
3. **Per-key bucket (L-28):** in the middleware, restore the per-address bucket `'health-check:'.$request->ip()` with 60. "counts requests without the key in one shared bucket" and "answers 429 to wrong keys…" fail.
4. **No-store (L-27):** drop `'Cache-Control' => 'no-store'` from the 429. Both throttle cases fail.
5. **Minimum length (F15):** in the controller, compare `config('skv.health_check.key')` directly again. The `monitor` and 31-character rows fail.
6. **Unwritable store (L-30):** remove the `try` around `put()` in `refresh()`. "…cannot be written" fails with 503.
7. **Cache lifetime (L-16):** in `refresh()`, `put(…)` → `forever(…)`. "runs the checks again once the cached report is older than cache_seconds" fails.
8. **Unreachable disk (L-17):** in `LastBackupCheck`, drop the `isReachable()` branch. "fails while a backup disk cannot be reached" fails.
9. **Container wiring (L-26):** change `HealthReport::checks()`'s default list (drop `new MailCheck`). "resolves the kit's own health report" fails.
10. **`/up` (F9):** remove `health: '/up'` from `bootstrap/app.php`. Both `/up` cases fail with 404.
11. **Ops store pin (L-23):** remove `<env name="OPS_CACHE_STORE" value="array" force="true"/>` from `phpunit.xml`, then `php artisan config:clear`. "runs the suite on a named array ops store" fails. Restore the line.

- [ ] **Step 5: The gate, then commit**

Run the per-task gate from the Global Constraints. No setup view changes.

```bash
git add app/Support/Health/OpsCache.php app/Support/Health/HealthReport.php app/Support/Scheduler/Heartbeat.php \
  app/Http/Middleware/ThrottleHealthChecks.php app/Http/Controllers/HealthCheckController.php \
  tests/Fixtures/UnwritableCacheStore.php tests/Feature/Health
git commit -m "fix: throttle /health-check by key on one ops store and survive an unwritable cache

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: The scheduler and the System page (F1, F8, L-39 (CD-8), F4, F20 (CD-9), L-31, L-32, L-40, L-42, L-44 (CD-12), L-45 (CD-16), F26, F27)

**Files:**
- Create:
  - `app/Support/Scheduler/OpsSchedule.php`, `app/Support/Scheduler/InertEventMutex.php`
  - `app/Support/ByteSize.php`
  - `tests/Feature/Scheduler/ScheduledTasksTest.php`, `tests/Unit/ByteSizeTest.php`
- Modify:
  - `routes/console.php`, `app/Support/Scheduler/ScheduledTasks.php`
  - `resources/views/pages/system/index/index.php`, `resources/views/pages/system/index/index.blade.php`
  - `lang/nl.json`, `lang/en.json`
- Test:
  - `tests/Feature/Scheduler/ScheduleTest.php`, `tests/Feature/System/SystemPageTest.php`
  - `tests/Feature/Navigation/NavigationTest.php`, `tests/Browser/SystemPageTest.php`
  - `tests/Arch/KitRulesTest.php`

**Interfaces:**
- Consumes:
  - `App\Support\Health\OpsCache::store(): Illuminate\Contracts\Cache\Repository` (Task 8, RL-11)
  - `HealthReport::cached()` / `refresh()`, `Heartbeat::beat()` / `last()` (both on the ops store after Task 8)
  - `BackupDestinations::all()`, `SystemChecks::rows()`, `BackupEncryption::available()`, `Skv::moduleEnabled()`
  - `Illuminate\Console\Scheduling\Event::preventOverlapsUsing(EventMutex $mutex): $this` (verified: `Event.php:847`; the `withoutOverlapping()` skip closure reads `$this->mutex` when it runs, `ManagesAttributes.php:192-194`)
  - test helpers: `useBackupsModule()`, `setupSandbox()`, `asSuperAdmin()`, `onAdminHost()`, `onTenant()`, `browserUser()`; fixture `Tests\Fixtures\FixedStatusCheck`
- Produces:
  - `App\Support\Scheduler\OpsSchedule::define(Schedule $schedule): void`, which defines the eight ops events (Task 10 adds a ninth)
  - `App\Support\Scheduler\InertEventMutex implements EventMutex`:
    - `create(Event $event): bool` is always `true`
    - `exists(Event $event): bool` is always `false`
    - `forget(Event $event): void` does nothing
  - `ScheduledTasks::all(): list<array{task: string, expression: string, next: string, active: bool}>`: the same shape, now built from a fresh `Schedule` in `OpsSchedule`'s order. `next` is ISO-8601.
  - `App\Support\ByteSize::format(int|float $bytes): string`: 1024-based, units `B KB MB GB TB`, at most one decimal, `.` separator
  - The page's computed shapes:
    - `health()`: `array{available: bool, healthy: bool, checked: string, rows: list<…>}`
    - `backups()`: destinations `count: ?int`
    - `schedule()`: `array{heartbeat: ?string, heartbeatAvailable: bool, tasks: list<array{key: string, task: string, expression: string, next: string, active: bool}>}`
  - `#[Locked] public bool $rateLimited`
  - data-test hooks:
    - backups: `system-backup-newest-<disk>`, `system-backup-count-<disk>`, `system-backup-size-<disk>`
    - schedule: `system-task-<key>`, `system-task-expression-<key>`, `system-task-next-<key>`, `system-task-off-<key>`
    - page state: `system-rate-limited`, `system-health-unavailable`
    - `<key>` = `Str::slug(str_replace(':', ' ', $task))`, for example `backup-run-only-db`
  - Lang keys: `The health report is not available right now`, `The scheduler heartbeat is not available right now`, `Too many attempts. Try again in a minute.`; nl `Doctor` becomes `Diagnose`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Scheduler/ScheduledTasksTest.php` (new):

```php
<?php

declare(strict_types=1);

/** covers §4.9, §6.5 */

use App\Support\Scheduler\ScheduledTasks;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

it('lists the ops schedule from its own copy, not from the scheduler\'s instance (CD-8, L-39)', function (): void {
    // A web request has not loaded routes/console.php: an empty bound schedule stands in for that.
    app()->instance(Schedule::class, new Schedule);

    $tasks = ScheduledTasks::all();

    expect($tasks)->toHaveCount(8)
        ->and(array_column($tasks, 'expression', 'task'))->toBe([
            'activitylog:clean --force' => '0 1 * * *',
            'backup:clean' => '30 1 * * *',
            'backup:run --only-db' => '0 2 * * *',
            'backup:monitor' => '0 3 * * *',
            'queue:prune-failed' => '30 3 * * *',
            'model:prune' => '0 4 * * *',
            'auth:clear-resets' => '*/15 * * * *',
            'scheduler-heartbeat' => '* * * * *',
        ]);
});

it('reports a backup as active while its overlap lock is held, and leaves that lock alone (CD-8, F1)', function (): void {
    useBackupsModule(true);
    $running = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'backup:run'))
        ?? throw new LogicException('backup:run is not scheduled.');

    // The scheduler holds this lock (default cache store) while the nightly backup runs.
    expect($running->mutex->create($running))->toBeTrue();

    $active = array_column(ScheduledTasks::all(), 'active', 'task');

    expect($active['backup:run --only-db'])->toBeTrue()
        ->and($running->mutex->exists($running))->toBeTrue();

    $running->mutex->forget($running);
});
```

> The lock name is `sha1(expression.command)`, so a fresh copy of the event shares it. The F1 test therefore also fails if `ScheduledTasks` builds copies but drops `preventOverlapsUsing()`.

`tests/Feature/Scheduler/ScheduleTest.php`: add `use App\Support\Scheduler\ScheduledTasks;` to the imports. Then add this closure below the existing `$events` closure:

```php
/** @return list<string> every scheduled event's identifier, duplicates kept (L-31) */
$identifiers = fn (): array => array_values(array_map(
    fn (Event $event): string => $event->command !== null ? Str::after($event->command, "'artisan' ") : (string) $event->description,
    app(Schedule::class)->events(),
));
```

Replace the test 'schedules exactly the ops jobs of §4.9 (DEC-28)' with:

```php
it('schedules exactly the ops jobs of §4.9, each once (DEC-28, L-31)', function () use ($identifiers): void {
    expect($identifiers())->toHaveCount(8)->toEqualCanonicalizing([
        'activitylog:clean --force', 'backup:clean', 'backup:run --only-db', 'backup:monitor',
        'queue:prune-failed', 'model:prune', 'auth:clear-resets', 'scheduler-heartbeat',
    ]);
});

it('lists on the System page exactly what the scheduler runs, in its order (CD-8)', function () use ($identifiers): void {
    expect(array_column(ScheduledTasks::all(), 'task'))->toBe($identifiers());
});
```

Replace the test 'records a heartbeat every minute (RL-8)' with:

```php
it('records a heartbeat every minute and keeps it until the next one (RL-8, L-32)', function () use ($events): void {
    $this->travelTo(Carbon::parse('2026-10-15 12:34:00'));

    expect(app(Heartbeat::class)->last())->toBeNull();

    $events()['scheduler-heartbeat']->run(app());

    expect(app(Heartbeat::class)->last()?->toIso8601String())->toBe(Carbon::parse('2026-10-15 12:34:00')->toIso8601String());

    // Stored forever: a missing cron entry shows the old beat, never "has not run yet".
    $this->travel(10)->years();

    expect(app(Heartbeat::class)->last()?->toIso8601String())->toBe(Carbon::parse('2026-10-15 12:34:00')->toIso8601String());
});
```

`tests/Feature/System/SystemPageTest.php`. Add these imports, keeping the alphabetical order:

```php
use App\Support\ByteSize;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
```

Replace the test 'lists the backups per destination while the module is on (§4.9)' with this version (L-40, CD-9):

```php
it('lists the backups per destination while the module is on (§4.9, L-40)', function (): void {
    useBackupsModule(true);
    Storage::fake('backups');
    Storage::disk('backups')->put(config('backup.backup.name').'/'.now()->subHour()->format('Y-m-d-H-i-s').'.zip', 'archive');
    asSuperAdmin();
    onAdminHost();

    Livewire::test('pages::system.index')
        ->assertDontSee(__('The backups module is off'))
        ->assertSeeHtml('data-test="system-backup-row-backups"')
        ->assertSeeHtml('data-test="system-backup-newest-backups">'.now()->subHour()->diffForHumans().'</span>')
        ->assertSeeHtml('data-test="system-backup-count-backups">1</span>')
        ->assertSeeHtml('data-test="system-backup-size-backups">'.ByteSize::format(7).'</span>');
});
```

Append these tests to the end of the file:

```php
it('renders the schedule without booting the console kernel (CD-8, F8)', function (): void {
    asSuperAdmin();
    onAdminHost();
    $kernel = $this->spy(Kernel::class);

    $this->get(route('system.index'))->assertOk()
        ->assertSeeHtml('data-test="system-task-backup-run-only-db"')
        ->assertSeeHtml('data-test="system-task-scheduler-heartbeat"');

    $kernel->shouldNotHaveReceived('bootstrap');
    $kernel->shouldNotHaveReceived('all');
});

it('shows the health report and the heartbeat as unavailable while the ops store is broken, never a 500 (F4)', function (): void {
    Exceptions::fake();
    config(['skv.ops_cache_store' => 'no-such-store']);
    asSuperAdmin();
    onAdminHost();

    $this->get(route('system.index'))->assertOk()
        ->assertSee(__('The health report is not available right now'))
        ->assertSee(__('The scheduler heartbeat is not available right now'))
        ->assertDontSee(__('The scheduler has not run yet'))
        ->assertSeeHtml('data-test="system-task-backup-run-only-db"');

    Livewire::test('pages::system.index')
        ->call('refreshHealth')
        ->assertSee(__('The health report is not available right now'));

    Exceptions::assertReported(InvalidArgumentException::class);
});

it('shows a backup disk that fails after answering as unreachable, never a 500 (F4)', function (): void {
    Exceptions::fake();
    useBackupsModule(true);
    // The health section keeps off this disk: LastBackupCheck reads the backups disk too.
    app()->instance(HealthReport::class, new HealthReport([new FixedStatusCheck(CheckStatus::Pass, 'probe')], $this->sandbox->basePath));
    $archive = config('backup.backup.name').'/'.now()->subHour()->format('Y-m-d-H-i-s').'.zip';
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('files')->andReturn([]);
    $disk->shouldReceive('allFiles')->andReturn([$archive]);
    $disk->shouldReceive('exists')->andReturn(true);
    $disk->shouldReceive('size')->andThrow(new RuntimeException('The remote disk timed out.'));
    Storage::set('backups', $disk);
    asSuperAdmin();
    onAdminHost();

    $this->get(route('system.index'))->assertOk()
        ->assertSeeHtml('data-test="system-backup-row-backups"')
        ->assertSee(__('Not reachable'))
        ->assertSeeHtml('data-test="system-backup-count-backups">—</span>')
        ->assertSeeHtml('data-test="system-backup-size-backups">—</span>');

    Exceptions::assertReported(RuntimeException::class);
});

it('shows each task\'s cron, next run and whether it is off (L-42)', function (bool $enabled): void {
    $this->travelTo(Carbon::parse('2026-10-16 12:00:00'));
    useBackupsModule($enabled);
    asSuperAdmin();
    onAdminHost();

    $page = Livewire::test('pages::system.index')
        ->assertSeeHtml('data-test="system-task-expression-backup-run-only-db">0 2 * * *</code>')
        ->assertSeeHtml('data-test="system-task-next-backup-run-only-db">'.Carbon::parse('2026-10-17 02:00:00')->diffForHumans().'</span>')
        ->assertDontSeeHtml('data-test="system-task-off-activitylog-clean-force"');

    if ($enabled) {
        $page->assertDontSeeHtml('data-test="system-task-off-backup-run-only-db"');
    } else {
        $page->assertSeeHtml('data-test="system-task-off-backup-run-only-db"');
    }
})->with(['module on' => true, 'module off' => false]);

it('allows six doctor runs a minute per user, counted on the ops store (CD-16, L-45)', function (): void {
    config(['skv.ops_cache_store' => 'ops-test']);
    config(['cache.stores.ops-test' => ['driver' => 'array']]);
    // CD-5: the page's health-check-key row judges the running configuration, never the sandbox .env, so the
    // developer's own HEALTH_CHECK_KEY never decides this test.
    config(['skv.health_check.key' => '']);
    $admin = asSuperAdmin();
    onAdminHost();
    $page = Livewire::test('pages::system.index');

    for ($run = 0; $run < 6; $run++) {
        $page->call('runDoctor')->assertDontSee(__('Too many attempts. Try again in a minute.'));
    }

    // The seventh run would see a usable key; a refused run keeps the old rows.
    config(['skv.health_check.key' => str_repeat('k', 40)]);
    $page->call('runDoctor')->assertSee(__('Too many attempts. Try again in a minute.'));
    /** @var list<array{key: string, label: string, status: string, message: string}> $rows */
    $rows = $page->get('doctorRows');

    expect(array_column($rows, 'status', 'key')['health-check-key'])->toBe(CheckStatus::Fail->value)
        ->and(new RateLimiter(Cache::store('ops-test'))->attempts('system:runDoctor:'.$admin->id))->toBe(6)
        ->and(new RateLimiter(Cache::store())->attempts('system:runDoctor:'.$admin->id))->toBe(0);

    // The refresh has its own budget.
    app()->instance(HealthReport::class, new HealthReport([new FixedStatusCheck(CheckStatus::Fail, 'probe')], $this->sandbox->basePath));
    $page->call('refreshHealth')->assertSee('broken')->assertDontSee(__('Too many attempts. Try again in a minute.'));
});

it('keeps the health rows when the seventh refresh in a minute is refused (CD-16, L-45)', function (): void {
    asSuperAdmin();
    onAdminHost();
    $page = Livewire::test('pages::system.index');

    for ($run = 0; $run < 6; $run++) {
        $page->call('refreshHealth');
    }

    app()->instance(HealthReport::class, new HealthReport([new FixedStatusCheck(CheckStatus::Fail, 'probe')], $this->sandbox->basePath));

    $page->call('refreshHealth')
        ->assertSee(__('Too many attempts. Try again in a minute.'))
        ->assertDontSee('broken');
});

it('names the doctor section apart from the check column in Dutch (CD-12, L-44)', function (): void {
    app()->setLocale('nl');

    expect(__('Doctor'))->toBe('Diagnose')
        ->and(__('Check'))->toBe('Controle');
});
```

> The 'unavailable' test runs `refreshHealth` with the store still broken. The limiter fails open (it reports and lets the call through), `refresh()` is guarded, and `health()` then shows "unavailable" again.
>
> The CD-16 doctor test sets `skv.health_check.key` through `config()`, never the sandbox `.env`: since Task 7 (CD-5) the page's checks run in a runtime context and read the configuration, which the suite loads from the developer's own `.env` (phpunit.xml pins no `HEALTH_CHECK_KEY`). `EnvFile` stays imported for Task 7's test. Each `skv.*` key gets its own `config(['skv.…' => …]);` call, because `NameLeakTest` (which scans `tests/`) allows `skv.` only right after `config(['` or `config('`.

`tests/Feature/Navigation/NavigationTest.php`: append:

```php
it('hides System status from a super admin on a tenant host (§6.2, F26)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    asSuperAdmin();
    onTenant($organisation);

    $html = (string) $this->get(route('dashboard'))->assertOk()->getContent();
    preg_match_all('/data-test="nav-([a-z.]+)"/', $html, $matches);

    expect($matches[1])->toContain('dashboard')->not->toContain('system.index');
});
```

`tests/Browser/SystemPageTest.php`: add `use Illuminate\Support\Facades\Process;` and `use Illuminate\Support\Str;` to the imports, and make these the first lines of the test body (F27):

```php
    // RL-7: the page's doctor starts no process; a check that tries gets a 'could not run' row, which fails below (F27).
    Process::preventStrayProcesses();
    Process::fake([]);
```

At the end of the test body, after the `$page->click('@system-run-checks')…->assertNoJavaScriptErrors();` chain, append:

```php
    // Doctor::run() turns a check's exception (a stray process too) into a 'could not run' row; none may appear.
    foreach (['en', 'nl'] as $locale) {
        $page->assertDontSee(Str::before(__('The check could not run (:exception).', locale: $locale), '('));
    }
```

> The fake alone cannot fail the test: `Doctor::run()` catches every `Throwable` from a check and renders it as a row, so the page still shows `@system-doctor-row-database`. The row text is checked in both locales, because the browser's locale is not pinned.

`tests/Arch/KitRulesTest.php`: append:

```php
it('formats sizes without ext-intl: no Illuminate\Support\Number in app code or views (CD-9, F20)', function (): void {
    expect(filesMatching(kitSourceFiles('app', 'resources/views'), '/Illuminate\\\\Support\\\\Number\b|(?<![\w\\\\$>:])Number::/'))->toBe([]);
});
```

`tests/Unit/ByteSizeTest.php` (new):

```php
<?php

declare(strict_types=1);

/** covers §6.5 */

use App\Support\ByteSize;

it('formats a size in 1024-based units with at most one decimal, without ext-intl (CD-9, F20)', function (int|float $bytes, string $expected): void {
    expect(ByteSize::format($bytes))->toBe($expected);
})->with([
    'nothing' => [0, '0 B'],
    'a few bytes' => [7, '7 B'],
    'just under a kilobyte' => [1023, '1023 B'],
    'a kilobyte' => [1024, '1 KB'],
    'one and a half kilobytes' => [1536, '1.5 KB'],
    'rounded to one decimal' => [1100, '1.1 KB'],
    'rounding into the next unit' => [1048575, '1 MB'],
    'a float, as usedStorage() returns' => [2.5 * 1024 ** 2, '2.5 MB'],
    'gigabytes' => [5 * 1024 ** 3, '5 GB'],
    'terabytes' => [1.5 * 1024 ** 4, '1.5 TB'],
    'beyond terabytes' => [2048 * 1024 ** 4, '2048 TB'],
    'a negative size' => [-5, '0 B'],
]);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run:

```bash
php artisan test --compact tests/Feature/Scheduler tests/Feature/System tests/Feature/Navigation tests/Arch/KitRulesTest.php tests/Unit/ByteSizeTest.php
```

Expected: FAIL.
- `ScheduledTasksTest` 'lists…' fails: the bound empty schedule gives `[]`.
- 'reports a backup as active…' gets `false`.
- `ScheduleTest` 'lists on the System page…' passes already: it is a parity guard. 'records a heartbeat…' passes already: it pins the TTL against a later regression (L-32).
- `SystemPageTest` fails in these places:
  - the new data-test hooks are missing
  - `bootstrap` was received
  - the broken store answers 500
  - the failing `size()` answers 500
  - no `Too many attempts` message
  - `Doctor` is `Controle`
- NavigationTest F26 passes already. It is a guard; the mutation in Step 8 proves it can fail.
- The arch rule lists `resources/views/pages/system/index/index.php`, and `ByteSize` does not exist.

- [ ] **Step 3: The ops schedule and the inert mutex (CD-8)**

`app/Support/Scheduler/OpsSchedule.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Scheduler;

use App\Support\Backups\BackupEncryption;
use App\Support\Skv;
use Illuminate\Console\Scheduling\Schedule;

/**
 * The ops schedule of §4.9 (CD-8). routes/console.php defines it on the scheduler's Schedule, and ScheduledTasks
 * defines it again on a fresh one for the System page, so a page render never boots the console kernel.
 *
 * Each job runs on one server and never twice at once. The backup jobs run only while the backups module is on (§6.8;
 * always off on SQLite), and backup:run only while it would encrypt the archive (DEC-32 CD-10): the package writes a
 * plaintext dump for a blank password and still exits 0. backup:monitor keeps running, so a missing backup is still
 * reported, and last-backup fails on /health-check. activitylog:clean asks for confirmation in production, hence
 * --force. model:prune removes expired handoff tokens (HandoffToken is the only prunable model).
 */
final readonly class OpsSchedule
{
    public static function define(Schedule $schedule): void
    {
        $backupsEnabled = fn (): bool => Skv::moduleEnabled('backups');
        $encryptedBackupsEnabled = fn (): bool => Skv::moduleEnabled('backups') && BackupEncryption::available();

        $schedule->command('activitylog:clean --force')->dailyAt('01:00')->onOneServer()->withoutOverlapping();
        $schedule->command('backup:clean')->dailyAt('01:30')->when($backupsEnabled)->onOneServer()->withoutOverlapping();
        $schedule->command('backup:run --only-db')->dailyAt('02:00')->when($encryptedBackupsEnabled)->onOneServer()->withoutOverlapping();
        $schedule->command('backup:monitor')->dailyAt('03:00')->when($backupsEnabled)->onOneServer()->withoutOverlapping();
        $schedule->command('queue:prune-failed')->dailyAt('03:30')->onOneServer()->withoutOverlapping();
        $schedule->command('model:prune')->dailyAt('04:00')->onOneServer()->withoutOverlapping();
        $schedule->command('auth:clear-resets')->everyFifteenMinutes()->onOneServer()->withoutOverlapping();

        // RL-8: the heartbeat the System page shows.
        $schedule->call(function (): void {
            app(Heartbeat::class)->beat();
        })->everyMinute()->name('scheduler-heartbeat')->onOneServer();
    }
}
```

`app/Support/Scheduler/InertEventMutex.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Scheduler;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;

/**
 * The mutex of the System page's schedule copies (CD-8, F1). It never holds a lock and never sees one, so reading a
 * copy's filters neither takes nor releases the real overlap lock of a running job: `active` reports the module and
 * encryption conditions, not whether the job happens to run right now.
 */
final readonly class InertEventMutex implements EventMutex
{
    public function create(Event $event): bool
    {
        return true;
    }

    public function exists(Event $event): bool
    {
        return false;
    }

    public function forget(Event $event): void {}
}
```

`routes/console.php` (the whole file):

```php
<?php

declare(strict_types=1);

use App\Support\Scheduler\OpsSchedule;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// §4.9, CD-8: the ops schedule lives in OpsSchedule, so the System page lists it without booting this kernel.
OpsSchedule::define(app(Schedule::class));
```

`app/Support/Scheduler/ScheduledTasks.php` (the whole file):

```php
<?php

declare(strict_types=1);

namespace App\Support\Scheduler;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;

/**
 * The ops schedule as the System page lists it (§4.9, RL-6, CD-8). It defines OpsSchedule on a fresh Schedule (in the
 * timezone the console kernel uses), so a render never boots the console kernel, and gives every copy an inert mutex,
 * so `active` never takes or releases the real overlap lock of a running job (F1).
 */
final readonly class ScheduledTasks
{
    /** @return list<array{task: string, expression: string, next: string, active: bool}> in OpsSchedule's order */
    public static function all(): array
    {
        $schedule = new Schedule(config('app.schedule_timezone', config('app.timezone')));
        OpsSchedule::define($schedule);
        $mutex = new InertEventMutex;

        return array_values(array_map(fn (Event $event): array => [
            'task' => $event->command !== null ? trim(Str::after($event->command, 'artisan'), " '") : (string) $event->description,
            'expression' => $event->expression,
            'next' => $event->nextRunDate()->toIso8601String(),
            'active' => $event->preventOverlapsUsing($mutex)->filtersPass(app()),
        ], $schedule->events()));
    }
}
```

> `new Schedule()` resolves `CacheEventMutex` and `CacheSchedulingMutex` from the container, but neither touches a store until it is used. `Schedule::command()` only formats the command line (`PhpExecutableFinder` checks paths and starts no process).

- [ ] **Step 4: `ByteSize` (CD-9)**

`app/Support/ByteSize.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A size in bytes for people (CD-9, F20): 1024-based, B to TB, at most one decimal with a point. Laravel's own
 * file-size helper needs ext-intl, which the kit does not require.
 */
final readonly class ByteSize
{
    private const array UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

    public static function format(int|float $bytes): string
    {
        $value = max(0.0, (float) $bytes);
        $unit = 0;

        // Rounded first, so 1023.96 KB reads as 1 MB, never as 1024 KB.
        while ($unit < count(self::UNITS) - 1 && round($value, 1) >= 1024) {
            $value /= 1024;
            $unit++;
        }

        $rounded = round($value, 1);

        return number_format($rounded, floor($rounded) === $rounded ? 0 : 1, '.', '').' '.self::UNITS[$unit];
    }
}
```

> The `Number` arch rule reads raw file contents, comments included. Never write `Number::` or `Illuminate\Support\Number` in a comment or docblock under `app/` or `resources/views`; name Laravel's helper in words, as this docblock does.

- [ ] **Step 5: The page**

`resources/views/pages/system/index/index.php` (the whole file):

```php
<?php

declare(strict_types=1);

use App\Authorization\Permission;
use App\Enums\CheckStatus;
use App\Support\Backups\BackupDestinations;
use App\Support\ByteSize;
use App\Support\Health\HealthReport;
use App\Support\Health\OpsCache;
use App\Support\Health\SystemChecks;
use App\Support\Scheduler\Heartbeat;
use App\Support\Scheduler\ScheduledTasks;
use App\Support\Skv;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('System status')] class extends Component
{
    /** CD-16: the calls each user may make per action and minute. */
    private const int ATTEMPTS_PER_MINUTE = 6;

    /** @var list<array{key: string, label: string, status: string, message: string}>|null the last on-demand doctor run (RL-6) */
    #[Locked]
    public ?array $doctorRows = null;

    /** CD-16: the last refresh or doctor run was refused for too many attempts. */
    #[Locked]
    public bool $rateLimited = false;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        Gate::authorize(Permission::SystemHealth);
    }

    /** Runs the health checks again and caches the new report (§4.9), at most six times a minute (CD-16). */
    public function refreshHealth(): void
    {
        Gate::authorize(Permission::SystemHealth);

        $this->withinRateLimit('refreshHealth', function (): void {
            try {
                app(HealthReport::class)->refresh();
            } catch (Throwable $exception) {
                // F4: a broken ops store shows the report as unavailable, never a 500.
                report($exception);
            }

            unset($this->health);
        });
    }

    /** Runs the web-safe doctor checks (RL-7), at most six times a minute (CD-16). */
    public function runDoctor(): void
    {
        Gate::authorize(Permission::SystemHealth);

        $this->withinRateLimit('runDoctor', function (): void {
            $this->doctorRows = app(SystemChecks::class)->rows();
        });
    }

    /**
     * The cached health report as display rows; PHP code calls this method, never the magic computed property.
     *
     * @return array{available: bool, healthy: bool, checked: string, rows: list<array{key: string, label: string, status: string, color: string, message: string}>}
     */
    #[Computed]
    public function health(): array
    {
        try {
            $report = app(HealthReport::class)->cached();
        } catch (Throwable $exception) {
            // F4: the ops store cannot be read; the rest of the page still renders.
            report($exception);

            return ['available' => false, 'healthy' => false, 'checked' => '', 'rows' => []];
        }

        $rows = [];

        foreach ($report['checks'] as $key => $check) {
            $status = CheckStatus::from($check['status']);
            $rows[] = ['key' => $key, 'label' => $check['label'], 'status' => $status->label(), 'color' => $status->color(), 'message' => $check['message']];
        }

        return ['available' => true, 'healthy' => $report['healthy'], 'checked' => Carbon::parse($report['checked_at'])->diffForHumans(), 'rows' => $rows];
    }

    /**
     * @return array{enabled: bool, destinations: list<array{disk: string, reachable: bool, count: ?int, newest: ?string, size: ?string}>}
     */
    #[Computed]
    public function backups(): array
    {
        if (! Skv::moduleEnabled('backups')) {
            return ['enabled' => false, 'destinations' => []];
        }

        $destinations = [];

        foreach (BackupDestinations::all() as $destination) {
            try {
                $reachable = $destination->isReachable();
                $newest = $reachable ? $destination->newestBackup() : null;
                $destinations[] = [
                    'disk' => $destination->diskName(),
                    'reachable' => $reachable,
                    'count' => $reachable ? $destination->backups()->count() : null,
                    'newest' => $newest?->date()->diffForHumans(),
                    'size' => $reachable ? ByteSize::format($destination->usedStorage()) : null,
                ];
            } catch (Throwable $exception) {
                // F4: a remote disk that fails after answering isReachable() reads as unreachable, never a 500.
                report($exception);
                $destinations[] = ['disk' => $destination->diskName(), 'reachable' => false, 'count' => null, 'newest' => null, 'size' => null];
            }
        }

        return ['enabled' => true, 'destinations' => $destinations];
    }

    /**
     * @return array{heartbeat: ?string, heartbeatAvailable: bool, tasks: list<array{key: string, task: string, expression: string, next: string, active: bool}>}
     */
    #[Computed]
    public function schedule(): array
    {
        $tasks = array_map(fn (array $task): array => [
            ...$task,
            'key' => Str::slug(str_replace(':', ' ', $task['task'])),
            'next' => Carbon::parse($task['next'])->diffForHumans(),
        ], ScheduledTasks::all());

        try {
            $heartbeat = app(Heartbeat::class)->last()?->diffForHumans();
        } catch (Throwable $exception) {
            // F4: an unreadable ops store is not "the scheduler has not run yet".
            report($exception);

            return ['heartbeat' => null, 'heartbeatAvailable' => false, 'tasks' => $tasks];
        }

        return ['heartbeat' => $heartbeat, 'heartbeatAvailable' => true, 'tasks' => $tasks];
    }

    /**
     * The doctor rows with their translated status.
     *
     * @return list<array{key: string, label: string, status: string, color: string, message: string}>
     */
    #[Computed]
    public function doctor(): array
    {
        return array_map(function (array $row): array {
            $status = CheckStatus::from($row['status']);

            return [...$row, 'status' => $status->label(), 'color' => $status->color()];
        }, $this->doctorRows ?? []);
    }

    /**
     * CD-16: runs $callback at most ATTEMPTS_PER_MINUTE times a minute per user and action, counted on the ops store
     * (RL-11), as RateLimiter::attempt() does. Above that the page keeps its rows and says so. A broken ops store lets
     * the call through (reported), as /health-check's throttle fails open (F4).
     */
    private function withinRateLimit(string $action, Closure $callback): void
    {
        $key = 'system:'.$action.':'.Auth::id();

        try {
            $limiter = new RateLimiter(OpsCache::store());

            if ($limiter->tooManyAttempts($key, self::ATTEMPTS_PER_MINUTE)) {
                $this->rateLimited = true;

                return;
            }

            $limiter->hit($key, 60);
        } catch (Throwable $exception) {
            report($exception);
        }

        $this->rateLimited = false;
        $callback();
    }
};
```

> The file has no namespace, so `Closure` and `Throwable` are global names and take no `use` line (a `use Closure;` in the global namespace only warns). The two actions authorise before the limiter, so 'refuses an organisation admin' and 're-authorises the actions' keep their meaning.

`resources/views/pages/system/index/index.blade.php` (the whole file):

```blade
<section class="w-full space-y-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('System status') }}</flux:heading>
        <flux:subheading size="lg" class="mb-6">{{ __('See health checks, backups and the scheduler') }}</flux:subheading>
        <flux:separator variant="subtle" />
    </div>

    @if ($rateLimited)
        {{-- CD-16: the last refresh or doctor run was refused; the rows below are unchanged. --}}
        <div data-test="system-rate-limited">
            <flux:callout variant="warning" icon="clock" heading="{{ __('Too many attempts. Try again in a minute.') }}" />
        </div>
    @endif

    {{-- §4.9: the same cached report /health-check returns. --}}
    <section class="space-y-3" data-test="system-health">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <flux:heading size="lg" level="2">{{ __('Health') }}</flux:heading>
                @if ($this->health['available'])
                    <flux:badge size="sm" :color="$this->health['healthy'] ? 'lime' : 'red'">
                        {{ $this->health['healthy'] ? __('Healthy') : __('Unhealthy') }}
                    </flux:badge>
                    <flux:text size="sm">{{ __('Checked :time', ['time' => $this->health['checked']]) }}</flux:text>
                @endif
            </div>
            <flux:button size="sm" icon="arrow-path" wire:click="refreshHealth" data-test="system-refresh-health">{{ __('Refresh') }}</flux:button>
        </div>

        @if (! $this->health['available'])
            {{-- F4: the ops store cannot be read. --}}
            <div data-test="system-health-unavailable">
                <flux:text>{{ __('The health report is not available right now') }}</flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Check') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Details') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->health['rows'] as $row)
                        <flux:table.row :key="'health-'.$row['key']" data-test="system-health-row-{{ $row['key'] }}">
                            <flux:table.cell variant="strong">{{ $row['label'] }}</flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" :color="$row['color']">{{ $row['status'] }}</flux:badge></flux:table.cell>
                            <flux:table.cell>{{ $row['message'] }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </section>

    <section class="space-y-3" data-test="system-backups">
        <flux:heading size="lg" level="2">{{ __('Backups') }}</flux:heading>

        @if (! $this->backups['enabled'])
            <flux:text>{{ __('The backups module is off') }}</flux:text>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Disk') }}</flux:table.column>
                    <flux:table.column>{{ __('Newest backup') }}</flux:table.column>
                    <flux:table.column>{{ __('Backups') }}</flux:table.column>
                    <flux:table.column>{{ __('Used storage') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->backups['destinations'] as $destination)
                        <flux:table.row :key="'backup-'.$destination['disk']" data-test="system-backup-row-{{ $destination['disk'] }}">
                            <flux:table.cell variant="strong">{{ $destination['disk'] }}</flux:table.cell>
                            <flux:table.cell>
                                @if (! $destination['reachable'])
                                    <flux:badge size="sm" color="red">{{ __('Not reachable') }}</flux:badge>
                                @else
                                    <span data-test="system-backup-newest-{{ $destination['disk'] }}">{{ $destination['newest'] ?? __('None yet') }}</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell><span data-test="system-backup-count-{{ $destination['disk'] }}">{{ $destination['count'] ?? '—' }}</span></flux:table.cell>
                            <flux:table.cell><span data-test="system-backup-size-{{ $destination['disk'] }}">{{ $destination['size'] ?? '—' }}</span></flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </section>

    <section class="space-y-3" data-test="system-schedule">
        <div class="flex flex-wrap items-center gap-3">
            <flux:heading size="lg" level="2">{{ __('Schedule') }}</flux:heading>
            <flux:text size="sm">
                @if (! $this->schedule['heartbeatAvailable'])
                    {{ __('The scheduler heartbeat is not available right now') }}
                @elseif ($this->schedule['heartbeat'] === null)
                    {{ __('The scheduler has not run yet') }}
                @else
                    {{ __('Last heartbeat :time', ['time' => $this->schedule['heartbeat']]) }}
                @endif
            </flux:text>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Task') }}</flux:table.column>
                <flux:table.column>{{ __('When') }}</flux:table.column>
                <flux:table.column>{{ __('Next run') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->schedule['tasks'] as $task)
                    <flux:table.row :key="'task-'.$task['key']" data-test="system-task-{{ $task['key'] }}">
                        <flux:table.cell variant="strong">
                            <code class="text-sm">{{ $task['task'] }}</code>
                            @if (! $task['active'])
                                <span data-test="system-task-off-{{ $task['key'] }}"><flux:badge size="sm" color="zinc">{{ __('off') }}</flux:badge></span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell><code class="text-sm" data-test="system-task-expression-{{ $task['key'] }}">{{ $task['expression'] }}</code></flux:table.cell>
                        <flux:table.cell><span data-test="system-task-next-{{ $task['key'] }}">{{ $task['next'] }}</span></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </section>

    <section class="space-y-3" data-test="system-doctor">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:heading size="lg" level="2">{{ __('Doctor') }}</flux:heading>
                <flux:text size="sm">{{ __('The checks that start no process. Run php artisan skv:doctor for the full report and its repairs.') }}</flux:text>
            </div>
            <flux:button size="sm" icon="play" wire:click="runDoctor" data-test="system-run-checks">{{ __('Run checks') }}</flux:button>
        </div>

        @if ($this->doctor !== [])
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Check') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Details') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->doctor as $row)
                        <flux:table.row :key="'doctor-'.$row['key']" data-test="system-doctor-row-{{ $row['key'] }}">
                            <flux:table.cell variant="strong">{{ $row['label'] }}</flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" :color="$row['color']">{{ $row['status'] }}</flux:badge></flux:table.cell>
                            <flux:table.cell>{{ $row['message'] }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </section>
</section>
```

> `flux:callout` is a free component (the settings pages use it). The data-test hooks sit on plain `<span>`/`<code>`/`<div>` elements so the tests can match exact HTML. Flux components may reorder attributes.

- [ ] **Step 6: Copy**

`lang/en.json` and `lang/nl.json`. Insert each new key at its PHP `sort()` position. On `main` that is the position given below; Tasks 1–8 may have added neighbours, so re-check the order.

| Key | en | nl | Position on `main` |
|---|---|---|---|
| `The health report is not available right now` | same | `Het gezondheidsrapport is nu niet beschikbaar` | after `The gd extension is missing` |
| `The scheduler heartbeat is not available right now` | same | `De hartslag van de planner is nu niet beschikbaar` | after `The scheduler has not run yet` |
| `Too many attempts. Try again in a minute.` | same | `Te veel pogingen. Probeer het over een minuut opnieuw.` | after `Toggle sidebar` |

In `lang/nl.json` only, change the value of `"Doctor"` from `"Controle"` to `"Diagnose"` (CD-12, L-44). The key and its position stay.

- [ ] **Step 7: Run the tests to verify they pass**

Run:
- `php artisan test --compact tests/Feature/Scheduler tests/Feature/System tests/Feature/Navigation tests/Feature/Tenancy/RouteHostMatrixTest.php tests/Feature/LangFilesTest.php tests/Feature/LangCoverageTest.php tests/Arch tests/Unit/ByteSizeTest.php`
- `php artisan schedule:list`. Expected: the same eight rows as before (it only reads).
- `npm run build && php artisan config:clear && vendor/bin/pest --testsuite=Browser --filter=SystemPage`

Expected: PASS.

- [ ] **Step 8: Verify by mutation**

Revert each change locally, confirm the named test fails, then restore it and confirm it passes. The report lists each mutation and its result:

| Mutation | Test that must fail |
|---|---|
| Drop `->preventOverlapsUsing($mutex)` in `ScheduledTasks` | 'reports a backup as active while its overlap lock is held…' |
| Use `app(Schedule::class)->events()` instead of the fresh schedule | 'lists the ops schedule from its own copy…' |
| Re-add `app(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();` at the top of `ScheduledTasks::all()` | 'renders the schedule without booting the console kernel' |
| Add a second `backup:run --only-db` line in `OpsSchedule` | 'schedules exactly the ops jobs…, each once' |
| `put(self::CACHE_KEY, …, 60)` instead of `forever()` in `Heartbeat::beat()` | 'records a heartbeat … keeps it until the next one' |
| Remove the `@if (! $task['active'])` badge | 'shows each task's cron, next run and whether it is off' (module off) |
| Call `$callback()` directly in `withinRateLimit()` | both CD-16 tests |
| Remove `centralOnly: true` from the `system.index` item | 'hides System status from a super admin on a tenant host' |
| Add `'node'` to `SystemChecks::KEYS` (NodeCheck starts `node -v` wherever Node is on the toolchain PATH, e.g. Homebrew's `/opt/homebrew/bin`) | the browser 'lets a super admin read the system status…' (a 'could not run' row) |

- [ ] **Step 9: The gate, then commit**

Run the full per-task gate (Global Constraints). Then:

```bash
git add app/Support/ByteSize.php app/Support/Scheduler routes/console.php resources/views/pages/system lang \
    tests/Feature/Scheduler tests/Feature/System tests/Feature/Navigation/NavigationTest.php \
    tests/Browser/SystemPageTest.php tests/Arch/KitRulesTest.php tests/Unit/ByteSizeTest.php
git commit -m "fix: list the ops schedule without the console kernel and harden the system page

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Backups (L-01, L-03, L-05, L-07, F6, F18 (CD-10), O1 (CD-7), F19, F23, F28, L-23 (1))

**Files:**
- Create:
  - `app/Console/Commands/BackupRunCommand.php`, `app/Support/Backups/PrivateBackupTemp.php`
  - `tests/Feature/Backups/BackupRunCommandTest.php`, `tests/Feature/Backups/PrivateBackupTempTest.php`, `tests/Feature/Backups/BackupEncryptionTest.php`
- Modify:
  - `config/backup.php` (L-01)
  - `app/Support/Backups/ConfigurePgDump.php` (L-05), `app/Support/Backups/RefuseUnencryptedBackups.php` (O1), `app/Support/Backups/BackupEncryption.php` (L-23)
  - `app/Support/Scheduler/OpsSchedule.php` (F6, F18), `app/Providers/AppServiceProvider.php` (L-07)
- Test:
  - `tests/Feature/Backups/BackupConfigTest.php`, `tests/Feature/Backups/ConfigurePgDumpTest.php`, `tests/Feature/Backups/RefuseUnencryptedBackupsTest.php`, `tests/Feature/Backups/BackupRunTest.php`
  - `tests/Feature/Scheduler/ScheduleTest.php`, `tests/Feature/Scheduler/ScheduledTasksTest.php` (Task 9's counts, 8 → 9)
  - `tests/Arch/KitRulesTest.php`

**Interfaces:**
- Consumes:
  - `OpsSchedule::define()`, `ScheduledTasks::all()` (Task 9)
  - `BackupEncryption::available()`, `PostgresBinaries::find()`, `Toolchain::environment()`
  - `Spatie\Backup\Commands\BackupCommand` (signature `backup:run …`, `handle(): int`, `Isolatable`, `Retryable`)
  - `Artisan::findCommand(string): ?Command`, `Artisan::registerCommand(Command)`, `Connection::getServerVersion(): string`
- Produces:
  - `App\Console\Commands\BackupRunCommand extends BackupCommand`: owns `backup:run`. It first resolves `--config` (as `parent::handle()` would), so it judges the configuration the run will use. While `! BackupEncryption::available()` it prints one `components->error(RefuseUnencryptedBackups::MESSAGE)` line and returns 1; otherwise it returns `parent::handle()`.
  - `RefuseUnencryptedBackups::MESSAGE` (the M7a refusal text). The listener throws only while `backup:run` is not the app's command.
  - `BackupEncryption::available(?bool $zipHasAes256 = null): bool`
  - `App\Support\Backups\PrivateBackupTemp`:
    - `STALE_AFTER_HOURS = 6`
    - `__invoke(CommandStarting $event): void`
    - `secure(): void`
    - `removeStale(): int` (the number of files removed, only under the package's working directory `<temporary_directory>/temp`)
  - `ConfigurePgDump` puts the first PostgreSQL backup source's `sslmode` into `PGSSLMODE` before `backup:run`
  - The schedule:
    - `backup:clean`, `backup:run --only-db` and `backup:monitor` use `withoutOverlapping(720)`
    - `backup-temp-cleanup` (a call) runs every hour at :15 (`hourlyAt(15)`), also while the module is off, on every server, without an overlap lock
  - `config('backup.backup.source.databases')` defaults to `[env('DB_CONNECTION', 'sqlite')]`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Backups/BackupConfigTest.php` (the whole file; L-01, L-03, F28):

```php
<?php

declare(strict_types=1);

/** covers §4.9, §6.8 */

use Spatie\Backup\Config\Config;
use Spatie\Backup\Exceptions\InvalidConfig;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;

/**
 * Runs $callback with each variable set (a string) or removed (null) in getenv(), $_SERVER and $_ENV, the three places
 * env() reads ($_SERVER and $_ENV first), and restores all three afterwards (F28, L-03).
 *
 * @param  array<string, string|null>  $variables
 */
$withEnvironment = function (array $variables, Closure $callback): mixed {
    $saved = [];

    foreach ($variables as $key => $value) {
        $saved[$key] = [
            'getenv' => getenv($key),
            'server' => array_key_exists($key, $_SERVER) ? [$_SERVER[$key]] : [],
            'env' => array_key_exists($key, $_ENV) ? [$_ENV[$key]] : [],
        ];

        if ($value === null) {
            putenv($key);
            unset($_SERVER[$key], $_ENV[$key]);
        } else {
            putenv($key.'='.$value);
            $_SERVER[$key] = $_ENV[$key] = $value;
        }
    }

    try {
        return $callback();
    } finally {
        foreach ($saved as $key => $original) {
            putenv($original['getenv'] === false ? $key : $key.'='.$original['getenv']);
            unset($_SERVER[$key], $_ENV[$key]);

            if ($original['server'] !== []) {
                $_SERVER[$key] = $original['server'][0];
            }

            if ($original['env'] !== []) {
                $_ENV[$key] = $original['env'][0];
            }
        }
    }
};

it('backs up the database only, to the dedicated backups disk (§4.9, CD-6)', function (): void {
    expect(config('backup.backup.source.files.include'))->toBe([])
        ->and(config('backup.backup.source.databases'))->toBe([config('database.default')])
        ->and(config('backup.backup.destination.disks'))->toBe(['backups'])
        ->and(config('filesystems.disks.backups.driver'))->toBe('local')
        ->and(config('filesystems.disks.backups.root'))->toBe(storage_path('app/backups'));
});

it('backs up the app\'s default connection, also while DB_CONNECTION is unset (L-01)', function () use ($withEnvironment): void {
    /** @var array{0: array<string, mixed>, 1: array<string, mixed>} $configs */
    $configs = $withEnvironment(
        ['DB_CONNECTION' => null],
        fn (): array => [require config_path('backup.php'), require config_path('database.php')],
    );

    expect(data_get($configs[1], 'default'))->toBe('sqlite')
        ->and(data_get($configs[0], 'backup.source.databases'))->toBe([data_get($configs[1], 'default')]);
});

it('names the backup after the app, never after the kit (CD-6)', function (): void {
    $name = config('backup.backup.name');

    expect($name)->toBeString()->toMatch('/\A[a-z0-9-]+\z/')
        ->and(config('backup.monitor_backups.0.name'))->toBe($name)
        ->and(config('backup.monitor_backups.0.disks'))->toBe(['backups'])
        ->and(config('backup.monitor_backups.0.health_checks.'.MaximumAgeInDays::class))->toBe(1);
});

it('encrypts the archive with BACKUP_ARCHIVE_PASSWORD (§4.9, L-03)', function () use ($withEnvironment): void {
    $backup = $withEnvironment(['BACKUP_ARCHIVE_PASSWORD' => 'from-the-environment'], fn (): mixed => require config_path('backup.php'));

    expect(data_get($backup, 'backup.password'))->toBe('from-the-environment')
        ->and(data_get($backup, 'backup.encryption'))->toBe('default')
        ->and(config('backup.backup.encryption'))->toBe('default');
});

it('mails on failure only (§4.9)', function (): void {
    /** @var array<class-string, list<string>> $channels */
    $channels = config('backup.notifications.notifications');

    expect($channels)->toBe([
        BackupHasFailedNotification::class => ['mail'],
        UnhealthyBackupWasFoundNotification::class => ['mail'],
        CleanupHasFailedNotification::class => ['mail'],
        BackupWasSuccessfulNotification::class => [],
        HealthyBackupWasFoundNotification::class => [],
        CleanupWasSuccessfulNotification::class => [],
    ]);
});

it('builds the package configuration, so a blank BACKUP_NOTIFY_EMAIL falls back to a valid address (§4.9)', function (): void {
    // The suite sets no BACKUP_NOTIFY_EMAIL: the fallback (MAIL_FROM_ADDRESS) must pass the package's email check.
    $config = Config::fromArray(config('backup'));

    expect($config->notifications->mail->to)->toBe(config('backup.notifications.mail.to'))
        ->and(filter_var(config('backup.notifications.mail.to'), FILTER_VALIDATE_EMAIL))->not->toBeFalse();
});

it('lets only a valid notify and sender address through, so a typo never breaks every artisan command (§4.9, F28)', function () use ($withEnvironment): void {
    // The package's commands are built whenever Artisan boots, and their Config throws on an invalid address. Both
    // variables are set in $_SERVER and $_ENV too, which env() reads before putenv(), so no original value shadows them.
    /** @var array<string, mixed> $array */
    $array = $withEnvironment(
        ['BACKUP_NOTIFY_EMAIL' => 'not-an-address', 'MAIL_FROM_ADDRESS' => 'not-an-address'],
        fn (): mixed => require config_path('backup.php'),
    );

    expect(fn (): Config => Config::fromArray($array))->not->toThrow(InvalidConfig::class)
        ->and(data_get($array, 'notifications.mail.to'))->toBe('hello@example.com')
        ->and(data_get($array, 'notifications.mail.from.address'))->toBe('hello@example.com');
});

it('lists the backup keys in .env.example (§4.9, A6)', function (): void {
    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)->toMatch('/^BACKUP_ARCHIVE_PASSWORD=$/m')
        ->toMatch('/^BACKUP_NOTIFY_EMAIL=$/m')
        ->toMatch('/^PG_DUMP_BINARY_PATH=$/m');
});

it('passes PG_DUMP_BINARY_PATH to the dumper as its binary directory, blank when unset (RL-4, L-03)', function (?string $value, string $expected) use ($withEnvironment): void {
    $database = $withEnvironment(['PG_DUMP_BINARY_PATH' => $value], fn (): mixed => require config_path('database.php'));

    expect(data_get($database, 'connections.pgsql.dump.dump_binary_path'))->toBe($expected)
        ->and(config('database.connections.pgsql.dump'))->toHaveKey('dump_binary_path');
})->with([
    'set' => ['/opt/postgresql/18/bin', '/opt/postgresql/18/bin'],
    'unset' => [null, ''],
]);
```

`tests/Feature/Backups/ConfigurePgDumpTest.php` (L-05). At the end of `beforeEach`, add:

```php
    // Every backup:run listener may run (the last test fires the event): never the repository's storage/app/backup-temp.
    config(['backup.backup.temporary_directory' => $this->sandbox->path('backup-temp')]);
    // L-05: ConfigurePgDump exports PGSSLMODE; start without one and put the original back afterwards.
    $this->pgSslMode = ['getenv' => getenv('PGSSLMODE'), 'server' => $_SERVER['PGSSLMODE'] ?? null, 'env' => $_ENV['PGSSLMODE'] ?? null];
    putenv('PGSSLMODE');
    unset($_SERVER['PGSSLMODE'], $_ENV['PGSSLMODE']);
```

Right after the `beforeEach` block, add:

```php
afterEach(function (): void {
    /** @var array{getenv: string|false, server: mixed, env: mixed} $original */
    $original = $this->pgSslMode;
    putenv($original['getenv'] === false ? 'PGSSLMODE' : 'PGSSLMODE='.$original['getenv']);
    unset($_SERVER['PGSSLMODE'], $_ENV['PGSSLMODE']);

    if ($original['server'] !== null) {
        $_SERVER['PGSSLMODE'] = $original['server'];
    }

    if ($original['env'] !== null) {
        $_ENV['PGSSLMODE'] = $original['env'];
    }
});
```

Append:

```php
it('hands the backup source\'s sslmode to pg_dump as PGSSLMODE (L-05)', function () use ($starting): void {
    config([
        'backup.backup.source.databases' => ['pgsql'],
        'database.connections.pgsql.sslmode' => 'verify-full',
        'database.connections.pgsql.dump.dump_binary_path' => '/opt/postgresql/bin',
    ]);

    new ConfigurePgDump($this->sandbox->path('no-herd-services'), $this->sandbox->basePath)($starting('backup:run'));

    // Symfony's Process passes on the getenv() keys that $_SERVER also holds, plus every $_ENV key.
    expect(getenv('PGSSLMODE'))->toBe('verify-full')
        ->and($_SERVER['PGSSLMODE'] ?? null)->toBe('verify-full')
        ->and($_ENV['PGSSLMODE'] ?? null)->toBe('verify-full');
});

it('leaves PGSSLMODE alone for other commands and other databases (L-05)', function (string $command, string $source) use ($starting): void {
    config([
        'backup.backup.source.databases' => [$source],
        'database.connections.pgsql.sslmode' => 'verify-full',
        'database.connections.pgsql.dump.dump_binary_path' => '/opt/postgresql/bin',
    ]);

    new ConfigurePgDump($this->sandbox->path('no-herd-services'), $this->sandbox->basePath)($starting($command));

    expect(getenv('PGSSLMODE'))->toBeFalse()
        ->and($_ENV)->not->toHaveKey('PGSSLMODE');
})->with([
    'another command' => ['migrate', 'pgsql'],
    'a SQLite source' => ['backup:run', 'sqlite'],
]);
```

`tests/Feature/Backups/RefuseUnencryptedBackupsTest.php` (the whole file; O1):

```php
<?php

declare(strict_types=1);

/** covers §4.9 */

use App\Support\Backups\RefuseUnencryptedBackups;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Commands\BackupCommand;
use Spatie\Backup\Config\Config;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;

// The console kernel dispatches CommandStarting in tests only with this trait.
uses(WithConsoleEvents::class);

beforeEach(function (): void {
    useBackupsModule(true);
    Storage::fake('backups');
    // Should a run get through, it works in a sandbox and never looks for pg_dump in the repository's .env.
    $this->sandbox = setupSandbox();
    config([
        'backup.backup.temporary_directory' => $this->sandbox->path('backup-temp'),
        'database.connections.pgsql.dump.dump_binary_path' => $this->sandbox->path('no-pg-dump'),
        // No source connection: ConfigurePgDump leaves PGSSLMODE alone (L-05), and a run that got through would stop
        // at "no files to back up" instead of dumping.
        'backup.backup.source.databases' => [],
    ]);
    // The package's command takes its Config at construction, from an instance RefreshDatabase's migrate run already
    // built: rebuild it from the configuration above, as BackupRunTest does.
    Config::rebind();
});

/** O1: the refusal is the one message, with no exception class and no stack frame. */
$assertCleanRefusal = function (string $output): void {
    expect((string) preg_replace('/\s+/', ' ', $output))->toContain(RefuseUnencryptedBackups::MESSAGE)
        ->and($output)->not->toContain('Exception')
        ->and($output)->not->toMatch('/#\d+ /')
        ->and($output)->not->toContain('vendor/');
};

it('refuses to start backup:run with one error line while the archive would not be encrypted (CD-10, O1)', function (array $config) use ($assertCleanRefusal): void {
    config($config);

    $exit = Artisan::call('backup:run', ['--only-db' => true, '--disable-notifications' => true]);

    expect($exit)->toBe(1);
    $assertCleanRefusal(Artisan::output());
    expect(Storage::disk('backups')->allFiles())->toBe([]);
})->with([
    'blank password' => [['backup.backup.password' => '']],
    'no password' => [['backup.backup.password' => null]],
    'encryption off' => [['backup.backup.encryption' => 'none']],
]);

it('exits 1 for php artisan backup:run without a password (CD-10, O1)', function () use ($assertCleanRefusal): void {
    config(['backup.backup.password' => '']);
    $output = new BufferedOutput;

    $exit = app(Kernel::class)->handle(new ArrayInput(['command' => 'backup:run', '--only-db' => true, '--disable-notifications' => true]), $output);

    expect($exit)->toBe(1);
    $assertCleanRefusal($output->fetch());
    expect(Storage::disk('backups')->allFiles())->toBe([]);
});

it('still stops a backup:run that is not the app\'s command (CD-7)', function (): void {
    config(['backup.backup.password' => '']);
    // Should the package's command hold the name again, the listener stays the backstop.
    Artisan::registerCommand(app(BackupCommand::class));
    $listener = new RefuseUnencryptedBackups;

    expect(function () use ($listener): void {
        $listener(new CommandStarting('backup:run', new ArrayInput([]), new NullOutput));
    })->toThrow(RuntimeException::class, 'php artisan skv:doctor --fix');
});

it('lets encrypted backups and every other command start (CD-10)', function (string $command, ?string $password): void {
    config(['backup.backup.password' => $password]);
    $listener = new RefuseUnencryptedBackups;

    $listener(new CommandStarting($command, new ArrayInput([]), new NullOutput));
})->throwsNoExceptions()->with([
    'an encrypted backup' => ['backup:run', 'correct-horse-battery'],
    'the list without a password' => ['backup:list', null],
    'the monitor without a password' => ['backup:monitor', null],
    'another command' => ['migrate', null],
]);
```

`tests/Feature/Backups/BackupRunCommandTest.php` (new; CD-7, O1):

```php
<?php

declare(strict_types=1);

/** covers §4.9 */

use App\Console\Commands\BackupRunCommand;
use App\Support\Backups\RefuseUnencryptedBackups;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Commands\BackupCommand;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Exceptions\BackupFailed;

beforeEach(function (): void {
    useBackupsModule(true);
    Storage::fake('backups');
    $this->sandbox = setupSandbox();
    config([
        'backup.backup.temporary_directory' => $this->sandbox->path('backup-temp'),
        'database.connections.pgsql.dump.dump_binary_path' => $this->sandbox->path('no-pg-dump'),
        // No source connection: a run that starts stops at "no files to back up" and dumps nothing.
        'backup.backup.source.databases' => [],
    ]);
    // The package's command takes its Config at construction, from an instance RefreshDatabase's migrate run already
    // built: rebuild it from the configuration above, as BackupRunTest does. Without this, a run backs up :memory:.
    Config::rebind();
});

it('owns the backup:run name over the package\'s command (CD-7, O1)', function (): void {
    expect(Artisan::all()['backup:run'] ?? null)
        ->toBeInstanceOf(BackupRunCommand::class)
        ->toBeInstanceOf(BackupCommand::class);
});

it('refuses by itself, without the CommandStarting backstop (CD-7, O1)', function (array $config): void {
    // Without WithConsoleEvents no listener runs: the refusal is the command's own.
    config($config);

    $exit = Artisan::call('backup:run', ['--only-db' => true, '--disable-notifications' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and((string) preg_replace('/\s+/', ' ', $output))->toContain(RefuseUnencryptedBackups::MESSAGE)
        ->and($output)->not->toContain('Starting backup');
    expect(Storage::disk('backups')->allFiles())->toBe([]);
})->with([
    'blank password' => [['backup.backup.password' => '']],
    'encryption off' => [['backup.backup.encryption' => 'none']],
]);

it('judges the configuration that --config names, not the default one (CD-7)', function (): void {
    // The default configuration encrypts (useBackupsModule() sets a password); the one --config names would not.
    /** @var array<string, mixed> $backup */
    $backup = config('backup');
    config(['backup_plain' => array_replace_recursive($backup, ['backup' => ['password' => '']])]);

    $exit = Artisan::call('backup:run', ['--only-db' => true, '--config' => 'backup_plain', '--disable-notifications' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and((string) preg_replace('/\s+/', ' ', $output))->toContain(RefuseUnencryptedBackups::MESSAGE)
        ->and($output)->not->toContain('Starting backup');
    expect(Storage::disk('backups')->allFiles())->toBe([]);
});

it('hands an encrypted backup to the package (CD-7)', function (): void {
    Exceptions::fake();

    $exit = Artisan::call('backup:run', ['--only-db' => true, '--disable-notifications' => true]);
    $output = Artisan::output();

    // The package's job starts, then stops at "no files to back up" (no source connection).
    expect($exit)->toBe(1)
        ->and($output)->toContain('Starting backup')
        ->and((string) preg_replace('/\s+/', ' ', $output))->not->toContain(RefuseUnencryptedBackups::MESSAGE);
    Exceptions::assertReported(BackupFailed::class);
});
```

> spatie's backup commands receive a scoped `Config` that RefreshDatabase's migrate run has already built, so a `config()` call in `beforeEach` never reaches them. Every test file that changes `backup.*` before `backup:run` runs calls `Config::rebind()` before the first Artisan call. Without it, 'hands an encrypted backup to the package' backs up the SQLite `:memory:` database and exits 0.

`tests/Feature/Backups/PrivateBackupTempTest.php` (new; L-07, F18):

```php
<?php

declare(strict_types=1);

/** covers §4.9 */

use App\Support\Backups\PrivateBackupTemp;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

beforeEach(function (): void {
    $this->sandbox = setupSandbox();
    $this->temp = $this->sandbox->path('backup-temp');
    config(['backup.backup.temporary_directory' => $this->temp]);
});

$starting = fn (string $command): CommandStarting => new CommandStarting($command, new ArrayInput([]), new NullOutput);

/** The permission bits of $path, read past PHP's stat cache. */
$mode = function (string $path): int {
    clearstatcache(true, $path);

    return (int) fileperms($path) & 07777;
};

it('creates the temporary directory private to this account before backup:run (CD-10, L-07)', function () use ($starting, $mode): void {
    $listener = new PrivateBackupTemp;
    $listener($starting('backup:run'));

    expect(is_dir($this->temp))->toBeTrue()
        ->and($mode($this->temp))->toBe(0700);
});

it('narrows an existing temporary directory to 0700 (CD-10, L-07)', function () use ($starting, $mode): void {
    File::ensureDirectoryExists($this->temp);
    chmod($this->temp, 0755);

    $listener = new PrivateBackupTemp;
    $listener($starting('backup:run'));

    expect($mode($this->temp))->toBe(0700);
});

it('leaves the directory alone for other commands (CD-10)', function () use ($starting): void {
    $listener = new PrivateBackupTemp;
    $listener($starting('backup:list'));

    expect(is_dir($this->temp))->toBeFalse();
});

it('runs as a CommandStarting listener (CD-10)', function () use ($starting, $mode): void {
    // The other backup:run listeners run too: an encrypted run, a configured pg_dump, no PostgreSQL source (L-05).
    config([
        'backup.backup.password' => 'correct-horse-battery',
        'database.connections.pgsql.dump.dump_binary_path' => $this->sandbox->path('no-pg-dump'),
        'backup.backup.source.databases' => [],
    ]);

    event($starting('backup:run'));

    expect($mode($this->temp))->toBe(0700);
});

it('removes the files a killed run left behind, only those older than six hours and only under temp/ (CD-10, F18)', function (): void {
    File::ensureDirectoryExists($this->temp.'/temp/db-dumps');
    File::put($this->temp.'/temp/db-dumps/database.sql', 'plaintext');
    touch($this->temp.'/temp/db-dumps/database.sql', now()->subHours(7)->getTimestamp());
    File::put($this->temp.'/temp/manifest.txt', 'a running backup');
    touch($this->temp.'/temp/manifest.txt', now()->subHours(5)->getTimestamp());
    // The package writes only under temp/: a file beside it is not a backup's, however old.
    File::put($this->temp.'/keep.txt', 'not a backup file');
    touch($this->temp.'/keep.txt', now()->subHours(7)->getTimestamp());

    expect(new PrivateBackupTemp()->removeStale())->toBe(1)
        ->and(is_file($this->temp.'/temp/db-dumps/database.sql'))->toBeFalse()
        ->and(is_file($this->temp.'/temp/manifest.txt'))->toBeTrue()
        ->and(is_file($this->temp.'/keep.txt'))->toBeTrue();
});

it('never changes or empties a shared sticky directory (CD-10)', function () use ($starting, $mode): void {
    File::ensureDirectoryExists($this->temp.'/temp');
    chmod($this->temp, 01777);
    File::put($this->temp.'/temp/someone-else.sql', 'theirs');
    touch($this->temp.'/temp/someone-else.sql', now()->subDay()->getTimestamp());

    $listener = new PrivateBackupTemp;
    $listener($starting('backup:run'));

    expect($mode($this->temp))->toBe(01777)
        ->and(new PrivateBackupTemp()->removeStale())->toBe(0)
        ->and(is_file($this->temp.'/temp/someone-else.sql'))->toBeTrue();
});
```

> The listener is called through a variable, as `RefuseUnencryptedBackupsTest` does. Pint's `new_with_parentheses` would rewrite `new PrivateBackupTemp()($event)` to `new PrivateBackupTemp($event)`, which passes the event to a constructor the class does not have, so `__invoke()` would never run. `new PrivateBackupTemp()->removeStale()` is safe.

`tests/Feature/Backups/BackupEncryptionTest.php` (new; L-23 (1)):

```php
<?php

declare(strict_types=1);

/** covers §4.9 */

use App\Support\Backups\BackupEncryption;

it('counts default and aes256 archives as unencrypted on a PHP zip without AES-256 (CD-10, L-23)', function (string $encryption, bool $withoutAes256): void {
    config(['backup.backup.password' => 'correct-horse-battery', 'backup.backup.encryption' => $encryption]);

    expect(BackupEncryption::available(zipHasAes256: false))->toBe($withoutAes256)
        ->and(BackupEncryption::available(zipHasAes256: true))->toBeTrue()
        ->and(BackupEncryption::available())->toBeTrue();
})->with([
    'default' => ['default', false],
    'aes256' => ['aes256', false],
    'aes128 needs no AES-256' => ['aes128', true],
]);

it('never counts a missing password or no encryption as encrypted, whatever the zip offers (CD-10)', function (array $config): void {
    config(['backup.backup.password' => 'correct-horse-battery', 'backup.backup.encryption' => 'default', ...$config]);

    expect(BackupEncryption::available(zipHasAes256: true))->toBeFalse();
})->with([
    'blank password' => [['backup.backup.password' => '']],
    'encryption none' => [['backup.backup.encryption' => 'none']],
    'unknown encryption' => [['backup.backup.encryption' => 'aes-256']],
]);
```

> `BackupEncryption::available()` without the seam is `true` only on a PHP zip with AES-256. The suite already assumes such a PHP (HealthChecksTest, ScheduleTest).

`tests/Feature/Backups/BackupRunTest.php` (the whole file; F19, F23):

```php
<?php

declare(strict_types=1);

/** covers §4.9 */

use App\Setup\Detection;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Doctor\PostgresBinaries;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Config\Config;

/** The pg_dump directory when it can dump the test server, else null (an older client refuses a newer server). */
$usablePgDump = function (): ?string {
    if (config('database.default') !== 'pgsql') {
        return null;
    }

    $toolchain = new Toolchain(storage_path('framework/testing/home'));
    $path = new PostgresBinaries()->find(new DoctorContext(storage_path('framework/testing'), $toolchain, Detection::none(), null), 'pg_dump');

    if ($path === null) {
        return null;
    }

    // F19: through Process with the toolchain's environment, never exec(). This file fakes no process, so the probe
    // and the package's pg_dump run for real.
    preg_match('/(\d+)(?:\.\d+)?/', Process::env($toolchain->environment())->run([$path, '--version'])->output(), $client);
    preg_match('/^(\d+)/', DB::connection()->getServerVersion(), $server);

    return isset($client[1], $server[1]) && (int) $client[1] >= (int) $server[1] ? dirname($path) : null;
};

it('backs up the database into an encrypted archive on the backups disk (§4.9)', function () use ($usablePgDump): void {
    Storage::fake('backups');
    // The package creates, empties and deletes its working directory: a sandbox, never the repository's storage/app/backup-temp.
    $sandbox = setupSandbox();
    config([
        'database.connections.pgsql.dump.dump_binary_path' => $usablePgDump(),
        'backup.backup.password' => 'correct-horse-battery',
        'backup.backup.temporary_directory' => $sandbox->path('backup-temp'),
    ]);
    Config::rebind();

    $exit = Artisan::call('backup:run', ['--only-db' => true, '--disable-notifications' => true]);
    // F23: Artisan::output() empties its buffer, so it is read once and checked twice.
    $output = Artisan::output();
    $name = (string) config('backup.backup.name');
    $newest = BackupDestination::create('backups', $name)->newestBackup();

    expect($exit)->toBe(0, $output)
        ->and($newest)->not->toBeNull();

    $zip = new ZipArchive;
    $zip->open(Storage::disk('backups')->path((string) $newest?->path()));
    $entry = $zip->statIndex(0);
    $zip->close();

    expect($entry)->toBeArray()
        ->and($entry['encryption_method'] ?? 0)->not->toBe(0)
        ->and($output)->not->toContain('correct-horse-battery');
})->skip(fn (): bool => $usablePgDump() === null, 'needs PostgreSQL and a pg_dump at least as new as the server');
```

`tests/Arch/KitRulesTest.php`: append (F19):

```php
it('starts external commands only through Process: no exec() and friends, no backticks (F19)', function (): void {
    $banned = ['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec'];
    $offenders = [];

    foreach (kitSourceFiles('app', 'tests', 'routes', 'database', 'config') as $path) {
        // Tokens, not text: comments, strings and docblocks may name these functions and use backticks.
        $tokens = array_values(array_filter(
            PhpToken::tokenize((string) file_get_contents(base_path($path))),
            fn (PhpToken $token): bool => ! $token->isIgnorable(),
        ));

        foreach ($tokens as $index => $token) {
            if ($token->text === '`') {
                $offenders[] = $path.': backticks';

                continue;
            }

            $previous = $tokens[$index - 1] ?? null;
            $next = $tokens[$index + 1] ?? null;
            // A method or static call, a declaration or a constant of the same name is not a call of the function.
            $declaredOrMember = $previous instanceof PhpToken
                && $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST]);

            if ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED])
                && in_array(strtolower(ltrim($token->text, '\\')), $banned, true)
                && $next?->text === '('
                && ! $declaredOrMember) {
                $offenders[] = $path.': '.$token->text.'()';
            }
        }
    }

    expect(array_values(array_unique($offenders)))->toBe([]);
});
```

> A dry run of this scan (and of Task 9's `Number` rule) against `main` found exactly one offender, `tests/Feature/Backups/BackupRunTest.php: exec()`. PDO's `$admin->exec(...)` calls in the setup tests are method calls and pass.

`tests/Feature/Scheduler/ScheduleTest.php` (Task 9's counts, 8 → 9). Add `use Illuminate\Support\Facades\File;` to the imports. Replace 'schedules exactly the ops jobs of §4.9, each once (DEC-28, L-31)' with:

```php
it('schedules exactly the ops jobs of §4.9, each once (DEC-28, L-31, CD-10)', function () use ($identifiers): void {
    expect($identifiers())->toHaveCount(9)->toEqualCanonicalizing([
        'activitylog:clean --force', 'backup-temp-cleanup', 'backup:clean', 'backup:run --only-db', 'backup:monitor',
        'queue:prune-failed', 'model:prune', 'auth:clear-resets', 'scheduler-heartbeat',
    ]);
});
```

Replace 'runs each job at its time, on one server, never twice at once (§4.9)' and its dataset with:

```php
it('runs each job at its time, on the right servers, never twice at once (§4.9, CD-10)', function (string $job, string $expression, bool $oneServer, bool $overlapGuard, int $lockMinutes) use ($events): void {
    $event = $events()[$job];

    expect($event->expression)->toBe($expression)
        ->and($event->onOneServer)->toBe($oneServer)
        ->and($event->withoutOverlapping)->toBe($overlapGuard)
        ->and($event->expiresAt)->toBe($lockMinutes);
})->with([
    ['activitylog:clean --force', '0 1 * * *', true, true, 1440],
    // Each server empties its own temporary directory, every hour.
    ['backup-temp-cleanup', '15 * * * *', false, false, 1440],
    // A killed backup's lock expires before the next night (F6).
    ['backup:clean', '30 1 * * *', true, true, 720],
    ['backup:run --only-db', '0 2 * * *', true, true, 720],
    ['backup:monitor', '0 3 * * *', true, true, 720],
    ['queue:prune-failed', '30 3 * * *', true, true, 1440],
    ['model:prune', '0 4 * * *', true, true, 1440],
    ['auth:clear-resets', '*/15 * * * *', true, true, 1440],
    ['scheduler-heartbeat', '* * * * *', true, false, 1440],
]);
```

In 'runs the backup jobs only while the backups module is on (§6.8)', keep the loop's list (`backup:clean`, `backup:run --only-db`, `backup:monitor`). Below the `activitylog:clean --force` expectation, add:

```php
    // F18: a dump left from before the module was switched off must still go.
    expect($all['backup-temp-cleanup']->filtersPass(app()))->toBeTrue();
```

Append:

```php
it('empties the stale files of the backup temp directory (CD-10, F18)', function () use ($events): void {
    $temp = setupSandbox()->path('backup-temp');
    config(['backup.backup.temporary_directory' => $temp]);
    File::ensureDirectoryExists($temp.'/temp/db-dumps');
    File::put($temp.'/temp/db-dumps/database.sql', 'plaintext');
    touch($temp.'/temp/db-dumps/database.sql', now()->subHours(7)->getTimestamp());

    $events()['backup-temp-cleanup']->run(app());

    expect(is_file($temp.'/temp/db-dumps/database.sql'))->toBeFalse();
});
```

`tests/Feature/Scheduler/ScheduledTasksTest.php` (Task 9's list, 8 → 9). In 'lists the ops schedule from its own copy…', change `toHaveCount(8)` to `toHaveCount(9)`. Then add `'backup-temp-cleanup' => '15 * * * *',` right after `'activitylog:clean --force' => '0 1 * * *',`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Backups tests/Feature/Scheduler tests/Arch/KitRulesTest.php`

Expected: FAIL.
- L-01: `['pgsql']` is not `['sqlite']`.
- L-05: `getenv('PGSSLMODE')` is `false`.
- `RefuseUnencryptedBackupsTest` 'refuses…': `Artisan::call()` throws the listener's `RuntimeException` instead of returning 1. 'exits 1…': the output contains `RuntimeException` and frames.
- `BackupRunCommandTest`: `App\Console\Commands\BackupRunCommand` does not exist, and the package's command runs and prints "Starting backup".
- `PrivateBackupTemp` does not exist.
- `BackupEncryption::available()` has no `zipHasAes256` parameter.
- `ScheduleTest`: 8 identifiers, `expiresAt` 1440, no `backup-temp-cleanup`.
- The arch rule lists `BackupRunTest.php: exec()`.
- These pass already:
  - the L-03 rewrites and the F28 test (they only stop being shadowable and source-text based)
  - 'still stops a backup:run that is not the app's command' (a backstop guard)
  - `BackupRunTest` (it is skipped on SQLite)

- [ ] **Step 3: The source connection default (L-01)**

`config/backup.php`, in `'source'`:

```php
            // L-01: the app's default connection, with config/database.php's own default.
            'databases' => [env('DB_CONNECTION', 'sqlite')],
```

- [ ] **Step 4: The encryption seam (L-23 (1))**

`app/Support/Backups/BackupEncryption.php` (the whole file):

```php
<?php

declare(strict_types=1);

namespace App\Support\Backups;

use Spatie\Backup\Enums\Encryption;
use ZipArchive;

/**
 * CD-10: whether backup:run would encrypt the archive. The package silently writes a plaintext zip for a blank
 * password, for encryption 'none', or for 'default' / 'aes256' on a PHP zip without AES-256, so this mirrors its
 * BackupConfig parsing and Zip task instead of trusting the config. An unknown encryption value counts as none.
 */
final readonly class BackupEncryption
{
    /**
     * @param  bool|null  $zipHasAes256  whether this PHP's zip writes AES-256; null asks ZipArchive. Tests pass false to
     *                                   stand in for a PHP without it (L-23). It only narrows the answer: the package's
     *                                   own check must pass as well.
     */
    public static function available(?bool $zipHasAes256 = null): bool
    {
        $password = config('backup.backup.password');

        if (! is_string($password) || $password === '') {
            return false;
        }

        $setting = config('backup.backup.encryption', 'default');

        $encryption = match (true) {
            $setting instanceof Encryption => $setting,
            is_string($setting) => Encryption::tryFrom($setting),
            default => null,
        };

        if (! $encryption instanceof Encryption || ! $encryption->shouldEncrypt()) {
            return false;
        }

        // 'default' and 'aes256' need AES-256; without it the package writes a plaintext zip.
        return ! in_array($encryption, [Encryption::Default, Encryption::Aes256], true)
            || ($zipHasAes256 ?? defined(ZipArchive::class.'::EM_AES_256'));
    }
}
```

- [ ] **Step 5: A clean refusal (CD-7, O1)**

`app/Console/Commands/BackupRunCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Backups\BackupEncryption;
use App\Support\Backups\RefuseUnencryptedBackups;
use Spatie\Backup\Commands\BackupCommand;

/**
 * CD-7, O1: spatie's backup:run under the same signature. While the archive would not be encrypted, it prints the
 * refusal as one error line and exits 1. Without it, the backstop listener's exception and stack trace would print
 * (RefuseUnencryptedBackups). The kernel registers discovered commands after the package's, so this class owns the
 * name; it therefore carries no #[AsCommand], which would register it lazily, behind the package's eager command.
 */
final class BackupRunCommand extends BackupCommand
{
    public function handle(): int
    {
        // --config swaps config('backup') only inside parent::handle(); judge the configuration this run will use (CD-7).
        if ($this->option('config')) {
            $this->config = $this->resolveConfig();
        }

        if (! BackupEncryption::available()) {
            $this->components->error(RefuseUnencryptedBackups::MESSAGE);

            return self::FAILURE;
        }

        return parent::handle();
    }
}
```

> `resolveConfig()` sets `config('backup')` to the named array and rebinds `Config`; `parent::handle()` runs it again on the same array, which only rebuilds the same objects. Without this, `backup:run --config=<a configuration without a password>` would pass the check on the default configuration and write a plaintext archive, and the backstop listener steps aside for the app's command.

`app/Support/Backups/RefuseUnencryptedBackups.php` (the whole file):

```php
<?php

declare(strict_types=1);

namespace App\Support\Backups;

use App\Console\Commands\BackupRunCommand;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * CD-10, CD-7: the backstop behind BackupRunCommand. Before backup:run it refuses to start while the archive would not
 * be encrypted (BackupEncryption), because the package writes a plaintext dump for a blank password, encryption
 * 'none' or a zip without AES-256 and still reports success. Laravel fires CommandStarting before the command's
 * handle(), so while backup:run is the app's command (which prints the refusal as one line, O1) this listener steps
 * aside; should the package's command hold the name again, it throws and Artisan exits 1. The schedule does not even
 * start backup:run then (OpsSchedule).
 */
final readonly class RefuseUnencryptedBackups
{
    public const string MESSAGE = 'backup:run refuses to write an unencrypted backup: the running configuration has no BACKUP_ARCHIVE_PASSWORD, '
        .'backup.backup.encryption turns encryption off, or this PHP zip has no AES-256. Run php artisan skv:doctor --fix, '
        .'then php artisan config:cache (or config:clear) when the configuration is cached.';

    public function __invoke(CommandStarting $event): void
    {
        if ($event->command !== 'backup:run' || BackupEncryption::available() || $this->appCommandRefuses()) {
            return;
        }

        throw new RuntimeException(self::MESSAGE);
    }

    /** Whether backup:run is the app's command, which refuses by itself (O1). */
    private function appCommandRefuses(): bool
    {
        return Artisan::findCommand('backup:run') instanceof BackupRunCommand;
    }
}
```

- [ ] **Step 6: PGSSLMODE (L-05)**

`app/Support/Backups/ConfigurePgDump.php`: three targeted edits. The rest of the file stays as Task 7 left it, including its CD-6 search order.

Replace the class docblock (Task 7's) with this one. Its second paragraph is Task 7's text, unchanged:

```php
/**
 * Before backup:run, prepares pg_dump.
 *
 * It fills an empty `database.connections.pgsql.dump.dump_binary_path` with the directory of the pg_dump the doctor
 * finds (RL-4, CD-6): PG_DUMP_BINARY_PATH, then for a server on this machine Herd's newest trusted PostgreSQL service
 * before the toolchain PATH, else the PATH first (PostgresBinaries). A cron job has no Herd PATH, so without this the
 * nightly backup would not find Herd's pg_dump.
 *
 * It also passes the source connection's sslmode as PGSSLMODE (L-05), because pg_dump reads its TLS mode from the
 * environment, not from the Laravel connection.
 */
```

Replace `__invoke()` with:

```php
    public function __invoke(CommandStarting $event): void
    {
        if ($event->command !== 'backup:run') {
            return;
        }

        $this->passSslMode();

        if ((string) config('database.connections.pgsql.dump.dump_binary_path', '') !== '') {
            return;
        }

        $path = new PostgresBinaries($this->herdServices)->find(DoctorContext::withoutDetection($this->basePath), 'pg_dump');

        if ($path !== null) {
            config(['database.connections.pgsql.dump.dump_binary_path' => dirname($path)]);
        }
    }
```

Add this method below `__invoke()`:

```php
    /**
     * L-05: the first PostgreSQL backup source's sslmode, as the app's own connection uses it. The package's Symfony
     * Process inherits the getenv() keys that $_SERVER also holds, plus every $_ENV key, so all three are set.
     */
    private function passSslMode(): void
    {
        $sources = config('backup.backup.source.databases', []);

        foreach (is_array($sources) ? $sources : [] as $connection) {
            if (! is_string($connection) || config('database.connections.'.$connection.'.driver') !== 'pgsql') {
                continue;
            }

            $mode = config('database.connections.'.$connection.'.sslmode');

            if (is_string($mode) && $mode !== '') {
                putenv('PGSSLMODE='.$mode);
                $_SERVER['PGSSLMODE'] = $_ENV['PGSSLMODE'] = $mode;
            }

            return;
        }
    }
```

> If Task 7 changed `__invoke()`'s body (for example the `find()` call), keep Task 7's version and only move the `dump_binary_path` check below `$this->passSslMode();`.

- [ ] **Step 7: The private temporary directory and its cleanup (CD-10, L-07, F18)**

`app/Support/Backups/PrivateBackupTemp.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Backups;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/**
 * CD-10: backup:run's working directory holds the plaintext dump until the archive is encrypted.
 *
 * Before every backup:run, this listener creates the directory with mode 0700, or narrows an existing one to 0700. The
 * package's own subdirectories (0777 & umask) then sit under a private parent (L-07). The schedule's hourly
 * backup-temp-cleanup removes what a killed run left in the package's temp/ subdirectory, also while the backups
 * module is off (F18).
 *
 * A shared directory is never changed or emptied: the system temp directory, a sticky directory, or a directory
 * another account owns.
 */
final readonly class PrivateBackupTemp
{
    /** Files older than this are left over from a killed run: a running backup keeps writing its own. */
    public const int STALE_AFTER_HOURS = 6;

    public function __invoke(CommandStarting $event): void
    {
        if ($event->command === 'backup:run') {
            $this->secure();
        }
    }

    /** Creates the temporary directory with mode 0700, or narrows an existing one to 0700. */
    public function secure(): void
    {
        $directory = $this->directory();

        if (! is_dir($directory)) {
            File::ensureDirectoryExists($directory, 0700);
        }

        if ($this->isOurs($directory)) {
            chmod($directory, 0700);
        }
    }

    /**
     * Removes the files under the package's working directory (<temporary_directory>/temp) older than
     * STALE_AFTER_HOURS; returns how many. The package writes nowhere else, so the rest of the directory is left alone.
     */
    public function removeStale(): int
    {
        $directory = $this->directory();

        if (! is_dir($directory) || ! $this->isOurs($directory)) {
            return 0;
        }

        $working = $directory.'/temp';

        if (! is_dir($working)) {
            return 0;
        }

        $cutoff = Date::now()->subHours(self::STALE_AFTER_HOURS)->getTimestamp();
        $removed = 0;

        foreach (Finder::create()->files()->ignoreDotFiles(false)->ignoreVCS(false)->in($working) as $file) {
            if ($file->getMTime() < $cutoff && File::delete($file->getPathname())) {
                $removed++;
            }
        }

        return $removed;
    }

    /** The directory the package's TemporaryDirectory uses (BackupServiceProvider binds the same fallback). */
    private function directory(): string
    {
        $configured = config('backup.backup.temporary_directory');

        return is_string($configured) && $configured !== '' ? $configured : storage_path('app/backup-temp');
    }

    /** Whether the directory is this account's own and not a shared one (system temp, sticky, another owner). */
    private function isOurs(string $directory): bool
    {
        clearstatcache(true, $directory);
        $real = realpath($directory);
        $mode = fileperms($directory);
        $user = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();

        return $real !== false
            && $real !== realpath(sys_get_temp_dir())
            && $mode !== false
            && ($mode & 01000) === 0
            && fileowner($directory) === $user;
    }
}
```

> Finder lists symlinked files, but `File::delete()` unlinks the link, never its target. Finder does not follow symlinked directories. Directories stay, because the package's `force()->create()` replaces `temp/` at the next run.
>
> Only `temp/` is scanned: `BackupJob` writes the dump, the manifest and the zip there (`TemporaryDirectory::name('temp')`), so a `temporary_directory` pointed at a shared project directory loses nothing else. Every `backup:run` force-deletes `temp/` at its start anyway; the hourly cleanup shortens the window a killed run's plaintext dump stays on disk from a day to at most about seven hours.

`app/Providers/AppServiceProvider.php`: import `App\Support\Backups\PrivateBackupTemp`. In `configureOperations()`, right after the `ConfigurePgDump` line, add:

```php
        // CD-10, L-07: the plaintext dump's working directory is private to this account.
        Event::listen(CommandStarting::class, PrivateBackupTemp::class);
```

- [ ] **Step 8: The schedule (F6, F18)**

`app/Support/Scheduler/OpsSchedule.php`. Import `App\Support\Backups\PrivateBackupTemp`. Replace the class docblock's second paragraph with:

```php
 * The jobs run on one server and never twice at once, except the heartbeat (no overlap lock) and backup-temp-cleanup
 * (hourly on every server, because each has its own temporary directory, and also while the backups module is off,
 * so a dump left from before it was switched off still goes). The backup jobs' overlap lock expires after 12
 * hours, so a killed run never blocks the next night (F6). The backup jobs run only while the backups module is on
 * (§6.8; always off on SQLite), and backup:run only while it would encrypt the archive (DEC-32 CD-10): the package
 * writes a plaintext dump for a blank password and still exits 0. backup:monitor keeps running, so a missing backup
 * is still reported, and last-backup fails on /health-check. activitylog:clean asks for confirmation in production,
 * hence --force. model:prune removes expired handoff tokens (HandoffToken is the only prunable model).
```

Replace the `activitylog:clean` through `backup:monitor` lines with:

```php
        $schedule->command('activitylog:clean --force')->dailyAt('01:00')->onOneServer()->withoutOverlapping();
        // CD-10, F18: a killed backup:run leaves its plaintext dump behind; each server empties its own stale files
        // every hour. No module filter: removeStale() does nothing without the directory.
        $schedule->call(function (): void {
            app(PrivateBackupTemp::class)->removeStale();
        })->hourlyAt(15)->name('backup-temp-cleanup');
        $schedule->command('backup:clean')->dailyAt('01:30')->when($backupsEnabled)->onOneServer()->withoutOverlapping(720);
        $schedule->command('backup:run --only-db')->dailyAt('02:00')->when($encryptedBackupsEnabled)->onOneServer()->withoutOverlapping(720);
        $schedule->command('backup:monitor')->dailyAt('03:00')->when($backupsEnabled)->onOneServer()->withoutOverlapping(720);
```

- [ ] **Step 9: Run the tests to verify they pass**

Run:
- `php artisan test --compact tests/Feature/Backups tests/Feature/Scheduler tests/Feature/System tests/Feature/Health tests/Arch`
- `php artisan schedule:list`. Expected: nine rows, with `backup-temp-cleanup` at `15 * * * *` (it only reads).
- `php artisan list backup`. Expected: `backup:run` is listed once. Never run it in the repository.

Then the pgsql line directly (Composer appends script arguments to every command of a script):

```bash
php artisan config:clear && php scripts/create-pgsql-test-database.php && DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=$(php scripts/create-pgsql-test-database.php --print-name) DB_USERNAME=root DB_PASSWORD= vendor/bin/pest --filter='BackupRun|BackupConfig|ConfigurePgDump|PrivateBackupTemp'
```

Expected: PASS. `BackupRunTest` must run, not be skipped, on a machine with Herd's PostgreSQL 18 client (F19). Report which.

- [ ] **Step 10: Verify by mutation**

Revert each change locally, confirm the named test fails, then restore it and confirm it passes:

| Mutation | Test that must fail |
|---|---|
| `env('DB_CONNECTION', 'pgsql')` in `config/backup.php` | 'backs up the app's default connection, also while DB_CONNECTION is unset' |
| Remove `$_ENV`/`$_SERVER` from `passSslMode()` (keep `putenv`) | 'hands the backup source's sslmode to pg_dump as PGSSLMODE' |
| Drop the `in_array(... Default, Aes256 ...)` clause in `BackupEncryption` | 'counts default and aes256 archives as unencrypted…' |
| Drop `|| $this->appCommandRefuses()` in `RefuseUnencryptedBackups` | 'refuses to start backup:run with one error line…' (the trace returns) |
| Drop the encryption check in `BackupRunCommand::handle()` | 'refuses by itself, without the CommandStarting backstop' |
| Drop the `--config` block at the top of `BackupRunCommand::handle()` | 'judges the configuration that --config names, not the default one' (the package starts the run) |
| `return self::FAILURE;` instead of `parent::handle()` | 'hands an encrypted backup to the package' |
| Drop `chmod($directory, 0700)` in `secure()` | 'narrows an existing temporary directory to 0700' |
| Drop the sticky check in `isOurs()` | 'never changes or empties a shared sticky directory' |
| Scan `$directory` instead of `$working` in `removeStale()` | 'removes the files a killed run left behind…' (`keep.txt` goes) |
| Add `->when($backupsEnabled)` to `backup-temp-cleanup` | 'runs the backup jobs only while the backups module is on' (module off) |
| `withoutOverlapping()` on `backup:run` | the schedule dataset row `backup:run --only-db` (1440) |
| With `$_SERVER['BACKUP_NOTIFY_EMAIL'] = 'ops@example.com'` set at the top of the old F28 test, and `filter_var` removed from `$notifyTo` | the new test fails; the old one passed |
| In `BackupRunCommand::handle()`, `$this->line((string) config('backup.backup.password'));` before `parent::handle()` | `BackupRunTest` on PostgreSQL (F23; report it if the test is skipped on this machine) |

- [ ] **Step 11: The gate, then commit**

Run the full per-task gate (Global Constraints). Then:

```bash
git add config/backup.php app/Console/Commands/BackupRunCommand.php app/Support/Backups \
    app/Support/Scheduler/OpsSchedule.php app/Providers/AppServiceProvider.php \
    tests/Feature/Backups tests/Feature/Scheduler tests/Arch/KitRulesTest.php
git commit -m "fix: keep backup dumps private, refuse in one line and pass the ssl mode to pg_dump

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 11: Test hygiene and arch guards (g2-10, g4-09, g2-12, g4-05, g4-06, g2-23, g4-16, g1-14, g4-25, g4-22, g4-23, g4-24, g1-16)

**Files:**
- Create:
  - `tests/Feature/Setup/StrayProcessGuardTest.php`
- Modify:
  - `tests/Pest.php` (RL-12 hook)
  - `tests/Arch/KitRulesTest.php` (catch-all ban, RL-12 pin, setup-page rule)
  - `tests/Arch/NameLeakTest.php`
  - `app/Setup/Doctor/Checks/LastBackupCheck.php` (docblock only)
  - `app/Console/Commands/FluxProCommand.php` (CD-13)
  - `scripts/e2e-local.sh` (g1-14)
- Test (modify):
  - `tests/Feature/Setup/Doctor/DoctorTest.php`, `tests/Feature/Console/DoctorCommandTest.php`
  - `tests/Feature/Console/InstallCommandTest.php`, `tests/Feature/Console/ProductionGuardsTest.php`
  - `tests/Feature/Setup/DetectorTest.php`, `tests/Feature/Setup/BootstrapTest.php`, `tests/Feature/Setup/Steps/ClaudeStepTest.php`
  - `tests/Feature/Setup/Wizard/WizardRunnerTest.php`, `tests/Feature/Setup/Wizard/WizardPageTest.php`, `tests/Feature/Setup/Wizard/HerdStageTest.php`
  - `tests/Feature/Backups/BackupRunTest.php` (only if Step 3 needs the opt-out)
  - `tests/Browser/SetupWizardTest.php`
  - `tests/Feature/Tooling/CiWorkflowTest.php`, `tests/Feature/Tooling/ComposerScriptsTest.php`
  - `tests/Feature/Console/FluxProCommandTest.php`

**Interfaces:**
- Consumes:
  - `Process::fake()` / `preventStrayProcesses()` / `assertRan()` (Laravel 13.35)
  - `kitSourceFiles()`, `filesMatching()`, `useSetupWizard()`, `setupSandbox()`
  - `Pest\Browser\Playwright\Playwright::timeout()` / `setTimeout()` and `Pest\Browser\Api\PendingAwaitablePage` (pest-plugin-browser 5.1.2)
  - Tasks 1–10 as merged; Task 10 may have changed `BackupRunTest`'s version probe (F19)
- Produces:
  - A `tests/Pest.php` hook: `Process::preventStrayProcesses()` + `Process::fake([])` before every test under `Feature/Setup`, `Feature/Console`, `Feature/Backups`, `Feature/Health` and `Feature/System` (RL-12).
  - Arch rules in `KitRulesTest`:
    - no `Process::fake(['*' …])`, no `'*'` key anywhere in a fake's array, no bare `Process::fake()`, no closure catch-all under `tests/`
    - no `@vite`, `Vite::`, `/build/`, `@fonts` or `hasFluxPro` in the setup views
  - `NameLeakTest` over `app/`, `resources/views` and `lang/`, with exact state-file names.
  - `skv:flux-pro` writes `extra.skv.flux-pro: true` into `composer.json` before the require, and removes `extra.skv` again when the install fails (CD-13). The test "never references Flux Pro in the committed composer files (committed)" skips when the committed `composer.json` carries the mark.

> No new global test helper. Every helper below is a file-local closure (Global Constraints). Process fake keys use Symfony's single-quoted command line: `which('git')` gives `/usr/bin/git`, so the line is `'/usr/bin/git' 'init'` and the key is `"*git' 'init'*"`.

- [ ] **Step 1: Write the catch-all ban (red)**

`tests/Arch/KitRulesTest.php`, append:

```php
it('fakes processes by name, never with a catch-all, so the stray-process guard stays on (g2-10, RL-12)', function (): void {
    $files = array_values(array_diff(kitSourceFiles('tests'), ['tests/Arch/KitRulesTest.php']));

    // Factory::fake() fakes every command for no argument, a closure, a '*' key or a list value (a numeric key is '*'),
    // or a '*' key anywhere in the array.
    expect(filesMatching($files, '/Process::fake\(\s*(?:\)|(?:static\s+)?(?:fn|function)\b|\[\s*(?:([\'"])\*\1|Process::|(?:static\s+)?(?:fn|function)\b))|Process::fake\(\s*\[[^;]*?([\'"])\*\2\s*=>/s'))->toBe([]);
});
```

> The scan reads raw text, comments included (`filesMatching()` matches whole file contents, and `tests/Pest.php` is in the list). Comments under `tests/` never spell `Process::fake()` or a catch-all key. The last alternative needs the same quote on both sides of the `*`, so named keys such as `"*herd' 'tld'*" =>` never match it (validated against today's tree: 20 hits in 10 files).

Run: `php artisan test --compact tests/Arch/KitRulesTest.php`

Expected: FAIL, listing these ten files (`BootstrapTest.php` for its bare fake at line 93 and the `'*'` second key at line 151):
- `tests/Browser/SetupWizardTest.php`
- `tests/Feature/Setup/Doctor/DoctorTest.php`
- `tests/Feature/Setup/Wizard/WizardPageTest.php`
- `tests/Feature/Setup/Wizard/WizardRunnerTest.php`
- `tests/Feature/Setup/DetectorTest.php`
- `tests/Feature/Setup/Steps/ClaudeStepTest.php`
- `tests/Feature/Setup/BootstrapTest.php`
- `tests/Feature/Console/DoctorCommandTest.php`
- `tests/Feature/Console/InstallCommandTest.php`
- `tests/Feature/Console/ProductionGuardsTest.php` (unless Task 6 already fixed L-57)

- [ ] **Step 2: Replace every catch-all with named patterns (g2-10, g2-23, g4-16)**

Each file below lists the commands its tests actually reach, read from the code (`DoctorContext::process()`, the steps' `run()`, `Bootstrap`, `Detector`, `HerdTld`).

**`tests/Feature/Setup/Doctor/DoctorTest.php`.**
- Add `use Illuminate\Process\PendingProcess;`.
- In `beforeEach`, replace the comment and the catch-all (lines 24–26):

```php
    Process::preventStrayProcesses();
    // The commands the checks and repairs start here: Node's version (it answers none, so the assets repair stops
    // before npm), the storage-link repair, and composer's Flux Pro credentials where Pro is installed (CI's main job).
    // The sandbox has no .git and no marker, so the git-hooks and superpowers checks start nothing.
    Process::fake([
        "*node' '-v'*" => Process::result(''),
        "*'artisan' 'storage:link'*" => Process::result(''),
        "*composer' 'config' 'http-basic.composer.fluxui.dev'*" => Process::result('', exitCode: 1),
    ]);
```

- In 'reports drift without repairing it, and repairs it with --fix …', after the existing `expect(...)` chain:

```php
    // The sandbox has no public/storage, so --fix runs the storage-link repair (a safe repair, §5.4).
    Process::assertRan(fn (PendingProcess $process): bool => str_contains(implode(' ', (array) $process->command), 'artisan storage:link'));
```

**`tests/Feature/Console/DoctorCommandTest.php`.** Replace line 15 with the same three patterns (Task 7's `--fix` test needs the storage-link one):

```php
    Process::fake([
        "*node' '-v'*" => Process::result(''),
        "*'artisan' 'storage:link'*" => Process::result(''),
        "*composer' 'config' 'http-basic.composer.fluxui.dev'*" => Process::result('', exitCode: 1),
    ]);
```

**`tests/Feature/Console/InstallCommandTest.php`.** Replace line 31:

```php
    // The commands a sandbox install starts (§5.3.3):
    // - the bootstrap's migration
    // - Node's version (it answers none, so the frontend step ends manual before npm)
    // - git init and the hooks path
    // - where Claude Code is on the system PATH (the claude answer then defaults to true): Boost and the plugin on a
    //   .test address, and the doctor's plugin list
    // - composer's Flux Pro credentials where Pro is installed
    Process::fake([
        "*'artisan' 'migrate'*" => Process::result(''),
        "*node' '-v'*" => Process::result(''),
        "*git' 'init'*" => Process::result(''),
        "*git' 'config' 'core.hooksPath' '.githooks'*" => Process::result(''),
        "*'artisan' 'boost:install'*" => Process::result(''),
        "*claude' 'plugin'*" => Process::result('[]'),
        "*composer' 'config' 'http-basic.composer.fluxui.dev'*" => Process::result('', exitCode: 1),
    ]);
```

**`tests/Feature/Console/ProductionGuardsTest.php`.** Skip this file if Task 6 (L-57) already removed the line. Otherwise, in 'refuses the demo data outside local development …', delete the comment and `Process::fake(['*' => Process::result('')]);` (lines 55–56):
- The sandbox toolchain has no Herd, so detection starts nothing, and the refusal comes before the bootstrap.
- The `beforeEach`'s `Process::fake([])` then makes a regressed guard throw at the bootstrap's migrate instead of passing.

**`tests/Feature/Setup/DetectorTest.php`.**
- Add `use Illuminate\Process\PendingProcess;`.
- In 'reads Herd Pro from the services JSON …', after the `expect(...)` chain (g4-16):

```php
    // Both Herd probes give up after 5 s, so a hung Herd never stalls detection (A5, A18).
    Process::assertRan(fn (PendingProcess $process): bool => in_array('services:list', (array) $process->command, true) && $process->timeout === 5);
    Process::assertRan(fn (PendingProcess $process): bool => in_array('tld', (array) $process->command, true) && $process->timeout === 5);
```

- In 'treats a Herd that hangs as no Herd Pro …', replace the comment and line 78:

```php
    // A handler that throws stands in for each Herd probe timing out: Detector must catch every process failure.
    Process::fake([
        "*herd' 'services:list'*" => fn () => throw new RuntimeException('timed out'),
        "*herd' 'tld'*" => fn () => throw new RuntimeException('timed out'),
    ]);
```

**`tests/Feature/Setup/BootstrapTest.php`.**
- In 'leaves a non-default APP_URL and a non-sqlite database alone', delete `Process::fake();` (line 93). The `beforeEach`'s `Process::fake([])` refuses any process, and `Process::assertNothingRan()` stays.
- In 'survives a herd tld that times out (A5)', replace line 151. `Bootstrap::run()` starts only `herd tld` and the migration, so a named migrate pattern replaces the `'*'` second key:

```php
    Process::fake(["*herd' 'tld'*" => fn () => throw new RuntimeException('timed out'), "*'artisan' 'migrate' '--graceful' '--force'*" => Process::result('Migrated')]);
```

**`tests/Feature/Setup/Steps/ClaudeStepTest.php`.**
- **g2-23.** In 'installs Boost, registers the Herd MCP server as herd …', delete the key `"*composer' 'require' '--dev' 'laravel/boost'*" => Process::result(''),`. It never matched (the argument is `'laravel/boost:^2.10'`), and the kit's `composer.json` lists `^2.10`, so the step never requires. `Process::assertDidntRun(… 'composer require')` pins that.
- In 'hands over the Boost command when the address is not a .test site', delete `Process::fake();` (line 120) and add after `$result = new ClaudeStep()->run($context);`:

```php
    Process::assertNothingRan();
```

- In 'restores the Boost constraint the installer lowered …', replace line 136:

```php
    Process::fake(["*composer' 'require' '--dev' 'laravel/boost:^2.10'*" => Process::result('')]);
```

**`tests/Feature/Setup/Wizard/WizardRunnerTest.php`.**
- Add `use Illuminate\Process\FakeProcessResult;`.
- After the `$holdLock` closure, add:

```php
/**
 * The commands a wizard run without Herd starts (§5.3.3): Node answers no version, so the frontend step ends manual
 * before npm; git init and the hooks path; composer's Flux Pro credentials where Pro is installed (the doctor step).
 *
 * @return array<string, FakeProcessResult>
 */
$wizardCommands = fn (): array => [
    "*node' '-v'*" => Process::result(''),
    "*git' 'init'*" => Process::result(''),
    "*git' 'config' 'core.hooksPath' '.githooks'*" => Process::result(''),
    "*composer' 'config' 'http-basic.composer.fluxui.dev'*" => Process::result('', exitCode: 1),
];
```

- In each of the four tests that call `Process::fake(['*' => Process::result('')]);`, replace that line with `Process::fake($wizardCommands());` and add `$wizardCommands` to the closure's `use (…)`. The four tests:
  - 'runs steps 1 to 8 one call at a time, the long ones detached'
  - 'marks a detached step that could not start as a manual command'
  - 'finishes with the marker, a Herd state holding the secret's hash …'
  - the HTTPS-consequence / manual-page test that Task 3 retitled (g3-22)

**`tests/Feature/Setup/Wizard/WizardPageTest.php`.**
- Add `use Illuminate\Process\FakeProcessResult;`.
- Add the same `$wizardCommands` closure after `$answers`.
- In each of the six tests that call `Process::fake(['*' => Process::result('')]);`, replace it with `Process::fake($wizardCommands());` and add `$wizardCommands` to `use (…)`. The six tests:
  - 'completes on SQLite with QUEUE_CONNECTION=database and no worker'
  - 'keeps the session across the environment step …'
  - 'completes on PostgreSQL without a 500 or 419'
  - 'shows a manual command with a re-check'
  - 'shows a re-check that another tab blocks as a failure …'
  - 'goes straight on to the Herd stage when no step ended manual'
- Correct the stale comments that say "The sandbox has no node". On a machine with Node the step reaches `node -v`, which `$wizardCommands` answers with no version:
  - in 'completes on SQLite …': "Node answers no version (`$wizardCommands`), so the frontend step ends manual and the page stops at the review: the user clicks "Finish setup" there."
  - in 'goes straight on …': "the frontend is already built, so the step is complete before it reaches Node"

**`tests/Browser/SetupWizardTest.php`:** Step 7 rewrites it, including its fake.

Run: `php artisan test --compact tests/Feature/Setup tests/Feature/Console tests/Arch/KitRulesTest.php`

Expected: PASS. If a test now throws "Attempted process [...] without a matching fake":
1. Read the command line in the message.
2. Add the narrowest pattern for it to that file, with a comment saying why it runs.
3. Never fall back to a catch-all.

- [ ] **Step 3: The directory-level guard (RL-12, g4-09)**

`tests/Feature/Setup/StrayProcessGuardTest.php` (a file without its own fake, like `SetupHostTest`):

```php
<?php

declare(strict_types=1);

/** covers §7.2 */

use Illuminate\Support\Facades\Process;

it('refuses a process that no fake names, also in a file without its own fake (RL-12)', function (): void {
    expect(fn () => Process::run(['true']))->toThrow(RuntimeException::class, 'without a matching fake');
});
```

`tests/Arch/KitRulesTest.php`, append:

```php
it('guards the setup, console, backup, health and System-page tests against stray processes (RL-12)', function (): void {
    expect((string) file_get_contents(base_path('tests/Pest.php')))
        ->toContain("->in('Feature/Setup', 'Feature/Console', 'Feature/Backups', 'Feature/Health', 'Feature/System')");
});
```

Run: `php artisan test --compact tests/Feature/Setup/StrayProcessGuardTest.php tests/Arch/KitRulesTest.php`

Expected: FAIL. `Process::run(['true'])` runs the real `true` and succeeds, and `tests/Pest.php` has no hook.

`tests/Pest.php`, after `pest()->extend(TestCase::class)->in('Arch');`:

```php
/*
| RL-12: every setup, console, backup, health and System-page test refuses a process it did not fake. Recording starts
| with fake([]): preventStrayProcesses() alone guards nothing until a fake is recording. A file's own
| Process::fake([...]) adds its named patterns; catch-alls are banned (tests/Arch/KitRulesTest.php).
*/
pest()->beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
})->in('Feature/Setup', 'Feature/Console', 'Feature/Backups', 'Feature/Health', 'Feature/System');
```

> `pest()->beforeEach()` reaches `UsesCall::beforeEach()` through `Configuration::__call()`, and `in()` merges with the `Feature` configuration (Pest 5.3 `TestRepository::use()`). Larastan level 8 accepts it (probed). `Process` is already imported in `tests/Pest.php`. The comment says "until a fake is recording", not the method call: Step 1's ban scans `tests/Pest.php` too, comments included.

**`tests/Feature/Backups/BackupRunTest.php`.** Read the `$usablePgDump` closure first:
- If Task 10 (F19) made its `pg_dump --version` probe run through the `Process` facade, the hook now refuses it. Add these lines right before the probe, after the closure's `if ($path === null) { return null; }`:

```php
    // The one test that runs a real pg_dump (AGENTS.md › Ops): its version probe runs for real, not faked (RL-12).
    Process::preventStrayProcesses(false);
```

  Also add `use Illuminate\Support\Facades\Process;` if the file lacks it.
- If the probe does not use the facade, change nothing. Record which case applied in the task report.

Run:

```bash
php artisan test --compact tests/Feature/Setup tests/Feature/Console tests/Feature/Backups tests/Feature/Health tests/Feature/System tests/Arch
composer test:pgsql -- --filter=BackupRun
```

Expected: PASS. On PostgreSQL, `BackupRunTest` runs and is not skipped where a usable `pg_dump` exists, as before.

- [ ] **Step 4: NameLeakTest over `app/`, the views and `lang/`, with exact names (g2-12, g4-05)**

Replace `tests/Arch/NameLeakTest.php` with:

```php
<?php

declare(strict_types=1);

/** covers §7.2 */

use Illuminate\Support\Facades\File;

/*
 * The allowed forms of the kit name, removed from a line before it is searched, in this order (the alias rule runs
 * before the Skv:: rule, which would strip half of it). A state-file name counts only whole, so `skv-installed-old` or
 * `skv-herd.log.1` still leak (g4-05).
 */
$allowed = [
    '/skv:[a-z-]+/',                     // artisan command namespace
    "/config\\(\\[?['\"]skv\\./",        // config('skv.…') keys, read or set
    "/->setting\\('[A-Z_]+', 'skv\\./",  // DoctorContext::setting()'s config key (CD-5)
    '/config\/skv\.php/',
    "/->alias\\('Skv', /",               // the global Skv alias (RL-1)
    '/\bSkv::/',
    '/App\\\\Support\\\\Skv\b/',         // the Skv support class name (RL-1)
    '/\bfinal class Skv\b|alias `Skv`/', // its declaration and its docblock
    '#skv-(?:installed|setup-token|setup\.(?:json|lock)|setup|herd\.(?:json|exit|log))(?![\w.-])#', // the setup's state files (RL-5); tests use the constants
    '/\.ai\/guidelines\/skv3\.md/',
];

/** Whether $line still names the kit once the allowed forms are removed. */
$leaks = fn (string $line): bool => stripos((string) preg_replace($allowed, '', $line), 'skv') !== false;

it('hard-codes no kit or site name in app-identity values', function () use ($leaks): void {
    $paths = collect([base_path('.env.example'), base_path('phpunit.xml')])
        ->merge(collect(File::allFiles(config_path()))->map->getPathname())
        ->merge(collect(File::allFiles(database_path('seeders')))->map->getPathname())
        ->merge(collect(File::allFiles(app_path()))->map->getPathname())
        ->merge(collect(File::allFiles(resource_path('views')))->map->getPathname())
        ->merge(collect(File::allFiles(lang_path()))->map->getPathname())
        ->merge(collect(File::allFiles(base_path('tests')))->map->getPathname()
            ->reject(fn (string $p): bool => str_ends_with($p, 'NameLeakTest.php')));

    $hits = $paths->flatMap(function (string $path) use ($leaks) {
        $lines = preg_split('/\R/', (string) file_get_contents($path)) ?: [];

        return collect($lines)
            ->filter(fn (string $line): bool => $leaks($line))
            ->map(fn (string $line, int $n): string => basename($path).':'.($n + 1).': '.trim($line));
    });

    expect($hits->values()->all())->toBe([]);
});

it('allows the setup state-file names only whole (g4-05)', function () use ($leaks): void {
    expect($leaks("'skv-installed-old'"))->toBeTrue()
        ->and($leaks("'skv-herd.log.1'"))->toBeTrue()
        ->and($leaks("'skv-setup.json.bak'"))->toBeTrue()
        ->and($leaks("'storage/app/private/skv-herd.log'"))->toBeFalse()
        ->and($leaks("'storage/app/private/skv-setup'"))->toBeFalse()
        ->and($leaks("'storage/app/private/skv-setup/frontend.json'"))->toBeFalse();
});

it('finds a kit name planted in app code (g2-12)', function () use ($leaks): void {
    expect($leaks("        'name' => 'skv3',"))->toBeTrue()
        ->and($leaks("config('skv.modules.backups')"))->toBeFalse()
        ->and($leaks('php artisan skv:doctor --fix'))->toBeFalse();
});
```

Run: `php artisan test --compact tests/Arch/NameLeakTest.php`

Expected: FAIL with `LastBackupCheck.php:17: * skv.health_check.backup_grace_minutes: …` as the only hit (checked against today's tree with this list). Anything else is a name that Tasks 1–10 added:
- Fix it in the code: a constant, `config('skv.…')`, or the derived app name.
- Widen `$allowed` only for a form that is not an app-identity value, with an exact pattern and a comment.
- Name each such case in the task report.

`app/Setup/Doctor/Checks/LastBackupCheck.php`, class docblock: replace the line

```
 * skv.health_check.backup_grace_minutes: an archive is named after the time its job starts, so while tonight's
```

with

```
 * config('skv.health_check.backup_grace_minutes'): an archive is named after the time its job starts, so while tonight's
```

Run: `php artisan test --compact tests/Arch/NameLeakTest.php`

Expected: PASS.

**Verify by mutation:**
1. Put the old trailing `\b` back in the state-file rule. 'allows the setup state-file names only whole' fails.
2. Restore the rule.
3. Add `'name' => 'skv3',` to any `app/` config array. The scan fails.
4. Remove it.

- [ ] **Step 5: Setup pages without a Vite manifest (g4-06, M55)**

`tests/Feature/Setup/Wizard/WizardPageTest.php`: add `use Illuminate\Support\Facades\Vite;`, then:

```php
it('renders the wizard without a Vite manifest (§5.3.4, M55)', function (): void {
    $context = useSetupWizard();
    Vite::useManifestFilename('missing-'.Str::uuid().'.json');

    $this->get('/welcome-setup?token='.new SetupToken($context->basePath)->value());
    $html = (string) $this->get('/welcome-setup')->assertOk()->getContent();

    expect($html)->toContain('data-test="setup-wizard"')->not->toContain('/build/');
});
```

`tests/Feature/Setup/Wizard/HerdStageTest.php`: add `use Illuminate\Support\Facades\Vite;`, then:

```php
it('renders the Herd-stage pages without a Vite manifest (§5.3.4, M55)', function () use ($afterStepNine): void {
    $context = useSetupWizard();
    $secret = $afterStepNine($context);
    Vite::useManifestFilename('missing-'.Str::uuid().'.json');

    $finishing = $this->get('/welcome-setup/finish?secret='.$secret)->assertOk()->assertHeader('Refresh', '2');
    $manual = $this->get('/welcome-setup/finish?secret='.$secret.'&timeout=1')->assertOk()->assertHeaderMissing('Refresh');
    $state = HerdState::load($context->basePath) ?? throw new LogicException('No Herd state.');
    $state->result = StepResult::skipped('Herd is not installed');
    $state->save($context->basePath);
    $summary = $this->get('/welcome-setup/finish?secret='.$secret)->assertOk()->assertSee('data-test="setup-summary"', false);

    foreach ([$finishing, $manual, $summary] as $response) {
        expect((string) $response->getContent())->not->toContain('/build/');
    }
});
```

> The manual page is asserted only as "not the finishing page". After Task 3 (RL-7) its command depends on Herd, and the sandbox has none.

`tests/Arch/KitRulesTest.php`, append:

```php
it('keeps Vite, public/build, web fonts and Flux Pro out of the setup pages, which render before the first build (§5.3.4, M55)', function (): void {
    $files = ['resources/views/layouts/setup.blade.php', ...kitSourceFiles('resources/views/pages/setup', 'resources/views/setup')];

    expect(filesMatching($files, '#@vite|Vite::|/build/|@fonts|hasFluxPro#'))->toBe([]);
});
```

Run: `php artisan test --compact tests/Feature/Setup/Wizard tests/Arch/KitRulesTest.php`

Expected: PASS. Today's setup views hold none of these tokens. The layout's comment says "no Vite and nothing from public/build", which matches no case-sensitive rule.

**Verify by mutation:**
1. Add `@vite(['resources/css/app.css'])` as the first line of `resources/views/pages/setup/wizard/wizard.blade.php`. All three new tests fail (the wizard GET answers 500 on the missing manifest).
2. Restore the file.
3. Confirm `npm run build:setup && git diff --exit-code resources/setup/setup.css` is clean.

- [ ] **Step 6: The e2e script and its clone route (g1-14, g4-25)**

`tests/Feature/Tooling/CiWorkflowTest.php`, append:

```php
it('never hides a failed command substitution behind export or local in the shell scripts (g1-14)', function (): void {
    $scripts = [...(glob(base_path('scripts/*.sh')) ?: []), ...(glob(base_path('.githooks/*')) ?: [])];
    // `export X="$(cmd)"` returns export's status, so set -e never sees a failing cmd; assign first, then export.
    $masked = array_values(array_filter($scripts, fn (string $path): bool => preg_match('/^\s*(?:export|local|readonly|declare)\s+(?:-\w+\s+)*[A-Za-z_]\w*="?\$\(/m', (string) file_get_contents($path)) === 1));

    expect($scripts)->not->toBeEmpty()
        ->and($masked)->toBe([]);
});
```

`tests/Feature/Tooling/ComposerScriptsTest.php`: add `use Illuminate\Support\Str;` after the docblock, then append:

```php
it('repeats the commands of composer setup in the clone route of scripts/e2e-local.sh (g4-25, RL-11)', function (): void {
    /** @var array{scripts: array{setup: list<string>}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $normalise = fn (string $command): string => str_replace(' --no-interaction', '', (string) preg_replace('/^@php /', 'php ', $command));
    $clone = $normalise(Str::between((string) file_get_contents(base_path('scripts/e2e-local.sh')), "        clone)\n", ';;'));
    $offset = 0;

    foreach (array_slice($composer['scripts']['setup'], 1, 3) as $command) {
        $position = strpos($clone, $normalise($command), $offset);

        expect($position)->not->toBeFalse("The clone route lacks [{$command}] after the commands before it.");

        $offset = (int) $position + 1;
    }
});
```

Run: `php artisan test --compact tests/Feature/Tooling`

Expected:
- The g1-14 test FAILs on `scripts/e2e-local.sh` (`export COMPOSER_HOME="$(mktemp -d)"`, line 83).
- The g4-25 test PASSes.

`scripts/e2e-local.sh`, replace line 83:

```bash
export COMPOSER_HOME="$(mktemp -d)"
```

with

```bash
COMPOSER_HOME="$(mktemp -d)"
export COMPOSER_HOME
```

Run: `php artisan test --compact tests/Feature/Tooling && bash -n scripts/e2e-local.sh`

Expected: PASS, no syntax error.

**Verify by mutation:**
1. Change `composer install --no-interaction` in the script's `clone)` block to `composer update`. The g4-25 test fails.
2. Restore the script.
3. Move `php artisan skv:bootstrap` above the `.env` copy. The test fails on the order.
4. Restore the script.

- [ ] **Step 7: The browser wizard tests (g4-22, g4-23, g4-24)**

Replace `tests/Browser/SetupWizardTest.php` with:

```php
<?php

declare(strict_types=1);

/** covers §5.3.4, §7.2 */

use App\Setup\Detector;
use App\Setup\Steps\DatabaseStep;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Playwright\Playwright;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    // The commands a wizard run without Herd starts: Node answers no version, so the frontend step ends manual and the
    // page stops at the review; git init and the hooks path; composer's Flux Pro credentials where Pro is installed.
    Process::fake([
        "*node' '-v'*" => Process::result(''),
        "*git' 'init'*" => Process::result(''),
        "*git' 'config' 'core.hooksPath' '.githooks'*" => Process::result(''),
        "*composer' 'config' 'http-basic.composer.fluxui.dev'*" => Process::result('', exitCode: 1),
    ]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
    $this->previousConnection = config('database.default');
});

// afterEach, not beforeApplicationDestroyed: RefreshDatabase reads database.default again at teardown.
afterEach(function (): void {
    DB::purge('setup');
    config(['database.default' => $this->previousConnection]);
});

/**
 * Waits up to two minutes for $selector while the in-process server answers the page's polls. The plugin retries an
 * assertion for Playwright::timeout() milliseconds (5 s by default), so the timeout is raised for this one wait and
 * restored after it. Playwright::usingTimeout() would not do: it changes only each request's timeout, not the retries.
 */
$waitFor = function (PendingAwaitablePage $page, string $selector): void {
    $previous = Playwright::timeout();
    Playwright::setTimeout(120_000);

    try {
        $page->assertPresent($selector);
    } finally {
        Playwright::setTimeout($previous);
    }
};

it('sets a demo install up on SQLite in the browser and signs in with the quick login (§7.2)', function () use ($waitFor): void {
    useSetupWizard();

    $page = visit('/')->withHost('localhost');

    $page->assertSee(__('Start setup'))
        ->fill('appName', 'Acme')
        ->click('@setup-database-sqlite')
        ->click('@setup-start-button');

    // The frontend step ends manual, so the page stops at the review. Click "Finish setup" once, after it shows: a
    // second click would reach the gate the marker closed.
    $waitFor($page, '@setup-complete');
    $page->click('@setup-complete');
    $waitFor($page, '@setup-summary');

    $page->assertSee(__('Setup is done.'))
        ->assertSee('admin@demo1.localhost')
        ->click('@setup-login')
        ->assertSee(__('Quick login (local only)'))
        // GuessLocator strips every '@', so the selector holds none after the first.
        ->press('@quick-login-admin-demo1-localhost')
        ->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();
});

it('sets a super-admin install up on PostgreSQL and signs the owner in once (§7.2)', function () use ($waitFor): void {
    $context = useSetupWizard(setupSandbox(prefix: 'setup_probe_'));
    $name = DatabaseStep::databaseName($context);
    /** @var array{host: string, port: string|int, username: string, password: string} $server */
    $server = config('database.connections.pgsql');
    $admin = new PDO("pgsql:host={$server['host']};port={$server['port']};dbname=postgres", $server['username'], $server['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    // Runs after afterEach's purge of the setup connection, so no session holds the databases.
    $this->beforeApplicationDestroyed(function () use ($admin, $name): void {
        foreach ([$name, "{$name}_test"] as $database) {
            $admin->exec('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
        }
    });

    $page = visit('/')->withHost('localhost');

    $page->fill('appName', 'Acme')
        ->click('@setup-database-pgsql')
        ->click('@setup-start-super_admin')
        ->fill('superAdminEmail', 'owner@example.com')
        ->fill('superAdminPassword', 'correct-horse-battery')
        ->click('@setup-start-button');

    $waitFor($page, '@setup-complete');
    $page->click('@setup-complete');
    $waitFor($page, '@setup-summary');

    // The handoff lands on the admin host's '/', which sends a super admin to the organisations index (TEN-1).
    $page->assertSee(__('Setup is done.'))
        ->assertSee(__('Log in as :email', ['email' => 'owner@example.com']))
        ->click('@setup-login')
        ->assertPathIs('/organisations')
        ->assertNoJavaScriptErrors();
})->skip(fn (): bool => ! app(Detector::class)->postgresReachable(), 'PostgreSQL is not reachable');
```

> Keep the PostgreSQL case's `skip()` exactly as Task 1 left it. RL-3 makes the PostgreSQL wizard cases rebind `Detector` themselves, so carry over any rebinding Task 1 added to this file.

Run:

```bash
npm run build
composer test:browser -- --filter=SetupWizard
```

Expected: PASS. The SQLite case takes well under the old 90 s, because it no longer sleeps 2 s per poll.

**Verify by mutation:**
1. Make the frontend step never end, for example by binding `InlineStepLauncher` with `refuses = true` in a local edit, so the review never shows. The test fails after about 120 s at `$waitFor($page, '@setup-complete')`, instead of looping 45 times.
2. Revert the edit.

- [ ] **Step 8: The Flux Pro mark and the committed-files test (g1-16, CD-13)**

`tests/Feature/Console/FluxProCommandTest.php`: add `use Illuminate\Process\FakeProcessResult;`, then change three existing tests.

In 'adds the repository, requires Flux Pro and clears compiled views', replace the `Process::fake([...])` call with:

```php
    $order = [];
    $recorded = function (string $step, FakeProcessResult $result) use (&$order): Closure {
        return function () use ($step, $result, &$order): FakeProcessResult {
            $order[] = $step;

            return $result;
        };
    };
    Process::fake([
        "*composer' 'config' 'http-basic.composer.fluxui.dev'*" => Process::result('{"username":"me@example.com","password":"secret-key"}'),
        "*composer' 'config' 'extra.skv.flux-pro' 'true' '--json'*" => $recorded('mark', Process::result('')),
        "*composer' 'config' 'repositories.flux-pro' 'composer' 'https://composer.fluxui.dev'*" => $recorded('repository', Process::result('')),
        "*composer' 'require' 'livewire/flux-pro'*" => $recorded('require', Process::result('Installed')),
    ]);
```

and after the existing `Process::assertRan(...)` lines add:

```php
    // CD-13: the mark goes in before the require, because Composer's lock hash covers `extra`.
    expect($order)->toBe(['mark', 'repository', 'require']);
```

In 'stops with the README one-liner when no credentials are configured', add:

```php
    Process::assertDidntRun(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'extra.skv.flux-pro'));
```

In 'fails with the last line Composer printed when the require fails', add to its `Process::fake([...])`:

```php
        "*composer' 'config' 'extra.skv.flux-pro' 'true' '--json'*" => Process::result(''),
        "*composer' 'config' '--unset' 'extra.skv'*" => Process::result(''),
```

and before the final `expect(is_file($this->probe))…`:

```php
    // An install that did not finish must not mark composer.json as committing Flux Pro (CD-13).
    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'config --unset extra.skv'));
```

`tests/Feature/Tooling/CiWorkflowTest.php`:
- After the `$committedFile` closure, add the two closures below.
- Give 'never references Flux Pro in the committed composer files (committed)' a skip.
- Add the test.

```php
/** Whether a composer.json carries skv:flux-pro's mark (CD-13): an app that commits Flux Pro on purpose carries it. */
$commitsFluxPro = function (string $composerJson): bool {
    $composer = json_decode($composerJson, true);

    return is_array($composer) && data_get($composer, 'extra.skv.flux-pro') === true;
};

/** A composer.json whose mark is $flag (data_set keeps the kit's namespace in one allowed form, NameLeakTest). */
$withFluxProMark = function (mixed $flag): string {
    $composer = ['name' => 'acme/app', 'require' => ['livewire/flux-pro' => '^2.0']];
    data_set($composer, 'extra.skv.flux-pro', $flag);

    return json_encode($composer, JSON_THROW_ON_ERROR);
};
```

Append to the existing committed-files test, after its closing `})`:

```php
})->skip(fn (): bool => $commitsFluxPro($committedFile(base_path(), 'composer.json')), 'this app committed Flux Pro on purpose with skv:flux-pro');
```

The kit never commits the mark. So the kit's own run, and CI after `skv:flux-pro` changed only the working tree, still check.

```php
it('skips the committed-files check only for a commit that skv:flux-pro marked (CD-13)', function () use ($commitsFluxPro, $committedFile, $withFluxProMark): void {
    $repository = storage_path('framework/testing/ci-flux-pro-'.Str::uuid());
    File::ensureDirectoryExists($repository);
    File::put($repository.'/composer.json', $withFluxProMark(true));

    try {
        Process::path($repository)->run(['git', 'init', '--quiet'])->throw();
        Process::path($repository)->run(['git', 'add', 'composer.json'])->throw();
        Process::path($repository)->run(['git', '-c', 'user.name=CI', '-c', 'user.email=ci@example.com', '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', 'commit', '--quiet', '-m', 'Add Flux Pro'])->throw();

        expect($commitsFluxPro($committedFile($repository, 'composer.json')))->toBeTrue()
            ->and($commitsFluxPro($withFluxProMark('true')))->toBeFalse()
            ->and($commitsFluxPro((string) json_encode(['require' => ['livewire/flux-pro' => '^2.0']])))->toBeFalse()
            ->and($commitsFluxPro('{'))->toBeFalse();
    } finally {
        File::deleteDirectory($repository);
    }
});
```

`tests/Arch/NameLeakTest.php`: add to `$allowed`, before the `.ai/guidelines` entry:

```php
    '/\bextra\.skv(?:\.flux-pro)?(?![\w.-])/', // skv:flux-pro's composer.json mark (CD-13)
```

Run: `php artisan test --compact tests/Feature/Console/FluxProCommandTest.php tests/Feature/Tooling/CiWorkflowTest.php tests/Arch/NameLeakTest.php`

Expected: FAIL. The command never runs `extra.skv.flux-pro` (the order is `['repository', 'require']`) and never unsets it. The CiWorkflowTest cases pass.

`app/Console/Commands/FluxProCommand.php`. Replace the class docblock with:

```php
/**
 * Installs the optional Flux Pro layer (§6.7, RL-12): adds the composer.fluxui.dev repository and requires
 * livewire/flux-pro with the credentials Composer already has, then clears compiled views, because Pro branches are
 * stripped at compile time while Pro is missing (DEC-29). It marks composer.json with extra.skv.flux-pro first, so an
 * app that commits Pro on purpose skips the kit's committed-files test (CD-13); Composer's lock hash covers `extra`,
 * so the mark goes in before the require. It never writes credentials and never prints them.
 */
```

Replace the `foreach` block in `handle()` with:

```php
        foreach ([
            [$composer, 'config', 'extra.skv.flux-pro', 'true', '--json'],
            [$composer, 'config', 'repositories.flux-pro', 'composer', 'https://composer.fluxui.dev'],
            [$composer, 'require', 'livewire/flux-pro', '--no-interaction'],
        ] as $command) {
            $result = $this->process($toolchain, 900)->run($command);

            if ($result->failed()) {
                // An install that did not finish must not mark the app as committing Flux Pro (CD-13).
                $this->process($toolchain, 60)->run([$composer, 'config', '--unset', 'extra.skv']);
                $this->components->error($this->lastLine($result));

                return self::FAILURE;
            }
        }
```

Run: `php artisan test --compact tests/Feature/Console/FluxProCommandTest.php tests/Feature/Tooling/CiWorkflowTest.php tests/Arch`

Expected: PASS.

**Verify by mutation:**
1. Move the mark after the require. The order test fails.
2. Drop the `--unset` call. The failure test fails.
3. Make `$commitsFluxPro` return `true`. The committed-files test reports skipped, and the new test fails on its `toBeFalse()` rows.
4. Restore all three.

- [ ] **Step 9: Fix, the per-task gate, then commit**

```bash
composer fix
composer check
composer test:pgsql
php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser
npm run build && composer test:browser
npm run build:setup && git diff --exit-code resources/setup/setup.css
php artisan config:clear
```

Expected: all green. `resources/setup/setup.css` is unchanged, because this task changes no setup view.

Commit in three steps, so each message describes one change:

```bash
git add tests/Pest.php tests/Arch/KitRulesTest.php tests/Feature/Setup tests/Feature/Console/DoctorCommandTest.php tests/Feature/Console/InstallCommandTest.php tests/Feature/Console/ProductionGuardsTest.php tests/Feature/Backups/BackupRunTest.php tests/Browser/SetupWizardTest.php
git commit -m "test: fake setup processes by name, guard setup tests against strays and render setup pages without a build

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"

git add tests/Arch/NameLeakTest.php app/Setup/Doctor/Checks/LastBackupCheck.php scripts/e2e-local.sh tests/Feature/Tooling/ComposerScriptsTest.php
git commit -m "test: scan app code for the kit name and pin the e2e script and its clone route

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"

git add app/Console/Commands/FluxProCommand.php tests/Feature/Console/FluxProCommandTest.php tests/Feature/Tooling/CiWorkflowTest.php tests/Arch/NameLeakTest.php
git commit -m "feat: mark composer.json when skv:flux-pro installs flux pro

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

> The staged sets overlap (`NameLeakTest.php` in the second and third commit, `CiWorkflowTest.php`'s g1-14 test in the third): stage whole files only and fold the overlap into the later commit, because `git add -p` is interactive and does not work in an agent shell. The pre-commit hook runs Pint and Larastan on the staged files; never bypass it.

The task report names:
- each mutation and its result (Steps 4–8)
- whether `BackupRunTest` needed the opt-out (Step 3)
- any pattern added beyond this list (Step 2)
- any `NameLeakTest` hit from Tasks 1–10 and how it was resolved (Step 4)

---

### Task 12: Documentation, copy and DEC-33 (F3 (1)(2)(3)(5), F17, F21, F22, g4-07, g4-10, g4-11, g3-18, g3-19, g3-20, g1-25, g1-26, CD-11, CD-12)

**Files:**
- Modify:
  - `README.md`, `CHANGELOG.md`, `docs/handleiding.html`
  - `docs/decisions.md`: DEC-25 (3), DEC-30's Consequence, DEC-31's Consequence, DEC-32 (17), (24) and Consequence, and the new DEC-33
  - `.ai/guidelines/skv3.md`, `lang/nl.json`
- Test (modify):
  - `tests/Feature/LangFilesTest.php` (the glossary, CD-12)
  - `tests/Feature/Tooling/CiWorkflowTest.php` (g4-07)
  - `tests/Feature/Tooling/ComposerScriptsTest.php` (g1-25)
- Regenerate: `AGENTS.md` (git-ignored, not committed).

**Interfaces:**
- Consumes: the merged Tasks 1–11 and their task reports. Before writing a line below, check it against the code: an `__()` key's exact English text, a constant, a command's output. Change the doc to match the code, never the code to match the doc, and record each such change in the task report.
- Produces: no code. A copy-only change of `lang/nl.json` values (no key changes), plus three test pins.

> Edits are given as old → new strings. Long handleiding lines are edited by unique substrings with the Edit tool. `nl.json` line numbers below are today's; Tasks 1–10 shift them, so locate entries by key.

- [ ] **Step 1: Pin the glossary and the docs facts (red)**

`tests/Feature/LangFilesTest.php`, append:

```php
it('keeps the Dutch glossary: installatie for setup, and Diagnose apart from Controle (CD-12)', function (): void {
    /** @var array<string, string> $dutch */
    $dutch = json_decode((string) file_get_contents(lang_path('nl.json')), true, flags: JSON_THROW_ON_ERROR);
    $setup = array_keys(array_filter($dutch, fn (string $value): bool => stripos((string) preg_replace('#[a-z]+:[a-z-]+|/welcome-setup|composer setup#', '', $value), 'setup') !== false));

    expect($setup)->toBe([])
        ->and($dutch['Doctor'])->toBe('Diagnose')
        ->and($dutch['Check'])->toBe('Controle');
});
```

`tests/Feature/Tooling/CiWorkflowTest.php`, replace 'checks in CI that the setup stylesheet is current (OD-1)' with:

```php
it('checks in CI that the setup stylesheet is current, and names the dependencies that change it (OD-1, g4-07)', function (): void {
    expect((string) file_get_contents(base_path('.github/workflows/tests.yml')))
        ->toContain('npm run build:setup && git diff --exit-code resources/setup/setup.css')
        // A Dependabot update of these changes the built stylesheet too, so the guideline names them.
        ->and((string) file_get_contents(base_path('.ai/guidelines/skv3.md')))
        ->toContain('`tailwindcss`')->toContain('`@tailwindcss/vite`')->toContain('`vite`')->toContain('`livewire/flux`');
});
```

`tests/Feature/Tooling/ComposerScriptsTest.php`, replace 'generates the Boost guidelines during setup' with the tightened, retitled test (g1-25, CD-11):

```php
it('leaves the Boost guidelines to the Claude step of skv:install, never to boost:update (CD-11)', function (): void {
    /** @var array{scripts: array{setup: list<string>}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    // AGENTS.md comes from the Claude step's boost:install (ClaudeStepTest); composer setup runs no Boost command itself.
    expect($composer['scripts']['setup'])->toContain('@php artisan skv:install')
        ->and(implode("\n", $composer['scripts']['setup']))->not->toContain('boost:');
});
```

Run: `php artisan test --compact tests/Feature/LangFilesTest.php tests/Feature/Tooling`

Expected:
- The glossary test FAILs on the ten `setup` values (`Doctor` is already `Diagnose` since Task 9). The regex strips every `namespace:command` (`skv:…`, `build:setup`) without spelling the kit name: a literal `skv:[a-z-]+` in a test is a `NameLeakTest` hit, because `skv:[` is no allowed form.
- The CiWorkflowTest case FAILs: the guideline names none of the four packages.
- The ComposerScriptsTest case PASSes: it pins today's script.

- [ ] **Step 2: Copy: `lang/nl.json` (g4-10, g4-11, CD-12, L-44)**

Values only; keys untouched. `lang/en.json` is unchanged (its values equal its keys).

| Key | Old `nl` value | New `nl` value |
|---|---|---|
| `A super admin already exists, so setup does not add the demo data by default. Pass \"start_with\": \"demo\" to add it anyway, or \"super_admin\" with your own login.` (line 23) | `Er bestaat al een superbeheerder, dus setup voegt de demodata niet standaard toe. Geef \"start_with\": \"demo\" mee om ze toch toe te voegen, of \"super_admin\" met je eigen login.` | `Er bestaat al een superbeheerder, dus de installatie voegt de demodata niet standaard toe. Geef \"start_with\": \"demo\" mee om ze toch toe te voegen, of \"super_admin\" met je eigen login.` |
| `Created the one-time setup token` (147) | `Eenmalige setup-token aangemaakt` | `Eenmalig installatietoken aangemaakt` |
| `Remove database/schema/pgsql-schema.* first: setup runs plain migrations (§7.3).` (441) | `Verwijder eerst database/schema/pgsql-schema.*: setup draait gewone migraties (§7.3).` | `Verwijder eerst database/schema/pgsql-schema.*: de installatie draait gewone migraties (§7.3).` |
| `Setup changed :keys in .env.` (499) | `Setup heeft :keys in .env gewijzigd.` | `De installatie heeft :keys in .env gewijzigd.` |
| `Setup has not finished yet` (500) | `De setup is nog niet klaar` | `De installatie is nog niet klaar` |
| `Setup stopped at :step: :message` (505) | `Setup gestopt bij :step: :message` | `Installatie gestopt bij :step: :message` |
| `Setup token` (506) | `Setup-token` | `Installatietoken` |
| `The setup token is gone` (599) | `Het setup-token is verwijderd` | `Het installatietoken is verwijderd` |
| `The setup token is still there` (600) | `Het setup-token staat er nog` | `Het installatietoken staat er nog` |
| `Unknown setup answers: :keys` (666) | `Onbekende setup-antwoorden: :keys` | `Onbekende installatieantwoorden: :keys` |

`Doctor` → `Diagnose` was done in Task 9 (L-44); check that it is still there.

Then list every `nl` value that still holds "setup":

```bash
php -r '$nl = json_decode(file_get_contents("lang/nl.json"), true); foreach ($nl as $k => $v) { if (stripos(preg_replace("#[a-z]+:[a-z-]+|/welcome-setup|composer setup#", "", $v), "setup") !== false) echo $k, " => ", $v, PHP_EOL; }'
```

Any line it prints is a key that Tasks 1–10 added. Examples: Task 2's host messages and its host-problem page, Task 4's "This step is still running.", Task 6's "skv:install runs only in the local, testing and staging environments (APP_ENV=:env)." Translate each with "installatie" or "installatietoken" (het-woord: "het installatietoken", "eenmalig installatietoken") and the informal "je". Keep command names (`skv:install`, `/welcome-setup`) as they are.

Run: `php artisan test --compact tests/Feature/LangFilesTest.php tests/Feature/LangCoverageTest.php`

Expected: the glossary test and both lang tests PASS.

- [ ] **Step 3: README**

Make each edit below. **Before the Scheduler and System page edits**, read `App\Support\Scheduler\OpsSchedule::define()` and the System page component, and write what they do. For example: which events use `onOneServer()` and `withoutOverlapping()`, and how the cleanup closure is named.

1. **Status (line 5).** Old:
   `> **Status:** under construction (milestone M7a — the operations layer: the health check, backups, the scheduler and the System page). See \`docs/superpowers/specs/2026-10-10-skv3-design.md\`.`

   New:
   `> **Status:** under construction (milestone M7b — setup hardening: the wizard's access rules, the Herd stage, the setup's state files, the doctor, the health check, the backups and the System page). See \`docs/superpowers/specs/2026-10-10-skv3-design.md\`.`

2. **Requirements (line 9, F21).** Old:
   `- PHP 8.4+, Composer 2, Node 22.13+ (required by \`composer dev\`)`

   New:
   `- PHP 8.4+ with the \`zip\` extension (spatie/laravel-backup needs it, also on SQLite), Composer 2, Node 22.13+ (required by \`composer dev\`)`

3. **When it opens (line 47, g4-01, g3-17, g3-26, g3-27, g3-25).** Replace the whole bullet with:

   `- **When it opens.** Only in *setup mode*: \`APP_ENV=local\`, no install marker (\`storage/app/skv-installed\`) and a setup token. Every other request to \`/welcome-setup\` gets a 404. So does a request from another machine: only \`127.0.0.1\` and \`::1\` get in, also when the site listens on every address. So does a request that arrives through a proxy or a tunnel such as \`herd share\`, which carries an \`X-Forwarded-*\`, \`X-Expose-*\`, \`X-Original-Host\` or \`Forwarded\` header. \`/\` hands out the token only while no super admin exists, and only on a host the wizard accepts. The wizard keeps a hash of the token in a new session, so a session id from before the token never gets in, and removes the token from the address bar and from the session's previous URL.`

4. **The host (line 49, g3-34, g1-17, g3-10, g3-28, g3-25).** Replace the whole bullet with:

   `- **The host.** Under Herd, open the setup on \`myapp.test\` or \`admin.myapp.test\`; without Herd on \`localhost\` or a name ending in \`.localhost\` or \`.test\`, with the port you serve it on (\`APP_URL\` keeps that port). On any other host \`/\` hands out no token and sends you to the address to use: without Herd to \`http://localhost:<port>/\`, also from an IP address such as \`127.0.0.1\` (the address \`php artisan serve\` prints), and under Herd to \`http://myapp.test/\`. Where no address can be named, \`/\` answers 409 with a page that names the problem: a folder whose name is not a valid host name (for example \`my_app\`) cannot be served as \`my_app.test\`, so the page asks you to rename the folder (\`my-app\`) or to link it under such a name (\`herd link my-app\`); when \`herd tld\` does not answer, it asks you to reload instead of guessing. Under Herd the app's address is pinned to \`myapp.test\`, whichever of the two you started on. From then on every wizard and Herd-stage request must use that host or its \`admin.\` form.`

5. **The questions (line 50, g4-04).** Append to the bullet:

   ` With a super admin already in the database the demo data is not added by default: choose *Demo data* yourself to add it anyway, as \`skv:install\` asks for \`"start_with": "demo"\`.`

6. **The steps (line 51, RL-8, g3-30, g3-31).** Replace the bullet's last sentence (`A step that fails shows *Setup stopped* with its message and *Try again*.`) with:

   `A step that fails, or a start that cannot write its state, shows *Setup stopped* with its message and *Try again*, never an error page. A long step whose process still runs cannot be re-checked until it ends; after four hours its process is no longer trusted, and *Re-check* works again.`

7. **The Herd stage (line 53, CD-14, g3-13, g3-22).**
   - Old: `*Log in as \`<email>\`* signs your new super admin in on the admin host with a single-use link;`
     New: `*Log in as \`<email>\`* signs your new super admin in on the admin host with a single-use link (a super admin who already confirmed two-factor sign-in goes to the login page instead, so the second factor is never skipped);`
   - Old: `These pages only work in the browser that ran the wizard, for at most 30 minutes, and the login works once.`
     New: `These pages only work in the browser that ran the wizard, on the pinned host, for at most 30 minutes, and the login works once; they send \`Referrer-Policy: no-referrer\`, so the secret in their address never leaves in a Referer header. When the Herd step cannot start at all, the page goes straight to the command to run yourself.`

8. **Continue without HTTPS (line 54, RL-6, RL-7).** Old:
   `When Herd did not confirm HTTPS in time, the page shows \`herd secure <name>\`.`

   New:
   `When Herd did not confirm HTTPS in time, the page shows the command that is left: \`herd secure <name>\`, or \`herd link <name>\` while Herd does not serve the site yet. A name with characters a shell would split is quoted.`

9. **Local quick login (line 58, g3-18, g3-15, g3-14).** Replace the paragraph with:

   `After a local install with demo data, the login page lists the demo accounts under *Quick login (local only)*: one click signs you in on the account's own host. It shows only when \`APP_ENV=local\`, the install marker records \`start_with: demo\` and the request comes straight from this machine (not through a proxy or a tunnel such as \`herd share\`). It lists only demo accounts that exist, are active and have no confirmed two-factor authentication (the quick login skips the second factor). An account whose password you changed stays in the list, but clicking it takes you back to the login page with a message. Everywhere else \`POST /quick-login\` answers 404, also for a malformed request.`

10. **The setup files (lines 66 and 73, g3-19).**
    - Row `storage/app/private/skv-setup/`: replace its middle cell with `per long step its result (\`<step>.json\`), output (\`<step>.log\`), exit code (\`<step>.exit\`) and the process id of its child (\`<step>.started\`)`.
    - Line 73. Old: `The state files are written atomically with mode 0600; only the token file holds a secret in plain text, the others hold hashes. Never write them by hand.`
      New: `The state files are written atomically with mode 0600. The logs and exit codes of the long steps and the Herd step, which their shell writes, get mode 0600 too, but are not written atomically. A state file that is a symbolic link or cannot be read counts as damaged. Only the token file holds a secret in plain text; the others hold hashes. Never write them by hand.`

11. **Commands table (lines 79–82).**
    - `skv:install` row.
      - Old: `inline JSON with a \`super_admin_password\` is refused before anything runs,`
        New: `inline JSON with a non-empty \`super_admin_password\` (a number too) is refused before anything runs,`
      - Old: `It refuses to run in production (\`APP_ENV=production\`), and outside local development (\`local\`, \`testing\`) it refuses \`start_with=demo\` before anything is written.`
        New: `It runs only in the \`local\`, \`testing\` and \`staging\` environments and refuses any other \`APP_ENV\` before anything is written; outside local development (\`local\`, \`testing\`) it also refuses \`start_with=demo\`.`
    - `skv:bootstrap` row. Old: `In production (the \`.env\` says \`APP_ENV=production\`)`
      New: `In production (the \`.env\` or the process environment says \`APP_ENV=production\`)`
    - `skv:doctor` row.
      - Old: `a missing \`HEALTH_CHECK_KEY\`,`
        New: `a missing \`HEALTH_CHECK_KEY\` or one shorter than 32 characters (replaced by a random 40-character key),`
      - Old: `The \`marker\` row fails when \`storage/app/skv-installed\` is not valid JSON,`
        New: `The \`marker\` row fails when \`storage/app/skv-installed\` is not valid JSON, cannot be read or is a symbolic link; while the database is unreachable, \`database\` fails and \`queue\` and \`failed-jobs\` are skipped instead of reporting a missing table;`
    - `skv:create-super-admin` row (L-54). Pick the variant that matches Task 6's commit:
      - Refusal on a terminal: append `On a terminal, \`--password-stdin\` refuses to read, because the password would echo: pipe it in.`
      - A hidden prompt: append `On a terminal, \`--password-stdin\` asks for the password without echoing it.`

12. **Health check (lines 92–100, CD-4, CD-5, F2, L-27, L-30, F17).**
    - Line 93. Append: ` A key shorter than 32 characters counts as no key: the endpoint answers 403, the doctor's \`health-check-key\` row fails and \`skv:doctor --fix\` replaces it with a random 40-character key.`
    - Line 94. Old: `Results are cached for 30 seconds, and the endpoint is throttled to 60 requests a minute per address.`
      New: `Results are cached for 30 seconds; when the ops store cannot be written, the endpoint still answers with a fresh report. Requests with the right key may come 60 times a minute for that key, and all requests without it share one limit of 300 a minute, so strangers can never lock your monitor out. A throttled request gets 429 with \`Retry-After\` and \`Cache-Control: no-store\`.`
    - After line 96, add two bullets:
      - `- A PostgreSQL server that does not answer costs at most \`DB_CONNECT_TIMEOUT\` seconds (5) per report: \`database\` fails, and \`queue\` and \`failed-jobs\` are skipped instead of reporting a missing table.`
      - `- The endpoint and the System page judge the running configuration (the mail settings, \`HEALTH_CHECK_KEY\`). \`skv:doctor\` and setup read \`.env\`, because they judge the file they repair.`
    - Replace the code block (lines 98–100) with:

      ````markdown
      ```bash
      printf 'X-Health-Check-Key: %s\n' "$HEALTH_CHECK_KEY" | curl -fsS -H @- https://example.com/health-check
      ```

      The key reaches curl on standard input (curl 7.55 or later), so it never shows in the process list.
      ````

13. **Backups (lines 106–108, CD-6, CD-7, CD-10, L-05).**
    - Line 106. Old: `\`backup:run\` refuses to start (also by hand: it exits 1 before anything is dumped)`
      New: `\`backup:run\` refuses to start (also by hand: it prints one error line and exits 1 before anything is dumped)`
    - Line 108. Replace the bullet with:

      `- **\`PG_DUMP_BINARY_PATH\`** is the directory that holds \`pg_dump\`. Blank finds it: when the \`pgsql\` connection's host is \`127.0.0.1\`, \`localhost\` or \`::1\` and Herd's PostgreSQL services exist, Herd's newest client comes first, because that server is the one being dumped; otherwise the one on PATH. Both work under cron, which has no Herd PATH. A Herd binary is used only when it and every directory above it up to \`/Users/Shared/Herd\` belong to you or root and are not group- or world-writable. \`pg_dump\` uses the connection's \`sslmode\` (\`DB_SSLMODE\`).`
    - After line 108, add:

      `- **The temporary dump.** \`backup:run\` writes the plain dump to \`storage/app/backup-temp\` (\`backup.backup.temporary_directory\`) before it zips it. A backup makes that directory private (mode 0700) when it starts, and every hour at :15 the scheduler removes the files older than six hours from the package's working directory under it (\`storage/app/backup-temp/temp\`), also while the backups module is off. Only a killed backup leaves files there; the next \`backup:run\` empties the directory too.`

14. **Scheduler (lines 115–128, CD-8, CD-10).**
    - Line 115. Replace with:

      `The kit schedules the ops jobs in \`App\Support\Scheduler\OpsSchedule::define()\`, which \`routes/console.php\` calls; the System page reads the same definition without booting the console kernel. Every job runs on one server (\`onOneServer()\`), and every job but the heartbeat never twice at once (\`withoutOverlapping()\`). The backup jobs' overlap lock expires after 12 hours instead of 24, so a killed backup never skips the next night. The times are in \`config('app.timezone')\` (UTC by default).`

      Name the cleanup closure among the exceptions if `OpsSchedule::define()` gives it no `withoutOverlapping()` or no `onOneServer()`.
    - Table: insert before the `auth:clear-resets` row:

      `| \`backup-temp-cleanup\` | every hour at :15 | always, also while the backups module is off (removes files older than six hours from \`storage/app/backup-temp/temp\`) |`

15. **System page (lines 138–143, F3 (3), F12, CD-16, F4).**
    - Line 138. Old: `in the *Systeem* group of the sidebar`
      New: `in the *System* group of the sidebar, *Systeem* in Dutch`
    - Doctor bullet. Replace it with:

      `- **Doctor:** *Run checks* runs a selection of the doctor checks that start no process; the development-only ones stay out. The selection: \`php\`, \`gd\`, \`app-key\`, \`marker\`, \`apex\`, \`database\`, \`migrations\`, \`permissions\`, \`organisation-admin-role\`, \`super-admin\`, \`super-admin-badges\`, \`session-driver\`, \`authenticate-session\`, \`health-check-key\`, \`backup-password\`, \`assets\`, \`mail\`, \`pg-dump\`, \`queue\`, \`failed-jobs\` and \`storage-link\`. Their repairs are not offered there: run \`php artisan skv:doctor --fix\`.`

      Use the key list exactly as `App\Support\Health\SystemChecks::KEYS` holds it after Task 7.
    - Append after the list:

      `*Refresh* and *Run checks* allow six runs a minute per user; above that the page keeps its rows and says *Too many attempts. Try again in a minute.* When the ops store or a backup disk fails, the page still opens: it says the health report is unavailable, or shows that disk as unreachable.`

16. **The answers schema (lines 147–152, F16).** Replace the sentence after the first one and the code block with the text below.

    ````markdown
    `super_admin_password` goes in a file or on standard input, never inline on the command line. Keep that file outside the project, readable only by you, and remove it afterwards (`/answers.json` is git-ignored as a safety net):

    ```bash
    (umask 077 && "${EDITOR:-vi}" ~/setup-answers.json)
    php artisan skv:install --no-interaction --answers=@"$HOME/setup-answers.json"   # or: --answers=- < ~/setup-answers.json
    rm ~/setup-answers.json
    ```
    ````

    Also, in line 164, old: `A \`super_admin_password\` given for an account that already exists replaces its password, also a demo account's.`

    New: `A \`super_admin_password\` given for an account that already exists replaces its password, also a demo account's; the same password again changes nothing (no new remember token, no audit entry).`

17. **Flux Pro (line 180, CD-13).** Replace with:

    `\`skv:flux-pro\` never writes or prints your credentials, and runs \`view:clear\` after it installs the package. Before it requires the package it marks \`composer.json\` with \`"extra": {"skv": {"flux-pro": true}}\`, so the lock stays current, and it removes the mark again when the install fails. Commit both Composer files to keep Pro in your app: the kit's test "never references Flux Pro in the committed composer files" then skips, because your commit carries the mark.`

18. **Production checklist (lines 193 and 195, CD-3, CD-4).**
    - Line 193. Old: `Never run \`php artisan skv:install\` there (it refuses in production); \`php artisan skv:bootstrap\` only prepares \`.env\`, \`APP_KEY\` and the generated secrets.`
      New: `Never run \`php artisan skv:install\` there (it runs only in \`local\`, \`testing\` and \`staging\`); \`php artisan skv:bootstrap\` only prepares \`.env\`, \`APP_KEY\` and the generated secrets, also when only the server's environment sets \`APP_ENV=production\`.`
    - Line 195. Old: `Set a non-empty \`HEALTH_CHECK_KEY\` (setup and the production \`skv:bootstrap\` generate one),`
      New: `Set a \`HEALTH_CHECK_KEY\` of at least 32 characters (setup and the production \`skv:bootstrap\` generate a 40-character one; a shorter key counts as none),`

19. **AI (after line 206, CD-11).** Add:

    `- \`AGENTS.md\`, which \`CLAUDE.md\` imports, is written by setup's Claude step (\`php artisan boost:install\`); with the Claude answer off, setup writes none. Run \`php artisan boost:install\` yourself when you add Claude Code later.`

20. **Develop the kit (line 221, g4-07).** Old: `or the theme (\`resources/css/theme.css\`, \`resources/css/setup.css\`). CI fails when the committed file is not current.`

    New: `or the theme (\`resources/css/theme.css\`, \`resources/css/setup.css\`), and after an update of \`tailwindcss\`, \`@tailwindcss/vite\`, \`vite\` or \`livewire/flux\` (a Dependabot pull request included). CI fails when the committed file is not current.`

Never print a real key, token or password in an example.

- [ ] **Step 4: CHANGELOG (Unreleased)**

Under **Fixed**, replace the stale line (g1-26, CD-11):

`- \`composer setup\` runs \`boost:update\`, so a fresh clone gets the Boost guidelines (\`AGENTS.md\`) that \`CLAUDE.md\` imports.`

with

`- Setup's Claude step writes the Boost guidelines (\`AGENTS.md\`) that \`CLAUDE.md\` imports; \`composer setup\` never ran \`boost:update\`, and without the Claude answer no \`AGENTS.md\` is written.`

Drop or adjust any line below whose task reported a deviation.

Append to **Added**:

```markdown
- `skv:flux-pro` marks `composer.json` with `extra.skv.flux-pro` before it requires the package, so an app that commits Flux Pro on purpose skips the kit's "never references Flux Pro (committed)" test.
- An hourly clean-up (`backup-temp-cleanup`, at :15, also while the backups module is off) removes the files older than six hours that a killed backup left in the package's working directory (`storage/app/backup-temp/temp`); `backup:run` now keeps the temporary directory private (mode 0700).
- `DB_CONNECT_TIMEOUT` (5 seconds) limits how long a PostgreSQL connection may take, so a database that does not answer cannot stall `/health-check` or the System page.
- The System page's *Refresh* and *Run checks* allow six runs a minute per user.
```

Append to **Changed**:

```markdown
- The setup wizard and the Herd stage answer only requests from this machine (`127.0.0.1` or `::1`) without an `X-Forwarded-*`, `X-Expose-*`, `X-Original-Host` or `Forwarded` header, check the pinned host on every request, start a new session when the token is accepted, and send `Referrer-Policy: no-referrer` on the Herd-stage pages.
- Without Herd, the wizard refuses an IP address as the host, and setup keeps `APP_URL`'s port. On a host the wizard cannot use, `/` hands out no token: it redirects to the address to use (`http://localhost:<port>/` without Herd, `http://<folder>.<tld>/` under Herd), or answers 409 with a page that names the problem when the folder name is not a valid host name or `herd tld` does not answer.
- The quick login follows the same rule: behind a proxy or tunnel it lists no accounts and `POST /quick-login` answers 404.
- After setup, a super admin who confirmed two-factor sign-in is sent to the login page instead of signed in with the single-use link.
- When the Herd step cannot start, the Herd stage shows the command to run yourself; that command is `herd link <name>` or `herd secure <name>`, whichever the site still needs, and every printed command quotes a name that needs it.
- The wizard no longer adds the demo data by default when a super admin already exists, as `skv:install` already refused.
- `skv:install` runs only in the `local`, `testing` and `staging` environments, and refuses an inline `super_admin_password` of any type; `skv:bootstrap` treats an install as production when `.env` or the process environment says `APP_ENV=production`.
- A `HEALTH_CHECK_KEY` shorter than 32 characters counts as no key: `/health-check` answers 403, the doctor's `health-check-key` row fails, and `skv:doctor --fix` replaces it with a random 40-character key.
- `/health-check` counts requests without the right key in one shared limit (300 a minute) and requests with it per key (60 a minute), so strangers cannot lock a monitor out; its 429 answers carry `Cache-Control: no-store`.
- The System page and `/health-check` judge the running mail and health-key settings; `skv:doctor` and setup keep reading `.env`. The System page also runs the `apex` check.
- With a PostgreSQL server on this machine the backups prefer Herd's newest `pg_dump` over the one on PATH, use a Herd binary only when it and its directories belong to you or root and are not group- or world-writable, and pass `DB_SSLMODE` to `pg_dump`.
- `backup:run`, `backup:clean` and `backup:monitor` hold their overlap lock for 12 hours instead of 24, so a killed backup never skips the next night; `backup:run` refuses an unencrypted backup with one error line instead of an exception trace, also for a configuration chosen with `--config`.
- The setup's super-admin password follows the app's password rules (`Password::default()`).
- The Dutch copy says *installatie* and *installatietoken* instead of *setup* and *setup-token*, and the doctor is *Diagnose*, no longer the same word as a check (*Controle*).
- The answers examples keep the answers file outside the project, and `/answers.json` is git-ignored.
```

Plus the line matching Task 6's L-54 choice:
- `` - `skv:create-super-admin --password-stdin` refuses to read a password from a terminal, where it would echo; pipe it in. ``
- or `` - `skv:create-super-admin --password-stdin` asks for the password without echoing it on a terminal. ``

Append to **Fixed**:

```markdown
- Setup and the doctor no longer crash on an unparsable `.env`, and read it once per change instead of on every child process.
- A state file that is a symbolic link or cannot be read counts as damaged: the doctor's `marker` row fails instead of the doctor crashing, and a setup token left next to such a marker is still reported.
- `herd.yml`, `.mcp.json`, `composer.json`, `.claude/settings.local.json`, `.env.testing` and the bootstrap's `.env` are written atomically and replace a symbolic link instead of writing through it; the long steps' logs and exit codes are created with mode 0600.
- A re-check of a long step whose process still runs is refused instead of starting it twice (for at most four hours after the step started, so a reused process id cannot block the step for good), and a step that finished between two polls keeps its result.
- The setup errors that still answered 500 (a start that cannot write its state, a long step whose completion check throws, a failure after the marker) show a message the page can act on.
- The wizard detects Herd and PostgreSQL once per setup instead of on every poll, and its page title no longer repeats *Installatie*.
- `POST /quick-login` with a malformed email answers 404 instead of 500.
- A Herd step that ends after the login no longer leaves the setup lock behind, and an old Herd exit file no longer times out a new Herd stage at once.
- A re-run with an unchanged super-admin password no longer rotates the remember token or logs a password change, and a secured site with an `http://` `APP_URL` is finished on `https://`.
- The demo seeder creates its super admin, badge and grant in one transaction; setup, `skv:create-super-admin` and the seeder share one creator.
- Setup's step errors and `.env` messages are translated.
- The doctor's `queue` and `failed-jobs` rows are skipped while the database is unreachable instead of reporting a missing table.
- `/health-check` still answers with the real report when the ops store can be read but not written.
- The System page no longer answers 500 when the ops store or a backup disk fails, no longer needs ext-intl for its sizes, no longer boots the console kernel on every render, and no longer takes the backup lock to show whether a job is on.
- The backups' source connection defaults to the app's default database (`sqlite`), like `config/database.php`.
```

- [ ] **Step 5: `docs/handleiding.html` (Dutch, informal "je")**

Old → new substrings; each old string is unique in the file.

1. **Line 600 (F21).**
   - Old: `<li>PHP 8.4 of nieuwer, Composer 2</li>`
   - New: `<li>PHP 8.4 of nieuwer met de extensie <code>zip</code> (nodig voor de back-ups, ook op SQLite), Composer 2</li>`
2. **Line 619.**
   - Old: `het eenmalige setup-token. Daarna zegt hij:`
   - New: `het eenmalige installatietoken. Daarna zegt hij:`
3. **Line 638.** Replace the `<li><strong>Wanneer hij opent.</strong> …</li>` item with:

   ```html
   <li><strong>Wanneer hij opent.</strong> Alleen lokaal (<code>APP_ENV=local</code>), zolang de marker <code>storage/app/skv-installed</code> ontbreekt en het installatietoken bestaat, en alleen voor verzoeken van deze computer zelf (<code>127.0.0.1</code> of <code>::1</code>). Anders antwoordt <code>/welcome-setup</code> met 404, ook vanaf een andere computer en via een proxy of een tunnel zoals <code>herd share</code> (met een header <code>X-Forwarded-*</code>, <code>X-Expose-*</code>, <code>X-Original-Host</code> of <code>Forwarded</code>). De startpagina geeft het token alleen zolang er nog geen superbeheerder is, en alleen op een adres dat de wizard accepteert. De wizard bewaart een hash van het token in een nieuwe sessie en haalt het token uit de adresbalk.</li>
   ```

4. **Line 639.** Replace the `<li><strong>Het adres.</strong> …</li>` item with:

   ```html
   <li><strong>Het adres.</strong> Open de wizard met Herd op <code>mijnapp.test</code> of <code>admin.mijnapp.test</code>, zonder Herd op <code>localhost</code> of een naam die eindigt op <code>.localhost</code> of <code>.test</code>, met de poort waarop je de site serveert (<code>APP_URL</code> houdt die poort). Op een ander adres geeft de startpagina geen token en stuurt hij je naar het adres dat je moet gebruiken: zonder Herd naar <code>http://localhost:&lt;poort&gt;/</code>, ook vanaf een IP-adres zoals <code>127.0.0.1</code> (het adres dat <code>php artisan serve</code> noemt), en met Herd naar <code>http://mijnapp.test/</code>. Valt er geen adres te noemen, dan antwoordt de startpagina met 409 en een pagina die het probleem noemt: is de mapnaam geen geldige hostnaam (zoals <code>mijn_app</code>), dan vraagt die je de map te hernoemen (<code>mijn-app</code>) of hem onder zo&rsquo;n naam te koppelen met <code>herd link mijn-app</code>; geeft <code>herd tld</code> geen antwoord, dan vraagt die je de pagina te herladen. Met Herd ligt het adres daarna vast op <code>mijnapp.test</code>, en elk verzoek van de wizard en de Herd-stap moet dat adres of zijn <code>admin.</code>-vorm gebruiken.</li>
   ```

5. **Line 640 (questions).**
   - Old: `Het wachtwoord wordt direct gehasht; alleen de hash wordt bewaard.</li>`
   - New: `Het wachtwoord wordt direct gehasht; alleen de hash wordt bewaard. Bestaat er al een superbeheerder, dan voegt de wizard de demodata niet vanzelf toe: kies <span class="ui">Demodata</span> zelf als je ze toch wilt.</li>`
6. **Line 641 (steps).**
   - Old: `Gaat een stap fout, dan zie je <span class="ui">Installatie gestopt</span> met de melding en <span class="ui">Probeer het opnieuw</span>.</li>`
   - New: `Gaat een stap fout, dan zie je <span class="ui">Installatie gestopt</span> met de melding en <span class="ui">Probeer het opnieuw</span>, nooit een foutpagina. Een lange stap waarvan het proces nog loopt, kun je pas opnieuw controleren als hij klaar is; na vier uur vertrouwt de wizard dat proces niet meer en kun je weer opnieuw controleren.</li>`
7. **Line 643 (Herd stage).**
   - Old: `logt je nieuwe superbeheerder &eacute;&eacute;n keer in op de admin-host;`
   - New: `logt je nieuwe superbeheerder &eacute;&eacute;n keer in op de admin-host (heeft die al tweestapsverificatie ingesteld, dan ga je naar de inlogpagina, zodat de tweede stap nooit wordt overgeslagen);`
   - Old: `Deze pagina&rsquo;s werken alleen in de browser waarin je de wizard doorliep, hooguit 30 minuten.</li>`
   - New: `Deze pagina&rsquo;s werken alleen in de browser waarin je de wizard doorliep, op het vastgelegde adres en hooguit 30 minuten, en ze sturen <code>Referrer-Policy: no-referrer</code> mee. Kan de Herd-stap helemaal niet starten, dan zie je meteen het commando om zelf te draaien.</li>`
8. **Line 644.**
   - Old: `dan zie je <span class="ui">Herd heeft HTTPS niet op tijd bevestigd.</span> met <code>herd secure &lt;naam&gt;</code>.`
   - New: `dan zie je <span class="ui">Herd heeft HTTPS niet op tijd bevestigd.</span> met het commando dat nog openstaat: <code>herd secure &lt;naam&gt;</code>, of <code>herd link &lt;naam&gt;</code> zolang Herd de site nog niet serveert (een naam met tekens die de shell zou splitsen, staat tussen aanhalingstekens).`

   Check the quoted UI string against `lang/nl.json` after Task 3.
9. **Lines 651–653 (setup files, g3-19).**
   - Old: `<td>Het eenmalige setup-token</td>`
   - New: `<td>Het eenmalige installatietoken</td>`
   - Old: `<td>Per lange stap de uitkomst, de uitvoer en de exitcode</td>`
   - New: `<td>Per lange stap de uitkomst, de uitvoer, de exitcode en het procesnummer van het kindproces (<code>&lt;stap&gt;.started</code>)</td>`
   - Old: `<p>Schrijf deze bestanden nooit met de hand. Pas je de setupviews`
   - New: `<p>Ze staan op modus 0600 (de uitvoer en de exitcodes, die de shell schrijft, niet atomair), en een statusbestand dat een symbolische link is of niet te lezen valt, telt als beschadigd. Schrijf deze bestanden nooit met de hand. Pas je de setupviews`
10. **Same paragraph (g4-07).**
    - Old: `<code>resources/css/setup.css</code>), draai dan <code>npm run build:setup</code>`
    - New: `<code>resources/css/setup.css</code>), of werk je <code>tailwindcss</code>, <code>@tailwindcss/vite</code>, <code>vite</code> of <code>livewire/flux</code> bij (ook via Dependabot), draai dan <code>npm run build:setup</code>`
11. **Line 714 (CD-12).**
    - Old: `<tr><td><span class="ui">Controle</span></td><td>Draait <code>skv:doctor</code></td></tr>`
    - New: `<tr><td><span class="ui">Diagnose</span></td><td>Draait <code>skv:doctor</code></td></tr>`
12. **Line 722 (F16, CD-3, L-55).**
    - Old: `zet je in een bestand (<code>--answers=@answers.json</code>) of geef je op standaardinvoer (<code>--answers=-</code>), nooit op de opdrachtregel: daar weigert <code>skv:install</code> het. In productie draait <code>skv:install</code> niet, en demodata kies je alleen lokaal.</li>`
    - New: `zet je in een bestand buiten het project dat alleen jij kunt lezen (<code>--answers=@"$HOME/setup-answers.json"</code>; gooi het daarna weg) of geef je op standaardinvoer (<code>--answers=-</code>), nooit op de opdrachtregel: daar weigert <code>skv:install</code> het, ook als het een getal is. <code>skv:install</code> draait alleen in <code>local</code>, <code>testing</code> en <code>staging</code>, en demodata kies je alleen lokaal.</li>`
13. **Lines 731–732 (F3 (2), CD-4, CD-12).** Replace the two items with:

    ```html
    <li><code>--fix</code> doet alleen veilige reparaties: <code>permissions:sync</code>, superbeheerders en rollen die uit de pas lopen, <code>storage:link</code>, de git-hooks, de build, een ontbrekende of te korte <code>HEALTH_CHECK_KEY</code> (korter dan 32 tekens; hij zet er een willekeurige van 40 tekens voor in de plaats), een ontbrekend <code>BACKUP_ARCHIVE_PASSWORD</code> (met de back-upmodule aan) en een installatietoken dat na de installatie is blijven staan (dat gooit hij weg).</li>
    <li>Tijdens de installatie slaat de diagnosestap de Herd-site, HTTPS en het installatietoken over (<span class="ui">Wordt na de Herd-stap gecontroleerd</span>), omdat de marker en de Herd-stap dan nog moeten komen.</li>
    <li>Is de database onbereikbaar, dan faalt <code>database</code> en staan de queue en de mislukte taken op <code>skipped</code>. Een installatiemarkering die geen geldige JSON is, niet te lezen valt of een symbolische link is, laat de regel <code>marker</code> falen.</li>
    ```

14. **Line 751 (g3-18, g3-15).**
    - Old: `Het blok verschijnt alleen als <code>APP_ENV=local</code> is en de installatie met demodata begon,`
    - New: `Het blok verschijnt alleen als <code>APP_ENV=local</code> is, de installatie met demodata begon en het verzoek rechtstreeks van deze computer komt (niet via een proxy of een tunnel zoals <code>herd share</code>),`
    - Old: `Heb je het wachtwoord van een demo-account veranderd, dan werkt snel inloggen er niet meer voor:`
    - New: `Heb je het wachtwoord van een demo-account veranderd, dan blijft het account in de lijst staan, maar werkt snel inloggen er niet meer voor:`
15. **Line 752 (L-54).** After `(zonder vragen geef je het wachtwoord op standaardinvoer met <code>--password-stdin</code>`, insert the variant that matches Task 6:
    - Refusal: `; op een terminal weigert hij, omdat je wachtwoord dan zichtbaar zou zijn: geef het via een pipe`
    - Hidden prompt: `; op een terminal vraagt hij het zonder het te tonen`
16. **Line 771 (F3 (5)).**
    - Old: `<li><strong>Er staan nog geen geplande taken.</strong> <code>schedule:list</code> is leeg. Opruimen van het log doe je met de hand: <code>php artisan activitylog:clean</code>.</li>`
    - New: `<li><strong>Zonder cronregel draait er niets.</strong> De planner ruimt het log om 01:00 op (<code>activitylog:clean --force</code>) en draait de back-ups, maar alleen als de cronregel <code>* * * * * cd /pad/naar/app &amp;&amp; php artisan schedule:run</code> op de server staat; <a href="#systeemstatus">Systeemstatus</a> toont de laatste hartslag.</li>`
17. **Its source line.**
    - Old: `routes/console.php:8-10; CLAUDE.md (Safety)`
    - New: `app/Support/Scheduler/OpsSchedule.php; CLAUDE.md (Safety)`
18. **Line 794 (HEALTH_CHECK_KEY row, CD-4).**
    - Old: `leeg geeft altijd 403. De setup en <code>php artisan skv:doctor --fix</code> vullen een willekeurige sleutel van 40 tekens in als hij leeg is</td>`
    - New: `minstens 32 tekens; leeg of korter geeft altijd 403. De setup en <code>php artisan skv:doctor --fix</code> zetten een willekeurige sleutel van 40 tekens als hij leeg of te kort is</td>`
19. **`OPS_CACHE_STORE` row.**
    - Old: `<td>Cache van de gezondheidsuitslag en de hartslag van de planner;`
    - New: `<td>Cache van de gezondheidsuitslag, de limieten van <code>/health-check</code> en Systeemstatus en de hartslag van de planner;`
20. **After the `DB_CONNECTION` row, add (F2):**

    ```html
            <tr><td><code>DB_CONNECT_TIMEOUT</code></td><td class="tabnum"><code>5</code> s</td><td>Hoe lang een verbinding met PostgreSQL mag duren; een server die niet antwoordt, houdt de gezondheidscontrole zo hooguit 5 seconden op</td></tr>
    ```

21. **Line 867 (Flux Pro, CD-13).**
    - Old: `draai daarna <code>npm run build</code>.`
    - New: `draai daarna <code>npm run build</code>. Voordat het Pro installeert, zet het <code>"extra": {"skv": {"flux-pro": true}}</code> in <code>composer.json</code>; commit je Pro bewust mee, dan slaat de kittest die Flux Pro uit de gecommitte composer-bestanden weert, zichzelf over. Mislukt de installatie, dan haalt het die markering weer weg.`
22. **Line 1262 (Gezondheid, CD-4, F2).**
    - Old: `met de waarde van <code>HEALTH_CHECK_KEY</code> in de header <code>X-Health-Check-Key</code>.`
    - New: `met de waarde van <code>HEALTH_CHECK_KEY</code> (minstens 32 tekens) in de header <code>X-Health-Check-Key</code>. Verzoeken met de juiste sleutel mogen 60 keer per minuut, alle verzoeken zonder samen 300 keer, zodat vreemden je monitor nooit buitensluiten. Antwoordt PostgreSQL niet, dan wacht de controle hooguit <code>DB_CONNECT_TIMEOUT</code> seconden (5); de queue en de mislukte taken staan dan op overgeslagen.`
23. **Line 1265 (L-44, F12, CD-16).** Replace the `<li><strong>Controle.</strong> …</li>` item with:

    ```html
    <li><strong>Diagnose.</strong> <span class="ui">Controles draaien</span> draait een selectie van de controles van <code>php artisan skv:doctor</code> die geen proces starten (de controles die alleen bij het ontwikkelen horen, ontbreken), ook <code>apex</code>. <span class="ui">Vernieuwen</span> en <span class="ui">Controles draaien</span> mogen elk zes keer per minuut; daarna blijven de regels staan en vraagt de pagina je het over een minuut opnieuw te proberen. Werkt de cache van de beheerlaag of een back-upschijf niet, dan opent de pagina toch: hij meldt dat het rapport niet beschikbaar is of dat de schijf onbereikbaar is. Repareren doe je in de terminal met <code>php artisan skv:doctor --fix</code>.</li>
    ```

24. **Line 1267 (Back-ups paragraph, CD-7, CD-10).**
    - Old: `dan gaat er een mail naar <code>BACKUP_NOTIFY_EMAIL</code>.</p>`
    - New: `dan gaat er een mail naar <code>BACKUP_NOTIFY_EMAIL</code>. Weigert <code>backup:run</code>, dan zie je één foutregel, geen stacktrace. Tijdens een back-up staat de onversleutelde dump even in <code>storage/app/backup-temp</code>; die map is dan alleen voor jou leesbaar (modus 0700). Elk uur om kwart over ruimt de planner in de werkmap <code>storage/app/backup-temp/temp</code> bestanden ouder dan zes uur op, ook als de back-upmodule uitstaat; alleen een afgebroken back-up laat ze achter. Het slot tegen een dubbele back-up vervalt na 12 uur, zodat een afgebroken back-up de volgende nacht niet overslaat.</p>`
25. **Line 1542 (status).** Replace the paragraph with:

    ```html
    <p>Milestones M0 tot en met M7a zitten in <code>main</code> (M7a sinds merge <code>2473d50</code>). M7b, het verstevigen van de setup, staat op de branch <code>m7b-setup-hardening</code> en gaat na de review naar <code>main</code>; daarna volgt M7c, de extra&rsquo;s en het verstevigen van de beveiliging. Er is nog geen release: alles staat onder <code>[Unreleased]</code> in de changelog.</p>
    ```

26. **Line 1555 (M6b row).**
    - Old: `het setup-token en de setupmodus`
    - New: `het installatietoken en de setupmodus`
27. **Line 1556 (M7a row, F3 (1)).**
    - Old: `wachtwoorden nooit meer als argument, en <code>skv:install</code> en de demodata alleen lokaal</td><td><span class="badge b-ok">op branch</span></td></tr>`
    - New: `wachtwoorden nooit meer als argument, <code>skv:install</code> niet in productie en de demodata alleen lokaal en in de tests</td><td><span class="badge b-ok">klaar</span></td></tr>`

    Add the M7b row after it:

    ```html
            <tr><td class="tabnum">M7b</td><td>Setup verstevigd: de wizard alleen vanaf deze computer en op het vastgelegde adres, statusbestanden die geen links volgen, een Herd-stap die altijd een uitslag of een commando geeft, <code>skv:install</code> alleen in <code>local</code>, <code>testing</code> en <code>staging</code>, een sleutel van minstens 32 tekens voor <code>/health-check</code> met aparte limieten, een privémap voor de tijdelijke dump, en een Systeemstatus die blijft werken als de cache of een schijf uitvalt</td><td><span class="badge b-ok">op branch</span></td></tr>
    ```

28. **The table's source line.**
    - Old: `git log (bfdaadd, 262f0e7, c416f61, 8c9ecab, 0b788a1, 6dc448a, 0b558b4; m7a-ops)`
    - New: `git log (bfdaadd, 262f0e7, c416f61, 8c9ecab, 0b788a1, 6dc448a, 0b558b4, 2473d50; m7b-setup-hardening)`
29. **Roadmap.** Delete the row `<tr><td><strong>M7b · Setup verstevigen</strong></td>…</tr>`.
30. **Colofon.**
    - Old: `de branch <code>m7a-ops</code> (M7a, na <code>47f27c6</code>)`
    - New: `de branch <code>m7b-setup-hardening</code> (M7b)`

Check every new `<span class="ui">…</span>` against the final `lang/nl.json`.

- [ ] **Step 6: The kit guideline and `AGENTS.md`**

`.ai/guidelines/skv3.md`:

1. **Setup, the "External commands" bullet (line 48).** Replace its last sentence (`Tests fake every command (\`Process::preventStrayProcesses()\` + \`Process::fake([])\` + a sandbox \`Toolchain\`).`) with:

   `Tests fake every command they expect by name, on Symfony's single-quoted command line (\`Process::fake(["*git' 'init'*" => …])\`), on top of \`Process::preventStrayProcesses()\` + \`Process::fake([])\` and a sandbox \`Toolchain\`. Never \`Process::fake(['*' => …])\`, a \`'*'\` key anywhere in the array, a closure or a bare \`Process::fake()\`: each fakes every command (\`tests/Arch/KitRulesTest.php\`). \`tests/Pest.php\` applies the guard to every test under \`Feature/Setup\`, \`Feature/Console\`, \`Feature/Backups\`, \`Feature/Health\` and \`Feature/System\`.`

2. **Setup, after that bullet**, add:

   - `- Project files setup writes (\`herd.yml\`, \`.mcp.json\`, \`composer.json\`, \`.claude/settings.local.json\`) go through \`App\Setup\Support\ProjectFile::write()\`, which is atomic and replaces a symlink. A command the kit prints quotes its arguments with \`App\Setup\Support\ShellArgument::quote()\`.`
   - `- "Straight from this machine" is \`App\Setup\Http\DirectRequest::isDirect()\` (loopback \`REMOTE_ADDR\`, no proxy headers), for the wizard, the Herd stage and the quick login; never re-implement it.`

3. **Line 51 (F22).** Replace with:

   `- A new doctor check is one class per check under \`App\Setup\Doctor\Checks\`, implementing \`App\Setup\Doctor\Check\`, and is listed in \`Doctor::checks()\`, unless it is a health-only check that a fresh install cannot pass (\`last-backup\`), which goes only in \`HealthReport::checks()\` (see Ops).`

4. **Line 55 (RL-3).** Append:

   ` \`useSetupWizard()\` also binds a \`Detector\` without the PostgreSQL probe (\`withoutPostgresProbe()\`); a test that needs the real probe rebinds \`Detector\` itself.`

   Skip this if Task 1 already wrote it.

5. **Line 56 (g4-07, g4-06).** Replace with:

   `- Run \`npm run build:setup\` and commit \`resources/setup/setup.css\` after changing \`resources/views/{layouts/setup.blade.php,pages/setup,setup}\` or \`resources/css/{theme,setup}.css\`, and after updating \`tailwindcss\`, \`@tailwindcss/vite\`, \`vite\` or \`livewire/flux\` (a Dependabot pull request included); CI checks the file is current. The setup pages never use \`@vite\`, \`Vite::\`, \`/build/\`, \`@fonts\` or \`Skv::hasFluxPro()\` (\`tests/Arch/KitRulesTest.php\`).`

6. **Ops, line 61 (RL-11).** Replace with:

   `- Ops caches (the health report, the health-check throttle, the scheduler heartbeat, the System page's limiter) use \`App\Support\Health\OpsCache::store()\`, never the default store, so \`/health-check\` still answers while the database is down.`

7. **Ops, append:**

   - `- The ops schedule lives in \`App\Support\Scheduler\OpsSchedule::define()\`, which \`routes/console.php\` calls; web code reads it through \`App\Support\Scheduler\ScheduledTasks\`, never by booting the console kernel.`
   - `- The health-key rule is \`App\Support\Health\HealthCheckKey\` (at least 32 characters); never check a key anywhere else.`
   - `- Never \`exec()\`, \`shell_exec()\`, \`system()\`, \`passthru()\`, \`proc_open()\` or backticks in \`app/\` or \`tests/\`. Format byte sizes with \`App\Support\ByteSize::format()\`, never \`Illuminate\Support\Number\`, which needs ext-intl.`

   Use the exact class names Tasks 8–10 built; skip a line that a task already added.

Then regenerate `AGENTS.md`:

```bash
php artisan boost:update --no-discover --ignore-skills --no-interaction
git status --short
```

Expected: `git status` lists only the files this task changed. `AGENTS.md` is git-ignored. If `CLAUDE.md` or `boost.json` changed, inspect the diff, restore them (`git checkout -- CLAUDE.md boost.json`) and record it in the task report.

- [ ] **Step 7: `docs/decisions.md`: earlier decisions**

1. **DEC-25 (3), CD-11.**
   - Old: `` `composer setup` runs `boost:update` once so a fresh clone gets `AGENTS.md`, which `CLAUDE.md` imports. ``
   - New: `` superseded in part, see DEC-33 (11): `composer setup` runs no `boost:update`, and `AGENTS.md`, which `CLAUDE.md` imports, comes only from setup's Claude step. ``
2. **DEC-30's Consequence (g3-20).**
   - Old: `the browser Herd step with its status endpoint and the signed login URL`
   - New: `the browser Herd step with its status endpoint, the secret-bound Herd stage and the single-use handoff (DEC-31 (6), (23))`
3. **DEC-31's Consequence.** Append after `M7 was split into M7a, M7b and M7c (DEC-32, CD-1).`:

   ` M7b done (DEC-33): the carried M6b minors were re-triaged against \`main\` \`2473d50\` and fixed in M7b or deferred as DEC-33 (29) and its Consequence record. The session id is now regenerated when the token is accepted, the step logs and exit codes are written with mode 0600, and the Herd stage compares the request host with the pinned host on every request. DEC-33 (20) replaces (2)'s "The IP is not checked", and (30) drops (3)'s \`127.0.0.1\` without Herd.`
4. **DEC-32 (17), F12.**
   - Old: `` lists the twenty checks that start no process (`php`, `gd`, `app-key`, `marker`, ``
   - New: `` lists a selection of the checks that start no process, without the development-only ones (`php`, `gd`, `app-key`, `marker`, `apex` (added in M7b, DEC-33 (31)), ``
5. **DEC-32 (24), the A8 sentence.**
   - Old: `` A8, an unreadable install marker no longer crashes the doctor: `DoctorContext` reads it as absent and the new `marker` row fails ("Restore it from a backup, or delete it and run php artisan skv:install"); ``
   - New: `` A8, an install marker that is not a JSON object no longer crashes the doctor: `DoctorContext` reads it as absent and the new `marker` row fails ("Restore it from a backup, or delete it and run php artisan skv:install"); a marker that permissions made unreadable still crashed it until M7b (DEC-33 (18)); ``
6. **DEC-32's Consequence.** Append:

   ` M7b done (DEC-33): the A/T numbering above lived only in a working report that was lost, so M7b re-triaged its sources (DEC-33 (1), (29)). The ledger minors named here are fixed in M7b: the System page's 500s, the throttle, \`pg_dump\`'s sslmode, the private dump directory, the 12-hour mutex, \`--password-stdin\` on a terminal, \`skv:install\`'s allowlist and the ops-store tests. \`LastBackupCheck\`'s message, which still does not name encryption \`none\`, goes to M7c. DEC-33 (3) replaces (9)'s refusal of the literal \`production\`, (4) replaces (5)'s per-address throttle, (6) replaces (14)'s order, and (8) replaces (30)'s kernel bootstrap.`

- [ ] **Step 8: `docs/decisions.md`: DEC-33**

Append after DEC-32. One blank line before the heading. The Answer is one paragraph, as in DEC-32.

> Write the three marked placeholders ((32), (34) and the Consequence's last sentence) exactly as `(filled in after execution)`. The controller replaces them from the task reports, the review fix rounds and Task 13's real run. If Task 11 did not need `BackupRunTest`'s opt-out, drop that clause from (28). If Task 2 dropped `X-Original-Host` (note 8), adjust (20).

```markdown
### DEC-33 — Setup hardening build rulings (M7b)

**Question:** Which choices made while building M7b refine the spec or earlier decisions, what did the controller decide under the owner's delegation, and what is deferred?
**Answer:** Items (1)–(16) are the controller's decisions CD-1…CD-16, taken under the owner's delegation of every non-destructive M7 decision (2026-10-15); the owner can overrule each of them. Items (17)–(28) are the M7b plan's rulings RL-1…RL-12 (`docs/superpowers/plans/2026-10-16-m7b-setup-hardening.md`) as built, (29) the triage and how it replaces DEC-32's lost numbering, (30) the deviations from the spec with their reasons, (31) the earlier decisions this one supersedes or amends, (32) the deviations from the plan that the task reports and the review fix rounds recorded, with the controller's rulings during the run, (33) the documentation, and (34) the controller's real wizard run. (1) CD-1, a fresh triage: DEC-32 named the M7b scope as "A12–A56, A59, A61–A63, A65–A79 and T1–T8", but that numbering lived only in a working report that was lost when the session's context was compacted, so M7b re-triaged the source lists against `main` `2473d50`: the M6a and M6b final-review lists, the M7a final review (F1–F28, O1–O3) and the M7a build ledger's 66 deferred minors (L-01…L-66); (29) gives the outcome. (2) CD-2, won't fix: `?secret=` in the Herd-stage URLs (g3-03), because DEC-31 (6) keeps it there (single use, 30 minutes, local only), a cookie cannot cross the http→https origin change, and the Referer leak is closed by the stage's `Referrer-Policy: no-referrer` (g3-13); a stale Claude plugin-registry entry (g3-04), which is Claude Code's machine state, not something the kit writes (the owner removes it by hand); `/health-check` on tenant hosts and the SMTP address in its JSON (O2), both behind the key by design (DEC-32 (15)), with the key hardened by CD-4; a crashed repair's trace that may log 15 characters of `.env` (L-12), which needs `zend.exception_ignore_args=Off` plus a failing `EnvFile`, and stays in the local log; and a duplicated test closure (L-53), file-local by the M7a brief. (3) CD-3, where install and bootstrap run (L-60, L-61): `skv:install` runs only while `app()->environment()` is `local`, `testing` or `staging` and refuses every other name with "skv:install runs only in the local, testing and staging environments (APP_ENV=:env).", an allowlist instead of DEC-32 (9)'s refusal of the literal `production`, so `live` or `prod` is refused too; `skv:bootstrap` takes its production half when `.env` or the process environment (`getenv('APP_ENV')`, `$_SERVER['APP_ENV']`) says `production`, which closes DEC-32 (33)'s gap for a server that sets `APP_ENV` only in its environment. (4) CD-4, the health key and the throttle (F14, F15, L-28): `App\Support\Health\HealthCheckKey` holds the key rule with `MIN_LENGTH = 32`; a configured key shorter than that counts as not configured, so the endpoint answers 403, the doctor's `health-check-key` row fails ("HEALTH_CHECK_KEY is too short") and its repair replaces the short key with `Str::random(40)`, and `EnvironmentStep::missingSecrets()` counts a short key as missing, so setup and the production bootstrap replace it too. The throttle checks the key first: requests without a matching key count in one global bucket, `health-check:denied`, at 300 a minute (429 above that), and requests with it per key hash, `health-check:ok:<sha256(key)>` (`sha1` is banned in `App` by the security preset), at 60 a minute, so strangers never lock the monitor out and key-less traffic leaves one cache entry instead of one per address. (5) CD-5, runtime settings (F10): `DoctorContext` gains `public bool $runtime`, true only from `withoutDetection()`, and `setting(string $envKey, string $configKey): ?string`, which reads the running configuration in a runtime context and `.env` otherwise; `MailCheck` and `HealthCheckKeyCheck` use it, so `/health-check` and the System page judge what the app runs with, while `skv:doctor` and setup keep reading `.env`, because they judge the file they are about to write. (6) CD-6, which `pg_dump` (F5, F13): `PG_DUMP_BINARY_PATH` still wins; otherwise Herd's newest service binary is preferred over the toolchain PATH when the `pgsql` connection's host is `127.0.0.1`, `localhost` or `::1` and Herd's services exist, because Herd's server is then the one being dumped; a Herd-glob candidate is accepted only when it and every directory up to `/Users/Shared/Herd` are owned by the current user or root and are not group- or world-writable; there is no version probe, because the System page must not start a process. (7) CD-7, a clean refusal (O1): `App\Console\Commands\BackupRunCommand` extends spatie's `BackupCommand` under the same `backup:run` signature (the kernel's discovered commands register after the package's, so the app's command wins the name); it first resolves a `--config=<name>` configuration itself (`resolveConfig()`, which the package's `handle()` runs only after the check), so the check judges the configuration the run will use and `backup:run --config=<a configuration without a password>` refuses too; while `BackupEncryption::available()` is false it prints one error line (the M7a refusal text) and returns 1, otherwise it calls `parent::handle()`; it carries no `#[AsCommand]`, because a lazily registered command would lose the name to the package's eagerly added one. `RefuseUnencryptedBackups` stays as the backstop: `CommandStarting` fires before `handle()`, so the listener steps aside while `Artisan::findCommand('backup:run')` is the app's command and still throws when the package's command holds the name. (8) CD-8, the ops schedule (F1, F8, L-39): `App\Support\Scheduler\OpsSchedule::define(Schedule $schedule): void` holds the ops events and `routes/console.php` calls it; `ScheduledTasks` builds a fresh `Schedule`, calls `define()` and evaluates each event's filters after `preventOverlapsUsing(new InertEventMutex())`, on copies, not the events the scheduler runs, so a System page render never boots the console kernel and never touches a real overlap lock. (9) CD-9, sizes without intl (F20): `App\Support\ByteSize::format(int|float $bytes): string` (1024-based, `B`, `KB`, `MB`, `GB`, `TB`, at most one decimal) replaces `Number::fileSize()`, which needs ext-intl that nothing requires; an arch rule bans `Illuminate\Support\Number` in `app/` and `resources/views/pages`. (10) CD-10, stale dumps and the overlap lock (F6, F18, L-07): `backup:run`, `backup:clean` and `backup:monitor` use `withoutOverlapping(720)`, so a killed run's lock has expired by the next night; a `CommandStarting` listener for `backup:run`, `App\Support\Backups\PrivateBackupTemp`, creates the temporary directory with mode 0700 or chmods an existing one to 0700; and a scheduled closure, `backup-temp-cleanup`, runs hourly at :15 on every server (each has its own temporary directory), also while the module is off, so a dump left behind before the module was switched off still goes; it removes files older than six hours only under the package's working directory `<temporary_directory>/temp`, never directories and never the rest of a temporary directory pointed at a shared folder; spatie's `BackupJob` already empties `temp/` at the start of every run, so the cleanup covers the hours until the next one; `PrivateBackupTemp` never touches the system temporary directory, a sticky directory or one another account owns; there is no new health check. (11) CD-11, `AGENTS.md` without Claude (g1-25, g1-26): DEC-25 (3)'s "`composer setup` runs `boost:update` once" is superseded: the script never ran it (`ComposerScriptsTest` pins it), and `boost:install`, which writes `AGENTS.md`, runs only in setup's Claude step (`laravel/boost` itself stays in `require-dev`, DEC-25 (2)), so `AGENTS.md` exists only with the Claude answer, and `CLAUDE.md`'s import of it matters only to Claude Code; the CHANGELOG line is corrected, with no code change. (12) CD-12, copy: every Dutch "setup" and "Setup-token" in `lang/nl.json` became "installatie" and "installatietoken", with the het-form ("Eenmalig installatietoken aangemaakt"), and "Doctor" became "Diagnose", so the System page's doctor heading and the wizard's doctor step no longer read the same as a check ("Controle"); the keys are unchanged, and a `LangFilesTest` case keeps the glossary. (13) CD-13, the Flux Pro committed-files test in generated apps (g1-16): `skv:flux-pro` writes `"extra": {"skv": {"flux-pro": true}}` into `composer.json` with `composer config extra.skv.flux-pro true --json` before it requires the package, because Composer's lock hash covers `extra` and the require then leaves the lock current; when the repository or the require step fails, `composer config --unset extra.skv` removes the mark again. The test "never references Flux Pro in the committed composer files (committed)" skips when the committed `composer.json` carries the mark ("this app committed Flux Pro on purpose with skv:flux-pro"); the kit never commits it, so the kit's own run, and CI after `skv:flux-pro` changed only the working tree, still check. Composer edits the file itself, so no test writes the repository's `composer.json`, and RL-1's `ProjectFile` has no caller in `skv:flux-pro`. (14) CD-14, second-factor accounts after setup (g4-03): when the answered super admin has `two_factor_confirmed_at`, the Herd stage's login sends the browser to the apex login page instead of issuing a handoff, as a demo install already does. (15) CD-15, detection once (g3-29): `WizardRunner::begin()` detects once and stores the `Detection` in `WizardState` (`Detection::toArray()` / `fromArray()`); `advance()`, `finish()`, `settleWithoutHttps()` and `confirmHerd()` rebuild their context from that snapshot, and the Herd stage keeps its own copy in `HerdState`. (16) CD-16, System page limits (L-45): `refreshHealth` and `runDoctor` allow six calls a minute per user each (`RateLimiter::attempt('system:<action>:<user id>', 6, …)` on the ops store); above that the page keeps its rows and shows "Too many attempts. Try again in a minute." (17) RL-1, `ProjectFile` (g2-07, g2-08): `App\Setup\Support\ProjectFile::write(string $path, string $contents): void` writes through `StateFile::write($path, $contents, $mode)`, which gained `int $mode = 0600`; the mode is the existing regular file's, else 0644, and a symlink at `$path` is replaced, not followed; `herd.yml` (`HerdManifestStep`), `.mcp.json`, `composer.json` and `.claude/settings.local.json` (`ClaudeStep`) use it, and `.env.testing` (`DatabaseStep`) goes through `StateFile::write()` with `.env`'s mode, the bootstrap's `.env` copy with `.env.example`'s. (18) RL-2, `StateFile::readJson()` (g3-07, L-09, F3 (4)): a symlink throws `JsonException(":file is a symbolic link.")` and a file that exists but cannot be read throws `JsonException(":file is not readable.")`, so `DoctorContext`, `MarkerCheck`, `WizardState` and `HerdState`, which catch `JsonException`, treat both like a damaged file; this makes DEC-32 (24)'s A8 true for a marker that permissions make unreadable. (19) RL-3, the probe seam (g1-13, g4-08): `Detector::__construct(Toolchain $toolchain, ?Closure $postgresProbe = null)`, where a given probe `fn (string $host, int $port): bool` replaces the PDO probe; `withoutPostgresProbe(bool $reachable = false): void` in `tests/Pest.php` binds a `Detector` with that probe, and `useSetupWizard()` calls it, so wizard tests never open a socket; the PostgreSQL wizard cases rebind `Detector` themselves. (20) RL-4, `DirectRequest` (g4-01, g3-17): a request is direct only when the app is local, `REMOTE_ADDR` is in `127.0.0.0/8` or is `::1`, no header name starts with `x-forwarded-` or `x-expose-`, and no `Forwarded` or `X-Original-Host` header is present (§5.3.4 and DEC-31 (2) list the latter); `herd share` traffic arrives from loopback too, so the header rule stays. This replaces DEC-31 (2)'s "The IP is not checked" and its three `X-Forwarded-*` names, because a server bound to every address (`php artisan serve --host=0.0.0.0`) would otherwise let another machine on the network run the wizard. (21) RL-5, the pinned host (g3-25): `App\Setup\Http\SetupHostGuard::allows(Request $request, ?string $pinned): bool` holds when `SetupHost::normalise()` of the request host equals the pinned host or its `admin.` form, or, before `begin()` pins one, when `SetupHost::forWizard()` accepts it; `SetupMode::homeRedirect()` and `HerdStage::state()` use it in both forms, and `EnsureSetupAccess::authorised()` only once a `WizardState` has pinned a host, so the page can still show the host-problem callout before `begin()`; the pinned host is `WizardState::$host` or `HerdState::$host`. On a host the wizard cannot pin (an IP address such as `127.0.0.1`, a folder that is no DNS label, a failed `herd tld`), `/` hands out no token either: `SetupMode::homeHostProblem()` makes it redirect token-less to the suggested address (`http://localhost:<port>/` without Herd, `http://<folder>.<tld>/` under Herd), or answer 409 with the `setup.host-problem` page naming the problem where no address can be suggested; that page never carries the token. Residual: without Herd, before `begin()`, a DNS-rebinding page on a `*.test` name can still be handed the token by `/`; it needs control of the developer's DNS during the pre-install window, and the loopback rule (RL-4) still applies. (22) RL-6, `ShellArgument` (g2-05): `App\Setup\Support\ShellArgument::quote(string $value): string` returns the value unchanged when it matches `/^[A-Za-z0-9._\/:=@%+-]+$/`, else `escapeshellarg($value)`; every command the kit prints (manual and fix commands) uses it, and process argv is unchanged. (23) RL-7, the manual Herd command (g3-23): `HerdSiteStep::manualCommand(SetupContext $context): ?string` returns null without Herd, `herd link <name>` when the site is not served, `herd secure <name>` when HTTPS was chosen, else null; the wizard runner, `SetupFinishController` and `RunStepCommand` use it. (24) RL-8, the long step's process (g3-12): `StepLauncher::start()` gained `?string $pidFile = null`, and the detached inner `sh -c` writes its own `$$` into `<step>.started` (written empty first; its mtime still marks the start), runs php and then writes the exit code, so the pid lives exactly as long as the step (`exec php` would leave nothing to write the exit code); `WizardRunner::recheck()` refuses with "This step is still running." while `posix_kill($pid, 0)` reports the process alive, and the 30-minute stall rule reports a live pid as still running, for at most four hours after the step started (`LIVE_PID_SECONDS`): a shell that died without writing the exit file (a reboot, an OOM kill) can leave a pid that another process reuses, so an older pid is no longer trusted and the step ends manual. (25) RL-9, one super-admin creator (g2-11, g2-13): `App\Authorization\SetSuperAdmin::createNew(array $attributes): User` inserts a user with `is_super_admin = true`, `organisation_id = null` and status Active and calls `handleCreated()`, in one `DB::transaction()`; `DatabaseStep`, `skv:create-super-admin` and `DemoSeeder` call it. (26) RL-10, the generated secrets (L-56): `EnvironmentStep::missingSecrets(EnvFile $env): array<string, string>` returns a fresh random value of the `GENERATED` length for every blank `GENERATED` key, and for a `HEALTH_CHECK_KEY` that fails `HealthCheckKey::isUsable()` (CD-4); `EnvironmentStep`, `Bootstrap` and `BackupPasswordCheck` use it. (27) RL-11, the ops store (F7): `App\Support\Health\OpsCache::store(): Illuminate\Contracts\Cache\Repository` returns `Cache::store((string) config('skv.ops_cache_store', 'file'))`; `HealthReport`, `Heartbeat`, `ThrottleHealthChecks` and the System page's limiter use it. (28) RL-12, the stray-process guard (g4-09, g2-10): `tests/Pest.php` runs `Process::preventStrayProcesses()` and `Process::fake([])` before every test under `Feature/Setup`, `Feature/Console`, `Feature/Backups`, `Feature/Health` and `Feature/System`. The plan named only `preventStrayProcesses()`, but Laravel 13.35's `PendingProcess::run()` refuses a stray process only while the factory records, which `Process::fake()` starts, so the hook calls both; a file's own `Process::fake([...])` adds its named patterns, and `BackupRunTest`'s version probe of the real `pg_dump` opts out with `Process::preventStrayProcesses(false)`. `KitRulesTest` fails on `Process::fake(['*' …])`, on a `'*'` key anywhere in a fake's array, on a bare `Process::fake()` and on a closure catch-all anywhere under `tests/`; the 18 `'*'` keys in 10 files (one of them, `BootstrapTest.php:151`, the second key of its array) and the 2 bare fakes were replaced by named patterns for the commands each test reaches, including those that run only where Node, Claude Code or Flux Pro is installed, because `Toolchain::which()` scans the system PATH. (29) The triage: six read-only agents checked every item of the four source lists against `main` `2473d50`, 221 entries after splitting bundled lines. 48 were already fixed (most by M6b's fix waves and M7a), 1 was obsolete (g1-11, after spec §5.2 changed), 15 duplicated another item (L-14, L-15, L-19, L-25, L-32's store half, L-33, L-34, L-35, L-37, L-38, L-43, L-62, L-63, L-64 and L-65, each closed with its F or O item), 141 were in scope for M7b (Tasks 1–12), 4 went to M7c, 7 to M8 and 5 are won't-fix (CD-2). The ids of the plan's triage table (`g1-NN`/`g2-NN` from M6a, `g3-NN`/`g4-NN` from M6b, `F1`–`F28` and `O1`–`O3` from the M7a final review, `L-NN` from the M7a ledger) replace DEC-32's A/T numbering; the plan's requirement table maps every in-scope id to its task. Two of the triage's suggested fixes changed during planning: the browser wizard tests wait with a raised `Playwright::setTimeout()` instead of `Playwright::usingTimeout()`, which changes only each request's timeout while pest-plugin-browser 5.1 retries an assertion for `Playwright::timeout()` (g4-22), and `NameLeakTest` allows `extra.skv` (CD-13) besides the exact state-file names (g4-05). (30) The deviations from the spec, each with its reason: (a) §5.3.4's access gate also requires a loopback `REMOTE_ADDR` (RL-4), so a server that listens on every address never lets another machine run the wizard; (b) without Herd the wizard no longer opens on `127.0.0.1` or another IP host (g3-34) and `/` sends the browser to `http://localhost:<port>/` instead, because the host becomes `TENANCY_APEX_DOMAIN` and an IP cannot carry organisation subdomains, while `*.localhost` resolves in browsers (DEC-29); (c) §5.3.4 / §6.5's local quick login also follows the direct-request rule (g3-15), so a `herd share` visitor never sees or uses the demo accounts; (d) §4.9's health-check throttle counts by key, not by address (CD-4), so strangers cannot lock a monitor out; (e) §4.9's scheduler gains an hourly `backup-temp-cleanup` (at :15, also while the backups module is off) and gives the backup jobs a 12-hour overlap lock instead of the default 24 hours (CD-10), so a killed backup neither leaves a plaintext dump behind nor skips the next night. (31) Earlier decisions superseded or amended: DEC-25 (3) by CD-11 (marked in place); DEC-30's Consequence names the secret-bound Herd stage and the single-use handoff instead of "the signed login URL" (g3-20); DEC-31 (2)'s "The IP is not checked" by RL-4, and DEC-31 (3)'s `127.0.0.1` without Herd by (30) (b); DEC-32 (5)'s 60 requests a minute per address by CD-4; DEC-32 (9)'s refusal of the literal `production` by CD-3; DEC-32 (14)'s order (`.env`, PATH, Herd) by CD-6; DEC-32 (17)'s `SystemChecks::KEYS` gains `apex` after `marker`, its place in `Doctor::checks()` (F12), so it is a selection of the checks that start no process, without the development-only ones (amended in place); DEC-32 (24)'s A8 sentence is corrected in place (an unreadable marker still crashed the doctor until RL-2); DEC-32 (30)'s console-kernel `bootstrap()` by CD-8. (32) The deviations from the plan that the task reports and the review fix rounds recorded, and the controller's rulings during the run: (filled in after execution). (33) The documentation (Task 12): the README describes the wizard's loopback and header rule, the pinned host on every request, the IP host and port rules, the token-less redirect and the 409 host-problem page with the folder-name and `herd tld` messages, the default demo choice, the Herd stage's second-factor login, `Referrer-Policy` and manual command, the quick login's direct-request rule and the account that stays listed (g3-18), the setup files' `<step>.started` and modes (g3-19), `skv:install`'s allowlist and inline-password refusal, the production bootstrap from the process environment, the `--fix` list with the short health key, the `marker` and outage rows, the health key's 32 characters, the split throttle with `Cache-Control: no-store` on 429, `DB_CONNECT_TIMEOUT`, the runtime settings, a `curl` example that reads the key from standard input (F17), `pg_dump`'s choice and `DB_SSLMODE`, the one-line refusal, the private temporary dump and its clean-up, the 12-hour lock, the schedule in `OpsSchedule`, the System page's *System* group (F3 (3)), `apex`, limits and unavailable states, the answers file outside the project (F16), the Flux Pro mark (CD-13), `AGENTS.md` only with Claude (CD-11), `ext-zip` (F21) and the dependency updates that need `npm run build:setup` (g4-07). `docs/handleiding.html` got the same in Dutch and lost its stale lines (F3 (1), (2), (5)); the CHANGELOG lists every change and corrects its `boost:update` line (g1-26); the guideline got the process-fake rule, `ProjectFile`, `ShellArgument`, `DirectRequest`, the qualified doctor-check rule (F22), the probe seam, `OpsCache`, `OpsSchedule`, `HealthCheckKey` and the exec and `Number` bans; `AGENTS.md` (git-ignored) was regenerated with `php artisan boost:update --no-discover --ignore-skills --no-interaction`. (34) The controller's real wizard run on a throwaway Herd site (Task 13): (filled in after execution).
**Evidence:** `tests/Feature/Setup/**` (among them `ToolchainTest`, `DetectorTest`, `BootstrapTest`, `SetupHostTest`, `AnswersTest`, `SetupRunnerTest`, `InstallPreflightTest`, `StrayProcessGuardTest`, `Support/StateFileTest`, `Steps/*`, `Doctor/{Checks,Doctor}Test`, `Wizard/{DetachedStepLauncher,HerdStage,SetupAccess,SetupLayout,WizardPage,WizardRunner,WizardState}Test`), `tests/Feature/Console/{Bootstrap,Install,RunStep,Doctor,CreateSuperAdmin,FluxPro,ProductionGuards}*Test.php`, `tests/Feature/Auth/QuickLoginTest.php`, `tests/Feature/Health/{HealthChecks,HealthReport,HealthCheckEndpoint}Test.php`, `tests/Feature/Backups/*Test.php`, `tests/Feature/Scheduler/ScheduleTest.php`, `tests/Feature/System/SystemPageTest.php`, `tests/Feature/Seeders/DemoSeederTest.php`, `tests/Feature/Authorization/SetSuperAdminTest.php`, `tests/Feature/Tooling/{CiWorkflow,ComposerScripts}Test.php`, `tests/Feature/LangFilesTest.php`, `tests/Arch/{KitRules,NameLeak}Test.php`, `tests/Browser/{SetupWizard,SystemPage}Test.php`, the whole suite on SQLite, on PostgreSQL and with `MODULE_IMPERSONATION=false MODULE_PASSKEYS=false`, the browser suite, and `npm run build:setup && git diff --exit-code resources/setup/setup.css`; vendor reads of Laravel 13.35 (`Process\Factory::fake()`, `PendingProcess::run()` and `start()`, the scheduler's event mutex), Pest 5.3 (`Configuration::__call()`, `UsesCall::beforeEach()`, `TestRepository::use()`, `toThrow()`), pest-plugin-browser 5.1.2 (`AwaitableWebpage::__call()`, `Execution::waitForExpectation()`, `Playwright::usingTimeout()` and `setTimeout()`) and Composer 2.10.2 (`config extra.*` and `--unset` on a scratch copy of `composer.json`); the six verification reports of the triage, the task reports and the progress ledger of the M7b run.
**Consequence:** Deferred to M7c: the §6.6 extras and the security-hardening follow-ups that DEC-32's Consequence lists; L-46 (a `$member->exists` assertion that cannot fail), L-48 (a queue worker keeping the console tag, untested), L-49 (an untyped closure) and L-50 (jobs dispatched during a web request's `Artisan::call()` tagged as console); and `LastBackupCheck`'s message, which still names only a blank password or a zip without AES-256 when the encryption is `none` (carried by DEC-32's Consequence, no triage id). Deferred to M8: g1-24 (the e2e `tests/` diff for the `--phpunit` row), g1-30 (the Herd deep link in the README), g2-01 (CI's global Flux credentials), g3-05 and L-59 (the browser suite's Trace warning), g3-39 (the PostgreSQL browser wizard in CI) and L-06 (a PostgreSQL 18 client in CI), with the CI runs of the e2e and `phase1` jobs, the release check and publication. Won't fix: CD-2. The deferred minors of the M7b reviews: (filled in after execution).
```

- [ ] **Step 9: Run the pinned tests and the docs checks**

Run:

```bash
php artisan test --compact tests/Feature/LangFilesTest.php tests/Feature/LangCoverageTest.php tests/Feature/Tooling tests/Arch
php -r 'json_decode(file_get_contents("lang/nl.json"), flags: JSON_THROW_ON_ERROR); echo "nl.json ok\n";'
grep -n "setup-token\|>Controle\.<\|op branch.*M7a\|m7a-ops" docs/handleiding.html
grep -n "drops out\|\*Systeem\* group\|X-Health-Check-Key: \$HEALTH_CHECK_KEY\" https" README.md
grep -n "composer setup\` runs \`boost:update\`" CHANGELOG.md
grep -n "01:15\|<step>\.pid\|<stap>\.pid" README.md CHANGELOG.md docs/handleiding.html docs/decisions.md
```

Expected:
- The tests PASS and `nl.json ok` prints.
- The four greps print nothing; the handleiding grep may still list `skv-setup-token`, a file path that stays. The last one guards CD-10's hourly clean-up and RL-8's pid file (`<step>.started`).

- [ ] **Step 10: The per-task gate, then commit**

```bash
composer check
composer test:pgsql
php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser
npm run build && composer test:browser
npm run build:setup && git diff --exit-code resources/setup/setup.css
php artisan config:clear
```

Expected: all green. The browser and wizard tests that assert Dutch copy through `__()` follow the new values.

```bash
git add README.md CHANGELOG.md docs .ai lang/nl.json tests/Feature/LangFilesTest.php tests/Feature/Tooling
git commit -m "docs: document the setup hardening, align the dutch glossary and record DEC-33

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

The task report lists:
- every doc line changed to match the code instead of this plan
- the L-54 variant chosen
- any `nl` value from Tasks 1–10 translated in Step 2
- whether `boost:update` touched a tracked file

### Task 13: Milestone gate and the real wizard run on a throwaway Herd site

This task is the controller's. The workflow's final review and gate cover Step 1. Step 2 creates a Herd site and PostgreSQL databases, so the controller runs it after the workflow, one command at a time, and reports each result.

- [ ] **Step 1: Full verification (superpowers:verification-before-completion)**

```bash
composer check
composer test:pgsql
php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser
npm run build && composer test:browser
npm run build:setup && git diff --exit-code resources/setup/setup.css
git status --short
git show HEAD:composer.json | grep -c flux-pro; git show HEAD:composer.lock | grep -c flux-pro
grep -rnE "'\*' *=>|\"\*\" *=>|Process::fake\(\)" tests/ | grep -v KitRulesTest
grep -L "covers §" $(git diff --name-only --diff-filter=A main -- 'tests/**/*.php' | grep -v -e Fixtures -e Support)
```

Expected:
- four green suites
- the stylesheet as committed
- a clean tree
- `0` and `0` for flux-pro
- no output from the catch-all grep (a `'*'` key in either quote style, or a bare fake; named keys such as `"*herd' 'tld'*"` mix the quotes and never match)
- an empty `grep -L`

- [ ] **Step 2: The real wizard run (PostgreSQL + Herd Pro + HTTPS)**

Every command of this step names the throwaway site by its absolute path, never a relative one: the controller's shell sits in `/Users/frankcornet/Herd/skv3`, and shell variables do not survive between Bash calls. Run artisan as `php "$HOME/Herd/m7b-e2e/artisan" …` (artisan takes its base path from its own location, so this targets the site from any directory), and give every file path in full. Never run `artisan`, `awk`, `stat`, `test` or `ls` on a relative path here: in the repository they would run a real `backup:run` on the development database and read the repository's `.env`, which the Global Constraints forbid.

1. **Install.** Run phase 1 only: `scripts/e2e-local.sh "$HOME/Herd/m7b-e2e" --entry=herd --phase1-only`. Every phase-1 check must pass. Then check that the site is not the repository, and stop if it is:

   ```bash
   [ "$(cd "$HOME/Herd/m7b-e2e" && pwd -P)" != "$(git -C /Users/frankcornet/Herd/skv3 rev-parse --show-toplevel)" ] || exit 1
   ```
2. **The gate (RL-4, RL-5, g3-26, g3-27).** Open the URL the script prints with Playwright, as in M6b's Task 16, and complete the wizard with:
   - PostgreSQL
   - an own super admin `owner@m7b-e2e.test`
   - the passkeys, backups and impersonation modules
   - HTTPS
   - Claude Code: off (switch it off; the Claude step would record this throwaway folder in the global `~/.claude/plugins/installed_plugins.json`, g3-04)

   Before you start it, check:
   - `curl -s -o /dev/null -w '%{http_code}' -H 'X-Forwarded-Port: 443' http://m7b-e2e.test/welcome-setup` answers `404`
   - the same request on `http://evil.m7b-e2e.test/…` does not hand out a token, and `curl -s -o /dev/null -w '%{http_code} %{redirect_url}' http://evil.m7b-e2e.test/` redirects to `http://m7b-e2e.test/` with no `token=` in the address (RL-5)
   - after the token is accepted, the session cookie differs from the one sent before
3. **The Herd stage (g3-13, g3-24, CD-14).**
   - The finish page sends `Referrer-Policy: no-referrer`.
   - The site ends secured, and the login hands off to `https://admin.m7b-e2e.test`.
   - `test ! -e "$HOME/Herd/m7b-e2e/storage/app/private/skv-setup.lock"` succeeds: the setup lock is gone.
4. **The doctor and the health key (CD-4, CD-5, F12).**
   - `php "$HOME/Herd/m7b-e2e/artisan" skv:doctor --json` reports `"green": true`.
   - `HEALTH_CHECK_KEY` in the site's `.env` has at least 32 characters. Measure it, never print it: `awk -F= '/^HEALTH_CHECK_KEY=/{print length($2)}' "$HOME/Herd/m7b-e2e/.env"`.
   - `curl -s -o /dev/null -w '%{http_code}' https://m7b-e2e.test/health-check` answers `403` without the key.
   - With the key, read and sent in one shell call, so it never reaches argv or the transcript:

     ```bash
     KEY="$(awk '/^HEALTH_CHECK_KEY=/{sub(/^HEALTH_CHECK_KEY=/, ""); print}' "$HOME/Herd/m7b-e2e/.env")"; printf 'X-Health-Check-Key: %s\n' "$KEY" | curl -s -o /dev/null -w '%{http_code}\n' -H @- https://m7b-e2e.test/health-check
     ```

     It answers `503` here, with only `last-backup` failing: the backups module is on and no backup exists yet. Item 5 repeats it after the first backup.
5. **Backups (CD-7, CD-10, L-05).**
   - `BACKUP_ARCHIVE_PASSWORD= php "$HOME/Herd/m7b-e2e/artisan" backup:run --only-db` exits 1 with one error line and no stack trace.
   - `php "$HOME/Herd/m7b-e2e/artisan" backup:run --only-db` succeeds, `stat -f '%Lp' "$HOME/Herd/m7b-e2e/storage/app/backup-temp"` prints `700`, and the zip entry of the newest archive under `"$HOME/Herd/m7b-e2e/storage/app/backups/"` is AES-encrypted (method 99).
   - After 30 seconds (the report's cache), item 4's keyed `curl` call answers `200`.
   - `php "$HOME/Herd/m7b-e2e/artisan" schedule:list` lists nine events, including `backup-temp-cleanup` every hour at :15 (`15 * * * *`).
6. **The System page (CD-8, CD-12, CD-16).**
   - Sign in on `https://admin.m7b-e2e.test` as `owner@m7b-e2e.test`.
   - *Systeemstatus* renders the health, the backup (size formatted without intl), the schedule and the heartbeat.
   - The section heading reads *Diagnose*.
   - *Controles draaien* includes the `apex` row.
   - No JavaScript errors.
7. **Quick login (g3-15).** Install the demo data in a second throwaway run only if Step 2.2 used an own super admin and the quick login was not exercised: `scripts/e2e-local.sh "$HOME/Herd/m7b-e2e-demo" --entry=herd --phase1-only`, then the wizard with the demo start, PostgreSQL and Claude Code off. Check there that the login page lists the demo accounts, and that the same request with `X-Forwarded-For: 1.2.3.4` shows none. Otherwise check on the first site, with `X-Forwarded-For: 1.2.3.4`, that the login page shows no quick-login accounts.
8. **Record** every output in the ledger, never a key, token or password.

Clean up:
0. Only if the Claude step ran anyway: `(cd "$HOME/Herd/m7b-e2e" && claude plugin uninstall superpowers@superpowers-marketplace --scope project)`, before the folder is removed.
1. `herd unsecure m7b-e2e`
2. `herd unisolate --site=m7b-e2e`, only if `herd isolated` lists it
3. `rm -rf "$HOME/Herd/m7b-e2e"` and the script's temp directory
4. Drop the databases with a PHP PDO script on Herd's PostgreSQL (`root`, empty password), written to the session scratchpad, never in the repository. It first runs `SELECT datname FROM pg_database WHERE datname IN ('m7b_e2e', 'm7b_e2e_test') ORDER BY datname` and stops unless both rows come back. It then runs `DROP DATABASE "m7b_e2e_test"` and `DROP DATABASE "m7b_e2e"` as literal statements: never a `LIKE` pattern, never a name read from a query result.
5. Delete the answers file.
6. If Step 2.7 created `m7b-e2e-demo`, repeat steps 0–5 for it: `herd unsecure m7b-e2e-demo`, `herd unisolate --site=m7b-e2e-demo` only if `herd isolated` lists it, `rm -rf "$HOME/Herd/m7b-e2e-demo"`, and drop `m7b_e2e_demo_test` and `m7b_e2e_demo` with the same literal-name script.

- [ ] **Step 3: Final review and hand-off**

The workflow's final review covers `main..m7b-setup-hardening`. Fix what Step 2 finds, test-first, then re-run Step 1. Fill in DEC-33's execution items: the deviations from the task reports and this run.

Stop before merging: the owner merges. Then update the memory notes:
- M7b done, and M7c's plan next
- the deferred minors
