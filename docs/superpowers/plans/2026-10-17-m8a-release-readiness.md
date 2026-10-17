# M8a Release Readiness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make skv3 ready for its v1.0.0 release with everything that can be done and proven locally: close the follow-ups that block v1 (FM-18's address-lookup limit, handoff links bound to the password, the session rotation, the temporary-upload clean-up, K9 and the other open behaviour fixes), harden the test suite and CI workflow, add the packaging metadata, the kit skills and the traceability script, bring the documentation up to date with DEC-36, and record fresh success-criteria evidence. Publication (M8b) is out of scope and waits for the owner's explicit approval.

**Architecture:** M8a is a hardening milestone, not a feature milestone. Each task bundles the open items of one area (the triage of the carried follow-ups, `scratchpad/m8/reconcile.md`) with its own failing tests:
- behaviour and security fixes first (Tasks 3–8), each behind a test that fails today;
- then tests that pin protections no test guards today (Tasks 1, 2, 9–12);
- then CI workflow content (Task 13, pinned by `tests/Feature/Tooling/CiWorkflowTest.php`; its first real run is in M8b), the kit skills (Task 14), the traceability script (Task 15) and the packaging metadata (Task 16);
- documentation and DEC-36 come last (Task 17), and the controller's evidence run on a throwaway Herd site closes the milestone (Task 18).

New shared pieces:
- `UserActionLimit::addressLookup()` (Task 3)
- the `handoff_tokens.password_hash` column (Task 4, one new migration)
- `App\Support\Uploads\StaleTemporaryUploads` and the `livewire-tmp-cleanup` schedule entry (Task 7)
- a test-database guard in `Tests\TestCase` and a runtime stray-process guard (Task 1)
- `.ai/skills/*/SKILL.md` (Task 14), `scripts/traceability.php` with frozen `§4.x.n` bullet ids (Task 15), a `LICENSE` (Task 16)

**Tech Stack:** PHP 8.4, Laravel 13.35, Livewire 4.4 (multi-file components), Flux 2.20 (free) with the optional Pro layer, Fortify, laravel/passkeys, spatie/laravel-permission, spatie/laravel-activitylog v5, spatie/laravel-backup 10, intervention/image 4, Laravel Boost 2, Pest 5.3 with pest-plugin-browser 5.1, Larastan 3 level 8, PostgreSQL 18 (Herd), SQLite, GitHub Actions (content only; no run in M8a).

**Spec:** `docs/superpowers/specs/2026-10-10-skv3-design.md`. Read these before starting:
- §11.2 (M8's scope) and §12 (success criteria 1–5)
- §7 (quality pipeline), §8 (CI, the job table and Hardening), §9 (AI developer experience), §10 (documentation)
- the sections each task names (§4.x, §5.x, §6.x) and Appendix A (the skv2 findings; their regression tests must stay green)

Decisions live in `docs/decisions.md` (DEC-01…DEC-35). DEC-34 (M7c) and DEC-35 (M7d) hold the deferrals this milestone takes. M8a writes **DEC-36**.

## Global Constraints

- **Versions:**
  - PHP floor `^8.4`; `laravel/framework ^13.35`; Pest `^5.3`.
  - Larastan level **8**, **no baseline**, no new `ignoreErrors` entries. This includes the multi-file component `.php` files under `resources/views/**`.
- **PHP files:** every one starts with `declare(strict_types=1);`. A whole-file code block in this plan starts with `<?php` on its first line; nothing, not even a blank line, may come before it. Pint uses the `laravel` preset plus `declare_strict_types`, `strict_comparison` and `ordered_class_elements`. Run `composer fix` after editing PHP.
- **Dependencies:** M8a adds **none** (no Composer, no npm package, no `ext-*`). `livewire/flux-pro` and `composer.fluxui.dev` never appear in the committed `composer.json` or `composer.lock`. Task 16 changes only `composer.json`'s metadata keys (`keywords`, `homepage`, `support`), which are not part of Composer's content-hash: `composer.lock` does not change and no `composer update` runs.
- **Never touch the developer's own environment from code or tests:**
  - No test reads or writes the repository's `.env`, `.env.testing`, `herd.yml`, `.mcp.json`, `storage/app/skv-installed`, `storage/app/private/skv-*`, `storage/app/backups`, `storage/app/backup-temp`, `storage/app/public` or `public/storage`.
  - No test opens a connection to the developer's PostgreSQL unless it is an explicit PostgreSQL test that skips when the server is unreachable (and, from Task 13 on, fails instead of skipping when `REQUIRE_POSTGRES=true`, CD-10).
  - Implementers never run `skv:*`, `backup:*`, `storage:link`, `migrate`, `db:seed` or `tinker` in the repository itself, and never run a mutating Herd command. Task 4's migration is tested through `RefreshDatabase` only; the owner applies it to the developer database (CD-19).
- **External commands:** every external command the kit starts runs through `Process` with `Toolchain::environment()`. Never `exec()`, `shell_exec()`, `system()`, `passthru()`, `proc_open()`, `popen()`, `pcntl_exec()` or backticks (`tests/Arch/KitRulesTest.php`). Tests fake every command by name on Symfony's single-quoted command line; never a catch-all fake (`AGENTS.md` › Setup).
- **Never print or log secrets:** passwords and their hashes, `APP_KEY`, session ids and payloads, remember tokens, reset, invitation, email-change and handoff tokens, the Flux licence key. Audit entries never hold a password, a token, a session id or an accept URL. CI never writes a credential to a global Composer `auth.json` (CD-9).
- **Never run against the development database:** no `migrate:fresh`, `db:wipe` or `db:seed`. `tinker`, `migrate` and `db:seed` prompt for approval.
- **Tenancy and authorization (`AGENTS.md` › Tenancy and authorization):**
  - `Tenant::bypass()` and scope removal only in `App\Console`, `App\Setup`, `App\Tenancy`, `App\Actions\Audit`, `Database\Seeders` and `app/Http/Controllers/HealthCheckController.php`. Livewire pages read through `HostScope::read()`.
  - Use the `App\Authorization\Permission` enum, never a permission string; switch teams only with `withPermissionsTeam()`.
  - Administrative actions take the actor first and authorise with `Gate::forUser($actor)`. Every transaction follows DEC-34's lock order: organisation → roles in id order → one `users` batch in id order → `model_has_roles` → invitations → sessions, handoff and reset tokens → activity inserts.
  - Livewire components authorise in `mount()` and again before calling an action, keep `#[Locked]` ids and derived state, and re-resolve models on every request.
- **Limits:** every action a member can repeat to send mail, test a secret, look up whether an address has an account, or make the server do expensive work uses `App\Support\RateLimits\UserActionLimit` in the component (never in the action): `attempt()` before a secret check or a lookup, `around()` for a send. A refusal is a `ValidationException` on the form's field with "Too many attempts. Try again in :seconds seconds." Route-level limits use a named `RateLimiter::for()` limiter keyed with `ThrottleKey::of()`.
- **Routes:** group single-host routes with `Route::tenant()` / `Route::central()`; never `Route::domain()` (the Fortify-mirroring groups are the named exceptions). M8a adds **no** route.
- **Audit:** entries are written only through `App\Actions\Audit\RecordActivity` with an `App\Enums\ActivityEvent` case. M8a adds no case unless a task says so.
- **Mail:** mails implement `ShouldQueue` and call `afterCommit()`; tests run them on the sync queue with the `array` mailer, not `Mail::fake()`.
- **Schedule:** the ops schedule lives in `App\Support\Scheduler\OpsSchedule::define()`; web code reads it through `ScheduledTasks`; `tests/Feature/Scheduler/*` pins every entry (Task 7 adds one).
- **Names:** no hard-coded kit or site name (`skv`) in app-identity values (`tests/Arch/NameLeakTest.php`). The only places the kit's own repository name `frankcornet/skv3` may appear are those `NameLeakTest` already allows, `.github/workflows/tests.yml` (CD-11) and the packaging metadata (Task 16).
- **Copy and lang keys:**
  - Code, comments and docs are in English. UI copy goes through `__()`, `nl` by default, with `en`.
  - Every key goes into **both** `lang/nl.json` and `lang/en.json`: identical keys, the same order (PHP `sort()` order, byte-wise), Dutch informal "je". No task deletes or renames a key.
- **Livewire:** multi-file components only, no ⚡, never `$this->view()`. `password.confirm` does not cover Livewire updates (`ConfirmsPasswords`).
- **Flux Pro (§6.7):** Pro-only components appear only inside `@if (Skv::hasFluxPro())` with a complete free `@else` binding the same `wire:model` names (`tests/Arch/FluxProLayerTest.php`). No test installs Pro.
- **Tests:**
  - Pest functions (`it(...)`) with typed closures. Each new file starts with a `/** covers §x.y */` docblock as its first statement; from Task 15 on, `covers` lines may cite the frozen bullet ids `§4.x.n` (CD-17).
  - `Event::fake()` is never called bare: `Event::fake()->except([TenantResolved::class, TenantForgotten::class])`.
  - `Livewire::test()` skips all middleware: test host rules, limits and redirects over HTTP (`pageSnapshot()` + `callPageAction()`).
  - Tests that depend on a module call `useImpersonationModule()`, `usePasskeysModule()` or `useBackupsModule()` first.
  - **No `*Test.php` file declares a class (anonymous ones included) or spells a qualified class name outside its imports** (`tests/Arch/TestFileClassesTest.php`). Fixtures go in `tests/Fixtures`.
  - Tests are rewritten, never deleted (deleting a test needs the owner's approval). A test whose title promises more than it checks is strengthened or retitled; this milestone does that a lot, and the task says which.
  - A `match` on a `string` dataset parameter ends with a `default =>` arm.
  - A weak-test item is done only when a named mutation of the production code makes the new or strengthened test fail (see "Mutation check"); where the guarded code is test support itself (the Flux Pro lint, D50), the mutation is in that support file.
- **Global test helpers already taken (never redeclare):** `acceptLinkFor`, `asMember`, `asOrgAdmin`, `asSuperAdmin`, `browserUser`, `callPageAction`, `confirmationLinkFor`, `dutchTranslationKeys`, `filesMatching`, `inviteMember`, `kitSourceFiles`, `lockStatements`, `onAdminHost`, `onApex`, `onTenant`, `pageSnapshot`, `recordedActivity`, `sameOrigin`, `sessionRowFor`, `setupSandbox`, `startImpersonatingOverHttp`, `useBackupsModule`, `useImpersonationModule`, `usePasskeysModule`, `useSetupWizard`, `withoutPostgresProbe`. A task that needs a new global helper names it under Produces; otherwise file-local helpers are closures.
- **Gate per task:**
  - `composer check` (SQLite) green
  - `composer test:pgsql` green
  - the modules-off run: `php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser` green
  - `npm run build && composer test:browser` green (the one detail-less warning in the agent JSON reporter is the known pest-plugin-browser `Trace::cleanup()` warning, CD-15, not a finding)
  - `npm run build:setup && git diff --exit-code resources/setup/setup.css`
  - then `php artisan config:clear`
- **Commits:** Conventional Commits, lower case, imperative, ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Stage files by name. The pre-commit hook (Pint + Larastan on staged files) runs; never bypass it.
- **Never** run `herd sites` or the Herd MCP `get_all_sites`, never read `.env` files (`.env.example` is fine), never use `git stash` (it is shared by every worktree; set a file aside with `cp`), never push, never add a git remote, never contact GitHub or Packagist with a write.

## Conventions used in this plan

- All M8a work happens on branch `m8-release`, created from `main` (merge `5a621c3`, "Merge M7d: account extras"). The plan file, `docs/superpowers/plans/2026-10-17-m8a-release-readiness.md`, is its first commit.
- Bracketed ids are traceability tags:
  - `CD-*`: the controller's decisions below, taken under the owner's delegation
  - `§x.y`: the spec requirement a step implements
  - `A12`, `D82`, `E4`, `F16`, …: the triage ids (`A` = M2–M5 lists, `B` = M6a/M6b, `C` = M7a/M7b, `D` = M7c, `E` = M7d, `F` = spec gap analysis), with the source's own id (e.g. FM-18, K9, g2-01, L-06) where it has one
  - `DEC-*`: decision-log entries
- **HTTP tests for host rules** use `onTenant()`, `onApex()`, `onAdminHost()` and the `as*()` helpers from `tests/Pest.php`.
- **CHANGELOG:** `## [Unreleased]` has `### Added`, `### Changed`, `### Fixed` and `### Removed` on main; Task 3 adds `### Security` after `### Removed`, and every later security line goes there.
- **Mutation check:** where a step says "verify by mutation", the implementer reverts or breaks the production change locally, confirms the new test fails, restores it and confirms it passes. Copy the file aside with `cp` and copy it back; **never `git stash`**. After a Blade or Livewire component mutation run `php artisan view:clear`. The task report names each mutation and its result.

## Scope

M8a (this plan), in execution order:
1. **Test-suite safety guards** — the test-database guard, the runtime stray-process guard, BackupRunTest's probe, the HerdSiteStepTest fakes.
2. **Kit-rule and arch guards** — the arch and config-default tests that pin kit rules no test guards today.
3. **The address-lookup limit (FM-18)** on the profile, the bulk page's "Check", the invite page and `users.edit`.
4. **Handoff links bound to the password** (one new migration).
5. **The sessions page** — this device's session id rotated by "log out other devices", and the small sessions items.
6. **Second factor, organisation edit and recovery codes.**
7. **Uploads, bulk invitations and photos**, including the scheduled clean-up of Livewire's temporary uploads.
8. **Setup steps, bootstrap and doctor** — K9, the storage-link check, `Bootstrap::isProduction()`, GD without WebP.
9. **Setup gate, wizard runner, setup and ops weak tests.**
10. **Auth and tenancy protections without a failing test** (tests only).
11. **UI and feature weak tests**, `Role::color()` and the LoginTrend fix.
12. **Browser suite hardening and coverage** (browser only).
13. **CI workflow content** (`.github/workflows/tests.yml`, pinned by `CiWorkflowTest`; its first real run is in M8b).
14. **The four kit skills (§9) and `.claude/settings.json`.**
15. **The traceability script and the Appendix A R2 test** (success criteria 3 and 5).
15b. **The §4 clause gap-fill** — tests for the 19 partially covered §4 bullets the traceability mapping found (tests only).
16. **Packaging metadata** — `LICENSE`, `.gitattributes`, `composer.json` metadata.
17. **Release documentation, the spec amendments, DEC-36 and the CHANGELOG pass.**
18. **The controller's success-criteria evidence run** on throwaway Herd sites, then cleanup.

Not in scope:
- **M8b (publication, only after the owner's explicit approval):** the GitHub repository and push, the Flux secrets, the first green CI run and its fix-forward loop (browser `*.localhost` on ubuntu, phase1 ×4, e2e ×3), branch protection, Packagist, the Packagist install check, the timed Herd deep-link run through the Herd GUI (criterion 1 as worded), the release-check workflow, the CHANGELOG `1.0.0` cut, the `v1.0.0` tag and release-check green after it.
- The post-v1 and wont-fix items listed in DEC-36's Consequence (Task 17 copies them from `reconcile.md` §5).

## Controller decisions (owner delegation, 2026-10-15, reaffirmed 2026-10-17)

The owner delegated every non-destructive decision to the controller; each can be overruled. Publication and every outward-facing action stay with the owner (CD-1).


**CD-1. M8 split.**
- Decision: M8a is everything provable locally (T1–T18); M8b is publication (§4).
- Reason: §11.2 makes publication conditional on explicit owner approval, and nothing in M8b can run without a remote.

**CD-2. Address-lookup limit.**
- Decision:
  - `UserActionLimit::addressLookup()` = `new self('address-lookup', 500, 86400)`, with `attempt()`, so every lookup
    counts and is never given back;
  - cost = the row count on bulk Check, 1 elsewhere, and only when the typed address differs from the stored one;
  - the refusal reuses the generic "Too many attempts" key;
  - DEC-36 supersedes DEC-34 (16)'s "accepted for v1".
- Reason:
  - a daily cap is what bounds enumeration;
  - 500 ≥ `MAX_ROWS` (`assertCost()`) and allows five full 100-row Checks per actor per day, more than one large
    onboarding needs;
  - a per-user key keeps one member's probing from blocking an admin;
  - the constant is the one tuning point.

**CD-3. Handoff binding.**
- Decision:
  - a new migration `0001_01_01_000010_add_password_hash_to_handoff_tokens_table.php` with a nullable `password_hash`
    string(64), holding `SessionGuard::hashPasswordForCookie()` of the user's hash;
  - a null or mismatched value is refused; the explicit delete in "log out other devices" stays.
- Reason:
  - it covers every password write path (security page, reset, `skv:create-super-admin --password-stdin`,
    DatabaseStep), where a delete-on-change hook covers only the paths that remember it;
  - it is the same HMAC as `AuthenticateSession`;
  - a new migration rather than editing `…000004`, because developer databases are already migrated and
    `migrate:fresh` is forbidden.

**CD-4. Upload clean-up.**
- Decision: `livewire-tmp-cleanup` at `hourlyAt(45)` on every server (no `onOneServer()`), with a 24 h threshold; it does
  nothing on an S3 temporary disk (the README points at `livewire:configure-s3-upload-cleanup`).
- Reason:
  - 24 h equals Livewire's own threshold, so no open tab loses an upload earlier than today;
  - :45 keeps clear of `backup-temp-cleanup` at :15;
  - the disk is local per server, as for the backup temp directory.

**CD-5. Kit skills (§9).**
- Decision: build the four skills in M8a (T14) as `.ai/skills/*/SKILL.md`, published by Boost.
- Reason:
  - §9 lists them by name and no DEC deferred them;
  - they are small Markdown files pointing at existing rules;
  - Boost 2.10 already supports `.ai/skills`;
  - deferring them would mean recording a spec deviation for v1's headline AI-DX feature.

**CD-6. Manual format.**
- Decision: keep `docs/handleiding.html` and record the deviation from §10's `docs/handleiding.md` in DEC-36; amend §10.
- Reason:
  - the HTML manual has existed since dfe18df, is self-contained and is not under `public/`;
  - a conversion is churn with no user gain;
  - R2 is guarded by T15's test.

**CD-7. Pre-commit hook.**
- Decision: keep `pint --test` on the staged file list instead of §7.4's `pint --dirty`; record it in DEC-36 and amend
  §7.4.
- Reason: it checks exactly what is committed, never rewrites files mid-commit, and ignores unstaged edits, which
  `--dirty` would format.

**CD-8. Criterion 1 measurement.**
- Decision:
  - M8a (T18) measures locally with `scripts/e2e-local.sh --entry=herd` (Herd's exact `laravel new` command, then the
    wizard), from the `laravel new` start, which stands in for *Create*, to the dashboard;
  - configuration A is Herd Pro + PostgreSQL; configuration B is SQLite with Herd Pro's services left running (stopping them would change the owner's other sites), recorded as
    "free-Herd approximation";
  - `skv:doctor --json` is captured;
  - M8b (step 9) measures the spec's actual wording through the Herd GUI and deep link once Packagist resolves the
    package.
- Reason: *Create* in the Herd GUI needs a Composer-resolvable package; a pre-publication global vcs repository would
  change the owner's global Composer config, which needs separate approval.

**CD-9. Flux credentials in CI.**
- Decision:
  - `COMPOSER_AUTH` on the steps that need it (check job: Enable Flux Pro; e2e postgres-pro: Enable Flux Pro, `skv:install` and `skv:doctor`, whose `FluxProCredentialsCheck` reads the credentials), built from the secrets with `php -r … getenv()` so the key is never in argv;
  - no `composer config --global http-basic`;
  - `composer audit` moved before the Flux Pro step;
  - the false comment corrected.
- Reason:
  - `--global` leaves the licence in `auth.json` for `npm ci` lifecycle scripts and pull-request Pest runs;
  - e2e's `skv:install` runs `composer require` against the fluxui repository, so B33's unset-after-flux-pro would
    break it.

**CD-10. Required PostgreSQL in CI.**
- Decision:
  - an env flag `REQUIRE_POSTGRES=true` on the pgsql check row and the browser job;
  - the three PostgreSQL-dependent tests (WizardPageTest:212, Browser SetupWizardTest:112, BackupRunTest:79) skip only
    when it is unset;
  - the check job's service uses Herd's credentials (root, trust).
- Reason: the sqlite rows have no server, so `CI=true` alone cannot decide; a silent skip hid that these tests never ran
  in CI.

**CD-11. Kit-repository Flux Pro guard.**
- Decision: a check-job step with `if: github.repository == 'frankcornet/skv3'` (M8a); release-check repeats it on the
  tag (M8b).
- Reason: `tests.yml` already names the kit (`:411`); generated apps may commit the mark (CD-13 of M7b).

**CD-12. Missing APP_ENV.**
- Decision: `Bootstrap::isProduction()` treats "no APP_ENV in any source" as production.
- Reason:
  - it matches Laravel's own default (`config/app.php:31`);
  - `run()` creates `.env` from `.env.example` (APP_ENV=local) before it asks, so fresh installs are unaffected.

**CD-13. §6.5 badge colours.**
- Decision: add `Role::color()` and use it at both super-admin badge sites; narrow §6.5 to "enums shown as a coloured
  badge" (Permission and ActivityEvent render none); record it in DEC-36.
- Reason: this fixes the one real spec gap without inventing colours for enums no view badges.

**CD-14. Shared docs files.**
- Decision:
  - behaviour tasks update the README section and the CHANGELOG line for their own change, and regenerate AGENTS.md
    after any guideline edit;
  - T17 alone mirrors into `docs/handleiding.html`, writes DEC-36 and does the CHANGELOG consistency pass;
  - old DECs get only an inline "Superseded, see DEC-36 (n)" pointer.
- Reason: each reviewer sees the docs of the change they review; the Dutch manual gets one consistent pass.

**CD-15. Gate template.**
- Decision:
  - the composer.lock package check uses `git diff <base> -- composer.lock | grep -E '^[-+] {12}"name": '` (E6);
  - the browser suite's "1 warning, empty details" in agent JSON is the known pest-plugin-browser Trace warning and is
    not a finding;
  - T18 removes the two stale setup sandboxes (E7).
- Reason: the M7d pattern matched `authors[].name`, and reviewers kept re-reporting the vendor warning.

**CD-16. Test-database guard.**
- Decision:
  - `TestCase::setUp()` allows `:memory:`, names matching `/_test(_test_\d+)?$/`, paths under `storage/framework/testing`,
    or `DB_TEST_DATABASE`;
  - it reads the environment before `parent::setUp()`, and refuses a non-empty `DB_URL` (its database would win over `DB_DATABASE`; `phpunit.xml` sets `DB_URL=""`);
  - `phpunit.xml` keeps no `force` on `DB_*`, because `test:pgsql` passes them.
- Reason: this protects the developer database (CLAUDE.md safety rule) without breaking `test:pgsql`, parallel runs or
  CI.

**CD-17. Traceability.**
- Decision:
  - the spec's 90 §4 bullets get frozen `§4.x.n` ids;
  - `covers` lines cite bullet ids;
  - `scripts/traceability.php` plus a Tooling test fail on any uncovered bullet;
  - T15 may split into script/ids and gap-fill if more than about 10 behaviour gaps appear.
- Reason: criterion 3 is bullet-level, and today only the subsection level is proven.

**CD-18. "Log out other devices" rotates this device's session.**
- Decision: inside the transaction, after `Auth::logoutOtherDevices()`, call `session()->migrate()` and move this device's `sessions` row to the new id (`UPDATE sessions SET id = <new> WHERE id = <old>`), keeping the lock order and the CSRF token. (Revised during planning: `migrate(true)` deletes the row at once under the database driver, and the list the same Livewire update renders then loses "This device".)
- Reason: the button exists for the copied-cookie case (E21), and the old id must die with it. With another session driver the old session stays stored but holds the old password hash, which `AuthenticateSession` refuses.

**CD-19. Developer-database migration.**
- Decision: T4's migration is applied to the developer database only by the owner (`php artisan migrate` is not
  pre-approved); tests use their own databases.
- Reason: CLAUDE.md safety rules.

**CD-20. LICENSE holder.**
- Decision: "Copyright (c) 2026 Frank Cornet", MIT, as composer.json and the README already state; reported to the
  owner afterwards.
- Reason: non-destructive and matches the declared licence (memory: decide non-destructive choices, report after).

**CD-21. Handleiding source references.**
- Decision: "Bronnen" blocks name files without line numbers.
- Reason: line refs went stale after every milestone (E67); the newer blocks already do this.

**CD-22. Deep link and release-check placement.**
- Decision:
  - the deep link goes into the README and handleiding in M8a (the page already answers 200) and is verified in M8b;
  - release-check is written in M8b, in its own export-ignored workflow file guarded by `github.repository`, never in
    `tests.yml`.
- Reason: generated apps ship `tests.yml` unchanged (the e2e diff asserts it) and must never run a kit-only Packagist
  check.

## Requirement traceability

| Requirement | Task(s) |
|---|---|
| §12 (1) five minutes per configuration | 18 (local approximation, CD-8); M8b (Herd GUI) |
| §12 (2) `skv:doctor` without `fail` | 8 (checks), 18 (evidence) |
| §12 (3) every §4 item referenced by a test | 15, 15b (CD-17) |
| §12 (4) `composer check` + browser green in CI, e2e before publication | 13 (content), M8b (runs) |
| §12 (5) every Appendix A finding has a regression test | 15 (R2), 10 (auth protections) |
| §7.2 tests, §7.4 scripts and hooks | 1, 2, 9, 10, 11, 12, 13, 17 (CD-7) |
| §8 CI jobs and Hardening | 13 (CD-9, CD-10, CD-11) |
| §9 AI developer experience (kit skills, settings) | 14 (CD-5) |
| §10 documentation | 16, 17 (CD-6, CD-14, CD-21, CD-22) |
| FM-18 = DEC-34 (16), DEC-35 Consequence | 3 (CD-2) |
| DEC-35 Consequence: handoff after a password change, upload clean-up, GD without WebP, K9 | 4 (CD-3), 7 (CD-4), 8 |
| the remaining open M2–M7d follow-ups | 1–12 (per-task item lists) |

## File ownership

A task may change a file another task owns only where this table says it adds to it, and only after the owner task (tasks run in order).


| file | owner | others (in order) and how |
|---|---|---|
| `tests/Pest.php`, `tests/TestCase.php`, `tests/Fixtures/RecordingProcessFactory.php`, `tests/Fixtures/RecordingPendingProcess.php`, `tests/Feature/Tooling/TestDatabaseGuardTest.php`, and the setup tests the recorder exposes (`DoctorStepTest.php`, `InstallCommandTest.php`, `StrayProcessGuardTest.php`) | T1 | – |
| `tests/Arch/KitRulesTest.php`, `TenancyRulesTest.php`, `AuthorizationRulesTest.php`, `LangCoverageTest.php`, `StrictModeTest.php`, `SyncPermissionsTest.php` | T2 | – |
| `UserActionLimit.php`, `ActionLimitsTest.php`, `users/invite/invite.php`, `users/edit/edit.php` | T3 | – |
| `invitations/bulk/bulk.php`, `BulkInvitationsTest.php` | T3 | T7 adds to it after T3 (ordering, Throwable, dead markup) |
| `settings/profile/profile.php` | T3 | T7 adds to it (removeAvatar return) |
| handoff migration, `HandoffToken`, `IssueHandoff`, `HandoffController`, HandoffTest | T4 | – |
| `settings/sessions/*`, `SessionsTest.php`, `LockOrderTest.php` | T5 | – |
| `security.php`, `recovery-codes.php`, `EnsureSecondFactorTest`, organisation edit tests | T6 | T11 appends the A43 test to `OrganisationEditTest` |
| `OpsSchedule.php`, `StaleTemporaryUploads.php`, schedule tests, avatar actions (`app/Actions/Users/{UpdateAvatar,RemoveAvatar}.php`), `AvatarTest` | T7 | – |
| `HerdSiteStep.php`, `EnvironmentStep.php`, `StorageLinkCheck.php`, `Bootstrap.php`, `GdCheck.php`, `EnvironmentStepTest`, `ChecksTest`, `tests/Feature/Console/ProductionGuardsTest.php` | T8 | – |
| `tests/Feature/Setup/Steps/HerdSiteStepTest.php` | T1 | T8 adds the K9 fakes |
| `tests/Feature/Setup/Wizard/HerdStageTest.php` | T8 | T9 adds the C50 assertion |
| `WizardRunner.php`, `DirectRequest.php`, `SetupAccessTest`, `SetupModeTest`, health/system tests, `ConfigurePgDumpTest`, `CreateOrganisationCommandTest` | T9 | – |
| `tests/Feature/Auth/PasskeyTest.php` | T9 (match arms) | T10 adds the A20/A21 cases |
| `tests/Feature/Tooling/ComposerScriptsTest.php` | T9 | – (T13 pins the `e2e-local.sh` options in `CiWorkflowTest`) |
| auth test files of T10 | T10 | – |
| `ErrorPagesTest`, `DashboardTest`, `Role.php`, users blades, `LoginTrend.php`, `ActivityExportTest` | T11 | – |
| `tests/Browser/*`, app layout marker, `ProVariantsTest` | T12 | T13 changes only `SetupWizardTest.php`'s skip line and its `RequiredPostgres` import |
| `.github/workflows/tests.yml`, `CiWorkflowTest.php`, `scripts/e2e-local.sh`, `WizardPageTest.php` skip, `tests/Fixtures/RequiredPostgres.php`, `tests/Feature/Tooling/RequiredPostgresTest.php` | T13 | – |
| `tests/Feature/Backups/BackupRunTest.php` | T1 (finally) | T13 adds the `REQUIRE_POSTGRES` skip |
| `.ai/skills/**`, `.claude/settings.json`, `boost.json`, `AiConfigTest.php` | T14 | T15 adds the `§4.x.n` covers sentence to `write-tenant-test/SKILL.md`; T17 re-runs AiConfigTest after editing CLAUDE.md |
| `scripts/traceability.php`, traceability and R2 tests, `covers` docblocks | T15 | T15b adds tests to `tests/Arch/AuthorizationRulesTest.php` (T2's) and `tests/Feature/DashboardTest.php` (T11's), and creates `tests/Feature/ConfigDefaultsTest.php` and `tests/Arch/MigrationRulesTest.php` |
| `.gitattributes`, `LICENSE`, `composer.json`, `PackageMetadataTest` | T16 | – (never export-ignore `docs/superpowers/specs/`, `docs/handleiding.html`, `scripts/traceability.php` or `.ai/skills/`: generated apps run `TraceabilityTest` and `DemoCredentialsExposureTest`) |
| `lang/nl.json`, `lang/en.json` | – | T5, T7 and T8 each add their own keys to both files (no task deletes or renames; T2 only reads them) |
| `.ai/guidelines/skv3.md` (+ regenerated AGENTS.md) | – | T1 (Setup paragraph), T3 (limits sentence), T17 (T2's kit-rule sentences, T13's `RequiredPostgres` sentence, E68 scoping, final read); each task regenerates AGENTS.md with `php artisan boost:update --no-discover --no-interaction` |
| `README.md` | T17 (final) | T3 (Limits), T4 (handoff note), T5 (sessions), T7 (schedule table) add their own section; T17 does the rest and the handleiding mirror |
| `docs/handleiding.html` | T17 | T15 only reads it |
| spec `2026-10-10-skv3-design.md` | – | T15 (§4 bullet ids and §4's intro paragraph on the id convention), then T17 (header, §4.9.2 wording, §5.x, §6.x, §7.4, §8, §9, §10) |
| `docs/decisions.md` | T17 (DEC-36 + inline pointers) | T18 appends the evidence paragraph |
| `CHANGELOG.md` | – | T3–T9, T13, T14 and T16 each add one line under `[Unreleased]` (Security / Fixed / Added / Changed); T17 does one consistency pass; the 1.0.0 cut is M8b |
| `CLAUDE.md`, `config/trustedproxy.php` | T17 | – |

---

### Task 1: Test-suite safety guards (§7.2, CLAUDE.md › Safety; CD-16; A84a, C117=D113 (L7b-77, C116=L7b-76), C118=D114 (L7b-78), C111=D110=C120 (L7b-71/80), C61=D97 (L7b-21), C62=D98 (L7b-22))

The test suite gets two guards it lacks today.

The first is a **test-database guard**. `phpunit.xml` sets `DB_DATABASE=:memory:` without `force`, on purpose: `composer test:pgsql` and CI pass their own database. As a result, a `DB_DATABASE` exported in the shell wins, and `RefreshDatabase` then wipes that database, even the developer's own. `Tests\TestCase::setUp()` now reads the environment before `parent::setUp()` boots the application. It refuses to run unless the database is `:memory:`, a name ending in `_test`, a file under `storage/framework/testing`, or the `DB_TEST_DATABASE` that `test:pgsql` was given (CD-16).

The second is a **runtime stray-process guard** behind the static ban in `KitRulesTest`. Every RL-12 test (Setup, Console, Backups, Health, System) runs on a `Tests\Fixtures\RecordingProcessFactory`. That factory notes every stray command *before* `PendingProcess` throws for it, so a caller's `catch (Throwable)` can no longer hide one (L7b-78). After each test it also fails the test when any fake pattern matches a command nobody names (L7b-77). The static rule misses `'**'`, a list value after a named key and `Process::fake($fakes)`; this check does not.

Three smaller items:
- The recorder exposed two tests that stayed green only because the code under test swallowed a stray. They now fake those commands.
- `BackupRunTest` turns the stray guard back on right after its real `pg_dump --version` probe (L7b-71).
- Two `HerdSiteStepTest` cases are strengthened (L7b-21, L7b-22).

**Files:**
- Create:
  - `tests/Fixtures/RecordingProcessFactory.php` (the factory: stray list, catch-all patterns, `current()`)
  - `tests/Fixtures/RecordingPendingProcess.php` (the `PendingProcess` that reports a stray before the parent throws)
  - `tests/Feature/Tooling/TestDatabaseGuardTest.php`
- Modify:
  - `tests/TestCase.php` (the guard in `setUp()` and `databaseRefusal()`)
  - `tests/Pest.php` (the RL-12 hook binds the recorder and gains an `afterEach`; one import)
  - `tests/Feature/Setup/StrayProcessGuardTest.php` (the existing case acknowledges its stray; six new cases)
  - `tests/Feature/Setup/Steps/HerdSiteStepTest.php` (L7b-21, L7b-22)
  - `tests/Feature/Setup/Steps/DoctorStepTest.php` (fakes `node -v`, a hidden stray)
  - `tests/Feature/Console/InstallCommandTest.php` (fakes the two Herd detection commands, a hidden stray)
  - `tests/Feature/Backups/BackupRunTest.php` (`try … finally` around the probe)
  - `.ai/guidelines/skv3.md` (the Setup paragraph's stray-process sentence); then regenerate the git-ignored `AGENTS.md`
- Test:
  - `tests/Feature/Tooling/TestDatabaseGuardTest.php` (new)
  - `tests/Feature/Setup/StrayProcessGuardTest.php`
  - `tests/Feature/Setup/Steps/HerdSiteStepTest.php`

**Interfaces:**
- Consumes:
  - `Illuminate\Process\Factory` internals (laravel/framework 13.35):
    - the protected `$fakeHandlers` (pattern ⇒ handler; `fake()` stores a list value and a closure under `'*'`)
    - `newPendingProcess()`
    - `isRecording()` and `preventingStrayProcesses()`
  - `Illuminate\Process\PendingProcess`:
    - the protected `toSymfonyProcess()`, `fakeFor()` and `$factory`
    - the public `$command`
    - the stray branch of `run()` / `start()`: no fake matches while the factory records and prevents strays
  - `Illuminate\Support\Env::get()`; `Illuminate\Support\Facades\Process::swap()`
  - `App\Setup\Support\ShellArgument::quote(string $value): string`
- Produces:
  - `Tests\Fixtures\RecordingProcessFactory` (final, extends `Illuminate\Process\Factory`):
    - `public static function current(): self` — the facade's factory; throws `LogicException` outside an RL-12 test
    - `public function newPendingProcess(): PendingProcess` — returns a `RecordingPendingProcess`
    - `public function recordStray(string $command): void`
    - `public function strays(): array` (`list<string>`, Symfony command lines in order)
    - `public function forgetStrays(): void`
    - `public function catchAllPatterns(): array` (`list<string>`, the fake patterns that also match an argv nobody names)
  - `Tests\Fixtures\RecordingPendingProcess` (final, extends `PendingProcess`): `run()` and `start()` record a stray, then call the parent.
  - `Tests\TestCase::databaseRefusal(mixed $database, mixed $url, mixed $testDatabase, string $basePath): ?string` (public static): the refusal message, or null when the run may go ahead.
  - The RL-12 hook in `tests/Pest.php`:
    - `beforeEach`: `Process::swap(new RecordingProcessFactory)`, then `preventStrayProcesses()` + `fake([])`
    - `afterEach`: fails on `catchAllPatterns() !== []` or `strays() !== []`
    - the `->in('Feature/Setup', 'Feature/Console', 'Feature/Backups', 'Feature/Health', 'Feature/System')` line is unchanged, because `KitRulesTest` pins it
  - From now on, every later task's setup, console, backup, health and System test must fake every command it starts by name, including commands the code catches. Task 8's K9 change to `HerdSiteStep::isComplete()` adds `herd isolated` and `php -r` to flows whose tests fake neither; the recorder will name each one. No new global helper.

Background for the implementer:
- **Why the guard reads the environment, not config.** `RefreshDatabase` runs inside `parent::setUp()` (`setUpTraits()`), so there is no application yet. `Env::get()` reads the same repository Laravel's config reads: `$_SERVER`, `$_ENV`, then `getenv()`.
  - phpunit puts `DB_DATABASE=:memory:` there unless the shell exported a value.
  - The developer's `.env` can never change it: Dotenv is immutable, and phpunit has set the variable before Laravel loads `.env`.
  - A parallel run's `_test_test_<n>` suffix is applied to *config* later, so the environment still holds `…_test`. The regex accepts both anyway (CD-16).
  - A non-empty `DB_URL` is refused: its database would win over `DB_DATABASE` (`ConfigurationUrlParser`), and phpunit sets `DB_URL=""`.
- **Why the guard test runs a child Pest.** Only a real run proves that the check sits before `parent::setUp()`. The child gets an SQLite path in a directory that does not exist (`/nonexistent-directory/database.sqlite`). Even with the guard removed nothing can be created there: Laravel's `migrate:fresh` offers to create a missing SQLite file, and `touch()` fails in a missing directory. The parallel runner's variables (`LARAVEL_PARALLEL_TESTING`, `PARATEST`, `TEST_TOKEN`, `UNIQUE_TEST_TOKEN`) are removed (`false` unsets a variable in Symfony's Process), so the child is a plain run. `tests/Feature/Tooling` is outside the RL-12 directories and already runs PHP children (`PgsqlTestDatabaseScriptTest`).
- **Why a `PendingProcess` subclass.** `PendingProcess::run()` throws `RuntimeException('Attempted process [...] without a matching fake.')` before it records anything, so `Process::assertRan()` never sees a stray. `RecordingPendingProcess` asks the same question first, through the protected `toSymfonyProcess()` and `fakeFor()`, and tells the factory. Pools and pipes build their processes through `newPendingProcess()` too.
- **What the recorder ignores.** A factory that does not prevent strays (`DetachedStepLauncherTest` and `BackupRunTest`'s probe, the two real-process exceptions in `AGENTS.md`) records nothing, and so does a factory that is not recording.
- **The planted proofs.** The `afterEach` cannot be tested by a permanent test that fails. Step 8 plants two temporary tests that must fail and then deletes them; `StrayProcessGuardTest` pins the hook's text instead, the way `KitRulesTest` pins the `->in(...)` line.
- **Hidden strays the recorder found on HEAD** (prototype run, macOS with Homebrew node):
  - `DoctorStepTest` › "defers the Herd and token checks…": the node check found `/opt/homebrew/bin/node` on the toolchain's system PATH and ran `node -v`. The Doctor caught the stray. CI's ubuntu runner has a node on its PATH too.
  - `InstallCommandTest` › "refuses a folder name that can be no Herd address before anything runs (g3-10)": `Detector::detect()` ran the Herd stub's `services:list --json` and `herd tld`, and `Detector` caught both strays.
  - `StrayProcessGuardTest`'s existing case causes its stray on purpose.
  - A machine with other binaries on `/usr/local/bin` or `/opt/homebrew/bin` (claude, composer, git) may surface more. Fake each one by name in the test that runs it. Never relax the guard.

- [ ] **Step 1: Write the failing tests**

Replace `tests/Feature/Setup/StrayProcessGuardTest.php` with:

```php
<?php

declare(strict_types=1);

/** covers §7.2 */

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Fixtures\RecordingProcessFactory;

it('refuses a process that no fake names, also in a file without its own fake (RL-12)', function (): void {
    expect(fn () => Process::run(['true']))->toThrow(RuntimeException::class, 'without a matching fake');

    // The stray is the point of this test: the RL-12 afterEach would fail it otherwise (L7b-78).
    expect(RecordingProcessFactory::current()->strays())->toBe(["'true'"]);
    RecordingProcessFactory::current()->forgetStrays();
});

it('records every stray before it throws, so a caller that catches the exception cannot hide it (L7b-78)', function (): void {
    $factory = new RecordingProcessFactory;
    $factory->preventStrayProcesses();
    $factory->fake(["*git' 'init'*" => Process::result('')]);
    $factory->newPendingProcess()->run(['git', 'init']);

    foreach ([fn () => $factory->newPendingProcess()->run(['herd', 'links']), fn () => $factory->newPendingProcess()->start(['npm', 'ci'])] as $stray) {
        try {
            $stray();
        } catch (RuntimeException) {
            // Detector, HerdTld, HerdSiteStep::manualCommand(), DatabaseStep and the Doctor catch Throwable like this.
        }
    }

    expect($factory->strays())->toBe(["'herd' 'links'", "'npm' 'ci'"]);
});

it('names a fake that matches every command, whatever form the test wrote it in (L7b-77)', function (Closure $fakes): void {
    $factory = new RecordingProcessFactory;
    $factory->fake($fakes());

    expect($factory->catchAllPatterns())->not->toBe([]);
})->with([
    'a double star' => [fn (): array => ['**' => Process::result('')]],
    'a single star' => [fn (): array => ['*' => Process::result('')]],
    'a list value after a named key' => [fn (): array => ["*git' 'init'*" => Process::result(''), Process::result('')]],
    'any quoted command' => [fn (): array => ["*'*" => Process::result('')]],
    'a closure' => [fn (): Closure => fn () => Process::result('')],
]);

it('accepts fakes that each name a command (L7b-77)', function (): void {
    $factory = new RecordingProcessFactory;
    $factory->fake([
        "*herd' 'links'*" => Process::result(''),
        "*git' 'config' '--get' 'core.hooksPath'*" => Process::result('.githooks'),
        "*'tld'*" => Process::result('test'),
    ]);

    expect($factory->catchAllPatterns())->toBe([]);
});

it('binds the recording factory in every RL-12 test (L7b-77, L7b-78)', function (): void {
    expect(Process::getFacadeRoot())->toBeInstanceOf(RecordingProcessFactory::class)
        ->and(RecordingProcessFactory::current()->isRecording())->toBeTrue()
        ->and(RecordingProcessFactory::current()->preventingStrayProcesses())->toBeTrue();
});

it('checks every RL-12 test for catch-all fakes and strays once it ran (L7b-77, L7b-78)', function (): void {
    $pest = (string) file_get_contents(base_path('tests/Pest.php'));
    $hooks = Str::between($pest, 'pest()->beforeEach(function (): void {'."\n".'    Process::swap(new RecordingProcessFactory);', "->in('Feature/Setup'");

    expect($hooks)->toContain('})->afterEach(function (): void {')
        ->toContain('$factory = RecordingProcessFactory::current();')
        ->toContain('expect($factory->catchAllPatterns())->toBe([]')
        ->toContain('->and($factory->strays())->toBe([]');
});
```

The fixture factories call `fake()` on their own instances, never `Process::fake(`, so `KitRulesTest`'s static ban (a literal `'*'` key, a list value first) does not see the dataset. The dataset exists to prove that the runtime check catches these forms.

Create `tests/Feature/Tooling/TestDatabaseGuardTest.php`. The names avoid the kit's name, because `NameLeakTest` scans `tests/`:

```php
<?php

declare(strict_types=1);

/** covers §7.2 */

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

it('lets the tests run only on a test database (CD-16, A84a)', function (mixed $database, mixed $testDatabase, bool $allowed): void {
    $refusal = TestCase::databaseRefusal($database, '', $testDatabase, '/srv/app');

    expect($refusal === null)->toBe($allowed);
})->with([
    'SQLite in memory' => [':memory:', null, true],
    'a _test database' => ['my_app_test', null, true],
    'a parallel worker of one' => ['my_app_test_test_3', null, true],
    'CI\'s database' => ['app_test', null, true],
    'a file under storage/framework/testing' => ['/srv/app/storage/framework/testing/browser.sqlite', null, true],
    'the DB_TEST_DATABASE test:pgsql was given' => ['my_app_ci', 'my_app_ci', true],
    'the development database' => ['my_app', null, false],
    'the development database, with another DB_TEST_DATABASE' => ['my_app', 'my_app_ci', false],
    'a name that only contains _test' => ['my_app_test_backup', null, false],
    'a name with a trailing newline' => ["my_app_test\n", null, false],
    'the default SQLite file' => ['/srv/app/database/database.sqlite', null, false],
    'a path that climbs out of storage/framework/testing' => ['/srv/app/storage/framework/testing/../../../database/database.sqlite', null, false],
    'another project\'s testing folder' => ['/srv/other/storage/framework/testing/x.sqlite', null, false],
    'no DB_DATABASE' => [null, null, false],
    'an empty DB_DATABASE and DB_TEST_DATABASE' => ['', '', false],
]);

it('refuses a DB_URL, whose database would win over DB_DATABASE (CD-16)', function (): void {
    expect(TestCase::databaseRefusal(':memory:', 'pgsql://root@127.0.0.1/my_app', null, '/srv/app'))->toBeString()->toContain('DB_URL');
});

it('stops a run on the development database before the application boots (CD-16, A84a)', function (): void {
    // A real child run, as `composer test` would start it with DB_DATABASE exported. The directory does not exist, so
    // even without the guard nothing could be created; the parallel runner's variables are removed for the child.
    $result = Process::path(base_path())
        ->env([
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => '/nonexistent-directory/database.sqlite',
            'DB_URL' => '',
            'DB_TEST_DATABASE' => false,
            'LARAVEL_PARALLEL_TESTING' => false,
            'PARATEST' => false,
            'TEST_TOKEN' => false,
            'UNIQUE_TEST_TOKEN' => false,
        ])
        ->timeout(120)
        ->run([PHP_BINARY, 'vendor/bin/pest', 'tests/Feature/ExampleTest.php']);

    expect($result->exitCode())->not->toBe(0)
        ->and($result->output().$result->errorOutput())->toContain('Refusing to run the tests on the database');
});
```

The `toBeString()` matters: Pest's `toContain()` on `null` passes, so without it a guard that ignores `DB_URL` would stay green (found in the prototype's mutation run).

`tests/Feature/Setup/Steps/HerdSiteStepTest.php`: add `use App\Setup\Support\ShellArgument;` after `use App\Setup\Support\EnvFile;`. In "names the command HTTPS asks for when Herd's listings fail (RL-7)", replace the `Process::fake([...])` and the `expect(...)` line with:

```php
    // L7b-21: every listing siteState() runs is faked and only `herd links` fails, so the answer comes from that failure,
    // not from a stray the step's catch (Throwable) swallowed. The parked directory is elsewhere: without the links
    // call the name would be free and the answer `herd link` for both rows. A fake that throws is not recorded, so
    // the links fake notes its own call.
    $askedForLinks = false;
    Process::fake([
        "*herd' 'links'*" => function () use (&$askedForLinks): never {
            $askedForLinks = true;

            throw new ProcessTimedOutException(new SymfonyTimeout(new SymfonyProcess(['herd']), SymfonyTimeout::TYPE_GENERAL), Process::result(''));
        },
        "*herd' 'paths'*" => Process::result(json_encode(['/somewhere/else'], JSON_THROW_ON_ERROR)),
    ]);

    expect(new HerdSiteStep()->manualCommand($context))->toBe(sprintf($command, $context->folderName()))
        ->and($askedForLinks)->toBeTrue();
```

(The reconcile item suggested `Process::assertRan(…'links'…)`. A fake that throws is never recorded, because `resolveSynchronousFake()` throws before `recordIfRecording()`, so `assertRan` fails there even on correct code. The closure notes the call instead.)

In "quotes a folder name with a space in the commands it prints, never in the argv it runs (RL-6, g2-05)", after the existing `Process::assertRan(...)` line, add:

```php
    // L7b-22: and no command got the printed (quoted) form, whatever other command got the bare name.
    Process::assertDidntRun(fn ($process): bool => in_array(ShellArgument::quote($name), (array) $process->command, true)
        || in_array(ShellArgument::quote('--site='.$name), (array) $process->command, true));
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Setup/StrayProcessGuardTest.php tests/Feature/Tooling/TestDatabaseGuardTest.php tests/Feature/Setup/Steps/HerdSiteStepTest.php`

Expected: FAIL.
- `StrayProcessGuardTest`: every case fails with `Class "Tests\Fixtures\RecordingProcessFactory" not found`.
- `TestDatabaseGuardTest`:
  - the dataset and the DB_URL case fail with `Call to undefined method Tests\TestCase::databaseRefusal()`;
  - the child-run case fails on the output: the child has no guard, so it fails with `Database file at path [/nonexistent-directory/database.sqlite] does not exist` instead of the refusal.
- `HerdSiteStepTest` passes on HEAD code. These two cases are strengthened weak tests, proven red by mutation in Step 8 (rows 8 and 9).

- [ ] **Step 3: The recording factory**

Create `tests/Fixtures/RecordingProcessFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LogicException;

/**
 * The Process factory of every RL-12 test (tests/Pest.php), the runtime half of the static rule in
 * tests/Arch/KitRulesTest.php. It notes every stray command before PendingProcess throws, so a caller's
 * `catch (Throwable)` cannot hide one (L7b-78), and it names the fake patterns that match any command, whatever form
 * the test wrote them in (L7b-77). Both read laravel/framework 13.x internals: the protected Factory::$fakeHandlers
 * (pattern => handler; a list value and a closure are stored under '*') and PendingProcess's stray branch, which
 * RecordingPendingProcess repeats.
 */
final class RecordingProcessFactory extends Factory
{
    /** @var list<string> */
    private array $strays = [];

    /** The factory behind the Process facade; a test outside the RL-12 directories has none. */
    public static function current(): self
    {
        $factory = Process::getFacadeRoot();

        return $factory instanceof self ? $factory : throw new LogicException('The Process facade has no RecordingProcessFactory (RL-12 binds it).');
    }

    public function newPendingProcess(): PendingProcess
    {
        return new RecordingPendingProcess($this)->withFakeHandlers($this->fakeHandlers);
    }

    public function recordStray(string $command): void
    {
        $this->strays[] = $command;
    }

    /** @return list<string> the command lines that ran into the stray-process guard, in order */
    public function strays(): array
    {
        return $this->strays;
    }

    public function forgetStrays(): void
    {
        $this->strays = [];
    }

    /** @return list<string> the fake patterns that also match a command no test names */
    public function catchAllPatterns(): array
    {
        // Symfony's single-quoted command line of an argv nobody fakes by name.
        $unnamed = "'/".Str::uuid()."' '--".Str::uuid()."'";

        return array_values(array_filter(
            array_map(strval(...), array_keys($this->fakeHandlers)),
            fn (string $pattern): bool => Str::is($pattern, $unnamed),
        ));
    }
}
```

Create `tests/Fixtures/RecordingPendingProcess.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Contracts\Process\InvokedProcess as InvokedProcessContract;
use Illuminate\Contracts\Process\ProcessResult as ProcessResultContract;
use Illuminate\Process\PendingProcess;

/**
 * A PendingProcess that tells its RecordingProcessFactory about a stray command before the parent throws for it. The
 * condition is PendingProcess::run()'s and ::start()'s own (laravel/framework 13.x): no fake handler matches the
 * command line while the factory records and prevents strays.
 */
final class RecordingPendingProcess extends PendingProcess
{
    public function __construct(private readonly RecordingProcessFactory $recorder)
    {
        parent::__construct($recorder);
    }

    /** @param  array<array-key, string>|string|null  $command */
    public function run(array|string|null $command = null, ?callable $output = null): ProcessResultContract
    {
        $this->recordIfStray($command);

        return parent::run($command, $output);
    }

    /** @param  array<array-key, string>|string|null  $command */
    public function start(array|string|null $command = null, ?callable $output = null): InvokedProcessContract
    {
        $this->recordIfStray($command);

        return parent::start($command, $output);
    }

    /** @param  array<array-key, string>|string|null  $command */
    private function recordIfStray(array|string|null $command): void
    {
        $commandLine = $this->toSymfonyProcess($command ?? $this->command)->getCommandline();

        if ($this->fakeFor($commandLine) === null && $this->recorder->isRecording() && $this->recorder->preventingStrayProcesses()) {
            $this->recorder->recordStray($commandLine);
        }
    }
}
```

`start()` is typed with the contract, because a faked start returns a `FakeInvokedProcess`, which is no `Illuminate\Process\InvokedProcess`. Larastan level 8 accepts both overrides (checked in the prototype).

- [ ] **Step 4: The RL-12 hook**

`tests/Pest.php`: add `use Tests\Fixtures\RecordingProcessFactory;` after `use Tests\Fixtures\InlineStepLauncher;`, and replace the RL-12 comment and statement with:

```php
/*
| RL-12: every setup, console, backup, health and System-page test refuses a process it did not fake. Recording starts
| with fake([]): preventStrayProcesses() alone guards nothing until a fake is recording. A file's own
| Process::fake([...]) adds its named patterns. The factory is a RecordingProcessFactory, so after each test two
| runtime checks back the static ban in tests/Arch/KitRulesTest.php: no fake pattern matches a command nobody names
| (L7b-77: a '**' key, a list value after a named key, Process::fake($fakes)), and no command ran into the guard,
| even one a caller's catch (Throwable) swallowed (L7b-78).
*/
pest()->beforeEach(function (): void {
    Process::swap(new RecordingProcessFactory);
    Process::preventStrayProcesses();
    Process::fake([]);
})->afterEach(function (): void {
    $factory = RecordingProcessFactory::current();

    expect($factory->catchAllPatterns())->toBe([], 'A Process fake matches every command: fake each command by name (RL-12).')
        ->and($factory->strays())->toBe([], 'A command ran without a matching fake, even if the code caught the exception (RL-12).');
})->in('Feature/Setup', 'Feature/Console', 'Feature/Backups', 'Feature/Health', 'Feature/System');
```

`Process::swap()` also binds the instance in the container, so code that resolves `Illuminate\Process\Factory` gets the recorder too. `Process::fake()` on the facade calls `fake()` on this instance and keeps it. Pest runs `afterEach` before Laravel's `tearDown()`, so the facade still holds the recorder there (the prototype's planted tests failed with these exact messages). `useSetupWizard()` keeps calling `Process::preventStrayProcesses(); Process::fake([]);`, which now act on the recorder.

- [ ] **Step 5: The test-database guard**

`tests/TestCase.php`: add `use Illuminate\Support\Env;` and `use RuntimeException;` to the imports. Replace `setUp()` and its docblock with these two methods (Pint's `ordered_class_elements` keeps the public static method after `setUp()`):

```php
    /**
     * CD-16, A84a: phpunit.xml sets DB_DATABASE without `force`, because `composer test:pgsql` and CI pass their own, so
     * a DB_DATABASE exported in the shell wins. The check runs before parent::setUp(), which boots the application and
     * lets RefreshDatabase wipe the database it names.
     *
     * Every test starts a new request cycle, as a php-fpm worker does: Livewire keeps the `@assets` it rendered (the
     * passkey script) and other per-request state in static properties, which would otherwise leak into the HTML of
     * the next test in the same process.
     */
    protected function setUp(): void
    {
        $refusal = self::databaseRefusal(Env::get('DB_DATABASE'), Env::get('DB_URL'), Env::get('DB_TEST_DATABASE'), dirname(__DIR__));

        if ($refusal !== null) {
            throw new RuntimeException($refusal);
        }

        parent::setUp();

        Livewire::flushState();
    }

    /**
     * Why the tests must not run on this database, or null when they may (CD-16): DB_DATABASE is `:memory:`, a name
     * ending in `_test` (a parallel worker's `_test_test_<n>` too), a file under storage/framework/testing, or the
     * DB_TEST_DATABASE that `composer test:pgsql` was given. A DB_URL is refused: its database would win over
     * DB_DATABASE.
     */
    public static function databaseRefusal(mixed $database, mixed $url, mixed $testDatabase, string $basePath): ?string
    {
        if (is_string($url) && $url !== '') {
            return 'Refusing to run the tests with DB_URL set: unset it and name a test database in DB_DATABASE (CD-16).';
        }

        $name = is_string($database) ? $database : '';
        $testing = rtrim($basePath, '/').'/storage/framework/testing/';

        $safe = $name === ':memory:'
            || preg_match('/_test(_test_\d+)?\z/', $name) === 1
            || (str_starts_with($name, $testing) && ! in_array('..', explode('/', $name), true))
            || (is_string($testDatabase) && $testDatabase !== '' && $name === $testDatabase);

        return $safe ? null : sprintf(
            'Refusing to run the tests on the database [%s]: DB_DATABASE must be :memory:, end in _test, lie under storage/framework/testing or equal DB_TEST_DATABASE (CD-16).',
            $name,
        );
    }
```

Unit tests (`tests/Unit`) do not extend `Tests\TestCase` and touch no database, so the guard does not run there. The browser suite extends it and uses `:memory:`. `composer test:pgsql` passes `DB_DATABASE=${DB_TEST_DATABASE:-<folder>_test}`, which passes either way. CI's check job uses `app_test`. With `--parallel`, every worker refuses each test on its own: the prototype run with `DB_DATABASE=/nonexistent-directory/x.sqlite vendor/bin/pest --parallel tests/Feature/Dashboard` reported 8 errors, each with the refusal, and exit 2.

- [ ] **Step 6: Fake the commands the recorder found**

`tests/Feature/Setup/Steps/DoctorStepTest.php`, in "defers the Herd and token checks to after the Herd step (A6, RL-8)", directly after the `$context = setupSandbox(...)` line:

```php
    // The node check finds a node on the system PATH of most machines (CI's included); the Doctor catches a stray (L7b-78).
    Process::fake(["*node' '-v'*" => Process::result('v22.12.0')]);
```

`tests/Feature/Console/InstallCommandTest.php`, in "refuses a folder name that can be no Herd address before anything runs (g3-10)", directly after `app()->instance(SetupContext::class, $sandbox);`:

```php
    // Detection asks the Herd stub before the folder check; Detector catches a stray, so both are faked (L7b-78).
    Process::fake([
        "*herd' 'services:list' '--json'*" => Process::result('Herd Pro is required'),
        "*herd' 'tld'*" => Process::result('test'),
    ]);
```

Then run the five RL-12 directories: `php artisan test --compact tests/Feature/Setup tests/Feature/Console tests/Feature/Backups tests/Feature/Health tests/Feature/System`. A failure that reads "A command ran without a matching fake, even if the code caught the exception (RL-12)" names a hidden stray this machine's PATH exposes. Fake that command by name in that test, as above, and list it in the task report. Never widen a fake to a catch-all, and never turn the guard off.

- [ ] **Step 7: BackupRunTest's probe**

`tests/Feature/Backups/BackupRunTest.php`: in `$usablePgDump`, replace the comment block, `Process::preventStrayProcesses(false);` and the `preg_match(... Process::env(...)->run([$path, '--version']) ...)` line with:

```php
    // The one test that runs a real pg_dump (AGENTS.md › Ops): its version probe runs for real, not faked (RL-12), and
    // the guard is back on for the rest of the test (L7b-71). F19: through Process with the toolchain's environment,
    // never exec(). The package's pg_dump runs through Symfony's Process directly, so the facade's fake never touches it.
    Process::preventStrayProcesses(false);

    try {
        preg_match('/(\d+)(?:\.\d+)?/', Process::env($toolchain->environment())->run([$path, '--version'])->output(), $client);
    } finally {
        Process::preventStrayProcesses();
    }

```

The probe runs only on PostgreSQL with a usable `pg_dump` (the closure returns null on SQLite before it gets here), so Step 8's mutation 10 runs under `composer test:pgsql`. Task 13 later adds the `REQUIRE_POSTGRES` skip to this file.

- [ ] **Step 8: Run the tests to verify they pass, and verify by mutation**

Run: `php artisan test --compact tests/Feature/Setup tests/Feature/Console tests/Feature/Backups tests/Feature/Health tests/Feature/System tests/Feature/Tooling tests/Arch`

Expected: PASS. The prototype ran the whole SQLite suite (2725 tests) and the modules-off run green.

Then plant the two proofs of the `afterEach` (C117, C118). Create the temporary file `tests/Feature/Setup/PlantedGuardTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

it('plants a catch-all the static rule misses', function (): void {
    $fakes = ['**' => Process::result('')];
    Process::fake($fakes);

    expect(Process::run(['anything'])->successful())->toBeTrue();
});

it('plants a stray that a caller swallows', function (): void {
    try {
        Process::run(['herd', 'links']);
    } catch (RuntimeException) {
    }

    expect(true)->toBeTrue();
});
```

Run `php artisan test --compact tests/Feature/Setup/PlantedGuardTest.php`. Expect both to fail: the first with "A Process fake matches every command…", the second with "A command ran without a matching fake, even if the code caught the exception…". With HEAD's `tests/Pest.php` both pass (prototype). Then delete the file.

Next, revert or break each change locally, run the named test, expect it to fail, then restore. Never use `git stash`: copy the file aside (`cp <file> <scratchpad>/<name>.bak`), edit it, and restore it with `cp`.

| # | Mutation | Test that must fail |
|---|---|---|
| 1 | `TestCase::setUp()`: delete the `if ($refusal !== null) { throw … }` block | `TestDatabaseGuardTest` › 'stops a run on the development database…' (the child fails on the missing SQLite file instead) |
| 2 | `databaseRefusal()`: regex `'/_test/'` | 'lets the tests run only on a test database' › 'a name that only contains _test', 'a name with a trailing newline' |
| 3 | `databaseRefusal()`: drop `&& ! in_array('..', explode('/', $name), true)` | › 'a path that climbs out of storage/framework/testing' |
| 4 | `databaseRefusal()`: `if (false) {` instead of the DB_URL check | 'refuses a DB_URL…' |
| 5 | `tests/Pest.php`: delete the `->afterEach(...)` closure | `StrayProcessGuardTest` › 'checks every RL-12 test for catch-all fakes and strays once it ran' (and the planted file passes again) |
| 6 | `tests/Pest.php`: delete `Process::swap(new RecordingProcessFactory);` | › 'binds the recording factory in every RL-12 test' |
| 7 | `RecordingPendingProcess::run()`: delete `$this->recordIfStray($command);` | › 'refuses a process that no fake names…' and › 'records every stray before it throws…' |
| 8 | `RecordingProcessFactory::catchAllPatterns()`: filter `fn (string $pattern): bool => $pattern === '*'` | › 'names a fake that matches every command…' › 'a double star', 'any quoted command' |
| 9 | `app/Setup/Steps/HerdSiteStep.php` `siteState()`: `$linkPath = null;` instead of the `links` lookup | `HerdSiteStepTest` › 'names the command HTTPS asks for when Herd's listings fail' (both rows; passes with HEAD's test) |
| 10 | `HerdSiteStep::run()`: `['link', $name, ShellArgument::quote($name)]` | `HerdSiteStepTest` › 'quotes a folder name with a space…' › 'herd link' (passes with HEAD's test) |
| 11 | `BackupRunTest.php`: inside the test body, after `$usablePgDump()` in `config([...])`, add `Process::run(['true']);` | under `composer test:pgsql`, 'backs up the database…' fails with "without a matching fake" (with HEAD's file it ran `true` for real). Skipped on SQLite. |

The prototype ran rows 1–10 and saw each one fail (row 4 only after the `toBeString()` fix). Row 11 needs PostgreSQL; it was not run.

- [ ] **Step 9: The guideline**

`.ai/guidelines/skv3.md`, `## Setup`, the external-commands bullet: replace the sentences from "`tests/Arch/KitRulesTest.php` catches only the literal forms" to the end of the bullet ("…`Feature/Health` and `Feature/System`.") with:

```markdown
`tests/Arch/KitRulesTest.php` catches the literal forms as text (a bare call, a closure, an array that starts with a list value, a literal `'*'` or `"*"` key before the statement's first `;`). `tests/Pest.php` applies the guard to every test under `Feature/Setup`, `Feature/Console`, `Feature/Backups`, `Feature/Health` and `Feature/System`, on a `Tests\Fixtures\RecordingProcessFactory`: after each of those tests it fails the test when a fake pattern matches a command nobody names (a `'**'` key, a list value after a named key, `Process::fake($fakes)`) or when a command ran into the guard, even one the code caught (`catch (Throwable)` in `Detector`, `HerdTld`, the steps and the doctor). A test that starts a stray on purpose checks `RecordingProcessFactory::current()->strays()` and calls `forgetStrays()`.
```

Then run `php artisan boost:update` to regenerate the git-ignored `AGENTS.md`, and check that it shows the new sentence. Task 1 adds no lang key, no CHANGELOG line (the changes are test-only; Task 17's consistency pass decides whether `### Security` names the database guard) and no README section.

- [ ] **Step 10: The gate, then commit**

```bash
composer fix
vendor/bin/phpstan analyse tests/Fixtures/RecordingProcessFactory.php tests/Fixtures/RecordingPendingProcess.php tests/TestCase.php tests/Pest.php tests/Feature/Setup/StrayProcessGuardTest.php tests/Feature/Tooling/TestDatabaseGuardTest.php tests/Feature/Setup/Steps/HerdSiteStepTest.php
composer check
composer test:pgsql
php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser
npm run build && composer test:browser
npm run build:setup && git diff --exit-code resources/setup/setup.css
php artisan config:clear
```

`composer test:pgsql` is where the guard meets a real `DB_DATABASE` (`<folder>_test`) and where `BackupRunTest`'s probe runs. Expect it green, and run mutation 11 there. The browser suite goes through the new `setUp()` with `:memory:`. Task 1 changes no setup view, so expect no stylesheet diff.

```bash
git add tests/Fixtures/RecordingProcessFactory.php tests/Fixtures/RecordingPendingProcess.php tests/TestCase.php tests/Pest.php \
    tests/Feature/Setup/StrayProcessGuardTest.php tests/Feature/Tooling/TestDatabaseGuardTest.php \
    tests/Feature/Setup/Steps/HerdSiteStepTest.php tests/Feature/Setup/Steps/DoctorStepTest.php \
    tests/Feature/Console/InstallCommandTest.php tests/Feature/Backups/BackupRunTest.php .ai/guidelines/skv3.md
git commit -m "test: refuse a non-test database and record stray processes behind caught exceptions

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Add any further test file that Step 6 had to change to the `git add` line.

---

---

### Task 2: Kit-rule and arch guards (§6.2, §6.3, §6.5, §7.2; DEC-28, DEC-29; A5, A6, A8, A25 (FU2-11, FU2-12, FU3-16), D77 (M7c T13 #6), C110=D109 (L7b-70), C141=D116 (G3-08))

Seven kit rules are stated in `AGENTS.md`, the spec or a DEC, but no test (or only a weak one) holds them:
- **A5.** The "strict mode is on by default" test reads `config('tenancy.strict')`, which `phpunit.xml` forces to `true`. It now re-reads `config/tenancy.php` with `TENANCY_STRICT` cleared.
- **A6.** The debug-call text rule now also catches a namespaced `\dd()` and the `dd*`/`dump*` helpers (`->ddHeaders()`, `->dumpSession()`, `->ddRawSql()`). It also scans Blade, the Livewire component classes and the route files, with a self-check like `TenancyRulesTest`'s.
- **A8.** The permission-sync idempotency test also proves that a second run (and the run after a drift repair) leaves every row of the four tables it owns as it was and issues no INSERT or UPDATE. A detach-and-reattach on every run is invisible in the rows but not in the writes.
- **A25.** `CentralContext` (strict mode off while active) may appear only in `app/Tenancy` and `TenancyServiceProvider`.
- **D77.** Nothing outside `SetSuperAdmin` writes `users.is_super_admin`. Factories are exempt; `InviteUser`'s `false` on create stays allowed.
- **C110.** The F19 exec ban also scans `bootstrap/` (without the cache) and `scripts/`, and bans shell-string commands: `fromShellCommandline()`, and `Process::run|start|command()` with a string.
- **C141.** `lang/{nl,en}.json` never hold the copy of the Flux components Blaze folds (DEC-29, G3-08).

Each rule gets self-check samples, and the step that adds it plants an offender that today's suite lets through.

**Files:**
- Modify:
  - `tests/Feature/Tenancy/StrictModeTest.php` ('is on by default' rewritten and retitled; A5)
  - `tests/Arch/KitRulesTest.php` (the debug rule, the exec rule, one comment; A6, C110; one import)
  - `tests/Feature/Authorization/SyncPermissionsTest.php` ('is idempotent' strengthened and retitled, one new case, a file-local closure; A8; four imports)
  - `tests/Arch/TenancyRulesTest.php` (one new case; A25)
  - `tests/Arch/AuthorizationRulesTest.php` (one new case; D77)
  - `tests/Feature/LangCoverageTest.php` (one new case; C141)
- Test: the six files above (tests only; no production code changes).

**Interfaces:**
- Consumes:
  - `kitSourceFiles(string ...$directories): array` and `filesMatching(array $paths, string $pattern): array` (`tests/Pest.php`)
  - `App\Authorization\SyncPermissions::handle(): SyncResult` and `SyncResult::changed(): bool`
  - `App\Authorization\PermissionsTeam::run()`; `User::factory()->superAdmin()`
  - Task 1's `RecordingProcessFactory::catchAllPatterns()`, named only in a comment
- Produces:
  - no production code and no global helper (`$syncAgain` is a file-local closure)
  - the rules the later tasks must keep:
    - no debug helper in `tests`, `database/migrations`, `resources/views` or `routes`
    - no shell-string command and no exec family in `app`, `tests`, `routes`, `database`, `config`, `resources/views`, `bootstrap` (minus `bootstrap/cache`) or `scripts`
    - `CentralContext` only in `app/Tenancy/` and `app/Providers/TenancyServiceProvider.php`
    - `is_super_admin` written only in `app/Authorization/SetSuperAdmin.php`
    - none of the eight folded Flux keys in `lang/*.json`

Background for the implementer:
- **Re-verified on HEAD 5a621c3.** None of the seven guards exists, and none of the widened scans finds an offender today:
  - the widened debug scan
  - `bootstrap/` and `scripts/` for the exec family
  - shell strings anywhere
  - `CentralContext` outside the two places (it is used in `TenancyServiceProvider.php:52,85,88`, `JobTenancy.php:43,83` and `TenantScope.php:33`)
  - `is_super_admin` writes outside `SetSuperAdmin.php`: `InviteUser.php:108` writes `false` inside `forceFill([...])` and must stay allowed
  - the folded keys in `lang/*.json`

  So each rule passes once added, and its RED proof is a planted offender (Step 8). The prototype also restored today's patterns inside the new tests: each self-check then failed on its first new form.
- **A5 needs the environment cleared everywhere `env()` reads.** `phpunit.xml`'s `force="true"` puts `TENANCY_STRICT=true` in `putenv()` and `$_ENV`, and a shell can put it in `$_SERVER`. The test clears all three, `require`s `config/tenancy.php` (as `ActivityRetentionTest` does for `activitylog.php`), and puts all three back in a `finally`. The second row shows `TENANCY_STRICT=false` still turns strict mode off.
- **A6's lookbehind** drops `\` (so `\dd(` counts) and adds `-` (so copy like "X-ray (beta)" in a Blade file is not a `ray(` call). `@dd(` and `@dump(` in Blade match the plain branch.
- **C110 works on tokens, not text**, as the exec rule already does. A string as the first argument of `Process::run`, `::start` or `::command` goes through `Symfony\Component\Process\Process::fromShellCommandline()` (`PendingProcess::toSymfonyProcess()`), so the shell parses it. An argv array never goes through a shell. The rule sees the static facade call only: `Process::path($dir)->run('ls')` is not caught; the plan accepts that, since no kit code builds a string command. `$schedule->command('backup:run')` is a member call and is not flagged.
- **A25 bans the class name, not just `->enter(`**: an import or a two-step `$context = app(CentralContext::class); $context->enter();` is the same escape. The rule is separate from the bypass rule, whose allow-list includes `app/Console`, `app/Setup` and the seeders, where entering the central context is not allowed.
- **D77's lookahead is possessive** (`\s*+(?!false\b)`). With a plain `\s*` the engine gives back a space and the lookahead passes, so `InviteUser`'s `'is_super_admin' => false` would be flagged; the prototype hit exactly that.
- **A8 compares rows, not counts.** Each table is read whole and sorted by its JSON encoding, because `role_has_permissions` and `model_has_roles` have no id. A listener records every `insert`/`update` statement of the second run. Today that run issues only DELETEs that remove nothing (the badge's `syncPermissions([])`, the super-only detach, the badge repair) and SELECTs; `insert([])` sends no query.
- **C141's list** is the eight keys DEC-29 calls folded: the password visibility, clear, copy, file-input and OTP labels, and "Close modal". The free Flux stubs also use "Search...", "Showing", "of", "to", "results", "Toggle sidebar" and "pagination.*". Those render at runtime and are translated on purpose, so they are not in the list.

- [ ] **Step 1: StrictModeTest (A5)**

In `tests/Feature/Tenancy/StrictModeTest.php`, replace the test `it('is on by default', …)` with:

```php
it('is on by default, and TENANCY_STRICT=false turns it off (A5, FU2-12)', function (?string $value, bool $strict): void {
    // phpunit.xml forces TENANCY_STRICT=true, so config('tenancy.strict') says nothing about the shipped default: the
    // variable is cleared in every place env() reads (and set for the off row), the config file is read again, and the
    // variable is put back afterwards.
    $saved = [$_ENV['TENANCY_STRICT'] ?? null, $_SERVER['TENANCY_STRICT'] ?? null, getenv('TENANCY_STRICT')];
    unset($_ENV['TENANCY_STRICT'], $_SERVER['TENANCY_STRICT']);
    putenv('TENANCY_STRICT');

    if ($value !== null) {
        $_ENV['TENANCY_STRICT'] = $_SERVER['TENANCY_STRICT'] = $value;
        putenv('TENANCY_STRICT='.$value);
    }

    try {
        /** @var array{strict: mixed} $config */
        $config = require config_path('tenancy.php');
    } finally {
        unset($_ENV['TENANCY_STRICT'], $_SERVER['TENANCY_STRICT']);
        putenv('TENANCY_STRICT');

        if ($saved[0] !== null) {
            $_ENV['TENANCY_STRICT'] = $saved[0];
        }

        if ($saved[1] !== null) {
            $_SERVER['TENANCY_STRICT'] = $saved[1];
        }

        if ($saved[2] !== false) {
            putenv('TENANCY_STRICT='.$saved[2]);
        }
    }

    expect($config['strict'])->toBe($strict)
        ->and(config('tenancy.strict'))->toBeTrue();
})->with([
    'TENANCY_STRICT unset' => [null, true],
    'TENANCY_STRICT=false' => ['false', false],
]);
```

Run: `php artisan test --compact tests/Feature/Tenancy/StrictModeTest.php`. Expect PASS. Then mutate `config/tenancy.php:25` to `'strict' => (bool) env('TENANCY_STRICT', false),` and expect the 'TENANCY_STRICT unset' row to fail with `false` instead of `true`. HEAD's test stays green under that mutation (prototype). Restore the file.

- [ ] **Step 2: The debug rule (A6)**

In `tests/Arch/KitRulesTest.php`, add `use Illuminate\Support\Str;` after the `/** covers §7.2 */` docblock (with a blank line on both sides), and replace `it('has no debugging leftovers in tests and migrations', …)` with:

```php
it('has no debugging leftovers in tests, migrations, views and routes (FU2-12)', function (): void {
    // The arch rule above sees namespaced classes only: Livewire's multi-file classes, Blade (@dd, @dump), route files
    // and anonymous migrations are scanned as text. A namespaced \\dd() counts, and so do the dd and dump helpers of
    // responses, queries and collections (->ddHeaders(), ->dumpSession(), ->ddRawSql()).
    $pattern = '/(?<![\w$>:-])(dd|dump|ray|var_dump)\s*\(|->(dd|dump)\w*\s*\(/';

    foreach (['dd($user);', '\\dd($user);', 'dump($user);', '\\dump($user);', 'ray($user);', 'var_dump($user);', '@dd($user)', '$response->ddHeaders();', '$response->dumpSession();', '$query->ddRawSql();', '$users->dump();'] as $form) {
        expect(preg_match($pattern, $form))->toBe(1, "The debug rule misses [{$form}].");
    }

    foreach (['$this->address($user);', 'array($user);', 'Str::ddx($user);', 'X-ray (beta)', '$query->toRawSql();'] as $form) {
        expect(preg_match($pattern, $form))->toBe(0, "The debug rule flags [{$form}].");
    }

    $files = array_values(array_diff(kitSourceFiles('tests', 'database/migrations', 'resources/views', 'routes'), ['tests/Arch/KitRulesTest.php']));

    expect(filesMatching($files, $pattern))->toBe([]);
});
```

- [ ] **Step 3: The exec rule (C110)**

In the same file, replace `it('starts external commands only through Process: no exec() and friends, no backticks (F19)', …)` with:

```php
it('starts external commands only through Process: no exec() and friends, no backticks, no shell strings (F19, L7b-70)', function (): void {
    $banned = ['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec'];

    /** @return list<string> */
    $offendersIn = function (string $code) use ($banned): array {
        // Tokens, not text: comments, strings and docblocks may name these functions and use backticks.
        $tokens = array_values(array_filter(PhpToken::tokenize($code), fn (PhpToken $token): bool => ! $token->isIgnorable()));
        $offenders = [];

        foreach ($tokens as $index => $token) {
            if ($token->text === '`') {
                $offenders[] = 'backticks';

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
                $offenders[] = $token->text.'()';
            }

            // L7b-70: a string command runs through a shell (Symfony's Process::fromShellCommandline()); an argv never does.
            if ($token->is(T_STRING) && $token->text === 'fromShellCommandline') {
                $offenders[] = 'fromShellCommandline()';
            }

            $class = $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) ? Str::afterLast($token->text, '\\') : null;
            $method = $tokens[$index + 2] ?? null;
            $argument = $tokens[$index + 4] ?? null;

            if ($class === 'Process' && $next?->is(T_DOUBLE_COLON) === true
                && $method instanceof PhpToken && in_array($method->text, ['run', 'start', 'command'], true)
                && ($tokens[$index + 3] ?? null)?->text === '('
                && $argument instanceof PhpToken && ($argument->is([T_CONSTANT_ENCAPSED_STRING, T_START_HEREDOC]) || $argument->text === '"')) {
                $offenders[] = 'Process::'.$method->text.'() with a string';
            }
        }

        return $offenders;
    };

    foreach (['exec(\'ls\');', '\\shell_exec(\'ls\');', '$out = `ls`;', 'Symfony\\Component\\Process\\Process::fromShellCommandline(\'ls\');', 'Process::run(\'ls -la\');', 'Process::start("npm run {$script}");', '\\Illuminate\\Support\\Facades\\Process::command(\'ls\');'] as $form) {
        expect($offendersIn('<?php '.$form))->not->toBe([], "The rule misses [{$form}].");
    }

    foreach (['Process::run([\'ls\', \'-la\']);', '$schedule->command(\'backup:run\');', '$runner->exec($step);', 'Process::path($dir)->run([$herd, \'links\']);', '// exec(\'ls\') in a comment'] as $form) {
        expect($offendersIn('<?php '.$form))->toBe([], "The rule flags [{$form}].");
    }

    // resources/views holds the multi-file components' classes, the System page's among them (M3); Blade is no PHP.
    // bootstrap/ (without the compiled cache) and scripts/ run kit code too (L7b-70).
    $files = array_filter(
        kitSourceFiles('app', 'tests', 'routes', 'database', 'config', 'resources/views', 'bootstrap', 'scripts'),
        fn (string $path): bool => ! str_ends_with($path, '.blade.php') && ! str_starts_with($path, 'bootstrap/cache/'),
    );
    $offenders = [];

    foreach ($files as $path) {
        foreach ($offendersIn((string) file_get_contents(base_path($path))) as $offender) {
            $offenders[] = $path.': '.$offender;
        }
    }

    expect(array_values(array_unique($offenders)))->toBe([]);
});
```

In `it('fakes processes by name, never with a catch-all, …')`, replace the last two comment lines ("This catches the literal forms only: … (.ai/guidelines/skv3.md names what passes, M2).") with:

```php
    // This catches the literal forms only: a bare call, a closure, an array that starts with a list value, and a
    // literal '*' key before the statement's first ';' (M2). The rest ('**', a list value after a named key,
    // Process::fake($fakes)) fails at runtime: RecordingProcessFactory::catchAllPatterns() in tests/Pest.php (L7b-77).
```

Run: `php artisan test --compact tests/Arch/KitRulesTest.php`. Expect PASS: no file in the widened scans offends today. `scripts/create-pgsql-test-database.php` uses PDO, and `scripts/*.sh` is not PHP, which `kitSourceFiles()` skips.

- [ ] **Step 4: SyncPermissionsTest (A8)**

In `tests/Feature/Authorization/SyncPermissionsTest.php`, add these imports in order: `use App\Models\User;` after `use App\Models\Organisation;`; `use Illuminate\Database\Events\QueryExecuted;` before `use Illuminate\Support\Facades\Artisan;`; `use Illuminate\Support\Facades\DB;` after it. Then replace `it('is idempotent', …)` with the closure and the two tests:

```php
/*
| The rows the sync owns and the writes a run issues (FU2-11): an idempotent run reports no change, leaves the rows as
| they were (ids and timestamps included) and inserts or updates nothing, so a detach-and-reattach on every run, which
| leaves the same pivot rows behind, is caught too.
*/
$syncAgain = function (): array {
    $rows = fn (): array => collect(['permissions', 'roles', 'role_has_permissions', 'model_has_roles'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->get()->map(fn (object $row): array => (array) $row)->sortBy(fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR))->values()->all()])
        ->all();
    $before = $rows();
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    $changed = app(SyncPermissions::class)->handle()->changed();

    return ['changed' => $changed, 'before' => $before, 'after' => $rows(), 'writes' => $writes];
};

it('is idempotent: a second run changes no row and writes nothing (FU2-11)', function () use ($syncAgain): void {
    $run = $syncAgain();

    expect($run['changed'])->toBeFalse()
        ->and($run['after'])->toBe($run['before'])
        ->and($run['writes'])->toBe([])
        ->and(RoleModel::query()->whereNull('team_id')->count())->toBe(2);
});

it('reports nothing left to repair on the run after a drift repair (FU2-11)', function () use ($syncAgain): void {
    PermissionModel::query()->where('name', Permission::UsersView->value)->delete();
    systemRole(Role::OrganisationAdmin)->revokePermissionTo(Permission::RolesView->value);
    $badge = systemRole(Role::SuperAdmin);
    PermissionsTeam::run(null, fn (): RoleModel => $badge->givePermissionTo(Permission::RolesView->value));
    // A flagged user without the badge: the factory writes the flag directly, as an old import would.
    User::factory()->superAdmin()->create();

    expect(app(SyncPermissions::class)->handle()->changed())->toBeTrue();

    $run = $syncAgain();

    expect($run['changed'])->toBeFalse()
        ->and($run['after'])->toBe($run['before'])
        ->and($run['writes'])->toBe([]);
});
```

The file's existing `function systemRole()` stays. The new helper is a closure, so it adds no global function (Global Constraints).

Run: `php artisan test --compact tests/Feature/Authorization/SyncPermissionsTest.php`. Expect PASS.

- [ ] **Step 5: The central-context rule (A25)**

In `tests/Arch/TenancyRulesTest.php`, insert before `it('keeps driver-specific SQL out of the app', …)`:

```php
it('enters the central context only in the tenancy layer (FU3-16, A25)', function (): void {
    // CentralContext switches strict mode off for everything that runs while it is active (TenantScope), like a bypass
    // that never ends. It is entered for Artisan commands (TenancyServiceProvider) and queued jobs (JobTenancy); any
    // other mention of the class, an import included, is a way to enter it.
    $allowed = ['app/Tenancy/', 'app/Providers/TenancyServiceProvider.php'];
    $pattern = '/\bCentralContext\b/';

    foreach (['app(CentralContext::class)->enter();', '$this->app->make(\App\Tenancy\CentralContext::class)->enter();', 'use App\Tenancy\CentralContext;', "resolve('App\\Tenancy\\CentralContext')->enter();"] as $form) {
        expect(preg_match($pattern, $form))->toBe(1, "The central-context rule misses [{$form}].");
    }

    $candidates = array_values(array_filter(
        kitSourceFiles('app', 'routes', 'resources/views', 'database', 'bootstrap', 'config'),
        fn (string $path): bool => ! Str::startsWith($path, $allowed) && ! str_starts_with($path, 'bootstrap/cache/'),
    ));

    expect(filesMatching($candidates, $pattern))->toBe([]);
});
```

(`Str` is already imported there.)

- [ ] **Step 6: The super-admin write rule (D77)**

Append to `tests/Arch/AuthorizationRulesTest.php`:

```php
it('writes users.is_super_admin only in SetSuperAdmin (§6.3, M7c T13 #6)', function (): void {
    // L7b-39 pins who calls SetSuperAdmin; this pins that nothing else writes the flag: no `true` for it, no property
    // assignment, and no value other than `false` in a write (InviteUser creates invitees with false). Factories are
    // test data and exempt.
    $pattern = '/[\'"]is_super_admin[\'"]\s*=>\s*(?:true|1)\b'
        .'|->is_super_admin\s*=(?!=)'
        .'|\b(?:update|forceFill|fill|insert|insertGetId|insertOrIgnore|upsert|updateOrInsert|updateOrCreate|firstOrCreate|forceCreate)\s*\([^;]*?[\'"]is_super_admin[\'"]\s*=>\s*+(?!false\b)'
        .'|setAttribute\s*\(\s*[\'"]is_super_admin[\'"]\s*,\s*+(?!false\b)/s';

    foreach ([
        '$user->forceFill([\'is_super_admin\' => true])->save();',
        'DB::table(\'users\')->where(\'id\', $id)->update([\'is_super_admin\' => $flag]);',
        "User::query()->whereKey(\$id)->update([\n    'name' => \$name,\n    'is_super_admin' => \$flag,\n]);",
        '$user->is_super_admin = true;',
        '$user->setAttribute(\'is_super_admin\', $flag);',
        '$user->fill([\'is_super_admin\' => 1]);',
        'User::forceCreate([\'email\' => $email, \'is_super_admin\' => $flag]);',
    ] as $form) {
        expect(preg_match($pattern, $form))->toBe(1, "The super-admin write rule misses [{$form}].");
    }

    foreach ([
        '$invitee->forceFill([\'organisation_id\' => $id, \'is_super_admin\' => false, \'status\' => $status])->save();',
        'return $this->is_super_admin === true;',
        'User::query()->where(\'is_super_admin\', true)->exists();',
        '\'is_super_admin\' => __(\'Cancel the invitation and invite again with the new roles.\'),',
        '->lockForUpdate()->get([\'id\', \'email\', \'is_super_admin\']);',
        '$table->boolean(\'is_super_admin\')->default(false)->index();',
        '\'is_super_admin\' => \'boolean\',',
    ] as $form) {
        expect(preg_match($pattern, $form))->toBe(0, "The super-admin write rule flags [{$form}].");
    }

    $files = array_values(array_filter(
        kitSourceFiles('app', 'routes', 'bootstrap', 'config', 'database/migrations', 'database/seeders', 'resources/views'),
        fn (string $path): bool => $path !== 'app/Authorization/SetSuperAdmin.php' && ! str_starts_with($path, 'bootstrap/cache/'),
    ));

    expect(filesMatching($files, $pattern))->toBe([]);
});
```

(`Str` is already imported; the new case does not need it.)

- [ ] **Step 7: The folded Flux keys (C141)**

Append to `tests/Feature/LangCoverageTest.php`:

```php
it('never translates the copy inside the Flux components Blaze folds (G3-08, DEC-29)', function (string $locale): void {
    // Blaze runs a folded component's __() once, when the view compiles, and memoises the result: a translation here
    // would show the first language to compile to everyone. The labels stay English for every locale instead.
    $folded = ['Toggle password visibility', 'Clear input', 'Copy to clipboard', 'Choose file', 'Choose files', 'No file chosen', 'Close modal', 'Character {current} of {total}'];
    /** @var array<string, string> $translations */
    $translations = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);

    expect(array_values(array_intersect($folded, array_keys($translations))))->toBe([]);
})->with(['nl', 'en']);
```

Run: `php artisan test --compact tests/Arch tests/Feature/LangCoverageTest.php tests/Feature/Tenancy/StrictModeTest.php tests/Feature/Authorization/SyncPermissionsTest.php`. Expect PASS.

- [ ] **Step 8: Verify by mutation and by planted offenders**

Each rule passes on today's tree, so its RED proof is a planted offender that HEAD's tests let through and the new rule catches. Create these temporary files:

`tests/Feature/PlantedDebugTest.php`:

```php
<?php

declare(strict_types=1);

it('plants debug calls', function (): void {
    $this->get('/')->ddHeaders();
    \dd(1);
});
```

`resources/views/components/planted-debug.blade.php`:

```blade
<div>@dump($user)</div>
```

`scripts/planted-exec.php`:

```php
<?php

declare(strict_types=1);

exec('ls');
```

`app/Support/PlantedShell.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

final class PlantedShell
{
    public function run(): void
    {
        Process::run('ls -la');
        SymfonyProcess::fromShellCommandline('ls')->run();
    }
}
```

`app/Support/PlantedEscape.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use App\Tenancy\CentralContext;

final class PlantedEscape
{
    public function run(User $user): void
    {
        app(CentralContext::class)->enter();
        $user->forceFill(['is_super_admin' => true])->save();
    }
}
```

Run `php artisan test --compact tests/Arch/KitRulesTest.php tests/Arch/TenancyRulesTest.php tests/Arch/AuthorizationRulesTest.php`. Expect exactly four failures:
- 'has no debugging leftovers in tests, migrations, views and routes' (the planted test file and Blade view)
- 'starts external commands only through Process…' (`scripts/planted-exec.php`, `app/Support/PlantedShell.php`)
- 'enters the central context only in the tenancy layer'
- 'writes users.is_super_admin only in SetSuperAdmin'

With HEAD's three files (`git show HEAD:tests/Arch/<file> > tests/Arch/<file>`, then copy yours back) all pass: the prototype saw `passed`. Delete the five planted files, and run `php artisan view:clear` because a Blade file was added and removed.

Then the per-rule mutations. Copy each file aside with `cp`, edit it, run the named test, and restore it with `cp`; never `git stash`.

| # | Mutation | Test that must fail |
|---|---|---|
| 1 | `config/tenancy.php`: `env('TENANCY_STRICT', false)` | `StrictModeTest` › 'is on by default…' › 'TENANCY_STRICT unset' (HEAD's test passes) |
| 2 | `KitRulesTest` debug rule: put HEAD's pattern `'/(?<![\w$>:\\\\])(dd\|dump\|ray\|var_dump)\s*\(\|->(dd\|dump)\s*\(/'` back | the debug rule's self-check: "The debug rule misses [\dd($user);]" |
| 3 | `KitRulesTest` exec rule: `if (false) {` for the `fromShellCommandline` check | the exec rule's self-check: "The rule misses [Symfony\Component\Process\Process::fromShellCommandline('ls');]" |
| 4 | `KitRulesTest` exec rule: `false` instead of `in_array($method->text, ['run', 'start', 'command'], true)` | the self-check: "The rule misses [Process::run('ls -la');]" |
| 5 | `KitRulesTest` exec rule: drop `'bootstrap', 'scripts'` from the scan | the self-check stays green; with `scripts/planted-exec.php` planted the rule passes again (HEAD's blind spot), so this row is proven by the planted file |
| 6 | `app/Authorization/SyncPermissions.php` `syncOrganisationAdmin()`: call `$template->syncPermissions($wanted);` before `return false;` in the equal branch | 'is idempotent: a second run changes no row and writes nothing' and 'reports nothing left to repair…' (the writes list holds the pivot INSERT); HEAD's 'is idempotent' passes |
| 7 | `SyncPermissions::upsertPermissions()`: an `else` branch that runs `->update(['updated_at' => now()])` on each existing permission | the same two tests; HEAD's test passes |
| 8 | `AuthorizationRulesTest`: `\s*(?!false\b)` instead of `\s*+(?!false\b)` (both places) | the rule's self-check: "The super-admin write rule flags [$invitee->forceFill([… 'is_super_admin' => false …])…]" |
| 9 | `AuthorizationRulesTest`: drop the `->is_super_admin\s*=(?!=)` alternative | the self-check: "misses [$user->is_super_admin = true;]" |
| 10 | `lang/nl.json`: add `"Close modal": "Venster sluiten"` in sort order | `LangCoverageTest` › 'never translates the copy inside the Flux components Blaze folds' › 'nl' |

The prototype ran all ten, plus the planted files, and each failed as named (row 5 by the planted file).

- [ ] **Step 9: The gate, then commit**

```bash
composer fix
vendor/bin/phpstan analyse tests/Arch/KitRulesTest.php tests/Arch/TenancyRulesTest.php tests/Arch/AuthorizationRulesTest.php tests/Feature/Authorization/SyncPermissionsTest.php tests/Feature/LangCoverageTest.php tests/Feature/Tenancy/StrictModeTest.php
composer check
composer test:pgsql
php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser
npm run build && composer test:browser
npm run build:setup && git diff --exit-code resources/setup/setup.css
php artisan config:clear
```

`composer fix` runs Rector, which wants the explicit `fn (): RoleModel => …` return type in the new drift test; the code above has it. `SyncPermissionsTest` reads whole tables, so on PostgreSQL the timestamps come back as strings, the same before and after. That the second run writes nothing there too is checked only by `composer test:pgsql`; the prototype ran SQLite only. Task 2 adds no lang key, no CHANGELOG line, no README section and no guideline line. Task 17 carries the guideline sentences (see that task's notes).

```bash
git add tests/Feature/Tenancy/StrictModeTest.php tests/Arch/KitRulesTest.php tests/Feature/Authorization/SyncPermissionsTest.php \
    tests/Arch/TenancyRulesTest.php tests/Arch/AuthorizationRulesTest.php tests/Feature/LangCoverageTest.php
git commit -m "test: pin the strict default, sync idempotency and five kit rules no test guarded

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

---

### Task 3: The address-lookup limit (FM-18 = DEC-34 (16), DEC-35 Consequence, §4.2, §4.5; CD-2; D82 = E1, D80)

Four pages answer "can this address be used?" for an address the user types, through `App\Rules\AvailableEmail` (directly, or through `InviteUser::addressRules()`): the profile's email change ("This address can't be used." means the address has an account elsewhere), the invite page, an administrator's address change on `users.edit` and, since M7d, the bulk invitation page's *Check*, which answers it for up to 100 addresses per click ("This address can't be invited." against *Ready*). None of them counts the lookup: the profile and the invite page reserve their send limits with `around()`, which gives the slot back on a refused address, and *Check* has no limit at all. A member can therefore list which addresses have an account (FM-18, accepted for v1 in DEC-34 (16); DEC-35 widened it to *Check*).

This task adds one per-user limit, `UserActionLimit::addressLookup()` (500 lookups per 86 400 s, CD-2), consumed with `attempt()`, so every lookup counts, a refused one included, and is never given back:
- profile `updateProfileInformation()`: cost 1, field `email`, only when the typed address (lower-cased) differs from the stored one, before `validate()`; outside `emailChange()->around()`, so that limit's release cannot give the lookup back;
- `users.invite` `invite()`: cost 1, field `email`, after the format validation and `organisationToInvite()`, before `invite()->around()`;
- `users.edit` `saveAccount()`: cost 1, field `email`, only when the address is editable and differs from the stored one, before `validate()` (and so before the RL-18 confirmation park);
- bulk `check()`: cost `count($entries)`, field `addresses`, after the empty-list and `MAX_ROWS` guards and before `review()`. `send()` re-reviews only the `#[Locked]` rows a Check already paid for and is not charged.

`organisations.create` (super admins only, under `invite()`) and `RestoreUser` (no typed address) are not charged. The refusal reuses "Too many attempts. Try again in :seconds seconds.", so there are no new lang keys. The docs part of this task: the limits sentence in `.ai/guidelines/skv3.md` (AGENTS.md regenerated) and the README "Limits" bullet, with the sign-in sentences moved into a bullet of their own (D80). The handleiding mirror and DEC-36 (superseding DEC-34 (16)'s "accepted for v1") are Task 17's.

**Files:**
- Modify:
  - `app/Support/RateLimits/UserActionLimit.php` (the `addressLookup()` factory, directly after `addressChange()`)
  - `resources/views/pages/settings/profile/profile.php` (`updateProfileInformation()`: the lookup charge and its docblock sentence)
  - `resources/views/pages/users/invite/invite.php` (`invite()`: the lookup charge)
  - `resources/views/pages/users/edit/edit.php` (`saveAccount()`: the lookup charge and its docblock sentence)
  - `resources/views/pages/invitations/bulk/bulk.php` (`check()`: the lookup charge and its docblock)
  - `.ai/guidelines/skv3.md` (the `UserActionLimit` bullet; then regenerate the git-ignored `AGENTS.md`)
  - `README.md` (› Accounts and security: the **Limits** bullet and a new **Sign-in limits** bullet)
  - `CHANGELOG.md` (a new `### Security` heading at the end of `[Unreleased]`, with this task's line)
- Test:
  - `tests/Feature/Auth/ActionLimitsTest.php` (three new tests at the end of the file: profile, invite page, `users.edit`)
  - `tests/Feature/Invitations/BulkInvitationsTest.php` (two new tests; the existing test 'counts nothing for a list larger than what is left, not even for a moment, …' starts its mock after the Check)
  - unchanged and green: `tests/Feature/Support/UserActionLimitTest.php`, `tests/Feature/Auth/AdministratorActionLimitsTest.php`, `tests/Feature/Impersonation/ImpersonationPreferencesTest.php`, `tests/Feature/Users`, `tests/Feature/Settings`, `tests/Feature/Invitations`, `tests/Feature/Organisations`

**Interfaces:**
- Consumes:
  - `App\Support\RateLimits\UserActionLimit` (unchanged API): `attempt(User $user, string $field, int $cost = 1): void` (counts first with `RateLimiter::increment()`, refuses past the maximum, never gives back), `hit(User $user, int $cost = 1): void`, `key(User $user): string`, `around()`, `emailChange()`, `invite()`, `addressChange()`, `bulkInvite()`; `assertCost()` throws `InvalidArgumentException` for a cost above the maximum
  - `App\Support\Invitations\InvitationList::MAX_ROWS` (100), `App\Rules\AvailableEmail`, `App\Actions\Invitations\InviteUser::addressRules(Organisation $organisation): array`
  - `App\Concerns\ChoosesInvitationOrganisation::currentUser()` (bulk and invite pages), `App\Concerns\ResolvesCurrentUser::currentUser()` (profile, `users.edit`)
  - test helpers: `onTenant()`, `asOrgAdmin()`, `pageSnapshot()`, `callPageAction()`; `Pest\Laravel\withSession()`; the file-local `$errorsOf`, `$throttled` (ActionLimitsTest) and `$templateRoleId`, `$snapshotOf`, `$deliveredMails` (BulkInvitationsTest)
- Produces:
  - `UserActionLimit::addressLookup(): self` = `new self('address-lookup', 500, 86400)`; key `address-lookup:{user id}`. Its maximum must stay ≥ `InvitationList::MAX_ROWS`, because a full Check costs 100 at once.
  - The `### Security` heading under `## [Unreleased]` in `CHANGELOG.md`, after `### Removed` (Task 4 and later tasks add their Security lines under it).

**Notes for the implementer:**
- **Why `attempt()` and not `around()`.** A lookup reveals its answer whether or not the save goes through, so a refused address must cost as much as an accepted one. `attempt()` also counts a refused attempt (it is a burst guard), so a member who hits the limit stays refused for the rest of the window; the tests assert the counter past 500 on purpose.
- **Order on the profile page.** The charge sits before `validate()` and outside `emailChange()->around()`. Inside the closure, or wrapped by `around()`, a refused address would give the lookup back. The comparison runs after the page's existing `Str::lower()`, so the stored address in other letters is no lookup, and a name-only or language-only save counts nothing. During an impersonation the charge lands on the impersonated user's bucket (the page's `currentUser()`); `RequestEmailChange` refuses the change itself afterwards, and `AvailableEmail` has already answered by then, so counting it is right.
- **Order on `users.edit`.** The charge comes before `validate()`, so a save parked for the RL-18 password confirmation counts one lookup, and the save from the restored draft counts another: 2 per real change, well inside 500. Your own and a pending user's address are not editable (`$emailEditable` false) and never count.
- **The invite page.** The lookup counts after the format check (`'email'` rule, no database) and `organisationToInvite()` (authorisation), before `invite()->around()`; `around()` gives back only the invite slot when `InviteUser` refuses the address.
- **The bulk page.** The charge is `count($entries)`: every parsed row, an invalid or a repeated one included (simple and conservative). A list refused by the empty-list or `MAX_ROWS` guard looks nothing up and costs nothing. A refused Check keeps `rows` empty, so nothing can be sent from it.
- **The existing mock test.** 'counts nothing for a list larger than what is left, not even for a moment, so a parallel send still fits (CD-6)' mocks the rate limiter with `shouldNotReceive('increment')` before calling `check()`. The Check's lookup now increments, so the test fails as it stands ("Method increment(<Any Arguments>) … should be called exactly 0 times but called 1 times"). Start the mock after the Check; it still guards the Send it was written for (mutation 17 below).
- **Reading the rows over HTTP.** Livewire 4 dehydrates an array property as a tuple, so an empty `rows` reads `[[], ['s' => 'arr']]` in the snapshot's `data`.
- **No lang keys**: the refusal is `UserActionLimit::refuse()`'s "Too many attempts. Try again in :seconds seconds.".
- **No `git stash`.** For mutation checks copy the file aside with `cp`, change it, run the test, copy it back, then `php artisan view:clear` for the component files.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Auth/ActionLimitsTest.php`, after the last test (the `account-security` dataset test). The file's imports and its `$errorsOf` / `$throttled` closures already cover everything these tests use (`Role`, `RoleModel`, `ConfirmEmailChange`, `Notification`, `RateLimiter`, `TestResponse`, `withSession`):

```php
it('counts every address a profile save looks up, a refused one too, and refuses the lookup past 500 a day (FM-18, CD-2)', function () use ($errorsOf, $throttled): void {
    Notification::fake();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    User::factory()->for(Organisation::factory())->create(['email' => 'taken@example.com']);
    $member = User::factory()->for($organisation)->create(['name' => 'Ada', 'email' => 'old@example.com']);
    onTenant($organisation);
    $this->actingAs($member);
    $key = UserActionLimit::addressLookup()->key($member);
    $snapshot = pageSnapshot(route('profile.edit'), 'pages::settings.profile');
    UserActionLimit::addressLookup()->hit($member, cost: 498);

    // An address held elsewhere is refused, and its lookup still counts: attempt() never gives it back.
    expect($errorsOf(callPageAction($snapshot, 'updateProfileInformation', updates: ['email' => 'taken@example.com'])))
        ->toBe(['email' => [__('This address can\'t be used.')]])
        ->and(RateLimiter::attempts($key))->toBe(499);

    // The stored address, in other letters, is no lookup.
    expect($errorsOf(callPageAction($snapshot, 'updateProfileInformation', updates: ['name' => 'Eve', 'email' => 'OLD@example.com'])))->toBe([])
        ->and($member->fresh()?->name)->toBe('Eve')
        ->and(RateLimiter::attempts($key))->toBe(499);

    expect($errorsOf(callPageAction($snapshot, 'updateProfileInformation', updates: ['name' => 'Eve', 'email' => 'new@example.com'])))->toBe([])
        ->and(RateLimiter::attempts($key))->toBe(500);
    Notification::assertSentOnDemandTimes(ConfirmEmailChange::class, 1);

    $this->travel(1)->hour();

    // Past the limit no address is looked up: a taken and a free one get the same answer, and nothing is saved.
    foreach (['taken@example.com', 'free@example.com'] as $address) {
        expect($errorsOf(callPageAction($snapshot, 'updateProfileInformation', updates: ['name' => 'Zed', 'email' => $address])))
            ->toBe(['email' => [$throttled(82800)]]);
    }

    expect($member->fresh()?->name)->toBe('Eve')
        ->and(UserActionLimit::addressLookup())->toEqual(new UserActionLimit('address-lookup', 500, 86400));
    Notification::assertSentOnDemandTimes(ConfirmEmailChange::class, 1);
});

it('counts the address the invite page looks up, also when InviteUser refuses it, before the invitation limit (FM-18, CD-2)', function () use ($errorsOf, $throttled): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    User::factory()->for(Organisation::factory())->create(['email' => 'taken@example.com']);
    $admin = asOrgAdmin($organisation);
    $templateId = (string) RoleModel::query()->whereNull('team_id')->where('name', Role::OrganisationAdmin->value)->value('id');
    $snapshot = pageSnapshot(route('users.invite'), 'pages::users.invite');
    $invite = fn (string $email): TestResponse => callPageAction($snapshot, 'invite', updates: ['email' => $email, 'roles' => [$templateId]]);
    $lookups = UserActionLimit::addressLookup()->key($admin);
    UserActionLimit::addressLookup()->hit($admin, cost: 499);

    // InviteUser refuses the address and the invite slot is given back; the lookup is not.
    expect($errorsOf($invite('taken@example.com')))->toBe(['email' => [__('This address can\'t be used.')]])
        ->and(RateLimiter::attempts($lookups))->toBe(500)
        ->and(RateLimiter::attempts(UserActionLimit::invite()->key($admin)))->toBe(0);

    expect($errorsOf($invite('new@example.com')))->toBe(['email' => [$throttled(86400)]])
        ->and(RateLimiter::attempts(UserActionLimit::invite()->key($admin)))->toBe(0)
        ->and(User::query()->where('email', 'new@example.com')->exists())->toBeFalse();
});

it('counts the address an administrator types on users.edit before the confirmation and the address-change limit (FM-18, CD-2)', function () use ($errorsOf, $throttled): void {
    Notification::fake();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    User::factory()->for(Organisation::factory())->create(['email' => 'taken@example.com']);
    $member = User::factory()->for($organisation)->create(['name' => 'Mila', 'email' => 'mila@acme.test']);
    $admin = asOrgAdmin($organisation);
    $lookups = UserActionLimit::addressLookup()->key($admin);
    withSession(['auth.password_confirmed_at' => time()]);
    $snapshot = pageSnapshot(route('users.edit', ['user' => $member->id]), 'pages::users.edit');
    UserActionLimit::addressLookup()->hit($admin, cost: 499);

    expect($errorsOf(callPageAction($snapshot, 'saveAccount', updates: ['email' => 'taken@example.com'])))->toBe(['email' => [__('This address can\'t be used.')]])
        ->and(RateLimiter::attempts($lookups))->toBe(500);

    expect($errorsOf(callPageAction($snapshot, 'saveAccount', updates: ['name' => 'Mila de Vries', 'email' => 'mila.new@acme.test'])))->toBe(['email' => [$throttled(86400)]])
        ->and($member->fresh()?->only(['name', 'email']))->toBe(['name' => 'Mila', 'email' => 'mila@acme.test'])
        ->and(RateLimiter::attempts(UserActionLimit::addressChange()->key($admin)))->toBe(0);

    // A save that keeps the address, here in other letters, looks nothing up.
    expect($errorsOf(callPageAction($snapshot, 'saveAccount', updates: ['name' => 'Mila de Vries', 'email' => 'MILA@acme.test'])))->toBe([])
        ->and($member->fresh()?->name)->toBe('Mila de Vries')
        ->and(RateLimiter::attempts($lookups))->toBe(501);
});
```

In `tests/Feature/Invitations/BulkInvitationsTest.php`, replace the opening of the existing test 'counts nothing for a list larger than what is left, not even for a moment, so a parallel send still fits (CD-6)' up to its `->call('send')` line:

```php
    UserActionLimit::bulkInvite()->hit($admin, cost: 98);
    // A reservation that is given back still pushes the counter past the limit while it lasts; the pre-check refuses first.
    $limiter = Mockery::mock(CacheRateLimiter::class, [Cache::store()])->makePartial();
    $limiter->shouldNotReceive('increment');
    RateLimiter::swap($limiter);

    Livewire::test('pages::invitations.bulk')
        ->set('roles', [$templateRoleId()])
        ->set('addresses', 'ada@example.com, bob@example.com, cleo@example.com')
        ->call('check')
        ->call('send')
```

with:

```php
    UserActionLimit::bulkInvite()->hit($admin, cost: 98);
    $page = Livewire::test('pages::invitations.bulk')
        ->set('roles', [$templateRoleId()])
        ->set('addresses', 'ada@example.com, bob@example.com, cleo@example.com')
        ->call('check');
    // A reservation that is given back still pushes the counter past the limit while it lasts; the pre-check refuses
    // first. The mock starts after the Check, whose address lookups count with increment() (FM-18).
    $limiter = Mockery::mock(CacheRateLimiter::class, [Cache::store()])->makePartial();
    $limiter->shouldNotReceive('increment');
    RateLimiter::swap($limiter);

    $page->call('send')
```

(the rest of the test, from `->assertHasErrors([...3600...])` on, stays). Then insert these two tests directly before the test 'sends only the rows the server checked, whatever a forged update says (CD-6)'. Both run over HTTP (`pageSnapshot()` + `callPageAction()`), because the limit is a request-level rule:

```php
it('refuses a Check whose rows would pass the address-lookup limit before it reviews a row, and counts it (FM-18, CD-2)', function () use ($templateRoleId, $snapshotOf): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($acme);
    $lookups = UserActionLimit::addressLookup()->key($admin);
    UserActionLimit::addressLookup()->hit($admin, cost: 450);
    $snapshot = pageSnapshot(route('invitations.bulk'), 'pages::invitations.bulk');
    $hundred = implode("\n", array_map(fn (int $index): string => "person{$index}@example.com", range(1, 100)));

    $refused = $snapshotOf(callPageAction($snapshot, 'check', updates: ['addresses' => $hundred, 'roles' => [$templateRoleId()]]));

    expect($refused['memo']['errors'] ?? [])->toBe(['addresses' => [__('Too many attempts. Try again in :seconds seconds.', ['seconds' => 86400])]])
        ->and($refused['data']['rows'])->toBe([[], ['s' => 'arr']])
        // attempt() counts the refused list too, so the next Check of one row is refused as well.
        ->and(RateLimiter::attempts($lookups))->toBe(550);

    $single = $snapshotOf(callPageAction($snapshot, 'check', updates: ['addresses' => 'ada@example.com', 'roles' => [$templateRoleId()]]));

    expect($single['memo']['errors'] ?? [])->toHaveKey('addresses')
        ->and($single['data']['rows'])->toBe([[], ['s' => 'arr']]);
});

it('charges a Check one lookup per row after the list checks, and never charges the Send that follows (FM-18, CD-2)', function () use ($templateRoleId, $snapshotOf, $deliveredMails): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($acme);
    $lookups = UserActionLimit::addressLookup()->key($admin);
    $snapshot = pageSnapshot(route('invitations.bulk'), 'pages::invitations.bulk');
    $tooMany = implode("\n", array_map(fn (int $index): string => "person{$index}@example.com", range(1, 101)));

    // A list the page refuses before it reviews a row looks nothing up.
    expect($snapshotOf(callPageAction($snapshot, 'check', updates: ['addresses' => $tooMany, 'roles' => [$templateRoleId()]]))['memo']['errors'] ?? [])
        ->toBe(['addresses' => [__('Add at most :max addresses at a time.', ['max' => 100])]])
        ->and(RateLimiter::attempts($lookups))->toBe(0);

    // Every row counts, an invalid and a repeated one included.
    $checked = $snapshotOf(callPageAction($snapshot, 'check', updates: ['addresses' => 'ada@example.com, not-an-address, bob@example.com, ada@example.com', 'roles' => [$templateRoleId()]]));

    expect($checked['memo']['errors'] ?? [])->toBe([])
        ->and(RateLimiter::attempts($lookups))->toBe(4);

    // Send reviews only the rows the server checked (#[Locked]), so it looks up nothing new.
    expect($snapshotOf(callPageAction($checked, 'send'))['memo']['errors'] ?? [])->toBe([])
        ->and($deliveredMails())->toHaveCount(2)
        ->and(RateLimiter::attempts($lookups))->toBe(4)
        ->and(RateLimiter::attempts(UserActionLimit::bulkInvite()->key($admin)))->toBe(2);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Auth/ActionLimitsTest.php tests/Feature/Invitations/BulkInvitationsTest.php --filter=FM-18`

Expected: FAIL, all five:
- the three ActionLimitsTest tests with "Call to undefined method App\Support\RateLimits\UserActionLimit::addressLookup()"
- 'refuses a Check whose rows would pass the address-lookup limit …' with "Failed asserting that two arrays are identical." (no error on `addresses`: today the 100 rows are reviewed)
- 'charges a Check one lookup per row …' with "Failed asserting that 0 is identical to 4."

Once Step 3 has added the factory alone, the ActionLimitsTest tests fail on behaviour instead: "Failed asserting that 498 is identical to 499." (profile) and "Failed asserting that 499 is identical to 500." (invite page, `users.edit`): no page counts a lookup yet.

- [ ] **Step 3: `UserActionLimit::addressLookup()`**

`app/Support/RateLimits/UserActionLimit.php`: insert the factory directly after `addressChange()` (before `avatarUpdate()`):

```php
    /**
     * Look up whether a typed address can be used (FM-18): 500 addresses per day per user. The profile's email change,
     * the invite page, users.edit and the bulk page's "Check" (one per row) count each lookup with attempt() before
     * AvailableEmail runs, and never give it back, so their answers cannot list which addresses have an account. A
     * bulk Check costs up to InvitationList::MAX_ROWS at once, so the limit never drops below it (assertCost()).
     */
    public static function addressLookup(): self
    {
        return new self('address-lookup', 500, 86400);
    }
```

- [ ] **Step 4: The profile page**

`resources/views/pages/settings/profile/profile.php` (`UserActionLimit` and `Str` are already imported): replace the whole `updateProfileInformation()` method, docblock included:

```php
    /**
     * Save the name and the language; a new email address only applies after it is confirmed through the
     * mailed link (E1). A new language reloads the page, so it renders in that language (§4.10); the result
     * is then flashed in that language for the reloaded page instead of a toast the reload would drop (G3-06).
     * Sending the confirmation link is limited per user (CD-3): the slot is reserved before anything is saved,
     * so a refusal saves nothing, and given back when any part of the save throws (UserActionLimit::around()).
     * A typed address other than the stored one is looked up by AvailableEmail, so it first counts under
     * UserActionLimit::addressLookup() (FM-18, CD-2), outside around(): a refused address is never given back.
     */
    public function updateProfileInformation(RequestEmailChange $requestEmailChange, UpdateLocale $updateLocale): void
    {
        $user = $this->currentUser();

        $this->email = Str::lower($this->email);

        if ($this->email !== $user->email) {
            UserActionLimit::addressLookup()->attempt($user, 'email');
        }

        $validated = $this->validate([
            ...$this->profileRules($user->id, $user->organisation_id),
            'locale' => ['required', Rule::enum(Locale::class)],
        ]);

        $email = (string) $validated['email'];
        $changesEmail = $email !== $user->email;
        $locale = Locale::from((string) $validated['locale']);
        $localeChanged = $locale !== Impersonation::localeFor($user);

        $save = function () use ($user, $validated, $email, $changesEmail, $locale, $localeChanged, $updateLocale, $requestEmailChange): void {
            $user->fill(['name' => $validated['name']])->save();

            if ($localeChanged) {
                $updateLocale->handle($user, $locale);
            }

            if ($changesEmail) {
                $requestEmailChange->handle($user, $email);
            }
        };

        if ($changesEmail) {
            UserActionLimit::emailChange()->around($user, 'email', $save);

            $this->email = $user->email;
            $status = __('We sent a confirmation link to :email.', ['email' => $email], $locale->value);
        } else {
            $save();

            $status = __('Profile updated.', [], $locale->value);
        }

        if (! $localeChanged) {
            Flux::toast(variant: 'success', text: $status);

            return;
        }

        // A toast dispatched now is lost on the full reload (G3-06): the status is flashed instead, in the new
        // language, and the base layout's flash→toast bridge shows it on the reloaded page.
        session()->flash('status', $status);
        $this->redirect(route('profile.edit'), navigate: false);
    }
```

- [ ] **Step 5: The invite page**

`resources/views/pages/users/invite/invite.php` (`UserActionLimit` is already imported): replace the whole `invite()` method:

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
        // Components authorise before calling an action: organisationToInvite() authorises the organisation it returns.
        $organisation = $this->organisationToInvite();

        // FM-18: InviteUser looks the address up, so the lookup counts first and is never given back, also when the
        // invitation is refused (CD-2).
        UserActionLimit::addressLookup()->attempt($this->currentUser(), 'email');

        // CD-3: a per-user limit on sent invitations, here and not in InviteUser, so a bulk caller counts its own way.
        // The slot is reserved before the send and given back when InviteUser refuses (DEC-27 (2)).
        UserActionLimit::invite()->around($this->currentUser(), 'email', fn (): Invitation => $inviteUser->handle(
            $this->currentUser(),
            $organisation,
            $email,
            array_map(fn (int|string $roleId): int => (int) $roleId, $this->roles),
            Locale::from($this->locale),
        ));

        session()->flash('status', __('Invitation sent to :email.', ['email' => Str::lower($email)]));
        $this->redirectRoute($this->returnRoute(), navigate: true);
    }
```

- [ ] **Step 6: `users.edit`**

`resources/views/pages/users/edit/edit.php` (`UserActionLimit` is already imported): replace the whole `saveAccount()` method, docblock included:

```php
    /**
     * Save name, address and status (USR-12), all or nothing (G4-08). Your own and a pending user's address are
     * read-only and never sent (E1); another user's new address needs a fresh password confirmation first (RL-18).
     * A new address mails the old one, so it is limited per administrator (FM-19): the slot is reserved after the
     * confirmation and before anything is saved, so a refusal saves nothing, and given back when the save throws
     * (UserActionLimit::around()). A save that keeps the address counts nothing. A new address is first counted
     * under UserActionLimit::addressLookup() (FM-18, CD-2), before the validation looks it up.
     */
    public function saveAccount(UpdateUser $updateUser, UpdateUserStatus $updateUserStatus): void
    {
        $actor = $this->currentUser();
        $target = $this->target();
        $this->authorize('update', $target);

        $pending = $target->status === UserStatus::PendingActivation;
        $emailEditable = $actor->isNot($target) && ! $pending;
        $this->email = Str::lower(trim($this->email));

        // FM-18: AvailableEmail looks a new address up, so the lookup counts first and is never given back (CD-2).
        if ($emailEditable && $this->email !== $target->email) {
            UserActionLimit::addressLookup()->attempt($actor, 'email');
        }

        $this->validate([
            'name' => $this->nameRules(),
            ...($emailEditable ? ['email' => $this->emailRules($target->id, $target->organisation_id)] : []),
            ...($pending ? [] : ['status' => ['required', 'string', Rule::in([UserStatus::Active->value, UserStatus::Disabled->value])]]),
        ]);

        $email = $emailEditable ? $this->email : $target->email;
        $status = $pending ? $target->status : UserStatus::from($this->status);
        $statusChanges = $status !== $target->status;

        if ($statusChanges) {
            $this->authorize('updateStatus', $target);
        }

        $changesEmail = $email !== $target->email;

        if ($changesEmail && $this->confirmationParked($target)) {
            return;
        }

        // One transaction (G4-08): a refused status change keeps the old name and address too. The status goes
        // first, so its organisation and users locks come before UpdateUser writes the target row (CD-5).
        $save = function () use ($updateUser, $updateUserStatus, $actor, $target, $email, $status, $statusChanges): void {
            DB::transaction(function () use ($updateUser, $updateUserStatus, $actor, $target, $email, $status, $statusChanges): void {
                if ($statusChanges) {
                    $updateUserStatus->handle($actor, $target, $status);
                }

                $updateUser->handle($actor, $target, $this->name, $email);
            });
        };

        if ($changesEmail) {
            UserActionLimit::addressChange()->around($actor, 'email', $save);
        } else {
            $save();
        }

        $this->resolvedTarget = null;
        $this->fillAccount($this->target());
        $this->draftRestored = false;

        Flux::toast(variant: 'success', text: __('User saved.'));
    }
```

- [ ] **Step 7: The bulk page's Check**

`resources/views/pages/invitations/bulk/bulk.php` (`UserActionLimit` is already imported): replace the whole `check()` method, docblock included. `send()` stays as it is:

```php
    /** Parses the input and reviews every row, one address lookup each (FM-18); nothing is written or sent. */
    public function check(InviteUser $inviteUser): void
    {
        $this->summary = null;
        $this->rows = [];
        $this->validateBatch();
        $this->validate([
            'addresses' => ['nullable', 'string', 'max:30000'],
            'csv' => ['nullable', 'file', 'mimes:csv,txt', 'max:512'],
        ]);

        $organisation = $this->organisationToInvite();
        $entries = [
            ...InvitationList::fromText($this->addresses),
            ...($this->csv instanceof TemporaryUploadedFile ? InvitationList::fromCsv((string) $this->csv->get()) : []),
        ];

        if ($entries === []) {
            throw ValidationException::withMessages(['addresses' => __('Paste addresses or choose a CSV file.')]);
        }

        if (count($entries) > InvitationList::MAX_ROWS) {
            throw ValidationException::withMessages(['addresses' => __('Add at most :max addresses at a time.', ['max' => InvitationList::MAX_ROWS])]);
        }

        // FM-18: review() looks every row up with InviteUser's address rules, so each row counts, an invalid or a
        // repeated one included, and is never given back (CD-2). send() re-reviews only these #[Locked] rows.
        UserActionLimit::addressLookup()->attempt($this->currentUser(), 'addresses', count($entries));

        $this->rows = $this->review($entries, $organisation, $inviteUser);
    }
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Auth/ActionLimitsTest.php tests/Feature/Auth/AdministratorActionLimitsTest.php tests/Feature/Support/UserActionLimitTest.php tests/Feature/Users tests/Feature/Settings tests/Feature/Invitations tests/Feature/Impersonation tests/Feature/Organisations`

Expected: PASS. The existing tests stay far below 500 lookups. Without the Step 1 change to the mock test, 'counts nothing for a list larger than what is left, not even for a moment, …' fails here with "Method increment(<Any Arguments>) from Mockery_…_RateLimiter should be called exactly 0 times but called 1 times."

- [ ] **Step 9: Verify by mutation**

For each mutation: copy the file aside with `cp`, change it, run `php artisan view:clear`, then `php artisan test --compact tests/Feature/Auth/ActionLimitsTest.php tests/Feature/Invitations/BulkInvitationsTest.php --filter=FM-18` (mutation 17: `--filter="not even for a moment"`), confirm the named test fails, copy the original back, run `php artisan view:clear` again and confirm the file passes (never `git stash`). Name each mutation and its result in the task report.

| # | mutation | the test that must fail |
|---|---|---|
| 1 | profile: delete the `if ($this->email !== $user->email) { … addressLookup()->attempt(…) }` block | 'counts every address a profile save looks up …' ("498 is identical to 499") |
| 2 | profile: charge every save (`UserActionLimit::addressLookup()->attempt($user, 'email');` without the `if`) | 'counts every address a profile save looks up …' ("500 is identical to 499" after the kept-address save) |
| 3 | profile: `ensureAvailable()` instead of `attempt()` | 'counts every address a profile save looks up …' ("498 is identical to 499") |
| 4 | profile: move the charge block after `$validated = $this->validate([...])` | 'counts every address a profile save looks up …' (the refused address is not counted: "498 is identical to 499") |
| 5 | bulk: delete the `addressLookup()->attempt(…, count($entries))` line | both bulk FM-18 tests (no refusal; "0 is identical to 4") |
| 6 | bulk: cost 1 (drop the `count($entries)` argument) | both bulk FM-18 tests (no refusal at 451; "1 is identical to 4") |
| 7 | bulk: charge after `$this->rows = $this->review(...)` | 'refuses a Check whose rows would pass …' (`rows` holds 100 reviewed rows) |
| 8 | bulk: move the charge before `if ($entries === [])` | 'charges a Check one lookup per row …' ("101 is identical to 0") |
| 9 | bulk: charge `send()` too (`UserActionLimit::addressLookup()->attempt($actor, 'addresses', count($this->rows));` before "Reviewed again") | 'charges a Check one lookup per row …' ("8 is identical to 4") |
| 10 | invite: delete the `addressLookup()->attempt(…)` line | 'counts the address the invite page looks up …' ("499 is identical to 500") |
| 11 | invite: wrap the invite in `UserActionLimit::addressLookup()->around($this->currentUser(), 'email', fn (): Invitation => UserActionLimit::invite()->around(…))` instead of the `attempt()` line (the lookup is given back on a refusal) | 'counts the address the invite page looks up …' ("499 is identical to 500") |
| 12 | `users.edit`: delete the `if ($emailEditable && …) { … }` block | 'counts the address an administrator types on users.edit …' ("499 is identical to 500") |
| 13 | `users.edit`: charge every save (no `if`) | 'counts the address an administrator types on users.edit …' (the kept-address save is refused) |
| 14 | `addressLookup()`: `new self('address-lookup', 1000, 86400)` | the three ActionLimitsTest FM-18 tests (no refusal; and the `toEqual` pin) |
| 15 | `addressLookup()`: `new self('address-lookup', 500, 3600)` | all three ActionLimitsTest FM-18 tests and 'refuses a Check whose rows would pass …' (the seconds in the message) |
| 16 | `addressLookup()`: `new self('address-lookup', 99, 86400)` | the three ActionLimitsTest FM-18 tests ("A cost of 498 does not fit the address-lookup limit of 99.") |
| 17 | bulk `send()`: delete `$limit->ensureAvailable($actor, 'addresses', count($ready));` | 'counts nothing for a list larger than what is left, not even for a moment, …' (still guards the Send after its change: "Component has no errors.") |

- [ ] **Step 10: The kit guideline and the README**

`.ai/guidelines/skv3.md`, › Tenancy and authorization, the bullet that starts with "- The self-service and invitation actions that send mail or check a secret": three changes, the rest of the bullet stays.
- Replace "and any new action a member can repeat to send mail, test a secret or make the server do expensive work, limit themselves" with "and any new action a member can repeat to send mail, test a secret, look up whether an address has an account or make the server do expensive work, limit themselves".
- Replace "`attempt()` before a secret check; `around()` for a send," with "`attempt()` before a secret check or an address lookup; `around()` for a send,".
- After the sentence "The organisation wizard's first-admin invitation counts under `invite()` (`around()`, field `adminEmail`)." insert:

```markdown
`addressLookup()` (500/86 400 s, FM-18) counts every `AvailableEmail` check of a typed address with `attempt()` before it runs and never gives it back: the profile's email change and `users.edit` only when the typed address differs from the stored one, the invite page before `invite()`'s `around()`, and the bulk page's *Check* one per row after the list's own checks (*Send* re-reviews only the checked rows and is not charged); its maximum never drops below `InvitationList::MAX_ROWS`.
```

Then regenerate the git-ignored `AGENTS.md` with `php artisan boost:update --no-discover --no-interaction` and check it shows the new sentence (`grep -c 'addressLookup' AGENTS.md` prints 1). The command changes no tracked file.

`README.md`, › Accounts and security: replace the whole **Limits** bullet (the line that starts with "- **Limits** (per user).") with these two bullets. The sign-in sentences move into their own bullet, because the sign-in limits count per address and IP address, not per user (D80):

```markdown
- **Limits** (per user). Requesting an email change: 3 per 10 minutes. Changing the password, confirming two-factor sign-in and *Log out other devices*: 5 per minute, every attempt counts. Inviting and reminding: 10 per minute each (the invite limit also covers the first administrator's invitation from the organisation wizard), and one reminder per invitation per 10 minutes. Bulk invitations: 100 per hour per administrator. An administrator changing another user's address: 10 per hour. Exporting the activity log: 5 per minute. Saving a profile photo: 10 per 10 minutes, every attempt counts. Uploading a file (the photo or a bulk invitation list): 10 per minute and 60 per hour (`livewire-upload`). The two-factor management routes and removing a passkey: 10 per minute (`account-security`). Looking up whether an address can be used: 500 addresses a day, every lookup counts, also one that is refused: a new address on the profile page or on an administrator's *Edit user*, the address on *Invite user*, and every row of a bulk invitation's *Check the list* (sending a checked list adds nothing). Above a limit the form says *Too many attempts. Try again in … seconds.*
- **Sign-in limits** (per address and IP address). Five failed sign-ins a minute per address and IP address, and 20 failed sign-ins a minute per IP address. A successful sign-in, also with a second factor or a passkey, clears that address's failed attempts from that IP address. A throttled sign-in returns to the login page.
```

- [ ] **Step 11: CHANGELOG**

`CHANGELOG.md`: `[Unreleased]` has no Security section yet. Add one at the end of the `[Unreleased]` section, after the `### Removed` list (Keep a Changelog's order puts Security last), with a blank line before the heading:

```markdown
### Security
- Looking up whether an address can be used is limited to 500 addresses a day per user, every lookup counted, refused ones too: the profile's email change, *Invite user*, an administrator's address change and each row of the bulk invitation page's *Check the list*. Before, a member could test without limit which addresses have an account (FM-18).
```

No lang keys.

- [ ] **Step 12: The gate, then commit**

Run the Global Constraints gate:
- `composer check`
- `composer test:pgsql` (the rate limiter runs on the default cache store, so PostgreSQL changes nothing here)
- the modules-off run
- `npm run build && composer test:browser` (no browser test fills the lookup limit)
- `npm run build:setup && git diff --exit-code resources/setup/setup.css` (no setup view changes, so expect no diff)
- `php artisan config:clear`

```bash
git add app/Support/RateLimits/UserActionLimit.php resources/views/pages/settings/profile/profile.php \
    resources/views/pages/users/invite/invite.php resources/views/pages/users/edit/edit.php \
    resources/views/pages/invitations/bulk/bulk.php tests/Feature/Auth/ActionLimitsTest.php \
    tests/Feature/Invitations/BulkInvitationsTest.php .ai/guidelines/skv3.md README.md CHANGELOG.md
git commit -m "fix: limit the address lookups of the profile, invite, user edit and bulk check pages

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

---

### Task 4: Handoff links bound to the password (§6.4, §4.2; CD-3, CD-19; E4 = DEC-35 (12)(a), DEC-35 Consequence)

A cross-host sign-in link (`IssueHandoff`, §6.4) lives 60 seconds and works once, but nothing ties it to the password it was issued under: a link issued just before a password change or reset still signs a clean browser in afterwards. Only *Log out other devices* deletes the user's unused tokens (`sessions.php`); the security page's `updatePassword()`, Fortify's reset (`ResetUserPassword`), `skv:create-super-admin --password-stdin` and setup's `DatabaseStep` do not (E4).

This task binds every link to the password (CD-3). A new migration adds a nullable `handoff_tokens.password_hash` (string, 64): the HMAC that `AuthenticateSession` keeps in the session, `SessionGuard::hashPasswordForCookie()` of the user's password hash, keyed with `APP_KEY`. `IssueHandoff` stores it; `HandoffController::userOf()` refuses a link whose value is missing or no longer matches the user's current hash. This covers every password write path at once, including the ones a delete-on-change hook would have to remember, and a link from before the migration (null) is refused. The explicit delete in "log out other devices" stays.

**Files:**
- Create:
  - `database/migrations/0001_01_01_000010_add_password_hash_to_handoff_tokens_table.php`
- Modify:
  - `app/Models/HandoffToken.php` (the column in `#[Fillable]` and as a `@property`; `passwordBindingFor()` and `isBoundTo()`)
  - `app/Actions/Auth/IssueHandoff.php` (stores the binding; the class docblock)
  - `app/Http/Controllers/Auth/HandoffController.php` (`userOf()` checks the binding)
  - `README.md` (› Accounts and security: a new **Cross-host sign-in links** bullet after **Passkeys and the security page**)
  - `CHANGELOG.md` (one line under Task 3's `### Security`)
- Test:
  - `tests/Feature/Auth/HandoffTest.php` (two new tests at the end; the imports `Hash`, `Password` and `withSession`)
  - unchanged and green: `tests/Feature/Settings/SessionsTest.php`, `tests/Feature/Auth/QuickLoginTest.php`, `tests/Feature/Setup/Wizard/HerdStageTest.php`, `tests/Feature/Organisations/OrganisationsIndexTest.php`, `tests/Feature/Organisations/OrganisationEditTest.php`, `tests/Feature/Organisations/ArchiveRestorePurgeTest.php` (its hand-made token row has no `password_hash`, which is nullable), `tests/Feature/Impersonation/ImpersonationEnforcementTest.php`

**Interfaces:**
- Consumes:
  - `Illuminate\Auth\SessionGuard::hashPasswordForCookie(?string $passwordHash): string` (`hash_hmac('sha256', $passwordHash ?? '', config('app.key'))`; `Auth::guard('web')` resolves to `SessionGuard`, as in `App\Tenancy\Auth\Impersonation`)
  - `App\Actions\Auth\IssueHandoff::handle(User $user, string $targetHost, string $path = '/dashboard'): string` (signature unchanged)
  - `App\Tenancy\Auth\HostLoginRule::allows(User $user): bool`
  - Task 3's `### Security` heading under `## [Unreleased]` in `CHANGELOG.md`
  - test helpers: `onTenant()`, `pageSnapshot()`, `callPageAction()`; `Pest\Laravel\withSession()`; `Illuminate\Support\Facades\Password::broker()->createToken()`
- Produces:
  - the column `handoff_tokens.password_hash` (`string(64)`, nullable)
  - `HandoffToken::passwordBindingFor(User $user): string` (static) and `HandoffToken::isBoundTo(User $user): bool`; `HandoffToken::$password_hash` (`string|null`, fillable)

**Notes for the implementer:**
- **A new migration, not an edit of `…000004`.** Developer databases are already migrated, and `migrate:fresh` is forbidden (CD-3). Tests get the column through `RefreshDatabase`. Never run `php artisan migrate` in the repository: the owner applies the migration to the developer database after this task (CD-19, Step 10). Until then the local site's `IssueHandoff` fails with an unknown-column error, so the owner should migrate before using *Open* or the quick login.
- **`->after('path')`** only orders the column on MySQL; SQLite and PostgreSQL append it (a plain `varchar(64)` on PostgreSQL). `dropColumn()` works on SQLite in Laravel 13.
- **Why the session's HMAC.** It is the value `AuthenticateSession` already compares on every request, so a link and a session end at the same moment, and the password hash itself is not copied into a second table. It is keyed with `APP_KEY`: rotating `APP_KEY` ends links in flight too, which a 60-second link can afford.
- **What ends a link.** Every write of `users.password`: the security page, a reset, `skv:create-super-admin --password-stdin`, setup's `DatabaseStep`, and `Auth::logoutOtherDevices()`'s rehash. A bcrypt rehash at sign-in (`Hash::needsRehash()`) ends links in flight as well, as it ends other sessions through `AuthenticateSession`; that is acceptable.
- **The order in `userOf()`.** The binding is checked before the host login rule; either refusal is the same "This sign-in link is invalid or has expired." redirect, and the token stays unconsumed.
- **The test harness.** The password change runs over HTTP on the security page (`pageSnapshot()` + `callPageAction()`, with a fresh `auth.password_confirmed_at`), then `auth()->logout()` stands in for the clean browser the link is opened in. The reset posts to Fortify's `password.update` with a broker token. Each case first asserts that the password really changed, so the refusal is not vacuous.
- **No lang keys**: the refusal reuses "This sign-in link is invalid or has expired.".
- **No `git stash`.** For mutation checks copy the file aside with `cp`, change it, run the test, and copy it back.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Auth/HandoffTest.php`: add the imports in their sorted places, `use Illuminate\Support\Facades\Hash;` and `use Illuminate\Support\Facades\Password;` after `use Illuminate\Support\Facades\Event;`, and the function import after the class imports:

```php
use Tests\Fixtures\CsrfEnforcingPreventRequestForgery;

use function Pest\Laravel\withSession;
```

Then append these two tests at the end of the file:

```php
it('binds a handoff link to the password it was issued under, as the session\'s HMAC, and signs in while it is unchanged (CD-3)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();

    $url = app(IssueHandoff::class)->handle($member, Hosts::tenant('acme'));

    // The HMAC AuthenticateSession keeps in the session (SessionGuard::hashPasswordForCookie()), never the hash itself.
    expect(HandoffToken::query()->sole()->password_hash)
        ->toBe(hash_hmac('sha256', $member->getAuthPassword(), (string) config('app.key')))
        ->not->toBe($member->getAuthPassword());
    $this->get($url)->assertRedirect('http://acme.localhost/dashboard');
    $this->assertAuthenticatedAs($member);
});

it('refuses a handoff link issued before a password change or reset, or before links were bound to the password (DEC-35 (12)(a), CD-3)', function (string $case): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $url = app(IssueHandoff::class)->handle($member, Hosts::tenant('acme'));
    onTenant($organisation);

    if ($case === 'changed on the security page') {
        $this->actingAs($member);
        withSession(['auth.password_confirmed_at' => time()]);
        $snapshot = pageSnapshot(route('security.edit'), 'pages::settings.security');
        callPageAction($snapshot, 'updatePassword', updates: [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();
        auth()->logout();
    } elseif ($case === 'reset') {
        $this->post(route('password.update'), [
            'token' => Password::broker()->createToken($member),
            'email' => $member->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertSessionHasNoErrors();
    } else {
        // 'issued before the binding': a link from before the password_hash column has none.
        DB::table('handoff_tokens')->update(['password_hash' => null]);
    }

    expect(Hash::check($case === 'issued before the binding' ? 'password' : 'new-password-123', (string) $member->fresh()?->password))->toBeTrue();
    $this->assertGuest();

    $this->get($url)
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', __('This sign-in link is invalid or has expired.'));
    $this->assertGuest();
    expect(HandoffToken::query()->sole()->consumed_at)->toBeNull();
})->with(['changed on the security page', 'reset', 'issued before the binding']);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Auth/HandoffTest.php`

Expected: FAIL, four cases:
- 'binds a handoff link to the password it was issued under, …' with "The attribute [password_hash] either does not exist or was not retrieved for model [App\Models\HandoffToken]."
- 'refuses a handoff link issued before a password change or reset, …' with data set "changed on the security page" and "reset": "Failed asserting that two strings are equal." (`'http://acme.localhost/login'` expected, `'http://acme.localhost/dashboard'` actual: today the link still signs in)
- the same test with data set "issued before the binding": "SQLSTATE[HY000]: General error: 1 no such column: password_hash"

Once Step 3 has added the migration alone, the "issued before the binding" case fails like the other two (the link signs in), and the first test fails with "Failed asserting that null is identical to '…'".

- [ ] **Step 3: The migration**

Create `database/migrations/0001_01_01_000010_add_password_hash_to_handoff_tokens_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CD-3 (M8a): the HMAC of the user's password hash when the link was issued (SessionGuard::hashPasswordForCookie()).
 * A link whose value no longer matches, or that has none because it was issued before this column, is refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('handoff_tokens', function (Blueprint $table): void {
            $table->string('password_hash', 64)->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('handoff_tokens', function (Blueprint $table): void {
            $table->dropColumn('password_hash');
        });
    }
};
```

- [ ] **Step 4: The model**

Replace `app/Models/HandoffToken.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * A single-use, host-bound sign-in link (§6.4). Central (no organisation); only the hash is stored. The link is bound
 * to the password it was issued under (CD-3): a password changed since, by any path, ends it.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string $target_host
 * @property string $path
 * @property string|null $password_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'token_hash', 'target_host', 'path', 'password_hash', 'expires_at'])]
final class HandoffToken extends Model
{
    use MassPrunable;

    /**
     * The value that binds a link to $user's current password (CD-3): the HMAC that AuthenticateSession keeps in the
     * session, keyed with APP_KEY, so the password hash itself is never stored a second time.
     */
    public static function passwordBindingFor(User $user): string
    {
        return Auth::guard('web')->hashPasswordForCookie($user->getAuthPassword());
    }

    /** Whether this link was issued under $user's current password; a link from before the binding (null) never was. */
    public function isBoundTo(User $user): bool
    {
        return is_string($this->password_hash) && hash_equals($this->password_hash, self::passwordBindingFor($user));
    }

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return self::query()->where('expires_at', '<', now()->subDay());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 5: `IssueHandoff` stores the binding**

`app/Actions/Auth/IssueHandoff.php`: replace the first paragraph of the class docblock

```php
 * Issues a single-use sign-in link for another host (§6.4): valid 60 seconds, bound to the user and the
 * target host, only for relative paths. Super admins may enter any host; members only their own
 * organisation's host (used after a slug rename). Session cookies stay host-only. Whether the user may
 * still sign in at all (active, not deleted) is decided when the link is used, by the host login rule.
```

with

```php
 * Issues a single-use sign-in link for another host (§6.4): valid 60 seconds, bound to the user, the target
 * host and the user's current password (CD-3: a password change or reset ends it), only for relative paths.
 * Super admins may enter any host; members only their own organisation's host (used after a slug rename).
 * Session cookies stay host-only. Whether the user may still sign in at all (active, not deleted) is decided
 * when the link is used, by the host login rule.
```

and the token insert in `handle()` with:

```php
        HandoffToken::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'target_host' => $targetHost,
            'path' => $path,
            'password_hash' => HandoffToken::passwordBindingFor($user),
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
        ]);
```

- [ ] **Step 6: `HandoffController` checks it**

`app/Http/Controllers/Auth/HandoffController.php`: replace `userOf()`, docblock included:

```php
    /**
     * The token's user, read without the tenant scope, when the link was issued under their current password and the
     * host login rule lets them in here. A password changed or reset since the link was issued, by any path, ends it,
     * as does a link issued before links were bound to the password (CD-3).
     */
    private function userOf(HandoffToken $handoff, HostLoginRule $rule): ?User
    {
        $user = Auth::createUserProvider('users')?->retrieveById($handoff->user_id);

        return $user instanceof User && $handoff->isBoundTo($user) && $rule->allows($user) ? $user : null;
    }
```

`resources/views/pages/settings/sessions/sessions.php` keeps its explicit `HandoffToken::query()->where('user_id', …)->whereNull('consumed_at')->delete()` (CD-3): it ends the links at once, inside its transaction, rather than at their next use.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Auth tests/Feature/Settings tests/Feature/Setup tests/Feature/Organisations tests/Feature/Impersonation`

Expected: PASS. Every existing handoff test issues and opens its link under an unchanged password.

- [ ] **Step 8: Verify by mutation**

For each mutation: copy the file aside with `cp`, change it, run `php artisan test --compact tests/Feature/Auth/HandoffTest.php`, confirm the named tests fail, then copy the original back and confirm the file passes (never `git stash`). Name each mutation and its result in the task report.

| # | mutation | the tests that must fail |
|---|---|---|
| 1 | `HandoffController::userOf()`: drop `$handoff->isBoundTo($user) &&` | 'refuses a handoff link issued before a password change or reset, …', all three data sets (the link signs in) |
| 2 | `isBoundTo()`: `return ! is_string($this->password_hash) \|\| hash_equals($this->password_hash, self::passwordBindingFor($user));` (a missing binding passes) | 'refuses a handoff link …' with "issued before the binding" |
| 3 | `passwordBindingFor()`: `return $user->getAuthPassword();` (the hash itself is stored) | 'binds a handoff link to the password it was issued under, …' (the stored value is the bcrypt hash) |
| 4 | `passwordBindingFor()`: `return Auth::guard('web')->hashPasswordForCookie((string) $user->id);` (no tie to the password) | 'binds a handoff link …' and 'refuses a handoff link …' with "changed on the security page" and "reset" |
| 5 | `IssueHandoff::handle()`: delete the `'password_hash' => …` line | 'binds a handoff link …' ("null is identical to '…'") and every existing test that opens a link (refused: 'signs a super admin in on another host with a single-use link', 'signs a user in on the apex and on the admin host (FU3-3)', …) |

- [ ] **Step 9: README and CHANGELOG**

`README.md`, › Accounts and security: insert this bullet directly after the **Passkeys and the security page** bullet (Task 5 owns the **Sessions** bullet and does not touch this one):

```markdown
- **Cross-host sign-in links.** *Open* on the organisations list, an organisation's new address after a rename, the setup wizard's last step and the demo quick login sign you in on another host with a link that works once, for 60 seconds, only on the host it was made for, and only while your password is the one you had when it was made: changing it on the security page, resetting it or setting it from the command line (`skv:create-super-admin --password-stdin`) ends every link made before. Opening links is limited to 10 a minute per IP address.
```

`CHANGELOG.md`, under `## [Unreleased]` › `### Security` (Task 3's heading), after Task 3's line:

```markdown
- A cross-host sign-in link is bound to the password it was issued under: changing or resetting the password, on any path, ends the links issued before, which until now still signed a browser in for their 60 seconds. The new column `handoff_tokens.password_hash` needs `php artisan migrate`; a link issued before the migration is refused.
```

No lang keys.

- [ ] **Step 10: The gate, then commit**

Run the Global Constraints gate:
- `composer check`
- `composer test:pgsql` (the migration runs on PostgreSQL through `RefreshDatabase`; the column is a plain `varchar(64)`)
- the modules-off run
- `npm run build && composer test:browser` (`tests/Browser/SetupWizardTest.php` and `tests/Browser/AppearanceTest.php` open handoff links under an unchanged password and must stay green)
- `npm run build:setup && git diff --exit-code resources/setup/setup.css` (no setup view changes, so expect no diff)
- `php artisan config:clear`

Do not run `php artisan migrate` against the developer database: report to the owner that the migration `0001_01_01_000010_add_password_hash_to_handoff_tokens_table` is pending there and that they apply it themselves (CD-19).

```bash
git add database/migrations/0001_01_01_000010_add_password_hash_to_handoff_tokens_table.php app/Models/HandoffToken.php \
    app/Actions/Auth/IssueHandoff.php app/Http/Controllers/Auth/HandoffController.php tests/Feature/Auth/HandoffTest.php \
    README.md CHANGELOG.md
git commit -m "fix: bind cross-host sign-in links to the password they were issued under

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

---

### Task 5: The sessions page (§6.6 sessions, §4.8; CD-18; E21, E17, E19, E23, E25, E26)

"Log out other devices" exists for one case above all: someone holds a copy of this browser's cookies. Today the action rehashes the password and deletes the user's *other* rows, but this device keeps its session id, and the response writes the new password hash into that same row, so a copied session cookie stays signed in (E21). This task gives this device a new session id inside the action's transaction (CD-18), keeps the lock order, and pins the order with a lock-order test (E26). It also closes the small sessions items: the platform slot of "Unknown browser on Unknown platform" is lower case mid-sentence (E17), the "ended and counted" test gets an expired row (E19), the wrong-password test checks that the field is emptied (E23), and the action's docblock is rewrapped (E25).

**Files:**
- Modify:
  - `resources/views/pages/settings/sessions/sessions.php` (`logoutOtherDevices()`: the new session id and the rewrapped docblock)
  - `resources/views/pages/settings/sessions/sessions.blade.php` (line 21: `__('unknown platform')`)
  - `lang/nl.json`, `lang/en.json` (1 key)
  - `README.md` (the **Sessions** bullet)
  - `CHANGELOG.md`
- Test:
  - `tests/Feature/Settings/SessionsTest.php` (a `$cookiesOf` closure, two new tests, three strengthened)
  - `tests/Feature/Authorization/LockOrderTest.php` (one new test, `handoff_tokens` in `$cd5Tables`)

**Interfaces:**
- Consumes:
  - `Illuminate\Session\Store::migrate(bool $destroy = false): bool` (a new id; the data and the CSRF token stay; `setExists(false)`, so the handler's `write()` reads the new id first and updates the row it finds)
  - `Illuminate\Session\DatabaseSessionHandler::write()` (inserts or, when `read()` found the id, updates the row; it runs at the end of the request, in `StartSession`)
  - `App\Actions\Auth\IssueHandoff::handle(User $user, string $targetHost, string $path = '/dashboard'): string` as Task 4 leaves it (it also stores the password hash; this task only creates a link with it)
  - `App\Tenancy\Hosts::tenant(string $slug): string`
  - test helpers: `sessionRowFor()`, `pageSnapshot()`, `callPageAction()`, `recordedActivity()`, `lockStatements()`, `asMember()`, `onTenant()`
- Produces:
  - After a successful `logoutOtherDevices()` the session has a new id. The response of that Livewire update sets the new session cookie; a request with the old cookie finds no session. Task 12's browser test of the modal (E69) relies on the browser keeping the cookie the update set.
  - Lang key `unknown platform` (nl "onbekend platform"). `Unknown platform` stays in both files (no task deletes a key).

Background for the implementer:
- **Why the row moves instead of `migrate(true)`.** CD-18 names `session()->migrate(true)`. With the database driver that destroys this device's row at once, and the handler writes the new row only at the end of the request, after Livewire has rendered. The list the same update renders then has no "This device" row until the next page load (a prototype measured 0 instead of 1 in `components.0.effects.html`). So the action calls `session()->migrate()` and moves the row to the new id with one `UPDATE sessions SET id = <new> WHERE id = <old>`. The old id is gone exactly as with `migrate(true)`: a request carrying it reads no row and starts a guest session. At the end of the request `write()` finds the moved row under the new id and updates it with the new password hash. With another session driver (the kit requires `database`, §5.4) the update touches nothing and the old session stays stored, but it still holds the old password hash, which `AuthenticateSession` refuses on its next request.
- **The order inside the transaction** stays DEC-34's: the `users` UPDATE (`Auth::logoutOtherDevices()`), then the `sessions` writes (the move, then the delete of every other row of the user), then the `handoff_tokens` DELETE, then the activity INSERT. The delete still uses `where id != session()->getId()`, now the new id, so the moved row stays and `count` is the number of other rows, expired ones included.
- **The CSRF token** stays (no `regenerateToken()`), so the page's other Livewire components keep working without a reload.
- **The tests' browsers.** `SessionsTest` already simulates browsers by their cookies (see its `$newProcess`, `$signIn` and `$useBrowser` closures). After the action this device holds the cookie the update response set, so the "remember me" test now continues with `[...$thisDevice, ...$cookiesOf($response)]`; the old `$thisDevice` cookies stand for the thief's copy.
- **E26 is a separate test, not a dataset row.** The dataset test checks explicit `FOR UPDATE` locks, and this action takes none: each write takes its row lock, so the order of the writes is the lock order. The test follows the file's rule and `AGENTS.md` (lock-order tests skip outside PostgreSQL); the order of the writes is the same on SQLite (a prototype passed there with the skip removed).

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/Settings/SessionsTest.php`:

1. Add the import `use App\Enums\Locale;` after `use App\Enums\ActivityEvent;`.

2. Replace the `$signIn` closure and its docblock with a `$cookiesOf` closure followed by the shorter `$signIn`:

```php
/**
 * The cookies a response set, encrypted as it set them: what the browser holds from then on.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, string>
 */
$cookiesOf = function (TestResponse $response): array {
    $cookies = [];

    foreach ($response->headers->getCookies() as $cookie) {
        $cookies[$cookie->getName()] = (string) $cookie->getValue();
    }

    return $cookies;
};

/**
 * Signs $user in through the login form in a new browser and returns the cookies that browser then holds, encrypted
 * as the response set them: the session and, with $remember, the "remember me" cookie.
 *
 * @return array<string, string>
 */
$signIn = function (User $user, bool $remember = false) use ($newProcess, $cookiesOf): array {
    $newProcess();

    return $cookiesOf(post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => $remember])->assertRedirect());
};
```

3. Directly after the first test ('lists the user's live sessions…'), add:

```php
it('names an unknown browser and platform in one sentence, the platform in lower case (§6.6)', function (Locale $locale, string $expected): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create(['locale' => $locale]);
    onTenant($organisation);
    sessionRowFor($member, ['user_agent' => null]);
    $this->actingAs($member);
    withSession(['auth.password_confirmed_at' => time()]);

    $this->get(route('security.edit'))->assertOk()->assertSee($expected);
})->with([
    'English' => [Locale::English, 'Unknown browser on unknown platform'],
    'Dutch' => [Locale::Dutch, 'Onbekende browser op onbekend platform'],
]);
```

4. Replace the test 'signs a second "remember me" browser out on its next request and keeps this device signed in (§6.6)' with it and a new test after it:

```php
it('signs a second "remember me" browser out on its next request and keeps this device signed in (§6.6)', function () use ($newProcess, $signIn, $useBrowser, $errorsOf, $cookiesOf): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    $laptop = $signIn($member, remember: true);
    $thisDevice = $signIn($member);
    $useBrowser($thisDevice);
    $snapshot = pageSnapshot(route('security.edit'), 'pages::settings.sessions');

    // Before: the laptop is signed in.
    $newProcess();
    call('GET', route('dashboard'), [], $laptop)->assertOk();
    $this->assertAuthenticatedAs($member);

    $useBrowser($thisDevice);
    $response = callPageAction($snapshot, 'logoutOtherDevices', updates: ['password' => 'password']);
    expect($errorsOf($response))->toBe([]);

    // Its session row is gone, and its remember cookie holds the old password hash, which AuthenticateSession refuses.
    $newProcess();
    call('GET', route('dashboard'), [], $laptop)->assertRedirect(route('login'));
    $this->assertGuest();

    // This device goes on with the session cookie the update set (CD-18).
    $newProcess();
    call('GET', route('dashboard'), [], [...$thisDevice, ...$cookiesOf($response)])->assertOk();
    $this->assertAuthenticatedAs($member);
});

it('gives this device a new session id, so a copy of its old cookie is signed out with the other devices (§6.6, CD-18)', function () use ($newProcess, $signIn, $useBrowser, $errorsOf, $cookiesOf, $rowsOf): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    // The cookies a thief copied from this browser before the member pressed the button.
    $thisDevice = $signIn($member);
    $useBrowser($thisDevice);
    $before = $rowsOf($member);
    $snapshot = pageSnapshot(route('security.edit'), 'pages::settings.sessions');

    $response = callPageAction($snapshot, 'logoutOtherDevices', updates: ['password' => 'password']);

    $after = $rowsOf($member);
    expect($errorsOf($response))->toBe([])
        ->and($before)->toHaveCount(1)
        ->and($after)->toHaveCount(1)
        ->and($after)->not->toBe($before)
        // The list the update renders still marks this device: its row moved to the new id.
        ->and(substr_count((string) $response->json('components.0.effects.html'), 'This device'))->toBe(1);

    $newProcess();
    call('GET', route('dashboard'), [], $thisDevice)->assertRedirect(route('login'));
    $this->assertGuest();

    $newProcess();
    call('GET', route('dashboard'), [], [...$thisDevice, ...$cookiesOf($response)])->assertOk();
    $this->assertAuthenticatedAs($member);
    expect($rowsOf($member))->toBe($after);
});
```

5. In 'ends only the user's other sessions and records how many (§4.8, CD-9)', replace from `sessionRowFor($member);` through the end of the test with (E19, and this device's row now has a new id):

```php
    $others = [
        sessionRowFor($member),
        sessionRowFor($member, ['user_agent' => null, 'ip_address' => null]),
        // Expired: not listed, but ended and counted all the same.
        sessionRowFor($member, ['last_activity' => now()->subDay()->getTimestamp()]),
    ];
    $colleagueRow = sessionRowFor($colleague);
    $passwordHash = (string) $member->fresh()?->password;
    $snapshot = pageSnapshot(route('security.edit'), 'pages::settings.sessions');

    expect($errorsOf(callPageAction($snapshot, 'logoutOtherDevices', updates: ['password' => 'password'])))->toBe([]);

    $remaining = $rowsOf($member);
    // One row is left: this device's, under its new id (CD-18).
    expect($remaining)->toHaveCount(1)
        ->and(array_intersect($remaining, [...$others, ...$thisDevice]))->toBe([])
        ->and($rowsOf($colleague))->toBe([$colleagueRow])
        ->and((string) $member->fresh()?->password)->not->toBe($passwordHash);
    $entry = recordedActivity(ActivityEvent::OtherSessionsEnded)->sole();
    expect([$entry->subject_id, $entry->causer_id, $entry->organisation_id])->toBe([$member->id, $member->id, $organisation->id])
        ->and($entry->properties?->all())->toBe(['count' => 3]);
});
```

6. In 'refuses a wrong password on the password field and ends nothing (CD-2)', replace

```php
    expect($errorsOf(callPageAction($snapshot, 'logoutOtherDevices', updates: ['password' => 'wrong-password'])))->toHaveKey('password');

    expect($rowsOf($member))->toBe($rows)
```

with (E23)

```php
    $response = callPageAction($snapshot, 'logoutOtherDevices', updates: ['password' => 'wrong-password']);

    /** @var array{data: array{password: string}} $after */
    $after = json_decode((string) $response->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR);
    expect($errorsOf($response))->toHaveKey('password')
        // The modal stays open with an empty field, not the password just refused.
        ->and($after['data']['password'])->toBe('');

    expect($rowsOf($member))->toBe($rows)
```

In `tests/Feature/Authorization/LockOrderTest.php`:

1. Change the docblock to `/** covers §4.3, §4.4, §4.5, §4.6, §6.3, §6.6 */`; add `use App\Actions\Auth\IssueHandoff;` before `use App\Actions\Invitations\AcceptInvitation;` and `use App\Tenancy\Hosts;` after `use App\Models\User;`.

2. In the dataset test, replace the `$cd5Tables` line with:

```php
    $cd5Tables = ['organisations', 'roles', 'users', 'model_has_roles', 'invitations', 'sessions', 'handoff_tokens', 'password_reset_tokens'];
```

3. Append at the end of the file:

```php
it('rehashes the password, then moves and ends the sessions, then deletes the handoff links, then records ("log out other devices", §6.6)', function (): void {
    // The kit's session driver, so the update moves and deletes real rows. No explicit lock: each write takes its
    // row lock, so the order of the writes is the lock order.
    config(['session.driver' => 'database']);
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = asMember($organisation);
    sessionRowFor($member);
    app(IssueHandoff::class)->handle($member, Hosts::tenant('acme'));
    $page = Livewire::test('pages::settings.sessions')->set('password', 'password');

    $statements = lockStatements(fn () => $page->call('logoutOtherDevices')->assertHasNoErrors());
    $phases = [];

    foreach ($statements as $entry) {
        $phase = match ($entry['statement'].' '.$entry['table']) {
            'update users' => 'users',
            'update sessions', 'delete sessions' => 'sessions',
            'delete handoff_tokens' => 'handoff_tokens',
            'insert activity_log' => 'activity_log',
            default => null,
        };

        // Consecutive writes of one phase count once: this device's row moves, then the other rows go.
        if ($phase !== null && end($phases) !== $phase) {
            $phases[] = $phase;
        }
    }

    expect($phases)->toBe(['users', 'sessions', 'handoff_tokens', 'activity_log']);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'needs PostgreSQL row locks');
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Settings/SessionsTest.php`

Expected: FAIL, 4 of 11:
- 'names an unknown browser and platform…' fails in both rows: the page says "Unknown browser on Unknown platform" / "Onbekende browser op Onbekend platform".
- 'gives this device a new session id…' fails on `->not->toBe($before)`: the row keeps its id.
- 'ends only the user's other sessions…' fails on `array_intersect(...)`: this device's old id is still there.

'signs a second "remember me" browser out…' and 'refuses a wrong password…' still pass: they are strengthened, and Step 5 proves them by mutation. The new `LockOrderTest` test is skipped on SQLite; it already passes on PostgreSQL against today's code (a weak-test item, proved by mutation in Step 5).

- [ ] **Step 3: The new session id**

In `resources/views/pages/settings/sessions/sessions.php`, replace the docblock and the transaction of `logoutOtherDevices()` (E21, E25):

```php
    /**
     * Logs out every other device (§6.6): the password is rehashed, so AuthenticateSession signs out every other
     * session and "remember me" cookie on its next request, and this device gets a fresh remember cookie
     * (Auth::logoutOtherDevices()). This device also gets a new session id (CD-18): its row moves to the new id, so
     * a copy of its old cookie finds no session, while the list rendered by this update still marks this device.
     * Then the user's other rows and their unused handoff links are deleted. Every attempt counts against the
     * user's limit before the password is checked (CD-2), so the form cannot be used to guess it. One transaction
     * in DEC-34's lock order: users, then sessions and handoff tokens, then the activity insert.
     */
    public function logoutOtherDevices(RecordActivity $recordActivity): void
    {
        $user = $this->currentUser();

        try {
            UserActionLimit::logoutOtherDevices()->attempt($user, 'password');

            $this->validate(['password' => $this->currentPasswordRules()]);
        } catch (ValidationException $e) {
            $this->reset('password');

            throw $e;
        }

        DB::transaction(function () use ($user, $recordActivity): void {
            Auth::logoutOtherDevices($this->password);

            // The response sets the new id's cookie; the session keeps its data and its CSRF token.
            $previousId = session()->getId();
            session()->migrate();
            DB::table('sessions')->where('id', $previousId)->update(['id' => session()->getId()]);

            $ended = DB::table('sessions')
                ->where('user_id', $user->id)
                ->where('id', '!=', session()->getId())
                ->delete();

            // A handoff link issued by another session would sign that browser in again with the new hash (§6.4).
            HandoffToken::query()->where('user_id', $user->id)->whereNull('consumed_at')->delete();

            $recordActivity->handle(ActivityEvent::OtherSessionsEnded, $user, ['count' => $ended]);
        });

        $this->reset('password');
        unset($this->sessions);

        Flux::modal('logout-other-devices')->close();
        Flux::toast(variant: 'success', text: __('You were logged out on your other devices.'));
    }
```

No import changes: `DB` and `Auth` are already imported.

- [ ] **Step 4: The platform slot and its key**

In `resources/views/pages/settings/sessions/sessions.blade.php`, line 21, replace `__('Unknown platform')` with `__('unknown platform')`; the line becomes:

```blade
                                <p class="font-medium tracking-tight">{{ __(':browser on :platform', ['browser' => $session['browser'] ?? __('Unknown browser'), 'platform' => $session['platform'] ?? __('unknown platform')]) }}</p>
```

Lang key, in both files, at its sorted position (PHP `sort()` order: lower-case keys come after every upper-case key). In `en.json` the value equals the key.

| Key | nl | Position |
|---|---|---|
| `unknown platform` | `onbekend platform` | right after `to`, before `waiting` |

`Unknown browser` stays the sentence's first word, so it keeps its capital. `Unknown platform` stays in both files unused.

Run `php artisan view:clear`.

- [ ] **Step 5: Run the tests to verify they pass, then verify by mutation**

Run: `php artisan test --compact tests/Feature/Settings tests/Feature/Authorization/LockOrderTest.php tests/Feature/LangFilesTest.php tests/Feature/LangCoverageTest.php tests/Feature/Auth`

Expected: PASS (the `LockOrderTest` tests are skipped on SQLite).

Then the PostgreSQL lock-order test, on the test database (never the development database):

```bash
php artisan config:clear
php scripts/create-pgsql-test-database.php
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=$(php scripts/create-pgsql-test-database.php --print-name) DB_USERNAME=root DB_PASSWORD= \
    vendor/bin/pest tests/Feature/Authorization/LockOrderTest.php
```

Expected: PASS, the new test included.

Revert each change locally, run the named test, expect it to fail, then restore the file. Never use `git stash`: copy the file aside (`cp <file> <scratchpad>/<name>.bak`), edit it, restore it with `cp`, and run `php artisan view:clear` after every restore (the files are component files).

| Mutation (in `sessions.php` unless named) | Test that must fail |
|---|---|
| Drop the three new lines (`$previousId`, `migrate()`, the move) | 'gives this device a new session id…' (`->not->toBe($before)`) and 'ends only the user's other sessions…' (`array_intersect`) |
| Replace the three lines with `session()->migrate(true);` (CD-18 as worded) | 'gives this device a new session id…': "This device" appears 0 times in the update's HTML instead of once |
| Drop only the move (`DB::table('sessions')->where('id', $previousId)->update(...)`) | 'gives this device a new session id…' (0 instead of 1) and 'ends only the user's other sessions…' (the count is 4) |
| Add `->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime'))->getTimestamp())` to the delete (E19) | 'ends only the user's other sessions…': two rows remain |
| Drop `$this->reset('password');` from the `catch` (E23) | 'refuses a wrong password on the password field…': `password` is `'wrong-password'` |
| In `sessions.blade.php`, back to `__('Unknown platform')` (E17) | both rows of 'names an unknown browser and platform…' |
| Move `Auth::logoutOtherDevices($this->password);` down to just above the handoff delete (E26) | PostgreSQL run: 'rehashes the password, then moves and ends the sessions…' (`sessions` before `users`) |
| Move the `HandoffToken` delete up to just after `Auth::logoutOtherDevices(...)` | PostgreSQL run: the same test (`handoff_tokens` before `sessions`) |
| Move `$recordActivity->handle(...)` up to just above the handoff delete | PostgreSQL run: the same test (`activity_log` before `handoff_tokens`) |

Run the three PostgreSQL mutations with the `DB_CONNECTION=pgsql … vendor/bin/pest tests/Feature/Authorization/LockOrderTest.php` line above, then `php artisan config:clear`.

- [ ] **Step 6: README and CHANGELOG**

In `README.md`, in the **Sessions** bullet, replace

```markdown
On their next request it signs out every other session and every "remember me" cookie, on every host, and it ends your unused cross-host sign-in links; the audit log records how many sessions it ended (*Other devices signed out*).
```

with

```markdown
On their next request it signs out every other session and every "remember me" cookie, on every host, and it ends your unused cross-host sign-in links. It also gives this device a new session, so a copy of this browser's session cookie is signed out too. The audit log records how many sessions it ended (*Other devices signed out*).
```

In `CHANGELOG.md`, under `## [Unreleased]`, `### Security` (after `### Removed`; Tasks 3 and 4 create the heading, create it there if it is still missing), append:

```markdown
- *Log out other devices* also gives this device a new session id, so a copy of this browser's session cookie is signed out with the other devices.
```

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

The browser suite has no sessions test yet (Task 12 adds it, E69); it must stay green. Task 5 changes no setup view, so expect no stylesheet diff.

```bash
git add resources/views/pages/settings/sessions/sessions.php resources/views/pages/settings/sessions/sessions.blade.php \
    lang/nl.json lang/en.json README.md CHANGELOG.md \
    tests/Feature/Settings/SessionsTest.php tests/Feature/Authorization/LockOrderTest.php
git commit -m "fix: give this device a new session id when it logs out the other devices

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

---

### Task 6: Second factor, organisation edit and recovery codes (§6.6 second factor, §6.8, §4.2, §4.3; no CD; E35, E31, E34, E37, E40, E41, E43, D17, D18)

Two behaviour fixes and seven tests that pin what M7d left unguarded. The fixes:
- The security page's second-factor callout ignores impersonation, so it tells an impersonator to set up the target's factor, which they cannot do. `EnsureSecondFactor` already lets an impersonation session through (E35).
- `storedRecoveryCodes()` catches only `DecryptException`. A value encrypted without serialising makes `unserialize()` warn, so the security page answers 500 (D17). A JSON object, an empty list or a list with non-strings is read as codes, so *Download* streams an empty or partial file (D18).

The tests:
- turning the rule off records `old` and the entry count (E31);
- a super admin also passes the password confirmation on the organisation edit page (E34);
- a committed brief instruction becomes a comment (E37);
- the wildcard exemptions match an explicit route list (E40);
- HEAD is redirected like GET, and an impersonated Livewire update passes (E41);
- removing the last factor stays allowed under the rule (E43).

**Files:**
- Modify:
  - `resources/views/pages/settings/security/security.php` (`secondFactorNotice()` and the `Impersonation` import)
  - `resources/views/pages/settings/two-factor/recovery-codes/recovery-codes.php` (`storedRecoveryCodes()` and its docblock)
  - `CHANGELOG.md`
- Test:
  - `tests/Feature/Auth/EnsureSecondFactorTest.php` (five new tests, one comment)
  - `tests/Feature/Organisations/UpdateOrganisationTest.php` (one strengthened test)
  - `tests/Feature/Organisations/OrganisationEditTest.php` (one test gets a super-admin dimension)
  - `tests/Feature/Settings/RecoveryCodesTest.php` (two datasets)

**Interfaces:**
- Consumes:
  - `App\Tenancy\Auth\Impersonation::isActive(): bool`, `Impersonation::appearanceFor(User $user): Appearance`
  - `App\Tenancy\Auth\SecondFactor::missingFor(User $user): bool`, `SecondFactor::requiredFor(User $user): bool`, `SecondFactor::explanation(User $user): string`
  - `App\Support\Skv::passkeysEnabled(): bool` (read at the start of a test, before any `usePasskeysModule()`, it is the flag the app booted with, so it says which routes exist)
  - `Laravel\Fortify\Fortify::currentEncrypter()` (the app encrypter: `decrypt()` unserialises)
  - the `user-menu` component's `saveAppearance(string $appearance, UpdateAppearance $updateAppearance): void` (during an impersonation `UpdateAppearance` keeps the choice in the session)
  - test helpers: `asOrgAdmin()`, `asSuperAdmin()`, `onAdminHost()`, `onTenant()`, `pageSnapshot()`, `callPageAction()`, `startImpersonatingOverHttp()`, `useImpersonationModule()`, `recordedActivity()`
- Produces:
  - `secondFactorNotice()` returns null while `Impersonation::isActive()`.
  - `storedRecoveryCodes(User $user): ?array` returns a non-empty `list<string>` or null.

Background for the implementer:
- **The recovery-codes component has no namespace** (an anonymous class in a Blade-side `.php` file). Write `catch (DecryptException|ErrorException)` without importing `ErrorException`: `use ErrorException;` in a file without a namespace is the PHP warning "The use statement with non-compound name 'ErrorException' has no effect", which Laravel turns into a 500 on every security page (a prototype hit it).
- **Where D17 is proved.** Under `Livewire::test()` the `unserialize()` warning does not surface as an exception (a prototype row passed before the fix), so the "encrypted without serialising" row lives in the HTTP test 'renders the security page with an error…', which answers 500 before the fix.
- **E40 and the modules.** The passkey routes are registered only when the app boots with `MODULE_PASSKEYS` on (`routes/web.php`, `AppServiceProvider::configureModules()`), and CI also runs the suite with the module off. The expected list therefore takes the passkey names only when `Skv::passkeysEnabled()`, read before the test changes any module. `setup.*` and `two-factor.*` are registered in both runs.
- **E41's impersonated update** takes the dashboard's `user-menu` snapshot during the impersonation while the organisation has no rule, then switches the rule on, as the K7 test does for a blocked page. A replayed update then passes only through the impersonation exemption, not through the GET that loaded the page.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/Auth/EnsureSecondFactorTest.php`:

1. Imports: add `use App\Enums\Appearance;` after `use App\Authorization\Role;`, and after `use App\Models\User;`:

```php
use App\Support\Skv;
use App\Tenancy\Auth\Impersonation;
use App\Tenancy\Auth\SecondFactor;
```

2. After the test 'refuses any other method with 403 (§6.6)', add (E41, HEAD):

```php
it('sends a HEAD request to the security page like a GET (§6.6)', function (string $method) use ($memberUnderTheRule): void {
    $memberUnderTheRule();

    $this->call($method, route('dashboard'))->assertRedirect(route('security.edit'));
})->with(['GET', 'HEAD']);
```

3. After the test 'lets an impersonation session through: the impersonator acts and cannot set up the target's factor (§6.6, CD-4)', add (E41, the replayed update; E35):

```php
it('lets an impersonation session\'s Livewire update through on a page the rule now blocks (§6.6, CD-4)', function (): void {
    useImpersonationModule(true);
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $admin = User::factory()->for($acme)->create();
    withPermissionsTeam($acme->id, fn (): User => $admin->assignRole(Role::OrganisationAdmin->value));
    $member = User::factory()->for($acme)->create();
    onTenant($acme);
    startImpersonatingOverHttp($admin, $member);
    $snapshot = pageSnapshot(route('dashboard'), 'user-menu');

    $acme->forceFill(['require_second_factor' => true])->save();

    // The replayed persistent middleware passes the impersonation, so the update runs.
    callPageAction($snapshot, 'saveAppearance', ['dark'])->assertOk();
    expect(Impersonation::appearanceFor($member))->toBe(Appearance::Dark);
});

it('shows no second-factor notice on the security page during an impersonation (§6.6, CD-4)', function (): void {
    useImpersonationModule(true);
    $acme = Organisation::factory()->create(['slug' => 'acme', 'require_second_factor' => true]);
    $admin = User::factory()->for($acme)->create();
    withPermissionsTeam($acme->id, fn (): User => $admin->assignRole(Role::OrganisationAdmin->value));
    $member = User::factory()->for($acme)->create();
    onTenant($acme);

    startImpersonatingOverHttp($admin, $member);
    $this->withSession(['auth.password_confirmed_at' => time()]);

    // The impersonator cannot set up the member's factor, so the page does not ask them to.
    $this->get(route('security.edit'))
        ->assertOk()
        ->assertDontSee('data-test="second-factor-required"', escape: false)
        ->assertDontSee(SecondFactor::explanation($member));
});
```

4. In 'refuses a Livewire update from a page the rule now blocks (CD-4, plan review K7)', replace the two comment lines (E37)

```php
    // The replayed persistent middleware answers with abort(redirect): check the exact status Livewire returns and
    // assert it (a redirect to security.edit); the point is that the action did not run.
```

with

```php
    // The replayed persistent middleware ends the update with abort(redirect()): Livewire answers with that redirect
    // to the security page, and saveAccount() never runs.
```

5. After the test 'exempts setting up a factor, the password confirmation, signing out, handoffs, ending an impersonation and setup by default (CD-4)', add (E40):

```php
it('exempts by wildcard only the routes that set up or check a factor and the setup wizard\'s, so a new route never joins unnoticed (CD-4)', function (): void {
    // The passkey routes exist only when the app booted with the module on (§6.8).
    $passkeyRoutes = Skv::passkeysEnabled() ? [
        'passkey.confirm',
        'passkey.confirm-options',
        'passkey.destroy',
        'passkey.login',
        'passkey.login-options',
        'passkey.registration-options',
        'passkey.store',
    ] : [];
    $names = [];

    foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
        $name = $route->getName();

        if ($name !== null && Str::is(['two-factor.*', 'passkey.*', 'setup.*'], $name)) {
            $names[] = $name;
        }
    }

    sort($names);

    expect($names)->toBe([
        ...$passkeyRoutes,
        'setup.finish',
        'setup.herd-status',
        'setup.login',
        'setup.wizard',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.enable',
        'two-factor.login',
        'two-factor.login.store',
        'two-factor.qr-code',
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        'two-factor.secret-key',
    ]);
});
```

6. Append at the end of the file (E43):

```php
it('lets a member under the rule turn off their only factor, and then sends them back to set one up (§6.6)', function () use ($memberUnderTheRule): void {
    $member = $memberUnderTheRule();
    $member->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])), 'two_factor_confirmed_at' => now()])->save();
    $this->withSession(['auth.password_confirmed_at' => time()]);
    $this->get(route('dashboard'))->assertOk();
    $snapshot = pageSnapshot(route('security.edit'), 'pages::settings.security');

    callPageAction($snapshot, 'disable')->assertOk();

    expect($member->fresh()?->hasEnabledTwoFactorAuthentication())->toBeFalse();
    $this->get(route('dashboard'))->assertRedirect(route('security.edit'));
});
```

In `tests/Feature/Organisations/UpdateOrganisationTest.php`, in 'records the second-factor rule in organisation.updated's changes (§6.6, CD-3)', replace the last expectation (E31)

```php
    expect($organisation->fresh()?->require_second_factor)->toBeFalse()
        ->and(recordedActivity(ActivityEvent::OrganisationUpdated)->last()?->attribute_changes?->get('attributes'))->toBe(['require_second_factor' => false]);
```

with

```php
    $entries = recordedActivity(ActivityEvent::OrganisationUpdated);
    expect($organisation->fresh()?->require_second_factor)->toBeFalse()
        ->and($entries)->toHaveCount(2)
        ->and($entries->last()?->attribute_changes?->get('old'))->toBe(['require_second_factor' => true])
        ->and($entries->last()?->attribute_changes?->get('attributes'))->toBe(['require_second_factor' => false]);
```

In `tests/Feature/Organisations/OrganisationEditTest.php`, in 'asks for a fresh password confirmation before changing the second-factor rule and comes back to this page with the unsaved form (CD-3)' (E34):

1. Replace the opening three lines

```php
it('asks for a fresh password confirmation before changing the second-factor rule and comes back to this page with the unsaved form (CD-3)', function (bool $before): void {
    $acme = Organisation::factory()->create(['name' => 'Acme', 'slug' => 'acme', 'description' => null, 'require_second_factor' => $before]);
    asOrgAdmin($acme)->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
```

with

```php
it('asks for a fresh password confirmation before changing the second-factor rule and comes back to this page with the unsaved form (CD-3)', function (string $actor, bool $before): void {
    $acme = Organisation::factory()->create(['name' => 'Acme', 'slug' => 'acme', 'description' => null, 'require_second_factor' => $before]);

    if ($actor === 'a super admin') {
        // No second factor of their own needed: the organisation's rule is not theirs.
        asSuperAdmin();
        onAdminHost();
    } else {
        asOrgAdmin($acme)->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    }

```

2. Replace its last line `})->with(['turning it on' => false, 'turning it off' => true]);` with

```php
})->with(['an organisation admin', 'a super admin'])->with(['turning it on' => false, 'turning it off' => true]);
```

The rest of the test is unchanged: on the admin host `route()` builds the admin host's URLs, and the super admin's final save keeps the slug `acme`.

In `tests/Feature/Settings/RecoveryCodesTest.php` (D17, D18):

1. Add `use Illuminate\Support\Facades\Crypt;` after `use Illuminate\Encryption\Encrypter;`.

2. Extend the dataset of 'refuses to download stored codes it cannot read instead of failing with a server error (G2-07)' to:

```php
})->with([
    'encrypted with another key' => [fn (): string => new Encrypter(random_bytes(32), (string) config('app.cipher'))->encrypt(json_encode(['recovery-code-1']))],
    'not a list of codes' => [fn (): string => encrypt('not json')],
    'a JSON object' => [fn (): string => encrypt(json_encode(['first' => 'recovery-code-1']))],
    'a list of numbers' => [fn (): string => encrypt(json_encode([1, 2]))],
    'a list with a number in it' => [fn (): string => encrypt(json_encode(['recovery-code-1', 2]))],
    'an empty list' => [fn (): string => encrypt(json_encode([]))],
]);
```

3. Replace the test 'renders the security page with an error when the stored codes are not a list (G2-07)' with:

```php
it('renders the security page with an error when the stored codes cannot be read (G2-07)', function (Closure $stored): void {
    $user = memberWithRecoveryCodes();
    $user->forceFill(['two_factor_recovery_codes' => $stored()])->save();

    $this->get(route('security.edit'))->assertOk()->assertSee(__('Failed to load recovery codes'));
})->with([
    'not a list of codes' => [fn (): string => encrypt('not json')],
    // Fortify serialises before it encrypts; unserialize() warns about this value, which is a 500 without the catch.
    'encrypted without serialising' => [fn (): string => Crypt::encryptString((string) json_encode(['recovery-code-1']))],
    'a list of numbers' => [fn (): string => encrypt(json_encode([1, 2]))],
]);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Auth/EnsureSecondFactorTest.php tests/Feature/Organisations/UpdateOrganisationTest.php tests/Feature/Organisations/OrganisationEditTest.php tests/Feature/Settings/RecoveryCodesTest.php`

Expected: FAIL, 7 tests:
- 'shows no second-factor notice on the security page during an impersonation' fails: the page contains `data-test="second-factor-required"`.
- 'refuses to download stored codes it cannot read…' fails for 'a JSON object', 'a list of numbers', 'a list with a number in it' and 'an empty list': "Component has no errors."
- 'renders the security page with an error when the stored codes cannot be read' fails for 'encrypted without serialising' (500, `ErrorException: unserialize(): Error at offset 0`) and 'a list of numbers' (no error on the page).

The other new and strengthened tests pass against today's code (weak-test items, E31, E34, E40, E41, E43); Step 5 proves them by mutation. With the passkeys module off (`MODULE_PASSKEYS=false`) the E40 test passes too, with the passkey names left out.

- [ ] **Step 3: No callout during an impersonation**

In `resources/views/pages/settings/security/security.php`, add `use App\Tenancy\Auth\Impersonation;` before `use App\Tenancy\Auth\SecondFactor;`, and replace `secondFactorNotice()` and its docblock (E35):

```php
    /**
     * Why this page opened instead of the one asked for (§6.6, CD-4): the explanation while the second-factor rule
     * applies to the user and is unmet, otherwise null. Read on every render, so it goes away as soon as a factor is
     * set up on this page. Null during an impersonation: EnsureSecondFactor lets it through, and the impersonator
     * cannot set up the target's factor.
     */
    #[Computed]
    public function secondFactorNotice(): ?string
    {
        $user = $this->currentUser();

        return Impersonation::isActive() || ! SecondFactor::missingFor($user) ? null : SecondFactor::explanation($user);
    }
```

- [ ] **Step 4: Read only a non-empty list of strings**

In `resources/views/pages/settings/two-factor/recovery-codes/recovery-codes.php`, replace `storedRecoveryCodes()` and its docblock (D17, D18). Do not add a `use ErrorException;` line (see the background):

```php
    /**
     * The stored codes, or null when they cannot be read (G2-07): encrypted with another APP_KEY that
     * APP_PREVIOUS_KEYS does not list, encrypted without being serialised first (unserialize() warns, which Laravel
     * turns into an ErrorException), or not a non-empty JSON list of strings. Decrypted like Fortify's
     * recoveryCodes().
     *
     * @return list<string>|null
     */
    private function storedRecoveryCodes(User $user): ?array
    {
        try {
            $decrypted = Fortify::currentEncrypter()->decrypt((string) $user->two_factor_recovery_codes);
        } catch (DecryptException|ErrorException) {
            return null;
        }

        $codes = is_string($decrypted) ? json_decode($decrypted, true) : null;

        if (! is_array($codes) || $codes === [] || ! array_is_list($codes)) {
            return null;
        }

        $strings = [];

        foreach ($codes as $code) {
            if (! is_string($code)) {
                return null;
            }

            $strings[] = $code;
        }

        return $strings;
    }
```

Both callers already treat null as "Failed to load recovery codes". Run `php artisan view:clear`.

- [ ] **Step 5: Run the tests to verify they pass, then verify by mutation**

Run: `php artisan test --compact tests/Feature/Auth tests/Feature/Organisations tests/Feature/Settings tests/Feature/Impersonation`

Expected: PASS.

Then the E40 test with the module off:

```bash
php artisan config:clear && MODULE_PASSKEYS=false MODULE_IMPERSONATION=false vendor/bin/pest tests/Feature/Auth/EnsureSecondFactorTest.php
php artisan config:clear
```

Expected: PASS.

Revert each change locally, run the named test, expect it to fail, then restore the file. Never use `git stash`: copy the file aside (`cp <file> <scratchpad>/<name>.bak`), edit it, restore it with `cp`, and run `php artisan view:clear` after every restore of a component file. Every mutation was run against a prototype.

| Mutation | Test that must fail |
|---|---|
| `security.php`: `return SecondFactor::missingFor($user) ? SecondFactor::explanation($user) : null;` (E35) | 'shows no second-factor notice on the security page during an impersonation' |
| `recovery-codes.php`: `catch (DecryptException)` (D17) | 'renders the security page with an error…' / 'encrypted without serialising' (500) |
| `recovery-codes.php`: drop `\|\| ! array_is_list($codes)` (D18) | 'refuses to download stored codes…' / 'a JSON object' |
| `recovery-codes.php`: drop `$codes === [] \|\|` (D18) | 'refuses to download stored codes…' / 'an empty list' |
| `recovery-codes.php`: `continue;` instead of `return null;` in the loop (D18) | 'refuses to download stored codes…' / 'a list of numbers' and 'a list with a number in it'; 'renders the security page…' / 'a list of numbers' |
| `app/Http/Middleware/EnsureSecondFactor.php`: `['GET']` instead of `['GET', 'HEAD']` (E41) | 'sends a HEAD request to the security page like a GET' / HEAD (403) |
| `EnsureSecondFactor.php`: drop `Impersonation::isActive() \|\|` from `exempt()` (E41) | 'lets an impersonation session's Livewire update through…' (302), and 'lets an impersonation session through…' |
| `routes/web.php`: after `require __DIR__.'/setup.php';` add `Route::get('two-factor-export', fn (): string => 'codes')->middleware('auth')->name('two-factor.export');` (E40) | 'exempts by wildcard only the routes…' |
| `security.php`: in `disable()`, before `$disableTwoFactorAuthentication(...)`, add `if (SecondFactor::requiredFor($this->currentUser())) { return; }` (E43) | 'lets a member under the rule turn off their only factor…' (still enabled) |
| `app/Actions/Organisations/UpdateOrganisation.php`: `$old[$field] = $field === 'require_second_factor' ? false : $locked->getOriginal($field);` (E31) | 'records the second-factor rule in organisation.updated's changes' (`old` is `false`) |
| `resources/views/pages/organisations/edit/edit.php`: in `confirmationParked()`, `if (! $this->currentUser()->isSuperAdmin() && $this->passwordConfirmationRequired(...))` (E34) | 'asks for a fresh password confirmation…' / 'a super admin' rows (no redirect to `password.confirm`) |

- [ ] **Step 6: CHANGELOG**

Under `## [Unreleased]`, `### Fixed`, append:

```markdown
- During an impersonation the security page no longer asks the impersonator to set up the member's second factor.
- Recovery codes stored in a form Fortify never writes (encrypted without serialising, a JSON object, an empty list or a list with other values than codes) show *Failed to load recovery codes* instead of a server error or an empty download.
```

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

Task 6 changes no setup view and adds no lang key, so expect no stylesheet diff.

```bash
git add resources/views/pages/settings/security/security.php \
    resources/views/pages/settings/two-factor/recovery-codes/recovery-codes.php CHANGELOG.md \
    tests/Feature/Auth/EnsureSecondFactorTest.php tests/Feature/Organisations/UpdateOrganisationTest.php \
    tests/Feature/Organisations/OrganisationEditTest.php tests/Feature/Settings/RecoveryCodesTest.php
git commit -m "fix: hide the second-factor callout during an impersonation and read only valid recovery codes

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

---

### Task 7: Uploads, bulk invitations and photos (§4.9, §6.6, §4.5; CD-4; E5, E11, E12, E14, E16, E55, E56, E59)

DEC-35's Consequence left Livewire's temporary uploads without a scheduled clean-up, and M7d's reviews left five small faults on the bulk page and the photo actions. This task closes them:
- **E5 (CD-4):** Livewire deletes a temporary upload older than 24 hours only when someone uploads again (`WithFileUploads::cleanupOldUploads()`), so a photo with its EXIF and GPS data, or a CSV of addresses, left on an abandoned page stays on disk until the next upload. A new `App\Support\Uploads\StaleTemporaryUploads::removeStale()` mirrors Livewire's sweep (same disk, same directory, same 24 hours, nothing on S3), and the ops schedule runs it as `livewire-tmp-cleanup`, hourly at :45 on every server.
- **E11:** on a central host, *Check* without an organisation and without a role says "Choose at least one role." instead of "Choose an organisation first.": `check()` and `send()` resolve the organisation before they validate the batch.
- **E12, E16:** `send()` catches only `ValidationException` per row, so a queue push that fails or an authorisation lost halfway answers 500 and shows nothing of what was already sent. A `catch (Throwable)` around the loop reports the failure, skips the failed row and the rows after it with "Not sent: something went wrong." and still sets the summary; the `finally` release (E16) gives the unsent rows their attempts back.
- **E14:** the bulk page's `<flux:error name="organisation" />` names no property and can never show anything; the select already shows `organisationId`'s error. The line goes.
- **E55:** removing a photo when there is none toasts "Profile photo removed." and re-renders the user menu. `RemoveAvatar::handle()` returns whether it removed one, and the page confirms only then.
- **E56:** `UpdateAvatar` refuses only by size; a future caller without the page's `mimes` rule could make it decode every frame of an animated GIF. It now refuses any header type but JPEG, PNG and WebP before decoding.
- **E59:** the only "no EXIF in the stored WebP" assertion sits in the test that is skipped without ext-exif. It moves into a test that runs everywhere.

**Files:**
- Create:
  - `app/Support/Uploads/StaleTemporaryUploads.php`
  - `tests/Feature/Uploads/StaleTemporaryUploadsTest.php`
- Modify:
  - `app/Support/Scheduler/OpsSchedule.php` (the `livewire-tmp-cleanup` entry after `backup-temp-cleanup`, its import, the class docblock)
  - `resources/views/pages/invitations/bulk/bulk.php` (the class docblock; `check()` and `send()`: the organisation before `validateBatch()`; `send()`: the `catch (Throwable)` block)
  - `resources/views/pages/invitations/bulk/bulk.blade.php` (the dead `<flux:error name="organisation" />` line)
  - `app/Actions/Users/RemoveAvatar.php` (`handle(): bool`, the class docblock)
  - `app/Actions/Users/UpdateAvatar.php` (the `TYPES` constant, the header check, the class docblock)
  - `resources/views/pages/settings/profile/profile.php` (`removeAvatar()`)
  - `lang/nl.json`, `lang/en.json` (one key)
  - `README.md` (the Scheduler section: its paragraph and one table row)
  - `CHANGELOG.md` (Added, Fixed and Security lines)
- Test:
  - `tests/Feature/Uploads/StaleTemporaryUploadsTest.php` (new)
  - `tests/Feature/Scheduler/ScheduleTest.php` (the job list, the timing dataset, the module test, one new test)
  - `tests/Feature/Scheduler/ScheduledTasksTest.php` (the System page's copy)
  - `tests/Feature/Invitations/BulkInvitationsTest.php` (the central-host test strengthened, one new test)
  - `tests/Feature/Settings/AvatarTest.php` (the EXIF test split, four new tests)
  - unchanged and green: `tests/Feature/System/SystemPageTest.php` (it renders the schedule), `tests/Feature/Authorization/LockOrderTest.php` (calls `RemoveAvatar`), `tests/Feature/LangCoverageTest.php`, `tests/Feature/LangFilesTest.php`

**Interfaces:**
- Consumes:
  - Task 3's `bulk.php`: `check()` keeps its HEAD opening (`$this->summary = null; $this->rows = []; $this->validateBatch(); $this->validate([...]); … $organisation = $this->organisationToInvite();`) and adds the FM-18 `addressLookup()` charge after the `MAX_ROWS` guard; `send()` is unchanged by Task 3. Task 3 also replaces the opening of 'counts nothing for a list larger than what is left, …' in `BulkInvitationsTest.php`; this task touches neither.
  - Task 3's `### Security` heading under `## [Unreleased]` in `CHANGELOG.md` (after `### Removed`), with the lines Tasks 3–6 put under it.
  - Livewire 4.4.7's `Livewire\Features\SupportFileUploads\FileUploadConfiguration::isUsingS3()`, `::storage()`, `::path(string $path = '')` and `::directory()` (not public API; under test `storage()` is the faked `tmp-for-tests` disk, shared by every test in the process).
  - `App\Actions\Invitations\InviteUser::handle(User $actor, Organisation $organisation, string $email, array $roleIds, Locale $locale): Invitation` (final, so the test makes it fail through a `User::creating` listener, as the existing raced test does)
  - `asMember()`, `asOrgAdmin()`, `asSuperAdmin()`, `onAdminHost()`, `onTenant()`, `recordedActivity()` (`tests/Pest.php`); the file-local `$templateRoleId`, `$deliveredMails` (BulkInvitationsTest) and `$orientedJpeg`, `$memberWithPhoto` (AvatarTest)
- Produces:
  - `App\Support\Uploads\StaleTemporaryUploads::removeStale(): int` and `StaleTemporaryUploads::STALE_AFTER_HOURS = 24`
  - the schedule entry `livewire-tmp-cleanup`: `45 * * * *`, every server (no `onOneServer()`), no overlap lock, no module filter
  - `App\Actions\Users\RemoveAvatar::handle(User $user): bool` (was `void`): true when a photo was removed
  - the lang key "Not sent: something went wrong."

**Notes for the implementer:**
- **Where the organisation check goes.** `organisationToInvite()` authorises and throws "Choose an organisation first." on `organisationId`; moving it above `validateBatch()` is the whole E11 fix. In `check()` the moved line comes before Task 3's lookup charge anyway, so a refusal charges nothing. In `send()` the same order is for consistency only: `send()` needs a checked list, and every change of the pick, the roles or the language clears that list (`updated()`), so the order cannot be observed there and no test pins it.
- **What the `Throwable` catch covers.** It sits on the outer `try` around the loop, next to the existing `finally`. A row's own `ValidationException` is still caught inside the loop and keeps the row's reason; anything else ends the loop, is reported (`report()`; Laravel's handler drops `AuthorizationException` itself), and the rows from the failing one on are skipped with the generic reason. A failure after `InviteUser` committed (a queue push in `afterCommit()`) can leave that row's invitation stored while the summary says "Not sent"; the pending list then shows it with *Remind*. The `finally` stays the one place that gives attempts back.
- **The test failure.** `InviteUser` is `final readonly`, so the test throws a `RuntimeException` from a `User::creating` listener for the second address: the row's transaction rolls back and the exception leaves `handle()` unchanged. `Exceptions::fake()` captures the report.
- **E14 has no failing test.** The deleted line rendered nothing. The strengthened central-host test asserts the message is on the page (`assertSee`), which the select renders itself, so the deletion is shown to lose nothing.
- **Livewire's temporary disk in tests.** `FileUploadConfiguration::storage()` fakes `tmp-for-tests` once per process and keeps it, so other tests' uploads may sit there: `StaleTemporaryUploadsTest` empties the upload directory in `beforeEach` and removes what it put elsewhere. The S3 test only switches the configured driver; the files stay on the local fake, so nothing is contacted.
- **The race catch.** `lastModified()` throws `League\Flysystem\UnableToRetrieveMetadata` (a `FilesystemException`) for a file deleted between `allFiles()` and the read, as Livewire's own sweep or a page's `delete()` can do. The mocked-disk test pins the `continue`.
- **No overlap lock, every server.** As `backup-temp-cleanup`: each server sweeps its own local disk (CD-4); two sweeps at once only race on deletions, which the catch absorbs. `ScheduledTasksTest`'s 'builds its copies …' still counts 7 commands: a `call()` event has no command line.
- **E59 runs everywhere.** The orientation test keeps its ext-exif skip and loses only the two byte assertions, which move into the new test with no skip; its title no longer promises the metadata check.
- **No `git stash`.** For mutation checks copy the file aside with `cp`, change it, run the tests, and copy it back; after a change to a file under `resources/views/` run `php artisan view:clear` before and after.

- [ ] **Step 1: Write the failing tests for the upload clean-up**

`tests/Feature/Uploads/StaleTemporaryUploadsTest.php` (new):

```php
<?php

declare(strict_types=1);

/** covers §4.9, §6.6 */

use App\Support\Uploads\StaleTemporaryUploads;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToRetrieveMetadata;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

beforeEach(function (): void {
    $this->freezeTime();
    // Under test Livewire's temporary disk is the faked tmp-for-tests disk, shared by the whole run: start empty.
    FileUploadConfiguration::storage()->deleteDirectory(FileUploadConfiguration::directory());
});

/** Puts a file on Livewire's temporary disk, last modified $hours ago. */
$upload = function (string $path, int $hours): void {
    $disk = FileUploadConfiguration::storage();
    $disk->put($path, 'a list of addresses');
    touch($disk->path($path), now()->subHours($hours)->getTimestamp());
};

it('deletes the temporary uploads older than 24 hours and keeps the newer ones (E5, CD-4)', function () use ($upload): void {
    $disk = FileUploadConfiguration::storage();
    $upload(FileUploadConfiguration::path('stale.csv'), 25);
    $upload(FileUploadConfiguration::path('stale.csv.json'), 25);
    $upload(FileUploadConfiguration::path('fresh.png'), 1);
    $upload(FileUploadConfiguration::path('almost.png'), 23);
    // Outside Livewire's directory: never touched, whatever its age.
    $upload('elsewhere/old.txt', 48);

    expect(app(StaleTemporaryUploads::class)->removeStale())->toBe(2)
        ->and($disk->exists(FileUploadConfiguration::path('stale.csv')))->toBeFalse()
        ->and($disk->exists(FileUploadConfiguration::path('stale.csv.json')))->toBeFalse()
        ->and($disk->exists(FileUploadConfiguration::path('fresh.png')))->toBeTrue()
        ->and($disk->exists(FileUploadConfiguration::path('almost.png')))->toBeTrue()
        ->and($disk->exists('elsewhere/old.txt'))->toBeTrue();

    $disk->deleteDirectory('elsewhere');
});

it('follows a configured upload directory (E5)', function () use ($upload): void {
    $disk = FileUploadConfiguration::storage();
    config(['livewire.temporary_file_upload.directory' => 'uploads-tmp']);
    $upload('uploads-tmp/stale.csv', 25);
    $upload('livewire-tmp/stale.csv', 25);

    expect(app(StaleTemporaryUploads::class)->removeStale())->toBe(1)
        ->and($disk->exists('uploads-tmp/stale.csv'))->toBeFalse()
        ->and($disk->exists('livewire-tmp/stale.csv'))->toBeTrue();

    $disk->deleteDirectory('uploads-tmp');
});

it('leaves an S3 temporary disk to its lifecycle rule (E5, CD-4)', function () use ($upload): void {
    $disk = FileUploadConfiguration::storage();
    // Livewire reads the configured disk's driver; under test the files still sit on tmp-for-tests, so nothing is contacted.
    config([
        'livewire.temporary_file_upload.disk' => 'uploads',
        'filesystems.disks.uploads' => ['driver' => 's3', 'bucket' => 'never-contacted'],
    ]);
    $upload(FileUploadConfiguration::path('stale.csv'), 25);

    expect(app(StaleTemporaryUploads::class)->removeStale())->toBe(0)
        ->and($disk->exists(FileUploadConfiguration::path('stale.csv')))->toBeTrue();
});

it('skips an upload that is gone before its age is read, as a parallel clean-up leaves it (E5)', function (): void {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('allFiles')->with('livewire-tmp')->andReturn(['livewire-tmp/gone.csv', 'livewire-tmp/stale.csv']);
    $disk->shouldReceive('lastModified')->with('livewire-tmp/gone.csv')->andThrow(UnableToRetrieveMetadata::lastModified('livewire-tmp/gone.csv'));
    $disk->shouldReceive('lastModified')->with('livewire-tmp/stale.csv')->andReturn(now()->subHours(25)->getTimestamp());
    $disk->shouldReceive('delete')->once()->with('livewire-tmp/stale.csv')->andReturnTrue();
    Storage::set('tmp-for-tests', $disk);

    expect(app(StaleTemporaryUploads::class)->removeStale())->toBe(1);
});

it('does nothing while there is no upload directory (E5)', function (): void {
    expect(app(StaleTemporaryUploads::class)->removeStale())->toBe(0);
});
```

`tests/Feature/Scheduler/ScheduleTest.php`: add `use Livewire\Features\SupportFileUploads\FileUploadConfiguration;` after `use Illuminate\Support\Str;`. Replace the first test:

```php
it('schedules exactly the ops jobs of §4.9, each once (DEC-28, L-31, CD-10)', function () use ($identifiers): void {
    expect($identifiers())->toHaveCount(9)->toEqualCanonicalizing([
        'activitylog:clean --force', 'backup-temp-cleanup', 'backup:clean', 'backup:run --only-db', 'backup:monitor',
        'queue:prune-failed', 'model:prune', 'auth:clear-resets', 'scheduler-heartbeat',
    ]);
});
```

with:

```php
it('schedules exactly the ops jobs of §4.9, each once (DEC-28, L-31, CD-10, CD-4)', function () use ($identifiers): void {
    expect($identifiers())->toHaveCount(10)->toEqualCanonicalizing([
        'activitylog:clean --force', 'backup-temp-cleanup', 'livewire-tmp-cleanup', 'backup:clean', 'backup:run --only-db',
        'backup:monitor', 'queue:prune-failed', 'model:prune', 'auth:clear-resets', 'scheduler-heartbeat',
    ]);
});
```

In 'runs each job at its time, on the right servers, never twice at once (§4.9, CD-10)', change the title's parenthesis to `(§4.9, CD-10, CD-4)` and add a dataset row directly after the `backup-temp-cleanup` row:

```php
    // Livewire's temporary uploads sit on each server's own disk too; clear of backup-temp-cleanup's :15 (CD-4).
    ['livewire-tmp-cleanup', '45 * * * *', false, false, 1440],
```

In 'runs the backup jobs only while the backups module is on (§6.8)', replace:

```php
    expect($all['backup-temp-cleanup']->filtersPass(app()))->toBeTrue();
```

with:

```php
    expect($all['backup-temp-cleanup']->filtersPass(app()))->toBeTrue()
        ->and($all['livewire-tmp-cleanup']->filtersPass(app()))->toBeTrue();
```

and append at the end of the file:

```php

it('empties the stale files of Livewire\'s temporary upload directory (E5, CD-4)', function () use ($events): void {
    $disk = FileUploadConfiguration::storage();
    $stale = FileUploadConfiguration::path('abandoned-photo.jpg');
    $fresh = FileUploadConfiguration::path('open-tab.csv');
    $disk->put($stale, 'a photo with its EXIF data');
    $disk->put($fresh, 'a list of addresses');
    touch($disk->path($stale), now()->subHours(25)->getTimestamp());

    $events()['livewire-tmp-cleanup']->run(app());

    expect($disk->exists($stale))->toBeFalse()
        ->and($disk->exists($fresh))->toBeTrue();

    $disk->delete($fresh);
});
```

`tests/Feature/Scheduler/ScheduledTasksTest.php`, in 'lists the ops schedule from its own copy, …': replace `expect($tasks)->toHaveCount(9)` with `expect($tasks)->toHaveCount(10)` and add, directly after `'backup-temp-cleanup' => '15 * * * *',`:

```php
            'livewire-tmp-cleanup' => '45 * * * *',
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Uploads tests/Feature/Scheduler`

Expected: FAIL.
- every `StaleTemporaryUploadsTest` case: "Target class [App\Support\Uploads\StaleTemporaryUploads] does not exist."
- 'schedules exactly the ops jobs …' and 'lists the ops schedule from its own copy …': "Failed asserting that actual size 9 matches expected size 10."
- the `livewire-tmp-cleanup` row of 'runs each job at its time …', both rows of 'runs the backup jobs only while …' and 'empties the stale files of Livewire's temporary upload directory': `Undefined array key "livewire-tmp-cleanup"`

- [ ] **Step 3: `StaleTemporaryUploads` and the schedule entry**

`app/Support/Uploads/StaleTemporaryUploads.php` (new):

```php
<?php

declare(strict_types=1);

namespace App\Support\Uploads;

use Illuminate\Support\Facades\Date;
use League\Flysystem\FilesystemException;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

/**
 * E5, CD-4: Livewire deletes a temporary upload older than 24 hours only when someone uploads again
 * (WithFileUploads::cleanupOldUploads()), so a photo or an address list left behind on an abandoned page, a photo
 * with its EXIF and GPS data, stays on disk until then. The schedule's hourly livewire-tmp-cleanup removes them with
 * Livewire's own threshold, so no open page loses its upload earlier than it would today.
 *
 * It mirrors cleanupOldUploads() of livewire/livewire 4.4 (FileUploadConfiguration is not public API: check this
 * class when Livewire changes it): the configured temporary disk and directory, and nothing on an S3 disk, whose
 * clean-up is a bucket lifecycle rule (php artisan livewire:configure-s3-upload-cleanup).
 */
final readonly class StaleTemporaryUploads
{
    /** Livewire's own threshold for a stale temporary upload. */
    public const int STALE_AFTER_HOURS = 24;

    /** Removes the temporary uploads older than STALE_AFTER_HOURS; returns how many. */
    public function removeStale(): int
    {
        if (FileUploadConfiguration::isUsingS3()) {
            return 0;
        }

        $disk = FileUploadConfiguration::storage();
        $cutoff = Date::now()->subHours(self::STALE_AFTER_HOURS)->getTimestamp();
        $removed = 0;

        foreach ($disk->allFiles(FileUploadConfiguration::path()) as $path) {
            try {
                $stale = $disk->lastModified($path) < $cutoff;
            } catch (FilesystemException) {
                // Gone meanwhile: Livewire's own clean-up, or a page that deleted its upload, got there first.
                continue;
            }

            if ($stale && $disk->delete($path)) {
                $removed++;
            }
        }

        return $removed;
    }
}
```

`app/Support/Scheduler/OpsSchedule.php`: add `use App\Support\Uploads\StaleTemporaryUploads;` after `use App\Support\Skv;`. In the class docblock, replace:

```php
 * The jobs run on one server and never twice at once, except the heartbeat (no overlap lock) and backup-temp-cleanup
 * (hourly on every server, because each has its own temporary directory, and also while the backups module is off,
 * so a dump left from before it was switched off still goes). The backup jobs' overlap lock expires after 12
 * hours, so a killed run never blocks the next night (F6). The backup jobs run only while the backups module is on
```

with:

```php
 * The jobs run on one server and never twice at once, except the heartbeat (no overlap lock), backup-temp-cleanup
 * (hourly on every server, because each has its own temporary directory, and also while the backups module is off,
 * so a dump left from before it was switched off still goes) and livewire-tmp-cleanup (hourly on every server, whose
 * local disk holds its own temporary uploads, CD-4). The backup jobs' overlap lock expires after 12 hours, so a
 * killed run never blocks the next night (F6). The backup jobs run only while the backups module is on
```

and in `define()`, directly after the `backup-temp-cleanup` entry (`})->hourlyAt(15)->name('backup-temp-cleanup');`), insert:

```php
        // E5, CD-4: the temporary uploads an abandoned page left (photos with their EXIF data, address lists), older
        // than Livewire's own 24 hours. At :45, clear of backup-temp-cleanup; nothing on an S3 temporary disk.
        $schedule->call(function (): void {
            app(StaleTemporaryUploads::class)->removeStale();
        })->hourlyAt(45)->name('livewire-tmp-cleanup');
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Uploads tests/Feature/Scheduler tests/Feature/System`

Expected: PASS (`SystemPageTest` lists the new task without change).

- [ ] **Step 5: Write the failing tests for the bulk page**

`tests/Feature/Invitations/BulkInvitationsTest.php`: add `use Illuminate\Support\Facades\Exceptions;` after `use Illuminate\Support\Facades\DB;`.

In 'lets a super admin on a central host invite after picking an organisation (§6.2, INV-17)', replace:

```php
    $page = Livewire::test('pages::invitations.bulk')
        ->set('addresses', 'first@example.com')
        ->set('roles', [$templateRoleId()])
        ->call('check')
        ->assertHasErrors(['organisationId' => __('Choose an organisation first.')]);
```

with:

```php
    // E11: without a pick the organisation is what the page asks for first, also before a role is chosen; the
    // picker shows the message itself.
    $page = Livewire::test('pages::invitations.bulk')
        ->set('addresses', 'first@example.com')
        ->call('check')
        ->assertHasErrors(['organisationId' => __('Choose an organisation first.')])
        ->assertHasNoErrors('roles')
        ->assertSee(__('Choose an organisation first.'));
```

Insert this test directly before 'reviews the rows again when it sends and skips an organisation archived meanwhile with the action\'s message (CD-6)':

```php
it('stops at an unexpected failure, reports it, summarises what was sent and gives the unsent rows their attempts back (E12, E16)', function () use ($templateRoleId, $deliveredMails): void {
    Exceptions::fake();
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($acme);
    // Anything InviteUser throws that is not a ValidationException: a queue that refuses the mail, an authorisation
    // lost halfway through the list. The second row's transaction rolls back.
    User::creating(function (User $user): void {
        if ($user->email === 'bob@example.com') {
            throw new RuntimeException('The queue is unavailable.');
        }
    });

    Livewire::test('pages::invitations.bulk')
        ->set('roles', [$templateRoleId()])
        ->set('addresses', 'ada@example.com, bob@example.com, cleo@example.com')
        ->call('check')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('rows', [])
        ->assertSet('summary', [
            'sent' => ['ada@example.com'],
            'skipped' => [
                ['email' => 'bob@example.com', 'reason' => __('Not sent: something went wrong.')],
                ['email' => 'cleo@example.com', 'reason' => __('Not sent: something went wrong.')],
            ],
        ])
        ->assertSee(__('Sent: :count', ['count' => 1]))
        ->assertSee(__('Skipped: :count', ['count' => 2]));

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'The queue is unavailable.');
    expect(collect($deliveredMails())->map(fn (Email $mail): string => $mail->getTo()[0]->getAddress())->all())->toBe(['ada@example.com'])
        ->and(DB::table('users')->whereIn('email', ['bob@example.com', 'cleo@example.com'])->exists())->toBeFalse()
        // E16: only the sent row keeps its attempt; the failed and the unsent rows give theirs back.
        ->and(RateLimiter::attempts(UserActionLimit::bulkInvite()->key($admin)))->toBe(1);
});
```

- [ ] **Step 6: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Invitations/BulkInvitationsTest.php`

Expected: FAIL, two tests:
- 'lets a super admin on a central host invite after picking an organisation': "Component has no matching failed rule or error message [Choose an organisation first.] for [organisationId] attribute." (the roles error comes first)
- 'stops at an unexpected failure, …': the `RuntimeException` "The queue is unavailable." escapes `send()`

- [ ] **Step 7: The bulk page**

`resources/views/pages/invitations/bulk/bulk.php`. In the class docblock, replace its last line:

```php
 * the bulk limit before the first send (UserActionLimit::bulkInvite()); the rows InviteUser refuses are given back.
 */
```

with:

```php
 * the bulk limit before the first send (UserActionLimit::bulkInvite()); the rows InviteUser refuses are given back.
 * An unexpected failure halfway (E12) is reported and stops the list: the summary still shows what was sent, and the
 * rows it did not reach are skipped with a generic reason and give their attempts back.
 */
```

In `check()`, replace its opening (the same after Task 3):

```php
        $this->summary = null;
        $this->rows = [];
        $this->validateBatch();
        $this->validate([
            'addresses' => ['nullable', 'string', 'max:30000'],
            'csv' => ['nullable', 'file', 'mimes:csv,txt', 'max:512'],
        ]);

        $organisation = $this->organisationToInvite();
        $entries = [
```

with:

```php
        $this->summary = null;
        $this->rows = [];
        // E11: on a central host the organisation comes first, so the page never asks for a role of no organisation.
        $organisation = $this->organisationToInvite();
        $this->validateBatch();
        $this->validate([
            'addresses' => ['nullable', 'string', 'max:30000'],
            'csv' => ['nullable', 'file', 'mimes:csv,txt', 'max:512'],
        ]);

        $entries = [
```

In `send()`, replace:

```php
        $this->validateBatch();
        $organisation = $this->organisationToInvite();
        $actor = $this->currentUser();
```

with:

```php
        $organisation = $this->organisationToInvite();
        $this->validateBatch();
        $actor = $this->currentUser();
```

and replace the send loop up to the `finally`:

```php
        $sent = [];

        try {
            foreach ($ready as $row) {
                try {
                    $inviteUser->handle($actor, $organisation, $row['email'], $roleIds, Locale::from($row['locale']));
                    $sent[] = $row['email'];
                } catch (ValidationException $exception) {
                    $skipped[] = ['email' => $row['email'], 'reason' => $this->reasonFor($exception)];
                }
            }
        } finally {
```

with:

```php
        $sent = [];
        $handled = 0;

        try {
            foreach ($ready as $row) {
                try {
                    $inviteUser->handle($actor, $organisation, $row['email'], $roleIds, Locale::from($row['locale']));
                    $sent[] = $row['email'];
                } catch (ValidationException $exception) {
                    $skipped[] = ['email' => $row['email'], 'reason' => $this->reasonFor($exception)];
                }

                $handled++;
            }
        } catch (Throwable $exception) {
            // E12: a failure that is not the row's fault (a queue that refuses the mail, an authorisation lost
            // halfway) stops the list. It is reported, and the summary still says what was sent; the row that
            // failed and the rows after it are not sent.
            report($exception);

            foreach (array_slice($ready, $handled) as $row) {
                $skipped[] = ['email' => $row['email'], 'reason' => __('Not sent: something went wrong.')];
            }
        } finally {
```

The component file is in the global namespace: `Throwable` needs no import (a `use Throwable;` there is a PHP warning, "The use statement with non-compound name 'Throwable' has no effect", that breaks every render).

`resources/views/pages/invitations/bulk/bulk.blade.php`: delete the line

```blade
                    <flux:error name="organisation" />
```

(directly after the organisation `</flux:select>`; the `<div class="space-y-2">` around the select stays).

Lang keys, in PHP `sort()` order (as of main: after "Not reachable", before "Nothing accepts mail at :address"):

`lang/nl.json`:

```json
    "Not sent: something went wrong.": "Niet verstuurd: er ging iets mis.",
```

`lang/en.json`:

```json
    "Not sent: something went wrong.": "Not sent: something went wrong.",
```

Then run `php artisan view:clear`.

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Invitations tests/Feature/LangCoverageTest.php tests/Feature/LangFilesTest.php`

Expected: PASS.

- [ ] **Step 9: Write the failing tests for the photos**

`tests/Feature/Settings/AvatarTest.php`. Replace the whole test 'turns the photo by its EXIF orientation before the square crop and drops the metadata (§6.6)' (its `->skip(...)` line included) with these two (E59; the first is retitled because it no longer checks the metadata):

```php
it('turns the photo by its EXIF orientation before the square crop (§6.6)', function () use ($orientedJpeg, $pixel): void {
    $member = asMember(Organisation::factory()->create(['slug' => 'acme']));

    Livewire::test('pages::settings.profile')
        ->set('avatar', UploadedFile::fake()->createWithContent('phone.jpg', $orientedJpeg(6)))
        ->call('saveAvatar')
        ->assertHasNoErrors();

    $stored = (string) Storage::disk('public')->get((string) $member->fresh()?->avatar_path);
    [$topRight, $bottomLeft] = [$pixel($stored, 448, 64), $pixel($stored, 64, 448)];

    // Turned 90° clockwise, the red left half is on top and the blue right half below; unturned it would be the reverse.
    expect($topRight[0])->toBeGreaterThan(200)->and($topRight[2])->toBeLessThan(60)
        ->and($bottomLeft[2])->toBeGreaterThan(200)->and($bottomLeft[0])->toBeLessThan(60);
})->skip(fn (): bool => ! function_exists('exif_read_data'), 'reading the orientation needs ext-exif');

it('drops the photo\'s metadata, also without ext-exif (§6.6, E59)', function () use ($orientedJpeg): void {
    $member = asMember(Organisation::factory()->create(['slug' => 'acme']));
    $upload = $orientedJpeg(6);

    Livewire::test('pages::settings.profile')
        ->set('avatar', UploadedFile::fake()->createWithContent('phone.jpg', $upload))
        ->call('saveAvatar')
        ->assertHasNoErrors();

    $stored = (string) Storage::disk('public')->get((string) $member->fresh()?->avatar_path);

    // The upload carries an EXIF APP1 segment; the stored WebP carries none, and no EXIF chunk either.
    expect(str_contains($upload, "Exif\x00\x00"))->toBeTrue()
        ->and(getimagesizefromstring($stored)['mime'] ?? null)->toBe('image/webp')
        ->and(str_contains($stored, 'Exif'))->toBeFalse()
        ->and(str_contains($stored, 'EXIF'))->toBeFalse();
});
```

Insert these two tests directly before 'refuses an image wider than 4096 pixels in the action itself, before decoding it' (E56):

```php
it('refuses a type other than JPEG, PNG or WebP in the action itself, before decoding it (E56)', function (): void {
    $member = User::factory()->for(Organisation::factory())->create();
    // A future caller without the page's mimes rule: an animated GIF would have every frame decoded.
    $processed = 0;
    app(ImageManager::class)->transformUsing('gd', Cover::class, function () use (&$processed): never {
        $processed++;

        throw new LogicException('The GIF reached the image pipeline.');
    });

    expect(fn () => app(UpdateAvatar::class)->handle($member, UploadedFile::fake()->image('photo.gif', 64, 64)))
        ->toThrow(ValidationException::class, __('This image could not be processed.'));

    expect($processed)->toBe(0)
        ->and($member->fresh()?->avatar_path)->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('accepts the three types the page allows in the action itself (E56)', function (string $name): void {
    $member = User::factory()->for(Organisation::factory())->create();

    app(UpdateAvatar::class)->handle($member, UploadedFile::fake()->image($name, 64, 64));

    expect((string) $member->fresh()?->avatar_path)->toEndWith('.webp')
        ->and(Storage::disk('public')->allFiles())->toHaveCount(1);
})->with(['photo.jpg', 'photo.png', 'photo.webp']);
```

Insert these two tests directly after 'removes the photo and its file and falls back to the initials (§6.6)' (E55):

```php
it('says nothing and tells the user menu nothing when there was no photo to remove (E55)', function (): void {
    asMember(Organisation::factory()->create(['slug' => 'acme']));

    Livewire::test('pages::settings.profile')
        ->call('removeAvatar')
        ->assertHasNoErrors()
        ->assertNotDispatched('avatar-updated')
        ->assertNotDispatched('toast-show');

    expect(recordedActivity(ActivityEvent::UserUpdated))->toBeEmpty();
});

it('tells whether it removed a photo (E55)', function () use ($memberWithPhoto): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = $memberWithPhoto($organisation);
    onTenant($organisation);

    expect(app(RemoveAvatar::class)->handle($member))->toBeTrue()
        ->and(app(RemoveAvatar::class)->handle($member))->toBeFalse();
});
```

All the classes these tests use are imported already.

- [ ] **Step 10: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Settings/AvatarTest.php`

Expected: FAIL, three tests:
- 'refuses a type other than JPEG, PNG or WebP …': "Failed asserting that 'Failed to process image: The GIF reached the image pipeline.' … contains "This image could not be processed."" (the GIF is decoded)
- 'says nothing and tells the user menu nothing …': "Failed asserting that an event [avatar-updated] was not fired."
- 'tells whether it removed a photo': "Failed asserting that null is true."

'drops the photo's metadata, also without ext-exif' and 'accepts the three types …' pass on HEAD: they are the weak-test half of E59 and the guard for E56's allow-list, proven by mutations 15 and 13.

- [ ] **Step 11: The photo actions and the profile page**

`app/Actions/Users/RemoveAvatar.php`. Replace the class docblock's last two lines:

```php
 * the file is deleted after commit. Without a stored photo nothing is written or recorded. Never during an
 * impersonation.
 */
```

with:

```php
 * the file is deleted after commit. Without a stored photo nothing is written or recorded, and it returns false, so
 * the page confirms nothing (E55). Never during an impersonation.
 */
```

and replace the whole `handle()` method:

```php
    /**
     * Returns whether a photo was removed.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user): bool
    {
        if (Impersonation::isActive()) {
            throw new AuthorizationException('The profile photo is not changed during an impersonation.');
        }

        return DB::transaction(function () use ($user): bool {
            $previous = DB::table('users')->where('id', $user->id)->lockForUpdate()->value('avatar_path');

            if (! is_string($previous) || $previous === '') {
                return false;
            }

            // The locked row decides, not the model: one loaded before a parallel save still holds null, and clearing
            // it would then write nothing while the file below goes.
            $user->forceFill(['avatar_path' => $previous])->syncOriginalAttribute('avatar_path');
            $user->forceFill(['avatar_path' => null])->saveQuietly();
            $this->recordActivity->handle(ActivityEvent::UserUpdated, $user, changes: ['attributes' => ['avatar' => 'removed']]);

            DB::afterCommit(fn (): bool => Storage::disk('public')->delete($previous));

            return true;
        });
    }
```

`app/Actions/Users/UpdateAvatar.php`. In the class docblock, replace:

```php
 * Stores a user's own profile photo (§6.6). The upload is never stored as sent: an image whose header declares more
 * than 4096 pixels on a side is refused before decoding; the rest goes through Laravel's image API on the GD driver
```

with:

```php
 * Stores a user's own profile photo (§6.6). The upload is never stored as sent: an image whose header declares more
 * than 4096 pixels on a side, or another type than JPEG, PNG or WebP (E56: an animated GIF would have every frame
 * decoded), is refused before decoding; the rest goes through Laravel's image API on the GD driver
```

add the constant directly after `private const int MEMORY_HEADROOM = 64 * 1024 * 1024;`:

```php

    /** The types the page accepts (mimes:jpeg,png,webp), checked again on the header before anything is decoded. */
    private const array TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
```

and in `encode()` replace the header check:

```php
        if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[0] > self::MAX_SIDE || $size[1] > self::MAX_SIDE) {
```

with:

```php
        if ($size === false || ! in_array($size[2], self::TYPES, true) || $size[0] < 1 || $size[1] < 1 || $size[0] > self::MAX_SIDE || $size[1] > self::MAX_SIDE) {
```

`resources/views/pages/settings/profile/profile.php` (after Task 3, which changes only `updateProfileInformation()`): replace the whole `removeAvatar()` method, docblock included:

```php
    /**
     * Remove the user's own photo; the initials show again (§6.6). A chosen photo not saved yet is deleted too. Only a
     * photo that was there is confirmed and re-renders the user menu (E55).
     */
    public function removeAvatar(RemoveAvatar $removeAvatar): void
    {
        $this->ensureAvatarManageable();

        $removed = $removeAvatar->handle($this->currentUser());

        $this->avatar?->delete();
        $this->reset('avatar');

        if (! $removed) {
            return;
        }

        unset($this->avatarUrl);
        $this->dispatch('avatar-updated');
        Flux::toast(variant: 'success', text: __('Profile photo removed.'));
    }
```

Then run `composer fix` and `php artisan view:clear`.

- [ ] **Step 12: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Settings tests/Feature/Authorization/LockOrderTest.php`

Expected: PASS (`LockOrderTest` skips outside PostgreSQL; its `RemoveAvatar` case ignores the return value).

- [ ] **Step 13: Verify by mutation**

For each mutation: copy the file aside with `cp`, change it (for a file under `resources/views/` run `php artisan view:clear`), run the named test file, confirm the named test fails, copy the original back (`php artisan view:clear` again for a view) and confirm the file passes (never `git stash`). Name each mutation and its result in the task report. The prototype ran every row and saw each named test fail.

| # | File: mutation | Must fail |
|---|---|---|
| 1 | `StaleTemporaryUploads.php`: delete the `isUsingS3()` early return | `StaleTemporaryUploadsTest` › 'leaves an S3 temporary disk to its lifecycle rule' ("1 is identical to 0") |
| 2 | `StaleTemporaryUploads.php`: `allFiles(FileUploadConfiguration::path())` → `allFiles()` | › 'deletes the temporary uploads older than 24 hours …' ("3 is identical to 2"), › 'follows a configured upload directory', › 'skips an upload that is gone …' (no matching handler for `allFiles()`) |
| 3 | `StaleTemporaryUploads.php`: `STALE_AFTER_HOURS = 24` → `12` | › 'deletes the temporary uploads older than 24 hours …' ("3 is identical to 2": the 23-hour file goes) |
| 4 | `StaleTemporaryUploads.php`: replace the `try { … } catch (FilesystemException) { continue; }` with `$stale = $disk->lastModified($path) < $cutoff;` | › 'skips an upload that is gone …' ("Unable to retrieve the last_modified …") |
| 5 | `OpsSchedule.php`: empty the `livewire-tmp-cleanup` closure (drop the `removeStale()` call) | `ScheduleTest` › 'empties the stale files of Livewire's temporary upload directory' ("true is false") |
| 6 | `OpsSchedule.php`: append `->onOneServer()` to the `livewire-tmp-cleanup` entry | `ScheduleTest` › 'runs each job at its time …' › the `livewire-tmp-cleanup` row |
| 7 | `bulk.php`: in `check()`, move `$organisation = $this->organisationToInvite();` back below `$this->validateBatch();` | `BulkInvitationsTest` › 'lets a super admin on a central host invite after picking an organisation' |
| 8 | `bulk.php`: `array_slice($ready, $handled)` → `array_slice($ready, $handled + 1)` | › 'stops at an unexpected failure, …' (the summary lacks bob) |
| 9 | `bulk.php`: delete `report($exception);` | › 'stops at an unexpected failure, …' ("The expected [RuntimeException] exception was not reported.") |
| 10 | `bulk.php`: insert `} catch (RuntimeException $exception) { throw $exception;` before `} catch (Throwable $exception) {` | › 'stops at an unexpected failure, …' ("The queue is unavailable." escapes) |
| 11 | `bulk.php`: delete `$limit->release($actor, $unused);` in the `finally` | › 'stops at an unexpected failure, …' ("3 is identical to 1", E16), and the existing 'skips a row the action refuses at send time …' and 'reviews the rows again …' |
| 12 | `UpdateAvatar.php`: delete `! in_array($size[2], self::TYPES, true) \|\| ` | `AvatarTest` › 'refuses a type other than JPEG, PNG or WebP …' |
| 13 | `UpdateAvatar.php`: drop `IMAGETYPE_WEBP` from `TYPES` | › 'accepts the three types the page allows …' › 'photo.webp' |
| 14 | `RemoveAvatar.php`: `return false;` → `return true;` | › 'says nothing and tells the user menu nothing …', › 'tells whether it removed a photo' |
| 15 | `UpdateAvatar.php`: `$disk->put($path, $encoded)` → `$disk->put($path, (string) $file->get())` (the upload stored as sent) | › 'drops the photo's metadata, also without ext-exif' (the one metadata test without an ext-exif skip), and the 512 × 512 test |
| 16 | `profile.php`: delete the `if (! $removed) { return; }` block | › 'says nothing and tells the user menu nothing …' |

Not mutation-covered by design: `send()`'s new order (unobservable, see the notes) and the deleted Blade line (E14, dead markup).

- [ ] **Step 14: README and CHANGELOG**

`README.md`, section `## Scheduler`. Replace the paragraph's second sentence:

```markdown
Every job but `backup-temp-cleanup` runs on one server (`onOneServer()`), because each server has its own temporary directory, and every job but the heartbeat and `backup-temp-cleanup` never twice at once (`withoutOverlapping()`).
```

with:

```markdown
Every job but `backup-temp-cleanup` and `livewire-tmp-cleanup` runs on one server (`onOneServer()`), because each server has its own temporary directory and its own temporary uploads, and every job but the heartbeat and those two never twice at once (`withoutOverlapping()`).
```

and add this row to the table directly after the `backup-temp-cleanup` row:

```markdown
| `livewire-tmp-cleanup` | every hour at :45 | always (removes Livewire's temporary uploads older than 24 hours, such as a photo or a list of addresses left on an abandoned page; on an S3 temporary-upload disk it does nothing: run `php artisan livewire:configure-s3-upload-cleanup` once, so the bucket expires them) |
```

`CHANGELOG.md`, under `## [Unreleased]`:
- `### Added`, directly after the line that starts with `- Livewire's temporary uploads take one file`:

```markdown
- An hourly clean-up (`livewire-tmp-cleanup`, at :45, on every server) removes Livewire's temporary uploads older than 24 hours, so a photo with its metadata or a list of addresses left on an abandoned page no longer waits for the next upload; on an S3 temporary-upload disk it does nothing (`php artisan livewire:configure-s3-upload-cleanup` sets the bucket's lifecycle rule instead).
```

- `### Fixed`, as its last two lines (after any Tasks 3–6 added):

```markdown
- Bulk invitations: on a central host *Check* asks for the organisation before the roles, and an unexpected error halfway through *Send* no longer answers with a server error: it is reported, the summary lists what was sent, and the rows it did not reach are skipped as *Not sent: something went wrong.* without counting against the limit.
- Removing a profile photo when there is none no longer says *Profile photo removed.*
```

- `### Security` (Task 3's heading), as its last line:

```markdown
- Saving a profile photo refuses any image type but JPEG, PNG and WebP in the action itself, before decoding, so no caller can make the server decode every frame of an animated GIF.
```

The Dutch manual (`docs/handleiding.html`) is Task 17's (CD-14).

- [ ] **Step 15: The gate, then commit**

Run the Global Constraints gate:
- `composer check`
- `composer test:pgsql` (nothing here is driver-specific; `LockOrderTest`'s `RemoveAvatar` case runs here)
- the modules-off run
- `npm run build && composer test:browser` (the bulk page and the profile page render through the browser suite)
- `npm run build:setup && git diff --exit-code resources/setup/setup.css` (no setup view changes, so expect no diff)
- `php artisan config:clear`

Commit in three parts:

```bash
git add app/Support/Uploads/StaleTemporaryUploads.php app/Support/Scheduler/OpsSchedule.php \
    tests/Feature/Uploads/StaleTemporaryUploadsTest.php tests/Feature/Scheduler/ScheduleTest.php \
    tests/Feature/Scheduler/ScheduledTasksTest.php README.md
git commit -m "feat: remove livewire's stale temporary uploads every hour

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"

git add resources/views/pages/invitations/bulk/bulk.php resources/views/pages/invitations/bulk/bulk.blade.php \
    tests/Feature/Invitations/BulkInvitationsTest.php lang/nl.json lang/en.json
git commit -m "fix: ask for the organisation first and summarise a bulk send that fails halfway

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"

git add app/Actions/Users/RemoveAvatar.php app/Actions/Users/UpdateAvatar.php \
    resources/views/pages/settings/profile/profile.php tests/Feature/Settings/AvatarTest.php CHANGELOG.md
git commit -m "fix: confirm only a removed photo and refuse other image types before decoding

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

The CHANGELOG goes with the last commit because it holds all four lines; `README.md` goes with the first, the only one it describes.

---

---

### Task 8: Setup steps, bootstrap and doctor (§5.2, §5.3.3, §5.4; CD-12; D85=E2=D55 (K9), D63, C37, E3)

Four setup and doctor answers are wrong today, each found in a review and deferred to M8:
- **K9 (DEC-34 (14), DEC-35 Consequence):** `HerdSiteStep::isComplete()` checks that Herd serves the site and, with HTTPS, that it is secured, but not its PHP. Both runners skip a complete step (`SetupRunner::runStep()` reports "Already done", `WizardRunner` does the same), so a re-run on a site Herd serves with PHP 8.3 never reaches `run()`'s `herd isolate 8.4`. `confirm()` and `manualCommand()` already require `runsSupportedPhp()`; `isComplete()` now does too, last and inside its `try`, so a failing probe makes it false.
- **D63:** the environment step and the doctor's `storage-link` row accept any `public/storage` (`is_link() || file_exists()`): a link to the project root, which would serve `.env` under `/storage`, a dangling link or a plain directory all count as linked. Both now accept only a `public/storage` that resolves to `storage/app/public`. The step replaces a wrong or dangling link with `storage:link --force`, the doctor fails the row and its `--fix` does the same; Laravel's `storage:link` removes only a link with `--force`, never a directory or a file, so neither ever deletes real content.
- **C37 (DEC-33 Consequence, CD-12):** `Bootstrap::isProduction()` is false when no source names an `APP_ENV`, while Laravel itself then runs as `production` (`config/app.php`). A server `.env` written by hand without `APP_ENV` thus gets `skv:bootstrap`'s development half (the Herd `APP_URL`, SQLite and `migrate`, the setup token). "No `APP_ENV` anywhere" is production now. `run()` copies `.env.example` (`APP_ENV=local`) before it asks, so a fresh install is unaffected.
- **E3 (DEC-35 Consequence):** profile photos are stored as WebP, and a GD without WebP support fails every upload with a server error, while the doctor's `gd` row passes. The row now warns when GD is loaded but `imagetypes()` lacks `IMG_WEBP`, with a constructor seam like the existing `$loaded`.

**Files:**
- Modify:
  - `app/Setup/Steps/HerdSiteStep.php` (`isComplete()` and its docblock)
  - `app/Setup/Steps/EnvironmentStep.php` (the new `isStorageLink()`, `storageIsLinked()`, the new `storageProblem()`, `linkStorage()`, `run()`'s manual result, the class docblock)
  - `app/Setup/Doctor/Checks/StorageLinkCheck.php` (whole file)
  - `app/Setup/Support/Bootstrap.php` (`isProduction()` and its docblock)
  - `app/Setup/Doctor/Checks/GdCheck.php` (whole file)
  - `lang/nl.json`, `lang/en.json` (four keys)
  - `CHANGELOG.md` (Added, Changed and Fixed lines)
- Test:
  - `tests/Feature/Setup/Steps/HerdSiteStepTest.php` (after Task 1: one existing test gets the PHP fakes, three new tests)
  - `tests/Feature/Setup/Steps/EnvironmentStepTest.php` (four new tests)
  - `tests/Feature/Setup/Doctor/ChecksTest.php` (the gd test, three new tests)
  - `tests/Feature/Console/ProductionGuardsTest.php` (two new tests, next to the existing `isProduction()` tests)
  - unchanged and green, re-checked with K9 in place: `tests/Feature/Setup/Wizard/HerdStageTest.php` and `tests/Feature/Console/RunStepCommandTest.php` (they fake `herd isolated` and `php -r` already), `tests/Feature/Setup/SetupRunnerTest.php` (its Herd-step tests run without Herd or with recording steps), `tests/Feature/Setup/Steps/SummaryStepTest.php`, `tests/Feature/Setup/Doctor/DoctorTest.php`, `tests/Feature/Setup/BootstrapTest.php`, `tests/Feature/System/SystemPageTest.php` (runs `gd` and `storage-link`), `tests/Feature/LangCoverageTest.php`, `tests/Feature/LangFilesTest.php`

**Interfaces:**
- Consumes:
  - Task 1's `HerdSiteStepTest.php` (`use App\Setup\Support\ShellArgument;` added after `use App\Setup\Support\EnvFile;`; 'names the command HTTPS asks for when Herd\'s listings fail (RL-7)' and 'quotes a folder name with a space …' strengthened) and its stray-process recorder in `tests/Pest.php`, which fails a test on any unfaked command, also one a `catch (Throwable)` swallows: every test below fakes every command it causes.
  - `App\Setup\SetupRunner::runStep(SetupContext $context, SetupStep $step, ?Closure $around = null): StepResult` (unchanged)
  - `setupSandbox()`, `withoutPostgresProbe()` (`tests/Pest.php`); `ChecksTest`'s `$this->sandbox` / `$this->context`; `ProductionGuardsTest`'s `$this->sandbox` and its sandboxed `Bootstrap`
  - Task 7's CHANGELOG lines (this task's lines go after them)
- Produces:
  - `App\Setup\Steps\EnvironmentStep::isStorageLink(string $link, string $target): bool` (public static; `StorageLinkCheck` uses it)
  - `App\Setup\Doctor\Checks\GdCheck::__construct(?bool $loaded = null, ?bool $webp = null)`
  - the doctor's `storage-link` row: `fail` for a wrong link, a dangling link or anything else in `public/storage`'s place (was `pass`); `warn` while missing (as before)
  - the doctor's `gd` row: `warn` for a GD without WebP (was `pass`)
  - the lang keys "public/storage is not a link to storage/app/public", "Move public/storage aside, then run php artisan storage:link", "GD has no WebP support: profile photos cannot be saved", "Install GD with WebP (libwebp)"

**Notes for the implementer:**
- **K9 needs fakes in one test only.** With the change in place, the prototype ran every setup and console test with stray processes logged at the source: the only new unfaked command was `herd isolated` in 'is complete once served, secured and on the https APP_URL, …' (its 'secured' row then reads false). `HerdStageTest` and `RunStepCommandTest` fake `herd isolated` and `php -r` already, and `SetupRunnerTest` never runs the real Herd step with Herd detected, so `reconcile.md`'s "fakes in four files" shrinks to that one test plus the new ones.
- **The probe order.** `runsSupportedPhp()` runs `herd isolated` and, for a site Herd does not list, Herd's global `php -r 'echo PHP_VERSION;'`. It goes last in the `&&` chain, after `herd links`/`paths` (and `secured` with HTTPS), so an unserved site starts no PHP probe; inside the `try`, so a timeout is false, as for the listings.
- **What counts as the storage link.** `realpath(public/storage) === realpath(storage/app/public)`, both resolved: a relative link (`storage:link --relative`) and a deployment whose `storage/` is itself a link to shared storage pass; a dangling link (`realpath()` false), a link elsewhere, a directory or a file do not. `clearstatcache(true, $link)` comes first because `storage:link` runs as a child process, outside PHP's stat and realpath caches (HEAD called `clearstatcache()` after the process for the same reason); tests change the link in-process, where PHP clears its own caches, so no test pins that line.
- **`--force` only over a link.** `storage:link --force` removes a link in its way and nothing else (`StorageLinkCommand::isRemovableSymlink()`); without `--force` it already replaces a dangling link but refuses a wrong one ("The [public/storage] link already exists."). The step and the doctor pass `--force` exactly when `public/storage` is a link, so the argv for a missing link stays `['artisan', 'storage:link', '--no-interaction']` (the existing tests pin it) and a directory is never forced. The manual command and the doctor's fix say the same: `php artisan storage:link --force` for a link; for a directory or a file, the step names `php artisan storage:link` with "Move public/storage aside, then run php artisan storage:link" in its message, and the doctor gives that sentence as the fix.
- **Fail, not warn.** A missing link stays a warning (a fresh clone has none and the app works). Anything else in its place fails: a link to the project root serves `.env` and the rest of the project under `/storage`, and success criterion 2 wants a doctor without `fail` only on a sound install, which setup's own link always is.
- **C37 in tests.** phpunit sets `APP_ENV=testing` in `getenv()` and `$_SERVER`, the two process sources `isProduction()` reads besides `.env`. The test removes it from both and puts both back in `beforeApplicationDestroyed`, as the existing L-60 test does (`$_ENV` is not a source; the app's own `app()->environment()` is not read). The sandbox `.env` loses its `APP_ENV=local` line, or gets a blank one. `ProductionGuardsTest` binds a sandboxed `Bootstrap` and `Toolchain`, so `run()` touches the sandbox only; the development-half test fakes `migrate` by name, and `HerdTld` starts nothing without a `herd` binary in the sandbox home.
- **`GdCheck`'s seam.** `IMG_WEBP` and `imagetypes()` exist only with GD loaded, so the WebP probe runs after the loaded check, in a private method. The System page runs `gd` (`SystemChecks::KEYS`); `imagetypes()` starts no process.
- **No `git stash`.** For mutation checks copy the file aside with `cp`, change it, run the tests, and copy it back.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Setup/Steps/HerdSiteStepTest.php` (after Task 1). Add `use App\Setup\SetupRunner;` after `use App\Setup\SetupContext;`. In 'is complete once served, secured and on the https APP_URL, and not when herd fails (§5.3.1, g1-08)', replace the end of its fake list:

```php
        "*herd' 'secured'*" => Process::result(str_replace('{name}', $context->folderName(), $secured)),
    ]);

    expect(new HerdSiteStep()->isComplete($context))->toBe($complete);
```

with:

```php
        "*herd' 'secured'*" => Process::result(str_replace('{name}', $context->folderName(), $secured)),
        // K9: complete also needs PHP 8.4 or newer for the site.
        "*herd' 'isolated'*" => Process::result(''),
        "*php' '-r'*" => Process::result('8.4.13'),
    ]);

    expect(new HerdSiteStep()->isComplete($context))->toBe($complete);
```

Insert these three tests directly before 'names the one Herd command left to run: herd link while unserved, herd secure while HTTPS is missing (RL-7)':

```php
it('is complete only while the site runs PHP 8.4 or newer, whether isolated or on Herd\'s global PHP (K9, DEC-34 (14))', function (string $global, ?string $isolatedTo, bool $complete): void {
    $context = setupSandbox($this->detection);
    $context->answers = Answers::fromArray(['https' => false, 'modules' => []], $this->detection, $context->folderName());
    $name = $context->folderName();

    Process::fake([
        "*herd' 'links'*" => Process::result(''),
        "*herd' 'paths'*" => Process::result(json_encode([dirname($context->basePath)], JSON_THROW_ON_ERROR)),
        "*herd' 'isolated'*" => Process::result($isolatedTo === null ? '' : "| Site | PHP Version |\n| {$name}.test | {$isolatedTo} |\n"),
        "*php' '-r'*" => Process::result($global),
    ]);

    expect(new HerdSiteStep()->isComplete($context))->toBe($complete);
    Process::assertRan(fn ($process): bool => array_slice((array) $process->command, 1) === ['isolated']);
})->with([
    'global 8.3, not isolated' => ['8.3.0', null, false],
    'global 8.4, not isolated' => ['8.4.13', null, true],
    'global 8.5, not isolated' => ['8.5.1', null, true],
    'global 8.4, isolated to 8.3' => ['8.4.13', '8.3', false],
    'global 8.3, isolated to 8.4' => ['8.3.20', '8.4', true],
    'the PHP probe prints nothing' => ['', null, false],
]);

it('is not complete when the PHP check itself fails (K9)', function (): void {
    $context = setupSandbox($this->detection);
    $context->answers = Answers::fromArray(['https' => false, 'modules' => []], $this->detection, $context->folderName());

    Process::fake([
        "*herd' 'links'*" => Process::result(''),
        "*herd' 'paths'*" => Process::result(json_encode([dirname($context->basePath)], JSON_THROW_ON_ERROR)),
        "*herd' 'isolated'*" => fn () => throw new ProcessTimedOutException(new SymfonyTimeout(new SymfonyProcess(['herd']), SymfonyTimeout::TYPE_GENERAL), Process::result('')),
    ]);

    expect(new HerdSiteStep()->isComplete($context))->toBeFalse();
});

it('isolates on a re-run a served site still on an older PHP instead of reporting it already done (K9, L7b-16)', function (): void {
    $context = setupSandbox($this->detection);
    $context->answers = Answers::fromArray(['https' => false, 'modules' => []], $this->detection, $context->folderName());
    $name = $context->folderName();

    Process::fake([
        "*herd' 'links'*" => Process::result(''),
        "*herd' 'paths'*" => Process::result(json_encode([dirname($context->basePath)], JSON_THROW_ON_ERROR)),
        // Not isolated for the completeness check and run()'s own check; listed with 8.4 once `herd isolate` ran.
        "*herd' 'isolated'*" => Process::sequence()->push(Process::result(''))->push(Process::result(''))
            ->whenEmpty(Process::result("| Site | PHP Version |\n| {$name}.test | 8.4 |\n")),
        "*herd' 'isolate'*" => Process::result('Isolated'),
        "*php' '-r'*" => Process::result('8.3.20'),
    ]);

    $result = new SetupRunner([new HerdSiteStep])->runStep($context, new HerdSiteStep);

    expect($result->status)->toBe('done')
        ->and($result->message)->toBe(__('Site served by Herd'));
    Process::assertRan(fn ($process): bool => array_slice((array) $process->command, 1) === ['isolate', '8.4', '--site='.$name]);
});
```

`tests/Feature/Setup/Steps/EnvironmentStepTest.php`: add `use App\Setup\SetupContext;` after `use App\Setup\Detection;`, and insert this closure and these four tests directly before 'starts no storage:link when public/storage already exists (G6-09)':

```php
/** Puts $kind in place of public/storage: a link to the project root, a dangling link or a directory (D63). */
$replaceStorageLink = function (SetupContext $context, string $kind): void {
    unlink($context->path('public/storage'));

    match ($kind) {
        'a link to the project root' => symlink($context->basePath, $context->path('public/storage')),
        'a dangling link' => symlink($context->path('storage/app/gone'), $context->path('public/storage')),
        default => File::ensureDirectoryExists($context->path('public/storage')), // 'a directory'
    };
};

it('is incomplete while public/storage is not a link to storage/app/public (D63)', function (string $kind) use ($replaceStorageLink): void {
    $context = setupSandbox();
    new EnvironmentStep()->run($context);
    $complete = new EnvironmentStep()->isComplete($context);

    $replaceStorageLink($context, $kind);

    expect($complete)->toBeTrue()
        ->and(new EnvironmentStep()->isComplete($context))->toBeFalse();
})->with(['a link to the project root', 'a dangling link', 'a directory']);

it('replaces a wrong or dangling link with storage:link --force (D63)', function (string $kind) use ($replaceStorageLink): void {
    $context = setupSandbox();
    $replaceStorageLink($context, $kind);
    Process::fake(["*'artisan' 'storage:link' '--no-interaction' '--force'*" => function () use ($context): ProcessResult {
        unlink($context->path('public/storage'));
        symlink($context->path('storage/app/public'), $context->path('public/storage'));

        return Process::result('The [public/storage] link has been connected to [storage/app/public].');
    }]);

    $result = new EnvironmentStep()->run($context);

    expect($result->status)->toBe('done')
        ->and(realpath($context->path('public/storage')))->toBe(realpath($context->path('storage/app/public')))
        ->and(new EnvironmentStep()->isComplete($context))->toBeTrue();
    Process::assertRan(fn (PendingProcess $process): bool => array_slice((array) $process->command, 1) === ['artisan', 'storage:link', '--no-interaction', '--force']);
})->with(['a link to the project root', 'a dangling link']);

it('never forces storage:link over a directory, and names what is in the way (D63)', function () use ($replaceStorageLink): void {
    $context = setupSandbox();
    $replaceStorageLink($context, 'a directory');
    File::put($context->path('public/storage/kept.txt'), 'a file someone put there');
    // storage:link refuses an existing directory: "The [public/storage] link already exists."
    Process::fake(["*'artisan' 'storage:link' '--no-interaction'*" => Process::result('The [public/storage] link already exists.')]);

    $result = new EnvironmentStep()->run($context);

    expect($result->status)->toBe('manual')
        ->and($result->command)->toBe('php artisan storage:link')
        ->and($result->message)->toContain(__('public/storage is not a link to storage/app/public'))
        ->and($result->message)->toContain(__('Move public/storage aside, then run php artisan storage:link'))
        ->and(is_file($context->path('public/storage/kept.txt')))->toBeTrue();
    Process::assertRan(fn (PendingProcess $process): bool => array_slice((array) $process->command, 1) === ['artisan', 'storage:link', '--no-interaction']);
    Process::assertDidntRun(fn (PendingProcess $process): bool => in_array('--force', (array) $process->command, true));
});

it('leaves storage:link --force to run when a wrong link stays (D63)', function () use ($replaceStorageLink): void {
    $context = setupSandbox();
    $replaceStorageLink($context, 'a link to the project root');
    Process::fake(["*'artisan' 'storage:link'*" => Process::result('', exitCode: 1)]);

    $result = new EnvironmentStep()->run($context);

    expect($result->status)->toBe('manual')
        ->and($result->command)->toBe('php artisan storage:link --force')
        ->and($result->message)->toContain(__('public/storage is not a link to storage/app/public'))
        ->and($result->message)->not->toContain(__('Move public/storage aside, then run php artisan storage:link'));
});
```

`tests/Feature/Setup/Doctor/ChecksTest.php`. Replace the whole test 'needs the gd extension (§5.4)':

```php
it('needs the gd extension (§5.4)', function (): void {
    $missing = new GdCheck(loaded: false)->run($this->context);

    expect(new GdCheck(loaded: true)->run($this->context)->status)->toBe(CheckStatus::Pass)
        ->and($missing->status)->toBe(CheckStatus::Fail)
        ->and($missing->fix)->toBe(__('Install the gd extension'));
});
```

with:

```php
it('needs the gd extension (§5.4)', function (): void {
    $missing = new GdCheck(loaded: false)->run($this->context);

    expect(new GdCheck(loaded: true, webp: true)->run($this->context)->status)->toBe(CheckStatus::Pass)
        ->and($missing->status)->toBe(CheckStatus::Fail)
        ->and($missing->fix)->toBe(__('Install the gd extension'));
});

it('warns when GD cannot write WebP, which profile photos need (§5.4, E3)', function (): void {
    $withoutWebp = new GdCheck(loaded: true, webp: false)->run($this->context);

    expect($withoutWebp->status)->toBe(CheckStatus::Warn)
        ->and($withoutWebp->message)->toBe(__('GD has no WebP support: profile photos cannot be saved'))
        ->and($withoutWebp->fix)->toBe(__('Install GD with WebP (libwebp)'))
        // Without GD at all the missing extension is the one finding.
        ->and(new GdCheck(loaded: false, webp: false)->run($this->context)->status)->toBe(CheckStatus::Fail)
        // This PHP's own answer, as the doctor and the System page ask it.
        ->and(new GdCheck()->run($this->context)->status)->toBe((imagetypes() & IMG_WEBP) !== 0 ? CheckStatus::Pass : CheckStatus::Warn);
});
```

and insert these two tests directly before 'runs the doctor as setup step 8 (§5.3.3)':

```php
it('fails while public/storage is not a link to storage/app/public, and forces storage:link only over a link (D63)', function (string $kind, string $fix, bool $forced): void {
    $link = $this->sandbox->path('public/storage');
    unlink($link);

    match ($kind) {
        // /storage would serve the project's .env.
        'a link to the project root' => symlink($this->sandbox->basePath, $link),
        'a dangling link' => symlink($this->sandbox->path('storage/app/gone'), $link),
        default => File::ensureDirectoryExists($link), // 'a directory'
    };

    Process::fake(["*'artisan' 'storage:link'*" => Process::result('')]);
    $wrong = new StorageLinkCheck()->run($this->context);
    $fixed = new StorageLinkCheck()->fix($this->context);

    expect($wrong->status)->toBe(CheckStatus::Fail)
        ->and($wrong->message)->toBe(__('public/storage is not a link to storage/app/public'))
        ->and($wrong->fix)->toBe($fix === 'move' ? __('Move public/storage aside, then run php artisan storage:link') : $fix)
        ->and($fixed->status)->toBe(CheckStatus::Fail)
        ->and(is_link($link) || is_dir($link))->toBeTrue();
    Process::assertRan(fn ($process): bool => array_slice((array) $process->command, 1) === ['artisan', 'storage:link', '--no-interaction', ...($forced ? ['--force'] : [])]);
})->with([
    'a link to the project root' => ['a link to the project root', 'php artisan storage:link --force', true],
    'a dangling link' => ['a dangling link', 'php artisan storage:link --force', true],
    'a directory' => ['a directory', 'move', false],
]);

it('passes a storage link that reaches storage/app/public through another link (D63)', function (): void {
    // A deployment that links storage/ itself to a shared directory, with a relative public/storage.
    $link = $this->sandbox->path('public/storage');
    unlink($link);
    symlink('../storage/app/public', $link);

    expect(new StorageLinkCheck()->run($this->context)->status)->toBe(CheckStatus::Pass);
});
```

`tests/Feature/Console/ProductionGuardsTest.php`: add `use Illuminate\Support\Facades\File;` after `use Illuminate\Support\Facades\DB;`, and insert this closure and these two tests directly before 'tells a production bootstrap to follow the checklist instead of the wizard, and never prints a secret (CD-9, CD-10)':

```php
/**
 * Removes APP_ENV from getenv() and $_SERVER, the process sources Bootstrap::isProduction() reads besides .env (phpunit
 * puts APP_ENV=testing there); returns the closure that puts both back.
 */
$withoutProcessAppEnv = function (): Closure {
    $previousEnv = getenv('APP_ENV');
    $previousServer = $_SERVER['APP_ENV'] ?? null;
    putenv('APP_ENV');
    unset($_SERVER['APP_ENV']);

    return function () use ($previousEnv, $previousServer): void {
        putenv($previousEnv === false ? 'APP_ENV' : 'APP_ENV='.$previousEnv);

        if ($previousServer === null) {
            unset($_SERVER['APP_ENV']);
        } else {
            $_SERVER['APP_ENV'] = $previousServer;
        }
    };
};

it('takes the production half when no source names an APP_ENV, as Laravel defaults to production (CD-12, C37)', function (string $line) use ($withoutProcessAppEnv): void {
    // A server .env written by hand: no APP_ENV, or a blank one.
    $path = $this->sandbox->path('.env');
    file_put_contents($path, (string) preg_replace('/^APP_ENV=.*$/m', $line, (string) file_get_contents($path)));
    new EnvFile($path)->set(['APP_KEY' => '', 'HEALTH_CHECK_KEY' => '', 'BACKUP_ARCHIVE_PASSWORD' => '']);
    $this->beforeApplicationDestroyed($withoutProcessAppEnv());
    $bootstrap = new Bootstrap($this->sandbox->basePath, new Toolchain($this->sandbox->path('home')));

    expect(new EnvFile($path)->get('APP_ENV'))->toBeIn([null, ''])
        ->and($bootstrap->isProduction())->toBeTrue();

    expect($bootstrap->run())->toBe([
        __('Generated an application key'),
        __('Generated :key', ['key' => 'HEALTH_CHECK_KEY']),
        __('Generated :key', ['key' => 'BACKUP_ARCHIVE_PASSWORD']),
    ])
        ->and(is_file($this->sandbox->path(SetupToken::PATH)))->toBeFalse()
        ->and(is_file($this->sandbox->path('database/database.sqlite')))->toBeFalse();
    Process::assertNothingRan();
})->with(['no APP_ENV line' => '', 'a blank APP_ENV' => 'APP_ENV=']);

it('still takes the development half for a fresh copy of .env.example, which says local (CD-12, C37)', function () use ($withoutProcessAppEnv): void {
    unlink($this->sandbox->path('.env'));
    File::copy(base_path('.env.example'), $this->sandbox->path('.env.example'));
    $this->beforeApplicationDestroyed($withoutProcessAppEnv());
    Process::fake(["*'artisan' 'migrate' '--graceful' '--force' '--no-interaction'*" => Process::result('')]);
    $bootstrap = new Bootstrap($this->sandbox->basePath, new Toolchain($this->sandbox->path('home')));

    $actions = $bootstrap->run();

    expect($bootstrap->isProduction())->toBeFalse()
        ->and($actions)->toContain(__('Created .env from .env.example'))
        ->and($actions)->toContain(__('Created the one-time setup token'))
        ->and(new EnvFile($this->sandbox->path('.env'))->get('APP_ENV'))->toBe('local');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Setup/Steps/HerdSiteStepTest.php tests/Feature/Setup/Steps/EnvironmentStepTest.php tests/Feature/Setup/Doctor/ChecksTest.php tests/Feature/Console/ProductionGuardsTest.php`

Expected: FAIL.
- `HerdSiteStepTest`: the rows 'global 8.3, not isolated', 'global 8.4, isolated to 8.3' and 'the PHP probe prints nothing' with "Failed asserting that true is identical to false."; the other three rows with "An expected process was not invoked." (no PHP check runs); 'is not complete when the PHP check itself fails' with "Failed asserting that true is false."; 'isolates on a re-run …' with `'done'` expected, `'skipped'` actual ("Already done"). The strengthened 'is complete once served, …' passes (its new fakes are unused yet).
- `EnvironmentStepTest`: all three rows of 'is incomplete while …' with "Failed asserting that true is false."; 'replaces a wrong or dangling link …' (the link still points elsewhere, or `realpath()` is false); 'never forces storage:link over a directory …' and 'leaves storage:link --force to run …' with `'manual'` expected, `'done'` actual.
- `ChecksTest`: 'needs the gd extension' and 'warns when GD cannot write WebP …' with "Unknown named parameter $webp"; the three rows of 'fails while public/storage is not a link …' with `Fail` expected, `Pass` actual. 'passes a storage link that reaches storage/app/public through another link' passes (a guard against an exact-path comparison; mutation 8).
- `ProductionGuardsTest`: both rows of 'takes the production half when no source names an APP_ENV …' with "Failed asserting that false is true."; 'still takes the development half …' passes: it guards CD-12's "a fresh install is unaffected".

- [ ] **Step 3: K9 in `HerdSiteStep::isComplete()`**

`app/Setup/Steps/HerdSiteStep.php`: replace the whole `isComplete()` method, docblock included:

```php
    /**
     * Served (parked or linked), on PHP 8.4 or newer and, when HTTPS was chosen, secured with APP_URL on that https
     * address (RL-16): a site secured by hand, or by a detached run that died before writing APP_URL, still needs
     * run()'s rewrite (g1-08), and a site on an older PHP still needs run()'s `herd isolate`, which "Already done"
     * would skip (K9, DEC-34 (14)). The PHP check runs last, after the cheaper listings. False on any process failure.
     */
    public function isComplete(SetupContext $context): bool
    {
        $herd = $this->toolchain->herd();

        if (! $context->detection->herd || $herd === null) {
            return false;
        }

        try {
            return $this->siteState($context, $herd) === self::SERVED
                && (! $context->answers->https || ($this->appUrlIsSecured($context) && $this->isSecured($context, $herd)))
                && $this->runsSupportedPhp($context, $herd);
        } catch (Throwable) {
            return false;
        }
    }
```

- [ ] **Step 4: D63 in the environment step and the doctor**

`app/Setup/Steps/EnvironmentStep.php`. In the class docblock, replace:

```php
 * or DB_*. It also creates public/storage (G6-09, CD-12): this step always runs, while the frontend step is skipped
 * when a build exists.
 */
```

with:

```php
 * or DB_*. It also creates public/storage (G6-09, CD-12): this step always runs, while the frontend step is skipped
 * when a build exists. Only a public/storage that resolves to storage/app/public counts (D63): a wrong or dangling
 * link is replaced, a directory or file in its place is never removed.
 */
```

Insert this method directly after `missingSecrets()` (before `key()`):

```php
    /**
     * Whether $link resolves to $target, read past PHP's stat cache (D63): a link to anything else (the project root
     * would serve .env under /storage), a dangling link, or a directory or a file in its place is not the storage link.
     * A link that reaches the target through another link, or a relative one, is. The doctor's `storage-link` check
     * reads public/storage the same way.
     */
    public static function isStorageLink(string $link, string $target): bool
    {
        clearstatcache(true, $link);
        $resolved = realpath($link);

        return $resolved !== false && $resolved === realpath($target);
    }
```

In `run()`, replace:

```php
        if (! $this->linkStorage($context)) {
            return StepResult::manual('php artisan storage:link', trim(__('public/storage is missing').'. '.$note));
        }
```

with:

```php
        if (! $this->linkStorage($context)) {
            $link = $context->path('public/storage');

            return StepResult::manual(
                is_link($link) ? 'php artisan storage:link --force' : 'php artisan storage:link',
                trim($this->storageProblem($link).'. '.$note),
            );
        }
```

Replace `storageIsLinked()`, docblock included:

```php
    /** public/storage exists, as a link or otherwise (the doctor's `storage-link` check). */
    private function storageIsLinked(SetupContext $context): bool
    {
        $link = $context->path('public/storage');

        return is_link($link) || file_exists($link);
    }
```

with:

```php
    /** public/storage resolves to storage/app/public (D63; the doctor's `storage-link` check). */
    private function storageIsLinked(SetupContext $context): bool
    {
        return self::isStorageLink($context->path('public/storage'), $context->path('storage/app/public'));
    }

    /** Why public/storage is still not the storage link, for the manual result (D63). */
    private function storageProblem(string $link): string
    {
        if (is_link($link)) {
            return __('public/storage is not a link to storage/app/public');
        }

        if (file_exists($link)) {
            return __('public/storage is not a link to storage/app/public').'. '.__('Move public/storage aside, then run php artisan storage:link');
        }

        return __('public/storage is missing');
    }
```

and replace the whole `linkStorage()` method, docblock included:

```php
    /**
     * `php artisan storage:link` as a child process in the project (G6-09, CD-12), so it links the project and honours
     * its filesystems.links: the CLI php next to the running one, because the wizard runs this step under php-fpm, and
     * the toolchain's environment (DEC-23). A wrong or dangling link is replaced with --force, which storage:link
     * applies to a link only: a directory or a file in its place is never removed (D63). The link is read back, never
     * the exit code; false when it is still not the storage link.
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

        $force = is_link($context->path('public/storage')) ? ['--force'] : [];

        try {
            Process::path($context->basePath)->env($toolchain->environment())->timeout(60)
                ->run([$php, 'artisan', 'storage:link', '--no-interaction', ...$force]);
        } catch (ProcessTimedOutException) {
            return false;
        }

        return $this->storageIsLinked($context);
    }
```

(HEAD's `clearstatcache();` before the read-back moves into `isStorageLink()`.)

`app/Setup/Doctor/Checks/StorageLinkCheck.php` (whole file):

```php
<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Steps\EnvironmentStep;

/**
 * §5.4: public/storage is the storage link, resolving to storage/app/public (D63). Missing, it warns; anything else in
 * its place fails, because a link to the project root would serve .env under /storage and a dangling link or a
 * directory serves no upload. The fix runs `php artisan storage:link` as a child process in the base path, so it links
 * the examined project (and honours its filesystems.links), never the running application; with --force only while a
 * link is in the way, which storage:link replaces, while it never removes a directory or a file.
 */
final readonly class StorageLinkCheck implements Check
{
    public function key(): string
    {
        return 'storage-link';
    }

    public function label(): string
    {
        return __('Storage link');
    }

    public function run(DoctorContext $context): CheckResult
    {
        $link = $context->path('public/storage');

        if (EnvironmentStep::isStorageLink($link, $context->path('storage/app/public'))) {
            return CheckResult::pass(__('public/storage is linked'));
        }

        if (is_link($link)) {
            return CheckResult::fail(__('public/storage is not a link to storage/app/public'), 'php artisan storage:link --force');
        }

        if (file_exists($link)) {
            return CheckResult::fail(__('public/storage is not a link to storage/app/public'), __('Move public/storage aside, then run php artisan storage:link'));
        }

        return CheckResult::warn(__('public/storage is missing'), 'php artisan storage:link');
    }

    public function fix(DoctorContext $context): CheckResult
    {
        $force = is_link($context->path('public/storage')) ? ['--force'] : [];

        $context->process(60)->run([PHP_BINARY, 'artisan', 'storage:link', '--no-interaction', ...$force]);
        $context->forgetSync();

        return $this->run($context);
    }
}
```

- [ ] **Step 5: C37 in `Bootstrap::isProduction()`**

`app/Setup/Support/Bootstrap.php`: replace the whole `isProduction()` method, docblock included:

```php
    /**
     * Whether the project this Bootstrap prepares is a production install (CD-3): its .env, or the process environment,
     * names an APP_ENV outside local, testing and staging, the environments skv:install runs in (`prod` and `live`
     * count too). A server's own APP_ENV overrides the file, and the running app's loaded .env shows there too. When
     * no source names one, it is production too, as Laravel's own default is (config/app.php, CD-12): run() copies
     * .env.example (APP_ENV=local) first, so only a .env written without APP_ENV gets here (C37).
     */
    public function isProduction(): bool
    {
        $environments = array_filter(
            [new EnvFile($this->basePath.'/.env')->get('APP_ENV'), getenv('APP_ENV'), $_SERVER['APP_ENV'] ?? null],
            fn (mixed $environment): bool => is_string($environment) && $environment !== '',
        );

        return $environments === [] || array_any($environments, fn (string $environment): bool => ! in_array($environment, self::DEVELOPMENT, true));
    }
```

- [ ] **Step 6: E3 in `GdCheck`**

`app/Setup/Doctor/Checks/GdCheck.php` (whole file):

```php
<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;

/**
 * §5.4: ext-gd is present (image handling, logo validation), and it writes WebP: profile photos are stored as WebP, so
 * a GD without it fails every photo upload with a server error (E3, DEC-35).
 */
final readonly class GdCheck implements Check
{
    /**
     * @param  bool|null  $loaded  null asks PHP; a test passes the answer
     * @param  bool|null  $webp  whether GD supports WebP; null asks GD, a test passes the answer
     */
    public function __construct(private ?bool $loaded = null, private ?bool $webp = null) {}

    public function key(): string
    {
        return 'gd';
    }

    public function label(): string
    {
        return __('GD extension');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if (! ($this->loaded ?? extension_loaded('gd'))) {
            return CheckResult::fail(__('The gd extension is missing'), __('Install the gd extension'));
        }

        if (! ($this->webp ?? $this->gdSupportsWebp())) {
            return CheckResult::warn(__('GD has no WebP support: profile photos cannot be saved'), __('Install GD with WebP (libwebp)'));
        }

        return CheckResult::pass(__('The gd extension is loaded'));
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }

    /** Asked only once GD is loaded: imagetypes() and IMG_WEBP come with it. */
    private function gdSupportsWebp(): bool
    {
        return (imagetypes() & IMG_WEBP) !== 0;
    }
}
```

- [ ] **Step 7: Lang keys**

Insert in PHP `sort()` order, the same place in both files (the neighbours as of main):

`lang/nl.json`:

```json
    "GD has no WebP support: profile photos cannot be saved": "GD ondersteunt geen WebP: profielfoto's kunnen niet worden opgeslagen",
```

(after "GD extension", before "Generated :key")

```json
    "Install GD with WebP (libwebp)": "Installeer GD met WebP (libwebp)",
```

(after "Impersonation stopped", before "Install Node ^20.19 or >=22.12")

```json
    "Move public/storage aside, then run php artisan storage:link": "Zet public/storage opzij en voer daarna php artisan storage:link uit",
```

(after "Modules", before "My own super admin")

```json
    "public/storage is not a link to storage/app/public": "public/storage is geen link naar storage/app/public",
```

(after "public/storage is missing", before "results")

`lang/en.json`, at the same places:

```json
    "GD has no WebP support: profile photos cannot be saved": "GD has no WebP support: profile photos cannot be saved",
    "Install GD with WebP (libwebp)": "Install GD with WebP (libwebp)",
    "Move public/storage aside, then run php artisan storage:link": "Move public/storage aside, then run php artisan storage:link",
    "public/storage is not a link to storage/app/public": "public/storage is not a link to storage/app/public",
```

Then run `composer fix`.

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Setup tests/Feature/Console tests/Feature/System tests/Feature/Health tests/Feature/LangCoverageTest.php tests/Feature/LangFilesTest.php`

Expected: PASS, with Task 1's stray recorder reporting nothing new (the prototype logged every unfaked command at its source: K9 adds none outside the strengthened test).

- [ ] **Step 9: Verify by mutation**

For each mutation: copy the file aside with `cp`, change it, run the named test file, confirm the named test fails, copy the original back and confirm the file passes (never `git stash`). Name each mutation and its result in the task report. The prototype ran every row and saw each named test fail.

| # | File: mutation | Must fail |
|---|---|---|
| 1 | `HerdSiteStep.php`: drop `&& $this->runsSupportedPhp($context, $herd)` from `isComplete()` | `HerdSiteStepTest` › every row of 'is complete only while the site runs PHP 8.4 or newer …', 'is not complete when the PHP check itself fails', 'isolates on a re-run …' |
| 2 | `EnvironmentStep.php`: `storageIsLinked()` returns `is_link($context->path('public/storage')) \|\| file_exists($context->path('public/storage'))` (HEAD's rule) | `EnvironmentStepTest` › the three rows of 'is incomplete while …', both rows of 'replaces a wrong or dangling link …', 'never forces storage:link over a directory …', 'leaves storage:link --force to run …' |
| 3 | `EnvironmentStep.php`: `$force = [];` | › both rows of 'replaces a wrong or dangling link …' (the unforced command has no fake) |
| 4 | `EnvironmentStep.php`: `$force = ['--force'];` | › 'links public/storage through php artisan storage:link in the project, …' (the existing argv pin), 'never forces storage:link over a directory …' |
| 5 | `StorageLinkCheck.php`: the pass condition `is_link($link) \|\| file_exists($link)` | `ChecksTest` › the three rows of 'fails while public/storage is not a link …' (`Pass` actual) |
| 6 | `StorageLinkCheck.php` `fix()`: `$force = [];` | › 'fails while public/storage is not a link …' › 'a link to the project root', 'a dangling link' |
| 7 | `StorageLinkCheck.php` `fix()`: `$force = ['--force'];` | › 'fails while public/storage is not a link …' › 'a directory' |
| 8 | `EnvironmentStep.php` `isStorageLink()`: `return $resolved !== false && readlink($link) === $target;` (an exact-path comparison) | `ChecksTest` › 'passes a storage link that reaches storage/app/public through another link' |
| 9 | `Bootstrap.php`: `return array_any(…)` without `$environments === [] \|\|` (HEAD's rule) | `ProductionGuardsTest` › both rows of 'takes the production half when no source names an APP_ENV …' |
| 10 | `GdCheck.php`: `if (false) {` in place of the WebP condition | `ChecksTest` › 'warns when GD cannot write WebP …' |
| 11 | `GdCheck.php`: `(imagetypes() & IMG_WEBP) === 0` | › 'warns when GD cannot write WebP …' (this PHP's own answer) |

Not mutation-covered: `clearstatcache(true, $link)` in `isStorageLink()` (see the notes; only an out-of-process change of the link needs it).

- [ ] **Step 10: CHANGELOG**

`CHANGELOG.md`, under `## [Unreleased]`:
- `### Added`, as its last line (after Task 7's):

```markdown
- The doctor's `gd` row warns when GD has no WebP support, which profile photos need.
```

- `### Changed`, as its last line:

```markdown
- `skv:bootstrap` treats a project where no source names an `APP_ENV` (no line in `.env`, a blank value, nothing in the process environment) as production, as Laravel does, and only prepares `.env`, `APP_KEY` and the generated secrets there; a fresh copy of `.env.example` still says `local`.
```

- `### Fixed`, as its last two lines (after Task 7's):

```markdown
- Setup no longer reports the Herd step as already done for a site Herd serves with a PHP older than 8.4: a re-run isolates it with `herd isolate 8.4 --site=<name>`.
- Setup and the doctor accept `public/storage` only as a link to `storage/app/public`: a link elsewhere (one to the project root would serve `.env` under `/storage`), a dangling link or a directory in its place makes the doctor's `storage-link` row fail; setup and `skv:doctor --fix` replace a wrong link with `storage:link --force` and never remove a directory.
```

The README's `skv:bootstrap` row, its production checklist and the doctor's `--fix` list, and the Dutch manual, are Task 17's (CD-14).

- [ ] **Step 11: The gate, then commit**

Run the Global Constraints gate:
- `composer check`
- `composer test:pgsql` (nothing here is driver-specific)
- the modules-off run
- `npm run build && composer test:browser` (no view changes; the browser `SetupWizardTest` drives the setup steps, so run it as the gate requires)
- `npm run build:setup && git diff --exit-code resources/setup/setup.css` (no setup view changes, so expect no diff)
- `php artisan config:clear`

Commit in two parts:

```bash
git add app/Setup/Steps/HerdSiteStep.php tests/Feature/Setup/Steps/HerdSiteStepTest.php \
    app/Setup/Steps/EnvironmentStep.php app/Setup/Doctor/Checks/StorageLinkCheck.php \
    tests/Feature/Setup/Steps/EnvironmentStepTest.php lang/nl.json lang/en.json
git commit -m "fix: re-run the herd step on an older php and accept only the real storage link

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"

git add app/Setup/Support/Bootstrap.php app/Setup/Doctor/Checks/GdCheck.php \
    tests/Feature/Setup/Doctor/ChecksTest.php tests/Feature/Console/ProductionGuardsTest.php CHANGELOG.md
git commit -m "fix: treat a missing app_env as production and warn about gd without webp

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

The lang files go with the first commit, whose code uses two of the four keys (`LangCoverageTest` checks only that every used key exists; the two gd keys wait unused for one commit). `ChecksTest.php` holds both the storage-link and the gd tests, so it goes with the second commit, where `GdCheck` gains its `webp` parameter. The pre-commit hook runs Pint and Larastan on each commit's staged files.

---

---

### Task 9: Setup gate, wizard runner, setup and ops weak tests (§5.3.4, §4.9, §4.12, §7.2; DEC-24, DEC-33; C68=D100, C73=D102, D57, C55=D94, C50=D92, C89=D104, C90=D105, C95=D106, C100=D107, C107=D108, C114=D112, C139=D90, C140=D91)

Three small behaviour fixes in the setup code, then a sweep of setup and ops tests whose titles promise more than they check:
- **The Herd stage's own error reaches the page (L7b-28).** When `HerdState::begin()` or the launcher fails after step 9, `WizardRunner::finish()` strands the stage (`strand()`: the Herd files go, the marker records what the Herd step did not do) and rethrows. When `strand()` itself throws (here: `.env` is gone, so switching passkeys off fails), its exception replaces the cause, and the page shows the wrong message. `strand()` now runs in its own `try`: its failure is `report()`ed and the original exception is rethrown.
- **A garbled pid never reads as a live step (L7b-33).** `isRunning()` read `.started` with `(int) strtok(…)` and only checked `$pid > 1`. `4294967295` passes and `posix_kill()` narrows it to pid_t `-1`, so `kill(-1, 0)` succeeds and the step reads as running for up to `LIVE_PID_SECONDS` (Re-check is refused); `12345x` reads as pid 12345. Only `ctype_digit` values with `1 < $pid <= 2147483647` count now.
- **More proxy headers are "not direct" (M7c T10 #5).** `DirectRequest::HEADERS` gains `x-client-ip`, `client-ip`, `x-cluster-client-ip`, `fastly-client-ip`, `x-host`, `x-original-url`, a bare `x-forwarded` and `forwarded-for`, and `PREFIXES` gains `tailscale-`. It stays a deny-list (DEC-33's residual); none of these is a header a browser or Herd's nginx sends on a direct request.
- **Weak tests made to fail under a named mutation:** the referrer policy on refused setup requests (L7b-10), the IPv4-mapped addresses (L7b-15), every health row while the database is down (L7b-49), the apex row's message (L7b-50), the dataset `match`es that silently fell back to a default host (L7b-55), the System page's unavailable block (L7b-60), `$_SERVER` in the PGSSLMODE tests (L7b-67), the `clone)` marker in `ComposerScriptsTest` (L7b-74), and `CreateOrganisationCommandTest`'s assertion that could not fail (L-46) plus an untyped closure (L-49).

Re-verification on `5a621c3`: every item is open as triaged. One correction: L7b-10's triage says "a reorder that puts `EnsureSetupAccess` first passes today"; it does not (the token redirect is answered by `EnsureSetupAccess` itself, so the existing g3-13 test loses its header). The new 404 assertions still pin what nothing pinned: the header on a refused request, which the mutation "set the header only when the response is not a client error" removes while every existing assertion stays green.

**Files:**
- Modify:
  - `app/Setup/Wizard/WizardRunner.php` (`finish()`'s `catch`: `strand()` in its own `try`; `isRunning()`: the pid parse and its docblock)
  - `app/Setup/Http/DirectRequest.php` (`HEADERS`, `PREFIXES`, the class docblock)
  - `CHANGELOG.md`
- Test:
  - `tests/Feature/Setup/Wizard/WizardRunnerTest.php` (two new tests)
  - `tests/Feature/Setup/Wizard/SetupAccessTest.php` (header dataset, address dataset, the g3-13 test, the g3-35 `match`)
  - `tests/Feature/Setup/SetupModeTest.php` (header dataset)
  - `tests/Feature/Setup/Wizard/HerdStageTest.php` (the g3-13 Herd-stage test; adds to Task 8's changes)
  - `tests/Feature/Health/HealthChecksTest.php`, `tests/Feature/Health/HealthCheckEndpointTest.php`, `tests/Feature/System/SystemPageTest.php`
  - `tests/Feature/Auth/PasskeyTest.php` (the three `match`es only; Task 10 adds cases after this task)
  - `tests/Feature/Backups/ConfigurePgDumpTest.php`, `tests/Feature/Tooling/ComposerScriptsTest.php`, `tests/Feature/Console/CreateOrganisationCommandTest.php`
  - unchanged and green: `tests/Feature/Auth/QuickLoginTest.php`, the rest of `tests/Feature/Setup`

**Interfaces:**
- Consumes:
  - `useSetupWizard(): SetupContext`, `setupSandbox()`, `pageSnapshot()`, `onTenant()`, `onApex()`, `onAdminHost()` (`tests/Pest.php`, unchanged)
  - `WizardRunnerTest`'s file-local `$beforeFrontend(SetupContext $context): string` (steps 1–4 done, returns the steps directory)
  - `HerdState::PATH` (`storage/app/private/skv-herd.json`), `WizardState::STEPS`, `WizardRunner::PAGE_STEPS`, `StateFile::write()`'s "Could not write :file." and `EnvFile::set()`'s "The environment file [:path] does not exist." (unchanged)
  - Task 8's state of `tests/Feature/Setup/Wizard/HerdStageTest.php` (this task changes one test in it, by title)
  - Task 1's RL-12 hook in `tests/Pest.php`: the Setup, Console, Backups, Health and System tests below start no process, so it records no stray
- Produces: nothing new (no class, method, helper or lang key).

**Notes for the implementer:**
- **Why `.env` is deleted in the L7b-28 test.** `finish()` needs `strand()` to throw after `HerdState::begin()` failed. With `https: true` and the passkeys module in the answers, `strand()`'s `applyHttpsConsequence()` switches passkeys off through `EnvFile::set()`, which throws "The environment file [...] does not exist." when there is no `.env`. `syncRuntimeConfig()` and `MarkerStep` both cope without `.env`, so the marker is still written first. The sandbox is the test's own; nothing outside it is touched.
- **Datasets of closures.** The pid dataset passes closures and the test calls `$pid()`: `getmypid()` must be the pid of the process that runs the test (a parallel worker), not of the one that collected it. Pest passes a closure unchanged to a parameter typed `Closure`.
- **`kill(-1, 0)`.** On macOS and Linux `posix_kill(4294967295, 0)` returns true as long as the user owns another process, which is why that row is red today. The fix never calls `posix_kill()` for it.
- **`match` arms (L7b-55).** Each dataset `match` names every row and ends with `default => throw new LogicException(…)` (the Global Constraints' default arm), so a mistyped row errors instead of silently using the last host. `LogicException` is a global class, written unqualified like the other test files do.
- **No lang keys, no README section** (Task 17 updates the header lists in README, the manual and the spec; see its notes).
- **No `git stash`.** For mutation checks copy the file aside with `cp`, change it, run the test, copy it back; keep the mode of `scripts/e2e-local.sh` (it is executable), and run `php artisan view:clear` after the Blade mutation.

- [ ] **Step 1: The failing WizardRunner tests**

`tests/Feature/Setup/Wizard/WizardRunnerTest.php`: insert this test directly before `it('detects the machine once per setup, not on every poll (CD-15, g3-29)', …)` (after the RL-8 tests that use `$beforeFrontend`):

```php
it('never takes a garbled pid in the started file for a live step process (RL-8, L7b-33)', function (Closure $pid) use ($beforeFrontend): void {
    // Arrange: a quiet step whose started file holds something the launcher's shell never writes, past the stall bound.
    $context = useSetupWizard();
    $steps = $beforeFrontend($context);
    File::put($steps.'/frontend.started', $pid()."\n");
    File::put($steps.'/frontend.log', 'npm ci');
    $this->travel(31)->minutes();

    expect(app(WizardRunner::class)->advance())->toBe([
        'key' => 'frontend',
        'state' => 'done',
        'message' => __('The step has not finished; see :log.', ['log' => WizardState::STEPS.'/frontend.log']),
    ])->and(app(WizardRunner::class)->state()?->results['frontend']->status ?? null)->toBe('manual');
})->with([
    // posix_kill() narrows it to pid_t: -1, which signals every process this user owns, so kill() succeeds.
    'a number past pid_t' => [fn (): string => '4294967295'],
    // (int) would read this test's own, live pid.
    'a live pid with trailing garbage' => [fn (): string => getmypid().'x'],
]);
```

and insert this test directly before `it('writes the port the wizard was opened on into APP_URL without Herd (CD-15, g1-17)', …)` (after the g3-32 test):

```php
it('throws the Herd stage\'s own failure and reports the stranding\'s when both fail after step 9 (g3-32, L7b-28)', function (): void {
    // Arrange: steps 1–8 are done and HTTPS with passkeys was chosen; a directory stands where the Herd state goes, so
    // HerdState::begin() fails after the marker, and .env is gone, so switching passkeys off while stranding fails too.
    Exceptions::fake();
    $context = useSetupWizard();
    $answers = Answers::fromArray(['modules' => ['passkeys', 'impersonation'], 'https' => true], Detection::none(), $context->folderName());
    new WizardState('localhost', $answers, Detection::none(), array_fill_keys(WizardRunner::PAGE_STEPS, StepResult::done('ok')))->save($context->basePath);
    File::ensureDirectoryExists($context->path(HerdState::PATH));
    File::delete($context->path('.env'));

    expect(fn () => app(WizardRunner::class)->finish())
        ->toThrow(RuntimeException::class, __('Could not write :file.', ['file' => basename(HerdState::PATH)]))
        ->and(Marker::exists($context))->toBeTrue();
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === __('The environment file [:path] does not exist.', ['path' => $context->path('.env')]));
});
```

Every import is already in the file (`Answers`, `Detection`, `Marker`, `StepResult`, `HerdState`, `WizardRunner`, `WizardState`, `Exceptions`, `File`).

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test --compact tests/Feature/Setup/Wizard/WizardRunnerTest.php --filter='garbled|both fail'`

Expected: FAIL, all three:
- both rows of 'never takes a garbled pid …': "Failed asserting that two arrays are identical." (`'state' => 'running'`, `'message' => ''`)
- 'throws the Herd stage's own failure …': "Failed asserting that 'The environment file [….env] does not exist.' […] contains "Could not write skv-herd.json."."

- [ ] **Step 3: The WizardRunner fixes**

`app/Setup/Wizard/WizardRunner.php`, in `finish()`, replace:

```php
            } catch (Throwable $exception) {
                $this->strand($context, $exception);

                throw $exception;
            }
```

with:

```php
            } catch (Throwable $exception) {
                // L7b-28: a failing strand() is reported, never thrown, so the page shows why the Herd stage failed.
                try {
                    $this->strand($context, $exception);
                } catch (Throwable $strandFailure) {
                    report($strandFailure);
                }

                throw $exception;
            }
```

Replace the whole `isRunning()` method, docblock included:

```php
    /**
     * RL-8: whether the process the launcher's shell wrote into `<step>.started` still runs. It does not once the exit
     * file exists, when the file holds no pid (the inline launcher, or a shell that never started) or anything but one
     * (L7b-33), or without posix.
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

        $line = strtok((string) @file_get_contents($started), "\n");

        // Digits only, as the shell's `echo $$` writes them (L7b-33). 0 would signal this process group, 1 is launchd
        // or init, and a number past pid_t wraps in posix_kill(): 4294967295 becomes kill(-1, 0), which succeeds.
        if (! is_string($line) || ! ctype_digit($line) || (int) $line <= 1 || (int) $line > 2147483647) {
            return false;
        }

        return posix_kill((int) $line, 0);
    }
```

- [ ] **Step 4: Run the WizardRunner tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Setup/Wizard/WizardRunnerTest.php`

Expected: PASS (34 tests; the existing RL-8 tests still write `getmypid()."\n"`, which passes the new check).

- [ ] **Step 5: The failing gate tests**

`tests/Feature/Setup/Wizard/SetupAccessTest.php`:

Replace the dataset of 'answers 404 behind a proxy or tunnel (§7.2, DEC-24)':

```php
})->with(['X-Forwarded-For', 'X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Forwarded-Port', 'X-Forwarded-Prefix', 'X-Original-Host', 'Forwarded', 'X-Expose-Subdomain', 'Via', 'X-Real-IP', 'True-Client-IP', 'CF-Connecting-IP', 'CF-Ray']);
```

with:

```php
})->with([
    'X-Forwarded-For', 'X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Forwarded-Port', 'X-Forwarded-Prefix', 'X-Original-Host', 'Forwarded',
    'X-Expose-Subdomain', 'Via', 'X-Real-IP', 'True-Client-IP', 'CF-Connecting-IP', 'CF-Ray',
    // M7c T10 #5: what other proxies, CDNs and tunnels add.
    'X-Client-IP', 'Client-IP', 'X-Cluster-Client-IP', 'Fastly-Client-IP', 'X-Host', 'X-Original-URL', 'X-Forwarded', 'Forwarded-For',
    'Tailscale-User-Login',
]);
```

Replace the dataset of 'answers 404 to a request from another machine and never hands it the token (RL-4, g4-01)':

```php
})->with(['LAN' => '192.168.1.20', 'public' => '203.0.113.7', 'IPv6' => '2001:db8::1']);
```

with:

```php
})->with([
    'LAN' => '192.168.1.20',
    'public' => '203.0.113.7',
    'IPv6' => '2001:db8::1',
    // L7b-15: IpUtils matches an IPv4-mapped address against IPv6 ranges only, so even the mapped loopback is refused.
    'IPv4-mapped loopback' => '::ffff:127.0.0.1',
    'IPv4-mapped LAN' => '::ffff:192.168.1.20',
]);
```

Replace the whole g3-13 test:

```php
it('sends no referrer from the wizard, whose first URL carries the token, also when it refuses the token (g3-13, L7b-10)', function (): void {
    $context = useSetupWizard();

    $this->get('/welcome-setup?token=wrong')->assertNotFound()->assertHeader('Referrer-Policy', 'no-referrer');
    $this->get('/welcome-setup?token='.new SetupToken($context->basePath)->value())->assertRedirect()->assertHeader('Referrer-Policy', 'no-referrer');
    $this->get('/welcome-setup')->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
});
```

In 'opens on the apex, the admin host and a tenant host until the marker exists (§6.2, g3-35)', replace the `match`:

```php
    $host = match ($kind) {
        'apex' => Hosts::apex(),
        'admin' => Hosts::admin(),
        'tenant' => Hosts::tenant('acme'),
        default => throw new LogicException("Unknown host kind [{$kind}]."),
    };
```

`tests/Feature/Setup/SetupModeTest.php`: replace the dataset of 'never hands the token to a proxied or tunnelled request (§5.3.4, DEC-24)':

```php
})->with(['X-Forwarded-For', 'X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Original-Host', 'Forwarded', 'X-Expose-Subdomain', 'Via', 'X-Real-IP', 'True-Client-IP', 'CF-Connecting-IP', 'CF-Ray']);
```

with:

```php
})->with([
    'X-Forwarded-For', 'X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Original-Host', 'Forwarded', 'X-Expose-Subdomain', 'Via', 'X-Real-IP',
    'True-Client-IP', 'CF-Connecting-IP', 'CF-Ray',
    // M7c T10 #5: what other proxies, CDNs and tunnels add.
    'X-Client-IP', 'Client-IP', 'X-Cluster-Client-IP', 'Fastly-Client-IP', 'X-Host', 'X-Original-URL', 'X-Forwarded', 'Forwarded-For',
    'Tailscale-User-Login',
]);
```

`tests/Feature/Setup/Wizard/HerdStageTest.php`: in the test that starts `it('sends Referrer-Policy: no-referrer from every Herd-stage endpoint, whose URLs carry the secret (g3-13)'`, change the title to:

```php
it('sends Referrer-Policy: no-referrer from every Herd-stage endpoint, whose URLs carry the secret, also when it refuses the secret (g3-13, L7b-10)', function () use ($afterStepNine): void {
```

and append, as its last statement (after the `/welcome-setup/login?secret='.$secret` line):

```php
    $this->get('/welcome-setup/login?secret=wrong')->assertNotFound()->assertHeader('Referrer-Policy', 'no-referrer');
```

- [ ] **Step 6: Run them to verify the header rows fail**

Run: `php artisan test --compact tests/Feature/Setup/Wizard/SetupAccessTest.php tests/Feature/Setup/SetupModeTest.php tests/Feature/Setup/Wizard/HerdStageTest.php`

Expected: FAIL, exactly the 18 new header rows:
- the nine new rows of 'answers 404 behind a proxy or tunnel' ("Expected response status code [404] but received 302.")
- the nine new rows of 'never hands the token to a proxied or tunnelled request' ("Failed asserting that two strings are equal.", the token redirect instead of `/login`)

The IPv4-mapped rows, the referrer assertions and the `match` pass already: they pin behaviour no test pinned (their mutations are in Step 13).

- [ ] **Step 7: The DirectRequest headers**

`app/Setup/Http/DirectRequest.php`: replace the class docblock and the two constants:

```php
/**
 * A request straight from the local machine (§5.3.4, DEC-24, RL-4): the local environment, a loopback REMOTE_ADDR
 * (127.0.0.0/8 or ::1) read raw, never through the trusted proxies, and none of the headers a proxy or a tunnel adds:
 * no X-Forwarded-*, X-Expose-*, CF-* or Tailscale-* of any kind, no Forwarded, Via, X-Original-Host, X-Real-IP or
 * True-Client-IP (L7b-14: cloudflared and other tunnels strip X-Forwarded-* but add these), and no client-address or
 * original-URL header of other proxies and CDNs: X-Client-IP, Client-IP, X-Cluster-Client-IP, Fastly-Client-IP,
 * X-Host, X-Original-URL, a bare X-Forwarded or Forwarded-For (M7c T10 #5). The address alone is not enough,
 * because `herd share` traffic arrives from loopback too; the headers alone are not enough, because `php artisan
 * serve --host=0.0.0.0` lets the LAN in without any (g4-01). The headers are a deny-list: a tunnel that adds none of
 * them (ssh -R, socat) stays indistinguishable from the browser on this machine, so the manuals warn against tunnels
 * (DEC-33).
 */
final class DirectRequest
{
    private const array LOOPBACK = ['127.0.0.0/8', '::1'];

    private const array HEADERS = [
        'client-ip', 'fastly-client-ip', 'forwarded', 'forwarded-for', 'true-client-ip', 'via', 'x-client-ip',
        'x-cluster-client-ip', 'x-forwarded', 'x-host', 'x-original-host', 'x-original-url', 'x-real-ip',
    ];

    private const array PREFIXES = ['cf-', 'tailscale-', 'x-expose-', 'x-forwarded-'];
```

`isDirect()` is unchanged: Symfony's `HeaderBag` lower-cases the names, so the lists are lower case.

- [ ] **Step 8: Run the gate tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Setup tests/Feature/Auth/QuickLoginTest.php`

Expected: PASS (the quick login and the Herd stage use the same `isDirect()`; their tests send none of the new headers).

- [ ] **Step 9: The ops tests**

`tests/Feature/Health/HealthChecksTest.php`, in 'skips the queue and failed-jobs checks while the default database is down, so only the database check fails (F2)', replace the `expect` chain with:

```php
    expect($report['healthy'])->toBeFalse()
        ->and($report['checks']['database']['status'])->toBe(CheckStatus::Fail->value)
        ->and(Arr::only($report['checks']['queue'], ['status', 'message']))->toBe($skipped)
        ->and(Arr::only($report['checks']['failed-jobs'], ['status', 'message']))->toBe($skipped)
        // L7b-49: every row of the report, so a check added later that fails with the database fails this test.
        ->and(array_keys(array_filter($report['checks'], fn (array $row): bool => $row['status'] === CheckStatus::Fail->value)))->toBe(['database']);
```

(The mail row reads the running configuration through `DoctorContext::withoutDetection()`, the `array` mailer in tests, so it passes on every machine; the test turns the backups module off itself.)

`tests/Feature/Health/HealthCheckEndpointTest.php`, in 'answers /up on the apex, the admin host and an organisation host, without a cookie (F9)', replace the `match`:

```php
    $host = match ($kind) {
        'apex' => Hosts::apex(),
        'admin host' => Hosts::admin(),
        'organisation host' => Hosts::tenant('acme'),
        default => throw new LogicException("Unknown host kind [{$kind}]."),
    };
```

`tests/Feature/System/SystemPageTest.php`, in 'runs only the web-safe doctor checks on the sandbox, starting no process (RL-2, RL-7, F12)', replace the last two lines of the first `expect` chain:

```php
        // F12: the apex row reads the sandbox's .env, not the repository's.
        ->and(array_column($rows, 'status', 'key')['apex'])->toBe(CheckStatus::Fail->value);
```

with:

```php
        // F12: the apex row reads the sandbox's .env, not the repository's (L7b-50: its message names the sandbox's values).
        ->and(array_column($rows, 'status', 'key')['apex'])->toBe(CheckStatus::Fail->value)
        ->and(array_column($rows, 'message', 'key')['apex'])->toBe(__('TENANCY_APEX_DOMAIN (:apex) differs from the APP_URL host (:host)', ['apex' => 'other.test', 'host' => 'my-app.test']));
```

and in 'shows the health report and the heartbeat as unavailable while the ops store is broken, never a 500 (F4)', make the first assertion after `assertOk()` the block's marker:

```php
    $this->get(route('system.index'))->assertOk()
        ->assertSeeHtml('data-test="system-health-unavailable"')
        ->assertSee(__('The health report is not available right now'))
```

(the rest of the chain unchanged).

`tests/Feature/Backups/ConfigurePgDumpTest.php`: in 'leaves PGSSLMODE alone for a SQLite source and a connection --db-name leaves out (L-05, L7b-68)', replace the `expect` chain with:

```php
    // L7b-67: all three stores; $_SERVER is the one Symfony's Process hands pg_dump.
    expect(getenv('PGSSLMODE'))->toBeFalse()
        ->and($_ENV)->not->toHaveKey('PGSSLMODE')
        ->and($_SERVER)->not->toHaveKey('PGSSLMODE');
```

and in 'leaves PGSSLMODE to backup:run, which knows its --config, for every command it listens to (L-05, L7b-68)', replace the `expect` chain with:

```php
    expect(getenv('PGSSLMODE'))->toBeFalse()
        ->and($_ENV)->not->toHaveKey('PGSSLMODE')
        ->and($_SERVER)->not->toHaveKey('PGSSLMODE');
```

(`PgSslModeSnapshot::takeAndClear()` in the file's `beforeEach` already clears all three stores and restores them afterwards.)

`tests/Feature/Tooling/ComposerScriptsTest.php`, in 'repeats the commands of composer setup in the clone route of scripts/e2e-local.sh (g4-25, RL-11)', replace the `$clone = …` line:

```php
    $clone = $normalise(Str::between((string) file_get_contents(base_path('scripts/e2e-local.sh')), "        clone)\n", ';;'));
```

with:

```php
    $script = (string) file_get_contents(base_path('scripts/e2e-local.sh'));
    $marker = "        clone)\n";

    // L7b-74: without the arm's marker Str::between() returns the whole script, which holds the commands elsewhere.
    expect($script)->toContain($marker);

    $clone = $normalise(Str::betweenFirst($script, $marker, ';;'));
```

`tests/Feature/Console/CreateOrganisationCommandTest.php`:
- retitle 'acts as the super admin --as names, and refuses another address (CD-7)' to `'acts as the super admin --as names, and refuses another address without promoting it (CD-7)'`, and replace its last line `->and($member->exists)->toBeTrue();` with:

```php
        // L-46: the refused --as user is still a plain member.
        ->and(Tenant::bypass(fn (): ?bool => $member->fresh()?->is_super_admin))->toBeFalse();
```

- in 'invites the first admin in the app\'s default language unless --locale says otherwise (A64)', type the inner closure (L-49):

```php
    $locale = fn (string $email): ?Locale => Tenant::bypass(fn (): ?Locale => User::query()->where('email', $email)->first()?->locale);
```

- [ ] **Step 10: The passkey test's `match`es**

`tests/Feature/Auth/PasskeyTest.php`, three `match`es (L7b-55). Replace `passkeyHost()`:

```php
/** Switch to a test host ('tenant', 'apex' or 'admin') and return its browser origin; another name is a typo. */
function passkeyHost(string $host, Organisation $organisation): string
{
    match ($host) {
        'tenant' => onTenant($organisation),
        'apex' => onApex(),
        'admin' => onAdminHost(),
        default => throw new LogicException("Unknown host kind [{$host}]."),
    };

    return Hosts::origin(match ($host) {
        'tenant' => Hosts::tenant($organisation->slug),
        'apex' => Hosts::apex(),
        default => Hosts::admin(),
    });
}
```

(the second `match` runs only after the first accepted the name, so its `default` is the admin host). In 'refuses a passkey of a user who is no longer active or was deleted', replace the `match`:

```php
    match ($change) {
        'disabled' => DB::table('users')->where('id', $member->id)->update(['status' => 'disabled']),
        'deleted' => $member->delete(),
        default => throw new LogicException("Unknown change [{$change}]."),
    };
```

In 'records a failed passkey sign-in without a user when the passkey names nobody this host may show (CD-6, R1)', replace the last arm of the `match`:

```php
        default => null, // 'an unknown passkey': nothing is registered
```

with:

```php
        'an unknown passkey' => null, // nothing is registered
        default => throw new LogicException("Unknown owner [{$owner}]."),
```

- [ ] **Step 11: Run the swept tests**

Run: `php artisan test --compact tests/Feature/Health tests/Feature/System/SystemPageTest.php tests/Feature/Backups/ConfigurePgDumpTest.php tests/Feature/Tooling/ComposerScriptsTest.php tests/Feature/Console/CreateOrganisationCommandTest.php tests/Feature/Auth/PasskeyTest.php`

Expected: PASS. These pin current behaviour; Step 13 proves each one fails under its mutation.

- [ ] **Step 12: CHANGELOG (Fixed)**

`CHANGELOG.md`, under `## [Unreleased]` › `### Fixed`, append at the end of the list:

```markdown
- When the Herd stage cannot start after step 9 and recording that fails too, the wizard shows why the Herd stage failed instead of the second error, which goes to the log.
- A background setup step whose pid file holds anything but a plain process id (such as `4294967295`, which `posix_kill()` turned into "every process") no longer reads as running, so *Re-check* is not refused for up to four hours.
```

The Security line follows in Step 14, between the first and the second commit, so each commit carries its own lines.

- [ ] **Step 13: Verify by mutation**

For each mutation: copy the file aside with `cp`, change it, run the named test file with `php artisan test --compact <file>`, confirm the named tests fail, copy the original back and confirm the file passes (never `git stash`; run `php artisan view:clear` after mutation 10). Name each mutation and its result in the task report.

| # | Item | Mutation | Must fail |
|---|---|---|---|
| 1 | L7b-28 | `WizardRunner::finish()`: call `$this->strand($context, $exception);` without its `try`/`catch` | WizardRunnerTest 'throws the Herd stage's own failure …' (the `.env` message instead of "Could not write skv-herd.json.") |
| 2 | L7b-33 | `isRunning()`: drop `\|\| (int) $line > 2147483647` | WizardRunnerTest 'never takes a garbled pid …' row 'a number past pid_t' (`'state' => 'running'`) |
| 3 | L7b-33 | `isRunning()`: drop `\|\| ! ctype_digit($line)` | the same test, row 'a live pid with trailing garbage' |
| 4 | M7c T10 #5 | `DirectRequest`: put back the old `HEADERS` (`['forwarded', 'true-client-ip', 'via', 'x-original-host', 'x-real-ip']`) and `PREFIXES` (`['cf-', 'x-expose-', 'x-forwarded-']`) | the 18 new header rows of SetupAccessTest and SetupModeTest |
| 5 | L7b-15 | `DirectRequest::isDirect()`: after `$address = $request->server('REMOTE_ADDR');` add `$address = is_string($address) ? (string) preg_replace('/^::ffff:/i', '', $address) : $address;` | SetupAccessTest 'answers 404 to a request from another machine …' row 'IPv4-mapped loopback' (the token is handed out) |
| 6 | L7b-15 | `DirectRequest::LOOPBACK`: add `'::ffff:0:0/96'` | the same test, rows 'IPv4-mapped loopback' and 'IPv4-mapped LAN' |
| 7 | L7b-10 | `SetReferrerPolicy::handle()`: wrap the `set()` in `if (! $response->isClientError()) { … }` | SetupAccessTest 'sends no referrer from the wizard … also when it refuses the token' and HerdStageTest 'sends Referrer-Policy: no-referrer from every Herd-stage endpoint … also when it refuses the secret' ("Header [Referrer-Policy] not present on response."); the pre-task tests stay green under it |
| 8 | L7b-49 | `LastBackupCheck::run()`: delete the `if (! Skv::moduleEnabled('backups')) { return CheckResult::skipped(…); }` block | HealthChecksTest 'skips the queue and failed-jobs checks …' (`['database', 'last-backup']`); the pre-task test stays green |
| 9 | L7b-50 | `ApexCheck::run()`: swap the placeholder values (`['apex' => $host, 'host' => $apex]`) | SystemPageTest 'runs only the web-safe doctor checks …' (the message differs; the status still fails) |
| 10 | L7b-60 | `resources/views/pages/system/index/index.blade.php`: rename `data-test="system-health-unavailable"` to `data-test="system-health-gone"` | SystemPageTest 'shows the health report and the heartbeat as unavailable …' |
| 11 | L7b-55 | test-side: rename a dataset row (`'organisation host'` → `'organization host'` in HealthCheckEndpointTest, `'deleted'` → `'removed'`, `'admin'` → `'admin host'` in 'names the passkey's user on a central host', `'an unknown passkey'` → `'an unknown key'` in PasskeyTest, `'tenant'` → `'tennant'` in SetupAccessTest's g3-35 test) | the renamed row errors with "Unknown host kind […]" / "Unknown change […]" / "Unknown owner […]"; before this task each passed silently |
| 12 | L7b-67 | `ConfigurePgDump::passSslMode()`: add `else { $_SERVER['PGSSLMODE'] = 'prefer'; }` to `if ($strictest !== null) { … }` | ConfigurePgDumpTest 'leaves PGSSLMODE alone for a SQLite source …' (both rows) |
| 13 | L7b-67 | `ConfigurePgDump::__invoke()`: make `$_SERVER['PGSSLMODE'] = 'prefer';` its first statement | ConfigurePgDumpTest 'leaves PGSSLMODE to backup:run …' (both rows) |
| 14 | L7b-74 | `scripts/e2e-local.sh`: rename the arm `        clone)` to `        clone-repo)` (keep the file executable) | ComposerScriptsTest 'repeats the commands of composer setup in the clone route …' (the marker is missing); the pre-task test stays green |
| 15 | L-46 | `CreateOrganisationCommand::handle()`: as the first statement inside `if (! $actor instanceof User) {`, add `DB::table('users')->where('email', $email)->update(['is_super_admin' => true]);` (import `Illuminate\Support\Facades\DB`) | CreateOrganisationCommandTest 'acts as the super admin --as names, and refuses another address without promoting it' ("Failed asserting that true is false."); the pre-task test stays green |

- [ ] **Step 14: The gate, then commit**

Run the Global Constraints gate:
- `composer check`
- `composer test:pgsql` (nothing here is driver-specific; `HealthChecksTest`'s F2 test points the default connection at an unreachable SQLite file on both runs)
- the modules-off run (`PasskeyTest` turns the module on itself; `SetupAccessTest` and `SetupModeTest` do not depend on a module)
- `npm run build && composer test:browser`
- `npm run build:setup && git diff --exit-code resources/setup/setup.css` (no setup view changes, so expect no diff)
- `php artisan config:clear`

```bash
git add app/Setup/Wizard/WizardRunner.php tests/Feature/Setup/Wizard/WizardRunnerTest.php CHANGELOG.md
git commit -m "fix: keep the herd stage's own error and refuse a garbled step pid

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Then, in `CHANGELOG.md` under `## [Unreleased]` › `### Security` (Task 3's heading, after `### Removed`), append at the end of its list:

```markdown
- The setup wizard, the Herd stage and the quick login also treat a request with an `X-Client-IP`, `Client-IP`, `X-Cluster-Client-IP`, `Fastly-Client-IP`, `X-Host`, `X-Original-URL`, `X-Forwarded`, `Forwarded-For` or `Tailscale-*` header as not coming from this machine.
```

```bash
git add app/Setup/Http/DirectRequest.php tests/Feature/Setup/Wizard/SetupAccessTest.php tests/Feature/Setup/SetupModeTest.php CHANGELOG.md
git commit -m "fix: treat more proxy and tunnel headers as not direct

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"

git add tests/Feature/Setup/Wizard/HerdStageTest.php tests/Feature/Health/HealthChecksTest.php \
    tests/Feature/Health/HealthCheckEndpointTest.php tests/Feature/System/SystemPageTest.php \
    tests/Feature/Auth/PasskeyTest.php tests/Feature/Backups/ConfigurePgDumpTest.php \
    tests/Feature/Tooling/ComposerScriptsTest.php tests/Feature/Console/CreateOrganisationCommandTest.php
git commit -m "test: pin the referrer policy on refused setup requests and strengthen weak setup and ops tests

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

---

### Task 10: Auth and tenancy protections without a failing test (§4.2, §4.4, §4.7, §6.2, §12 (5); DEC-27, DEC-28, DEC-29; A20, A21, A22, A23, A24, A42, A45, D13, D16)

Tests only. DEC-28 and DEC-29 left nine protections that work today but that no test would miss: each new test below passes on `5a621c3` and fails under the named mutation of the production code (Step 7). The protections:
- **A20 (FU3-16):** the two-factor and passkey limiters count failed attempts only (`->after(failedAttempt)` in `FortifyServiceProvider`). Only the password limiter's success case was pinned. Six successful two-factor sign-ins of one user in a minute (with recovery codes; a TOTP code cannot be replayed in its window), eleven successful passkey sign-ins from one IP and eleven successful passkey confirmations of one user are never throttled.
- **A21:** a passkey of an acme member is refused on beta's host even with beta's own origin, so only the host rule (`Passkeys::authorizeLoginUsing()` → `HostLoginRule`) can refuse it. The existing test covers the apex only, and the foreign-origin test fails on the origin.
- **A22:** the two-factor challenge re-applies the host rule (`UnscopedTwoFactorLoginRequest`), not only `isActive()`: a super admin of acme who starts the challenge on the admin host and is demoted before the code is refused, while still active.
- **A23:** `JobTenancy::end()` skips `JobExceptionOccurred` only for a `SyncJob`. A worker job that throws and is released for a retry gets no `JobFailed`, so without that condition its tenant would stay bound in the worker.
- **A24:** `AuthenticateSession` runs on Livewire updates too (DEC-27 (6)), not only on page GETs: a password changed elsewhere signs the session out on its next update (401).
- **A42 (DEC-28 (20)):** a member with confirmed two-factor authentication may be impersonated: a policy dataset row, and an HTTP start from the users index that lands on the dashboard without a challenge.
- **A45 (FU2-5):** `UpdateUserStatus` and `DeleteUser` decide the super-admin lock-out on the locked row (`LockoutGuard::ensureAnotherActiveAdmin()` reads `$row['is_super_admin']`): for a super-admin actor whose target was promoted to the only remaining super admin after the models were read, both are refused.
- **D13 (M7c T2 #1):** the trusted-proxy case of `PasswordResetHostTest` now proves the request saw the forwarded port, so "APP_URL beats a trusted proxy" is really exercised.
- **D16 (M7c T2 #4):** the same file resets Symfony's static trusted proxies after each test, which the proxy case left at `127.0.0.1` for every later request built outside the middleware.

Re-verification on `5a621c3`: all nine are open as triaged. Every mutation in Step 7 was run in a prototype: each fails the new test and leaves the file's other tests green (mutation 6 aside, see there). D16's leak was confirmed: without the reset, a request built in a later test of the file still trusts `127.0.0.1`.

**Files:**
- Test:
  - `tests/Feature/Auth/LoginThrottleTest.php` (A20, two-factor)
  - `tests/Feature/Auth/PasskeyTest.php` (A20 passkeys, A21; adds to Task 9's changes)
  - `tests/Feature/Auth/HostScopedLoginTest.php` (A22)
  - `tests/Feature/Tenancy/QueueTenancyTest.php` (A23)
  - `tests/Feature/Auth/AuthenticateSessionTest.php` (A24)
  - `tests/Feature/Impersonation/ImpersonationPolicyTest.php`, `tests/Feature/Impersonation/ImpersonationUiTest.php` (A42)
  - `tests/Feature/Users/UserLifecycleTest.php` (A45)
  - `tests/Feature/Auth/PasswordResetHostTest.php` (D13, D16)
- No production file changes; no CHANGELOG line (test-only, as Task 1), no README section, no lang key.

**Interfaces:**
- Consumes:
  - `onTenant()`, `onApex()`, `onAdminHost()`, `asSuperAdmin()`, `pageSnapshot()`, `callPageAction()`, `recordedActivity()`, `usePasskeysModule()`, `useImpersonationModule()`, `withPermissionsTeam()` (`tests/Pest.php`, unchanged)
  - `PasskeyTest`'s file-level functions `registerPasskeyFor(User, SoftwareAuthenticator, string $origin): void`, `signInWithPasskey(SoftwareAuthenticator, string $origin): TestResponse` and Task 9's `passkeyHost(string $host, Organisation): string` (now with an explicit `'admin'` arm), and its `beforeEach(usePasskeysModule(true))`
  - `QueueTenancyTest`'s `workOneJob()` (`queue:work --once --stop-when-empty --tries=3`) and `beforeEach` (`queue.default = database`); `Tests\Fixtures\FailingCountUsersJob` (throws "boom"; its `failed()` hook writes `failed-hook.users`)
  - `ImpersonationUiTest`'s `$organisationAdmin` and `$confirmedSnapshot` closures (the latter is defined after the 'starts from the users index …' test, so the new test goes after it); `ImpersonationPolicyTest`'s dataset `match`
  - `UserLifecycleTest`'s `$errorsOf` closure
  - `UserFactory::withTwoFactor()` (recovery codes `['recovery-code-1']`, overridable), `UserFactory::superAdmin()`
  - `App\Authorization\SetSuperAdmin::handle(User, bool)` (tests may call it; `AuthorizationRulesTest` restricts `app/` callers only)
- Produces: nothing (no helper, no fixture).

**Notes for the implementer:**
- **Why recovery codes (A20).** Fortify refuses a TOTP code it has already accepted in the same window, so six sign-ins in one frozen minute need six recovery codes. Each one is consumed and replaced by Fortify; the factory's `two_factor_recovery_codes` is overridden with six known codes.
- **Request budgets (A20).** Each passkey ceremony is two requests (options + verify) through `throttle:passkeys`, and the registration two more under the user's bucket: eleven sign-ins are 22 requests from one IP, past the failure limit's ten a minute and under the 30-request backstop (which counts every request and is the same per IP for the registration). Sign-ins and confirmations are separate tests so the backstop never decides.
- **The super admin of A22 has an organisation.** `SetSuperAdmin` refuses to demote a super admin without one (W8), and the demotion must leave the user active and existing, or `isActive()` alone would refuse too. A second active super admin keeps the demotion from being the last.
- **Stale models (A45).** The actor's and the target's models are loaded before `DB::table()` promotes the target and revokes the actor's flag, as a concurrent request would. The policy and `ensureStillManageable()` read the actor's model (still a super admin), so only the locked-row guard refuses.
- **D16's reset uses `TrustProxies`' own header set**, spelled with `Request::HEADER_*` constants: `Request::getTrustedHeaderSet()` returns `int`, which Larastan refuses for `setTrustedProxies()`'s `int<0, 63>`.
- **Livewire's 401 (A24).** `AuthenticateSession` throws `AuthenticationException` after logging out; a JSON request (Livewire's update) renders it as 401.
- **No `git stash`.** For mutation checks copy the file aside with `cp`, change it, run the test, copy it back.

- [ ] **Step 1: The two-factor and passkey limit tests (A20, A21)**

`tests/Feature/Auth/LoginThrottleTest.php`: insert directly before `it('clears an address\'s failures once it signs in (G1-03)', …)`:

```php
it('does not count successful two-factor sign-ins (FU3-16)', function (): void {
    // Six recovery codes, one per sign-in: a TOTP code cannot be used twice in its window, a recovery code only once.
    $codes = array_map(fn (int $number): string => "recovery-code-{$number}", range(1, 6));
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->withTwoFactor()->create([
        'two_factor_recovery_codes' => encrypt(json_encode($codes, JSON_THROW_ON_ERROR)),
    ]);
    onTenant($organisation);

    // One more than the two-factor limit's five a minute, all in the frozen minute.
    foreach ($codes as $code) {
        $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
        $this->post(route('two-factor.login.store'), ['recovery_code' => $code])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($member);
        $this->post(route('logout'));
    }
});
```

(The file's `beforeEach` already fakes `Sleep` and freezes time.)

`tests/Feature/Auth/PasskeyTest.php`: insert directly before `it('signs a super admin in with a passkey on central and tenant hosts (P2)', …)`:

```php
it('refuses a passkey on the host of another organisation, with that host\'s own origin (§4.2, P2)', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $beta = Organisation::factory()->create(['slug' => 'beta']);
    $member = User::factory()->for($acme)->create();
    $authenticator = new SoftwareAuthenticator;
    registerPasskeyFor($member, $authenticator, passkeyHost('tenant', $acme));

    // beta's origin is the one its host allows, so only the host rule can refuse.
    signInWithPasskey($authenticator, passkeyHost('tenant', $beta))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('credential');
    $this->assertGuest();

    signInWithPasskey($authenticator, passkeyHost('tenant', $acme))->assertOk();
    $this->assertAuthenticatedAs($member);
});

it('does not count successful passkey sign-ins against the passkeys limit (FU3-16)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $authenticator = new SoftwareAuthenticator;
    registerPasskeyFor($member, $authenticator, passkeyHost('tenant', $organisation));
    $this->freezeTime();

    // Eleven sign-ins, 22 requests from one IP: past the failure limit's ten a minute, under the backstop's 30.
    foreach (range(1, 11) as $signIn) {
        signInWithPasskey($authenticator, 'http://acme.localhost')->assertOk();
        $this->assertAuthenticatedAs($member);
        Auth::guard('web')->logout();
    }
});

it('does not count successful passkey confirmations against the passkeys limit (FU3-16)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $authenticator = new SoftwareAuthenticator;
    registerPasskeyFor($member, $authenticator, passkeyHost('tenant', $organisation));
    $this->freezeTime();
    actingAs($member);

    // Eleven confirmations, 22 requests by one user: past the failure limit's ten a minute, under the backstop's 30.
    foreach (range(1, 11) as $confirmation) {
        /** @var array<string, mixed> $options */
        $options = getJson(route('passkey.confirm-options'))->assertOk()->json('options');

        postJson(route('passkey.confirm'), ['credential' => $authenticator->assert($options, 'http://acme.localhost')])->assertSuccessful();
    }
});
```

Every import is already in the file (`Auth`, `SoftwareAuthenticator`, `actingAs`, `getJson`, `postJson`).

- [ ] **Step 2: The host-rule, queue and session tests (A22, A23, A24)**

`tests/Feature/Auth/HostScopedLoginTest.php`: add `use App\Authorization\SetSuperAdmin;` as the first import (before `use App\Models\Organisation;`), and insert directly before `it('completes the two-factor challenge on the member own host', …)`:

```php
it('refuses a two-factor challenge for a super admin demoted before the code, on the admin host (§4.2, DEC-28)', function (): void {
    // A promoted member of acme: the demotion keeps them active and in acme, so only the host rule refuses the code.
    $organisation = Organisation::factory()->create();
    User::factory()->superAdmin()->create();
    $superAdmin = User::factory()->for($organisation)->withTwoFactor()->create();
    app(SetSuperAdmin::class)->handle($superAdmin, true);
    onAdminHost();
    $this->post(route('login.store'), ['email' => $superAdmin->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));

    app(SetSuperAdmin::class)->handle($superAdmin, false);

    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
    expect($superAdmin->fresh()?->isActive())->toBeTrue();
});
```

`tests/Feature/Tenancy/QueueTenancyTest.php`: insert directly before `it('fails the job without retry when the organisation is gone', …)`:

```php
it('restores the worker\'s context after a tenant job that throws and is released for a retry (§6.2)', function (): void {
    // A worker job that throws raises JobExceptionOccurred and is released (tries 3): no JobFailed follows, so the
    // context must be restored on that event, the SyncJob exception aside.
    $organisation = Organisation::factory()->create();
    Tenant::run($organisation, function (): void {
        Bus::dispatch(new FailingCountUsersJob);
    });

    workOneJob();

    expect(Tenant::check())->toBeFalse()
        ->and(getPermissionsTeamId())->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(Cache::has('failed-hook.users'))->toBeFalse();
});
```

(`FailingCountUsersJob`, `Bus`, `Cache`, `DB` and `Tenant` are already imported; `getPermissionsTeamId()` is spatie's global helper.)

`tests/Feature/Auth/AuthenticateSessionTest.php`: insert directly before `it('keeps the current device signed in after logging out other devices', …)`:

```php
it('signs a session out on its next Livewire update once the password changed elsewhere (DEC-27 (6))', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password']);
    $snapshot = pageSnapshot(route('profile.edit'), 'pages::settings.profile');

    DB::table('users')->where('id', $member->id)->update(['password' => Hash::make('changed-elsewhere')]);
    Auth::guard('web')->forgetUser();

    callPageAction($snapshot, '$refresh')->assertUnauthorized();
    $this->assertGuest();
});
```

- [ ] **Step 3: The impersonation and lock-out tests (A42, A45)**

`tests/Feature/Impersonation/ImpersonationPolicyTest.php`, in 'decides who may impersonate whom on a tenant host …':
- extend the title's references: `'decides who may impersonate whom on a tenant host (IMP-4, IMP-5, IMP-13, W3, W6, X6, RK-I6, DEC-28 (20))'`
- add an arm after `'organisation admin → member' => [$organisationAdmin($organisation), $member($organisation)],`:

```php
        'organisation admin → member with two-factor authentication' => [$organisationAdmin($organisation), User::factory()->for($organisation)->withTwoFactor()->create()],
```

- add a dataset row after `['organisation admin → member', true],`:

```php
    // DEC-28 (20): a second factor never keeps an administrator from impersonating its holder.
    ['organisation admin → member with two-factor authentication', true],
```

`tests/Feature/Impersonation/ImpersonationUiTest.php`: insert directly before `it('shows the sticky banner with a stop button while impersonating', …)` (after the two FU4-3 tests, where `$confirmedSnapshot` is defined):

```php
it('starts impersonating a member with two-factor authentication and lands on the dashboard without a challenge (DEC-28 (20))', function () use ($organisationAdmin, $confirmedSnapshot): void {
    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $member = User::factory()->for($organisation)->withTwoFactor()->create();
    onTenant($organisation);
    $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])->assertRedirect(route('dashboard'));
    Auth::guard('web')->forgetUser();
    $afterConfirm = $confirmedSnapshot($member);

    Auth::guard('web')->forgetUser();
    callPageAction($afterConfirm, 'startImpersonation', [], ['impersonationReason' => 'Ticket 42'])
        ->assertOk()
        ->assertJsonPath('components.0.effects.redirect', route('dashboard'));

    Auth::guard('web')->forgetUser();
    $this->get(route('dashboard'))->assertOk();
    $this->assertAuthenticatedAs($member);
    expect(Impersonation::isActive())->toBeTrue()
        ->and(session()->has('login.id'))->toBeFalse();
});
```

`tests/Feature/Users/UserLifecycleTest.php`: insert directly before `it('refuses to disable or delete yourself (W2, W12)', …)`:

```php
it('decides on the locked row that a stale target is now the only active super admin, for a super admin actor (FU2-5)', function (string $action) use ($errorsOf): void {
    // Both models were read before another request promoted the target and revoked the actor's flag: the models still
    // say "member" and "super admin", the rows say the target is the only super admin left.
    $organisation = Organisation::factory()->create();
    $target = User::factory()->for($organisation)->create();
    $superAdmin = asSuperAdmin();
    DB::table('users')->where('id', $target->id)->update(['is_super_admin' => true]);
    DB::table('users')->where('id', $superAdmin->id)->update(['is_super_admin' => false]);
    $before = (array) DB::table('users')->where('id', $target->id)->first();

    $errors = $errorsOf(fn () => match ($action) {
        'disable' => app(UpdateUserStatus::class)->handle($superAdmin, $target, UserStatus::Disabled),
        'delete' => app(DeleteUser::class)->handle($superAdmin, $target),
        default => throw new LogicException("Unknown action [{$action}]."),
    });

    expect($errors)->toBe([$action === 'disable' ? 'status' : 'user' => [__('At least one active super admin must remain.')]])
        ->and((array) DB::table('users')->where('id', $target->id)->first())->toBe($before)
        ->and(recordedActivity(ActivityEvent::UserStatusChanged))->toBeEmpty()
        ->and(recordedActivity(ActivityEvent::UserDeleted))->toBeEmpty();
})->with(['disable', 'delete']);
```

- [ ] **Step 4: The trusted-proxy case (D13, D16)**

`tests/Feature/Auth/PasswordResetHostTest.php`: add `use Illuminate\Http\Request;` after `use Illuminate\Foundation\Http\Events\RequestHandled;`, and insert before the first `it(…)`:

```php
// M7c T2 #4: TrustProxies sets Symfony's trusted proxies statically, so the proxy case would leave 127.0.0.1 trusted
// for every later request built outside the middleware. Trust none again, with TrustProxies' own header set.
afterEach(function (): void {
    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PREFIX | Request::HEADER_X_FORWARDED_AWS_ELB);
});
```

In 'builds the reset link with APP_URL\'s scheme and port, whatever the request says (CD-4, G1-04)', replace the `else` branch:

```php
    } else {
        config(['trustedproxy.proxies' => '127.0.0.1']);
        $this->post(route('password.email'), ['email' => $member->email], ['X-Forwarded-Proto' => 'http', 'X-Forwarded-Port' => '8080'])
            ->assertSessionHasNoErrors();
    }
```

with:

```php
    } else {
        // M7c T2 #1: the request must really see the proxy's scheme and port, or APP_URL beats nothing.
        $seen = null;
        Event::listen(RequestHandled::class, function (RequestHandled $event) use (&$seen): void {
            $seen = ['port' => $event->request->getPort(), 'secure' => $event->request->isSecure()];
        });
        config(['trustedproxy.proxies' => '127.0.0.1']);
        $this->post(route('password.email'), ['email' => $member->email], ['X-Forwarded-Proto' => 'http', 'X-Forwarded-Port' => '8080'])
            ->assertSessionHasNoErrors();

        expect($seen)->toBe(['port' => 8080, 'secure' => false]);
    }
```

(`Event` and `RequestHandled` are already imported; the listener is the test's own, registered with no `Event::fake()`.)

- [ ] **Step 5: Run the new tests**

Run: `php artisan test --compact tests/Feature/Auth/LoginThrottleTest.php tests/Feature/Auth/PasskeyTest.php tests/Feature/Auth/HostScopedLoginTest.php tests/Feature/Tenancy/QueueTenancyTest.php tests/Feature/Auth/AuthenticateSessionTest.php tests/Feature/Impersonation tests/Feature/Users/UserLifecycleTest.php tests/Feature/Auth/PasswordResetHostTest.php`

Expected: PASS. Every new test pins a protection that works today; Step 7 proves that each fails without it.

- [ ] **Step 6: Run them with the modules off**

Run: `php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false php artisan test --compact tests/Feature/Auth tests/Feature/Impersonation`

Expected: PASS (`PasskeyTest` turns the passkeys module on in its `beforeEach`, the impersonation tests in theirs; `LoginThrottleTest`'s two-factor test needs neither).

- [ ] **Step 7: Verify by mutation**

For each mutation: copy the file aside with `cp`, change it, run the named test file with `php artisan test --compact <file>`, confirm the named tests fail, copy the original back and confirm the file passes (never `git stash`). Each mutation leaves the named file's other tests green (mutation 6 notes its one exception), so the new test is what catches it. Name each mutation and its result in the task report.

| # | Item | Mutation | Must fail |
|---|---|---|---|
| 1 | A20 | `FortifyServiceProvider::configureRateLimiting()`: drop `->after(self::failedAttempt($request))` from `Limit::perMinute(5)->by('two-factor:'.$challenge)` | LoginThrottleTest 'does not count successful two-factor sign-ins (FU3-16)' (429 on the sixth code) |
| 2 | A20 | `FortifyServiceProvider::passkeysLimiter()`: drop `->after(self::failedAttempt($request))` from the per-user/per-IP limit | PasskeyTest 'does not count successful passkey sign-ins …' and '… confirmations …' (429) |
| 3 | A21 | `TenancyServiceProvider::boot()`: replace `&& app(HostLoginRule::class)->allows($user)` in `Passkeys::authorizeLoginUsing()` with `&& ! $user->trashed() && $user->isActive() && ($user->belongsToCurrentHost() \|\| \App\Tenancy\Facades\Tenant::check())` (any organisation host) | PasskeyTest 'refuses a passkey on the host of another organisation …' (200 instead of 422); the apex test stays green |
| 4 | A22 | `UnscopedTwoFactorLoginRequest::allowedChallengedUser()`: replace `app(HostLoginRule::class)->allows($user)` with `! $user->trashed() && $user->isActive()` | HostScopedLoginTest 'refuses a two-factor challenge for a super admin demoted before the code …' (redirect to the dashboard) |
| 5 | A23 | `JobTenancy::end()`: drop `$event->job instanceof SyncJob && ` from the first `if` | QueueTenancyTest 'restores the worker\'s context after a tenant job that throws …' (`Tenant::check()` is true) |
| 6 | A24 | `bootstrap/app.php`: remove `AuthenticateSession::class` from `$middleware->web(append: …)`, and in `routes/web.php` add it to the dashboard group (`Route::middleware(['auth', \Illuminate\Session\Middleware\AuthenticateSession::class, EnsureSecondFactor::class])`); restore both files | AuthenticateSessionTest 'signs a session out on its next Livewire update …' (200 instead of 401, because Livewire's persistent middleware does not re-run it); 'signs a session out once the password changed elsewhere' (the dashboard GET) stays green; 'keeps the current device signed in after logging out other devices' also fails, on its own route without the middleware |
| 7 | A42 | `UserAbilities::mayImpersonate()`: after the `if ($actor->isSuperAdmin()) { return true; }` block, add `if ($target->two_factor_confirmed_at !== null) { return false; }` | ImpersonationPolicyTest row 'organisation admin → member with two-factor authentication' and ImpersonationUiTest 'starts impersonating a member with two-factor authentication …' (403) |
| 8 | A45 | `LockoutGuard::ensureAnotherActiveAdmin()`: replace `if ($row['is_super_admin']) {` with `if ($target->is_super_admin) {` | UserLifecycleTest 'decides on the locked row that a stale target is now the only active super admin …' (both rows: no error, the target is disabled or deleted) |
| 9 | D13 | test-side: delete `config(['trustedproxy.proxies' => '127.0.0.1']);` from the proxy case | PasswordResetHostTest 'builds the reset link with APP_URL\'s scheme and port …' row 'forwarded headers from a trusted proxy' (`'port' => 80`); before this task the row stayed green without it |
| 10 | D16 | test-side: delete the `afterEach` and add, after the CD-4 test, `it('probe', function (): void { expect(Request::getTrustedProxies())->toBe([]); });`; run `--filter='CD-4\|probe'` | 'probe' fails (`['127.0.0.1']`); with the `afterEach` back it passes; remove the probe |

- [ ] **Step 8: The gate, then commit**

Run the Global Constraints gate:
- `composer check`
- `composer test:pgsql` (the database queue runs inside `RefreshDatabase`'s transaction on the same connection, as QueueTenancyTest's other worker tests do; `SetSuperAdmin` and `LockoutGuard` take their `FOR UPDATE` locks there)
- the modules-off run
- `npm run build && composer test:browser`
- `npm run build:setup && git diff --exit-code resources/setup/setup.css` (no setup changes, so expect no diff)
- `php artisan config:clear`

```bash
git add tests/Feature/Auth/LoginThrottleTest.php tests/Feature/Auth/PasskeyTest.php tests/Feature/Auth/HostScopedLoginTest.php \
    tests/Feature/Tenancy/QueueTenancyTest.php tests/Feature/Auth/AuthenticateSessionTest.php \
    tests/Feature/Impersonation/ImpersonationPolicyTest.php tests/Feature/Impersonation/ImpersonationUiTest.php \
    tests/Feature/Users/UserLifecycleTest.php tests/Feature/Auth/PasswordResetHostTest.php
git commit -m "test: pin the sign-in limits, host rules, job context and lock-out guard that no test guarded

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

---

### Task 11: UI and feature weak tests, `Role::color()` and the LoginTrend fix (§4.3, §4.8, §4.10, §4.11, §6.5, §6.6, §6.7; CD-13; A43, A71, A72, A73, A74, A76, A84o, A84p, A84r (window half), D34, D35, D36, D50, E44, A62=C137=D88, C138=D89)

Most of this task is tests. Each item below is a protection the code already has but no test pins: deleting or breaking it today leaves the suite green. Every new or strengthened test passes on `main` and fails under a named mutation of the code it guards (the mutation table in Step 11). Two items change code:
- **`Role::color()` (CD-13, G5-04).** §6.5 asks for `color()` on the enums shown as coloured badges. Only `UserStatus` and `CheckStatus` have one; the super admin badge hard-codes `color="violet"` at two sites. `Role::color()` (super admin violet, organisation admin zinc) replaces both literals. Nothing a user sees changes. `Permission` and `ActivityEvent` are rendered as plain badges or text, never coloured, so they get no `color()`; Task 17 narrows §6.5 and records it in DEC-36.
- **LoginTrend (G5-08).** `LoginTrend::lastDays()` steps through its days with `$start->addDays($offset)`. That only works because `AppServiceProvider` makes every date immutable (`Date::use(CarbonImmutable::class)`). An application that switches to mutable Carbon gets the days d, d+1, d+3, d+6, … The start becomes `now()->toImmutable()`. The window test also gets a row on each edge (A84r's window half).

Re-verified on `main` (5a621c3): every item is still open as the triage describes, with these corrections:
- **E44.** The suggested mutation ("reordering throttle after `can:`") cannot be made. Laravel's middleware priority list puts `ThrottleRequests` before `Authorize` whatever order the route names them in, and `prependToPriorityList()` ignores a class that is already in the list (prototyped: the reordered priority stays green). The mutation used is a limiter that counts only users who may export.
- **D50.** The lint and the stripper share `FluxProBranches::branches()`. No mutation of `app/` makes the new lint assertion fail while the strip assertions stay green. The mutation is in the lint itself (`tests/Support/FluxProLint.php`): it reads the branches of a text whose `@@` escapes were undone, which is the kind of lint-only misreading G5-09 fixed.
- **A43.** The old host answers 404 because of two independent guards: `OrganisationObserver::forgetSlugs()` forgets the old slug's cache key, and `SubdomainResolver::resolve()` checks the cached organisation's slug again. Either one alone keeps the old host at 404 (prototyped), so the mutation removes both.

**Files:**
- Create:
  - `tests/Fixtures/ForbiddingPreventRequestForgery.php` (A76: answers 403 to one probe path where the CSRF middleware sits, ahead of `EnforceImpersonation`)
  - `tests/Unit/RoleTest.php` (`Role::color()`)
  - `tests/Feature/Users/RoleBadgeTest.php` (both super-admin badge sites take their colour from `Role::color()`)
- Modify:
  - `app/Authorization/Role.php` (`color()`)
  - `resources/views/pages/users/index/index.blade.php` (`:110`, the super admin badge uses `Role::SuperAdmin->color()`)
  - `resources/views/pages/users/edit/edit.blade.php` (`:101`, the same)
  - `app/Support/Dashboard/LoginTrend.php` (`now()->toImmutable()`)
- Test:
  - `tests/Feature/Organisations/OrganisationEditTest.php` (A43: one test appended at the end of the file, after Task 6's changes)
  - `tests/Feature/Localisation/SetLocaleTest.php` (A71)
  - `tests/Feature/DashboardTest.php` (A72: the recent-activity test strengthened; A84p: one new test, and 'counts organisations…' rewritten to check the value)
  - `tests/Feature/Dashboard/OnboardingChecklistTest.php` (A73)
  - `tests/Feature/Settings/ProfileUpdateTest.php` (A74; docblock `§4.10, §4.11`)
  - `tests/Feature/Impersonation/ImpersonationEnforcementTest.php` (A76)
  - `tests/Feature/ErrorPagesTest.php` (D34, D35, D36, A84o)
  - `tests/Feature/FluxPro/ProBranchCompilationTest.php` (D50)
  - `tests/Feature/Audit/ActivityExportTest.php` (E44: the 403 test strengthened and retitled)
  - `tests/Feature/Dashboard/LoginTrendTest.php` (C138, A84r window half)

No lang keys, no CHANGELOG line (nothing a user of the kit sees changes; see Notes for Task 17), no README section.

**Interfaces:**
- Consumes:
  - test helpers: `asSuperAdmin()`, `asOrgAdmin()`, `asMember()`, `onTenant()`, `onAdminHost()`, `onApex()`, `startImpersonatingOverHttp()`, `recordedActivity()`, `useImpersonationModule()` (via the file's `beforeEach`)
  - `App\Actions\Organisations\CreateOrganisation::handle(User $actor, string $name, string $slug, ?string $description, string $adminEmail, Locale $adminLocale): Organisation`
  - `App\Actions\Organisations\ArchiveOrganisation::handle(User $actor, Organisation $organisation)`
  - `App\Support\RateLimits\ThrottleKey::of(string $limiter, string $key): string`
  - `Tests\Support\FluxProLint::violations(string $blade): list<string>`
  - Task 6's `OrganisationEditTest.php` and Task 3's `profile.php` as they stand after those tasks. The A43 test is appended at the end of the file. The A74 test sends no new address, so it costs no address lookup (CD-2).
- Produces:
  - `App\Authorization\Role::color(): string`: `'violet'` for `SuperAdmin`, `'zinc'` for `OrganisationAdmin`.
  - `LoginTrend::lastDays(int $days = 14)` gives `$days` consecutive dates whatever `Date::use()` chose.
  - `Tests\Fixtures\ForbiddingPreventRequestForgery` (final class, extends `Illuminate\Foundation\Http\Middleware\PreventRequestForgery`), `public const string PATH = '_forbidden-probe'`: it answers 403 to `PATH` and handles every other request as its parent does.

Background for the implementer:
- **Weak-test items pass on `main`.** Their steps show the test, the run (PASS), then the mutation that must turn it red. Copy the file aside with `cp`, mutate, run, copy it back; `php artisan view:clear` after a Blade or Livewire component file. Never `git stash`.
- **A43 and the slug cache.** The first request to `acme.localhost` puts the organisation's id in the slug cache, so the test covers the cached path as well as the database one. After the HTTP request to `acme-new.localhost`, that organisation stays bound as the tenant in the test process. `onAdminHost()` forgets it before `CreateOrganisation`; without it, `HostScope` refuses the nested invite with "This action is unauthorized." (prototyped).
- **A76 and the fixture.** In the web group, `PreventRequestForgery` runs after `StartSession` and before `EnforceImpersonation`, just as in the existing 419 test. Binding the fixture in its place makes the 403 page render before the impersonation is re-checked. `errors/403.blade.php` must then read only a user the guard already resolved (`Locales::resolvedUser()`, fix 302c7d0). With `auth()->user()` the guard would resolve the gone target, fall back to the admin's remember cookie, and sign the admin in a second time. The fixture aborts only on its own path, so the test's later `route('dashboard')` request runs the real middleware.
- **D36's mutation** is the hand-rolled "same host" check the triage names. It passes the existing same-host test ('never points "Try again" at a POST-only address') and fails only the new foreign-Referer rows (prototyped).
- **The badge test** matches Flux's violet badge class (`bg-violet-400/20`, `vendor/livewire/flux/stubs/resources/views/flux/badge/index.blade.php:81`), never `Role::color()` itself. A changed colour then fails the HTML test as well as the unit test. The test passes on `main` too (the literal is violet): it guards the refactor, and the `amber` mutation proves the views read `color()`.
- **LoginTrend under mutable dates.** `Date::use(Carbon::class)` is a global static. The test restores `CarbonImmutable` in a `finally`. The next test's application boot sets it again anyway (`AppServiceProvider.php:120`).

- [ ] **Step 1: A43 — the old slug is free at once (`OrganisationEditTest.php`)**

Add two imports to the `use` block (keep it sorted):

```php
use App\Actions\Organisations\CreateOrganisation;
use App\Enums\Locale;
```

Append at the end of the file:

```php

it('frees the old slug at once: the old host answers 404 without a redirect and a new organisation may take it (DEC-28 (33))', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $superAdmin = asSuperAdmin();
    onAdminHost();

    // Resolves acme.localhost once, so the slug cache holds the organisation's id before the rename.
    $this->get('http://acme.localhost/dashboard')->assertOk();

    Livewire::test('pages::organisations.edit', ['organisation' => $acme->id])
        ->set('slug', 'acme-new')
        ->call('save')
        ->assertHasNoErrors();

    $this->get('http://acme.localhost/dashboard')->assertNotFound();
    $this->get('http://acme-new.localhost/dashboard')->assertOk();

    // The request bound acme-new as the tenant; the next organisation is created on the admin host again.
    onAdminHost();
    $again = app(CreateOrganisation::class)->handle($superAdmin, 'Acme Again', 'acme', null, 'second-admin@example.com', Locale::Dutch);

    expect($again->slug)->toBe('acme')
        ->and($again->id)->not->toBe($acme->id);
    $this->get('http://acme.localhost/dashboard')->assertOk();
});
```

Run: `php artisan test --compact tests/Feature/Organisations/OrganisationEditTest.php --filter='frees the old slug'`
Expected: PASS. Mutations 1 and 2 of Step 11 turn it red.

- [ ] **Step 2: A71 — the user's language before the session's (`SetLocaleTest.php`)**

Insert after the test 'uses the signed-in user\'s language' (before 'gives guests the language their browser prefers…'):

```php
it('puts the signed-in user\'s language before a language the session holds from a guest choice (RL-8)', function (): void {
    config(['skv.locale.default' => 'en']);
    $this->actingAs(User::factory()->for($this->organisation)->create(['locale' => Locale::Dutch]));

    $this->withSession(['locale' => 'en'])
        ->get(route('dashboard'), ['Accept-Language' => 'en-GB,en;q=0.9'])
        ->assertOk()
        ->assertSee('<html lang="nl"', false);
});

```

The session, the browser and the default all say `en`, so only the user's own `nl` can give `lang="nl"`.

Run: `php artisan test --compact tests/Feature/Localisation/SetLocaleTest.php`
Expected: PASS. Mutation 3 turns only this test red.

- [ ] **Step 3: A72 and A84p — the dashboard counts and lists this host only (`DashboardTest.php`)**

Add `use App\Models\Invitation;` to the imports (before `use App\Models\Organisation;`).

In 'lists the last ten entries of this host\'s activity for users who may read the log (§4.8)', replace the lines from the `foreach` to the end of the first `expect(...)` chain with:

```php
    foreach (range(1, 12) as $i) {
        app(RecordActivity::class)->handle(ActivityEvent::PasswordChanged, $admin);
    }
    // The newest entry of all, so it would head the list if the host's scope were lost (A72).
    $stranger = User::factory()->for($other)->create(['name' => 'Olga Outsider']);
    Tenant::run($other, fn () => app(RecordActivity::class)->handle(ActivityEvent::RecoveryCodesRegenerated, $stranger));

    $html = (string) $this->get(route('dashboard'))->assertOk()->getContent();

    expect(substr_count($html, 'data-test="recent-activity-row"'))->toBe(10)
        // e(): Blade escapes the name, and nl_NL faker names may hold an apostrophe ("van 't Riet").
        ->and($html)->toContain(e($admin->name))
        ->and($html)->toContain(e(ActivityEvent::PasswordChanged->label()))
        ->and($html)->not->toContain('Olga Outsider')
        ->and($html)->not->toContain(e(ActivityEvent::RecoveryCodesRegenerated->label()));
```

(The member half, `asMember($acme)` … `assertDontSee('data-test="recent-activity"'…)`, stays as it is.)

Replace the whole test 'counts organisations for super admins on central hosts' with these two tests. The second one keeps the old test's title and adds what the title promised (the count):

```php
it('counts this host\'s pending invitations, without accepted ones or another organisation\'s (RL-11)', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $other = Organisation::factory()->create(['slug' => 'other']);
    Tenant::run($other, fn () => Invitation::factory()->for($other)->count(3)->create());
    asOrgAdmin($acme);
    Invitation::factory()->for($acme)->count(2)->create();
    Invitation::factory()->for($acme)->accepted()->create();

    $html = (string) $this->get(route('dashboard'))->assertOk()->getContent();

    expect(preg_match('/data-test="stat-pending-invitations".*?>\s*(\d+)\s*</s', $html, $match))->toBe(1)
        ->and((int) ($match[1] ?? -1))->toBe(2);
});

it('counts organisations for super admins on central hosts, archived ones left out', function (): void {
    Organisation::factory()->count(2)->create();
    $archived = Organisation::factory()->create();
    $superAdmin = asSuperAdmin();
    onAdminHost();
    app(ArchiveOrganisation::class)->handle($superAdmin, $archived);

    $html = (string) $this->get(route('dashboard'))->assertOk()->getContent();

    expect(preg_match('/data-test="stat-organisations".*?>\s*(\d+)\s*</s', $html, $match))->toBe(1)
        ->and((int) ($match[1] ?? -1))->toBe(2);
});
```

Run: `php artisan test --compact tests/Feature/DashboardTest.php`
Expected: PASS (11 tests). Mutations 4–7 each turn exactly one of these tests red. With the file as it is on `main`, mutations 4 and 7 stay green (prototyped): that is the weakness A72/A84p describe.

- [ ] **Step 4: A73 — no checklist for a super admin on an organisation's host (`OnboardingChecklistTest.php`)**

Insert before 'gives members no checklist':

```php
it('gives a super admin no checklist on an organisation\'s host (RL-11)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asSuperAdmin();
    onTenant($organisation);

    expect(OnboardingChecklist::for($admin))->toBe([]);
});

```

Run: `php artisan test --compact tests/Feature/Dashboard/OnboardingChecklistTest.php`
Expected: PASS. Mutation 8 turns it red.

- [ ] **Step 5: A74 — an unsupported profile language (`ProfileUpdateTest.php`)**

The docblock becomes `/** covers §4.10, §4.11 */`. Insert before 'flashes the result in the new language when a language change reloads the page (G3-06)':

```php
it('refuses a language the kit does not support and saves nothing (§4.11)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $user = User::factory()->for($organisation)->create(['name' => 'Old Name', 'locale' => Locale::English]);
    $this->actingAs($user);
    onTenant($organisation);

    Livewire::test('pages::settings.profile')
        ->set('name', 'New Name')
        ->set('locale', 'fr')
        ->call('updateProfileInformation')
        ->assertHasErrors('locale')
        ->assertNoRedirect();

    expect($user->fresh()?->only(['name', 'locale']))->toBe(['name' => 'Old Name', 'locale' => Locale::English]);
});

```

Run: `php artisan test --compact tests/Feature/Settings/ProfileUpdateTest.php`
Expected: PASS. Mutation 9 turns it red with `"fr" is not a valid backing value` (a 500 in the browser).

- [ ] **Step 6: A76 — a 403 before the impersonation re-check (fixture + `ImpersonationEnforcementTest.php`)**

Create `tests/Fixtures/ForbiddingPreventRequestForgery.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Closure;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;

/**
 * Answers 403 to PATH where the CSRF middleware sits in the web group, ahead of EnforceImpersonation, so the 403 page
 * renders before a running impersonation is re-checked (RL-20). Every other request passes through as usual.
 */
final class ForbiddingPreventRequestForgery extends PreventRequestForgery
{
    public const string PATH = '_forbidden-probe';

    /** @param  Request  $request */
    public function handle($request, Closure $next): mixed
    {
        abort_if($request->is(self::PATH), 403);

        return parent::handle($request, $next);
    }
}
```

The parent's `handle($request, Closure $next)` has no parameter types, so the override cannot add one; the docblock types it for Larastan.

In `ImpersonationEnforcementTest.php`, add `use Tests\Fixtures\ForbiddingPreventRequestForgery;` after `use Tests\Fixtures\CsrfEnforcingPreventRequestForgery;`. Insert after the 419 test ('renders an error page raised before the re-check without resolving the user, …') and before 'keeps the impersonation session when disabling the target cuts their sessions, …':

```php
it('renders a 403 page raised before the re-check without resolving the user, so a gone target never falls back to the remember cookie (RL-20, RK-I1)', function () use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    startImpersonatingOverHttp($admin, $member);

    // The fixture answers 403 where the CSRF middleware sits, ahead of EnforceImpersonation: errors/403 reads only a
    // user the guard already resolved for its "Go to your organisation" link, and none is resolved yet (302c7d0).
    $this->app->bind(PreventRequestForgery::class, ForbiddingPreventRequestForgery::class);
    Route::middleware('web')->get(ForbiddingPreventRequestForgery::PATH, fn (): string => 'not reached');

    DB::table('users')->where('id', $member->id)->update(['deleted_at' => now()]);
    $guard = Auth::guard('web');
    $this->withCookie(
        $guard->getRecallerName(),
        $admin->id.'|'.$admin->remember_token.'|'.$guard->hashPasswordForCookie($admin->password),
    );

    $this->get('/'.ForbiddingPreventRequestForgery::PATH)->assertForbidden();

    expect(Impersonation::isActive())->toBeTrue()
        ->and(recordedActivity(ActivityEvent::Login))->toHaveCount(1) // only the sign-in through the login route
        ->and(recordedActivity(ActivityEvent::ImpersonationStopped))->toBeEmpty();

    Auth::guard('web')->forgetUser();
    $this->get(route('dashboard'))->assertRedirect(route('users.index'));

    expect(Impersonation::isActive())->toBeFalse()
        ->and(recordedActivity(ActivityEvent::Login))->toHaveCount(1)
        ->and(recordedActivity(ActivityEvent::ImpersonationStopped)->sole()->getProperty('ended_because'))->toBe('target_unavailable');
});

```

Run: `php artisan test --compact tests/Feature/Impersonation/ImpersonationEnforcementTest.php`
Expected: PASS (16 tests). Mutation 10 turns only the new test red ("Failed asserting that false is true": the remember cookie signed the admin in and ended the impersonation).

- [ ] **Step 7: D34, D35, D36, A84o — error pages (`ErrorPagesTest.php`)**

In `beforeEach`, after the `_error/crash` route, add:

```php
    Route::middleware('web')->get('_error/detail', fn () => abort(403, 'Secret detail'));
```

(`_error/{status}` only matches numbers, so `detail` reaches this route.)

D34: in the dataset of 'renders every error page without Vite, Flux or Livewire, with a dark mode (§6.5)', after the `'401, …'` row, add:

```php
    '402, from the kit instead of the framework (G3-04)' => ['/_error/402', 402],
```

and in the dataset of 'words a status without a page of its own in the visitor\'s language (G3-04)', after `'an unauthorised abort'`, add:

```php
    'a payment-required abort' => ['/_error/402', 402, 'Er klopt iets niet aan dit verzoek'],
```

D35: the dataset of 'lets "Try again" reload the same address with its query string, root-relative (G3-05)' becomes:

```php
})->with(['429', '500', '502', '503']);
```

D36 and A84o: insert before 'never points "Try again" at a POST-only address (G3-05)':

```php
it('points "Try again" after a failed POST only at this host (G2-02, G3-05)', function (string $referer): void {
    onApex();

    $this->post('/_error/429', [], ['Referer' => $referer])
        ->assertTooManyRequests()
        ->assertSee('href="/"', false)
        ->assertDontSee('evil.example');
})->with([
    'another site' => ['https://evil.example/phish'],
    'a protocol-relative URL' => ['//evil.example/phish'],
]);

it('shows no exception message on an error page (§6.5)', function (string $path, int $status, string $detail): void {
    onApex();

    $this->get($path)->assertStatus($status)->assertDontSee($detail);
})->with([
    'an exception behind a 500' => ['/_error/crash', 500, 'Boom'],
    'the message of an abort' => ['/_error/detail', 403, 'Secret detail'],
]);

```

With `app.debug` off, Laravel wraps the `RuntimeException('Boom')` in an `HttpException(500, 'Boom')`, so `$exception->getMessage()` in a view would print "Boom". The 500 row is therefore a real guard.

Run: `php artisan test --compact tests/Feature/ErrorPagesTest.php`
Expected: PASS (31 tests). Mutations 11–14 each turn only the new rows or tests red.

- [ ] **Step 8: D50 and E44 (`ProBranchCompilationTest.php`, `ActivityExportTest.php`)**

`ProBranchCompilationTest.php`: in 'reads @forelse\'s @empty, escaped directives and @verbatim blocks as Blade does (G5-09)', the expectation chain ends:

```php
    expect($stripped)->not->toContain('<flux:chart')->not->toContain('<flux:date-picker')
        ->toContain('<p>free</p>')
        ->and(substr_count($stripped, "\n"))->toBe(substr_count($blade, "\n"))
        // The lint reads the same branches as the stripper on exactly these cases (§6.7, G5-09).
        ->and(FluxProLint::violations($blade))->toBe([]);
```

`ActivityExportTest.php`: replace the whole test 'refuses a user without the activity_log.view permission' with this strengthened and retitled version (the imports `RateLimiter` and `ThrottleKey` are already there):

```php
it('refuses a user without the activity_log.view permission, after counting the request against the limit (CD-5)', function (): void {
    $organisation = Organisation::factory()->create();
    $member = asMember($organisation);

    $this->get(route('activity.export'))->assertForbidden();

    // The limiter runs before can:, so refused requests count too: a member cannot probe the export without limit.
    expect(recordedActivity(ActivityEvent::ActivityExported))->toHaveCount(0)
        ->and(RateLimiter::attempts(ThrottleKey::of('activity-export', 'activity-export:'.$member->id)))->toBe(1);
});
```

Run: `php artisan test --compact tests/Feature/FluxPro/ProBranchCompilationTest.php tests/Feature/Audit/ActivityExportTest.php`
Expected: PASS. Mutation 15 turns only the `@forelse` test red (`'line 9: <flux:date-picker />'`, while `tests/Arch/FluxProLayerTest.php` stays green: no kit view holds `@@`). Mutation 16 turns only the export test red ("Failed asserting that 0 is identical to 1.").

- [ ] **Step 9: `Role::color()` (CD-13; A62)**

Create `tests/Unit/RoleTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §6.5 */

use App\Authorization\Role;

it('gives every system role the colour of its badge (§6.5, CD-13)', function (): void {
    expect(array_combine(Role::values(), array_map(fn (Role $role): string => $role->color(), Role::cases())))
        ->toBe(['super_admin' => 'violet', 'organisation_admin' => 'zinc']);
});
```

Create `tests/Feature/Users/RoleBadgeTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §6.5 */

use App\Authorization\Role;
use App\Authorization\SetSuperAdmin;
use App\Models\User;

it('shows the super admin badge in the role\'s colour on the users list and on the user\'s page (§6.5, CD-13)', function (): void {
    $root = User::factory()->superAdmin()->create(['name' => 'Rita Root']);
    app(SetSuperAdmin::class)->handle($root, true);
    asSuperAdmin();
    onAdminHost();

    // Flux's violet badge; Role::color() decides it, so a changed colour fails here as well as in the unit test.
    $violetBadge = '/<[^>]*\bbg-violet-400\/20\s[^>]*>\s*'.preg_quote(Role::SuperAdmin->label(), '/').'\s*</';

    expect((string) $this->get(route('users.index'))->assertOk()->getContent())->toMatch($violetBadge)
        ->and((string) $this->get(route('users.edit', $root->id))->assertOk()->getContent())->toMatch($violetBadge);
});
```

Run: `php artisan test --compact tests/Unit/RoleTest.php tests/Feature/Users/RoleBadgeTest.php`
Expected: `RoleTest` FAILS with "Call to undefined method App\Authorization\Role::color()"; `RoleBadgeTest` passes (the literal is violet on `main`).

In `app/Authorization/Role.php`, add after `label()`:

```php

    /** The Flux badge colour of the role (§6.5): the super admin badge stands out, a team role stays neutral. */
    public function color(): string
    {
        return match ($this) {
            self::SuperAdmin => 'violet',
            self::OrganisationAdmin => 'zinc',
        };
    }
```

`resources/views/pages/users/index/index.blade.php` (`:110`) and `resources/views/pages/users/edit/edit.blade.php` (`:101`): replace

```blade
<flux:badge size="sm" color="violet">{{ \App\Authorization\Role::SuperAdmin->label() }}</flux:badge>
```

with

```blade
<flux:badge size="sm" :color="\App\Authorization\Role::SuperAdmin->color()">{{ \App\Authorization\Role::SuperAdmin->label() }}</flux:badge>
```

(the indentation of each line stays). The organisation admin's badge on these pages comes from `rolesByUser` / `roleOptions` as a plain label, like every team role, and stays uncoloured; `OrganisationAdmin->color()` is `zinc`, Flux's default, so a later caller gets the same look.

Run: `php artisan view:clear && php artisan test --compact tests/Unit/RoleTest.php tests/Feature/Users`
Expected: PASS. Mutations 17 and 18 turn the tests red.

- [ ] **Step 10: LoginTrend (C138; A84r window half)**

`tests/Feature/Dashboard/LoginTrendTest.php`: add the imports

```php
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
```

(between `use App\Tenancy\Facades\Tenant;` and `use Livewire\Livewire;`). In 'counts this host\'s sign-ins per day for the last 14 days, oldest first, zeros included (RL-15)', replace everything from the `Tenant::run($other, …)` line to the end of the test with:

```php
    Tenant::run($other, fn () => app(RecordActivity::class)->handle(ActivityEvent::Login, $admin));
    // The window's edges: the first minute of its first day counts, the last minute before it does not (A84r).
    $this->travelTo(now()->setDate(2026, 9, 29)->setTime(0, 1));
    app(RecordActivity::class)->handle(ActivityEvent::Login, $admin);
    $this->travelTo(now()->setDate(2026, 9, 28)->setTime(23, 59));
    app(RecordActivity::class)->handle(ActivityEvent::Login, $admin);
    $this->travelTo(now()->setDate(2026, 10, 12)->setTime(12, 0));

    $trend = LoginTrend::lastDays(14);

    expect($trend)->toHaveCount(14)
        ->and($trend[0])->toBe(['date' => '2026-09-29', 'logins' => 1])
        ->and($trend[13])->toBe(['date' => '2026-10-12', 'logins' => 2])
        ->and($trend[10])->toBe(['date' => '2026-10-09', 'logins' => 1])
        ->and(array_sum(array_column($trend, 'logins')))->toBe(4);
});

it('lists consecutive days whichever date class the application uses (G5-08)', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 12:00:00'));
    asOrgAdmin(Organisation::factory()->create(['slug' => 'acme']));
    // AppServiceProvider makes every date immutable; LoginTrend must not depend on it.
    Date::use(Carbon::class);

    try {
        $dates = array_column(LoginTrend::lastDays(3), 'date');
    } finally {
        Date::use(CarbonImmutable::class);
    }

    expect($dates)->toBe(['2026-10-10', '2026-10-11', '2026-10-12']);
});
```

Run: `php artisan test --compact tests/Feature/Dashboard/LoginTrendTest.php`
Expected: the new test FAILS with `2 => '2026-10-13'` instead of `'2026-10-12'` (the mutable start moved: d, d+1, d+3); the strengthened window test passes.

`app/Support/Dashboard/LoginTrend.php`: replace

```php
        $start = now()->startOfDay()->subDays($days - 1);
```

with

```php
        // Immutable whatever Date::use() chose: addDays() below must not move the start itself (G5-08).
        $start = now()->toImmutable()->startOfDay()->subDays($days - 1);
```

Run: `php artisan test --compact tests/Feature/Dashboard/LoginTrendTest.php`
Expected: PASS (3 tests). Mutation 19 turns the window test red.

- [ ] **Step 11: Verify by mutation**

Copy each file aside with `cp` and copy it back after the run; never `git stash`. Run `php artisan view:clear` after a Blade or component mutation. Every mutation below was run while writing this plan and fails exactly the named test.

| # | Mutation | Test that must fail |
|---|---|---|
| 1 | `SubdomainResolver::resolve()`: drop ` \|\| $organisation->slug !== $slug`, **and** `OrganisationObserver::forgetSlugs()`: `[$organisation->slug, $organisation->getOriginal('slug')]` → `[$organisation->slug]` (both together; either alone stays green, two independent guards) | 'frees the old slug at once…' (the old host answers 200) |
| 2 | `OrganisationValidationRules::uniqueOrganisationValue()`: in the `$holder === null` branch, before `return;`, add `if (\Illuminate\Support\Facades\DB::table('activity_log')->where('event', 'organisation.updated')->where('attribute_changes->old->'.$column, $value)->exists()) { $fail(__('This address can\'t be used.')); }` (an old-slug reservation) | 'frees the old slug at once…' ("This address can't be used.") |
| 3 | `Locales::resolve()`: move the `if ($request->hasSession()) { … }` block above `if ($user instanceof User) { … }` | 'puts the signed-in user\'s language before a language the session holds…' |
| 4 | `dashboard.php` `recentActivity()`: `$entries = HostScope::read(function (): array {` → `$entries = Tenant::bypass(function (): array {` | 'lists the last ten entries of this host\'s activity…' |
| 5 | `dashboard.php` `stats()`: the pending invitations' `HostScope::read(fn (): int => …)` → `Tenant::bypass(fn (): int => …)` | 'counts this host\'s pending invitations…' (5 instead of 2) |
| 6 | `dashboard.php` `stats()`: drop `->whereNull('accepted_at')` | 'counts this host\'s pending invitations…' (3) |
| 7 | `dashboard.php` `stats()`: `Organisation::query()->count()` → `Organisation::withTrashed()->count()` | 'counts organisations for super admins on central hosts, archived ones left out' (3) |
| 8 | `OnboardingChecklist::for()`: `if ($user->isSuperAdmin() && ! Tenant::check())` → `if ($user->isSuperAdmin())` | 'gives a super admin no checklist on an organisation\'s host' |
| 9 | `profile.php` `updateProfileInformation()`: `'locale' => ['required', Rule::enum(Locale::class)]` → `'locale' => ['required']` | 'refuses a language the kit does not support…' |
| 10 | `errors/403.blade.php:5`: `$user = \App\Support\Locales::resolvedUser();` → `$user = auth()->user();` | 'renders a 403 page raised before the re-check…' |
| 11 | delete `resources/views/errors/402.blade.php` (move it aside) | the `'402, …'` row of 'renders every error page…' and the `'a payment-required abort'` row of 'words a status…' |
| 12 | `errors/5xx.blade.php`: remove `@include('errors.partials.try-again')` | the `'502'` row of 'lets "Try again" reload…' |
| 13 | `errors/partials/try-again.blade.php`, the POST branch: `{{ \App\Http\Support\ReturnUrl::previous($request, '/') }}` → `{{ \Illuminate\Support\Str::after((string) $request->headers->get('referer', '/'), $request->getSchemeAndHttpHost()) }}` (a hand-rolled same-host check) | both rows of 'points "Try again" after a failed POST only at this host' (the existing same-host test stays green) |
| 14 | `errors/layout.blade.php:25`: `<p>@yield('message')</p>` → `<p>@yield('message')</p><p>{{ $exception->getMessage() }}</p>` | both rows of 'shows no exception message on an error page' |
| 15 | `tests/Support/FluxProLint.php` `violations()`: `FluxProBranches::branches($blade)` → `FluxProBranches::branches(str_replace('@@', '@', $blade))` (the lint reads escaped directives as live ones) | 'reads @forelse\'s @empty, escaped directives and @verbatim blocks as Blade does' |
| 16 | `FortifyServiceProvider` `activity-export` limiter → `fn (Request $request): Limit => $request->user()?->can(\App\Authorization\Permission::ActivityLogView) === true ? Limit::perMinute(5)->by('activity-export:'.$request->user()->getAuthIdentifier()) : Limit::none()` (counts only users who may export) | 'refuses a user without the activity_log.view permission, after counting the request…' |
| 17 | `Role::color()`: `self::SuperAdmin => 'violet'` → `'amber'` | `RoleTest` and `RoleBadgeTest` |
| 18 | `users/edit/edit.blade.php`: `:color="\App\Authorization\Role::SuperAdmin->color()"` → `color="zinc"` (same for `users/index/index.blade.php`) | `RoleBadgeTest` |
| 19 | `LoginTrend::lastDays()`: drop `->startOfDay()` | 'counts this host\'s sign-ins per day…' (`$trend[0]` has 0 logins instead of 1) |
| 20 | `LoginTrend::lastDays()`: drop `->toImmutable()` | 'lists consecutive days whichever date class…' |

- [ ] **Step 12: The gate, then commit**

```bash
composer fix
vendor/bin/phpstan analyse tests/Fixtures/ForbiddingPreventRequestForgery.php tests/Unit/RoleTest.php tests/Feature/Users/RoleBadgeTest.php tests/Feature/Dashboard/LoginTrendTest.php app/Authorization/Role.php app/Support/Dashboard/LoginTrend.php
php artisan test --compact tests/Arch
composer check
composer test:pgsql
php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser
npm run build && composer test:browser
npm run build:setup && git diff --exit-code resources/setup/setup.css
php artisan config:clear
```

`tests/Arch/TestFileClassesTest.php` must stay green: the fixture lives in `tests/Fixtures`, and no `*Test.php` spells a qualified class name (the mutations in Step 11 that do are code edits, never test code). Task 11 changes no setup view, so expect no stylesheet diff. The users blades change only the badge's attribute, so the browser suite's `SmokeTest` (`/users`) is the one browser check that touches them.

```bash
git add tests/Feature/Organisations/OrganisationEditTest.php tests/Feature/Localisation/SetLocaleTest.php \
    tests/Feature/DashboardTest.php tests/Feature/Dashboard/OnboardingChecklistTest.php \
    tests/Feature/Settings/ProfileUpdateTest.php tests/Fixtures/ForbiddingPreventRequestForgery.php \
    tests/Feature/Impersonation/ImpersonationEnforcementTest.php tests/Feature/ErrorPagesTest.php \
    tests/Feature/FluxPro/ProBranchCompilationTest.php tests/Feature/Audit/ActivityExportTest.php
git commit -m "test: pin the slug, locale, dashboard, error-page, lint and export protections no test guarded

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"

git add app/Authorization/Role.php resources/views/pages/users/index/index.blade.php \
    resources/views/pages/users/edit/edit.blade.php tests/Unit/RoleTest.php tests/Feature/Users/RoleBadgeTest.php
git commit -m "feat: give the role enum its badge colour and use it for the super admin badge

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"

git add app/Support/Dashboard/LoginTrend.php tests/Feature/Dashboard/LoginTrendTest.php
git commit -m "fix: keep the sign-in trend's days consecutive when the application uses mutable dates

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

---

### Task 12: Browser suite hardening and coverage (§6.5, §6.6, §6.7, §7.2; CD-15, CD-18; A77, A78, A84s, E69, E74)

The browser suite has three weaknesses, and three account extras have no browser coverage:
- **A77.** `SmokeTest` checks only the path of each page. An error page at the same path passes: with `abort(403)` in the roles page's `mount()`, the suite stays green today (prototyped). The app layout's `flux:main` gets a `data-test="app-main"` marker. `errors.layout` is self-contained and never has it. `SmokeTest` asserts the marker on every path, and a feature test pins both halves of the contract.
- **A84s.** `SmokeTest` presses the login button and navigates straight away. The first `navigate()` can race the sign-in POST. It now waits with `assertPathIs('/dashboard')`, which the plugin retries until the redirect has landed.
- **A78.** `LoginTest` and `AppearanceTest` click Dark, wait a fixed second, then read the database. The sidebar user menu saves the choice in a Livewire request of its own. When that request lands after the second, the test fails: with the save delayed by 1.5 s, both tests fail today (prototyped). The fixed wait becomes two waits:
  - the visible outcome, `html.dark`, which Flux sets in the browser at once;
  - a bounded poll (at most five seconds) until the database holds the choice. While the test waits, the plugin's event loop lets the in-process server answer the request.
- **E69.** A new `tests/Browser/AccountExtrasTest.php` covers three flows in Chromium:
  - The sessions modal: "Log out other devices" from the security page. It also proves Task 5's rotation in a real browser: this device's old session id is gone, and the browser keeps working with the cookie the Livewire update set (CD-18).
  - A PNG chosen in the free avatar file input and saved.
  - Two pasted addresses plus a CSV file in the free bulk-invitation input, then Check and Send.
- **E74.** A `ProVariantsTest` case checks that the Pro `flux:file-item` escapes a client-chosen file name, on the bulk page and on the profile. It runs only where Flux Pro is installed (CI's Pro job, M8b).

Re-verified on `main` (5a621c3), with these changes to the items as triaged:
- **A78.** Waiting for `html.dark` alone, as the triage suggests, proves nothing about the save: Flux sets the class in the browser before the request leaves. The plan keeps the `html.dark` check and adds the poll.
- **E69, uploads.** pest-plugin-browser 5.1.2's in-process server (`LaravelHttpServer::parseMultipartBody()`) mishandles a multipart upload in two ways:
  - it keeps a part named `files[]` under that flat key, while Livewire's upload endpoint reads `files`. The upload stores nothing, and `_finishUpload()` fails with "Undefined array key 0";
  - it passes each part through `mb_ltrim()`, which turns every byte that is not valid UTF-8 into `?`. A PNG arrives as `?PNG…` and fails the `image` rule. A CSV is plain ASCII and survives.

  The test-only middleware `Tests\Fixtures\NestUploadedFiles` works around both. It reads the `files[]` parts again from the raw body, byte for byte, and hands them on under `files`. The tests that upload a file append it to `livewire.temporary_file_upload.middleware`. The tests also fake Livewire's temporary-upload disk (`tmp-for-tests` while tests run), which Livewire otherwise uses before faking it ("Disk [tmp-for-tests] does not have a configured driver").
- **E74.** The triage's file name `<b>x</b>.csv` holds a `/`, which no file system allows in a name and no browser sends. The test uses `<img src=x onerror=alert(1)>` instead.
- **Task 5's rotation.** task-5.md implements CD-18 as `session()->migrate()` plus a move of the `sessions` row to the new id, not `migrate(true)`. The browser test was prototyped against exactly that code, and it passes. Without Task 5 it fails on the old id.

**Files:**
- Create:
  - `tests/Browser/AccountExtrasTest.php` (E69: three tests)
  - `tests/Fixtures/NestUploadedFiles.php` (the multipart workaround for Livewire uploads in the in-process server)
- Modify:
  - `resources/views/layouts/app.blade.php` (`<flux:main data-test="app-main">` and a Blade comment)
- Test:
  - `tests/Feature/Layouts/BaseLayoutTest.php` (A77: the marker on an app page, never on an error page at an app path)
  - `tests/Browser/SmokeTest.php` (A77, A84s)
  - `tests/Browser/LoginTest.php` (A78)
  - `tests/Browser/AppearanceTest.php` (A78)
  - `tests/Feature/FluxPro/ProVariantsTest.php` (E74, Pro only)

No lang keys, no CHANGELOG line (test-only plus a marker attribute), no README section.

**Interfaces:**
- Consumes:
  - Task 5: after `logoutOtherDevices()` the session has a new id. The response of that Livewire update sets the new cookie, and the old id's row is gone (task-5.md, Produces).
  - Task 3: the bulk page's Check charges `UserActionLimit::addressLookup()` one slot per row (3 here, of 500).
  - Task 7: `UpdateAvatar` accepts a PNG (`$size[2]` is JPEG, PNG or WEBP).
  - test helpers: `browserUser()`, `sameOrigin()`, `sessionRowFor()`, `withPermissionsTeam()`; `App\Authorization\Role::templateId()`; `App\Tenancy\Facades\Tenant::run()`
  - pest-plugin-browser 5.1.2: `attach()`, `assertPresent('@…')` (`[data-testid=…], [data-test=…]`), `assertScript()`, `script()`, `wait()`. Every assertion is retried until the plugin's timeout (`AwaitableWebpage::__call()` → `Execution::waitForExpectation()`), so `assertPathIs()` and `assertScript()` wait. `click()` and `press()` are never retried.
  - Livewire 4: the `livewire-upload-finish` event, dispatched once `_finishUpload()` has stored the file and set the property (`dist/livewire.js`, `UploadManager::markUploadFinished()`)
- Produces:
  - `resources/views/layouts/app.blade.php`: `<flux:main data-test="app-main">`, exactly once on every page that renders through the app layout, never on an error page.
  - `Tests\Fixtures\NestUploadedFiles` (final class, middleware `handle(Request $request, Closure $next): Response`): when the request is multipart, it puts every `files[]` part, read again from `$request->getContent()`, into the input as `files` => list of `Illuminate\Http\UploadedFile` (test mode), and deletes those temporary copies after the response.

Background for the implementer:
- **Selectors on the security page.** The page holds two inputs named `password`: the password change form and the modal. `fill('password', …)` would pick the first one, so every selector stays inside `dialog[data-modal="logout-other-devices"]`. The modal trigger is the only `[data-flux-modal-trigger]` inside the sessions component (`[wire\:name="pages::settings.sessions"]`). The security page is behind `password.confirm`, and a fresh sign-in is no confirmation, so the test passes the confirmation page first.
- **The session driver.** `phpunit.xml` uses the `array` driver, which writes no `sessions` rows. The sessions test switches to `database` before its first visit and forgets the resolved drivers. The browser and the test share the container, so the in-process server uses the new driver.
- **Uploads.** The free inputs are `name="avatar"` and `name="csv"` (Flux derives the name from `wire:model`). Before the attach, the test sets a `livewire-upload-finish` counter on `window` and waits for it (`assertScript('window.uploadsFinished === 1', true)`). Pressing Save or Check earlier would race the upload. The PNG and the CSV are written under `storage/framework/testing` and deleted in a `finally`. `Storage::fake('public')` keeps the saved avatar out of `storage/app/public` (Global Constraints).
- **E74 cannot run locally.** Flux Pro is not installed. Its body was run once with the `skip` removed on the free variant: both `set()` calls work, and the first `assertSee` fails as expected, because the free input shows no file name. The Pro rendering itself is verified in M8b.

- [ ] **Step 1: A77 — the app layout's marker**

In `tests/Feature/Layouts/BaseLayoutTest.php`, insert before 'lets guests follow the system preference':

```php
it('marks the main area of the app layout, which an error page at an app path never has (A77)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $this->actingAs(User::factory()->for($organisation)->create());
    onTenant($organisation);

    expect(substr_count((string) $this->get(route('dashboard'))->assertOk()->getContent(), 'data-test="app-main"'))->toBe(1);

    // A member without users.view: the 403 page renders through errors.layout at /users.
    $this->get(route('users.index'))->assertForbidden()->assertDontSee('data-test="app-main"', false);
});

```

Run: `php artisan test --compact tests/Feature/Layouts/BaseLayoutTest.php --filter='marks the main area'`
Expected: FAIL, "Failed asserting that 0 is identical to 1."

`resources/views/layouts/app.blade.php`: replace `    <flux:main>` with

```blade
    {{-- data-test="app-main": the browser suite tells a page from an error page at the same path by it (A77). --}}
    <flux:main data-test="app-main">
```

`flux:main` passes its attributes on to its `<div data-flux-main>` (`vendor/livewire/flux/stubs/resources/views/flux/main.blade.php`). The Blade comment never reaches the HTML, so the count stays 1.

Run: `php artisan view:clear && php artisan test --compact tests/Feature/Layouts/BaseLayoutTest.php`
Expected: PASS.

- [ ] **Step 2: A77 and A84s — `SmokeTest.php`**

Replace the line `$page->fill('email', $admin->email)->fill('password', 'password')->press('@login-button');` and the `foreach` after it with:

```php
    // assertPathIs retries until the sign-in has landed, so the first navigate() never races the login POST (A84s).
    $page->fill('email', $admin->email)->fill('password', 'password')->press('@login-button')->assertPathIs('/dashboard');

    foreach (['/dashboard', '/users', '/invitations/pending', '/roles', '/activity', '/settings/profile', '/settings/appearance'] as $path) {
        // assertPathIs proves the page rendered on this host, not a login redirect or a 404 on 127.0.0.1; the app
        // layout's marker proves it is the page itself, not an error page at the same path (A77).
        $page->navigate(sameOrigin($page->url(), $path))
            ->assertPathIs($path)
            ->assertPresent('@app-main')
            ->assertNoJavaScriptErrors();
    }
```

Run: `npm run build && vendor/bin/pest tests/Browser/SmokeTest.php`
Expected: PASS (2 tests). Before Step 1's layout change it fails with "Expected element [@app-main] to be present in the DOM".

- [ ] **Step 3: A78 — wait for the saved appearance (`LoginTest.php`, `AppearanceTest.php`)**

`tests/Browser/LoginTest.php`, in 'saves the appearance chosen on the settings page (DEC-12)', replace from `->click('[data-flux-radio-segmented][value="dark"]')` to the end of the test with:

```php
        ->click('[data-flux-radio-segmented][value="dark"]')
        ->assertScript('document.documentElement.classList.contains("dark")', true)
        ->assertNoJavaScriptErrors();

    // The user menu saves the choice in a Livewire request of its own: wait for it, at most five seconds (A78).
    for ($tries = 0; $member->fresh()?->appearance !== Appearance::Dark && $tries < 50; $tries++) {
        $page->wait(0.1);
    }

    expect($member->fresh()?->appearance)->toBe(Appearance::Dark);
});
```

`tests/Browser/AppearanceTest.php`, replace

```php
        ->click('[data-flux-radio-segmented][value="dark"]')
        ->wait(1);

    expect($admin->fresh()?->appearance)->toBe(Appearance::Dark);
```

with

```php
        ->click('[data-flux-radio-segmented][value="dark"]')
        ->assertScript('document.documentElement.classList.contains("dark")', true);

    // The user menu saves the choice in a Livewire request of its own: wait for it, at most five seconds (A78).
    for ($tries = 0; $admin->fresh()?->appearance !== Appearance::Dark && $tries < 50; $tries++) {
        $page->wait(0.1);
    }

    expect($admin->fresh()?->appearance)->toBe(Appearance::Dark);
```

The loop is inline in both files: the `$page` object is an `AwaitableWebpage` at run time but a `Webpage` to Larastan (the plugin's `@mixin`), so a typed shared closure would fail one of the two. `wait()` yields to the plugin's event loop, which is what lets the in-process server answer the save.

Run: `vendor/bin/pest tests/Browser/LoginTest.php` and then `vendor/bin/pest tests/Browser/AppearanceTest.php`
Expected: PASS.

- [ ] **Step 4: E69 — the upload fixture and `AccountExtrasTest.php`**

Create `tests/Fixtures/NestUploadedFiles.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

/**
 * pest-plugin-browser 5.1's in-process server reads a multipart upload in two ways Livewire's upload endpoint cannot
 * use: a part named `files[]` stays under that flat key (PHP nests it as `files` => [...]), and every byte that is no
 * valid UTF-8 becomes "?" (mb_ltrim()), which breaks any image. Added to that endpoint's middleware by the browser
 * tests that upload a file, this reads the `files[]` parts again from the raw body, byte for byte, and hands them on
 * under `files`, as PHP would. The request has converted its files already, so they go into the input, which all()
 * reads as well. The temporary copies are deleted once the endpoint has stored them.
 */
final class NestUploadedFiles
{
    private const string PART = '/\A\r\nContent-Disposition: form-data; name="files\[\]"; filename="([^"]*)"\r\n(?:Content-Type: ([^\r\n]*)\r\n)?\r\n(.*)\r\n\z/s';

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (preg_match('/boundary="?([^";]+)"?/i', (string) $request->header('content-type'), $boundary) !== 1) {
            return $next($request);
        }

        $files = [];

        foreach (explode('--'.$boundary[1], $request->getContent()) as $part) {
            if (preg_match(self::PART, $part, $match) === 1) {
                $path = (string) tempnam(sys_get_temp_dir(), 'pest-upload-');
                file_put_contents($path, $match[3]);
                $files[] = new UploadedFile($path, $match[1], $match[2] === '' ? null : $match[2], null, true);
            }
        }

        if ($files !== []) {
            $request->merge(['files' => $files]);
        }

        try {
            return $next($request);
        } finally {
            foreach ($files as $file) {
                File::delete($file->getPathname());
            }
        }
    }
}
```

Create `tests/Browser/AccountExtrasTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §6.6, §7.2 */

use App\Authorization\Role;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Tests\Fixtures\NestUploadedFiles;

/**
 * Lets Livewire's upload endpoint read the file the in-process server keeps under `files[]` (NestUploadedFiles), and
 * fakes the disk Livewire keeps temporary uploads on while tests run, so nothing reaches storage/app.
 */
$prepareUploads = function (): void {
    /** @var list<string> $middleware */
    $middleware = config('livewire.temporary_file_upload.middleware');

    config(['livewire.temporary_file_upload.middleware' => [...$middleware, NestUploadedFiles::class]]);
    Storage::fake(FileUploadConfiguration::disk());
};

it('logs out the other devices from the sessions modal and keeps this browser signed in on a new session (§6.6, CD-18)', function (): void {
    // The kit's driver: the sessions list reads the rows the database driver writes (phpunit.xml uses the array driver).
    config(['session.driver' => 'database']);
    app(SessionManager::class)->forgetDrivers();

    $member = browserUser(Organisation::factory()->create(['slug' => 'acme']));

    $page = visit('/login')->withHost('acme.localhost')
        ->fill('email', $member->email)->fill('password', 'password')->press('@login-button')
        ->assertPathIs('/dashboard');

    $thisDevice = DB::table('sessions')->where('user_id', $member->id)->value('id');
    $otherDevice = sessionRowFor($member);

    // The security page sits behind password.confirm; the sign-in a moment ago is no confirmation.
    $page->navigate(sameOrigin($page->url(), '/settings/security'))
        ->assertPathIs('/user/confirm-password')
        ->fill('password', 'password')
        ->press('@confirm-password-button')
        ->assertPathIs('/settings/security')
        ->assertSee(__('This device'))
        ->assertSee(__(':browser on :platform', ['browser' => 'Chrome', 'platform' => 'Windows']))
        // The page holds two password fields (the password change form): every selector stays inside the modal.
        ->click('[wire\:name="pages::settings.sessions"] [data-flux-modal-trigger] button')
        ->fill('dialog[data-modal="logout-other-devices"] input[name="password"]', 'password')
        ->click('dialog[data-modal="logout-other-devices"] button[type="submit"]')
        ->assertSee(__('You were logged out on your other devices.'))
        ->assertDontSee(__(':browser on :platform', ['browser' => 'Chrome', 'platform' => 'Windows']))
        ->assertSee(__('This device'))
        ->assertNoJavaScriptErrors();

    $rows = DB::table('sessions')->where('user_id', $member->id)->pluck('id')->all();

    // T5 (CD-18): this device's old id died with the others; the browser carries the new one.
    expect(is_string($thisDevice))->toBeTrue()
        ->and($rows)->toHaveCount(1)
        ->and($rows)->not->toContain($otherDevice)
        ->and($rows)->not->toContain($thisDevice);

    $page->navigate(sameOrigin($page->url(), '/dashboard'))
        ->assertPathIs('/dashboard')
        ->assertSee($member->name);

    expect(DB::table('sessions')->where('user_id', $member->id)->pluck('id')->all())->toBe($rows);
});

it('saves a photo chosen in the free file input (§6.6, §6.7)', function () use ($prepareUploads): void {
    $prepareUploads();
    Storage::fake('public');
    $member = browserUser(Organisation::factory()->create(['slug' => 'acme']));

    $photo = storage_path('framework/testing/browser-avatar-'.Str::uuid().'.png');
    File::ensureDirectoryExists(dirname($photo));
    $image = imagecreatetruecolor(64, 64);
    imagefilledrectangle($image, 0, 0, 63, 63, (int) imagecolorallocate($image, 0, 128, 255));
    imagepng($image, $photo);

    try {
        $page = visit('/login')->withHost('acme.localhost')
            ->fill('email', $member->email)->fill('password', 'password')->press('@login-button')
            ->assertPathIs('/dashboard');

        $page->navigate(sameOrigin($page->url(), '/settings/profile'))->assertPathIs('/settings/profile');

        // Livewire dispatches livewire-upload-finish once the temporary upload is stored and set on the component.
        $page->script('window.uploadsFinished = 0; window.addEventListener("livewire-upload-finish", () => window.uploadsFinished++)');

        $page->attach('avatar', $photo)
            ->assertScript('window.uploadsFinished === 1', true)
            ->press('@save-avatar-button')
            ->assertSee(__('Profile photo saved.'))
            ->assertPresent('@remove-avatar-button')
            ->assertNoJavaScriptErrors();
    } finally {
        File::delete($photo);
    }

    $path = $member->fresh()?->avatar_path;

    expect($path)->toBeString();
    Storage::disk('public')->assertExists((string) $path);
});

it('checks pasted addresses and a CSV file, then sends the invitations (§6.6, §6.7)', function () use ($prepareUploads): void {
    $prepareUploads();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = browserUser($organisation, 'admin@example.com');
    withPermissionsTeam($organisation->id, fn (): User => $admin->assignRole(Role::OrganisationAdmin->value));

    $csv = storage_path('framework/testing/browser-invitations-'.Str::uuid().'.csv');
    File::ensureDirectoryExists(dirname($csv));
    File::put($csv, "email\nthree@example.com\n");

    try {
        $page = visit('/login')->withHost('acme.localhost')
            ->fill('email', $admin->email)->fill('password', 'password')->press('@login-button')
            ->assertPathIs('/dashboard');

        $page->navigate(sameOrigin($page->url(), '/invitations/bulk'))->assertPathIs('/invitations/bulk');
        $page->script('window.uploadsFinished = 0; window.addEventListener("livewire-upload-finish", () => window.uploadsFinished++)');

        $page->fill('addresses', "one@example.com\ntwo@example.com")
            ->attach('csv', $csv)
            ->assertScript('window.uploadsFinished === 1', true)
            ->click('[data-test="bulk-roles"] ui-checkbox[value="'.Role::templateId().'"]')
            ->press('@bulk-check')
            ->assertSee(__('Ready: :ready of :total', ['ready' => 3, 'total' => 3]))
            ->press('@bulk-send')
            ->assertSee(__('Sent: :count', ['count' => 3]))
            ->assertNoJavaScriptErrors();
    } finally {
        File::delete($csv);
    }

    $invited = fn (): array => Invitation::query()->with('user')->get()
        ->map(fn (Invitation $invitation): string => $invitation->user->email)->sort()->values()->all();

    expect(Tenant::run($organisation, $invited))->toBe(['one@example.com', 'three@example.com', 'two@example.com']);
});
```

Run: `npm run build && vendor/bin/pest tests/Browser/AccountExtrasTest.php`
Expected: PASS (3 tests; the one detail-less agent-reporter warning is CD-15's). Without the fixture, both upload tests fail on `window.uploadsFinished === 1`: the server reports "Undefined array key 0" from `WithFileUploads::_finishUpload()`, and once the files are nested but not re-read, "The profile photo field must be an image."

- [ ] **Step 5: E74 — escaped file names in the Pro file items (`ProVariantsTest.php`)**

Add `use Illuminate\Http\UploadedFile;` after `use Composer\InstalledVersions;`, and append:

```php

it('escapes the name of a chosen file in the file items (§6.7, E74)', function (): void {
    asOrgAdmin(Organisation::factory()->create(['slug' => 'acme']));
    $name = '<img src=x onerror=alert(1)>';

    // The client chooses the name: flux:file-item shows it as the item's heading.
    Livewire::test('pages::invitations.bulk')
        ->set('csv', UploadedFile::fake()->createWithContent($name.'.csv', "one@example.com\n"))
        ->assertSee(e($name.'.csv'), false)
        ->assertDontSee($name, false);

    Livewire::test('pages::settings.profile')
        ->set('avatar', UploadedFile::fake()->image($name.'.png', 64, 64))
        ->assertSee(e($name.'.png'), false)
        ->assertDontSee($name, false);
})->group('flux-pro')->skip(fn (): bool => ! InstalledVersions::isInstalled('livewire/flux-pro'), 'Flux Pro is not installed (OD-1)');
```

Run: `php artisan test --compact tests/Feature/FluxPro/ProVariantsTest.php`
Expected: 3 skipped ("Flux Pro is not installed (OD-1)"). The test runs in CI's Pro job (M8b).

- [ ] **Step 6: Verify by mutation**

Copy each file aside with `cp` and copy it back after the run; never `git stash`. Run `php artisan view:clear` after a Blade or component mutation. Run browser files one at a time. Every row was run while writing this plan.

| # | Mutation | Test that must fail |
|---|---|---|
| 1 | `pages/roles/index/index.php` `mount()`: `$this->authorize('viewAny', RoleModel::class);` → `abort(403);` | `SmokeTest` (both rows: "Expected element [@app-main] to be present"); the `SmokeTest` of `main` stays green under it |
| 2 | `layouts/app.blade.php`: drop `data-test="app-main"` | 'marks the main area of the app layout…' and `SmokeTest` |
| 3 | `components/user-menu/user-menu.blade.php:2`: `value => $wire.saveAppearance(value)` → `value => setTimeout(() => $wire.saveAppearance(value), 1500)` (a slow save) | none: the new `LoginTest` and `AppearanceTest` stay green. Their `main` versions fail under it ("Failed asserting that two variables reference the same object"), which is A78 |
| 4 | `components/user-menu/user-menu.blade.php:2`: drop the `x-init="$watch('$flux.appearance', …)"` attribute (no save) | `AppearanceTest` and 'saves the appearance chosen on the settings page' (after the five-second poll) |
| 5 | revert Task 5's three lines in `settings/sessions/sessions.php` (`$previousId`, `session()->migrate()`, the row move) | 'logs out the other devices from the sessions modal…' (`$rows` still contains this device's old id) |
| 6 | `settings/profile/profile.blade.php`, the free branch: drop `wire:model="avatar"` from `<flux:input type="file" …>` | 'saves a photo chosen in the free file input' (the input has no `name="avatar"` and no upload) |
| 7 | `invitations/bulk/bulk.blade.php`, the free branch: drop `wire:model="csv"` from the file `flux:input` | 'checks pasted addresses and a CSV file…' |

E74 has no local mutation: it needs Flux Pro (M8b runs it).

- [ ] **Step 7: The gate, then commit**

```bash
composer fix
vendor/bin/phpstan analyse tests/Browser/AccountExtrasTest.php tests/Browser/SmokeTest.php tests/Browser/LoginTest.php \
    tests/Browser/AppearanceTest.php tests/Fixtures/NestUploadedFiles.php tests/Feature/FluxPro/ProVariantsTest.php \
    tests/Feature/Layouts/BaseLayoutTest.php
php artisan test --compact tests/Arch
composer check
composer test:pgsql
php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser
npm run build && composer test:browser
npm run build:setup && git diff --exit-code resources/setup/setup.css
php artisan config:clear
```

Name the files on the `phpstan` line: given the directory `tests/Browser`, Larastan reports the unmatched `resources/views/*` ignore pattern as an error. The browser suite now has 11 files. Task 12 changes no setup view, so expect no stylesheet diff.

```bash
git add resources/views/layouts/app.blade.php tests/Feature/Layouts/BaseLayoutTest.php \
    tests/Browser/SmokeTest.php tests/Browser/LoginTest.php tests/Browser/AppearanceTest.php
git commit -m "test: tell pages from error pages in the browser suite and wait for outcomes instead of a second

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"

git add tests/Fixtures/NestUploadedFiles.php tests/Browser/AccountExtrasTest.php tests/Feature/FluxPro/ProVariantsTest.php
git commit -m "test: cover the sessions modal, the free upload inputs and the bulk flow in the browser

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

---

### Task 13: CI workflow content (§8, §8 Hardening, §7.2, §6.7, §12 (4); CD-9, CD-10, CD-11; B26=C130=F23(a), F23(b), B33=C132, B80=C133=F16, C134=F17, B107=F25, B108=C35=C119=C135, F35, A80, F56)

GitHub Actions cannot run locally, so this task changes the workflow's content and pins every change in `tests/Feature/Tooling/CiWorkflowTest.php`, which now reads the **parsed** workflow (Symfony YAML) instead of its text, and runs the scripts of the new steps for real in sandboxes where they can run anywhere (the kit guard, the `boost:update` count, the `COMPOSER_AUTH` lines, the local script's refusals). The first real run is M8b.

What changes:
- **Flux credentials (CD-9, B33=C132).** No step writes `composer config --global http-basic…` any more, which left the licence in `COMPOSER_HOME/auth.json` for every later step (`npm ci` lifecycle scripts, Pest on same-repository pull requests). The steps that need the key build `COMPOSER_AUTH` from their own environment with `php -r … getenv(…)` (the key never reaches a command line) and export it for that step alone: the check job's *Enable Flux Pro*, and on the e2e job's postgres-pro row *Enable Flux Pro*, *skv:install* (its Claude step runs `composer require --dev laravel/boost` in an app that now lists the fluxui repository) and *skv:doctor* (its `flux-pro-credentials` check runs `composer config http-basic.composer.fluxui.dev` once Flux Pro is installed and fails without credentials; CD-9 names only the first two e2e steps, but without this one the postgres-pro row's doctor reports a `fail`). `composer audit` moves before the Flux Pro step. The two false comments are corrected.
- **PostgreSQL in CI (CD-10, B80=C133=F16).** The check job's service gets Herd's credentials (`root`, trust), which `Detector::postgresReachable()` probes; *Check (PostgreSQL)* uses `DB_USERNAME=root`, `DB_PASSWORD=''`. The browser job gets the same `postgres:18` service and `pdo_pgsql`. `REQUIRE_POSTGRES=true` on *Check (PostgreSQL)* and *Browser tests* turns the three availability skips (`WizardPageTest` 'completes on PostgreSQL…', the browser 'sets a super-admin install up on PostgreSQL…', `BackupRunTest`) into failures through the new fixture `Tests\Fixtures\RequiredPostgres`, and a Tooling test makes every such skip go through it.
- **A PostgreSQL 18 `pg_dump` (C134=F17).** A *PostgreSQL 18 client* step on the pgsql row installs `postgresql-client-18` from PGDG and links its `pg_dump` into `/usr/local/bin`, the first directory of `Toolchain::environment()`'s PATH that exists on Linux (neither the runner's PATH nor a `PG_DUMP_BINARY_PATH` variable reaches `BackupRunTest`; see the background below).
- **The kit-repository Flux Pro guard (CD-11, B108=C35).** A check-job step `if: github.repository == 'frankcornet/skv3'` fails when the committed `composer.json` or `composer.lock` names `flux-pro` or `composer.fluxui.dev`, or `composer.json` carries `extra.skv`. Generated apps skip it and may commit the mark (CD-13 of M7b). The release-check job repeats it on the tag in M8b (D115).
- **e2e assertions (B26=C130=F23).** *Phase 1 assertions* diffs `tests/Pest.php` and `tests/` on every row (no `--pest` guard: DEC-22's `--phpunit` leaves them as shipped). A new *boost:update after skv:install* step counts again after `skv:install`: exactly one with Boost, at most one without.
- **Path filter (B107=F25).** The `phase1` and `e2e` *Changes* steps share one widened regex: `composer.lock`, `package(-lock).json`, `bootstrap/`, `config/`, `database/(seeders|migrations)/`, `routes/setup.php`, `resources/setup/`, `resources/views/(pages/setup/|layouts/setup.blade.php|setup/)`, `scripts/e2e-local.sh`, `tests/Fixtures/e2e-answers.json` and the workflow itself, besides the old four.
- **Hardening test (F35)** and the **passkeys matrix test (A80)**, both weak-test items proven by mutation.
- **`scripts/e2e-local.sh` (F56, script half).** `--flags='<installer flags>'` and `--db=sqlite|pgsql` for the herd entry, so Task 18 can run the phpunit-no-boost and pgsql variants locally; phase 2 also counts `boost:update` after `skv:install`. The flag set is validated before anything happens.
- A82's `/etc/hosts` fallback is not written (M8b, only if `*.localhost` fails on ubuntu).

**Files:**
- Create:
  - `tests/Fixtures/RequiredPostgres.php`
  - `tests/Feature/Tooling/RequiredPostgresTest.php`
- Modify:
  - `.github/workflows/tests.yml` (whole file below)
  - `scripts/e2e-local.sh` (whole file below)
  - `tests/Feature/Tooling/CiWorkflowTest.php` (whole file below: 12 tests rewritten or added, the rest kept)
  - `tests/Feature/Backups/BackupRunTest.php` (on top of Task 1's `try … finally`: the import, the first lines of the test body, the `dump_binary_path` line and the skip line)
  - `tests/Feature/Setup/Wizard/WizardPageTest.php` (the import and the skip line of 'completes on PostgreSQL…')
  - `tests/Browser/SetupWizardTest.php` (the import and the skip line only; Task 12 owns the rest)
  - `CHANGELOG.md`
- Test:
  - `tests/Feature/Tooling/CiWorkflowTest.php`, `tests/Feature/Tooling/RequiredPostgresTest.php`
  - unchanged and still green: `tests/Feature/Tooling/ComposerScriptsTest.php` (its clone-route pin of `scripts/e2e-local.sh`), `tests/Arch/NameLeakTest.php`, `tests/Arch/TestFileClassesTest.php`. The script options are pinned in `CiWorkflowTest` next to the other `e2e-local.sh` tests, so `ComposerScriptsTest` needs no change.

**Interfaces:**
- Consumes:
  - `App\Setup\Detector::postgresReachable(string $host = '127.0.0.1', int $port = 5432): bool` (probes `root` with an empty password, database `postgres`)
  - `App\Setup\Support\Toolchain::environment(): array{HOME: string, PATH: string, ...}` (its own PATH: the Herd bin directory, Herd's newest node, `~/.local/bin`, then `/usr/local/bin:/opt/homebrew/bin:/usr/bin:/bin:/usr/sbin:/sbin`)
  - `App\Setup\Doctor\PostgresBinaries::find()` (reads `PG_DUMP_BINARY_PATH` from the `DoctorContext`'s `.env` file, then the toolchain PATH)
  - `php artisan skv:flux-pro` (`FluxProCommand`: first `composer config http-basic.composer.fluxui.dev`, which reads `COMPOSER_AUTH`) and the `flux-pro-credentials` doctor check (`FluxProCredentialsCheck`, the same command)
  - `ClaudeStep::addBoostUpdate()` (adds one `boost:update` when composer.json lists `laravel/boost` and has none)
  - Task 1's `BackupRunTest` (`$usablePgDump` with the probe in `try … finally`), Task 12's `tests/Browser/SetupWizardTest.php` (only its skip line and imports change here)
- Produces:
  - `Tests\Fixtures\RequiredPostgres` (final): `public const string FLAG = 'REQUIRE_POSTGRES';` and `public static function skips(Closure $available): bool` (true only when the flag is not `true` and `$available()` is false; `$available` is not called while the flag is `true`)
  - the CI variable `REQUIRE_POSTGRES: 'true'` on *Check (PostgreSQL)* and *Browser tests*
  - the workflow steps *Kit commits no Flux Pro* (check), *PostgreSQL 18 client* (check), *boost:update after skv:install* (e2e)
  - `scripts/e2e-local.sh <target> --entry=herd [--flags='--phpunit --git --no-boost'] [--db=pgsql] [--https]` (`--flags` default `--pest --git --boost`; `--db` default `sqlite`; both herd-only; exit 2 on any other value)

Background for the implementer:
- **Why `php -r` builds `COMPOSER_AUTH`.** `getenv()` inside PHP reads the key, so it never appears in a command line (a `jq --arg` or a `"$FLUX_LICENSE_KEY"` argument would put it in argv), and `json_encode` escapes any quote or backslash in it. PHP is on PATH in every step that needs it (after *Setup PHP*). The prototype proved, in a temporary `COMPOSER_HOME` with no `auth.json`, that `composer config http-basic.composer.fluxui.dev` prints the credentials from `COMPOSER_AUTH` (exit 0) and fails without it ("is not defined", exit 1), and that `Process::env(Toolchain::environment())` passes `COMPOSER_AUTH` through to the child (Symfony's Process inherits the parent environment; `environment()` only removes the project's `.env` keys). Nothing writes an `auth.json`, so no later step can read the key.
- **Residual exposure, accepted under CD-9.** On the postgres-pro row with secrets present, `skv:install`'s own children (`npm ci` among them) inherit `COMPOSER_AUTH` for that one step, because its Claude step needs Composer to reach the fluxui repository. Secrets reach only trusted events: fork pull requests and Dependabot pull requests get no repository secrets, so `FLUX_PRO_SECRETS` is false there.
- **Why a symlink in `/usr/local/bin`.** `BackupRunTest` finds `pg_dump` through `new Toolchain(storage_path('framework/testing/home'))`, whose PATH is fixed (`Toolchain::SYSTEM_PATH`), so `GITHUB_PATH` does not reach it; `PostgresBinaries::find()` reads `PG_DUMP_BINARY_PATH` from the sandbox `.env` file, not from the process. The runner's `/usr/bin/pg_dump` is Debian's `pg_wrapper`, which chooses the runner's own older PostgreSQL cluster's version. `/usr/local/bin` comes before `/usr/bin` on the toolchain PATH, and the Herd directories and the sandbox `~/.local/bin` do not exist on Linux; a `CiWorkflowTest` case pins that order. `ChecksTest`'s two "pg_dump is on the system PATH" skips already skip on the runner (`/usr/bin/pg_dump` exists), so the link changes nothing there.
- **`REQUIRE_POSTGRES` reaches Pest** through the process environment (`getenv()`), in parallel workers too. A required run calls no probe in the skip closure: it reaches the test body, which fails on the PDO connection (`WizardPageTest`, the browser test) or on the new explicit `expect($pgDump)->not->toBeNull(...)` (`BackupRunTest`). Only the value `true` counts.
- **Symfony YAML** (`symfony/yaml`, already installed through `laravel/roster`; `HerdManifestStepTest` uses it) reads `on:` as the string key `on`, as GitHub does, and `DB_PASSWORD: ''` as an empty string. A workflow it cannot parse fails every test that reads it; the prototype also parsed the file with PyYAML and Ruby's Psych.
- **The scripts that run in `CiWorkflowTest`** run under `bash --noprofile --norc -eo pipefail -c`, the shell GitHub uses for `shell: bash` (the check job's default `bash -e` is weaker). The kit-guard script runs in throwaway git repositories (`GIT_CONFIG_GLOBAL=/dev/null`); the count script in a temporary directory with a planted `composer.json`. They need `bash`, `git` and `php` on PATH, as the existing tests already do. `tests/Feature/Tooling` is outside the RL-12 stray-process directories (Task 1).
- **The local script's refusals** run `bash scripts/e2e-local.sh <existing dir> …`. Every refusal exits 2 before the script reads git, writes anything or looks at the target; the target is an existing directory, so if a validation were ever removed the script would stop at "it already exists" (exit 1) instead of installing.
- **macOS bash 3.2.** `/usr/bin/env bash` is bash 3.2 on a stock Mac (the prototype's). The option parsing uses `read -r -a` and refuses an empty `--flags=`, because `"${INSTALLER_FLAGS[@]}"` of an empty array fails under `set -u` in bash 3.2.
- **Kit name.** `tests.yml` names the kit (`--using=frankcornet/skv3:dev-ci` and now the guard's `if`); `NameLeakTest` does not scan it. The tests derive the name from `composer.json` (`$package()`), never a literal.

- [ ] **Step 1: Write the failing PostgreSQL-requirement test**

Create `tests/Feature/Tooling/RequiredPostgresTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §7.2, §8 */

use Illuminate\Support\Facades\File;
use Tests\Fixtures\RequiredPostgres;

/** Runs $test with REQUIRE_POSTGRES set to $value (null: unset), then puts the runner's own value back. */
$withFlag = function (?string $value, Closure $test): void {
    $original = getenv(RequiredPostgres::FLAG);
    putenv($value === null ? RequiredPostgres::FLAG : RequiredPostgres::FLAG.'='.$value);

    try {
        $test();
    } finally {
        putenv($original === false ? RequiredPostgres::FLAG : RequiredPostgres::FLAG.'='.$original);
    }
};

it('skips a PostgreSQL test only while REQUIRE_POSTGRES is unset (CD-10)', function () use ($withFlag): void {
    $withFlag(null, function (): void {
        expect(RequiredPostgres::skips(fn (): bool => false))->toBeTrue()
            ->and(RequiredPostgres::skips(fn (): bool => true))->toBeFalse();
    });

    $withFlag('true', function (): void {
        $probed = false;

        expect(RequiredPostgres::skips(function () use (&$probed): bool {
            $probed = true;

            return false;
        }))->toBeFalse()
            // A required run never probes: it reaches the test body, which fails on the missing server or client.
            ->and($probed)->toBeFalse();
    });

    $withFlag('false', function (): void {
        expect(RequiredPostgres::skips(fn (): bool => false))->toBeTrue();
    });
});

it('sends every skip on a missing PostgreSQL server or pg_dump through RequiredPostgres (CD-10, F16, F17)', function (): void {
    $skips = collect(File::allFiles(base_path('tests')))
        // This file names the pattern itself.
        ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), 'Test.php') && $file->getFilename() !== 'RequiredPostgresTest.php')
        ->flatMap(fn (SplFileInfo $file): array => collect(preg_split('/\R/', (string) file_get_contents($file->getPathname())) ?: [])
            ->filter(fn (string $line): bool => str_contains($line, '->skip(') && preg_match('/postgresReachable|PgDump/', $line) === 1)
            ->map(fn (string $line): string => $file->getFilename().': '.trim($line))
            ->all());

    expect($skips->reject(fn (string $line): bool => str_contains($line, 'RequiredPostgres::skips('))->values()->all())->toBe([])
        ->and($skips->map(fn (string $line): string => strstr($line, ':', true) ?: $line)->all())
        ->toContain('BackupRunTest.php', 'SetupWizardTest.php', 'WizardPageTest.php');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact tests/Feature/Tooling/RequiredPostgresTest.php`

Expected: FAIL. 'skips a PostgreSQL test only while REQUIRE_POSTGRES is unset' errors with `Class "Tests\Fixtures\RequiredPostgres" not found`; 'sends every skip on a missing PostgreSQL server or pg_dump through RequiredPostgres' fails listing the three skip lines of `SetupWizardTest.php`, `BackupRunTest.php` and `WizardPageTest.php`.

- [ ] **Step 3: Add the fixture and route the three skips through it**

Create `tests/Fixtures/RequiredPostgres.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Closure;

/**
 * CD-10: a test that needs PostgreSQL, or a pg_dump that can dump it, skips only where nobody asked for PostgreSQL.
 * CI sets REQUIRE_POSTGRES=true on the main (PostgreSQL) check row and in the browser job, so a broken service or
 * client fails there instead of hiding the test (F16, F17). Locally, and on the SQLite rows, the flag is unset.
 */
final class RequiredPostgres
{
    public const string FLAG = 'REQUIRE_POSTGRES';

    /**
     * Whether the test may skip: never while the flag is `true`, else when $available says no. $available runs only
     * when the flag is unset, so a required run always reaches the test body and fails there.
     *
     * @param  Closure(): bool  $available
     */
    public static function skips(Closure $available): bool
    {
        return getenv(self::FLAG) !== 'true' && ! $available();
    }
}
```

`tests/Feature/Setup/Wizard/WizardPageTest.php`: after `use Tests\Fixtures\InlineStepLauncher;` add

```php
use Tests\Fixtures\RequiredPostgres;
```

and replace the skip line of 'completes on PostgreSQL without a 500 or 419 (§7.2)'

```php
})->skip(fn (): bool => ! new Detector(new Toolchain)->postgresReachable(), 'PostgreSQL is not reachable');
```

with

```php
})->skip(fn (): bool => RequiredPostgres::skips(fn (): bool => new Detector(new Toolchain)->postgresReachable()), 'PostgreSQL is not reachable');
```

`tests/Browser/SetupWizardTest.php`: after `use Pest\Browser\Playwright\Playwright;` add `use Tests\Fixtures\RequiredPostgres;`, and replace the same skip line (the last line of 'sets a super-admin install up on PostgreSQL and signs the owner in once (§7.2)') with the same replacement. Nothing else in that file changes.

`tests/Feature/Backups/BackupRunTest.php` (after Task 1's change to `$usablePgDump`, which this step does not touch): after `use Tests\Fixtures\PgSslModeSnapshot;` add `use Tests\Fixtures\RequiredPostgres;`. Then replace the start of the test body

```php
it('backs up the database into an encrypted archive on the backups disk (§4.9)', function () use ($usablePgDump): void {
    Storage::fake('backups');
```

with

```php
it('backs up the database into an encrypted archive on the backups disk (§4.9)', function () use ($usablePgDump): void {
    $pgDump = $usablePgDump();
    // CD-10: under REQUIRE_POSTGRES the test runs without a usable pg_dump too, and fails here instead of skipping.
    expect($pgDump)->not->toBeNull('no pg_dump on the toolchain PATH can dump the PostgreSQL test server');
    Storage::fake('backups');
```

replace `        'database.connections.pgsql.dump.dump_binary_path' => $usablePgDump(),` with `        'database.connections.pgsql.dump.dump_binary_path' => $pgDump,`, and replace the skip line

```php
})->skip(fn (): bool => $usablePgDump() === null, 'needs PostgreSQL and a pg_dump at least as new as the server');
```

with

```php
})->skip(fn (): bool => RequiredPostgres::skips(fn (): bool => $usablePgDump() !== null), 'needs PostgreSQL and a pg_dump at least as new as the server');
```

- [ ] **Step 4: Run the tests to verify they pass, and that the flag turns a skip into a failure**

Run: `php artisan test --compact tests/Feature/Tooling/RequiredPostgresTest.php tests/Feature/Backups/BackupRunTest.php tests/Feature/Setup/Wizard/WizardPageTest.php tests/Arch`

Expected: PASS; `BackupRunTest` is skipped on SQLite, as before. (On a machine with Herd's PostgreSQL, 'completes on PostgreSQL…' runs, as it did before.)

Run: `REQUIRE_POSTGRES=true php artisan test --compact tests/Feature/Backups/BackupRunTest.php`

Expected: FAIL with `Expecting null not to be null 'no pg_dump on the toolchain PATH can dump the PostgreSQL test server'` (SQLite has no pg_dump to use): the flag turns the skip into a failure. This is the local proof of CD-10.

- [ ] **Step 5: Commit**

The task's full gate runs in Step 13, before the second commit.

```bash
vendor/bin/pint --test tests/Fixtures/RequiredPostgres.php tests/Feature/Tooling/RequiredPostgresTest.php tests/Feature/Backups/BackupRunTest.php tests/Feature/Setup/Wizard/WizardPageTest.php tests/Browser/SetupWizardTest.php
git add tests/Fixtures/RequiredPostgres.php tests/Feature/Tooling/RequiredPostgresTest.php tests/Feature/Backups/BackupRunTest.php \
    tests/Feature/Setup/Wizard/WizardPageTest.php tests/Browser/SetupWizardTest.php
git commit -m "test: fail the postgresql tests instead of skipping them when REQUIRE_POSTGRES is set

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 6: Write the failing workflow tests**

Replace `tests/Feature/Tooling/CiWorkflowTest.php` with the file below. It keeps every existing test (the committed-files check, its CD-13 skip and the unborn-repository case, audit, cache, Dependabot, IMP-12, phpunit.xml, the browser job, the installer, the local script, the setup stylesheet, phase1, g1-14) and changes these:
- 'runs the free job with the passkeys module off (§6.8)' is strengthened and retitled 'runs the free job with the passkeys module off and every other row with it on (§6.8, A80)': it reads the parsed matrix row by row, where the old regex also matched php85's line.
- 'enables Flux Pro in CI only when the secrets exist, before any view compiles (§6.7, §8)' becomes '…, after the audit and before any view compiles (§6.7, §8, CD-9)': it pins the step's environment, its exact script and the step order instead of `composer config --global`.
- 'maps the Flux Pro credentials of the e2e job in its Flux Pro step alone (§6.7, §8)' becomes 'maps the e2e job's Flux credentials only on the postgres-pro row (§6.7, §8, CD-9)': the e2e job now maps them in three steps, so the old title is false.
- New: the kit guard (CD-11, run in sandbox repositories, six cases), the hardening test (F35), PostgreSQL with Herd's credentials and `REQUIRE_POSTGRES` (CD-10), the PostgreSQL 18 client (F17), the credential steps and the `COMPOSER_AUTH` lines (CD-9, the latter run for real), the unconditional phase-1 diffs (g1-24), the `boost:update` count after `skv:install` (F23, run for real, six cases), the path filter (F25, against real paths), and the local script's variants and refusals (F56).

```php
<?php

declare(strict_types=1);

/** covers §8 */

use App\Setup\Support\Toolchain;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\Fixtures\RequiredPostgres;

it('keeps CI in tests.yml with matrix PHP versions only', function (): void {
    $ci = file_get_contents(base_path('.github/workflows/tests.yml'));

    expect($ci)->toContain('php-version: ${{ matrix.php }}')
        ->and(preg_match("/php-version:\s*'\d+\.\d+'/", (string) $ci))->toBe(0)
        ->and($ci)->not->toContain('./vendor/bin/phpunit')
        ->and($ci)->toContain('postgres:18')
        ->and($ci)->toContain('composer check')
        ->and(file_exists(base_path('.github/workflows/ci.yml')))->toBeFalse();
});

/**
 * tests.yml, parsed (F35): the workflow's structure is checked on what GitHub reads, not on its text. Symfony's parser
 * keeps `on` a string key, as GitHub does; a file it cannot parse fails every test that reads it.
 *
 * @return array<string, mixed>
 */
$workflow = function (): array {
    $parsed = Yaml::parseFile(base_path('.github/workflows/tests.yml'));

    expect($parsed)->toBeArray();

    /** @var array<string, mixed> $parsed */
    return $parsed;
};

/**
 * The steps of $job, in order.
 *
 * @return list<array<string, mixed>>
 */
$steps = function (string $job) use ($workflow): array {
    $steps = $workflow()['jobs'][$job]['steps'] ?? null;

    expect($steps)->toBeArray()->not->toBeEmpty();

    /** @var list<array<string, mixed>> $steps */
    return $steps;
};

/**
 * The one step of $job named $name.
 *
 * @return array<string, mixed>
 */
$step = function (string $job, string $name) use ($steps): array {
    $found = array_values(array_filter($steps($job), fn (array $step): bool => ($step['name'] ?? null) === $name));

    expect($found)->toHaveCount(1, "{$job} has no single step named [{$name}]");

    return $found[0];
};

/** Where the step named $name sits in $job (a missing step sorts last). */
$position = function (string $job, string $name) use ($steps): int {
    $index = array_search($name, array_column($steps($job), 'name'), true);

    return is_int($index) ? $index : PHP_INT_MAX;
};

/** $script run as GitHub runs a `shell: bash` step (`bash --noprofile --norc -eo pipefail {0}`), in $path. */
$bash = fn (string $script, string $path, array $env = []): ProcessResult => Process::path($path)
    ->env([...$env, 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1'])
    ->run(['bash', '--noprofile', '--norc', '-eo', 'pipefail', '-c', $script]);

/** A step's script with every line trimmed, so a block nested in an `if` compares like a top-level one. */
$trimmed = fn (mixed $script): string => implode("\n", array_map(trim(...), explode("\n", is_string($script) ? $script : '')));

/** The lines each step that needs the Flux credentials runs to hand them to Composer (CD-9), trimmed. */
$composerAuth = <<<'SH'
    COMPOSER_AUTH="$(php -r 'echo json_encode(["http-basic" => ["composer.fluxui.dev" => ["username" => getenv("FLUX_USERNAME"), "password" => getenv("FLUX_LICENSE_KEY")]]], JSON_THROW_ON_ERROR);')"
    export COMPOSER_AUTH
    SH;

/** The kit's package name, read from composer.json: NameLeakTest scans tests/, so this file holds no kit name literal. */
$package = fn (): string => (string) json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR)['name'];

/**
 * The committed version of $file in $repository (§6.7): a CI job that ran skv:flux-pro has flux-pro in its working
 * tree. Without a repository, or before its first commit (GitStep leaves `git init` without one), the working-tree
 * file is what will be committed.
 */
$committedFile = function (string $repository, string $file): string {
    $hasCommit = file_exists($repository.'/.git')
        && Process::path($repository)->run(['git', 'rev-parse', '--verify', '--quiet', 'HEAD'])->successful();

    return $hasCommit
        ? Process::path($repository)->run(['git', 'show', 'HEAD:'.$file])->throw()->output()
        : (string) file_get_contents($repository.'/'.$file);
};

/** Whether a composer.json carries skv:flux-pro's mark (CD-13): an app that commits Flux Pro on purpose carries it. */
$commitsFluxPro = function (string $composerJson): bool {
    $composer = json_decode($composerJson, true);

    return is_array($composer) && data_get($composer, 'extra.skv.flux-pro') === true;
};

/**
 * git in a throwaway repository, without the developer's global or system configuration (M33): a core.fsmonitor
 * there would start a daemon, and an init.templateDir would copy its hooks into the repository.
 */
$sandboxGit = fn (string $repository): PendingProcess => Process::path($repository)->env(['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1']);

/** A composer.json whose mark is $flag (data_set keeps the kit's namespace in one allowed form, NameLeakTest). */
$withFluxProMark = function (mixed $flag): string {
    $composer = ['name' => 'acme/app', 'require' => ['livewire/flux-pro' => '^2.0']];
    data_set($composer, 'extra.skv.flux-pro', $flag);

    return json_encode($composer, JSON_THROW_ON_ERROR);
};

it('never references Flux Pro in the committed composer files (committed)', function () use ($committedFile): void {
    expect($committedFile(base_path(), 'composer.json'))->toContain('"name"')->not->toContain('flux-pro')->not->toContain('composer.fluxui.dev')
        ->and($committedFile(base_path(), 'composer.lock'))->toContain('"packages"')->not->toContain('flux-pro')->not->toContain('composer.fluxui.dev');
})->skip(fn (): bool => $commitsFluxPro($committedFile(base_path(), 'composer.json')), 'this app committed Flux Pro on purpose with skv:flux-pro');

it('skips the committed-files check only for a commit that skv:flux-pro marked (CD-13)', function () use ($commitsFluxPro, $committedFile, $withFluxProMark, $sandboxGit): void {
    $repository = storage_path('framework/testing/ci-flux-pro-'.Str::uuid());
    File::ensureDirectoryExists($repository);
    File::put($repository.'/composer.json', $withFluxProMark(true));

    try {
        $sandboxGit($repository)->run(['git', 'init', '--quiet'])->throw();
        $sandboxGit($repository)->run(['git', 'add', 'composer.json'])->throw();
        $sandboxGit($repository)->run(['git', '-c', 'user.name=CI', '-c', 'user.email=ci@example.com', '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', 'commit', '--quiet', '-m', 'Add Flux Pro'])->throw();

        expect($commitsFluxPro($committedFile($repository, 'composer.json')))->toBeTrue()
            ->and($commitsFluxPro($withFluxProMark('true')))->toBeFalse()
            ->and($commitsFluxPro((string) json_encode(['require' => ['livewire/flux-pro' => '^2.0']])))->toBeFalse()
            ->and($commitsFluxPro('{'))->toBeFalse();
    } finally {
        File::deleteDirectory($repository);
    }
});

it('reads the working-tree composer files in a repository without a commit yet (§6.7)', function () use ($committedFile, $sandboxGit): void {
    $repository = storage_path('framework/testing/ci-unborn-'.Str::uuid());
    File::ensureDirectoryExists($repository);
    File::put($repository.'/composer.json', '{"name": "acme/app"}');

    try {
        $sandboxGit($repository)->run(['git', 'init', '--quiet'])->throw();

        expect($committedFile($repository, 'composer.json'))->toBe('{"name": "acme/app"}');
    } finally {
        File::deleteDirectory($repository);
    }
});

it('refuses a committed Flux Pro in the kit\'s own repository only (CD-11, M7b M1)', function (string $composerJson, string $composerLock, ?string $workingTree, bool $passes) use ($step, $position, $bash, $package, $sandboxGit): void {
    $guard = $step('check', 'Kit commits no Flux Pro');
    $repository = storage_path('framework/testing/ci-kit-guard-'.Str::uuid());
    File::ensureDirectoryExists($repository);
    File::put($repository.'/composer.json', $composerJson);
    File::put($repository.'/composer.lock', $composerLock);

    try {
        $sandboxGit($repository)->run(['git', 'init', '--quiet'])->throw();
        $sandboxGit($repository)->run(['git', 'add', 'composer.json', 'composer.lock'])->throw();
        $sandboxGit($repository)->run(['git', '-c', 'user.name=CI', '-c', 'user.email=ci@example.com', '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', 'commit', '--quiet', '-m', 'Composer files'])->throw();

        if ($workingTree !== null) {
            // What the Flux Pro step leaves in the working tree never counts: the guard reads the commit.
            File::put($repository.'/composer.json', $workingTree);
        }

        $result = $bash((string) $guard['run'], $repository);

        // Generated apps may commit the mark on purpose (CD-13 of M7b): the step runs in the kit's repository alone.
        expect($guard['if'])->toBe("github.repository == '{$package()}'")
            ->and($position('check', 'Kit commits no Flux Pro'))->toBeGreaterThan($position('check', 'Setup PHP'))
            ->and($result->successful())->toBe($passes, $result->output().$result->errorOutput());
    } finally {
        File::deleteDirectory($repository);
    }
})->with(function () use ($withFluxProMark): array {
    $clean = (string) json_encode(['name' => 'acme/app', 'require' => ['livewire/flux' => '^2.13']]);
    $cleanLock = (string) json_encode(['packages' => [['name' => 'livewire/flux']]]);
    $otherMark = ['name' => 'acme/app'];
    data_set($otherMark, 'extra.skv', ['note' => true]);

    return [
        'clean files' => [$clean, $cleanLock, null, true],
        'a clean commit under a working tree with Flux Pro' => [$clean, $cleanLock, $withFluxProMark(true), true],
        'the mark and flux-pro in composer.json' => [$withFluxProMark(true), $cleanLock, null, false],
        'flux-pro in the lock only' => [$clean, (string) json_encode(['packages' => [['name' => 'livewire/flux-pro']]]), null, false],
        'the fluxui repository' => [(string) json_encode(['name' => 'acme/app', 'repositories' => ['pro' => ['type' => 'composer', 'url' => 'https://composer.fluxui.dev']]]), $cleanLock, null, false],
        'an extra namespace of the kit without flux-pro' => [(string) json_encode($otherMark), $cleanLock, null, false],
    ];
});

it('reports abandoned packages in composer audit instead of failing', function (): void {
    /** @var array{config: array{audit?: array{abandoned?: string}}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['config']['audit']['abandoned'] ?? null)->toBe('report');
});

it('caches the Composer cache dir reported by Composer itself', function (): void {
    $ci = (string) file_get_contents(base_path('.github/workflows/tests.yml'));

    expect($ci)->toContain('composer config cache-files-dir')
        ->and($ci)->toContain('${{ steps.composer-cache.outputs.dir }}')
        ->and($ci)->not->toContain('~/.composer/cache');
});

it('keeps Dependabot on actions, composer and npm', function (): void {
    $path = base_path('.github/dependabot.yml');

    expect(is_file($path))->toBeTrue();

    $dependabot = (string) file_get_contents($path);

    foreach (['github-actions', 'composer', 'npm'] as $ecosystem) {
        expect($dependabot)->toContain(sprintf('package-ecosystem: "%s"', $ecosystem));
    }
});

it('pins every action by commit, keeps checkout credentials out of the workspace and bounds every job (§8 Hardening, F35)', function () use ($workflow): void {
    $text = (string) file_get_contents(base_path('.github/workflows/tests.yml'));
    $parsed = $workflow();
    preg_match_all('/^\s*(?:- )?uses: (.+)$/m', $text, $uses);
    $checkouts = 0;

    expect($uses[1])->not->toBeEmpty()
        // A tag or a branch can be moved to other code; a full commit SHA cannot. The comment names the release.
        ->each->toMatch('/^[\w.-]+\/[\w.\/-]+@[0-9a-f]{40} # v?\d+(\.\d+)*$/')
        ->and($parsed['permissions'])->toBe(['contents' => 'read'])
        ->and($parsed['concurrency']['cancel-in-progress'] ?? null)->toBeTrue()
        ->and($parsed['jobs'])->toBeArray()->toHaveKeys(['check', 'browser', 'phase1', 'e2e']);

    foreach ($parsed['jobs'] as $id => $job) {
        expect($job['timeout-minutes'] ?? null)->toBeInt("{$id} has no timeout-minutes")->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
            ->and($job)->not->toHaveKey('permissions');

        foreach ($job['steps'] as $jobStep) {
            if (str_starts_with((string) ($jobStep['uses'] ?? ''), 'actions/checkout@')) {
                $checkouts++;

                expect($jobStep['with']['persist-credentials'] ?? null)->toBeFalse("a checkout in {$id} keeps the token in .git/config");
            }
        }
    }

    expect($checkouts)->toBe(count($parsed['jobs']));
});

it('runs the sqlite-free job with the impersonation module off (IMP-12)', function (): void {
    $ci = (string) file_get_contents(base_path('.github/workflows/tests.yml'));

    expect(Str::between($ci, '- name: sqlite-free', '- name: php85'))->toContain("impersonation: 'false'")
        ->and(Str::between($ci, '- name: main', '- name: sqlite-free'))->toContain("impersonation: 'true'")
        ->and(Str::between($ci, '- name: php85', 'services:'))->toContain("impersonation: 'true'")
        ->and($ci)->toContain('MODULE_IMPERSONATION: ${{ matrix.impersonation }}');
});

it('runs the free job with the passkeys module off and every other row with it on (§6.8, A80)', function () use ($workflow): void {
    $parsed = $workflow();
    $modules = [];

    foreach ($parsed['jobs']['check']['strategy']['matrix']['include'] as $row) {
        $modules[Str::before((string) $row['name'], ' ')] = ['passkeys' => $row['passkeys'], 'impersonation' => $row['impersonation']];
    }

    expect($parsed['jobs']['check']['env']['MODULE_PASSKEYS'])->toBe('${{ matrix.passkeys }}')
        ->and($modules)->toBe([
            'main' => ['passkeys' => 'true', 'impersonation' => 'true'],
            'sqlite-free' => ['passkeys' => 'false', 'impersonation' => 'false'],
            'php85' => ['passkeys' => 'true', 'impersonation' => 'true'],
        ]);
});

it('lets the environment turn the module off in tests and pins the test hosts (IMP-12, FU2-9, FU3-17)', function (): void {
    $phpunit = (string) file_get_contents(base_path('phpunit.xml'));

    expect($phpunit)
        ->toContain('<env name="MODULE_IMPERSONATION" value="true"/>')
        ->toContain('<env name="APP_URL" value="http://localhost" force="true"/>')
        ->toContain('<env name="TENANCY_APEX_DOMAIN" value="localhost" force="true"/>')
        ->toContain('<env name="TENANCY_ADMIN_SUBDOMAIN" value="admin" force="true"/>')
        ->toContain('<env name="TENANCY_STRICT" value="true" force="true"/>')
        ->toContain('<env name="PASSKEYS_RELYING_PARTY_ID" value=""/>')
        ->toContain('<env name="TRUSTED_PROXIES" value=""/>');
});

it('runs the PostgreSQL tests in CI as Herd\'s root user and fails instead of skipping them (CD-10, F16, g3-39)', function () use ($workflow, $step): void {
    $jobs = $workflow()['jobs'];

    // Detector::postgresReachable() and the wizard expect Herd's credentials: user root without a password (RL-4).
    expect($jobs['check']['services']['postgres']['image'])->toBe('postgres:18')
        ->and($jobs['check']['services']['postgres']['env'])->toBe(['POSTGRES_USER' => 'root', 'POSTGRES_HOST_AUTH_METHOD' => 'trust', 'POSTGRES_DB' => 'app_test'])
        ->and($jobs['check']['services']['postgres']['options'])->toContain('pg_isready -U root')
        ->and($step('check', 'Check (PostgreSQL)')['env'])->toBe([
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => 5432,
            'DB_DATABASE' => 'app_test',
            'DB_USERNAME' => 'root',
            'DB_PASSWORD' => '',
            RequiredPostgres::FLAG => 'true',
        ])
        ->and($jobs['browser']['services']['postgres']['image'])->toBe('postgres:18')
        ->and($jobs['browser']['services']['postgres']['env'])->toBe(['POSTGRES_USER' => 'root', 'POSTGRES_HOST_AUTH_METHOD' => 'trust'])
        ->and($jobs['browser']['services']['postgres']['options'])->toContain('pg_isready -U root')
        ->and($step('browser', 'Setup PHP')['with']['extensions'])->toContain('pdo_pgsql')
        ->and($step('browser', 'Browser tests')['env'] ?? null)->toBe([RequiredPostgres::FLAG => 'true'])
        // The SQLite rows have no server to require.
        ->and($step('check', 'Check (SQLite)'))->not->toHaveKey('env')
        ->and($jobs['check']['env'])->not->toHaveKey(RequiredPostgres::FLAG);
});

it('installs a PostgreSQL 18 pg_dump where the toolchain looks first, before the PostgreSQL check (L-06, F17)', function () use ($step, $position): void {
    $client = $step('check', 'PostgreSQL 18 client');
    $path = explode(':', new Toolchain(storage_path('framework/testing/home'))->environment()['PATH']);
    $local = array_search('/usr/local/bin', $path, true);
    $system = array_search('/usr/bin', $path, true);

    expect($client['if'])->toBe("matrix.db == 'pgsql'")
        ->and($client['run'])->toContain('sudo /usr/share/postgresql-common/pgdg/apt.postgresql.org.sh -y')
        ->toContain('postgresql-client-18')
        ->toContain('sudo ln -sf /usr/lib/postgresql/18/bin/pg_dump /usr/local/bin/pg_dump')
        ->toContain("/usr/local/bin/pg_dump --version | grep -q '(PostgreSQL) 18\\.'")
        ->and($position('check', 'PostgreSQL 18 client'))->toBeLessThan($position('check', 'Check (PostgreSQL)'))
        // BackupRunTest finds pg_dump on this PATH: /usr/local/bin comes before the runner's /usr/bin/pg_dump wrapper.
        ->and($local)->toBeInt()
        ->and($system)->toBeInt()
        ->and(is_int($local) && is_int($system) && $local < $system)->toBeTrue();
});

it('runs the browser suite in its own CI job (§8)', function (): void {
    $workflow = (string) file_get_contents(base_path('.github/workflows/tests.yml'));

    expect($workflow)->toContain('npx playwright install --with-deps chromium')
        ->toContain('composer test:browser')
        ->toContain('tests/Browser/Traces')
        ->and(preg_match('/uses: actions\/upload-artifact@[0-9a-f]{40}/', $workflow))->toBe(1);
});

it('caches Composer and the Playwright browsers in the browser job and keeps failure traces briefly (§8)', function (): void {
    $browser = Str::after((string) file_get_contents(base_path('.github/workflows/tests.yml')), "\n  browser:\n");

    expect($browser)->toContain('composer config cache-files-dir')
        ->toContain('${{ steps.composer-cache.outputs.dir }}')
        ->toContain('path: ~/.cache/ms-playwright')
        ->toContain("require('./package-lock.json').packages['node_modules/playwright'].version")
        ->toContain('key: ${{ runner.os }}-playwright-${{ steps.playwright.outputs.version }}')
        ->toContain("if: steps.playwright-cache.outputs.cache-hit != 'true'")
        ->toContain("if: steps.playwright-cache.outputs.cache-hit == 'true'")
        ->toContain('npx playwright install-deps chromium')
        ->toContain('retention-days: 7')
        ->and(preg_match_all('/uses: actions\/cache@[0-9a-f]{40}/', $browser))->toBe(2);
});

it('enables Flux Pro in CI only when the secrets exist, after the audit and before any view compiles (§6.7, §8, CD-9)', function () use ($workflow, $step, $position, $trimmed, $composerAuth): void {
    $pro = $step('check', 'Enable Flux Pro');

    // The job maps only whether the secrets exist; the key itself never reaches install scripts or the test run.
    expect($workflow()['jobs']['check']['env']['FLUX_PRO_SECRETS'])->toBe("\${{ secrets.FLUX_USERNAME != '' && secrets.FLUX_LICENSE_KEY != '' }}")
        ->and($pro['if'])->toBe("env.FLUX_PRO_SECRETS == 'true' && matrix.db == 'pgsql'")
        ->and($pro['env'])->toBe([
            'FLUX_USERNAME' => '${{ secrets.FLUX_USERNAME }}',
            'FLUX_LICENSE_KEY' => '${{ secrets.FLUX_LICENSE_KEY }}',
            // The Pro dist zip must never land in the cached Composer files directory, which pull requests can restore.
            'COMPOSER_CACHE_DIR' => '${{ runner.temp }}/composer-flux-pro',
        ])
        ->and($trimmed($pro['run']))->toBe($composerAuth."\nphp artisan skv:flux-pro --no-interaction\n")
        // The audit reads the committed lock, which never holds flux-pro, so it runs before Composer gets the key.
        ->and($position('check', 'Audit dependencies'))->toBeLessThan($position('check', 'Enable Flux Pro'))
        ->and($position('check', 'Enable Flux Pro'))->toBeLessThan($position('check', 'Check (SQLite)'))
        ->and($position('check', 'Enable Flux Pro'))->toBeLessThan($position('check', 'Check (PostgreSQL)'));
});

it('hands the Flux credentials to Composer only in the steps that need them, never through a global auth.json (CD-9, g2-01)', function () use ($workflow, $step, $trimmed, $composerAuth): void {
    $text = (string) file_get_contents(base_path('.github/workflows/tests.yml'));
    $secretSteps = [];
    $secretJobEnv = [];

    foreach ($workflow()['jobs'] as $id => $job) {
        foreach ($job['env'] ?? [] as $key => $value) {
            if (str_contains((string) $value, 'secrets.')) {
                $secretJobEnv[] = "{$id}: {$key}";
            }
        }

        foreach ($job['steps'] as $jobStep) {
            foreach ($jobStep['env'] ?? [] as $key => $value) {
                if (str_contains((string) $value, 'secrets.')) {
                    $secretSteps["{$id}: {$jobStep['name']}"][] = $key;
                }
            }

            expect((string) ($jobStep['run'] ?? ''))->not->toContain('secrets.');
        }
    }

    expect($secretJobEnv)->toBe(['check: FLUX_PRO_SECRETS', 'e2e: FLUX_PRO_SECRETS'])
        ->and($secretSteps)->toBe([
            'check: Enable Flux Pro' => ['FLUX_USERNAME', 'FLUX_LICENSE_KEY'],
            'e2e: Enable Flux Pro' => ['FLUX_USERNAME', 'FLUX_LICENSE_KEY'],
            'e2e: skv:install' => ['FLUX_USERNAME', 'FLUX_LICENSE_KEY'],
            'e2e: skv:doctor' => ['FLUX_USERNAME', 'FLUX_LICENSE_KEY'],
        ])
        // B33: `composer config --global` wrote the key into COMPOSER_HOME/auth.json for every later step to read.
        ->and(preg_match('/composer config (?:-g|--global)\b[^\n]*http-basic/', $text))->toBe(0);

    foreach (array_keys($secretSteps) as $key) {
        [$job, $name] = explode(': ', $key, 2);

        expect($trimmed($step($job, $name)['run']))->toContain($composerAuth);
    }
});

it('builds COMPOSER_AUTH from the step\'s environment, so the licence key never reaches a command line (CD-9)', function () use ($bash, $composerAuth): void {
    $result = $bash($composerAuth."\nprintenv COMPOSER_AUTH", base_path(), ['FLUX_USERNAME' => 'dev@example.com', 'FLUX_LICENSE_KEY' => 'k"e\\y-1']);

    expect(json_decode($result->throw()->output(), true))->toBe(['http-basic' => ['composer.fluxui.dev' => ['username' => 'dev@example.com', 'password' => 'k"e\\y-1']]])
        // PHP reads the variables itself: the shell never expands the key into php's arguments.
        ->and($composerAuth)->not->toContain('$FLUX_');
});

it('runs the installer end to end in an e2e job (§8)', function () use ($package): void {
    $workflow = (string) file_get_contents(base_path('.github/workflows/tests.yml'));

    expect($workflow)
        ->toContain('workflow_dispatch:')
        ->toContain('schedule:')
        ->toContain('  e2e:')
        ->toContain('fetch-depth: 0')
        ->toContain('git switch -C ci')
        ->toContain('composer config -g repositories.'.Str::after($package(), '/').' vcs "$GITHUB_WORKSPACE"')
        ->toContain("--using={$package()}:dev-ci")
        ->toContain('--no-interaction --no-ansi --database=sqlite --npm')
        ->toContain('skv:install --no-interaction --answers=')
        ->toContain('--answers=@"$RUNNER_TEMP/e2e-answers.json"')
        ->toContain('skv:doctor --json')
        ->toContain("flags: '--pest --git --boost'")
        ->toContain("flags: '--phpunit --git --no-boost'");
});

it('maps the e2e job\'s Flux credentials only on the postgres-pro row (§6.7, §8, CD-9)', function () use ($workflow, $step, $position): void {
    $pro = $step('e2e', 'Enable Flux Pro');

    expect($workflow()['jobs']['e2e']['env']['FLUX_PRO_SECRETS'])->toBe("\${{ secrets.FLUX_USERNAME != '' && secrets.FLUX_LICENSE_KEY != '' }}")
        ->and($pro['if'])->toBe("steps.changes.outputs.run == 'true' && env.FLUX_PRO_SECRETS == 'true' && matrix.name == 'postgres-pro'")
        ->and($pro['env']['FLUX_LICENSE_KEY'])->toBe('${{ secrets.FLUX_LICENSE_KEY }}')
        ->and($pro['run'])->toContain('php artisan skv:flux-pro --no-interaction')
        ->and($position('e2e', 'Enable Flux Pro'))->toBeLessThan($position('e2e', 'skv:install'));

    // skv:install's composer require and skv:doctor's credentials check talk to the fluxui repository; the default
    // rows prove the credential-free path, so they get empty values (§8).
    foreach (['skv:install', 'skv:doctor'] as $name) {
        $credentialed = $step('e2e', $name);

        expect($credentialed['env'] ?? null)->toBe([
            'FLUX_USERNAME' => "\${{ matrix.name == 'postgres-pro' && secrets.FLUX_USERNAME || '' }}",
            'FLUX_LICENSE_KEY' => "\${{ matrix.name == 'postgres-pro' && secrets.FLUX_LICENSE_KEY || '' }}",
        ])->and($credentialed['run'])->toStartWith('if [ -n "$FLUX_LICENSE_KEY" ]; then');
    }
});

it('checks tests/ and tests/Pest.php after laravel new on every e2e row (g1-24, F23)', function () use ($step): void {
    $assertions = $step('e2e', 'Phase 1 assertions');

    // DEC-22: --phpunit leaves tests/ and the workflows exactly as shipped, so the phpunit-no-boost row checks them too.
    expect($assertions['run'])->toContain("\ndiff \"\$GITHUB_WORKSPACE/tests/Pest.php\" tmp/tests/Pest.php ")
        ->toContain("\ndiff -r \"\$GITHUB_WORKSPACE/tests\" tmp/tests ")
        ->not->toContain('FLAGS')
        ->and($assertions)->not->toHaveKey('env');
});

it('counts boost:update again after skv:install: exactly one with Boost, at most one without (F23)', function (string $flags, int $lines, bool $passes) use ($step, $position, $bash): void {
    $count = $step('e2e', 'boost:update after skv:install');
    $app = storage_path('framework/testing/ci-boost-'.Str::uuid());
    File::ensureDirectoryExists($app);
    File::put($app.'/composer.json', json_encode(['scripts' => ['post-update-cmd' => [
        '@php artisan vendor:publish --tag=laravel-assets --ansi --force',
        ...array_fill(0, $lines, '@php artisan boost:update --ansi'),
    ]]], JSON_THROW_ON_ERROR));

    try {
        $result = $bash((string) $count['run'], $app, ['FLAGS' => $flags]);

        expect($count['env'])->toBe(['FLAGS' => '${{ matrix.flags }}'])
            ->and($position('e2e', 'skv:install'))->toBeLessThan($position('e2e', 'boost:update after skv:install'))
            ->and($position('e2e', 'boost:update after skv:install'))->toBeLessThan($position('e2e', 'composer check'))
            ->and($result->successful())->toBe($passes, $result->output().$result->errorOutput());
    } finally {
        File::deleteDirectory($app);
    }
})->with([
    'Boost, none' => ['--pest --git --boost', 0, false],
    'Boost, one' => ['--pest --git --boost', 1, true],
    'Boost, two' => ['--pest --git --boost', 2, false],
    'no Boost, none' => ['--phpunit --git --no-boost', 0, true],
    'no Boost, one' => ['--phpunit --git --no-boost', 1, true],
    'no Boost, two' => ['--phpunit --git --no-boost', 2, false],
]);

it('runs phase1 and e2e on pull requests that touch what they install (DEC-31, F25)', function () use ($step): void {
    $filters = array_map(function (string $job) use ($step): string {
        preg_match("/grep -qE '([^']+)' <<< \"\\\$changed\"/", (string) $step($job, 'Changes')['run'], $match);

        return $match[1] ?? '';
    }, ['phase1', 'e2e']);
    // grep -E and PCRE read this alternation of anchored paths alike.
    $touches = fn (string $path): bool => preg_match('#'.$filters[0].'#', $path) === 1;
    $first = fn (string $directory): string => Str::after(collect(File::allFiles(base_path($directory)))->first()?->getPathname() ?? '', base_path().'/');
    $installed = [
        'composer.json', 'composer.lock', 'package.json', 'package-lock.json', '.env.example',
        'app/Setup/SetupRunner.php', 'app/Console/Commands/InstallCommand.php', 'bootstrap/app.php', 'config/app.php',
        $first('database/migrations'), $first('database/seeders'), 'routes/setup.php', 'resources/setup/setup.css',
        $first('resources/views/pages/setup'), 'resources/views/layouts/setup.blade.php', $first('resources/views/setup'),
        'scripts/e2e-local.sh', 'tests/Fixtures/e2e-answers.json', '.github/workflows/tests.yml',
    ];
    $unrelated = [
        'app/Models/User.php', 'README.md', 'docs/decisions.md', 'lang/nl.json', 'routes/web.php',
        'resources/views/layouts/app.blade.php', 'tests/Feature/Tooling/CiWorkflowTest.php', '.github/dependabot.yml',
        'composer.json.orig', 'scripts/e2e-local.sh.bak',
    ];

    expect($filters[0])->not->toBe('')
        ->and($filters[1])->toBe($filters[0])
        ->and(array_filter($installed, fn (string $path): bool => ! is_file(base_path($path))))->toBe([])
        ->and(array_values(array_filter($installed, fn (string $path): bool => ! $touches($path))))->toBe([])
        ->and(array_values(array_filter($unrelated, $touches)))->toBe([]);
});

it('ships a local e2e script that never touches global Composer config (OD-3)', function (): void {
    $script = (string) file_get_contents(base_path('scripts/e2e-local.sh'));

    expect(is_executable(base_path('scripts/e2e-local.sh')))->toBeTrue()
        ->and($script)->toContain('COMPOSER_HOME=')
        ->and($script)->toContain('skv:install --no-interaction --answers=')
        ->and($script)->toContain('--answers=@"$ANSWERS"')
        ->and($script)->not->toContain('composer config --global --list');
});

it('installs the e2e job\'s variants with the local script, and refuses any other (F56)', function () use ($workflow, $step): void {
    $script = (string) file_get_contents(base_path('scripts/e2e-local.sh'));
    // Every refusal names an existing directory: should a check ever be skipped, the script stops at "it already
    // exists" instead of installing anything.
    $existing = storage_path('framework/testing/e2e-local-'.Str::uuid());
    File::ensureDirectoryExists($existing);
    $refusals = [
        [['--flags=--pest --bogus'], 'Unknown installer flag: --bogus'],
        [['--flags=--pest --pest'], 'Installer flag given twice: --pest'],
        [['--flags=--pest --phpunit'], 'Pick one of --pest and --phpunit'],
        [['--flags=--boost --no-boost'], 'Pick one of --pest and --phpunit'],
        [['--flags='], '--flags needs at least one installer flag.'],
        [['--db=mysql'], 'Unknown database: mysql'],
        [['--entry=terminal', '--flags=--pest'], '--flags and --db apply to the herd entry only.'],
        [['--entry=composer', '--db=pgsql'], '--flags and --db apply to the herd entry only.'],
    ];

    try {
        foreach ($refusals as [$arguments, $message]) {
            $result = Process::run(['bash', base_path('scripts/e2e-local.sh'), $existing, ...$arguments]);

            expect($result->exitCode())->toBe(2, implode(' ', $arguments).': '.$result->errorOutput())
                ->and($result->errorOutput())->toContain($message);
        }
    } finally {
        File::deleteDirectory($existing);
    }

    $rows = array_column($workflow()['jobs']['e2e']['strategy']['matrix']['include'], 'flags', 'name');
    preg_match('/\.modules=(\[[^\]]*\])/', (string) $step('e2e', 'Answers')['run'], $ciModules);
    preg_match('/\$a\["modules"\] = (\[[^\]]*\]);/', $script, $localModules);

    // The default is Herd's flag set, the e2e job's default row; --db=pgsql answers as the postgres-pro row does.
    expect($script)->toContain('FLAGS="'.$rows['default'].'"')
        ->toContain('--database=sqlite --npm "${INSTALLER_FLAGS[@]}"')
        ->toContain('if ($argv[4] === "pgsql") { $a["database"] = "pgsql";')
        ->toContain('check "post-update-cmd holds exactly one boost:update after skv:install" boost_updates_after_install')
        ->and($rows['phpunit-no-boost'])->toBe('--phpunit --git --no-boost')
        ->and(json_decode($localModules[1] ?? 'null', true))->toBe(json_decode($ciModules[1] ?? 'null', true))
        ->and(json_decode($ciModules[1] ?? 'null', true))->toBe(['passkeys', 'backups', 'impersonation']);
});

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

it('checks in CI that the setup stylesheet is current, and names the dependencies that change it (OD-1, g4-07)', function (): void {
    expect((string) file_get_contents(base_path('.github/workflows/tests.yml')))
        ->toContain('npm run build:setup && git diff --exit-code resources/setup/setup.css')
        // A Dependabot update of these changes the built stylesheet too, so the guideline names them.
        ->and((string) file_get_contents(base_path('.ai/guidelines/skv3.md')))
        ->toContain('`tailwindcss`')->toContain('`@tailwindcss/vite`')->toContain('`vite`')->toContain('`livewire/flux`');
});

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
        ->toContain('SetupToken::PATH')
        ->not->toContain('composer config --global --list');
});

it('never hides a failed command substitution behind export or local in the shell scripts (g1-14)', function (): void {
    $scripts = [...(glob(base_path('scripts/*.sh')) ?: []), ...(glob(base_path('.githooks/*')) ?: [])];
    // `export X="$(cmd)"` returns export's status, so set -e never sees a failing cmd; assign first, then export.
    $masked = array_values(array_filter($scripts, fn (string $path): bool => preg_match('/^\s*(?:export|local|readonly|declare)\s+(?:-\w+\s+)*[A-Za-z_]\w*="?\$\(/m', (string) file_get_contents($path)) === 1));

    expect($scripts)->not->toBeEmpty()
        ->and($masked)->toBe([]);
});
```

- [ ] **Step 7: Run it to verify it fails**

Run: `php artisan test --compact tests/Feature/Tooling/CiWorkflowTest.php`

Expected: FAIL, 20 of 40 (20 pass: the kept tests, the hardening test and the passkeys test, which are weak-test items proven by mutation in Step 11, and 'builds COMPOSER_AUTH…', which runs the pinned lines). The failures:
- 'refuses a committed Flux Pro in the kit's own repository only' ×6: `check has no single step named [Kit commits no Flux Pro]`
- 'runs the PostgreSQL tests in CI as Herd's root user…': the service env is `postgres`/`postgres`
- 'installs a PostgreSQL 18 pg_dump…': `check has no single step named [PostgreSQL 18 client]`
- 'enables Flux Pro in CI only when the secrets exist, after the audit…': the script is `composer config --global …`
- 'hands the Flux credentials to Composer only in the steps that need them…': only the two Flux Pro steps map secrets
- 'maps the e2e job's Flux credentials only on the postgres-pro row': `skv:install` has no `env` (null)
- 'checks tests/ and tests/Pest.php after laravel new on every e2e row': the diffs sit inside `if [[ "$FLAGS" == *--pest* ]]`
- 'counts boost:update again after skv:install…' ×6: `e2e has no single step named [boost:update after skv:install]`
- 'runs phase1 and e2e on pull requests that touch what they install': `composer.lock`, `package.json`, … are not matched
- 'installs the e2e job's variants with the local script…': `Unknown option: --flags=--pest --bogus` instead of the installer-flag message

- [ ] **Step 8: Replace the workflow**

Replace `.github/workflows/tests.yml` with:

```yaml
name: ci

on:
  push:
    branches: [main]
  pull_request:
  # §8: the e2e job runs nightly and on demand; the other jobs run along, which is fine once a night.
  schedule:
    - cron: '17 3 * * *'
  workflow_dispatch:

permissions:
  contents: read

# The event is part of the group, so a push to main never cancels a nightly or dispatched run.
concurrency:
  group: ci-${{ github.event_name }}-${{ github.ref }}
  cancel-in-progress: true

jobs:
  check:
    name: ${{ matrix.name }}
    runs-on: ubuntu-latest
    timeout-minutes: 20
    strategy:
      fail-fast: false
      matrix:
        include:
          - name: main (PHP 8.4, PostgreSQL)
            php: '8.4'
            db: pgsql
            impersonation: 'true'
            passkeys: 'true'
          - name: sqlite-free (PHP 8.4, SQLite)
            php: '8.4'
            db: sqlite
            # §6.8: the free job runs the whole suite with both optional modules off.
            impersonation: 'false'
            passkeys: 'false'
          - name: php85 (PHP 8.5, SQLite)
            php: '8.5'
            db: sqlite
            impersonation: 'true'
            passkeys: 'true'

    env:
      MODULE_IMPERSONATION: ${{ matrix.impersonation }}
      MODULE_PASSKEYS: ${{ matrix.passkeys }}
      # §6.7: an `if:` cannot read secrets, so the job maps only whether they exist (fork PRs get none). The credentials
      # themselves are mapped in the Flux Pro step alone and reach Composer through that step's COMPOSER_AUTH, never a
      # file, so no install script, no later step and no test process sees the licence key (CD-9).
      FLUX_PRO_SECRETS: ${{ secrets.FLUX_USERNAME != '' && secrets.FLUX_LICENSE_KEY != '' }}

    # Herd's credentials (RL-4, CD-10): user root without a password, which the wizard's PostgreSQL probe expects. Only
    # the main row uses the server.
    services:
      postgres:
        image: postgres:18
        env:
          POSTGRES_USER: root
          POSTGRES_HOST_AUTH_METHOD: trust
          POSTGRES_DB: app_test
        ports:
          - 5432:5432
        options: >-
          --health-cmd "pg_isready -U root"
          --health-interval 5s
          --health-timeout 5s
          --health-retries 10

    steps:
      - name: Checkout
        uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
        with:
          persist-credentials: false

      - name: Setup PHP
        uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962 # 2.40.0
        with:
          php-version: ${{ matrix.php }}
          extensions: pdo_pgsql, pdo_sqlite, gd, zip
          tools: composer:v2
          coverage: none

      # CD-11 (M7b M1): the kit itself never commits Flux Pro or skv:flux-pro's mark, while a generated app may commit
      # both on purpose (CD-13 of M7b), so this runs in the kit's own repository only. It reads the committed files,
      # which the Flux Pro step below never changes.
      - name: Kit commits no Flux Pro
        if: github.repository == 'frankcornet/skv3'
        run: |
          committed="$(git show HEAD:composer.json HEAD:composer.lock)"
          if grep -nE 'flux-pro|composer\.fluxui\.dev' <<< "$committed"; then
            echo "FAIL: the committed composer.json or composer.lock names Flux Pro"
            exit 1
          fi
          git show HEAD:composer.json | php -r '$c = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR); exit(isset($c["extra"]["skv"]) ? 1 : 0);' || { echo "FAIL: the committed composer.json carries extra.skv"; exit 1; }

      - name: Setup Node
        uses: actions/setup-node@949feb2413d6458794dcd2491c4babbbce0c15c1 # v7.1.0
        with:
          node-version: '22'
          cache: npm

      - name: Composer cache dir
        id: composer-cache
        run: echo "dir=$(composer config cache-files-dir)" >> "$GITHUB_OUTPUT"

      - name: Cache Composer and analysis caches
        uses: actions/cache@55cc8345863c7cc4c66a329aec7e433d2d1c52a9 # v6.1.0
        with:
          path: |
            ${{ steps.composer-cache.outputs.dir }}
            storage/framework/cache/phpstan
            storage/framework/cache/rector
          key: ${{ runner.os }}-php${{ matrix.php }}-${{ hashFiles('composer.lock') }}
          restore-keys: ${{ runner.os }}-php${{ matrix.php }}-

      - name: Install
        run: |
          composer install --no-interaction --prefer-dist --no-progress
          cp .env.example .env
          php artisan key:generate --ansi
          npm ci
          npm run build

      - name: Check the setup stylesheet is current
        run: npm run build:setup && git diff --exit-code resources/setup/setup.css

      # Before Flux Pro: the audit reads the committed lock, which never holds flux-pro, and needs no credentials (CD-9).
      - name: Audit dependencies
        run: composer audit

      # §6.7, §8: only the main (PostgreSQL) row enables Pro, before any view compiles; without the secrets the step is
      # skipped and the job runs on free Flux. CD-9: the credentials reach Composer through COMPOSER_AUTH in this step's
      # environment alone, built by PHP from the variables (never argv); nothing writes an auth.json, so no later step
      # finds them. The step's own Composer cache keeps the Pro dist zip out of the cached files directory, which pull
      # requests (forks included) can restore; skv:flux-pro's composer inherits COMPOSER_CACHE_DIR and COMPOSER_AUTH.
      - name: Enable Flux Pro
        if: env.FLUX_PRO_SECRETS == 'true' && matrix.db == 'pgsql'
        env:
          FLUX_USERNAME: ${{ secrets.FLUX_USERNAME }}
          FLUX_LICENSE_KEY: ${{ secrets.FLUX_LICENSE_KEY }}
          COMPOSER_CACHE_DIR: ${{ runner.temp }}/composer-flux-pro
        run: |
          COMPOSER_AUTH="$(php -r 'echo json_encode(["http-basic" => ["composer.fluxui.dev" => ["username" => getenv("FLUX_USERNAME"), "password" => getenv("FLUX_LICENSE_KEY")]]], JSON_THROW_ON_ERROR);')"
          export COMPOSER_AUTH
          php artisan skv:flux-pro --no-interaction

      - name: Check (SQLite)
        if: matrix.db == 'sqlite'
        run: composer check

      # L-06, F17: BackupRunTest runs a real pg_dump, which refuses a server newer than itself. The runner's
      # /usr/bin/pg_dump is Debian's pg_wrapper, which picks the runner's own older cluster, so PGDG's version-18 client
      # is linked into /usr/local/bin, the first directory of Toolchain::environment()'s PATH that exists on Linux.
      - name: PostgreSQL 18 client
        if: matrix.db == 'pgsql'
        run: |
          sudo apt-get update -qq
          sudo apt-get install -y -qq postgresql-common
          sudo /usr/share/postgresql-common/pgdg/apt.postgresql.org.sh -y
          sudo apt-get install -y -qq --no-install-recommends postgresql-client-18
          sudo ln -sf /usr/lib/postgresql/18/bin/pg_dump /usr/local/bin/pg_dump
          /usr/local/bin/pg_dump --version | grep -q '(PostgreSQL) 18\.' || { echo "FAIL: /usr/local/bin/pg_dump is not version 18"; exit 1; }

      # CD-10: REQUIRE_POSTGRES turns the skips of the PostgreSQL wizard test and the real backup into failures, so a
      # broken service or client fails the row instead of hiding the tests (Tests\Fixtures\RequiredPostgres).
      - name: Check (PostgreSQL)
        if: matrix.db == 'pgsql'
        env:
          DB_CONNECTION: pgsql
          DB_HOST: 127.0.0.1
          DB_PORT: 5432
          DB_DATABASE: app_test
          DB_USERNAME: root
          DB_PASSWORD: ''
          REQUIRE_POSTGRES: 'true'
        run: composer check

  browser:
    name: browser (PHP ${{ matrix.php }}, SQLite, Chromium)
    runs-on: ubuntu-latest
    timeout-minutes: 20
    strategy:
      matrix:
        php: ['8.4']

    # g3-39, F16: the wizard's PostgreSQL browser test needs Herd's credentials (RL-4): user root without a password.
    services:
      postgres:
        image: postgres:18
        env:
          POSTGRES_USER: root
          POSTGRES_HOST_AUTH_METHOD: trust
        ports:
          - 5432:5432
        options: >-
          --health-cmd "pg_isready -U root"
          --health-interval 5s
          --health-timeout 5s
          --health-retries 10

    steps:
      - name: Checkout
        uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
        with:
          persist-credentials: false

      - name: Setup PHP
        uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962 # 2.40.0
        with:
          php-version: ${{ matrix.php }}
          extensions: pdo_pgsql, pdo_sqlite, gd, sockets, zip
          tools: composer:v2
          coverage: none

      - name: Setup Node
        uses: actions/setup-node@949feb2413d6458794dcd2491c4babbbce0c15c1 # v7.1.0
        with:
          node-version: '22'
          cache: npm

      - name: Composer cache dir
        id: composer-cache
        run: echo "dir=$(composer config cache-files-dir)" >> "$GITHUB_OUTPUT"

      - name: Cache Composer
        uses: actions/cache@55cc8345863c7cc4c66a329aec7e433d2d1c52a9 # v6.1.0
        with:
          path: ${{ steps.composer-cache.outputs.dir }}
          key: ${{ runner.os }}-php${{ matrix.php }}-browser-${{ hashFiles('composer.lock') }}
          restore-keys: ${{ runner.os }}-php${{ matrix.php }}-browser-

      - name: Playwright version
        id: playwright
        run: echo "version=$(node -p "require('./package-lock.json').packages['node_modules/playwright'].version")" >> "$GITHUB_OUTPUT"

      - name: Cache Playwright browsers
        id: playwright-cache
        uses: actions/cache@55cc8345863c7cc4c66a329aec7e433d2d1c52a9 # v6.1.0
        with:
          path: ~/.cache/ms-playwright
          key: ${{ runner.os }}-playwright-${{ steps.playwright.outputs.version }}

      # Chromium resolves *.localhost to loopback itself, so the tenant hosts need no /etc/hosts step (Task 1 spike, RL-2).
      - name: Install
        run: |
          composer install --no-interaction --prefer-dist --no-progress
          cp .env.example .env
          php artisan key:generate --ansi
          npm ci
          npm run build

      - name: Install Chromium
        if: steps.playwright-cache.outputs.cache-hit != 'true'
        run: npx playwright install --with-deps chromium

      # A cache hit restores the browser binaries only; the system libraries Chromium needs are installed each run.
      - name: Install Chromium system dependencies
        if: steps.playwright-cache.outputs.cache-hit == 'true'
        run: npx playwright install-deps chromium

      # CD-10: the PostgreSQL wizard test fails instead of skipping when the service does not answer.
      - name: Browser tests
        env:
          REQUIRE_POSTGRES: 'true'
        run: composer test:browser

      - name: Upload screenshots and traces
        if: failure()
        uses: actions/upload-artifact@cf430e030ddbb5b0abf93d22962f4752f3646cd9 # v7.0.2
        with:
          name: browser-artifacts
          path: |
            tests/Browser/Screenshots
            tests/Browser/Traces
          if-no-files-found: ignore
          # Traces hold request and response bodies and cookies of the test run; keep them only briefly.
          retention-days: 7

  # §7.2 (OD-3, RL-11): installs the commit under test through each of the four entry points without Herd, then
  # checks that .env and its APP_KEY exist and that the wizard renders, with its token, under php artisan serve.
  phase1:
    name: phase1 (${{ matrix.entry }})
    runs-on: ubuntu-latest
    timeout-minutes: 45
    strategy:
      fail-fast: false
      matrix:
        php: ['8.4']
        entry: [terminal, terminal-nodb, composer, clone]

    defaults:
      run:
        shell: bash

    steps:
      - name: Checkout
        uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
        with:
          fetch-depth: 0
          persist-credentials: false

      # Every later step runs only when this says so, so a pull request that does not touch the setup skips the job
      # without failing it. The filter (DEC-31, F25) names everything the install depends on: the setup code and its
      # views, the dependencies, bootstrap, config, migrations and seeders, the answers, the script and this file.
      - name: Changes
        id: changes
        env:
          EVENT_NAME: ${{ github.event_name }}
          BASE_REF: ${{ github.base_ref }}
        run: |
          run=false
          if [ "$EVENT_NAME" = schedule ] || [ "$EVENT_NAME" = workflow_dispatch ]; then
            run=true
          elif [ "$EVENT_NAME" = pull_request ]; then
            changed="$(git diff --name-only "origin/$BASE_REF...HEAD")"
            if grep -qE '^(composer\.(json|lock)$|package(-lock)?\.json$|\.env\.example$|app/Setup/|app/Console/Commands/|bootstrap/|config/|database/(seeders|migrations)/|routes/setup\.php$|resources/setup/|resources/views/(pages/setup/|layouts/setup\.blade\.php$|setup/)|scripts/e2e-local\.sh$|tests/Fixtures/e2e-answers\.json$|\.github/workflows/tests\.yml$)' <<< "$changed"; then
              run=true
            fi
          fi
          echo "run=$run" >> "$GITHUB_OUTPUT"

      - name: Setup PHP
        if: steps.changes.outputs.run == 'true'
        uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962 # 2.40.0
        with:
          php-version: ${{ matrix.php }}
          extensions: pdo_pgsql, pdo_sqlite, gd, zip
          tools: composer:v2
          coverage: none

      - name: Setup Node
        if: steps.changes.outputs.run == 'true'
        uses: actions/setup-node@949feb2413d6458794dcd2491c4babbbce0c15c1 # v7.1.0
        with:
          node-version: '22'

      # The script installs dev-<branch>, so the commit under test becomes the branch ci.
      - name: Serve the commit under test as dev-ci
        if: steps.changes.outputs.run == 'true'
        run: |
          git config --global user.name "e2e"
          git config --global user.email "e2e@example.com"
          git switch -C ci
          composer config -g repositories.skv3 vcs "$GITHUB_WORKSPACE"

      - name: Install the Laravel installer
        if: steps.changes.outputs.run == 'true'
        run: |
          composer global require laravel/installer --no-interaction
          echo "$(composer global config bin-dir --absolute)" >> "$GITHUB_PATH"

      - name: Phase 1 (${{ matrix.entry }})
        if: steps.changes.outputs.run == 'true'
        run: scripts/e2e-local.sh "$RUNNER_TEMP/phase1-app" --entry=${{ matrix.entry }}

  # §8: installs the commit under test with the installer, never Packagist, then runs skv:install, the doctor and
  # composer check in the new app. It runs nightly, on demand and on pull requests that touch the setup.
  e2e:
    name: e2e (${{ matrix.name }})
    runs-on: ubuntu-latest
    timeout-minutes: 45
    strategy:
      fail-fast: false
      matrix:
        include:
          - name: default
            php: '8.4'
            flags: '--pest --git --boost'
            db: sqlite
          - name: phpunit-no-boost
            php: '8.4'
            flags: '--phpunit --git --no-boost'
            db: sqlite
          - name: postgres-pro
            php: '8.4'
            flags: '--pest --git --boost'
            db: pgsql

    defaults:
      run:
        shell: bash

    env:
      # §6.7, CD-9: like the check job, the job maps only whether the Flux Pro secrets exist. On the postgres-pro row,
      # the three steps that run Composer against the fluxui repository (Enable Flux Pro, skv:install with its
      # composer require, and skv:doctor with its credentials check) map the credentials and pass them as
      # COMPOSER_AUTH; the installer, the other rows and composer check never see the licence key.
      FLUX_PRO_SECRETS: ${{ secrets.FLUX_USERNAME != '' && secrets.FLUX_LICENSE_KEY != '' }}

    # Herd's credentials (RL-4): user root with an empty password. Only the postgres-pro row uses the server.
    services:
      postgres:
        image: postgres:18
        env:
          POSTGRES_USER: root
          POSTGRES_HOST_AUTH_METHOD: trust
        ports:
          - 5432:5432
        options: >-
          --health-cmd "pg_isready -U root"
          --health-interval 5s
          --health-timeout 5s
          --health-retries 10

    steps:
      - name: Checkout
        uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
        with:
          fetch-depth: 0
          persist-credentials: false

      # Every later step runs only when this says so, so a pull request that does not touch the setup skips the job
      # without failing it. The filter (DEC-31, F25) names everything the install depends on: the setup code and its
      # views, the dependencies, bootstrap, config, migrations and seeders, the answers, the script and this file.
      - name: Changes
        id: changes
        env:
          EVENT_NAME: ${{ github.event_name }}
          BASE_REF: ${{ github.base_ref }}
        run: |
          run=false
          if [ "$EVENT_NAME" = schedule ] || [ "$EVENT_NAME" = workflow_dispatch ]; then
            run=true
          elif [ "$EVENT_NAME" = pull_request ]; then
            changed="$(git diff --name-only "origin/$BASE_REF...HEAD")"
            if grep -qE '^(composer\.(json|lock)$|package(-lock)?\.json$|\.env\.example$|app/Setup/|app/Console/Commands/|bootstrap/|config/|database/(seeders|migrations)/|routes/setup\.php$|resources/setup/|resources/views/(pages/setup/|layouts/setup\.blade\.php$|setup/)|scripts/e2e-local\.sh$|tests/Fixtures/e2e-answers\.json$|\.github/workflows/tests\.yml$)' <<< "$changed"; then
              run=true
            fi
          fi
          echo "run=$run" >> "$GITHUB_OUTPUT"

      - name: Setup PHP
        if: steps.changes.outputs.run == 'true'
        uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962 # 2.40.0
        with:
          php-version: ${{ matrix.php }}
          extensions: pdo_pgsql, pdo_sqlite, gd, zip
          tools: composer:v2
          coverage: none

      - name: Setup Node
        if: steps.changes.outputs.run == 'true'
        uses: actions/setup-node@949feb2413d6458794dcd2491c4babbbce0c15c1 # v7.1.0
        with:
          node-version: '22'

      # The installer commits the new app (--git); the runner has no git identity of its own.
      - name: Serve the commit under test as dev-ci
        if: steps.changes.outputs.run == 'true'
        run: |
          git config --global user.name "e2e"
          git config --global user.email "e2e@example.com"
          git switch -C ci
          composer config -g repositories.skv3 vcs "$GITHUB_WORKSPACE"

      - name: Install the Laravel installer
        if: steps.changes.outputs.run == 'true'
        run: |
          composer global require laravel/installer --no-interaction
          echo "$(composer global config bin-dir --absolute)" >> "$GITHUB_PATH"

      # Herd's exact command (§8); the installer's log proves the hooks below.
      - name: laravel new
        if: steps.changes.outputs.run == 'true'
        run: |
          cd "$RUNNER_TEMP" && laravel new tmp --using=frankcornet/skv3:dev-ci --no-interaction --no-ansi --database=sqlite --npm ${{ matrix.flags }} 2>&1 | tee install.log

      # §5.2, §7.2, §8: bootstrap ran once (it prints the skv:install hint), the SQLite file is migrated, the build
      # exists, the installer left tests.yml, tests/Pest.php and tests/ alone on every row (g1-24: DEC-22's --phpunit
      # leaves them as shipped too), and post-update-cmd holds at most one boost:update.
      - name: Phase 1 assertions
        if: steps.changes.outputs.run == 'true'
        working-directory: ${{ runner.temp }}
        run: |
          bootstraps="$(grep -c 'skv:install' install.log || true)"
          [ "$bootstraps" = 1 ] || { echo "FAIL: skv:bootstrap ran $bootstraps times, expected once"; exit 1; }
          grep -q '^DB_CONNECTION=sqlite' tmp/.env || { echo "FAIL: .env does not use SQLite"; exit 1; }
          status="$(cd tmp && php artisan migrate:status --no-ansi)" || { echo "FAIL: migrate:status"; exit 1; }
          if grep -q 'Pending' <<< "$status"; then echo "FAIL: pending migrations"; echo "$status"; exit 1; fi
          test -f tmp/public/build/manifest.json || { echo "FAIL: public/build/manifest.json is missing"; exit 1; }
          diff "$GITHUB_WORKSPACE/.github/workflows/tests.yml" tmp/.github/workflows/tests.yml || { echo "FAIL: tests.yml changed"; exit 1; }
          diff "$GITHUB_WORKSPACE/tests/Pest.php" tmp/tests/Pest.php || { echo "FAIL: tests/Pest.php changed"; exit 1; }
          diff -r "$GITHUB_WORKSPACE/tests" tmp/tests || { echo "FAIL: tests/ changed"; exit 1; }
          php -r '$c = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR); exit(count(array_filter($c["scripts"]["post-update-cmd"] ?? [], fn ($line) => str_contains($line, "boost:update"))) <= 1 ? 0 : 1);' tmp/composer.json || { echo "FAIL: post-update-cmd holds more than one boost:update"; exit 1; }

      # No Herd in CI, so RL-7 turns passkeys off again after asking for them.
      - name: Answers
        if: steps.changes.outputs.run == 'true'
        env:
          DB: ${{ matrix.db }}
        run: |
          if [ "$DB" = pgsql ]; then
            jq '.database="pgsql" | .modules=["passkeys","backups","impersonation"] | .https=true' "$GITHUB_WORKSPACE/tests/Fixtures/e2e-answers.json" > "$RUNNER_TEMP/e2e-answers.json"
          else
            cp "$GITHUB_WORKSPACE/tests/Fixtures/e2e-answers.json" "$RUNNER_TEMP/e2e-answers.json"
          fi

      # The same lines as the check job (§6.7, CD-9), inside the new app and only on the postgres-pro row.
      - name: Enable Flux Pro
        if: steps.changes.outputs.run == 'true' && env.FLUX_PRO_SECRETS == 'true' && matrix.name == 'postgres-pro'
        working-directory: ${{ runner.temp }}/tmp
        env:
          FLUX_USERNAME: ${{ secrets.FLUX_USERNAME }}
          FLUX_LICENSE_KEY: ${{ secrets.FLUX_LICENSE_KEY }}
        run: |
          COMPOSER_AUTH="$(php -r 'echo json_encode(["http-basic" => ["composer.fluxui.dev" => ["username" => getenv("FLUX_USERNAME"), "password" => getenv("FLUX_LICENSE_KEY")]]], JSON_THROW_ON_ERROR);')"
          export COMPOSER_AUTH
          php artisan skv:flux-pro --no-interaction

      # CD-9: with Flux Pro the app lists the fluxui repository, so the Claude step's composer require needs the
      # credentials too; the other rows map empty values and run without them.
      - name: skv:install
        if: steps.changes.outputs.run == 'true'
        working-directory: ${{ runner.temp }}/tmp
        env:
          FLUX_USERNAME: ${{ matrix.name == 'postgres-pro' && secrets.FLUX_USERNAME || '' }}
          FLUX_LICENSE_KEY: ${{ matrix.name == 'postgres-pro' && secrets.FLUX_LICENSE_KEY || '' }}
        run: |
          if [ -n "$FLUX_LICENSE_KEY" ]; then
            COMPOSER_AUTH="$(php -r 'echo json_encode(["http-basic" => ["composer.fluxui.dev" => ["username" => getenv("FLUX_USERNAME"), "password" => getenv("FLUX_LICENSE_KEY")]]], JSON_THROW_ON_ERROR);')"
            export COMPOSER_AUTH
          fi
          sed -i 's#^APP_URL=.*#APP_URL=http://localhost#' .env
          php artisan skv:install --no-interaction --answers=@"$RUNNER_TEMP/e2e-answers.json"

      # DEC-22 rule 5: skv:install restores the Boost constraint the installer lowered to ^2.2.
      - name: Boost constraint
        if: steps.changes.outputs.run == 'true' && !contains(matrix.flags, '--no-boost')
        working-directory: ${{ runner.temp }}/tmp
        run: |
          php -r '$c = json_decode(file_get_contents("composer.json"), true, flags: JSON_THROW_ON_ERROR); exit(($c["require-dev"]["laravel/boost"] ?? null) === "^2.10" ? 0 : 1);' || { echo "FAIL: require-dev laravel/boost is not ^2.10"; exit 1; }

      # F23: the installer's --boost adds one boost:update and skv:install's Claude step adds one only when there is
      # none, so the app ends with exactly one; without Boost at most one.
      - name: boost:update after skv:install
        if: steps.changes.outputs.run == 'true'
        working-directory: ${{ runner.temp }}/tmp
        env:
          FLAGS: ${{ matrix.flags }}
        run: |
          lines="$(php -r '$c = json_decode(file_get_contents("composer.json"), true, flags: JSON_THROW_ON_ERROR); echo count(array_filter($c["scripts"]["post-update-cmd"] ?? [], fn ($line) => str_contains($line, "boost:update")));')"
          if [[ "$FLAGS" == *--no-boost* ]]; then
            [ "$lines" -le 1 ] || { echo "FAIL: post-update-cmd holds $lines boost:update lines, expected at most one"; exit 1; }
          else
            [ "$lines" = 1 ] || { echo "FAIL: post-update-cmd holds $lines boost:update lines, expected exactly one"; exit 1; }
          fi

      # RL-11: exits non-zero on any fail. An apex under localhost passes the subdomains check (RFC 6761). CD-9: with
      # Flux Pro the flux-pro-credentials check asks Composer for the credentials.
      - name: skv:doctor
        if: steps.changes.outputs.run == 'true'
        working-directory: ${{ runner.temp }}/tmp
        env:
          FLUX_USERNAME: ${{ matrix.name == 'postgres-pro' && secrets.FLUX_USERNAME || '' }}
          FLUX_LICENSE_KEY: ${{ matrix.name == 'postgres-pro' && secrets.FLUX_LICENSE_KEY || '' }}
        run: |
          if [ -n "$FLUX_LICENSE_KEY" ]; then
            COMPOSER_AUTH="$(php -r 'echo json_encode(["http-basic" => ["composer.fluxui.dev" => ["username" => getenv("FLUX_USERNAME"), "password" => getenv("FLUX_LICENSE_KEY")]]], JSON_THROW_ON_ERROR);')"
            export COMPOSER_AUTH
          fi
          php artisan skv:doctor --json

      - name: composer check
        if: steps.changes.outputs.run == 'true'
        working-directory: ${{ runner.temp }}/tmp
        run: composer check
```

- [ ] **Step 9: Replace the local e2e script**

Replace `scripts/e2e-local.sh` with the file below (keep its executable bit: `git diff` must show no mode change; `CiWorkflowTest` checks `is_executable`):

```bash
#!/usr/bin/env bash
#
# The local counterpart of the e2e and phase1 CI jobs (§7.2, §8, OD-3): installs the current branch through one of
# the four entry points into a new directory and checks what it left behind (phase 1), including the browser wizard.
# The herd entry then runs skv:install with the e2e answers and skv:doctor (phase 2). It never deletes anything and
# never runs a Herd command that changes a site; it prints the cleanup commands instead.
#
# Usage: scripts/e2e-local.sh [<target-dir>] [--https] [--entry=herd|terminal|terminal-nodb|composer|clone] [--phase1-only]
#                               [--flags='<installer flags>'] [--db=sqlite|pgsql]
#   <target-dir>   where the new app goes; it must not exist yet (default for herd: $HOME/Herd/m6b-e2e)
#   --https        answer https=true, so the Herd step secures the site (herd entry, phase 2)
#   --entry=       herd (default): Herd's laravel new, then phase 2; terminal: laravel new --database=sqlite;
#                  terminal-nodb: laravel new without --database; composer: composer create-project;
#                  clone: git clone plus the commands of composer setup before skv:install (RL-11)
#   --phase1-only  stop after phase 1, leaving the site as phase 1 left it (token present, no marker)
#   --flags=       the herd entry's installer flags after --npm, as the e2e job's rows pass them (F56): Herd's
#                  '--pest --git --boost' by default, or '--phpunit --git --no-boost'; each of --pest, --phpunit,
#                  --git, --boost and --no-boost at most once
#   --db=          the herd entry's phase-2 database answer: sqlite (default) or pgsql, which also turns on every
#                  module, as the e2e job's postgres-pro row does
#
# Every entry but herd serves the app with php artisan serve on a free port of 127.0.0.1 for the wizard check, and
# reaches it under the host the wizard pins (RL-5): {name}.{tld} where Herd is installed, else localhost. Each entry
# ends after phase 1. Only the committed state of the current branch is installed (as dev-<branch>). Composer runs
# with a temporary COMPOSER_HOME, so the global Composer configuration and credentials are never read or changed.
# The setup token is never printed.

set -euo pipefail

TARGET=""
HTTPS=false
ENTRY=herd
PHASE1_ONLY=false
# Herd's exact flags, the e2e job's default row (§8).
FLAGS="--pest --git --boost"
DB=sqlite
VARIANT=false

for argument in "$@"; do
    case "$argument" in
        --https) HTTPS=true ;;
        --entry=*) ENTRY="${argument#--entry=}" ;;
        --phase1-only) PHASE1_ONLY=true ;;
        --flags=*) FLAGS="${argument#--flags=}"; VARIANT=true ;;
        --db=*) DB="${argument#--db=}"; VARIANT=true ;;
        -*) echo "Unknown option: $argument" >&2; exit 2 ;;
        *) [ -z "$TARGET" ] || { echo "Only one target directory is allowed" >&2; exit 2; }; TARGET="$argument" ;;
    esac
done

case "$ENTRY" in
    herd | terminal | terminal-nodb | composer | clone) ;;
    *) echo "Unknown entry: $ENTRY (use herd, terminal, terminal-nodb, composer or clone)" >&2; exit 2 ;;
esac

case "$DB" in
    sqlite | pgsql) ;;
    *) echo "Unknown database: $DB (use sqlite or pgsql)" >&2; exit 2 ;;
esac

read -r -a INSTALLER_FLAGS <<< "$FLAGS"
if [ "${#INSTALLER_FLAGS[@]}" = 0 ]; then
    echo "--flags needs at least one installer flag." >&2
    exit 2
fi
SEEN=" "
for flag in "${INSTALLER_FLAGS[@]}"; do
    case "$flag" in
        --pest | --phpunit | --git | --boost | --no-boost) ;;
        *) echo "Unknown installer flag: $flag (use --pest, --phpunit, --git, --boost or --no-boost)" >&2; exit 2 ;;
    esac
    [[ "$SEEN" != *" $flag "* ]] || { echo "Installer flag given twice: $flag" >&2; exit 2; }
    SEEN="$SEEN$flag "
done
if [[ "$SEEN" == *" --pest "* && "$SEEN" == *" --phpunit "* ]] || [[ "$SEEN" == *" --boost "* && "$SEEN" == *" --no-boost "* ]]; then
    echo "Pick one of --pest and --phpunit, and one of --boost and --no-boost." >&2
    exit 2
fi
# What the installer's --boost leaves (DEC-22 rule 5) is checked only when the flags ask for Boost.
WITH_BOOST=true
if [[ "$SEEN" == *" --no-boost "* ]]; then WITH_BOOST=false; fi

if [ "$ENTRY" = herd ]; then
    TARGET="${TARGET:-$HOME/Herd/m6b-e2e}"
elif [ -z "$TARGET" ]; then
    echo "The $ENTRY entry needs a target directory." >&2
    exit 2
elif [ "$HTTPS" = true ]; then
    echo "--https applies to the herd entry's phase 2 only." >&2
    exit 2
elif [ "$VARIANT" = true ]; then
    echo "--flags and --db apply to the herd entry only." >&2
    exit 2
fi

if [ -e "$TARGET" ]; then
    echo "Refusing to install into $TARGET: it already exists. Remove it yourself or pick another directory." >&2
    exit 1
fi

TARGET_PARENT="$(cd "$(dirname "$TARGET")" && pwd)"
NAME="$(basename "$TARGET")"
TARGET="$TARGET_PARENT/$NAME"

REPO=$(git -C "$(dirname "${BASH_SOURCE[0]}")" rev-parse --show-toplevel)
BRANCH=$(git -C "$REPO" rev-parse --abbrev-ref HEAD)

if [ "$BRANCH" = HEAD ]; then
    echo "Check out a branch first: the installer installs dev-<branch>." >&2
    exit 1
fi

if [ -n "$(git -C "$REPO" status --porcelain)" ]; then
    echo "WARN: the working tree has uncommitted changes; only the commits on $BRANCH are installed."
fi

if [[ "$ENTRY" =~ ^(herd|terminal|terminal-nodb)$ ]] && ! command -v laravel > /dev/null; then
    echo "The laravel command was not found; Herd ships it in ~/Library/Application Support/Herd/bin." >&2
    exit 1
fi

PACKAGE="$(php -r 'echo json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR)["name"];' "$REPO/composer.json")"

# The real cache dir is read before COMPOSER_HOME moves, otherwise it would point into the temporary home.
CACHE_DIR="$(composer config --global cache-dir)"
COMPOSER_HOME="$(mktemp -d)"
export COMPOSER_HOME
export COMPOSER_CACHE_DIR="$CACHE_DIR"
WORK="$(mktemp -d)"
SERVE_PID=""
# The one EXIT trap: a second one would replace this cleanup.
trap 'kill "${SERVE_PID:-}" 2>/dev/null || true; rm -rf "$COMPOSER_HOME" "$WORK/expected"' EXIT
composer config -g repositories."${PACKAGE#*/}" vcs "$REPO"

LOG="$WORK/install.log"
ANSWERS="$WORK/e2e-answers.json"

herd_lists() {
    # $1: the read-only herd listing (links or isolated); true when it has a row for the site.
    command -v herd > /dev/null && herd "$1" 2>/dev/null | grep -qiE "^\| *${NAME//./\\.}(\.[a-z]+)? *\|"
}

LINKED_BEFORE=false
if [ "$ENTRY" = herd ] && herd_lists links; then LINKED_BEFORE=true; fi

# The installer hides the hooks' output from a detected AI agent (it logs to a temporary file and prints JSON), so
# the agent markers are removed for this one command; the output then matches a run from a terminal.
AGENT_VARIABLES=(AI_AGENT CURSOR_AGENT GEMINI_CLI CODEX_SANDBOX CODEX_CI CODEX_THREAD_ID AUGMENT_AGENT OPENCODE_CLIENT
    OPENCODE AMP_CURRENT_THREAD_ID CLAUDECODE CLAUDE_CODE REPL_ID COPILOT_MODEL COPILOT_ALLOW_ALL COPILOT_GITHUB_TOKEN
    COPILOT_CLI ANTIGRAVITY_AGENT PI_CODING_AGENT KIRO_AGENT_PATH)
UNSET=()
for variable in "${AGENT_VARIABLES[@]}"; do UNSET+=(-u "$variable"); done

FAILED=0

install_app() {
    case "$ENTRY" in
        herd) (cd "$TARGET_PARENT" && env "${UNSET[@]}" laravel new "$NAME" --using="$PACKAGE:dev-$BRANCH" --no-interaction --no-ansi --database=sqlite --npm "${INSTALLER_FLAGS[@]}") ;;
        terminal) (cd "$TARGET_PARENT" && env "${UNSET[@]}" laravel new "$NAME" --using="$PACKAGE:dev-$BRANCH" --no-interaction --no-ansi --database=sqlite) ;;
        terminal-nodb) (cd "$TARGET_PARENT" && env "${UNSET[@]}" laravel new "$NAME" --using="$PACKAGE:dev-$BRANCH" --no-interaction --no-ansi) ;;
        composer) composer create-project "$PACKAGE:dev-$BRANCH" "$TARGET" --stability=dev --no-interaction ;;
        # RL-11: composer setup without its final skv:install, which is the CLI by design (§5.3.3).
        clone)
            git clone --quiet --branch "$BRANCH" "$REPO" "$TARGET"
            (cd "$TARGET" \
                && composer install --no-interaction \
                && php -r "file_exists('.env') || (copy('.env.example', '.env') && chmod('.env', 0600));" \
                && php artisan skv:bootstrap --no-interaction)
            ;;
    esac
}

echo "Installing $PACKAGE:dev-$BRANCH into $TARGET through the $ENTRY entry (log: $LOG)"
if [ "$ENTRY" = herd ]; then echo "Installer flags: ${INSTALLER_FLAGS[*]}; database answer: $DB"; fi
if ! install_app 2>&1 | tee "$LOG"; then
    echo "FAIL: the $ENTRY install exited with an error"
    FAILED=1
fi

check() {
    # $1: what is checked; the rest: the command that must succeed.
    local label="$1"
    shift

    if "$@" > /dev/null 2>&1; then
        echo "PASS: $label"
    else
        echo "FAIL: $label"
        FAILED=1
    fi
}

bootstrapped_once() { [ "$(grep -c 'skv:install' "$LOG" || true)" = 1 ]; }
migrated() { local status; status="$(cd "$TARGET" && php artisan migrate:status --no-ansi)" && ! grep -q 'Pending' <<< "$status"; }
boost_update_lines() {
    php -r '$c = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR); echo count(array_filter($c["scripts"]["post-update-cmd"] ?? [], fn ($line) => str_contains($line, "boost:update")));' "$TARGET/composer.json"
}
one_boost_update() { local lines; lines="$(boost_update_lines)" && [ "$lines" -le 1 ]; }
# F23: after skv:install exactly one with Boost (the Claude step adds one only when there is none), at most one without.
boost_updates_after_install() {
    local lines
    lines="$(boost_update_lines)" || return 1
    if [ "$WITH_BOOST" = true ]; then [ "$lines" = 1 ]; else [ "$lines" -le 1 ]; fi
}
boost_restored() {
    php -r '$c = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR); exit(($c["require-dev"]["laravel/boost"] ?? null) === "^2.10" ? 0 : 1);' "$TARGET/composer.json"
}

# The wizard check (§7.2). The token is read into TOKEN and compared, never printed; the token request reads its URL
# from stdin, so the token stays out of the process list too.
TOKEN=""
JAR="$WORK/cookies.txt"
token_path() { (cd "$TARGET" && php -r 'require "vendor/autoload.php"; echo App\Setup\SetupToken::PATH;'); }
# Env::writeVariables quotes the key skv:bootstrap writes (composer, clone); the installer's key:generate does not.
has_app_key() { grep -qE '^APP_KEY="?base64:' "$TARGET/.env"; }
# RL-5: curl reaches artisan serve under the host the wizard pins, whatever that name resolves to (empty: no override).
RESOLVE=""
web() { if [ -n "$RESOLVE" ]; then curl --resolve "$RESOLVE" "$@"; else curl "$@"; fi; }
home_redirects() {
    local location
    location="$(web -s -o /dev/null -w '%{redirect_url}' "$BASE/")" && [ -n "$TOKEN" ] && [[ "$location" == *"/welcome-setup?token=$TOKEN" ]]
}
token_accepted() {
    [ "$(printf 'url = "%s"\n' "$BASE/welcome-setup?token=$TOKEN" | web -s -K - -c "$JAR" -b "$JAR" -o /dev/null -w '%{http_code}')" = 302 ]
}
wizard_renders() {
    local code
    code="$(web -s -c "$JAR" -b "$JAR" -o "$WORK/wizard.html" -w '%{http_code}' "$BASE/welcome-setup")" \
        && [ "$code" = 200 ] && grep -q 'data-test="setup-wizard"' "$WORK/wizard.html"
}

wizard_check() {
    # $1: the base URL; $2: what the label adds, if anything.
    BASE="$1"
    local suffix="${2:-}" path
    rm -f "$JAR" "$WORK/wizard.html"
    path="$(token_path 2>/dev/null || true)"
    TOKEN=""
    if [ -n "$path" ] && [ -f "$TARGET/$path" ]; then TOKEN="$(tr -d '[:space:]' < "$TARGET/$path")"; fi

    check ".env has an APP_KEY$suffix" has_app_key
    check "the setup token exists$suffix" test -n "$TOKEN"
    check "/ redirects to /welcome-setup with the token$suffix" home_redirects
    check "/welcome-setup?token=… answers 302$suffix" token_accepted
    check "/welcome-setup answers 200 with the wizard$suffix" wizard_renders
    rm -f "$JAR" "$WORK/wizard.html"
}

free_port() { php -r '$s=stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s,false))[1];'; }
serving() { web -s -o /dev/null "$1/favicon.ico"; }
wait_until_serving() {
    for _ in $(seq 1 60); do
        if serving "$1"; then return 0; fi
        sleep 0.5
    done

    return 1
}

if [ "$ENTRY" = herd ]; then
    # What the installer received: a clone of the branch, so ignored local files (screenshots, caches) do not count.
    git clone --quiet --branch "$BRANCH" "$REPO" "$WORK/expected"

    echo
    echo "Phase 1 (§5.2, §7.2, §8)"
    check "skv:bootstrap ran once" bootstrapped_once
    check ".env has DB_CONNECTION=sqlite" grep -q '^DB_CONNECTION=sqlite' "$TARGET/.env"
    check "the SQLite database is migrated" migrated
    check "public/build/manifest.json exists" test -f "$TARGET/public/build/manifest.json"
    check "tests.yml is unchanged" diff "$WORK/expected/.github/workflows/tests.yml" "$TARGET/.github/workflows/tests.yml"
    check "tests/Pest.php is unchanged" diff "$WORK/expected/tests/Pest.php" "$TARGET/tests/Pest.php"
    check "tests/ is unchanged" diff -r "$WORK/expected/tests" "$TARGET/tests"
    check "post-update-cmd holds at most one boost:update" one_boost_update

    URL="http://$NAME.test"
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

# §7.2: the wizard renders without public/build.
if [[ "$ENTRY" =~ ^(composer|clone)$ ]]; then
    check "public/build/manifest.json is absent" test ! -e "$TARGET/public/build/manifest.json"
fi

wizard_check "$URL"

if [ -d "$TARGET/public/build" ]; then
    mv "$TARGET/public/build" "$WORK/build"
    wizard_check "$URL" " (without public/build)"
    mv "$WORK/build" "$TARGET/public/build"
fi

if [ "$ENTRY" != herd ]; then
    kill "$SERVE_PID" 2>/dev/null || true
    SERVE_PID=""
fi

if [ "$ENTRY" = herd ] && [ "$PHASE1_ONLY" = false ]; then
    # --db=pgsql answers as the e2e job's postgres-pro row does: PostgreSQL with every module.
    php -r '$a = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR); $a["https"] = $argv[3] === "true"; if ($argv[4] === "pgsql") { $a["database"] = "pgsql"; $a["modules"] = ["passkeys", "backups", "impersonation"]; } file_put_contents($argv[2], json_encode($a, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));' "$REPO/tests/Fixtures/e2e-answers.json" "$ANSWERS" "$HTTPS" "$DB"

    echo
    echo "Phase 2: skv:install (database=$DB, https=$HTTPS)"
    INSTALLED=true
    (cd "$TARGET" && php artisan skv:install --no-interaction --answers=@"$ANSWERS") || INSTALLED=false
    check "skv:install finished" "$INSTALLED"
    if [ "$WITH_BOOST" = true ]; then
        check "require-dev laravel/boost is ^2.10 (DEC-22 rule 5)" boost_restored
        check "post-update-cmd holds exactly one boost:update after skv:install" boost_updates_after_install
    else
        check "post-update-cmd holds at most one boost:update after skv:install" boost_updates_after_install
    fi

    echo
    echo "skv:doctor --json"
    DOCTOR_GREEN=true
    (cd "$TARGET" && php artisan skv:doctor --json) || DOCTOR_GREEN=false
    check "skv:doctor is green" "$DOCTOR_GREEN"
fi

echo
if [ "$FAILED" = 0 ]; then echo "Summary: every check passed."; else echo "Summary: some checks failed (see FAIL above)."; fi

echo
echo "Clean up when you are done (this script runs none of these):"
# %q quotes a name or path the shell would split or expand, so every printed line runs as it is (RL-6).
if [ "$ENTRY" = herd ]; then
    printf '  herd unsecure %q\n' "$NAME"
    if herd_lists isolated; then printf '  herd unisolate %q\n' "--site=$NAME"; fi
    if [ "$LINKED_BEFORE" = false ] && herd_lists links; then printf '  herd unlink %q\n' "$NAME"; fi
fi
printf '  rm -rf %q\n' "$TARGET"
printf '  rm -rf %q\n' "$WORK"

exit "$FAILED"
```

- [ ] **Step 10: Run the tests to verify they pass, and validate the workflow**

Run: `php artisan test --compact tests/Feature/Tooling tests/Feature/Backups tests/Feature/Setup/Wizard/WizardPageTest.php tests/Arch`

Expected: PASS (the prototype: 42 tests in `CiWorkflowTest` + `RequiredPostgresTest`; the whole SQLite suite, 2724 tests, green).

Validate the file with a second and third YAML parser and the shell script's syntax:

```bash
php -r 'require "vendor/autoload.php"; Symfony\Component\Yaml\Yaml::parseFile(".github/workflows/tests.yml"); echo "symfony ok\n";'
ruby -ryaml -e 'YAML.load_file(".github/workflows/tests.yml"); puts "psych ok"'
bash -n scripts/e2e-local.sh && /bin/bash -n scripts/e2e-local.sh && echo "bash ok"
command -v actionlint > /dev/null && actionlint .github/workflows/tests.yml || echo "actionlint not installed: skipped"
```

Expected: `symfony ok`, `psych ok`, `bash ok` (and a clean actionlint run if it is installed; it was not on the prototype's machine).

Then the green path of CD-10 on this machine (Herd's PostgreSQL 18 and its trusted `pg_dump` 18): `REQUIRE_POSTGRES=true composer test:pgsql`. Expected: PASS with the same skip count as the plain `composer test:pgsql` run in Step 13: here 'completes on PostgreSQL…' and 'backs up the database…' already run, so the flag must change nothing. Run `php artisan config:clear` afterwards.

- [ ] **Step 11: Verify by mutation**

Copy the file aside (`cp <file> <scratchpad>/<name>.bak`), make the change, run the named test, expect it to fail, then `cp` it back. Never `git stash`. All 19 ran in the prototype with the stated result.

| # | Mutation | Test that must fail |
|---|---|---|
| 1 | `tests.yml`: the browser job's checkout `uses: actions/checkout@<sha> # v7.0.1` → `uses: actions/checkout@v7.0.1` | 'pins every action by commit…' (`'actions/checkout@v7.0.1' matches PCRE pattern`) |
| 2 | the first `persist-credentials: false` → `true` | 'pins every action by commit…' (`a checkout in check keeps the token in .git/config`) |
| 3 | drop `timeout-minutes: 45` from `phase1` | 'pins every action by commit…' |
| 4 | swap the `passkeys` values of the sqlite-free and php85 rows | 'runs the free job with the passkeys module off and every other row with it on' (HEAD's regex test stays green under this mutation: proven) |
| 5 | the guard's `grep -nE 'flux-pro\|composer\.fluxui\.dev'` → `grep -nE 'flux-pro'` | 'refuses a committed Flux Pro…' with data set "the fluxui repository" |
| 6 | the guard's `exit(isset($c["extra"]["skv"]) ? 1 : 0);` → `exit(0);` | 'refuses a committed Flux Pro…' with data set "an extra namespace of the kit without flux-pro" |
| 7 | the guard's `git show HEAD:composer.json HEAD:composer.lock` → `cat composer.json composer.lock` | 'refuses a committed Flux Pro…' with data set "a clean commit under a working tree with Flux Pro" |
| 8 | the count step's `[ "$lines" = 1 ]` → `[ "$lines" -le 1 ]` | 'counts boost:update again…' with data set "Boost, none" |
| 9 | in both *Changes* regexes, `composer\.(json\|lock)$` → `composer\.json$` | 'runs phase1 and e2e on pull requests…' (`composer.lock` unmatched) |
| 10 | add `\|README` to the e2e regex only | 'runs phase1 and e2e on pull requests…' (the two filters differ) |
| 11 | drop `env: REQUIRE_POSTGRES: 'true'` from *Browser tests* | 'runs the PostgreSQL tests in CI as Herd's root user…' |
| 12 | move *Audit dependencies* back after *Enable Flux Pro* (or drop it) | 'enables Flux Pro in CI only when the secrets exist, after the audit…' |
| 13 | in the check job's *Enable Flux Pro*, put back `composer config --global http-basic.composer.fluxui.dev "$FLUX_USERNAME" "$FLUX_LICENSE_KEY"` instead of the two `COMPOSER_AUTH` lines | 'enables Flux Pro…' and 'hands the Flux credentials to Composer only in the steps that need them…' |
| 14 | drop the `env:` and the `COMPOSER_AUTH` block from e2e *skv:doctor* (back to `run: php artisan skv:doctor --json`) | 'hands the Flux credentials…' and 'maps the e2e job's Flux credentials only on the postgres-pro row' |
| 15 | wrap the phase-1 `tests/Pest.php` diff in `if [[ "$MATRIX_FLAGS" == *--pest* ]]; then …; fi` | 'checks tests/ and tests/Pest.php after laravel new on every e2e row' |
| 16 | drop the `sudo ln -sf … /usr/local/bin/pg_dump` line | 'installs a PostgreSQL 18 pg_dump…' |
| 17 | `e2e-local.sh`: drop the `elif [ "$VARIANT" = true ]` branch | 'installs the e2e job's variants with the local script…' (exit 1 "already exists" instead of 2; nothing is installed) |
| 18 | `e2e-local.sh`: `$a["modules"] = ["passkeys", "impersonation"];` | 'installs the e2e job's variants…' (the modules differ from the postgres-pro row's) |
| 19 | `RequiredPostgres::skips()`: `return ! $available();` | 'skips a PostgreSQL test only while REQUIRE_POSTGRES is unset' |

- [ ] **Step 12: CHANGELOG**

Under `## [Unreleased]`, `### Changed`, append:

```markdown
- CI: the Flux Pro licence reaches Composer only through `COMPOSER_AUTH` in the steps that need it, never a global `auth.json`, and `composer audit` runs before Flux Pro is installed; the PostgreSQL row and the browser job use Herd's `root` user and fail instead of skipping when PostgreSQL or a version-18 `pg_dump` is missing (`REQUIRE_POSTGRES`); the kit's own repository refuses a committed Flux Pro; every e2e row checks `tests/` and, after `skv:install`, the `boost:update` count; the setup jobs run on pull requests that touch anything they install; `scripts/e2e-local.sh` takes `--flags=` and `--db=` for the e2e job's variants.
```

- [ ] **Step 13: The gate**

```bash
composer fix
vendor/bin/phpstan analyse tests/Feature/Tooling/CiWorkflowTest.php tests/Feature/Tooling/RequiredPostgresTest.php tests/Fixtures/RequiredPostgres.php tests/Feature/Backups/BackupRunTest.php tests/Feature/Setup/Wizard/WizardPageTest.php tests/Browser/SetupWizardTest.php
composer check
composer test:pgsql
php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser
npm run build && composer test:browser
npm run build:setup && git diff --exit-code resources/setup/setup.css
php artisan config:clear
```

`composer test:browser` runs the PostgreSQL wizard test as before (Herd's server answers); `REQUIRE_POSTGRES=true composer test:browser` must give the same result. The task changes no setup view, so expect no stylesheet diff, and no `composer.lock` package change (CD-15).

- [ ] **Step 14: Commit**

```bash
git add .github/workflows/tests.yml scripts/e2e-local.sh tests/Feature/Tooling/CiWorkflowTest.php CHANGELOG.md
git commit -m "ci: scope the flux credentials, require postgresql and widen the setup filters

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

What only M8b's first runs can prove (record them in DEC-36's M8b addendum):
- the check job's pgsql row green with the `root`/trust service, the PGDG install on ubuntu-latest (noble) and the `/usr/local/bin/pg_dump` link, and `BackupRunTest` and 'completes on PostgreSQL…' running (not skipped) there for the first time;
- the browser job's PostgreSQL wizard test green against the new service (and A82: `*.localhost` on ubuntu);
- `skv:flux-pro`, `skv:install` and `skv:doctor` with `COMPOSER_AUTH` on the real fluxui repository (check job main row, e2e postgres-pro), and that no later step finds the key;
- the kit guard's `if: github.repository == …` evaluating true in the kit's repository (and its being skipped in a fork or generated app);
- the phpunit-no-boost row with the unconditional `tests/` diff (DEC-22's spike says `--phpunit` leaves `tests/` as shipped; today's installer is not re-proven), and the post-`skv:install` count on all three rows;
- the widened path filter on a real pull request.

---

### Task 14: The four kit skills and the Claude settings (§9; CD-5; F40, F41)

§9 promises four kit skills (`add-tenant-resource`, `add-permission`, `add-settings-page`, `write-tenant-test`) and a `.claude/settings.json` with the MCP servers Boost and Herd and an allow-list of the safe commands. No skill exists today (`.claude/skills/` holds only Boost's eight vendor skills, and it is git-ignored), the allow-list lacks the two commands `AGENTS.md` tells agents to run (`composer doctor`, `npm run build:setup`), and the settings enable no MCP server, so Claude Code asks about `.mcp.json`'s servers on first use. This task adds the skills as `.ai/skills/<name>/SKILL.md`, which Boost 2.10 publishes to `.claude/skills/` on `boost:install` / `boost:update` (`SkillComposer::discoverExplicitUserSkills()`, `vendor/laravel/boost/src/Install/SkillComposer.php:155`), adds the two allow rules and `enabledMcpjsonServers` with the two server names the Claude step writes into `.mcp.json` (`laravel-boost`, `herd`; `app/Setup/Steps/ClaudeStep.php:70-71`), and pins all of it in `AiConfigTest`.

Each skill is a short checklist that points at the `AGENTS.md` rules and at the real files to copy, rather than restating the rules. The skills ship to generated apps (they are in git and not export-ignored), where `ClaudeStep` runs `boost:install --skills` and publishes them; so they name no kit or site name and no milestone.

**Files:**
- Create:
  - `.ai/skills/add-tenant-resource/SKILL.md`
  - `.ai/skills/add-permission/SKILL.md`
  - `.ai/skills/add-settings-page/SKILL.md`
  - `.ai/skills/write-tenant-test/SKILL.md`
- Modify:
  - `.claude/settings.json` (two allow rules, `enabledMcpjsonServers`)
  - `boost.json` (`boost:update` appends the four skill names to `skills`)
  - `CHANGELOG.md`
- Test:
  - `tests/Feature/Tooling/AiConfigTest.php` (three new tests)

**Interfaces:**
- Consumes:
  - `Laravel\Boost\Install\SkillComposer::skills(): Collection<string, Skill>` (Boost 2.10.3; keyed by the frontmatter `name`; user skills from `.ai/skills/*` come last and carry `custom: true`)
  - `Laravel\Boost\Install\Skill` (public readonly `string $name`, `string $package`, `string $path`, `string $description`, `bool $custom`)
  - Boost's frontmatter rule (`SkillComposer::parseSkill()`): a `---` YAML block at the top with non-empty `name` and `description`; a skill without both is skipped silently
- Produces:
  - the skills `add-tenant-resource`, `add-permission`, `add-settings-page`, `write-tenant-test` (names equal their folders)
  - `.claude/settings.json`: `permissions.allow` gains `Bash(composer doctor)` (after `Bash(composer test:browser)`) and `Bash(npm run build:setup)` (after `Bash(npm run build)`); a new top-level `enabledMcpjsonServers: ["laravel-boost", "herd"]` before `enabledPlugins`
  - Task 15 adds one sentence to `.ai/skills/write-tenant-test/SKILL.md` (the `covers` bullet ids); Task 17 re-runs `AiConfigTest` after editing `CLAUDE.md`

Background for the implementer:
- **Why `SkillComposer` in the test.** It is the code `boost:install` and `boost:update` run, so the test proves that Boost finds each skill, reads its frontmatter and keys it by the folder's name, not merely that a file exists. A YAML error or a missing `description` makes Boost skip the skill without failing the command (it only records a parse failure), which a file-exists test would not notice.
- **Why the second test.** Skills go stale when a file or class they name moves. The test resolves every backticked repository path (`app/…`, `tests/…`, `routes/…`, …) and every `App\…` class, `::method()` or `::CONSTANT` the four files name; placeholders such as `<name>` are skipped.
- **`boost:update` changes `boost.json`.** It rewrites the `skills` list with every skill it installed, so the four names are appended; commit that file. It also regenerates the git-ignored `AGENTS.md` and `.claude/skills/`. It does not touch `CLAUDE.md`, `.mcp.json` or the guideline. Run it with `--no-interaction` so it does not prompt for newly discovered packages.
- **`enabledMcpjsonServers`** is Claude Code's project-settings list of `.mcp.json` servers to approve without the first-use prompt (`disabledMcpjsonServers` is its opposite). The names must be the `.mcp.json` keys: `laravel-boost` (written by `boost:install --mcp`) and `herd` (written by `ClaudeStep::registerHerdMcp()`), which also keeps the `mcp__herd__*` deny rules effective.
- `NameLeakTest` does not scan `.ai/`, but the skills ship to generated apps, so they never name the kit itself.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Tooling/AiConfigTest.php`: add the two imports after the `/** covers §9 */` docblock (the file has none yet), so the head reads

```php
<?php

declare(strict_types=1);

/** covers §9 */

use Laravel\Boost\Install\Skill;
use Laravel\Boost\Install\SkillComposer;

it('imports the Boost guidelines from CLAUDE.md', function (): void {
```

and append these three tests at the end of the file:

```php
it('publishes the four kit skills of §9 through Boost (CD-5)', function (): void {
    $skills = app(SkillComposer::class)->skills();

    foreach (['add-tenant-resource', 'add-permission', 'add-settings-page', 'write-tenant-test'] as $name) {
        $skill = $skills->get($name);

        expect($skill)->toBeInstanceOf(Skill::class, "Boost does not discover the {$name} skill")
            ->and($skill?->path)->toBe(base_path('.ai/skills/'.$name))
            ->and($skill?->custom)->toBeTrue()
            ->and(mb_strlen((string) $skill?->description))->toBeGreaterThan(80);
    }
});

it('names only files, classes and methods that exist in the kit skills (CD-5)', function (): void {
    $missing = [];

    foreach (glob(base_path('.ai/skills/*/SKILL.md')) ?: [] as $file) {
        preg_match_all('/`([^`\s]+)`/', (string) file_get_contents($file), $matches);

        foreach ($matches[1] as $token) {
            $exists = match (true) {
                preg_match('#^(?:app|config|database|lang|resources|routes|scripts|tests)/[\w./-]+$#', $token) === 1 => file_exists(base_path($token)),
                preg_match('/^(App\\\\[\w\\\\]+)(?:::(\w+)(?:\(\))?)?$/', $token, $parts) === 1 => match (true) {
                    ! class_exists($parts[1]) && ! trait_exists($parts[1]) && ! enum_exists($parts[1]) && ! interface_exists($parts[1]) => false,
                    ! isset($parts[2]) => true,
                    default => method_exists($parts[1], $parts[2]) || defined($parts[1].'::'.$parts[2]),
                },
                default => true,
            };

            if (! $exists) {
                $missing[] = basename(dirname($file)).': '.$token;
            }
        }
    }

    expect($missing)->toBe([]);
});

it('allows the doctor and the setup stylesheet build and enables the Boost and Herd MCP servers (§9)', function (): void {
    /** @var array{permissions: array{allow: list<string>}, enabledMcpjsonServers: list<string>} $settings */
    $settings = json_decode((string) file_get_contents(base_path('.claude/settings.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($settings['permissions']['allow'])->toContain('Bash(composer doctor)', 'Bash(npm run build:setup)')
        ->and($settings['enabledMcpjsonServers'])->toBe(['laravel-boost', 'herd']);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Tooling/AiConfigTest.php`

Expected: FAIL in three tests: 'publishes the four kit skills of §9 through Boost (CD-5)' with "Boost does not discover the add-tenant-resource skill"; 'names only files, classes and methods that exist in the kit skills (CD-5)' passes vacuously (no skill file yet, so nothing to resolve; it fails only under its mutation in Step 7); 'allows the doctor and the setup stylesheet build and enables the Boost and Herd MCP servers (§9)' with an undefined array key `enabledMcpjsonServers`. The five existing tests stay green.

- [ ] **Step 3: The four skills**

Create `.ai/skills/add-tenant-resource/SKILL.md`:

```markdown
---
name: add-tenant-resource
description: "Use when adding a model, table or resource that belongs to one organisation (it has an organisation_id): the migration, the model with BelongsToOrganisation and SoftDeletes, the tenant_models entry, the factory, the archive cascade and the purge, the policy, the pages that read it and its tests. Covers tenant-scoped CRUD on tenant hosts and any-host pages that read tenant rows."
---

# Add a tenant resource

A tenant resource is a table with an `organisation_id` whose rows only the organisation's own host may see. The rules
are in `AGENTS.md` › *Tenancy and authorization*; this skill is the checklist that applies them. Read those rules
first and follow them where this list is shorter.

## 1. Migration

- Create it with `php artisan make:migration --no-interaction`.
- `$table->foreignId('organisation_id')->constrained()->restrictOnDelete();` plus `$table->softDeletes();`. Restrict, not
  cascade: a purge deletes the rows itself (step 4), and the foreign key is the safety net.
- Index the columns the pages filter on, starting with `organisation_id`. No driver-specific SQL (`AGENTS.md` › kit rules).
- Never run `migrate`, `migrate:fresh` or `db:wipe` against the development database: tests use their own databases
  through `RefreshDatabase`.

## 2. Model

- `use BelongsToOrganisation, HasFactory, SoftDeletes;` (`App\Tenancy\Concerns\BelongsToOrganisation`). The trait adds
  the tenant scope, fills `organisation_id` from the bound tenant and refuses a row without one. Copy the shape of
  `app/Models/Invitation.php` (attributes, `@property` docblock, relations).
- Never make `organisation_id` fillable (`tests/Arch/TenancyRulesTest.php`).
- Add the class to `tenant_models` in `config/tenancy.php`. That list drives the archive cascade
  (`App\Tenancy\SoftDeleteCascade`), and `tests/Feature/Tenancy/TenantModelsTest.php` fails for a model with an
  `organisation_id` that is missing from it.

## 3. Factory

- `php artisan make:factory --no-interaction`. `'organisation_id' => Organisation::factory()`, and build related tenant
  rows in the same organisation (see `database/factories/InvitationFactory.php`).

## 4. Archive, restore and purge

- Archive and restore need nothing more: `SoftDeleteCascade` reads `tenant_models`.
- Purge does: add one query-builder delete with an explicit `where('organisation_id', …)` to
  `App\Actions\Organisations\PurgeOrganisation`, before the users are deleted, in the lock order of `AGENTS.md`
  (*a transaction locks rows in one order*). Never Eloquent's `forceDelete()` on a builder: it runs without the
  global scopes.

## 5. Authorization

- A permission per ability: see the `add-permission` skill. Never a permission string.
- Policy methods check the permission and the organisation: `$actor->can(Permission::…) && $actor->organisation_id ===
  $model->organisation_id` (see `app/Policies/InvitationPolicy.php`). A method named like an always-checked ability
  grants super admins itself (`App\Authorization\SuperAdminGate::ALWAYS_CHECKED`).
- Administrative actions take the acting user first and call `Gate::forUser($actor)->authorize(…)`.

## 6. Pages

- Administration pages go into `routes/admin.php`, inside its `auth` + `EnsureSecondFactor` group, with
  `->can(Permission::…)`. A tenant-host-only page sits in `Route::tenant()`, where the tenant scope does the
  filtering; an any-host page reads through `App\Tenancy\HostScope::read()`. Never `Tenant::bypass()` or
  `withoutGlobalScope()` in a Livewire page.
- Livewire components authorise in `mount()` and again before each action, keep `#[Locked]` ids, never an Eloquent
  model property, and re-resolve the model on every request.
- A sidebar entry goes into `App\Support\Navigation\Navigation::items()`. Every new named GET route goes into
  `matrixRoutes()` in `tests/Feature/Tenancy/RouteHostMatrixTest.php`, with its outcome in `routeMatrixOutcome()`.
- Audit writes go through `App\Actions\Audit\RecordActivity` with a new `App\Enums\ActivityEvent` case and its label.
- New copy goes into both `lang/nl.json` and `lang/en.json`.

## 7. Tests

Use the `write-tenant-test` skill. At least:
- the scope: a row of another organisation is invisible on this host and visible inside `Tenant::bypass()` (model
  `tests/Feature/Tenancy/TenantScopeTest.php`);
- the cascade: archive soft-deletes the rows with the organisation's timestamp, restore brings back only those
  (`tests/Feature/Organisations/OrganisationCascadeTest.php`);
- the purge deletes them (`tests/Feature/Organisations/ArchiveRestorePurgeTest.php`);
- the policy for each role, and the pages over HTTP on the tenant host and on a central host.

Then `composer fix`, `composer check`, `composer test:pgsql` and `composer test:browser`.
```

Create `.ai/skills/add-permission/SKILL.md`:

```markdown
---
name: add-permission
description: "Use when adding, renaming or removing a permission or gating a page, route, action or navigation entry behind a new ability: the Permission enum case with its label, description and group, the super-only decision, permissions:sync, the route can: middleware, the policy and the tests. Never a permission string."
---

# Add a permission

Permissions live in one place, the `App\Authorization\Permission` enum (`app/Authorization/Permission.php`). The rules
are in `AGENTS.md` › *Tenancy and authorization* (*Permissions*, *Super admin*, *A policy method named like an
always-checked ability*); this skill is the checklist.

## 1. The enum case

- Add a case named like the existing ones (`UsersView = 'users.view'`): `<area>.<action>`, TitleCase key.
- Give it a `label()`, a `description()` and a `group()` arm (`App\Authorization\PermissionGroup`; add a group case only
  for a new area). The roles index renders its badge from these.
- Decide `superOnly()`. A super-only permission never reaches the `organisation_admin` template or a custom role
  (`Permission::organisationAdmin()`, `Permission::assignableToCustomRoles()`). Platform-wide abilities (creating or
  deleting organisations, system health) are super-only.
- Put the label and the description into both `lang/nl.json` and `lang/en.json` (informal Dutch "je").

## 2. Sync

- `php artisan permissions:sync` (`App\Authorization\SyncPermissions`) creates the permission, keeps the global
  `organisation_admin` template on the catalogue minus the super-only permissions, strips super-only permissions
  from custom roles and prunes permissions that left the catalogue. It is idempotent. Production runs it on every
  deploy (README › production checklist), and `php artisan skv:doctor` reports drift
  (`App\Setup\Doctor\Checks\PermissionsCheck`).
- The `super_admin` role is a display badge and holds no permissions; super admins pass through `Gate::before`
  (`App\Authorization\SuperAdminGate`).

## 3. Use it

- Routes: `->can(Permission::…)` (`routes/admin.php`). A sidebar entry: the permission argument of its
  `App\Support\Navigation\NavigationItem` in `App\Support\Navigation\Navigation::items()`.
- Code: `$user->can(Permission::…)`, `Gate::forUser($actor)->authorize(…)` in actions, and policy methods that check it
  together with the organisation (see `app/Policies/InvitationPolicy.php`).
- Never write the permission's string value anywhere else, Blade included (`tests/Arch/AuthorizationRulesTest.php`).
- Switch the Spatie team only with `withPermissionsTeam($teamId, fn () => …)`.

## 4. Tests and docs

- `tests/Feature/Authorization/PermissionCatalogueTest.php` pins the number of permissions and the super-only list:
  update both, and the catalogue in the spec (§4.6).
- Its dataset over `Permission::cases()` checks the Dutch label, description and group of the new case.
- Test the gate for each role (`asMember()`, `asOrgAdmin()`, `asSuperAdmin()`) over HTTP, and the route's row in
  `tests/Feature/Tenancy/RouteHostMatrixTest.php` (`routeMatrixOutcome()`: the matrix member holds no permission).
- See the `write-tenant-test` skill for the helpers.

Then `composer fix`, `composer check`, `composer test:pgsql` and `composer test:browser`.
```

Create `.ai/skills/add-settings-page/SKILL.md`:

```markdown
---
name: add-settings-page
description: "Use when adding a page or section under Settings (the signed-in user's own account: profile, appearance, security and the like): the multi-file Livewire page under pages/settings, its route in routes/settings.php, the settings sub-navigation, the route × host matrix row, the copy in both language files and the tests."
---

# Add a settings page

Settings pages are the signed-in user's own account pages. They answer on every host the user may sign in on. The
rules are in `AGENTS.md` (kit rules, *Tenancy and authorization*, *UI*); this skill is the checklist.

## 1. The component

- `php artisan make:livewire pages::settings.<name> --no-interaction` creates
  `resources/views/pages/settings/<name>/<name>.php` and `<name>.blade.php` (multi-file, no ⚡; the config defaults
  to it). Add `declare(strict_types=1);` to the generated class file. Never a single-file component, never
  `$this->view()`: pass data with `with(): array` or `#[Computed]`.
- Give the class a `#[Title('…')]`, and read the user with `App\Concerns\ResolvesCurrentUser::currentUser()`.
  Model `resources/views/pages/settings/appearance/` (the smallest page) or `resources/views/pages/settings/profile/`
  (a form).
- The Blade view starts with `@include('partials.settings-heading')` and wraps its content in
  `<x-pages::settings.layout :heading="__('…')" :subheading="__('…')">`.
- A settings page is an any-host page: it reads tenant models only through `App\Tenancy\HostScope::read()`.
- An action that changes or reveals a second factor starts with `passwordConfirmationRequired()`
  (`App\Concerns\ConfirmsPasswords`); an action a member can repeat to send mail or test a secret uses
  `App\Support\RateLimits\UserActionLimit` in the component. Display preferences change only through
  `App\Actions\Settings\UpdateLocale` and `App\Actions\Settings\UpdateAppearance`.
- During an impersonation the impersonator acts as the user: hide what only the account owner may do (see the
  sessions section in `resources/views/pages/settings/security/security.blade.php`).

## 2. Route and navigation

- Add the route inside the existing group in `routes/settings.php` (`auth` + `EnsureSecondFactor`):
  `Route::livewire('settings/<name>', 'pages::settings.<name>')->name('<name>.edit');`. No `Route::tenant()` or
  `Route::central()`: settings answer on every host.
- Add the entry to the settings sub-navigation in `resources/views/pages/settings/layout.blade.php` (not
  `Navigation::items()`, which is the sidebar). The user menu keeps its own items.
- Add the route name to `matrixRoutes()` in `tests/Feature/Tenancy/RouteHostMatrixTest.php`; its outcome follows
  `profile.edit` (`ok` for a signed-in user on their own host, `login` for a guest).

## 3. Copy

- Every new `__()` key goes into both `lang/nl.json` and `lang/en.json`, in sorted order (informal Dutch "je").

## 4. Tests

Use the `write-tenant-test` skill. At least:
- the page over HTTP on a tenant host for a member and on the admin host for a super admin, and a guest sent to login;
- the current item in the sub-navigation (`tests/Feature/Settings/SettingsNavigationTest.php`);
- each action through `pageSnapshot()` + `callPageAction()`, so the middleware runs, with its refusals.

Then `composer fix`, `composer check`, `composer test:pgsql` and `composer test:browser`.
```

Create `.ai/skills/write-tenant-test/SKILL.md`:

```markdown
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
```

Facts the skills rely on, checked on main: `make:livewire pages::settings.<name>` writes `resources/views/pages/settings/<name>/<name>.php` + `.blade.php` without `declare(strict_types=1)` (hence the skill's line); the settings routes sit in `routes/settings.php`'s `auth` + `EnsureSecondFactor` group and answer on every host (`RouteHostMatrixTest`: `ok` on the own host, `login` for a guest); `PurgeOrganisation` deletes tenant rows with explicit organisation filters before the users (`app/Actions/Organisations/PurgeOrganisation.php:88-92`); `SyncPermissions` keeps the global `organisation_admin` template on `Permission::organisationAdmin()` and strips super-only permissions from team roles (`app/Authorization/SyncPermissions.php:28-47`); `PermissionCatalogueTest` pins "fifteen" permissions and the super-only list.

- [ ] **Step 4: The Claude settings**

`.claude/settings.json`: in `permissions.allow`, replace

```json
            "Bash(composer test:browser)",
            "Bash(composer install:*)",
```

with

```json
            "Bash(composer test:browser)",
            "Bash(composer doctor)",
            "Bash(composer install:*)",
```

and

```json
            "Bash(npm run build)",
            "Bash(npm ci)",
```

with

```json
            "Bash(npm run build)",
            "Bash(npm run build:setup)",
            "Bash(npm ci)",
```

then replace

```json
    },
    "enabledPlugins": {
```

with

```json
    },
    "enabledMcpjsonServers": [
        "laravel-boost",
        "herd"
    ],
    "enabledPlugins": {
```

`composer doctor` runs `php artisan skv:doctor --ansi` (read-only; `--fix` is not covered by the exact rule). The file stays valid JSON; `AiConfigTest`'s other tests read it with `JSON_THROW_ON_ERROR`.

- [ ] **Step 5: Publish the skills**

Run: `php artisan boost:update --no-interaction`

Expected: "Boost guidelines and skills updated successfully."; `.claude/skills/` (git-ignored) now holds the four skills next to the eight vendor skills, and `git diff boost.json` shows exactly:

```diff
-        "tailwindcss-development"
+        "tailwindcss-development",
+        "add-permission",
+        "add-settings-page",
+        "add-tenant-resource",
+        "write-tenant-test"
```

`git status` shows no change to `CLAUDE.md`, `.mcp.json` or `.ai/guidelines/skv3.md`.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Tooling/AiConfigTest.php tests/Arch/NameLeakTest.php`

Expected: PASS (8 tests in `AiConfigTest`).

- [ ] **Step 7: Verify by mutation**

Copy the file aside with `cp`, make the change, run the named test, expect it to fail, restore the file with `cp`. Never `git stash`.

| Mutation | Test that must fail |
|---|---|
| Move `.ai/skills` aside (`mv .ai/skills /tmp/…`, then back) | 'publishes the four kit skills…' ("Boost does not discover the add-tenant-resource skill") |
| In `add-permission/SKILL.md`, `name: add-permission` → `name: add-permissions` | 'publishes the four kit skills…' ("…the add-permission skill") |
| In `add-permission/SKILL.md`, `description:` → `summary:` | 'publishes the four kit skills…' (Boost skips a skill without a description) |
| In `add-tenant-resource/SKILL.md`, `app/Models/Invitation.php` → `app/Models/Invite.php` and `App\Tenancy\SoftDeleteCascade` → `App\Tenancy\SoftDeleteCascades` | 'names only files, classes and methods…' (lists both tokens) |
| Restore `.claude/settings.json` from `git show HEAD:.claude/settings.json` | 'allows the doctor…' (undefined key `enabledMcpjsonServers`) |
| Drop the `Bash(npm run build:setup)` line | 'allows the doctor…' |
| Drop `"herd"` from `enabledMcpjsonServers` | 'allows the doctor…' |

- [ ] **Step 8: CHANGELOG**

Under `## [Unreleased]`, `### Added`, append:

```markdown
- Four Claude Code skills for working on the kit, published by Boost: `add-tenant-resource`, `add-permission`, `add-settings-page` and `write-tenant-test`. The project settings now approve the Boost and Herd MCP servers and allow `composer doctor` and `npm run build:setup`.
```

- [ ] **Step 9: The gate, then commit**

Run the Global Constraints gate:
- `composer check`
- `composer test:pgsql`
- the modules-off run: `php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser`
- `npm run build && composer test:browser`
- `npm run build:setup && git diff --exit-code resources/setup/setup.css` (no setup change, so expect no diff)
- `php artisan config:clear`

```bash
git add .ai/skills/add-tenant-resource/SKILL.md .ai/skills/add-permission/SKILL.md \
    .ai/skills/add-settings-page/SKILL.md .ai/skills/write-tenant-test/SKILL.md \
    .claude/settings.json boost.json CHANGELOG.md tests/Feature/Tooling/AiConfigTest.php
git commit -m "feat: add the four kit skills and approve the boost and herd mcp servers

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

---

### Task 15: §4 bullet ids, the traceability script and the Appendix A R2 test (§4, §12 (3), §12 (5), Appendix A R2; CD-17; F54, F57)

Success criterion 3 asks for every item of §4 to be referenced by a test, and §4's intro promises "a script [that] lists §4 bullets without a test reference". Today the `covers` docblocks name only subsections (`§4.2`), so the 90 bullets of §4 have no ids and bullet-level coverage is unproven. This task gives every §4 bullet a frozen id `§4.x.n`, adds `scripts/traceability.php`, which reads the spec's §4 bullets and every test file's `covers` line and fails on any bullet no test cites, pins the script in a Tooling test (against the real spec and against small planted projects), and cites the bullet ids in the `covers` lines of 100 test files.

Criterion 5 asks for a regression test for every Appendix A finding; R2 (skv2 served `public/handleiding.html` with the demo accounts and their password) is the only one without one. This task adds `DemoCredentialsExposureTest`.

The gap count, measured on main + this task's citations in a prototype: **all 90 bullets have at least one test that checks their core; 0 are uncovered.** At clause level 21 bullets are partial (a clause of the bullet that no test would catch). Task 15 cites the ids where the existing tests are; the clause gaps are more than about ten bullets of new behaviour tests, so they are split off into Task 15b (CD-17), apart from the clauses Tasks 10 and 11 already close (listed in Task 15b).

**Files:**
- Create:
  - `scripts/traceability.php`
  - `tests/Feature/Tooling/TraceabilityTest.php`
  - `tests/Feature/Tooling/DemoCredentialsExposureTest.php`
- Modify:
  - `docs/superpowers/specs/2026-10-10-skv3-design.md` (§4 only: the intro paragraph and an id at the start of each of the 90 bullets)
  - the first-docblock `covers` line of the 100 test files in Step 5's table (that line only)
  - `.ai/skills/write-tenant-test/SKILL.md` (one bullet on citing the ids; Task 14's file)
- Test:
  - the two new Tooling tests; `AiConfigTest` (Task 14) checks that the skill's new path `scripts/traceability.php` exists

**Interfaces:**
- Consumes:
  - `Database\Seeders\DemoSeeder::accounts(?string $apex = null): list<array{email: string, role: string, host: string}>` and `DemoSeeder::PASSWORD` (`'password'`)
  - Task 14's `.ai/skills/write-tenant-test/SKILL.md` and `AiConfigTest` ('names only files, classes and methods that exist in the kit skills')
  - `Illuminate\Support\Facades\Process` (`Process::path(base_path())->run([PHP_BINARY, 'scripts/…'])`, as `PgsqlTestDatabaseScriptTest` runs the other PHP script; no `Toolchain`, no fake: the Tooling folder is outside the RL-12 guard)
- Produces:
  - spec ids `§4.0.1` … `§4.12.2` (Step 4's table), frozen: never renumbered, a new bullet takes the next free number of its subsection, a removed bullet's id is never reused
  - `php scripts/traceability.php [--spec=<file>] [--tests=<directory>]`: prints `Problem: …` and `Uncovered: §4.x.n <bullet text, cut at 100 characters>` lines, then `§4 traceability: <n> bullets, <n> cited by a test, <n> uncovered, <n> problems.`; exit 0 when nothing is uncovered and there are no problems, else 1. Problems: a §4 bullet without an id of its own subsection, an id used twice, a `covers` line that cites an id §4 does not have
  - the `covers` rule for bullet ids (CD-17): the `covers` lines of a `*Test.php` file's **first** docblock count; `§4.x.n` cites a bullet; a plain `§4.x` names the subsection and cites none of its bullets
  - Task 15b cites the ids of its new tests the same way; Task 17 records the convention in DEC-36 and the README

Background for the implementer:
- **Where the ids go.** At the start of each bullet: `- §4.2.3 Password reset. → The reset URL …`. Only the top-level `- ` lines between `## 4.` and `## 5.` are bullets (§4 has no nested ones). The script requires the id's subsection to be the `### 4.x` heading the bullet is under, so a bullet moved to another subsection must get a new id there.
- **Which docblock.** Every test file starts with its `/** covers … */` line (`RouteHostMatrixTest` has it as a ` * covers §6.2` line inside a longer first docblock; both forms count). A `covers` string later in the file, for example inside a fixture string of `TraceabilityTest`, does not count.
- **How the citations were chosen.** For each bullet, the strongest one to three existing test files that check it, read test by test (Step 4's table names them). Where a file had `§4.x` and now cites bullets of `§4.x`, the bullet ids replace `§4.x`; where it cites none of `§4.x`'s bullets, `§4.x` stays. The §4 tokens are sorted and placed where the first §4 token was; the other tokens (`§6.2`, `Appendix A W5, W14`, …) keep their order.
- **If an earlier task changed a covers line.** Tasks 1–14 add tests to some of these files but, as planned, change none of their first docblocks. If a line differs from Step 5's "Today" column anyway, keep that task's tokens and add the ids of the "After" column.
- **The R2 test reads `public/` without following `public/storage`** (the link to the uploads; Global Constraints) and reads `docs/handleiding.*`. The demo addresses come from `DemoSeeder::accounts()` on any apex, also in the manual's `&lt;apex&gt;` placeholder form. A pair is a demo address and the demo password on one line: the manual's line 751 says the password is in the setup summary without printing it, and its factory snippet `'password' => 'password'` names no address, so both pass. The README documents the demo password on purpose (§10) and is not served, so it is not scanned.
- **No kit name in tests.** `NameLeakTest` scans `tests/`, so the test files never spell the spec's file name or the kit's name; the script, outside `tests/`, holds the spec's path.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Tooling/TraceabilityTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4, §12 */

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/*
| scripts/traceability.php (§12 (3), CD-17) against the real spec and tests, and against small projects written to
| storage/framework/testing: a spec with a §4 section and a tests/ folder with one file per test.
*/

/** A spec whose §4 has two subsections, three bullets, and a §5 bullet the script must ignore. */
$spec = <<<'MD'
    # Spec

    ## 4. Feature inventory

    Every item is covered by a test.

    ### 4.1 Hosts
    - §4.1.1 The host decides the organisation.
    - §4.1.2 Unknown hosts answer 404.

    ### 4.2 Login
    - §4.2.1 Login is scoped per host.

    ## 5. Setup
    - Not a §4 bullet.
    MD;

/** The PHP of a test file whose first docblock is $docblock. */
$testFile = fn (string $docblock): string => "<?php\n\ndeclare(strict_types=1);\n\n{$docblock}\n\nit('works', function (): void {\n    expect(true)->toBeTrue();\n});\n";

/**
 * Writes $spec and the test files ($name => contents, under tests/) to a fresh project folder and runs the script on
 * them.
 *
 * @param  array<string, string>  $tests
 */
$run = function (string $spec, array $tests): ProcessResult {
    $directory = storage_path('framework/testing/traceability-'.Str::uuid());
    File::ensureDirectoryExists($directory.'/tests/Feature');
    File::put($directory.'/spec.md', $spec);

    foreach ($tests as $name => $contents) {
        File::put($directory.'/tests/Feature/'.$name, $contents);
    }

    try {
        return Process::path(base_path())->run([PHP_BINARY, 'scripts/traceability.php', '--spec='.$directory.'/spec.md', '--tests='.$directory.'/tests']);
    } finally {
        File::deleteDirectory($directory);
    }
};

it('finds every §4 bullet of the design spec cited by a test (§12 (3), CD-17)', function (): void {
    $result = Process::path(base_path())->run([PHP_BINARY, 'scripts/traceability.php']);

    expect($result->output())->toMatch('/^§4 traceability: (\d+) bullets, \1 cited by a test, 0 uncovered, 0 problems\.$/m')
        ->and($result->output())->not->toContain('Uncovered:')
        ->and($result->exitCode())->toBe(0);
});

it('passes when every bullet is cited, in one-line and multi-line covers docblocks (CD-17)', function () use ($run, $spec, $testFile): void {
    $result = $run($spec, [
        'HostsTest.php' => $testFile('/** covers §4.1.1, §4.1.2, §6.2 */'),
        'LoginTest.php' => $testFile("/**\n * The login rules.\n *\n * covers §4.2.1\n */"),
    ]);

    expect($result->output())->toContain('§4 traceability: 3 bullets, 3 cited by a test, 0 uncovered, 0 problems.')
        ->and($result->exitCode())->toBe(0);
});

it('lists a bullet no test cites and fails (CD-17)', function () use ($run, $spec, $testFile): void {
    $result = $run($spec, [
        'HostsTest.php' => $testFile('/** covers §4.1.1 */'),
        'LoginTest.php' => $testFile('/** covers §4.2.1 */'),
    ]);

    expect($result->output())->toContain('Uncovered: §4.1.2 Unknown hosts answer 404.')
        ->and($result->output())->toContain('3 bullets, 2 cited by a test, 1 uncovered, 0 problems.')
        ->and($result->exitCode())->toBe(1);
});

it('counts neither a subsection id, nor an id outside the first docblock, nor a file that is no test (CD-17)', function () use ($run, $spec, $testFile): void {
    $result = $run($spec, [
        'HostsTest.php' => $testFile('/** covers §4.1, §4.2.1 */'),
        'LoginTest.php' => $testFile("/** covers §6.2 */\n\n/** covers §4.1.1 */"),
        'Helpers.php' => $testFile('/** covers §4.1.2 */'),
    ]);

    expect($result->output())->toContain('Uncovered: §4.1.1 ')
        ->and($result->output())->toContain('Uncovered: §4.1.2 ')
        ->and($result->exitCode())->toBe(1);
});

it('reports a covers line that cites an id §4 does not have (CD-17)', function () use ($run, $spec, $testFile): void {
    $result = $run($spec, [
        'HostsTest.php' => $testFile('/** covers §4.1.1, §4.1.2, §4.2.1, §4.1.9 */'),
    ]);

    expect($result->output())->toContain('Problem: Feature/HostsTest.php: covers §4.1.9, which §4 does not have')
        ->and($result->output())->toContain('0 uncovered, 1 problems.')
        ->and($result->exitCode())->toBe(1);
});

it('reports a §4 bullet without an id, with another subsection\'s id or with an id used twice (CD-17)', function (string $bullet, string $problem) use ($run, $spec, $testFile): void {
    $result = $run(str_replace('- §4.2.1 Login is scoped per host.', $bullet, $spec), [
        'AllTest.php' => $testFile('/** covers §4.1.1, §4.1.2, §4.2.1 */'),
    ]);

    expect($result->output())->toContain('Problem: spec.md:'.$problem)
        ->and($result->exitCode())->toBe(1);
})->with([
    'no id' => ['- Login is scoped per host.', '12: a §4 bullet without the id of its subsection (§4.2.n)'],
    'another subsection' => ['- §4.1.3 Login is scoped per host.', '12: a §4 bullet without the id of its subsection (§4.2.n)'],
    'used twice' => ["- §4.2.1 Login is scoped per host.\n- §4.2.1 Logout ends the session.", '13: §4.2.1 is used twice'],
]);
```

Create `tests/Feature/Tooling/DemoCredentialsExposureTest.php`:

```php
<?php

declare(strict_types=1);

/** covers Appendix A R2, §10 */

use Database\Seeders\DemoSeeder;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/*
| The predecessor kit served its manual from public/handleiding.html, with the demo accounts and their password on it (Appendix A
| R2). The manual lives in docs/ (§10), nothing under public/ names a demo account, and the manual never puts a demo
| account next to the demo password. public/storage (the link to the uploads) is never read.
*/

/**
 * Whether $text names a demo account: the addresses DemoSeeder::accounts() derives, on any apex, also written with
 * the manual's `<apex>` placeholder.
 */
$namesDemoAccount = function (string $text): bool {
    foreach (DemoSeeder::accounts('apex.invalid') as $account) {
        $pattern = str_replace(preg_quote('apex.invalid', '/'), '(?:[\w-]+(?:\.[\w-]+)*|&lt;apex&gt;|<apex>)', preg_quote($account['email'], '/'));

        if (preg_match('/(?<![\w.+-])'.$pattern.'/i', $text) === 1) {
            return true;
        }
    }

    return false;
};

/** Whether $line pairs a demo account with the demo password. */
$pairsDemoCredentials = fn (string $line): bool => $namesDemoAccount($line)
    && preg_match('/\b'.preg_quote(DemoSeeder::PASSWORD, '/').'\b/i', $line) === 1;

/** @return list<SplFileInfo> */
$publicFiles = fn (): array => iterator_to_array(
    Finder::create()->files()->in(public_path())->exclude('storage')->ignoreDotFiles(false)->ignoreVCS(true),
    false,
);

it('serves no manual and no HTML page from public/ (Appendix A R2)', function () use ($publicFiles): void {
    $pages = collect($publicFiles())
        ->filter(fn (SplFileInfo $file): bool => in_array(strtolower($file->getExtension()), ['html', 'htm', 'md', 'markdown', 'pdf', 'docx'], true)
            || preg_match('/handleiding|manual/i', $file->getFilename()) === 1)
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect(is_file(base_path('docs/handleiding.html')))->toBeTrue()
        ->and($pages)->toBe([]);
});

it('names no demo account in any file under public/ (Appendix A R2)', function () use ($publicFiles, $namesDemoAccount): void {
    $hits = collect($publicFiles())
        ->filter(fn (SplFileInfo $file): bool => $namesDemoAccount($file->getContents()))
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($hits)->toBe([]);
});

it('never puts a demo account next to the demo password in the manual (Appendix A R2)', function () use ($pairsDemoCredentials): void {
    $manuals = glob(base_path('docs/handleiding.*')) ?: [];
    $hits = [];

    foreach ($manuals as $manual) {
        foreach (preg_split('/\R/', (string) file_get_contents($manual)) ?: [] as $number => $line) {
            if ($pairsDemoCredentials($line)) {
                $hits[] = basename($manual).':'.($number + 1);
            }
        }
    }

    expect($manuals)->not->toBeEmpty()
        ->and($hits)->toBe([]);
});

it('recognises the demo accounts and the pairs the predecessor kit published (Appendix A R2)', function () use ($namesDemoAccount, $pairsDemoCredentials): void {
    expect($namesDemoAccount('<td>superadmin@acme.test</td>'))->toBeTrue()
        ->and($namesDemoAccount('<code>admin@demo2.&lt;apex&gt;</code>'))->toBeTrue()
        ->and($namesDemoAccount('user@demo1.localhost'))->toBeTrue()
        ->and($namesDemoAccount('someone@acme.test, admin@example.com'))->toBeFalse()
        ->and($pairsDemoCredentials('<tr><td>superadmin@acme.test</td><td>password</td></tr>'))->toBeTrue()
        ->and($pairsDemoCredentials("'password' => 'password'"))->toBeFalse()
        ->and($pairsDemoCredentials('<code>user@demo1.&lt;apex&gt;</code>: het wachtwoord staat in de samenvatting'))->toBeFalse();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Tooling/TraceabilityTest.php tests/Feature/Tooling/DemoCredentialsExposureTest.php`

Expected: `TraceabilityTest` FAILS in all 8 tests: the script does not exist (`Could not open input file: scripts/traceability.php`, exit code 1, no summary line). `DemoCredentialsExposureTest` PASSES: nothing exposes the demo accounts today, so it is a regression test; Step 8 proves it fails on the R2 situation.

- [ ] **Step 3: The script**

Create `scripts/traceability.php`:

```php
<?php

declare(strict_types=1);

/*
 * Spec §4 traceability (§12 (3), CD-17): every bullet of §4 carries a frozen id `§4.x.n`, and every bullet is cited by
 * at least one test file's `covers` docblock line. Lists the bullets no test cites, the ids a covers line cites that
 * §4 does not have, and the §4 bullets without a valid id; exits 1 when there are any, 0 otherwise.
 *
 * Usage: php scripts/traceability.php [--spec=<file>] [--tests=<directory>]
 * Defaults: the design spec under docs/superpowers/specs and the tests/ folder. Relative paths are read from the
 * project root. A plain `§4.x` in a covers line names a subsection and cites none of its bullets.
 */

$root = dirname(__DIR__);
$options = getopt('', ['spec:', 'tests:']);
$resolve = fn (string $path): string => str_starts_with($path, '/') ? $path : $root.'/'.$path;
$specPath = $resolve(is_string($options['spec'] ?? null) ? $options['spec'] : 'docs/superpowers/specs/2026-10-10-skv3-design.md');
$testsPath = $resolve(is_string($options['tests'] ?? null) ? $options['tests'] : 'tests');

if (! is_file($specPath) || ! is_dir($testsPath)) {
    fwrite(STDERR, "Cannot read the spec [{$specPath}] or the tests folder [{$testsPath}].\n");

    exit(1);
}

/*
 * The §4 bullets: the top-level `- ` lines from `## 4.` up to the next `## ` heading, keyed by id. A bullet's id is
 * the `§4.x.n` that starts it, and x must be its `### 4.x` subsection.
 */
$bullets = [];
$problems = [];
$inSection = false;
$subsection = null;

foreach (preg_split('/\R/', (string) file_get_contents($specPath)) ?: [] as $number => $line) {
    if (preg_match('/^## /', $line) === 1) {
        $inSection = preg_match('/^## 4\. /', $line) === 1;

        continue;
    }

    if (! $inSection) {
        continue;
    }

    if (preg_match('/^### 4\.(\d+) /', $line, $heading) === 1) {
        $subsection = $heading[1];

        continue;
    }

    if (! str_starts_with($line, '- ')) {
        continue;
    }

    $where = basename($specPath).':'.($number + 1);

    if (preg_match('/^- §4\.(\d+)\.(\d+) (.+)$/u', $line, $bullet) !== 1 || $bullet[1] !== $subsection) {
        $problems[] = "{$where}: a §4 bullet without the id of its subsection (§4.{$subsection}.n)";

        continue;
    }

    $id = "§4.{$bullet[1]}.{$bullet[2]}";

    if (isset($bullets[$id])) {
        $problems[] = "{$where}: {$id} is used twice";

        continue;
    }

    $bullets[$id] = $bullet[3];
}

/*
 * The ids each test file cites: the `covers` lines of its first docblock.
 */
$cited = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testsPath, FilesystemIterator::SKIP_DOTS));

foreach ($files as $file) {
    if (! $file instanceof SplFileInfo || ! str_ends_with($file->getFilename(), 'Test.php')) {
        continue;
    }

    if (preg_match('#/\*\*(.*?)\*/#s', (string) file_get_contents($file->getPathname()), $docblock) !== 1) {
        continue;
    }

    $relative = substr($file->getPathname(), strlen(rtrim($testsPath, '/')) + 1);

    foreach (preg_split('/\R/', $docblock[1]) ?: [] as $line) {
        if (preg_match('/^\s*\*?\s*covers\s+(.+)$/u', $line, $covers) !== 1) {
            continue;
        }

        preg_match_all('/§4\.\d+\.\d+/u', $covers[1], $ids);

        foreach ($ids[0] as $id) {
            if (! isset($bullets[$id])) {
                $problems[] = "{$relative}: covers {$id}, which §4 does not have";

                continue;
            }

            $cited[$id][] = $relative;
        }
    }
}

$uncovered = array_diff_key($bullets, $cited);

foreach ($problems as $problem) {
    fwrite(STDOUT, "Problem: {$problem}\n");
}

foreach ($uncovered as $id => $text) {
    fwrite(STDOUT, "Uncovered: {$id} ".mb_strimwidth($text, 0, 100, '…')."\n");
}

fwrite(STDOUT, sprintf("§4 traceability: %d bullets, %d cited by a test, %d uncovered, %d problems.\n", count($bullets), count($bullets) - count($uncovered), count($uncovered), count($problems)));

exit($uncovered === [] && $problems === [] && $bullets !== [] ? 0 : 1);
```

It runs no external command (Task 2's exec ban scans `scripts/*.php`), reads only the two paths it is given, and is formatted by Pint like every PHP file (`composer check`'s `pint --test` covers `scripts/`); Larastan and Rector do not analyse `scripts/`, as for `scripts/create-pgsql-test-database.php`.

Run: `php scripts/traceability.php`

Expected: exit 1, one `Problem:` line per bullet, starting with `Problem: 2026-10-10-skv3-design.md:90: a §4 bullet without the id of its subsection (§4.0.n)`, then `§4 traceability: 0 bullets, 0 cited by a test, 0 uncovered, 90 problems.`

- [ ] **Step 4: Number the §4 bullets**

In `docs/superpowers/specs/2026-10-10-skv3-design.md`, replace §4's intro paragraph

```markdown
Every item must exist in skv3 and be covered by at least one test (traceability: each test file starts with a `covers §4.x` docblock line; a script lists §4 bullets without a test reference). "→" marks a change from skv2.
```

with

```markdown
Every item must exist in skv3 and be covered by at least one test. "→" marks a change from skv2.

Traceability (M8a, CD-17): every bullet starts with a frozen id `§4.x.n`. Ids are never renumbered: a new bullet takes the next free number of its subsection, and the id of a removed bullet is never reused. Each test file starts with a `covers` docblock line that cites the bullet ids it tests (a plain `§4.x` names the subsection and cites none of its bullets). `php scripts/traceability.php` lists the bullets no test cites and the cited ids §4 does not have, and exits 1 when there are any; `tests/Feature/Tooling/TraceabilityTest.php` runs it.
```

Then prefix the bullets. They are numbered in order within each `### 4.x` subsection, starting at 1; run this one-off from the project root (save it in the scratchpad, never commit it):

```php
<?php

declare(strict_types=1);

// One-off (not committed): prefix every top-level bullet of spec §4 with its frozen id §4.x.n (CD-17).
$path = 'docs/superpowers/specs/2026-10-10-skv3-design.md';
$lines = explode("\n", (string) file_get_contents($path));
$inSection = false;
$subsection = null;
$number = 0;

foreach ($lines as $index => $line) {
    if (str_starts_with($line, '## ')) {
        $inSection = str_starts_with($line, '## 4. ');

        continue;
    }

    if ($inSection && preg_match('/^### 4\.(\d+) /', $line, $heading) === 1) {
        $subsection = $heading[1];
        $number = 0;

        continue;
    }

    if ($inSection && str_starts_with($line, '- ')) {
        $number++;
        $lines[$index] = "- §4.{$subsection}.{$number} ".substr($line, 2);
    }
}

file_put_contents($path, implode("\n", $lines));
```

Expected: `git diff --stat` shows 90 changed bullet lines plus the intro (`+93 −91` or close), and no line outside §4 changes. The ids, each bullet's opening words, and the test files Step 5 makes cite it:

| Id | Bullet starts with | Cited by after this task |
|---|---|---|
| §4.0.1 | AppServiceProvider::configureDefaults(): … | `Feature/AppDefaultsTest.php`, `Feature/Console/CreateSuperAdminCommandTest.php`, `Feature/Console/ProductionGuardsTest.php` |
| §4.0.2 | Tests: now() is CarbonImmutable; migrate:fresh / db:wipe refused in … | `Feature/AppDefaultsTest.php` |
| §4.1.1 | Single database; organisation resolved from the subdomain: … | `Feature/Tenancy/ResolveTenantTest.php`, `Feature/Tenancy/HostScopingTest.php` |
| §4.1.2 | → Env: TENANCY_APEX_DOMAIN (default: APP_URL host), … | `Feature/Tenancy/TenantUrlTest.php`, `Feature/Setup/Doctor/ChecksTest.php` |
| §4.1.3 | Resolver binds the current organisation, sets the Spatie team id, … | `Feature/Tenancy/ResolveTenantTest.php`, `Feature/Tenancy/CurrentTenantTest.php` |
| §4.1.4 | Global organisation scope + BelongsToOrganisation trait (auto-fill … | `Feature/Tenancy/TenantScopeTest.php`, `Feature/Tenancy/TenantModelsTest.php` |
| §4.1.5 | Unscoped auth user provider for sessions, password broker, 2FA and … | `Feature/Tenancy/TenantScopeTest.php`, `Feature/Tenancy/StrictModeTest.php`, `Feature/Auth/PasswordResetHostTest.php` |
| §4.1.6 | Membership middleware: logs out inactive users / users on the wrong … | `Feature/Tenancy/MembershipTest.php`, `Feature/Impersonation/ImpersonationEnforcementTest.php` |
| §4.1.7 | Tenant URL helper with APP_URL's scheme and port, never the request's … | `Feature/Tenancy/TenantUrlTest.php` |
| §4.1.8 | Config: apex domain, admin subdomain, tenant models list (soft-delete … | `Feature/Tenancy/TenantUrlTest.php`, `Feature/Tenancy/TenantModelsTest.php`, `Feature/Organisations/OrganisationSlugTest.php` |
| §4.1.9 | Soft-deleting an organisation soft-deletes its tenant models with the … | `Feature/Organisations/OrganisationCascadeTest.php`, `Feature/Organisations/ArchiveRestorePurgeTest.php` |
| §4.1.10 | Host-only session cookies (SESSION_DOMAIN=null) → per-subdomain … | `Feature/Tenancy/MembershipTest.php` |
| §4.1.11 | Tenant name in the brand on tenant hosts. | `Feature/Layouts/BrandingTest.php` |
| §4.2.1 | Login scoped per host: an active user that belongs to the current host; … | `Feature/Auth/HostScopedLoginTest.php`, `Feature/Auth/PasskeyTest.php` |
| §4.2.2 | Registration and email verification disabled; invite-only. | `Feature/Auth/InviteOnlyTest.php` |
| §4.2.3 | Password reset. → The reset URL points at the user's own host … | `Feature/Auth/PasswordResetHostTest.php`, `Feature/Auth/PasswordResetTest.php` |
| §4.2.4 | TOTP 2FA: enable/confirm/disable with QR + setup key, password … | `Feature/Auth/EnsureSecondFactorTest.php`, `Feature/Settings/SecurityActionsTest.php`, `Feature/Settings/RecoveryCodesTest.php` |
| §4.2.5 | Passkeys (module, §6.8): register/list/delete, login from the login … | `Feature/Auth/PasskeyTest.php`, `Feature/Passkeys/PasskeysModuleTest.php`, `Feature/Tenancy/StrictModeTest.php` |
| §4.2.6 | Password confirmation flow. | `Feature/Settings/SecurityTest.php`, `Feature/Settings/SecurityActionsTest.php`, `Feature/Auth/LoginThrottleTest.php` |
| §4.2.7 | Rate limiters for login (email+IP, IP), two-factor, passkeys. → Count … | `Feature/Auth/LoginThrottleTest.php` |
| §4.2.8 | Lower-cased emails. | `Feature/Settings/ProfileUpdateTest.php`, `Feature/Users/UpdateUserTest.php`, `Unit/InvitationListTest.php` |
| §4.2.9 | → Changing the email address requires confirmation via a signed link to … | `Feature/Settings/EmailChangeTest.php` |
| §4.2.10 | Illuminate\Session\Middleware\AuthenticateSession on every … | `Feature/Auth/AuthenticateSessionTest.php`, `Feature/Setup/Doctor/ChecksTest.php` |
| §4.3.1 | Model: name (unique), slug (unique), description, soft deletes. → … | `Feature/Organisations/OrganisationModelTest.php`, `Feature/Organisations/OrganisationEditTest.php`, `Feature/Organisations/UpdateOrganisationTest.php` |
| §4.3.2 | Index (tenant host shows only its own), create, edit, soft delete. | `Feature/Organisations/OrganisationsIndexTest.php`, `Feature/Organisations/OrganisationWizardTest.php`, `Feature/Organisations/OrganisationEditTest.php` |
| §4.3.3 | → Archived view with restore and purge. restore = organisations.delete. … | `Feature/Organisations/ArchiveRestorePurgeTest.php`, `Feature/Organisations/OrganisationsIndexTest.php`, `Feature/Settings/AvatarTest.php` |
| §4.3.4 | Policy: view/update = permission + ownership; … | `Feature/Authorization/OrganisationPolicyTest.php`, `Feature/Organisations/UpdateOrganisationTest.php`, `Feature/Tenancy/RouteHostMatrixTest.php` |
| §4.3.5 | Slug rename redirects to the new subdomain (via the handoff token, … | `Feature/Organisations/OrganisationEditTest.php`, `Feature/Organisations/UpdateOrganisationTest.php` |
| §4.3.6 | organisation_admin role available for every organisation (mechanism per … | `Feature/Authorization/TemplateRoleTest.php`, `Feature/Organisations/CreateOrganisationTest.php` |
| §4.3.7 | Super-admin tenant picker. → Merged into the organisations index … | `Feature/Organisations/OrganisationsIndexTest.php` |
| §4.3.8 | → Slug rule: [a-z0-9-], 3–63 chars, no leading/trailing hyphen, not in … | `Feature/Organisations/OrganisationSlugTest.php`, `Feature/Organisations/OrganisationWizardTest.php` |
| §4.3.9 | → Organisation wizard: name, slug (auto, live availability, slug.apex … | `Feature/Organisations/OrganisationWizardTest.php`, `Feature/Organisations/CreateOrganisationTest.php` |
| §4.4.1 | Soft deletes, status enum (pending_activation, active, disabled), … | `Feature/Users/UserModelTest.php`, `Feature/Users/LocaleTest.php`, `Feature/Audit/UserActivityTest.php` |
| §4.4.2 | Super admin. → Explicit is_super_admin boolean, the only source of … | `Feature/Users/UserModelTest.php`, `Feature/Authorization/SuperAdminGateTest.php`, `Feature/Authorization/SetSuperAdminTest.php` |
| §4.4.3 | Index: search, status filter, role filter, 25 per page, organisation … | `Feature/Users/UsersIndexTest.php`, `Feature/Users/UserLifecycleTest.php`, `Feature/Authorization/UserPolicyTest.php` |
| §4.4.4 | Edit page doubles as show page: name, email, status, roles … | `Feature/Users/UsersEditTest.php`, `Feature/Users/UserLifecycleTest.php`, `Feature/Users/UpdateUserTest.php` |
| §4.4.5 | Policy: view, update, delete (not yourself), impersonation matrix. | `Feature/Authorization/UserPolicyTest.php`, `Feature/Impersonation/ImpersonationPolicyTest.php` |
| §4.4.6 | Factory states: pending, disabled, superAdmin, withTwoFactor … | `Feature/Users/UserModelTest.php`, `Feature/Auth/TwoFactorChallengeTest.php`, `Feature/Auth/InviteOnlyTest.php` |
| §4.4.7 | Email unique among non-deleted users: the users migration creates email … | `Feature/Users/UserModelTest.php`, `Feature/Users/UpdateUserTest.php` |
| §4.4.8 | → Avatar upload (§6.6): users.avatar_path, nullable. → users.appearance … | `Feature/Settings/AvatarTest.php`, `Feature/Settings/AppearanceTest.php` |
| §4.5.1 | Invite by email with locale and roles; user created as pending. → At … | `Feature/Invitations/InviteUserTest.php`, `Feature/Invitations/InvitePageTest.php`, `Feature/Invitations/InvitationMailTest.php` |
| §4.5.2 | Invitation: token, expires_at (configurable, default 7 days), … | `Feature/Invitations/InviteUserTest.php`, `Feature/Invitations/RemindInvitationTest.php` |
| §4.5.3 | → Accept URL built in one place: Invitation::acceptUrl() = … | `Feature/Invitations/InvitationMailTest.php`, `Feature/Invitations/AcceptInvitationTest.php`, `Browser/InvitationTest.php` |
| §4.5.4 | Actions: invite, accept, remind, cancel (cancel hard-deletes the … | `Feature/Invitations/CancelInvitationTest.php`, `Feature/Invitations/InviteUserTest.php`, `Feature/Authorization/PermissionsTeamTest.php` |
| §4.5.5 | Markdown mails: invitation, reminder. → Queued (ShouldQueue), … | `Feature/Invitations/InviteUserTest.php`, `Feature/Invitations/InvitationMailTest.php` |
| §4.5.6 | Pending list: inviter, relative expiry, remind (users.invite.resend), … | `Feature/Invitations/PendingInvitationsTest.php`, `Feature/Invitations/InvitationPolicyTest.php`, `Feature/Invitations/RemindInvitationTest.php` |
| §4.5.7 | Accept page: set password; expired state; resend button for authorised … | `Feature/Invitations/AcceptInvitationTest.php`, `Browser/InvitationTest.php` |
| §4.5.8 | Audit events: sent, reminder sent, accepted, cancelled. | `Feature/Invitations/InviteUserTest.php`, `Feature/Invitations/RemindInvitationTest.php`, `Feature/Invitations/AcceptInvitationTest.php` |
| §4.5.9 | Re-inviting an address after cancel works. | `Feature/Invitations/CancelInvitationTest.php` |
| §4.5.10 | → The invitations table is the single source of truth (no … | `Feature/Invitations/PendingInvitationsTest.php` |
| §4.5.11 | → "Copy invite link" button. → Super admins can invite from the admin … | `Feature/Invitations/PendingInvitationsTest.php`, `Feature/Invitations/InvitePageTest.php`, `Feature/Invitations/InvitationPolicyTest.php` |
| §4.5.12 | → Bulk invite built (§6.6, M7d). → Generic "this address can't be … | `Feature/Invitations/BulkInvitationsTest.php`, `Feature/Invitations/InviteUserTest.php` |
| §4.6.1 | 15 permissions: … | `Feature/Authorization/PermissionCatalogueTest.php`, `Feature/Authorization/SyncPermissionsTest.php` |
| §4.6.2 | Super-only: roles.manage, organisations.create, organisations.delete, … | `Feature/Authorization/PermissionCatalogueTest.php`, `Feature/Authorization/OrganisationPolicyTest.php`, `Feature/Roles/RoleActionsTest.php` |
| §4.6.3 | Global super_admin role (display badge only, §6.3); organisation_admin … | `Feature/Authorization/SyncPermissionsTest.php`, `Feature/Authorization/PermissionCatalogueTest.php`, `Feature/Authorization/SetSuperAdminTest.php` |
| §4.6.4 | Gate::before grants super admins everything except impersonate and the … | `Feature/Authorization/SuperAdminGateTest.php`, `Feature/Authorization/UserPolicyTest.php`, `Feature/Impersonation/ImpersonationPolicyTest.php` |
| §4.6.5 | Roles index with a "System roles" group carrying a "Protected" badge … | `Feature/Roles/RolesIndexTest.php`, `Feature/Roles/RoleActionsTest.php`, `Feature/Authorization/SyncPermissionsTest.php` |
| §4.6.6 | Pivot primary keys exclude team_id (null team allowed, skv2 D28). | `Feature/Authorization/TemplateRoleTest.php`, `Feature/Authorization/SetSuperAdminTest.php`, `Feature/Tenancy/TestingApiTest.php` |
| §4.6.7 | Route-level can: middleware and permission-gated navigation. | `Feature/Tenancy/RouteHostMatrixTest.php`, `Feature/Navigation/NavigationTest.php`, `Feature/Navigation/AdministrationLinksTest.php` |
| §4.6.8 | → Permission and Role backed enums; no permission string literals in … | `Arch/AuthorizationRulesTest.php`, `Feature/Authorization/PermissionCatalogueTest.php` |
| §4.6.9 | → Idempotent permissions:sync command. | `Feature/Authorization/SyncPermissionsTest.php` |
| §4.6.10 | → Role assignment only via an UpdateUserRoles action with full … | `Feature/Users/UpdateUserRolesTest.php`, `Feature/Users/UsersEditTest.php` |
| §4.6.11 | → Guards: last super admin, last organisation admin, removing your own … | `Feature/Users/UserLifecycleTest.php`, `Feature/Users/UpdateUserRolesTest.php`, `Feature/Authorization/SetSuperAdminTest.php` |
| §4.7.1 | Reason required (non-blank, ≤ 500 chars), … | `Feature/Impersonation/ImpersonationTest.php`, `Feature/Impersonation/ImpersonationPolicyTest.php` |
| §4.7.2 | Matrix: target never a super admin; super admin may impersonate anyone; … | `Feature/Impersonation/ImpersonationPolicyTest.php`, `Feature/Impersonation/ImpersonationUiTest.php`, `Feature/Impersonation/ImpersonationEnforcementTest.php` |
| §4.7.3 | Audit impersonation.start (with reason) / impersonation.stop; causer … | `Feature/Impersonation/ImpersonationTest.php` |
| §4.7.4 | Sticky banner with stop button; DELETE /impersonation. → "Stopped" … | `Feature/Impersonation/ImpersonationUiTest.php`, `Browser/ImpersonationTest.php` |
| §4.8.1 | Custom Activity model: causer rewrite during impersonation; … | `Feature/Impersonation/ImpersonationTest.php`, `Feature/Audit/ActivityLogTest.php`, `Feature/Audit/UserActivityTest.php` |
| §4.8.2 | Authentication subscriber: login, login failed (with email), logout, … | `Feature/Audit/AuthenticationActivityTest.php` |
| §4.8.3 | Viewer: date range, action, subject type, causer filters; 50 per page; … | `Feature/Audit/ActivityViewerTest.php` |
| §4.8.4 | Dashboard widget: last 10 entries. → Gated by activity_log.view; … | `Feature/DashboardTest.php` |
| §4.8.5 | → Real indexed organisation_id column (no FK), created_at index, IP + … | `Feature/Audit/ActivityLogTest.php`, `Feature/Organisations/ArchiveRestorePurgeTest.php`, `Feature/Audit/AuthenticationActivityTest.php` |
| §4.8.6 | → ActivityEvent enum with translated labels. | `Feature/Audit/ActivityLogTest.php` |
| §4.8.7 | → Also logged: organisation CRUD/purge, role/permission changes, role … | `Feature/Organisations/ArchiveRestorePurgeTest.php`, `Feature/Organisations/UpdateOrganisationTest.php`, `Feature/Roles/RoleActionsTest.php` |
| §4.8.8 | → Filters in the URL (#[Url]), resetPage() on filter change, detail … | `Feature/Audit/ActivityViewerTest.php`, `Feature/Audit/ActivityExportTest.php` |
| §4.8.9 | → Retention: published config/activitylog.php sets clean_after_days => … | `Feature/Audit/ActivityRetentionTest.php` |
| §4.9.1 | /up: framework liveness route (no auth; 200, or 500 on failure). … | `Feature/Health/HealthCheckEndpointTest.php` |
| §4.9.2 | /health-check: registered outside the web group (withRouting(then:)), … | `Feature/Health/HealthCheckEndpointTest.php`, `Feature/Health/HealthReportTest.php`, `Feature/Health/HealthChecksTest.php` |
| §4.9.3 | Backups (module, §6.8): database only, encrypted, monitored. → Off on … | `Feature/Backups/BackupConfigTest.php`, `Feature/Support/ModulesConfigTest.php`, `Feature/Backups/ConfigurePgDumpTest.php` |
| §4.9.4 | Scheduler (with onOneServer() + withoutOverlapping()): backup:clean … | `Feature/Scheduler/ScheduleTest.php`, `Feature/Audit/ActivityRetentionTest.php`, `Feature/Auth/HandoffTest.php` |
| §4.9.5 | Custom error pages 403/404/419/429/500/503. → Self-contained (§6.5). | `Feature/ErrorPagesTest.php` |
| §4.10.1 | Sidebar layout with permission-gated navigation, mobile header, user … | `Feature/Navigation/NavigationTest.php`, `Feature/Navigation/UserMenuTest.php`, `Feature/Navigation/FlashToastTest.php` |
| §4.10.2 | Settings: profile (name, email, language), appearance … | `Feature/Settings/ProfileUpdateTest.php`, `Feature/Settings/AppearanceTest.php`, `Feature/Settings/SecurityTest.php` |
| §4.10.3 | → No self-service account deletion (D33): M1 removes the starter kit's … | `Feature/Auth/InviteOnlyTest.php`, `Feature/Users/UserLifecycleTest.php`, `Feature/Users/UsersIndexTest.php` |
| §4.10.4 | Dashboard: for every signed-in user, an Organisation card (current … | `Feature/DashboardTest.php` |
| §4.10.5 | → Everything else in §6.5. | `Feature/ErrorPagesTest.php`, `Feature/Navigation/UserMenuTest.php`, `Feature/Layouts/BaseLayoutTest.php` |
| §4.11.1 | nl default, en fallback; per-user locale middleware. | `Feature/Localisation/SetLocaleTest.php`, `Feature/Users/LocaleTest.php` |
| §4.11.2 | → Guests get a locale from Accept-Language (changes skv2 D17); see §6.5. | `Feature/Localisation/SetLocaleTest.php`, `Feature/ErrorPagesTest.php` |
| §4.12.1 | Permission catalogue + super_admin always seeded (essentials seeder, … | `Feature/Authorization/SyncPermissionsTest.php`, `Feature/Setup/Steps/DatabaseStepTest.php` |
| §4.12.2 | Demo data: super admin, organisations demo1 and demo2, each with an … | `Feature/Seeders/DemoSeederTest.php`, `Feature/Console/InstallCommandTest.php`, `Feature/Console/CreateSuperAdminCommandTest.php` |

Run: `php scripts/traceability.php`

Expected: exit 1, `§4 traceability: 90 bullets, 0 cited by a test, 90 uncovered, 0 problems.`

- [ ] **Step 5: Cite the bullet ids in the covers lines**

In each file below, change only the text between `/** covers ` and ` */` on its first docblock line (in `RouteHostMatrixTest.php`: the text after ` * covers ` on that line of its first docblock).

| File | Today | After Task 15 |
|---|---|---|
| `tests/Arch/AuthorizationRulesTest.php` | `§4.6, §6.3, §7.2` | `§4.6.8, §6.3, §7.2` |
| `tests/Browser/ImpersonationTest.php` | `§4.7, §7.2` | `§4.7.4, §7.2` |
| `tests/Browser/InvitationTest.php` | `§4.5, §7.2` | `§4.5.3, §4.5.7, §7.2` |
| `tests/Feature/AppDefaultsTest.php` | `§4.0` | `§4.0.1, §4.0.2` |
| `tests/Feature/Audit/ActivityExportTest.php` | `§4.8, §6.6` | `§4.8.8, §6.6` |
| `tests/Feature/Audit/ActivityLogTest.php` | `§4.8, §6.2` | `§4.8.1, §4.8.5, §4.8.6, §6.2` |
| `tests/Feature/Audit/ActivityRetentionTest.php` | `§4.8, §4.9` | `§4.8.9, §4.9.4` |
| `tests/Feature/Audit/ActivityViewerTest.php` | `§4.8, §6.2` | `§4.8.3, §4.8.8, §6.2` |
| `tests/Feature/Audit/AuthenticationActivityTest.php` | `§4.8, §6.4` | `§4.8.2, §4.8.5, §6.4` |
| `tests/Feature/Audit/UserActivityTest.php` | `§4.4, §4.6, §4.8` | `§4.4.1, §4.6, §4.8.1` |
| `tests/Feature/Auth/AuthenticateSessionTest.php` | `§4.2` | `§4.2.10` |
| `tests/Feature/Auth/EnsureSecondFactorTest.php` | `§6.6, §6.8` | `§4.2.4, §6.6, §6.8` |
| `tests/Feature/Auth/HandoffTest.php` | `§6.4` | `§4.9.4, §6.4` |
| `tests/Feature/Auth/HostScopedLoginTest.php` | `§4.2` | `§4.2.1` |
| `tests/Feature/Auth/InviteOnlyTest.php` | `§4.2, §4.10` | `§4.2.2, §4.4.6, §4.10.3` |
| `tests/Feature/Auth/LoginThrottleTest.php` | `§4.2` | `§4.2.6, §4.2.7` |
| `tests/Feature/Auth/PasskeyTest.php` | `§4.2` | `§4.2.1, §4.2.5` |
| `tests/Feature/Auth/PasswordResetHostTest.php` | `§4.2` | `§4.1.5, §4.2.3` |
| `tests/Feature/Auth/PasswordResetTest.php` | `§4.2` | `§4.2.3` |
| `tests/Feature/Auth/TwoFactorChallengeTest.php` | `§4.2` | `§4.2, §4.4.6` |
| `tests/Feature/Authorization/OrganisationPolicyTest.php` | `§4.3, §4.6` | `§4.3.4, §4.6.2` |
| `tests/Feature/Authorization/PermissionCatalogueTest.php` | `§4.6, §6.3` | `§4.6.1, §4.6.2, §4.6.3, §4.6.8, §6.3` |
| `tests/Feature/Authorization/PermissionsTeamTest.php` | `§6.3` | `§4.5.4, §6.3` |
| `tests/Feature/Authorization/SetSuperAdminTest.php` | `§4.6, §6.3` | `§4.4.2, §4.6.3, §4.6.6, §4.6.11, §6.3` |
| `tests/Feature/Authorization/SuperAdminGateTest.php` | `§4.6, §6.3` | `§4.4.2, §4.6.4, §6.3` |
| `tests/Feature/Authorization/SyncPermissionsTest.php` | `§4.6, §6.3` | `§4.6.1, §4.6.3, §4.6.5, §4.6.9, §4.12.1, §6.3` |
| `tests/Feature/Authorization/TemplateRoleTest.php` | `§4.6, §6.3` | `§4.3.6, §4.6.6, §6.3` |
| `tests/Feature/Authorization/UserPolicyTest.php` | `§4.4, §4.6` | `§4.4.3, §4.4.5, §4.6.4` |
| `tests/Feature/Backups/BackupConfigTest.php` | `§4.9, §6.8` | `§4.9.3, §6.8` |
| `tests/Feature/Backups/ConfigurePgDumpTest.php` | `§4.9` | `§4.9.3` |
| `tests/Feature/Console/CreateSuperAdminCommandTest.php` | `§4.12` | `§4.0.1, §4.12.2` |
| `tests/Feature/Console/InstallCommandTest.php` | `§5.3.3, §5.3.2` | `§4.12.2, §5.3.3, §5.3.2` |
| `tests/Feature/Console/ProductionGuardsTest.php` | `§4.12, §5.2, §5.3.3` | `§4.0.1, §4.12, §5.2, §5.3.3` |
| `tests/Feature/DashboardTest.php` | `§4.10` | `§4.8.4, §4.10.4` |
| `tests/Feature/ErrorPagesTest.php` | `§4.9, §6.5` | `§4.9.5, §4.10.5, §4.11.2, §6.5` |
| `tests/Feature/Health/HealthCheckEndpointTest.php` | `§4.9` | `§4.9.1, §4.9.2` |
| `tests/Feature/Health/HealthChecksTest.php` | `§4.9` | `§4.9.2` |
| `tests/Feature/Health/HealthReportTest.php` | `§4.9` | `§4.9.2` |
| `tests/Feature/Impersonation/ImpersonationEnforcementTest.php` | `§4.1, §4.7, §6.4` | `§4.1.6, §4.7.2, §6.4` |
| `tests/Feature/Impersonation/ImpersonationPolicyTest.php` | `§4.7, §6.8, §7.2` | `§4.4.5, §4.6.4, §4.7.1, §4.7.2, §6.8, §7.2` |
| `tests/Feature/Impersonation/ImpersonationTest.php` | `§4.7, §6.4, §6.8` | `§4.7.1, §4.7.3, §4.8.1, §6.4, §6.8` |
| `tests/Feature/Impersonation/ImpersonationUiTest.php` | `§4.4, §4.7, §6.2` | `§4.4, §4.7.2, §4.7.4, §6.2` |
| `tests/Feature/Invitations/AcceptInvitationTest.php` | `§4.5, §6.2, Appendix A W5, W14` | `§4.5.3, §4.5.7, §4.5.8, §6.2, Appendix A W5, W14` |
| `tests/Feature/Invitations/BulkInvitationsTest.php` | `§6.6, §4.5, §6.2` | `§6.6, §4.5.12, §6.2` |
| `tests/Feature/Invitations/CancelInvitationTest.php` | `§4.4, §4.5` | `§4.4, §4.5.4, §4.5.9` |
| `tests/Feature/Invitations/InvitationMailTest.php` | `§4.5, §6.2, Appendix A W10, X11` | `§4.5.1, §4.5.3, §4.5.5, §6.2, Appendix A W10, X11` |
| `tests/Feature/Invitations/InvitationPolicyTest.php` | `§4.5, §4.6, §6.3` | `§4.5.6, §4.5.11, §4.6, §6.3` |
| `tests/Feature/Invitations/InvitePageTest.php` | `§4.5, §6.2` | `§4.5.1, §4.5.11, §6.2` |
| `tests/Feature/Invitations/InviteUserTest.php` | `§4.5, §4.6, Appendix A W8, W9, W10, W11, W13, W14` | `§4.5.1, §4.5.2, §4.5.4, §4.5.5, §4.5.8, §4.5.12, §4.6, Appendix A W8, W9, W10, W11, W13, W14` |
| `tests/Feature/Invitations/PendingInvitationsTest.php` | `§4.5, §6.2` | `§4.5.6, §4.5.10, §4.5.11, §6.2` |
| `tests/Feature/Invitations/RemindInvitationTest.php` | `§4.5, §6.2, Appendix A W5, X11` | `§4.5.2, §4.5.6, §4.5.8, §6.2, Appendix A W5, X11` |
| `tests/Feature/Layouts/BaseLayoutTest.php` | `§6.5` | `§4.10.5, §6.5` |
| `tests/Feature/Layouts/BrandingTest.php` | `§4.1, §6.5` | `§4.1.11, §6.5` |
| `tests/Feature/Localisation/SetLocaleTest.php` | `§4.11, §6.5` | `§4.11.1, §4.11.2, §6.5` |
| `tests/Feature/Navigation/AdministrationLinksTest.php` | `§6.5` | `§4.6.7, §6.5` |
| `tests/Feature/Navigation/FlashToastTest.php` | `§6.5` | `§4.10.1, §6.5` |
| `tests/Feature/Navigation/NavigationTest.php` | `§6.5, §6.8` | `§4.6.7, §4.10.1, §6.5, §6.8` |
| `tests/Feature/Navigation/UserMenuTest.php` | `§6.5` | `§4.10.1, §4.10.5, §6.5` |
| `tests/Feature/Organisations/ArchiveRestorePurgeTest.php` | `§4.3, §4.8` | `§4.1.9, §4.3.3, §4.8.5, §4.8.7` |
| `tests/Feature/Organisations/CreateOrganisationTest.php` | `§4.3, §4.5, §6.2` | `§4.3.6, §4.3.9, §4.5, §6.2` |
| `tests/Feature/Organisations/OrganisationCascadeTest.php` | `§4.1` | `§4.1.9` |
| `tests/Feature/Organisations/OrganisationEditTest.php` | `§4.3, §6.2, §6.4, §6.6` | `§4.3.1, §4.3.2, §4.3.5, §6.2, §6.4, §6.6` |
| `tests/Feature/Organisations/OrganisationModelTest.php` | `§4.3` | `§4.3.1` |
| `tests/Feature/Organisations/OrganisationSlugTest.php` | `§4.3, §6.2` | `§4.1.8, §4.3.8, §6.2` |
| `tests/Feature/Organisations/OrganisationWizardTest.php` | `§4.3, §6.2` | `§4.3.2, §4.3.8, §4.3.9, §6.2` |
| `tests/Feature/Organisations/OrganisationsIndexTest.php` | `§4.3, §6.2` | `§4.3.2, §4.3.3, §4.3.7, §6.2` |
| `tests/Feature/Organisations/UpdateOrganisationTest.php` | `§4.3, §4.8, §6.6` | `§4.3.1, §4.3.4, §4.3.5, §4.8.7, §6.6` |
| `tests/Feature/Passkeys/PasskeysModuleTest.php` | `§6.8` | `§4.2.5, §6.8` |
| `tests/Feature/Roles/RoleActionsTest.php` | `§4.6, §4.8, §6.8` | `§4.6.2, §4.6.5, §4.8.7, §6.8` |
| `tests/Feature/Roles/RolesIndexTest.php` | `§4.6, §6.2, §6.8` | `§4.6.5, §6.2, §6.8` |
| `tests/Feature/Scheduler/ScheduleTest.php` | `§4.9` | `§4.9.4` |
| `tests/Feature/Seeders/DemoSeederTest.php` | `§4.12` | `§4.12.2` |
| `tests/Feature/Settings/AppearanceTest.php` | `§4.10, §6.5` | `§4.4.8, §4.10.2, §6.5` |
| `tests/Feature/Settings/AvatarTest.php` | `§6.6, §4.3, §4.8, §6.7` | `§6.6, §4.3.3, §4.4.8, §4.8, §6.7` |
| `tests/Feature/Settings/EmailChangeTest.php` | `§4.2` | `§4.2.9` |
| `tests/Feature/Settings/ProfileUpdateTest.php` | `§4.10` | `§4.2.8, §4.10.2` |
| `tests/Feature/Settings/RecoveryCodesTest.php` | `§4.2` | `§4.2.4` |
| `tests/Feature/Settings/SecurityActionsTest.php` | `§4.2` | `§4.2.4, §4.2.6` |
| `tests/Feature/Settings/SecurityTest.php` | `§4.10` | `§4.2.6, §4.10.2` |
| `tests/Feature/Setup/Doctor/ChecksTest.php` | `§5.4` | `§4.1.2, §4.2.10, §5.4` |
| `tests/Feature/Setup/Steps/DatabaseStepTest.php` | `§5.3.3, §7.3` | `§4.12.1, §5.3.3, §7.3` |
| `tests/Feature/Support/ModulesConfigTest.php` | `§6.8` | `§4.9.3, §6.8` |
| `tests/Feature/Tenancy/CurrentTenantTest.php` | `§6.2` | `§4.1.3, §6.2` |
| `tests/Feature/Tenancy/HostScopingTest.php` | `§6.2` | `§4.1.1, §6.2` |
| `tests/Feature/Tenancy/MembershipTest.php` | `§4.1` | `§4.1.6, §4.1.10` |
| `tests/Feature/Tenancy/ResolveTenantTest.php` | `§4.1, §6.2` | `§4.1.1, §4.1.3, §6.2` |
| `tests/Feature/Tenancy/RouteHostMatrixTest.php` | `§6.2` | `§4.3.4, §4.6.7, §6.2` |
| `tests/Feature/Tenancy/StrictModeTest.php` | `§4.0, §6.2` | `§4.0, §4.1.5, §4.2.5, §6.2` |
| `tests/Feature/Tenancy/TenantModelsTest.php` | `§6.2, §7.2` | `§4.1.4, §4.1.8, §6.2, §7.2` |
| `tests/Feature/Tenancy/TenantScopeTest.php` | `§4.1, §6.2` | `§4.1.4, §4.1.5, §6.2` |
| `tests/Feature/Tenancy/TenantUrlTest.php` | `§4.1, §6.2` | `§4.1.2, §4.1.7, §4.1.8, §6.2` |
| `tests/Feature/Tenancy/TestingApiTest.php` | `§7.2` | `§4.6.6, §7.2` |
| `tests/Feature/Users/LocaleTest.php` | `§4.4, §4.11, §6.5` | `§4.4.1, §4.11.1, §6.5` |
| `tests/Feature/Users/UpdateUserRolesTest.php` | `§4.6, §6.3` | `§4.6.10, §4.6.11, §6.3` |
| `tests/Feature/Users/UpdateUserTest.php` | `§4.4` | `§4.2.8, §4.4.4, §4.4.7` |
| `tests/Feature/Users/UserLifecycleTest.php` | `§4.4, §4.6` | `§4.4.3, §4.4.4, §4.6.11, §4.10.3` |
| `tests/Feature/Users/UserModelTest.php` | `§4.4, §6.3` | `§4.4.1, §4.4.2, §4.4.6, §4.4.7, §6.3` |
| `tests/Feature/Users/UsersEditTest.php` | `§4.4, §6.2, §6.3` | `§4.4.4, §4.6.10, §6.2, §6.3` |
| `tests/Feature/Users/UsersIndexTest.php` | `§4.4, §6.2` | `§4.4.3, §4.10.3, §6.2` |
| `tests/Unit/InvitationListTest.php` | `§6.6` | `§4.2.8, §6.6` |

Run: `php scripts/traceability.php`

Expected: exit 0, `§4 traceability: 90 bullets, 90 cited by a test, 0 uncovered, 0 problems.`

- [ ] **Step 6: The skill**

`.ai/skills/write-tenant-test/SKILL.md`, section "1. The file": replace

```markdown
  `tests/Feature`. It starts with `declare(strict_types=1);` and a `/** covers §x.y */` docblock that names the spec
  sections it covers.
```

with

```markdown
  `tests/Feature`. It starts with `declare(strict_types=1);` and a `/** covers §x.y */` docblock that names the spec
  sections it covers.
- For spec §4, the covers line cites the bullet ids (`/** covers §4.5.2, §4.5.8, §6.2 */`); a plain `§4.5` cites
  no bullet. `php scripts/traceability.php` fails while a §4 bullet has no test that cites it.
```

Then run `php artisan boost:update --no-interaction` (it republishes the git-ignored `.claude/skills/`; `boost.json` does not change).

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Tooling tests/Arch`

Expected: PASS. `TraceabilityTest` 8 tests, `DemoCredentialsExposureTest` 4, `AiConfigTest` 8 (the skill's `scripts/traceability.php` resolves); `NameLeakTest` and `TestFileClassesTest` stay green (the new files spell no kit name and declare no class).

- [ ] **Step 8: Verify by mutation**

Copy the file aside with `cp`, make the change, run the named test, expect it to fail, restore with `cp`. Never `git stash`.

| Mutation | Test that must fail |
|---|---|
| `scripts/traceability.php`: drop ` \|\| $bullet[1] !== $subsection` | 'reports a §4 bullet without an id, with another subsection's id or with an id used twice' (dataset "another subsection") |
| `scripts/traceability.php`: read the whole file instead of its first docblock (`$docblock = [1 => (string) file_get_contents($file->getPathname())];` in place of the `preg_match('#/\*\*(.*?)\*/#s', …)` block) and let a covers line start with `/**` (`'/^\s*(?:\/\*\*\|\*)?\s*covers\s+(.+)$/u'`) | 'counts neither a subsection id, nor an id outside the first docblock, nor a file that is no test' |
| `scripts/traceability.php`: `str_ends_with($file->getFilename(), 'Test.php')` → `'.php'` | 'counts neither a subsection id, …' (`Helpers.php` cites §4.1.2) |
| `scripts/traceability.php`: drop the `$problems[] = "{$relative}: covers {$id}, which §4 does not have";` line | 'reports a covers line that cites an id §4 does not have' |
| `scripts/traceability.php`: `exit(0);` as the last line | 'lists a bullet no test cites and fails' and four more |
| `scripts/traceability.php`: `if (isset($bullets[$id])) {` → `if (false) {` | '… with an id used twice' (dataset "used twice") |
| `scripts/traceability.php`: never leave §4 (`$inSection = $inSection \|\| preg_match('/^## 4\. /', $line) === 1;`) | 'finds every §4 bullet of the design spec cited by a test' (the bullets of §5–§10 have no id) and three more |
| `tests/Feature/AppDefaultsTest.php`: covers `§4.0.1, §4.0.2` → `§4.0.1` | 'finds every §4 bullet of the design spec cited by a test' (`Uncovered: §4.0.2 …`) |
| `cp docs/handleiding.html public/handleiding.html` (then delete it) | 'serves no manual and no HTML page from public/' and 'names no demo account in any file under public/' |
| Add `public/robots-demo.txt` holding `Demo: superadmin@localhost` (then delete it) | 'names no demo account in any file under public/' |
| `docs/handleiding.html`: after `<h4>Demo-accounts</h4>` add `<p><code>superadmin@&lt;apex&gt;</code> / <code>password</code></p>` | 'never puts a demo account next to the demo password in the manual' |

`git status` after the mutations shows no file under `public/` and an unchanged `docs/handleiding.html`.

- [ ] **Step 9: The gate, then commit**

Run the Global Constraints gate:
- `composer check`
- `composer test:pgsql`
- the modules-off run: `php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser`
- `npm run build && composer test:browser` (two browser files changed their docblock only)
- `npm run build:setup && git diff --exit-code resources/setup/setup.css` (no setup change, so expect no diff)
- `php artisan config:clear`

Commit in two steps. First the ids, the script and the citations (the 100 test files of Step 5's table, by name):

```bash
git add docs/superpowers/specs/2026-10-10-skv3-design.md scripts/traceability.php \
    tests/Feature/Tooling/TraceabilityTest.php .ai/skills/write-tenant-test/SKILL.md \
    tests/Arch/AuthorizationRulesTest.php tests/Browser/ImpersonationTest.php tests/Browser/InvitationTest.php \
    tests/Feature/AppDefaultsTest.php tests/Feature/Audit/ActivityExportTest.php \
    tests/Feature/Audit/ActivityLogTest.php tests/Feature/Audit/ActivityRetentionTest.php \
    tests/Feature/Audit/ActivityViewerTest.php tests/Feature/Audit/AuthenticationActivityTest.php \
    tests/Feature/Audit/UserActivityTest.php tests/Feature/Auth/AuthenticateSessionTest.php \
    tests/Feature/Auth/EnsureSecondFactorTest.php tests/Feature/Auth/HandoffTest.php \
    tests/Feature/Auth/HostScopedLoginTest.php tests/Feature/Auth/InviteOnlyTest.php \
    tests/Feature/Auth/LoginThrottleTest.php tests/Feature/Auth/PasskeyTest.php \
    tests/Feature/Auth/PasswordResetHostTest.php tests/Feature/Auth/PasswordResetTest.php \
    tests/Feature/Auth/TwoFactorChallengeTest.php tests/Feature/Authorization/OrganisationPolicyTest.php \
    tests/Feature/Authorization/PermissionCatalogueTest.php \
    tests/Feature/Authorization/PermissionsTeamTest.php tests/Feature/Authorization/SetSuperAdminTest.php \
    tests/Feature/Authorization/SuperAdminGateTest.php tests/Feature/Authorization/SyncPermissionsTest.php \
    tests/Feature/Authorization/TemplateRoleTest.php tests/Feature/Authorization/UserPolicyTest.php \
    tests/Feature/Backups/BackupConfigTest.php tests/Feature/Backups/ConfigurePgDumpTest.php \
    tests/Feature/Console/CreateSuperAdminCommandTest.php tests/Feature/Console/InstallCommandTest.php \
    tests/Feature/Console/ProductionGuardsTest.php tests/Feature/DashboardTest.php \
    tests/Feature/ErrorPagesTest.php tests/Feature/Health/HealthCheckEndpointTest.php \
    tests/Feature/Health/HealthChecksTest.php tests/Feature/Health/HealthReportTest.php \
    tests/Feature/Impersonation/ImpersonationEnforcementTest.php \
    tests/Feature/Impersonation/ImpersonationPolicyTest.php tests/Feature/Impersonation/ImpersonationTest.php \
    tests/Feature/Impersonation/ImpersonationUiTest.php tests/Feature/Invitations/AcceptInvitationTest.php \
    tests/Feature/Invitations/BulkInvitationsTest.php tests/Feature/Invitations/CancelInvitationTest.php \
    tests/Feature/Invitations/InvitationMailTest.php tests/Feature/Invitations/InvitationPolicyTest.php \
    tests/Feature/Invitations/InvitePageTest.php tests/Feature/Invitations/InviteUserTest.php \
    tests/Feature/Invitations/PendingInvitationsTest.php tests/Feature/Invitations/RemindInvitationTest.php \
    tests/Feature/Layouts/BaseLayoutTest.php tests/Feature/Layouts/BrandingTest.php \
    tests/Feature/Localisation/SetLocaleTest.php tests/Feature/Navigation/AdministrationLinksTest.php \
    tests/Feature/Navigation/FlashToastTest.php tests/Feature/Navigation/NavigationTest.php \
    tests/Feature/Navigation/UserMenuTest.php tests/Feature/Organisations/ArchiveRestorePurgeTest.php \
    tests/Feature/Organisations/CreateOrganisationTest.php \
    tests/Feature/Organisations/OrganisationCascadeTest.php \
    tests/Feature/Organisations/OrganisationEditTest.php tests/Feature/Organisations/OrganisationModelTest.php \
    tests/Feature/Organisations/OrganisationSlugTest.php \
    tests/Feature/Organisations/OrganisationWizardTest.php \
    tests/Feature/Organisations/OrganisationsIndexTest.php \
    tests/Feature/Organisations/UpdateOrganisationTest.php tests/Feature/Passkeys/PasskeysModuleTest.php \
    tests/Feature/Roles/RoleActionsTest.php tests/Feature/Roles/RolesIndexTest.php \
    tests/Feature/Scheduler/ScheduleTest.php tests/Feature/Seeders/DemoSeederTest.php \
    tests/Feature/Settings/AppearanceTest.php tests/Feature/Settings/AvatarTest.php \
    tests/Feature/Settings/EmailChangeTest.php tests/Feature/Settings/ProfileUpdateTest.php \
    tests/Feature/Settings/RecoveryCodesTest.php tests/Feature/Settings/SecurityActionsTest.php \
    tests/Feature/Settings/SecurityTest.php tests/Feature/Setup/Doctor/ChecksTest.php \
    tests/Feature/Setup/Steps/DatabaseStepTest.php tests/Feature/Support/ModulesConfigTest.php \
    tests/Feature/Tenancy/CurrentTenantTest.php tests/Feature/Tenancy/HostScopingTest.php \
    tests/Feature/Tenancy/MembershipTest.php tests/Feature/Tenancy/ResolveTenantTest.php \
    tests/Feature/Tenancy/RouteHostMatrixTest.php tests/Feature/Tenancy/StrictModeTest.php \
    tests/Feature/Tenancy/TenantModelsTest.php tests/Feature/Tenancy/TenantScopeTest.php \
    tests/Feature/Tenancy/TenantUrlTest.php tests/Feature/Tenancy/TestingApiTest.php \
    tests/Feature/Users/LocaleTest.php tests/Feature/Users/UpdateUserRolesTest.php \
    tests/Feature/Users/UpdateUserTest.php tests/Feature/Users/UserLifecycleTest.php \
    tests/Feature/Users/UserModelTest.php tests/Feature/Users/UsersEditTest.php \
    tests/Feature/Users/UsersIndexTest.php tests/Unit/InvitationListTest.php
git commit -m "test: give every spec section 4 bullet a frozen id and fail on bullets no test cites

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Then the R2 test:

```bash
git add tests/Feature/Tooling/DemoCredentialsExposureTest.php
git commit -m "test: keep the manual and the demo credentials out of public

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

---

### Task 15b: Close the §4 clause gaps (§4.0.1, §4.1.2, §4.1.8, §4.2.4, §4.2.5, §4.3.1, §4.3.8, §4.4.7, §4.5.2, §4.5.10, §4.6.5, §4.6.10, §4.8.4, §4.8.5, §4.9.2, §4.10.1, §4.10.3, §4.11.1, §4.12.1, §4.12.2, §12 (3); CD-17; F54)

Task 15 proved that every §4 bullet has a test that checks its core, and cites it. Read clause by clause, 21 bullets still have a clause that no test would catch if it broke. This task adds the missing tests for 19 of them (two are closed by Tasks 10 and 11, see below), tests only: no production code changes. Each test fails under the named mutation of the production line it guards, and each file cites the bullet in its `covers` line, so `scripts/traceability.php` stays green.

Clauses not closed here, with the reason:
- §4.2.7 "count only failed attempts" for the two-factor and passkey limiters: Task 10 (A20).
- §4.3.5 "the old slug is free at once, not redirected": Task 11 (A43).
- §4.8.4 host scoping of the dashboard widget: Task 11 (A72); this task adds the order and the cross-scope causer.
- §4.2.5 "a passkey survives a slug rename" and "the origin resets on `TenantForgotten`": both follow from the fixed apex relying-party id, which `PasskeyTest` pins ('uses the lower-cased apex as the relying-party id…', 'signs a super admin in with a passkey on central and tenant hosts (P2)'); a rename test would re-test the same constant.
- §4.2.4 the recovery codes' show/hide toggle: client-side Alpine state with no server effect; a feature test could only assert markup.
- §4.9.2 "the System page shows the same cached results": both consumers call `HealthReport::cached()` (`HealthCheckController`, `pages/system/index/index.php:82`), whose cache `HealthReportTest` covers.
- §4.4.7 the index name `users_email_unique`: the behaviour (unique among non-deleted users) runs on both drivers in `UserModelTest`.

**Files:**
- Create:
  - `tests/Feature/ConfigDefaultsTest.php` (§4.1.2, §4.1.8, §4.3.8, §4.11.1)
  - `tests/Arch/MigrationRulesTest.php` (§4.4.7)
- Modify (tests only; each gets its new test(s), and the `covers` line where named):
  - `tests/Feature/AppDefaultsTest.php` (§4.0.1)
  - `tests/Feature/Settings/SecurityActionsTest.php` (§4.2.4)
  - `tests/Feature/Settings/SecurityTest.php` (§4.2.5; covers `§4.2.6, §4.10.2` → `§4.2.5, §4.2.6, §4.10.2`)
  - `tests/Feature/Organisations/CreateOrganisationTest.php` (§4.3.1; covers `§4.3.6, §4.3.9, §4.5, §6.2` → `§4.3.1, §4.3.6, §4.3.9, §4.5, §6.2`)
  - `tests/Feature/Invitations/InviteUserTest.php` (§4.5.2, §4.5.10; covers: add `§4.5.10` after `§4.5.8`)
  - `tests/Feature/Invitations/RemindInvitationTest.php` (§4.5.2)
  - `tests/Feature/Roles/RolesIndexTest.php` (§4.6.5)
  - `tests/Arch/AuthorizationRulesTest.php` (§4.6.10; covers `§4.6.8, §6.3, §7.2` → `§4.6.8, §4.6.10, §6.3, §7.2`; Task 2's file)
  - `tests/Feature/DashboardTest.php` (§4.8.4; Task 11's file)
  - `tests/Feature/Audit/ActivityLogTest.php` (§4.8.5)
  - `tests/Feature/Setup/Doctor/ChecksTest.php` (§4.9.2; covers `§4.1.2, §4.2.10, §5.4` → `§4.1.2, §4.2.10, §4.9.2, §5.4`)
  - `tests/Feature/Navigation/UserMenuTest.php` (§4.10.1)
  - `tests/Feature/Auth/InviteOnlyTest.php` (§4.10.3)
  - `tests/Feature/Console/ProductionGuardsTest.php` (§4.12.1; covers `§4.0.1, §4.12, §5.2, §5.3.3` → `§4.0.1, §4.12.1, §5.2, §5.3.3`)
  - `tests/Feature/Seeders/DemoSeederTest.php` (§4.12.2)

**Interfaces:**
- Consumes:
  - Task 15's bullet ids and `php scripts/traceability.php`
  - test helpers `onTenant()`, `onAdminHost()`, `asMember()`, `asOrgAdmin()`, `asSuperAdmin()`, `pageSnapshot()`, `callPageAction()`, `inviteMember()`, `kitSourceFiles()`, `filesMatching()`, `usePasskeysModule()`; `SecurityActionsTest`'s own `memberOnSecurityPage()` is not used (it makes a user with confirmed 2FA)
  - `App\Setup\Support\EnvFile::get(string $key): ?string`; `DemoSeeder::accounts()`, `DemoSeeder::PASSWORD`; `Permission::values()`
  - Laravel 13's `Schema::getIndexes()` / `Schema::getForeignKeys()` (`columns` key; same shape on SQLite and PostgreSQL)
- Produces: tests only; no global helper (`ConfigDefaultsTest`'s `$configWith` is file-local)

Background for the implementer:
- **Config defaults.** phpunit.xml forces `APP_URL`, `TENANCY_APEX_DOMAIN`, `TENANCY_ADMIN_SUBDOMAIN` (to the values the defaults give) and `APP_LOCALE=en`, so a broken default passes the suite. `ConfigDefaultsTest` re-reads a config file with its variables set or removed in `$_ENV`, `$_SERVER` and `putenv()` (Laravel's env repository reads all three) and restores them in a `finally`, as `PasskeyTest`'s `passkeyRelyingPartyIdWith()` does. It passes project-relative paths (`config/skv.php`), the form `NameLeakTest` allows.
- **The password rule** is checked with a ten-letter lower-case password: the lax rule (8 characters) accepts it, the strict rule refuses it on length and case before its `uncompromised()` check would call the breach API, so the test makes no network request.
- **The dashboard causer** must be created before `asOrgAdmin()` binds the tenant: `BelongsToOrganisation` fills a null `organisation_id` from the bound tenant on create, which would turn the super admin into an acme member (the prototype's first version passed under the mutation for that reason).
- **The roles index** renders the role editor's permission checkboxes after the tables, so the custom role's badge is asserted inside that role's table row, cut from the page by `Str::after()` / `Str::before('</tr>')`.
- **The migration rule** strips comments with `token_get_all()` first: `create_users_table.php`'s comment says "never an ALTER TABLE ... CONSTRAINT".
- **MailCheck** has no "info" status (pass, warn, fail, skipped); for a mailer other than `smtp` and `log` it passes with "Mail uses the :mailer mailer" and opens no socket, which is what §4.9.2's "else info" means. The test points the SMTP settings at the closed port 1, so a probe would fail it. Task 17 aligns §4.9.2's wording (see its notes).

- [ ] **Step 1: The config defaults (§4.1.2, §4.1.8, §4.3.8, §4.11.1)**

Create `tests/Feature/ConfigDefaultsTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.1.2, §4.1.8, §4.3.8, §4.11.1 */

use App\Setup\Support\EnvFile;

/*
| The defaults the config files ship with. phpunit.xml sets APP_URL, TENANCY_* and APP_LOCALE for the suite, so these
| tests read a config file again with the variables of their case set or removed, and restore them afterwards.
*/

/**
 * The array the config file at $file (relative to the project) returns while each variable in $variables holds its value, or is unset when it is null.
 *
 * @param  array<string, string|null>  $variables
 * @return array<string, mixed>
 */
$configWith = function (string $file, array $variables): array {
    $saved = [];

    try {
        foreach ($variables as $name => $value) {
            $saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            if ($value !== null) {
                $_ENV[$name] = $_SERVER[$name] = $value;
                putenv("{$name}={$value}");
            }
        }

        /** @var array<string, mixed> $config */
        $config = require base_path($file);

        return $config;
    } finally {
        foreach ($saved as $name => [$env, $server, $process]) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            if ($env !== null) {
                $_ENV[$name] = $env;
            }

            if ($server !== null) {
                $_SERVER[$name] = $server;
            }

            if ($process !== false) {
                putenv("{$name}={$process}");
            }
        }
    }
};

it('takes the apex from the APP_URL host and the admin subdomain "admin" unless they are set (§4.1.2)', function (array $variables, string $apex, string $admin) use ($configWith): void {
    $tenancy = $configWith('config/tenancy.php', $variables);

    expect([$tenancy['apex_domain'], $tenancy['admin_subdomain']])->toBe([$apex, $admin]);
})->with([
    'defaults' => [['APP_URL' => 'https://acme-platform.test:8443', 'TENANCY_APEX_DOMAIN' => null, 'TENANCY_ADMIN_SUBDOMAIN' => null], 'acme-platform.test', 'admin'],
    'set' => [['APP_URL' => 'https://acme-platform.test', 'TENANCY_APEX_DOMAIN' => 'example.org', 'TENANCY_ADMIN_SUBDOMAIN' => 'beheer'], 'example.org', 'beheer'],
    'an empty apex' => [['APP_URL' => 'https://acme-platform.test', 'TENANCY_APEX_DOMAIN' => '', 'TENANCY_ADMIN_SUBDOMAIN' => null], 'acme-platform.test', 'admin'],
    'no APP_URL' => [['APP_URL' => null, 'TENANCY_APEX_DOMAIN' => null, 'TENANCY_ADMIN_SUBDOMAIN' => null], 'localhost', 'admin'],
]);

it('reserves the seventeen slugs of the spec by default (§4.1.8, §4.3.8)', function () use ($configWith): void {
    expect($configWith('config/tenancy.php', [])['reserved_slugs'])->toBe([
        'admin', 'www', 'api', 'app', 'mail', 'smtp', 'ftp', 'static', 'assets', 'cdn', 'docs',
        'help', 'status', 'support', 'dashboard', 'login', 'test',
    ]);
});

it('speaks Dutch by default and falls back to English (§4.11.1)', function () use ($configWith): void {
    $variables = ['APP_LOCALE' => null, 'APP_FALLBACK_LOCALE' => null];
    $app = $configWith('config/app.php', $variables);
    $kit = $configWith('config/skv.php', $variables);
    $example = new EnvFile(base_path('.env.example'));

    expect([$app['locale'], $app['fallback_locale']])->toBe(['nl', 'en'])
        ->and($kit['locale'])->toBe(['default' => 'nl'])
        ->and([$example->get('APP_LOCALE'), $example->get('APP_FALLBACK_LOCALE')])->toBe(['nl', 'en']);
});
```

| Mutation | Test that must fail |
|---|---|
| `config/tenancy.php`: drop `env('TENANCY_APEX_DOMAIN') ?: ` | 'takes the apex from the APP_URL host…' (dataset "set") |
| `config/tenancy.php`: replace the `APP_URL` fallback `(is_string($appUrlHost) && $appUrlHost !== '' ? $appUrlHost : 'localhost')` with `'localhost'` | 'takes the apex…' (datasets "defaults", "an empty apex") |
| `config/tenancy.php`: `env('TENANCY_ADMIN_SUBDOMAIN', 'admin')` → `'admin'` | 'takes the apex…' (dataset "set") |
| `config/tenancy.php`: drop `'status'` from `reserved_slugs` | 'reserves the seventeen slugs of the spec by default' |
| `config/app.php`: `env('APP_FALLBACK_LOCALE', 'en')` → `env('APP_FALLBACK_LOCALE', 'nl')` | 'speaks Dutch by default and falls back to English' |
| `config/skv.php`: `'default' => env('APP_LOCALE', 'nl')` → `env('APP_LOCALE', 'en')` | 'speaks Dutch by default…' |
| `.env.example`: `APP_LOCALE=nl` → `APP_LOCALE=en` | 'speaks Dutch by default…' |

- [ ] **Step 2: The password rule (§4.0.1)**

`tests/Feature/AppDefaultsTest.php`: add the imports `use Illuminate\Support\Facades\Validator;` and `use Illuminate\Validation\Rules\Password;` after `use Illuminate\Support\Facades\DB;`, and append:

```php
it('applies the strict password rule everywhere but local development and the test suite (§4.0.1, CD-10)', function (string $environment, bool $accepted): void {
    app()->detectEnvironment(fn (): string => $environment);

    try {
        // Ten lower-case letters: the lax rule (8 characters) accepts it; the strict one refuses it on length and
        // case before it asks the breach API, so the test sends nothing over the network.
        $validator = Validator::make(['password' => 'abcdefghij'], ['password' => Password::default()]);

        expect($validator->passes())->toBe($accepted);
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
})->with([
    'production' => ['production', false],
    'staging' => ['staging', false],
    'local' => ['local', true],
    'testing' => ['testing', true],
]);
```

| Mutation | Test that must fail |
|---|---|
| `app/Providers/AppServiceProvider.php`: `! app()->environment(['local', 'testing'])` → `! app()->environment(['testing'])` | 'applies the strict password rule…' (dataset "local") |
| …the same condition → `app()->environment('staging')` | 'applies the strict password rule…' (dataset "production") |

- [ ] **Step 3: Two-factor setup and passkeys on the security page (§4.2.4, §4.2.5)**

`tests/Feature/Settings/SecurityActionsTest.php`: insert this test before 'returns to the security page once the password is confirmed again' (all imports exist):

```php
it('shows the QR code and the setup key once two-factor setup starts with a confirmed password (§4.2.4)', function (): void {
    $user = User::factory()->create();
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));
    actingAs($user);
    withSession(['auth.password_confirmed_at' => time()]);

    $component = Livewire::test('pages::settings.two-factor-setup-modal', ['requiresConfirmation' => true])
        ->call('startTwoFactorSetup');

    $secret = decrypt((string) $user->fresh()?->two_factor_secret);
    expect($secret)->toMatch('/^[A-Z2-7]{16,}$/')
        ->and($component->get('manualSetupKey'))->toBe($secret)
        ->and((string) $component->get('qrCodeSvg'))->toStartWith('<svg');
    $component->assertSee($secret)->assertSeeHtml((string) $component->get('qrCodeSvg'));
});
```

`tests/Feature/Settings/SecurityTest.php`: change the covers line to `/** covers §4.2.5, §4.2.6, §4.10.2 */` and append (its `beforeEach` turns on Fortify's passkeys feature with `confirmPassword`; all imports exist):

```php
it('lists the user\'s own passkeys and deletes one after the confirmation (§4.2.5)', function (): void {
    usePasskeysModule(true);
    $user = User::factory()->create();
    $colleague = User::factory()->create(['organisation_id' => $user->organisation_id]);
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));
    $laptop = $user->passkeys()->create(['name' => 'Work laptop', 'credential_id' => 'credential-1', 'credential' => []]);
    $colleague->passkeys()->create(['name' => 'Colleague key', 'credential_id' => 'credential-2', 'credential' => []]);
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $this->get(route('security.edit'))->assertOk()
        ->assertSee('Work laptop')
        ->assertDontSee('Colleague key')
        ->assertDontSee('No passkeys yet')
        ->assertSeeHtml('wire:click="confirmDelete('.$laptop->id.')"');

    $confirmation = callPageAction(pageSnapshot(route('security.edit'), 'pages::settings.security'), 'confirmDelete', [$laptop->id])->assertOk();
    /** @var array<string, mixed> $snapshot */
    $snapshot = json_decode((string) $confirmation->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR);
    $deleted = callPageAction($snapshot, 'deletePasskey')->assertOk();

    expect($user->passkeys()->count())->toBe(0)
        ->and($colleague->passkeys()->count())->toBe(1)
        ->and((string) $deleted->json('components.0.effects.html'))->toContain('No passkeys yet');
});
```

| Mutation | Test that must fail |
|---|---|
| `two-factor-setup-modal.php` `loadSetupData()`: `$this->manualSetupKey = decrypt($user->two_factor_secret);` → `$this->manualSetupKey = "";` | 'shows the QR code and the setup key…' |
| …`$this->qrCodeSvg = $user->twoFactorQrCodeSvg();` → `$this->qrCodeSvg = "";` | 'shows the QR code and the setup key…' |
| `two-factor-setup-modal.blade.php`: drop `{!! $qrCodeSvg !!}` | 'shows the QR code and the setup key…' |
| `security.php` `deletePasskey()`: drop `$deletePasskey($this->currentUser(), $passkey);` | 'lists the user's own passkeys and deletes one…' |
| `security.php` `loadPasskeys()`: `->latest()` → `->where('id', 0)` | 'lists the user's own passkeys…' |

Run `php artisan view:clear` after each component mutation is restored.

- [ ] **Step 4: Organisations and invitations (§4.3.1, §4.5.2, §4.5.10)**

`tests/Feature/Organisations/CreateOrganisationTest.php`: change the covers line to `/** covers §4.3.1, §4.3.6, §4.3.9, §4.5, §6.2 */` and append:

```php
it('creates the organisation without the second-factor rule (§4.3.1)', function () use ($createAcme): void {
    $superAdmin = asSuperAdmin();
    onAdminHost();

    $organisation = $createAcme($superAdmin);

    expect((bool) DB::table('organisations')->where('id', $organisation->id)->value('require_second_factor'))->toBeFalse()
        ->and($organisation->fresh()?->require_second_factor)->toBeFalse();
});
```

`tests/Feature/Invitations/InviteUserTest.php`: add `§4.5.10` to the covers line after `§4.5.8`, add `use Illuminate\Support\Facades\Schema;` after `use Illuminate\Support\Facades\Mail;`, and append:

```php
it('takes the expiry from the configured number of days (§4.5.2)', function (): void {
    config(['skv.invitations.expires_in_days' => 14]);
    // Whole seconds: the datetime columns store no microseconds.
    $this->freezeSecond();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);

    $invitation = inviteMember($organisation, asOrgAdmin($organisation));

    expect($invitation->expires_at->equalTo(now()->addDays(14)))->toBeTrue();
});

it('keeps no activation columns on users: the invitation is the only token store (§4.5.10, W13)', function (): void {
    $activationColumns = array_values(array_filter(
        Schema::getColumnListing('users'),
        fn (string $column): bool => str_starts_with($column, 'activation'),
    ));

    expect($activationColumns)->toBe([])
        ->and(Schema::hasColumns('invitations', ['token_hash', 'token', 'expires_at', 'reminder_sent_at', 'accepted_at', 'invited_by']))->toBeTrue();
});
```

`tests/Feature/Invitations/RemindInvitationTest.php`: append (its `beforeEach` uses the database queue):

```php
it('renews the expiry by the configured number of days (§4.5.2)', function (): void {
    $this->freezeSecond();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($organisation);
    $invitation = inviteMember($organisation, $admin);
    config(['skv.invitations.expires_in_days' => 3]);
    $this->travel(1)->days();

    app(RemindInvitation::class)->handle($admin, $invitation);

    expect($invitation->expires_at->equalTo(now()->addDays(3)))->toBeTrue();
});
```

The titles say "the configured number of days", not the config key: `NameLeakTest` refuses the key's prefix in a test title.

| Mutation | Test that must fail |
|---|---|
| `0001_01_00_000000_create_organisations_table.php`: `->default(false)` → `->default(true)` on `require_second_factor` | 'creates the organisation without the second-factor rule' |
| `InviteUser.php:131`: `now()->addDays((int) config('skv.invitations.expires_in_days', 7))` → `now()->addDays(7)` | 'takes the expiry from the configured number of days' |
| `RemindInvitation.php:61`: the same change | 'renews the expiry by the configured number of days' |
| `0001_01_01_000000_create_users_table.php`: add `$table->string('activation_token')->nullable();` after `$table->string('email');` | 'keeps no activation columns on users…' |

- [ ] **Step 5: Migrations, roles and role assignment (§4.4.7, §4.6.5, §4.6.10)**

Create `tests/Arch/MigrationRulesTest.php`:

```php
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
```

`tests/Feature/Roles/RolesIndexTest.php`: add `use Illuminate\Support\Str;` after `use App\Tenancy\Facades\Tenant;`, and append:

```php
it('groups the system roles under their own heading and shows each custom role\'s permissions as badges (§4.6.5)', function () use ($teamRoleIn): void {
    $organisation = Organisation::factory()->create();
    $teamRoleIn($organisation, 'Support Desk', Permission::UsersInviteResend);
    asOrgAdmin($organisation);

    $html = (string) $this->get(route('roles.index'))->assertOk()->getContent();
    // The custom role's table row: from its name to the end of the row (the role editor's checkboxes come later).
    $row = Str::before(Str::after($html, 'Support Desk'), '</tr>');

    expect($html)->toMatch('/data-flux-heading>\s*'.preg_quote(__('System roles'), '/').'\s*</')
        ->and($html)->toMatch('/data-flux-heading>\s*'.preg_quote(__('Custom roles'), '/').'\s*</')
        ->and($row)->toContain(Permission::UsersInviteResend->label())
        ->and($row)->toContain('data-flux-badge');
});
```

`tests/Arch/AuthorizationRulesTest.php` (after Task 2's rules): change the covers line to `/** covers §4.6.8, §4.6.10, §6.3, §7.2 */` and append:

```php
it('assigns and removes roles only in UpdateUserRoles and, for the super admin badge, SetSuperAdmin (§4.6.10, W1)', function (): void {
    // Spatie's role methods on a user; everything else goes through these two classes (AGENTS.md › Super admin).
    $pattern = '/->\s*(?:assignRole|syncRoles|removeRole)\s*\(/';
    $files = array_values(array_filter(
        kitSourceFiles('app', 'routes', 'resources/views', 'database', 'bootstrap', 'config'),
        fn (string $path): bool => ! str_starts_with($path, 'bootstrap/cache/'),
    ));

    expect(preg_match($pattern, '$user->assignRole($role)'))->toBe(1)
        ->and(preg_match($pattern, '$target->syncRoles([])'))->toBe(1)
        ->and(preg_match($pattern, "\$user\n    ->removeRole(\$role)"))->toBe(1)
        ->and(preg_match($pattern, '$user->hasRole($role)'))->toBe(0)
        ->and(filesMatching($files, $pattern))->toBe(['app/Actions/Users/UpdateUserRoles.php', 'app/Authorization/SetSuperAdmin.php']);
});
```

| Mutation | Test that must fail |
|---|---|
| `0001_01_01_000000_create_users_table.php`: the `CREATE UNIQUE INDEX users_email_unique …` statement → `DB::statement('ALTER TABLE users DROP CONSTRAINT users_email_unique');` | 'changes no table constraint with raw SQL in a migration' |
| `roles/index/index.blade.php`: drop `<flux:heading size="lg" level="2">{{ __('System roles') }}</flux:heading>` | 'groups the system roles under their own heading…' |
| `roles/index/index.blade.php`: drop the custom roles' `@foreach ($role->permissions as $permission) … @endforeach` badge loop | 'groups the system roles under their own heading…' |
| `app/Actions/Users/UpdateUser.php`: add the line `// $user->assignRole('x');` after `declare(strict_types=1);` | 'assigns and removes roles only in UpdateUserRoles…' |

- [ ] **Step 6: The audit log (§4.8.4, §4.8.5)**

`tests/Feature/DashboardTest.php` (after Task 11's changes; all imports exist): append

```php
it('lists the ten newest entries first and names a causer from outside the organisation (§4.8.4)', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    // Created before a tenant is bound, so it stays a super admin without an organisation.
    $operator = User::factory()->superAdmin()->create(['name' => 'Platform Operator']);
    $admin = asOrgAdmin($acme);
    $record = fn (User $causer) => app(RecordActivity::class)->handle(ActivityEvent::PasswordChanged, $admin, causer: $causer);
    $this->freezeSecond();

    $record(User::factory()->for($acme)->create(['name' => 'Oldest Causer']));

    foreach (range(1, 9) as $minute) {
        $this->travel(1)->minutes();
        $record(User::factory()->for($acme)->create(['name' => sprintf('Causer %02d', $minute)]));
    }

    $this->travel(1)->minutes();
    $record($operator);

    expect($operator->organisation_id)->toBeNull();
    $this->get(route('dashboard'))->assertOk()
        ->assertSeeInOrder(['Platform Operator', ...array_map(fn (int $minute): string => sprintf('Causer %02d', $minute), range(9, 1))])
        ->assertDontSee('Oldest Causer');
});
```

`tests/Feature/Audit/ActivityLogTest.php`: add `use Illuminate\Support\Facades\Schema;` after `use Illuminate\Support\Facades\Route;`, and append:

```php
it('indexes organisation_id and created_at and gives organisation_id no foreign key (§4.8.5)', function (): void {
    $indexed = array_map(fn (array $index): array => $index['columns'], Schema::getIndexes('activity_log'));
    $foreignKeys = array_map(fn (array $foreignKey): array => $foreignKey['columns'], Schema::getForeignKeys('activity_log'));

    expect($indexed)->toContain(['organisation_id'], ['created_at'], ['organisation_id', 'created_at'])
        ->and($foreignKeys)->not->toContain(['organisation_id']);
});
```

| Mutation | Test that must fail |
|---|---|
| `dashboard.php` `recentActivity()`: `orderByDesc('created_at')->orderByDesc('id')` → `orderBy('created_at')->orderBy('id')` | 'lists the ten newest entries first…' |
| `dashboard.php`: `$names = UserNames::of($causerIds);` → `$names = User::query()->whereKey($causerIds)->pluck('name', 'id')->mapWithKeys(fn (mixed $name, mixed $id): array => [(int) $id => (string) $name])->all();` (the tenant-scoped read) | 'lists the ten newest entries first…' ("Platform Operator" missing) |
| `0001_01_01_000005_create_activity_log_table.php`: drop `->index()` from `organisation_id` | 'indexes organisation_id and created_at…' |
| …the same column → `$table->foreignId('organisation_id')->nullable()->index()->constrained()->nullOnDelete();` | 'indexes organisation_id and created_at…' |

- [ ] **Step 7: Ops, the shell and seeding (§4.9.2, §4.10.1, §4.10.3, §4.12.1, §4.12.2)**

`tests/Feature/Setup/Doctor/ChecksTest.php`: change the covers line to `/** covers §4.1.2, §4.2.10, §4.9.2, §5.4 */` and insert before 'looks for pg_dump only while the backups module is on' (all imports exist):

```php
it('names a mailer other than smtp or log without probing a port (§4.9.2)', function (): void {
    // Port 1 on the loopback refuses every connection: a probe there would fail the check.
    new EnvFile($this->sandbox->path('.env'))->set(['MAIL_MAILER' => 'postmark', 'MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => '1']);

    expect(new MailCheck()->run($this->context))->toEqual(CheckResult::pass(__('Mail uses the :mailer mailer', ['mailer' => 'postmark'])));
});
```

`tests/Feature/Navigation/UserMenuTest.php`: append

```php
it('gives small screens a header with the sidebar toggle and the user menu (§4.10.1)', function (): void {
    asMember(Organisation::factory()->create(['slug' => 'acme']));

    $html = (string) $this->get(route('dashboard'))->assertOk()->getContent();
    preg_match('/<header\b[^>]*\blg:hidden\b.*?<\/header>/s', $html, $header);

    expect($header)->not->toBeEmpty()
        ->and($header[0] ?? '')->toContain('data-flux-sidebar-toggle')
        ->and($header[0] ?? '')->toContain('aria-label="'.e(__('Toggle sidebar')).'"')
        ->and($header[0] ?? '')->toContain('data-test="logout-button"');
});
```

`tests/Feature/Auth/InviteOnlyTest.php`: append

```php
it('deletes users only from the users index, never the signed-in user (§4.10.3, D33)', function (): void {
    $files = kitSourceFiles('app', 'resources/views', 'routes');
    // The signed-in user, however a page reaches it, followed by a delete.
    $deletesSelf = '/(?:auth\(\)->user\(\)|Auth::user\(\)|\$request->user\(\)|\$this->currentUser\(\)|\$this->user\(\))\??->(?:delete|forceDelete)\(/';

    expect(preg_match($deletesSelf, '$this->currentUser()->delete()'))->toBe(1)
        ->and(preg_match($deletesSelf, 'auth()->user()?->forceDelete()'))->toBe(1)
        ->and(preg_match($deletesSelf, '$deleteUser->handle($this->currentUser(), $target)'))->toBe(0)
        ->and(filesMatching($files, $deletesSelf))->toBe([])
        ->and(filesMatching($files, '/\bDeleteUser\b/'))->toBe(['app/Actions/Users/DeleteUser.php', 'resources/views/pages/users/index/index.php']);
});
```

`tests/Feature/Console/ProductionGuardsTest.php`: change the covers line to `/** covers §4.0.1, §4.12.1, §5.2, §5.3.3 */`, add `use App\Authorization\Permission;` and `use App\Authorization\Role;` before `use App\Models\Organisation;`, and append:

```php
it('seeds the permission catalogue and the system roles in every environment (§4.12.1)', function (string $environment): void {
    DB::table('roles')->whereNull('team_id')->delete();
    DB::table('permissions')->delete();
    app()['env'] = $environment;

    $exit = Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

    expect($exit)->toBe(0)
        ->and(DB::table('permissions')->orderBy('name')->pluck('name')->all())->toBe(collect(Permission::values())->sort()->values()->all())
        ->and(DB::table('roles')->whereNull('team_id')->orderBy('name')->pluck('name')->all())->toBe([Role::OrganisationAdmin->value, Role::SuperAdmin->value]);
})->with(['local', 'testing', 'staging', 'production']);
```

`tests/Feature/Seeders/DemoSeederTest.php`: append

```php
it('gives every demo account the shared password "password" (§4.12.2)', function (): void {
    $this->seed(DemoSeeder::class);

    $passwords = Tenant::bypass(fn (): array => User::query()->whereIn('email', array_column(DemoSeeder::accounts(), 'email'))->pluck('password', 'email')->all());

    expect(DemoSeeder::PASSWORD)->toBe('password')
        ->and(array_keys($passwords))->toEqualCanonicalizing(array_column(DemoSeeder::accounts(), 'email'));

    foreach ($passwords as $email => $hash) {
        expect(Hash::check('password', (string) $hash))->toBeTrue("{$email} does not use the demo password");
    }
});
```

| Mutation | Test that must fail |
|---|---|
| `MailCheck.php`: drop the `if ($mailer !== 'smtp') { return CheckResult::pass(…); }` block | 'names a mailer other than smtp or log without probing a port' |
| `components/app-sidebar.blade.php`: `<flux:header class="lg:hidden">` → `<div class="lg:hidden">` (and its closing tag) | 'gives small screens a header…' |
| …drop `data-flux-sidebar-toggle` from the header button | 'gives small screens a header…' |
| …drop `<livewire:user-menu variant="header" />` | 'gives small screens a header…' |
| `settings/profile/profile.php`: add `public function deleteAccount(): void { $this->currentUser()->delete(); }` after `use ResolvesCurrentUser;` | 'deletes users only from the users index…' |
| `settings/profile/profile.php`: add `use App\Actions\Users\DeleteUser;` | 'deletes users only from the users index…' |
| `DatabaseSeeder.php`: wrap `$this->call(EssentialsSeeder::class);` in `if (app()->environment(['local', 'testing'])) { … }` | 'seeds the permission catalogue…' (datasets "staging", "production") |
| `DemoSeeder.php:81`: `'Demo user', self::PASSWORD)` → `'Demo user', 'another-password')` | 'gives every demo account the shared password…' |
| `DemoSeeder.php:34`: `PASSWORD = 'password'` → `'demo-password'` | 'gives every demo account the shared password…' |

- [ ] **Step 8: Run the tests**

Run: `php artisan test --compact tests/Feature/ConfigDefaultsTest.php tests/Arch tests/Feature/AppDefaultsTest.php tests/Feature/Settings tests/Feature/Organisations/CreateOrganisationTest.php tests/Feature/Invitations tests/Feature/Roles tests/Feature/DashboardTest.php tests/Feature/Audit tests/Feature/Setup/Doctor/ChecksTest.php tests/Feature/Navigation tests/Feature/Auth/InviteOnlyTest.php tests/Feature/Console/ProductionGuardsTest.php tests/Feature/Seeders tests/Feature/Tooling`

Expected: PASS, with `php scripts/traceability.php` still printing `90 bullets, 90 cited by a test, 0 uncovered, 0 problems.` Then run every mutation in Steps 1–7 (copy aside with `cp`, never `git stash`; `php artisan view:clear` after a Blade or component file is restored). In the prototype each named test failed under its mutation and passed after the restore.

- [ ] **Step 9: The gate, then commit**

Run the Global Constraints gate:
- `composer check`
- `composer test:pgsql` (the index and foreign-key reads, the partial index and the `db:seed` runs also on PostgreSQL)
- the modules-off run: `php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser`
- `npm run build && composer test:browser`
- `npm run build:setup && git diff --exit-code resources/setup/setup.css` (no setup change, so expect no diff)
- `php artisan config:clear`

```bash
git add tests/Feature/ConfigDefaultsTest.php tests/Arch/MigrationRulesTest.php tests/Feature/AppDefaultsTest.php \
    tests/Feature/Settings/SecurityActionsTest.php tests/Feature/Settings/SecurityTest.php \
    tests/Feature/Organisations/CreateOrganisationTest.php tests/Feature/Invitations/InviteUserTest.php \
    tests/Feature/Invitations/RemindInvitationTest.php tests/Feature/Roles/RolesIndexTest.php \
    tests/Arch/AuthorizationRulesTest.php tests/Feature/DashboardTest.php tests/Feature/Audit/ActivityLogTest.php \
    tests/Feature/Setup/Doctor/ChecksTest.php tests/Feature/Navigation/UserMenuTest.php \
    tests/Feature/Auth/InviteOnlyTest.php tests/Feature/Console/ProductionGuardsTest.php \
    tests/Feature/Seeders/DemoSeederTest.php
git commit -m "test: cover the spec section 4 clauses no test guarded

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

---

### Task 16: Packaging metadata (§10, §5.1, publication; CD-20; F50, F58, F59)

Three small fixes before the kit goes to Packagist (M8b):
- **F50.** `.gitattributes` export-ignores `README.md` and `CHANGELOG.md` (a skeleton leftover) and the non-existent `.github/workflows/browser-tests.yml`. Composer installs dist archives by default, and GitHub's zipballs honour `export-ignore`, so a Herd, `laravel new` or `create-project` install from Packagist would have no README, while `skv:bootstrap` sends a production install to "the production checklist in the README" (`BootstrapCommand.php:31`) and the manual points at the README for the answers keys. The e2e and phase-1 runs install from a local vcs repository (source), so they cannot catch it. The three lines go.
- **F58.** `composer.json` gets real `keywords` (`laravel`, `starter-kit`, `multi-tenant`, `livewire`, `flux`, `saas`, instead of the skeleton's `laravel`, `framework`), a `homepage` and `support.issues` / `support.source`, all at `https://github.com/frankcornet/skv3`. No `authors`: the triage made it optional, and every generated app keeps the kit's `composer.json`, so it would name the kit's author as the author of every app.
- **F59.** An MIT `LICENSE` ("Copyright (c) 2026 Frank Cornet", CD-20), the licence `composer.json` and the README already declare.

A new `tests/Feature/Tooling/PackageMetadataTest.php` pins the three. The metadata keys are not part of Composer's lock `content-hash` (it covers `name`, `version`, `require`, `require-dev`, `conflict`, `replace`, `provide`, `minimum-stability`, `prefer-stable`, `repositories`, `extra` and `config.platform`), so `composer.lock` does not change and no `composer update --lock` runs; the prototype's `composer validate --strict` reported the lock current after the edit.

**Files:**
- Create:
  - `LICENSE`
  - `tests/Feature/Tooling/PackageMetadataTest.php`
- Modify:
  - `.gitattributes` (drop the three `export-ignore` lines and the blank line before them)
  - `composer.json` (`keywords`, new `homepage` after them, new `support` after `license`)
  - `CHANGELOG.md`
- Test:
  - `tests/Feature/Tooling/PackageMetadataTest.php`
  - unchanged and still green: `tests/Feature/Tooling/CiWorkflowTest.php` (the committed composer files hold no Flux Pro; Task 13's kit guard reads them), `tests/Arch/NameLeakTest.php` (it does not scan `composer.json`; the new test derives the URL from the package name)

**Interfaces:**
- Consumes: `composer.json`'s `name` (`frankcornet/skv3`); `BootstrapCommand`'s production message (`__('This is a production install: follow the production checklist in the README.')`).
- Produces: `LICENSE` (MIT); `composer.json` `homepage` = `https://github.com/{name}`, `support` = `{"issues": "{homepage}/issues", "source": "{homepage}"}`; `.gitattributes` without `export-ignore` lines. M8b's release-check workflow (CD-22) will add its own `export-ignore` line; the test accepts any pattern that matches an existing path and ignores none of the shipped files.

Background for the implementer:
- `PackageMetadataTest` ships into every generated app with the rest of `tests/` and must pass there. It reads the app's own `composer.json`, so the links are built from the package name, never a literal (NameLeakTest scans `tests/`). An app that later renames its package or changes its licence changes these tests with it, as it already does for `CiWorkflowTest`'s installer test.
- The export-ignore check reads `.gitattributes` itself (no `git check-attr`): an app installed without `--git` has no repository. Patterns are matched with `fnmatch()` and as directory prefixes, which covers the literal paths and simple globs `.gitattributes` uses.
- Composer's own content-hash list is the reason the lock stays as it is; if `composer validate --strict` ever reports the lock out of date after this edit, stop and report it instead of running `composer update`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Tooling/PackageMetadataTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §10 */

/**
 * The patterns .gitattributes marks export-ignore: what a dist install from Packagist (a GitHub zipball, Composer's
 * default) leaves out (F50).
 *
 * @return list<string>
 */
$exportIgnored = function (): array {
    $patterns = [];

    foreach (preg_split('/\R/', (string) file_get_contents(base_path('.gitattributes'))) ?: [] as $line) {
        $fields = preg_split('/\s+/', trim($line), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        if ($fields !== [] && ! str_starts_with($fields[0], '#') && in_array('export-ignore', array_slice($fields, 1), true)) {
            $patterns[] = ltrim($fields[0], '/');
        }
    }

    return $patterns;
};

/** @return array<string, mixed> */
$composer = function (): array {
    /** @var array<string, mixed> $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    return $composer;
};

it('ships every file the app points its users to in a Packagist install, and ignores no missing path (F50)', function () use ($exportIgnored): void {
    $patterns = $exportIgnored();
    $ignored = fn (string $file): bool => array_any($patterns, fn (string $pattern): bool => fnmatch($pattern, $file) || str_starts_with($file, rtrim($pattern, '/').'/'));
    // The README holds the production checklist BootstrapCommand sends a production install to; the MIT notice must
    // travel with every copy; the others are what the docs, the e2e job and a new app's CI read.
    $shipped = ['README.md', 'LICENSE', 'CHANGELOG.md', 'docs/handleiding.html', '.env.example', 'composer.json', 'composer.lock', '.github/workflows/tests.yml', 'tests/Fixtures/e2e-answers.json'];

    expect((string) file_get_contents(app_path('Console/Commands/BootstrapCommand.php')))->toContain('production checklist in the README')
        ->and(array_values(array_filter($shipped, fn (string $file): bool => ! is_file(base_path($file)))))->toBe([])
        ->and(array_values(array_filter($shipped, $ignored)))->toBe([])
        ->and(array_values(array_filter($patterns, fn (string $pattern): bool => (glob(base_path($pattern)) ?: []) === [])))->toBe([]);
});

it('ships the MIT licence composer.json declares (F59, CD-20)', function () use ($composer): void {
    $license = is_file(base_path('LICENSE')) ? (string) file_get_contents(base_path('LICENSE')) : '';

    expect($composer()['license'] ?? null)->toBe('MIT')
        ->and($license)->toStartWith("MIT License\n\nCopyright (c) ")
        ->toContain('Permission is hereby granted, free of charge, to any person obtaining a copy')
        ->toContain('The above copyright notice and this permission notice shall be included in all')
        ->toContain('THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND');
});

it('describes the package for Packagist, with links built from its own name (F58)', function () use ($composer): void {
    $package = $composer();
    // Built from composer.json's name: NameLeakTest scans tests/, so this file holds no kit name literal.
    $repository = 'https://github.com/'.$package['name'];

    expect($package['type'] ?? null)->toBe('project')
        ->and($package['description'] ?? '')->not->toBe('')
        ->and($package['keywords'] ?? null)->toBe(['laravel', 'starter-kit', 'multi-tenant', 'livewire', 'flux', 'saas'])
        ->and($package['homepage'] ?? null)->toBe($repository)
        ->and($package['support'] ?? null)->toBe(['issues' => $repository.'/issues', 'source' => $repository]);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact tests/Feature/Tooling/PackageMetadataTest.php`

Expected: FAIL, all three:
- 'ships every file the app points its users to in a Packagist install…': `LICENSE` is missing (with `LICENSE` in place it fails next on `README.md` and `CHANGELOG.md`, then on the missing `browser-tests.yml`; mutations 1–3 show each)
- 'ships the MIT licence composer.json declares': `Failed asserting that '' starts with "MIT License…"`
- 'describes the package for Packagist…': the keywords are `['laravel', 'framework']`

- [ ] **Step 3: Add the licence, fix `.gitattributes` and the metadata**

Create `LICENSE`:

```text
MIT License

Copyright (c) 2026 Frank Cornet

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

`.gitattributes`: replace

```text
resources/setup/setup.css linguist-generated=true -diff

CHANGELOG.md export-ignore
README.md export-ignore
.github/workflows/browser-tests.yml export-ignore
```

with

```text
resources/setup/setup.css linguist-generated=true -diff
```

(the file then ends after that line, with its newline).

`composer.json`: replace

```json
    "keywords": [
        "laravel",
        "framework"
    ],
    "license": "MIT",
```

with

```json
    "keywords": [
        "laravel",
        "starter-kit",
        "multi-tenant",
        "livewire",
        "flux",
        "saas"
    ],
    "homepage": "https://github.com/frankcornet/skv3",
    "license": "MIT",
    "support": {
        "issues": "https://github.com/frankcornet/skv3/issues",
        "source": "https://github.com/frankcornet/skv3"
    },
```

- [ ] **Step 4: Run the tests to verify they pass, and check Composer's view**

Run: `php artisan test --compact tests/Feature/Tooling tests/Arch`

Expected: PASS.

Run: `composer validate --strict && git diff --exit-code composer.lock`

Expected: `./composer.json is valid` and no lock diff (CD-15's package check `git diff <base> -- composer.lock | grep -E '^[-+] {12}"name": '` prints nothing).

- [ ] **Step 5: Verify by mutation**

Copy the file aside, make the change, run `php artisan test --compact tests/Feature/Tooling/PackageMetadataTest.php`, expect the named test to fail, then `cp` it back. All six ran in the prototype.

| # | Mutation | Test that must fail |
|---|---|---|
| 1 | add `README.md export-ignore` to `.gitattributes` | 'ships every file the app points its users to…' (`README.md` listed as ignored) |
| 2 | add `.github/workflows/browser-tests.yml export-ignore` | 'ships every file…' (a pattern that matches no path) |
| 3 | add `*.md export-ignore` | 'ships every file…' (`README.md` and `CHANGELOG.md` matched by the glob) |
| 4 | `LICENSE`'s first line `MIT License` → `The MIT License (MIT)` | 'ships the MIT licence composer.json declares' |
| 5 | `support.source` → `https://example.com/skv3` | 'describes the package for Packagist…' |
| 6 | drop the `homepage` line | 'describes the package for Packagist…' (`null`) |

- [ ] **Step 6: CHANGELOG**

Under `## [Unreleased]`, `### Added`, append:

```markdown
- An MIT `LICENSE` file and Packagist metadata (keywords, homepage, issue and source links). Installs from Packagist now include `README.md` and `CHANGELOG.md`, which `skv:bootstrap`'s production hint and the manual point to.
```

- [ ] **Step 7: The gate, then commit**

```bash
composer fix
composer validate --strict
composer check
composer test:pgsql
php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser
npm run build && composer test:browser
npm run build:setup && git diff --exit-code resources/setup/setup.css
git diff --exit-code composer.lock
php artisan config:clear
```

```bash
git add LICENSE .gitattributes composer.json CHANGELOG.md tests/Feature/Tooling/PackageMetadataTest.php
git commit -m "chore: add the mit licence and the packagist metadata, and ship the readme in dist installs

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Only M8b can prove that a dist install from Packagist (a GitHub zipball) contains `README.md`, `CHANGELOG.md` and `LICENSE`, and that Packagist shows the keywords and links: the release-check job or a manual `composer create-project frankcornet/skv3 --prefer-dist` after publication.

---

### Task 17: Release documentation, the spec amendments, DEC-36 and the CHANGELOG pass (§10, §4–§9 and §11–§12 as built; CD-6, CD-7, CD-13, CD-14, CD-15, CD-21, CD-22; A27, A29 (residual), A60, A67, A86, B21, B30=F46 (+ C131's docs half), B58=C128, D27, E60, E61, E65, E66, E67, E68, F15, F47)

Tasks 1–16 changed behaviour, tests, CI and packaging, and each wrote the README section and the CHANGELOG line of its own change (CD-14). This task does the rest of the release documentation:
- **README:** the release status, the Herd deep link and the real clone URL without the "once it is published (M8)" caveat (F46, B30), and the statements the behaviour tasks left to it: the setup gate's full header list (Task 9), `skv:bootstrap` without any `APP_ENV` and the doctor's `storage-link` and `gd` rows (Task 8), the second factor during an impersonation and the recovery codes (Task 6), the bulk page's header rule, clean-up and failed send (E66, Task 7), the photos (E61, E60, Tasks 7 and 8), an impersonation that ends with the session (A29), the folded Flux copy (A60), the kit skills (Task 14), the test-database guard, `REQUIRE_POSTGRES`, the focused browser-test command and the traceability script (Tasks 1, 13, D27, Task 15), and the licence (Task 16).
- **`docs/handleiding.html`:** the same in Dutch, informal "je", plus the mirror of Tasks 3–8's README sections (the address-lookup and sign-in limits, the password-bound handoff link, this device's new session id, the hourly upload clean-up), the M8a status, roadmap and limitations (F47, A67), and its source lines without line numbers (E67, CD-21).
- **The kit guideline:** the kit-rule sentences of Tasks 2, 4, 7, 12, 13 and 15, and the two rules E68 found worded kit-wide; then `AGENTS.md` is regenerated.
- **`CLAUDE.md`:** the focused browser-test command (D27), the traceability script, the kit skills and the test-database guard. **`config/trustedproxy.php`:** the docblock names the hosts Laravel trusts by itself (A27).
- **The spec:** accepted (F46); the as-built notes of every M8a change behind the → marker; the deviations CD-6 (§10), CD-7 (§7.4), CD-13 (§6.5), CD-22 (§8) and Task 15b's mail wording (§4.9.2, §5.4); §5.4's safe-repair list (B21); §6.6's avatar rule restored with the as-built rule behind the marker (E65).
- **`docs/decisions.md`:** DEC-36, which records CD-1…CD-22 as built, the other closed items, the deviations, M8b's scope and the post-v1 and won't-fix lists (with "(filled in after execution)" where only the run and Task 18 can know), and the inline pointers in DEC-27 (2), DEC-33, DEC-34 (14) and (16) and DEC-35 (1), (11), (12) (a) and its Consequence (A86, E61, E65; CD-14).
- **CHANGELOG:** one consistency pass: Task 8's production line folded into the existing one, the old header list pointed at the Security line that extends it, and the lines the test-only Tasks 1, 11 and 15 did not write.

Every replacement block below was applied in a prototype worktree on top of Tasks 1–16's own documentation edits, exactly as their task files give them (Task 1's and Task 3's guideline sentences, Task 3's, Task 4's, Task 5's and Task 7's README blocks, every task's CHANGELOG lines, Task 15's §4 intro and bullet ids): each `Replace` block occurred exactly once.

**Files:**
- Modify:
  - `README.md` (Step 1)
  - `docs/handleiding.html` (Steps 2 and 3)
  - `.ai/guidelines/skv3.md` (Step 4); then regenerate the git-ignored `AGENTS.md`
  - `CLAUDE.md`, `config/trustedproxy.php` (Step 5)
  - `docs/superpowers/specs/2026-10-10-skv3-design.md` (Step 6)
  - `docs/decisions.md` (Step 7: the inline pointers and DEC-36)
  - `CHANGELOG.md` (Step 8)
- Create: nothing in the repository. Step 3's one-off script goes in the scratchpad and is never committed.
- Test: no new test. These stay green (Step 9): `tests/Feature/Tooling/*` (`AiConfigTest` after the `CLAUDE.md` edit, `DemoCredentialsExposureTest` after the manual edit, `TraceabilityTest` after the spec edit, `CiWorkflowTest`, which reads the guideline, `PackageMetadataTest`), `tests/Arch/*` (`NameLeakTest`), `tests/Feature/LangCoverageTest.php`, `tests/Feature/Localisation/LangFilesTest.php` (no lang file changes), `tests/Feature/Console/ProductionGuardsTest.php` (the production hint names the README's checklist, which keeps its heading).

**Interfaces:**
- Consumes:
  - Tasks 1–16 merged on `m8-release`, in particular the documentation they leave: Task 1's guideline Setup sentences, Task 3's guideline limits sentence and README *Limits* and *Sign-in limits* bullets, Task 4's *Cross-host sign-in links* bullet, Task 5's *Sessions* sentence, Task 7's *Scheduler* paragraph and row, the CHANGELOG's `### Security` heading (Task 3) and every task's CHANGELOG lines (Tasks 3–9, 13, 14, 16), Task 15's §4 intro, the bullet ids `§4.x.n`, `scripts/traceability.php` and `tests/Feature/Tooling/DemoCredentialsExposureTest.php`, Task 14's `.ai/skills/**` and `AiConfigTest`, Task 16's `LICENSE`.
  - The code as merged. Where a statement below and the merged code disagree, **the code wins**: change the statement to what the code does and record the difference in DEC-36 (25).
- Produces:
  - DEC-36 in `docs/decisions.md`, with "(filled in after execution)" in four places: (25)'s run half (the deviations from the task briefs during the run, the ledger's rulings, the final review and its fix rounds), (27) (Task 18's evidence run), the run half of **Evidence**, and the deferred minors of the M8a reviews at the end of **Consequence**. The controller fills the first, third and fourth after the final review; Task 18 fills (27).
  - No code, no lang key, no route.

Background for the implementer:
- **An old block that does not match.** Each `Replace` block occurs exactly once once Tasks 1–16 are merged. If one does not, an earlier task's text differs from its task file: read the merged text, keep its meaning, apply the change to it, and record the difference in DEC-36 (25).
- **Spec §4 stays as Task 15 numbered it.** Every §4 edit below stays inside its bullet's line. Never add, split, move or remove a §4 bullet: the ids are frozen and `TraceabilityTest` fails on a bullet without the id of its subsection. `php scripts/traceability.php` must report `90 bullets` and `0 problems` before and after Step 6.
- **The manual and R2.** Never put a demo address (`superadmin@…`, `admin@demo1.…`, `user@demo2.…`, also as `&lt;apex&gt;`) and the word `password` on one line of `docs/handleiding.html`: `DemoCredentialsExposureTest` fails on it, and `--password-stdin` counts as the word. The manual is Dutch with the informal "je"; UI labels in `<span class="ui">` quote `lang/nl.json` exactly.
- **The decision log.** DEC-36 goes after DEC-35, in DEC-35's form (one paragraph each for **Context**, **Question**, **Answer** with numbered items, **Evidence** and **Consequence**). The index table at the top lists only the owner decisions, so DEC-36 gets no row (DEC-35 (12) (g)). Older entries change only by the inline pointers of Step 7 (CD-14).
- **`AGENTS.md`.** Regenerate it with `php artisan boost:update --no-discover --no-interaction`. In the prototype that command rewrote only the git-ignored `AGENTS.md` (and `.claude/skills/`) and changed no tracked file; after Task 14 `boost.json` already lists the four skills.
- **CHANGELOG.** The pass adds no feature line beyond the three named in Step 8 (the test-only Tasks 1, 11 and 15 wrote none). The `1.0.0` cut is M8b.
- **No `git stash`.** For the mutation check in Step 9, copy the manual aside with `cp` and copy it back.

- [ ] **Step 1: README**

`README.md`. Apply each replacement (Tasks 3, 4, 5 and 7 own the *Limits*, *Sign-in limits*, *Cross-host sign-in links* and *Sessions* bullets and the *Scheduler* section; this step does not touch them):

1. **F46: the status line.** Replace

````text
> **Status:** under construction (milestone M7d — the account extras: the sessions list with "log out other devices", a second factor required per organisation, the activity log's CSV export, bulk invitations, profile photos, and the limits on an administrator's address changes and the organisation wizard's invitation). See `docs/superpowers/specs/2026-10-10-skv3-design.md`.
````

with

````text
> **Status:** ready for v1.0.0: milestones M0–M8a are done. Publication on GitHub and Packagist and the `v1.0.0` tag (M8b) follow once the owner approves them. The design is in `docs/superpowers/specs/2026-10-10-skv3-design.md`, the decisions are in `docs/decisions.md` and the changes in `CHANGELOG.md`.
````

2. **B30 = F46: the Herd deep link.** Replace

````text
- **Herd** (no terminal needed): *New site* → *Custom Starter Kit* → `frankcornet/skv3` → *Open site*. The site's home page opens the [setup wizard](#the-setup-wizard).
````

with

````text
- **Herd** (no terminal needed): *New site* → *Custom Starter Kit* → `frankcornet/skv3` → *Open site*, or start from the deep link <https://herd.laravel.com/new/frankcornet/skv3>. The site's home page opens the [setup wizard](#the-setup-wizard).
````

3. **F46: the real clone URL.** Replace

````text
  git clone … myapp && cd myapp && composer setup
````

with

````text
  git clone https://github.com/frankcornet/skv3.git myapp && cd myapp && composer setup
````

4. **F46: no "once it is published (M8)".** Replace

````text
The first three routes install the package from Packagist, so they work once it is published (M8). In every route
````

with

````text
The first three routes install the package from Packagist. In every route
````

5. **D57, L7b-15: the setup gate's header list and the IPv4-mapped address.** Replace

````text
So does a request from another machine: only loopback addresses (`127.0.0.0/8`, such as `127.0.0.1`, and `::1`) get in, also when the site listens on every address. So does a request that arrives through a proxy or a tunnel such as `herd share`, which carries an `X-Forwarded-*`, `X-Expose-*`, `CF-*`, `Forwarded`, `Via`, `X-Original-Host`, `X-Real-IP` or `True-Client-IP` header.
````

with

````text
So does a request from another machine: only loopback addresses (`127.0.0.0/8`, such as `127.0.0.1`, and `::1`, but not an IPv4-mapped address such as `::ffff:127.0.0.1`) get in, also when the site listens on every address. So does a request that arrives through a proxy or a tunnel such as `herd share`, which carries an `X-Forwarded-*`, `X-Expose-*`, `CF-*`, `Tailscale-*`, `Forwarded`, `X-Forwarded`, `Forwarded-For`, `Via`, `X-Original-Host`, `X-Original-URL`, `X-Host`, `X-Real-IP`, `True-Client-IP`, `X-Client-IP`, `Client-IP`, `X-Cluster-Client-IP` or `Fastly-Client-IP` header.
````

6. **C37 (CD-12): skv:bootstrap without any APP_ENV.** Replace

````text
In production (the `.env` or the process environment names an `APP_ENV` outside `local`, `testing` and `staging`, such as `production`, `prod` or `live`) it only prepares
````

with

````text
In production (the `.env` or the process environment names an `APP_ENV` outside `local`, `testing` and `staging`, such as `production`, `prod` or `live`, or no source names one at all, which Laravel treats as `production` too) it only prepares
````

7. **D63: storage:link --force in the doctor's repairs.** Replace

````text
`--fix` performs only the safe repairs (`permissions:sync`, flag and role drift, `storage:link`, `core.hooksPath`,
````

with

````text
`--fix` performs only the safe repairs (`permissions:sync`, flag and role drift, `storage:link` (with `--force` only over a wrong or dangling link; a directory or a file in its place is never removed), `core.hooksPath`,
````

8. **D63, E3: the storage-link and gd rows.** Replace

````text
and the `backup-password` row fails while the backups module is on and `BACKUP_ARCHIVE_PASSWORD` is blank. `--json`
````

with

````text
and the `backup-password` row fails while the backups module is on and `BACKUP_ARCHIVE_PASSWORD` is blank. The `storage-link` row warns while `public/storage` is missing and fails while it is anything but a link to `storage/app/public` (a link elsewhere, a dangling link, a directory or a file; a link to the project root would serve `.env` under `/storage`), and the `gd` row warns when GD cannot write WebP, which profile photos need. `--json`
````

9. **E35: no second-factor notice during an impersonation.** Replace

````text
Impersonation sessions are not affected. Fortify asks
````

with

````text
Impersonation sessions are not affected, and during one the security page shows no second-factor notice. Fortify asks
````

10. **D17, D18: recovery codes in a form Fortify never writes.** Replace

````text
After an `APP_KEY` rotation without `APP_PREVIOUS_KEYS`, the recovery codes show "Failed to load recovery codes": regenerate them.
````

with

````text
After an `APP_KEY` rotation without `APP_PREVIOUS_KEYS`, and for codes stored in a form Fortify never writes, the recovery codes show "Failed to load recovery codes": regenerate them.
````

11. **E66: the CSV header rule.** Replace

````text
The header row is optional and recognised only by its column names: a cell containing "mail" (email, e-mail, e-mailadres …) and `locale`, `language`, `lang` or `taal`; without one, the first column is the address and the second the locale.
````

with

````text
The header row is optional and recognised only by its column names: the first row is a header when none of its cells holds an `@` and a cell contains "mail" without a dot (email, e-mail, e-mailadres …) or is `locale`, `language`, `lang` or `taal`; without one, the first column is the address and the second the locale.
````

12. **E5 (CD-4): an abandoned bulk upload goes with the hourly clean-up.** Replace

````text
(a file left on an abandoned page goes with Livewire's own clean-up, a day later, at a later upload)
````

with

````text
(a file left on an abandoned page is removed by the hourly `livewire-tmp-cleanup` once it is 24 hours old, see [Scheduler](#scheduler))
````

13. **E12, E16: a bulk send that fails halfway.** Replace

````text
The summary shows what was sent and what was skipped. Mails are queued in each row's language.
````

with

````text
The summary shows what was sent and what was skipped; when something unexpected fails halfway through, it is reported, and the rows not reached yet are skipped as *Not sent: something went wrong.* without counting against the limit. Mails are queued in each row's language.
````

14. **E61: Intervention's DecoderException.** Replace

````text
It is processed with `intervention/image` ^4 through Laravel's `Illuminate\Image` on the GD driver:
````

with

````text
It is processed with `intervention/image` ^4 through Laravel's `Illuminate\Image` on the GD driver (`UpdateAvatar` also catches Intervention's own `DecoderException`, to tell an image it cannot read from a server fault):
````

15. **D63, E3: the photo's storage link and the gd warning.** Replace

````text
It needs `public/storage` (setup creates the link; `skv:doctor --fix` repairs it) and GD with WebP support. Without WebP support an upload fails with a reported server error.
````

with

````text
It needs `public/storage` (setup creates the link; `skv:doctor --fix` repairs a missing, wrong or dangling one) and GD with WebP support. Without WebP support an upload fails with a reported server error, and the doctor's `gd` row warns.
````

16. **E56, E5: the upload rules and the hourly clean-up.** Replace

````text
The pages check the type and size again (a bulk invitation list is at most 512 KB).
````

with

````text
The pages check the type and size again (a bulk invitation list is at most 512 KB), and `UpdateAvatar` refuses any image type but JPEG, PNG and WebP before it decodes one. Livewire's temporary uploads older than 24 hours are removed every hour (`livewire-tmp-cleanup`, see [Scheduler](#scheduler)).
````

17. **A29: an impersonation that ends with the session.** Replace

````text
An impersonation that ends because the impersonator is disabled, deleted, archived or purged writes no `impersonation.stop` entry: their session is gone, and the start entry and the status change are the record.
````

with

````text
An impersonation that ends because the impersonator is disabled, deleted, archived or purged, or because the session expires, writes no `impersonation.stop` entry: the session is gone, and the start entry (with the status change, if any) is the record.
````

18. **A60: the folded Flux copy.** Replace

````text
The email-change mails and the notice after an administrator changed your address are in the account's language, also from a queue worker.
````

with

````text
The email-change mails and the notice after an administrator changed your address are in the account's language, also from a queue worker. Known limitation: the copy inside the Flux components that Blaze folds when a view compiles stays English in both languages (the password field's visibility toggle, an input's clear and copy buttons, a modal's close button, the one-time-code input's character label and the free file input's *Choose file* and *No file chosen*), because a folded component translates once, for everyone (DEC-29).
````

19. **E60: the photos are not in the backups.** Replace

````text
No files are backed up. On the local disk
````

with

````text
No files are backed up: back up the profile photos in `storage/app/public/avatars` yourself. A database restored without them points at photos that are gone, which show as broken images until each user saves a new photo or removes theirs. On the local disk
````

20. **C37 (CD-12): the production checklist.** Replace

````text
also when only the server's environment sets `APP_ENV` (any name but `local`, `testing` and `staging`). Demo data
````

with

````text
also when only the server's environment sets `APP_ENV` (any name but `local`, `testing` and `staging`), and when no source sets one at all, because Laravel then runs as `production`. Demo data
````

21. **E60: the production checklist backs up the photos.** Replace

````text
- On several servers, set `OPS_CACHE_STORE`
````

with

````text
- Back up `storage/app/public/avatars` (the profile photos) next to the database backups, which hold the database only (see [Backups](#backups)).
- On several servers, set `OPS_CACHE_STORE`
````

22. **F40, F41 (CD-5): the kit skills and the Claude settings.** Replace

````text
- `AGENTS.md`, which `CLAUDE.md` imports, is written by setup's Claude step (`php artisan boost:install`); with the Claude answer off, setup writes none. Run `php artisan boost:install` yourself when you add Claude Code later.
````

with

````text
- `AGENTS.md`, which `CLAUDE.md` imports, is written by setup's Claude step (`php artisan boost:install`); with the Claude answer off, setup writes none. Run `php artisan boost:install` yourself when you add Claude Code later.
- The kit's own skills live in `.ai/skills/<name>/SKILL.md`: `add-tenant-resource`, `add-permission`, `add-settings-page` and `write-tenant-test`. `php artisan boost:update` (and setup's Claude step) publishes them, next to Boost's own skills, to the git-ignored `.claude/skills/`. Each points at the kit rules and the files to copy, and they ship with every app made from the kit.
- `.claude/settings.json` approves the `laravel-boost` and `herd` servers of `.mcp.json` (`enabledMcpjsonServers`), so Claude Code does not ask about them on first use, and allows `composer doctor` and `npm run build:setup` next to the test and build commands.
````

23. **F54, D27, A84a: the traceability script in the command list.** Replace

````text
npm run build:setup     # rebuild resources/setup/setup.css
```
````

with

````text
npm run build:setup     # rebuild resources/setup/setup.css
php scripts/traceability.php   # spec §4 bullets no test cites (exits 1 while there are any)
```

The tests never touch your development database: `Tests\TestCase` refuses to run unless `DB_DATABASE` is `:memory:`, a name ending in `_test`, a file under `storage/framework/testing` or the `DB_TEST_DATABASE` that `composer test:pgsql` was given, and it refuses any non-empty `DB_URL`, so an exported `DB_DATABASE` can never let `RefreshDatabase` wipe your own database. To run one browser test, call Pest directly, because Composer appends extra arguments to every line of `composer test:browser`: `php artisan config:clear && vendor/bin/pest --testsuite=Browser --filter='<name>'`. With `REQUIRE_POSTGRES=true`, as CI sets it, the tests that need PostgreSQL or a usable `pg_dump` fail instead of skipping when it is missing. Every test file starts with a `covers` docblock line; for the spec's §4 it cites the bullet ids (`§4.5.2`), and `php scripts/traceability.php` (run by `TraceabilityTest`) fails while a §4 bullet has no test that cites it.
````

24. **F59: the licence.** Replace

````text
## License

MIT

````

with

````text
## License

MIT, see [LICENSE](LICENSE).

````

- [ ] **Step 2: The Dutch manual**

`docs/handleiding.html`, in its register (informal "je"). Replace `DD-MM-YYYY` in the first block with the date of the commit (`date +%d-%m-%Y`). Apply each replacement:

1. **A67: de kop.** Replace

````text
    <div class="eyebrow"><span>M7d</span><span>M0–M7d in main</span><span>bijgewerkt 17-10-2026</span></div>
    <h1>skv3 <span>handleiding</span></h1>
    <p class="lede">Alles wat je nodig hebt om de multi-tenant Laravel starter kit te installeren, te beheren en erop verder te bouwen. Geschreven voor de stand na milestone M7d.</p>
````

with

````text
    <div class="eyebrow"><span>M8a</span><span>M0–M8a in main</span><span>bijgewerkt DD-MM-YYYY</span></div>
    <h1>skv3 <span>handleiding</span></h1>
    <p class="lede">Alles wat je nodig hebt om de multi-tenant Laravel starter kit te installeren, te beheren en erop verder te bouwen. Geschreven voor de stand na milestone M8a: klaar voor de release van v1.0.0.</p>
````

2. **A60: niet elk scherm is volledig vertaald.** Replace

````text
Alle schermen, paginatitels, mails, meldingen en foutpagina&rsquo;s zijn vertaald.
````

with

````text
Alle schermen, paginatitels, mails, meldingen en foutpagina&rsquo;s zijn vertaald, op een paar vaste teksten in Flux-onderdelen na (zie <a href="#beperkingen">Bekende beperkingen</a>).
````

3. **B30 = F46: de Herd-deeplink en de echte repository.** Replace

````text
<p>Met de Herd-app gaat het zonder terminal: <em>New site</em> &rarr; <em>Custom Starter Kit</em> &rarr; <code>frankcornet/skv3</code> &rarr; <em>Open site</em>. De startpagina van de nieuwe site opent de <a href="#wizard">setupwizard</a>. Die route installeert het pakket via Packagist, dus hij werkt pas als het gepubliceerd is <span class="badge b-plan">M8</span>. Tot die tijd kloon je de code en open je de wizard zo:</p>
````

with

````text
<p>Met de Herd-app gaat het zonder terminal: <em>New site</em> &rarr; <em>Custom Starter Kit</em> &rarr; <code>frankcornet/skv3</code> &rarr; <em>Open site</em>, of je begint met de deeplink <a href="https://herd.laravel.com/new/frankcornet/skv3"><code>https://herd.laravel.com/new/frankcornet/skv3</code></a>. De startpagina van de nieuwe site opent de <a href="#wizard">setupwizard</a>. Die route installeert het pakket via Packagist. Wil je met de code zelf werken, bijvoorbeeld om aan de kit te bouwen, kloon dan de repository en open de wizard zo:</p>
````

4. **F46: de kloon-URL.** Replace

````text
git clone &lt;repository-url&gt; mijnapp
````

with

````text
git clone https://github.com/frankcornet/skv3.git mijnapp
````

5. **D57, L7b-15: de headers van de toegangspoort.** Replace

````text
(een loopbackadres: <code>127.0.0.0/8</code>, zoals <code>127.0.0.1</code>, of <code>::1</code>). Anders antwoordt <code>/welcome-setup</code> met 404, ook vanaf een andere computer en via een proxy of een tunnel zoals <code>herd share</code> (met een header <code>X-Forwarded-*</code>, <code>X-Expose-*</code>, <code>CF-*</code>, <code>Forwarded</code>, <code>Via</code>, <code>X-Original-Host</code>, <code>X-Real-IP</code> of <code>True-Client-IP</code>).
````

with

````text
(een loopbackadres: <code>127.0.0.0/8</code>, zoals <code>127.0.0.1</code>, of <code>::1</code>; een IPv4-adres in IPv6-vorm, zoals <code>::ffff:127.0.0.1</code>, telt niet). Anders antwoordt <code>/welcome-setup</code> met 404, ook vanaf een andere computer en via een proxy of een tunnel zoals <code>herd share</code> (met een header <code>X-Forwarded-*</code>, <code>X-Expose-*</code>, <code>CF-*</code>, <code>Tailscale-*</code>, <code>Forwarded</code>, <code>X-Forwarded</code>, <code>Forwarded-For</code>, <code>Via</code>, <code>X-Original-Host</code>, <code>X-Original-URL</code>, <code>X-Host</code>, <code>X-Real-IP</code>, <code>True-Client-IP</code>, <code>X-Client-IP</code>, <code>Client-IP</code>, <code>X-Cluster-Client-IP</code> of <code>Fastly-Client-IP</code>).
````

6. **F46: laravel new en composer create-project zonder M8-badge.** Replace

````text
<p>De varianten <code>laravel new mijnapp --using=frankcornet/skv3</code> en <code>composer create-project frankcornet/skv3 mijnapp</code> werken pas als het pakket gepubliceerd is <span class="badge b-plan">M8</span>. Daarna open je het adres dat ze afdrukken, voor de wizard, of draai je <code>php artisan skv:install</code> in de nieuwe map.</p>
````

with

````text
<p>Met <code>laravel new mijnapp --using=frankcornet/skv3</code> of <code>composer create-project frankcornet/skv3 mijnapp</code> installeer je het pakket via Packagist. Daarna open je het adres dat ze afdrukken, voor de wizard, of draai je <code>php artisan skv:install</code> in de nieuwe map.</p>
````

7. **D27: één browsertest.** Replace

````text
<tr><td><code>composer test:browser</code></td><td>De browsertests in Chromium (<code>tests/Browser</code>). Eerst één keer <code>npx playwright install chromium</code> en <code>npm run build</code></td></tr>
````

with

````text
<tr><td><code>composer test:browser</code></td><td>De browsertests in Chromium (<code>tests/Browser</code>). Eerst één keer <code>npx playwright install chromium</code> en <code>npm run build</code>. Eén browsertest draai je met <code>php artisan config:clear &amp;&amp; vendor/bin/pest --testsuite=Browser --filter='&lt;naam&gt;'</code>: Composer plakt extra argumenten achter elke regel van het script</td></tr>
````

8. **F54: het traceerbaarheidsscript.** Replace

````text
<tr><td><code>php artisan activitylog:clean</code></td><td>Logregels ouder dan <code>ACTIVITYLOG_RETENTION_DAYS</code> verwijderen</td></tr>
````

with

````text
<tr><td><code>php artisan activitylog:clean</code></td><td>Logregels ouder dan <code>ACTIVITYLOG_RETENTION_DAYS</code> verwijderen</td></tr>
        <tr><td><code>php scripts/traceability.php</code></td><td>De punten van §4 van de spec die geen test noemt; eindigt met exitcode 1 zolang er een is</td></tr>
````

9. **D63: de setupstap Omgevingsbestand.** Replace

````text
de modules en, met Herd Pro-mail, <code>MAIL_MAILER=smtp</code>; daarna de link <code>public/storage</code></td></tr>
````

with

````text
de modules en, met Herd Pro-mail, <code>MAIL_MAILER=smtp</code>; daarna de link <code>public/storage</code> (een verkeerde of kapotte link vervangt hij; een map of bestand op die plek laat hij staan en vraagt hij je opzij te zetten)</td></tr>
````

10. **K9: de Herd-stap bij een nieuwe run.** Replace

````text
<td>Koppelt de map aan Herd als die nog niet geserveerd wordt, zet de site op PHP 8.4 als dat nodig is en beveiligt hem met HTTPS als je dat koos</td>
````

with

````text
<td>Koppelt de map aan Herd als die nog niet geserveerd wordt, zet de site op PHP 8.4 als dat nodig is (ook bij een nieuwe run, als Herd de site intussen met een oudere PHP serveert) en beveiligt hem met HTTPS als je dat koos</td>
````

11. **D63: storage:link --force bij --fix.** Replace

````text
<li><code>--fix</code> doet alleen veilige reparaties: <code>permissions:sync</code>, superbeheerders en rollen die uit de pas lopen, <code>storage:link</code>, de git-hooks,
````

with

````text
<li><code>--fix</code> doet alleen veilige reparaties: <code>permissions:sync</code>, superbeheerders en rollen die uit de pas lopen, <code>storage:link</code> (met <code>--force</code> alleen over een verkeerde of kapotte link; een map of bestand op die plek haalt hij nooit weg), de git-hooks,
````

12. **D63, E3: de regels storage-link en gd.** Replace

````text
<li><code>--json</code> geeft het rapport als JSON en eindigt met exitcode 1 als het niet groen is (voor CI).</li>
````

with

````text
<li>De regel <code>storage-link</code> waarschuwt zolang <code>public/storage</code> ontbreekt, en faalt als het iets anders is dan een link naar <code>storage/app/public</code> (een link elders, een kapotte link, een map of een bestand): <span class="ui">public/storage is geen link naar storage/app/public</span>. Een link naar de hoofdmap van het project zou <code>.env</code> onder <code>/storage</code> serveren. Bij een map of bestand is de oplossing <span class="ui">Zet public/storage opzij en voer daarna php artisan storage:link uit</span>.</li>
    <li>De regel <code>gd</code> waarschuwt als GD geen WebP kan schrijven: <span class="ui">GD ondersteunt geen WebP: profielfoto's kunnen niet worden opgeslagen</span>, met als oplossing <span class="ui">Installeer GD met WebP (libwebp)</span>.</li>
    <li><code>--json</code> geeft het rapport als JSON en eindigt met exitcode 1 als het niet groen is (voor CI).</li>
````

13. **C37 (CD-12), A84a (CD-16): twee nieuwe valkuilen.** Replace

````text
    <li><strong>De tests trekken zich niets aan van je <code>.env</code>-hosts.</strong> <code>phpunit.xml</code> zet het apex vast op <code>localhost</code>.</li>
````

with

````text
    <li><strong>De tests trekken zich niets aan van je <code>.env</code>-hosts.</strong> <code>phpunit.xml</code> zet het apex vast op <code>localhost</code>.</li>
    <li><strong>De tests raken je ontwikkeldatabase nooit.</strong> Ze weigeren te starten als <code>DB_DATABASE</code> geen <code>:memory:</code> is, geen naam die op <code>_test</code> eindigt, geen bestand onder <code>storage/framework/testing</code> en niet de <code>DB_TEST_DATABASE</code> van <code>composer test:pgsql</code>, en ook als <code>DB_URL</code> gevuld is. Een <code>DB_DATABASE</code> die je in je shell hebt gezet, kan <code>RefreshDatabase</code> zo nooit je eigen database laten wissen.</li>
    <li><strong>Een server zonder <code>APP_ENV</code> is productie.</strong> Noemen <code>.env</code> en de omgeving van het proces geen <code>APP_ENV</code> (of een lege), dan behandelt <code>skv:bootstrap</code> de app als productie, net als Laravel zelf: hij maakt dan alleen <code>.env</code>, <code>APP_KEY</code> en de gegenereerde geheimen klaar. Een verse kopie van <code>.env.example</code> zegt <code>local</code>, dus een lokale installatie merkt hier niets van.</li>
````

14. **E21 (CD-18): een nieuw sessie-id voor dit apparaat.** Replace

````text
op elke host; op dit apparaat blijf je ingelogd. Overdrachtslinks die je nog niet gebruikte, vervallen ook.
````

with

````text
op elke host; op dit apparaat blijf je ingelogd. Dit apparaat krijgt daarbij een nieuw sessie-id, zodat een gekopieerde sessiecookie van deze browser ook wordt uitgelogd. Overdrachtslinks die je nog niet gebruikte, vervallen ook.
````

15. **FM-18 (CD-2), D80: adressen opzoeken en inloggen in de limieten.** Replace

````text
    <li>De routes voor tweestapsbeheer en het verwijderen van een passkey: 10 keer per minuut (<code>account-security</code>).</li>
  </ul>
````

with

````text
    <li>De routes voor tweestapsbeheer en het verwijderen van een passkey: 10 keer per minuut (<code>account-security</code>).</li>
    <li>Opzoeken of een e-mailadres gebruikt kan worden: 500 adressen per dag per gebruiker. Elke opzoeking telt, ook een geweigerde: een nieuw adres op je profielpagina of bij <span class="ui">Gebruiker bewerken</span>, het adres bij <span class="ui">Gebruiker uitnodigen</span>, en elke regel van <span class="ui">Lijst controleren</span> bij meerdere uitnodigingen. Een gecontroleerde lijst versturen telt niet. Zo kan niemand ongemerkt nagaan welke adressen al een account hebben.</li>
    <li>Inloggen (per adres en IP-adres, niet per gebruiker): 5 mislukte pogingen per minuut per adres en IP-adres, en 20 per minuut per IP-adres. Log je met succes in, ook met een tweede stap of een passkey, dan tellen de mislukte pogingen van dat adres vanaf dat IP-adres niet meer mee.</li>
  </ul>
````

16. **E55, E56, D63, E3: de profielfoto.** Replace

````text
Met <span class="ui">Foto verwijderen</span> haal je hem weg.
````

with

````text
Met <span class="ui">Foto verwijderen</span> haal je hem weg; alleen als er een foto was, zie je <span class="ui">Profielfoto verwijderd.</span>
````

17. **D63, E3, E56: de profielfoto voor beheerders.** Replace

````text
de foto&rsquo;s hebben de link <code>public/storage</code> nodig (de setup maakt hem; <code>php artisan skv:doctor --fix</code> herstelt hem) en GD met WebP-ondersteuning. Zonder WebP mislukt een upload met een serverfout die in het log komt.
````

with

````text
de foto&rsquo;s hebben de link <code>public/storage</code> nodig (de setup maakt hem; <code>php artisan skv:doctor --fix</code> herstelt een ontbrekende, verkeerde of kapotte link) en GD met WebP-ondersteuning. Zonder WebP mislukt een upload met een serverfout die in het log komt, en de regel <code>gd</code> van <code>skv:doctor</code> waarschuwt ervoor. Elk ander beeldtype dan JPEG, PNG en WebP weigert de kit in de actie zelf, nog voordat hij de foto uitpakt.
````

18. **D17, D18: herstelcodes in een vorm die Fortify nooit schrijft.** Replace

````text
Is <code>APP_KEY</code> gewisseld zonder de oude sleutel in <code>APP_PREVIOUS_KEYS</code>, dan zie je <span class="ui">De herstelcodes konden niet worden geladen</span>;
````

with

````text
Is <code>APP_KEY</code> gewisseld zonder de oude sleutel in <code>APP_PREVIOUS_KEYS</code>, of staan de codes in een vorm die Fortify nooit schrijft, dan zie je <span class="ui">De herstelcodes konden niet worden geladen</span>;
````

19. **E35: geen melding tijdens inloggen als.** Replace

````text
<li><strong>Inloggen als.</strong> Tijdens &lsquo;inloggen als&rsquo; geldt de regel niet: de beheerder kan de factor van de ander toch niet instellen.</li>
````

with

````text
<li><strong>Inloggen als.</strong> Tijdens &lsquo;inloggen als&rsquo; geldt de regel niet: de beheerder kan de factor van de ander toch niet instellen. De beveiligingspagina toont dan ook geen melding over de tweede factor.</li>
````

20. **E4 (CD-3): de overdrachtslink hoort bij je wachtwoord.** Replace

````text
<li>Verlopen, gebruikt of verkeerde host: <span class="ui">Deze inloglink is ongeldig of verlopen.</span></li>
````

with

````text
<li>De link werkt alleen zolang je wachtwoord hetzelfde is als toen hij gemaakt werd: wijzig je het op de beveiligingspagina, stel je het opnieuw in, of zet iemand het via de opdrachtregel (<code>skv:create-super-admin --password-stdin</code>), dan werkt geen eerdere link meer. Ook de laatste stap van de setupwizard en snel inloggen gebruiken zo&rsquo;n link. Links openen kan hooguit 10 keer per minuut per IP-adres.</li>
    <li>Verlopen, gebruikt, verkeerde host of een ander wachtwoord: <span class="ui">Deze inloglink is ongeldig of verlopen.</span></li>
````

21. **E12, E16: een verzending die halverwege misgaat.** Replace

````text
De samenvatting toont <span class="ui">Verstuurd: <em>12</em></span> en <span class="ui">Overgeslagen: <em>2</em></span> met de redenen.</p></div></li>
````

with

````text
De samenvatting toont <span class="ui">Verstuurd: <em>12</em></span> en <span class="ui">Overgeslagen: <em>2</em></span> met de redenen. Gaat er halverwege iets onverwachts mis, dan komt de fout in het log, toont de samenvatting wat al verstuurd is, en staan de regels die niet meer aan de beurt kwamen bij de overgeslagen regels met <span class="ui">Niet verstuurd: er ging iets mis.</span>; die tellen niet mee voor de limiet.</p></div></li>
````

22. **E66, E5: de kopregel en het opruimen.** Replace

````text
Een kopregel mag; de kit herkent hem alleen aan de kolomnamen: een naam met &lsquo;mail&rsquo; erin (email, e-mail, e-mailadres …) en <code>locale</code>, <code>language</code>, <code>lang</code> of <code>taal</code>.
````

with

````text
Een kopregel mag; de kit herkent hem alleen aan de kolomnamen: de eerste regel is een kopregel als geen enkele cel een <code>@</code> bevat en een cel &lsquo;mail&rsquo; bevat zonder punt (email, e-mail, e-mailadres …) of <code>locale</code>, <code>language</code>, <code>lang</code> of <code>taal</code> is.
````

23. **E5 (CD-4): een verlaten upload.** Replace

````text
(een bestand op een verlaten pagina ruimt Livewire zelf op, een dag later, bij een volgende upload)
````

with

````text
(een bestand op een verlaten pagina ruimt de planner elk uur op zodra het 24 uur oud is, met <code>livewire-tmp-cleanup</code>)
````

24. **A29: een sessie die verloopt tijdens inloggen als.** Replace

````text
<li>Wordt jouw eigen account uitgeschakeld, verwijderd, gearchiveerd of definitief verwijderd, dan verdwijnt je sessie en komt er geen regel <code>impersonation.stop</code>: de startregel en de statuswijziging zijn dan het spoor.</li>
````

with

````text
<li>Wordt jouw eigen account uitgeschakeld, verwijderd, gearchiveerd of definitief verwijderd, dan verdwijnt je sessie en komt er geen regel <code>impersonation.stop</code>: de startregel en de statuswijziging zijn dan het spoor. Verloopt je sessie, dan komt er ook geen regel <code>impersonation.stop</code>; dan is de startregel het spoor.</li>
````

25. **E5 (CD-4): livewire-tmp-cleanup in de planning.** Replace

````text
Staat er <span class="ui">De planner heeft nog niet gedraaid</span>, dan ontbreekt de cronregel op de server.</li>
````

with

````text
Staat er <span class="ui">De planner heeft nog niet gedraaid</span>, dan ontbreekt de cronregel op de server. Elk uur om kwart voor ruimt <code>livewire-tmp-cleanup</code> op elke server de tijdelijke uploads van Livewire op die ouder zijn dan 24 uur, zoals een foto of een adressenlijst op een verlaten pagina; staan de tijdelijke uploads op S3, dan doet hij niets en regel je dat één keer met <code>php artisan livewire:configure-s3-upload-cleanup</code>.</li>
````

26. **E60: de foto's staan niet in de back-up.** Replace

````text
<p><strong>Back-ups.</strong> Met de module aan (alleen op PostgreSQL) maakt de planner elke nacht om 02:00 een versleutelde back-up van de database.
````

with

````text
<p><strong>Back-ups.</strong> Met de module aan (alleen op PostgreSQL) maakt de planner elke nacht om 02:00 een versleutelde back-up van de database. Bestanden gaan niet mee: maak zelf een back-up van de profielfoto&rsquo;s in <code>storage/app/public/avatars</code>. Zet je alleen de database terug, dan wijzen de accounts naar foto&rsquo;s die er niet meer zijn, en zie je een kapot plaatje tot de gebruiker een nieuwe foto opslaat of zijn foto verwijdert.
````

27. **FM-18 (CD-2): de limietregel voor ontwikkelaars.** Replace

````text
en elke nieuwe actie die een lid kan herhalen om mail te versturen of een geheim te proberen, begrenzen zichzelf
````

with

````text
en elke nieuwe actie die een lid kan herhalen om mail te versturen, een geheim te proberen, op te zoeken of een adres een account heeft, of de server zwaar werk te laten doen, begrenzen zichzelf
````

28. **FM-18 (CD-2): attempt() voor het opzoeken van een adres.** Replace

````text
<code>attempt()</code> vóór de controle van een geheim; <code>around()</code> om het versturen heen:
````

with

````text
<code>attempt()</code> vóór de controle van een geheim of het opzoeken van een adres (<code>addressLookup()</code>: 500 per dag, elke opzoeking telt en komt nooit terug); <code>around()</code> om het versturen heen:
````

29. **CD-17, Task 1, Task 12: tests voor ontwikkelaars.** Replace

````text
<p>Gebruik je <code>Event::fake()</code>, houd dan de tenancy-events echt: <code>Event::fake()->except([TenantResolved::class, TenantForgotten::class])</code>. Anders blijft het rechtenteam leeg.</p>
````

with

````text
<p>Gebruik je <code>Event::fake()</code>, houd dan de tenancy-events echt: <code>Event::fake()->except([TenantResolved::class, TenantForgotten::class])</code>. Anders blijft het rechtenteam leeg.</p>
  <p>Elk testbestand begint met een docblock <code>/** covers §x.y */</code>. Voor §4 van de spec noem je de vaste ids van de punten (<code>/** covers §4.5.2, §4.5.8, §6.2 */</code>); een kale <code>§4.5</code> telt voor geen enkel punt. <code>php scripts/traceability.php</code> noemt de punten van §4 die geen test noemt en eindigt dan met exitcode 1; <code>tests/Feature/Tooling/TraceabilityTest.php</code> draait hem.</p>
  <p>Tests onder <code>Feature/Setup</code>, <code>Feature/Console</code>, <code>Feature/Backups</code>, <code>Feature/Health</code> en <code>Feature/System</code> faken elk commando bij naam. Een test faalt als een fake ook commando&rsquo;s dekt die niemand noemt, of als er een commando tegen de bewaking aanliep, ook een dat de code zelf opving (<code>Tests\Fixtures\RecordingProcessFactory</code>). Een test die zonder PostgreSQL of een bruikbare <code>pg_dump</code> overslaat, doet dat via <code>Tests\Fixtures\RequiredPostgres::skips()</code>: met <code>REQUIRE_POSTGRES=true</code>, zoals in CI, faalt hij dan in plaats van over te slaan.</p>
````

30. **D27, A77, E69: browsertests.** Replace

````text
<p>Browsertests staan in <code>tests/Browser</code> en draaien in Chromium met de Pest browser-plugin: <code>composer test:browser</code>. De gewone runs laden die map nooit. Kies de host per bezoek met <code>->withHost()</code>; dat geldt alleen voor de eerste pagina, dus open een volgende pagina met een absoluut adres op dezelfde host.</p>
````

with

````text
<p>Browsertests staan in <code>tests/Browser</code> en draaien in Chromium met de Pest browser-plugin: <code>composer test:browser</code>. De gewone runs laden die map nooit. Kies de host per bezoek met <code>->withHost()</code>; dat geldt alleen voor de eerste pagina, dus open een volgende pagina met een absoluut adres op dezelfde host. Eén browsertest draai je met <code>php artisan config:clear &amp;&amp; vendor/bin/pest --testsuite=Browser --filter='&lt;naam&gt;'</code>. Een pagina met de app-layout heeft <code>data-test="app-main"</code>, een foutpagina nooit; zo ziet een test het verschil op hetzelfde pad. Een browsertest die een bestand uploadt, volgt <code>tests/Browser/AccountExtrasTest.php</code>: de ingebouwde server van de plugin verminkt multipart-uploads, en <code>Tests\Fixtures\NestUploadedFiles</code> leest ze opnieuw in.</p>
````

31. **F15 (CD-7): de pre-commit-hook.** Replace

````text
<li>De pre-commit-hook draait Pint en PHPStan op de gestagede PHP-bestanden.</li>
````

with

````text
<li>De pre-commit-hook draait Pint (<code>--test</code>) en PHPStan op de gestagede PHP-bestanden, en weigert een bestand dat ook niet-gestagede wijzigingen heeft. Zo controleert hij precies wat je commit en past hij tijdens een commit nooit een bestand aan.</li>
````

32. **Task 13: CI in het kort.** Replace

````text
<li>CI (<code>.github/workflows/tests.yml</code>) draait vier jobs: PHP 8.4 op PostgreSQL, PHP 8.4 op SQLite met <code>MODULE_IMPERSONATION=false</code> en <code>MODULE_PASSKEYS=false</code>, PHP 8.5 op SQLite, en de browsertests in Chromium (met screenshots en traces als een test faalt).</li>
````

with

````text
<li>CI (<code>.github/workflows/tests.yml</code>) draait drie controlerijen: PHP 8.4 op PostgreSQL, PHP 8.4 op SQLite met <code>MODULE_IMPERSONATION=false</code> en <code>MODULE_PASSKEYS=false</code>, en PHP 8.5 op SQLite; de browsertests in Chromium (met een PostgreSQL-service, en screenshots en traces als een test faalt); en, &rsquo;s nachts, met de hand of bij een pull request die de installatie raakt, de jobs <code>phase1</code> (de wizard na elk van de vier instappunten) en <code>e2e</code> (drie varianten van <code>laravel new</code>). De PostgreSQL-rij en de browserjob zetten <code>REQUIRE_POSTGRES=true</code>. De Flux Pro-licentie komt alleen via <code>COMPOSER_AUTH</code> in de stappen die hem nodig hebben, en in de repository van de kit zelf weigert een stap een gecommitte Flux Pro.</li>
````

33. **F47, A67: de status.** Replace

````text
<p>Milestones M0 tot en met M7d zitten in <code>main</code>. Daarna volgt M8, de release. Er is nog geen release: alles staat onder <code>[Unreleased]</code> in de changelog.</p>
````

with

````text
<p>Milestones M0 tot en met M8a zitten in <code>main</code>: de kit is klaar voor de release van v1.0.0. Publicatie op GitHub en Packagist (M8b) volgt na akkoord van de eigenaar. Er is nog geen release: alles staat onder <code>[Unreleased]</code> in de changelog.</p>
````

34. **F47: de rij M8a.** Replace

````text
        <tr><td class="tabnum">M7d</td><td>Extra&rsquo;s: sessieoverzicht met &lsquo;andere apparaten uitloggen&rsquo;, tweede factor verplicht per organisatie, CSV-export van het log, meerdere mensen tegelijk uitnodigen, profielfoto&rsquo;s, en limieten op adreswijzigingen door een beheerder en de uitnodiging uit de organisatiewizard</td><td><span class="badge b-ok">klaar</span></td></tr>
````

with

````text
        <tr><td class="tabnum">M7d</td><td>Extra&rsquo;s: sessieoverzicht met &lsquo;andere apparaten uitloggen&rsquo;, tweede factor verplicht per organisatie, CSV-export van het log, meerdere mensen tegelijk uitnodigen, profielfoto&rsquo;s, en limieten op adreswijzigingen door een beheerder en de uitnodiging uit de organisatiewizard</td><td><span class="badge b-ok">klaar</span></td></tr>
        <tr><td class="tabnum">M8a</td><td>Klaar voor de release: een limiet op het opzoeken van adressen, overdrachtslinks die bij je wachtwoord horen, een nieuw sessie-id bij &lsquo;andere apparaten uitloggen&rsquo;, het opruimen van tijdelijke uploads, strengere controles van de installatie en de diagnose, bewakingen in de testsuite, de kit-skills voor Claude Code, het traceerbaarheidsscript, de inhoud van CI, de licentie en deze handleiding</td><td><span class="badge b-ok">klaar</span></td></tr>
````

35. **F47: de bronnen van de status.** Replace

````text
<p class="src">git log (main); CHANGELOG.md; README.md (Status); docs/decisions.md (DEC-34, DEC-35)</p>
````

with

````text
<p class="src">git log (main); CHANGELOG.md; README.md (Status); docs/decisions.md (DEC-34, DEC-35, DEC-36)</p>
````

36. **F47: M8b in de roadmap.** Replace

````text
<tr><td><strong>M8 · Release</strong></td><td>Documentatie, volledige CI met e2e, Herd-deeplink gecontroleerd; daarna (na akkoord van de eigenaar) publicatie op GitHub/Packagist als <code>frankcornet/skv3</code>, tag <code>v1.0.0</code></td></tr>
````

with

````text
<tr><td><strong>M8b · Publicatie</strong></td><td>Na akkoord van de eigenaar: de repository op GitHub, de eerste groene CI-run (ook <code>phase1</code> en <code>e2e</code>), de beveiliging van <code>main</code>, het pakket op Packagist als <code>frankcornet/skv3</code>, de Herd-deeplink gecontroleerd en de installatie in Herd gemeten, de release-check en de tag <code>v1.0.0</code></td></tr>
````

37. **F47: de bronnen van de roadmap.** Replace

````text
DEC-34 Consequence, DEC-35 Consequence)</p>
````

with

````text
DEC-34 Consequence, DEC-35 Consequence, DEC-36 Consequence)</p>
````

38. **F47, A60: de bekende beperkingen.** Replace

````text
    <li>Installeren met één klik vanuit de Herd-app werkt pas na de publicatie (M8); tot dan kloon je de code en open je de wizard zoals in <a href="#herd">Installeren via Herd</a>.</li>
````

with

````text
    <li>Installeren vanuit de Herd-app, met de deeplink, met <code>laravel new</code> of met <code>composer create-project</code> werkt pas na de publicatie (M8b); tot dan kloon je de code en open je de wizard zoals in <a href="#herd">Installeren via Herd</a>.</li>
    <li>Een paar teksten in Flux-onderdelen blijven in beide talen Engels: de knop die een wachtwoord toont, de knoppen om een veld te wissen of te kopiëren, de sluitknop van een venster, het label van een tekenvak bij een eenmalige code en het gratis bestandsveld (<span class="ui">Choose file</span>, <span class="ui">No file chosen</span>). Flux vouwt die onderdelen bij het compileren tot vaste tekst, dus een vertaling zou voor iedereen de taal van de eerste compilatie tonen (DEC-29).</li>
````

39. **A67: het colofon.** Replace

````text
<p>Gebaseerd op de code en documentatie van <code>main</code> na milestone M7d.
````

with

````text
<p>Gebaseerd op de code en documentatie van <code>main</code> na milestone M8a.
````

40. **F40, F41 (CD-5): de kit-skills en de Claude-instellingen.** Replace

````text
Veel regels worden afgedwongen door arch-tests in <code>tests/Arch</code>, dus een overtreding breekt <code>composer check</code>.</p>
````

with

````text
Veel regels worden afgedwongen door arch-tests in <code>tests/Arch</code>, dus een overtreding breekt <code>composer check</code>.</p>
  <p>Voor Claude Code levert de kit vier eigen skills in <code>.ai/skills/&lt;naam&gt;/SKILL.md</code>: <code>add-tenant-resource</code>, <code>add-permission</code>, <code>add-settings-page</code> en <code>write-tenant-test</code>. Elke skill is een korte checklist die naar de regels en naar de bestanden om na te doen wijst. <code>php artisan boost:update</code> (en de Claude-stap van de installatie) zet ze naast de skills van Boost in <code>.claude/skills/</code>, dat niet in git staat; ze gaan mee naar elke app die je met de kit maakt. <code>.claude/settings.json</code> keurt de MCP-servers <code>laravel-boost</code> en <code>herd</code> uit <code>.mcp.json</code> vooraf goed (<code>enabledMcpjsonServers</code>), zodat Claude Code er niet naar vraagt, en staat naast de test- en buildcommando&rsquo;s ook <code>composer doctor</code> en <code>npm run build:setup</code> toe.</p>
````

- [ ] **Step 3: The manual's sources without line numbers (CD-21, E67)**

Save this one-off script in the scratchpad (never in the repository) and run it from the project root: `php <scratchpad>/strip_source_lines.php docs/handleiding.html`. It removes every `:line` and `:from-to` reference from the 51 source lines (`<p class="src">…</p>`, inside and outside the *Bronnen* blocks), turns the spec's line numbers into its sections and the decision log's into decisions, and names a file once per block.

```php
<?php

declare(strict_types=1);

// One-off for Task 17 (CD-21, E67; not committed): the manual's source lines name files without line numbers.
// Run from the project root: php <this file> docs/handleiding.html

$path = $argv[1] ?? 'docs/handleiding.html';
$html = (string) file_get_contents($path);

// The spec's line numbers become the sections they pointed at; the decision log's become the decisions.
$named = [
    'spec:94' => 'spec §4.1',
    'spec:33,79' => 'spec §2, §3',
    'spec:295' => 'spec §5.3.4',
    'spec:209-212,310' => 'spec §5.1, §5.4',
    'spec:18-25' => 'spec §1.1',
    'spec:111, §6.8' => 'spec §4.2, §6.8',
    'docs/decisions.md:132 (7, 34), :134' => 'docs/decisions.md (DEC-28 (7), (34) and its Consequence)',
    'docs/decisions.md:132 (37)' => 'docs/decisions.md (DEC-28 (37))',
    'docs/decisions.md:132 (18)' => 'docs/decisions.md (DEC-28 (18))',
    'docs/decisions.md:132 (13, 30, 33)' => 'docs/decisions.md (DEC-28 (13), (30), (33))',
    'docs/decisions.md:127' => 'docs/decisions.md (DEC-27 Consequence)',
    'app/Policies/InvitationPolicy.php:25-73 (viewLink :44-59)' => 'app/Policies/InvitationPolicy.php (viewLink)',
    '.ai/guidelines/skv3.md:7,19, (UI)' => '.ai/guidelines/skv3.md (Tests, UI)',
];

$count = 0;
$html = (string) preg_replace_callback('#(<p class="src">)(.*?)(</p>)#s', function (array $match) use ($named, &$count): string {
    $sources = strtr($match[2], $named);
    // path:12, path:12-34, path:12,40-41 (also before a space and a parenthesis, as in "README.md:9-10 (…)").
    $sources = (string) preg_replace('/(?<=[\w)*}\]])(:\d+(?:-\d+)?(?:,\d+(?:-\d+)?)*)(?=[;\s<]|$)/', '', $sources, -1, $stripped);
    $count += $stripped;

    // A file named twice in one block (once per line range) is named once.
    $parts = array_values(array_unique(array_map(trim(...), explode(';', $sources))));

    return $match[1].implode('; ', $parts).$match[3];
}, $html);

file_put_contents($path, $html);

fwrite(STDOUT, "Dropped {$count} line references.\n");
```

Expected: `Dropped 147 line references.`, and `grep -o 'class="src">[^<]*' docs/handleiding.html | grep -cE '[A-Za-z)*}]:[0-9]'` prints `0`. Then add the new sources to five blocks (these `Replace` blocks hold the text as the script leaves it):

1. **Bronnen: sessies.** Replace

````text
resources/views/pages/settings/sessions/*; app/Support/UserAgent.php; tests/Feature/Settings/SessionsTest.php</p>
````

with

````text
resources/views/pages/settings/sessions/*; app/Support/UserAgent.php; tests/Feature/Settings/SessionsTest.php; tests/Feature/Authorization/LockOrderTest.php</p>
````

2. **Bronnen: limieten.** Replace

````text
tests/Feature/Auth/AdministratorActionLimitsTest.php; tests/Feature/Settings/TemporaryUploadsTest.php</p>
````

with

````text
tests/Feature/Auth/AdministratorActionLimitsTest.php; tests/Feature/Settings/TemporaryUploadsTest.php; app/Rules/AvailableEmail.php; tests/Feature/Invitations/BulkInvitationsTest.php</p>
````

3. **Bronnen: de overdrachtslink.** Replace

````text
resources/views/pages/organisations/index/index.php</p>
````

with

````text
resources/views/pages/organisations/index/index.php; app/Models/HandoffToken.php; database/migrations/0001_01_01_000010_add_password_hash_to_handoff_tokens_table.php; tests/Feature/Auth/HandoffTest.php</p>
````

4. **Bronnen: installeren en controleren.** Replace

````text
app/Setup/Doctor/Doctor.php; database/seeders/{DatabaseSeeder,DemoSeeder}.php;
````

with

````text
app/Setup/Doctor/Doctor.php; app/Setup/Doctor/Checks/{StorageLinkCheck,GdCheck}.php; app/Setup/Support/Bootstrap.php; database/seeders/{DatabaseSeeder,DemoSeeder}.php;
````

5. **Bronnen: systeemstatus.** Replace

````text
app/Support/Scheduler/OpsSchedule.php; app/Support/Scheduler/ScheduledTasks.php; config/backup.php;
````

with

````text
app/Support/Scheduler/OpsSchedule.php; app/Support/Scheduler/ScheduledTasks.php; app/Support/Uploads/StaleTemporaryUploads.php; config/backup.php;
````

6. **Bronnen: tests.** Replace

````text
tests/Pest.php; tests/Feature/Auth/HostScopedLoginTest.php; tests/Browser/LoginTest.php; phpunit.xml; composer.json (test:browser)</p>
````

with

````text
tests/Pest.php; tests/TestCase.php; tests/Fixtures/{RecordingProcessFactory,RequiredPostgres,NestUploadedFiles}.php; scripts/traceability.php; tests/Feature/Auth/HostScopedLoginTest.php; tests/Browser/LoginTest.php; tests/Browser/AccountExtrasTest.php; phpunit.xml; composer.json (test:browser)</p>
````

- [ ] **Step 4: The kit guideline**

`.ai/guidelines/skv3.md`. Tasks 1 and 3 changed the Setup paragraph and the `UserActionLimit` bullet; these replacements touch neither:

1. **CD-17: the covers rule.** Replace

````text
- Tests are Pest functions (`it(...)`). Feature tests use `RefreshDatabase` via `tests/Pest.php`. Arch rules live in `tests/Arch`.
````

with

````text
- Tests are Pest functions (`it(...)`). Feature tests use `RefreshDatabase` via `tests/Pest.php`. Arch rules live in `tests/Arch`. Every test file starts with a `/** covers §x.y */` docblock; for the spec's §4 it cites the frozen bullet ids (`§4.5.2`), because a plain `§4.5` cites none of its bullets, and `php scripts/traceability.php` (run by `TraceabilityTest`) fails while a §4 bullet has no test that cites it.
- `Tests\TestCase` refuses to run on a database that is not a test database (`:memory:`, a name ending in `_test`, a file under `storage/framework/testing`, or the `DB_TEST_DATABASE` of `composer test:pgsql`; a non-empty `DB_URL` is refused); never relax it. A test that skips while PostgreSQL or a usable `pg_dump` is missing skips through `Tests\Fixtures\RequiredPostgres::skips()`, so `REQUIRE_POSTGRES=true` in CI turns the skip into a failure (`RequiredPostgresTest`).
````

2. **A25: the central context.** Replace

````text
Never remove `TenantScope` with `withoutGlobalScope(s)` / `newQueryWithoutScopes` outside those namespaces; use `Tenant::bypass()`.
````

with

````text
Never remove `TenantScope` with `withoutGlobalScope(s)` / `newQueryWithoutScopes` outside those namespaces; use `Tenant::bypass()`. `App\Tenancy\CentralContext`, which switches strict mode off while it is active, is named only in `app/Tenancy/` and `TenancyServiceProvider` (`TenancyRulesTest`).
````

3. **D77: one writer of is_super_admin.** Replace

````text
- Super admin = `users.is_super_admin`, changed only by `App\Authorization\SetSuperAdmin` (pages call `App\Actions\Users\UpdateSuperAdmin`, which authorises first).
````

with

````text
- Super admin = `users.is_super_admin`, changed only by `App\Authorization\SetSuperAdmin` (pages call `App\Actions\Users\UpdateSuperAdmin`, which authorises first); no other code writes the column, apart from the factories and `InviteUser`'s `false` for a new invitee (`AuthorizationRulesTest`).
````

4. **E4 (CD-3): handoff links bound to the password.** Replace

````text
- Moving a signed-in user to another host goes through `App\Actions\Auth\IssueHandoff`; sessions are host-only.
````

with

````text
- Moving a signed-in user to another host goes through `App\Actions\Auth\IssueHandoff`; sessions are host-only. A handoff link is bound to the user's current password (`HandoffToken::passwordBindingFor()`, checked by `HandoffController`), so every password write ends the links issued before; never issue or accept one without that binding.
````

5. **C141: the folded Flux keys.** Replace

````text
- Every new `__()` key goes into both `lang/nl.json` and `lang/en.json` (`LangCoverageTest`); Dutch copy uses the informal "je".
````

with

````text
- Every new `__()` key goes into both `lang/nl.json` and `lang/en.json` (`LangCoverageTest`); Dutch copy uses the informal "je". Never add the copy of the Flux components Blaze folds at compile time to `lang/*.json` (`Toggle password visibility`, `Clear input`, `Copy to clipboard`, `Choose file`, `Choose files`, `No file chosen`, `Close modal`, `Character {current} of {total}`): a folded component translates once, so the first language to compile would show for everyone (DEC-29).
````

6. **E68: X-Livewire decides no access.** Replace

````text
Never detect a Livewire request by the `X-Livewire` header, which any client can send; use the route name.
````

with

````text
Never decide access by the `X-Livewire` header, which any client can send; use the route name (error views may read it to choose what they display).
````

7. **E68: the audit downloads.** Replace

````text
Downloads are plain GET routes with `streamDownload()` and `lazyById()` inside `HostScope::read()`, never a Livewire download.
````

with

````text
Audit and log downloads are plain GET routes with `streamDownload()` and `lazyById()` inside `HostScope::read()`, never a Livewire download (a Livewire action may stream a small file of the user's own, as the recovery codes do).
````

8. **E5 (CD-4): the hourly upload clean-up is a backstop.** Replace

````text
A component deletes its temporary upload (`TemporaryUploadedFile::delete()`) once it is used, removed or replaced, never leaving it for Livewire's 24-hour clean-up.
````

with

````text
A component deletes its temporary upload (`TemporaryUploadedFile::delete()`) once it is used, removed or replaced, never leaving it for Livewire's 24-hour clean-up; the hourly `livewire-tmp-cleanup` (`App\Support\Uploads\StaleTemporaryUploads`, local disks only) is a backstop for abandoned pages, not a substitute.
````

9. **D27, A77, E69: browser tests.** Replace

````text
- Browser tests live in `tests/Browser` and pick a host with `->withHost()`; after the first page, navigate with `sameOrigin($page->url(), '/path')`. Run them with `composer test:browser`.
````

with

````text
- Browser tests live in `tests/Browser` and pick a host with `->withHost()`; after the first page, navigate with `sameOrigin($page->url(), '/path')`. Run them with `composer test:browser`, one of them with `php artisan config:clear && vendor/bin/pest --testsuite=Browser --filter=…` (Composer appends arguments to every line of the script). A page of the app layout carries `data-test="app-main"` and an error page never does, so a smoke check asserts it. A browser test that uploads a file appends `Tests\Fixtures\NestUploadedFiles` to `livewire.temporary_file_upload.middleware` and fakes Livewire's temporary disk, as `tests/Browser/AccountExtrasTest.php` does, because the plugin's in-process server mangles multipart uploads.
````

10. **C110: the exec ban widened.** Replace

````text
- Never `exec()`, `shell_exec()`, `system()`, `passthru()`, `proc_open()`, `popen()`, `pcntl_exec()` or backticks in `app/`, `tests/`, `routes/`, `database/`, `config/` or the component classes under `resources/views/` (`tests/Arch/KitRulesTest.php`).
````

with

````text
- Never `exec()`, `shell_exec()`, `system()`, `passthru()`, `proc_open()`, `popen()`, `pcntl_exec()` or backticks in `app/`, `tests/`, `routes/`, `database/`, `config/`, `bootstrap/` (not `bootstrap/cache/`), `scripts/` or the component classes under `resources/views/`, and never a shell string: no `Process::fromShellCommandline()` and no string command to `Process::run()`, `start()` or `command()`, only an argv array (`tests/Arch/KitRulesTest.php`).
````

Then regenerate the git-ignored `AGENTS.md`:

```bash
php artisan boost:update --no-discover --no-interaction
git status --short
grep -c 'RequiredPostgres::skips()' AGENTS.md
grep -c 'Never decide access by the `X-Livewire` header' AGENTS.md
```

Expected: "Boost guidelines and skills updated successfully."; `git status` lists no file this task does not change (the command touches only the git-ignored `AGENTS.md` and `.claude/skills/`); both counts print `1`.

- [ ] **Step 5: `CLAUDE.md` and the trusted-proxy docblock**

`CLAUDE.md` (it stays under 50 lines, far below §9's 200):

1. **D27: one browser test.** Replace

````text
- `composer fix` — Rector, then Pint.
````

with

````text
- `php artisan config:clear && vendor/bin/pest --testsuite=Browser --filter=<name>` — run one browser test (`composer test:browser -- --filter=…` cannot work: Composer appends the arguments to every line of the script).
- `composer fix` — Rector, then Pint.
````

2. **F54: the traceability script.** Replace

````text
- `php artisan test --compact --filter=<name>` — run one test; `php artisan test --compact tests/Arch` — arch rules only.
````

with

````text
- `php artisan test --compact --filter=<name>` — run one test; `php artisan test --compact tests/Arch` — arch rules only.
- `php scripts/traceability.php` — lists the spec §4 bullets no test cites (`/** covers §4.x.n */`); exits 1 while there are any.
````

3. **F40 (CD-5): the kit skills.** Replace

````text
- UI copy defaults to Dutch (`nl`) with English (`en`); code, comments and docs are English.
````

with

````text
- UI copy defaults to Dutch (`nl`) with English (`en`); code, comments and docs are English.
- Kit skills for common changes live in `.ai/skills/` (`add-tenant-resource`, `add-permission`, `add-settings-page`, `write-tenant-test`); `php artisan boost:update` publishes them to `.claude/skills/`.
````

4. **A84a (CD-16): the test-database guard.** Replace

````text
- Never run `migrate:fresh` or `db:wipe` against the development database; tests use their own databases.
````

with

````text
- Never run `migrate:fresh` or `db:wipe` against the development database; tests use their own databases.
- The tests refuse to run unless `DB_DATABASE` is `:memory:`, a name ending in `_test`, a file under `storage/framework/testing` or the `DB_TEST_DATABASE` given to `composer test:pgsql`, and they refuse a non-empty `DB_URL` (`Tests\TestCase`); never relax that guard.
````

`config/trustedproxy.php` (a comment only; Laravel's `TrustProxies::setTrustedProxyIpAddresses()` trusts every proxy by itself only on Laravel Cloud and on `*.on-forge.com` and `*.on-vapor.com` hosts):

1. **A27: the docblock names the hosts Laravel trusts by itself.** Replace

````text
    | $request->ip(), so a proxied production must set this, or every visitor
    | shares the proxy's address. Empty means Laravel's default: no proxies
    | are trusted, except on Laravel Cloud, Forge and Vapor hosts.

````

with

````text
    | $request->ip(), so a proxied production must set this, or every visitor
    | shares the proxy's address. Empty means Laravel's default: no proxy is
    | trusted, except on Laravel Cloud and on *.on-forge.com and *.on-vapor.com
    | hosts, so a Forge or Vapor site on its own domain must set this too.

````

- [ ] **Step 6: The spec**

`docs/superpowers/specs/2026-10-10-skv3-design.md`. First run `php scripts/traceability.php | tail -1` and note the line (`90 bullets`, `0 problems`). The spec marks as-built notes with "→"; every note below follows that style. Apply each replacement:

1. **F46: the spec is accepted.** Replace

````text
- **Status:** Draft, awaiting owner review
````

with

````text
- **Status:** Accepted for v1 (M8a); amended in place by the build decisions DEC-25…DEC-36 in `docs/decisions.md`
````

2. **§4.9.2 (Task 15b note): the mail check names the mailer.** Replace

````text
mail (SMTP probe only when `MAIL_MAILER=smtp`, else info),
````

with

````text
mail (SMTP probe only when `MAIL_MAILER=smtp`; any other mailer passes with a message that names it and opens no socket, DEC-36 (24) (d)),
````

3. **§4.9.4 (CD-4): the schedule as built.** Replace

````text
`model:prune` only once a model is prunable.
````

with

````text
`model:prune` only once a model is prunable. → Built (M7a–M8a): `model:prune` removes the expired handoff tokens; `backup-temp-cleanup` (hourly at :15) and `livewire-tmp-cleanup` (hourly at :45: Livewire's temporary uploads older than 24 hours, nothing on an S3 temporary disk; DEC-36 (4)) run on every server without an overlap lock; the `scheduler-heartbeat` runs every minute.
````

4. **§4.5 (CD-2): the address-lookup limit.** Replace

````text
→ Generic "this address can't be invited" message (no cross-organisation enumeration).
````

with

````text
→ Generic "this address can't be invited" message (no cross-organisation enumeration). → Every check of a typed address (the profile's email change, `users.edit`, `users.invite` and each row of the bulk page's *Check*) counts under `UserActionLimit::addressLookup()`, 500 a day per user, refused ones too, so an account cannot list which addresses have one (FM-18, DEC-36 (2)).
````

5. **§5.1: the real clone URL.** Replace

````text
4. **Clone/template**: `git clone …` → `composer setup`.
````

with

````text
4. **Clone/template**: `git clone https://github.com/frankcornet/skv3.git` → `composer setup`.
````

6. **§5.2 (CD-12): production without APP_ENV.** Replace

````text
In the Herd route the installer's later `migrate` is a no-op.
````

with

````text
In the Herd route the installer's later `migrate` is a no-op. → In production (an `APP_ENV` outside `local`, `testing` and `staging` in `.env` or the process environment, or none in any source, which Laravel treats as `production`; DEC-33, DEC-36 (12)) it only prepares `.env`, `APP_KEY` and the generated secrets.
````

7. **§5.3.3 step 1 (D63): only the real storage link counts.** Replace

````text
the link left to make is `manual('php artisan storage:link')`.
````

with

````text
the link left to make is `manual('php artisan storage:link')`. → Only a `public/storage` that resolves to `storage/app/public` counts (M8a, D63): a wrong or dangling link is replaced with `storage:link --force`, and a directory or a file in its place is never removed (the manual result asks to move it aside).
````

8. **§5.3.3 step 10 (K9): a re-run reaches the isolate.** Replace

````text
in the browser this is always `manual(...)` because isolate restarts php-fpm.
````

with

````text
in the browser this is always `manual(...)` because isolate restarts php-fpm. → The step is complete only while Herd serves the site with PHP 8.4 or newer, so a re-run on a site served with an older PHP reaches the isolate (K9, DEC-36 (23)).
````

9. **§5.3.4 (D57, L7b-15): the access gate's header list.** Replace

````text
a loopback `REMOTE_ADDR` (`127.0.0.0/8`, `::1`) and no header a proxy or tunnel adds (`X-Forwarded-*`, `X-Expose-*`, `CF-*`, `Forwarded`, `Via`, `X-Original-Host`, `X-Real-IP`, `True-Client-IP`; DEC-24, RL-4, L7b-14);
````

with

````text
a loopback `REMOTE_ADDR` (`127.0.0.0/8`, `::1`; an IPv4-mapped `::ffff:` address is not loopback) and no header a proxy or tunnel adds (`X-Forwarded-*`, `X-Expose-*`, `CF-*`, `Tailscale-*`, `Forwarded`, `X-Forwarded`, `Forwarded-For`, `Via`, `X-Original-Host`, `X-Original-URL`, `X-Host`, `X-Real-IP`, `True-Client-IP`, `X-Client-IP`, `Client-IP`, `X-Cluster-Client-IP`, `Fastly-Client-IP`; DEC-24, RL-4, L7b-14, M7c T10 #5 in M8a);
````

10. **§5.4 (B21, D63): the safe repairs as built.** Replace

````text
`--fix` performs only safe repairs (`permissions:sync`, flag/role drift, `storage:link`, `core.hooksPath`, asset build).
````

with

````text
`--fix` performs only safe repairs (`permissions:sync`, flag/role drift, `storage:link`, `core.hooksPath`, asset build). → Built (M7a–M8a): `storage:link` gets `--force` only over a wrong or dangling link and never removes a directory or a file; a missing or short `HEALTH_CHECK_KEY` is replaced by a random 40-character key, a blank `BACKUP_ARCHIVE_PASSWORD` is generated while the backups module is on, and a setup token left after setup is deleted (B21).
````

11. **§5.4 (E3): gd with WebP.** Replace

````text
- PHP ≥ 8.4; `ext-gd` present; `APP_KEY` set.
````

with

````text
- PHP ≥ 8.4; `ext-gd` present (→ warn when it cannot write WebP, which profile photos need; M8a); `APP_KEY` set.
- → `public/storage` resolves to `storage/app/public` (warn while it is missing; fail for a link elsewhere, a dangling link, a directory or a file; M8a, D63).
````

12. **§5.4 (DEC-36 (24) (d)): the mail row.** Replace

````text
- [`MAIL_MAILER=smtp`] the configured `MAIL_PORT` is listening; [log] info line.
````

with

````text
- [`MAIL_MAILER=smtp`] the configured `MAIL_PORT` is listening; [log] info line (→ any other mailer passes with a message that names it, without a probe; DEC-36 (24) (d)).
````

13. **§6.4 (CD-3): the link is bound to the password.** Replace

````text
Session cookies stay host-only.
````

with

````text
Session cookies stay host-only. → Bound to the password (M8a, DEC-36 (3)): the token row stores `SessionGuard::hashPasswordForCookie()` of the user's password hash (`handoff_tokens.password_hash`), and a missing or different value is refused, so a password change or reset on any path ends the links issued before.
````

14. **§6.5 (CD-13): color() on the badge enums.** Replace

````text
`label()` (and `color()` for badges) on status, role, permission and activity enums.
````

with

````text
`label()` on status, role, permission and activity enums, and `color()` on the enums shown as a coloured badge (`UserStatus`, `CheckStatus`, `Role`). → Narrowed in M8a (CD-13, DEC-36 (13)): `Permission` and `ActivityEvent` are never shown as a coloured badge, so they have no `color()`.
````

15. **§6.6 sessions (CD-18): this device's new session id.** Replace

````text
During an impersonation the section is hidden and every request to it answers 403.
````

with

````text
During an impersonation the section is hidden and every request to it answers 403. → M8a (CD-18, DEC-36 (18)): "log out other devices" also gives this device a new session id inside the same transaction (`session()->migrate()`, and its `sessions` row moves to the new id), so a copy of this browser's session cookie is signed out too; the CSRF token stays.
````

16. **§6.6 second factor (E35): no callout during an impersonation.** Replace

````text
and they sign in with the passkey (DEC-35, final review).
````

with

````text
and they sign in with the passkey (DEC-35, final review). → M8a: during an impersonation the security page shows no second-factor callout (E35).
````

17. **§6.6 bulk (E66): the header rule.** Replace

````text
a header row is optional and recognised only by its names, a cell containing `mail` and `locale`, `language`, `lang` or `taal`;
````

with

````text
a header row is optional and recognised only by its names: a first row with no `@` in any cell and a cell that contains `mail` without a dot or is `locale`, `language`, `lang` or `taal`;
````

18. **§6.6 bulk (CD-2, E12, E16, CD-4): as built in M8a.** Replace

````text
Livewire's upload endpoint takes one file of at most 2 MB from signed-in users only, 10 a minute and 60 an hour per user (DEC-35, final review).
````

with

````text
Livewire's upload endpoint takes one file of at most 2 MB from signed-in users only, 10 a minute and 60 an hour per user (DEC-35, final review). → M8a (DEC-36 (2), (4), (23)): *Check* counts one address lookup per row (§4.5); an unexpected failure halfway through *Send* is reported, and the rows it did not reach are skipped as "Not sent: something went wrong." and given back to the limit; an abandoned upload is removed by the hourly `livewire-tmp-cleanup` after 24 hours (§4.9).
````

19. **§6.6 avatar (E65): the original rule restored.** Replace

````text
`accept="image/jpeg,image/png,image/webp"`, `image|mimes:jpeg,png,webp|max:2048|dimensions:max_width=4096,max_height=4096`, `flux:avatar` preview.
````

with

````text
`accept="image/jpeg,image/png,image/webp"`, `image|max:2048`, `flux:avatar` preview.
````

20. **§6.6 avatar (E65, E55, E56): the as-built rule behind the marker.** Replace

````text
→ Built in M7d (DEC-35 (7)): the action reads the image header before decoding
````

with

````text
→ Built in M7d (DEC-35 (7)): the upload rule is `image|mimes:jpeg,png,webp|max:2048|dimensions:max_width=4096,max_height=4096` (DEC-36 (26)); the action reads the image header before decoding
````

21. **§6.6 avatar (E55, E56): M8a.** Replace

````text
and the temporary upload is deleted once it is processed, replaced or the photo removed (DEC-35, final review).
````

with

````text
and the temporary upload is deleted once it is processed, replaced or the photo removed (DEC-35, final review). → M8a: the action refuses any header type but JPEG, PNG and WebP before it decodes (E56), and removing a photo confirms only when there was one (E55).
````

22. **§6.7 CI (CD-9, CD-11): the Flux credentials.** Replace

````text
and never committed. The step is skipped when the secrets are absent (fork PRs); no job is skipped.
````

with

````text
and never committed. The step is skipped when the secrets are absent (fork PRs); no job is skipped. → M8a (CD-9, CD-11, DEC-36 (9), (11)): no step writes a global `auth.json`; the steps that need the licence (the check job's *Enable Flux Pro*; on the e2e postgres-pro row *Enable Flux Pro*, `skv:install` and `skv:doctor`) get `COMPOSER_AUTH`, built from the secrets with `php -r … getenv()` so the key never reaches a command line, and `composer audit` runs before Pro is installed. In the kit's own repository a check-job step refuses a committed Flux Pro (`if: github.repository == 'frankcornet/skv3'`).
````

23. **§7.2 (CD-10, CD-16, CD-17): the suite's guards.** Replace

````text
- `tests/Fixtures/e2e-answers.json` covers every `--answers` key; a test asserts the schema.
````

with

````text
- `tests/Fixtures/e2e-answers.json` covers every `--answers` key; a test asserts the schema.
- → M8a (DEC-36 (10), (16), (17), (23)): `Tests\TestCase` refuses a database that is not a test database; the Setup, Console, Backups, Health and System tests run on a recording process factory that fails a test on a catch-all fake or on a stray command, also one the code caught; the tests that need PostgreSQL skip through `Tests\Fixtures\RequiredPostgres`, which `REQUIRE_POSTGRES=true` turns into a failure; every §4 bullet has a frozen id and `scripts/traceability.php` (§4 intro).
````

24. **§7.4 (CD-7): the pre-commit hook as built.** Replace

````text
- **Git hooks**: `.githooks/pre-commit` (< 10 s: `pint --dirty`, phpstan on changed files); full suite in CI.
````

with

````text
- **Git hooks**: `.githooks/pre-commit` (< 10 s: `pint --dirty`, phpstan on changed files); full suite in CI. → Built as `pint --test` and phpstan on the staged PHP files, refusing a file that also has unstaged changes, so the hook checks exactly what is committed and never rewrites a file mid-commit (CD-7, DEC-36 (7)).
````

25. **§8 (CD-22): release-check lives outside tests.yml.** Replace

````text
All jobs live in `.github/workflows/tests.yml`; PHP versions come from `matrix.php`.
````

with

````text
All jobs but release-check live in `.github/workflows/tests.yml`; PHP versions come from `matrix.php`. → release-check gets its own workflow file `.github/workflows/release-check.yml`, `export-ignore`d and guarded by `github.repository`, because generated apps ship `tests.yml` unchanged; it also repeats the kit's Flux Pro guard on the tag (CD-22, M8b).
````

26. **§8 (Task 13): the jobs as built.** Replace

````text
The e2e job runs without Flux credentials in its default variant, proving the credential-free path.
````

with

````text
The e2e job runs without Flux credentials in its default variant, proving the credential-free path.

→ As built in M8a (DEC-36 (9)–(11), (23); the first real run is M8b): the check job has three rows (main: PHP 8.4 on PostgreSQL 18 with Herd's credentials, user `root` and trust, the PostgreSQL 18 client from PGDG with its `pg_dump` linked into `/usr/local/bin`, and `REQUIRE_POSTGRES=true`; sqlite-free; php85 on SQLite); the browser job has a `postgres:18` service with the same credentials and `REQUIRE_POSTGRES=true`; a `phase1` job checks the wizard after each of the four entry points; `phase1` and e2e run on pull requests that touch anything they install (`composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `.env.example`, `app/Setup/`, `app/Console/Commands/`, `bootstrap/`, `config/`, `database/seeders/`, `database/migrations/`, `routes/setup.php`, `resources/setup/`, the setup views, `scripts/e2e-local.sh`, `tests/Fixtures/e2e-answers.json` and the workflow itself); *Phase 1 assertions* diff `tests/` on every row, and the `boost:update` count is checked again after `skv:install` (exactly one with Boost, at most one without); `scripts/e2e-local.sh` takes `--flags=` and `--db=` for the variants.
````

27. **§8 Hardening (CD-9).** Replace

````text
caches (composer, npm, phpstan, rector, Playwright), `composer audit`.
````

with

````text
caches (composer, npm, phpstan, rector, Playwright), `composer audit` (→ before Flux Pro is installed; the licence reaches Composer only through per-step `COMPOSER_AUTH`, §6.7).
````

28. **§9 (CD-5): the kit skills as built.** Replace

````text
kit skills: `add-tenant-resource`, `add-permission`, `add-settings-page`, `write-tenant-test`.
````

with

````text
kit skills: `add-tenant-resource`, `add-permission`, `add-settings-page`, `write-tenant-test` (→ built in M8a as `.ai/skills/<name>/SKILL.md`, published by `boost:update` to the git-ignored `.claude/skills/`; CD-5, DEC-36 (5)).
````

29. **§9 (CD-5): the MCP servers approved.** Replace

````text
`.claude/settings.json`: superpowers via `extraKnownMarketplaces` + `enabledPlugins`; MCP servers Boost + Herd;
````

with

````text
`.claude/settings.json`: superpowers via `extraKnownMarketplaces` + `enabledPlugins`; MCP servers Boost + Herd (→ approved with `enabledMcpjsonServers: ["laravel-boost", "herd"]`, M8a);
````

30. **§10 (CD-6): the manual is HTML; the licence.** Replace

````text
- `docs/handleiding.md`: Dutch end-user/admin manual (never under `public/`).
````

with

````text
- `docs/handleiding.html`: Dutch end-user/admin manual, one self-contained HTML page (never under `public/`; `DemoCredentialsExposureTest`). → Changed from `docs/handleiding.md` (CD-6, DEC-36 (6)).
- `LICENSE` (MIT; M8a).
````

31. **§11.2 (CD-1): the M8 split.** Replace

````text
| M8 | Release | Docs (§10), full CI matrix + e2e green, Herd deep link verified; then — only after explicit owner approval — GitHub/Packagist publication, `v1.0.0` tag, release-check job. |
````

with

````text
| M8 | Release | Docs (§10), full CI matrix + e2e green, Herd deep link verified; then — only after explicit owner approval — GitHub/Packagist publication, `v1.0.0` tag, release-check job. → Split (DEC-36 (1)): M8a release readiness, everything provable locally; M8b publication, the first CI runs, Packagist, the deep link verified, release-check and `v1.0.0`, only after the owner's approval. |
````

32. **§12 (1) (CD-8): the local approximation.** Replace

````text
Any `manual()` commands left are optional improvements listed in the summary, never needed for a working app.
````

with

````text
Any `manual()` commands left are optional improvements listed in the summary, never needed for a working app. → M8a records a local approximation (`scripts/e2e-local.sh --entry=herd`, from the `laravel new` start to the dashboard; free Herd approximated by SQLite with Herd Pro's services stopped); the measurement through the Herd GUI and the deep link is M8b (CD-8, DEC-36 (8)).
````

Run `php scripts/traceability.php | tail -1` again: the same `90 bullets` and `0 problems`, and the same cited and uncovered counts as before.

- [ ] **Step 7: The decision log**

`docs/decisions.md`. First the inline pointers (CD-14):

1. **DEC-27 (2), A86: the custom fortify.prefix.** Replace

````text
so a custom `fortify.prefix` or path would bring the unthrottled vendor routes back.
````

with

````text
so a custom `fortify.prefix` or path would bring the unthrottled vendor routes back. → Superseded, see DEC-36 (26): since 661e707 `routes/fortify.php` follows `fortify.prefix`, `fortify.domain` and the configured paths, so the kit's throttled routes replace Fortify's there too.
````

2. **DEC-33 Consequence, C37: the missing APP_ENV.** Replace

````text
An `APP_ENV` missing from every source still gets `skv:bootstrap`'s development half.
````

with

````text
An `APP_ENV` missing from every source still gets `skv:bootstrap`'s development half. → Closed in M8a, see DEC-36 (12).
````

3. **DEC-34 (14), K9.** Replace

````text
the fix adds `&& $this->runsSupportedPhp(...)` last and the `herd isolated` / `php -r` fakes in four test files)
````

with

````text
the fix adds `&& $this->runsSupportedPhp(...)` last and the `herd isolated` / `php -r` fakes in four test files; → closed in M8a, see DEC-36 (23))
````

4. **DEC-34 (16), FM-18.** Replace

````text
a probe limit is an M8 candidate.
````

with

````text
a probe limit is an M8 candidate. → Superseded, see DEC-36 (2): M8a counts every address lookup under `UserActionLimit::addressLookup()`.
````

5. **DEC-35 (1), E61: Intervention's DecoderException.** Replace

````text
on the GD driver; no Imagick.
````

with

````text
on the GD driver; no Imagick. → Superseded in part, see DEC-36 (26): `UpdateAvatar` also imports Intervention's `DecoderException`, to tell an image it cannot read from a server fault.
````

6. **DEC-35 (11), E65: the avatar rule.** Replace

````text
(e) §6.6's formula guard is broader than "a cell that starts with" (see (12)).
````

with

````text
(e) §6.6's formula guard is broader than "a cell that starts with" (see (12)). (f) → See DEC-36 (26): §6.6's avatar rule `image|max:2048` had been overwritten in place with the as-built rule; M8a restored it and put the as-built rule behind the → marker.
````

7. **DEC-35 (12) (a), E4: the handoff after a password change.** Replace

````text
is an M8 follow-up (it needs a migration).
````

with

````text
is an M8 follow-up (it needs a migration). → Closed in M8a, see DEC-36 (3).
````

8. **DEC-35 Consequence: what M8a closed.** Replace

````text
and publication. The deferred minors of the M7d reviews
````

with

````text
and publication. → M8a closed FM-18 (DEC-36 (2)), K9 and the GD note (DEC-36 (23)), the handoff gap (DEC-36 (3)), the upload clean-up (DEC-36 (4)) and the M7d minors it triaged for v1; the release check, the CI runs and publication are M8b (DEC-36's Consequence). The deferred minors of the M7d reviews
````

Then append DEC-36 at the end of the file, after DEC-35, with one blank line before its heading. The four "(filled in after execution)" markers stay as they are for the controller and Task 18.

````markdown
### DEC-36 — Release readiness build rulings (M8a)

**Context:** DEC-34's and DEC-35's Consequences deferred to M8 FM-18's probe limit (which must also cover the bulk page's "Check"), the M7c plan review's K9 (`HerdSiteStep::isComplete()` ignoring the PHP version), a doctor note for GD without WebP support, a handoff link that still signs in after a password change or reset, a scheduled clean-up of Livewire's temporary uploads, the carried minors of M2–M7d, the CI runs of the e2e and `phase1` jobs, the release check and publication. Spec §11.2 makes publication conditional on the owner's explicit approval. Before the plan, six triage reports re-checked every carried follow-up and the spec's §7–§12 on `5a621c3`, and one reconciliation turned them into 127 M8a items in 18 tasks, 14 M8b steps, 55 post-v1 items and 29 won't-fix items.
**Question:** What did M8a build to make skv3 ready for v1.0.0, which deviations from the spec and the plan did it record, and what is left for M8b and after v1?
**Answer:** Items (1)–(22) are the controller's decisions CD-1…CD-22 (`docs/superpowers/plans/2026-10-17-m8a-release-readiness.md`), taken under the owner's delegation of every non-destructive decision (2026-10-15, reaffirmed 2026-10-17), as built; the owner can overrule each of them. (23) lists the other items each task closed, (24) the deviations from the spec with their reasons, (25) the deviations from the plan and the controller's rulings, (26) the documentation and (27) the controller's success-criteria evidence run. (1) CD-1, the split: M8a is everything provable locally (Tasks 1–18); M8b is publication, the first CI runs and the release (see Consequence), because §11.2 makes publication conditional on the owner's explicit approval and nothing in M8b can run without a remote. (2) CD-2, the address-lookup limit (FM-18, D82 = E1, D80), which supersedes DEC-34 (16)'s "accepted for v1": `UserActionLimit::addressLookup()` (`address-lookup:<user id>`, 500 per 86 400 s, `attempt()`, never given back) counts every `AvailableEmail` check of a typed address before it runs. The profile's email change and an administrator's change on `users.edit` cost 1 only when the typed address (lower-cased) differs from the stored one, before `validate()`; on the profile the charge sits outside `emailChange()->around()`, so a refused address is not given back, and on `users.edit` a save parked for the RL-18 password confirmation and the save from the restored draft count one each. `users.invite` costs 1 after the format check and `organisationToInvite()`, before `invite()->around()`. The bulk page's *Check* costs `count($entries)` (every parsed row, invalid and repeated ones included) after the empty-list and `MAX_ROWS` guards; *Send*, which re-reviews only the `#[Locked]` rows a Check paid for, costs nothing. `organisations.create` (super admins only) and `RestoreUser` (no typed address) are not charged. The refusal is the generic "Too many attempts. Try again in :seconds seconds." on the field. The maximum must stay at least `InvitationList::MAX_ROWS`, because a full Check costs 100 at once; the constant is the one tuning point, and the per-user key keeps one member's probing from blocking an administrator. E1's `addressProbe()`, counted only for a refused address on the profile, was not built: "free" is information too. The README's sign-in sentences moved into their own *Sign-in limits* bullet, because those limits count per address and IP address (D80). (3) CD-3, handoff links bound to the password (E4 = DEC-35 (12) (a)): the new migration `0001_01_01_000010_add_password_hash_to_handoff_tokens_table` adds a nullable `handoff_tokens.password_hash` (string, 64) that holds `SessionGuard::hashPasswordForCookie()` of the user's password hash, the HMAC with `APP_KEY` that `AuthenticateSession` keeps in the session. `IssueHandoff` stores it (`HandoffToken::passwordBindingFor()`), and `HandoffController::userOf()` refuses a missing or different value (`HandoffToken::isBoundTo()`) before the host rule, with the same "This sign-in link is invalid or has expired." redirect, leaving the token unconsumed. So every write of `users.password` ends the links issued before: the security page, a reset, `skv:create-super-admin --password-stdin`, setup's `DatabaseStep`, the rehash of `Auth::logoutOtherDevices()` and a bcrypt rehash at sign-in; rotating `APP_KEY` ends links in flight too, which a 60-second link can afford. A link issued before the migration (null) is refused. The explicit delete in "log out other devices" stays. It is a new migration rather than an edit of `…000004`, because developer databases are already migrated and `migrate:fresh` is forbidden; until `php artisan migrate` runs there (CD-19), *Open*, the quick login and the slug-rename handoff fail on the unknown column. (4) CD-4, the upload clean-up (E5): `App\Support\Uploads\StaleTemporaryUploads::removeStale()` mirrors Livewire's own sweep (`FileUploadConfiguration`'s disk and directory, `STALE_AFTER_HOURS` = 24, nothing on an S3 temporary disk), and the ops schedule runs it as `livewire-tmp-cleanup` at `45 * * * *` on every server, without an overlap lock or module filter: each server sweeps its own local disk, 24 hours equals Livewire's threshold so no open tab loses an upload earlier than before, and :45 keeps clear of `backup-temp-cleanup` at :15. A file another sweep deleted between the listing and `lastModified()` (`UnableToRetrieveMetadata`) is skipped. On S3 the README points at `php artisan livewire:configure-s3-upload-cleanup`. (5) CD-5, the kit skills (§9; F40, F41): `.ai/skills/{add-tenant-resource,add-permission,add-settings-page,write-tenant-test}/SKILL.md`, each a short checklist that points at the `AGENTS.md` rules and the real files to copy; Boost 2.10 publishes them to the git-ignored `.claude/skills/` and `boost.json` lists them. They ship to generated apps (`ClaudeStep` runs `boost:install`), so they name no kit or site name and no milestone. `.claude/settings.json` gained `Bash(composer doctor)`, `Bash(npm run build:setup)` and `enabledMcpjsonServers: ["laravel-boost", "herd"]`, the `.mcp.json` keys the Claude step writes. `AiConfigTest` asks Boost's `SkillComposer` whether it finds each skill under its folder's name, and resolves every file, class and method the skills name. (6) CD-6, the manual's format (F47): the Dutch manual stays `docs/handleiding.html`, a self-contained page since dfe18df, instead of the `docs/handleiding.md` that §10 named; a conversion would be churn with no gain for a reader. §10 is amended. It is never under `public/`, which `DemoCredentialsExposureTest` guards (see (17)). (7) CD-7, the pre-commit hook (F15): `.githooks/pre-commit` runs `pint --test` and `phpstan analyse` on the staged PHP files and refuses a file that also has unstaged changes, instead of §7.4's `pint --dirty`, which would also format unstaged edits and rewrite files mid-commit; it checks exactly what is committed. §7.4 is amended. (8) CD-8, success criterion 1: M8a measures locally with `scripts/e2e-local.sh --entry=herd` (Herd's exact `laravel new` command, then the wizard), from the `laravel new` start, which stands in for *Create*, to the dashboard: configuration A with Herd Pro and PostgreSQL, configuration B with SQLite and Herd Pro's services stopped, recorded as a free-Herd approximation, each with `skv:doctor --json` (see (27)). The measurement as the spec words it, from *Create* in the Herd GUI and through the deep link, needs a package Composer can resolve, so it is M8b; a global vcs repository before publication would change the owner's global Composer configuration. (9) CD-9, Flux credentials in CI (B33 = C132): no step runs `composer config --global http-basic…` any more, which left the licence in `COMPOSER_HOME/auth.json` for every later step. The steps that need it export `COMPOSER_AUTH` for themselves, built from `FLUX_USERNAME` and `FLUX_LICENSE_KEY` with `php -r … getenv()`, so the key never reaches a command line: the check job's *Enable Flux Pro*, and on the e2e postgres-pro row *Enable Flux Pro*, `skv:install` (its Claude step runs `composer require --dev laravel/boost` in an app that now lists the fluxui repository) and `skv:doctor` (its `flux-pro-credentials` check runs `composer config http-basic.composer.fluxui.dev` once Pro is installed; added during planning, because the header's CD-9 named only the first two). `composer audit` runs before Pro is installed, because it audits the committed lock, which never holds Pro, and the two false comments were corrected. Accepted residual: on the postgres-pro row `skv:install`'s own children, `npm ci` among them, inherit `COMPOSER_AUTH` for that one step; pull requests from forks and Dependabot get no repository secrets. (10) CD-10, PostgreSQL required in CI (B80 = C133 = F16, C134 = F17): `Tests\Fixtures\RequiredPostgres::skips()` lets the three PostgreSQL-dependent tests (`WizardPageTest`'s "completes on PostgreSQL…", the browser test "sets a super-admin install up on PostgreSQL…" and `BackupRunTest`) skip only while `REQUIRE_POSTGRES` is not `true`; *Check (PostgreSQL)* and *Browser tests* set it, and `RequiredPostgresTest` makes every such skip go through the fixture. The check job's service and a new `postgres:18` service on the browser job use Herd's credentials (`root`, trust), which `Detector::postgresReachable()` probes; before, the check job's `postgres`/`postgres` service made the wizard test skip on the pgsql row too. A *PostgreSQL 18 client* step installs `postgresql-client-18` from PGDG and links its `pg_dump` into `/usr/local/bin`, because `BackupRunTest` finds it through `Toolchain`'s fixed PATH, which neither `GITHUB_PATH` nor a process `PG_DUMP_BINARY_PATH` reaches, and the runner's `/usr/bin/pg_dump` wrapper picks an older version. (11) CD-11, the kit-repository Flux Pro guard (B108 = C35 = C119 = C135): a check-job step with `if: github.repository == 'frankcornet/skv3'` fails when the committed `composer.json` or `composer.lock` names `flux-pro` or `composer.fluxui.dev`, or `composer.json` carries `extra.skv`; generated apps skip it and may commit the mark (CD-13 of M7b). `tests.yml` already named the kit (`--using=frankcornet/skv3:dev-ci`), and the test derives the name from `composer.json`. The release-check job repeats the guard on the tag (M8b). (12) CD-12, a missing `APP_ENV` (C37, DEC-33's Consequence): `Bootstrap::isProduction()` treats a project where no source names an `APP_ENV` (no line in `.env`, a blank value, nothing in `getenv()` or `$_SERVER`) as production, as Laravel's own default does (`config/app.php`); `run()` copies `.env.example` (`APP_ENV=local`) before it asks, so a fresh install is unaffected and only a server `.env` written without `APP_ENV` changes behaviour: `skv:bootstrap` then only prepares `.env`, `APP_KEY` and the generated secrets. (13) CD-13, badge colours (A62 = C137 = D88): `App\Authorization\Role::color()` (super admin violet, organisation admin zinc) replaces the literal `color="violet"` at both super-admin badge sites (`users.index`, `users.edit`), next to the existing `UserStatus::color()` and `CheckStatus::color()`; nothing a user sees changes. `Permission` and `ActivityEvent` are shown as plain badges or text, never coloured, so they get no `color()`, and §6.5 is narrowed to the enums shown as a coloured badge. The dashboard's "Your roles" card still shows the super-admin label as a plain badge (post-v1). (14) CD-14, the shared documentation files: each behaviour task wrote the README section and the CHANGELOG line of its own change and regenerated `AGENTS.md` after its guideline edit (Tasks 1 and 3); Task 17 alone mirrored the changes into `docs/handleiding.html`, wrote this entry, amended the spec and `CLAUDE.md` and made one consistency pass over the CHANGELOG (see (26)); older decisions got only an inline pointer. (15) CD-15, the gate template (E6, E7): the `composer.lock` package check is `git diff <base> -- composer.lock | grep -E '^[-+] {12}"name": '`, which matches packages and not `authors[].name`. The browser suite's one warning without details in the agent JSON reporter is pest-plugin-browser's `Trace::cleanup()`, an `@rmdir()` of the missing `tests/Browser/Traces` (`vendor/pestphp/pest-plugin-browser/src/Support/Trace.php:139` in 5.1.2), which laravel/pao counts but cannot detail; a terminal or CI run shows no warning, so it is not a finding (won't fix, B58 = C128; creating the directory would only change the warning). Task 18 removes the two stale setup sandboxes of 2026-10-13 under `storage/framework/testing/setup` (E7). (16) CD-16, the test-database guard (A84a): `Tests\TestCase::setUp()` reads the environment (`Env::get()`) before `parent::setUp()` boots the application, because `RefreshDatabase` runs inside it, and refuses to run unless `DB_DATABASE` is `:memory:`, matches `/_test(_test_\d+)?$/` (a parallel worker's suffix included), is a path under `storage/framework/testing`, or equals `DB_TEST_DATABASE`; a non-empty `DB_URL` is refused too, because its database would win over `DB_DATABASE` (`phpunit.xml` sets `DB_URL=""`). `phpunit.xml` keeps no `force` on `DB_*`, because `composer test:pgsql` and CI pass their own. `TestDatabaseGuardTest` proves the order with a child Pest run on an SQLite path in a directory that does not exist. (17) CD-17, traceability (F54, success criterion 3, and F57, criterion 5): the 90 top-level bullets of spec §4 carry frozen ids `§4.0.1`…`§4.12.2`, never renumbered (a new bullet takes the next free number of its subsection, a removed id is never reused). Only the `covers` lines of a `*Test.php` file's first docblock count; `§4.x.n` cites a bullet and a plain `§4.x` cites none. `php scripts/traceability.php [--spec=…] [--tests=…]` lists the uncovered bullets, the cited ids §4 does not have and the bullets without a valid id, and exits 1 on any; `TraceabilityTest` runs it against the real spec and against planted projects. After Task 15 every bullet is cited (100 test files' `covers` lines name bullet ids). Read clause by clause, 21 bullets had a clause no test would catch: Task 15b closed 19 of them, Task 10 (A20) and Task 11 (A43) the other two. Four clauses kept no test of their own, each covered by another: a passkey surviving a slug rename and the origin reset on `TenantForgotten` (both follow from the fixed apex relying-party id that `PasskeyTest` pins), the recovery codes' show/hide toggle (client-side Alpine state), the System page and `/health-check` sharing one cache (both call `HealthReport::cached()`, which `HealthReportTest` covers) and the index name `users_email_unique` (`UserModelTest` runs the behaviour on both drivers). `DemoCredentialsExposureTest` is Appendix A R2's regression test: nothing under `public/` (the uploads behind `public/storage` aside) is a manual or an HTML page or names a demo account, and the manual never puts a demo address and the demo password on one line; with it every Appendix A finding has a regression test. (18) CD-18, "log out other devices" rotates this device's session (E21): inside the transaction, after `Auth::logoutOtherDevices()`, the action calls `session()->migrate()` and moves this device's `sessions` row to the new id (`UPDATE sessions SET id = <new> WHERE id = <old>`), then deletes the user's other rows and unused handoff tokens; the CSRF token stays, so the page's other components keep working. It was revised during planning from `migrate(true)`, which under the database driver deletes the row at once, so the list the same Livewire update renders lost "This device". A request with the old cookie finds no row and starts a guest session; with another session driver the old session stays stored, but it holds the old password hash, which `AuthenticateSession` refuses. `LockOrderTest` pins the write order users → sessions → handoff tokens → activity (E26). (19) CD-19, the developer database: Task 4's migration reaches the test databases through `RefreshDatabase`; the owner applies it to the developer database with `php artisan migrate`, which is not pre-approved. (20) CD-20, the licence: `LICENSE` is MIT, "Copyright (c) 2026 Frank Cornet", as `composer.json` and the README already declared (F59); reported to the owner afterwards. (21) CD-21, the manual's sources: every *Bronnen* block and source line of `docs/handleiding.html` names files without line numbers (E67), because line references went stale after every milestone; the spec's line numbers became section numbers and the decision log's became decision numbers. (22) CD-22, the deep link and release-check: the deep link `https://herd.laravel.com/new/frankcornet/skv3`, which already answers 200, is in the README and the manual (B30 = F46, C131's documentation half) and is verified in M8b; release-check is written in M8b, in its own `export-ignore`d workflow file guarded by `github.repository`, never in `tests.yml`, which generated apps ship unchanged (the e2e diff asserts it) and which must never run a kit-only Packagist check. (23) The other items each task closed (the triage ids of the planning run's reconciliation): Task 1 runs every RL-12 test (Setup, Console, Backups, Health, System) on `Tests\Fixtures\RecordingProcessFactory`, which records a stray before `PendingProcess` throws for it and, after the test, fails it on a stray (also one a `catch (Throwable)` swallowed) or on a fake pattern that matches a command nobody names (L7b-76, -77, -78); the recorder found two hidden strays, now faked by name (`DoctorStepTest`'s `node -v`, `InstallCommandTest`'s `herd services:list --json` and `herd tld`); `BackupRunTest` turns the guard back on right after its real `pg_dump --version` probe, in a `finally` (L7b-71, -80); two `HerdSiteStepTest` cases were strengthened (L7b-21, with a closure flag, because a fake that throws is never recorded; L7b-22, which also bans the quoted `'--site=<name>'` form). Task 2 pinned seven kit rules no test held: A5, the strict default re-read from `config/tenancy.php` with `TENANCY_STRICT` cleared everywhere `env()` reads; A6, the debug-call rule also catching `\dd()` and the `dd*`/`dump*` helpers, over Blade, the component classes and the route files; A8, a second `permissions:sync` (and the run after a drift repair) leaving every row as it was and issuing no INSERT or UPDATE; A25, `CentralContext` named only in `app/Tenancy/` and `TenancyServiceProvider`, an import included; D77, `users.is_super_admin` written only by `SetSuperAdmin` (factories and `InviteUser`'s `false` exempt); C110, the exec ban widened to `bootstrap/` (without `bootstrap/cache/`) and `scripts/`, plus shell-string commands (`fromShellCommandline()`, a string to `Process::run()`, `start()` or `command()`); C141, none of the eight Blaze-folded Flux keys in `lang/*.json`. Task 5 also lower-cased the platform slot mid-sentence ("Unknown browser on unknown platform", E17), strengthened the sessions tests (E19 an expired row, E23 the emptied password field) and rewrapped the action's docblock (E25). Task 6 hid the security page's second-factor callout during an impersonation (E35), made the recovery-code reader accept only a non-empty list of strings and catch the `unserialize()` warning (`DecryptException|ErrorException`, without an import in the namespace-less component, where `use ErrorException;` is itself a warning; D17, D18), and pinned E31, E34, E37, E40, E41 and E43. Task 7 also made the bulk page resolve the organisation before it validates the batch (E11; applied in `send()` too without a test, because the order cannot be observed there), catch any `Throwable` around the send loop, report it and skip the remaining rows as "Not sent: something went wrong." while the `finally` gives their attempts back (E12, E16), dropped its dead `<flux:error name="organisation" />` (E14; no failing test, because it rendered nothing), made `RemoveAvatar::handle()` return whether it removed a photo so the page confirms only then (E55), made `UpdateAvatar` refuse any header type but JPEG, PNG and WebP before decoding (E56) and moved the "no EXIF in the stored WebP" assertion into a test that runs without ext-exif (E59). Task 8 closed K9 (D85 = E2 = D55; DEC-34 (14) and DEC-35's Consequence): `HerdSiteStep::isComplete()` also requires `runsSupportedPhp()`, last and inside its `try`, so a re-run on a site Herd serves with PHP 8.3 reaches the isolate; only one existing test needed the `herd isolated` and `php -r` fakes, not the four files the triage expected. It closed D63: setup and the doctor accept `public/storage` only when it resolves to `storage/app/public`; a wrong or dangling link is replaced with `storage:link --force`, a directory or a file is never removed, and the doctor's `storage-link` row, which passed for anything in that place, now fails for anything but the link and still warns while it is missing. It closed E3 and DEC-35's GD note: the `gd` row warns when `imagetypes()` lacks `IMG_WEBP`. Task 9 kept the Herd stage's own error when `strand()` fails too, reporting the second one (L7b-28), refused a garbled step pid (`ctype_digit`, 1 < pid ≤ 2147483647; L7b-33), extended `DirectRequest`'s deny-list with `x-client-ip`, `client-ip`, `x-cluster-client-ip`, `fastly-client-ip`, `x-host`, `x-original-url`, `x-forwarded`, `forwarded-for` and the prefix `tailscale-` (D57, M7c T10 #5; it stays a deny-list, DEC-33's residual stays accepted), and strengthened L7b-10, -15, -49, -50, -55, -60, -67 and -74 and L-46 and L-49. Task 10 wrote the tests DEC-28 and DEC-29 left for protections that work but that no test would miss (A20 the two-factor and passkey limiters counting failures only; A21 a member's passkey refused on another organisation's host by the host rule alone; A22 the two-factor challenge re-applying the host rule; A23 a released worker job leaving no tenant bound; A24 `AuthenticateSession` on Livewire updates; A42 impersonating a member with confirmed two-factor authentication; A45 the lock-out guard deciding on the locked row), and made `PasswordResetHostTest`'s trusted-proxy case see the forwarded port and reset Symfony's static trusted proxies after each test (D13, D16). Task 11 pinned A43, A71, A72, A73, A74, A76, A84o, A84p, A84r (the window half), D34, D35, D36, D50 and E44, added `Role::color()` (see (13)) and made `LoginTrend` independent of `Date::use(CarbonImmutable::class)` (C138 = D89). Task 12 marked the app layout (`data-test="app-main"`), so `SmokeTest` tells a page from an error page at the same path (A77), waits for the sign-in redirect (A84s) and for the saved appearance with a bounded poll (A78), and covered the sessions modal, the free photo input and the bulk flow in Chromium (E69) and the Pro file items' escaping of a client-chosen name (E74, Pro only). Task 13 also widened the `phase1` and `e2e` path filter (B107 = F25), made *Phase 1 assertions* diff `tests/` on every row and count `boost:update` again after `skv:install` (B26 = C130 = F23), pinned the hardening and the passkeys matrix (F35, A80), gave `scripts/e2e-local.sh` the options `--flags=` and `--db=` (F56's script half) and made `CiWorkflowTest` read the parsed workflow. Task 16 ships `README.md` and `CHANGELOG.md` in dist installs (F50: `.gitattributes` export-ignored them, and a workflow that does not exist), added the Packagist metadata without `authors`, because every generated app keeps the kit's `composer.json` (F58), and the `LICENSE` (F59); the metadata keys are not part of Composer's content-hash, so `composer.lock` did not change. (24) The deviations from the spec, each with its reason: (a) §10's `docs/handleiding.md` is `docs/handleiding.html` (CD-6). (b) §7.4's `pint --dirty` is `pint --test` on the staged files (CD-7). (c) §6.5's `color()` "on status, role, permission and activity enums" is narrowed to the enums shown as a coloured badge (CD-13). (d) §4.9's mail check "else info": `MailCheck` has no info status, and any mailer but `smtp` passes with a message that names it and opens no socket, which is what "info" meant; §4.9 and §5.4 are amended. (e) §8's table put release-check among the jobs of `tests.yml`; it gets its own `export-ignore`d workflow file in M8b (CD-22). (f) §12 (1)'s measurement from *Create* in the Herd GUI is approximated locally in M8a and measured as worded in M8b (CD-8). (g) §6.7's CI line "`composer config http-basic…` from `FLUX_USERNAME` / `FLUX_LICENSE_KEY`" became per-step `COMPOSER_AUTH` (CD-9). The access gate's header list (§5.3.4), the doctor's checks and repairs (§5.4), the schedule (§4.9) and the handoff (§6.4) grew as built and were amended without a deviation. (25) The deviations from the plan and the controller's rulings. While the plan was written, its writers re-verified every item on `5a621c3` and recorded these changes to the triage: the header's CD-3, CD-9, CD-16 and CD-18 were revised (CD-9 gained `skv:doctor`, CD-16 the `DB_URL` refusal, CD-18 the moved row instead of `migrate(true)`); E1's `addressProbe()` gave way to CD-2's `addressLookup()`, and the existing bulk test "counts nothing for a list larger than what is left…" starts its rate-limiter mock after the Check, whose lookup now increments; the K9 fakes needed one test, not four files; C134 reaches `BackupRunTest` through a `/usr/local/bin` link, not PATH or `PG_DUMP_BINARY_PATH`; Task 13 also created `Tests\Fixtures\RequiredPostgres` and `RequiredPostgresTest`, which the ownership table did not list, and pins the `e2e-local.sh` options in `CiWorkflowTest` rather than `ComposerScriptsTest`; Task 3 created the CHANGELOG's `### Security` heading, which `[Unreleased]` lacked; L7b-10's triage claim that a reordered gate passed today was wrong, so its mutation sets the header only on responses that are no client error; E44's mutation "throttle after `can:`" cannot be made, because Laravel's middleware priority runs `ThrottleRequests` before `Authorize` in any order (so a refused export uses a slot), and the mutation is a limiter that counts only users who may export; D50's mutation sits in `tests/Support/FluxProLint.php`, because the lint and the stripper share `FluxProBranches::branches()`; A43's old host stays at 404 through two guards (`OrganisationObserver::forgetSlugs()` and `SubdomainResolver`'s slug re-check), so its mutation removes both; A78's `html.dark` proves nothing about the save (Flux sets it before the request leaves), so a bounded database poll was added; E74's file name `<b>x</b>.csv` holds a `/` that no browser sends, so the test uses `<img src=x onerror=alert(1)>`; pest-plugin-browser 5.1.2's in-process server keeps a `files[]` multipart part under that flat key and turns bytes that are not UTF-8 into `?`, so the browser suite reads uploads again with `Tests\Fixtures\NestUploadedFiles` and fakes Livewire's `tmp-for-tests` disk (an upstream report is post-v1); E5's sweep also skips a file deleted between the listing and the read. The deviations from the task briefs during the run, the controller's rulings in the ledger, the final review, its fix rounds and the rulings after it: (filled in after execution). (26) The documentation (Task 17): the README's status line (M8a done, M8b after the owner's approval), the Herd deep link and the real clone URL without the "once it is published" caveat, the setup gate's full header list and the IPv4-mapped address, `skv:bootstrap` without any `APP_ENV` (also in the production checklist), the doctor's `storage-link` and `gd` rows and `storage:link --force`, the second factor during an impersonation, recovery codes in a form Fortify never writes, the CSV header rule (E66), the hourly upload clean-up and a bulk send that fails halfway, `UpdateAvatar`'s `DecoderException` (E61) and type check, an impersonation that ends with the session (A29), the folded Flux copy that stays English (A60), the photos outside the backups (E60, also in the production checklist), the kit skills and the Claude settings, the test-database guard, the focused browser-test command (D27), `REQUIRE_POSTGRES`, the traceability script and the licence; `docs/handleiding.html` says the same in Dutch, adds the address-lookup and sign-in limits, the password-bound handoff link, this device's new session id and the M8a row, and names M8b in its status, roadmap and known limitations; its source lines name files without line numbers (CD-21); `CLAUDE.md` names the focused browser-test command, the traceability script, the kit skills and the test-database guard; the kit guideline gained the `covers` and test-database rules, `RequiredPostgres`, the central-context and super-admin-write rules, the password-bound handoff, the folded Flux keys, the upload clean-up as a backstop, the browser-test marker, command and upload pattern, the widened exec ban, and scoped two rules E68 found worded kit-wide (`X-Livewire` decides no access; audit and log downloads, not every download, are GET routes), and `AGENTS.md` was regenerated from it; `config/trustedproxy.php`'s docblock names the hosts Laravel trusts by itself (A27); the spec's header (accepted), §4.5, §4.9, §5.1, §5.2, §5.3.3, §5.3.4, §5.4 (B21), §6.4, §6.5, §6.6, §6.7, §7.2, §7.4, §8, §9, §10, §11.2 and §12 were amended, and §6.6's avatar rule, overwritten in place in M7d, was restored to `image|max:2048` with the as-built rule behind the → marker (E65); the CHANGELOG pass folded the two lines on `skv:bootstrap` in production into one, added the test suite's guards, the traceability script with the R2 test and the sign-in trend fix (Tasks 1, 11 and 15 add no line of their own), and points the old header list at the Security line that extends it. Amended in place: DEC-27 (2) (the custom `fortify.prefix` is stale since 661e707, A86), DEC-33's Consequence (the missing `APP_ENV`), DEC-34 (14) (K9) and (16) (FM-18), DEC-35 (1) (`DecoderException`, E61), (11) ((f), the avatar rule, E65), (12) (a) (the handoff after a password change) and its Consequence. (27) The controller's success-criteria evidence run (Task 18): (filled in after execution).
**Evidence:** `tests/Feature/Tooling/{TestDatabaseGuard,Traceability,DemoCredentialsExposure,RequiredPostgres,PackageMetadata,AiConfig,CiWorkflow}Test.php`, `tests/Feature/Setup/StrayProcessGuardTest.php`, `tests/Arch/{KitRules,TenancyRules,AuthorizationRules,MigrationRules}Test.php`, `tests/Feature/{LangCoverage,ConfigDefaults,AppDefaults}Test.php`, `tests/Feature/Auth/{ActionLimits,Handoff,EnsureSecondFactor,LoginThrottle,Passkey,HostScopedLogin,AuthenticateSession,PasswordResetHost}Test.php`, `tests/Feature/Invitations/BulkInvitationsTest.php`, `tests/Feature/Settings/{Sessions,RecoveryCodes,Avatar}Test.php`, `tests/Feature/Authorization/{LockOrder,SyncPermissions}Test.php`, `tests/Feature/Uploads/StaleTemporaryUploadsTest.php`, `tests/Feature/Scheduler/{Schedule,ScheduledTasks}Test.php`, `tests/Feature/Setup/Steps/{HerdSiteStep,EnvironmentStep}Test.php`, `tests/Feature/Setup/Doctor/ChecksTest.php`, `tests/Feature/Console/ProductionGuardsTest.php`, `tests/Feature/Setup/Wizard/{WizardRunner,SetupAccess,HerdStage}Test.php`, `tests/Feature/Setup/SetupModeTest.php`, `tests/Unit/RoleTest.php`, `tests/Feature/Users/RoleBadgeTest.php`, `tests/Feature/Dashboard/LoginTrendTest.php`, `tests/Browser/{AccountExtras,Smoke,Login,Appearance}Test.php`, the mutation tables of every task, and `php scripts/traceability.php` (90 bullets, 90 cited, 0 uncovered, 0 problems); the whole suite on SQLite, on PostgreSQL and with `MODULE_IMPERSONATION=false MODULE_PASSKEYS=false`, the browser suite and `npm run build:setup && git diff --exit-code resources/setup/setup.css` per task, and the run's task reports, review reports and progress ledger: (filled in after execution).
**Consequence:** FM-18, the handoff after a password change or reset, K9, the GD note, the upload clean-up and the carried M2–M7d follow-ups triaged for M8a are closed; criteria 2, 3 and 5 are met locally, criteria 1 and 4 have their local half (see (27)). M8b, in this order and only after the owner's explicit approval of each marked step: (1) the owner approves publication [approval]; (2) the public GitHub repository `frankcornet/skv3`, marked as a template for §5.1's fourth entry [approval]; (3) push `main`, no tag yet [approval]; (4) the optional repository secrets `FLUX_USERNAME` and `FLUX_LICENSE_KEY`, entered by the owner [approval]; (5) the first CI run and a fix-forward loop through pull requests until it is green: the workflow is valid, the check matrix with the PostgreSQL service, the PostgreSQL 18 `pg_dump` and the scoped credentials (and `BackupRunTest` and the PostgreSQL wizard test running, not skipping), the browser job including `*.localhost` on ubuntu (A82's `/etc/hosts` fallback only if it fails), `phase1` ×4 and `e2e` ×3 through `workflow_dispatch`, the Pro steps with the secrets, the kit guard's `if` and the widened path filter on a real pull request; (6) a branch-protection ruleset on `main`: pull requests only, no force push or delete, the three check rows, the browser job, `phase1` ×4 and `e2e` ×3 required and up to date with the base [approval]; (7) the package on Packagist with GitHub's auto-update hook [approval]; (8) `laravel new --using=frankcornet/skv3` from Packagist (a dist install: README, CHANGELOG and LICENSE present) reaches the wizard; (9) the Herd deep link opened, and *Create* → wizard → dashboard timed in both configurations through the Herd GUI (criterion 1 as worded, an owner action); (10) the release-check job in its own `export-ignore`d workflow file guarded by `github.repository`, on a release or tag push and `workflow_dispatch`: `laravel new --using=frankcornet/skv3` from Packagist, the installed reference equal to `git ls-remote origin main`, and the tagged `composer.json` and `composer.lock` free of `flux-pro` and `composer.fluxui.dev` (D115), green on dispatch, through a pull request; (11) the CHANGELOG cut (`## [1.0.0] - <date>`, a new empty `[Unreleased]`, compare and release links; F49), and the README status line, the manual's status, roadmap and known limitations updated for the release, through a pull request; (12) the tag `v1.0.0`, and a GitHub release if wanted [approval]; (13) release-check green after the tag (criterion 4), recorded in an addendum to this entry. Post-v1, each with why it can wait: tests and test ergonomics: namespaced test helpers (A26: a rename that touches hundreds of files), the browser wrong-host test showing valid credentials (A84b: HTTP tests guard the rule), `usePasskeysModule(false)` leaving the listener (A84c: CI also boots with the module off), the appearance "no audit entry" claim (A84d: cosmetic), the banner on the auth pages found by grep (A84g: low value), element-level branding asserts (A84h: cosmetic), the partial-permission navigation actor (A84i: the routes still answer 403), the settings link in the user menu (A84j: low value), the duplicate `LangFilesTest` basename (A84l: cosmetic), error-page dataset rows for tenant-not-found and invitation-invalid (A84m: low value), the 403 locale before `SetLocale` (A84n: A76 covers the related half), the Fortify logout URI under a custom prefix (A84q: only under a non-default prefix), the Pro-branch comment case and listbox asserts (A84r's other half: cosmetic), the browser reset-link host (A84t: `PasswordResetHostTest` covers it), a lang sort-order and "je" lint (A84v: style), `PasskeysModuleTest`'s vacuous `fresh()` (D20: the 404 already pins it), branding on the apex login (D51: one code path), route coverage for `Authenticate::class` (E38: no route uses it); setup and wizard code: `TestDatabaseCheck` after a hand-edited `DB_DATABASE` (B109: no setup path produces it), a mailbox clean-up line in the e2e script (C40: a development leftover that only Herd's UI removes), a `useHerdStub()` helper (C52 = D93: duplication only), `HerdState::underLock()` (C59 = D95: duplication, every copy tested), computing `manualCommand()` once (C60 = D96: extra Herd calls on error paths only), the `SetupLock` inode re-check (C66 = D99: no race today), `HerdSiteStep::resultFor()` (C71 = D101: duplication), a pty test for `StreamTerminal::isTerminal()` (C83 = D103: the binding and the non-TTY branch are pinned, a pty would be a third real-process exception and is unreliable on CI runners), host normalisation in `WizardState` and `HerdState` (D58: fails closed), the step umask (D62: Herd's FPM uses 022, local only), `EnvFile` messages with the absolute path (D64: local, the developer's own path); rate limits, sign-in and security: `ThrottleKey` versus `shouldHashKeys(false)` (D2: the kit never turns hashing off), two-factor routes conditional on the Fortify feature (D3: two-factor is a fixed kit feature), Livewire limits for disabling 2FA, deleting a passkey and starting the 2FA setup (D9: the user's own account, behind password confirmation), an `AppUrlHttpsCheck` (D15: a new check is a feature; the README checklist names it), the rawId padding in the log (D21: cosmetic), `SetSuperAdmin`'s stored-organisation comparison (D39: theoretical, no action moves users between organisations), the HIBP request before the hash refusal (D73: one pointless k-anonymity request), a purpose label in the session row HMAC (E22: no collision possible), a separate current-password bucket (E24: a design change that needs a session already), `SecondFactor::mustOwnBeforeRequiring()` (E28: the action decides on the locked row), the organisation save race (E32: a millisecond window an attacker cannot force), `requiredFor()` reusing `Tenant::current()` (E42: one primary-key lookup); performance and the admin UI: caching the audit viewer's DISTINCT lists (A31 = C136 = D87: performance only, the log is bounded by retention), a PCRE failure in `BladeDirectives` (D52: production fails closed), the export running `applied()` twice (E45: CPU only), the filter names spelled out three times (E46: a refactor), an export memory probe (E47: the review's claim is acceptable), a mid-stream export error marker (E48: the known trigger is gone), the cross-site export GET (E49: no disclosure), the purge modal's hidden label (E71: invisible), the dashboard's plain super-admin badge (CD-13: one more site, no colour shown today); uploads, sessions and backups: the as-root accepting branch of `PrivateBackupTemp` (D66: fails closed), the root stray directory (D67: a root crontab is documented as unsupported), the free CSV input's remove button (E13: choosing again or reloading drops the file), refreshing the sessions list after a database failure (E18: database failures only), the `HeadlessChrome` user agent (E27: headless browsers only), an orphan-avatar sweep (E58: unguessable names, leftover data only); and, optional, upstream reports to pest-plugin-browser for the Trace warning and the multipart upload handling. Won't fix, each with its reason: process, history and vendor: the reused FU ids (A79: historical tags whose ledgers are gone; whether shipped code keeps internal tags is an owner question, by default kept), the model default that hides the migration default (A84e: needed under `shouldBeStrict()`), the Trace warning (A85 = B58 = B105 = C128 = D7 = E70, with D14, D22, D26, D31, D40, D56, D61, D68, D75: vendor code, agent JSON only, recorded in (15)), the M7c Task 15 observations (D86: no kit defects; the hints went to Task 18); the setup gate and wizard: `?secret=` in the Herd-stage URLs (B56: DEC-33 (2), single use, 30 minutes, local, no-referrer), the Claude plugin registry entry (B57: Claude Code's machine state), the exact compare in `appUrlIsSecured()` (C76: RL-16), the echo of `--answers=-` on a terminal (C84: DEC-33 (32) (e)), `$wizardCommands` three times (C115: test data), a `DatabaseStep` read-failure test (D59: `EnvFile::contents()` is tested), the environment step's toolchain at build time (D60: fresh steps per call, DEC-34 (16) (d)), `ProcessAccount::home()` (D84, FM-17: correct as it is); health, ops and backups: `/health-check` outside the web group (C30: by design, behind the key), ACLs on the System page (C92: they need a process), the health report's detail for key holders (C99: L-30), the System page's limiter failing open (C101 = C103: CD-16 of M7b, super admins only), the non-atomic `tooManyAttempts` plus `hit` (C102: a harmless burst), a general guard against vendor exec (C104's residual: impossible in-process), the root symlink-swap race (D69's residual: root backups are unsupported, README); sign-in, tests and UI: a `fortify.domain` test (C142: the kit never sets it, and it would break per-host sign-in), the guest passkey bucket per IP (D10: G1-05's design), HEAD bodies (D35's HEAD half: Symfony drops them), `lockOrganisation()` outside a transaction (D41: unreachable under `RefreshDatabase`), `FluxProLint` tokenising twice (D49: test-support speed only), bulk tests tied to the RateLimiter API (E15: they fail loudly on a rewrite), the unreachable 403 in the sessions `mount()` (E20: defence in depth), the second-factor rule gated on `organisations.update` (E33: deliberate, DEC-35 (3)), the `tmp-for-tests*` directories (E72: git-ignored, wiped by Livewire), `ensureMemoryFor()` keeping the raised limit (E73: DEC-35 (7)). The deferred minors of the M8a reviews: (filled in after execution).
````

- [ ] **Step 8: The CHANGELOG consistency pass**

`CHANGELOG.md`, `## [Unreleased]` as Tasks 3–16 leave it (`### Added`, `### Changed`, `### Fixed`, `### Removed`, then Task 3's `### Security`). Read the whole section first. Fix any other line a later task made inconsistent (a factory name, a limit, the copy), fold duplicates, and record each such fix in DEC-36 (25). Then apply:

1. **Fold Task 8's Changed line into the production-bootstrap line.** Replace

````text
- `skv:bootstrap` only prepares `.env`, `APP_KEY` and the generated secrets (`HEALTH_CHECK_KEY`, `BACKUP_ARCHIVE_PASSWORD`) for a production install: `.env` or the process environment names an `APP_ENV` outside `local`, `testing` and `staging` (`prod` and `live` too).
````

with

````text
- `skv:bootstrap` only prepares `.env`, `APP_KEY` and the generated secrets (`HEALTH_CHECK_KEY`, `BACKUP_ARCHIVE_PASSWORD`) for a production install: `.env` or the process environment names an `APP_ENV` outside `local`, `testing` and `staging` (`prod` and `live` too), or no source names one at all (no line in `.env`, a blank value, nothing in the process environment), which Laravel runs as production as well; a fresh copy of `.env.example` still says `local`.
````

2. **Drop Task 8's Changed line, now folded into the line above.** Delete these lines (the blank line before the bullet included):

````text
- `skv:bootstrap` treats a project where no source names an `APP_ENV` (no line in `.env`, a blank value, nothing in the process environment) as production, as Laravel does, and only prepares `.env`, `APP_KEY` and the generated secrets there; a fresh copy of `.env.example` still says `local`.
````

3. **Added: the test suite's guards and the traceability script (Tasks 1, 15; test-only tasks add no line of their own).** Replace

````text
- An MIT `LICENSE` file and Packagist metadata
````

with

````text
- The test suite refuses to run against a database that is not a test database (`DB_DATABASE` must be `:memory:`, a name ending in `_test`, a file under `storage/framework/testing` or the `DB_TEST_DATABASE` of `composer test:pgsql`, and `DB_URL` must be empty), so an exported `DB_DATABASE` can never let `RefreshDatabase` wipe a development database. The setup, console, backup, health and System tests also fail on a process fake that matches every command and on a command that ran into the stray-process guard, even one the code caught.
- Every bullet of the design spec's §4 has a frozen id (`§4.x.n`), every test file's `covers` line cites the bullets it tests, and `php scripts/traceability.php` lists the bullets no test cites (`TraceabilityTest` fails on any). A regression test keeps the manual and the demo accounts out of `public/` (skv2 finding R2).
- An MIT `LICENSE` file and Packagist metadata
````

4. **Fixed: the sign-in trend under mutable dates (Task 11).** Replace

````text
- Removing a profile photo when there is none no longer says *Profile photo removed.*
````

with

````text
- Removing a profile photo when there is none no longer says *Profile photo removed.*
- The dashboard's sign-in trend keeps fourteen consecutive days when an app switches Laravel to mutable dates (`Date::use(Carbon::class)`).
````

5. **Changed: point the old header list at the Security line that extends it.** Replace

````text
- The setup wizard and the Herd stage answer only requests from this machine (`127.0.0.0/8` or `::1`) without an `X-Forwarded-*`, `X-Expose-*`, `CF-*`, `X-Original-Host`, `Forwarded`, `Via`, `X-Real-IP` or `True-Client-IP` header,
````

with

````text
- The setup wizard and the Herd stage answer only requests from this machine (`127.0.0.0/8` or `::1`) without an `X-Forwarded-*`, `X-Expose-*`, `CF-*`, `X-Original-Host`, `Forwarded`, `Via`, `X-Real-IP` or `True-Client-IP` header (more headers under Security),
````

Expected: `grep -c '^### Security$' CHANGELOG.md` prints `1`, `grep -n '^### ' CHANGELOG.md` lists Added, Changed, Fixed, Removed, Security in that order, and `grep -c 'treats a project where no source names an' CHANGELOG.md` prints `0`.

- [ ] **Step 9: Checks**

```bash
command grep -n 'once it is published\|(M8)\|M8</span>\|werkt pas als het gepubliceerd is\|Draft, awaiting owner review\|git clone …\|repository-url' README.md docs/handleiding.html docs/superpowers/specs/2026-10-10-skv3-design.md   # prints nothing
command grep -o 'class="src">[^<]*' docs/handleiding.html | command grep -cE '[A-Za-z)*}]:[0-9]'   # 0
command grep -c '^### DEC-36' docs/decisions.md                                                   # 1
command grep -c 'DEC-36' docs/decisions.md                                                        # at least 6 (the heading and the pointers)
command grep -c 'image|max:2048`, `flux:avatar` preview' docs/superpowers/specs/2026-10-10-skv3-design.md   # 1
php scripts/traceability.php | tail -1                                                            # 90 bullets, 0 problems
php artisan test --compact tests/Feature/Tooling tests/Arch tests/Feature/LangCoverageTest.php tests/Feature/Localisation/LangFilesTest.php tests/Feature/Console/ProductionGuardsTest.php
```

Expected: the comments' results, and the tests pass (in the prototype, which had Task 15's `DemoCredentialsExposureTest` and script but not Tasks 13, 14 and 16's new tests, 122 passed). Then prove that the R2 test still guards the edited manual: copy `docs/handleiding.html` aside with `cp`, add the line `  <p><code>superadmin@&lt;apex&gt;</code> / <code>password</code></p>` after `<h4>Demo-accounts</h4>`, run `php artisan test --compact tests/Feature/Tooling/DemoCredentialsExposureTest.php` (expected: 'never puts a demo account next to the demo password in the manual (Appendix A R2)' fails), copy the file back and run it again (passes).

Read the rendered manual once in a browser (`open docs/handleiding.html`): the new paragraphs, the tables and the M8a row show, and the contents rail still jumps to every chapter.

- [ ] **Step 10: The gate, then commit**

Run the Global Constraints gate:
- `composer check`
- `composer test:pgsql`
- the modules-off run: `php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser`
- `npm run build && composer test:browser`
- `npm run build:setup && git diff --exit-code resources/setup/setup.css` (no setup view changes, so expect no diff)
- `php artisan config:clear`

The task changes documentation and one PHP comment, so every run must be as green as after Task 16.

```bash
git add README.md docs/handleiding.html .ai/guidelines/skv3.md CLAUDE.md config/trustedproxy.php \
    docs/superpowers/specs/2026-10-10-skv3-design.md docs/decisions.md CHANGELOG.md
git commit -m "docs: bring the release documentation up to date, amend the spec and record DEC-36

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

The pre-commit hook runs Pint and Larastan on the one staged PHP file (`config/trustedproxy.php`).

---

---

### Task 18: Milestone gate and the controller's success-criteria evidence run (§12 (1)–(5); CD-8, CD-15; F51, F53, F56, D117, D86, E75, E7, E6)

This task is the controller's. The workflow's final review and gate cover Step 1. Step 2 creates throwaway Herd sites and PostgreSQL databases, so the controller runs it after the workflow, one command at a time, and reports each result. It produces evidence, not code: a finding becomes a test-first fix (Step 3) before the milestone is handed over.

Configuration B is recorded as a **free-Herd approximation**: the controller answers SQLite but does not stop Herd Pro's services, because stopping them would change the owner's other sites. The real measurement from *Create* in the Herd GUI is M8b (CD-8).

- [ ] **Step 1: Full verification (superpowers:verification-before-completion)**

```bash
composer check
composer test:pgsql && composer test:pgsql && composer test:pgsql
REQUIRE_POSTGRES=true composer test:pgsql
php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser
npm run build && composer test:browser
npm run build:setup && git diff --exit-code resources/setup/setup.css
php scripts/traceability.php
composer validate --strict
git status --short
git show HEAD:composer.json | grep -c flux-pro; git show HEAD:composer.lock | grep -c flux-pro
git diff main -- composer.lock | grep -E '^[-+] {12}"name": '
git diff main --stat -- composer.json composer.lock package.json package-lock.json
grep -rnE "(^|[[ ,(])['\"]\*['\"] *=>|Process::fake\(\)" tests/ | grep -v KitRulesTest
grep -L "covers §" $(git diff --name-only --diff-filter=A main -- 'tests/**/*.php' | grep -v -e Fixtures -e Support)
git diff --name-only --diff-filter=D main -- tests/
```

Expected:
- every suite green; the three `test:pgsql` runs give the same counts (E75: a run that differs names a flaky test, which becomes a Step 3 fix before the hand-off)
- `REQUIRE_POSTGRES=true composer test:pgsql` green: no PostgreSQL test skips (CD-10)
- the stylesheet as committed; `traceability.php` prints `90 bullets, 90 cited by a test, 0 uncovered, 0 problems`; `composer validate --strict` valid
- a clean tree; `0` and `0`; the lock-name grep prints nothing (M8a adds no package, CD-15's pattern)
- `composer.json` changes only in `keywords`, `homepage` and `support` (Task 16); no lock, npm or package-lock change
- no catch-all fake; an empty `grep -L`; no deleted test file

- [ ] **Step 2: The real runs (PostgreSQL + Herd Pro + HTTPS; criterion 1 and 2 evidence)**

Every command names the throwaway site by its absolute path; artisan runs from inside the site's directory. Never run `artisan`, `stat` or `ls` on a relative path from the repository. Timestamps are taken with `date +%s` before the script starts and after the driver reaches the dashboard; the duration is recorded in seconds, split into phase 1 (`laravel new`, Composer, npm, bootstrap) and the wizard (from *Open site* to the dashboard).

1. **Configuration A: Herd Pro + PostgreSQL, the default answers.**
   - `scripts/e2e-local.sh "$HOME/Herd/m8a-e2e-a" --entry=herd --phase1-only`: every phase-1 check passes, and the site is not the repository.
   - The wizard through Playwright (M7d's `drive.cjs`, adapted): only the app name is typed; every other answer keeps its pre-filled default (PostgreSQL is detected, so it is the default; the default start and modules; HTTPS as pre-filled; Claude Code as pre-filled). It reaches its summary with no JavaScript errors and lands on the dashboard after the quick login (demo) or the super-admin sign-in.
   - Record: phase-1 seconds, wizard seconds, total, and the answers the wizard pre-filled.
   - `php artisan skv:doctor --json` inside the site: no row with status `fail` (criterion 2). Record every `warn`.
2. **Configuration B: SQLite (free-Herd approximation).** The same in `"$HOME/Herd/m8a-e2e-b"`, answering SQLite and leaving every other answer at its default. Record the same numbers and the doctor result.
3. **The e2e job's variants, through the CLI (phase 2 of the script, no wizard):**
   - `scripts/e2e-local.sh "$HOME/Herd/m8a-e2e-phpunit" --entry=herd --flags='--phpunit --git --no-boost'`: phase 1 and phase 2 pass, including the `tests/` diff and the `boost:update` count (at most 1).
   - `scripts/e2e-local.sh "$HOME/Herd/m8a-e2e-pgsql" --entry=herd --db=pgsql --https`: phase 1 and phase 2 pass; `skv:doctor --json` has no `fail`.
4. **M8a's behaviour on site A** (Playwright, demo data, log mailer: switch `MAIL_MAILER` to `log` without reading `.env`, `perl -i -pe 's/^MAIL_MAILER=.*/MAIL_MAILER=log/'` followed by `chmod 600`):
   - **Address lookups (Task 3):** as `admin@demo1…`, five bulk "Check" runs of 100 new addresses each pass; the sixth list is refused with "Too many attempts" on the addresses field; another member's profile address change still works.
   - **Handoff bound to the password (Task 4):** as `superadmin@…` on the admin host, capture the handoff URL that "Open" on demo1 issues (do not follow it), change the super admin's password in a second context, then open the captured URL: it lands on the login page, not on the dashboard. A fresh "Open" after the change works.
   - **Session rotation (Task 5):** `user@demo1…` signed in twice; "Log out other devices" in context A changes A's session cookie value, the security page still shows one row with "This device", and context B lands on the login page.
   - **Upload clean-up (Task 7):** `php artisan schedule:list` inside the site lists `livewire-tmp-cleanup` hourly at :45; a file older than 24 h placed under the site's `storage/app/private/livewire-tmp/` is gone after `php artisan schedule:test --name=livewire-tmp-cleanup` (the throwaway site only).
   - **Storage link (Task 8):** the doctor's `storage-link` row passes on the fresh site.
   - **Impersonation as an organisation admin on a tenant host (D86):** `admin@demo1…` impersonates `user@demo1…` from the users page, lands on demo1's dashboard as the user, sees the impersonation banner, and stops back to the admin; the activity log shows both entries.
   - **The direct-request gate over Herd's nginx (Task 9, D57):** already proven by the wizard opening in 2.1; record that no header Herd adds was refused.
5. **Housekeeping (E7):** list `storage/framework/testing/setup/` in the repository; remove exactly the two stale sandboxes `eb9a4942-…` and `f6e24034-…` dated 2026-10-13 (git-ignored), by their full names, after checking `git check-ignore` and their dates.
6. **Optional (A83):** if the owner provides a Flux Pro licence locally, the owner runs `skv:flux-pro` in a throwaway worktree and opens the Pro variants; otherwise this stays an M8b item.
7. **Record** every output in the ledger, never a password, secret, token, session id, handoff URL or accept URL.

Clean up as M7d's Task 9 did, for each of `m8a-e2e-a`, `m8a-e2e-b`, `m8a-e2e-phpunit` and `m8a-e2e-pgsql`:
- `herd unsecure <site>` where it was secured, and `herd unisolate --site=<site>` only if `herd isolated` lists it
- `rm -rf "$HOME/Herd/<site>"` and each script's temp directory (the script prints them)
- drop the PostgreSQL databases with a PDO script in the scratchpad that first checks that `pg_database` lists exactly the expected names (`m8a_e2e_a`, `m8a_e2e_a_test`, `m8a_e2e_pgsql`, `m8a_e2e_pgsql_test`, and any other the summaries name), then drops them by literal name
- delete the downloads, the browser state and the screenshots

- [ ] **Step 3: Hand-off**

- Fix what Steps 1 and 2 find, test-first, as separate commits; then re-run Step 1.
- Fill in DEC-36's "(filled in after execution)" markers: the deviations during execution, the final review and its fixes, and this task's evidence (criterion 1's measured seconds per configuration with the approximation stated, criterion 2's doctor results, criterion 3's traceability output, criterion 4's local suites, criterion 5's Appendix A coverage).
- Stop before merging: the owner merges.
- Update the memory notes: M8a done, its deferred items, and M8b's approval list (publication itself, the repository, the push, the Flux secrets, branch protection, Packagist, the `v1.0.0` tag).
