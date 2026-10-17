# M7c Security Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the security, robustness and localisation follow-ups that M3–M7b left for M7c. These cover:
- the limiter family and the links the kit builds from a request
- the settings pages, redirect targets and impersonation side effects
- the locale of flashed text, mails and error pages
- one lock order for the administrative actions, and the audit trail
- branding, the Flux Pro stripper and its lint
- the setup gate, setup files, ops, backups and CLI leftovers of M7b

**Architecture:** M7c adds no feature. Each task hardens one area and pins the hardening with a test that fails before the change. A few small shared pieces are introduced and reused:
- `App\Support\RateLimits\UserActionLimit`, one per-user limiter for Livewire actions (M7d's bulk invitations reuse it)
- `App\Http\Support\ReturnUrl`, one same-host rule for "go back" targets
- `Hosts::origin()` taking scheme and port from `APP_URL`
- one documented lock order for every transaction that writes organisations, roles, users and invitations
- one Blade directive tokenizer shared by `FluxProBranches` and the Pro lint

Documentation and DEC-34 come last. The controller then makes one real wizard run on a throwaway Herd site and exercises the new limits and error pages by hand.

**Tech Stack:** PHP 8.4, Laravel 13.35, Livewire 4.4 (multi-file components), Flux 2.20 (free), Fortify, laravel/passkeys, spatie/laravel-permission, spatie/laravel-activitylog v5, spatie/laravel-backup 10.3, Pest 5.3 with pest-plugin-browser 5.1, Larastan 3 level 8, PostgreSQL 18 (Herd), SQLite. **No new dependency.**

**Spec:** `docs/superpowers/specs/2026-10-10-skv3-design.md`. Read these before starting:
- §4.2 (authentication), §4.4–§4.8 (users, invitations, roles, impersonation, audit)
- §4.11 (localisation), §6.2 (tenancy core, route × host matrix), §6.3 (authorization), §6.5 (UI), §6.7 (Flux Pro layer)
- §5.3–§5.4 (setup, the wizard, the doctor) and §4.9 (ops) for Tasks 10–13
- Appendix A (the skv2 findings; their regression tests must stay green)

Decisions live in `docs/decisions.md` (DEC-27 auth, DEC-28 administration, DEC-29 UI, DEC-32 ops, DEC-33 setup hardening).

## Global Constraints

- **Versions:**
  - PHP floor `^8.4`; `laravel/framework ^13.35`; Pest `^5.3`.
  - Larastan level **8**, **no baseline**, no new `ignoreErrors` entries. This includes the multi-file component `.php` files under `resources/views/**`.
- **PHP files:** every one starts with `declare(strict_types=1);`. Pint uses the `laravel` preset plus `declare_strict_types`, `strict_comparison` and `ordered_class_elements`. Run `composer fix` after editing PHP.
- **Dependencies:**
  - M7c adds **none**, PHP or npm, and no `ext-*` requirement (CD-9).
  - `composer.json` changes only in Task 11's two `scripts` one-liners; `composer.lock`, `package.json` and `package-lock.json` do not change.
  - `livewire/flux-pro` and `composer.fluxui.dev` never appear in the committed `composer.json` or `composer.lock`.
- **Never touch the developer's own environment from code or tests:**
  - No test reads or writes the repository's `.env`, `.env.testing`, `herd.yml`, `.mcp.json`, `storage/app/skv-installed`, `storage/app/private/skv-*`, `storage/app/backups`, `storage/app/backup-temp` or `public/storage`.
  - No test opens a connection to the developer's PostgreSQL unless it is an explicit PostgreSQL test that skips when the server is unreachable; wizard tests get `withoutPostgresProbe()` through `useSetupWizard()`.
  - Implementers never run `skv:install`, `skv:bootstrap`, `skv:run-step`, `skv:flux-pro`, `backup:run`, `backup:clean` or `storage:link` in the repository itself.
  - Never run a mutating Herd command; read-only ones (`herd tld`, `herd secured`, `herd isolated`, `--help`) are fine.
- **External commands:**
  - Every external command the kit starts runs through `Process` with `Toolchain::environment()` (DEC-23), and `herd` by its absolute path.
  - Never `exec()`, `shell_exec()`, `system()`, `passthru()`, `proc_open()`, `popen()`, `pcntl_exec()` or backticks (`tests/Arch/KitRulesTest.php`).
  - Tests fake commands with named patterns on Symfony's single-quoted command line (`"*'git' 'init'*"`); never a catch-all. Tests under `Feature/Setup`, `Feature/Console`, `Feature/Backups`, `Feature/Health` and `Feature/System` already get `Process::preventStrayProcesses()` + `Process::fake([])` from `tests/Pest.php`.
- **Never print or log secrets:** passwords and their hashes, `APP_KEY`, `HEALTH_CHECK_KEY`, `BACKUP_ARCHIVE_PASSWORD`, the Postgres password, the setup token, the Herd-stage secret, reset, invitation, email-change and handoff tokens, Composer credentials. Audit entries never hold a password, a token or an accept URL. Secrets never travel in argv.
- **Never run against the development database:** no `migrate:fresh`, `db:wipe` or `db:seed`. `tinker`, `migrate` and `db:seed` prompt for approval.
- **Tenancy and authorization (`AGENTS.md` › Tenancy and authorization):**
  - `Tenant::bypass()` and scope removal only in `App\Console`, `App\Setup`, `App\Tenancy`, `App\Actions\Audit`, `Database\Seeders` and `app/Http/Controllers/HealthCheckController.php`. Livewire pages never bypass.
  - Use the `App\Authorization\Permission` enum, never a permission string; switch teams only with `withPermissionsTeam()`.
  - Administrative actions take the actor first and authorise with `Gate::forUser($actor)`. From Task 7 on, every transaction follows the lock order of CD-5 (it replaces the old one-line rule "An action that takes an `Organisation` calls `HostScope::lockForWrite($organisation)` inside its transaction before `Tenant::run()`", which stays true as the first step of that order).
  - Livewire components authorise in `mount()` and again before calling an action, keep `#[Locked]` ids, re-resolve the model on every request.
  - Links that leave the request are built for `Hosts::homeOf($user)`; signed links use `URL::temporarySignedRoute(..., absolute: false)` with `signed:relative`.
- **Routes:**
  - Group single-host routes with `Route::tenant()` / `Route::central()`; never `Route::domain()` (the Fortify groups in `routes/fortify.php` and `routes/passkeys.php` are the named exceptions, Task 14).
  - Every new named GET route goes into `matrixRoutes()` in `tests/Feature/Tenancy/RouteHostMatrixTest.php` or its exclusions. M7c adds no named GET route.
- **Audit:** entries are written only through `App\Actions\Audit\RecordActivity` with an `App\Enums\ActivityEvent` case; never `activity()`, the facade or `LogsActivity`.
- **Mail:** mails implement `ShouldQueue` and call `afterCommit()`; tests run them on the sync queue with the `array` mailer, not `Mail::fake()`.
- **Setup and ops rules (`AGENTS.md` › Setup, › Ops):** steps resolve files through `SetupContext::path()`; setup state files only through `App\Setup\Support\StateFile` and the classes' constants; wizard tests start with `useSetupWizard()` and go over HTTP (`pageSnapshot()` + `callPageAction()`), forged updates send `X-CSRF-TOKEN`; web code uses `DoctorContext::withoutDetection()`; ops caches use `OpsCache::store()`; backup tests use `Storage::fake('backups')` and a sandbox `backup.backup.temporary_directory`; command-listener tests use `WithConsoleEvents`. After changing `resources/views/{layouts/setup.blade.php,pages/setup,setup}` or `resources/css/{theme,setup}.css`, run `npm run build:setup` and commit `resources/setup/setup.css`.
- **Names:** no hard-coded kit or site name (`skv`) in app-identity values (`tests/Arch/NameLeakTest.php`). Tests set every `skv.*` key in its own `config(['skv.…' => …]);` call.
- **Copy and lang keys:**
  - Code, comments and docs are in English. UI copy goes through `__()`, `nl` by default, with `en`.
  - Every key goes into **both** `lang/nl.json` and `lang/en.json`: identical keys, the same order (PHP `sort()` order, byte-wise), Dutch informal "je".
  - Glossary: Setup = Installatie, token = token (het), System = Systeem, Health = Gezondheid, Backup = Back-up, Check = Controle, Doctor = Diagnose.
- **Livewire:** multi-file components only, no ⚡, never `$this->view()`. `password.confirm` does not cover Livewire updates: actions that change or reveal a second factor start with `if ($this->passwordConfirmationRequired($returnUrl)) { return; }`.
- **Flux Pro:** Pro-only components only inside `@if (Skv::hasFluxPro())` with a complete free `@else` (`tests/Arch/FluxProLayerTest.php`).
- **Tests:**
  - Pest functions (`it(...)`) with typed closures. Each new file starts with a `/** covers §x.y */` docblock as its first statement.
  - `Event::fake()` is never called bare: `Event::fake()->except([TenantResolved::class, TenantForgotten::class])`.
  - `Livewire::test()` skips all middleware: test host rules, limits and redirects over HTTP.
  - Tests that depend on a module call `useImpersonationModule()`, `usePasskeysModule()` or `useBackupsModule()` first.
  - **No `*Test.php` file declares a class (anonymous ones included) or spells a qualified class name outside its imports** (`tests/Arch/TestFileClassesTest.php`). Fixtures go in `tests/Fixtures`.
  - Tests are rewritten, never deleted (deleting a test needs the owner's approval). A test whose title promises more than it checks is strengthened or retitled.
  - PostgreSQL-only tests (lock order) skip on SQLite with `->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'needs PostgreSQL row locks')`.
- **Global test helpers already taken (never redeclare):** `acceptLinkFor`, `asMember`, `asOrgAdmin`, `asSuperAdmin`, `browserUser`, `callPageAction`, `confirmationLinkFor`, `dutchTranslationKeys`, `filesMatching`, `inviteMember`, `kitSourceFiles`, `onAdminHost`, `onApex`, `onTenant`, `pageSnapshot`, `recordedActivity`, `sameOrigin`, `setupSandbox`, `startImpersonatingOverHttp`, `useBackupsModule`, `useImpersonationModule`, `usePasskeysModule`, `useSetupWizard`, `withoutPostgresProbe`.
- **Global test helpers reserved for M7c:** `lockStatements` (Task 7, `tests/Pest.php`). Nothing else; file-local helpers are closures.
- **Gate per task:**
  - `composer check` (SQLite) green
  - `composer test:pgsql` green
  - the modules-off run: `php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser` green
  - `npm run build && composer test:browser` green
  - `npm run build:setup && git diff --exit-code resources/setup/setup.css` (only Tasks 10 and 11 may change setup views; then commit the rebuilt stylesheet)
  - then `php artisan config:clear`
- **Commits:** Conventional Commits, lower case, imperative, ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Stage files by name. The pre-commit hook (Pint + Larastan on staged files) runs; never bypass it.
- **Never** run `herd sites` or the Herd MCP `get_all_sites`, and never read `.env` files (`.env.example` is fine).

## Conventions used in this plan

- All M7c work happens on branch `m7c-security-hardening`, created from `main` (merge `a192dba`, "Merge M7b: setup hardening"). The plan file, `docs/superpowers/plans/2026-10-16-m7c-security-hardening.md`, is its first commit.
- Bracketed ids are traceability tags:
  - `CD-*`: the controller's decisions, taken under the owner's delegation
  - follow-up ids (CD-2): `G1-NN` … `G6-NN` (the M3–M7b memory lists and DEC-32/DEC-33 Consequences), `L7b-NN` (the M7b build ledger). The triage table below lists every id with its destination.
  - `DEC-*`: decision-log entries
- **HTTP tests for host rules** use `onTenant()`, `onApex()`, `onAdminHost()` and the `as*()` helpers from `tests/Pest.php`.
- **Livewire-over-HTTP tests** use `pageSnapshot()` and `callPageAction()`, as `tests/Feature/Settings/SecurityActionsTest.php` and `tests/Feature/Setup/Wizard/WizardPageTest.php` do, because `Livewire::test()` skips middleware and the persistent `Authorize` middleware.
- **Errors on keys that are not component properties** (`invitation`, `organisation`) are not kept in the snapshot's `memo.errors`; tests read them from the rendered `effects.html`.
- **Mutation check:** where a step says "verify by mutation", the implementer reverts the production change locally, confirms the new test fails, restores the change and confirms it passes. Copy the file aside with `cp` and copy it back; **never `git stash`** (the stash is shared by every worktree). The commit contains the fixed code only. The task report names each mutation and its result.

## Scope

1. **The limiter family:** per-user limits for the mail-sending and secret-checking Livewire actions, string-only limiter keys, a successful sign-in clearing its failure counter, a passkey failure bucket the client cannot choose.
2. **Links from `APP_URL`:** `Hosts::origin()` and the passkey origin take scheme and port from `APP_URL`, never from the request.
3. **Settings pages and passkey failures:** the module-off passkey actions, the recovery-code download after a key rotation, cloned-authenticator and failed passkey sign-ins that leave a trace.
4. **Language and appearance switches:** a same-host return target, no preference written to an impersonated account, the query string kept.
5. **Locale of flashed text and mails:** flashes in the page's language, queued notifications in the recipient's language, the profile toast that survives the reload.
6. **Error pages:** 4xx/5xx fallbacks and a "Try again" / "Back" that never points at a POST-only or foreign URL.
7. **One lock order:** every administrative transaction locks organisation → roles → users → the rest, which removes four deadlocks and one race; RK-B3 decided on the stored row; `saveAccount()` in one transaction.
8. **Audit trail and queued mails:** password-reset entries for promoted super admins, a bounded user agent, invitation mails that skip a gone invitation, the console tag only for real commands.
9. **Branding, dashboard and the Pro grammar:** the tenant's brand on auth pages, archived organisations' rows on the central dashboard, one tokenizer for the Pro stripper and lint.
10. **The setup gate and the Herd stage:** a planted session dropped on token acceptance, more proxy headers refused, state values validated, `/` resolving once, the second-factor login on the home host, the isolate command in the manual Herd step.
11. **Setup files and steps:** the step script's umask only on its own files, `public/storage` created, unreadable files refused, `.env` written 0600.
12. **Ops and backups:** the health-check key bucket and its docs, a clean log line on a broken ops store, `backup:run --config` passing its own `sslmode`, the account-trust rule without ext-posix and under root, private archives, `LastBackupCheck`'s message.
13. **CLI and super-admin guards:** hash-shaped passwords refused, one arch rule for `SetSuperAdmin` callers, the strict password rule on staging, `skv:flux-pro`'s cleanup.
14. **Documentation, guideline and DEC-34.**
15. **The controller's real verification:** a wizard run on a throwaway Herd site plus the new limits and error pages by hand, then cleanup.

Not in scope:
- **M7d** (CD-1): the §6.6 extras — sessions, second factor per organisation, the audit CSV export, bulk invitations, avatars and their Pro upload variants.
- **M8:** the weak-test and test-hygiene items in the triage table, the release check, CI runs of the e2e and `phase1` jobs, publication.
- **Won't fix:** the items marked so in the triage table, with their reasons.

## Controller decisions (owner delegation, 2026-10-15, reaffirmed 2026-10-16)

- **CD-1, the split:** DEC-32's M7c held both the §6.6 extras and the security-hardening follow-ups. M7c is split into **M7c** (this plan, hardening) and **M7d** (the §6.6 extras), as M6 and M7 were split, so each plan stays reviewable. Hardening goes first because the extras build on it: bulk invitations reuse `UserActionLimit` (Task 1) and localised queued mail (Task 5); the second factor per organisation lands on the hardened security page (Task 3).
- **CD-2, a fresh triage:** the sources (the M2–M7b memory follow-up lists, the M5 ledger's final review, DEC-32/DEC-33 Consequences, the M7b ledger's 84 deferred minors) were verified by six read-only agents against `main` `a192dba`: 149 entries: 44 were already fixed (L7b-43's binding and L7b-64's known case included), 1 obsolete, 8 duplicates; **62 are handled here** (L7b-73's failing-step tests; its other tests go to M8), 26 go to M8 only and 8 are won't-fix. The plan review added one M8 item of its own (K9). The ids in the triage table are this plan's numbering; DEC-34 records the mapping.
- **CD-3, the limits** (`UserActionLimit`, per user, on Laravel's rate-limiter cache — the default store, as for Fortify's limiters):

  | Action | Key | Limit |
  |---|---|---|
  | Request an email change (profile) | `email-change:<user id>` | 3 per 10 minutes |
  | Change the password (security page) | `password-update:<user id>` | 5 per minute, every attempt |
  | Confirm a TOTP code (security page) | `two-factor-confirm:<user id>` | 5 per minute, every attempt |
  | Invite a user (single invite) | `invite:<user id>` | 10 per minute |
  | Send a reminder (pending list, accept page) | `remind:<user id>` | 10 per minute, and per invitation at most one reminder per 10 minutes (`RemindInvitation` checks `reminder_sent_at`) |

  Fortify's 2FA management routes (`two-factor.enable`, `.confirm`, `.disable`, `.regenerate-recovery-codes`) and `passkey.destroy` get a route limiter `account-security`, 10 per minute per user. A refused action throws a `ValidationException` on the form's field with the new key "Too many attempts. Try again in :seconds seconds." (sign-in keeps `auth.throttle`, whose copy names sign-in attempts). Password and TOTP checks count every attempt; email change, invite and remind reserve a slot atomically before the send and give it back when the action throws. The TOTP confirm lives in `pages/settings/two-factor-setup-modal/`. Task 1 also replaces `FortifyServiceProvider::loginThrottled()`'s `back()` with `to_route('login')` (CD-13). Limits live in the components and routes, never in the actions, so M7d's bulk invitation can call `InviteUser` in a loop under its own limit.
- **CD-4, links from `APP_URL`:** `Hosts::origin(string $host)` keeps the host it is given (validated by tenancy) and takes the scheme and the port from `config('app.url')`; a request's port, `X-Forwarded-Proto` and `X-Forwarded-Port` no longer reach any link. This amends spec §6.2 ("Tenant URL helper keeping scheme and port") and DEC-26's test `TenantUrlTest`: the helper keeps `APP_URL`'s scheme and port.
- **CD-5, one lock order:** inside one transaction, rows are locked in this order and each class at most once: **(1)** the organisation the write concerns (`HostScope::lockForWrite()`; `HostScope::lockOrganisation(?int)`, new in Task 7, which refuses nothing, for user writes, which then check the archived state on the locked row; `Organisation::withTrashed()->lockForUpdate()` for archive, restore and purge; user actions and `AcceptInvitation` lock the target's organisation, `UpdateUser` takes none because it writes only the target row and its reset tokens); **(2)** the roles it changes or assigns, in id order; **(3)** one batch of `users` rows: one `SELECT … FOR UPDATE` on `users` only (no join), in id order. User administration (status, delete, roles, super admin) takes it through `LockoutGuard::lockUsers()` / `lockForRemoval()` and decides on the locked rows (FU2-5); `AcceptInvitation` and `CancelInvitation` lock the invitee's row alone; `ArchiveOrganisation` and `PurgeOrganisation` lock the organisation's users in one statement; **(4)** `model_has_roles`, then invitations, then sessions and password-reset tokens; **(5)** activity inserts last. No retry loop: a deadlock is a bug. The order goes into the guideline (Task 14) and is pinned by PostgreSQL-only tests that read the `for update` statements and the writes (UPDATE, DELETE, INSERT) through `DB::listen` (`lockStatements()`). A nested action may re-lock the organisation its caller already holds; that is harmless and the tests fold the repeat.
- **CD-6, passkey failures leave a trace:** `CounterException` (a signature counter that did not increase: a cloned authenticator) is reported again; other `WebauthnException`s stay unreported. A failed passkey sign-in is recorded like a failed password sign-in (Task 3 reads the existing `ActivityEvent` for failed sign-ins and reuses it with `properties.method = 'passkey'`), with the user only when the credential resolves to one and, on a tenant host, the user is a member of that organisation (R1's rule: no other organisation's member and no super admin in a host's log); a 429 records nothing. The recorder is the auto-discovered `RequestHandled` listener `App\Listeners\RecordFailedPasskeySignIn`.
- **CD-7, the second-factor login after setup on the home host:** the Herd stage sends a super admin with a confirmed second factor to `Hosts::url(Hosts::homeOf($user), '/login')`, the admin host, instead of the apex. Supersedes DEC-33 (14)'s apex target.
- **CD-8, private install files:** `skv:bootstrap` writes `.env` with mode 0600, Composer's `.env` one-liner (`post-root-package-install`, `composer setup`, which create `.env` before the bootstrap on every install route) chmods its copy 0600, and `DatabaseStep` writes `.env.testing` with 0600, whatever `.env.example`'s mode (supersedes DEC-33 RL-1's "the example's mode" for these two files); a read failure of `.env.example` or `.env` stops the step with "Could not read :file." (`EnvFile::contents()`, Task 11) instead of throwing PHP's warning with an absolute path. A php-fpm that runs as another account than the one that ran the bootstrap then cannot read `.env`; the production checklist says to `chown` it or use 0640 with a shared group (Task 14).
- **CD-9, no ext-posix requirement:** the "this account" checks (`PrivateBackupTemp`, `PostgresBinaries`) need `posix_geteuid()`/`posix_getpwuid()`. They read the uid through `App\Support\ProcessAccount` (Task 12), null without ext-posix. Without ext-posix `PrivateBackupTemp` treats every directory as foreign (fail closed, so `backup:run` refuses), `PostgresBinaries` trusts only root-owned Herd paths, and the doctor's `pg-dump` row fails and says why while the backups module is on. Under euid 0 a temporary directory counts as ours only inside `storage_path()`.
- **CD-10, the strict password rule outside local and testing:** the existing `Password::defaults()` rule (12 characters, letters, mixed case, numbers, symbols, uncompromised) applies in every environment except `local` and `testing`, so staging gets the production rule; local and testing keep min 8. `uncompromised()` calls api.pwnedpasswords.com, so a test that validates a staging password fakes HTTP (`Http::preventStrayRequests()` + `Http::fake()`).
- **CD-11, impersonations that end without `impersonation.stop`:** documentation only (Task 14). When the impersonator is disabled, deleted, archived or purged, their session row is deleted and no request can write the stop entry; the start entry and the status change are the record.
- **CD-12, `public/storage`:** setup creates the storage link in a step that always runs (Task 11 places it in `EnvironmentStep`, through `Toolchain` and `Process` like the other artisan children), because `FrontendStep` is skipped when a build exists.
- **CD-13, vendor `back()` stays:** Laravel's validation redirect, Fortify's and laravel/passkeys' response classes use `back()` on CSRF-protected POSTs; a third party cannot drive them. The kit's own three call sites change: `LocaleController` (Task 4) and the 403 page's Back link (Task 6) use `ReturnUrl`, and `loginThrottled()` redirects to the login route (Task 1). The vendor paths are won't fix.
- **Plan review (2026-10-16, wf_1a7b1baa-b8f):** five dimensions with a skeptic each; 21 findings, 19 confirmed, 18 folded into Tasks 1, 3, 5, 6, 7, 8, 11, 12, 13, 14, 15 and the global sections; K9 (HerdSiteStep::isComplete() ignoring the PHP version) went to M8.

## Requirement traceability

| Task | Ids |
|---|---|
| 1 | G1-01, G1-02, G1-03, G1-05; CD-3 |
| 2 | G1-04, G1-06, G2-08; CD-4 |
| 3 | G2-01, G2-03, G2-07; CD-6 |
| 4 | G2-02 (locale), G2-05, G3-07; CD-13 |
| 5 | G3-01, G3-02, G3-03, G3-06 |
| 6 | G3-04, G3-05, G2-02 (403 page) |
| 7 | G4-06, G4-07, G4-08, G2-06 (= G4-10's residual), D-cycles 3–4, D-race; CD-5 |
| 8 | G4-01, G4-03, G4-05, G6-04, G6-02 |
| 9 | G5-01, G5-03, G5-09, G5-10 (the users.index `organisation` error-key clash of the M5 review was verified and is not real) |
| 10 | L7b-11 (= L7b-13), L7b-14, L7b-30, L7b-35, G6-07, G6-08 (CD-7), L7b-16, L7b-18 |
| 11 | G6-06, G6-09 (CD-12), L7b-03, L7b-05 (CD-8) |
| 12 | G6-05, G6-10, L7b-56, L7b-57, L7b-58, L7b-68, L7b-51, L7b-69 (CD-9) |
| 13 | L7b-38, L7b-39, L7b-45 (CD-10), L7b-72, L7b-81, L7b-73 |
| 14 | G5-05, G5-07, G5-12, G4-02 (CD-11), every task's docs, DEC-34 |
| 15 | the controller's verification |

## Follow-up triage (CD-2)

The full rows (evidence `file:line` on `a192dba`, smallest fix, the test that fails first) are in the triage files the controller keeps; each task below repeats what its implementer needs.

**Already fixed (44):** G1-07, G1-08, G1-09, G1-10, G1-11 (661e707); G2-04 (79d797a); G2-09 (add3e07); G2-10, G2-11, G4-09, G4-10, G4-11, G4-12 (ba2665d); G4-13 (8237faa); G5-02 (449a636); G5-06 (f035ae2); G5-11 (8cbc9a6); L7b-02, -04, -06, -07, -09, -17, -23, -24, -25, -27, -29, -32, -34, -37, -40, -41, -42, -43 (the binding), -46, -47, -48, -53, -54, -64 (the known case), -66, -83, -84 (M7b's fix waves). G4-10's residual is G2-06.
**Obsolete (1):** L7b-82 (DEC-33 (32) is complete). **Duplicates (8):** L7b-01 = G6-06, L7b-08 = G6-07, L7b-13 = L7b-11, L7b-63 = L7b-61, L7b-65 = L7b-68, L7b-76 = L7b-77, L7b-79 = M7b's M1 (M8), L7b-80 = L7b-71.

**M8 (26 triaged ids plus the plan review's K9, and the M8 halves of L7b-43 and L7b-73):** G4-04 (the viewer's DISTINCT queries), G5-04 (`Role::color()`), G5-08 (`LoginTrend` and mutable Carbon), G6-01 and G6-03 (`CreateOrganisationCommandTest` polish), L7b-10, -12, -15, -19, -20, -21, -22, -26, -28, -31, -33, -43 (a pty test), -49, -50, -55 (the `default =>` dataset arms in three files), -60, -67, -70, -71, -73's remaining tests, -74, -77, -78; HerdSiteStep::isComplete() ignoring the PHP version (plan review K9: a served site without HTTPS records "Already done" and skips run()'s PHP 8.4 isolation, while confirm() and manualCommand() require 8.4; the fix adds `&& $this->runsSupportedPhp(...)` last and the `herd isolated` / `php -r` fakes in four test files); plus G3-08's optional lang-key guard, the weak-test lists of the M4 and M5 memory notes and M7b's M1 (a kit-repository Flux Pro guard).

**Won't fix (8):** G3-08 (Flux/Blaze compile-time copy, upstream; DEC-29), L7b-36 (RL-16's exact `APP_URL` comparison), L7b-44 (`--answers=-` is for pipes), L7b-52 (macOS ACLs), L7b-59, -61, -62 (the System page limiter's fail-open and non-atomic count, by design), L7b-75 (`$wizardCommands`). Also not changed, as rulings: G4-02's behaviour (CD-11), the vendor `back()` paths (CD-13), L7b-64's general vendor `exec()` guard and L7b-65's clean-up half.

## Cross-task notes from planning

1. **File ownership.** Tasks run in order, and each task's code is written against `main` `a192dba` plus the earlier tasks. Where two areas meet, one task owns the file:
   - `app/Providers/FortifyServiceProvider.php`: Task 1 (limiters, `loginThrottled()`'s `back()` too). Task 3 only adds the passkey-failure listener registration if one is needed in a provider; it uses `AppServiceProvider` or listener discovery instead.
   - `resources/views/components/user-menu/*`, `app/Http/Controllers/LocaleController.php`: Task 4.
   - `resources/views/errors/*`: Task 6.
   - `resources/views/pages/settings/security/*`: Task 1 adds the password limit; Task 3 adds the module guards and is written against Task 1's version.
   - `resources/views/pages/settings/profile/*`: Task 1 (email-change limit), then Task 5 (toast).
   - `resources/views/pages/invitations/pending/*`: Task 1 (remind limit), then Task 2 (copy link, comment).
   - `app/Actions/Invitations/RemindInvitation.php`: Task 1 (cooldown). `app/Actions/Invitations/AcceptInvitation.php`: Task 7. `app/Mail/Invitation*.php`: Task 8.
   - `app/Setup/Wizard/HerdStage.php`, `app/Setup/SetupMode.php`: Task 10. `app/Setup/Wizard/DetachedStepLauncher.php`, `app/Setup/Steps/EnvironmentStep.php`, `app/Setup/Support/Bootstrap.php`: Task 11.
   - `routes/fortify.php`, `routes/passkeys.php`, `resources/views/pages/invitations/accept/*`, `resources/views/pages/settings/two-factor-setup-modal/*`, `tests/Arch/PresetsTest.php`: Task 1.
   - `bootstrap/app.php`: Task 3 (`withExceptions` report rules); Task 6 builds on it if it touches the same block.
   - `resources/views/layouts/base.blade.php`: Task 4 (the `$appearance` line); Task 9 leaves `<title>` as it is (DEC-29 (5)).
   - `app/Support/Locales.php`, `app/Tenancy/Auth/Impersonation.php`, `app/Actions/Settings/{UpdateLocale,UpdateAppearance}.php`: Task 4; Task 5 adds `Locales::forNextPage()` after `forErrorPage()`.
   - `app/Support/EnvFile.php`, `tests/Pest.php`'s `setupSandbox()`: Task 11. `tests/Pest.php`'s `lockStatements()`: Task 7.
   - `lang/nl.json`, `lang/en.json`, `CHANGELOG.md`: every task adds its own keys and lines; `README.md`, `docs/handleiding.html`, `.ai/guidelines/skv3.md` and `docs/decisions.md`: Task 14 only (tasks list what Task 14 must say in their report), except the one `.env` command line in `docs/handleiding.html`, which Task 11 changes with `composer.json`'s `scripts` and `scripts/e2e-local.sh` because it quotes them.
2. **Spec edits** that a behaviour change needs are made in the same task as the behaviour (Task 2: §4.2 and §6.2; Task 10: §5.3.4's access gate; Task 11: §5.3.3 steps 1–2), so tests and spec never disagree on a commit.
3. **No new named GET route** in any task.

---

### Task 1: The limiter family (G1-01, G1-02, G1-03, G1-05; CD-3, CD-13)

**Files:**
- Create:
  - `app/Support/RateLimits/UserActionLimit.php` (the per-user Livewire action limit, CD-3; M7d's bulk invitations reuse it)
  - `app/Support/RateLimits/ThrottleKey.php` (rebuilds `ThrottleRequests`' cache key of a named limiter, G1-03)
  - `app/Http/Controllers/Auth/ResetPasswordController.php` (Fortify's reset with a string check first, G1-02)
  - `tests/Feature/Support/UserActionLimitTest.php`
  - `tests/Feature/Auth/ActionLimitsTest.php`
- Modify:
  - `app/Providers/FortifyServiceProvider.php` (string-only limiter keys; the passkeys bucket on user or IP; the `account-security` limiter; a sign-in clears the address+IP failure bucket; `loginThrottled()` goes to the login route instead of `back()`)
  - `routes/fortify.php` (the four two-factor management routes again, with `throttle:account-security`; `password.update` on the kit controller)
  - `routes/passkeys.php` (`passkey.destroy` again, with `throttle:account-security`)
  - `resources/views/pages/settings/profile/profile.php` (email-change limit)
  - `resources/views/pages/settings/security/security.php` (password-update limit)
  - `resources/views/pages/settings/two-factor-setup-modal/two-factor-setup-modal.php` (two-factor-confirm limit)
  - `resources/views/pages/users/invite/invite.php` (invite limit)
  - `resources/views/pages/invitations/pending/pending.php` (remind limit)
  - `resources/views/pages/invitations/accept/accept.php`, `accept.blade.php` (remind limit; the expired state shows the `invitation` error)
  - `app/Actions/Invitations/RemindInvitation.php` (the 10-minute cooldown per invitation)
  - `tests/Arch/PresetsTest.php` (the security preset ignores `ThrottleKey`)
  - `lang/nl.json`, `lang/en.json`, `CHANGELOG.md`
- Test:
  - `tests/Feature/Support/UserActionLimitTest.php`, `tests/Feature/Auth/ActionLimitsTest.php` (new)
  - `tests/Feature/Auth/LoginThrottleTest.php`, `tests/Feature/Auth/FortifyRoutesTest.php`, `tests/Feature/Invitations/RemindInvitationTest.php`

**Interfaces:**
- Consumes:
  - `RateLimiter::tooManyAttempts()`, `::increment(string $key, int $decaySeconds, int $amount): int` (atomic; returns the new count), `::decrement(string $key, int $decaySeconds, int $amount): int`, `::attempts()`, `::availableIn()`, `::clear()` (Laravel 13; the default cache store)
  - `pageSnapshot(string $url, string $component)`, `callPageAction(array $snapshot, string $method, array $params = [], array $updates = [], ?string $host = null)`, `inviteMember()`, `acceptLinkFor()`, `usePasskeysModule()`, `asOrgAdmin()`, `asSuperAdmin()`, `onTenant()` (`tests/Pest.php`)
  - `App\Concerns\ResolvesCurrentUser::currentUser(): User`
- Produces:
  - `final readonly class App\Support\RateLimits\UserActionLimit`:
    - `__construct(public string $name, public int $maxAttempts, public int $decaySeconds)` (throws `InvalidArgumentException` for `$maxAttempts < 1` or `$decaySeconds < 1`)
    - named limits `emailChange()` (3 / 600 s), `passwordUpdate()` (5 / 60 s), `twoFactorConfirm()` (5 / 60 s), `invite()` (10 / 60 s), `remind()` (10 / 60 s); M7d adds its own named constructor or calls `new UserActionLimit('bulk-invite', …)`
    - `key(User $user): string` = `"{name}:{user id}"`
    - `attempt(User $user, string $field, int $cost = 1): void` — counts `$cost` attempts first (`RateLimiter::increment()` returns the new count atomically) and throws `ValidationException` on `$field` with "Too many attempts. Try again in :seconds seconds." when that count passes the limit, so parallel requests cannot all read the old count (DEC-27 (2)); a refused attempt still counts (a burst guard, never given back); for limits that count every attempt
    - `reserve(User $user, string $field, int $cost = 1): void` — `attempt()`, named for the completed-send limits: called before the action, which the caller wraps in `try { … } catch (Throwable $exception) { $limit->release($user); throw $exception; }`
    - `release(User $user, int $cost = 1): void` — gives back `$cost` reserved attempts (`RateLimiter::decrement()`) when the action threw, so a typo or a refused address costs nothing
    - `ensureAvailable(User $user, string $field, int $cost = 1): void` — the same refusal when `$cost` more attempts would pass the limit; counts nothing (a pre-check parallel requests can outrun; kept for M7d)
    - `hit(User $user, int $cost = 1): void` — counts `$cost` attempts without a check, in a fixed window of `$decaySeconds` from the first hit (kept for M7d and the tests that fill a limit)
    - the refusal is one private `refuse(User $user, string $field): never`
    - `$cost` outside `1…$maxAttempts` throws `InvalidArgumentException`, so a batch larger than the limit is the caller's validation error, never a silent pass
  - `App\Support\RateLimits\ThrottleKey::of(string $limiter, string $key): string`
  - route limiter `account-security` (10 per minute per user, every request) on `two-factor.enable`, `two-factor.confirm`, `two-factor.disable`, `two-factor.regenerate-recovery-codes` and `passkey.destroy`
  - `App\Actions\Invitations\RemindInvitation::COOLDOWN_MINUTES = 10`
  - `App\Http\Controllers\Auth\ResetPasswordController` (route `password.update`)

**Notes for the implementer:**
- **The refusal message.** CD-3 names `auth.throttle`, whose copy is "Too many login attempts" ("Te veel inlogpogingen"), which is wrong on an invite or email form. The limits use a new key, "Too many attempts. Try again in :seconds seconds.", next to the System page's existing "Too many attempts. Try again in a minute.". The sign-in limiter keeps `auth.throttle`.
- **What counts.** The password and TOTP limits count every attempt before the secret is checked (`attempt()`). The email-change, invite and remind limits reserve a slot atomically before the send (`reserve()`) and give it back when the action throws (`release()` in a `catch (Throwable)` that rethrows), so a typo or a refused address costs nothing and parallel requests cannot pass the limit (DEC-27 (2): a check before and a count after the action lets a burst of parallel `/livewire/update` requests through, and Livewire's update route has no throttle). A refused attempt or reservation still counts and is never given back. The checks sit in the components, never in the actions (CD-3), so `RemindInvitation`'s cooldown is a rule of the invitation, not a limit of the actor.
- **Livewire errors.** Livewire keeps only errors on component properties in the snapshot (`SupportValidation::dehydrate()`). The pending list and the accept page show `invitation`, which is no property, so those tests read the rendered HTML of the update.
- **Why `ThrottleKey`.** `ThrottleRequests` stores a named limiter's `Limit` under `md5($limiterName.$limit->key)`, so clearing the `login:` bucket outside the middleware needs that hash. Pest's security preset bans `md5`; the hash is Laravel's storage format, not a security use, so the preset ignores this one class (no `hash('md5', …)` workaround).
- **Fortify's own route replacements.** Fortify registers its routes while its provider boots, and `routes/web.php` loads after that, so a kit route with the same method, domain, prefix and path replaces the vendor one (FU3-3). `passkey.destroy` lives in `routes/passkeys.php`, because `usePasskeysModule(true)` replays that file (and not `routes/fortify.php`) when a test turns the module on after boot.
- **`password.update` and arrays.** Fortify's `NewPasswordController::store()` lower-cases the address before it validates it, so `email[]=` still answered 500 after the limiter fix. `ResetPasswordController` validates `email` as a string first and calls the parent.
- **CD-13 for the sign-in limiter (controller ruling).** Task 4's `ReturnUrl` does not exist yet, so `loginThrottled()` answers with `to_route('login')`, keeping the input and the error. The login form posts from the login page, so this is the same page without trusting the Referer. Task 4 leaves `FortifyServiceProvider` alone.
- **No `git stash`.** The stash is shared by every worktree of the repository. For mutation checks, copy the file aside with `cp`, change it, run the test, and copy it back.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Support/UserActionLimitTest.php` (new):

```php
<?php

declare(strict_types=1);

/** covers §4.2 */

use App\Models\User;
use App\Support\RateLimits\UserActionLimit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->freezeTime();
});

it('admits the limit, then refuses on the field with the seconds left, per user (CD-3)', function (): void {
    $ada = User::factory()->create();
    $bob = User::factory()->create();
    $limit = new UserActionLimit('test-action', 3, 600);

    foreach (range(1, 3) as $attempt) {
        $limit->attempt($ada, 'email');
    }
    $this->travel(100)->seconds();

    $refusal = null;

    try {
        $limit->attempt($ada, 'email');
    } catch (ValidationException $exception) {
        $refusal = $exception->errors();
    }

    expect($refusal)->toBe(['email' => [__('Too many attempts. Try again in :seconds seconds.', ['seconds' => 500])]]);

    $limit->attempt($bob, 'email');
    expect($limit->key($ada))->toBe('test-action:'.$ada->id);
});

it('checks without counting, and counts only what hit() is told (CD-3)', function (): void {
    $user = User::factory()->create();
    $limit = new UserActionLimit('test-action', 2, 60);

    foreach (range(1, 5) as $check) {
        $limit->ensureAvailable($user, 'email');
    }
    $limit->hit($user);
    $limit->hit($user);

    expect(fn () => $limit->ensureAvailable($user, 'email'))->toThrow(ValidationException::class);
});

it('counts an attempt before it checks it, so a refused attempt counts too (CD-3, DEC-27 (2))', function (): void {
    $user = User::factory()->create();
    $limit = new UserActionLimit('test-action', 5, 60);

    foreach (range(1, 5) as $attempt) {
        $limit->attempt($user, 'current_password');
    }

    expect(fn () => $limit->attempt($user, 'current_password'))->toThrow(ValidationException::class)
        ->and(RateLimiter::attempts($limit->key($user)))->toBe(6);
});

it('reserves without a hit in between, refuses past the limit and takes a released slot back (CD-3, DEC-27 (2))', function (): void {
    $user = User::factory()->create();
    $limit = new UserActionLimit('test-send', 5, 60);

    foreach (range(1, 5) as $reservation) {
        $limit->reserve($user, 'email');
    }

    expect(fn () => $limit->reserve($user, 'email'))->toThrow(ValidationException::class);

    // The refused reservation counted (6); two releases bring it back under the limit, so one more passes.
    $limit->release($user);
    $limit->release($user);
    $limit->reserve($user, 'email');

    expect(RateLimiter::attempts($limit->key($user)))->toBe(5)
        ->and(fn () => $limit->reserve($user, 'email'))->toThrow(ValidationException::class);
});

it('weighs a batch by its cost and refuses a batch that would pass the limit (M7d)', function (): void {
    $user = User::factory()->create();
    $limit = new UserActionLimit('test-batch', 10, 3600);

    $limit->attempt($user, 'emails', cost: 7);
    $limit->ensureAvailable($user, 'emails', cost: 3);

    expect(fn () => $limit->ensureAvailable($user, 'emails', cost: 4))->toThrow(ValidationException::class)
        ->and(fn () => $limit->ensureAvailable($user, 'emails', cost: 11))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $limit->hit($user, cost: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $limit->release($user, cost: 11))->toThrow(InvalidArgumentException::class);
});

it('opens again once the window has passed (CD-3)', function (): void {
    $user = User::factory()->create();
    $limit = UserActionLimit::passwordUpdate();

    foreach (range(1, 5) as $attempt) {
        $limit->attempt($user, 'current_password');
    }
    $this->travel(61)->seconds();

    $limit->attempt($user, 'current_password');

    expect($limit->key($user))->toBe('password-update:'.$user->id);
});

it('holds the limits of CD-3', function (): void {
    $limits = array_map(
        fn (UserActionLimit $limit): array => [$limit->name, $limit->maxAttempts, $limit->decaySeconds],
        [UserActionLimit::emailChange(), UserActionLimit::passwordUpdate(), UserActionLimit::twoFactorConfirm(), UserActionLimit::invite(), UserActionLimit::remind()],
    );

    expect($limits)->toBe([
        ['email-change', 3, 600],
        ['password-update', 5, 60],
        ['two-factor-confirm', 5, 60],
        ['invite', 10, 60],
        ['remind', 10, 60],
    ]);
});
```

`tests/Feature/Auth/ActionLimitsTest.php` (new). Every limit is exercised over HTTP (`pageSnapshot()` + `callPageAction()`), because `Livewire::test()` skips middleware:

```php
<?php

declare(strict_types=1);

/** covers §4.2, §4.5 */

use App\Authorization\Role;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Notifications\ConfirmEmailChange;
use App\Support\RateLimits\UserActionLimit;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role as RoleModel;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\withSession;

beforeEach(function (): void {
    $this->freezeTime();
});

/**
 * The validation errors a Livewire update returned.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, list<string>>
 */
$errorsOf = function (TestResponse $response): array {
    /** @var array{memo: array{errors?: array<string, list<string>>}} $snapshot */
    $snapshot = json_decode((string) $response->assertOk()->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR);

    return $snapshot['memo']['errors'] ?? [];
};

$throttled = fn (int $seconds): string => __('Too many attempts. Try again in :seconds seconds.', ['seconds' => $seconds]);

it('sends at most three email-change links per ten minutes and saves nothing when it refuses (CD-3, G1-01)', function () use ($errorsOf, $throttled): void {
    Notification::fake();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create(['name' => 'Ada', 'email' => 'old@example.com']);
    onTenant($organisation);
    $this->actingAs($member);
    $snapshot = pageSnapshot(route('profile.edit'), 'pages::settings.profile');

    foreach (range(1, 3) as $attempt) {
        expect($errorsOf(callPageAction($snapshot, 'updateProfileInformation', updates: ['email' => "new{$attempt}@example.com"])))->toBe([]);
    }

    $this->travel(1)->minute();

    expect($errorsOf(callPageAction($snapshot, 'updateProfileInformation', updates: ['name' => 'Eve', 'email' => 'new4@example.com'])))
        ->toBe(['email' => [$throttled(540)]]);
    Notification::assertSentOnDemandTimes(ConfirmEmailChange::class, 3);
    expect($member->fresh()?->name)->toBe('Ada');

    // Saving the name alone sends nothing, so it is never refused.
    expect($errorsOf(callPageAction($snapshot, 'updateProfileInformation', updates: ['name' => 'Eve'])))->toBe([])
        ->and($member->fresh()?->name)->toBe('Eve');
});

it('counts every password change attempt before it checks the current password (CD-3, G1-01)', function () use ($errorsOf, $throttled): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    $this->actingAs($member);
    withSession(['auth.password_confirmed_at' => time()]);
    $snapshot = pageSnapshot(route('security.edit'), 'pages::settings.security');
    $change = fn (string $current): TestResponse => callPageAction($snapshot, 'updatePassword', updates: [
        'current_password' => $current,
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ]);

    foreach (range(1, 5) as $attempt) {
        expect($errorsOf($change('wrong-password')))->toHaveKey('current_password');
    }

    expect($errorsOf($change('password')))->toBe(['current_password' => [$throttled(60)]])
        ->and(Hash::check('password', (string) $member->fresh()?->password))->toBeTrue();
});

it('counts every two-factor confirmation before it checks the code (CD-3, G1-01)', function () use ($errorsOf, $throttled): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    $this->actingAs($member);
    withSession(['auth.password_confirmed_at' => time()]);
    $snapshot = pageSnapshot(route('security.edit'), 'pages::settings.two-factor-setup-modal');
    // The page clears an unconfirmed secret when it mounts, so the setup starts after the snapshot, as the modal's would.
    app(EnableTwoFactorAuthentication::class)($member);
    $secret = (string) Fortify::currentEncrypter()->decrypt((string) $member->fresh()?->two_factor_secret);
    $validCode = (string) app(Google2FA::class)->getCurrentOtp($secret);
    $wrongCode = $validCode === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $attempt) {
        expect($errorsOf(callPageAction($snapshot, 'confirmTwoFactor', updates: ['code' => $wrongCode])))->toHaveKey('code');
    }

    expect($errorsOf(callPageAction($snapshot, 'confirmTwoFactor', updates: ['code' => $validCode])))->toBe(['code' => [$throttled(60)]])
        ->and($member->fresh()?->two_factor_confirmed_at)->toBeNull();
});

it('counts sent invitations per inviter, gives a refused one back and refuses the one past ten a minute (CD-3, G1-01)', function () use ($errorsOf, $throttled): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($organisation);
    $templateId = (string) RoleModel::query()->whereNull('team_id')->where('name', Role::OrganisationAdmin->value)->value('id');
    $snapshot = pageSnapshot(route('users.invite'), 'pages::users.invite');
    $invite = fn (string $email): TestResponse => callPageAction($snapshot, 'invite', updates: ['email' => $email, 'roles' => [$templateId]]);
    UserActionLimit::invite()->hit($admin, cost: 9);

    // InviteUser refuses an invitation without a role; the component gives the reserved slot back.
    expect($errorsOf(callPageAction($snapshot, 'invite', updates: ['email' => 'no-role@example.com', 'roles' => []])))->toHaveKey('roles')
        ->and(RateLimiter::attempts(UserActionLimit::invite()->key($admin)))->toBe(9);

    expect($errorsOf($invite('tenth@example.com')))->toBe([])
        ->and(RateLimiter::attempts(UserActionLimit::invite()->key($admin)))->toBe(10)
        ->and($errorsOf($invite('eleventh@example.com')))->toBe(['email' => [$throttled(60)]])
        ->and(User::query()->where('email', 'eleventh@example.com')->exists())->toBeFalse();
});

it('limits reminders from the pending list per actor and shows why (CD-3, G1-01)', function () use ($errorsOf, $throttled): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($organisation);
    $first = inviteMember($organisation, $admin, 'first@example.com');
    $second = inviteMember($organisation, $admin, 'second@example.com');
    $snapshot = pageSnapshot(route('invitations.pending'), 'pages::invitations.pending');
    UserActionLimit::remind()->hit($admin, cost: 9);

    expect($errorsOf(callPageAction($snapshot, 'remind', [$first->id])))->toBe([]);

    // Livewire keeps only property errors in the snapshot; "invitation" is a message slot, so read the page.
    expect((string) callPageAction($snapshot, 'remind', [$second->id])->assertOk()->json('components.0.effects.html'))->toContain($throttled(60))
        ->and($first->fresh()?->reminder_sent_at)->not->toBeNull()
        ->and($second->fresh()?->reminder_sent_at)->toBeNull();
});

it('limits a resend from the accept page with the pending list\'s limit and shows why (CD-3, G1-01)', function () use ($throttled): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $invitation = inviteMember($organisation);
    $admin = asOrgAdmin($organisation);
    $this->travel(8)->days();
    $snapshot = pageSnapshot(acceptLinkFor($invitation), 'pages::invitations.accept');
    UserActionLimit::remind()->hit($admin, cost: 10);

    expect((string) callPageAction($snapshot, 'resend')->assertOk()->json('components.0.effects.html'))->toContain($throttled(60))
        ->and(Invitation::query()->findOrFail($invitation->id)->reminder_sent_at)->toBeNull();
});

it('limits the account-security routes to ten requests a minute per user (CD-3)', function (string $method, string $route): void {
    usePasskeysModule(true);
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->withTwoFactor()->create();
    onTenant($organisation);
    $this->actingAs($member);
    withSession(['auth.password_confirmed_at' => time()]);
    $uri = route($route, $route === 'passkey.destroy' ? ['passkey' => 999_999] : []);

    foreach (range(1, 10) as $attempt) {
        expect($this->json($method, $uri)->getStatusCode())->not->toBe(429);
    }

    $this->json($method, $uri)->assertTooManyRequests();
})->with([
    'enable 2FA' => ['POST', 'two-factor.enable'],
    'confirm 2FA' => ['POST', 'two-factor.confirm'],
    'disable 2FA' => ['DELETE', 'two-factor.disable'],
    'regenerate recovery codes' => ['POST', 'two-factor.regenerate-recovery-codes'],
    'delete a passkey' => ['DELETE', 'passkey.destroy'],
]);
```

`tests/Feature/Auth/LoginThrottleTest.php`: add `use PragmaRX\Google2FA\Google2FA;` to the imports (after `use Illuminate\Support\Sleep;`). Insert these tests right before `it('locks an address out after five failed attempts', …)`:

```php
it('clears an address\'s failures once it signs in (G1-03)', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    foreach (range(1, 4) as $attempt) {
        $this->post(route('login.store'), ['email' => $member->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
    }

    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])->assertRedirect(route('dashboard'));
    $this->post(route('logout'));
    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'wrong'])->assertSessionHasErrors('email');

    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])->assertRedirect(route('dashboard'));
});

it('clears an address\'s failures once it signs in with a second factor (G1-03)', function (): void {
    $secret = app(Google2FA::class)->generateSecretKey();
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->withTwoFactor()->create(['two_factor_secret' => encrypt($secret)]);
    onTenant($organisation);

    foreach (range(1, 4) as $attempt) {
        $this->post(route('login.store'), ['email' => $member->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
    }

    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
    $this->post(route('two-factor.login.store'), ['code' => (string) app(Google2FA::class)->getCurrentOtp($secret)])->assertRedirect(route('dashboard'));
    $this->post(route('logout'));
    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'wrong'])->assertSessionHasErrors('email');

    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
});

it('answers array input to the throttled auth routes with a validation error, not a server error (G1-02)', function (string $route, array $input): void {
    $organisation = Organisation::factory()->create();
    onTenant($organisation);

    $this->from(route('login'))->post(route($route), $input)->assertRedirect()->assertSessionHasErrors('email');
})->with([
    'sign in' => ['login.store', ['email' => ['member@example.com'], 'password' => 'secret']],
    'request a reset link' => ['password.email', ['email' => ['member@example.com']]],
    'reset the password' => ['password.update', ['token' => 'x', 'email' => ['member@example.com'], 'password' => 'new-password', 'password_confirmation' => 'new-password']],
]);

it('answers an array credential id to the passkey sign-in with a validation error, not a server error (G1-02)', function (): void {
    usePasskeysModule(true);
    onTenant(Organisation::factory()->create());

    $this->postJson(route('passkey.login'), ['credential' => ['id' => ['x']]])->assertUnprocessable();
});

it('sends a throttled sign-in back to the login page, never to the Referer (CD-13)', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), ['email' => $member->email, 'password' => 'wrong']);
    }

    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'wrong'], ['Referer' => 'https://evil.example/phish'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');
});
```

and these right before `it('trusts the proxies named in TRUSTED_PROXIES and none by default', …)`:

```php
it('limits failed passkey sign-ins per IP, whatever credential id the client sends (G1-05)', function (): void {
    usePasskeysModule(true);
    onTenant(Organisation::factory()->create());

    foreach (range(1, 10) as $attempt) {
        $this->postJson(route('passkey.login'), ['credential' => ['id' => "credential-{$attempt}"]])->assertUnprocessable();
    }

    $this->postJson(route('passkey.login'), ['credential' => ['id' => 'credential-11']])->assertTooManyRequests();
});

it('limits failed passkey confirmations per user, not per IP (G1-05)', function (): void {
    usePasskeysModule(true);
    $organisation = Organisation::factory()->create();
    $ada = User::factory()->for($organisation)->create();
    $bob = User::factory()->for($organisation)->create();
    onTenant($organisation);

    $this->actingAs($ada);
    foreach (range(1, 10) as $attempt) {
        $this->postJson(route('passkey.confirm'), ['credential' => ['id' => "credential-{$attempt}"]])->assertUnprocessable();
    }
    $this->postJson(route('passkey.confirm'), ['credential' => ['id' => 'credential-11']])->assertTooManyRequests();

    $this->actingAs($bob);
    $this->postJson(route('passkey.confirm'), ['credential' => ['id' => 'credential-12']])->assertUnprocessable();
});
```

`tests/Feature/Auth/FortifyRoutesTest.php`: retitle the test to `'keeps the throttled kit routes in place of Fortify\'s when Fortify has a prefix (FU3-3, CD-3)'` and extend the `foreach` list after the `password.confirm.store` row:

```php
        'two-factor.enable' => ['auth/user/two-factor-authentication', 'throttle:account-security'],
        'two-factor.confirm' => ['auth/user/confirmed-two-factor-authentication', 'throttle:account-security'],
        'two-factor.disable' => ['auth/user/two-factor-authentication', 'throttle:account-security'],
        'two-factor.regenerate-recovery-codes' => ['auth/user/two-factor-recovery-codes', 'throttle:account-security'],
```

`tests/Feature/Invitations/RemindInvitationTest.php`: insert right before `it('reminds an expired invitation', …)`:

```php
it('sends one reminder per invitation per ten minutes, whoever sends it (CD-3, G1-01)', function () use ($queuedReminders): void {
    $this->freezeSecond();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($organisation);
    $invitation = inviteMember($organisation, $admin);
    DB::table('jobs')->delete();
    $superAdmin = asSuperAdmin();
    onTenant($organisation);

    app(RemindInvitation::class)->handle($admin, $invitation);
    $this->travel(9)->minutes();

    expect(fn () => app(RemindInvitation::class)->handle($superAdmin, $invitation))
        ->toThrow(ValidationException::class, __('This invitation was sent again less than :minutes minutes ago.', ['minutes' => 10]));
    expect($queuedReminders())->toBe(1);

    $this->travel(61)->seconds();
    app(RemindInvitation::class)->handle($superAdmin, $invitation);

    expect($queuedReminders())->toBe(2)
        ->and($invitation->refresh()->reminder_sent_at?->equalTo(now()))->toBeTrue();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Support/UserActionLimitTest.php tests/Feature/Auth/ActionLimitsTest.php tests/Feature/Auth/LoginThrottleTest.php tests/Feature/Auth/FortifyRoutesTest.php tests/Feature/Invitations/RemindInvitationTest.php`

Expected: FAIL (29 tests), as follows:
- `UserActionLimitTest`, and the invite, pending-list and accept-page cases of `ActionLimitsTest`: `Class "App\Support\RateLimits\UserActionLimit" not found`.
- Email change, password update and TOTP confirmation: the last call returns no errors (`Array &0 []`); nothing limits them.
- `account-security`: the 11th request answers 200 (enable, disable, regenerate), 422 (confirm) or 404 (passkey) instead of 429.
- G1-03, both cases: the last correct password gets "Too many login attempts", because the success never cleared the `login:` bucket.
- G1-02: the sign-in, reset-link and reset routes answer 500 "Array to string conversion"; the passkey sign-in answers 500 instead of 422.
- CD-13: the throttled sign-in redirects to `https://evil.example/phish`.
- G1-05: the 11th failing passkey sign-in and confirmation answer 422 instead of 429 (a fresh `credential.id` is a fresh bucket).
- The cooldown test: "Exception [Illuminate\Validation\ValidationException] not thrown."
- `FortifyRoutesTest`: "Failed asserting that an array contains 'throttle:account-security'."

- [ ] **Step 3: `UserActionLimit` and `ThrottleKey`**

`app/Support/RateLimits/UserActionLimit.php` (new):

```php
<?php

declare(strict_types=1);

namespace App\Support\RateLimits;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A per-user limit on a Livewire action (§4.2, CD-3). Every Livewire action shares one update endpoint, so a
 * route limiter cannot tell them apart: the component checks the limit itself, before it calls the action.
 * Actions never count, so a caller that loops over an action (M7d's bulk invitations) counts under its own limit.
 *
 * The counter is `{name}:{user id}` on Laravel's rate limiter (the default cache store, as for Fortify's limiters),
 * in a fixed window of $decaySeconds from the first hit. A refusal is a ValidationException on the form's field.
 */
final readonly class UserActionLimit
{
    public function __construct(
        public string $name,
        public int $maxAttempts,
        public int $decaySeconds,
    ) {
        if ($maxAttempts < 1 || $decaySeconds < 1) {
            throw new InvalidArgumentException('A user action limit needs at least one attempt and one second.');
        }
    }

    /** Request an email change on the profile page: 3 sends per 10 minutes. */
    public static function emailChange(): self
    {
        return new self('email-change', 3, 600);
    }

    /** Change the password on the security page: 5 attempts per minute, counted before the current password is checked. */
    public static function passwordUpdate(): self
    {
        return new self('password-update', 5, 60);
    }

    /** Confirm a new TOTP secret: 5 attempts per minute, counted before the code is checked. */
    public static function twoFactorConfirm(): self
    {
        return new self('two-factor-confirm', 5, 60);
    }

    /** Invite a user from the invite page: 10 invitations per minute. */
    public static function invite(): self
    {
        return new self('invite', 10, 60);
    }

    /** Send an invitation again (pending list, accept page): 10 reminders per minute; RemindInvitation adds a per-invitation cooldown. */
    public static function remind(): self
    {
        return new self('remind', 10, 60);
    }

    /** The rate-limiter key: a string for any user id, never built from client input (G1-02). */
    public function key(User $user): string
    {
        return $this->name.':'.$user->id;
    }

    /**
     * Counts $cost attempts first and refuses when the new count passes the limit: RateLimiter::increment() returns
     * that count atomically, so parallel requests cannot all read the old one (CD-3, DEC-27 (2)). A refused attempt
     * still counts; it is a burst guard and is never given back. For limits that count every attempt.
     *
     * @throws ValidationException
     */
    public function attempt(User $user, string $field, int $cost = 1): void
    {
        $this->assertCost($cost);

        if (RateLimiter::increment($this->key($user), $this->decaySeconds, $cost) > $this->maxAttempts) {
            $this->refuse($user, $field);
        }
    }

    /**
     * Reserves $cost attempts before a completed-send action (email change, invite, remind), as attempt() does.
     * The caller gives them back with release() when the action throws, so a refused address costs nothing.
     *
     * @throws ValidationException
     */
    public function reserve(User $user, string $field, int $cost = 1): void
    {
        $this->attempt($user, $field, $cost);
    }

    /** Gives back $cost reserved attempts of an action that did not go through. */
    public function release(User $user, int $cost = 1): void
    {
        $this->assertCost($cost);

        RateLimiter::decrement($this->key($user), $this->decaySeconds, $cost);
    }

    /**
     * Refuses when $cost more attempts would pass the limit; counts nothing. A pre-check that parallel requests can
     * outrun, so the kit's limits use attempt() or reserve(); kept for M7d.
     *
     * @throws ValidationException
     */
    public function ensureAvailable(User $user, string $field, int $cost = 1): void
    {
        $this->assertCost($cost);

        if (RateLimiter::tooManyAttempts($this->key($user), $this->maxAttempts - $cost + 1)) {
            $this->refuse($user, $field);
        }
    }

    /** Counts $cost attempts without a check (M7d; tests fill a limit with it). */
    public function hit(User $user, int $cost = 1): void
    {
        $this->assertCost($cost);

        RateLimiter::increment($this->key($user), $this->decaySeconds, $cost);
    }

    private function assertCost(int $cost): void
    {
        if ($cost < 1 || $cost > $this->maxAttempts) {
            throw new InvalidArgumentException("A cost of {$cost} does not fit the {$this->name} limit of {$this->maxAttempts}.");
        }
    }

    /**
     * The refusal on the form's field, with the seconds left in the window.
     *
     * @throws ValidationException
     */
    private function refuse(User $user, string $field): never
    {
        throw ValidationException::withMessages([
            $field => __('Too many attempts. Try again in :seconds seconds.', [
                'seconds' => max(1, RateLimiter::availableIn($this->key($user))),
            ]),
        ]);
    }
}
```

`app/Support/RateLimits/ThrottleKey.php` (new):

```php
<?php

declare(strict_types=1);

namespace App\Support\RateLimits;

/**
 * The cache key under which ThrottleRequests counts one Limit of a named limiter: md5(limiter name . limit key)
 * (Illuminate\Routing\Middleware\ThrottleRequests::handleRequestUsingNamedLimiter()). md5 is Laravel's storage
 * format here, not a security hash, so tests/Arch/PresetsTest.php exempts this class from the security preset.
 * Code that clears a named limiter's bucket outside the middleware (G1-03) builds the key here, nowhere else.
 */
final class ThrottleKey
{
    public static function of(string $limiter, string $key): string
    {
        return md5($limiter.$key);
    }
}
```

`tests/Arch/PresetsTest.php`: add `use App\Support\RateLimits\ThrottleKey;` to the imports (after `use App\Authorization\Role;`) and replace `arch()->preset()->security();` with:

```php
// Justified ignore (G1-03): ThrottleKey rebuilds ThrottleRequests' md5 storage key for a named limiter; no security hash.
arch()->preset()->security()->ignoring([
    ThrottleKey::class,
]);
```

- [ ] **Step 4: The Fortify limiters, the routes and the reset controller (G1-02, G1-03, G1-05, CD-3, CD-13)**

`app/Providers/FortifyServiceProvider.php`: replace the whole file:

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use App\Support\RateLimits\ThrottleKey;
use App\Support\Skv;
use App\Tenancy\Auth\AuthenticateOnThisHost;
use App\Tenancy\Hosts;
use Closure;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse as FailedPasswordResetResponseContract;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Responses\FailedPasswordResetResponse;
use Symfony\Component\HttpFoundation\Response;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // R1: an unknown address gets the same error as a bad token, so password.update reveals no addresses.
        $this->app->bind(
            FailedPasswordResetResponseContract::class,
            fn (): FailedPasswordResetResponse => new FailedPasswordResetResponse(Password::INVALID_TOKEN),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->clearLoginFailuresOnSignIn();
    }

    /**
     * The passkeys limiter (§4.2, G1-05): failed ceremonies per signed-in user (confirm, register) or, for a guest's
     * sign-in, per IP, plus a backstop that counts every request. No bucket comes from the request body, so a client
     * cannot pick a fresh one per attempt. Public so tests that turn the module on after boot can register it (RL-3).
     *
     * @return Closure(Request): list<Limit>
     */
    public static function passkeysLimiter(): Closure
    {
        return static function (Request $request): array {
            $user = $request->user();

            return [
                Limit::perMinute(10)
                    ->by($user === null ? 'passkeys-ip:'.$request->ip() : 'passkeys-user:'.$user->getAuthIdentifier())
                    ->after(self::failedAttempt($request)),
                Limit::perMinute(30)->by('passkeys-burst:'.$request->ip()),
            ];
        };
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::authenticateUsing(fn (Request $request): ?User => app(AuthenticateOnThisHost::class)($request));

        // R1: the reset link points at the user's own host, whichever host the request came from.
        ResetPassword::createUrlUsing(fn (User $user, string $token): string => Hosts::url(
            Hosts::homeOf($user),
            route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()], absolute: false),
        ));
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (): Factory|View => view('pages::auth.login'));
        Fortify::twoFactorChallengeView(fn (): Factory|View => view('pages::auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn (): Factory|View => view('pages::auth.confirm-password'));
        Fortify::resetPasswordView(fn (): Factory|View => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn (): Factory|View => view('pages::auth.forgot-password'));
    }

    /**
     * §4.2: login, two-factor and passkey limiters count failed attempts only (a response of 400 or more,
     * or validation errors flashed by this request); password confirmation, password-reset and handoff
     * limiters count every request (DEC-27).
     *
     * A failure-only limit is checked before the request but counted after the response, so a burst of
     * parallel requests can pass it while its counter is still 0. Each one therefore has a backstop that
     * counts every request, set above what a real user ever reaches (DEC-27).
     *
     * The IP limits assume that $request->ip() is the client's address: behind a load balancer or CDN,
     * set TRUSTED_PROXIES (config/trustedproxy.php), or every visitor shares the proxy's buckets.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $email = $this->inputString($request, Fortify::username());

            return [
                Limit::perMinute(5)->by($this->loginFailureBucket($email, $request->ip()))
                    ->after(self::failedAttempt($request))
                    ->response($this->loginThrottled(...)),
                Limit::perMinute(20)->by('login-ip:'.$request->ip())
                    ->after(self::failedAttempt($request))
                    ->response($this->loginThrottled(...)),
                Limit::perMinute(30)->by('login-burst:'.Str::transliterate(Str::lower($email)).'|'.$request->ip())
                    ->response($this->loginThrottled(...)),
            ];
        });

        RateLimiter::for('two-factor', function (Request $request): array {
            $challenge = $request->session()->get('login.id');

            return [
                Limit::perMinute(5)->by('two-factor:'.$challenge)->after(self::failedAttempt($request)),
                Limit::perMinute(10)->by('two-factor-burst:'.$challenge),
            ];
        });

        RateLimiter::for('password-confirm', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('password-confirm:'.$request->user()?->getAuthIdentifier()));

        // CD-3: Fortify's two-factor management routes and passkey.destroy, every request, per user.
        RateLimiter::for('account-security', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('account-security:'.$request->user()?->getAuthIdentifier()));

        // §6.8 / RL-3: the passkeys limiter exists only while the module is on.
        if (Skv::passkeysEnabled()) {
            RateLimiter::for('passkeys', self::passkeysLimiter());
        }

        RateLimiter::for('password-email', fn (Request $request): array => [
            Limit::perMinute(5)->by('password-email:'.Str::lower($this->inputString($request, 'email')).'|'.$request->ip()),
            Limit::perMinute(20)->by('password-email-ip:'.$request->ip()),
        ]);

        RateLimiter::for('password-reset', fn (Request $request): array => [
            Limit::perMinute(5)->by('password-reset:'.Str::lower($this->inputString($request, 'email')).'|'.$request->ip()),
            Limit::perMinute(20)->by('password-reset-ip:'.$request->ip()),
        ]);

        RateLimiter::for('handoff', fn (Request $request): Limit => Limit::perMinute(10)->by('handoff:'.$request->ip()));
    }

    /**
     * G1-03: any sign-in (password, two-factor, passkey, handoff, invitation) clears the address+IP failure bucket of
     * the `login` limiter. Fortify's LoginRateLimiter::clear() clears a key the named limiter does not use. The
     * IP-wide and burst buckets stay, so a correct password never lifts the limit for other addresses.
     */
    private function clearLoginFailuresOnSignIn(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                RateLimiter::clear(ThrottleKey::of('login', $this->loginFailureBucket($event->user->email, request()->ip())));
            }
        });
    }

    /** The address+IP failure bucket of the `login` limiter (G1-03), shared by the limiter and the sign-in listener. */
    private function loginFailureBucket(string $email, ?string $ip): string
    {
        return 'login:'.Str::transliterate(Str::lower($email)).'|'.$ip;
    }

    /** G1-02: a limiter key part from the request; an array or other non-string input counts as empty, never as a 500. */
    private function inputString(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? $value : '';
    }

    /** @return Closure(Response): bool */
    private static function failedAttempt(Request $request): Closure
    {
        return fn (Response $response): bool => $response->getStatusCode() >= 400
            || in_array('errors', (array) $request->session()->get('_flash.new', []), true);
    }

    /** @param  array<string, mixed>  $headers */
    private function loginThrottled(Request $request, array $headers): Response
    {
        $seconds = (int) ($headers['Retry-After'] ?? 60);
        $message = __('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]);

        if ($request->expectsJson()) {
            return new JsonResponse(['message' => $message, 'errors' => [Fortify::username() => [$message]]], 429, $headers);
        }

        // CD-13: the login form posts from the login page, so the refusal goes back there, never to the Referer.
        return to_route('login')
            ->withInput($request->only(Fortify::username()))
            ->withErrors([Fortify::username() => $message])
            ->withHeaders($headers);
    }
}
```

`app/Http/Controllers/Auth/ResetPasswordController.php` (new):

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Controllers\NewPasswordController;

/**
 * Fortify's password reset (§4.2), with the address checked to be a string first (G1-02): Fortify lower-cases the
 * address before it validates it, so an array answered 500 instead of a validation error.
 */
final class ResetPasswordController extends NewPasswordController
{
    public function store(Request $request): Responsable
    {
        $request->validate([Fortify::email() => ['required', 'string']]);

        return parent::store($request);
    }
}
```

`routes/fortify.php`: replace the whole file:

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\SendPasswordResetLinkController;
use App\Tenancy\Http\Middleware\StopImpersonationBeforeLogout;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Laravel\Fortify\Http\Controllers\ConfirmedTwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\RecoveryCodeController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticationController;
use Laravel\Fortify\RoutePath;

/*
| The kit's replacements for eight Fortify routes (§4.2, §4.7). Same method, domain, prefix and path as Fortify
| (RoutePath honours fortify.paths), so each replaces the vendor route even with a custom prefix or domain (FU3-3).
*/
Route::group(['domain' => config('fortify.domain'), 'prefix' => config('fortify.prefix')], function (): void {
    // §4.2: password confirmation is throttled.
    Route::post(RoutePath::for('password.confirm', '/user/confirm-password'), [ConfirmablePasswordController::class, 'store'])
        ->middleware(['auth:web', 'throttle:password-confirm'])
        ->name('password.confirm.store');

    // §4.2 / R1: throttled, neutral and constant-time.
    Route::post(RoutePath::for('password.email', '/forgot-password'), SendPasswordResetLinkController::class)
        ->middleware(['guest:web', 'throttle:password-email'])
        ->name('password.email');

    // §4.2 / R1: throttled, so reset tokens and addresses cannot be probed; G1-02: an array address is a validation error.
    Route::post(RoutePath::for('password.update', '/reset-password'), [ResetPasswordController::class, 'store'])
        ->middleware(['guest:web', 'throttle:password-reset'])
        ->name('password.update');

    // CD-3: the two-factor management routes, as Fortify registers them, plus a per-user limit on every request.
    Route::middleware(['auth:web', 'throttle:account-security', 'password.confirm'])->group(function (): void {
        Route::post(RoutePath::for('two-factor.enable', '/user/two-factor-authentication'), [TwoFactorAuthenticationController::class, 'store'])
            ->name('two-factor.enable');
        Route::post(RoutePath::for('two-factor.confirm', '/user/confirmed-two-factor-authentication'), [ConfirmedTwoFactorAuthenticationController::class, 'store'])
            ->name('two-factor.confirm');
        Route::delete(RoutePath::for('two-factor.disable', '/user/two-factor-authentication'), [TwoFactorAuthenticationController::class, 'destroy'])
            ->name('two-factor.disable');
        Route::post(RoutePath::for('two-factor.recovery-codes', '/user/two-factor-recovery-codes'), [RecoveryCodeController::class, 'store'])
            ->name('two-factor.regenerate-recovery-codes');
    });

    // §4.7: signing out while impersonating ends the impersonation first.
    Route::post(RoutePath::for('logout', '/logout'), [AuthenticatedSessionController::class, 'destroy'])
        ->middleware(['auth:web', StopImpersonationBeforeLogout::class])
        ->name('logout');
});
```

`routes/passkeys.php`: replace the whole file:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\RoutePath;
use Laravel\Passkeys\Http\Controllers\PasskeyRegistrationController;

// §6.8: the optional passkeys module's own routes; routes/web.php requires this file only while it is on.
// Fortify registers the passkey ceremony routes itself, only while Features::passkeys() is enabled (RL-3).
Route::get('.well-known/passkey-endpoints', fn () => response()->json([
    'enroll' => route('security.edit'),
    'manage' => route('security.edit'),
]))->name('well-known.passkeys');

// CD-3: Fortify's passkey.destroy, with the same domain, prefix and middleware, plus a per-user limit on every request.
// Here and not in routes/fortify.php, because Fortify registers the passkey routes only while the module is on.
Route::group(['domain' => config('fortify.domain'), 'prefix' => config('fortify.prefix')], function (): void {
    Route::delete(RoutePath::for('passkey.destroy', '/user/passkeys/{passkey}'), [PasskeyRegistrationController::class, 'destroy'])
        ->middleware(['auth:web', 'throttle:account-security', 'password.confirm'])
        ->name('passkey.destroy');
});
```

- [ ] **Step 5: The component limits and the reminder cooldown (G1-01, CD-3)**

`resources/views/pages/settings/profile/profile.php`: add `use App\Support\RateLimits\UserActionLimit;` after `use App\Enums\Locale;` and `use Throwable;` after `use Livewire\Component;`, and replace `updateProfileInformation()` with its docblock:

```php
    /**
     * Save the name and the language; a new email address only applies after it is confirmed through the
     * mailed link (E1). A new language reloads the page, so it renders in that language (§4.10). Sending the
     * confirmation link is limited per user (CD-3): the slot is reserved before anything is saved, so a refusal
     * saves nothing, and given back when the request throws.
     */
    public function updateProfileInformation(RequestEmailChange $requestEmailChange, UpdateLocale $updateLocale): void
    {
        $user = $this->currentUser();

        $this->email = Str::lower($this->email);

        $validated = $this->validate([
            ...$this->profileRules($user->id, $user->organisation_id),
            'locale' => ['required', Rule::enum(Locale::class)],
        ]);

        $email = (string) $validated['email'];
        $emailChangeLimit = UserActionLimit::emailChange();

        if ($email !== $user->email) {
            $emailChangeLimit->reserve($user, 'email');
        }

        $user->fill(['name' => $validated['name']])->save();

        $locale = Locale::from((string) $validated['locale']);
        $localeChanged = $locale !== $user->locale;

        if ($localeChanged) {
            $updateLocale->handle($user, $locale);
        }

        if ($email !== $user->email) {
            try {
                $requestEmailChange->handle($user, $email);
            } catch (Throwable $exception) {
                $emailChangeLimit->release($user);

                throw $exception;
            }

            $this->email = $user->email;

            Flux::toast(variant: 'success', text: __('We sent a confirmation link to :email.', ['email' => $email]));
        } else {
            Flux::toast(variant: 'success', text: __('Profile updated.'));
        }

        if ($localeChanged) {
            $this->redirect(route('profile.edit'), navigate: false);
        }
    }
```

`resources/views/pages/settings/security/security.php`: add `use App\Support\RateLimits\UserActionLimit;` after `use App\Enums\ActivityEvent;`, and replace `updatePassword()` with its docblock (the `attempt()` sits inside the `try`, so a refusal also clears the three fields):

```php
    /**
     * Update the password for the currently authenticated user. Every attempt counts against the user's limit
     * before the current password is checked (CD-3), so the form cannot be used to guess it.
     */
    public function updatePassword(RecordActivity $recordActivity): void
    {
        try {
            UserActionLimit::passwordUpdate()->attempt($this->currentUser(), 'current_password');

            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        $this->currentUser()->update([
            'password' => $validated['password'],
        ]);

        // §4.8: the page changes the password without an event, so it records the change itself.
        $recordActivity->handle(ActivityEvent::PasswordChanged, $this->currentUser());

        $this->reset('current_password', 'password', 'password_confirmation');

        Flux::toast(variant: 'success', text: __('Password updated.'));
    }
```

`resources/views/pages/settings/two-factor-setup-modal/two-factor-setup-modal.php`: add `use App\Concerns\ResolvesCurrentUser;` and `use App\Support\RateLimits\UserActionLimit;` after `use App\Concerns\ConfirmsPasswords;`, add `use ResolvesCurrentUser;` after `use ConfirmsPasswords;` in the class, and replace `confirmTwoFactor()` with its docblock:

```php
    /**
     * Confirm two-factor authentication for the user. Every attempt counts against the user's limit before the
     * code is checked (CD-3).
     */
    public function confirmTwoFactor(ConfirmTwoFactorAuthentication $confirmTwoFactorAuthentication): void
    {
        if ($this->passwordConfirmationRequired()) {
            return;
        }

        UserActionLimit::twoFactorConfirm()->attempt($this->currentUser(), 'code');

        $this->validate();

        $confirmTwoFactorAuthentication($this->currentUser(), $this->code);

        $this->setupComplete = true;

        $this->closeModal();

        $this->dispatch('two-factor-enabled');
    }
```

`resources/views/pages/users/invite/invite.php`: add `use App\Support\RateLimits\UserActionLimit;` after `use App\Models\Organisation;` and `use Throwable;` after `use Spatie\Permission\Models\Role as RoleModel;`, and replace `invite()`:

```php
    public function invite(InviteUser $inviteUser): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'locale' => ['required', 'string', Rule::enum(Locale::class)],
            'roles' => ['array'],
            'roles.*' => ['integer'],
        ]);

        $email = trim($this->email);
        $organisation = $this->organisationToInvite();

        // Components authorise before calling an action; super admins pass through Gate::before ('create' is not always checked).
        $this->authorize('create', [Invitation::class, $organisation]);

        // CD-3: a per-user limit on sent invitations, here and not in InviteUser, so a bulk caller counts its own way.
        // The slot is reserved before the send and given back when InviteUser refuses (DEC-27 (2)).
        $inviteLimit = UserActionLimit::invite();
        $inviteLimit->reserve($this->currentUser(), 'email');

        try {
            $inviteUser->handle(
                $this->currentUser(),
                $organisation,
                $email,
                array_map(fn (int|string $roleId): int => (int) $roleId, $this->roles),
                Locale::from($this->locale),
            );
        } catch (Throwable $exception) {
            $inviteLimit->release($this->currentUser());

            throw $exception;
        }

        session()->flash('status', __('Invitation sent to :email.', ['email' => Str::lower($email)]));
        $this->redirectRoute($this->returnRoute(), navigate: true);
    }
```

`resources/views/pages/invitations/pending/pending.php`: add `use App\Support\RateLimits\UserActionLimit;` after `use App\Models\Organisation;` and `use Throwable;` after `use Livewire\WithPagination;`, and replace `remind()` with its docblock:

```php
    /**
     * Sends the invitation again with a renewed expiry (W5: refused once accepted, and within the cooldown, by the
     * action), under the actor's reminder limit (CD-3): reserved before the send, given back when the action refuses.
     */
    public function remind(int $invitationId, RemindInvitation $remindInvitation): void
    {
        $invitation = $this->findInvitation($invitationId);
        $this->authorize('remind', $invitation);

        $remindLimit = UserActionLimit::remind();
        $remindLimit->reserve($this->currentUser(), 'invitation');

        try {
            $remindInvitation->handle($this->currentUser(), $invitation);
        } catch (Throwable $exception) {
            $remindLimit->release($this->currentUser());

            throw $exception;
        }

        Flux::toast(variant: 'success', text: __('The invitation was sent again.'));
    }
```

`resources/views/pages/invitations/accept/accept.php`: add `use App\Support\RateLimits\UserActionLimit;` after `use App\Support\Locales;` and `use Throwable;` after `use Livewire\Component;`, and replace `resend()` with its docblock:

```php
    /**
     * Send the invitation again from the expired state (INV-11); RemindInvitation authorises. The actor's reminder
     * limit is the pending list's (CD-3): reserved before the send, given back when the action refuses.
     */
    public function resend(RemindInvitation $remindInvitation): void
    {
        $actor = Auth::user();

        if (! $actor instanceof User) {
            abort(403);
        }

        $remindLimit = UserActionLimit::remind();
        $remindLimit->reserve($actor, 'invitation');

        try {
            $remindInvitation->handle($actor, $this->invitation());
        } catch (Throwable $exception) {
            $remindLimit->release($actor);

            throw $exception;
        }

        Flux::toast(variant: 'success', text: __('Invitation reminder sent.'));
    }
```

`resources/views/pages/invitations/accept/accept.blade.php`: in the `expired` case, the refusal needs a place to show (the form's `invitation` error renders only in the `form` state):

```blade
            @if ($this->mayResend)
                <flux:button variant="primary" wire:click="resend" class="w-full" data-test="resend-invitation-button">
                    {{ __('Resend invitation') }}
                </flux:button>

                <flux:error name="invitation" />
            @endif
```

`app/Actions/Invitations/RemindInvitation.php`: replace the class docblock and add the constant:

```php
/**
 * Sends an invitation again (§4.5, C-10): renews the domain expiry, keeps the token, sets
 * reminder_sent_at and queues the reminder after commit. Allowed for an expired invitation,
 * refused once accepted (W5) and within COOLDOWN_MINUTES of the last reminder (CD-3), decided on
 * the locked row. The mail builds its link in the worker (CV-12).
 */
final readonly class RemindInvitation
{
    /** One reminder per invitation per this many minutes, whoever sends it (CD-3). */
    public const int COOLDOWN_MINUTES = 10;

    public function __construct(private RecordActivity $recordActivity) {}
```

and, inside `Tenant::run()`, right after the `isAccepted()` refusal:

```php
                if ($locked->reminder_sent_at?->isAfter(now()->subMinutes(self::COOLDOWN_MINUTES)) === true) {
                    throw ValidationException::withMessages([
                        'invitation' => __('This invitation was sent again less than :minutes minutes ago.', ['minutes' => self::COOLDOWN_MINUTES]),
                    ]);
                }
```

- [ ] **Step 6: Copy**

Lang keys, in both files, at their sorted positions (PHP `sort()` order):

| Key | nl | Position |
|---|---|---|
| `This invitation was sent again less than :minutes minutes ago.` | `Deze uitnodiging is minder dan :minutes minuten geleden opnieuw verstuurd.` | right after `This invitation link expires on :date.` |
| `Too many attempts. Try again in :seconds seconds.` | `Te veel pogingen. Probeer het over :seconds seconden opnieuw.` | right after `Toggle sidebar`, before `Too many attempts. Try again in a minute.` |

In `en.json` each value equals its key.

`CHANGELOG.md`, under `## [Unreleased]`:
- `### Added`: `- Per-user limits on sending email-change links (3 per 10 minutes), password changes and two-factor confirmations (5 per minute, every attempt), invitations and reminders (10 per minute), and on the two-factor and passkey management routes (10 per minute); an invitation is sent again at most once per 10 minutes.`
- `### Fixed`:
  - `- A successful sign-in, also with a second factor or a passkey, clears the failed attempts of that address from that IP address.`
  - `- An array sent as the address to the sign-in, reset-link and reset routes, or as the credential id to the passkey sign-in, answers with a validation error instead of a server error.`
  - `- Failed passkey sign-ins are limited per IP address and failed passkey confirmations per user, whatever credential id the client sends.`
  - `- A throttled sign-in returns to the login page, never to the page the Referer names.`

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Support/UserActionLimitTest.php tests/Feature/Auth tests/Feature/Invitations tests/Feature/Settings tests/Arch tests/Feature/LangFilesTest.php tests/Feature/LangCoverageTest.php`

Expected: PASS.

- [ ] **Step 8: Verify by mutation**

For each mutation: `cp <file> /tmp/<name>.orig` (or the scratchpad), make the change, run the named test and confirm it fails, then `cp` the original back and confirm it passes. Never `git stash` (shared by every worktree). Name each mutation and its result in the task report.
1. In `profile.php`, replace `$emailChangeLimit->reserve($user, 'email');` with `$emailChangeLimit->ensureAvailable($user, 'email');`. Expect 'sends at most three email-change links per ten minutes…' to fail (nothing counts, so the fourth send goes through).
2. In `security.php`, move the `attempt()` line below `$this->validate([...])`. Expect 'counts every password change attempt before it checks the current password' to fail (wrong passwords throw before they are counted, so the sixth try succeeds).
3. In `UserActionLimit::ensureAvailable()`, replace `$this->maxAttempts - $cost + 1` with `$this->maxAttempts`. Expect 'weighs a batch by its cost…' to fail.
4. In `FortifyServiceProvider::boot()`, drop `$this->clearLoginFailuresOnSignIn();`. Expect both G1-03 tests to fail.
5. In `passkeysLimiter()`, key the failure bucket on the client's field again: `->by('passkeys:'.(string) $request->input('credential.id').'|'.$request->ip())`. Expect 'limits failed passkey sign-ins per IP, whatever credential id the client sends' to fail.
6. In `RemindInvitation`, drop the cooldown `if`. Expect 'sends one reminder per invitation per ten minutes…' to fail.
7. In `loginThrottled()`, put `back()` back. Expect 'sends a throttled sign-in back to the login page, never to the Referer' to fail.
8. In `routes/passkeys.php`, drop `'throttle:account-security'`. Expect the `delete a passkey` case of 'limits the account-security routes…' to fail, also in the modules-off run (the helper replays this file).
9. In `UserActionLimit::attempt()`, check before counting again (`if (RateLimiter::tooManyAttempts($this->key($user), $this->maxAttempts - $cost + 1)) { $this->refuse($user, $field); }` then `RateLimiter::increment(...)`). Expect 'counts an attempt before it checks it…' to fail (5 instead of 6).
10. In `invite.php`, drop `$inviteLimit->release($this->currentUser());` from the `catch`. Expect 'counts sent invitations per inviter, gives a refused one back…' to fail (10 instead of 9).

- [ ] **Step 9: The gate, then commit**

Run the Global Constraints gate:
- `composer check`
- `composer test:pgsql`
- the modules-off run
- `npm run build && composer test:browser`
- `npm run build:setup && git diff --exit-code resources/setup/setup.css` (Task 1 changes no setup view, so expect no diff)
- `php artisan config:clear`

```bash
git add app/Support/RateLimits/UserActionLimit.php app/Support/RateLimits/ThrottleKey.php \
    app/Http/Controllers/Auth/ResetPasswordController.php app/Providers/FortifyServiceProvider.php \
    app/Actions/Invitations/RemindInvitation.php routes/fortify.php routes/passkeys.php \
    resources/views/pages/settings/profile/profile.php resources/views/pages/settings/security/security.php \
    resources/views/pages/settings/two-factor-setup-modal/two-factor-setup-modal.php \
    resources/views/pages/users/invite/invite.php resources/views/pages/invitations/pending/pending.php \
    resources/views/pages/invitations/accept/accept.php resources/views/pages/invitations/accept/accept.blade.php \
    lang/nl.json lang/en.json CHANGELOG.md tests/Arch/PresetsTest.php \
    tests/Feature/Support/UserActionLimitTest.php tests/Feature/Auth/ActionLimitsTest.php \
    tests/Feature/Auth/LoginThrottleTest.php tests/Feature/Auth/FortifyRoutesTest.php \
    tests/Feature/Invitations/RemindInvitationTest.php
git commit -m "fix: limit mail-sending and secret-checking actions per user and harden the sign-in limiters

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

---

### Task 2: Links from APP_URL (G1-04, G1-06, G2-08; CD-4)

**Files:**
- Modify:
  - `app/Tenancy/Hosts.php` (`origin()` takes scheme and port from `config('app.url')`; class docblock)
  - `app/Tenancy/Listeners/SetPasskeyOrigin.php` (the allowed origin is `Hosts::origin(request()->getHost())`)
  - `app/Support/helpers.php` (`tenant_url()` docblock)
  - `resources/views/pages/invitations/pending/pending.php` (the `mount()` docblock, G2-08; written against Task 1's version)
  - `docs/superpowers/specs/2026-10-10-skv3-design.md` (§4.2 lines 100 and 111, §6.2 line 365)
  - `CHANGELOG.md`
- Test:
  - `tests/Feature/Tenancy/TenantUrlTest.php` (rewritten: DEC-26's test now pins `APP_URL`'s scheme and port)
  - `tests/Feature/Auth/PasswordResetHostTest.php`, `tests/Feature/Auth/PasskeyTest.php`, `tests/Feature/Invitations/PendingInvitationsTest.php`

**Interfaces:**
- Consumes:
  - `config('app.url')` (pinned to `http://localhost` by `phpunit.xml`; pest-plugin-browser sets it to the server origin of every browser request, port included, so browser flows keep their port)
  - `onTenant()`, `asOrgAdmin()`, `pageSnapshot()`, `callPageAction()`, `passkeyHost()` (file-local in `PasskeyTest.php`)
  - Task 1's `pending.php` (the remind limit; this task only changes the `mount()` docblock)
- Produces:
  - `Hosts::origin(string $host): string` — `{APP_URL scheme}://{$host}[:{APP_URL port}]`, the port omitted when it is the scheme's default; the request's port, `X-Forwarded-Proto` and `X-Forwarded-Port` never count. `Hosts::url()`, `tenant_url()` and every caller (reset links, email-change links, invitation links, handoffs, the Herd stage's login URL, the 403 page's home link, `TenantNotFoundException`'s apex link, the test helpers `onTenant()`/`onApex()`/`onAdminHost()`) follow.
  - `passkeys.allowed_origins` = `[Hosts::origin(request()->getHost())]` on `TenantResolved` / `TenantForgotten`

**Notes for the implementer:**
- **Why every caller is fine.** The full suite passes with only `TenantUrlTest`'s two request-scheme tests rewritten: tests run with `APP_URL=http://localhost`, which is what the old code read from the test request. The setup flow is unchanged in practice: the Herd stage's login URL is built in a fresh request after the Herd step wrote `APP_URL`, and a site that is not secured keeps an http `APP_URL` (Task 15's real wizard run checks the landing origin).
- **`X-Forwarded-*` stays useful for `$request->ip()`** (the rate limiters) and Laravel's own same-host URLs; only the kit's cross-request links ignore it.
- **The copy-link modal** (`inviteLink()` → `Invitation::acceptUrl()` → `tenant_url()`) is built in the admin's request; its test pins that it follows `APP_URL` too.
- **No `git stash`** for the mutation checks (shared by every worktree); copy files aside with `cp`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Tenancy/TenantUrlTest.php`: replace the whole file:

```php
<?php

declare(strict_types=1);

/** covers §4.1, §6.2 */

use App\Models\Organisation;
use App\Tenancy\Hosts;
use Illuminate\Http\Request;

it('takes the scheme and port of APP_URL, not of the current request (CD-4, G1-04)', function (): void {
    config(['app.url' => 'https://localhost:8443']);
    app()->instance('request', Request::create('http://localhost:6666/anything'));

    expect(tenant_url('acme', '/dashboard'))->toBe('https://acme.localhost:8443/dashboard');
});

it('omits APP_URL\'s default port (CD-4)', function (string $appUrl, string $expected): void {
    config(['app.url' => $appUrl]);
    app()->instance('request', Request::create('https://localhost:8443/anything'));

    expect(tenant_url('acme'))->toBe($expected);
})->with([
    'https' => ['https://localhost', 'https://acme.localhost/'],
    'https on 443' => ['https://localhost:443', 'https://acme.localhost/'],
    'http on 80' => ['http://localhost:80/', 'http://acme.localhost/'],
]);

it('uses APP_URL outside a web request', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);

    expect(tenant_url($organisation, 'settings'))->toBe('http://acme.localhost/settings');
});

it('derives the central hosts from the config', function (): void {
    config(['tenancy.apex_domain' => 'Example.test', 'tenancy.admin_subdomain' => 'beheer']);

    expect(Hosts::apex())->toBe('example.test')
        ->and(Hosts::admin())->toBe('beheer.example.test')
        ->and(Hosts::tenant('acme'))->toBe('acme.example.test')
        ->and(Hosts::origin(Hosts::admin()))->toBe('http://beheer.example.test');
});
```

`tests/Feature/Auth/PasswordResetHostTest.php`: insert right before `it('sends a super admin the reset link for the admin host', …)`:

```php
it('builds the reset link with APP_URL\'s scheme and port, whatever the request says (CD-4, G1-04)', function (string $case): void {
    Notification::fake();
    config(['app.url' => 'https://localhost']);
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    if ($case === 'a port in the Host header') {
        $this->post('http://acme.localhost:6666/forgot-password', ['email' => $member->email])->assertSessionHasNoErrors();
    } else {
        config(['trustedproxy.proxies' => '127.0.0.1']);
        $this->post(route('password.email'), ['email' => $member->email], ['X-Forwarded-Proto' => 'http', 'X-Forwarded-Port' => '8080'])
            ->assertSessionHasNoErrors();
    }

    Notification::assertSentTo($member, ResetPassword::class, fn (ResetPassword $notification): bool => str_starts_with(
        (string) $notification->toMail($member)->actionUrl,
        'https://acme.localhost/reset-password/',
    ));
})->with(['a port in the Host header', 'forwarded headers from a trusted proxy']);
```

`tests/Feature/Auth/PasskeyTest.php`: replace the test `it('allows the origin of the current request for passkey ceremonies (P1)', …)` (its dataset included) with:

```php
it('allows the current host\'s origin for passkey ceremonies (P1)', function (string $host, string $origin): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    passkeyHost($host, $organisation);

    $this->get(route('login'))->assertOk();

    expect(config('passkeys.allowed_origins'))->toBe([$origin]);
})->with([
    'tenant' => ['tenant', 'http://acme.localhost'],
    'apex' => ['apex', 'http://localhost'],
    'admin' => ['admin', 'http://admin.localhost'],
]);

it('allows the host with APP_URL\'s scheme and port, not the request\'s (CD-4, G1-06)', function (): void {
    config(['app.url' => 'https://localhost']);
    Organisation::factory()->create(['slug' => 'acme']);

    $this->get('http://acme.localhost:8443/login')->assertOk();

    expect(config('passkeys.allowed_origins'))->toBe(['https://acme.localhost']);
});
```

`tests/Feature/Invitations/PendingInvitationsTest.php`: insert right before `it('refuses a forged id of another organisation\'s invitation', …)`:

```php
it('builds the copied invite link with APP_URL\'s scheme and port, not the request\'s (CD-4, G1-04)', function () use ($adminOf, $invite): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $invitation = $invite($adminOf($organisation), $organisation, 'invitee@example.com');
    asOrgAdmin($organisation);
    $snapshot = pageSnapshot(route('invitations.pending'), 'pages::invitations.pending');
    config(['app.url' => 'https://localhost:8443']);

    $html = (string) callPageAction($snapshot, 'showLink', [$invitation->id])->assertOk()->json('components.0.effects.html');

    expect($html)->toContain('https://acme.localhost:8443/invitations/accept/')
        ->not->toContain('http://acme.localhost/invitations/accept/');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Tenancy/TenantUrlTest.php tests/Feature/Auth/PasswordResetHostTest.php tests/Feature/Auth/PasskeyTest.php tests/Feature/Invitations/PendingInvitationsTest.php`

Expected: FAIL (8 tests), as follows:
- `TenantUrlTest`: `https://acme.localhost:8443/dashboard` expected, `http://acme.localhost:6666/dashboard` given (the request's scheme and port); the three default-port cases give `https://acme.localhost:8443/`.
- The reset link, both cases: "The expected [Illuminate\Auth\Notifications\ResetPassword] notification was not sent", because the link starts with `http://acme.localhost:6666/` (Host-header port) or `http://acme.localhost:8080/` (forwarded proto and port) instead of `https://acme.localhost/`.
- `PasskeyTest`: `['http://acme.localhost:8443']` given instead of `['https://acme.localhost']`.
- `PendingInvitationsTest`: the modal holds `http://acme.localhost/invitations/accept/…`, not `https://acme.localhost:8443/…`.

- [ ] **Step 3: `Hosts::origin()` and the passkey origin (G1-04, G1-06, CD-4)**

`app/Tenancy/Hosts.php`: replace the class docblock:

```php
/**
 * Host names and absolute URLs (§4.1, §6.2). The scheme and the port come from APP_URL (CD-4), in a request as in
 * the console and queue workers, so a request's port or forwarded headers never reach a mailed or redirected link.
 */
```

and replace `origin()`:

```php
    /**
     * The origin of $host with APP_URL's scheme and port (CD-4, G1-04). The host is the caller's: tenancy validated
     * it, or homeOf() / tenant() built it. A Host-header port, X-Forwarded-Proto and X-Forwarded-Port never count.
     */
    public static function origin(string $host): string
    {
        $appUrl = (string) config('app.url');
        $scheme = Str::lower((string) (parse_url($appUrl, PHP_URL_SCHEME) ?: 'http'));
        $port = parse_url($appUrl, PHP_URL_PORT);
        $defaultPort = $scheme === 'https' ? 443 : 80;

        return $scheme.'://'.$host.(is_int($port) && $port !== $defaultPort ? ':'.$port : '');
    }
```

`app/Tenancy/Listeners/SetPasskeyOrigin.php`: replace the whole file:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy\Listeners;

use App\Tenancy\Hosts;

/**
 * Passkey ceremonies are checked against the current host's origin (§4.2, Appendix A P1), with APP_URL's scheme and
 * port like every link the kit builds (CD-4, G1-06); the relying-party id stays the apex. Reads the request's host,
 * not the tenant, so the value stays the browser's host inside Tenant::run().
 */
final class SetPasskeyOrigin
{
    public function handle(): void
    {
        config(['passkeys.allowed_origins' => [Hosts::origin(request()->getHost())]]);
    }
}
```

`app/Support/helpers.php`: replace the `tenant_url()` docblock with:

```php
    /** Absolute URL on an organisation's subdomain, with APP_URL's scheme and port (§6.2, CD-4). */
```

`resources/views/pages/invitations/pending/pending.php` (G2-08, the comment was wrong: `Illuminate\Auth\Middleware\Authorize` is Livewire persistent middleware, `vendor/livewire/livewire/src/Mechanisms/PersistentMiddleware/PersistentMiddleware.php:23`): replace the one-line docblock of `mount()`:

```php
    /**
     * The route's can: middleware also runs on Livewire updates (Authorize is persistent middleware); this check
     * covers the page wherever it is mounted (FU4-4, G2-08).
     */
```

- [ ] **Step 4: The spec (§4.2, §6.2)**

`docs/superpowers/specs/2026-10-10-skv3-design.md`, three replacements (old line, then new line).

Line 100 (§4.2):

```markdown
- Tenant URL helper keeping scheme **and port** (§6.2).
```

```markdown
- Tenant URL helper with `APP_URL`'s scheme **and port**, never the request's (§6.2, DEC-34).
```

Line 111 (§4.2, passkeys), only this part of the bullet:

```markdown
Only `passkeys.allowed_origins` changes per request: set to the current request origin (scheme + host + port) on `TenantResolved` / central resolution, reset on `TenantForgotten`.
```

```markdown
Only `passkeys.allowed_origins` changes per request: set to the current request's host with `APP_URL`'s scheme and port (`Hosts::origin()`, DEC-34) on `TenantResolved` / central resolution, reset on `TenantForgotten`.
```

Line 365 (§6.2):

```markdown
- `tenant_url(Organisation|string $slug, string $path = '/')` takes scheme and port from the current request, or from `APP_URL` / the apex in workers and the console. Signed accept URLs are built with it and tested from a worker context.
```

```markdown
- `tenant_url(Organisation|string $slug, string $path = '/')` and `Hosts::url()` take the scheme and the port from `APP_URL`, in requests, workers and the console alike; a request's port and `X-Forwarded-Proto` / `X-Forwarded-Port` never reach a link (DEC-34). Signed accept URLs are built with it and tested from a worker context.
```

- [ ] **Step 5: CHANGELOG**

`CHANGELOG.md`, under `## [Unreleased]` › `### Changed`: `- Links the kit builds for another request (password reset, email change, invitation and handoff links) and the passkey origin take their scheme and port from APP_URL, never from the request's port or forwarded headers.`

No lang keys.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Tenancy tests/Feature/Auth tests/Feature/Invitations tests/Feature/Setup/Wizard`

Expected: PASS.

- [ ] **Step 7: Verify by mutation**

For each mutation: copy the file aside with `cp`, change it, run the named test and confirm it fails, then copy the original back and confirm it passes (never `git stash`). Name each mutation and its result in the task report.
1. In `Hosts::origin()`, take the port from the request again: `$port = request()->getPort();`. Expect 'takes the scheme and port of APP_URL, not of the current request' and the Host-header case of the reset-link test to fail.
2. In `Hosts::origin()`, drop the `&& $port !== $defaultPort` check. Expect the `https on 443` and `http on 80` cases of 'omits APP_URL's default port' to fail.
3. In `SetPasskeyOrigin::handle()`, put `request()->getSchemeAndHttpHost()` back. Expect 'allows the host with APP_URL's scheme and port, not the request's' to fail.

- [ ] **Step 8: The gate, then commit**

Run the Global Constraints gate:
- `composer check`
- `composer test:pgsql`
- the modules-off run
- `npm run build && composer test:browser` (the browser server sets `app.url` to its own origin per request, so cross-host redirects keep the server's port; a failure here means a flow still mixes the two)
- `npm run build:setup && git diff --exit-code resources/setup/setup.css` (no setup view changes, so expect no diff)
- `php artisan config:clear`

```bash
git add app/Tenancy/Hosts.php app/Tenancy/Listeners/SetPasskeyOrigin.php app/Support/helpers.php \
    resources/views/pages/invitations/pending/pending.php docs/superpowers/specs/2026-10-10-skv3-design.md CHANGELOG.md \
    tests/Feature/Tenancy/TenantUrlTest.php tests/Feature/Auth/PasswordResetHostTest.php \
    tests/Feature/Auth/PasskeyTest.php tests/Feature/Invitations/PendingInvitationsTest.php
git commit -m "fix: build every link and the passkey origin from app_url's scheme and port

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

---

### Task 3: Settings pages and passkey failures (G2-01, G2-03, G2-07; CD-6)

**Files:**
- Create:
  - `app/Listeners/RecordFailedPasskeySignIn.php` (auto-discovered `RequestHandled` listener)
- Modify:
  - `resources/views/pages/settings/security/security.php` (written against Task 1's version, whose `updatePassword()` starts with `UserActionLimit::attempt(...)`; this task does not touch `updatePassword()` or the imports Task 1 added: `loadPasskeys()`, `confirmDelete()` and `deletePasskey()` start with a module guard, and a private `ensurePasskeysEnabled()` is added)
  - `resources/views/pages/settings/two-factor/recovery-codes/recovery-codes.php` (one private reader of the stored codes, used by `downloadRecoveryCodes()` and `loadRecoveryCodes()`)
  - `resources/views/pages/settings/two-factor/recovery-codes/recovery-codes.blade.php` (the 'Regenerate codes' trigger leaves the `@if (filled($recoveryCodes))`, so codes that cannot be read can still be replaced)
  - `bootstrap/app.php` (`dontReport` loses `WebauthnException`; a `dontReportWhen` that keeps `CounterException` reported; a `report()` callback that logs it as one warning and stops)
  - `CHANGELOG.md`
- Test:
  - `tests/Feature/Passkeys/PasskeysModuleTest.php` (G2-01)
  - `tests/Feature/Settings/RecoveryCodesTest.php` (G2-07)
  - `tests/Feature/Auth/PasskeyTest.php` (G2-03, CD-6)

**Interfaces:**
- Consumes:
  - Task 1's `resources/views/pages/settings/security/security.php`: `updatePassword(RecordActivity $recordActivity)` begins with `UserActionLimit::attempt(...)` (CD-3). Nothing else of Task 1 is used.
  - `ActivityEvent::LoginFailed` (`auth.login_failed`), which `RecordAuthenticationActivity::recordFailedSignIn()` already writes for a failed password sign-in with `['email' => …]` or `['email' => null, 'invalid_address' => true]`.
  - `RecordActivity::handle(ActivityEvent $event, ?Model $subject = null, array $properties = [], …): Activity`
  - `Passkeys::passkeyModel()` (bound to `App\Tenancy\Auth\UnscopedPasskey` by `TenancyServiceProvider`; its `user` relation is unscoped and includes deleted users)
  - `Tenant::current(): ?Organisation`
  - test helpers: `usePasskeysModule()`, `asMember()`, `onTenant()`, `pageSnapshot()`, `callPageAction()`, `recordedActivity()`; `PasskeyTest.php`'s file-level `registerPasskeyFor()`, `passkeyHost()`, `signInWithPasskey()`; `RecoveryCodesTest.php`'s `memberWithRecoveryCodes()`; `Tests\Support\SoftwareAuthenticator`
- Produces:
  - `App\Listeners\RecordFailedPasskeySignIn::handle(Illuminate\Foundation\Http\Events\RequestHandled $event): void` (final readonly, constructor `RecordActivity $recordActivity`). A `passkey.login` response with status 422, or a redirect whose session holds `errors`, writes `ActivityEvent::LoginFailed` with properties exactly `['method' => 'passkey']`. The subject is the passkey's user when `credential.rawId` is a base64url string that (trailing `=` stripped) matches a stored `credential_id` and, on a tenant host, the user's `organisation_id` is the tenant's and the user is not a super admin; otherwise the entry has no subject (any other `rawId`, invalid UTF-8 included, never reaches the database). A 429 records nothing. A failure while recording is reported (`report()`), never answered instead of the response: `RequestHandled` runs outside the kernel's exception handling.
  - The log line for a cloned authenticator: `Log::warning('A passkey was used with a signature counter that did not increase; the authenticator may have been cloned.', ['credential_id' => ?string, 'received_counter' => int, 'stored_counter' => int, 'host' => string, 'ip' => ?string])`. The default error report of that exception is stopped.
  - `CounterException` is reported (`ExceptionHandler::shouldReport()` is true); every other `WebauthnException` stays unreported (FU3-1).

Background for the implementer:
- **G2-01:** `loadPasskeys()`, `confirmDelete()` and `deletePasskey()` are public Livewire actions. Only `mount()` checks the module (`$canManagePasskeys`), so with `MODULE_PASSKEYS=false` a forged update still loads, selects and deletes a passkey. The guard reads `Features::canManagePasskeys()` on every request, not the locked snapshot property, so a module switched off after the page loaded is honoured too.
- **G2-07:** `downloadRecoveryCodes()` calls Fortify's `recoveryCodes()` without a `try`. After an `APP_KEY` rotation without `APP_PREVIOUS_KEYS` that throws `DecryptException` (500). A value that decrypts to something other than a JSON list gives a `TypeError` in `array_filter()`, and on mount `loadRecoveryCodes()` assigns `null` to the typed `$recoveryCodes` (500 on the security page). One reader decrypts through `Fortify::currentEncrypter()`, as Fortify does, and returns `null` for either case.
- **G2-03 (a):** `bootstrap/app.php` lists `WebauthnException` in `dontReport`. `Webauthn\Exception\CounterException` (final, extends `WebauthnException`) is thrown raw by `ThrowExceptionIfInvalid`, whose logger is a `NullLogger`, and it is not wrapped by `AuthenticatorAssertionResponseValidator`. So a cloned authenticator leaves no trace today.
- **G2-03 (b):** `PasskeyLoginController::store()` fires no `Failed` event. The route is vendor-defined, so the kit reads the outcome from `RequestHandled`, which the HTTP kernel fires after the response is built. The tenant is still bound then (`CurrentTenant` is scoped and is not forgotten at the end of the pipeline). `StartSession` has already saved the session by then: saving ages the flash data, so `session()->has('errors')` is true exactly when this request flashed errors.
- **R1 on tenant hosts:** a host's audit log never names another organisation's member or a super admin, also not a super admin promoted from this organisation (`SetSuperAdmin` keeps `organisation_id`). That is the same rule as the password-reset entries in `RecordAuthenticationActivity` after Task 8 (G4-01). On central hosts (central entries, which only super admins read) the passkey's user is named.
- **A credential id from a form post:** `PasskeyVerificationRequest` only checks that `credential.rawId` is a string. A form post can carry invalid UTF-8 (`credential[rawId]=%C3%28`): `TrimStrings` keeps the bytes, `json_encode()` fails, the request answers a redirect with flashed errors, and a `where('credential_id', …)` with those bytes throws on PostgreSQL (SQLSTATE 22021; SQLite accepts them). `RequestHandled` is dispatched after the kernel's `try`/`catch`, so that throw would turn the refused sign-in into a 500. The listener therefore looks up only a base64url id and reports any failure of its own.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Passkeys/PasskeysModuleTest.php`: append at the end of the file (the imports it needs, `Organisation`, are already there):

```php
it('refuses the security page\'s passkey actions while the module is off, whatever an update calls (G2-01)', function (string $method): void {
    usePasskeysModule(false);
    $member = asMember(Organisation::factory()->create(['slug' => 'acme']));
    $passkey = $member->passkeys()->create(['name' => 'Laptop', 'credential_id' => 'credential-1', 'credential' => []]);
    $this->withSession(['auth.password_confirmed_at' => time()]);
    $snapshot = pageSnapshot(route('security.edit'), 'pages::settings.security');

    callPageAction($snapshot, $method, $method === 'confirmDelete' ? [$passkey->id] : [])->assertNotFound();

    expect($passkey->fresh())->not->toBeNull();
})->with(['loadPasskeys', 'confirmDelete', 'deletePasskey']);

it('keeps the security page\'s passkey actions while the module is on (G2-01)', function (): void {
    usePasskeysModule(true);
    $member = asMember(Organisation::factory()->create(['slug' => 'acme']));
    $passkey = $member->passkeys()->create(['name' => 'Laptop', 'credential_id' => 'credential-1', 'credential' => []]);
    $this->withSession(['auth.password_confirmed_at' => time()]);
    $snapshot = pageSnapshot(route('security.edit'), 'pages::settings.security');

    $response = callPageAction($snapshot, 'confirmDelete', [$passkey->id])->assertOk();

    /** @var array{data: array<string, mixed>} $updated */
    $updated = json_decode((string) $response->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR);
    expect($updated['data']['deletingPasskeyName'])->toBe('Laptop');
});
```

`tests/Feature/Settings/RecoveryCodesTest.php`: add `use Illuminate\Encryption\Encrypter;` to the imports (after `use App\Models\User;`), and append:

```php
it('refuses to download stored codes it cannot read instead of failing with a server error (G2-07)', function (Closure $stored): void {
    $user = memberWithRecoveryCodes();
    $component = Livewire::withoutLazyLoading()->test('pages::settings.two-factor.recovery-codes');

    // The column changed after this page loaded: APP_KEY rotated without APP_PREVIOUS_KEYS, or a damaged value.
    $user->forceFill(['two_factor_recovery_codes' => $stored()])->save();

    $component->call('downloadRecoveryCodes')
        ->assertHasErrors(['recoveryCodes' => __('Failed to load recovery codes')])
        ->assertNoFileDownloaded();
})->with([
    'encrypted with another key' => [fn (): string => new Encrypter(random_bytes(32), (string) config('app.cipher'))->encrypt(json_encode(['recovery-code-1']))],
    'not a list of codes' => [fn (): string => encrypt('not json')],
]);

it('renders the security page with an error when the stored codes are not a list (G2-07)', function (): void {
    $user = memberWithRecoveryCodes();
    $user->forceFill(['two_factor_recovery_codes' => encrypt('not json')])->save();

    $this->get(route('security.edit'))->assertOk()->assertSee(__('Failed to load recovery codes'));
});

it('offers to regenerate codes it cannot read (G2-07)', function (): void {
    $user = memberWithRecoveryCodes();
    $user->forceFill(['two_factor_recovery_codes' => encrypt('not json')])->save();

    $html = Livewire::withoutLazyLoading()->test('pages::settings.two-factor.recovery-codes')
        ->assertHasErrors('recoveryCodes')
        ->html();

    // The trigger and the confirmation modal's button; the modal alone always renders one.
    expect(substr_count($html, __('Regenerate codes')))->toBe(2);
});
```

`tests/Feature/Auth/PasskeyTest.php`: add these imports at their sorted places:
- `use App\Authorization\SetSuperAdmin;` and `use App\Enums\ActivityEvent;` before `use App\Models\Organisation;`
- `use Illuminate\Support\Facades\Log;` after `use Illuminate\Support\Facades\DB;`
- `use Webauthn\Exception\CounterException;` after `use Webauthn\Exception\AuthenticatorResponseVerificationException;`
- `use function Pest\Laravel\post;` after `use function Pest\Laravel\getJson;`

Then append:

```php
it('reports a signature counter that did not increase, and no other failed WebAuthn ceremony (CD-6)', function (): void {
    $handler = app(ExceptionHandler::class);

    expect($handler->shouldReport(CounterException::create(1, 1, 'Invalid counter.')))->toBeTrue()
        ->and($handler->shouldReport(AuthenticatorResponseVerificationException::create('Invalid signature.')))->toBeFalse();
});

it('refuses a cloned authenticator and logs it once as a warning, without a stack trace (CD-6)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $authenticator = new SoftwareAuthenticator;
    onTenant($organisation);
    registerPasskeyFor($member, $authenticator, 'http://acme.localhost');
    // The same key, credential and signature counter: what a copied authenticator presents.
    $clone = clone $authenticator;
    signInWithPasskey($authenticator, 'http://acme.localhost')->assertOk();
    Auth::guard('web')->logout();
    expect(recordedActivity(ActivityEvent::LoginFailed))->toBeEmpty();
    $log = Log::spy();

    signInWithPasskey($clone, 'http://acme.localhost')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('credential');

    $this->assertGuest();
    $log->shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'signature counter')
        && $context === [
            'credential_id' => $member->passkeys()->sole()->credential_id,
            'received_counter' => 1,
            'stored_counter' => 1,
            'host' => 'acme.localhost',
            'ip' => '127.0.0.1',
        ]);
    $log->shouldNotHaveReceived('error');
    expect(recordedActivity(ActivityEvent::LoginFailed)->sole()->subject_id)->toBe($member->id);
});

it('records a failed passkey sign-in like a failed password sign-in, naming the member whose passkey it was (CD-6)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $authenticator = new SoftwareAuthenticator;
    onTenant($organisation);
    registerPasskeyFor($member, $authenticator, 'http://acme.localhost');

    signInWithPasskey($authenticator, 'http://evil.localhost')->assertUnprocessable();

    $entry = recordedActivity(ActivityEvent::LoginFailed)->sole();
    expect([$entry->subject_id, $entry->causer_id, $entry->organisation_id])->toBe([$member->id, null, $organisation->id])
        ->and($entry->properties?->all())->toBe(['method' => 'passkey']);
});

it('records a failed passkey sign-in without a user when the passkey names nobody this host may show (CD-6, R1)', function (string $owner): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $beta = Organisation::factory()->create(['slug' => 'beta']);
    $authenticator = new SoftwareAuthenticator;

    match ($owner) {
        'another organisation\'s member' => registerPasskeyFor(User::factory()->for($beta)->create(), $authenticator, passkeyHost('tenant', $beta)),
        'a super admin' => registerPasskeyFor(User::factory()->superAdmin()->create(), $authenticator, passkeyHost('admin', $acme)),
        // SetSuperAdmin, the flag's only writer, keeps the organisation_id of a promoted member (G4-01's premise).
        'a super admin promoted from this organisation' => registerPasskeyFor(
            tap(User::factory()->for($acme)->create(), fn (User $promoted) => app(SetSuperAdmin::class)->handle($promoted, true)),
            $authenticator,
            passkeyHost('admin', $acme),
        ),
        default => null, // 'an unknown passkey': nothing is registered
    };
    onTenant($acme);

    signInWithPasskey($authenticator, 'http://evil.localhost')->assertUnprocessable();

    $entry = recordedActivity(ActivityEvent::LoginFailed)->sole();
    expect([$entry->subject_id, $entry->organisation_id])->toBe([null, $acme->id])
        ->and($entry->getProperty('method'))->toBe('passkey');
})->with(['an unknown passkey', 'another organisation\'s member', 'a super admin', 'a super admin promoted from this organisation']);

it('records a failed passkey sign-in sent as a form post (CD-6)', function (): void {
    onTenant(Organisation::factory()->create(['slug' => 'acme']));
    /** @var array<string, mixed> $options */
    $options = getJson(route('passkey.login-options'))->assertOk()->json('options');

    post(route('passkey.login'), ['credential' => new SoftwareAuthenticator()->assert($options, 'http://acme.localhost')])
        ->assertRedirect()
        ->assertSessionHasErrors('credential');

    expect(recordedActivity(ActivityEvent::LoginFailed)->sole()->getProperty('method'))->toBe('passkey');
});

it('records a form post whose credential id is not valid UTF-8 without a user instead of failing (CD-6)', function (): void {
    onTenant(Organisation::factory()->create(['slug' => 'acme']));

    // PostgreSQL refuses these bytes in a bound parameter (SQLSTATE 22021); SQLite accepts them.
    post(route('passkey.login'), ['credential' => ['id' => 'x', 'rawId' => "\xC3\x28", 'type' => 'public-key', 'response' => ['clientDataJSON' => 'x']]])
        ->assertRedirect()
        ->assertSessionHasErrors('credential');

    expect(recordedActivity(ActivityEvent::LoginFailed)->sole()->subject_id)->toBeNull();
});
```

`clone $authenticator` copies the private counter (0 after registration). The original's sign-in stores counter 1, and the clone then presents 1 again, so `ThrowExceptionIfInvalid` throws `CounterException` (`1 > 1` is false). The existing 'does not report failed WebAuthn ceremonies (FU3-1)' test stays as it is and keeps passing.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Passkeys/PasskeysModuleTest.php tests/Feature/Settings/RecoveryCodesTest.php tests/Feature/Auth/PasskeyTest.php`

Expected: FAIL, as follows:
- The three G2-01 rows get 200 instead of 404. The module-on test already passes; it guards against an over-broad fix.
- The G2-07 download rows throw "The MAC is invalid." and "array_filter(): Argument #1 ($array) must be of type array, null given".
- The security page answers 500 with "Cannot assign null to property … $recoveryCodes"; 'offers to regenerate codes it cannot read' fails the same way on mount (with Step 4's reader but without the moved trigger it counts 1 instead of 2).
- `shouldReport(CounterException)` is false.
- The clone test finds no `warning` call.
- The five failed-sign-in tests find no `auth.login_failed` entry (`sole()` throws `ItemNotFoundException`).

- [ ] **Step 3: The security page's module guard (G2-01)**

`resources/views/pages/settings/security/security.php` (Task 1's version). Start `loadPasskeys()` with the guard:

```php
    public function loadPasskeys(): void
    {
        $this->ensurePasskeysEnabled();

        $this->passkeys = $this->currentUser()->passkeys()
```

Start `confirmDelete()` with it:

```php
    public function confirmDelete(int $passkeyId): void
    {
        $this->ensurePasskeysEnabled();

        $passkey = $this->currentUser()->passkeys()->findOrFail($passkeyId);
```

Start `deletePasskey()` with it, before the password confirmation:

```php
    public function deletePasskey(DeletePasskey $deletePasskey): void
    {
        $this->ensurePasskeysEnabled();

        if ($this->passwordConfirmationRequired()) {
            return;
        }
```

Add this private method as the last method of the class, after `disable()` (Pint's `ordered_class_elements` puts private methods last):

```php
    /**
     * §6.8 / G2-01: with the passkeys module off, no passkey action runs, whatever a Livewire update calls. Read
     * on every request, not from the snapshot: the module may have been turned off since the page loaded.
     */
    private function ensurePasskeysEnabled(): void
    {
        abort_unless(Features::canManagePasskeys(), 404);
    }
```

`Features` is already imported. `mount()` keeps calling `loadPasskeys()` only when `$this->canManagePasskeys` is true, so a module-off page never reaches the guard on mount.

- [ ] **Step 4: Recovery codes that cannot be read (G2-07)**

`resources/views/pages/settings/two-factor/recovery-codes/recovery-codes.php`: the imports become:

```php
use App\Concerns\ConfirmsPasswords;
use App\Concerns\ResolvesCurrentUser;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Encryption\DecryptException;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;
```

In `downloadRecoveryCodes()`, replace

```php
        // The stored codes, not this page's snapshot; the page then shows the same codes it downloads.
        $this->recoveryCodes = array_values(array_filter($user->recoveryCodes(), is_string(...)));
        $codes = implode(PHP_EOL, $this->recoveryCodes).PHP_EOL;
```

with

```php
        // The stored codes, not this page's snapshot; the page then shows the same codes it downloads.
        $stored = $this->storedRecoveryCodes($user);

        if ($stored === null) {
            $this->recoveryCodes = [];
            $this->addError('recoveryCodes', __('Failed to load recovery codes'));

            return null;
        }

        $this->recoveryCodes = $stored;
        $codes = implode(PHP_EOL, $this->recoveryCodes).PHP_EOL;
```

Replace `loadRecoveryCodes()` with these two methods:

```php
    /**
     * Load the recovery codes for the user.
     */
    private function loadRecoveryCodes(): void
    {
        $user = $this->currentUser();

        if (! $user->hasEnabledTwoFactorAuthentication() || ! $user->two_factor_recovery_codes) {
            return;
        }

        $stored = $this->storedRecoveryCodes($user);

        if ($stored === null) {
            $this->addError('recoveryCodes', __('Failed to load recovery codes'));
        }

        $this->recoveryCodes = $stored ?? [];
    }

    /**
     * The stored codes, or null when they cannot be read (G2-07): encrypted with another APP_KEY that
     * APP_PREVIOUS_KEYS does not list, or not a JSON list. Decrypted like Fortify's recoveryCodes().
     *
     * @return list<string>|null
     */
    private function storedRecoveryCodes(User $user): ?array
    {
        try {
            $decrypted = Fortify::currentEncrypter()->decrypt((string) $user->two_factor_recovery_codes);
        } catch (DecryptException) {
            return null;
        }

        $codes = is_string($decrypted) ? json_decode($decrypted, true) : null;

        return is_array($codes) ? array_values(array_filter($codes, is_string(...))) : null;
    }
```

The FU3-4 check before it (two-factor off, or a `null` column) stays as it is and keeps its own message.

`resources/views/pages/settings/two-factor/recovery-codes/recovery-codes.blade.php`: with unreadable codes `$recoveryCodes` is `[]`, and the 'Regenerate codes' trigger sits inside `@if (filled($recoveryCodes))`, so the remedy Task 14 documents ("regenerate them") would be gone. Replace the block from `@if (filled($recoveryCodes))` to its `@endif` (the Copy, Download and Regenerate buttons) with:

```blade
            <div x-show="showRecoveryCodes" class="flex flex-wrap gap-2">
                @if (filled($recoveryCodes))
                    <flux:button
                        icon="clipboard"
                        variant="filled"
                        x-data="{ copied: false, failed: false }"
                        x-on:click="navigator.clipboard.writeText(@js(implode(PHP_EOL, $recoveryCodes))).then(() => { copied = true; setTimeout(() => copied = false, 2000) }).catch(() => { failed = true; setTimeout(() => failed = false, 4000) })"
                    >
                        <span x-show="! copied && ! failed">{{ __('Copy') }}</span>
                        <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                        <span x-show="failed" x-cloak>{{ __('Copying failed; select the codes and copy them yourself.') }}</span>
                    </flux:button>

                    <flux:button icon="arrow-down-tray" variant="filled" wire:click="downloadRecoveryCodes">
                        {{ __('Download') }}
                    </flux:button>
                @endif

                {{-- G2-07: also when the stored codes cannot be read; this component renders only while 2FA is on. --}}
                <flux:modal.trigger name="confirm-regenerate-recovery-codes">
                    <flux:button icon="arrow-path" variant="filled">{{ __('Regenerate codes') }}</flux:button>
                </flux:modal.trigger>
            </div>
```

Copy and Download stay inside the `@if`; the wrapper keeps the buttons' layout. `regenerateRecoveryCodes()` only writes new codes, so it works whatever the stored value was.

- [ ] **Step 5: Report a cloned authenticator (G2-03 a, CD-6)**

`bootstrap/app.php`: add `use Illuminate\Support\Facades\Log;` and `use Webauthn\Exception\CounterException;`, so the end of the import block reads:

```php
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Webauthn\Exception\CounterException;
use Webauthn\Exception\WebauthnException;
```

In `withExceptions()`, replace

```php
        // FU3-1: a failed WebAuthn ceremony is a client error (rendered as a 422 below), not an incident to report.
        $exceptions->dontReport([TenantNotFoundException::class, InvitationNotFoundException::class, WebauthnException::class]);
```

with

```php
        $exceptions->dontReport([TenantNotFoundException::class, InvitationNotFoundException::class]);

        // FU3-1: a failed WebAuthn ceremony is a client error (rendered as a 422 below), not an incident to report.
        // CD-6: except a signature counter that did not increase, the sign of a cloned authenticator.
        $exceptions->dontReportWhen(fn (Throwable $exception): bool => $exception instanceof WebauthnException
            && ! $exception instanceof CounterException);

        // CD-6: one warning without a stack trace; the request, not the code, is what needs looking at. A credential
        // id is a public identifier, not a secret.
        $exceptions->report(function (CounterException $exception): void {
            $credentialId = request()->input('credential.rawId');

            Log::warning('A passkey was used with a signature counter that did not increase; the authenticator may have been cloned.', [
                'credential_id' => is_string($credentialId) ? $credentialId : null,
                'received_counter' => $exception->currentCounter,
                'stored_counter' => $exception->authenticatorCounter,
                'host' => request()->getHost(),
                'ip' => request()->ip(),
            ]);
        })->stop();
```

`CounterException::create($currentCounter, $authenticatorCounter, …)` is called by `ThrowExceptionIfInvalid` with the response's counter first and the stored record's counter second, hence the two names. The render callback below it (422 for every `WebauthnException`) is unchanged, so the client still gets "This passkey could not be verified."

- [ ] **Step 6: Record failed passkey sign-ins (G2-03 b, CD-6)**

Create `app/Listeners/RecordFailedPasskeySignIn.php`:

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Audit\RecordActivity;
use App\Enums\ActivityEvent;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Laravel\Passkeys\Passkeys;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records a failed passkey sign-in like a failed password sign-in (§4.8, CD-6): auth.login_failed with
 * method "passkey". laravel/passkeys fires no Failed event, so the outcome is read from the passkey.login
 * response: a 422 (a WebAuthn or validation failure, JSON) or a redirect that flashed errors (a form post).
 * A throttled request (429) is not a sign-in attempt. The entry names a user only when the posted credential
 * is one of their passkeys and, on a tenant host, they are the host's own member: never another organisation's
 * member or a super admin (R1). RequestHandled runs outside the kernel's exception handling, so a failure here is
 * reported and never replaces the response. Auto-discovered.
 */
final readonly class RecordFailedPasskeySignIn
{
    public function __construct(private RecordActivity $recordActivity) {}

    public function handle(RequestHandled $event): void
    {
        $request = $event->request;

        if (! $request->routeIs('passkey.login') || ! $this->failed($request, $event->response)) {
            return;
        }

        // An audit failure is reported, never answered instead of the response (a 500 for a refused sign-in).
        try {
            $this->recordActivity->handle(ActivityEvent::LoginFailed, $this->ownerOf($request), ['method' => 'passkey']);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * StartSession saved the session before this event and aged this request's flash data, so the errors it
     * flashed are still there, and errors flashed by an earlier request are gone.
     */
    private function failed(Request $request, Response $response): bool
    {
        return $response->getStatusCode() === 422
            || ($response->isRedirection() && $request->hasSession() && $request->session()->has('errors'));
    }

    private function ownerOf(Request $request): ?User
    {
        $rawId = $request->input('credential.rawId');

        // A credential id is base64url (stored unpadded); anything else, invalid UTF-8 included, names nobody and never
        // reaches the database: PostgreSQL refuses invalid UTF-8 (SQLSTATE 22021), and RequestHandled runs outside the
        // kernel's exception handling, so a throw here would turn the refused sign-in into a 500.
        if (! is_string($rawId) || preg_match('/^[A-Za-z0-9_-]{1,1400}={0,2}$/', $rawId) !== 1) {
            return null;
        }

        // The passkey model is App\Tenancy\Auth\UnscopedPasskey: its user is found on every host, deleted ones included.
        $user = Passkeys::passkeyModel()::query()->where('credential_id', rtrim($rawId, '='))->first()?->user;

        if (! $user instanceof User) {
            return null;
        }

        $organisation = Tenant::current();

        if ($organisation === null) {
            return $user;
        }

        // R1 (as G4-01): a host's log names only its own members, never a super admin, also not one promoted from
        // this organisation, who keeps its organisation_id.
        return $user->organisation_id === $organisation->id && ! $user->isSuperAdmin() ? $user : null;
    }
}
```

Laravel's event discovery registers it from the `handle()` type hint, as it does `RecordAuthenticationActivity`. No provider changes, so `FortifyServiceProvider` (Task 1's file) stays untouched. The listener reads the credential id only when it is a base64url string (`is_string()` and the pattern), so an array `credential.rawId` (G1-02's shape) or one with invalid UTF-8 records an entry without a user instead of failing, and anything else that fails while recording is reported, not answered. The passkey guest route has no signed-in user, so the entry has no causer, like a failed password sign-in.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Passkeys tests/Feature/Settings tests/Feature/Auth tests/Feature/Audit tests/Arch`

Expected: PASS.

- [ ] **Step 8: Verify by mutation**

Revert each change locally, run the named test, expect it to fail, then restore the change. Never use `git stash` (it is shared by every worktree): copy the file aside first (`cp <file> <scratchpad>/<name>.bak`), edit it, and restore it with `cp` afterwards.
1. Empty the body of `ensurePasskeysEnabled()`. Expect all three rows of 'refuses the security page's passkey actions while the module is off…' to fail with 200.
2. In `storedRecoveryCodes()`, replace the `try`/`catch` with a bare `$decrypted = Fortify::currentEncrypter()->decrypt(...)`. Expect the 'encrypted with another key' row to fail with "The MAC is invalid."
3. Drop `->stop()` from the `CounterException` report callback. Expect 'refuses a cloned authenticator…' to fail on `shouldNotHaveReceived('error')`.
4. In `RecordFailedPasskeySignIn::ownerOf()`, return `$user` without the tenant comparison. Expect the 'another organisation's member', 'a super admin' and 'a super admin promoted from this organisation' rows to fail on `[1, 1]`.
5. In `RecordFailedPasskeySignIn::failed()`, keep only the 422 comparison. Expect 'records a failed passkey sign-in sent as a form post' to fail.
6. In `RecordFailedPasskeySignIn::ownerOf()`, drop `&& ! $user->isSuperAdmin()`. Expect the 'a super admin promoted from this organisation' row to fail on `[<promoted id>, <acme id>]`.
7. In `RecordFailedPasskeySignIn::ownerOf()`, drop the `preg_match(...)` conjunct (keep `! is_string($rawId)`). Under `composer test:pgsql`, expect 'records a form post whose credential id is not valid UTF-8…' to fail: the lookup throws SQLSTATE 22021, `handle()` reports it and writes no entry, so `sole()` throws. (On SQLite it passes; that is why the guard exists.)
8. In `recovery-codes.blade.php`, move the 'Regenerate codes' trigger back inside the `@if (filled($recoveryCodes))`. Expect 'offers to regenerate codes it cannot read' to fail with 1 instead of 2.

- [ ] **Step 9: CHANGELOG**

Under `## [Unreleased]`, `### Changed`, append:

```markdown
- A failed passkey sign-in is recorded in the audit log like a failed password sign-in (*Sign-in failed*, with `method: passkey`); it names the account only when the passkey belongs to a member of the host's organisation, or on the central hosts.
- A passkey whose signature counter did not increase (a sign of a cloned authenticator) is refused as before and now also writes one warning to the log.
```

Under `### Fixed`, append:

```markdown
- With the passkeys module off, the security page refuses its passkey actions (404) even when a Livewire request calls them directly.
- Recovery codes that can no longer be decrypted (after an `APP_KEY` change without `APP_PREVIOUS_KEYS`) show "Failed to load recovery codes" instead of a server error, on the security page and on download, and "Regenerate codes" stays available to replace them.
- A failed passkey sign-in never names a super admin in an organisation's audit log, also not one promoted from that organisation, and a form post with a malformed credential id no longer answers with a server error.
```

No lang keys: "Failed to load recovery codes" already exists in both files, and the log line is not translated.

- [ ] **Step 10: The gate, then commit**

```bash
composer fix
vendor/bin/phpstan analyse tests/Feature/Auth/PasskeyTest.php
composer check
composer test:pgsql
php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser
npm run build && composer test:browser
npm run build:setup && git diff --exit-code resources/setup/setup.css
php artisan config:clear
```

Run the `phpstan` line before `composer check`: the Pest prototypes never ran Larastan, and a `match` over a dataset's `string` needs its `default =>` arm (`match.unhandled`), as `passkeyHost()` has. Task 3 changes no setup view, so expect no stylesheet diff.

```bash
git add app/Listeners/RecordFailedPasskeySignIn.php bootstrap/app.php \
    resources/views/pages/settings/security/security.php \
    resources/views/pages/settings/two-factor/recovery-codes/recovery-codes.php \
    resources/views/pages/settings/two-factor/recovery-codes/recovery-codes.blade.php CHANGELOG.md \
    tests/Feature/Passkeys/PasskeysModuleTest.php tests/Feature/Settings/RecoveryCodesTest.php tests/Feature/Auth/PasskeyTest.php
git commit -m "fix: guard the security page's passkey actions, read recovery codes safely and trace failed passkey sign-ins

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

---

### Task 4: Language and appearance switches (G2-02 for `LocaleController`, G2-05, G3-07; CD-13)

**Files:**
- Create:
  - `app/Http/Support/ReturnUrl.php`
  - `tests/Feature/Support/ReturnUrlTest.php`
  - `tests/Feature/Impersonation/ImpersonationPreferencesTest.php`
  - `tests/Browser/LanguageSwitchTest.php`
- Modify:
  - `app/Http/Controllers/LocaleController.php` (`back()` → `ReturnUrl::previous()`)
  - `app/Tenancy/Auth/Impersonation.php` (`LOCALE_KEY`, `APPEARANCE_KEY`, `isTarget()`, `rememberPreference()`, `localeFor()`, `appearanceFor()`; `clear()` forgets the two new keys)
  - `app/Actions/Settings/UpdateLocale.php`, `app/Actions/Settings/UpdateAppearance.php` (during an impersonation of `$user`, keep the choice in the session)
  - `app/Support/Locales.php` (`resolve()`'s user branch reads `Impersonation::localeFor($user)`)
  - `resources/views/layouts/base.blade.php` (the `$appearance` line reads `Impersonation::appearanceFor($user)`)
  - `resources/views/components/user-menu/user-menu.php` (no `$returnPath`; `setLocale()` is `#[Renderless]` and reloads in the browser)
  - `resources/views/components/user-menu/user-menu.blade.php` (the radio's `:checked` follows the page's language; a `data-test` per language)
  - `CHANGELOG.md`
- Test:
  - `tests/Feature/Support/ReturnUrlTest.php`, `tests/Feature/Impersonation/ImpersonationPreferencesTest.php` (new)
  - `tests/Feature/Localisation/SetLocaleTest.php` (G2-02)
  - `tests/Feature/Navigation/UserMenuTest.php` (two tests rewritten for G3-07)
  - `tests/Browser/LanguageSwitchTest.php` (new, G3-07)

**Interfaces:**
- Consumes:
  - `Impersonation::isActive()`, `Impersonation::targetId()`, `Impersonation::clear()` (called by `leave()` and `signOut()`)
  - `Locale::tryFrom()`, `Appearance::tryFrom()`; `User::$locale` (cast to `Locale`) and `User::$appearance` (cast to `Appearance`)
  - Livewire 4's `Component::js(string $expression)` (an `effects.xjs` entry `['expression' => …, 'params' => []]`) and `#[Renderless]`
  - test helpers: `useImpersonationModule()`, `startImpersonatingOverHttp()`, `onTenant()`, `asMember()`, `asOrgAdmin()`, `pageSnapshot()`, `callPageAction()`, `browserUser()`, `sameOrigin()`
- Produces:
  - `App\Http\Support\ReturnUrl` (final, static):
    - `ReturnUrl::previous(Illuminate\Http\Request $request, string $fallback = '/'): string`: a root-relative path with its query string (for example `/dashboard?page=2`), never an absolute URL. It tries the `Referer` header first, then the session's previous URL (`$request->session()->previousUrl()`, read only when `$request->hasSession()`), in `url()->previous()`'s order. It returns the first of the two that is on this request's host. Otherwise it returns `'/'.ltrim($fallback, '/')`. It never throws on a request without a session (an error page rendered before `StartSession`, a 503 in maintenance mode). Task 6 uses it for the 403 page's Back link.
    - `ReturnUrl::pathOnThisHost(Illuminate\Http\Request $request, string $url): ?string`: `$url`'s path and query (the fragment dropped, leading slashes collapsed to one) when `$url` is a root-relative path, or an `http`/`https` URL whose host equals `$request->getHost()` case-insensitively (any port). It returns null for anything else: another host, a protocol-relative URL, a relative path, another scheme, or a backslash, space or control character anywhere.
  - `Impersonation::LOCALE_KEY = 'impersonation.locale'`, `Impersonation::APPEARANCE_KEY = 'impersonation.appearance'` (public string constants)
  - `Impersonation::isTarget(User $user): bool`: `isActive() && targetId() === $user->id`
  - `Impersonation::rememberPreference(Locale|Appearance $choice): void`
  - `Impersonation::localeFor(User $user): Locale` and `Impersonation::appearanceFor(User $user): Appearance`: the session choice while this session impersonates `$user`, else the account's value
  - `Locales::resolve()` (private) now returns `Impersonation::localeFor($user)` for a user, instead of `$user->locale`. `forRequest()`, `forErrorPage()` and every later caller of `resolve()` (Task 5's `forNextPage()`) therefore answer an impersonation in the language chosen during it. Their signatures are unchanged.
  - The user menu has no `returnPath` property. `setLocale(string $locale, UpdateLocale $updateLocale): void` answers with `effects.xjs.0.expression = 'window.location.reload()'` and no `effects.redirect`. Each language radio carries `data-test="user-menu-locale-<code>"`.

Background for the implementer:
- **G2-02:** `LocaleController` ends with `back()`, which is `url()->previous()`. That is the `Referer` when present, kept as an absolute URL, so a `Referer: https://evil.example/x` sends the browser there. `ReturnUrl` returns only a root-relative path on the current host, so the browser cannot leave the origin it is on. Rebuilding from the path also defeats `user@host` and port tricks. Backslashes and control characters are refused because browsers read `/\host` and `/<TAB>/host` as `//host`.
- **CD-13:** the vendor paths stay. That covers Laravel's validation redirect (also `LocaleController`'s own `$request->validate()`), Fortify's and laravel/passkeys' `back()` responses, and `FortifyServiceProvider::loginThrottled()` (Task 1's file).
- **G2-05:** `UpdateLocale` and `UpdateAppearance` write `currentUser()`, which during an impersonation is the target. So an impersonator's language or theme click silently changes the target's account. The fix lives in the two actions, so every caller is covered unchanged: the user menu, `LocaleController` and the profile page. The display reads the session choice back through `Impersonation::localeFor()` and `appearanceFor()`. Both keys live under `impersonation.*` and `clear()` forgets them, so they end with the impersonation. A new impersonation never inherits them, because `leave()` and `signOut()` both call `clear()`.
- **G3-07:** the user menu captured `request()->path()` in `mount()`, which drops the query string and every `#[Url]` filter changed after the page loaded (the users, organisations, roles, activity and pending pages). Now the server only saves the choice and asks the browser to reload its own URL, query string included. The server names no redirect target at all.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Support/ReturnUrlTest.php` (new):

```php
<?php

declare(strict_types=1);

/** covers §6.5 */

use App\Http\Support\ReturnUrl;
use Illuminate\Http\Request;

/** A request to acme's host with the given Referer and, when $previous is given, a started session whose previous URL it is. */
$requestOnAcme = function (?string $referer = null, ?string $previous = null): Request {
    $request = Request::create('http://acme.localhost/locale', 'POST', server: $referer === null ? [] : ['HTTP_REFERER' => $referer]);

    if ($previous !== null) {
        $session = app('session')->driver();
        $session->setPreviousUrl($previous);
        $request->setLaravelSession($session);
    }

    return $request;
};

it('keeps a page on this host as a root-relative path with its query (G2-02)', function (string $url, string $expected) use ($requestOnAcme): void {
    expect(ReturnUrl::pathOnThisHost($requestOnAcme(), $url))->toBe($expected);
})->with([
    'an absolute URL with its query' => ['http://acme.localhost/users?search=x&page=2', '/users?search=x&page=2'],
    'a root-relative path' => ['/dashboard?page=2', '/dashboard?page=2'],
    'the host in capitals' => ['HTTP://ACME.LOCALHOST/users', '/users'],
    'another port and scheme' => ['https://acme.localhost:8443/users', '/users'],
    'no path' => ['http://acme.localhost', '/'],
    'a fragment' => ['http://acme.localhost/users#top', '/users'],
    'doubled slashes after this host' => ['http://acme.localhost//evil.example/x', '/evil.example/x'],
]);

it('refuses a page on any other host, or anything that is no http(s) URL or root-relative path (G2-02)', function (string $url) use ($requestOnAcme): void {
    expect(ReturnUrl::pathOnThisHost($requestOnAcme(), $url))->toBeNull();
})->with([
    'another site' => ['https://evil.example/phish'],
    'another organisation' => ['http://beta.localhost/users'],
    'the admin host' => ['http://admin.localhost/users'],
    'a protocol-relative URL' => ['//evil.example/phish'],
    'a backslash path' => ['/\\evil.example/phish'],
    'a tab a browser drops' => ["/\t/evil.example/phish"],
    'credentials before another host' => ['http://acme.localhost@evil.example/'],
    'a javascript: URL' => ['javascript:alert(1)'],
    'another scheme on this host' => ['ftp://acme.localhost/file'],
    'a relative path' => ['users'],
    'nothing' => [''],
]);

it('goes back to the Referer, else the session\'s previous page, else the fallback, on this host only (G2-02)', function (?string $referer, ?string $previous, string $expected) use ($requestOnAcme): void {
    expect(ReturnUrl::previous($requestOnAcme($referer, $previous)))->toBe($expected);
})->with([
    'the Referer' => ['http://acme.localhost/users?search=x', 'http://acme.localhost/dashboard', '/users?search=x'],
    'a foreign Referer, then the previous page' => ['https://evil.example/phish', 'http://acme.localhost/dashboard?page=2', '/dashboard?page=2'],
    'no Referer, the previous page' => [null, 'http://acme.localhost/dashboard', '/dashboard'],
    'nothing on this host' => ['https://evil.example/phish', null, '/'],
]);

it('answers without a session and falls back to the path it is given (G2-02)', function () use ($requestOnAcme): void {
    $request = $requestOnAcme('https://evil.example/phish');

    expect($request->hasSession())->toBeFalse()
        ->and(ReturnUrl::previous($request, '/login'))->toBe('/login');
});
```

`tests/Feature/Localisation/SetLocaleTest.php`: append. The file's `beforeEach` puts every test on acme's host.

```php
it('goes back after a language switch only to a page on this host (G2-02)', function (string $referer, string $expected): void {
    $this->withHeader('Referer', $referer)
        ->post(route('locale.update'), ['locale' => 'nl'])
        ->assertRedirect($expected);
})->with([
    'another site' => ['https://evil.example/phish', '/'],
    'another organisation' => ['http://beta.localhost/login', '/'],
    'a protocol-relative URL' => ['//evil.example/phish', '/'],
    'this host, query included' => ['http://acme.localhost/login?next=1', '/login?next=1'],
]);
```

`tests/Feature/Impersonation/ImpersonationPreferencesTest.php` (new):

```php
<?php

declare(strict_types=1);

/** covers §4.7, §4.11 */

use App\Authorization\Role;
use App\Enums\Appearance;
use App\Enums\Locale;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Auth\Impersonation;
use Illuminate\Support\Facades\Auth;

beforeEach(function (): void {
    useImpersonationModule(true);
});

/**
 * Starts acme's organisation admin impersonating a member over HTTP; both are Dutch with the system appearance.
 *
 * @return array{0: User, 1: User} the admin and the member
 */
$impersonating = function (): array {
    $organisation = onTenant(Organisation::factory()->create(['slug' => 'acme']));
    $preferences = ['locale' => Locale::Dutch, 'appearance' => Appearance::System];
    $admin = User::factory()->for($organisation)->create($preferences);
    withPermissionsTeam($organisation->id, fn (): User => $admin->assignRole(Role::OrganisationAdmin->value));
    $admin->unsetRelation('roles');
    $member = User::factory()->for($organisation)->create($preferences);
    startImpersonatingOverHttp($admin, $member);

    return [$admin, $member];
};

it('keeps a language chosen in the user menu while impersonating off the target\'s account (G2-05)', function () use ($impersonating): void {
    [$admin, $member] = $impersonating();

    callPageAction(pageSnapshot(route('dashboard'), 'user-menu'), 'setLocale', ['en'])->assertOk();

    expect($member->fresh()?->locale)->toBe(Locale::Dutch)
        ->and($admin->fresh()?->locale)->toBe(Locale::Dutch);
    $html = (string) $this->get(route('dashboard'))->assertOk()->assertSee('<html lang="en"', false)->getContent();
    expect($html)->toMatch('/<ui-menu-radio[^>]*value="en"[^>]*checked/');
});

it('keeps a language posted to the language toggle while impersonating off the target\'s account (G2-05)', function () use ($impersonating): void {
    [, $member] = $impersonating();

    $this->post(route('locale.update'), ['locale' => 'en'])->assertRedirect();

    expect($member->fresh()?->locale)->toBe(Locale::Dutch);
    $this->get(route('dashboard'))->assertOk()->assertSee('<html lang="en"', false);
});

it('keeps an appearance chosen while impersonating off the target\'s account (G2-05)', function () use ($impersonating): void {
    [, $member] = $impersonating();

    callPageAction(pageSnapshot(route('dashboard'), 'user-menu'), 'saveAppearance', ['dark'])->assertOk();

    expect($member->fresh()?->appearance)->toBe(Appearance::System)
        ->and((string) $this->get(route('dashboard'))->assertOk()->getContent())->toMatch('/<html[^>]*class="dark"/');
});

it('forgets the language and appearance chosen during an impersonation when it stops (G2-05)', function () use ($impersonating): void {
    [$admin] = $impersonating();
    callPageAction(pageSnapshot(route('dashboard'), 'user-menu'), 'setLocale', ['en'])->assertOk();
    callPageAction(pageSnapshot(route('dashboard'), 'user-menu'), 'saveAppearance', ['dark'])->assertOk();

    $this->delete(route('impersonation.stop'))->assertRedirect(route('users.index'));
    Auth::guard('web')->forgetUser();

    $html = (string) $this->get(route('users.index'))->assertOk()
        ->assertSessionMissing(Impersonation::LOCALE_KEY)
        ->assertSessionMissing(Impersonation::APPEARANCE_KEY)
        ->assertSee('<html lang="nl"', false)
        ->getContent();
    expect($html)->not->toMatch('/<html[^>]*class="dark"/');
    $this->assertAuthenticatedAs($admin);
});
```

`tests/Feature/Navigation/UserMenuTest.php`: remove the import `use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;`. Then rewrite the two tests about the stored return path. They are rewritten, not deleted: the guarantees they pinned (never the Referer, nothing a client can set) still hold, now without a stored path. Replace

```php
it('changes the language and reloads the page it was opened on, never the Referer (RL-7)', function (): void {
    $user = asOrgAdmin(Organisation::factory()->create(['slug' => 'acme']));
    $this->withHeader('Referer', 'https://evil.example/phish');
    $snapshot = pageSnapshot(route('users.index'), 'user-menu');

    callPageAction($snapshot, 'setLocale', ['nl'])->assertOk()
        ->assertJsonPath('components.0.effects.redirect', '/users');

    expect($user->fresh()?->locale)->toBe(Locale::Dutch);
});

it('keeps the return path locked against the client', function (): void {
    asMember(Organisation::factory()->create(['slug' => 'acme']));

    Livewire::test('user-menu', ['variant' => 'sidebar'])->set('returnPath', 'https://evil.example');
})->throws(CannotUpdateLockedPropertyException::class);
```

with

```php
it('changes the language and lets the browser reload the page it shows, never a redirect to a stored path or the Referer (G3-07, RL-7)', function (): void {
    $user = asOrgAdmin(Organisation::factory()->create(['slug' => 'acme']));
    $this->withHeader('Referer', 'https://evil.example/phish');
    $snapshot = pageSnapshot(route('users.index', ['search' => 'x']), 'user-menu');

    callPageAction($snapshot, 'setLocale', ['nl'])->assertOk()
        ->assertJsonMissingPath('components.0.effects.redirect')
        ->assertJsonPath('components.0.effects.xjs.0.expression', 'window.location.reload()');

    expect($user->fresh()?->locale)->toBe(Locale::Dutch);
});

it('keeps no return path in its state for a client to change (G3-07)', function (): void {
    asMember(Organisation::factory()->create(['slug' => 'acme']));

    expect(pageSnapshot(route('dashboard'), 'user-menu')['data'])->not->toHaveKey('returnPath');
});
```

`Livewire` stays imported: 'refuses an unknown language' still uses `Livewire::test()`.

`tests/Browser/LanguageSwitchTest.php` (new):

```php
<?php

declare(strict_types=1);

/** covers §4.11, §6.5, §7.2 */

use App\Authorization\Role;
use App\Enums\ActivityEvent;
use App\Enums\Locale;
use App\Models\Organisation;
use App\Models\User;

it('switches the language from the user menu and keeps the filters chosen on the page (G3-07)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = browserUser($organisation, 'admin@example.com');
    $admin->forceFill(['locale' => Locale::Dutch])->save();
    withPermissionsTeam($organisation->id, fn (): User => $admin->assignRole(Role::OrganisationAdmin->value));

    $page = visit('/login')->withHost('acme.localhost')
        ->fill('email', $admin->email)->fill('password', 'password')->press('@login-button')
        ->assertPathIs('/dashboard');

    // The filter changes after the page loaded: only the browser's own URL still knows it.
    $page->navigate(sameOrigin($page->url(), '/activity'))
        ->assertSee('Activiteitenlog')
        ->select('event', ActivityEvent::Login->value)
        ->assertQueryStringHas('event', ActivityEvent::Login->value)
        ->click('@sidebar-menu-button')
        ->click('@user-menu-locale-en')
        ->assertSee('Activity log')
        ->assertPathIs('/activity')
        ->assertQueryStringHas('event', ActivityEvent::Login->value)
        ->assertNoJavaScriptErrors();

    expect($admin->fresh()?->locale)->toBe(Locale::English);
});
```

The headings are literal strings on purpose: `__()` in the test process does not follow the browser's language. `assertSee()` waits for visible text, so it also waits for the reload. Option labels inside a closed `<select>` do not count as visible, which is why the test checks the heading.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Support/ReturnUrlTest.php tests/Feature/Localisation/SetLocaleTest.php tests/Feature/Impersonation/ImpersonationPreferencesTest.php tests/Feature/Navigation/UserMenuTest.php`, then `composer test:browser -- --filter=LanguageSwitch` (after `npm run build`)

Expected: FAIL, as follows:
- Every `ReturnUrlTest` case fails with 'Class "App\Http\Support\ReturnUrl" not found'.
- Three rows of the G2-02 `SetLocaleTest` case redirect to `https://evil.example/phish`, `http://beta.localhost/login` and `//evil.example/phish`. The same-host row already passes.
- The three "off the target's account" tests find the target's locale `en` or appearance `dark`.
- The "forgets" test fails with "Undefined constant App\Tenancy\Auth\Impersonation::LOCALE_KEY".
- The rewritten user-menu tests find `effects.redirect` (`/users`) and a `returnPath` key.
- The browser test finds no `@user-menu-locale-en`. With the attribute alone it fails on `assertQueryStringHas`, because the old redirect drops `?event=`.

- [ ] **Step 3: `ReturnUrl` and the language toggle (G2-02, CD-13)**

Create `app/Http/Support/ReturnUrl.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Where a "go back" may lead (§6.5, G2-02, CD-13): only to a page on the request's own host, returned as a
 * root-relative path with its query, so the browser stays on the origin it is on and neither the Referer nor the
 * session can send a visitor to another site, another organisation's host or a javascript: URL. The kit's own back
 * links use it; Laravel's, Fortify's and laravel/passkeys' back() on CSRF-protected POSTs stay as they are (CD-13).
 */
final class ReturnUrl
{
    /**
     * The page to go back to, as url()->previous() picks it (the Referer, else the session's previous URL), but only
     * the first of the two that is on this host; else $fallback. Works without a started session (an error page
     * rendered before StartSession, a 503 in maintenance mode).
     */
    public static function previous(Request $request, string $fallback = '/'): string
    {
        $candidates = [
            $request->headers->get('referer'),
            $request->hasSession() ? $request->session()->previousUrl() : null,
        ];

        foreach ($candidates as $candidate) {
            $path = is_string($candidate) ? self::pathOnThisHost($request, $candidate) : null;

            if ($path !== null) {
                return $path;
            }
        }

        return '/'.ltrim($fallback, '/');
    }

    /**
     * The root-relative path and query of $url (the fragment dropped) when $url is a root-relative path or an
     * http(s) URL naming this host; null for anything else: another host, a protocol-relative URL, a relative
     * path, another scheme, or a backslash, space or control character, which browsers may read as "//".
     */
    public static function pathOnThisHost(Request $request, string $url): ?string
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        if (isset($parts['host'])) {
            $scheme = Str::lower($parts['scheme'] ?? '');

            if (! in_array($scheme, ['http', 'https'], true) || Str::lower($parts['host']) !== Str::lower($request->getHost())) {
                return null;
            }
        } elseif (isset($parts['scheme']) || ! str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return null;
        }

        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return '/'.ltrim($parts['path'] ?? '', '/').$query;
    }
}
```

`app/Http/Controllers/LocaleController.php`: add `use App\Http\Support\ReturnUrl;` after `use App\Enums\Locale;`, replace the class docblock with

```php
/**
 * The language toggle (§6.5): a guest's choice lives in the session, a user's on the account (during an impersonation
 * in the session, UpdateLocale). It goes back to the page it came from only when that page is on this host (G2-02).
 */
```

and replace `return back();` with

```php
        return redirect()->to(ReturnUrl::previous($request));
```

`redirect()->to()` makes the path absolute on the request's own root, so `assertRedirect(route('login'))` in the existing tests and the invitation page's `assertRedirect($link)` (`AcceptInvitationTest`, 'lets a guest's own language choice win…') still hold.

- [ ] **Step 4: Preferences during an impersonation (G2-05)**

`app/Tenancy/Auth/Impersonation.php`: add `use App\Enums\Appearance;` and `use App\Enums\Locale;` before `use App\Models\User;`. After the `IMPERSONATOR_HASH_KEY` constant add:

```php
    /** The language chosen during the impersonation (G2-05): it lasts as long as the impersonation, never on the target's account. */
    public const string LOCALE_KEY = 'impersonation.locale';

    /** The appearance chosen during the impersonation (G2-05), kept like LOCALE_KEY. */
    public const string APPEARANCE_KEY = 'impersonation.appearance';
```

Right before `impersonatorPasswordUnchanged()`, add:

```php
    /** Whether this session is impersonating $user. */
    public static function isTarget(User $user): bool
    {
        return self::isActive() && self::targetId() === $user->id;
    }

    /**
     * Keeps a language or appearance chosen during the impersonation in the session (G2-05): UpdateLocale and
     * UpdateAppearance call it instead of writing the impersonated user's account.
     */
    public static function rememberPreference(Locale|Appearance $choice): void
    {
        Session::put($choice instanceof Locale ? self::LOCALE_KEY : self::APPEARANCE_KEY, $choice->value);
    }

    /** The language $user sees: while this session impersonates them, the one chosen during it, else their own (G2-05). */
    public static function localeFor(User $user): Locale
    {
        $chosen = self::isTarget($user) ? Locale::tryFrom((string) Session::get(self::LOCALE_KEY)) : null;

        return $chosen ?? $user->locale;
    }

    /** The appearance $user sees, decided like localeFor() (G2-05). */
    public static function appearanceFor(User $user): Appearance
    {
        $chosen = self::isTarget($user) ? Appearance::tryFrom((string) Session::get(self::APPEARANCE_KEY)) : null;

        return $chosen ?? $user->appearance;
    }
```

Replace `clear()`:

```php
    /** Forget the impersonation keys and the preferences chosen during it; the guard's login key is left as it is. */
    public static function clear(): void
    {
        Session::forget([self::SESSION_KEY, self::IMPERSONATOR_HASH_KEY, self::LOCALE_KEY, self::APPEARANCE_KEY]);
    }
```

`app/Actions/Settings/UpdateLocale.php`, whole file:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Enums\Locale;
use App\Models\User;
use App\Tenancy\Auth\Impersonation;

/**
 * Stores the user's language (§4.11). A display preference: not audited (RL-7). While this session impersonates
 * the user, the choice stays in the session for the impersonation and their account keeps its own (G2-05).
 */
final class UpdateLocale
{
    public function handle(User $user, Locale $locale): void
    {
        if (Impersonation::isTarget($user)) {
            Impersonation::rememberPreference($locale);

            return;
        }

        $user->forceFill(['locale' => $locale])->saveQuietly();
    }
}
```

`app/Actions/Settings/UpdateAppearance.php`, whole file:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Enums\Appearance;
use App\Models\User;
use App\Tenancy\Auth\Impersonation;

/**
 * Stores the user's appearance (DEC-12). A display preference: not audited (RL-7). While this session impersonates
 * the user, the choice stays in the session for the impersonation and their account keeps its own (G2-05).
 */
final class UpdateAppearance
{
    public function handle(User $user, Appearance $appearance): void
    {
        if (Impersonation::isTarget($user)) {
            Impersonation::rememberPreference($appearance);

            return;
        }

        $user->forceFill(['appearance' => $appearance])->saveQuietly();
    }
}
```

`app/Support/Locales.php`: add `use App\Tenancy\Auth\Impersonation;` after `use App\Models\User;`. Replace `forRequest()`'s docblock with

```php
    /**
     * The signed-in user's language (during an impersonation the one chosen in it, G2-05), else the guest's choice, else
     * the browser's preference, else the default.
     */
```

In `resolve()`, replace

```php
        if ($user instanceof User) {
            return $user->locale;
        }
```

with

```php
        if ($user instanceof User) {
            return Impersonation::localeFor($user);
        }
```

`Impersonation::isActive()` reads the session through the facade. On an error page rendered before `StartSession`, the store is not started and holds nothing, so `forErrorPage()` keeps the account's language there.

`resources/views/layouts/base.blade.php`: in the `@php` block, replace

```php
    $appearance = $user instanceof \App\Models\User ? $user->appearance : null;
```

with

```php
    // G2-05: during an impersonation, the appearance chosen in it.
    $appearance = $user instanceof \App\Models\User ? \App\Tenancy\Auth\Impersonation::appearanceFor($user) : null;
```

The `$storedAppearance` line under it and the DEC-12 `localStorage` script stay as they are. The page writes the effective appearance first, so the impersonator's choice holds on every page of the impersonation, and the target's own value comes back after the stop.

- [ ] **Step 5: The user menu reloads in the browser (G3-07)**

`resources/views/components/user-menu/user-menu.php`: replace the `$returnPath` property, `mount()` and `setLocale()` with:

```php
    public function mount(string $variant = 'sidebar'): void
    {
        $this->variant = $variant === 'header' ? 'header' : 'sidebar';
    }

    /**
     * Switch the language, then let the browser reload the page it shows, so every string follows (§4.11). The
     * browser's own URL keeps the query string and the filters changed since the page loaded (G3-07); the server
     * names no target, so nothing a client sends can redirect elsewhere (RL-7).
     */
    #[Renderless]
    public function setLocale(string $locale, UpdateLocale $updateLocale): void
    {
        $choice = Locale::tryFrom($locale)
            ?? throw ValidationException::withMessages(['locale' => __('Choose a supported language.')]);

        $updateLocale->handle($this->currentUser(), $choice);

        $this->js('window.location.reload()');
    }
```

The imports stay: `Locked` is still used by `$variant`, and `Renderless` by `saveAppearance()`. `#[Renderless]` skips a re-render in the old language right before the reload. A refused language still reports its error, as 'refuses an unknown language' checks.

`resources/views/components/user-menu/user-menu.blade.php`: replace the radio line

```blade
                    <flux:menu.radio :value="$locale->value" :checked="$user->locale === $locale" wire:click="setLocale('{{ $locale->value }}')">
```

with

```blade
                    <flux:menu.radio :value="$locale->value" :checked="app()->getLocale() === $locale->value" data-test="user-menu-locale-{{ $locale->value }}" wire:click="setLocale('{{ $locale->value }}')">
```

`SetLocale` applies `Locales::forRequest()` on every web request, so `app()->getLocale()` is the account's language for a signed-in user, and during an impersonation it is the language chosen in it.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Support/ReturnUrlTest.php tests/Feature/Localisation tests/Feature/Impersonation tests/Feature/Navigation tests/Feature/Settings tests/Feature/Invitations/AcceptInvitationTest.php tests/Feature/Users/LocaleTest.php`, then `npm run build && composer test:browser -- --filter=LanguageSwitch`

Expected: PASS.

- [ ] **Step 7: Verify by mutation**

Revert each change locally, run the named test, expect it to fail, then restore the change (run `php artisan view:clear` after a Blade mutation). Never use `git stash` (it is shared by every worktree): copy the file aside first (`cp <file> <scratchpad>/<name>.bak`), edit it, and restore it with `cp` afterwards.
1. In `ReturnUrl::pathOnThisHost()`, drop the `preg_match(...)` half of the first guard. Expect the 'a backslash path' and 'a tab a browser drops' rows to fail.
2. In `ReturnUrl::previous()`, return the first `pathOnThisHost()` result without trying the session (`return self::pathOnThisHost(...) ?? '/'.ltrim($fallback, '/')` on the Referer alone). Expect 'a foreign Referer, then the previous page' and 'no Referer, the previous page' to fail.
3. In `Impersonation::clear()`, drop `self::LOCALE_KEY, self::APPEARANCE_KEY`. Expect the "forgets" test to fail with "Session has unexpected key [impersonation.locale]".
4. In `Locales::resolve()`, return `$user->locale` again. Expect the two language tests of `ImpersonationPreferencesTest` to fail on `<html lang="en"`.
5. In `layouts/base.blade.php`, read `$user->appearance` again. Expect 'keeps an appearance chosen while impersonating…' to fail on `class="dark"`.
6. In `user-menu.blade.php`, put back `:checked="$user->locale === $locale"`. Expect 'keeps a language chosen in the user menu…' to fail on the `ui-menu-radio` match.
7. In `setLocale()`, replace `$this->js('window.location.reload()');` with `$this->redirect('/'.ltrim(request()->path(), '/'));`. Expect the rewritten user-menu test and the browser test to fail.

- [ ] **Step 8: CHANGELOG**

Under `## [Unreleased]`, `### Changed`, append:

```markdown
- While you impersonate someone, a language or appearance you pick lasts until the impersonation ends and never changes their account.
- The language switch in the user menu reloads the page you are on, query string and filters included.
```

Under `### Fixed`, append:

```markdown
- The language toggle goes back only to a page on the same host; a `Referer` from another site or another organisation's address leads to the home page instead.
```

No lang keys: this task adds no copy.

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

`ImpersonationPreferencesTest` calls `useImpersonationModule(true)`, so it also passes in the modules-off run. Task 4 changes no setup view, so expect no stylesheet diff.

```bash
git add app/Http/Support/ReturnUrl.php app/Http/Controllers/LocaleController.php app/Tenancy/Auth/Impersonation.php \
    app/Actions/Settings/UpdateLocale.php app/Actions/Settings/UpdateAppearance.php app/Support/Locales.php \
    resources/views/layouts/base.blade.php resources/views/components/user-menu/user-menu.php \
    resources/views/components/user-menu/user-menu.blade.php CHANGELOG.md \
    tests/Feature/Support/ReturnUrlTest.php tests/Feature/Localisation/SetLocaleTest.php \
    tests/Feature/Impersonation/ImpersonationPreferencesTest.php tests/Feature/Navigation/UserMenuTest.php \
    tests/Browser/LanguageSwitchTest.php
git commit -m "fix: keep return targets on the host, preferences off impersonated accounts and the query string on a language switch

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

---

### Task 5: Locale of flashed text and mails (G3-01, G3-02, G3-03, G3-06)

Every string a user reads after a redirect or in a mail is worded in the language of the page or the account that shows it:
- **G3-01:** `VerifyTenantMembership` and `EnforceImpersonation` run before `SetLocale` (`bootstrap/app.php` appends `SetLocale` last), so their flashed status is translated in `app.locale`. They now translate it once the user switch is done, for the next page's reader.
- **G3-02:** `ImpersonationController::destroy()` runs after `SetLocale` applied the *target's* language. "You are back in your own account." now uses the impersonator's language.
- **G3-03:** the three on-demand notifications (`Notification::route('mail', …)`) have no `HasLocalePreference` notifiable, so a worker renders them in its own `app.locale`. Each now carries the account's language: the user's for the self-service pair, the *target's* (not the administrator's) for `EmailChangedByAdministrator`. `ConfirmEmailChange` and `EmailChangeRequested` also get the missing `afterCommit()` (the Mail rule in Global Constraints).
- **G3-06:** the profile page dispatched `Flux::toast()` and then reloaded the page for a language change, so the toast was lost, and it was worded in the old language. With a language change the status is now flashed in the new language, and the base layout's flash→toast bridge shows it after the reload.
- `config/app.php`'s `locale` default becomes `nl`, the same as `skv.locale.default`. Anything translated before `SetLocale` then defaults to the kit's language when `APP_LOCALE` is unset.

**Files:**
- Modify:
  - `app/Support/Locales.php` (new `forNextPage()`)
  - `app/Tenancy/Http/Middleware/VerifyTenantMembership.php` (the reason is translated after the sign-out, in the guest's language)
  - `app/Tenancy/Http/Middleware/EnforceImpersonation.php` (the status is translated in the restored impersonator's language, or the guest's)
  - `app/Http/Controllers/ImpersonationController.php` (the status is in the impersonator's language)
  - `app/Actions/Settings/RequestEmailChange.php`, `app/Actions/Users/UpdateUser.php` (`->locale()` on each notification)
  - `app/Notifications/ConfirmEmailChange.php`, `app/Notifications/EmailChangeRequested.php` (`afterCommit()`)
  - `resources/views/pages/settings/profile/profile.php` (flash instead of toast on a language change; during an impersonation the form starts from, and compares with, the language chosen during it (Task 4's G2-05); Task 1's email-change limit in this method stays as it is)
  - `config/app.php` (`'locale' => env('APP_LOCALE', 'nl')`)
  - `CHANGELOG.md`
- Test:
  - `tests/Feature/Tenancy/MembershipTest.php`
  - `tests/Feature/Impersonation/ImpersonationEnforcementTest.php`, `tests/Feature/Impersonation/ImpersonationTest.php`
  - `tests/Feature/Settings/EmailChangeTest.php`, `tests/Feature/Settings/ProfileUpdateTest.php`
  - `tests/Feature/Users/UpdateUserTest.php`
  - `tests/Feature/Impersonation/ImpersonationPreferencesTest.php` (Task 4's file; one test appended in Step 5b)

**Interfaces:**
- Consumes:
  - `Locales::resolve()` (private), `Locales::forRequest()` and `Locales::forErrorPage()` as on `main`. If Task 4 (G2-05) changed `resolve()` so that an impersonation's session locale wins, `forNextPage()` inherits that: it is called only once the impersonation has ended, so the account's language applies.
  - `Illuminate\Notifications\Notification::locale(string $locale): static` (the framework; `NotificationSender::queueNotification()` keeps `$notification->locale` on the queued copy, and `sendNow()` renders inside `withLocale()`)
  - Task 1's profile page: its `email-change:<user id>` limit in `updateProfileInformation()` (the `reserve()` before the name is saved, and the `try`/`release()` around `RequestEmailChange::handle()`) stays where Task 1 put it. This task's edits replace only the docblock, the two `Flux::toast(...)` lines and the closing `if ($localeChanged)` block.
  - The global test helpers `startImpersonatingOverHttp()`, `asOrgAdmin()`, `onTenant()`, `useImpersonationModule()` (the impersonation files already call it in `beforeEach`)
- Produces:
  - `App\Support\Locales::forNextPage(Request $request, ?User $user): Locale`: the language the next page for `$user` is answered in, or a guest's (session choice, then the browser, then `Locale::default()`) when `$user` is null. It never asks the guard (RL-20, RK-I1).
  - `ConfirmEmailChange` and `EmailChangeRequested` call `afterCommit()` in their constructors, like `EmailChangedByAdministrator`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Tenancy/MembershipTest.php`: add `use App\Enums\Locale;` above `use App\Models\Organisation;`. Add this test right after 'logs a member out on another organisation host':

```php
it('words the sign-out reason in the language of the login page that shows it (G3-01)', function (): void {
    actingAs(User::factory()->for(Organisation::factory()->create())->create(['locale' => Locale::English]));
    onTenant(Organisation::factory()->create());
    $reason = 'Je account heeft geen toegang tot dit adres. Log in bij je eigen organisatie.';

    $this->get(route('dashboard'), ['Accept-Language' => 'nl'])
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', $reason);

    $this->get(route('login'), ['Accept-Language' => 'nl'])
        ->assertOk()
        ->assertSee('<html lang="nl"', false)
        ->assertSee($reason);
});
```

`tests/Feature/Impersonation/ImpersonationEnforcementTest.php`: add `use App\Enums\Locale;` after `use App\Enums\ActivityEvent;`. Add this test right before 'signs out when the impersonator is disabled and leaves the target\'s remember token alone (RK-I1)':

```php
it('words the end of the impersonation in the language of the page that shows it (G3-01)', function (string $disabled, string $browser, string $route, string $status) use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $admin->forceFill(['locale' => Locale::English])->save();
    $member = User::factory()->for($organisation)->create(['locale' => Locale::Dutch]);
    onTenant($organisation);
    startImpersonatingOverHttp($admin, $member);
    // The next request starts in Dutch, as a request starts in APP_LOCALE before SetLocale runs.
    $this->get(route('dashboard'))->assertOk()->assertSee('<html lang="nl"', false);
    Auth::guard('web')->forgetUser();

    DB::table('users')->where('id', $disabled === 'target' ? $member->id : $admin->id)->update(['status' => UserStatus::Disabled->value]);

    $this->get(route('dashboard'), ['Accept-Language' => $browser])
        ->assertRedirect(route($route))
        ->assertSessionHas('status', $status);
})->with([
    // The impersonator's own language wins over a Dutch browser.
    'back in the English impersonator\'s account' => ['target', 'nl', 'users.index', 'The impersonation has ended. You are back in your own account.'],
    // Signed out, the login page follows the browser.
    'signed out to an English login page' => ['impersonator', 'en', 'login', 'The impersonation has ended. Sign in again.'],
]);
```

`tests/Feature/Impersonation/ImpersonationTest.php`: add `use App\Enums\Locale;` after `use App\Enums\ActivityEvent;`. Add this test right after 'swaps to the target and back while the session stays valid across requests':

```php
it('says "back in your own account" in the impersonator\'s language, not the target\'s (G3-02)', function () use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $admin->forceFill(['locale' => Locale::English])->save();
    $member = User::factory()->for($organisation)->create(['locale' => Locale::Dutch]);
    onTenant($organisation);
    startImpersonatingOverHttp($admin, $member);

    // A Dutch browser too: the account's language decides.
    Auth::guard('web')->forgetUser();
    $this->delete(route('impersonation.stop'), [], ['Accept-Language' => 'nl'])
        ->assertRedirect(route('users.index'))
        ->assertSessionHas('status', 'You are back in your own account.');

    Auth::guard('web')->forgetUser();
    $this->get(route('users.index'))->assertOk()->assertSee('<html lang="en"', false)->assertSee('You are back in your own account.');
});
```

`tests/Feature/Settings/EmailChangeTest.php`: the imports become:

```php
use App\Actions\Settings\RequestEmailChange;
use App\Enums\Locale;
use App\Models\Organisation;
use App\Models\User;
use App\Notifications\EmailChangeRequested;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
```

Add this closure right after the `memberChangingEmail()` function:

```php
/**
 * Recipient => subject of every mail the array mailer delivered.
 *
 * @return array<string, string>
 */
$deliveredSubjects = function (): array {
    $transport = Mail::driver()->getSymfonyTransport();
    $subjects = [];

    if ($transport instanceof ArrayTransport) {
        foreach ($transport->messages() as $sent) {
            $message = $sent instanceof SentMessage ? $sent->getOriginalMessage() : null;

            if ($message instanceof Email) {
                $subjects[$message->getTo()[0]->getAddress()] = (string) $message->getSubject();
            }
        }
    }

    return $subjects;
};
```

Append these tests at the end of the file. They use the real queue and the `array` mailer, not `Notification::fake()`, so they see what a worker renders:

```php
it('writes both email-change mails in the account\'s language when the worker sends them (G3-03)', function () use ($deliveredSubjects): void {
    config(['queue.default' => 'database']);
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create(['email' => 'old@example.com', 'locale' => Locale::English]);
    onTenant($organisation);

    app(RequestEmailChange::class)->handle($member, 'new@example.com');
    // The worker answers in its own language (here Dutch), not in the account's.
    app()->setLocale('nl');
    // Two jobs in one daemon run: --sleep=0 skips the 3-second sleep once the queue is empty, and --memory=0 keeps
    // the loop from stopping after the first job once a long test process passes 128 MB (Worker::memoryExceeded).
    Artisan::call('queue:work', ['--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1, '--memory' => 0]);

    expect($deliveredSubjects())->toBe([
        'new@example.com' => 'Confirm your new email address',
        'old@example.com' => 'Your email address is about to change',
    ]);
});

it('sends no email-change mail when the surrounding transaction rolls back (G3-03)', function () use ($deliveredSubjects): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create(['email' => 'old@example.com']);
    onTenant($organisation);

    expect(fn () => DB::transaction(function () use ($member): void {
        app(RequestEmailChange::class)->handle($member, 'new@example.com');

        throw new RuntimeException('Rolled back.');
    }))->toThrow(RuntimeException::class, 'Rolled back.')
        ->and($deliveredSubjects())->toBe([]);
});
```

The rollback test runs on the suite's `sync` queue: without `afterCommit()` a queued notification is sent at dispatch, inside the transaction.

`tests/Feature/Users/UpdateUserTest.php`: add these imports at their sorted places: `use App\Enums\Locale;`, `use Illuminate\Mail\Transport\ArrayTransport;`, `use Illuminate\Support\Facades\Mail;`, `use Symfony\Component\Mailer\SentMessage;`, `use Symfony\Component\Mime\Email;`. Add this test right after 'notifies the old address after an administrator changed it (D-14)':

```php
it('writes the notice in the target\'s language, not the administrator\'s (G3-03)', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create(['email' => 'old@example.com', 'locale' => Locale::English]);
    $admin = asOrgAdmin($organisation);
    $admin->forceFill(['locale' => Locale::Dutch])->save();
    // The Dutch administrator's request answers in Dutch.
    app()->setLocale('nl');

    app(UpdateUser::class)->handle($admin, $member, $member->name, 'new@example.com');

    $transport = Mail::driver()->getSymfonyTransport();
    $sent = $transport instanceof ArrayTransport ? $transport->messages()->first() : null;
    $mail = $sent instanceof SentMessage ? $sent->getOriginalMessage() : null;

    expect($mail)->toBeInstanceOf(Email::class)
        ->and($mail instanceof Email ? $mail->getSubject() : null)->toBe('An administrator changed your email address');
});
```

`tests/Feature/Settings/ProfileUpdateTest.php`: append:

```php
it('flashes the result in the new language when a language change reloads the page (G3-06)', function (string $email, string $status): void {
    Notification::fake();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $this->actingAs(User::factory()->for($organisation)->create(['email' => 'member@example.com', 'locale' => Locale::English]));
    onTenant($organisation);

    Livewire::test('pages::settings.profile')
        ->set('locale', 'nl')
        ->set('email', $email)
        ->call('updateProfileInformation')
        ->assertHasNoErrors()
        ->assertNotDispatched('toast-show')
        ->assertRedirect(route('profile.edit'));

    expect(session('status'))->toBe($status);
})->with([
    'with an address change' => ['new@example.com', 'We hebben een bevestigingslink gestuurd naar new@example.com.'],
    'the language alone' => ['member@example.com', 'Profiel bijgewerkt.'],
]);

it('keeps the toast and flashes nothing when the language stays (G3-06)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $this->actingAs(User::factory()->for($organisation)->create(['locale' => Locale::English]));
    onTenant($organisation);

    Livewire::test('pages::settings.profile')
        ->set('name', 'New Name')
        ->call('updateProfileInformation')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show')
        ->assertNoRedirect();

    expect(session('status'))->toBeNull();
});
```

`Livewire::test()` is enough here: the toast, the flash and the redirect are the component's own behaviour, and no middleware is involved. Task 1's email-change limit allows the one request per test.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Tenancy/MembershipTest.php tests/Feature/Impersonation tests/Feature/Settings/EmailChangeTest.php tests/Feature/Settings/ProfileUpdateTest.php tests/Feature/Users/UpdateUserTest.php`

Expected: 9 failures, every other test passes:
- **MembershipTest G3-01:** the status is the English 'Your account does not have access to this address. …' (translated in `app.locale`, `en` under `phpunit.xml`), not the Dutch text the Dutch login page shows.
- **ImpersonationEnforcementTest G3-01, both rows:** the status is Dutch ('Inloggen als deze gebruiker is beëindigd. …'), the language the request started in.
- **ImpersonationTest G3-02:** the status is 'Je bent terug in je eigen account.', the target's language.
- **EmailChangeTest:**
  - The worker test gets 'Bevestig je nieuwe e-mailadres' and 'Je e-mailadres gaat veranderen'.
  - The rollback test finds both mails sent.
- **UpdateUserTest G3-03:** the subject is 'Een beheerder heeft je e-mailadres gewijzigd'.
- **ProfileUpdateTest G3-06, both rows:** "an event [toast-show] was not fired" fails, because the toast is dispatched and no status is flashed.

'keeps the toast and flashes nothing when the language stays' passes already; it guards the branch that keeps the toast.

- [ ] **Step 3: The next page's language (G3-01, G3-02)**

`app/Support/Locales.php`: add this method right after `forErrorPage()`:

```php
    /**
     * The language the next page for $user is answered in, or a guest's when nobody will be signed in (G3-01, G3-02):
     * for a status flashed on a redirect after a sign-out or an impersonation swap, when the request's own language
     * (applied by SetLocale, or not yet at all) belongs to someone else. Never asks the guard (RL-20, RK-I1).
     */
    public static function forNextPage(Request $request, ?User $user): Locale
    {
        return self::resolve($request, $user);
    }
```

`app/Tenancy/Http/Middleware/VerifyTenantMembership.php`: add `use App\Support\Locales;` after `use App\Models\User;`. Replace the body of `handle()` from `$reason = …` to the end:

```php
        $active = $user->isActive() && ! $user->trashed();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // SetLocale has not run yet, and the login page answers a guest: word the reason in that guest's language (G3-01).
        $locale = Locales::forNextPage($request, null)->value;
        $reason = $active
            ? __('Your account does not have access to this address. Sign in at your own organisation.', [], $locale)
            : __('Your account is not active. Contact your administrator.', [], $locale);

        return redirect()->route('login')->with('status', $reason);
```

The locale is read after `invalidate()`, so a guest choice from before the sign-in no longer counts, just as the login page will not see it. A signed-out account gets the browser's language on the login page, as after a normal sign-out.

`app/Tenancy/Http/Middleware/EnforceImpersonation.php`: add `use App\Support\Locales;` after `use App\Models\User;`. Replace the two returns after the `routeIs('logout')` check:

```php
        // SetLocale has not run yet: word the status in the language of the page it lands on (G3-01).
        $locale = Locales::forNextPage($request, $impersonator)->value;

        if (! $impersonator instanceof User) {
            return to_route('login')->with('status', __('The impersonation has ended. Sign in again.', [], $locale));
        }

        return to_route($impersonator->can(Permission::UsersView) ? 'users.index' : 'dashboard')
            ->with('status', __('The impersonation has ended. You are back in your own account.', [], $locale));
```

`app/Http/Controllers/ImpersonationController.php`: replace the whole file:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Users\StopImpersonation;
use App\Authorization\Permission;
use App\Models\User;
use App\Support\Locales;
use App\Tenancy\Auth\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** DELETE /impersonation (§4.7): back to your own account, on whatever host the session lives. */
final class ImpersonationController extends Controller
{
    public function destroy(Request $request, StopImpersonation $stopImpersonation): RedirectResponse
    {
        // The route is registered only while the module is on; a cached route file can outlive the flag (RK-I12).
        abort_unless(Impersonation::enabled(), 404);

        $impersonator = $stopImpersonation->handle();

        if (! $impersonator instanceof User) {
            return to_route('dashboard');
        }

        // SetLocale applied the target's language to this request; the next page is the impersonator's (G3-02).
        return to_route($impersonator->can(Permission::UsersView) ? 'users.index' : 'dashboard')
            ->with('status', __('You are back in your own account.', [], Locales::forNextPage($request, $impersonator)->value));
    }
}
```

`config/app.php`: change the `locale` line to:

```php
    'locale' => env('APP_LOCALE', 'nl'),
```

This line has no test of its own. `phpunit.xml` sets `APP_LOCALE=en`, and the value counts only when `APP_LOCALE` is unset. Then `app.locale` and `skv.locale.default` (`config/skv.php`) agree, and the `fallback_locale` stays `en`.

- [ ] **Step 4: Notifications in the recipient's language (G3-03)**

`app/Actions/Settings/RequestEmailChange.php`: replace the two `notify()` lines at the end of `handle()`:

```php
        // On-demand notifications have no locale preference: both mails carry the account's language (G3-03).
        Notification::route('mail', $email)->notify(new ConfirmEmailChange($url)->locale($user->locale->value));
        // The notice goes to the address as it is now, even if the worker runs after the change was confirmed.
        Notification::route('mail', $user->email)->notify(new EmailChangeRequested($email)->locale($user->locale->value));
```

`app/Actions/Users/UpdateUser.php`: replace the last line of `handle()`:

```php
        // In the target's language, not the administrator's (G3-03).
        Notification::route('mail', $previousEmail)->notify(new EmailChangedByAdministrator($email)->locale($target->locale->value));
```

`app/Notifications/ConfirmEmailChange.php`: replace the constructor:

```php
    /** The link is built during the request, on the user's own host (§6.2). */
    public function __construct(public readonly string $url)
    {
        $this->afterCommit();
    }
```

`app/Notifications/EmailChangeRequested.php`: replace the constructor:

```php
    public function __construct(public readonly string $newEmail)
    {
        $this->afterCommit();
    }
```

- [ ] **Step 5: The profile toast that survives the reload (G3-06)**

`resources/views/pages/settings/profile/profile.php`: replace the docblock of `updateProfileInformation()`:

```php
    /**
     * Save the name and the language; a new email address only applies after it is confirmed through the
     * mailed link (E1). A new language reloads the page, so it renders in that language (§4.10); the result
     * is then flashed in that language for the reloaded page instead of a toast the reload would drop (G3-06).
     * Sending the confirmation link is limited per user (CD-3): the slot is reserved before anything is saved,
     * so a refusal saves nothing, and given back when the request throws.
     */
```

In the method, replace the toast line in the email-change branch:

```php
            $status = __('We sent a confirmation link to :email.', ['email' => $email], $locale->value);
```

and the toast line in the `else` branch:

```php
            $status = __('Profile updated.', [], $locale->value);
```

Replace the closing block

```php
        if ($localeChanged) {
            $this->redirect(route('profile.edit'), navigate: false);
        }
```

with:

```php
        if (! $localeChanged) {
            Flux::toast(variant: 'success', text: $status);

            return;
        }

        // A toast dispatched now is lost on the full reload (G3-06): the status is flashed instead, in the new
        // language, and the base layout's flash→toast bridge shows it on the reloaded page.
        session()->flash('status', $status);
        $this->redirect(route('profile.edit'), navigate: false);
```

`$locale` is the language the form chose. Without a change it equals the effective language, which `SetLocale` applied to this request. Task 1's limit check and everything above the toast lines stay unchanged.

- [ ] **Step 5b: The profile page during an impersonation (G2-05 follow-up)**

Task 4 keeps an impersonator's language in the session, but the profile form still starts from the target's stored `locale` and compares with it, so during an impersonation the form shows the wrong language and cannot switch back to the account's. Append to `tests/Feature/Impersonation/ImpersonationPreferencesTest.php` (it reuses that file's `$impersonating` closure):

```php
it('starts the profile form from the language chosen during an impersonation and switches back from there (G2-05)', function () use ($impersonating): void {
    [, $member] = $impersonating();
    $this->post(route('locale.update'), ['locale' => 'en'])->assertRedirect();

    $snapshot = pageSnapshot(route('profile.edit'), 'pages::settings.profile');
    expect($snapshot['data']['locale'])->toBe('en');

    callPageAction($snapshot, 'updateProfileInformation', [], ['locale' => 'nl'])->assertOk();

    expect($member->fresh()?->locale)->toBe(Locale::Dutch)
        ->and(session(Impersonation::LOCALE_KEY))->toBe('nl');
});
```

Run: `php artisan test --compact --filter='starts the profile form from the language chosen'`
Expected: FAIL: `$snapshot['data']['locale']` is `'nl'`, the target's stored language.

In `resources/views/pages/settings/profile/profile.php`, add `use App\Tenancy\Auth\Impersonation;` to the imports (sorted), and in `mount()` replace

```php
        $this->locale = $user->locale->value;
```

with

```php
        $this->locale = Impersonation::localeFor($user)->value;
```

In `updateProfileInformation()` replace

```php
        $locale = Locale::from((string) $validated['locale']);
        $localeChanged = $locale !== $user->locale;
```

with

```php
        $locale = Locale::from((string) $validated['locale']);
        $localeChanged = $locale !== Impersonation::localeFor($user);
```

Run the test again: PASS. (`UpdateLocale` keeps the choice in the session during the impersonation, Task 4.)

- [ ] **Step 6: Copy and CHANGELOG**

This task adds no lang key: every string it translates already exists in both files.

`CHANGELOG.md`, at the end of `### Changed` under `[Unreleased]`:

```markdown
- `config/app.php` defaults `APP_LOCALE` to `nl`, the same default as the kit's own language setting.
```

At the end of `### Fixed`:

```markdown
- The message after you are signed out on an address you may not use, after an impersonation ends by itself and after *Stop impersonating* is worded in the language of the page that shows it, no longer in the server's default or the impersonated user's language.
- The email-change mails (the confirmation link and the notice to the old address) and the notice after an administrator changed your address are written in the account's language, also when a queue worker sends them; the email-change mails are queued only once the surrounding transaction commits.
- Changing the language on the profile page shows its confirmation after the reload, in the new language.
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Tenancy tests/Feature/Impersonation tests/Feature/Settings tests/Feature/Users tests/Feature/Localisation tests/Feature/Navigation tests/Feature/LangCoverageTest.php tests/Feature/LangFilesTest.php`

Expected: PASS. The existing assertions that use `__()` (for example 'ends the impersonation cleanly … (W3)') still pass: in those tests the impersonator, the target and the browser are all English.

- [ ] **Step 8: Verify by mutation**

Revert each change locally, run the named test, expect it to fail, then restore the change.
1. **`EnforceImpersonation` and `ImpersonationController`:** replace `Locales::forNextPage($request, $impersonator)` with `Locales::forNextPage($request, null)`. Expect the 'back in the English impersonator\'s account' row and the G3-02 test to fail. With a Dutch browser, the guest rule answers Dutch.
2. **`VerifyTenantMembership`:** compute `$locale` with `Locales::forRequest($request)` before `logout()`. Expect MembershipTest G3-01 to fail: the signed-in account is English, and the login page follows the Dutch browser.
3. **`ConfirmEmailChange`:** drop `$this->afterCommit();`. Expect 'sends no email-change mail when the surrounding transaction rolls back' to fail with the confirmation mail delivered.
4. **`UpdateUser`:** use `$actor->locale->value` instead of `$target->locale->value`. Expect UpdateUserTest G3-03 to fail with the Dutch subject (the administrator is Dutch).
5. **Profile page:** drop `$locale->value` from the email-change branch. Expect the 'with an address change' row to fail with the English text.
6. **Profile page during an impersonation:** restore `$user->locale->value` in `mount()`. Expect Step 5b's test to fail.

- [ ] **Step 9: The gate, then commit**

Run the Global Constraints gate:
- `composer fix`
- `composer check`
- `composer test:pgsql`
- `php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser`
- `npm run build && composer test:browser`
- `npm run build:setup && git diff --exit-code resources/setup/setup.css` (no setup view changes, so expect no diff)
- `php artisan config:clear`

```bash
git add app/Support/Locales.php app/Tenancy/Http/Middleware/VerifyTenantMembership.php \
    app/Tenancy/Http/Middleware/EnforceImpersonation.php app/Http/Controllers/ImpersonationController.php \
    app/Actions/Settings/RequestEmailChange.php app/Actions/Users/UpdateUser.php \
    app/Notifications/ConfirmEmailChange.php app/Notifications/EmailChangeRequested.php \
    resources/views/pages/settings/profile/profile.php config/app.php CHANGELOG.md \
    tests/Feature/Tenancy/MembershipTest.php tests/Feature/Impersonation/ImpersonationEnforcementTest.php \
    tests/Feature/Impersonation/ImpersonationTest.php tests/Feature/Settings/EmailChangeTest.php \
    tests/Feature/Settings/ProfileUpdateTest.php tests/Feature/Users/UpdateUserTest.php \
    tests/Feature/Impersonation/ImpersonationPreferencesTest.php
git commit -m "fix: word flashed messages and email-change mails in their reader's language

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

---

### Task 6: Error pages (G3-04, G3-05, G2-02 (403 page))

The kit's error pages cover every status, and their links never lead somewhere broken or foreign:
- **G3-04:** `Handler::getHttpExceptionView()` tries `errors::{code}`, then `errors::4xx` / `errors::5xx`. The kit has no `4xx`/`5xx` view, so:
  - a 405 (a GET on a POST-only route such as `/logout` or `/locale`), a 400 or a 413 renders Symfony's English "An Error Occurred" page;
  - 401 and 402 render the framework's English pages, because those views share the `errors::` namespace.

  This task adds `4xx` and `5xx` fallbacks on `errors.layout`, plus kit 401 and 402 pages that show the `4xx` page.
- **G3-05:** "Try again" on the 429, 500 and 503 pages links to `url()->current()`. That URL drops the query string. For a throttled `POST /locale` it is a POST-only address, so the click gives a 405. Inside Livewire's error dialog (an iframe whose links Livewire retargets to `_top`) it is the update URI, which also gives a 405. One partial replaces it:
  - a GET reloads `fullUrl()`;
  - a Livewire update gets a button that reloads the page around the dialog;
  - any other request goes back to the same-host page it came from.
- **G2-02 (403 page):** "Back" used `url()->previous()`, which follows any absolute `Referer`. It now uses Task 4's same-host rule.

**Files:**
- Create:
  - `resources/views/errors/4xx.blade.php`
  - `resources/views/errors/5xx.blade.php`
  - `resources/views/errors/401.blade.php`, `resources/views/errors/402.blade.php`
  - `resources/views/errors/partials/try-again.blade.php`
- Modify:
  - `resources/views/errors/429.blade.php`, `resources/views/errors/500.blade.php`, `resources/views/errors/503.blade.php` (the partial)
  - `resources/views/errors/403.blade.php` ("Back" through `ReturnUrl`)
  - `lang/nl.json`, `lang/en.json`
  - `CHANGELOG.md`
- Test:
  - `tests/Feature/ErrorPagesTest.php`

**Interfaces:**
- Consumes:
  - Task 4's `App\Http\Support\ReturnUrl::previous(Request $request, string $fallback): string`. It returns the previous URL (the `Referer`, else the session's previous URL, as `url()->previous()` picks it) as a same-host root-relative path *with its query string*, for example `/dashboard?page=2`. Any http/https scheme and any port on this host count as this host, because only the path and query are kept. A root-relative path is kept as it is. Another host, a protocol-relative URL, a relative path (`users`), a non-http(s) scheme, a backslash, space or control character, or no previous URL gives `$fallback`. It must not throw on a request without a started session: the partial renders it for a POST that failed before `StartSession`, such as a 503 in maintenance mode.
  - `routes/web.php`'s `throttle:30,1` on `locale.update` (unchanged since `main`), and Task 4's `LocaleController`, which still answers a redirect.
  - `Handler::renderHttpException()` passing `$exception` (an `HttpExceptionInterface`) to every error view; the `View::composer(['errors::*', 'errors.*'])` in `AppServiceProvider`, which applies `Locales::forErrorPage()` to these new views too.
- Produces:
  - `resources/views/errors/partials/try-again.blade.php`: the one "Try again" action for error pages (`@include('errors.partials.try-again')`)
  - the `errors::4xx` and `errors::5xx` fallbacks, and kit `401` and `402` pages

- [ ] **Step 1: Write the failing tests**

`tests/Feature/ErrorPagesTest.php`: in `beforeEach`, add a POST twin of the `_error/{status}` route right after the GET one:

```php
    Route::middleware('web')->post('_error/{status}', fn (int $status) => abort($status))->whereNumber('status');
```

Add three rows to the dataset of 'renders every error page without Vite, Flux or Livewire, with a dark mode (§6.5)', after the `'503'` row:

```php
    '401, from the kit instead of the framework (G3-04)' => ['/_error/401', 401],
    '405, through the 4xx fallback (G3-04)' => ['/logout', 405],
    '502, through the 5xx fallback (G3-04)' => ['/_error/502', 502],
```

Add these tests right after that test:

```php
it('words a status without a page of its own in the visitor\'s language (G3-04)', function (string $path, int $status, string $title): void {
    onApex();

    $this->get($path, ['Accept-Language' => 'nl-NL,nl;q=0.9'])
        ->assertStatus($status)
        ->assertSee('<html lang="nl"', false)
        ->assertSee('<div class="code">'.$status.'</div>', false)
        ->assertSee($title)
        ->assertDontSee('Oops! An Error Occurred');
})->with([
    'GET on a POST-only route' => ['/logout', 405, 'Er klopt iets niet aan dit verzoek'],
    'an unauthorised abort' => ['/_error/401', 401, 'Er klopt iets niet aan dit verzoek'],
    'a bad gateway' => ['/_error/502', 502, 'Er ging iets mis'],
]);

it('lets "Try again" reload the same address with its query string (G3-05)', function (string $status): void {
    onApex();

    $this->get('/_error/'.$status.'?page=2')->assertSee('href="'.url('/_error/'.$status.'?page=2').'"', false);
})->with(['429', '500', '503']);

it('never points "Try again" at a POST-only address (G3-05)', function (): void {
    onApex();
    $from = ['Referer' => url('/dashboard?page=2')];

    // The language toggle's own limit: the 31st POST in a minute is refused.
    foreach (range(1, 30) as $attempt) {
        $this->post(route('locale.update'), ['locale' => 'en'], $from)->assertRedirect();
    }

    $this->post(route('locale.update'), ['locale' => 'en'], $from)
        ->assertTooManyRequests()
        ->assertSee('href="/dashboard?page=2"', false)
        ->assertDontSee('href="'.route('locale.update').'"', false);
});

it('reloads the page around Livewire\'s error dialog instead of opening the update address (G3-05)', function (): void {
    onApex();

    $html = (string) $this->post('/_error/500', [], ['X-Livewire' => '1', 'Referer' => url('/dashboard')])
        ->assertStatus(500)
        ->getContent();

    expect($html)->toContain('onclick="window.top.location.reload()"')
        ->not->toContain('href="'.url('/_error/500').'"');
});

it('points the 403 page\'s "Back" only at this host (G2-02)', function (string $referer, string $back): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $this->actingAs(User::factory()->for($organisation)->create());
    onTenant($organisation);

    $this->get(route('users.index'), ['Referer' => $referer])
        ->assertForbidden()
        ->assertSee('href="'.$back.'"', false)
        ->assertDontSee('evil.example');
})->with([
    'another site' => ['https://evil.example/phish', '/'],
    'this host' => ['http://acme.localhost/dashboard?page=2', '/dashboard?page=2'],
]);
```

The Livewire case posts to a test route with the `X-Livewire` header. The header is all that Livewire itself (`HandleRequests::isLivewireRequest()`) and the partial look at. Livewire sends no `Accept: application/json` on updates, so the error renders as HTML here too.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/ErrorPagesTest.php`

Expected: 13 failures:
- **The three new dataset rows:**
  - 401 is the framework's page, without the kit's dark mode.
  - 405 and 502 are Symfony's "An Error Occurred: Method Not Allowed" / "Bad Gateway".
- **The three G3-04 rows:** the same pages, in English (`<html lang="en">`).
- **'lets "Try again" reload …', all three rows:** the link is `http://localhost/_error/<status>` without `?page=2`.
- **'never points "Try again" at a POST-only address':** the 429 page links to `http://localhost/locale`.
- **The Livewire case:** there is no reload button, and the link is `http://localhost/_error/500`.
- **The 403 'another site' row:** the page links to `https://evil.example/phish`.
- **The 403 'this host' row:** the link is the absolute `http://acme.localhost/dashboard?page=2`, not the relative path `ReturnUrl` gives.

- [ ] **Step 3: The fallbacks and the "Try again" partial**

`resources/views/errors/partials/try-again.blade.php` (new):

```blade
{{--
    "Try again" that never opens a POST-only address (G3-05). A GET reloads its own address, query string included. A
    Livewire update shows this page in Livewire's error dialog (an iframe): reload the page around it. Any other request
    (a throttled POST /locale, a failed form) goes back to the same-host page it came from (App\Http\Support\ReturnUrl).
--}}
@php($request = request())
@if ($request->hasHeader('X-Livewire'))
    <button type="button" onclick="window.top.location.reload()">{{ __('Try again') }}</button>
@elseif ($request->isMethod('GET') || $request->isMethod('HEAD'))
    <a href="{{ $request->fullUrl() }}">{{ __('Try again') }}</a>
@else
    <a href="{{ \App\Http\Support\ReturnUrl::previous($request, '/') }}">{{ __('Try again') }}</a>
@endif
```

`resources/views/errors/429.blade.php`, `500.blade.php` and `503.blade.php`: in each, replace the line

```blade
    <a href="{{ url()->current() }}">{{ __('Try again') }}</a>
```

with:

```blade
    @include('errors.partials.try-again')
```

`resources/views/errors/4xx.blade.php` (new):

```blade
@extends('errors.layout')

{{-- Every 4xx without a page of its own (G3-04): 400, 405 on a POST-only route, 413 for an oversized POST, … --}}
@section('code', (string) $exception->getStatusCode())
@section('title', __('Something is wrong with this request'))
@section('message', __('This page cannot handle the request. Go to the home page and try again.'))
@section('actions')
    <a href="{{ url('/') }}">{{ __('Go to the home page') }}</a>
@endsection
```

`resources/views/errors/5xx.blade.php` (new):

```blade
@extends('errors.layout')

{{-- Every 5xx without a page of its own (G3-04): 502, 504 and the like, mostly from a proxy in front of the app. --}}
@section('code', (string) $exception->getStatusCode())
@section('title', __('Something went wrong'))
@section('message', __('An unexpected error occurred. Try again in a moment.'))
@section('actions')
    @include('errors.partials.try-again')
@endsection
```

`resources/views/errors/401.blade.php` (new):

```blade
{{-- The framework ships its own 401 page in English; this one is the kit's 4xx page (G3-04). --}}
@include('errors.4xx')
```

`resources/views/errors/402.blade.php` (new):

```blade
{{-- The framework ships its own 402 page in English; this one is the kit's 4xx page (G3-04). --}}
@include('errors.4xx')
```

The kit never aborts with 401 or 402, and an unauthenticated web request redirects to the login page. The two files exist only because the framework's own views would otherwise beat `4xx`. The partial sits in `errors/partials/`, so `BaseLayoutTest`'s rule ("only the error pages are documents besides the two layouts") still holds: it is no document.

- [ ] **Step 4: The 403 page's "Back" (G2-02)**

`resources/views/errors/403.blade.php`: replace the line

```blade
    <a href="{{ url()->previous() }}">{{ __('Back') }}</a>
```

with:

```blade
    {{-- Only back to a page on this host (G2-02): url()->previous() would follow any Referer. --}}
    <a href="{{ \App\Http\Support\ReturnUrl::previous(request(), '/') }}">{{ __('Back') }}</a>
```

The `@php` block that resolves `$home` without asking the guard (RL-20) stays as it is.

- [ ] **Step 5: Copy and CHANGELOG**

Lang keys, in both files, at their sorted positions (PHP `sort()` order):

| Key | nl | Position |
|---|---|---|
| `Something is wrong with this request` | `Er klopt iets niet aan dit verzoek` | right after `Someone asked to change the email address of your account to :email.`, before `Something went wrong` |
| `This page cannot handle the request. Go to the home page and try again.` | `Deze pagina kan dit verzoek niet verwerken. Ga naar de startpagina en probeer het opnieuw.` | right after `This organisation is not archived.`, before `This page does not exist or has moved.` |

In `en.json` each value equals its key. "Try again", "Back", "Go to the home page", "Something went wrong" and "An unexpected error occurred. Try again in a moment." already exist.

`CHANGELOG.md`, under `[Unreleased]` › `### Added`, replace the line

```markdown
- Friendly, self-contained error pages (403, 404, 419, 429, 500, 503) in the visitor's language, and a clear page for an invitation link that is no longer valid.
```

with:

```markdown
- Friendly, self-contained error pages (401, 402, 403, 404, 419, 429, 500, 503, and one for every other 4xx and 5xx status, such as 405 when a POST-only address is opened or 502 from a proxy) in the visitor's language, and a clear page for an invitation link that is no longer valid.
```

At the end of `### Fixed`:

```markdown
- *Try again* on the 429, 500 and 503 pages keeps the address's query string, reloads the page behind Livewire's error dialog, and after a refused form post goes back to the page you came from instead of opening an address that only accepts POST. *Back* on the 403 page only leads to a page on the same host.
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/ErrorPagesTest.php tests/Feature/Layouts tests/Feature/Localisation tests/Feature/LangFilesTest.php tests/Feature/LangCoverageTest.php tests/Feature/Impersonation/ImpersonationEnforcementTest.php`

Expected: PASS. `ImpersonationEnforcementTest` renders an error page before the impersonation re-check (RL-20); it must stay green with the new 403 "Back".

- [ ] **Step 7: Verify by mutation**

Revert each change locally, run the named test, expect it to fail, then restore the change.
1. **The partial:** change `@if ($request->hasHeader('X-Livewire'))` to `@if (false)`. Expect 'reloads the page around Livewire\'s error dialog …' to fail: the request falls through to the `ReturnUrl` link.
2. **The partial:** change `$request->fullUrl()` to `url()->current()`. Expect all three rows of 'lets "Try again" reload the same address with its query string' to fail.
3. **The partial:** change the last branch to `<a href="{{ url()->current() }}">`. Expect 'never points "Try again" at a POST-only address' to fail.
4. **`403.blade.php`:** put `url()->previous()` back. Expect both rows of 'points the 403 page\'s "Back" only at this host' to fail.
5. **Delete `4xx.blade.php`:** expect the 405 rows to fail, and the 401 rows with them (the `@include` cannot find its view).

- [ ] **Step 8: The gate, then commit**

Run the Global Constraints gate:
- `composer fix`
- `composer check`
- `composer test:pgsql`
- `php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser`
- `npm run build && composer test:browser`
- `npm run build:setup && git diff --exit-code resources/setup/setup.css` (the error views are no setup views, so expect no diff)
- `php artisan config:clear`

```bash
git add resources/views/errors/4xx.blade.php resources/views/errors/5xx.blade.php \
    resources/views/errors/401.blade.php resources/views/errors/402.blade.php \
    resources/views/errors/partials/try-again.blade.php resources/views/errors/403.blade.php \
    resources/views/errors/429.blade.php resources/views/errors/500.blade.php resources/views/errors/503.blade.php \
    lang/nl.json lang/en.json CHANGELOG.md tests/Feature/ErrorPagesTest.php
git commit -m "fix: add 4xx and 5xx error pages and keep their links on this host

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

---

### Task 7: One lock order (G4-06, G4-07, G4-08, G2-06 = G4-10's residual, D-cycles 3–4, D-race; CD-5)

The administrative actions lock rows in different orders today, so on PostgreSQL four pairs of transactions can deadlock (40P01), and one race is open (triage D, "Lock order each action takes today"):

1. **Guard against guard (G4-06).** `LockoutGuard::ensureAnotherActiveAdmin()` locks every super admin, then the team admins through `model_has_roles ⋈ users … FOR UPDATE`. Two id-ordered batches in a row break the id order, so disabling a super admin who is also an organisation admin deadlocks with `UpdateUserRoles` or `UpdateUserStatus` on another admin of that team.
2. **Accept against archive (G4-07).** `AcceptInvitation` locks the invitation and then writes the invitee's users row. `ArchiveOrganisation` locks the organisation's users and then (cascade) the invitations.
3. **Disable or delete against archive (cycle 3).** Without a guard, `UpdateUserStatus` and `DeleteUser` delete the sessions before they touch the users row, while `ArchiveOrganisation` holds the users rows and then deletes their sessions.
4. **Roles against role deletion (cycle 4).** `UpdateUserRoles` deletes the target's role assignment and then needs a key-share lock on the role; `DeleteRole` holds the role and deletes every assignment. Without a deadlock, the same race gives a foreign-key 500.
5. **Demote against archive (the D-race, FU3-4).** `SetSuperAdmin` reads "is the organisation archived?" without a lock, so a demotion that runs while the organisation is archived leaves a member who is neither super admin nor archived. `RestoreUser` has the same unlocked check.

CD-5 fixes all five with one order (organisation → roles → one users batch → assignments, invitations, sessions and reset tokens → activity). Every write of an organisation's users, roles and invitations now takes that organisation's row lock first, so those writes take turns per organisation. `LockoutGuard` is the one place where user administration locks users rows: one statement, on `users` only, in id order. The guards decide on the locked rows. The other users locks are single statements of their own: `AcceptInvitation` and `CancelInvitation` lock the invitee's row alone, `ArchiveOrganisation` and `PurgeOrganisation` the organisation's users.

The task also closes two smaller items:
- **G2-06** (G4-10's residual). RK-B3 in `UpdateUserRoles` looked at the roles a pending user still holds, so an invitee whose only custom role was deleted (`DeleteRole` removes it from its holders) counted as fresh and could be given any role, the organisation admin template included. RK-B3 is now decided on the locked status and on whether an invitation row exists. InviteUser's first assignment runs before the invitation exists.
- **G4-08.** The users edit page committed the name and address before the status change could still be refused. `saveAccount()` now runs both actions in one transaction, status first, and `UpdateUser` drops the self-service email-change link only after the commit.

Spec edits: none (§4.4–§4.6 promise the guards, not their lock order). Lang keys: none; every message already exists.

**Files:**
- Create:
  - `tests/Feature/Authorization/LockOrderTest.php` (PostgreSQL only)
- Modify:
  - `app/Authorization/LockoutGuard.php` (rewritten: `lockUsers()`, `lockForRemoval()`, the guards decide on locked rows; no join under FOR UPDATE)
  - `app/Tenancy/HostScope.php` (`lockOrganisation()`)
  - `app/Actions/Users/UpdateUserStatus.php`, `app/Actions/Users/DeleteUser.php` (organisation, then the users batch, before the sessions)
  - `app/Actions/Users/RestoreUser.php` (organisation lock; the archived check reads the locked row)
  - `app/Authorization/SetSuperAdmin.php` (`handle()`: organisation, then the users batch; `guardDemotion()` reads the locked rows)
  - `app/Actions/Users/UpdateUserRoles.php` (organisation, locked roles, users batch; RK-B3 by stored status and invitation, G2-06)
  - `app/Actions/Invitations/AcceptInvitation.php` (organisation → invitee → invitation, G4-07)
  - `app/Actions/Invitations/CancelInvitation.php` (invitee before the invitation)
  - `app/Actions/Invitations/InviteUser.php` (reset tokens deleted after the users insert)
  - `app/Actions/Users/UpdateUser.php` (the self-service link is dropped after commit)
  - `resources/views/pages/users/edit/edit.php` (`saveAccount()` in one transaction, status first)
  - `tests/Pest.php` (the global helper `lockStatements()`)
  - `CHANGELOG.md`
- Test:
  - `tests/Feature/Authorization/LockOrderTest.php` (new)
  - `tests/Feature/Authorization/LockoutGuardTest.php` (rewritten for the new API)
  - `tests/Feature/Tenancy/HostScopeTest.php`
  - `tests/Feature/Users/UpdateUserRolesTest.php`, `tests/Feature/Users/UsersEditTest.php`, `tests/Feature/Users/UpdateUserTest.php`
  - unchanged and still green, the behaviour the rewrite must keep: `tests/Feature/Users/UserLifecycleTest.php`, `tests/Feature/Authorization/SetSuperAdminTest.php`, `tests/Feature/Invitations/*`, `tests/Feature/Organisations/ArchiveRestorePurgeTest.php`, `tests/Feature/Roles/RoleActionsTest.php`

**Interfaces:**
- Consumes:
  - `HostScope::lockForWrite(Organisation $organisation): Organisation` (unchanged; still refuses an archived organisation and another tenant's)
  - `EffectivePermissions::holdsTemplate(array $userIds, int $teamId): array`, `Role::templateId(): int`, `Sessions::endFor(array $userIds): void`
  - `RemindInvitation` as Task 1 leaves it (its cooldown reads `reminder_sent_at`; the lock order `organisations → invitations` stays). The lock-order dataset only reminds a fresh invitation.
  - test helpers `asOrgAdmin()`, `asSuperAdmin()`, `onTenant()`, `onAdminHost()`, `inviteMember()`, `withPermissionsTeam()`
- Produces:
  - `HostScope::lockOrganisation(?int $organisationId): ?Organisation`: locks `Organisation::withTrashed()` FOR UPDATE inside the caller's transaction (a `LogicException` outside one). Returns the row archived or not, `null` for a null id or a gone row. It refuses nothing: user writes call it as CD-5 step 1 and decide on the archived state themselves.
  - `LockoutGuard` (`final readonly`, constructor unchanged), with the type `@phpstan-type LockedUser array{email: string, status: UserStatus, is_super_admin: bool, organisation_id: int|null, deleted: bool, team_admin: bool}`:
    - `lockUsers(array $userIds, bool $withSuperAdmins = false, ?int $teamAdminsOf = null): array<int, LockedUser>`: one `SELECT … FROM users … ORDER BY id FOR UPDATE` (CD-5 step 3). It locks `$userIds`, plus every non-deleted super admin when `$withSuperAdmins`, plus the organisation_admin template holders of `$teamAdminsOf` (`team_admin = true`).
    - `lockForRemoval(User $target): array<int, LockedUser>`: the batch for disabling or deleting `$target`, with the super admins when the stored flag is set and the team admins when the target holds the template.
    - `ensureAnotherActiveAdmin(User $target, array $locked, string $field): void`
    - `ensureAnotherActiveSuperAdmin(User $user, array $locked, string $field): void`
    - `ensureAnotherActiveOrganisationAdmin(User $user, array $locked, string $field): void`
    - The old signatures `ensureAnotherActiveAdmin(User, bool, string)`, `ensureAnotherActiveSuperAdmin(User, string)` and `ensureAnotherActiveOrganisationAdmin(User, int, string)` are gone. Their only callers are the four actions changed here.
  - `lockStatements(Closure $callback): array` (global test helper in `tests/Pest.php`, reserved for this task). It runs the callback and returns, in execution order, `list<array{statement: 'for update'|'update'|'delete'|'insert', table: string, sql: string, bindings: list<mixed>}>`: every `SELECT … FOR UPDATE` (the explicit locks) and every `UPDATE`, `DELETE` and `INSERT` (the implicit ones), each with its first table. It returns `[]` for FOR UPDATE on SQLite, which compiles no lock clause.

#### The order (CD-5) each action takes after this task

`O` organisations, `R` roles, `U` users (one batch, id order, no join), `MHR` model_has_roles, `I` invitations, `S` sessions, `PRT` password_reset_tokens, `A` activity_log; "w" is a write.

| Action | Order |
|---|---|
| `UpdateUserStatus`, `DeleteUser` | O(target's) → U(target + super admins if the stored flag is set + team admins if the target holds the template) → decide → w S, w PRT, w U → A |
| `RestoreUser` | O(target's), archived check on it → w U → A |
| `SetSuperAdmin::handle()` | O(user's) → U(user; + every super admin on a demotion) → decide → w U → MHR (badge) → A |
| `UpdateUserRoles` | O(team) → R(assigned, id order) → U(target; + team admins when the template goes) → decide → w MHR → A |
| `InviteUser` (`CreateOrganisation` too) | O → U insert → w PRT → nested `UpdateUserRoles` (O again, already held; R; U) → I insert → A |
| `AcceptInvitation` | O (`lockForWrite`) → U(invitee) → I → w U, w I → A |
| `CancelInvitation` | O → U(invitee) → I → w I, w U, w PRT → A |
| `RemindInvitation`, `ArchiveOrganisation`, `RestoreOrganisation`, `PurgeOrganisation`, `UpdateOrganisation`, `CreateRole`, `UpdateRole`, `DeleteRole` | unchanged; they already follow it (pinned by the dataset) |
| `UpdateUser` | w U(target) → w PRT; no organisation lock, so `saveAccount()` can run it after `UpdateUserStatus` without locking an organisation after a users row |

Accepted consequences (for DEC-34):
- **Super admin rows.** Disabling or deleting a super admin locks every super admin row.
- **The template row.** Every role assignment that hands out the organisation_admin template locks that global role row FOR UPDATE. That serialises those assignments across organisations for the length of one short transaction.
- **Exceptions that do not break the order.** `CreateOrganisation` inserts `organisation.created` before its nested invite, and `PurgeOrganisation` deletes the team's roles after its users. Both are allowed: a new row takes no lock that another transaction waits on, and every writer of a team's roles holds that organisation's lock.
- **No retry loop.** A deadlock is a bug.

- [ ] **Step 1: Write the failing tests**

`tests/Pest.php`: add `use Illuminate\Database\Events\QueryExecuted;` (after `use Illuminate\Database\Eloquent\Collection as EloquentCollection;`) and `use Illuminate\Support\Facades\DB;` (after `use Illuminate\Support\Facades\Auth;`) to the imports. Add this helper right before the `pageSnapshot()` docblock ("The snapshot of $component as the page at $url renders …"):

```php
/**
 * Runs $callback and returns, in the order they ran, the statements that lock or write rows (CD-5): every
 * SELECT … FOR UPDATE, UPDATE, DELETE and INSERT, each with its table, SQL and bindings. Lock-order tests read
 * `statement === 'for update'` for the explicit locks and the writes for the implicit ones. SQLite compiles no
 * FOR UPDATE, so those tests run on PostgreSQL only. DB::listen() cannot be undone: the listener goes on filling
 * its own array after the callback, but the list returned here is a copy taken when the callback returns. An
 * exception from the callback is passed on.
 *
 * @return list<array{statement: 'for update'|'update'|'delete'|'insert', table: string, sql: string, bindings: list<mixed>}>
 */
function lockStatements(Closure $callback): array
{
    $statements = [];

    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $sql = trim($query->sql);
        $statement = match (true) {
            str_ends_with($sql, 'for update') => 'for update',
            str_starts_with($sql, 'update ') => 'update',
            str_starts_with($sql, 'delete ') => 'delete',
            str_starts_with($sql, 'insert ') => 'insert',
            default => null,
        };

        if ($statement === null || preg_match('/^(?:select .+? from|update|delete from|insert into) "([^"]+)"/s', $sql, $table) !== 1) {
            return;
        }

        $statements[] = ['statement' => $statement, 'table' => $table[1], 'sql' => $sql, 'bindings' => array_values($query->bindings)];
    });

    $callback();

    return $statements;
}
```

`tests/Feature/Authorization/LockOrderTest.php` (new):

```php
<?php

declare(strict_types=1);

/** covers §4.3, §4.4, §4.5, §4.6, §6.3 */

use App\Actions\Invitations\AcceptInvitation;
use App\Actions\Invitations\CancelInvitation;
use App\Actions\Invitations\InviteUser;
use App\Actions\Invitations\RemindInvitation;
use App\Actions\Organisations\ArchiveOrganisation;
use App\Actions\Organisations\CreateOrganisation;
use App\Actions\Organisations\PurgeOrganisation;
use App\Actions\Organisations\RestoreOrganisation;
use App\Actions\Organisations\UpdateOrganisation;
use App\Actions\Roles\CreateRole;
use App\Actions\Roles\DeleteRole;
use App\Actions\Roles\UpdateRole;
use App\Actions\Users\DeleteUser;
use App\Actions\Users\RestoreUser;
use App\Actions\Users\UpdateUserRoles;
use App\Actions\Users\UpdateUserStatus;
use App\Authorization\Permission;
use App\Authorization\Role;
use App\Authorization\SetSuperAdmin;
use App\Enums\Locale;
use App\Enums\UserStatus;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;

/*
| CD-5, one lock order: inside a transaction, the organisation, then the roles, then one users batch, then
| role assignments, invitations, sessions and reset tokens, then activity. SQLite takes one database-wide
| write lock and compiles no FOR UPDATE, so these tests need PostgreSQL's row locks.
*/

/**
 * The tables of the explicit row locks, in order. Only a repeat of the organisation lock is merged (InviteUser's
 * nested UpdateUserRoles locks the organisation it already holds again); any other table locked twice is a CD-5
 * violation and shows up twice (G4-06 was two users batches in a row).
 *
 * @param  list<array{statement: string, table: string, sql: string, bindings: list<mixed>}>  $statements
 * @return list<string>
 */
$lockedTables = function (array $statements): array {
    $tables = [];

    foreach ($statements as $statement) {
        if ($statement['statement'] === 'for update' && ! ($statement['table'] === 'organisations' && end($tables) === 'organisations')) {
            $tables[] = $statement['table'];
        }
    }

    return $tables;
};

/**
 * The position of the first statement of this kind on this table.
 *
 * @param  list<array{statement: string, table: string, sql: string, bindings: list<mixed>}>  $statements
 */
$firstIndex = function (array $statements, string $statement, string $table): int {
    foreach ($statements as $index => $entry) {
        if ($entry['statement'] === $statement && $entry['table'] === $table) {
            return $index;
        }
    }

    throw new LogicException("No {$statement} statement on {$table} ran.");
};

/** An organisation admin of $organisation, without signing anyone in. */
$adminOf = function (Organisation $organisation): User {
    $user = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn (): User => $user->assignRole(Role::OrganisationAdmin->value));
    $user->unsetRelation('roles');

    return $user;
};

it('takes its row locks in the one order of CD-5', function (Closure $arrange, array $expected) use ($lockedTables): void {
    $act = $arrange();

    expect($lockedTables(lockStatements($act)))->toBe($expected);
})->with([
    'UpdateUserStatus, disabling a member (cycle 3)' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        $member = User::factory()->for($organisation)->create();
        $admin = asOrgAdmin($organisation);

        return fn () => app(UpdateUserStatus::class)->handle($admin, $member, UserStatus::Disabled);
    }, ['organisations', 'users']],
    'DeleteUser, a member (cycle 3)' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        $member = User::factory()->for($organisation)->create();
        $admin = asOrgAdmin($organisation);

        return fn () => app(DeleteUser::class)->handle($admin, $member);
    }, ['organisations', 'users']],
    'RestoreUser, a deleted member' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        $member = User::factory()->for($organisation)->create();
        $admin = asOrgAdmin($organisation);
        app(DeleteUser::class)->handle($admin, $member);

        return fn () => app(RestoreUser::class)->handle($admin, $member);
    }, ['organisations']],
    'UpdateUserRoles, taking the template from an admin (G4-06, cycle 4)' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        $leaving = User::factory()->for($organisation)->create();
        withPermissionsTeam($organisation->id, fn (): User => $leaving->assignRole(Role::OrganisationAdmin->value));
        $editor = RoleModel::query()->create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $organisation->id]);
        $admin = asOrgAdmin($organisation);

        return fn () => app(UpdateUserRoles::class)->handle($admin, $leaving, [(int) $editor->id]);
    }, ['organisations', 'roles', 'users']],
    'SetSuperAdmin, promoting a member' => [function (): Closure {
        $member = User::factory()->for(Organisation::factory())->create();

        return fn () => app(SetSuperAdmin::class)->handle($member, true);
    }, ['organisations', 'users']],
    'SetSuperAdmin, demoting a promoted super admin (the demote/archive race)' => [function (): Closure {
        asSuperAdmin();
        $promoted = User::factory()->for(Organisation::factory())->create();
        app(SetSuperAdmin::class)->handle($promoted, true);

        return fn () => app(SetSuperAdmin::class)->handle($promoted, false);
    }, ['organisations', 'users']],
    'InviteUser' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        $admin = asOrgAdmin($organisation);

        return fn () => app(InviteUser::class)->handle($admin, $organisation, 'new@example.com', [Role::templateId()], Locale::default());
    }, ['organisations', 'roles', 'users']],
    'AcceptInvitation (G4-07)' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        $invitation = inviteMember($organisation);
        onTenant($organisation);

        return fn () => app(AcceptInvitation::class)->handle($invitation, 'Ann Invitee', 'secret-password');
    }, ['organisations', 'users', 'invitations']],
    'CancelInvitation' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        $admin = asOrgAdmin($organisation);
        $invitation = inviteMember($organisation, $admin);

        return fn () => app(CancelInvitation::class)->handle($admin, $invitation);
    }, ['organisations', 'users', 'invitations']],
    'RemindInvitation' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        $admin = asOrgAdmin($organisation);
        $invitation = inviteMember($organisation, $admin);

        return fn () => app(RemindInvitation::class)->handle($admin, $invitation);
    }, ['organisations', 'invitations']],
    'CreateOrganisation, which invites its first admin' => [function (): Closure {
        $superAdmin = asSuperAdmin();
        onAdminHost();

        return fn () => app(CreateOrganisation::class)->handle($superAdmin, 'Acme', 'acme', null, 'admin@acme.test', Locale::default());
    }, ['organisations', 'roles', 'users']],
    'ArchiveOrganisation' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        User::factory()->for($organisation)->count(2)->create();
        $superAdmin = asSuperAdmin();
        onAdminHost();

        return fn () => app(ArchiveOrganisation::class)->handle($superAdmin, $organisation);
    }, ['organisations', 'users']],
    'RestoreOrganisation' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        $superAdmin = asSuperAdmin();
        onAdminHost();
        app(ArchiveOrganisation::class)->handle($superAdmin, $organisation);
        $archived = Organisation::withTrashed()->findOrFail($organisation->id);

        return fn () => app(RestoreOrganisation::class)->handle($superAdmin, $archived);
    }, ['organisations']],
    'PurgeOrganisation' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        User::factory()->for($organisation)->create();
        $superAdmin = asSuperAdmin();
        onAdminHost();
        app(ArchiveOrganisation::class)->handle($superAdmin, $organisation);
        $archived = Organisation::withTrashed()->findOrFail($organisation->id);

        return fn () => app(PurgeOrganisation::class)->handle($superAdmin, $archived, $archived->slug);
    }, ['organisations', 'users']],
    'UpdateOrganisation' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        $superAdmin = asSuperAdmin();
        onAdminHost();

        return fn () => app(UpdateOrganisation::class)->handle($superAdmin, $organisation, 'Renamed', $organisation->slug, null);
    }, ['organisations']],
    'CreateRole' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        $superAdmin = asSuperAdmin();

        return fn () => app(CreateRole::class)->handle($superAdmin, $organisation, 'Helpdesk', [Permission::UsersView]);
    }, ['organisations']],
    'UpdateRole' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        $role = RoleModel::query()->create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $organisation->id]);
        $superAdmin = asSuperAdmin();

        return fn () => app(UpdateRole::class)->handle($superAdmin, $organisation, (int) $role->id, 'Editors', [Permission::UsersView]);
    }, ['organisations', 'roles']],
    'DeleteRole' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        $role = RoleModel::query()->create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $organisation->id]);
        $superAdmin = asSuperAdmin();

        return fn () => app(DeleteRole::class)->handle($superAdmin, $organisation, (int) $role->id);
    }, ['organisations', 'roles']],
])->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'needs PostgreSQL row locks');

it('locks one users batch, on users only, before the sessions go when a super admin who is also an organisation admin is disabled (G4-06)', function () use ($adminOf, $firstIndex): void {
    $organisation = Organisation::factory()->create();
    $otherAdmin = $adminOf($organisation);
    $leaving = $adminOf($organisation);
    app(SetSuperAdmin::class)->handle($leaving, true);
    $actor = asSuperAdmin();
    onAdminHost();

    $statements = lockStatements(fn () => app(UpdateUserStatus::class)->handle($actor, $leaving, UserStatus::Disabled));
    $locks = array_values(array_filter($statements, fn (array $entry): bool => $entry['statement'] === 'for update'));
    $usersLocks = array_values(array_filter($locks, fn (array $entry): bool => $entry['table'] === 'users'));

    // The organisation first, then exactly one users statement: the target, the team's other admin and (by
    // the is_super_admin predicate) every super admin, in id order, without a join on model_has_roles.
    expect($locks[0]['table'] ?? null)->toBe('organisations')
        ->and($usersLocks)->toHaveCount(1)
        ->and($usersLocks[0]['sql'])->toContain('"is_super_admin" = ?')->toContain('order by "id" asc')->not->toContain(' join ')
        ->and($usersLocks[0]['bindings'])->toContain($otherAdmin->id)->toContain($leaving->id)
        ->and(array_column($locks, 'table'))->not->toContain('model_has_roles')
        ->and($firstIndex($statements, 'for update', 'users'))->toBeLessThan($firstIndex($statements, 'delete', 'sessions'))
        ->and($leaving->fresh()?->status)->toBe(UserStatus::Disabled);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'needs PostgreSQL row locks');

it('locks the assigned roles and the users batch before rewriting role assignments (cycle 4)', function () use ($firstIndex): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $editor = RoleModel::query()->create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $organisation->id]);
    $admin = asOrgAdmin($organisation);

    $statements = lockStatements(fn () => app(UpdateUserRoles::class)->handle($admin, $member, [(int) $editor->id]));

    expect($firstIndex($statements, 'for update', 'organisations'))->toBe(0)
        ->and($firstIndex($statements, 'for update', 'roles'))->toBeLessThan($firstIndex($statements, 'for update', 'users'))
        ->and($firstIndex($statements, 'for update', 'users'))->toBeLessThan($firstIndex($statements, 'delete', 'model_has_roles'))
        ->and(array_column(array_filter($statements, fn (array $entry): bool => $entry['statement'] === 'for update'), 'table'))->not->toContain('model_has_roles');
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'needs PostgreSQL row locks');

it('saves the status before the name, after the organisation and users locks, in one transaction (G4-08)', function () use ($firstIndex): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create(['name' => 'Mila Member']);
    asOrgAdmin($organisation);
    $page = Livewire::test('pages::users.edit', ['user' => $member->id])
        ->set('name', 'Mila de Vries')
        ->set('status', UserStatus::Disabled->value);

    $statements = lockStatements(fn () => $page->call('saveAccount')->assertHasNoErrors());
    $firstLockOrWrite = array_values(array_filter($statements, fn (array $entry): bool => $entry['statement'] !== 'insert'))[0] ?? null;

    // UpdateUser's write of the target row used to come first, ahead of UpdateUserStatus's organisation lock.
    expect($firstLockOrWrite['statement'] ?? null)->toBe('for update')
        ->and($firstLockOrWrite['table'] ?? null)->toBe('organisations')
        ->and($firstIndex($statements, 'for update', 'users'))->toBeLessThan($firstIndex($statements, 'update', 'users'))
        ->and($member->fresh()?->only(['name', 'status']))->toEqual(['name' => 'Mila de Vries', 'status' => UserStatus::Disabled]);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'needs PostgreSQL row locks');
```

`tests/Feature/Authorization/LockoutGuardTest.php`: replace the whole file. The first test used to assert the old two statements, one of them a `model_has_roles` join under FOR UPDATE. It now pins the single users-only batch, retitled to what it checks. The other three keep their cases on the new API.

```php
<?php

declare(strict_types=1);

/** covers §4.4, §4.6, §6.3 */

use App\Authorization\LockoutGuard;
use App\Authorization\Role;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role as RoleModel;

it('locks the target and every candidate in one statement on users only, in id order (RL-27, G4-06)', function (): void {
    $organisation = Organisation::factory()->create();
    [$firstAdmin, $secondAdmin] = User::factory()->for($organisation)->count(2)->create()->all();
    withPermissionsTeam($organisation->id, function () use ($firstAdmin, $secondAdmin): void {
        $firstAdmin->assignRole(Role::OrganisationAdmin->value);
        $secondAdmin->assignRole(Role::OrganisationAdmin->value);
    });
    $firstSuperAdmin = User::factory()->superAdmin()->create();
    $secondSuperAdmin = User::factory()->superAdmin()->create();
    $bystander = User::factory()->for($organisation)->create();

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $locked = DB::transaction(fn (): array => app(LockoutGuard::class)->lockUsers([$firstSuperAdmin->id], withSuperAdmins: true, teamAdminsOf: $organisation->id));

    $expected = [$firstAdmin->id, $secondAdmin->id, $firstSuperAdmin->id, $secondSuperAdmin->id];
    sort($expected);
    $usersStatements = array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "users"')));

    // One batch, the target included and the bystander left alone; the team admins are marked as such.
    expect(array_keys($locked))->toBe($expected)
        ->and($locked[$firstAdmin->id]['team_admin'])->toBeTrue()
        ->and($locked[$firstSuperAdmin->id]['team_admin'])->toBeFalse()
        ->and($locked)->not->toHaveKey($bystander->id)
        ->and($usersStatements)->toHaveCount(1);

    // Users only (no join under FOR UPDATE), the target not filtered out in SQL, in id order.
    expect($usersStatements[0])
        ->toContain('order by "id" asc')
        ->not->toContain(' join ')
        ->not->toContain('!=')
        ->not->toContain('<>');

    // SQLite has no row locks and compiles no FOR UPDATE clause.
    if (DB::getDriverName() !== 'sqlite') {
        expect($usersStatements[0])->toEndWith('for update');
    }
});

it('keeps the existing lock-out messages', function (): void {
    $onlySuperAdmin = User::factory()->superAdmin()->create();
    User::factory()->superAdmin()->disabled()->create();
    $organisation = Organisation::factory()->create();
    $onlyAdmin = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn (): User => $onlyAdmin->assignRole(Role::OrganisationAdmin->value));
    $guard = app(LockoutGuard::class);

    // A try/catch instead of toThrow(Closure): Pest types that closure as Closure(Throwable), so a
    // ValidationException-typed parameter fails Larastan.
    try {
        DB::transaction(fn () => $guard->ensureAnotherActiveSuperAdmin($onlySuperAdmin, $guard->lockUsers([$onlySuperAdmin->id], withSuperAdmins: true), 'is_super_admin'));
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['is_super_admin' => [__('At least one active super admin must remain.')]]);
    }

    try {
        DB::transaction(fn () => $guard->ensureAnotherActiveOrganisationAdmin($onlyAdmin, $guard->lockUsers([$onlyAdmin->id], teamAdminsOf: $organisation->id), 'roles'));
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['roles' => [__('An organisation needs at least one active organisation admin.')]]);
    }
});

it('counts only active, non-deleted template holders of the same organisation as another admin', function (): void {
    $organisation = Organisation::factory()->create();
    $otherOrganisation = Organisation::factory()->create();
    $onlyAdmin = User::factory()->for($organisation)->create();
    $otherOrganisationAdmin = User::factory()->for($otherOrganisation)->create();
    $deletedAdmin = User::factory()->for($organisation)->create(['deleted_at' => now()]);
    $lookAlikeAdmin = User::factory()->for($organisation)->create();
    $lookAlikeRole = RoleModel::query()->create([
        'name' => Role::OrganisationAdmin->value,
        'guard_name' => 'web',
        'team_id' => $organisation->id,
    ]);

    withPermissionsTeam($organisation->id, function () use ($onlyAdmin, $deletedAdmin, $lookAlikeAdmin, $lookAlikeRole): void {
        $onlyAdmin->assignRole(Role::OrganisationAdmin->value);
        $deletedAdmin->assignRole(Role::OrganisationAdmin->value);
        $lookAlikeAdmin->assignRole($lookAlikeRole);
    });
    withPermissionsTeam($otherOrganisation->id, fn (): User => $otherOrganisationAdmin->assignRole(Role::OrganisationAdmin->value));
    $guard = app(LockoutGuard::class);

    // Each candidate is active, so each one alone would cover the organisation if its filter were dropped:
    // the team filter (another organisation's admin), the deleted_at filter, and the template-id filter.
    try {
        DB::transaction(fn () => $guard->ensureAnotherActiveOrganisationAdmin($onlyAdmin, $guard->lockUsers([$onlyAdmin->id], teamAdminsOf: $organisation->id), 'roles'));
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['roles' => [__('An organisation needs at least one active organisation admin.')]]);
    }
});

it('counts only active, non-deleted super admins as another super admin', function (): void {
    $onlySuperAdmin = User::factory()->superAdmin()->create();
    User::factory()->superAdmin()->create(['deleted_at' => now()]);
    User::factory()->create();
    $guard = app(LockoutGuard::class);

    // Each candidate is active, so each one alone would keep a super admin if its filter were dropped: the
    // deleted_at filter (a deleted super admin) and the is_super_admin filter (a regular user).
    try {
        DB::transaction(fn () => $guard->ensureAnotherActiveSuperAdmin($onlySuperAdmin, $guard->lockUsers([$onlySuperAdmin->id], withSuperAdmins: true), 'is_super_admin'));
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['is_super_admin' => [__('At least one active super admin must remain.')]]);
    }
});
```

`tests/Feature/Tenancy/HostScopeTest.php`: add at the end:

```php
it('locks the organisation of a user write without refusing an archived one or another tenant\'s (CD-5)', function (): void {
    $organisation = Organisation::factory()->create();
    $archived = Organisation::factory()->create();
    $archived->delete();
    // A tenant host of another organisation: the user policies place the target, this lock refuses nothing.
    onTenant(Organisation::factory()->create());

    expect(DB::transaction(fn (): ?Organisation => HostScope::lockOrganisation($organisation->id))?->id)->toBe($organisation->id)
        ->and(DB::transaction(fn (): ?Organisation => HostScope::lockOrganisation($archived->id))?->trashed())->toBeTrue()
        ->and(DB::transaction(fn (): ?Organisation => HostScope::lockOrganisation(null)))->toBeNull();
});
```

`tests/Feature/Users/UpdateUserRolesTest.php`: add `use App\Actions\Invitations\InviteUser;` and `use App\Actions\Roles\DeleteRole;` before `use App\Actions\Users\UpdateUserRoles;`, and `use App\Enums\Locale;` after `use App\Authorization\Role;`. Then add at the end:

```php
it('keeps a pending user\'s roles as invited after deleting their only role left them none (G2-06)', function (): void {
    // Fails on main: RK-B3 looked at the roles the invitee still held, so an invitee whose only role was deleted
    // counted as a fresh one and could be given any role, the organisation admin template included.
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $helpdesk = teamRole($organisation, 'Helpdesk');
    $actor = asSuperAdmin();
    onTenant($organisation);
    $invitation = app(InviteUser::class)->handle($actor, $organisation, 'invitee@example.com', [(int) $helpdesk->id], Locale::default());
    app(DeleteRole::class)->handle($actor, $organisation, (int) $helpdesk->id);
    $invitee = User::query()->findOrFail($invitation->user_id);

    expect(roleNamesOf($invitee, $organisation))->toBe([])
        ->and(fn () => app(UpdateUserRoles::class)->handle($actor, $invitee, [systemRoleId(Role::OrganisationAdmin)]))
        ->toThrow(ValidationException::class, __('A pending user keeps the roles they were invited with. Cancel and re-invite to change them.'));

    expect(roleNamesOf($invitee, $organisation))->toBe([]);
});

it('decides again on the locked row that the target may hold organisation roles (FU2-5)', function (): void {
    // Fails before this task: the Gate and the team read the model, which a promotion that committed while this
    // write waited for the organisation lock leaves stale, so team roles landed on a super admin (W1).
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $helpdesk = teamRole($organisation, 'Helpdesk');
    $target = User::factory()->for($organisation)->create();
    $admin = asOrgAdmin($organisation);
    DB::table('users')->where('id', $target->id)->update(['is_super_admin' => true]);

    expect(fn () => app(UpdateUserRoles::class)->handle($admin, $target, [(int) $helpdesk->id]))
        ->toThrow(AuthorizationException::class);

    expect(roleNamesOf($target, $organisation))->toBe([]);
});
```

`tests/Feature/Users/UsersEditTest.php`: add right before `it('asks for a fresh password confirmation before switching super admin and comes back to this page (RL-18)', …`. All imports are already there.

```php
it('saves name, address and status all or nothing (G4-08)', function (): void {
    // Fails on main: UpdateUser committed the new name before UpdateUserStatus refused to disable the last admin.
    Notification::fake();
    $organisation = Organisation::factory()->create();
    $elsewhere = Organisation::factory()->create();
    $onlyAdmin = User::factory()->for($organisation)->create(['name' => 'Olga Admin']);
    withPermissionsTeam($organisation->id, fn (): User => $onlyAdmin->assignRole(Role::OrganisationAdmin->value));
    $member = User::factory()->for($organisation)->create(['email' => 'mila@acme.test']);
    asSuperAdmin();
    onAdminHost();
    $this->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test('pages::users.edit', ['user' => $onlyAdmin->id])
        ->set('name', 'Olga de Admin')
        ->set('status', UserStatus::Disabled->value)
        ->call('saveAccount')
        ->assertHasErrors(['status']);

    expect($onlyAdmin->fresh()?->only(['name', 'status']))->toEqual(['name' => 'Olga Admin', 'status' => UserStatus::Active]);

    // The other way round: an address taken between validation and save (FU3-8) also undoes the status change
    // that ran before it.
    User::updating(function (User $user) use ($elsewhere): void {
        if ($user->isDirty('email')) {
            DB::table('users')->insert([
                'organisation_id' => $elsewhere->id,
                'name' => 'Racer',
                'email' => $user->email,
                'password' => 'x',
                'status' => UserStatus::Active->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    Livewire::test('pages::users.edit', ['user' => $member->id])
        ->set('email', 'raced@acme.test')
        ->set('status', UserStatus::Disabled->value)
        ->call('saveAccount')
        ->assertHasErrors(['email']);

    expect($member->fresh()?->only(['email', 'status']))->toEqual(['email' => 'mila@acme.test', 'status' => UserStatus::Active]);
});
```

`tests/Feature/Users/UpdateUserTest.php`: add right before `it('turns a unique-index race into a validation error, also when confirming an email change (FU3-8)', …`. All imports are already there.

```php
it('keeps a pending self-service email change when the caller\'s transaction rolls back (G4-08)', function (): void {
    // Fails on main: the link was dropped right after UpdateUser's own transaction, before the caller's committed.
    Notification::fake();
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create(['email' => 'old@example.com']);
    app(RequestEmailChange::class)->handle($member, 'self-chosen@example.com');
    $admin = asOrgAdmin($organisation);

    expect(fn () => DB::transaction(function () use ($admin, $member): void {
        app(UpdateUser::class)->handle($admin, $member, $member->name, 'admin-chosen@example.com');

        throw new RuntimeException('A later step of the same save failed.');
    }))->toThrow(RuntimeException::class, 'A later step of the same save failed.');

    expect(Cache::has(RequestEmailChange::cacheKey($member)))->toBeTrue()
        ->and($member->fresh()?->email)->toBe('old@example.com');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

SQLite: `php artisan test --compact tests/Feature/Authorization/LockoutGuardTest.php tests/Feature/Tenancy/HostScopeTest.php tests/Feature/Users/UpdateUserRolesTest.php tests/Feature/Users/UsersEditTest.php tests/Feature/Users/UpdateUserTest.php tests/Feature/Authorization/LockOrderTest.php`

Expected: FAIL, as follows:
- **LockoutGuardTest:** all four fail with "Call to undefined method App\Authorization\LockoutGuard::lockUsers()".
- **HostScopeTest:** the new test fails with "Call to undefined method App\Tenancy\HostScope::lockOrganisation()".
- **G2-06:** "Exception [Illuminate\Validation\ValidationException] not thrown".
- **FU2-5, the locked target:** "Exception [Illuminate\Auth\Access\AuthorizationException] not thrown" (the stale model passes the Gate and Helpdesk is assigned).
- **G4-08, page:** fails on the first expectation: the stored name is `Olga de Admin`.
- **G4-08, UpdateUser:** fails with "Failed asserting that false is true": the link was forgotten inside the rolled-back transaction.
- **LockOrderTest:** every test is skipped ("needs PostgreSQL row locks").

PostgreSQL (the lock-order file only):

`php artisan config:clear && php scripts/create-pgsql-test-database.php && DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=$(php scripts/create-pgsql-test-database.php --print-name) DB_USERNAME=root DB_PASSWORD= vendor/bin/pest tests/Feature/Authorization/LockOrderTest.php`

Expected: FAIL, as follows. In the dataset, these ten cases fail; the other eight pass on `main` already and pin the actions that already follow the order:

| Case | Actual on `main` |
|---|---|
| disabling a member | `[]` |
| deleting a member | `[]` |
| RestoreUser | `[]` |
| UpdateUserRoles | `['model_has_roles']` |
| both SetSuperAdmin cases | `['users']` |
| InviteUser and CreateOrganisation | `['organisations']` |
| AcceptInvitation | `['invitations']` |
| CancelInvitation | `['organisations', 'invitations']` |

The three single tests fail too:
- **G4-06:** the first lock is on `users`.
- **cycle 4:** "No for update statement on organisations ran."
- **G4-08:** the first write is `update` on `users`.

- [ ] **Step 3: The organisation lock and the users batch (CD-5 steps 1 and 3)**

`app/Tenancy/HostScope.php`: add this method right after `lockForWrite()`:

```php
    /**
     * Locks the row of the organisation a user write concerns, first in the caller's transaction (CD-5 step 1), and
     * returns it, archived or not; null for a user without an organisation or one that is gone. Unlike lockForWrite()
     * it refuses nothing: the user policies have placed the target already, and each action decides on the archived
     * state itself, on this locked row. Every write of an organisation's users, roles and invitations takes this lock
     * or lockForWrite() first, so those writes take turns per organisation instead of deadlocking.
     */
    public static function lockOrganisation(?int $organisationId): ?Organisation
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('HostScope::lockOrganisation() must run inside a database transaction.');
        }

        if ($organisationId === null) {
            return null;
        }

        return Organisation::withTrashed()->lockForUpdate()->find($organisationId);
    }
```

`app/Authorization/LockoutGuard.php`: replace the whole file:

```php
<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one place where user administration (status, delete, roles, super admin) locks users rows, and the lock-out
 * guards (§4.4, §4.6, §6.3): never remove the last active, non-deleted super admin or the last active organisation
 * admin of a team. Invitations lock the invitee's row alone and archive/purge the organisation's users (CD-5).
 *
 * A write locks its users rows once, in one batch (CD-5 step 3): one SELECT … FOR UPDATE on users only (no join),
 * in id order, after the organisation lock (step 1) and the role locks (step 2). The batch holds the target and
 * every candidate a guard weighs, so the guards decide in PHP on locked rows (RL-27, FU2-5), and two batches of
 * different writes never lock the same rows in a different order (G4-06). pluck()/get() instead of count() or
 * exists(): PostgreSQL refuses FOR UPDATE with aggregates and in EXISTS subqueries.
 *
 * @phpstan-type LockedUser array{email: string, status: UserStatus, is_super_admin: bool, organisation_id: int|null, deleted: bool, team_admin: bool}
 */
final readonly class LockoutGuard
{
    public function __construct(private EffectivePermissions $effectivePermissions) {}

    /**
     * Locks the users batch of a write and returns the locked rows by id: $userIds, plus every non-deleted super
     * admin when $withSuperAdmins, plus every holder of the organisation_admin template (by role id) in
     * $teamAdminsOf, whose rows say team_admin. The template holders are read just before the lock; that read is
     * stable because every writer of a team's role assignments holds the organisation's row lock first (CD-5 step 1).
     *
     * @param  list<int>  $userIds
     * @return array<int, LockedUser>
     */
    public function lockUsers(array $userIds, bool $withSuperAdmins = false, ?int $teamAdminsOf = null): array
    {
        $teamAdminIds = $teamAdminsOf === null ? [] : $this->templateHolderIds($teamAdminsOf);
        $ids = array_values(array_unique([...$userIds, ...$teamAdminIds]));

        $rows = DB::table('users')
            ->where(function (Builder $query) use ($ids, $withSuperAdmins): void {
                $query->whereIn('id', $ids);

                if ($withSuperAdmins) {
                    $query->orWhere(function (Builder $superAdmins): void {
                        $superAdmins->where('is_super_admin', true)->whereNull('deleted_at');
                    });
                }
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'email', 'status', 'is_super_admin', 'organisation_id', 'deleted_at']);

        $locked = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $locked[$id] = [
                'email' => (string) $row->email,
                'status' => UserStatus::from((string) $row->status),
                'is_super_admin' => (bool) $row->is_super_admin,
                'organisation_id' => $row->organisation_id === null ? null : (int) $row->organisation_id,
                'deleted' => $row->deleted_at !== null,
                'team_admin' => in_array($id, $teamAdminIds, true),
            ];
        }

        return $locked;
    }

    /**
     * The users batch for disabling or deleting $target (W2, W12): the target, every super admin when the target's
     * stored flag is set, and the template holders of the target's team when the target holds the template there.
     * The flag and the template are read just before the lock; under the organisation lock the caller holds (CD-5
     * step 1) neither can change, because promotions and role writes take that lock too, and a user without an
     * organisation is a super admin for good (W8).
     *
     * @return array<int, LockedUser>
     */
    public function lockForRemoval(User $target): array
    {
        $stored = DB::table('users')->where('id', $target->id)->first(['is_super_admin', 'organisation_id']);

        if ($stored === null) {
            return [];
        }

        $teamId = $stored->organisation_id === null ? null : (int) $stored->organisation_id;
        $holdsTemplate = $teamId !== null && $this->effectivePermissions->holdsTemplate([$target->id], $teamId) !== [];

        return $this->lockUsers([$target->id], withSuperAdmins: (bool) $stored->is_super_admin, teamAdminsOf: $holdsTemplate ? $teamId : null);
    }

    /**
     * Both guards for removing an active user (disabling or deleting, W2, W12), on the rows lockForRemoval() locked:
     * the super-admin guard when the target's locked row carries the flag, the organisation-admin guard when it
     * holds the template in its team.
     *
     * @param  array<int, LockedUser>  $locked
     *
     * @throws ValidationException
     */
    public function ensureAnotherActiveAdmin(User $target, array $locked, string $field): void
    {
        $row = $locked[$target->id] ?? null;

        if ($row === null) {
            return;
        }

        if ($row['is_super_admin']) {
            $this->ensureAnotherActiveSuperAdmin($target, $locked, $field);
        }

        if ($row['team_admin']) {
            $this->ensureAnotherActiveOrganisationAdmin($target, $locked, $field);
        }
    }

    /**
     * @param  array<int, LockedUser>  $locked  a batch locked with $withSuperAdmins
     *
     * @throws ValidationException
     */
    public function ensureAnotherActiveSuperAdmin(User $user, array $locked, string $field): void
    {
        if (! $this->anotherActiveRemains($locked, $user, 'is_super_admin')) {
            throw ValidationException::withMessages([
                $field => __('At least one active super admin must remain.'),
            ]);
        }
    }

    /**
     * @param  array<int, LockedUser>  $locked  a batch locked with $teamAdminsOf
     *
     * @throws ValidationException
     */
    public function ensureAnotherActiveOrganisationAdmin(User $user, array $locked, string $field): void
    {
        if (! $this->anotherActiveRemains($locked, $user, 'team_admin')) {
            throw ValidationException::withMessages([
                $field => __('An organisation needs at least one active organisation admin.'),
            ]);
        }
    }

    /** @return list<int> the holders of the organisation_admin template in this team, by role id (FU2-4) */
    private function templateHolderIds(int $teamId): array
    {
        return array_values(DB::table('model_has_roles')
            ->where('role_id', Role::templateId())
            ->where('model_type', new User()->getMorphClass())
            ->where('team_id', $teamId)
            ->orderBy('model_id')
            ->pluck('model_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->all());
    }

    /**
     * @param  array<int, LockedUser>  $locked
     * @param  'is_super_admin'|'team_admin'  $role
     */
    private function anotherActiveRemains(array $locked, User $user, string $role): bool
    {
        return array_any(
            $locked,
            fn (array $row, int $id): bool => $id !== $user->id && $row[$role] && ! $row['deleted'] && $row['status'] === UserStatus::Active,
        );
    }
}
```

- [ ] **Step 4: The user actions (G4-06, cycle 3, the D-race)**

`app/Actions/Users/UpdateUserStatus.php`:
1. Add `use App\Tenancy\HostScope;` after `use App\Tenancy\Auth\Sessions;`.
2. In the class docblock, replace the last sentence, "Every decision is taken on the stored row, never on a possibly stale model (FU2-5).", with "Every decision is taken on the locked row, never on a possibly stale model (FU2-5), and the locks follow the order of CD-5: the organisation, the users batch, then the sessions."
3. Replace the whole `DB::transaction(...)` call in `handle()` with:

```php
        DB::transaction(function () use ($actor, $target, $status): void {
            // CD-5: the target's organisation, then one users batch, before the sessions go (G4-06).
            HostScope::lockOrganisation($target->organisation_id);
            $locked = $status === UserStatus::Disabled
                ? $this->lockoutGuard->lockForRemoval($target)
                : $this->lockoutGuard->lockUsers([$target->id]);
            $stored = $locked[$target->id] ?? null;

            if ($stored === null) {
                return;
            }

            $previous = $stored['status'];

            if ($previous === UserStatus::PendingActivation) {
                throw ValidationException::withMessages(['status' => [__('A pending user becomes active by accepting the invitation.')]]);
            }

            // Align the model with the stored row first, so the save and the logged old status match it.
            $target->forceFill(['status' => $previous])->syncOriginalAttribute('status');

            if ($status === $previous) {
                return;
            }

            if ($previous === UserStatus::Active) {
                $this->lockoutGuard->ensureAnotherActiveAdmin($target, $locked, 'status');

                Sessions::endFor([$target->id]);
                $target->forceFill(['remember_token' => null])->syncOriginalAttribute('remember_token');
            }

            $target->forceFill(['status' => $status])->save();

            $this->recordActivity->handle(
                ActivityEvent::UserStatusChanged,
                $target,
                changes: ['old' => ['status' => $previous->value], 'attributes' => ['status' => $status->value]],
                causer: $actor,
            );
        });
```

`app/Actions/Users/DeleteUser.php`:
1. Add `use App\Tenancy\HostScope;` after `use App\Tenancy\Auth\Sessions;`.
2. In the class docblock, replace "Every decision is taken on the stored row, never on a possibly stale model (FU2-5)." with "Every decision is taken on the locked row, never on a possibly stale model (FU2-5), and the locks follow the order of CD-5: the organisation, the users batch, then the sessions and reset tokens."
3. Replace the whole `DB::transaction(...)` call in `handle()` with:

```php
        DB::transaction(function () use ($actor, $target): void {
            // CD-5: the target's organisation, then one users batch, before the sessions and reset tokens go (G4-06).
            HostScope::lockOrganisation($target->organisation_id);
            $locked = $this->lockoutGuard->lockForRemoval($target);
            $stored = $locked[$target->id] ?? null;

            if ($stored === null || $stored['deleted']) {
                return;
            }

            if ($stored['status'] === UserStatus::PendingActivation) {
                throw ValidationException::withMessages(['user' => [__('Cancel the invitation instead.')]]);
            }

            if ($stored['status'] === UserStatus::Active) {
                $this->lockoutGuard->ensureAnotherActiveAdmin($target, $locked, 'user');
            }

            Sessions::endFor([$target->id]);
            DB::table('password_reset_tokens')->where('email', $stored['email'])->delete();
            $target->forceFill(['remember_token' => null])->syncOriginalAttribute('remember_token');
            $target->delete();

            $this->recordActivity->handle(ActivityEvent::UserDeleted, $target, causer: $actor);
        });
```

`app/Actions/Users/RestoreUser.php`:
1. Remove `use App\Models\Organisation;` and add `use App\Tenancy\HostScope;` after `use App\Rules\AvailableEmail;`.
2. In the class docblock, append to the last sentence: "The archived check reads the organisation row locked first in the transaction (CD-5), so a restore and an archive of the same organisation take turns."
3. Replace `handle()` with:

```php
    public function handle(User $actor, User $target): void
    {
        Gate::forUser($actor)->authorize('restore', $target);

        if (! $target->trashed()) {
            return;
        }

        if ($target->status === UserStatus::PendingActivation) {
            throw ValidationException::withMessages(['user' => [__('This user never accepted their invitation. Invite the address again.')]]);
        }

        try {
            DB::transaction(function () use ($actor, $target): void {
                // CD-5: the organisation first, so an archive that commits while this restore waits is seen.
                $organisation = HostScope::lockOrganisation($target->organisation_id);

                if ($organisation?->trashed() === true) {
                    throw ValidationException::withMessages(['user' => [__('Restore the organisation first.')]]);
                }

                Validator::make(
                    ['email' => $target->email],
                    ['email' => [new AvailableEmail($target->organisation_id, $target->id)]],
                )->validate();

                $target->restore();

                $this->recordActivity->handle(ActivityEvent::UserRestored, $target, causer: $actor);
            });
        } catch (UniqueConstraintViolationException) {
            // The address was taken between validation and restore (FU3-8).
            $target->refresh();

            throw ValidationException::withMessages(['email' => [__('This address can\'t be used.')]]);
        }
    }
```

`app/Authorization/SetSuperAdmin.php`:
1. Add `use App\Tenancy\HostScope;` after `use App\Models\User;`.
2. In the class docblock, change "It decides on the stored flag, read after its locks, never on a possibly stale model (FU2-5). It" to "It decides on the stored flag, read after its locks, never on a possibly stale model (FU2-5); the locks follow CD-5 (the user's organisation, then the users batch). It".
3. Add a last docblock paragraph, ` *` then ` * @phpstan-import-type LockedUser from LockoutGuard`, before ` */`.
4. Replace `handle()` with:

```php
    public function handle(User $user, bool $isSuperAdmin): void
    {
        DB::transaction(function () use ($user, $isSuperAdmin): void {
            // CD-5: the user's organisation first, so a demotion and an archive of that organisation take turns
            // (FU3-4); then one users batch. A demotion may take the last super admin away, so it locks every super
            // admin with the user, in id order (RL-27); a grant locks the user alone, which serialises concurrent
            // promotions of the same user, so the grant is recorded once.
            $organisation = HostScope::lockOrganisation($user->organisation_id);
            $locked = $this->lockoutGuard->lockUsers([$user->id], withSuperAdmins: ! $isSuperAdmin);
            $stored = $locked[$user->id] ?? null;

            if ($stored === null) {
                return;
            }

            $wasSuperAdmin = $stored['is_super_admin'];

            if ($wasSuperAdmin && ! $isSuperAdmin) {
                $this->guardDemotion($user, $stored, $organisation, $locked);
            }

            // Align the model with the stored row first, so the save writes only a real change.
            $user->forceFill(['is_super_admin' => $wasSuperAdmin])->syncOriginalAttribute('is_super_admin');
            $user->forceFill(['is_super_admin' => $isSuperAdmin])->save();

            $this->syncBadge($user, $isSuperAdmin);

            if ($wasSuperAdmin !== $isSuperAdmin) {
                $this->recordActivity->handle(
                    $isSuperAdmin ? ActivityEvent::SuperAdminGranted : ActivityEvent::SuperAdminRevoked,
                    $user,
                );
            }
        });
    }
```

5. Replace `guardDemotion()` (keep `storedFlag()`; `handleCreated()` still uses it) with:

```php
    /**
     * @param  LockedUser  $stored  the user's locked row
     * @param  array<int, LockedUser>  $locked  the batch, every super admin included
     *
     * @throws ValidationException
     */
    private function guardDemotion(User $user, array $stored, ?Organisation $organisation, array $locked): void
    {
        // A user without an organisation must stay a super admin: a regular user needs one (W8).
        if ($stored['organisation_id'] === null) {
            throw ValidationException::withMessages([
                'is_super_admin' => __('Assign an organisation before removing super admin.'),
            ]);
        }

        // An archived organisation has no host to come home to (FU3-4): restore it first. Read on the row locked
        // above, so an archive that commits while this demotion waits is seen.
        if (! $organisation instanceof Organisation || $organisation->trashed()) {
            throw ValidationException::withMessages([
                'is_super_admin' => __('Restore the organisation before removing super admin.'),
            ]);
        }

        $this->lockoutGuard->ensureAnotherActiveSuperAdmin($user, $locked, 'is_super_admin');
    }
```

- [ ] **Step 5: Roles, RK-B3 and the invitations (cycle 4, G2-06, G4-07)**

`app/Actions/Users/UpdateUserRoles.php`:
1. Add `use App\Tenancy\HostScope;` after `use App\Models\User;` (`Illuminate\Auth\Access\AuthorizationException` is already imported).
2. In the class docblock, append "The locks follow CD-5: the organisation, the assigned roles, then one users batch; decisions read the locked rows (FU2-5), the policy's 'not a super admin, still in this organisation, not deleted' included." to the last sentence. Then add ` *` and ` * @phpstan-import-type LockedUser from LockoutGuard` before ` */`.
3. Replace `handle()` and `resolveRoles()` with `handle()`, `lockedRoles()` and the new `guardInvitedRoles()`:

```php
    public function handle(User $actor, User $target, array $roleIds): void
    {
        Gate::forUser($actor)->authorize('updateRoles', $target);

        $teamId = $target->organisation_id ?? throw new LogicException('updateRoles allows only users with an organisation.');
        $templateId = Role::templateId();
        $roleIds = array_values(array_unique($roleIds));

        PermissionsTeam::run($teamId, function () use ($actor, $target, $roleIds, $teamId, $templateId): void {
            DB::transaction(function () use ($actor, $target, $roleIds, $teamId, $templateId): void {
                // CD-5: the organisation, then the roles it assigns, then one users batch, before any role
                // assignment is written. A concurrent DeleteRole holds the same organisation lock, so it can neither
                // deadlock with this write nor delete a role between the lookup and the insert (cycle 4).
                HostScope::lockOrganisation($teamId);
                $roles = $this->lockedRoles($teamId, $templateId, $roleIds);
                $keepsAdmin = $roles->contains(fn (RoleModel $role): bool => (int) $role->getKey() === $templateId);
                $removesAdmin = ! $keepsAdmin && $this->holdsTemplate($target, $teamId, $templateId);
                $locked = $this->lockoutGuard->lockUsers([$target->id], teamAdminsOf: $removesAdmin ? $teamId : null);
                $stored = $locked[$target->id] ?? null;

                if ($stored === null) {
                    return;
                }

                // FU2-5: the policy's preconditions, decided again on the locked row; a promotion, a move or a
                // deletion that committed while this write waited for the organisation lock is seen here.
                if ($stored['is_super_admin'] || $stored['organisation_id'] !== $teamId || $stored['deleted']) {
                    throw new AuthorizationException;
                }

                $this->guardInvitedRoles($target, $stored['status']);

                $target->unsetRelation('roles');
                $before = $this->roleNamesOf($target, $teamId);

                $this->guardEscalation($actor, $target, $roles);

                if ($removesAdmin) {
                    $this->guardAdminRemoval($actor, $target, $locked);
                }

                $target->syncRoles($roles);
                $target->unsetRelation('roles');

                $after = $roles->map(fn (RoleModel $role): string => $role->name)->values()->all();
                sort($after);

                if ($before !== $after) {
                    $this->recordActivity->handle(
                        ActivityEvent::UserRolesChanged,
                        $target,
                        changes: ['old' => ['roles' => $before], 'attributes' => ['roles' => $after]],
                        causer: $actor,
                    );
                }
            });
        });
    }

    /**
     * The requested roles, locked in id order (CD-5 step 2); none when none are requested.
     *
     * @param  list<int>  $roleIds
     * @return Collection<int, RoleModel>
     *
     * @throws ValidationException
     */
    private function lockedRoles(int $teamId, int $templateId, array $roleIds): Collection
    {
        if ($roleIds === []) {
            return new Collection;
        }

        $roles = RoleModel::query()
            ->whereKey($roleIds)
            ->where('guard_name', SyncPermissions::GUARD)
            ->where(function (Builder $query) use ($teamId, $templateId): void {
                $query->where('team_id', $teamId)->orWhere('id', $templateId);
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        // A team role that reads like a system role would pass for it in lists and checks (FU2-4, W6).
        $lookalike = $roles->contains(
            fn (RoleModel $role): bool => (int) $role->getKey() !== $templateId && Role::isReservedName($role->name),
        );

        if ($lookalike || $roles->count() !== count($roleIds)) {
            throw ValidationException::withMessages([
                'roles' => __('One or more roles are not available for this organisation.'),
            ]);
        }

        return $roles;
    }

    /**
     * RK-B3 (FU4-7, G2-06): a pending user's roles stay as invited; the accept link was issued for them. Only
     * InviteUser's first assignment passes, made before the invitation row exists. Decided on the locked status
     * and the invitation, never on the roles the user still holds: deleting a custom role can leave an invitee
     * without any (DeleteRole), which must not reopen the assignment. The invitation read needs no lock of its own:
     * every write of an organisation's invitations holds the organisation lock taken above.
     *
     * @throws ValidationException
     */
    private function guardInvitedRoles(User $target, UserStatus $storedStatus): void
    {
        if ($storedStatus !== UserStatus::PendingActivation) {
            return;
        }

        if (DB::table('invitations')->where('user_id', $target->id)->exists()) {
            throw ValidationException::withMessages([
                'roles' => __('A pending user keeps the roles they were invited with. Cancel and re-invite to change them.'),
            ]);
        }
    }
```

4. Replace `guardAdminRemoval()` with:

```php
    /**
     * @param  array<int, LockedUser>  $locked  the batch, the team's template holders included
     *
     * @throws ValidationException
     */
    private function guardAdminRemoval(User $actor, User $target, array $locked): void
    {
        if ($actor->is($target)) {
            throw ValidationException::withMessages([
                'roles' => __('You cannot remove your own organisation admin role.'),
            ]);
        }

        $this->lockoutGuard->ensureAnotherActiveOrganisationAdmin($target, $locked, 'roles');
    }
```

`app/Actions/Invitations/AcceptInvitation.php`:
1. Add `use App\Models\Organisation;` after `use App\Models\Invitation;` and `use App\Tenancy\HostScope;` after `use App\Models\User;`.
2. Replace the class docblock with:

```php
/**
 * Accepts an invitation (§4.5) on the invitation's tenant host. The organisation, the invitee's row and the
 * invitation are locked in that order (CD-5, G4-07), and the invitation's state is re-checked inside the
 * transaction (X9); the pending user must still exist, still be pending and not be a super admin (RK-M3, §6.3),
 * the invitee chooses their own name (W14), and the stored token copy is cleared so the link can never be rebuilt
 * (RK-B3). The user is saved quietly because this action records its own event (RL-6). Signing in is the
 * caller's job.
 */
```

3. Replace the `return DB::transaction(...)` call in `handle()` with:

```php
        return DB::transaction(function () use ($invitation, $name, $password): User {
            // CD-5 (G4-07): the organisation, then the invitee's row, then the invitation, the order ArchiveOrganisation
            // takes too before its cascade reaches the invitations. An archived organisation is refused here.
            HostScope::lockForWrite(Organisation::withTrashed()->findOrFail($invitation->organisation_id));
            $invitee = User::withTrashed()->lockForUpdate()->find($invitation->user_id);
            $locked = Invitation::query()->lockForUpdate()->find($invitation->id)
                ?? throw ValidationException::withMessages(['invitation' => __('This invitation is no longer valid.')]);

            if ($locked->isAccepted()) {
                throw ValidationException::withMessages([
                    'invitation' => __('This invitation has already been accepted. Please log in.'),
                ]);
            }

            if ($locked->isExpired()) {
                throw ValidationException::withMessages(['invitation' => __('This invitation has expired.')]);
            }

            // A pending user who became a super admin is never activated through a link (§6.3).
            if (! $invitee instanceof User || $invitee->trashed() || $invitee->status !== UserStatus::PendingActivation || $invitee->isSuperAdmin()) {
                throw ValidationException::withMessages(['invitation' => __('This invitation is no longer valid.')]);
            }

            $invitee->forceFill([
                'name' => $name,
                'password' => $password,
                'status' => UserStatus::Active,
            ])->saveQuietly();

            $locked->forceFill(['accepted_at' => now(), 'token' => null])->save();

            $this->recordActivity->handle(ActivityEvent::InvitationAccepted, $invitee, [
                'invitation_id' => $locked->id,
                'email' => $invitee->email,
            ], causer: $invitee);

            return $invitee;
        });
```

`app/Actions/Invitations/CancelInvitation.php`: inside `Tenant::run()`, replace

```php
                $locked = Invitation::query()->lockForUpdate()->findOrFail($invitation->id);

                if ($locked->isAccepted()) {
                    throw ValidationException::withMessages([
                        'invitation' => __('This invitation has already been accepted.'),
                    ]);
                }

                $invitee = $locked->user;
```

with

```php
                // CD-5: the invitee's row before the invitation, as AcceptInvitation locks them.
                $invitee = User::withTrashed()->lockForUpdate()->findOrFail($invitation->user_id);
                $locked = Invitation::query()->lockForUpdate()->findOrFail($invitation->id);

                if ($locked->isAccepted()) {
                    throw ValidationException::withMessages([
                        'invitation' => __('This invitation has already been accepted.'),
                    ]);
                }
```

`app/Actions/Invitations/InviteUser.php`: in `invite()`, move the reset-token delete from before the `$invitee` insert to right after it. Remove

```php
        // Reset tokens are keyed by address: one issued for this address belongs to whoever held it before.
        DB::table('password_reset_tokens')->where('email', $email)->delete();

```

and insert, between `])->save();` of the invitee and `$this->updateUserRoles->handle($actor, $invitee, $roleIds);`:

```php
        // Reset tokens are keyed by address: one issued for this address belongs to whoever held it before. Deleted
        // after the users row is written, as every write deletes reset tokens after its users rows (CD-5).
        DB::table('password_reset_tokens')->where('email', $email)->delete();

```

- [ ] **Step 6: One transaction for the account form (G4-08)**

`app/Actions/Users/UpdateUser.php`:
1. In the class docblock, change "user.updated is recorded by UserActivityObserver, not here." to "user.updated is recorded by UserActivityObserver, not here. It writes the target row and then its reset tokens, which keeps CD-5's order, and takes no organisation lock, so a caller may run it after UpdateUserStatus in one transaction (G4-08)."
2. Replace these two lines at the end of `handle()` and leave the `Notification::route(...)` line after them as it is (Task 5 may have changed it):

```php
        // A self-service link requested before this change must not move the address again (FU3-7).
        Cache::forget(RequestEmailChange::cacheKey($target));
```

with

```php
        // A self-service link requested before this change must not move the address again (FU3-7). After the
        // commit: when a caller's surrounding transaction rolls back (the edit page's saveAccount(), G4-08), the
        // address stays and so does the link.
        DB::afterCommit(fn (): bool => Cache::forget(RequestEmailChange::cacheKey($target)));
```

`resources/views/pages/users/edit/edit.php` (`DB` is already imported):
1. Replace the `saveAccount()` docblock with:

```php
    /**
     * Save name, address and status (USR-12), all or nothing (G4-08). Your own and a pending user's address are
     * read-only and never sent (E1); another user's new address needs a fresh password confirmation first (RL-18).
     */
```

2. Replace

```php
        $updateUser->handle($actor, $target, $this->name, $email);

        if ($statusChanges) {
            $updateUserStatus->handle($actor, $target, $status);
        }
```

with

```php
        // One transaction (G4-08): a refused status change keeps the old name and address too. The status goes
        // first, so its organisation and users locks come before UpdateUser writes the target row (CD-5).
        DB::transaction(function () use ($updateUser, $updateUserStatus, $actor, $target, $email, $status, $statusChanges): void {
            if ($statusChanges) {
                $updateUserStatus->handle($actor, $target, $status);
            }

            $updateUser->handle($actor, $target, $this->name, $email);
        });
```

- [ ] **Step 7: Run the tests to verify they pass**

SQLite: `php artisan test --compact tests/Feature/Authorization tests/Feature/Tenancy tests/Feature/Users tests/Feature/Invitations tests/Feature/Organisations tests/Feature/Roles tests/Feature/Impersonation tests/Feature/Audit`

Expected: PASS. `LockOrderTest` is skipped. Everything else is green: the last-admin and stale-model cases in `UserLifecycleTest` and `SetSuperAdminTest`, the archived-organisation cases of `RestoreUser` and the demotion (FU3-4), and every invitation test.

PostgreSQL: the Step 2 command. Expected: PASS: all 18 dataset cases and the three single tests (21 tests).

- [ ] **Step 8: Verify by mutation**

Revert each change locally, confirm the named test fails, restore it, and confirm it passes. For each mutation, copy the file aside with `cp` (for example `cp app/Authorization/LockoutGuard.php "$TMPDIR/LockoutGuard.php.keep"`), edit it, run the test, then copy it back. Never use `git stash`: the stash is shared by every worktree of the repository. Name each mutation and its result in the task report.
1. In `LockoutGuard::lockForRemoval()`, pass `withSuperAdmins: false`. Expect `UserLifecycleTest` "refuses to disable or delete the last active super admin, even when another super admin asks (W2)" to fail. The second super admin is no longer in the batch, so the final delete is refused.
2. In `UpdateUserRoles::guardInvitedRoles()`, replace the invitation check with `$this->roleNamesOf($target, (int) $target->organisation_id) !== []`, the old rule. Expect "keeps a pending user's roles as invited after deleting their only role left them none (G2-06)" to fail.
3. In `saveAccount()`, replace `DB::transaction(function () … });` with a plain call of the same closure (`(function () … })();`). Expect "saves name, address and status all or nothing (G4-08)" to fail on its second half: the status stays Disabled after the address race.
4. In `saveAccount()`, swap the two calls back (UpdateUser first) inside the transaction. Expect the PostgreSQL test "saves the status before the name, after the organisation and users locks, in one transaction (G4-08)" to fail. On SQLite the G4-08 page test still passes, because the transaction rolls the name back.
5. In `UpdateUser`, call `Cache::forget(...)` directly again. Expect "keeps a pending self-service email change when the caller's transaction rolls back (G4-08)" to fail.
6. In `AcceptInvitation`, move the `HostScope::lockForWrite(...)` line below the invitation lock. Expect the PostgreSQL dataset case "AcceptInvitation (G4-07)" to fail with `['invitations', 'organisations', 'users']`.
7. In `LockoutGuard::lockUsers()`, lock the team admins with the old `model_has_roles` join (`DB::table('model_has_roles')->join('users', …)->lockForUpdate()`) before the users statement. Expect the PostgreSQL test "locks one users batch, on users only, … (G4-06)" to fail on `not->toContain('model_has_roles')`.
8. In `SetSuperAdmin::handle()`, add `$this->lockoutGuard->lockUsers([$user->id]);` before the users batch (G4-06's shape: two users batches in a row). Expect both SetSuperAdmin dataset cases of 'takes its row locks in the one order of CD-5' to fail with `['organisations', 'users', 'users']`.
9. In `UpdateUserRoles::handle()`, drop the FU2-5 `if` on the locked row. Expect 'decides again on the locked row that the target may hold organisation roles (FU2-5)' to fail.

- [ ] **Step 9: CHANGELOG**

Add these lines at the end of the `### Fixed` list under `## [Unreleased]`, right before `### Removed`:

```markdown
- Administrative changes lock their rows in one order (organisation, roles, users, then the rest), so concurrent changes in one organisation wait for each other instead of deadlocking on PostgreSQL. Accepting an invitation while its organisation is archived, disabling or deleting a user during an archive, and changing roles while a role is deleted no longer deadlock. Demoting a super admin or restoring a user while their organisation is archived is refused instead of slipping through.
- A pending invitee keeps the roles they were invited with, also after their only role was deleted.
- Saving a user's name, address and status is all or nothing: a refused status change no longer saves the new name or address, and an address change that fails keeps a pending self-service change link.
```

- [ ] **Step 10: The gate, then commit**

Run the Global Constraints gate:
- `composer check`
- `composer test:pgsql` (runs `LockOrderTest`)
- the modules-off run
- `npm run build && composer test:browser`
- `npm run build:setup && git diff --exit-code resources/setup/setup.css` (no setup view changes, so expect no diff)
- `php artisan config:clear`

```bash
git add app/Authorization/LockoutGuard.php app/Authorization/SetSuperAdmin.php app/Tenancy/HostScope.php \
    app/Actions/Users/UpdateUserStatus.php app/Actions/Users/DeleteUser.php app/Actions/Users/RestoreUser.php \
    app/Actions/Users/UpdateUserRoles.php app/Actions/Users/UpdateUser.php \
    app/Actions/Invitations/AcceptInvitation.php app/Actions/Invitations/CancelInvitation.php app/Actions/Invitations/InviteUser.php \
    resources/views/pages/users/edit/edit.php CHANGELOG.md tests/Pest.php \
    tests/Feature/Authorization/LockOrderTest.php tests/Feature/Authorization/LockoutGuardTest.php tests/Feature/Tenancy/HostScopeTest.php \
    tests/Feature/Users/UpdateUserRolesTest.php tests/Feature/Users/UsersEditTest.php tests/Feature/Users/UpdateUserTest.php
git commit -m "fix: lock organisation, roles and users in one order in every administrative write

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

---

### Task 8: Audit trail and queued mails (G4-01, G4-03, G4-05, G6-04, G6-02)

Four small hardenings of what M4 and M7a left in the audit trail and the invitation mails:
- **G4-01:** a password reset of a super admin who was promoted from organisation X, and so still has `organisation_id = X`, is no longer recorded in X's log. The listener's comment and DEC-28 (29) already said "never a super admin"; the code only compared `organisation_id`.
- **G4-03:** the stored user agent is valid UTF-8 and at most 500 characters, as Laravel's database session handler keeps it. A failed sign-in writes an entry for any visitor, so a 5,000-character or non-UTF-8 header no longer fills the log or, on PostgreSQL, makes the insert throw (SQLSTATE 22021) and turn the failed sign-in into a 500.
- **G4-05:** the invitation mails carry the invitation's id instead of a serialised model. The worker reads the invitation again and sends nothing once it was cancelled (force-deleted), accepted (token cleared) or has expired. Today these jobs end in `failed_jobs`, `FailedJobsCheck` warns on the health report and the System page, and `queue:retry all` only fails them again.
- **G6-04 (with G6-02's test):** `ConsoleOrigin::enter()` ignores a command started inside a routed web request (the setup wizard's inline `migrate` / `db:seed`). Laravel serialises hidden Context into every job dispatched meanwhile, and a worker has no route, so `LogActivity`'s `route() === null` guard would tag those entries `via=console`. G6-02 pins the other half: a real command's queued job keeps the tag in a worker.

Not changed:
- **A mail for an archived organisation still fails in the worker.** `JobTenancy::begin()` fails a job whose `tenant_id` names a soft-deleted organisation, by design ("A missing or archived organisation fails the job without retry"), so the mail never reaches `send()`. An invitation mail queued seconds before an archive is a rare race, and archiving is a deliberate super-admin action. DEC-34 records it (Task 14).
- **Task 7 owns `AcceptInvitation`.** This task only calls `AcceptInvitation::handle()` in a test, with the signature Task 7 keeps.
- **No new `__()` key.** The `LogicException` messages in the mail base class stay untranslated, like the ones they replace.

**Files:**
- Create:
  - `app/Mail/InvitationMailable.php` (the shared base: the id instead of the model, the send-time check, the shared helpers)
  - `tests/Fixtures/RecordOrganisationCreatedJob.php` (a queued job that writes one audit entry)
- Modify:
  - `app/Listeners/RecordAuthenticationActivity.php` (the reset skip also covers super admins)
  - `app/Actions/Audit/LogActivity.php` (`boundedUserAgent()`)
  - `app/Actions/Audit/ConsoleOrigin.php` (`enter()` does nothing inside a routed request; docblock)
  - `app/Mail/InvitationMail.php`, `app/Mail/InvitationReminderMail.php` (extend `InvitationMailable`; no `SerializesModels`; corrected docblocks)
  - `CHANGELOG.md`
- Test:
  - `tests/Feature/Audit/AuthenticationActivityTest.php` (G4-01, G4-03)
  - `tests/Feature/Audit/ConsoleOriginTest.php` (G6-02, G6-04)
  - `tests/Feature/Invitations/InvitationMailTest.php` (G4-05, next to the worker tests of TEN-6 and X11)
  - unchanged and still green: `tests/Feature/Invitations/{InviteUserTest,RemindInvitationTest,CancelInvitationTest,AcceptInvitationTest,PendingInvitationsTest}.php`. They build the mails with `new InvitationMail($invitation)` and render them; the constructor keeps that signature.

**Interfaces:**
- Consumes:
  - `AcceptInvitation::handle(Invitation $invitation, string $name, string $password): User` (Task 7 changes its lock order, not its signature)
  - `RemindInvitation::handle(User $actor, Invitation $invitation): void` (Task 1's per-invitation cooldown refuses a second reminder within 10 minutes; the tests send only the first)
  - `CancelInvitation::handle(User $actor, Invitation $invitation): void`
  - `SetSuperAdmin::handle(User $user, bool $isSuperAdmin): void`
  - the test helpers `inviteMember()`, `asOrgAdmin()`, `onTenant()` and `recordedActivity()` (`withTrashed`, across organisations)
  - `JobTenancy`, which binds the job's `tenant_id`, or the central context for `null`
- Produces:
  - `abstract class App\Mail\InvitationMailable extends Mailable implements ShouldQueue`:
    - `public readonly int $invitationId`
    - `public function __construct(Invitation $invitation)` (calls `afterCommit()`)
    - `public function send($mailer): ?Illuminate\Mail\SentMessage`: `null`, and nothing sent, when the invitation is missing, accepted, tokenless or expired
    - `protected function invitation(): Invitation`, `protected function organisationName(Invitation $invitation): string`, `protected function expiresOn(Invitation $invitation): string`
  - `final class InvitationMail extends InvitationMailable` and `final class InvitationReminderMail extends InvitationMailable`, built with `new InvitationMail($invitation)` as before
  - `Tests\Fixtures\RecordOrganisationCreatedJob::__construct(public int $organisationId)`
  - `ConsoleOrigin::enter(?string $command): void` returns without setting the context while `request()->route() !== null`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Audit/AuthenticationActivityTest.php`: add these two tests right before `it('records enabling and disabling two-factor authentication', …)`. The file already imports everything they use (`SetSuperAdmin`, `ResetPassword`, `Notification`, `Organisation`, `User`, `ActivityEvent`).

```php
it('records nothing in its own organisation\'s log when a promoted super admin\'s reset is asked for or completed there (R1, G4-01)', function (): void {
    Notification::fake();
    $organisation = Organisation::factory()->create();
    // SetSuperAdmin keeps organisation_id on promotion, so this super admin still "belongs" to the host by id.
    $promoted = User::factory()->for($organisation)->create();
    app(SetSuperAdmin::class)->handle($promoted, true);
    onTenant($organisation);

    $this->post(route('password.email'), ['email' => $promoted->email]);

    $token = null;
    Notification::assertSentTo($promoted, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $promoted->email,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertSessionHasNoErrors();

    expect(recordedActivity(ActivityEvent::PasswordResetRequested))->toHaveCount(0)
        ->and(recordedActivity(ActivityEvent::PasswordResetCompleted))->toHaveCount(0);
});

it('stores at most 500 characters of valid UTF-8 as the user agent, also for a failed sign-in (G4-03)', function (string $userAgent, string $stored): void {
    $organisation = Organisation::factory()->create();
    onTenant($organisation);

    // Anyone can fail a sign-in: an over-long or invalid header must neither fill the log nor break the insert.
    $this->withHeader('User-Agent', $userAgent)
        ->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');

    $entry = recordedActivity(ActivityEvent::LoginFailed)->sole();
    expect($entry->user_agent)->toBe($stored)
        ->and(mb_check_encoding((string) $entry->user_agent, 'UTF-8'))->toBeTrue();
})->with([
    'over-long' => [str_repeat('a', 5000), str_repeat('a', 500)],
    'invalid UTF-8' => ["Probe\xffAgent/1.0", 'Probe?Agent/1.0'],
    'multibyte at the limit' => [str_repeat('é', 600), str_repeat('é', 500)],
]);
```

`tests/Fixtures/RecordOrganisationCreatedJob.php` (new):

```php
<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Actions\Audit\RecordActivity;
use App\Enums\ActivityEvent;
use App\Models\Organisation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** A queued job that writes one audit entry, for the console-origin tests (CD-7, G6-02, G6-04). */
final class RecordOrganisationCreatedJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $organisationId) {}

    public function handle(RecordActivity $recordActivity): void
    {
        $recordActivity->handle(ActivityEvent::OrganisationCreated, Organisation::query()->findOrFail($this->organisationId));
    }
}
```

`tests/Feature/Audit/ConsoleOriginTest.php`: add three imports at their sorted places: `use Illuminate\Http\Request;` after `WithConsoleEvents`, `use Illuminate\Support\Facades\DB;` after `Artisan`, and `use Tests\Fixtures\RecordOrganisationCreatedJob;` after `Livewire\Livewire`. Then add these two tests right before the comment `// A literal list, not ConsoleOrigin::WORKERS: …`:

```php
it('keeps a command\'s tag on the entries its queued jobs write in a worker (CD-7, RL-9, G6-02)', function (): void {
    config(['queue.default' => 'database']);
    $organisation = Organisation::factory()->create();

    ConsoleOrigin::enter('skv:create-organisation');
    RecordOrganisationCreatedJob::dispatch($organisation->id);
    ConsoleOrigin::leave('skv:create-organisation');

    // The worker is another process: nothing of the command is left but what the job's payload carries.
    expect(ConsoleOrigin::command())->toBeNull();

    Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true, '--tries' => 1]);

    $entry = recordedActivity(ActivityEvent::OrganisationCreated)->sole();

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and($entry->getProperty('via'))->toBe('console')
        ->and($entry->getProperty('command'))->toBe('skv:create-organisation');
});

it('never hands a command\'s tag to the jobs a web request dispatches during Artisan::call (CD-7, G6-04)', function (): void {
    config(['queue.default' => 'database']);
    $organisation = Organisation::factory()->create();

    // The setup wizard runs migrate and db:seed inline in its Livewire request; a job they dispatch is the request's.
    Route::middleware('web')->group(function () use ($organisation): void {
        Route::central(function () use ($organisation): void {
            Route::get('/_console-origin-job-probe', function () use ($organisation): string {
                ConsoleOrigin::enter('db:seed');
                RecordOrganisationCreatedJob::dispatch($organisation->id);
                ConsoleOrigin::leave('db:seed');

                return 'ok';
            });
        });
    });

    $this->get(Hosts::url(Hosts::apex(), '/_console-origin-job-probe'))->assertOk();

    expect((string) DB::table('jobs')->value('payload'))->not->toContain(ConsoleOrigin::CONTEXT_KEY);

    // A worker has no route: drop the probe's request, as a separate worker process would not have it.
    app()->instance('request', Request::create('http://localhost/'));
    Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true, '--tries' => 1]);

    $entry = recordedActivity(ActivityEvent::OrganisationCreated)->sole();

    expect($entry->getProperty('via'))->toBeNull()
        ->and($entry->getProperty('command'))->toBeNull();
});
```

`tests/Feature/Invitations/InvitationMailTest.php`: the file already runs on the `database` queue (its `beforeEach`), uses the `array` mailer of `phpunit.xml` and has the `$deliveredMails` closure. Change the first docblock to `/** covers §4.5, §6.2, Appendix A W10, X11 */`. Add three imports at their sorted places: `AcceptInvitation` and `CancelInvitation` before `InviteUser`, `RemindInvitation` after it (all `App\Actions\Invitations\…`), and `use App\Models\Invitation;` after `App\Enums\Locale`. Append at the end of the file:

```php
/** An invitation with its invitation mail and a reminder queued for the worker (G4-05). */
$queueBothMails = function (): Invitation {
    $organisation = Organisation::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $admin = asOrgAdmin($organisation);
    $invitation = inviteMember($organisation, $admin);
    app(RemindInvitation::class)->handle($admin, $invitation);

    expect(DB::table('jobs')->count())->toBe(2);

    return $invitation;
};

it('delivers both queued invitation mails while the invitation is open (W10)', function () use ($queueBothMails, $deliveredMails): void {
    $queueBothMails();

    // --sleep=0: with --stop-when-empty the worker otherwise sleeps 3 seconds once the queue is empty.
    // --memory=0: the daemon loop stops after the first job once the test process passes 128 MB
    // (Worker::memoryExceeded), which a long parallel or sequential run reaches.
    Artisan::call('queue:work', ['--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1, '--memory' => 0]);

    // inviteMember() invites in the default language; the worker renders each mail in it.
    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(array_map(fn (Email $mail): ?string => $mail->getSubject(), $deliveredMails()))->toBe([
            __('You have been invited to :organisation', ['organisation' => 'Acme'], Locale::default()->value),
            __('Reminder: your invitation to :organisation is waiting', ['organisation' => 'Acme'], Locale::default()->value),
        ]);
});

it('drops a queued invitation mail without a failed job once the invitation is gone, accepted or expired (G4-05)', function (string $outcome) use ($queueBothMails, $deliveredMails): void {
    $invitation = $queueBothMails();

    if ($outcome === 'cancelled') {
        app(CancelInvitation::class)->handle(User::query()->findOrFail((int) $invitation->invited_by), $invitation);
    } elseif ($outcome === 'accepted') {
        app(AcceptInvitation::class)->handle($invitation, 'Ann Invitee', 'secret-password');
    } else {
        $this->travel(8)->days();
    }

    Artisan::call('queue:work', ['--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1, '--memory' => 0]);

    // A failed job would warn on the health report and the System page, and queue:retry would only fail it again.
    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($deliveredMails())->toBe([]);
})->with(['cancelled', 'accepted', 'expired']);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Audit tests/Feature/Invitations/InvitationMailTest.php`

Expected: FAIL, as follows:
- 'records nothing in its own organisation\'s log when a promoted super admin\'s reset …': `auth.password_reset_requested` has 1 entry instead of 0.
- 'stores at most 500 characters of valid UTF-8 …': every row fails. The two long rows store 5,000 and 600 characters. On SQLite the invalid row stores the raw `\xff` byte; on PostgreSQL the insert throws, so the request answers 500 and `assertSessionHasErrors` fails.
- 'never hands a command\'s tag to the jobs a web request dispatches …': the job's payload contains `audit.console_command`. Without that assertion, the worker would tag the entry `via=console` (checked while planning).
- 'drops a queued invitation mail …': the `cancelled` and `accepted` rows leave 2 rows in `failed_jobs` (`ModelNotFoundException` while unserialising, and `LogicException` from `acceptUrl()`). The `expired` row delivers both mails.

These pass already; Step 7 mutation-checks them:
- 'keeps a command\'s tag on the entries its queued jobs write in a worker (G6-02)': new coverage of today's hidden-Context propagation.
- 'delivers both queued invitation mails while the invitation is open': the positive control for the worker path.

- [ ] **Step 3: The audit trail (G4-01, G4-03, G6-04)**

`app/Listeners/RecordAuthenticationActivity.php`: in `handle()`, replace the R1 comment and condition:

```php
        // R1: anyone can type an address into a host's reset form, so a tenant host's log names only its own
        // members. Not a super admin either, also not one promoted from this organisation, who keeps its
        // organisation_id (G4-01): the host's admins could then find the platform's super admins. The link goes
        // to the user's own host (R1). Central hosts record every reset.
        $organisation = Tenant::current();

        if (($event instanceof PasswordResetLinkSent || $event instanceof PasswordReset)
            && $organisation !== null
            && ($user->organisation_id !== $organisation->id || $user->isSuperAdmin())) {
            return;
        }
```

The class docblock already says "never for another organisation's member or a super admin (R1)", so it stays.

`app/Actions/Audit/LogActivity.php`: replace the first paragraph of the class docblock:

```php
/**
 * Enriches every entry before it is saved (§4.8): the organisation tag (RL-5); for a routed web request only,
 * the IP address and the user agent (valid UTF-8, at most 500 characters, G4-03); outside one, while an Artisan
 * command runs, properties.via = console and the command's name (CD-7, ConsoleOrigin). Registered as
 * activitylog.actions.log_activity.
```

In `beforeActivityLogged()`, replace the `user_agent` line:

```php
                'user_agent' => $this->boundedUserAgent($request->userAgent()),
```

Add this method right before `organisationIdFor()`. Two details:
- It is an instance method. Rector's `LocallyCalledStaticMethodToNonStaticRector` would rewrite a private static one.
- `mb_scrub()` returns `string`, so Larastan needs no guard. `mb_convert_encoding()` would return `array|string|false`.

```php
    /**
     * The header as Laravel's database session handler keeps it (G4-03): valid UTF-8, invalid bytes replaced by "?",
     * at most 500 characters. A failed sign-in writes an entry for any visitor, so the raw header must neither fill
     * the log nor make PostgreSQL refuse the insert.
     */
    private function boundedUserAgent(?string $userAgent): ?string
    {
        return $userAgent === null ? null : mb_substr(mb_scrub($userAgent, 'UTF-8'), 0, 500, 'UTF-8');
    }
```

`app/Actions/Audit/ConsoleOrigin.php`: replace the class docblock:

```php
/**
 * Which Artisan command is running, for the audit log (CD-7, RL-9). Hidden context: never written to the logs,
 * carried into the jobs a command dispatches, and replaced per job by a queue worker, so a worker's jobs keep the
 * origin they were dispatched with. A nested Artisan::call() keeps the outer command. An Artisan::call() inside a
 * routed web request (the setup wizard's migrate and db:seed) is never an origin: the request owns its entries and
 * the jobs it dispatches, and a worker, which has no route, would otherwise tag them (G6-04).
 */
```

Replace `enter()`:

```php
    public static function enter(?string $command): void
    {
        if ($command === null || $command === '' || in_array($command, self::WORKERS, true) || self::command() !== null || request()->route() !== null) {
            return;
        }

        Context::addHidden(self::CONTEXT_KEY, $command);
    }
```

`LogActivity`'s own `$request->route() === null` check stays as the backstop for an entry the request writes itself (the existing test 'leaves entries a web request writes during Artisan::call untagged'). A console process and a worker have no route (`SetRequestForConsole` binds a plain request), so real commands are tagged as before.

- [ ] **Step 4: The invitation mails (G4-05)**

`app/Mail/InvitationMailable.php` (new):

```php
<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\App;
use LogicException;

/**
 * The invitation mails (§4.5). Queued after commit, so a rolled-back invite sends nothing and a transport failure
 * leaves a remindable invitation (W10). The job carries the invitation's id, never the model (G4-05): the worker
 * reads the invitation again under the job's tenant (JobTenancy) and sends nothing once it was cancelled, accepted
 * or has expired, instead of failing with a job that `queue:retry` can only fail again. The accept link is built
 * when the worker renders the mail, on the organisation's host (TEN-6, FU3-14; CV-12, X13), and dates are written
 * in the mail's locale (X11).
 */
abstract class InvitationMailable extends Mailable implements ShouldQueue
{
    use Queueable;

    public readonly int $invitationId;

    public function __construct(Invitation $invitation)
    {
        $this->invitationId = $invitation->id;
        $this->afterCommit();
    }

    /**
     * Sends only while the invitation still waits for its invitee (G4-05).
     *
     * @param  MailFactory|Mailer  $mailer
     */
    public function send($mailer): ?SentMessage
    {
        $invitation = Invitation::query()->find($this->invitationId);

        if ($invitation === null || $invitation->isAccepted() || $invitation->token === null || $invitation->isExpired()) {
            return null;
        }

        return parent::send($mailer);
    }

    /** The stored invitation; the mail is built from it when it is sent or rendered, never when it is queued. */
    protected function invitation(): Invitation
    {
        return Invitation::query()->with('organisation')->find($this->invitationId)
            ?? throw new LogicException("Invitation [{$this->invitationId}] no longer exists.");
    }

    protected function organisationName(Invitation $invitation): string
    {
        return $invitation->organisation->name
            ?? throw new LogicException("Invitation [{$invitation->id}] has no active organisation.");
    }

    /** The expiry date in the mail's locale (X11); the mailer sets the app locale while it renders. */
    protected function expiresOn(Invitation $invitation): string
    {
        $expiresAt = $invitation->expires_at->copy();
        $expiresAt->locale(App::currentLocale());

        return $expiresAt->isoFormat('LL');
    }
}
```

Why it is built this way:
- **The id, not the model.** The job keeps `int $invitationId` only, so unserialising never touches the database, and a cancelled invitation can no longer throw `ModelNotFoundException` before `send()` runs. `SerializesModels` is gone. `deleteWhenMissingModels` would not help: `Queue::createPayload()` reads it from `SendQueuedMailable`, not from the mailable.
- **The check sits in `send()`.** That is what `SendQueuedMailable::handle()` calls, and returning `null` there ends the job successfully.
- **No cached model.** `invitation()` reads the row again on each call instead of caching it on the mailable. Nothing resolved before queueing can end up serialised into the payload, and a mail costs three primary-key reads.
- **The job's tenant.** The worker reads under the job's tenant, because `JobTenancy` binds `tenant_id`. A job run centrally (`tenant_id` null, as `RemindInvitationTest`'s CV-12 case forces) reads through `CentralContext`, where strict mode allows the id lookup.
- **`render()` too.** `InviteUserTest`'s Markdown case calls `render()`, which builds the mail through the same `invitation()`. That test binds the organisation's tenant, so the scoped read finds the row.
- **Expired counts as gone.** The link would only reach the "expired" page. `RemindInvitation` renews `expires_at` before it queues a reminder, so a reminder for a once-expired invitation is still sent.

`app/Mail/InvitationMail.php` (replace the file):

```php
<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The invitation mail (§4.5); see InvitationMailable for when it is sent and how its link is built. */
final class InvitationMail extends InvitationMailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('You have been invited to :organisation', ['organisation' => $this->organisationName($this->invitation())]),
        );
    }

    public function content(): Content
    {
        $invitation = $this->invitation();

        return new Content(
            markdown: 'mail.invitation',
            with: [
                'organisation' => $this->organisationName($invitation),
                'acceptUrl' => $invitation->acceptUrl(),
                'expiresOn' => $this->expiresOn($invitation),
            ],
        );
    }
}
```

`app/Mail/InvitationReminderMail.php` (replace the file):

```php
<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The invitation reminder (§4.5): like InvitationMail, it rebuilds the accept link in the worker, also after a slug
 * rename (CV-12, X13).
 */
final class InvitationReminderMail extends InvitationMailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Reminder: your invitation to :organisation is waiting', ['organisation' => $this->organisationName($this->invitation())]),
        );
    }

    public function content(): Content
    {
        $invitation = $this->invitation();

        return new Content(
            markdown: 'mail.invitation-reminder',
            with: [
                'organisation' => $this->organisationName($invitation),
                'acceptUrl' => $invitation->acceptUrl(),
                'expiresOn' => $this->expiresOn($invitation),
            ],
        );
    }
}
```

The two Blade templates (`resources/views/mail/invitation*.blade.php`) read `$organisation`, `$acceptUrl` and `$expiresOn`, which `content()` still passes, so they stay unchanged. Mailable also hands every public property to the view, so the view gets `$invitationId` as well; the templates do not use it.

- [ ] **Step 5: Copy**

No new `__()` key. The subjects and templates keep their keys, and the `LogicException` messages stay untranslated. `LangCoverageTest` in Step 6 confirms it.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Audit tests/Feature/Invitations tests/Feature/Console/CreateOrganisationCommandTest.php tests/Feature/Setup/Steps/DatabaseStepTest.php tests/Feature/LangCoverageTest.php tests/Arch`

Then `vendor/bin/pest tests/Feature` (sequential, one long-lived process): the two daemon-worker tests of `InvitationMailTest` must also pass there, not only when the file runs alone. Without `'--memory' => 0` the worker stops after the first of the two jobs once the process passes 128 MB, and `jobs` keeps 1 row.

Expected: PASS. The arch preset (`tests/Arch/PresetsTest.php`, the Laravel preset) accepts the abstract base: every class in `App\Mail` extends `Mailable` and implements `ShouldQueue`.

- [ ] **Step 7: Verify by mutation**

Revert each production change locally, confirm the named test fails, restore it, and confirm it passes. Name each mutation and its result in the task report. All six were run while planning.
1. In `RecordAuthenticationActivity::handle()`, drop `|| $user->isSuperAdmin()`. Expect 'records nothing in its own organisation\'s log when a promoted super admin\'s reset …' to fail with 1 entry.
2. In `LogActivity`, store `$request->userAgent()` again. Expect all three rows of 'stores at most 500 characters of valid UTF-8 …' to fail.
3. In `ConsoleOrigin::enter()`, drop `|| request()->route() !== null`. Expect 'never hands a command\'s tag …' to fail on the payload.
4. In `ConsoleOrigin`, keep the command in a `private static ?string $current` instead of `Context::addHidden()` / `getHidden()` / `forgetHidden()`. Expect 'keeps a command\'s tag on the entries its queued jobs write in a worker (G6-02)' to fail, because the worker sees no command.
5. In `InvitationMailable::send()`, drop `|| $invitation->isExpired()`. Expect the `expired` row of 'drops a queued invitation mail …' to fail with two delivered subjects.
6. In `InvitationMailable::send()`, return `parent::send($mailer)` without the check. Expect the `cancelled` row to fail, because the `invitation()` read throws (one failed job per mail), and the `accepted` row to fail, because `acceptUrl()` throws.

- [ ] **Step 8: CHANGELOG**

Append to the end of `### Fixed` under `## [Unreleased]`:

```markdown
- A password reset of a super admin who was promoted from an organisation is no longer recorded in that organisation's audit log.
- The audit log keeps at most 500 characters of a visitor's user agent, as valid UTF-8, so an unusual browser header can no longer break the entry of a failed sign-in.
- A queued invitation mail or reminder whose invitation was cancelled, accepted or has expired before the queue worker ran is dropped instead of ending as a failed job.
- Jobs dispatched while a web request runs an Artisan command itself (the setup wizard's migrations and seeders) no longer write *Command line* entries in the audit log.
```

- [ ] **Step 9: The gate, then commit**

Run the Global Constraints gate:
- `composer check`
- `composer test:pgsql`. Before the fix, the invalid-UTF-8 row of the G4-03 test answered 500 on this run.
- the modules-off run
- `npm run build && composer test:browser`
- `npm run build:setup && git diff --exit-code resources/setup/setup.css`. Task 8 changes no setup view, so expect no diff.
- `php artisan config:clear`

```bash
git add app/Listeners/RecordAuthenticationActivity.php app/Actions/Audit/LogActivity.php app/Actions/Audit/ConsoleOrigin.php \
    app/Mail/InvitationMailable.php app/Mail/InvitationMail.php app/Mail/InvitationReminderMail.php \
    tests/Fixtures/RecordOrganisationCreatedJob.php tests/Feature/Audit/AuthenticationActivityTest.php \
    tests/Feature/Audit/ConsoleOriginTest.php tests/Feature/Invitations/InvitationMailTest.php CHANGELOG.md
git commit -m "fix: harden the audit trail and drop queued mails of a gone invitation

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Branding, dashboard and the Pro grammar (G5-01, G5-03, G5-09, G5-10; C-extra dropped)

Three M5 leftovers:
- **G5-01:** the auth layout names the brand visibly. Today `Branding::name()` sits in an `sr-only` span, so the tenant's name never shows on its sign-in pages, against spec §4.1 ("Tenant name in the brand on tenant hosts"). DEC-29 (5) keeps the default icon as the mark ("only its name changes"), so only the name becomes visible.
- **G5-03:** on a central host the dashboard's recent activity includes the rows of archived organisations, which the cascade soft-deleted, as the viewer's `baseQuery()` already does (RL-7, DEC-28 (7)).
- **G5-09 / G5-10:** one Blade block grammar, `App\Support\BladeDirectives`, read by both the stripper (`FluxProBranches`) and the lint (`tests/Support/FluxProLint.php`).
  - Every built-in directive that compiles to a PHP `if` (`@auth`, `@can`, `@isset`, `@empty(…)`, `@env`, `@error`, `@session`, `@hasSection` …) opens a block, so its `@else` no longer ends the Pro cut early. Today the stripper keeps the Pro tags after such a block, and the view fails to compile without Pro (a 500).
  - Blade comments, `@verbatim` and `@php` blocks are blanked, and `@@else` and `mail@else` are text.
  - The lint scans the whole file, so a tag that spans several lines is read whole. It matches `variant` and `:variant` with either quote, and accepts a `pro-only` marker only right before its `@if`.

Decided while planning:
- **The `<title>` keeps the platform name.** The triage suggested `Branding::name()` there as well. But DEC-29 (5) says "`Branding::platformName()` is always the platform", and `tests/Feature/Layouts/BrandingTest.php` pins `<title>Profile settings - Platform Name</title>` on a tenant host on purpose. Only the visible brand changes.
- **C-extra (the users.index `organisation` error key) is dropped: not real on `a192dba`.** The organisation filter is `wire:model.live="organisation"` (`resources/views/pages/users/index/index.blade.php:41`). Flux's `select` without a label shows no error text, but it would get `data-invalid` when `$errors->has('organisation')`. None of the page's actions raises that key today:
  - `DeleteUser` raises `user` and the lock-out guard's keys, and locks no organisation.
  - `RestoreUser` raises `user` ("Restore the organisation first.") and `email`.
  - `StartImpersonation` raises `reason`.
  - The filters throw nothing.

  Task 7 keeps it that way. `DeleteUser` and `RestoreUser` lock the target's organisation with the new `HostScope::lockOrganisation()`, which refuses nothing, so they raise no `organisation` key. Task 7's only new `HostScope::lockForWrite()` caller is `AcceptInvitation`, on the accept page, not on `users.index`. If a later task makes a `users.index` action raise `organisation`, the filter's error styling is the place to look (DEC-34 notes it).

**Files:**
- Create:
  - `app/Support/BladeDirectives.php`
- Modify:
  - `resources/views/layouts/auth.blade.php` (the visible brand name)
  - `resources/views/pages/dashboard/dashboard.php` (`recentActivity()` reads trashed rows centrally)
  - `app/Support/FluxProBranches.php` (`branches()` on `BladeDirectives`; `strip()` cuts what it returns)
  - `tests/Support/FluxProLint.php` (rewritten on `FluxProBranches::branches()`)
  - `CHANGELOG.md`
- Test:
  - `tests/Feature/Layouts/BrandingTest.php` (G5-01)
  - `tests/Feature/DashboardTest.php` (G5-03)
  - `tests/Feature/FluxPro/ProBranchCompilationTest.php` (G5-09)
  - `tests/Arch/FluxProLayerTest.php` (G5-10; every existing case and 'keeps every view of the kit inside the free Flux surface' stay as they are and pass)

**Interfaces:**
- Consumes:
  - `Branding::name()`, `HostScope::read()`, `HostScope::organisationId()`, `ArchiveOrganisation::handle(User $actor, Organisation $organisation): void` (after Task 7's lock order; still cascade-soft-deletes the organisation's activity rows)
  - the test helpers `onTenant()`, `onAdminHost()`, `asSuperAdmin()`, `recordedActivity()`
- Produces:
  - `final class App\Support\BladeDirectives`:
    - `public const array OPENERS`, `BRANCHES`, `CLOSERS` (lower-case directive names)
    - `public static function blank(string $blade): string`: comments, `@verbatim` and `@php` blocks replaced by spaces of the same length, newlines kept
    - `public static function tokens(string $blade): list<array{kind: 'open'|'branch'|'close', name: string, token: string, offset: int}>`
  - `FluxProBranches::branches(string $blade): list<ProBranch>`, with `@phpstan-type ProBranch array{if: int, start: int, end: int, free: int|null, close: int, hasElse: bool}` (byte offsets; closed `@if (Skv::hasFluxPro())` blocks only, in source order)
  - `FluxProBranches::strip(string $blade): string` (signature unchanged)
  - `Tests\Support\FluxProLint::violations(string $blade): list<string>` (signature and message formats unchanged; a tag's whitespace is collapsed to single spaces in the message)
  - `data-test="auth-brand"` on the auth layout's brand name

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Layouts/BrandingTest.php`: add right before `it('maps a configured accent to the html element …')`:

```php
it('names the organisation visibly above the sign-in form on its host and the platform centrally (§4.1, G5-01)', function (): void {
    config(['branding.name' => 'Platform Name']);
    onTenant(Organisation::factory()->create(['slug' => 'acme', 'name' => 'Acme Corp']));
    $tenantPage = (string) $this->get(route('login'))->assertOk()->getContent();

    onAdminHost();
    $centralPage = (string) $this->get(route('login'))->assertOk()->getContent();

    // DEC-29 (5): the mark stays the default icon; the name next to it is the brand, so it is not screen-reader-only.
    expect($tenantPage)->toMatch('#<span(?![^>]*sr-only)[^>]*data-test="auth-brand"[^>]*>\s*Acme Corp\s*</span>#')
        ->and($centralPage)->toMatch('#<span(?![^>]*sr-only)[^>]*data-test="auth-brand"[^>]*>\s*Platform Name\s*</span>#');
});
```

`tests/Feature/DashboardTest.php`: add `use App\Actions\Organisations\ArchiveOrganisation;` after `use App\Actions\Audit\RecordActivity;`. Then add this test right after `it('lists the last ten entries of this host\'s activity …')`:

```php
it('lists an archived organisation\'s entries in the recent activity of a central host, as the viewer does (RL-7, G5-03)', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($acme)->create();
    Tenant::run($acme, fn () => app(RecordActivity::class)->handle(ActivityEvent::PasswordChanged, $member));
    $superAdmin = asSuperAdmin();
    onAdminHost();
    app(ArchiveOrganisation::class)->handle($superAdmin, $acme);

    $html = (string) $this->get(route('dashboard'))->assertOk()->getContent();

    // The archive cascade soft-deleted the entry; a central host still shows it.
    expect(recordedActivity(ActivityEvent::PasswordChanged)->sole()->trashed())->toBeTrue()
        ->and($html)->toContain(e(ActivityEvent::PasswordChanged->label()));
});
```

`tests/Feature/FluxPro/ProBranchCompilationTest.php`: add `use Tests\Support\FluxProLint;` after `use Illuminate\Support\Facades\Blade;`. Then add these two tests right before `it('compiles every view of the kit without Flux Pro, as view:cache does (§6.7)', …)`. The first heredoc is not a nowdoc, because it interpolates `{$open}` and `{$close}`; its body holds no other `$`.

```php
it('keeps cutting a Pro branch past the @else of a nested block directive, as the lint reads it (G5-09)', function (string $open, string $close): void {
    $blade = <<<BLADE
        @if (Skv::hasFluxPro())
            {$open}
                <p>inner</p>
            @else
                <flux:chart />
            {$close}
            <flux:date-picker wire:model="from" />
        @else
            <flux:input type="date" wire:model="from" />
        @endif
        BLADE;

    $stripped = FluxProBranches::strip($blade);

    expect($stripped)->not->toContain('<flux:chart')->not->toContain('<flux:date-picker')
        ->toContain('<flux:input type="date" wire:model="from" />')
        ->and(substr_count($stripped, "\n"))->toBe(substr_count($blade, "\n"))
        ->and(FluxProLint::violations($blade))->toBe([]);
})->with([
    '@unless' => ['@unless ($compact)', '@endunless'],
    '@auth' => ['@auth', '@endauth'],
    '@guest' => ['@guest', '@endguest'],
    '@can' => ["@can('users.view')", '@endcan'],
    '@cannot' => ["@cannot('users.view')", '@endcannot'],
    '@canany' => ["@canany(['users.view', 'roles.view'])", '@endcanany'],
    '@isset' => ['@isset($user)', '@endisset'],
    '@empty with arguments' => ['@empty($users)', '@endempty'],
    '@env' => ["@env('local')", '@endenv'],
    '@production' => ['@production', '@endproduction'],
    '@session' => ["@session('status')", '@endsession'],
    '@error' => ["@error('email')", '@enderror'],
    '@hasSection' => ["@hasSection('title')", '@endif'],
    'upper case' => ['@AUTH', '@ENDAUTH'],
]);

it('reads @forelse\'s @empty, escaped directives and @verbatim blocks as Blade does (G5-09)', function (): void {
    $blade = <<<'BLADE'
        @if (Skv::hasFluxPro())
            @forelse ($rows as $row)
                <flux:chart />
            @empty
                <p>none</p>
            @endforelse
            <p>@@else is text, and so is mail@else.test</p>
            @verbatim @else @endverbatim
            <flux:date-picker />
        @else
            <p>free</p>
        @endif
        BLADE;

    $stripped = FluxProBranches::strip($blade);

    expect($stripped)->not->toContain('<flux:chart')->not->toContain('<flux:date-picker')
        ->toContain('<p>free</p>')
        ->and(substr_count($stripped, "\n"))->toBe(substr_count($blade, "\n"));
});
```

`tests/Arch/FluxProLayerTest.php`: add these three tests right before `it('keeps every view of the kit inside the free Flux surface or a Pro branch (§6.7)', …)`:

```php
it('reads a Pro select variant over several lines and in either attribute form (G5-10)', function (string $tag, string $violation): void {
    expect(FluxProLint::violations($tag))->toBe([$violation]);
})->with([
    'over several lines' => ["<flux:select\n    variant=\"listbox\"\n    wire:model=\"roles\">", 'line 1: <flux:select variant="listbox" wire:model="roles">'],
    'bound' => ["<flux:select :variant=\"'listbox'\" wire:model=\"roles\">", "line 1: <flux:select :variant=\"'listbox'\" wire:model=\"roles\">"],
    'single quotes' => ["<p>Roles</p>\n<flux:select variant='combobox' wire:model=\"roles\">", "line 2: <flux:select variant='combobox' wire:model=\"roles\">"],
]);

it('ignores directives and tags inside Blade comments (G5-10)', function (): void {
    $blade = <<<'BLADE'
        @if (Skv::hasFluxPro())
            {{-- @if ($legacy) --}}
            <flux:date-picker wire:model="from" />
        @else
            <flux:input type="date" wire:model="until" />
        @endif
        BLADE;

    expect(FluxProLint::violations($blade))->toBe(['line 1: @if (Skv::hasFluxPro()) binds wire:model [from] in the Pro branch but [until] in the free @else'])
        ->and(FluxProLint::violations('{{-- <flux:command /> was the old palette --}}'))->toBe([]);
});

it('accepts a pro-only marker only right before its @if (G5-10)', function (): void {
    $distant = <<<'BLADE'
        {{-- pro-only (§6.7): free Flux has no sign-in trend --}}
        <div>
            <p>Stats</p>
        </div>
        @if (Skv::hasFluxPro())
            <flux:chart />
        @endif
        BLADE;

    expect(FluxProLint::violations($distant))->toBe(['line 5: @if (Skv::hasFluxPro()) has no free @else; mark a feature without a free variant (§6.7) with {{-- pro-only --}}'])
        ->and(FluxProLint::violations("{{-- pro-only (§6.7) --}} @if (Skv::hasFluxPro())\n<flux:chart />\n@endif"))->toBe([]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Layouts/BrandingTest.php tests/Feature/DashboardTest.php tests/Feature/FluxPro tests/Arch/FluxProLayerTest.php`

Expected: FAIL, as follows (checked while planning):
- 'names the organisation visibly above the sign-in form …': no `data-test="auth-brand"` span; the name is only in `<span class="sr-only">`.
- 'lists an archived organisation\'s entries in the recent activity of a central host …': the entry is soft-deleted (that part passes), and the page does not contain the label.
- 'keeps cutting a Pro branch past the @else of a nested block directive …': 13 of 14 rows keep `<flux:chart` after `strip()`. The `@unless` row passes already, because today's grammar tracks `@unless`; it stays as a regression row.
- 'reads @forelse\'s @empty, escaped directives and @verbatim blocks …': `<flux:date-picker` is kept, because `@@else` and the `@else` inside `@verbatim` end the cut.
- 'reads a Pro select variant over several lines and in either attribute form': all three rows return `[]`.
- 'ignores directives and tags inside Blade comments': the commented `@if` swallows the `@else`, so `[]` comes back instead of the binding violation.
- 'accepts a pro-only marker only right before its @if': the distant marker still counts, so `[]` comes back.

- [ ] **Step 3: The brand on auth pages and the central dashboard (G5-01, G5-03)**

`resources/views/layouts/auth.blade.php`: replace the line `<span class="sr-only">{{ \App\Support\Branding::name() }}</span>` with:

```blade
                {{-- §4.1: the organisation's name on its host, the platform's centrally; the mark stays the default icon (DEC-29 (5)). --}}
                <span class="text-sm text-zinc-800 dark:text-white" data-test="auth-brand">{{ \App\Support\Branding::name() }}</span>
```

The span sits inside the existing home link (`<a … class="flex flex-col items-center gap-2 font-medium">`), so the name stays the link's accessible text.

`resources/views/pages/dashboard/dashboard.php`: in `recentActivity()`, replace the docblock's first line and the `$entries` query:

```php
    /**
     * The last ten entries of this host's activity, without IP addresses, user agents or field changes (RL-11); on a
     * central host archived organisations' entries included, as in the viewer (RL-7).
     *
     * @return list<array{event: string, causer: string|null, when: string}>|null
     */
```

```php
        /** @var list<Activity> $entries */
        $entries = HostScope::read(function (): array {
            $query = Activity::query();

            // Central hosts also list archived organisations' rows, soft-deleted by the cascade, as the viewer does (RL-7).
            if (HostScope::organisationId() === null) {
                $query->withTrashed();
            }

            return $query->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get()->all();
        });
```

The causer names come from `UserNames::of()`, which already reads archived users (the viewer uses it the same way).

- [ ] **Step 4: One Blade grammar for the stripper and the lint (G5-09, G5-10)**

`app/Support/BladeDirectives.php` (new). The lists come from `vendor/laravel/framework/src/Illuminate/View/Compilers/Concerns/*`: every `compile*` method that emits `if (…):`, `elseif` / `else:`, or `endif;`. `@forelse` is left out on purpose. Its `@empty` without arguments is its own branch, and `@forelse` / `@endforelse` balance each other, so they never touch an `if` block's `@else`. The argument scanner mirrors Blade's `[ \t]*` between the name and `(`, so `@empty` at the end of a line is the forelse branch, and `@empty ($x)` is an opener.

```php
<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The block structure of a Blade template as Blade compiles it (§6.7, G5-09): every built-in directive that compiles
 * to a PHP `if` opens a block that an `@else` can belong to, and an `@else` always belongs to the innermost open
 * block. Directive names are matched without regard to case, as Blade's compile methods are; `@@if` is an escaped
 * literal, `email@if` is text, and the arguments are read up to their balanced closing parenthesis, outside quotes.
 * Blade comments, `@verbatim` and `@php` blocks are never compiled, so nothing inside them counts.
 * FluxProBranches (the stripper) and the Pro lint (tests/Support/FluxProLint.php) read templates only through this.
 */
final class BladeDirectives
{
    /** Directives that compile to `if (…):`. `@empty` opens a block only with arguments; without, it is @forelse's branch. */
    public const array OPENERS = [
        'if', 'unless', 'isset', 'empty', 'auth', 'guest', 'can', 'cannot', 'canany', 'env', 'production',
        'session', 'error', 'context', 'hassection', 'sectionmissing', 'hasstack', 'once', 'pushif', 'pushonce', 'prependonce',
    ];

    /** Directives that start another branch of the innermost open block. */
    public const array BRANCHES = ['else', 'elseif', 'elseauth', 'elseguest', 'elsecan', 'elsecannot', 'elsecanany', 'elsepushif', 'elsepush'];

    /** Directives that compile to `endif;`. `@hasSection`, `@sectionMissing` and `@hasstack` close with `@endif`. */
    public const array CLOSERS = [
        'endif', 'endunless', 'endisset', 'endempty', 'endauth', 'endguest', 'endcan', 'endcannot', 'endcanany', 'endenv',
        'endproduction', 'endsession', 'enderror', 'endcontext', 'endonce', 'endpushif', 'endpushonce', 'endprependonce',
    ];

    /**
     * The template with Blade comments, `@verbatim` blocks and `@php` blocks replaced by spaces of the same length.
     * Newlines are kept, so offsets and line numbers still match the source.
     */
    public static function blank(string $blade): string
    {
        return (string) preg_replace_callback(
            '/\{\{--.*?--\}\}|(?<!@)@verbatim\b.*?@endverbatim\b|(?<!@)@php\b(?![ \t]*\().*?@endphp\b/si',
            fn (array $block): string => (string) preg_replace('/[^\n]/', ' ', $block[0]),
            $blade,
        );
    }

    /**
     * The block directives of a template, in source order: what each one does to the block structure, its lower-case
     * name, its full text (arguments included) and its byte offset in the source.
     *
     * @return list<array{kind: 'open'|'branch'|'close', name: string, token: string, offset: int}>
     */
    public static function tokens(string $blade): array
    {
        $scanned = self::blank($blade);
        preg_match_all('/(?<![\w@])@([a-z]+)\b/i', $scanned, $matches, PREG_OFFSET_CAPTURE);

        $tokens = [];

        foreach ($matches[1] as [$name, $nameOffset]) {
            $name = strtolower($name);
            $offset = $nameOffset - 1;
            $end = self::argumentsEnd($scanned, $nameOffset + strlen($name));

            $kind = match (true) {
                in_array($name, self::OPENERS, true) && ($name !== 'empty' || $end !== null) => 'open',
                in_array($name, self::BRANCHES, true) => 'branch',
                in_array($name, self::CLOSERS, true) => 'close',
                default => null,
            };

            if ($kind !== null) {
                $length = ($end ?? $nameOffset + strlen($name)) - $offset;
                $tokens[] = ['kind' => $kind, 'name' => $name, 'token' => substr($blade, $offset, $length), 'offset' => $offset];
            }
        }

        return $tokens;
    }

    /** The offset right after the directive's balanced `(…)`, or null when no arguments follow on the same line. */
    private static function argumentsEnd(string $scanned, int $from): ?int
    {
        $position = $from + strspn($scanned, " \t", $from);

        if (($scanned[$position] ?? '') !== '(') {
            return null;
        }

        $depth = 0;
        $quote = null;
        $length = strlen($scanned);

        for ($index = $position; $index < $length; $index++) {
            $character = $scanned[$index];

            if ($quote !== null) {
                if ($character === '\\') {
                    $index++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')' && --$depth === 0) {
                return $index + 1;
            }
        }

        return null;
    }
}
```

`app/Support/FluxProBranches.php` (replace the file). `strip()` keeps its behaviour for the kit's views: the line count and the `STALE` guard stay, nested ranges are skipped, and an unclosed `@if` is not cut (Blade cannot compile it anyway).

```php
<?php

declare(strict_types=1);

namespace App\Support;

/**
 * §6.7: Blade resolves every `<flux:…>` tag while it compiles a view, not when it renders it, so a Pro-only tag
 * inside `@if (Skv::hasFluxPro())` still fails to compile when Flux Pro is absent. Without Pro, this removes the
 * true branch of each such `@if` before Blade compiles the view (also for `view:cache`). The free `@else` branch
 * stays, and the line count is kept so compiled line numbers still match the source. The block structure comes from
 * BladeDirectives, so an `@else` of a nested `@auth`, `@can`, `@isset`, `@error` … never ends the Pro branch (G5-09);
 * the Pro lint reads the same branches().
 *
 * @phpstan-type ProBranch array{if: int, start: int, end: int, free: int|null, close: int, hasElse: bool}
 */
final class FluxProBranches
{
    private const string PRO_IF = '/^@if\s*\(\s*\\\\?(App\\\\Support\\\\)?Skv::hasFluxPro\(\)\s*\)$/';

    /** Rendered only when Flux Pro was installed after the view was compiled without it. */
    private const string STALE = "<?php throw new \\LogicException('Flux Pro was installed after this view was compiled. Run php artisan view:clear.'); ?>";

    public static function strip(string $blade): string
    {
        if (! str_contains($blade, 'hasFluxPro')) {
            return $blade;
        }

        return self::cut($blade, array_map(fn (array $branch): array => [$branch['start'], $branch['end']], self::branches($blade)));
    }

    /**
     * Every `@if (Skv::hasFluxPro())` that Blade would close, in source order, as byte offsets: `if` where the
     * directive starts, [`start`, `end`) the Pro branch, `free` where the content after the first branch directive
     * (`@else`, `@elseif` …) starts or null without one, `close` where its closing directive starts, and whether a
     * plain `@else` belongs to it.
     *
     * @return list<ProBranch>
     */
    public static function branches(string $blade): array
    {
        $branches = [];
        $stack = []; // per open block: its Pro branch while it is collected, or null

        foreach (BladeDirectives::tokens($blade) as $directive) {
            if ($directive['kind'] === 'open') {
                $stack[] = $directive['name'] === 'if' && preg_match(self::PRO_IF, $directive['token']) === 1
                    ? ['if' => $directive['offset'], 'start' => $directive['offset'] + strlen($directive['token']), 'end' => null, 'free' => null, 'hasElse' => false]
                    : null;

                continue;
            }

            $last = array_key_last($stack);

            if ($last === null) {
                continue;
            }

            if ($stack[$last] !== null) {
                $stack[$last]['end'] ??= $directive['offset'];
            }

            if ($directive['kind'] === 'branch') {
                if ($stack[$last] !== null) {
                    $stack[$last]['free'] ??= $directive['offset'] + strlen($directive['token']);
                    $stack[$last]['hasElse'] = $stack[$last]['hasElse'] || $directive['name'] === 'else';
                }

                continue;
            }

            $frame = array_pop($stack);

            if ($frame !== null) {
                $branches[] = [
                    'if' => $frame['if'],
                    'start' => $frame['start'],
                    'end' => $frame['end'] ?? $directive['offset'],
                    'free' => $frame['free'],
                    'close' => $directive['offset'],
                    'hasElse' => $frame['hasElse'],
                ];
            }
        }

        usort($branches, fn (array $a, array $b): int => $a['if'] <=> $b['if']);

        return $branches;
    }

    /** @param  list<array{int, int}>  $cuts  [start, end) byte ranges; a range inside another one is skipped. */
    private static function cut(string $blade, array $cuts): string
    {
        usort($cuts, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $kept = [];
        $end = -1;

        foreach ($cuts as $cut) {
            if ($cut[0] >= $end) {
                $kept[] = $cut;
                $end = $cut[1];
            }
        }

        foreach (array_reverse($kept) as [$start, $stop]) {
            $removed = substr($blade, $start, $stop - $start);
            $blade = substr($blade, 0, $start).self::STALE.str_repeat("\n", substr_count($removed, "\n")).substr($blade, $stop);
        }

        return $blade;
    }
}
```

`tests/Support/FluxProLint.php` (replace the file). The checks and their message formats stay the same; how they read the template changes:
- **Branches.** They come from `FluxProBranches::branches()`.
- **Tags.** `<flux:…>` tags are matched in the comment-blanked text as a whole (quoted attribute values may hold `>`).
- **Pro or free.** A tag is outside every Pro branch when its offset lies outside every [`start`, `end`).
- **The free `@else`.** It renders something when its range still holds text once the comments and the block directives are blanked.
- **`wire:model`.** It is collected per range.
- **Order.** Violations are sorted by offset, a branch's own violations at its closing directive, as the old line-by-line stack reported them.

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\BladeDirectives;
use App\Support\FluxProBranches;
use Illuminate\Support\Facades\File;

/**
 * §6.7 / RL-13: Pro-only Flux components may appear only in the true branch of `@if (Skv::hasFluxPro())`.
 * A tag is free when free Flux (or a project override) has its view, or when it is an icon; `flux:select`
 * with a Pro variant (`variant` or `:variant`, either quote) is Pro (DEC-25).
 *
 * Each Pro `@if` also needs a free `@else` that renders something and binds the same `wire:model` properties
 * as the Pro branch (names only, modifiers ignored). A feature without a free variant (§6.7: the ⌘K palette,
 * the sign-in trend) is marked with a `{{-- pro-only … --}}` comment right before its `@if` (on the same line or
 * the line above) instead.
 *
 * The branches come from FluxProBranches::branches(), the stripper's own grammar (G5-09, G5-10): every block
 * directive that can take an `@else` nests, Blade comments are no directives, and a tag is read as a whole, also
 * when it spans several lines. An `@else` / `@elseif` ends the Pro branch of its `@if`; `@elseif (Skv::hasFluxPro())`
 * is not a supported pattern, so the free branch is always the `@else`.
 *
 * @phpstan-import-type ProBranch from FluxProBranches
 */
final class FluxProLint
{
    private const string FLUX_TAG = '/<flux:[a-z0-9.\-]+(?:\s(?:[^>"\']|"[^"]*"|\'[^\']*\')*)?>?/i';

    private const string PRO_VARIANT = '/\s:?variant\s*=\s*(["\'])(?:(?!\1).)*?\b(?:listbox|combobox|custom)\b/is';

    private const string PRO_ONLY_MARKER = '/\{\{--\s*pro-only\b(?:(?!--\}\}).)*--\}\}[ \t]*(?:\R[ \t]*)?$/s';

    private const string WIRE_MODEL = '/\bwire:model(?:\.[\w.-]+)?\s*=\s*"([^"]*)"/';

    /** @var list<string>|null */
    private static ?array $free = null;

    /** @return list<string> */
    public static function violations(string $blade): array
    {
        $scanned = BladeDirectives::blank($blade);
        $branches = FluxProBranches::branches($blade);
        $found = []; // [offset, message]

        preg_match_all(self::FLUX_TAG, $scanned, $tags, PREG_OFFSET_CAPTURE);

        foreach ($tags[0] as [$tag, $offset]) {
            if (self::isPro($tag) && ! self::insideProBranch($branches, $offset)) {
                $found[] = [$offset, 'line '.self::line($blade, $offset).': '.preg_replace('/\s+/', ' ', $tag)];
            }
        }

        $content = self::withoutDirectives($blade, $scanned);

        foreach ($branches as $branch) {
            foreach (self::branchViolations($blade, $scanned, $content, $branch) as $violation) {
                $found[] = [$branch['close'], $violation];
            }
        }

        usort($found, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_map(fn (array $violation): string => $violation[1], $found);
    }

    /** @return list<string> Free component names such as "sidebar.item" or "button". */
    public static function freeComponents(): array
    {
        if (self::$free !== null) {
            return self::$free;
        }

        $names = [];

        foreach ([base_path('vendor/livewire/flux/stubs/resources/views/flux'), resource_path('views/flux')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach (File::allFiles($root) as $file) {
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
                $name = preg_replace(['/\.blade\.php$/', '#/index$#'], '', $relative);
                $names[] = str_replace('/', '.', (string) $name);
            }
        }

        return self::$free = array_values(array_unique($names));
    }

    /**
     * @param  ProBranch  $branch
     * @return list<string>
     */
    private static function branchViolations(string $blade, string $scanned, string $content, array $branch): array
    {
        $at = 'line '.self::line($blade, $branch['if']).': @if (Skv::hasFluxPro())';

        if (! $branch['hasElse'] || $branch['free'] === null) {
            $marked = preg_match(self::PRO_ONLY_MARKER, substr($blade, 0, $branch['if'])) === 1;

            return $marked ? [] : ["{$at} has no free @else; mark a feature without a free variant (§6.7) with {{-- pro-only --}}"];
        }

        $free = $branch['free'];

        if (trim(substr($content, $free, $branch['close'] - $free)) === '') {
            return ["{$at} has an empty free @else"];
        }

        [$pro, $freeModels] = array_map(function (string $text): string {
            preg_match_all(self::WIRE_MODEL, $text, $models);
            $names = array_values(array_unique($models[1]));
            sort($names);

            return implode(', ', $names);
        }, [substr($scanned, $branch['start'], $branch['end'] - $branch['start']), substr($scanned, $free, $branch['close'] - $free)]);

        return $pro === $freeModels ? [] : ["{$at} binds wire:model [{$pro}] in the Pro branch but [{$freeModels}] in the free @else"];
    }

    /** The scanned template with every block directive blanked too: what is left is markup. */
    private static function withoutDirectives(string $blade, string $scanned): string
    {
        foreach (BladeDirectives::tokens($blade) as $directive) {
            $scanned = substr_replace($scanned, str_repeat(' ', strlen($directive['token'])), $directive['offset'], strlen($directive['token']));
        }

        return $scanned;
    }

    /** @param  list<ProBranch>  $branches */
    private static function insideProBranch(array $branches, int $offset): bool
    {
        return array_any($branches, fn (array $branch): bool => $offset >= $branch['start'] && $offset < $branch['end']);
    }

    private static function line(string $blade, int $offset): int
    {
        return substr_count($blade, "\n", 0, $offset) + 1;
    }

    private static function isPro(string $tag): bool
    {
        preg_match('/^<flux:([a-z0-9.\-]+)/i', $tag, $match);
        $name = strtolower($match[1] ?? '');

        if ($name === '' || str_starts_with($name, 'icon.') || $name === 'icon') {
            return false;
        }

        if ($name === 'select' && preg_match(self::PRO_VARIANT, $tag) === 1) {
            return true;
        }

        return ! in_array($name, self::freeComponents(), true);
    }
}
```

- [ ] **Step 5: Copy**

No new `__()` key: the brand name is a value, and the lint's messages are test output. `LangCoverageTest` in Step 6 confirms it.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Layouts tests/Feature/DashboardTest.php tests/Feature/FluxPro tests/Arch tests/Feature/LangCoverageTest.php`

Expected: PASS. Without Flux Pro, the two `ProVariantsTest` cases skip ('Flux Pro is not installed (OD-1)'). 'compiles every view of the kit without Flux Pro' and 'keeps every view of the kit inside the free Flux surface' run every kit view through the new grammar; both were green on the planning clone.

- [ ] **Step 7: Verify by mutation**

Revert each production change locally, confirm the named test fails, restore it, and confirm it passes. Name each mutation and its result in the task report. Mutations 1–5 were run while planning; 6 and 7 are what Step 2 showed.
1. Drop `'auth'` from `BladeDirectives::OPENERS`. Expect the `@auth` and `upper case` rows of 'keeps cutting a Pro branch past the @else …' to fail.
2. In `BladeDirectives::tokens()`, write the lookbehind as `(?<!\w)` instead of `(?<![\w@])`. Expect 'reads @forelse\'s @empty, escaped directives and @verbatim blocks …' to fail.
3. In `BladeDirectives::blank()`, drop the `\{\{--.*?--\}\}|` alternative. Expect 'ignores directives and tags inside Blade comments' and the existing 'flags an empty free @else' to fail.
4. In `FluxProLint::PRO_ONLY_MARKER`, drop the final `$`. Expect 'accepts a pro-only marker only right before its @if' to fail.
5. In `FluxProLint::PRO_VARIANT`, accept only `"` as the quote. Expect the `single quotes` row of 'reads a Pro select variant …' to fail.
6. Restore the `sr-only` span in the auth layout. Expect 'names the organisation visibly above the sign-in form …' to fail.
7. Drop the `withTrashed()` branch in `recentActivity()`. Expect 'lists an archived organisation\'s entries …' to fail.

- [ ] **Step 8: CHANGELOG**

Append to the end of `### Fixed` under `## [Unreleased]`:

```markdown
- The sign-in pages show the organisation's name under the mark on its own address, and the platform name on the central addresses; until now the name was there for screen readers only.
- On a central address, the dashboard's recent activity also lists the entries of archived organisations, as the audit log does.
- Without Flux Pro, a Pro branch that holds `@auth`, `@can`, `@isset`, `@error` or another block with its own `@else` no longer breaks the page; the Flux Pro check reads the views the same way, including tags over several lines, a bound or single-quoted `variant`, Blade comments and a `pro-only` marker that is not right before its `@if`.
```

- [ ] **Step 9: The gate, then commit**

Run the Global Constraints gate:
- `composer check`
- `composer test:pgsql`
- the modules-off run
- `npm run build && composer test:browser`. The auth pages now show the brand name; no browser test reads the brand.
- `npm run build:setup && git diff --exit-code resources/setup/setup.css`. Task 9 changes no setup view, so expect no diff; `layouts/auth.blade.php` is not under the setup sources.
- `php artisan config:clear`

```bash
git add app/Support/BladeDirectives.php app/Support/FluxProBranches.php tests/Support/FluxProLint.php \
    resources/views/layouts/auth.blade.php resources/views/pages/dashboard/dashboard.php \
    tests/Feature/Layouts/BrandingTest.php tests/Feature/DashboardTest.php tests/Feature/FluxPro/ProBranchCompilationTest.php \
    tests/Arch/FluxProLayerTest.php CHANGELOG.md
git commit -m "fix: show the brand on auth pages, list archived rows on the central dashboard and read pro branches with one blade grammar

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: The setup gate and the Herd stage (L7b-11, L7b-14, L7b-30, L7b-35, G6-07, G6-08, L7b-16, L7b-18; CD-7)

**Files:**
- Modify:
  - `app/Setup/Http/EnsureSetupAccess.php` (accepting the token invalidates the session first: L7b-11)
  - `app/Setup/Http/DirectRequest.php` (more proxy and tunnel headers refused: L7b-14)
  - `app/Setup/Detection.php` (`fromArray()` checks every key's presence and the host, scheme and port values: L7b-30, L7b-35)
  - `app/Setup/Detector.php` (never produces a port that `fromArray()` refuses: L7b-35)
  - `app/Setup/SetupMode.php` (`homeRedirect()` and `homeHostProblem()` become one `home()`: G6-07)
  - `routes/web.php` (`/` calls `home()` once)
  - `app/Setup/Wizard/HerdStage.php` (the second-factor login goes to `Hosts::homeOf($user)`: G6-08, CD-7)
  - `app/Setup/Steps/HerdSiteStep.php` (`manualCommand()` names `herd isolate`; `confirm()` requires PHP 8.4: L7b-16, L7b-18)
  - `app/Setup/Wizard/WizardRunner.php` (`launchFailed()` docblock only)
  - `docs/superpowers/specs/2026-10-10-skv3-design.md` (§5.3.4 access gate sentence)
  - `CHANGELOG.md`
- Test:
  - `tests/Feature/Setup/Wizard/SetupAccessTest.php`
  - `tests/Feature/Setup/SetupModeTest.php`
  - `tests/Feature/Setup/Wizard/WizardStateTest.php`
  - `tests/Feature/Setup/DetectorTest.php`
  - `tests/Feature/Setup/Wizard/HerdStageTest.php`
  - `tests/Feature/Setup/Steps/HerdSiteStepTest.php`
  - `tests/Feature/Console/RunStepCommandTest.php`

No lang key is added: the `Detection` exceptions stay untranslated developer messages, like the ones they replace, and every other string already exists. No setup view changes, so `resources/setup/setup.css` stays as it is.

**Interfaces:**
- Consumes:
  - `useSetupWizard(?SetupContext $context = null): SetupContext`, `setupSandbox(?Detection $detection = null, string $prefix = ''): SetupContext`, `withoutPostgresProbe(bool $reachable = false): void` (`tests/Pest.php`)
  - `SetupHostGuard::allows(Request $request, ?string $pinned): bool`, `SetupHostGuard::pinnedWizardHost(string $basePath): ?string`, `SetupHostGuard::resolve(string $httpHost, Toolchain $toolchain, string $folder): array{host: ?string, problem: ?string, suggestion: ?string}`
  - `SetupHost::normalise(string $host): ?string`
  - `Hosts::homeOf(User $user): string`, `Hosts::admin(): string`, `Hosts::url(string $host, string $path): string`
  - `Tests\Fixtures\InlineStepLauncher` (its public `$refuses` flag), `Tests\Fixtures\CallbackSetupStep`
- Produces:
  - `SetupMode::home(Request $request): array{redirect: ?string, problem: array{suggestion: ?string, problem: string}|null}`. It replaces `SetupMode::homeRedirect()` and `SetupMode::homeHostProblem()`, which are removed (their only caller is `routes/web.php`).
  - `Detection::fromArray(array $data): self` now throws `InvalidArgumentException` for:
    - a missing key, any key
    - a host that `SetupHost::normalise()` changes
    - a scheme other than `http` / `https`
    - a `port` or `herd_smtp_port` outside 1–65535
  - `HerdSiteStep::manualCommand(SetupContext $context): ?string`, which names, in `run()`'s order:
    - `herd link <name>` while Herd does not serve the site
    - `herd isolate 8.4 --site=<name>` while the served site runs PHP below 8.4, or its PHP check fails
    - `herd secure <name>` when HTTPS was chosen
    - null otherwise
  - `HerdSiteStep::confirm(SetupContext $context): ?StepResult` is null while the served site runs PHP below 8.4.

**Mutation checks in this task:** never use `git stash`. Every worktree of the repository shares one stash, so parallel work collides on it. Copy the production file aside, edit or restore it, run the test, then copy it back:

```bash
cp app/Setup/Http/EnsureSetupAccess.php /tmp/m7c-mutation.php   # or any scratch path of your own
# … edit app/Setup/Http/EnsureSetupAccess.php to the mutated form, run the test, see it fail …
cp /tmp/m7c-mutation.php app/Setup/Http/EnsureSetupAccess.php
```

`git show HEAD:<path> > <path>` brings back the version from before the task when a check needs the old code.

- [ ] **Step 1: Write the failing tests for the gate (L7b-11, L7b-14)**

`tests/Feature/Setup/Wizard/SetupAccessTest.php`: in `it('answers 404 behind a proxy or tunnel (§7.2, DEC-24)', …)` replace the dataset line with:

```php
})->with(['X-Forwarded-For', 'X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Forwarded-Port', 'X-Forwarded-Prefix', 'X-Original-Host', 'Forwarded', 'X-Expose-Subdomain', 'Via', 'X-Real-IP', 'True-Client-IP', 'CF-Connecting-IP', 'CF-Ray']);
```

Add this test right before `it('never stores the token in the session, not even as the previous URL (g3-27)', …)`:

```php
it('carries nothing of a planted session over and destroys its file when it accepts the token (L7b-11)', function (): void {
    // Arrange: a session file written beforehand under an id the attacker knows, holding data of their choosing.
    $context = useSetupWizard();
    $planted = Str::random(40);
    session()->getHandler()->write($planted, json_encode(['planted' => 'by-someone-else', '_token' => 'known-csrf-token'], JSON_THROW_ON_ERROR));

    $this->withCookie((string) config('session.cookie'), $planted)
        ->get('/welcome-setup?token='.new SetupToken($context->basePath)->value())
        ->assertRedirect(url('/welcome-setup'));

    expect(session()->getId())->not->toBe($planted)
        ->and(session()->has('planted'))->toBeFalse()
        ->and(session()->token())->not->toBe('known-csrf-token')
        ->and(session(EnsureSetupAccess::SESSION_KEY))->toBe(app(SetupToken::class)->hash())
        ->and(session()->getHandler()->read($planted))->toBe('');
});
```

(The session payload is JSON because `config/session.php` sets `'serialization' => 'json'`.)

`tests/Feature/Setup/SetupModeTest.php`: in `it('never hands the token to a proxied or tunnelled request (§5.3.4, DEC-24)', …)` replace the dataset line with:

```php
})->with(['X-Forwarded-For', 'X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Original-Host', 'Forwarded', 'X-Expose-Subdomain', 'Via', 'X-Real-IP', 'True-Client-IP', 'CF-Connecting-IP', 'CF-Ray']);
```

- [ ] **Step 2: Run them and see them fail**

Run: `php artisan test --compact tests/Feature/Setup/Wizard/SetupAccessTest.php tests/Feature/Setup/SetupModeTest.php`

Expected: 11 failures.
- The five new `SetupAccessTest` rows answer 302 instead of 404.
- The five new `SetupModeTest` rows redirect to `/welcome-setup?token=…` instead of `/login`.
- The L7b-11 test fails on `session()->has('planted')`, which is true: `regenerate()` migrates the planted attributes. Its file also stays readable under the planted id.

- [ ] **Step 3: Refuse the headers and invalidate the planted session**

`app/Setup/Http/DirectRequest.php`: replace the class docblock and the two header constants:

```php
/**
 * A request straight from the local machine (§5.3.4, DEC-24, RL-4): the local environment, a loopback REMOTE_ADDR
 * (127.0.0.0/8 or ::1) read raw, never through the trusted proxies, and none of the headers a proxy or a tunnel adds:
 * no X-Forwarded-*, X-Expose-* or CF-* of any kind, no Forwarded, Via, X-Original-Host, X-Real-IP or True-Client-IP
 * (L7b-14: cloudflared and other tunnels strip X-Forwarded-* but add these). The address alone is not enough,
 * because `herd share` traffic arrives from loopback too; the headers alone are not enough, because `php artisan
 * serve --host=0.0.0.0` lets the LAN in without any (g4-01). A tunnel that adds no header at all (ssh -R, socat)
 * stays indistinguishable from the browser on this machine: the manuals warn against tunnels (DEC-33).
 */
final class DirectRequest
{
    private const array LOOPBACK = ['127.0.0.0/8', '::1'];

    private const array HEADERS = ['forwarded', 'true-client-ip', 'via', 'x-original-host', 'x-real-ip'];

    private const array PREFIXES = ['cf-', 'x-expose-', 'x-forwarded-'];
```

`isDirect()` stays as it is: it already compares lower-cased header names with `HEADERS` and `PREFIXES`.

`app/Setup/Http/EnsureSetupAccess.php`: in the class docblock replace

```php
 * The token arrives once in the query, and its hash then lives in a new (file) session. Seeding a super admin
 * therefore never locks the wizard out, and a session id planted beforehand never becomes authorised (g3-26).
```

with

```php
 * The token arrives once in the query, and its hash then lives in a new (file) session. Seeding a super admin
 * therefore never locks the wizard out. Accepting the token invalidates the session first, so a session id planted
 * beforehand never becomes authorised (g3-26), its file is destroyed and nothing it held carries over (L7b-11).
```

and in `handle()` replace

```php
            abort_unless($this->token->matches($candidate), 404);
            $request->session()->put(self::SESSION_KEY, $this->token->hash());
            $request->session()->regenerate();
```

with

```php
            abort_unless($this->token->matches($candidate), 404);
            // L7b-11: nothing a planted session held carries over, and its file is destroyed; then a new CSRF token.
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->put(self::SESSION_KEY, $this->token->hash());
```

(`invalidate()` is `flush()` + `migrate(true)`: the old id's file is destroyed. After the flush the session has no `_token`, so `regenerateToken()` gives it one in this request, as Fortify's logout does.)

- [ ] **Step 4: Run them and see them pass; verify by mutation**

Run: `php artisan test --compact tests/Feature/Setup/Wizard/SetupAccessTest.php tests/Feature/Setup/SetupModeTest.php`
Expected: PASS.

Mutations (copy the file aside first, see the note above):
1. Put `$request->session()->regenerate();` back in place of `invalidate()` + `regenerateToken()`. The L7b-11 test fails on `has('planted')`.
2. Use `$request->session()->flush(); $request->session()->migrate(false);`. The L7b-11 test fails on `read($planted)`, which still returns the payload.
3. Drop `'via'` from `HEADERS`. The `Via` rows of both datasets fail.

- [ ] **Step 5: Spec, CHANGELOG, commit**

`docs/superpowers/specs/2026-10-10-skv3-design.md` §5.3.4, in the **Access gate** bullet:

Replace
```
no `X-Forwarded-For` / `X-Forwarded-Host` / `X-Original-Host` / `X-Expose-*` header;
```
with
```
a loopback `REMOTE_ADDR` (`127.0.0.0/8`, `::1`) and no header a proxy or tunnel adds (`X-Forwarded-*`, `X-Expose-*`, `CF-*`, `Forwarded`, `Via`, `X-Original-Host`, `X-Real-IP`, `True-Client-IP`; DEC-24, RL-4, L7b-14);
```

Replace
```
the wizard checks the token with `hash_equals` and stores it in its (file) session.
```
with
```
the wizard checks the token with `hash_equals`, invalidates whatever session the browser brought (L7b-11) and stores the token's hash in the new (file) session.
```

Replace
```
The request IP is **not** a gate: under Herd every request arrives from 127.0.0.1, including `herd share` traffic.
```
with
```
The address alone is no gate: under Herd every request arrives from 127.0.0.1, including `herd share` traffic, so the headers are checked as well.
```

`CHANGELOG.md`, `### Changed`: replace the line that starts with `- The setup wizard and the Herd stage answer only requests from this machine` with:

```markdown
- The setup wizard and the Herd stage answer only requests from this machine (`127.0.0.0/8` or `::1`) without an `X-Forwarded-*`, `X-Expose-*`, `CF-*`, `X-Original-Host`, `Forwarded`, `Via`, `X-Real-IP` or `True-Client-IP` header, check the pinned host on every request, start a new session when the token is accepted (nothing of an earlier session carries over, and its file is deleted), and send `Referrer-Policy: no-referrer` on the Herd-stage pages.
```

```bash
git add app/Setup/Http/DirectRequest.php app/Setup/Http/EnsureSetupAccess.php tests/Feature/Setup/Wizard/SetupAccessTest.php tests/Feature/Setup/SetupModeTest.php docs/superpowers/specs/2026-10-10-skv3-design.md CHANGELOG.md
git commit -m "fix: refuse tunnel headers and drop a planted session when the setup token is accepted

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 6: Write the failing tests for the state values (L7b-30, L7b-35)**

`tests/Feature/Setup/Wizard/WizardStateTest.php`: retitle and extend the dataset of the wrong-shape test:

```php
it('refuses a detection snapshot of the wrong shape or with a value setup never writes (CD-15, L7b-30, L7b-35)', function (array $broken): void {
    expect(fn (): Detection => Detection::fromArray($broken))->toThrow(InvalidArgumentException::class);
})->with([
    'a missing key' => [array_diff_key($snapshot, ['herd' => true])],
    'a string for a boolean' => [[...$snapshot, 'postgres' => 'yes']],
    'a string port' => [[...$snapshot, 'herd_smtp_port' => '2525']],
    'no host' => [[...$snapshot, 'host' => null]],
    'a string request port' => [[...$snapshot, 'port' => '8000']],
    'no SMTP port key (L7b-30)' => [array_diff_key($snapshot, ['herd_smtp_port' => true])],
    'no request port key (L7b-30)' => [array_diff_key($snapshot, ['port' => true])],
    'a host with a path (L7b-35)' => [[...$snapshot, 'host' => 'evil.test/x']],
    'a host with a port (L7b-35)' => [[...$snapshot, 'host' => 'localhost:8000']],
    'an upper-case host (L7b-35)' => [[...$snapshot, 'host' => 'MyApp.test']],
    'a javascript scheme (L7b-35)' => [[...$snapshot, 'scheme' => 'javascript']],
    'request port 0 (L7b-35)' => [[...$snapshot, 'port' => 0]],
    'request port 65536 (L7b-35)' => [[...$snapshot, 'port' => 65536]],
    'SMTP port 0 (L7b-35)' => [[...$snapshot, 'herd_smtp_port' => 0]],
]);

it('reads back a snapshot on https with the edge ports (L7b-35)', function () use ($snapshot): void {
    $detection = Detection::fromArray([...$snapshot, 'herd_smtp_port' => 1, 'host' => 'admin.my-app.test', 'scheme' => 'https', 'port' => 65535]);

    expect($detection->herdSmtpPort)->toBe(1)
        ->and($detection->host)->toBe('admin.my-app.test')
        ->and($detection->scheme)->toBe('https')
        ->and($detection->port)->toBe(65535);
});
```

`fromArray()` is stricter now, so the `Detector` must never write a value it refuses. Otherwise the wizard would save a state that reads back as damaged and start over on every request: a forged `Host: localhost:0`, or a `herd.json` with `smtpPort: 0`. `tests/Feature/Setup/DetectorTest.php`:
- add `use App\Setup\Detection;` to the imports (before `use App\Setup\Detector;`)
- retitle and extend the port test
- add the SMTP test right after it

```php
it('keeps the request\'s port without Herd, never the scheme\'s default port or a number that is no port (CD-15, g1-17, L7b-35)', function (string $scheme, ?int $requestPort, ?int $port): void {
    $detection = new Detector(new Toolchain($this->home), postgresProbe: fn (): bool => false)->detect('localhost', $scheme, $requestPort);

    expect($detection->port)->toBe($port);
})->with([
    'a port' => ['http', 8000, 8000],
    'http on 80' => ['http', 80, null],
    'https on 443' => ['https', 443, null],
    'no request' => ['http', null, null],
    'port 0, from a forged Host header' => ['http', 0, null],
    'port 65536' => ['http', 65536, null],
]);

it('reads a herd.json SMTP port outside 1–65535 as no Herd mail, so the wizard\'s snapshot reads back (L7b-35)', function (int $smtpPort) use ($detector): void {
    $herd = $this->home.'/Library/Application Support/Herd/bin/herd';
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);
    File::put($this->home.'/Library/Application Support/Herd/config/herd.json', json_encode(['mails' => true, 'smtpPort' => $smtpPort], JSON_THROW_ON_ERROR));
    Process::fake(["*'services:list' '--json'*" => Process::result('')]);

    $detection = $detector(new Toolchain($this->home))->detect('localhost');

    expect($detection->herdMail)->toBeFalse()
        ->and($detection->herdSmtpPort)->toBeNull()
        ->and(Detection::fromArray($detection->toArray()))->toEqual($detection);
})->with(['0' => [0], '70000' => [70000]]);
```

(`detect('localhost')` passes a request host, so `cliHost()` and its `herd tld` never run; `services:list` is the only Herd command, faked by name.)

- [ ] **Step 7: Run them and see them fail**

Run: `php artisan test --compact tests/Feature/Setup/Wizard/WizardStateTest.php tests/Feature/Setup/DetectorTest.php`

Expected failures:
- `WizardStateTest`: the nine new rows. Each one reads without a throw, because the `$int` closure takes a missing key as null and nothing checks a value.
- `DetectorTest`: four.
  - `port 0` and `port 65536` keep the port: "0 is identical to null".
  - The two SMTP rows report Herd mail: "true is false".

- [ ] **Step 8: Check the values in `fromArray()` and keep the `Detector` inside them**

`app/Setup/Detection.php`: replace the docblock and body of `fromArray()`:

```php
    /**
     * A snapshot toArray() wrote (CD-15), checked for shape and values, not detected again (L7b-30, L7b-35): every key
     * present, the host as SetupHost::normalise() leaves it, the scheme http or https, each port null or 1–65535. The
     * host, scheme and port go into APP_URL and TENANCY_APEX_DOMAIN, so a file this kit did not write is refused (RL-5).
     *
     * @param  array<array-key, mixed>  $data
     *
     * @throws InvalidArgumentException when a key is missing or has the wrong type or value
     */
    public static function fromArray(array $data): self
    {
        $host = self::text($data, 'host');
        $scheme = self::text($data, 'scheme');

        if (SetupHost::normalise($host) !== $host) {
            throw new InvalidArgumentException('Detection: host must be a lower-case host name without a port.');
        }

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Detection: scheme must be http or https.');
        }

        return new self(
            herd: self::boolean($data, 'herd'),
            herdPro: self::boolean($data, 'herd_pro'),
            herdMail: self::boolean($data, 'herd_mail'),
            herdSmtpPort: self::port($data, 'herd_smtp_port'),
            postgres: self::boolean($data, 'postgres'),
            fluxPro: self::boolean($data, 'flux_pro'),
            claude: self::boolean($data, 'claude'),
            host: $host,
            scheme: $scheme,
            port: self::port($data, 'port'),
        );
    }
```

Add these private static methods after `toArray()`. `SetupHost` is in the same namespace, so no import is needed. They stay static because `fromArray()` is static:

```php
    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws InvalidArgumentException when $key is missing
     */
    private static function field(array $data, string $key): mixed
    {
        return array_key_exists($key, $data) ? $data[$key] : throw new InvalidArgumentException("Detection: {$key} is missing.");
    }

    /** @param  array<array-key, mixed>  $data */
    private static function boolean(array $data, string $key): bool
    {
        $value = self::field($data, $key);

        return is_bool($value) ? $value : throw new InvalidArgumentException("Detection: {$key} must be a boolean.");
    }

    /** @param  array<array-key, mixed>  $data */
    private static function text(array $data, string $key): string
    {
        $value = self::field($data, $key);

        return is_string($value) ? $value : throw new InvalidArgumentException("Detection: {$key} must be a string.");
    }

    /** @param  array<array-key, mixed>  $data */
    private static function port(array $data, string $key): ?int
    {
        $value = self::field($data, $key);

        return $value === null || (is_int($value) && $value >= 1 && $value <= 65535)
            ? $value
            : throw new InvalidArgumentException("Detection: {$key} must be a port number or null.");
    }
```

`app/Setup/Detector.php`:
- in `detect()`'s docblock, replace the `$requestPort` line pair with:

```php
     * @param  int|null  $requestPort  the port of the current request; kept without Herd unless it is the scheme's
     *                                 default or no port at all (CD-15, g1-17; L7b-35: Detection::fromArray()
     *                                 refuses a port outside 1–65535, so a snapshot must never hold one)
```

- in `detect()`, replace the `port:` argument with:

```php
            port: $herd === null && $requestPort !== ($scheme === 'https' ? 443 : 80) && $this->isPort($requestPort) ? $requestPort : null,
```

- replace `herdMail()` and add `isPort()` after it. It is an instance method: Rector's `LocallyCalledStaticMethodToNonStaticRector` turns a static one into this.

```php
    /**
     * Herd's mail service from herd.json: a truthy `mails` and an `smtpPort` in 1–65535 (L7b-35). The file is read,
     * never printed.
     *
     * @return array{0: bool, 1: int|null}
     */
    private function herdMail(): array
    {
        $path = $this->toolchain->herdConfigPath();
        $config = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($config) || ! $this->isPort($config['smtpPort'] ?? null) || ! (bool) ($config['mails'] ?? false)) {
            return [false, null];
        }

        return [true, $config['smtpPort']];
    }

    /** @phpstan-assert-if-true int $value */
    private function isPort(mixed $value): bool
    {
        return is_int($value) && $value >= 1 && $value <= 65535;
    }
```

The wizard's host is always `SetupHost::forWizard()`'s normalised host (`WizardRunner::begin()`), and `Detector::detect()` puts a request host through `SetupHost::normalise()`. So no snapshot setup writes is refused by the host rule.

- [ ] **Step 9: Run them and see them pass; verify by mutation**

Run: `php artisan test --compact tests/Feature/Setup/Wizard/WizardStateTest.php tests/Feature/Setup/DetectorTest.php tests/Feature/Setup/Wizard tests/Feature/Console/RunStepCommandTest.php`
Expected: PASS.

`DetectorTest`'s "reads Herd Pro … herd.json" case compares the host with `basename(base_path()).'.test'`. It fails only in a checkout whose folder name has upper-case letters (a worktree named `wt-X`). It passes in the repository.

Mutations:
1. In `port()`, accept `0`. Rows `request port 0` and `SMTP port 0` fail.
2. Drop the `SetupHost::normalise()` check. The three host rows fail.
3. In `field()`, return `$data[$key] ?? null`. Both L7b-30 rows fail.
4. In `Detector::detect()`, drop `&& $this->isPort($requestPort)`. Rows `port 0` and `port 65536` fail.

- [ ] **Step 10: Commit**

```bash
git add app/Setup/Detection.php app/Setup/Detector.php tests/Feature/Setup/Wizard/WizardStateTest.php tests/Feature/Setup/DetectorTest.php
git commit -m "fix: refuse a setup state whose detection is missing a key or holds a host, scheme or port setup never writes

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

(No CHANGELOG line: a state file only this kit writes, behaviour unchanged for every file it does write.)

- [ ] **Step 11: Write the failing test for `/` resolving once (G6-07)**

`tests/Feature/Setup/SetupModeTest.php`: append (the file already has `$withHerd`, which fakes `herd tld` as "test"):

```php
it('resolves the host once when / refuses it under Herd, so a hung herd tld stalls / for one timeout (G6-07)', function () use ($withHerd): void {
    $context = useSetupWizard();
    $withHerd($context);

    $this->get('http://evil.test/')->assertRedirect('http://'.Str::lower($context->folderName()).'.test/');

    Process::assertRanTimes(fn ($process): bool => array_slice((array) $process->command, -1) === ['tld'], 1);
});
```

- [ ] **Step 12: Run it and see it fail**

Run: `php artisan test --compact tests/Feature/Setup/SetupModeTest.php --filter=G6-07`
Expected: FAIL, "An expected process ran 2 times instead of 1 times": `homeRedirect()` resolves through `allows(null)`, then `homeHostProblem()` resolves again.

- [ ] **Step 13: One `SetupMode::home()`, called once from `/`**

`app/Setup/SetupMode.php`: replace `homeRedirect()` and `homeHostProblem()`, docblocks included, with:

```php
    /**
     * What `/` does in setup mode (§5.3.4, RL-5), from one look at the gate, the token, the super admin, the pin and
     * the host (G6-07: the host is resolved at most once, so a hung `herd tld` stalls `/` for one timeout, not two).
     * - `redirect`: the wizard with the token, for a direct visitor while no super admin exists, on the pinned host or
     *   its admin host once begin() pinned one, else on a host the wizard would pin. A proxied or tunnelled request
     *   never gets the token (DEC-24): it would leave the machine in the tunnel's logs. Nor does a host the wizard
     *   cannot pin (RL-5): a page on such a name could read the token from the redirect.
     * - `problem`: before begin(), why such a visitor gets no token on this host, with `suggestion` the address to open
     *   instead (g3-34: localhost on the request's port without Herd; {folder}.{tld} under Herd), or null when no
     *   address would do (g3-10: the folder; g3-28: `herd tld`).
     * Both are null when `/` has nothing to do with setup.
     *
     * @return array{redirect: ?string, problem: array{suggestion: ?string, problem: string}|null}
     */
    public function home(Request $request): array
    {
        $none = ['redirect' => null, 'problem' => null];

        if ($request->attributes->get(self::ATTRIBUTE) !== true || ! DirectRequest::isDirect($request)) {
            return $none;
        }

        $token = $this->token()->value();

        if ($token === null || $this->superAdminExists()) {
            return $none;
        }

        $wizard = '/welcome-setup?token='.$token;
        $pinned = SetupHostGuard::pinnedWizardHost($this->basePath);

        if ($pinned !== null) {
            return SetupHostGuard::allows($request, $pinned) ? ['redirect' => $wizard, 'problem' => null] : $none;
        }

        $resolved = SetupHostGuard::resolve($request->getHttpHost(), app(Toolchain::class), app(SetupContext::class)->folderName());

        return $resolved['host'] !== null
            ? ['redirect' => $wizard, 'problem' => null]
            : ['redirect' => null, 'problem' => ['suggestion' => $resolved['suggestion'], 'problem' => (string) $resolved['problem']]];
    }
```

`SetupHostGuard::allows($request, null)` is `resolve(...)['host'] !== null`. So the unpinned branch decides exactly as the two old methods did, with one resolve and one `superAdminExists()`.

`routes/web.php`: replace the start of the `/` closure:

```php
Route::get('/', function (): RedirectResponse|Response {
    // G6-07: one look at setup mode, the token, the super admin, the pin and the host.
    $setup = app(SetupMode::class)->home(request());

    // §5.3.4: before setup, `/` leads to the wizard.
    if ($setup['redirect'] !== null) {
        return redirect($setup['redirect']);
    }

    // g3-34: a token-less redirect to the address the wizard pins; g3-10, g3-28: no address would do, so say why.
    if ($setup['problem'] !== null) {
        return $setup['problem']['suggestion'] !== null
            ? redirect()->away(rtrim($setup['problem']['suggestion'], '/').'/')
            : response()->view('setup.host-problem', ['problem' => $setup['problem']['problem']], 409);
    }
```

The rest of the closure (`$user = auth()->user();` …) is unchanged.

- [ ] **Step 14: Run it and see it pass; verify by mutation**

Run: `php artisan test --compact tests/Feature/Setup/SetupModeTest.php tests/Feature/Setup/Wizard/SetupAccessTest.php tests/Feature/Tenancy/RouteHostMatrixTest.php`
Expected: PASS. The existing `/` tests cover each branch: the token, a proxy, a refused host, the pinned host, the folder problem, `herd tld` failing.

Mutation: in the unpinned branch, call `SetupHostGuard::allows($request, null)` before `resolve()`. The G6-07 test fails with 2 runs.

- [ ] **Step 15: Commit**

`CHANGELOG.md`, `### Fixed`, append:

```markdown
- Under Herd, `/` on a host the setup refuses asks `herd tld` once instead of twice, so a Herd that does not answer delays it by at most 5 seconds.
```

```bash
git add app/Setup/SetupMode.php routes/web.php tests/Feature/Setup/SetupModeTest.php CHANGELOG.md
git commit -m "fix: let / in setup mode resolve the host once

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 16: Send the second-factor login to the home host (G6-08, CD-7)**

`tests/Feature/Setup/Wizard/HerdStageTest.php`: in the CD-14 test, change its title and its expected target:

```php
it('sends a super admin with a confirmed second factor to the login page of their home host, not through a handoff (CD-14, g4-03, CD-7)', function () use ($afterStepNine): void {
```

and

```php
    $this->get('/welcome-setup/login?secret='.$secret)->assertRedirect(Hosts::url(Hosts::admin(), '/login'));
```

Run: `php artisan test --compact tests/Feature/Setup/Wizard/HerdStageTest.php --filter="home host"`
Expected: FAIL. The Location is `Hosts::url(Hosts::apex(), '/login')`.

`app/Setup/Wizard/HerdStage.php`: replace `loginUrl()`'s docblock with

```php
    /**
     * The handoff for the installed super admin, or a login page: the apex's for a demo, which has no account to send
     * home, and the super admin's home host (the admin host) for one with a confirmed second factor, which a handoff
     * would skip (CD-14; CD-7: links that leave the request go to Hosts::homeOf()). The secret is used up (RL-6). The
     * state is claimed under the SetupLock, so two logins never both pass and a late Herd step never writes it back.
     */
```

and the second-factor branch with

```php
        if ($user->two_factor_confirmed_at !== null) {
            return Hosts::url(Hosts::homeOf($user), '/login');
        }
```

The demo branch (`$state->answers->startWith !== 'super_admin' …`) keeps `Hosts::url(Hosts::apex(), '/login')`. It has no user, and `it('sends a demo install to the login page (RL-6)')` still pins it.

Run: `php artisan test --compact tests/Feature/Setup/Wizard/HerdStageTest.php`
Expected: PASS.

`CHANGELOG.md`, `### Changed`: replace the line that starts with `- After setup, a super admin who confirmed two-factor sign-in` with:

```markdown
- After setup, a super admin who confirmed two-factor sign-in is sent to the login page of the admin host instead of signed in with the single-use link.
```

```bash
git add app/Setup/Wizard/HerdStage.php tests/Feature/Setup/Wizard/HerdStageTest.php CHANGELOG.md
git commit -m "fix: send the setup's second-factor login to the super admin's home host

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 17: Write the failing tests for the isolation left to run (L7b-16, L7b-18)**

A failed launch, a stranded stage and a Herd step that threw settle as `skipped` when `manualCommand()` is null. Under Herd that is also the case for a served site without HTTPS, so PHP 8.4 was never isolated and nothing said so. The same gap sits in `confirm()`, which the finish page calls once the answer is overdue. A launch that failed while `manualCommand()` named `herd isolate` would still settle as "Site served by Herd" there. Both change.

`tests/Feature/Setup/Steps/HerdSiteStepTest.php`:
- in `it('names the one Herd command left to run: herd link while unserved, herd secure while HTTPS is missing (RL-7)', …)`, add the PHP listings to the fake. The site runs 8.4, so the rows keep their commands:

```php
    Process::fake([
        "*herd' 'links'*" => Process::result(''),
        "*herd' 'paths'*" => Process::result(json_encode([$served ? dirname($context->basePath) : '/somewhere/else'], JSON_THROW_ON_ERROR)),
        "*herd' 'isolated'*" => Process::result(''),
        "*php' '-r'*" => Process::result('8.4.13'),
    ]);
```

- add these two tests right before `it('names no Herd command without Herd (RL-7)', …)`:

```php
it('names herd isolate for a served site on an older PHP, before herd secure, as run() would isolate it (L7b-16)', function (bool $https, string $php): void {
    $context = setupSandbox($this->detection);
    $context->answers = Answers::fromArray(['modules' => ['impersonation'], 'https' => $https], $this->detection, $context->folderName());
    Process::fake([
        "*herd' 'links'*" => Process::result(''),
        "*herd' 'paths'*" => Process::result(json_encode([dirname($context->basePath)], JSON_THROW_ON_ERROR)),
        "*herd' 'isolated'*" => Process::result(''),
        "*herd' 'secured'*" => Process::result(''),
        "*php' '-r'*" => Process::result($php),
    ]);

    expect(new HerdSiteStep()->manualCommand($context))->toBe('herd isolate 8.4 --site='.$context->folderName())
        ->and(new HerdSiteStep()->confirm($context))->toBeNull();
    Process::assertDidntRun(fn ($process): bool => in_array('isolate', (array) $process->command, true));
})->with([
    'no HTTPS, global PHP 8.3' => [false, '8.3.12'],
    'HTTPS chosen, global PHP 8.3' => [true, '8.3.12'],
    'no HTTPS, the PHP probe prints nothing' => [false, ''],
]);

it('names herd isolate when the PHP check itself fails, never nothing (L7b-16, L7b-18)', function (): void {
    $context = setupSandbox($this->detection);
    $context->answers = Answers::fromArray(['modules' => ['impersonation'], 'https' => false], $this->detection, $context->folderName());
    Process::fake([
        "*herd' 'links'*" => Process::result(''),
        "*herd' 'paths'*" => Process::result(json_encode([dirname($context->basePath)], JSON_THROW_ON_ERROR)),
        "*herd' 'isolated'*" => fn () => throw new ProcessTimedOutException(new SymfonyTimeout(new SymfonyProcess(['herd']), SymfonyTimeout::TYPE_GENERAL), Process::result('')),
    ]);

    expect(new HerdSiteStep()->manualCommand($context))->toBe('herd isolate 8.4 --site='.$context->folderName());
});
```

(The sandbox folder is a UUID, which `ShellArgument::quote()` leaves bare, so the printed command has no quotes.)

`tests/Feature/Setup/Wizard/HerdStageTest.php`:
- replace `$herdListings`, docblock included. Every existing user keeps a site on PHP 8.4, so its command and result do not change:

```php
/**
 * Herd's read-only listings: the project parked (served) or not, `herd secured` printing $secured, and the site on
 * PHP $php (no isolation, Herd's global PHP).
 *
 * @return array<string, mixed>
 */
$herdListings = fn (SetupContext $context, bool $served, string $secured = '', string $php = '8.4.13'): array => [
    "*herd' 'services:list'*" => Process::result(''),
    "*herd' 'links'*" => Process::result(''),
    "*herd' 'paths'*" => Process::result(json_encode([$served ? dirname($context->basePath) : '/somewhere/else'], JSON_THROW_ON_ERROR)),
    "*herd' 'secured'*" => Process::result($secured),
    "*herd' 'isolated'*" => Process::result(''),
    "*php' '-r'*" => Process::result($php),
];
```

- in `it('settles a site secured by hand once herd secured lists it, when the manual page is reloaded (§5.3.4, RL-6)', …)`, add to its `Process::fake([...])` after the `herd' 'secured'` entry:

```php
        "*herd' 'isolated'*" => Process::result(''),
        "*php' '-r'*" => Process::result('8.4.13'),
```

- add this test right before `it('shows no command on the manual page without Herd (RL-7)', …)`:

```php
it('offers herd isolate when the Herd step cannot start for a site Herd serves on an older PHP, never settling it as skipped (L7b-16)', function () use ($withHerd, $herdDetection, $herdListings): void {
    // Arrange: steps 1–8 are done under Herd, which serves the site on PHP 8.3; no HTTPS, so only the isolation is left.
    $context = useSetupWizard();
    $withHerd($context);
    Process::fake($herdListings($context, served: true, php: '8.3.12'));
    $answers = Answers::fromArray(['modules' => ['impersonation'], 'https' => false], $herdDetection(), $context->folderName());
    new WizardState('localhost', $answers, $herdDetection(), array_fill_keys(WizardRunner::PAGE_STEPS, StepResult::done('ok')))->save($context->basePath);
    /** @var InlineStepLauncher $launcher */
    $launcher = app(StepLauncher::class);
    $launcher->refuses = true;

    $secret = app(WizardRunner::class)->finish();

    expect(HerdState::load($context->basePath)?->result)->toBeNull();
    $this->get('/welcome-setup/finish?secret='.$secret)->assertOk()
        ->assertSee('data-test="setup-manual-herd"', false)
        ->assertSee('herd isolate 8.4 --site='.$context->folderName());
});
```

`tests/Feature/Console/RunStepCommandTest.php`:
- in `it('applies the HTTPS consequence when the Herd step throws, with the Herd command left to run (OD-2, RL-6, RL-7)', …)`, add the PHP listings to its `Process::fake([...])` after the `herd' 'paths'` entry:

```php
        "*herd' 'isolated'*" => Process::result(''),
        "*php' '-r'*" => Process::result('8.4.13'),
```

- add this test right before `it('leaves no lock file when the login used the secret while Herd ran (g3-02)', …)`:

```php
it('leaves herd isolate to run when the Herd step throws on a site Herd serves on an older PHP, never a skip (L7b-18)', function (): void {
    // Arrange: no HTTPS under Herd, which serves the site on PHP 8.3; the step throws before it isolates.
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
        "*herd' 'isolated'*" => Process::result(''),
        "*php' '-r'*" => Process::result('8.3.12'),
    ]);
    app()->instance(SetupContext::class, $context);
    $context->answers = Answers::fromArray(['modules' => ['impersonation'], 'https' => false], $detection, $context->folderName());
    Marker::write($context, []);
    HerdState::begin($context->basePath, $context, 'localhost');
    app()->instance(SetupRunner::class, new SetupRunner([
        new CallbackSetupStep('herd-site', function (): void {
            throw new RuntimeException('herd isolate did not finish');
        }, StepResult::done()),
    ]));

    expect(Artisan::call('skv:run-step', ['step' => 'herd-site', '--result' => $context->path(HerdState::PATH)]))->toBe(0);

    $result = HerdState::load($context->basePath)?->result;
    expect($result?->status)->toBe('manual')
        ->and($result?->command)->toBe('herd isolate 8.4 --site='.$context->folderName())
        ->and($result?->message)->toBe('herd isolate did not finish');
});
```

(`RunStepCommand` needs no change. Its `$command === null ? skipped : manual` is right once `manualCommand()` names every Herd action left: L7b-18's own fix.)

- [ ] **Step 18: Run them and see them fail**

Run: `php artisan test --compact tests/Feature/Setup/Steps/HerdSiteStepTest.php tests/Feature/Setup/Wizard/HerdStageTest.php tests/Feature/Console/RunStepCommandTest.php --filter="L7b-1"`

Expected: 6 failures.
- `HerdSiteStepTest`, PHP 8.3 or no PHP answer without HTTPS: "null is identical to 'herd isolate 8.4 --site=…'".
- `HerdSiteStepTest`, HTTPS chosen: `herd secure …` instead of `herd isolate …`.
- `HerdSiteStepTest`, the failing PHP check: null.
- `HerdStageTest`: the stage settled at once ("an instance of class App\Setup\StepResult is null").
- `RunStepCommandTest`: 'skipped' instead of 'manual'.

- [ ] **Step 19: Name the isolation in `manualCommand()`, require it in `confirm()`**

`app/Setup/Steps/HerdSiteStep.php`: replace `confirm()`'s docblock with

```php
    /**
     * The result run() gives when nothing is left to do: Herd already serves the project on PHP 8.4 or newer and, when
     * HTTPS was chosen, `herd secured` lists it (secured by hand, or by a detached step that did not live to write its
     * result). A secured site gets its APP_URL and marker like run(). Null when Herd confirms less (L7b-16: also a site
     * still on an older PHP), or a listing fails. Only Herd's read-only listings and the PHP version probe run, never
     * link, isolate or secure, so a web request may call it (§5.3.4, RL-6, DEC-23).
     */
```

and in its `try` replace

```php
            if ($this->siteState($context, $herd) !== self::SERVED) {
                return null;
            }
```

with

```php
            if ($this->siteState($context, $herd) !== self::SERVED || ! $this->runsSupportedPhp($context, $herd)) {
                return null;
            }
```

Replace `manualCommand()`, docblock included:

```php
    /**
     * The one Herd command the user still has to run for this project (RL-7), in run()'s order: `herd link` while Herd
     * does not serve it, `herd isolate 8.4 --site=…` while the site runs an older PHP (L7b-16), `herd secure` when
     * HTTPS was chosen, and null without Herd or with nothing left to do, so a caller that finds none may settle the
     * step as skipped (L7b-18). A site listing that fails counts as unknown, so the command HTTPS asks for is named; a
     * PHP check that fails names `herd isolate`, which is harmless to repeat. Only Herd's read-only listings and the
     * PHP version probe run, so the wizard, the Herd stage and skv:run-step may call it from a web request (DEC-23).
     */
    public function manualCommand(SetupContext $context): ?string
    {
        $herd = $this->toolchain->herd();

        if (! $context->detection->herd || $herd === null) {
            return null;
        }

        $name = $context->folderName();
        $quoted = ShellArgument::quote($name);

        try {
            $served = $this->siteState($context, $herd) === self::SERVED;
        } catch (Throwable) {
            return $context->answers->https ? 'herd secure '.$quoted : 'herd link '.$quoted;
        }

        if (! $served) {
            return 'herd link '.$quoted;
        }

        try {
            $supported = $this->runsSupportedPhp($context, $herd);
        } catch (Throwable) {
            $supported = false;
        }

        if (! $supported) {
            return 'herd isolate '.self::PHP_VERSION.' '.ShellArgument::quote('--site='.$name);
        }

        return $context->answers->https ? 'herd secure '.$quoted : null;
    }
```

The isolate command is the same string `run()` builds for `StepResult::manual()` (`$pending`), so the manual page, the marker and `skv:install`'s list agree.

`app/Setup/Wizard/WizardRunner.php`: replace `launchFailed()`'s docblock with

```php
    /**
     * The Herd step could not start in the background (g3-22). With a Herd command left to run (link, isolate or
     * secure, L7b-16), the exit file makes the answer overdue at once, so the page offers the manual page with Reload
     * and Continue without HTTPS; without one (no Herd, or nothing left to do), the stage ends as skipped.
     */
```

- [ ] **Step 20: Run them and see them pass; verify by mutation**

Run: `php artisan test --compact tests/Feature/Setup tests/Feature/Console`
Expected: PASS. The `DetectorTest` folder-name case is the worktree exception named in Step 9.

Mutations:
1. In `confirm()`, drop `|| ! $this->runsSupportedPhp($context, $herd)`. Three tests fail: the two non-HTTPS rows of the L7b-16 `HerdSiteStepTest` (on `confirm()`), and the L7b-16 `HerdStageTest`, which shows the summary instead of the manual page.
2. In `manualCommand()`, return `null` from the PHP check's `catch`. The "PHP check itself fails" test fails.
3. In `manualCommand()`, check `https` before the PHP version. The "HTTPS chosen, global PHP 8.3" row fails.

- [ ] **Step 21: CHANGELOG, commit**

`CHANGELOG.md`, `### Changed`: replace the line that starts with `- When the Herd step cannot start, the Herd stage shows the command to run yourself;` with:

```markdown
- When the Herd step cannot start, the Herd stage shows the command to run yourself; that command is `herd link <name>`, `herd isolate 8.4 --site=<name>` or `herd secure <name>`, whichever the site still needs, and every printed command quotes a name that needs it (also one that ends in a newline or starts with `=`).
```

`### Fixed`, append:

```markdown
- A Herd step that could not start, or failed, on a site Herd serves with an older PHP now leaves `herd isolate 8.4 --site=<name>` to run instead of ending as skipped, and the Herd stage no longer reports such a site as done.
```

```bash
git add app/Setup/Steps/HerdSiteStep.php app/Setup/Wizard/WizardRunner.php tests/Feature/Setup/Steps/HerdSiteStepTest.php tests/Feature/Setup/Wizard/HerdStageTest.php tests/Feature/Console/RunStepCommandTest.php CHANGELOG.md
git commit -m "fix: leave herd isolate to run when the herd step could not isolate php 8.4

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 22: Task gate**

Run every check of Global Constraints › Gate per task:

```bash
composer check
composer test:pgsql
php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser
npm run build && composer test:browser
npm run build:setup && git diff --exit-code resources/setup/setup.css
php artisan config:clear
```

Expected: all green. The setup stylesheet is unchanged, because this task edits no setup view. In the report, name each mutation of Steps 4, 9, 14 and 20 with its result.

---

### Task 11: Setup files and steps (G6-06, G6-09, L7b-03, L7b-05; CD-8, CD-12)

**Files:**
- Modify:
  - `app/Setup/Wizard/DetachedStepLauncher.php` (umask 077 only around the kit's own pid, log and exit files: G6-06)
  - `app/Setup/Steps/EnvironmentStep.php` (creates `public/storage` through `php artisan storage:link`, and counts it in `isComplete()`: G6-09, CD-12)
  - `app/Setup/Support/EnvFile.php` (new `contents()`; `all()` and `set()` read through it: L7b-03)
  - `app/Setup/Support/Bootstrap.php` (`.env` written 0600 from `contents()`: L7b-03, L7b-05, CD-8)
  - `app/Setup/Steps/DatabaseStep.php` (`.env.testing` written 0600 from `contents()`: L7b-03, CD-8)
  - `composer.json` (only `scripts`: the `.env` one-liner of `post-root-package-install` and of `setup` chmods the copy 0600, CD-8; `composer.lock` does not change, its content hash does not cover `scripts`)
  - `scripts/e2e-local.sh` (the clone route repeats that one-liner)
  - `docs/handleiding.html` (the clone instructions quote it; the one line of this file outside Task 14)
  - `tests/Pest.php` (`setupSandbox()` links `public/storage` as setup leaves it)
  - `lang/nl.json`, `lang/en.json` (one key)
  - `docs/superpowers/specs/2026-10-10-skv3-design.md` (§5.3.3 steps 1 and 2; the one-liner in §5.2 and §5.3)
  - `CHANGELOG.md`
- Test:
  - `tests/Feature/Setup/Wizard/DetachedStepLauncherTest.php`
  - `tests/Feature/Setup/Steps/EnvironmentStepTest.php`
  - `tests/Feature/Setup/Doctor/DoctorTest.php`, `tests/Feature/Setup/Doctor/ChecksTest.php` (each removes the sandbox's new link where it needs none)
  - `tests/Feature/Setup/BootstrapTest.php`
  - `tests/Feature/Setup/Steps/DatabaseStepTest.php`
  - `tests/Feature/Tooling/ComposerScriptsTest.php` (the one-liner, three expectations)

No setup view changes, so `resources/setup/setup.css` stays as it is.

**Interfaces:**
- Consumes:
  - `setupSandbox(?Detection $detection = null, string $prefix = ''): SetupContext`
  - `Toolchain::phpCli(?string $binary = null): ?string`, `Toolchain::environment(): array<string, string|false>`
  - `StateFile::write(string $path, string $contents, int $mode = 0600): void`
  - `WizardState::STEPS`
  - the existing lang key `public/storage is missing` (`StorageLinkCheck`)
- Produces:
  - `EnvFile::contents(): string`, which throws `RuntimeException(__('Could not read :file.', ['file' => basename($path)]))` for a missing or unreadable file. `EnvFile::all()` (for an existing file) and `EnvFile::set()` now throw the same instead of a raw PHP warning.
  - `EnvironmentStep::__construct(?Toolchain $toolchain = null)`; `app(EnvironmentStep::class)` and `new EnvironmentStep` keep working. The class becomes `final readonly`.
  - `EnvironmentStep::run()` returns `StepResult::manual('php artisan storage:link', …)` when `public/storage` is still missing after the link command.
  - `setupSandbox()` creates `storage/app/public` and the symlink `public/storage` → `storage/app/public`. A test that needs the link missing unlinks it first.

**Evidence correction (L7b-03):** the triage's claim, "a read failure gives an empty `.env.testing`/`.env`", does not hold under Laravel. `HandleExceptions` turns `file_get_contents()`'s warning into an `ErrorException`. Today an unreadable `.env.example` or `.env` therefore stops `skv:bootstrap` and the database step with an untranslated "file_get_contents(/absolute/path/.env): Failed to open stream: Permission denied". The fix keeps the stop and makes it the translated "Could not read .env.", without the absolute path. The tests below fail today on exactly that message.

**Mutation checks in this task:** never use `git stash`. Every worktree of the repository shares one stash, so parallel work collides on it. Copy the production file aside (`cp app/… /tmp/m7c-mutation.php`), edit it, run the test, then copy it back. Use `git show HEAD:<path> > <path>` when a check needs the version from before the task.

- [ ] **Step 1: Write the failing test for the detached step's umask (G6-06)**

The test runs the real launch script: `sh`, `nohup` and a stand-in for `php` in the sandbox. The script's behaviour lies in the shell, not in the argv, so it is the second test after `BackupRunTest` that lets a real process run.

`tests/Feature/Setup/Wizard/DetachedStepLauncherTest.php`: replace the imports

```php
use App\Setup\Support\Toolchain;
use App\Setup\Wizard\DetachedStepLauncher;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
```

with

```php
use App\Setup\Support\Toolchain;
use App\Setup\Wizard\DetachedStepLauncher;
use App\Setup\Wizard\WizardState;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
```

In the first test, replace the line

```php
        && str_contains($process->command[2], 'echo $$ > "$5"; "$0" artisan skv:run-step')
```

with

```php
        && str_contains($process->command[2], 'echo $$ > "$5"; ')
        && str_contains($process->command[2], '"$0" artisan skv:run-step "$1" --no-interaction --result="$2" > "$3" 2>&1')
```

Rewrite the g3-16 test. It is replaced by a test of the script's effect, with the title widened; it is not deleted:

```php
it('keeps the child\'s pid, log and exit files private but lets the step create files under the umask it inherited, and writes the exit file only after the step (g3-16, G6-06)', function (): void {
    // Arrange: a stand-in for php that creates a file of its own and records whether the exit file already exists.
    $context = setupSandbox();
    $directory = $context->path(WizardState::STEPS);
    File::ensureDirectoryExists($directory);
    $php = $context->path('fake-php');
    File::put($php, "#!/bin/sh\nif [ -e '{$directory}/frontend.exit' ]; then echo early > made-by-step; else echo late > made-by-step; fi\n");
    chmod($php, 0755);
    // The real script runs (sh, nohup and the stand-in); nothing else may.
    Process::preventStrayProcesses(false);
    $umask = umask(0022);

    try {
        $started = new DetachedStepLauncher(app(Toolchain::class), php: $php)
            ->start($context->basePath, 'frontend', $directory.'/frontend.json', $directory.'/frontend.log', $directory.'/frontend.exit', $directory.'/frontend.started');
    } finally {
        umask($umask);
    }

    // The child runs detached: wait at most ten seconds for the exit code it writes last.
    $deadline = microtime(true) + 10;

    while ((! is_file($directory.'/frontend.exit') || trim((string) file_get_contents($directory.'/frontend.exit')) === '') && microtime(true) < $deadline) {
        usleep(50_000);
        clearstatcache();
    }

    expect($started)->toBeTrue()
        ->and(trim((string) file_get_contents($directory.'/frontend.exit')))->toBe('0')
        ->and(trim((string) file_get_contents($context->path('made-by-step'))))->toBe('late')
        ->and(fileperms($context->path('made-by-step')) & 0777)->toBe(0644)
        ->and(fileperms($directory.'/frontend.log') & 0777)->toBe(0600)
        ->and(fileperms($directory.'/frontend.exit') & 0777)->toBe(0600)
        ->and(fileperms($directory.'/frontend.started') & 0777)->toBe(0600);
});
```

How the test works:
- The launcher's outer `sh` inherits the test process's umask when it starts. `umask()` is restored as soon as `start()` returns, which is after the outer shell has forked the detached child.
- The stand-in runs with the sandbox as its working directory (`Process::path($basePath)`), so `made-by-step` lands in the sandbox.
- The wait loop also waits for content, because the redirect creates the exit file before `echo` writes into it.

- [ ] **Step 2: Run it and see it fail**

Run: `php artisan test --compact tests/Feature/Setup/Wizard/DetachedStepLauncherTest.php`
Expected: the rewritten test fails with "Failed asserting that 384 is identical to 420". `made-by-step` is 0600 (384), not 0644 (420), because `umask 077` covers the whole step.

- [ ] **Step 3: Lower the umask only around the kit's own files**

`app/Setup/Wizard/DetachedStepLauncher.php`: replace the `SCRIPT` constant and its docblock:

```php
    /**
     * Outer: $1 php, $2 key, $3 result, $4 log, $5 exit, $6 pid; inner: $0 php, $1 key, $2 result, $3 log, $4 exit,
     * $5 pid. Only the kit's own files are private (g3-16, G6-06): under umask 077 the inner shell writes the pid file
     * and creates the log, then puts the umask it inherited back, so the step (npm, composer, claude, boost) creates
     * public/build, node_modules, vendor and .claude as it would in a terminal. The log's redirect truncates the 0600
     * file it created. The exit file is created only once the step has ended, again under 077: the runner reads its
     * existence as "the step exited" (WizardRunner::isRunning(), advanceLong()), so it is never created early.
     */
    private const string SCRIPT = 'nohup sh -c \'u=$(umask); umask 077; echo $$ > "$5"; : > "$3"; umask "$u"; "$0" artisan skv:run-step "$1" --no-interaction --result="$2" > "$3" 2>&1; rc=$?; umask 077; echo "$rc" > "$4"\' "$1" "$2" "$3" "$4" "$5" "$6" > /dev/null 2>&1 &';
```

The result file (`--result="$2"`) needs no umask: the child writes it through `StateFile::writeJson()`, which is 0600 by default.

- [ ] **Step 4: Run it and see it pass; verify by mutation**

Run: `php artisan test --compact tests/Feature/Setup/Wizard tests/Feature/Console/RunStepCommandTest.php`
Expected: PASS.

Mutations:
1. Pre-create the exit file (`: > "$3"; : > "$4"; umask "$u";`). The test fails with `'early'` instead of `'late'`. This is the runner's "the step exited" condition.
2. Drop `umask "$u";`. The test fails on 384 vs 420.
3. Drop `: > "$3";`. The log comes out 0644, and the test fails on the log's mode.

- [ ] **Step 5: Commit**

`CHANGELOG.md`, `### Fixed`, append:

```markdown
- The setup's background steps (frontend build, Claude) no longer create `public/build`, `node_modules`, `vendor` or `.claude` readable by their owner only; only the steps' own log, exit and pid files stay private.
```

```bash
git add app/Setup/Wizard/DetachedStepLauncher.php tests/Feature/Setup/Wizard/DetachedStepLauncherTest.php CHANGELOG.md
git commit -m "fix: keep umask 077 to the detached step's own files

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 6: Give the sandbox the link setup leaves (G6-09 preparation)**

`EnvironmentStep` runs in every setup test that runs the environment step. If the sandbox had no `public/storage`, each of those runs would start `storage:link`. Those tests are about 30, in `EnvironmentStepTest`, `SetupRunnerTest`, `WizardRunnerTest`, `WizardPageTest` and `InstallCommandTest`, and a faked command leaves the link missing. The sandbox therefore looks like a project setup has already linked, and only the tests of the link remove it.

`tests/Pest.php`: replace `setupSandbox()`'s docblock and its folder loop:

```php
/**
 * A throwaway project directory for setup steps (RL-2): .env from .env.example, storage and database folders, and
 * public/storage linked to storage/app/public as the environment step leaves it (G6-09), so only a test of the link
 * removes it. Removed when the test ends; never the repository itself. A $prefix starts the folder name, e.g.
 * setup_probe_ for the Database step's PostgreSQL databases, which take their name from the folder.
 */
function setupSandbox(?Detection $detection = null, string $prefix = ''): SetupContext
{
    $directory = storage_path('framework/testing/setup/'.$prefix.Str::uuid());

    foreach (['storage/app/private', 'storage/app/public', 'database', 'public'] as $folder) {
        File::ensureDirectoryExists($directory.'/'.$folder);
    }

    symlink($directory.'/storage/app/public', $directory.'/public/storage');
```

The rest of the function is unchanged. `File::deleteDirectory()` unlinks a symlink, never its target.

Two doctor tests relied on the missing link:

`tests/Feature/Setup/Doctor/DoctorTest.php`, in `it('reports drift without repairing it, and repairs it with --fix (DEC-26, DEC-28, FU2-13)', …)`:
- add as the first statement:

```php
    // Without public/storage, --fix also runs the storage-link repair (a safe repair, §5.4).
    unlink($this->sandbox->path('public/storage'));
```

- delete the comment line `// The sandbox has no public/storage, so --fix runs the storage-link repair (a safe repair, §5.4).` above the final `Process::assertRan(…)`. The assertion stays.

`tests/Feature/Setup/Doctor/ChecksTest.php`, in `it('warns without public/storage and runs storage:link with --fix (§5.4)', …)`, add as the first statement:

```php
    unlink($this->sandbox->path('public/storage'));
```

Run: `php artisan test --compact tests/Feature/Setup tests/Feature/Console tests/Feature/Tooling tests/Feature/Tenancy/RouteHostMatrixTest.php tests/Feature/Auth/QuickLoginTest.php tests/Feature/Scheduler`
Expected: PASS. These are all the suites that build a sandbox; the browser suite's `SetupWizardTest` uses `setupSandbox()` too and gets the same link.

- [ ] **Step 7: Write the failing tests for `public/storage` (G6-09, CD-12)**

`tests/Feature/Setup/Steps/EnvironmentStepTest.php`: add to the imports

```php
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
```

and append:

```php
it('links public/storage through php artisan storage:link in the project, and is incomplete without it (G6-09, CD-12)', function (): void {
    // Arrange: a fresh checkout has no public/storage; the faked command makes the link storage:link would make.
    $context = setupSandbox();
    unlink($context->path('public/storage'));
    Process::fake(["*'artisan' 'storage:link' '--no-interaction'*" => function (PendingProcess $process) use ($context): ProcessResult {
        symlink($context->path('storage/app/public'), $context->path('public/storage'));

        return Process::result('The [public/storage] link has been connected to [storage/app/public].');
    }]);

    $result = new EnvironmentStep()->run($context);
    $linked = is_link($context->path('public/storage'));
    $complete = new EnvironmentStep()->isComplete($context);
    File::delete($context->path('public/storage'));

    expect($result->status)->toBe('done')
        ->and($linked)->toBeTrue()
        ->and($complete)->toBeTrue()
        ->and(new EnvironmentStep()->isComplete($context))->toBeFalse();
    Process::assertRan(fn (PendingProcess $process): bool => array_slice((array) $process->command, 1) === ['artisan', 'storage:link', '--no-interaction']
        && $process->path === $context->basePath);
});

it('leaves php artisan storage:link to run when the link does not appear, after writing .env (G6-09)', function (): void {
    $context = setupSandbox();
    unlink($context->path('public/storage'));
    Process::fake(["*'artisan' 'storage:link' '--no-interaction'*" => Process::result('', exitCode: 1)]);

    $result = new EnvironmentStep()->run($context);

    expect($result->status)->toBe('manual')
        ->and($result->command)->toBe('php artisan storage:link')
        ->and($result->message)->toContain(__('public/storage is missing'))
        ->and($result->message)->toContain(__('Mail is written to the log (storage/logs/laravel.log).'))
        ->and(strlen((string) new EnvFile($context->path('.env'))->get('HEALTH_CHECK_KEY')))->toBe(40);
});

it('starts no storage:link when public/storage already exists (G6-09)', function (): void {
    $context = setupSandbox();

    expect(new EnvironmentStep()->run($context)->status)->toBe('done');
    Process::assertDidntRun(fn (PendingProcess $process): bool => in_array('storage:link', (array) $process->command, true));
});
```

(The fake is a closure under a named key, as `BootstrapTest`'s `herd tld` fake is. The command's first argument is the CLI php, `PHP_BINARY` under Pest, so the test compares from the second argument on.)

- [ ] **Step 8: Run them and see them fail**

Run: `php artisan test --compact tests/Feature/Setup/Steps/EnvironmentStepTest.php --filter=G6-09`
Expected: two failures.
- The first test fails with "Failed asserting that false is true": no link, because the step never runs `storage:link`.
- The second fails with 'done' instead of 'manual'.
- The third passes before and after; it pins that an existing link starts nothing.

- [ ] **Step 9: Create the link in the environment step**

`app/Setup/Steps/EnvironmentStep.php`:
- add these imports, in sorted position:

```php
use App\Setup\Support\Toolchain;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
```

- replace the class docblock and declaration:

```php
/**
 * §5.3.3 step 1: the app name, address and apex, the generated secrets (GENERATED), the faker locale, the module
 * flags and, when Herd Pro mail is active and the installer left mail alone, Herd's SMTP server. Never writes APP_KEY
 * or DB_*. It also creates public/storage (G6-09, CD-12): this step always runs, while the frontend step is skipped
 * when a build exists.
 */
final readonly class EnvironmentStep implements SetupStep
{
```

- add the constructor right after the `GENERATED` constant (Pint's `ordered_class_elements` puts it before `missingSecrets()`):

```php
    public function __construct(private ?Toolchain $toolchain = null) {}
```

- in `isComplete()` replace

```php
        if (self::missingSecrets($env) !== []) {
            return false;
        }
```

with

```php
        if (self::missingSecrets($env) !== [] || ! $this->storageIsLinked($context)) {
            return false;
        }
```

- in `run()` replace

```php
        $env->set($variables);

        return StepResult::done($note);
```

with

```php
        $env->set($variables);

        if (! $this->linkStorage($context)) {
            return StepResult::manual('php artisan storage:link', trim(__('public/storage is missing').'. '.$note));
        }

        return StepResult::done($note);
```

- add these two private methods right before `installerSetMail()`:

```php
    /** public/storage exists, as a link or otherwise (the doctor's `storage-link` check). */
    private function storageIsLinked(SetupContext $context): bool
    {
        $link = $context->path('public/storage');

        return is_link($link) || file_exists($link);
    }

    /**
     * `php artisan storage:link` as a child process in the project (G6-09, CD-12), so it links the project and honours
     * its filesystems.links: the CLI php next to the running one, because the wizard runs this step under php-fpm, and
     * the toolchain's environment (DEC-23). The link is read back, never the exit code; false when it is still missing.
     */
    private function linkStorage(SetupContext $context): bool
    {
        if ($this->storageIsLinked($context)) {
            return true;
        }

        $toolchain = $this->toolchain ?? app(Toolchain::class);
        $php = $toolchain->phpCli();

        if ($php === null) {
            return false;
        }

        try {
            Process::path($context->basePath)->env($toolchain->environment())->timeout(60)
                ->run([$php, 'artisan', 'storage:link', '--no-interaction']);
        } catch (ProcessTimedOutException) {
            return false;
        }

        clearstatcache();

        return $this->storageIsLinked($context);
    }
```

Why it is written this way:
- The toolchain is resolved when the step runs, not when it is built: `SetupRunner::steps()` builds the steps once, and wizard tests rebind `Toolchain` afterwards.
- Only a timeout is caught. Any other failure is a bug and surfaces as the step's failure.
- `StorageLinkCheck::fix()` keeps `PHP_BINARY`, because the doctor's `--fix` runs on the CLI.

- [ ] **Step 10: Run them and see them pass; verify by mutation**

Run: `php artisan test --compact tests/Feature/Setup tests/Feature/Console`
Expected: PASS. `DetectorTest`'s folder-name case fails only in a checkout whose folder name has upper-case letters (Task 10, Step 9).

Mutations:
1. Drop `|| ! $this->storageIsLinked($context)` from `isComplete()`. The first G6-09 test fails on the last `isComplete()` ("true is false").
2. Return `true` from `linkStorage()` without reading the link back. The second G6-09 test fails ('done').

- [ ] **Step 11: Spec, CHANGELOG, commit**

`docs/superpowers/specs/2026-10-10-skv3-design.md` §5.3.3, step 1: after `Existing `APP_KEY` preserved.` insert:

```
It also creates `public/storage` with `php artisan storage:link` (a child process in the project) while it is missing, because the frontend step is skipped when a build exists (CD-12); the link left to make is `manual('php artisan storage:link')`.
```

`CHANGELOG.md`, `### Fixed`, append:

```markdown
- Setup creates the `public/storage` link, so the doctor's `storage-link` row no longer warns after a fresh install.
```

```bash
git add app/Setup/Steps/EnvironmentStep.php tests/Feature/Setup/Steps/EnvironmentStepTest.php tests/Pest.php tests/Feature/Setup/Doctor/DoctorTest.php tests/Feature/Setup/Doctor/ChecksTest.php docs/superpowers/specs/2026-10-10-skv3-design.md CHANGELOG.md
git commit -m "fix: create public/storage in setup's environment step

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 12: Write the failing tests for private install files and read failures (L7b-03, L7b-05; CD-8)**

`tests/Feature/Setup/BootstrapTest.php`: rewrite the RL-1 copy test and add the read-failure test after it:

```php
it('copies .env.example atomically as 0600 whatever the example\'s mode, replacing a symlinked .env (RL-1, g2-08, CD-8)', function (): void {
    Process::fake(["*'artisan' 'migrate' '--graceful' '--force'*" => Process::result('Migrated')]);
    // A umask-002 checkout: group members could rewrite the example, and a copy of its mode.
    chmod($this->context->path('.env.example'), 0664);
    // Dangling: Bootstrap sees no .env and copies the example.
    symlink($this->context->path('elsewhere.env'), $this->context->path('.env'));

    new Bootstrap($this->context->basePath, $this->toolchain)->run();

    expect($this->context->path('elsewhere.env'))->not->toBeFile()
        ->and(is_link($this->context->path('.env')))->toBeFalse()
        ->and(fileperms($this->context->path('.env')) & 0777)->toBe(0600)
        ->and(new EnvFile($this->context->path('.env'))->get('APP_KEY'))->toStartWith('base64:');
});

it('stops with a message instead of writing an empty .env when .env.example cannot be read (L7b-03, CD-8)', function (): void {
    chmod($this->context->path('.env.example'), 0000);

    expect(fn (): array => new Bootstrap($this->context->basePath, $this->toolchain)->run())
        ->toThrow(RuntimeException::class, __('Could not read :file.', ['file' => '.env.example']))
        ->and($this->context->path('.env'))->not->toBeFile();
})->skip(fn (): bool => posix_geteuid() === 0, 'root reads every file');
```

`tests/Feature/Setup/Steps/DatabaseStepTest.php`: rewrite the RL-1 test and add the read-failure test after it:

```php
it('writes .env.testing atomically as 0600 whatever the mode of .env, replacing a symlink (RL-1, g2-08, CD-8)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['database' => 'sqlite'], Detection::none(), $context->folderName());
    chmod($context->path('.env'), 0644);
    File::put($context->path('elsewhere.env'), 'keep');
    symlink($context->path('elsewhere.env'), $context->path('.env.testing'));

    expect(new DatabaseStep()->run($context)->status)->toBe('done')
        ->and(file_get_contents($context->path('elsewhere.env')))->toBe('keep')
        ->and(is_link($context->path('.env.testing')))->toBeFalse()
        ->and(fileperms($context->path('.env.testing')) & 0777)->toBe(0600)
        ->and(new EnvFile($context->path('.env.testing'))->get('DB_DATABASE'))->toBe(':memory:');
});

it('stops with a message instead of writing an empty .env.testing when .env cannot be read (L7b-03, CD-8)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['database' => 'sqlite'], Detection::none(), $context->folderName());
    chmod($context->path('.env'), 0000);

    expect(fn (): mixed => new DatabaseStep()->run($context))
        ->toThrow(RuntimeException::class, __('Could not read :file.', ['file' => '.env']))
        ->and($context->path('.env.testing'))->not->toBeFile();
})->skip(fn (): bool => posix_geteuid() === 0, 'root reads every file');
```

(The two `posix_geteuid()` skips follow the existing `StateFileTest` and `ChecksTest` cases. The sandbox is removed with `File::deleteDirectory()`, which unlinks a 0000 file.)

`tests/Feature/Tooling/ComposerScriptsTest.php`: on every real install route `.env` exists before `skv:bootstrap` runs. The Herd GUI, `laravel new` and `composer create-project` run Composer's `post-root-package-install` hook, and `composer setup` (and the e2e clone route) run the same one-liner, `file_exists('.env') || copy('.env.example', '.env')`. PHP's `copy()` creates the file as `0666 & ~umask` (0644) whatever the example's mode, so Bootstrap's 0600 branch never runs there, and `APP_KEY`, `HEALTH_CHECK_KEY` and `BACKUP_ARCHIVE_PASSWORD` land in a world-readable file (`EnvFile::set()` keeps the mode). In the three expectations that spell the one-liner ('runs skv:bootstrap from the Composer and installer hooks', 'sets up through skv:bootstrap and skv:install' and 'ends composer setup with the CLI after the commands the clone route repeats'), replace

```php
"@php -r \"file_exists('.env') || copy('.env.example', '.env');\""
```

with

```php
"@php -r \"file_exists('.env') || (copy('.env.example', '.env') && chmod('.env', 0600));\""
```

'repeats the commands of composer setup in the clone route of scripts/e2e-local.sh' stays as it is: it compares the clone route with `composer.json`, so it fails until both change.

- [ ] **Step 13: Run them and see them fail**

Run: `php artisan test --compact tests/Feature/Setup/BootstrapTest.php tests/Feature/Setup/Steps/DatabaseStepTest.php tests/Feature/Tooling/ComposerScriptsTest.php`

Expected: seven failures.
- The two mode tests: "436 is identical to 384" (0664 copied) and "420 is identical to 384" (0644 copied).
- The two read tests: the exception message is "file_get_contents(/…/.env.example): Failed to open stream: Permission denied", not "Could not read .env.example.". The same goes for `.env`. See the evidence correction above.
- The three `ComposerScriptsTest` expectations: `composer.json` still holds the old one-liner.

- [ ] **Step 14: Read through `EnvFile::contents()`, write 0600**

`app/Setup/Support/EnvFile.php`: replace `all()` and add `contents()` after it:

```php
    /**
     * @return array<string, string> every value as Dotenv reads it; none when the file is missing
     *
     * @throws RuntimeException when the file exists but cannot be read (L7b-03)
     */
    public function all(): array
    {
        if (! $this->exists()) {
            return [];
        }

        return array_map(strval(...), Dotenv::parse($this->contents()));
    }

    /**
     * The file's text. A file that cannot be read stops the caller with "Could not read :file." instead of reading as
     * empty, so a copy of it is never written blank (L7b-03, CD-8).
     *
     * @throws RuntimeException when the file is missing or cannot be read
     */
    public function contents(): string
    {
        $contents = is_file($this->path) && is_readable($this->path) ? @file_get_contents($this->path) : false;

        return is_string($contents) ? $contents : throw new RuntimeException(__('Could not read :file.', ['file' => basename($this->path)]));
    }
```

In `set()` replace `$original = (string) file_get_contents($this->path);` with `$original = $this->contents();`.

The `@` keeps a read that fails between `is_readable()` and the read from reaching Laravel's error handler as a warning; `false` then becomes the refusal. `Toolchain::projectEnvKeys()` already checks `is_readable()` before `all()`, so its "never throws" rule holds.

`app/Setup/Support/Bootstrap.php`: replace

```php
        if (! $env->exists() && is_file($this->basePath.'/.env.example')) {
            $example = $this->basePath.'/.env.example';
            // Atomically, with the example's mode: a symlinked .env is replaced, never written through (RL-1).
            StateFile::write($this->basePath.'/.env', (string) file_get_contents($example), fileperms($example) & 0777);
            $actions[] = __('Created .env from .env.example');
        }
```

with

```php
        if (! $env->exists() && is_file($this->basePath.'/.env.example')) {
            // Atomically, so a symlinked .env is replaced, never written through (RL-1); 0600 whatever the example's
            // mode, because APP_KEY and the generated secrets go into it next (CD-8). An example that cannot be read
            // stops here instead of leaving an empty .env (L7b-03).
            StateFile::write($this->basePath.'/.env', new EnvFile($this->basePath.'/.env.example')->contents(), 0600);
            $actions[] = __('Created .env from .env.example');
        }
```

`EnvFile::set()` keeps a file's mode, so the `APP_KEY` and secrets written next keep `.env` at 0600. Bootstrap's own write covers a bare `skv:bootstrap` on a clone without `.env`; the install routes create `.env` earlier, through Composer's hook:

`composer.json`, in `scripts`, replace the one-liner in `post-root-package-install` and the third entry of `setup`

```json
"@php -r \"file_exists('.env') || copy('.env.example', '.env');\""
```

with

```json
"@php -r \"file_exists('.env') || (copy('.env.example', '.env') && chmod('.env', 0600));\""
```

Nothing else in `composer.json` changes, and `composer.lock` does not (its content hash covers `require`, `extra` and the like, not `scripts`). Run `composer validate --no-check-publish`: it must report the file valid and the lock file up to date.

`scripts/e2e-local.sh`, in the `clone)` route, replace `&& php -r "file_exists('.env') || copy('.env.example', '.env');" \` with:

```bash
                && php -r "file_exists('.env') || (copy('.env.example', '.env') && chmod('.env', 0600));" \
```

`docs/handleiding.html` (the clone instructions, "Bereid de app voor"): replace `php -r "file_exists('.env') || copy('.env.example', '.env');"` with `php -r "file_exists('.env') || (copy('.env.example', '.env') &amp;&amp; chmod('.env', 0600));"` (inside `<pre><code>`, so `&&` is written `&amp;&amp;`).

`app/Setup/Steps/DatabaseStep.php`, in `writeEnvironment()`: replace

```php
        // Atomically with .env's mode: .env.testing never sits at the umask's mode, and a symlink is replaced (RL-1).
        StateFile::write($testing, (string) file_get_contents($env), fileperms($env) & 0777);
```

with

```php
        // Atomically and 0600, whatever .env's mode, because it holds the same secrets (CD-8); a symlink is replaced
        // (RL-1), and a .env that cannot be read stops the step instead of leaving an empty .env.testing (L7b-03).
        StateFile::write($testing, new EnvFile($env)->contents(), 0600);
```

`lang/en.json` and `lang/nl.json`: add the key right before `"Could not write :file."`. That is its byte-wise `sort()` position, after `"Could not create a temporary copy of [:path]."`.

```json
    "Could not read :file.": "Could not read :file.",
```

```json
    "Could not read :file.": "Kon :file niet lezen.",
```

- [ ] **Step 15: Run them and see them pass; verify by mutation**

Run: `php artisan test --compact tests/Feature/Setup tests/Feature/Console tests/Feature/Tooling/ComposerScriptsTest.php tests/Feature/LangCoverageTest.php tests/Feature/Localisation/LangFilesTest.php`
Expected: PASS. Also `composer validate --no-check-publish` (valid, lock file up to date) and `git diff --stat -- composer.lock` (empty).

Mutations:
1. In `Bootstrap`, write `fileperms(…) & 0777` again. The Bootstrap mode test fails.
2. In `contents()`, return `''` instead of throwing. Both read tests fail. Each step writes an empty copy, and nothing is thrown.
3. In `DatabaseStep`, write with `0644`. The DatabaseStep mode test fails.
4. In `scripts/e2e-local.sh`, put the old one-liner back in the clone route. Expect 'repeats the commands of composer setup in the clone route of scripts/e2e-local.sh' to fail with "The clone route lacks […]".

- [ ] **Step 16: Spec, CHANGELOG, commit**

`docs/superpowers/specs/2026-10-10-skv3-design.md` §5.3.3, step 2: replace `Writes `.env.testing` (`<db>_test`, or in-memory SQLite).` with:

```
Writes `.env.testing` (`<db>_test`, or in-memory SQLite) with mode 0600, like the `.env` that `skv:bootstrap` creates (CD-8).
```

In the same spec, replace the one-liner in §5.2 (the `post-root-package-install` bullet) and in §5.3's `composer setup` line the same way as in `composer.json`: `file_exists('.env') || copy('.env.example', '.env');` becomes `file_exists('.env') || (copy('.env.example', '.env') && chmod('.env', 0600));` (in §5.3 inside the escaped `\"…\"` string, as there).

`CHANGELOG.md`, `### Changed`, append:

```markdown
- `skv:bootstrap` creates `.env`, and setup's database step `.env.testing`, with mode 0600 whatever the mode of `.env.example`, also when Composer's install hook (`post-root-package-install`, `composer setup`) creates `.env`; an `.env.example` or `.env` that cannot be read stops them with "Could not read .env." instead of a PHP warning that names the full path.
```

```bash
git add app/Setup/Support/EnvFile.php app/Setup/Support/Bootstrap.php app/Setup/Steps/DatabaseStep.php \
    composer.json scripts/e2e-local.sh docs/handleiding.html \
    tests/Feature/Setup/BootstrapTest.php tests/Feature/Setup/Steps/DatabaseStepTest.php tests/Feature/Tooling/ComposerScriptsTest.php \
    lang/en.json lang/nl.json docs/superpowers/specs/2026-10-10-skv3-design.md CHANGELOG.md
git commit -m "fix: write .env and .env.testing 0600 and refuse an env file that cannot be read

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 17: Task gate**

```bash
composer check
composer test:pgsql
php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser
npm run build && composer test:browser
npm run build:setup && git diff --exit-code resources/setup/setup.css
php artisan config:clear
```

Expected: all green. The setup stylesheet is unchanged, because this task edits no setup view. In the report, name each mutation of Steps 4, 10 and 15 with its result.

---

### Task 12: Ops and backups (G6-05, G6-10, L7b-56, L7b-57, L7b-58, L7b-68, L7b-51, L7b-69; CD-9)

**Files:**
- Create:
  - `app/Support/ProcessAccount.php` (the effective uid, null without ext-posix; one seam for the "this account" rules, CD-9)
  - `tests/Fixtures/PgSslModeSnapshot.php` (takes and restores `PGSSLMODE` in `getenv()`, `$_SERVER` and `$_ENV`)
- Modify:
  - `app/Http/Middleware/ThrottleHealthChecks.php` (the key bucket is an HMAC under `APP_KEY`, L7b-57; a broken ops store logs one line instead of `report()`, L7b-58; the docblock says the throttle does not slow down key guessing, L7b-56)
  - `app/Support/Backups/BackupEncryption.php` (`problem()` names the cause; `available()` delegates to it)
  - `app/Setup/Doctor/Checks/LastBackupCheck.php` (one message and fix per cause, G6-05; a `$zipHasAes256` seam)
  - `config/filesystems.php` (`backups` disk `visibility => private`, G6-10)
  - `app/Support/Backups/ConfigurePgDump.php` (`passSslMode()` public, narrowed to `--db-name`; the listener exports `PGSSLMODE` only as the backstop, when `backup:run` is not the app's `BackupRunCommand`, L7b-68, L-05)
  - `app/Console/Commands/BackupRunCommand.php` (calls `passSslMode()` once `--config` and `--db-name` are known)
  - `app/Support/Backups/PrivateBackupTemp.php` (the uid from `ProcessAccount`, fail closed without ext-posix, L7b-51; under euid 0 only below `storage_path()`, L7b-69; `MESSAGE` extended)
  - `app/Setup/Doctor/PostgresBinaries.php` (the uid from `ProcessAccount`; without ext-posix only root-owned Herd candidates; `processUserId()` removed)
  - `app/Setup/Doctor/Checks/PgDumpCheck.php` (fails without ext-posix and says why, CD-9)
  - `lang/nl.json`, `lang/en.json`
  - `CHANGELOG.md`
- Test:
  - `tests/Feature/Health/HealthCheckEndpointTest.php`, `tests/Feature/Health/OpsCacheTest.php`, `tests/Feature/Health/HealthChecksTest.php`
  - `tests/Feature/Backups/BackupEncryptionTest.php`, `tests/Feature/Backups/BackupConfigTest.php`
  - `tests/Feature/Backups/ConfigurePgDumpTest.php`, `tests/Feature/Backups/BackupRunCommandTest.php`, `tests/Feature/Backups/BackupRunTest.php`
  - `tests/Feature/Backups/PrivateBackupTempTest.php`, `tests/Feature/Setup/Doctor/PostgresBinariesTest.php`, `tests/Feature/Setup/Doctor/ChecksTest.php`

**Interfaces:**
- Consumes (main `a192dba`; no earlier M7c task changes these files):
  - `HealthCheckKey::configured(): ?string`, `HealthCheckKey::matches(?string): bool`, `HealthCheckKey::HEADER`
  - `OpsCache::store(): Repository`
  - spatie's `BackupCommand::resolveConfig()` (sets `config('backup')` to the `--config` array) and `BackupJob::onlyDbName()` (keeps only the named source connections)
  - `useBackupsModule(bool)`, `setupSandbox()`
- Produces:
  - `App\Support\ProcessAccount::__construct(?Closure $probe = null)`, `uid(): ?int` (the effective uid; null without ext-posix). Resolved from the container (`app(ProcessAccount::class)`); tests bind `new ProcessAccount(fn (): ?int => null)` (no ext-posix) or `fn (): int => 0` (root).
  - `BackupEncryption::problem(?bool $zipHasAes256 = null): ?string`, one of `BackupEncryption::NO_PASSWORD` (`'password'`), `ENCRYPTION_OFF` (`'encryption'`), `NO_AES256` (`'aes256'`), or null. `available()` keeps its signature and equals `problem() === null`.
  - `LastBackupCheck::__construct(?bool $zipHasAes256 = null)`
  - `ConfigurePgDump::passSslMode(array $only = []): void` (public; `list<string>` of `--db-name` connections, empty for every source)
  - `ThrottleHealthChecks` key bucket: `KEY_BUCKET_PREFIX.hash_hmac('sha256', $key, (string) config('app.key'))`
  - `PrivateBackupTemp::MESSAGE` (new text, see Step 5)
  - `Tests\Fixtures\PgSslModeSnapshot::takeAndClear(): self`, `->restore(): void`

Notes for the implementer:
- **Never use `git stash` to try a mutation.** The stash is shared by every worktree of the repository; copy the file aside (`cp`) and back instead.
- `uncompromised()` is untouched here; Task 13 owns `AppServiceProvider`.
- The `pg-dump` row now fails on a PHP without ext-posix. Herd's PHP has posix, and CI does too (the suite already calls `posix_geteuid()` unguarded in several `->skip()` conditions), so `DoctorTest`'s fresh-install expectation stays green.

- [ ] **Step 1: Write the failing tests**

`tests/Fixtures/PgSslModeSnapshot.php` (new):

```php
<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * PGSSLMODE in the three places ConfigurePgDump::passSslMode() writes it (getenv(), $_SERVER, $_ENV), taken before a
 * test and put back after it (L-05, L7b-68): putenv() outlives the test, and a later pg_dump or psql would inherit it.
 */
final readonly class PgSslModeSnapshot
{
    private function __construct(private string|false $getenv, private mixed $server, private mixed $env) {}

    /** Takes the current values and clears all three, so the test starts without PGSSLMODE. */
    public static function takeAndClear(): self
    {
        $snapshot = new self(getenv('PGSSLMODE'), $_SERVER['PGSSLMODE'] ?? null, $_ENV['PGSSLMODE'] ?? null);
        putenv('PGSSLMODE');
        unset($_SERVER['PGSSLMODE'], $_ENV['PGSSLMODE']);

        return $snapshot;
    }

    public function restore(): void
    {
        putenv($this->getenv === false ? 'PGSSLMODE' : 'PGSSLMODE='.$this->getenv);
        unset($_SERVER['PGSSLMODE'], $_ENV['PGSSLMODE']);

        if ($this->server !== null) {
            $_SERVER['PGSSLMODE'] = $this->server;
        }

        if ($this->env !== null) {
            $_ENV['PGSSLMODE'] = $this->env;
        }
    }
}
```

**The health-check throttle (L7b-57, L7b-58).** `tests/Feature/Health/HealthCheckEndpointTest.php`: add `use Illuminate\Support\Facades\Log;` to the imports (after `use Illuminate\Support\Facades\Exceptions;`). Add this test right before `it('answers 429 to wrong keys once 300 came in a minute, …`:

```php
it('counts the key under an HMAC with APP_KEY, never its plain SHA-256 (L7b-57)', function (): void {
    $limiter = new RateLimiter(OpsCache::store());

    $this->withHeaders([HealthCheckKey::HEADER => $this->key])->getJson('/health-check')->assertOk();

    expect($limiter->attempts(ThrottleHealthChecks::KEY_BUCKET_PREFIX.hash_hmac('sha256', $this->key, (string) config('app.key'))))->toBe(1)
        ->and($limiter->attempts(ThrottleHealthChecks::KEY_BUCKET_PREFIX.hash('sha256', $this->key)))->toBe(0)
        ->and($limiter->attempts(ThrottleHealthChecks::KEY_BUCKET_PREFIX.$this->key))->toBe(0);
});
```

and this one right before `it('answers with the fresh report while the ops store reads but cannot be written, …`:

```php
it('logs one line without a trace, and reports nothing, for a key-less request while the ops store is broken (L7b-58)', function (): void {
    Exceptions::fake();
    $log = Log::spy();
    config(['skv.ops_cache_store' => 'no-such-store']);

    $this->getJson('/health-check')->assertForbidden();

    Exceptions::assertNothingReported();
    $log->shouldHaveReceived('warning')->once()->with('The /health-check throttle is off: the ops cache store failed (InvalidArgumentException).');
});
```

The existing "answers 503 with JSON, never a 500, while the ops cache store is broken" test keeps `Exceptions::assertReported(InvalidArgumentException::class)`: for a key holder the controller still reports the failed read.

`tests/Feature/Health/OpsCacheTest.php`, in 'keeps the health report, the throttle and the heartbeat on the ops store…', replace the `$bucket` line:

```php
    $bucket = ThrottleHealthChecks::KEY_BUCKET_PREFIX.hash_hmac('sha256', $key, (string) config('app.key'));
```

**The last-backup message (G6-05).** `tests/Feature/Health/HealthChecksTest.php`: in 'fails while the backups would not be encrypted, even with a fresh backup (CD-10)', replace the message expectation:

```php
        ->and($result->message)->toBe(__('Backups are not encrypted: BACKUP_ARCHIVE_PASSWORD is empty'))
```

Replace the whole test 'fails while the encryption setting turns encryption off (CD-10)' with these two:

```php
it('fails while the encryption setting turns encryption off, and says so with its own fix (CD-10, G6-05)', function (string $encryption): void {
    // useBackupsModule(true) sets a password: only the setting is wrong.
    useBackupsModule(true);
    config(['backup.backup.encryption' => $encryption]);
    Storage::fake('backups');
    Storage::disk('backups')->put(config('backup.backup.name').'/'.now()->format('Y-m-d-H-i-s').'.zip', 'fresh');

    $result = new LastBackupCheck()->run($this->context);

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toBe(__('Backups are not encrypted: backup.backup.encryption turns encryption off'))
        ->and($result->fix)->toBe('config/backup.php');
})->with(['none' => 'none', 'an unknown value' => 'aes-256']);

it('fails on a PHP zip without AES-256 and says so (CD-10, G6-05)', function (): void {
    useBackupsModule(true);
    Storage::fake('backups');
    Storage::disk('backups')->put(config('backup.backup.name').'/'.now()->format('Y-m-d-H-i-s').'.zip', 'fresh');

    $result = new LastBackupCheck(zipHasAes256: false)->run($this->context);

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toBe(__('Backups are not encrypted: this PHP zip has no AES-256'))
        ->and($result->fix)->toBe(__('Use a PHP whose zip extension supports AES-256'));
});
```

`tests/Feature/Backups/BackupEncryptionTest.php`: append:

```php
it('names why an archive would not be encrypted, the password first (G6-05)', function (array $config, ?bool $zipHasAes256, ?string $problem): void {
    config(['backup.backup.password' => 'correct-horse-battery', 'backup.backup.encryption' => 'default', ...$config]);

    expect(BackupEncryption::problem($zipHasAes256))->toBe($problem);
})->with([
    'encrypted' => [[], true, null],
    'blank password' => [['backup.backup.password' => ''], true, BackupEncryption::NO_PASSWORD],
    'blank password and encryption none' => [['backup.backup.password' => '', 'backup.backup.encryption' => 'none'], true, BackupEncryption::NO_PASSWORD],
    'encryption none' => [['backup.backup.encryption' => 'none'], true, BackupEncryption::ENCRYPTION_OFF],
    'unknown encryption' => [['backup.backup.encryption' => 'aes-256'], true, BackupEncryption::ENCRYPTION_OFF],
    'no AES-256 for default' => [[], false, BackupEncryption::NO_AES256],
    'no AES-256 for aes256' => [['backup.backup.encryption' => 'aes256'], false, BackupEncryption::NO_AES256],
]);
```

**Private archives (G6-10).** `tests/Feature/Backups/BackupConfigTest.php`: add `use Illuminate\Support\Facades\File;`, `use Illuminate\Support\Facades\Storage;` and `use Illuminate\Support\Str;` to the imports (before `use Spatie\Backup\Config\Config;`), and append:

```php
it('writes archives on the backups disk readable by this account only (§4.9, G6-10)', function (): void {
    // As the package writes them: through the Flysystem driver's writeStream(), under a world-readable umask.
    $root = storage_path('framework/testing/backups-'.Str::uuid());
    $umask = umask(0022);

    try {
        $disk = Storage::build([...config('filesystems.disks.backups'), 'root' => $root]);
        $stream = fopen('php://memory', 'r+') ?: throw new LogicException('Could not open a memory stream.');
        fwrite($stream, 'archive');
        rewind($stream);
        $disk->getDriver()->writeStream('app/2026-10-16-02-00-00.zip', $stream);
        clearstatcache();

        expect(fileperms($root.'/app/2026-10-16-02-00-00.zip') & 0777)->toBe(0600)
            ->and(fileperms($root.'/app') & 0777)->toBe(0700);
    } finally {
        umask($umask);
        File::deleteDirectory($root);
    }
});
```

**`backup:run --config` / `--db-name` and `PGSSLMODE` (L7b-68).** `tests/Feature/Backups/ConfigurePgDumpTest.php`: add `use Illuminate\Support\Facades\Artisan;` after `use Illuminate\Console\Events\CommandStarting;`, `use Spatie\Backup\Commands\BackupCommand;` before `use Symfony\Component\Console\Input\ArrayInput;` and `use Tests\Fixtures\PgSslModeSnapshot;` to the imports. Replace the `PGSSLMODE` part of `beforeEach()` (the comment and the four lines from `$this->pgSslMode = [...]` to `unset(...)`) and the whole `afterEach()`:

```php
    // L-05: ConfigurePgDump exports PGSSLMODE; start without one and put the original back afterwards.
    $this->pgSslMode = PgSslModeSnapshot::takeAndClear();
});

afterEach(function (): void {
    $this->pgSslMode->restore();
});
```

Rewrite the three `PGSSLMODE` tests (from 'hands the backup source's sslmode to pg_dump as PGSSLMODE (L-05)' to the end of the file) as:

```php
it('hands the backup source\'s sslmode to pg_dump as PGSSLMODE (L-05)', function (): void {
    config([
        'backup.backup.source.databases' => ['pgsql'],
        'database.connections.pgsql.sslmode' => 'verify-full',
    ]);

    new ConfigurePgDump($this->sandbox->path('no-herd-services'), $this->sandbox->basePath)->passSslMode();

    // Symfony's Process passes on the getenv() keys that $_SERVER also holds, plus every $_ENV key.
    expect(getenv('PGSSLMODE'))->toBe('verify-full')
        ->and($_SERVER['PGSSLMODE'] ?? null)->toBe('verify-full')
        ->and($_ENV['PGSSLMODE'] ?? null)->toBe('verify-full');
});

it('reads the sslmode from the connection\'s url as the app and the dumper do (L-05)', function (): void {
    // ConfigurationUrlParser lets the url's query override the sslmode key (DATABASE_URL on managed PostgreSQL).
    config([
        'backup.backup.source.databases' => ['pgsql'],
        'database.connections.pgsql.url' => 'pgsql://backup:secret@db.example.com:5432/app?sslmode=verify-full',
        'database.connections.pgsql.sslmode' => 'prefer',
    ]);

    new ConfigurePgDump($this->sandbox->path('no-herd-services'), $this->sandbox->basePath)->passSslMode();

    expect(getenv('PGSSLMODE'))->toBe('verify-full')
        ->and($_SERVER['PGSSLMODE'] ?? null)->toBe('verify-full')
        ->and($_ENV['PGSSLMODE'] ?? null)->toBe('verify-full');
});

it('leaves PGSSLMODE alone for a SQLite source and a connection --db-name leaves out (L-05, L7b-68)', function (array $sources, ?string $dbName): void {
    config([
        'backup.backup.source.databases' => $sources,
        'database.connections.pgsql.sslmode' => 'verify-full',
    ]);

    new ConfigurePgDump($this->sandbox->path('no-herd-services'), $this->sandbox->basePath)->passSslMode($dbName === null ? [] : [$dbName]);

    expect(getenv('PGSSLMODE'))->toBeFalse()
        ->and($_ENV)->not->toHaveKey('PGSSLMODE');
})->with([
    'a SQLite source' => [['sqlite'], null],
    'a PostgreSQL source --db-name leaves out' => [['pgsql', 'sqlite'], 'sqlite'],
]);

it('takes the sslmode of the connection --db-name names, not the first source\'s (L7b-68)', function (): void {
    config([
        'backup.backup.source.databases' => ['pgsql', 'pgsql_reports'],
        'database.connections.pgsql.sslmode' => 'prefer',
        'database.connections.pgsql_reports' => [...config('database.connections.pgsql'), 'sslmode' => 'require'],
    ]);

    new ConfigurePgDump($this->sandbox->path('no-herd-services'), $this->sandbox->basePath)->passSslMode(['pgsql_reports']);

    expect(getenv('PGSSLMODE'))->toBe('require');
});

it('leaves PGSSLMODE to backup:run, which knows its --config, for every command it listens to (L-05, L7b-68)', function (string $command) use ($starting): void {
    // Before handle() the default configuration's sources are all the listener could read.
    config([
        'backup.backup.source.databases' => ['pgsql'],
        'database.connections.pgsql.sslmode' => 'verify-full',
        'database.connections.pgsql.dump.dump_binary_path' => '/opt/postgresql/bin',
    ]);

    new ConfigurePgDump($this->sandbox->path('no-herd-services'), $this->sandbox->basePath)($starting($command));

    expect(getenv('PGSSLMODE'))->toBeFalse()
        ->and($_ENV)->not->toHaveKey('PGSSLMODE');
})->with(['backup:run', 'migrate']);

it('still exports the default sources\' sslmode should the package\'s command hold backup:run again (L-05, L7b-68)', function () use ($starting): void {
    config([
        'backup.backup.source.databases' => ['pgsql'],
        'database.connections.pgsql.sslmode' => 'verify-full',
        'database.connections.pgsql.dump.dump_binary_path' => '/opt/postgresql/bin',
    ]);
    // The backstop path, bound as RefuseUnencryptedBackupsTest binds it: nothing else would export PGSSLMODE.
    Artisan::registerCommand(app(BackupCommand::class));

    new ConfigurePgDump($this->sandbox->path('no-herd-services'), $this->sandbox->basePath)($starting('backup:run'));

    expect(getenv('PGSSLMODE'))->toBe('verify-full');
});
```

(The old 'leaves PGSSLMODE alone for other commands and other databases' rows live on: 'a SQLite source' in the third test, 'another command' as the `migrate` row of the last.)

`tests/Feature/Backups/BackupRunCommandTest.php`: add `use Tests\Fixtures\PgSslModeSnapshot;` to the imports. At the end of `beforeEach()`, after `Config::rebind();`, add the snapshot and an `afterEach()`:

```php
    Config::rebind();
    // L7b-68: handle() exports PGSSLMODE; start without one and put the original back afterwards.
    $this->pgSslMode = PgSslModeSnapshot::takeAndClear();
});

afterEach(function (): void {
    $this->pgSslMode->restore();
});
```

Add this test right before `it('hands an encrypted backup to the package (CD-7)', …`:

```php
it('hands pg_dump the sslmode of the sources --config and --db-name choose, not the default ones (L7b-68)', function (array $options, string $expected): void {
    // The default configuration dumps pgsql (prefer); backup_reports dumps pgsql_reports (require), then pgsql_audit (verify-full).
    config([
        'backup.backup.source.databases' => ['pgsql'],
        'database.connections.pgsql.sslmode' => 'prefer',
        'database.connections.pgsql_reports' => [...config('database.connections.pgsql'), 'sslmode' => 'require'],
        'database.connections.pgsql_audit' => [...config('database.connections.pgsql'), 'sslmode' => 'verify-full'],
    ]);
    /** @var array<string, mixed> $backup */
    $backup = config('backup');
    config(['backup_reports' => array_replace_recursive($backup, ['backup' => ['source' => ['databases' => ['pgsql_reports', 'pgsql_audit']]]])]);
    // A shared sticky temporary directory stops the run after the export, before pg_dump starts (M22).
    File::ensureDirectoryExists($this->sandbox->path('backup-temp'));
    chmod($this->sandbox->path('backup-temp'), 01777);

    $exit = Artisan::call('backup:run', ['--only-db' => true, '--disable-notifications' => true, ...$options]);

    expect($exit)->toBe(1)
        ->and(getenv('PGSSLMODE'))->toBe($expected)
        ->and($_SERVER['PGSSLMODE'] ?? null)->toBe($expected)
        ->and($_ENV['PGSSLMODE'] ?? null)->toBe($expected);
})->with([
    'the default configuration' => [[], 'prefer'],
    '--config' => [['--config' => 'backup_reports'], 'require'],
    '--config and --db-name' => [['--config' => 'backup_reports', '--db-name' => ['pgsql_audit']], 'verify-full'],
]);
```

(The comment avoids the word "dump" right before "(": `KitRulesTest`'s debugging-leftovers scan reads `dump (` as a call.)

`tests/Feature/Backups/BackupRunTest.php` (the real `pg_dump` run now exports the test connection's `sslmode`): add `use Tests\Fixtures\PgSslModeSnapshot;` to the imports and, right after the `$usablePgDump` closure:

```php
beforeEach(function (): void {
    // L7b-68: backup:run exports the source's sslmode as PGSSLMODE; put the original back afterwards.
    $this->pgSslMode = PgSslModeSnapshot::takeAndClear();
});

afterEach(function (): void {
    $this->pgSslMode->restore();
});
```

**"This account" without ext-posix and under root (CD-9, L7b-51, L7b-69).** `tests/Feature/Backups/PrivateBackupTempTest.php`: add `use App\Support\ProcessAccount;` after `use App\Support\Backups\PrivateBackupTemp;` and append:

```php
it('counts no directory as this account\'s without ext-posix, so it changes and empties nothing (CD-9, L7b-51)', function () use ($mode): void {
    // getmyuid() is the entry script's owner, not the process: without posix_geteuid() the account is unknown.
    app()->instance(ProcessAccount::class, new ProcessAccount(fn (): ?int => null));
    File::ensureDirectoryExists($this->temp.'/temp');
    chmod($this->temp, 0755);
    File::put($this->temp.'/temp/database.sql', 'plaintext');
    touch($this->temp.'/temp/database.sql', now()->subHours(7)->getTimestamp());
    $temp = new PrivateBackupTemp(systemTemp: $this->sandbox->path('system-temp'));

    expect($temp->secure())->toBeFalse()
        ->and($temp->removeStale())->toBe(0)
        ->and($mode($this->temp))->toBe(0755)
        ->and(is_file($this->temp.'/temp/database.sql'))->toBeTrue();
});

it('as root, counts only a directory inside storage/ as its own, never a root-owned system directory (L7b-69)', function () use ($mode): void {
    // The process stands in for root; /usr belongs to root, so without the rule secure() would chmod it to 0700.
    app()->instance(ProcessAccount::class, new ProcessAccount(fn (): int => 0));
    config(['backup.backup.temporary_directory' => '/usr']);
    $before = $mode('/usr');
    $temp = new PrivateBackupTemp(systemTemp: $this->sandbox->path('system-temp'));

    expect($temp->secure())->toBeFalse()
        ->and($temp->removeStale())->toBe(0)
        ->and($mode('/usr'))->toBe($before);
})->skip(fn (): bool => posix_geteuid() === 0, 'a real root could chmod /usr should the rule regress');
```

(The probe is `fn (): int => 0`, not `?int`: Rector's `NarrowWideUnionReturnTypeRector` narrows it otherwise.)

`tests/Feature/Setup/Doctor/PostgresBinariesTest.php`: add `use App\Support\ProcessAccount;` after `use App\Setup\Support\Toolchain;` and append:

```php
it('trusts only root-owned Herd candidates without ext-posix, where this account is unknown (CD-9, L7b-51)', function () use ($stub): void {
    app()->instance(ProcessAccount::class, new ProcessAccount(fn (): ?int => null));
    $stub($this->services.'/18/bin/pg_dump');

    $found = new PostgresBinaries($this->services)->find($this->context, 'pg_dump');

    // The sandbox belongs to this account, not root: a PATH pg_dump may still be found, never the Herd one.
    expect(str_starts_with((string) $found, $this->root.'/'))->toBeFalse();
})->skip(fn (): bool => posix_geteuid() === 0, 'Running as root: root owns every candidate.');
```

`tests/Feature/Setup/Doctor/ChecksTest.php`: add `use App\Support\ProcessAccount;` right before `use App\Support\Skv;`, and add this test right before `it('honours PG_DUMP_BINARY_PATH (§5.4)', …`:

```php
it('fails the pg_dump row without ext-posix, because backup:run then refuses to start (CD-9, L7b-51)', function () use ($stub): void {
    config(['skv.modules.backups' => true]);
    $stub($this->sandbox->path('pg/bin/pg_dump'));
    new EnvFile($this->sandbox->path('.env'))->set(['PG_DUMP_BINARY_PATH' => $this->sandbox->path('pg/bin')]);
    app()->instance(ProcessAccount::class, new ProcessAccount(fn (): ?int => null));

    $result = new PgDumpCheck($this->sandbox->path('herd-services'))->run($this->context);

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toBe(__('PHP has no posix extension, so backup:run cannot tell this account\'s directories and refuses to start'))
        ->and($result->fix)->toBe(__('Install the posix extension'));
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Health tests/Feature/Backups tests/Feature/Setup/Doctor`

Expected: FAIL, as follows:
- 'counts the key under an HMAC…': `attempts(…hash_hmac…)` is 0 (the bucket is the plain SHA-256); OpsCacheTest's ops-store test fails the same way.
- 'logs one line without a trace…': "The following exceptions were reported: InvalidArgumentException".
- HealthChecksTest: the three message expectations get 'Backups are not encrypted: BACKUP_ARCHIVE_PASSWORD is empty or this PHP zip has no AES-256'; the AES-256 test fails with "Unknown named parameter $zipHasAes256".
- BackupEncryptionTest fails to load its dataset: `BackupEncryption::NO_PASSWORD` is undefined.
- 'writes archives on the backups disk readable by this account only': 420 (0644) instead of 384 (0600).
- ConfigurePgDumpTest: "Call to private method …passSslMode()"; the listener's `backup:run` row sees `verify-full` instead of false. The backstop test passes already (today's listener exports on every `backup:run`); mutation 7 proves it.
- BackupRunCommandTest's three sslmode rows: `getenv('PGSSLMODE')` is false (without `WithConsoleEvents` no listener runs, and `handle()` exports nothing).
- The PrivateBackupTemp, PostgresBinaries and ChecksTest ext-posix and root tests: "Class "App\Support\ProcessAccount" not found".

- [ ] **Step 3: The throttle, the last-backup message and private archives (L7b-56, L7b-57, L7b-58, G6-05, G6-10)**

`app/Http/Middleware/ThrottleHealthChecks.php`: add `use Illuminate\Support\Facades\Log;` after `use Illuminate\Http\Request;`. Replace the class docblock, the `KEY_BUCKET_PREFIX` docblock and `handle()`:

```php
/**
 * Throttles /health-check on the ops store (OpsCache), not the default database store the `throttle` middleware uses,
 * so a database outage is reported as a 503 instead of failing the limiter. The key decides the bucket (CD-4):
 * requests with the configured key count per key, so strangers never lock the monitor out; all other requests share
 * one bucket, so key-less traffic leaves one cache entry instead of one per address. Because the key picks the bucket
 * before any limit applies, the throttle does not slow down guessing the key (L7b-56): only the key's randomness does,
 * so a hand-set HEALTH_CHECK_KEY must be random (setup and skv:doctor --fix generate one).
 *
 * A broken ops store (an unknown OPS_CACHE_STORE, redis down, a full disk) lets the request through, so it never
 * locks monitoring out; the controller then answers a key holder 503 with JSON, never a 500, and reports the error.
 * The throttle itself logs one warning line with the exception's class only (L7b-58): no trace and no message, which
 * could hold the cache key, so key-less requests cannot flood the log.
 */
final readonly class ThrottleHealthChecks
{
    /** Requests a minute with the configured key, counted per key. */
    public const int PER_MINUTE = 60;

    /** Requests a minute without a matching key, counted together. */
    public const int DENIED_PER_MINUTE = 300;

    public const string DENIED_BUCKET = 'health-check:denied';

    /**
     * Followed by an HMAC-SHA-256 of the key under APP_KEY (L7b-57): neither the key nor a hash anyone can test guesses
     * against offline becomes a cache key.
     */
    public const string KEY_BUCKET_PREFIX = 'health-check:ok:';

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $limiter = new RateLimiter(OpsCache::store());
            $key = HealthCheckKey::configured();
            [$bucket, $limit] = $key !== null && HealthCheckKey::matches($request->header(HealthCheckKey::HEADER))
                ? [self::KEY_BUCKET_PREFIX.hash_hmac('sha256', $key, (string) config('app.key')), self::PER_MINUTE]
                : [self::DENIED_BUCKET, self::DENIED_PER_MINUTE];

            if ($limiter->tooManyAttempts($bucket, $limit)) {
                return new JsonResponse(['message' => 'Too Many Attempts.'], 429, [
                    'Retry-After' => (string) $limiter->availableIn($bucket),
                    'Cache-Control' => 'no-store',
                ]);
            }

            $limiter->hit($bucket, 60);
        } catch (Throwable $exception) {
            Log::warning('The /health-check throttle is off: the ops cache store failed ('.$exception::class.').');
        }

        return $next($request);
    }
}
```

The log line is English on purpose (an operator log, like the package's own lines), so it gets no lang key.

`app/Support/Backups/BackupEncryption.php`: replace the class body (keep the file header and the class docblock):

```php
final readonly class BackupEncryption
{
    /** BACKUP_ARCHIVE_PASSWORD (backup.backup.password) is blank. */
    public const string NO_PASSWORD = 'password';

    /** backup.backup.encryption is 'none' or a value the package does not know. */
    public const string ENCRYPTION_OFF = 'encryption';

    /** 'default' or 'aes256' on a PHP zip without AES-256. */
    public const string NO_AES256 = 'aes256';

    /**
     * @param  bool|null  $zipHasAes256  whether this PHP's zip writes AES-256; null asks ZipArchive. Tests pass false to
     *                                   stand in for a PHP without it (L-23). It only narrows the answer: the package's
     *                                   own check must pass as well.
     */
    public static function available(?bool $zipHasAes256 = null): bool
    {
        return self::problem($zipHasAes256) === null;
    }

    /**
     * Why backup:run would write a plaintext archive, or null when it encrypts (G6-05): the first of NO_PASSWORD,
     * ENCRYPTION_OFF and NO_AES256 that applies, so LastBackupCheck can name the one cause and its fix.
     *
     * @return self::NO_PASSWORD|self::ENCRYPTION_OFF|self::NO_AES256|null
     */
    public static function problem(?bool $zipHasAes256 = null): ?string
    {
        $password = config('backup.backup.password');

        if (! is_string($password) || $password === '') {
            return self::NO_PASSWORD;
        }

        $setting = config('backup.backup.encryption', 'default');

        $encryption = match (true) {
            $setting instanceof Encryption => $setting,
            is_string($setting) => Encryption::tryFrom($setting),
            default => null,
        };

        if (! $encryption instanceof Encryption || $encryption === Encryption::None) {
            return self::ENCRYPTION_OFF;
        }

        // 'default' and 'aes256' need AES-256; without it the package writes a plaintext zip.
        $needsAes256 = in_array($encryption, [Encryption::Default, Encryption::Aes256], true);

        if (! $encryption->shouldEncrypt() || ($needsAes256 && ! ($zipHasAes256 ?? defined(ZipArchive::class.'::EM_AES_256')))) {
            return self::NO_AES256;
        }

        return null;
    }
}
```

`app/Setup/Doctor/Checks/LastBackupCheck.php`: in the class docblock replace the last sentence's end, "…a zip without AES-256 (BackupEncryption)." with:

```php
 * silently skips encryption for a blank password, encryption 'none' or a zip without AES-256 (BackupEncryption); the
 * message names which of the three applies (G6-05).
```

Add a constructor as the first member of the class:

```php
    /** @param  bool|null  $zipHasAes256  passed to BackupEncryption::problem(); tests pass false for a zip without AES-256 */
    public function __construct(private ?bool $zipHasAes256 = null) {}
```

In `run()`, replace the `if (! BackupEncryption::available()) { … }` block with:

```php
        // G6-05: name the one cause, with its own fix.
        $unencrypted = match (BackupEncryption::problem($this->zipHasAes256)) {
            BackupEncryption::NO_PASSWORD => CheckResult::fail(__('Backups are not encrypted: BACKUP_ARCHIVE_PASSWORD is empty'), 'php artisan skv:doctor --fix'),
            BackupEncryption::ENCRYPTION_OFF => CheckResult::fail(__('Backups are not encrypted: backup.backup.encryption turns encryption off'), 'config/backup.php'),
            BackupEncryption::NO_AES256 => CheckResult::fail(__('Backups are not encrypted: this PHP zip has no AES-256'), __('Use a PHP whose zip extension supports AES-256')),
            null => null,
        };

        if ($unencrypted instanceof CheckResult) {
            return $unencrypted;
        }
```

`config/filesystems.php`: replace the `backups` disk's comment and opening lines:

```php
        // §4.9, CD-6: the backups module's archives; point it at S3 or another remote disk in production. Private
        // (G6-10): archives 0600 and folders 0700 on the local disk, never the umask's 0644.
        'backups' => [
            'driver' => 'local',
            'root' => storage_path('app/backups'),
            'visibility' => 'private',
            'serve' => false,
```

(spatie writes through `getDriver()->writeStream()` with `backup_options`, which stay unset; the disk's `visibility` is Flysystem's default for that write. An existing install's archives keep their mode until the cleanup removes them.)

- [ ] **Step 4: `PGSSLMODE` for the run's own sources (L7b-68)**

`app/Support/Backups/ConfigurePgDump.php`: add `use App\Console\Commands\BackupRunCommand;` before `use App\Setup\Doctor\DoctorContext;` and `use Illuminate\Support\Facades\Artisan;` after `use Illuminate\Console\Events\CommandStarting;`. Replace the class docblock's last paragraph:

```php
 * passSslMode() passes the source connection's sslmode as PGSSLMODE (L-05), because pg_dump reads its TLS mode from
 * the environment, not from the Laravel connection. BackupRunCommand calls it once --config and --db-name are known
 * (L7b-68): this listener runs before handle() and would read the default configuration's sources. Should the
 * package's command hold the name again (as RefuseUnencryptedBackups and PrivateBackupTemp guard against), this
 * listener exports the default sources' mode itself, as before, so pg_dump never falls back to libpq's `prefer`.
 */
```

In `__invoke()`, replace the line `$this->passSslMode();` with:

```php
        // L7b-68: BackupRunCommand exports PGSSLMODE once --config and --db-name are known; should the package's
        // command hold the name again, this backstop exports the default sources' mode, as before (L-05).
        if (! Artisan::findCommand('backup:run') instanceof BackupRunCommand) {
            $this->passSslMode();
        }
``` Replace the docblock, signature and loop head of `passSslMode()`:

```php
    /**
     * L-05: the first PostgreSQL backup source's sslmode, as the app's own connection uses it: a url's query overrides
     * the sslmode key, so the connection is read through ConfigurationUrlParser, as DatabaseManager and the package's
     * DbDumperFactory read it. The package's Symfony Process inherits the getenv() keys that $_SERVER also holds, plus
     * every $_ENV key, so all three are set. The sources are the running configuration's (`--config` has swapped
     * config('backup') by then), narrowed to $only as `--db-name` narrows the dump (L7b-68).
     *
     * @param  list<string>  $only  the connections backup:run --db-name names; empty for every source
     */
    public function passSslMode(array $only = []): void
    {
        $sources = config('backup.backup.source.databases', []);

        foreach (is_array($sources) ? $sources : [] as $connection) {
            if ($only !== [] && ! in_array($connection, $only, true)) {
                continue;
            }

            $settings = is_string($connection) ? config('database.connections.'.$connection) : null;
```

The rest of the method (the `ConfigurationUrlParser`, the `pgsql` driver check, the three writes and the `return`) is unchanged.

`app/Console/Commands/BackupRunCommand.php`: add `use App\Support\Backups\ConfigurePgDump;` after `use App\Support\Backups\BackupEncryption;`. Append to the class docblock, before ` */`:

```php
 * It also exports PGSSLMODE for the sources this run dumps (L7b-68), which only handle() knows: --config swaps the
 * configuration here, and --db-name narrows the sources.
```

In `handle()`, right after the `if ($this->option('config')) { … }` block and before the encryption refusal, add:

```php
        // L7b-68: pg_dump's TLS mode comes from the sources this run dumps, known only now (--config, --db-name).
        app(ConfigurePgDump::class)->passSslMode(array_values(array_filter((array) $this->option('db-name'), is_string(...))));
```

(Before the refusals, as the listener's export was: a refused run ends the process anyway, and the test can stop the run before `pg_dump`.)

- [ ] **Step 5: "This account" (CD-9, L7b-51, L7b-69)**

`app/Support/ProcessAccount.php` (new):

```php
<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

/**
 * CD-9: the account this PHP process runs as, for the "this account" rules of PrivateBackupTemp and PostgresBinaries.
 * Only ext-posix knows it: getmyuid() is the owner of the entry script, not the process. Without ext-posix uid() is
 * null and those rules count no directory as this account's (fail closed); the kit requires no ext-posix.
 */
final readonly class ProcessAccount
{
    /** @param  (Closure(): ?int)|null  $probe  returns the effective uid, or null when it is unknown; tests pass one */
    public function __construct(private ?Closure $probe = null) {}

    /** The effective uid of this process, or null without ext-posix. */
    public function uid(): ?int
    {
        if ($this->probe instanceof Closure) {
            return ($this->probe)();
        }

        return function_exists('posix_geteuid') ? posix_geteuid() : null;
    }
}
```

`app/Support/Backups/PrivateBackupTemp.php`: add `use App\Support\ProcessAccount;` after `use App\Console\Commands\BackupRunCommand;`. Append to the class docblock, before ` */`:

```php
 *
 * "This account" is the process's effective uid (ProcessAccount, CD-9). Without ext-posix it is unknown, so no
 * directory is this account's and backup:run refuses. Root owns every system directory, so under euid 0 only a
 * directory inside storage_path() counts as ours (L7b-69): a mistyped /var is never narrowed or emptied.
```

Replace `MESSAGE`:

```php
    public const string MESSAGE = 'backup:run refuses to start: its temporary directory (backup.backup.temporary_directory) is a symbolic link, '
        .'a shared or the system temporary directory, or another account\'s, so the database dump would not be private. '
        .'Choose a directory of this account; as root, one inside storage/. Without the PHP posix extension no directory counts as this account\'s.';
```

Replace `ownDirectoryMode()` and add `insideStorage()` after it, as the class's last method:

```php
    /**
     * The permission bits of a real directory (not a symbolic link) this account owns, read with lstat(); null
     * otherwise.
     */
    private function ownDirectoryMode(string $directory): ?int
    {
        clearstatcache(true, $directory);

        if (! file_exists($directory) && ! is_link($directory)) {
            return null;
        }

        $stat = lstat($directory);
        $user = app(ProcessAccount::class)->uid();

        // CD-9: an unknown account owns nothing.
        if ($user === null || $stat === false || ($stat['mode'] & 0170000) !== 0040000 || $stat['uid'] !== $user) {
            return null;
        }

        // L7b-69: root owns /usr, /var and the rest, so as root only a directory inside storage_path() is ours.
        if ($user === 0 && ! $this->insideStorage($directory)) {
            return null;
        }

        return $stat['mode'] & 07777;
    }

    /** Whether the directory, resolved, lies below storage_path(), resolved (a symlinked storage/ counts as itself). */
    private function insideStorage(string $directory): bool
    {
        $real = realpath($directory);
        $storage = realpath(storage_path());

        return $real !== false && $storage !== false && str_starts_with($real, $storage.'/');
    }
```

`app/Setup/Doctor/PostgresBinaries.php`: add `use App\Support\ProcessAccount;` after the namespace (with a blank line before the docblock). Append to the class docblock, before ` */`:

```php
 * Without ext-posix this user is unknown (ProcessAccount, CD-9), so only root-owned Herd candidates count.
```

Replace the `$userId` line of the constructor docblock:

```php
     * @param  int|null  $userId  the account a Herd candidate may belong to besides root; null is the running process's
     *                            (ProcessAccount, none without ext-posix); tests pass another
```

In `isTrusted()`, replace `$owners = [0, $this->userId ?? $this->processUserId()];` with:

```php
        $user = $this->userId ?? app(ProcessAccount::class)->uid();
        $owners = $user === null ? [0] : [0, $user];
```

Delete `processUserId()` (the `getmyuid()` fallback).

`app/Setup/Doctor/Checks/PgDumpCheck.php`: add `use App\Support\ProcessAccount;` before `use App\Support\Skv;`. Replace the class docblock:

```php
/**
 * §5.4, RL-11: the backups module (M7) needs pg_dump; skipped while the module is off. Fails without ext-posix (CD-9):
 * backup:run then cannot tell this account's directories and refuses to start, whichever pg_dump it would find.
 */
```

In `run()`, right after the module check:

```php
        if (app(ProcessAccount::class)->uid() === null) {
            return CheckResult::fail(__('PHP has no posix extension, so backup:run cannot tell this account\'s directories and refuses to start'), __('Install the posix extension'));
        }
```

- [ ] **Step 6: Copy**

Remove these two keys from both files:
- `Backups are not encrypted: BACKUP_ARCHIVE_PASSWORD is empty or this PHP zip has no AES-256`
- `backup:run refuses to start: its temporary directory (backup.backup.temporary_directory) is a symbolic link, a shared or the system temporary directory, or another account's, so the database dump would not be private. Choose a directory of this account.`

Add these, in both files, at their sorted positions (PHP `sort()` order, byte-wise). The neighbours are those on `main` plus this task's own keys; a key an earlier task added may now sit in between, so keep the sort order rather than the neighbour:

| Key | nl | Position |
|---|---|---|
| `Backups are not encrypted: BACKUP_ARCHIVE_PASSWORD is empty` | `Back-ups worden niet versleuteld: BACKUP_ARCHIVE_PASSWORD is leeg` | right after `Backups` |
| `Backups are not encrypted: backup.backup.encryption turns encryption off` | `Back-ups worden niet versleuteld: backup.backup.encryption zet de versleuteling uit` | right after the previous key |
| `Backups are not encrypted: this PHP zip has no AES-256` | `Back-ups worden niet versleuteld: deze PHP-zip heeft geen AES-256` | right after the previous key |
| `Install the posix extension` | `Installeer de posix-extensie` | right after `Install the gd extension` |
| `PHP has no posix extension, so backup:run cannot tell this account's directories and refuses to start` | `PHP heeft geen posix-extensie, dus backup:run kan de mappen van dit account niet herkennen en weigert te starten` | right after `PHP :version is too old; the kit needs 8.4 or newer` |
| `Use a PHP whose zip extension supports AES-256` | `Gebruik een PHP waarvan de zip-extensie AES-256 ondersteunt` | right after `Use HTTPS` |
| `PrivateBackupTemp::MESSAGE` (the new text of Step 5) | `backup:run start niet: de tijdelijke map (backup.backup.temporary_directory) is een symbolische link, een gedeelde map, de tijdelijke map van het systeem of een map van een ander account, dus de databasedump zou niet privé zijn. Kies een map van dit account; als root een map binnen storage/. Zonder de PHP-extensie posix telt geen enkele map als map van dit account.` | right after `as :name` (where the old text was) |

In `en.json` each value equals its key. The `/health-check` log line has no key.

CHANGELOG.md, under `## [Unreleased]`:
- `### Changed`:
  - `` - `/health-check` keeps its per-key counter under an HMAC of the key with `APP_KEY` instead of a plain SHA-256, and while the ops store is broken it logs one warning line instead of an exception with a stack trace on every request. ``
  - `` - The last-backup health check names why backups would not be encrypted (no `BACKUP_ARCHIVE_PASSWORD`, encryption switched off in `config/backup.php`, or a PHP zip without AES-256), each with its own fix. ``
  - `` - Without the PHP posix extension the backups trust no directory as this account's: `backup:run` refuses to start, Herd's `pg_dump` counts only when root owns it, and the doctor's `pg-dump` row says why. Running as root, `backup:run` accepts only a temporary directory inside `storage/`. ``
- `### Fixed`:
  - `` - Backup archives on the local `backups` disk are readable by this account only (0600, folders 0700). ``
  - `` - `backup:run --config=…` and `--db-name=…` hand `pg_dump` the `sslmode` of the sources that run dumps, not the default configuration's. ``

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Health tests/Feature/Backups tests/Feature/Setup/Doctor tests/Feature/System tests/Arch tests/Feature/LangFilesTest.php tests/Feature/LangCoverageTest.php`

Expected: PASS (on SQLite, `BackupRunTest` and the root-skipped tests skip).

- [ ] **Step 8: Verify by mutation**

Copy the production file aside (never `git stash`: it is shared by every worktree), make the change, confirm the named test fails, copy the file back and confirm it passes. Name each mutation and its result in the task report.
1. `ThrottleHealthChecks`: `hash_hmac('sha256', $key, (string) config('app.key'))` back to `hash('sha256', $key)`. Expect 'counts the key under an HMAC with APP_KEY…' and OpsCacheTest's ops-store test to fail.
2. `ThrottleHealthChecks`: `Log::warning(…)` back to `report($exception)`. Expect 'logs one line without a trace…' to fail on `assertNothingReported()`.
3. `BackupEncryption::problem()`: return `self::NO_PASSWORD` for a disabled encryption. Expect the 'encryption none' and 'unknown encryption' rows and the HealthChecksTest encryption test to fail.
4. `config/filesystems.php`: drop `'visibility' => 'private'`. Expect 'writes archives on the backups disk readable by this account only' to fail with 420.
5. `BackupRunCommand::handle()`: move the `passSslMode()` line above the `--config` block. Expect the '--config' row to fail with `prefer`.
6. `ConfigurePgDump::passSslMode()`: drop the `$only` check. Expect '--config and --db-name' to fail with `require`, and 'takes the sslmode of the connection --db-name names' with `prefer`.
7. `ConfigurePgDump::__invoke()`: delete the backstop `if` and its call. Expect 'still exports the default sources' sslmode should the package's command hold backup:run again' to fail with `false`. Then make the call unconditional again: expect the `backup:run` row of 'leaves PGSSLMODE to backup:run…' to fail with `verify-full`.
8. `PrivateBackupTemp::ownDirectoryMode()`: delete the `$user === 0` block. Expect 'as root, counts only a directory inside storage/…' to fail with "chmod(): Operation not permitted" (the rule would chmod `/usr`).
9. `PrivateBackupTemp` and `PostgresBinaries`: `app(ProcessAccount::class)->uid()` → `app(ProcessAccount::class)->uid() ?? getmyuid()`. Expect both ext-posix tests to fail with "true is false".

- [ ] **Step 9: The gate, then commit**

Run the Global Constraints gate:
- `composer check`
- `composer test:pgsql` (`BackupRunTest` runs a real `pg_dump` here and now exports the test connection's `sslmode`; the snapshot restores it)
- the modules-off run
- `npm run build && composer test:browser`
- `npm run build:setup && git diff --exit-code resources/setup/setup.css` (Task 12 changes no setup view, so expect no diff)
- `php artisan config:clear`

```bash
git add app/Support/ProcessAccount.php app/Http/Middleware/ThrottleHealthChecks.php \
    app/Support/Backups/BackupEncryption.php app/Support/Backups/ConfigurePgDump.php app/Support/Backups/PrivateBackupTemp.php \
    app/Setup/Doctor/Checks/LastBackupCheck.php app/Setup/Doctor/Checks/PgDumpCheck.php app/Setup/Doctor/PostgresBinaries.php \
    app/Console/Commands/BackupRunCommand.php config/filesystems.php lang/nl.json lang/en.json CHANGELOG.md \
    tests/Fixtures/PgSslModeSnapshot.php \
    tests/Feature/Health/HealthCheckEndpointTest.php tests/Feature/Health/OpsCacheTest.php tests/Feature/Health/HealthChecksTest.php \
    tests/Feature/Backups/BackupEncryptionTest.php tests/Feature/Backups/BackupConfigTest.php tests/Feature/Backups/ConfigurePgDumpTest.php \
    tests/Feature/Backups/BackupRunCommandTest.php tests/Feature/Backups/BackupRunTest.php tests/Feature/Backups/PrivateBackupTempTest.php \
    tests/Feature/Setup/Doctor/PostgresBinariesTest.php tests/Feature/Setup/Doctor/ChecksTest.php
git commit -m "fix: harden the health-check throttle, the backup archives and the backups' account checks

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 13: CLI and super-admin guards (L7b-38, L7b-39, L7b-45, L7b-72, L7b-81, L7b-73's tests; CD-10)

**Files:**
- Modify:
  - `app/Console/Commands/CreateSuperAdminCommand.php` (one `passwordRules()` that also refuses a hash, L7b-38)
  - `app/Providers/AppServiceProvider.php` (`Password::defaults()` strict outside `local` and `testing`, CD-10)
  - `app/Console/Commands/FluxProCommand.php` (undo the mark and the repository after a failed or interrupted install, name the manual command when an undo fails, trap SIGINT/SIGTERM, and undo too when a step throws `ProcessSignaledException` or `ProcessTimedOutException`; L7b-72, L7b-81)
  - `lang/nl.json`, `lang/en.json`
  - `CHANGELOG.md`
- Test:
  - `tests/Feature/Console/CreateSuperAdminCommandTest.php`
  - `tests/Feature/Console/ProductionGuardsTest.php` (the staging install now needs a strict password)
  - `tests/Arch/AuthorizationRulesTest.php` (the `SetSuperAdmin` caller rule, L7b-39)
  - `tests/Feature/Console/FluxProCommandTest.php` (the failing-step dataset, L7b-73; the undo warning; the signal; a step that throws)

**Interfaces:**
- Consumes (main `a192dba`; Task 3 may add a listener registration elsewhere in `AppServiceProvider`, Task 13 touches only `configureDefaults()`):
  - `Hash::isHashed(string): bool`
  - `Illuminate\Process\PendingProcess::run()`, which rethrows Symfony's `ProcessSignaledException` and turns a timeout into `ProcessTimedOutException` (it catches only the timeout)
  - `Illuminate\Console\Command::trap(Closure|iterable|int $signals, callable $callback)` and `Illuminate\Console\Signals::resolveAvailabilityUsing(callable)` (signals are off under unit tests: `ArtisanServiceProvider` resolves them to `! runningUnitTests()`)
  - `kitSourceFiles()`, `filesMatching()` (`tests/Pest.php`)
- Produces:
  - `Password::defaults()`: the strict rule (min 12, mixed case, letters, numbers, symbols, uncompromised) in every environment except `local` and `testing`; `null` (Laravel's min 8) there.
  - `skv:create-super-admin` refuses a value `Hash::isHashed()` accepts, with "Pass the password itself, not a hash."
  - `skv:flux-pro` (no signature change): on a failed step it runs `composer config --unset <key>` for each key it added, newest first (`repositories.flux-pro`, then `extra.skv`); a failed unset prints "Could not remove :key from composer.json. Run: composer config --unset :key"; after SIGINT/SIGTERM it starts no further step, undoes and prints "Flux Pro was not installed: the command was interrupted."; a step that throws `Symfony\Component\Process\Exception\ProcessSignaledException` (Composer re-raised Ctrl-C) or `Illuminate\Process\Exceptions\ProcessTimedOutException` (900 s) is undone the same way, with the interrupted message or "Composer could not install Flux Pro.", and an unset that throws counts as a failed one

Notes for the implementer:
- **Never use `git stash` to try a mutation.** The stash is shared by every worktree of the repository; copy the file aside (`cp`) and back instead.
- Staging's strict rule ends in `uncompromised()`, an HTTP request to `api.pwnedpasswords.com`, which runs only once the other rules pass. A test that runs a staging install past validation fakes it (`Http::preventStrayRequests()` + `Http::fake(['api.pwnedpasswords.com/*' => Http::response('')])`).
- The signal test sends a real SIGTERM to the test process. It installs its own no-op handler first, so a regressed command (no trap) fails the test instead of killing the Pest worker.

- [ ] **Step 1: Write the failing tests**

**A hash for a password (L7b-38) and staging's rule (L7b-45).** `tests/Feature/Console/CreateSuperAdminCommandTest.php`: add `use Illuminate\Support\Facades\Http;` after `use Illuminate\Support\Facades\Hash;`. Add these two tests right before `it('refuses a user who is not active (RL-9, g2-18)', …`:

```php
it('refuses a hash in place of the password, for a new and an existing user (L7b-38)', function () use ($withStdin): void {
    // The `hashed` cast keeps a value that already is a hash: piping bcrypt('a') would make "a" the password.
    $member = User::factory()->for(Organisation::factory()->create(['slug' => 'acme']))->create(['email' => 'member@example.com', 'password' => 'old-password-123']);
    $hash = Hash::make('a');

    [$newExit, $newOutput] = $withStdin('skv:create-super-admin', ['email' => 'owner@example.com', '--password-stdin' => true, '--no-interaction' => true], $hash."\n");
    [$existingExit, $existingOutput] = $withStdin('skv:create-super-admin', ['email' => 'member@example.com', '--password-stdin' => true, '--no-interaction' => true], $hash."\n");

    expect([$newExit, $existingExit])->toBe([1, 1])
        ->and($newOutput)->toContain(__('Pass the password itself, not a hash.'))->not->toContain($hash)
        ->and($existingOutput)->toContain(__('Pass the password itself, not a hash.'))
        ->and(Tenant::bypass(fn (): bool => User::query()->where('email', 'owner@example.com')->exists()))->toBeFalse()
        ->and($member->fresh()?->isSuperAdmin())->toBeFalse()
        ->and(Hash::check('old-password-123', (string) $member->fresh()?->password))->toBeTrue();
});

it('applies the strict password rule on staging, where skv:install creates a super admin (CD-10, L7b-45)', function () use ($withStdin): void {
    app()->detectEnvironment(fn (): string => 'staging');
    // The strict rule asks Have I Been Pwned once the other rules pass; this run never gets that far.
    Http::preventStrayRequests();

    [$exit, $output] = $withStdin('skv:create-super-admin', ['email' => 'owner@example.com', '--password-stdin' => true, '--no-interaction' => true], "password1\n");

    expect($exit)->toBe(1)
        ->and($output)->toContain(__('validation.min.string', ['attribute' => 'password', 'min' => 12]))
        ->and(Tenant::bypass(fn (): bool => User::query()->where('email', 'owner@example.com')->exists()))->toBeFalse();
});
```

The existing 'keeps an existing user's password when the new one is too weak (RL-9, L-51)' stays as it is: the suite runs in `testing`, where the rule is still min 8.

`tests/Feature/Console/ProductionGuardsTest.php`: add `use Illuminate\Support\Facades\Http;` after `use Illuminate\Support\Facades\DB;`. In 'lets a staging install with its own super admin past the guards (CD-3, CD-9, L-58)', fake the breach lookup after `app()['env'] = 'staging';` and give the answers a password the strict rule accepts:

```php
    app()->instance(BootstrapRunner::class, new FailingBootstrap);
    app()['env'] = 'staging';
    // CD-10: staging has the strict password rule; its uncompromised() lookup gets an empty range, never the network.
    Http::preventStrayRequests();
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);
    $answers = $this->sandbox->path('answers.json');
    file_put_contents($answers, json_encode([
        'database' => 'sqlite', 'start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com',
        'super_admin_password' => 'Correct-Horse-Battery-42', 'modules' => [], 'https' => false, 'claude' => false,
    ], JSON_THROW_ON_ERROR));
```

(After Step 4 this test fails without the change: `Answers` validates `super_admin_password` with `Password::default()`, and 'correct-horse-battery' has no capital and no digit.)

**Who calls `SetSuperAdmin` (L7b-39).** `tests/Arch/AuthorizationRulesTest.php`: add these imports, in order, around `use App\Authorization\Permission;`:

```php
use App\Actions\Users\UpdateSuperAdmin;
use App\Authorization\Permission;
use App\Authorization\SetSuperAdmin;
use App\Console\Commands\CreateSuperAdminCommand;
use App\Setup\Steps\DatabaseStep;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Str;
```

Append:

```php
// L7b-39: the super-admin flag changes only through SetSuperAdmin, and SetSuperAdmin is called only by the action pages
// use (UpdateSuperAdmin, which authorises first), setup's database step, skv:create-super-admin and the demo seeder.
arch('only the super-admin action, setup, the CLI and the demo seeder call SetSuperAdmin (L7b-39)')
    ->expect(SetSuperAdmin::class)
    ->toOnlyBeUsedIn([UpdateSuperAdmin::class, DatabaseStep::class, CreateSuperAdminCommand::class, DemoSeeder::class]);

it('never calls SetSuperAdmin from a page, a component or a route file (L7b-39)', function (): void {
    // The arch rule sees autoloaded classes only; Livewire's multi-file classes and route closures are scanned as text.
    expect(filesMatching(kitSourceFiles('resources/views', 'routes'), '/\bSetSuperAdmin\b/'))->toBe([]);
});
```

Both pass on `main` (they pin today's callers; `tests/Pest.php`'s helpers are not autoloaded classes, and `UserFactory`'s docblock mention is no use). Step 6 proves they fail on a new caller.

**`skv:flux-pro`'s cleanup (L7b-72, L7b-81, L7b-73).** `tests/Feature/Console/FluxProCommandTest.php`: add `use Illuminate\Console\Signals;` and `use Illuminate\Process\Exceptions\ProcessTimedOutException;` before `use Illuminate\Process\FakeProcessResult;`, and `use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeout;` and `use Symfony\Component\Process\Process as SymfonyProcess;` after `use Illuminate\Support\Str;` (as `HerdSiteStepTest` builds a timeout). In 'fails with the last line Composer printed when the require fails (RL-12)', the command now also removes the repository, so add its fake after the `--unset extra.skv` one:

```php
        "*composer' 'config' '--unset' 'extra.skv'*" => Process::result(''),
        "*composer' 'config' '--unset' 'repositories.flux-pro'*" => Process::result(''),
```

Append:

```php
/** Fakes every Composer call of skv:flux-pro; $results overrides a step by its name. */
$composerSteps = function (array $results = []): void {
    Process::fake([
        "*composer' 'config' 'http-basic.composer.fluxui.dev'*" => Process::result('{"username":"me@example.com","password":"secret-key"}'),
        "*composer' 'config' 'extra.skv.flux-pro' 'true' '--json'*" => $results['mark'] ?? Process::result(''),
        "*composer' 'config' 'repositories.flux-pro' 'composer' 'https://composer.fluxui.dev'*" => $results['repository'] ?? Process::result(''),
        "*composer' 'require' 'livewire/flux-pro'*" => $results['require'] ?? Process::result('Installed'),
        "*composer' 'config' '--unset' 'extra.skv'*" => $results['unset mark'] ?? Process::result(''),
        "*composer' 'config' '--unset' 'repositories.flux-pro'*" => $results['unset repository'] ?? Process::result(''),
    ]);
};

$ran = fn (string $command): Closure => fn ($process): bool => str_contains(implode(' ', (array) $process->command), $command);

it('undoes what a failed step added to composer.json and starts no later step (CD-13, L7b-72, L7b-73)', function (string $failing, array $undone, array $notRun) use ($composerSteps, $ran): void {
    $composerSteps([$failing => Process::result(errorOutput: "Composer refused.\n", exitCode: 1)]);

    expect(Artisan::call('skv:flux-pro', ['--no-interaction' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('Composer refused.');

    foreach ($undone as $command) {
        Process::assertRan($ran($command));
    }

    foreach ($notRun as $command) {
        Process::assertDidntRun($ran($command));
    }

    expect(is_file($this->probe))->toBeTrue();
})->with([
    'the mark' => ['mark', ['config --unset extra.skv'], ['config repositories.flux-pro', 'config --unset repositories.flux-pro', 'require livewire/flux-pro']],
    'the repository' => ['repository', ['config --unset repositories.flux-pro', 'config --unset extra.skv'], ['require livewire/flux-pro']],
    'the require' => ['require', ['config --unset repositories.flux-pro', 'config --unset extra.skv'], []],
]);

it('names the manual command for a key Composer would not remove (CD-13, L7b-72)', function () use ($composerSteps): void {
    $composerSteps([
        'require' => Process::result(errorOutput: "Your requirements could not be resolved.\n", exitCode: 2),
        'unset mark' => Process::result(errorOutput: "composer.json is not writable.\n", exitCode: 1),
    ]);

    expect(Artisan::call('skv:flux-pro', ['--no-interaction' => true]))->toBe(1);
    $output = (string) preg_replace('/\s+/', ' ', Artisan::output());

    expect($output)->toContain(__('Could not remove :key from composer.json. Run: composer config --unset :key', ['key' => 'extra.skv']))
        ->not->toContain(__('Could not remove :key from composer.json. Run: composer config --unset :key', ['key' => 'repositories.flux-pro']))
        ->toContain('Your requirements could not be resolved.');
});

it('undoes the mark and the repository and skips the require after Ctrl-C or SIGTERM (CD-13, L7b-81)', function () use ($composerSteps, $ran): void {
    // Signals are off under unit tests (ArtisanServiceProvider): turn them on, then signal this process while the
    // repository step "runs", as a terminal's Ctrl-C reaches the command while Composer works.
    // A handler of the test's own catches the signal should the command trap none, so a regression fails this test
    // instead of terminating the test process; the command's handler runs before it (Signals::register()).
    Signals::resolveAvailabilityUsing(fn (): bool => true);
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function (): void {});
    $composerSteps(['repository' => function (): FakeProcessResult {
        posix_kill(posix_getpid(), SIGTERM);

        return Process::result('');
    }]);

    try {
        $exit = Artisan::call('skv:flux-pro', ['--no-interaction' => true]);
    } finally {
        pcntl_signal(SIGTERM, SIG_DFL);
        Signals::resolveAvailabilityUsing(fn (): bool => false);
    }

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain(__('Flux Pro was not installed: the command was interrupted.'));

    Process::assertRan($ran('config --unset repositories.flux-pro'));
    Process::assertRan($ran('config --unset extra.skv'));
    Process::assertDidntRun($ran('require livewire/flux-pro'));
})->skip(fn (): bool => ! extension_loaded('pcntl') || ! extension_loaded('posix'), 'needs pcntl and posix to trap and send a signal');

it('undoes the mark and the repository when a step throws instead of failing, as on a timeout or Ctrl-C (L7b-72, L7b-81)', function () use ($composerSteps, $ran): void {
    // A real Ctrl-C makes Composer re-raise the signal, so Symfony throws ProcessSignaledException instead of
    // returning a failed result; a timeout throws too. Only the timeout can be built in a fake.
    $composerSteps(['require' => fn () => throw new ProcessTimedOutException(new SymfonyTimeout(new SymfonyProcess(['composer']), SymfonyTimeout::TYPE_GENERAL), Process::result(''))]);

    expect(Artisan::call('skv:flux-pro', ['--no-interaction' => true]))->toBe(1)
        ->and(Artisan::output())->toContain(__('Composer could not install Flux Pro.'));

    Process::assertRan($ran('config --unset repositories.flux-pro'));
    Process::assertRan($ran('config --unset extra.skv'));
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Console/CreateSuperAdminCommandTest.php tests/Feature/Console/ProductionGuardsTest.php tests/Feature/Console/FluxProCommandTest.php tests/Arch/AuthorizationRulesTest.php`

Expected: FAIL, as follows:
- 'refuses a hash in place of the password…': `[0, 0]` instead of `[1, 1]` (the hash is stored as the password hash).
- 'applies the strict password rule on staging…': exit 0 (min 8 accepts 'password1').
- FluxProCommandTest: the 'the repository' and 'the require' rows fail with "An expected process was not invoked" (no `--unset repositories.flux-pro`); 'names the manual command…' does not find the warning; the signal test gets exit 0 (no trap: the test's own handler swallows the signal and the require runs); the timeout test ends with the uncaught `ProcessTimedOutException`.
- The 'the mark' row, ProductionGuardsTest and the two arch tests pass already (they pin behaviour that must survive the change).

- [ ] **Step 3: Refuse a hash (L7b-38)**

`app/Console/Commands/CreateSuperAdminCommand.php`: add `use Closure;` after `use App\Tenancy\Facades\Tenant;` and `use Illuminate\Support\Facades\Hash;` after `use Illuminate\Support\Facades\DB;`. In `promote()`, replace the password rule:

```php
            'password' => ['sometimes', ...$this->passwordRules()],
```

In `newUserAttributes()`, replace the password rule:

```php
            'password' => $this->passwordRules(),
```

Add this method right before `givenPassword()`:

```php
    /**
     * The app's password rule, plus no hash (L7b-38): the `hashed` cast stores a value that already is a hash as it is,
     * so a piped bcrypt hash would become the account's hash and its plaintext the super admin's password.
     *
     * @return list<mixed>
     */
    private function passwordRules(): array
    {
        return ['required', 'string', Password::default(), function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && Hash::isHashed($value)) {
                $fail(__('Pass the password itself, not a hash.'));
            }
        }];
    }
```

Setup is not affected: `Answers` always `Hash::make()`s the password it is given.

- [ ] **Step 4: The strict rule outside local and testing (CD-10, L7b-45)**

`app/Providers/AppServiceProvider.php`, in `configureDefaults()`, replace the first line of the `Password::defaults()` call and put a comment above it; the rule itself is unchanged:

```php
        // CD-10: the strict rule everywhere but local development and the test suite, so a staging server (where
        // skv:install creates a super admin, M7b CD-3) gets the production rule.
        Password::defaults(fn (): ?Password => ! app()->environment(['local', 'testing'])
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
```

- [ ] **Step 5: `skv:flux-pro` undoes what it added (L7b-72, L7b-81)**

`app/Console/Commands/FluxProCommand.php`: add `use Illuminate\Process\Exceptions\ProcessTimedOutException;` before `use Illuminate\Process\PendingProcess;` and `use Symfony\Component\Process\Exception\ProcessSignaledException;` after `use Illuminate\Support\Str;`. Replace the class docblock, add the `$interrupted` property and replace `handle()`; add `undo()` before `lastLine()`. The whole class after the change:

```php
/**
 * Installs the optional Flux Pro layer (§6.7, RL-12): adds the composer.fluxui.dev repository and requires
 * livewire/flux-pro with the credentials Composer already has, then clears compiled views, because Pro branches are
 * stripped at compile time while Pro is missing (DEC-29). It marks composer.json with extra.skv.flux-pro first, so an
 * app that commits Pro on purpose skips the kit's committed-files test (CD-13); Composer's lock hash covers `extra`,
 * so the mark goes in before the require. It never writes credentials and never prints them.
 *
 * An install that does not finish undoes what it added to composer.json, the mark and the repository (L7b-72), and
 * names the manual command for a key Composer would not remove. Ctrl-C or SIGTERM is trapped while pcntl is loaded
 * (L7b-81): Composer, in the same process group, reverts its own change and re-raises the signal, so Symfony throws
 * ProcessSignaledException instead of returning a failed result; that and a timeout are caught and the same undo
 * runs; no later step starts.
 */
#[Description('Install Flux Pro with your Composer credentials (§6.7)')]
#[Signature('skv:flux-pro')]
final class FluxProCommand extends Command
{
    private bool $interrupted = false;

    public function handle(Toolchain $toolchain): int
    {
        if (Skv::hasFluxPro()) {
            $this->callSilently('view:clear');
            $this->components->info(__('Flux Pro is already installed.'));

            return self::SUCCESS;
        }

        $composer = $toolchain->which('composer') ?? 'composer';

        // The output is the credentials themselves: it is only tested for being non-empty, never shown or logged.
        $credentials = $this->process($toolchain, 60)->run([$composer, 'config', 'http-basic.composer.fluxui.dev']);

        if ($credentials->failed() || trim($credentials->output()) === '') {
            $this->components->error(__('Add your Flux Pro credentials first: composer config --global http-basic.composer.fluxui.dev <email> <license-key>'));

            return self::FAILURE;
        }

        // Artisan keeps one instance of the command per process: a second run starts uninterrupted.
        $this->interrupted = false;
        $this->trap(fn (): array => [SIGINT, SIGTERM], function (): void {
            $this->interrupted = true;
        });

        /** @var list<string> $added the composer.json keys the steps so far have written, undone in reverse */
        $added = [];

        foreach ([
            ['extra.skv', [$composer, 'config', 'extra.skv.flux-pro', 'true', '--json']],
            ['repositories.flux-pro', [$composer, 'config', 'repositories.flux-pro', 'composer', 'https://composer.fluxui.dev']],
            [null, [$composer, 'require', 'livewire/flux-pro', '--no-interaction']],
        ] as [$key, $command]) {
            if ($this->interrupted) {
                $this->undo($toolchain, $composer, $added);
                $this->components->error(__('Flux Pro was not installed: the command was interrupted.'));

                return self::FAILURE;
            }

            if ($key !== null) {
                $added[] = $key;
            }

            try {
                $result = $this->process($toolchain, 900)->run($command);
            } catch (ProcessSignaledException|ProcessTimedOutException $exception) {
                // Ctrl-C: Composer re-raises the signal, so Symfony throws instead of returning a failed result; a
                // timeout throws too (L7b-72, L7b-81).
                $this->undo($toolchain, $composer, $added);
                $this->components->error($this->interrupted || $exception instanceof ProcessSignaledException
                    ? __('Flux Pro was not installed: the command was interrupted.')
                    : __('Composer could not install Flux Pro.'));

                return self::FAILURE;
            }

            if ($result->failed()) {
                // An install that did not finish must not mark the app as committing Flux Pro (CD-13).
                $this->undo($toolchain, $composer, $added);
                $this->components->error($this->interrupted ? __('Flux Pro was not installed: the command was interrupted.') : $this->lastLine($result));

                return self::FAILURE;
            }
        }

        $this->callSilently('view:clear');
        $this->components->info(__('Flux Pro installed. Its components are available after this step; compiled views were cleared.'));

        return self::SUCCESS;
    }

    /**
     * Removes the composer.json keys an unfinished install added, newest first (CD-13, L7b-72). A key Composer does not
     * remove is named with the command that does, so the app never keeps the mark without Flux Pro unnoticed.
     *
     * @param  list<string>  $keys
     */
    private function undo(Toolchain $toolchain, string $composer, array $keys): void
    {
        foreach (array_reverse($keys) as $key) {
            try {
                $failed = $this->process($toolchain, 60)->run([$composer, 'config', '--unset', $key])->failed();
            } catch (ProcessSignaledException|ProcessTimedOutException) {
                // A second Ctrl-C or a hung Composer: the key may still be there, so the manual command is named.
                $failed = true;
            }

            if ($failed) {
                $this->components->warn(__('Could not remove :key from composer.json. Run: composer config --unset :key', ['key' => $key]));
            }
        }
    }

    /** The last line Composer printed (it prints no credentials), or a generic message when it printed nothing. */
    private function lastLine(ProcessResult $result): string
    {
        $output = trim($result->errorOutput()) !== '' ? trim($result->errorOutput()) : trim($result->output());

        return $output !== '' ? Str::afterLast($output, "\n") : __('Composer could not install Flux Pro.');
    }

    private function process(Toolchain $toolchain, int $timeout): PendingProcess
    {
        return Process::path(base_path())->env($toolchain->environment())->timeout($timeout);
    }
}
```

Why it works: a terminal's Ctrl-C reaches the whole foreground process group. Composer's `require` handles SIGINT/SIGTERM by reverting its own `composer.json` change and re-raising the signal on itself (seld/signal-handler's `exitWithLastSignal()`), and a `composer config` child dies of the default action, so the child ends *signaled*. Symfony's `Process::wait()` then throws `ProcessSignaledException` (a signal it did not send), which Laravel's `PendingProcess::run()` does not catch; a 900-second timeout throws `ProcessTimedOutException`. The `catch` around `run()` undoes for both, so `extra.skv.flux-pro` and `repositories.flux-pro` never stay behind. The trap keeps PHP alive (the callback only sets the flag, so neither Laravel nor Symfony exits). A SIGTERM sent to PHP alone lets the running step finish, and the loop stops before the next one. An interruption during a `require` that still succeeds leaves Pro installed, mark and all, which is the consistent state. The signal list is a closure, so `SIGINT` is only read where `Signals::whenAvailable()` found pcntl.

- [ ] **Step 6: Copy**

Lang keys, in both files, at their sorted positions (PHP `sort()` order, byte-wise). The neighbours are those on `main`; a key an earlier task added may now sit in between (Task 11's `Could not read :file.` sorts right before `Could not remove …`), so keep the sort order rather than the neighbour:

| Key | nl | Position |
|---|---|---|
| `Could not remove :key from composer.json. Run: composer config --unset :key` | `Kon :key niet uit composer.json verwijderen. Voer uit: composer config --unset :key` | right after `Could not create a temporary copy of [:path].` |
| `Flux Pro was not installed: the command was interrupted.` | `Flux Pro is niet geïnstalleerd: de opdracht werd onderbroken.` | right after `Flux Pro is not installed` |
| `Pass the password itself, not a hash.` | `Geef het wachtwoord zelf mee, geen hash.` | right after `Pass the email address: php artisan skv:create-super-admin you@example.com` |

In `en.json` each value equals its key.

CHANGELOG.md, under `## [Unreleased]`:
- `### Changed`:
  - `` - Every environment except `local` and `testing` uses the strict password rule (12 characters, mixed case, letters, numbers, symbols, not in a known breach), so a staging install's super admin gets it too. ``
  - `` - `skv:flux-pro` also removes the `repositories.flux-pro` entry after an install that did not finish, prints the `composer config --unset` command for a key it could not remove, and cleans up the same way after Ctrl-C or a Composer timeout. ``
- `### Fixed`:
  - `` - `skv:create-super-admin` refuses a password hash given in place of the password, which the `hashed` cast would have stored as it was. ``

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Console tests/Arch tests/Feature/AppDefaultsTest.php tests/Feature/Seeders tests/Feature/LangFilesTest.php tests/Feature/LangCoverageTest.php`

Expected: PASS.

- [ ] **Step 8: Verify by mutation**

Copy the file aside (never `git stash`), make the change, confirm the named test fails, copy it back and confirm it passes. Name each mutation and its result in the task report.
1. `CreateSuperAdminCommand::passwordRules()`: drop the closure rule. Expect 'refuses a hash in place of the password…' to fail with `[0, 0]`.
2. `AppServiceProvider`: back to `app()->isProduction()`. Expect 'applies the strict password rule on staging…' to fail with exit 0.
3. Add `use App\Authorization\SetSuperAdmin;` and `public function promote(SetSuperAdmin $setSuperAdmin): void {}` to `app/Http/Controllers/LocaleController.php`. Expect the arch rule to fail with "Expecting 'App\Authorization\SetSuperAdmin' not to be used on 'App\Http\Controllers\LocaleController'".
4. Add `use App\Authorization\SetSuperAdmin;` to `resources/views/pages/users/edit/edit.php`. Expect 'never calls SetSuperAdmin from a page…' to fail.
5. `FluxProCommand`: replace the `$added` bookkeeping with a fixed `$this->undo($toolchain, $composer, ['extra.skv'])`. Expect the 'the repository' and 'the require' rows to fail.
6. `FluxProCommand::undo()`: drop the `warn()`. Expect 'names the manual command…' to fail.
7. `FluxProCommand::handle()`: delete the `$this->trap(…)` call. Expect the signal test to fail with exit 0, and the Pest run to go on (the test's own handler caught the signal).
8. `FluxProCommand::handle()`: remove the `try`/`catch` around `run()`. Expect 'undoes the mark and the repository when a step throws…' to fail with the uncaught `ProcessTimedOutException`.

- [ ] **Step 9: The gate, then commit**

Run the Global Constraints gate:
- `composer check`
- `composer test:pgsql`
- the modules-off run
- `npm run build && composer test:browser`
- `npm run build:setup && git diff --exit-code resources/setup/setup.css` (Task 13 changes no setup view, so expect no diff)
- `php artisan config:clear`

```bash
git add app/Console/Commands/CreateSuperAdminCommand.php app/Console/Commands/FluxProCommand.php app/Providers/AppServiceProvider.php \
    lang/nl.json lang/en.json CHANGELOG.md \
    tests/Feature/Console/CreateSuperAdminCommandTest.php tests/Feature/Console/ProductionGuardsTest.php \
    tests/Feature/Console/FluxProCommandTest.php tests/Arch/AuthorizationRulesTest.php
git commit -m "fix: refuse a hash as super-admin password, apply the strict password rule on staging and undo a failed skv:flux-pro

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 14: Documentation, guideline and DEC-34 (G5-05, G5-07, G5-12, G4-02; CD-11; every task's docs)

**Files:**
- Modify:
  - `README.md` (the statements of Step 2, in the sections they name)
  - `docs/handleiding.html` (the same in Dutch, Step 3; the branch pin and status lines, G5-12)
  - `.ai/guidelines/skv3.md` (Step 4); then regenerate the git-ignored `AGENTS.md`
  - `docs/decisions.md` (DEC-34 new; the amendments of Step 5; DEC-29's open-minors list, G5-12)
  - `CHANGELOG.md` (only to fix lines Tasks 1–13 left inconsistent; Step 6)
  - `tests/Arch/KitRulesTest.php` (the G5-07 guard, Step 1)
- Test: `tests/Arch/KitRulesTest.php`

**Interfaces:**
- Consumes: the task reports of Tasks 1–13 (`.superpowers/sdd/<plan>/task-N-report.md` and the ledger) and the code as merged on the branch. Where a statement below and the code disagree, the code wins and the report records the difference.
- Produces: no code.

- [ ] **Step 1: The Fortify domain groups are the only exceptions (G5-07)**

`tests/Arch/KitRulesTest.php`: add after the `Number` test:

```php
it('constrains routes by domain only in the Fortify groups (G5-07)', function (): void {
    // Route::tenant() / Route::central() attach host middleware instead (§6.2). routes/fortify.php and
    // routes/passkeys.php keep Fortify's own domain + prefix group, so their replacements match the vendor routes
    // (FU3-3, CD-3). An empty Route::domain() in a comment does not match.
    expect(filesMatching(kitSourceFiles('routes', 'app'), "/Route::domain\\s*\\(\\s*[^)\\s]|'domain'\\s*=>/"))->toBe(['routes/fortify.php', 'routes/passkeys.php']);
});
```

Run: `php artisan test --compact --filter='constrains routes by domain'` → PASS (it pins the state after Task 1, whose `passkey.destroy` group in `routes/passkeys.php` mirrors Fortify's).
Verify by mutation: add `Route::domain('x.test')->group(function (): void {});` to the end of `routes/web.php` (copy the file aside with `cp` first); the test fails listing `routes/web.php`; copy the file back.

- [ ] **Step 2: README**

Write each statement in the section named, in the README's existing register (short, imperative where it instructs). Never quote a secret value.

- **Authentication / limits (Task 1):** email change 3 per 10 minutes; password change and 2FA confirmation 5 per minute (every attempt counts); invite and remind 10 per minute; one reminder per invitation per 10 minutes; the 2FA management routes and passkey removal 10 per minute (`account-security`). A successful sign-in, also by 2FA or passkey, clears that address's failure counter. A throttled sign-in returns to the login page.
- **Configuration › `APP_URL` (Task 2):** `APP_URL` must be the public origin, scheme and port included: every link the kit builds (mails, handoffs, passkey origins) takes its scheme and port from it, never from the request. Behind a proxy, `TRUSTED_PROXIES` now only affects the client IP the limits and logs see.
- **Security page / passkeys (Task 3):** with `MODULE_PASSKEYS=false` the passkey actions answer 404, also when called directly; a passkey whose signature counter did not increase (a possibly cloned authenticator) is refused and writes one warning line to the log; a failed passkey sign-in appears in the audit log as a failed sign-in with `method: passkey`, naming the account only for the host's own members (and on central hosts); after an `APP_KEY` rotation without `APP_PREVIOUS_KEYS`, recovery codes show "Failed to load recovery codes": regenerate them.
- **Impersonation (Tasks 4, 14):** language and appearance chosen during an impersonation last until it ends and never change the target's account. An impersonation that ends because the impersonator is disabled, deleted, archived or purged writes no `impersonation.stop` entry: their session is gone; the start entry and the status change are the record (CD-11, G4-02).
- **Languages (Tasks 4, 5):** the user-menu language switch reloads the current page, query string and filters included; messages after a forced sign-out or an ended impersonation are in the language of the page that shows them; email-change mails and the administrator-change notice are in the account's language, also from a queue worker; `APP_LOCALE` defaults to `nl`.
- **Error pages (Task 6):** 401, 402, 403, 404, 419, 429, 500 and 503 have their own page, every other 4xx/5xx status (405, 413, 502 …) a fallback, all in the visitor's language; *Try again* reloads the page (inside Livewire's error dialog the top page), *Back* never leaves the site.
- **Administration (Task 7):** no README change unless the report lists one.
- **Audit (Task 8):** the stored user agent is cut to 500 characters of valid UTF-8; invitation mails skip an invitation that was cancelled, accepted or expired before the worker ran (a mail for an organisation archived meanwhile still fails, by design).
- **Setup (Tasks 10, 11):** the wizard and the Herd stage refuse requests that carry `X-Forwarded-*`, `X-Expose-*`, `CF-*`, `Forwarded`, `Via`, `X-Original-Host`, `X-Real-IP` or `True-Client-IP`; a tunnel that adds no header (ssh -R, socat) still passes, so the warning against tunnels before setup ends stays; accepting the setup token starts a fresh session; a super admin with a confirmed second factor lands on the admin host's login page; the manual Herd page can show `herd isolate 8.4 --site=<name>`; setup creates `public/storage`; `.env` and `.env.testing` are written 0600.
- **Production checklist (Task 11):** `.env` is 0600; if php-fpm runs as another account than the one that ran `skv:bootstrap`, `chown` it to that account or use 0640 with a shared group.
- **Health check (Task 12):** a hand-set `HEALTH_CHECK_KEY` must be random (`php artisan skv:doctor --fix` writes one; or `openssl rand -hex 20`), because the per-key bucket does not slow down guessing; the bucket is an HMAC under `APP_KEY`, so rotating `APP_KEY` resets the monitor's per-minute count; with a broken ops store the throttle logs one warning line per request (exception class only) and lets the request through.
- **Backups (Task 12):** archives on the local `backups` disk are written 0600 (folders 0700; older archives keep their mode until the clean-up removes them); `backup:run --config` and `--db-name` give `pg_dump` the `sslmode` of the sources they dump (one value per process: with several PostgreSQL sources the first one's wins); ext-posix is not required, but without it `backup:run` refuses, Herd's `pg_dump` counts only when root owns it and the doctor's `pg-dump` row fails and says why; a `backup:run` as root (a root crontab) needs its temporary directory inside `storage/`; `last-backup` names one cause per message (no password, encryption off, no AES-256).
- **Install / staging (Task 13):** staging uses the strict password rule (12+ characters, letters, mixed case, a number, a symbol, checked against haveibeenpwned over the network); `skv:create-super-admin` refuses a password hash given in place of a password; after a failed or interrupted `skv:flux-pro` (Ctrl-C included) the `extra.skv` mark and `repositories.flux-pro` are removed again, and when a removal fails the command prints the `composer config --unset` line to run.

- [ ] **Step 3: `docs/handleiding.html`**

The same statements in Dutch (informal "je", the glossary of the Global Constraints), in the matching sections, plus:
- G5-12: remove the branch pin and the per-milestone status lines that name a branch (search for `m5-ui`, `m7b-setup-hardening`, `op de branch`); the status line says which milestones are in `main` (M0 to M7c after this milestone's merge) without a commit hash.
- The login page description (Task 9): the organisation's name shows above the form on its own host, the platform's name centrally.
- *Recente activiteit* (Task 9): on central hosts it includes archived organisations' entries.
- The Flux Pro rule (Task 9): a `pro-only` comment must stand on the line right before its `@if`.

- [ ] **Step 4: `.ai/guidelines/skv3.md`, then `AGENTS.md`**

Edit these bullets (keep the file's one-line-per-rule style; replace, do not add a contradicting line):
- **Tenancy and authorization:** replace "An action that takes an `Organisation` calls `HostScope::lockForWrite($organisation)` inside its transaction before `Tenant::run()`." with CD-5's order: first the organisation (`HostScope::lockForWrite()`, `HostScope::lockOrganisation()` for user writes, `withTrashed()->lockForUpdate()` for archive, restore and purge), then the roles in id order, then one batch of users rows (one `SELECT … FOR UPDATE` on `users` only, no join, in id order: user administration — status, delete, roles, super admin — takes it through `LockoutGuard::lockUsers()` / `lockForRemoval()` and decides on the locked rows; `AcceptInvitation` and `CancelInvitation` lock the invitee's row alone; `ArchiveOrganisation` and `PurgeOrganisation` lock the organisation's users in one statement), then `model_has_roles`, invitations, sessions and reset tokens, activity last; no retry loop; lock-order tests use `lockStatements()` and skip outside PostgreSQL.
- **Limits (Task 1):** a Livewire action that sends mail or checks a secret limits itself with `App\Support\RateLimits\UserActionLimit` in the component, never in the action (`attempt()` before a secret check; `reserve()` before a send, with `release()` in a `catch (Throwable)` that rethrows; never a check before and a count after the action, which parallel requests outrun); code that clears a named limiter's bucket builds the key with `ThrottleKey::of()`.
- **Links (Task 2):** `Hosts::origin()`, `Hosts::url()` and `tenant_url()` take scheme and port from `APP_URL`; never build a link from the request's scheme or port.
- **Return targets (Tasks 4, 6):** "go back" targets in kit code use `App\Http\Support\ReturnUrl::previous($request, $fallback)`, a same-host root-relative path; never `back()`, `url()->previous()` or `url()->current()` in kit code or error views; vendor `back()` on CSRF-protected POSTs stays (CD-13).
- **Preferences (Task 4):** display preferences change through `UpdateLocale` / `UpdateAppearance`, which keep an impersonator's choice in the session; read them with `Impersonation::localeFor()` / `appearanceFor()` (or `Locales::forRequest()`), never `$user->locale` / `$user->appearance` for display.
- **Localisation (Task 5):** a status flashed on a redirect that crosses a user switch is translated with `__($key, [], Locales::forNextPage($request, $user)->value)`; on-demand notifications carry the recipient account's language (`->locale($user->locale->value)`) and call `afterCommit()`; a Livewire action that reloads the page flashes its result in the new language instead of `Flux::toast()`.
- **Error pages (Task 6):** error pages extend `errors.layout`; *Try again* is `@include('errors.partials.try-again')`.
- **Passkeys (Task 3):** failed passkey sign-ins are recorded only by `App\Listeners\RecordFailedPasskeySignIn`.
- **Mail (Task 8):** queued invitation mails carry the invitation id, not the model, and send nothing when it is gone.
- **Flux Pro (Task 9):** the stripper and the lint share `App\Support\BladeDirectives`; a `{{-- pro-only … --}}` comment stands on the line before its `@if`.
- **Routes (G5-07):** after "never use `Route::domain()`" add "(the exceptions are the Fortify-mirroring groups in `routes/fortify.php` and `routes/passkeys.php`, pinned by `KitRulesTest`)".
- **UI (G5-05):** replace "Navigation entries go into `App\Support\Navigation\Navigation::items()`, never into a Blade file." with "The sidebar's navigation entries go into `App\Support\Navigation\Navigation::items()`, never into a Blade file; the settings sub-navigation (`pages/settings/layout.blade.php`) and the user menu keep their own items."
- **Setup (Task 11):** `setupSandbox()` links `public/storage`, so a test of the link unlinks it first; `DetachedStepLauncherTest` runs the real launch script with `Process::preventStrayProcesses(false)`, the second exception after `BackupRunTest`; read env files through `EnvFile`, whose `contents()` refuses an unreadable file.
- **Ops (Tasks 12, 13):** "this account" checks read the uid through `App\Support\ProcessAccount` (tests bind `new ProcessAccount(fn (): ?int => null)` or `fn (): int => 0`); a test that runs `backup:run` past the `--config` resolution restores `PGSSLMODE` with `Tests\Fixtures\PgSslModeSnapshot`; `SetSuperAdmin` is called only from `UpdateSuperAdmin`, `DatabaseStep`, `skv:create-super-admin` and `DemoSeeder` (`tests/Arch/AuthorizationRulesTest.php`).
- **Tests:** mutation checks copy files aside with `cp`, never `git stash` (shared by every worktree).

Then run `php artisan boost:update --no-discover --ignore-skills --no-interaction` (`AGENTS.md` is git-ignored; check that `git status --short` shows no other change from it).

- [ ] **Step 5: `docs/decisions.md`**

1. **DEC-34 — Security hardening build rulings (M7c)**, after DEC-33, in its format (**Question**, **Answer** with numbered items, **Evidence**, **Consequence**):
   - **Question:** Which choices made while building M7c refine the spec or earlier decisions, what did the controller decide under the owner's delegation, and what is deferred?
   - **Answer:** (1)–(13) CD-1…CD-13 as built, each with what the task report changed (CD-3's limits table and what counts, the new throttle key, `UserActionLimit`'s API and `RemindInvitation::COOLDOWN_MINUTES`; CD-5's order and the five problems it removes — the LockoutGuard cycle, accept/archive, status/delete/archive, roles/delete-role, the demote and restore races — plus `HostScope::lockOrganisation()`, the global template-role lock's accepted cost and the two allowed exceptions; CD-6's naming rule and the `RequestHandled` recorder; CD-7; CD-8 with the production note; CD-9; CD-10; CD-12; CD-13's three call sites). (14) The triage (CD-2) and its id mapping: the counts (149 = 44 already fixed + 1 obsolete + 8 duplicates + 62 handled here + 26 M8 only + 8 won't fix), where the triage files' evidence went (this plan's header), and the 26 M8 ids plus the M8 halves of L7b-43 and L7b-73, and the plan review's K9 (`HerdSiteStep::isComplete()` ignoring the PHP version). (15) The deviations from the spec with their reasons: §6.2's scheme-and-port rule (CD-4), §4.11's flashed text (`Locales::forNextPage()`), M5 RL-7's server-side `returnPath` replaced by a client reload (G3-07), the 401/402 kit pages next to the 4xx fallback (G3-04), 419 keeping `location.reload()`, `<title>` keeping `platformName()` (DEC-29 (5)). (16) The deviations from the plan that the task reports and the review fix rounds recorded, with the controller's rulings during the run (filled in after execution). (17) The documentation (this task). (18) The controller's real verification (Task 15; filled in after execution).
   - **Evidence:** the test files each task names.
   - **Consequence:** Deferred to M7d: the §6.6 extras. Deferred to M8: the triage's M8 list (with "HerdSiteStep::isComplete() ignoring the PHP version (plan review K9)"), with the CI runs of the e2e and `phase1` jobs, the release check and publication. Won't fix: the triage's list. The deferred minors of the M7c reviews: (filled in after execution).
2. **Amend in place**, each with "(amended by DEC-34 (n))":
   - DEC-26: `TenantUrlTest` keeps `APP_URL`'s scheme and port (CD-4).
   - DEC-27 (2): passkeys are limited "per signed-in user, or per IP for a guest's sign-in" instead of "per credential (or session) and IP" (G1-05).
   - DEC-28 (24) and the residual of (30) ("until M7's limits"): closed by CD-3. DEC-28 (29): the tenant-host reset log skips a promoted super admin too (G4-01).
   - DEC-29: RL-7's stored return path (G3-07); the open-minors list names the M5 minors M7c closed (G5-12) and points at DEC-34 (14) for the rest.
   - DEC-33 (14): the second-factor login goes to the admin host (CD-7). DEC-33's RL-1 note: `.env` and `.env.testing` are 0600 (CD-8). DEC-33's L-05 / (32)(h): the `PGSSLMODE` export moved to `BackupRunCommand::handle()` (L7b-68); `ConfigurePgDump` still exports it as the backstop when `backup:run` is not the app's command (L-05). DEC-33 CD-13: `skv:flux-pro` also removes `repositories.flux-pro` (L7b-72, L7b-81).
   - DEC-32's and DEC-33's Consequences: append "M7c done (DEC-34): …" naming what they deferred to M7c and where it went (fixed here, or M7d / M8).

- [ ] **Step 6: CHANGELOG consistency**

Read the `[Unreleased]` section top to bottom. Every M7c behaviour change has one line (Tasks 1–13 added them); merge lines that now describe the same behaviour twice, and replace older lines that M7c contradicts (for example any line that says links keep the request's port, or that names `returnPath`). Do not add lines for docs-only changes.

- [ ] **Step 7: Checks and commit**

- `grep -rn "m5-ui\|m7b-setup-hardening\|op de branch" docs/handleiding.html` prints nothing.
- `grep -nF 'HostScope::lockForWrite($organisation)' .ai/guidelines/skv3.md` prints nothing (fixed-string match; before the edit it prints line 26).
- `grep -c "(filled in after execution)" docs/decisions.md` prints `3` (DEC-34 (16), (18) and the Consequence).
- The gate of the Global Constraints.

```bash
git add README.md docs/handleiding.html .ai/guidelines/skv3.md docs/decisions.md CHANGELOG.md tests/Arch/KitRulesTest.php
git commit -m "docs: document the security hardening, the lock order and record DEC-34

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 15: Milestone gate and the controller's real verification

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
git diff main --stat -- composer.lock package.json package-lock.json
git diff main -- composer.json
```

Expected: four green suites; the stylesheet as committed; a clean tree; `0` and `0`; no catch-all fake; an empty `grep -L`; no dependency change (the `--stat` prints nothing); the `composer.json` diff shows only the two `scripts` lines of Task 11 (the `.env` one-liner in `setup` and `post-root-package-install`).

- [ ] **Step 2: The real run (PostgreSQL + Herd Pro + HTTPS)**

Every command names the throwaway site by its absolute path; artisan runs as `php "$HOME/Herd/m7c-e2e/artisan" …`. Never `artisan`, `awk`, `stat` or `ls` on a relative path (they would hit the repository).

1. **Install.** `scripts/e2e-local.sh "$HOME/Herd/m7c-e2e" --entry=herd --phase1-only`; every phase-1 check passes; the site is not the repository (`[ "$(cd "$HOME/Herd/m7c-e2e" && pwd -P)" != "$(git -C /Users/frankcornet/Herd/skv3 rev-parse --show-toplevel)" ]`).
2. **The gate (Task 10).** Before the wizard: `curl` with `X-Real-IP: 1.2.3.4`, with `Via: 1.1 proxy` and with `True-Client-IP: 1.2.3.4` on `http://m7c-e2e.test/welcome-setup` each answer 404; `/` hands out the token only without them.
3. **The wizard** (Playwright, as in M7b's Task 13): PostgreSQL, the demo data, passkeys + backups + impersonation, HTTPS, Claude Code off. It reaches its summary with no JavaScript errors; `test -L "$HOME/Herd/m7c-e2e/public/storage"` succeeds (CD-12); `stat -f '%Lp' "$HOME/Herd/m7c-e2e/.env"` prints `600` (CD-8); the login after setup lands on the https origin (Task 2).
4. **Links from `APP_URL` (Task 2).** On `https://demo1.m7c-e2e.test`, request a password reset for `admin@demo1.m7c-e2e.test` with `curl -H 'Host: demo1.m7c-e2e.test:6666'` (or `X-Forwarded-Port: 6666`); the link in the mail (Herd Pro's mailbox, or `storage/logs` when the log mailer is set) is `https://demo1.m7c-e2e.test/…` without `:6666`. Record only the host and path, never the token.
5. **Limits (Task 1).** Signed in as `admin@demo1…` through the quick login: the 11th invite within a minute is refused with "Te veel pogingen…"; a second reminder for the same invitation within 10 minutes is refused; `POST /login` with `email[]=x` answers 302/422, never 500.
6. **Error pages (Task 6).** `curl -s -w '\n%{http_code}' https://m7c-e2e.test/logout | tail -c 400` → 405 with the kit's page (`<html lang="nl"`, no "Oops! An Error Occurred"). In the browser, send 31 language switches through the auth pages' language toggle within a minute: the 429 page appears in Dutch, and its *Opnieuw proberen* reloads a GET page, never `/locale`.
7. **Languages (Tasks 4, 5).** As `superadmin@…` impersonate `user@demo1…`, switch to English in the user menu: the page reloads in English with its query string; stop impersonating; sign in as `user@demo1…` through the quick login: the page is still Dutch, so the account's language did not change.
8. **The System page and the backup (Task 12).** `php "$HOME/Herd/m7c-e2e/artisan" backup:run --only-db` succeeds; the newest archive is mode 600; *Systeemstatus* renders; `skv:doctor --json` is green.
9. **Record** every output in the ledger, never a key, token or password.

Clean up as M7b's Task 13 did: `herd unsecure m7c-e2e`; `herd unisolate --site=m7c-e2e` only if `herd isolated` lists it; `rm -rf "$HOME/Herd/m7c-e2e"` and the script's temp directory; drop `m7c_e2e` and `m7c_e2e_test` with a PDO script in the scratchpad that first checks `pg_database` lists exactly those two names and then drops them by literal name; delete the answers file and any saved browser state.

- [ ] **Step 3: Hand-off**

Fix what Step 2 finds, test-first, then re-run Step 1. Fill in DEC-34 (16), (18) and the Consequence's deferred minors. Stop before merging: the owner merges. Update the memory notes: M7c done, its deferred minors, M7d's plan next.
