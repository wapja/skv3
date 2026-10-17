# M7d Account Extras Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the §6.6 v1 extras: the sessions list with "log out other devices", the second factor required per organisation, the audit-log CSV export, bulk invitations and avatar upload, with their Flux Pro upload variants (§6.7). Also add the two mail limits FM-19 left open.

**Architecture:** Each extra is one task with its own tests, and each reuses what M7c hardened:
- `UserActionLimit` for every new mail-sending or secret-checking action
- `InviteUser` and the localised queued mails for bulk invitations
- the security page and `ConfirmsPasswords` for sessions and the second factor
- the activity viewer's filters for the export
- CD-5's lock order for every new write

New shared pieces:
- `App\Support\UserAgent`, a small user-agent parser
- `App\Http\Middleware\EnsureSecondFactor` and `App\Tenancy\Auth\SecondFactor`, which decides "has a second factor / must have one"
- `App\Support\Audit\ActivityFilters`, one filter → query builder for the viewer and the export, and `App\Support\Audit\ActivityRows`, one display rule (labels, RL-7) for both
- `App\Support\Invitations\InvitationList`, which parses pasted text and CSV into rows
- `App\Actions\Users\{UpdateAvatar,RemoveAvatar}` on Laravel's `Illuminate\Image` (with `intervention/image ^4`)

Documentation, the spec amendments and DEC-35 come last. The controller then makes one real run on a throwaway Herd site.

**Tech Stack:** PHP 8.4, Laravel 13.35, Livewire 4.4 (multi-file components, `WithFileUploads`), Flux 2.20 (free) with the optional Pro layer, Fortify, laravel/passkeys, spatie/laravel-permission, spatie/laravel-activitylog v5, **intervention/image ^4 (new; spec §3, through Laravel's `Illuminate\Image`)**, Pest 5.3 with pest-plugin-browser 5.1, Larastan 3 level 8, PostgreSQL 18 (Herd), SQLite.

**Spec:** `docs/superpowers/specs/2026-10-10-skv3-design.md`. Read these before starting:
- §6.6 (the extras, :399-405) and §6.7 (the Pro layer and its table, :407-420)
- §4.2 (authentication, AuthenticateSession), §4.3 (organisations, purge), §4.4 (users), §4.5 (invitations, "generic message"), §4.8 (audit)
- §6.2 (route × host matrix; `invitations.bulk` is already listed at :351), §6.3 (authorization), §6.5 (UI, settings), §6.8 (modules: the passkeys-off second-factor rule)
- Appendix A (the skv2 findings; their regression tests must stay green)

Decisions live in `docs/decisions.md`: DEC-27 (auth), DEC-28 (administration), DEC-29 (UI), DEC-34 (M7c: `UserActionLimit`, `ReturnUrl`, CD-5's lock order, FM-18/FM-19). M7d writes **DEC-35**.

## Global Constraints

- **Versions:**
  - PHP floor `^8.4`; `laravel/framework ^13.35`; Pest `^5.3`.
  - Larastan level **8**, **no baseline**, no new `ignoreErrors` entries. This includes the multi-file component `.php` files under `resources/views/**`.
- **PHP files:** every one starts with `declare(strict_types=1);`. Pint uses the `laravel` preset plus `declare_strict_types`, `strict_comparison` and `ordered_class_elements`. Run `composer fix` after editing PHP.
- **Dependencies:**
  - M7d adds exactly one: `intervention/image ^4` (spec §3, GD driver, `ext-gd` is already required by §5.4 and the `gd` doctor check), used through Laravel 13's first-party `Illuminate\Image` API, which requires `^4`. Only Task 6 runs `composer require intervention/image:^4`. No npm dependency, no other `ext-*` requirement.
  - `livewire/flux-pro` and `composer.fluxui.dev` never appear in the committed `composer.json` or `composer.lock`.
- **Never touch the developer's own environment from code or tests:**
  - No test reads or writes the repository's `.env`, `.env.testing`, `herd.yml`, `.mcp.json`, `storage/app/skv-installed`, `storage/app/private/skv-*`, `storage/app/backups`, `storage/app/backup-temp`, `storage/app/public` or `public/storage`. Upload tests use `Storage::fake('public')` and `UploadedFile::fake()`.
  - No test opens a connection to the developer's PostgreSQL unless it is an explicit PostgreSQL test that skips when the server is unreachable.
  - Implementers never run `skv:*`, `backup:*`, `storage:link`, `migrate`, `db:seed` or `tinker` in the repository itself, and never run a mutating Herd command.
- **External commands:** every external command the kit starts runs through `Process` with `Toolchain::environment()`. Never `exec()`, `shell_exec()`, `system()`, `passthru()`, `proc_open()`, `popen()`, `pcntl_exec()` or backticks (`tests/Arch/KitRulesTest.php`). M7d starts no external command.
- **Never print or log secrets:** passwords and their hashes, `APP_KEY`, session ids and payloads, remember tokens, reset, invitation, email-change and handoff tokens. Audit entries never hold a password, a token, a session id or an accept URL. The sessions list never renders a session id; it identifies rows by a keyed hash (Task 1).
- **Never run against the development database:** no `migrate:fresh`, `db:wipe` or `db:seed`. `tinker`, `migrate` and `db:seed` prompt for approval. Task 6's migration is tested through `RefreshDatabase` only.
- **Tenancy and authorization (`AGENTS.md` › Tenancy and authorization):**
  - `Tenant::bypass()` and scope removal only in `App\Console`, `App\Setup`, `App\Tenancy`, `App\Actions\Audit`, `Database\Seeders` and `app/Http/Controllers/HealthCheckController.php`. Livewire pages and the export controller never bypass; they read through `HostScope::read()`.
  - Use the `App\Authorization\Permission` enum, never a permission string; switch teams only with `withPermissionsTeam()`.
  - Administrative actions take the actor first and authorise with `Gate::forUser($actor)`. Every transaction follows DEC-34's lock order (CD-5 of M7c): organisation → roles in id order → one `users` batch in id order → `model_has_roles` → invitations → sessions and reset tokens → activity inserts.
  - Livewire components authorise in `mount()` and again before calling an action, keep `#[Locked]` ids and `#[Locked]` derived state the client must not change, and re-resolve models on every request.
  - Links that leave the request are built for `Hosts::homeOf($user)` / `Hosts::url()`; storage URLs come from the `public` disk (`APP_URL`).
- **Limits:** every new action that sends mail or checks a secret uses `App\Support\RateLimits\UserActionLimit` in the component (never in the action), through `around()` for sends and `attempt()` for secret checks. A refusal is a `ValidationException` on the form's field with "Too many attempts. Try again in :seconds seconds." Route-level limits use a named `RateLimiter::for()` limiter keyed with `ThrottleKey::of()`.
- **Routes:**
  - Group single-host routes with `Route::tenant()` / `Route::central()`; never `Route::domain()` (the Fortify-mirroring groups in `routes/fortify.php` and `routes/passkeys.php` are the named exceptions).
  - Every new named GET route goes into `matrixRoutes()` in `tests/Feature/Tenancy/RouteHostMatrixTest.php` (with its outcomes in `routeMatrixOutcome()`) or its exclusions. M7d adds exactly two: `invitations.bulk` (Task 5) and `activity.export` (Task 4).
- **Audit:** entries are written only through `App\Actions\Audit\RecordActivity` with an `App\Enums\ActivityEvent` case; never `activity()`, the facade or `LogsActivity`. A new case needs a Dutch and an English label in both lang files and a group in `ActivityEvent::group()` (`tests/Feature/Audit/ActivityLogTest.php` checks both).
- **Mail:** mails implement `ShouldQueue` and call `afterCommit()`; tests run them on the sync queue with the `array` mailer, not `Mail::fake()`.
- **Uploads:**
  - Livewire uploads use `WithFileUploads`; validation names exact rules (`image`, `mimes:`, `max:` in KB, `dimensions:`).
  - Uploaded content is never trusted. An image is re-encoded through `Illuminate\Image` before it is stored, which drops its metadata. A CSV is read as text with `str_getcsv`/`SplFileObject`, never included or evaluated.
  - Files written by the kit go to the `public` disk under `avatars/` only (Task 6).
- **Names:** no hard-coded kit or site name (`skv`) in app-identity values (`tests/Arch/NameLeakTest.php`). Tests set every `skv.*` key in its own `config(['skv.…' => …]);` call.
- **Copy and lang keys:**
  - Code, comments and docs are in English. UI copy goes through `__()`, `nl` by default, with `en`.
  - Every key goes into **both** `lang/nl.json` and `lang/en.json`: identical keys, the same order (PHP `sort()` order, byte-wise), Dutch informal "je".
  - Glossary: Session = Sessie, Device = Apparaat, Second factor = Tweede factor, Two-factor authentication = Tweestapsverificatie, Export = Exporteren, Bulk invitation = Meerdere uitnodigingen, Avatar = Profielfoto, Upload = Uploaden.
- **Livewire:** multi-file components only, no ⚡, never `$this->view()`. `password.confirm` does not cover Livewire updates: actions that change or reveal a second factor, end other sessions or change the organisation's second-factor rule start with `if ($this->passwordConfirmationRequired($returnUrl)) { return; }` where the task says so.
- **Flux Pro (§6.7):**
  - Pro-only components (`flux:file-upload`, `flux:file-item`, listbox `flux:select`) appear only inside `@if (Skv::hasFluxPro())` with a complete free `@else` that binds the same `wire:model` property names (`tests/Arch/FluxProLayerTest.php`).
  - The free upload is `<flux:input type="file" wire:model="…">`. Pro variants are covered by `FluxProLayerTest` and `ProVariantsTest` (see CD-10). No test installs Pro.
- **Tests:**
  - Pest functions (`it(...)`) with typed closures. Each new file starts with a `/** covers §x.y */` docblock as its first statement.
  - `Event::fake()` is never called bare: `Event::fake()->except([TenantResolved::class, TenantForgotten::class])`.
  - `Livewire::test()` skips all middleware: test host rules, limits, redirects and `EnsureSecondFactor` over HTTP (`pageSnapshot()` + `callPageAction()` for Livewire actions).
  - Tests that depend on a module call `useImpersonationModule()` or `usePasskeysModule()` first.
  - **No `*Test.php` file declares a class (anonymous ones included) or spells a qualified class name outside its imports** (`tests/Arch/TestFileClassesTest.php`). Fixtures go in `tests/Fixtures`.
  - Tests are rewritten, never deleted (deleting a test needs the owner's approval). A test whose title promises more than it checks is strengthened or retitled.
  - A `match` on a `string` dataset parameter ends with a `default =>` arm (Larastan `match.unhandled`).
- **Global test helpers already taken (never redeclare):** `acceptLinkFor`, `asMember`, `asOrgAdmin`, `asSuperAdmin`, `browserUser`, `callPageAction`, `confirmationLinkFor`, `dutchTranslationKeys`, `filesMatching`, `inviteMember`, `kitSourceFiles`, `lockStatements`, `onAdminHost`, `onApex`, `onTenant`, `pageSnapshot`, `recordedActivity`, `sameOrigin`, `setupSandbox`, `startImpersonatingOverHttp`, `useBackupsModule`, `useImpersonationModule`, `usePasskeysModule`, `useSetupWizard`, `withoutPostgresProbe`.
- **Global test helpers reserved for M7d:** `sessionRowFor` (Task 1, `tests/Pest.php`). Nothing else; file-local helpers are closures.
- **Gate per task:**
  - `composer check` (SQLite) green
  - `composer test:pgsql` green
  - the modules-off run: `php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser` green
  - `npm run build && composer test:browser` green
  - `npm run build:setup && git diff --exit-code resources/setup/setup.css` (M7d changes no setup view)
  - then `php artisan config:clear`
- **Commits:** Conventional Commits, lower case, imperative, ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Stage files by name. The pre-commit hook (Pint + Larastan on staged files) runs; never bypass it.
- **Never** run `herd sites` or the Herd MCP `get_all_sites`, never read `.env` files (`.env.example` is fine), never use `git stash` (it is shared by every worktree; set a file aside with `cp`).

## Conventions used in this plan

- All M7d work happens on branch `m7d-account-extras`, created from `main` (merge `e791111`, "Merge M7c: security hardening"). The plan file, `docs/superpowers/plans/2026-10-17-m7d-account-extras.md`, is its first commit.
- Bracketed ids are traceability tags:
  - `CD-*`: the controller's decisions, taken under the owner's delegation
  - `§x.y`: the spec requirement a step implements
  - `FM-19`: DEC-34 (16)'s deferral
  - `DEC-*`: decision-log entries
- **HTTP tests for host rules** use `onTenant()`, `onApex()`, `onAdminHost()` and the `as*()` helpers from `tests/Pest.php`.
- **Livewire-over-HTTP tests** use `pageSnapshot()` and `callPageAction()`, as `tests/Feature/Settings/SecurityActionsTest.php` does, because `Livewire::test()` skips middleware.
- **Mutation check:** where a step says "verify by mutation", the implementer reverts the production change locally, confirms the new test fails, restores the change and confirms it passes. Copy the file aside with `cp` and copy it back; **never `git stash`**. After a Blade mutation run `php artisan view:clear`. The task report names each mutation and its result.

## Scope

1. **Sessions** (§6.6): the user's sessions on the security page, with device, browser, IP, last activity and "this device"; "log out other devices" with the password, audited.
2. **The second-factor setting** (§6.6, §4.3): `organisations.require_second_factor` editable on `organisations.edit` by organisation admins and super admins, audited.
3. **EnsureSecondFactor** (§6.6, §6.8): the middleware, its exemptions, the super-admin switch and the security page's explanation.
4. **Audit CSV export** (§6.6, §4.8): a streamed download honouring the viewer's filters and the host's scope, audited and limited.
5. **Bulk invitations** (§6.6, §4.5, §6.7): paste or upload a CSV, validate per row, preview, send through `InviteUser`, summarise; the free file input and the Pro `flux:file-upload`.
6. **Avatars** (§6.6, §4.3, §6.7): upload, crop and resize to 512 px, initials fallback, cleanup on replace and purge; the free and the Pro upload.
7. **FM-19:** limits for an administrator's address change and the first-admin invitation from `organisations.create`.
8. **Documentation, the spec amendments and DEC-35.**
9. **The controller's real verification** on a throwaway Herd site, then cleanup.

Not in scope:
- **M8:** M7c's carried minors (memory `skv3-m7c-carried-followups`), FM-18's probe limit, plan review K9, the release check, CI runs of the e2e jobs, publication.
- A "delete my account" flow, avatar moderation, sessions on other hosts' cookies (a row is a row, whatever its host), a per-user second-factor requirement.

## Controller decisions (owner delegation, 2026-10-15, reaffirmed 2026-10-17)

- **CD-1, the dependency:** `intervention/image` with its GD driver, as spec §3 lists it, at `^4`: spec §3's "^3 (latest at M1)" meant the current major, and Laravel 13.35's first-party `Illuminate\Image` API (which the kit uses instead of calling Intervention directly) requires `^4`. It is M7d's only new dependency. Task 6 adds it with `composer require intervention/image:^4`, which also updates `composer.lock`. No Imagick. Task 8 amends spec §3.
- **CD-2, sessions** (Task 1):
  - A nested multi-file component `pages::settings.sessions` renders inside the security page, below the passkeys section.
  - It lists the `sessions` rows whose `user_id` is the signed-in user, newest `last_activity` first. Each row shows the browser and platform from `App\Support\UserAgent`, the IP, the last activity (`diffForHumans` in the page's locale) and "This device" for the current session.
  - A row is identified to the client only by `hash_hmac('sha256', $sessionId, config('app.key'))`, never by the session id.
  - **During an impersonation** the section is hidden and its actions answer 403. `ImpersonationAwareSessionHandler` writes the impersonator's id into `sessions.user_id`, and ending sessions needs the account owner's password.
  - "Log out other devices" asks for the current password in a modal.
    - It runs under `UserActionLimit::logoutOtherDevices()`: 5 per 60 s, `attempt()`, field `password`.
    - Then `Auth::logoutOtherDevices($password)` rehashes the password and refreshes this device's remember cookie.
    - Then the user's other `sessions` rows are deleted.
    - It records `ActivityEvent::OtherSessionsEnded` (`security.other_sessions_ended`) with `properties.count`.
    - A wrong password is a validation error on `password` and records nothing.
  - No `passwordConfirmationRequired()`: the action itself checks the password.
- **CD-3, the second-factor setting** (Task 2):
  - `organisations.edit` gains a switch "Require a second factor" bound to `bool $requireSecondFactor`.
  - `UpdateOrganisation::handle()` gains a last parameter `bool $requireSecondFactor`. The change is recorded in `organisation.updated`'s changes like the other fields.
  - Changing the flag in either direction starts with `passwordConfirmationRequired(route('organisations.edit', …, absolute: false))`.
  - An organisation admin who has no second factor themselves cannot turn the rule on. The error on `requireSecondFactor` reads "Set up a second factor for your own account first." This keeps them from locking themselves out of the page they are on. Super admins are not members, so the check does not apply to them.
  - The M4 test "has no second-factor setting yet (RL-17)" is rewritten into tests of the setting, never deleted.
- **CD-4, EnsureSecondFactor** (Task 3):
  - Who has a second factor: `App\Tenancy\Auth\SecondFactor::satisfiedBy(User $user): bool` is true for a confirmed TOTP secret (`hasEnabledTwoFactorAuthentication()`), or, only while the passkeys module is on, for at least one passkey.
  - Who must have one: `SecondFactor::requiredFor(User $user): bool`. For a super admin it is `config('skv.second_factor.super_admins')` (env `REQUIRE_SECOND_FACTOR_SUPER_ADMINS`, default false), on every host, whatever their `organisation_id`. For anyone else it is their own organisation's `require_second_factor`, read on every host.
  - The middleware is appended to the authenticated route groups: the `auth` groups in `routes/web.php`, `routes/settings.php` and `routes/admin.php`. It is not added to the global `web` group (§6.6).
  - It lets a request through when:
    - the user is a guest, or does not need a second factor
    - the session is an impersonation (`Impersonation::isActive()`)
    - it is a Livewire update or upload, recognised by its route name (`*livewire.update`, `livewire.upload-file`, `livewire.preview-file`), never by the `X-Livewire` header, which any client can send with a plain GET
    - the route name matches `config('skv.second_factor.exempt_routes')`
  - The exempt list's default is `security.edit`, `password.confirm`, `password.confirm.store`, `password.confirmation`, `two-factor.*`, `passkey.*`, `well-known.passkeys`, `logout`, `auth.handoff`, `auth.handoff.store`, `impersonation.stop`, `setup.*`. The spec's `passkeys.*` names no route and is replaced by these names in the spec (Task 8).
  - An unsatisfied GET is redirected to `security.edit`; any other method gets a 403. The redirect flashes "Your organisation requires a second factor. Set up two-factor authentication or a passkey to continue." (a super admin gets "Super admins need a second factor…" instead). When the passkeys module is off, the copy says only "two-factor authentication". `phpunit.xml` forces `REQUIRE_SECOND_FACTOR_SUPER_ADMINS=false` so the developer's `.env` cannot change the suite.
  - The security page shows the same explanation as a callout while the rule applies and is unmet.
  - Removing the last second factor stays allowed; the middleware then sends the user back to set one up.
- **CD-5, the export** (Task 4):
  - A GET route `activity.export` (`activity/export`, any host like `activity.index`, `can:` `Permission::ActivityLogView`, a named limiter `activity-export`: 5 per minute per user) is served by `App\Http\Controllers\ActivityExportController`. It streams `text/csv; charset=UTF-8` through `response()->streamDownload()`, so the file is never held in memory.
    - This is not a Livewire download, because Livewire buffers a download into its JSON response.
  - The page's filters and the export share `App\Support\Audit\ActivityFilters`, which turns the six filter strings into a query, ignoring invalid values exactly as the viewer does today.
  - The page's "Export CSV" button links to the route with the current filters as its query string.
  - Rows stream with `lazyById(500)` inside `HostScope::read()`, in id order.
  - The columns are: date (ISO 8601, app time zone), event key, event label, causer, subject, organisation (central host only), IP, user agent and properties (JSON).
    - IP and user agent are empty where the viewer hides them (RL-7).
  - The file starts with a UTF-8 BOM, so Excel reads it.
  - A cell that starts with `=`, `+`, `-`, `@`, a tab or a carriage return gets a leading `'` (CSV formula injection).
  - Before streaming, the export records `ActivityEvent::ActivityExported` (`audit.exported`, label "Activity log exported", in a new group `audit` that tags by host) with the filters it applied. There is no change-summary column, so RL-7's hidden staff changes stay hidden.
- **CD-6, bulk invitations** (Task 5):
  - The page is `pages::invitations.bulk` at `invitations/bulk`, route `invitations.bulk`, any host (§6.2 :351). Its authorisation is the same as `users.invite`: on a tenant host the `create` ability on `Invitation` for the host's organisation; on a central host super admins only, with an organisation picker. Reach it from a "Invite several people" button on the pending invitations page; there is no navigation item.
  - Input: a textarea for pasted addresses (one per line, or separated by commas or semicolons), or a CSV file.
    - The CSV has an email column and an optional locale column, with or without a header row, comma- or semicolon-separated. A UTF-8 BOM is stripped. The file is at most 512 KB (`file|mimes:csv,txt|max:512`).
    - Roles and a default language are chosen once for the batch, with the same free/Pro role controls as `users.invite`.
  - At most 100 rows per batch.
  - "Check" parses through `App\Support\Invitations\InvitationList` and validates each row:
    - the email format
    - a duplicate in the batch
    - an address that cannot be invited: the generic "This address can't be invited." (§4.5), never saying why
    - an unknown locale
  - The preview lists every row with "Ready" or its skip reason. The rows are `#[Locked]`, and they are re-validated when sent.
  - "Send" reserves the ready rows under `UserActionLimit::bulkInvite()`: 100 per 3600 s, cost = ready rows, field `addresses`. It then calls `InviteUser::handle()` once per row, catches a row's `ValidationException` as "skipped" with the action's message, releases the cost of skipped rows, and shows a summary ("Sent: n", "Skipped: m" with reasons).
  - Mails are queued by `InviteUser`; the single-invite limit `invite()` is not consumed.
  - The Pro variant is `<flux:file-upload wire:model="csv">` with `<flux:file-item>`; the free one is `<flux:input type="file" wire:model="csv">`.
- **CD-7, avatars** (Task 6):
  - A migration adds `users.avatar_path` (nullable string).
  - The profile page gains a section "Profile photo" with an upload (`image|mimes:jpeg,png,webp|max:2048|dimensions:max_width=4096,max_height=4096`), a preview (`flux:avatar`) and "Remove".
  - `App\Actions\Users\UpdateAvatar::handle(User $user, UploadedFile $file): void`:
    - reads the image through `Illuminate\Image` (GD driver) and applies its EXIF orientation
    - `cover(512, 512)`, encodes WebP at quality 85
    - stores it on the `public` disk as `avatars/{organisation_id|platform}/{user id}-{Str::random(20)}.webp`
    - saves the path and deletes the previous file after commit
  - `RemoveAvatar::handle(User $user): void` clears the path and deletes the file after commit.
  - An unreadable image is a validation error "This image could not be processed."
  - The user changes only their own photo. During an impersonation the section is hidden and its actions answer 403 (M7c's FM-20 refuses the email change in the same situation, with a validation error).
  - `User::avatarUrl(): ?string` returns `Storage::disk('public')->url($path)` or null. It is rendered by the user menu (`flux:sidebar.profile`, `flux:profile`, `flux:avatar`), by the users index's name cell and by the profile preview. Each falls back to the initials.
  - `PurgeOrganisation` deletes its users' avatar files after commit (§4.3). `DeleteUser` keeps them, because a soft-deleted user can be restored.
  - The Pro variant is `<flux:file-upload wire:model="avatar">` with a preview; the free one is `<flux:input type="file" accept="image/jpeg,image/png,image/webp" wire:model="avatar">`.
- **CD-8, FM-19** (Task 7):
  - An administrator's address change on `users.edit` runs under `UserActionLimit::addressChange()`: 10 per 3600 s per acting administrator, `around()`, field `email`.
  - The first-admin invitation in `organisations.create` runs under the existing `UserActionLimit::invite()` (`around()`, field `adminEmail`).
  - The guideline's and the handleiding's "without a limit" sentences are removed (Task 8).
- **CD-9, new audit events:** `security.other_sessions_ended` (Task 1, group `security`) and `audit.exported` (Task 4, new group `audit`). The second-factor flag rides on `organisation.updated` (Task 2). Avatar changes ride on `user.updated` with `changes.avatar` = `["set" | "removed"]`, never the path (Task 6). Bulk sends record one `invitation.sent` per row through `InviteUser`.
- **CD-10, the Pro variants:** both uploads have a free `flux:input type="file"` and a Pro `flux:file-upload`; the bulk page's roles reuse `users.invite`'s free/Pro pair. Pro branches are covered by `FluxProLayerTest` (static) and a line in the existing `ProVariantsTest` (which runs only with Pro installed and skips otherwise); the free branches by a line in `FreeVariantsTest`. Rendering with `Skv::useFluxPro(true)` does not work without the package, because `AppServiceProvider::configureFluxProBranches()` strips Pro branches at compile time based on `InstalledVersions`. No test installs Pro.

## Requirement traceability

| Task | Spec / ids |
|---|---|
| 1 | §6.6 sessions, §4.2 (AuthenticateSession), §4.8; CD-2, CD-9 |
| 2 | §6.6 second factor (setting), §4.3; CD-3 |
| 3 | §6.6 second factor (enforcement), §6.8 passkeys off; CD-4 |
| 4 | §6.6 CSV export, §4.8; CD-5, CD-9 |
| 5 | §6.6 bulk invitations, §4.5, §6.2 `invitations.bulk`, §6.7; CD-6, CD-10 |
| 6 | §6.6 avatar, §4.3 purge, §4.4, §6.7, §3; CD-1, CD-7, CD-10 |
| 7 | FM-19 (DEC-34 (16)); CD-8 |
| 8 | the spec amendments, README, handleiding, CHANGELOG, guideline, DEC-35 |
| 9 | the controller's verification |

## Codebase facts (main `e791111`)

- **Sessions:** `config/session.php` driver `database`, table `sessions` (`id`, `user_id` nullable index without FK, `ip_address`, `user_agent` text, `payload`, `last_activity` int). `TenancyServiceProvider.php:111` swaps in `App\Tenancy\Auth\ImpersonationAwareSessionHandler`, whose `userId()` is the impersonator's id during an impersonation. `App\Tenancy\Auth\Sessions::endFor(array $userIds)` deletes rows and nulls `remember_token`. `AuthenticateSession` is in the global `web` group (`bootstrap/app.php:42-45`). `tests/Feature/Auth/AuthenticateSessionTest.php:29` shows an ad-hoc `logoutOtherDevices` route in a test.
- **The security page** `resources/views/pages/settings/security/{security.php,security.blade.php}`:
  - The route is `security.edit` with `password.confirm`.
  - Traits: `ConfirmsPasswords`, `PasswordValidationRules`, `ResolvesCurrentUser`.
  - Sections: password; 2FA (`@if ($canManageTwoFactor)`, nesting `pages::settings.two-factor.recovery-codes` and the setup modal); passkeys (`@if ($canManagePasskeys)`).
  - `updatePassword()` uses `UserActionLimit::passwordUpdate()->attempt()`.
- **`App\Concerns\ConfirmsPasswords::passwordConfirmationRequired(?string $returnTo = null): bool`.**
- **ActivityEvent** (`app/Enums/ActivityEvent.php`):
  - Its prefixes are auth, security, user, invitation, organisation, role and impersonation.
  - `group()` throws `LogicException` for an unknown prefix.
  - `label()` and `tagsByHost()` exist.
  - `tests/Feature/Audit/ActivityLogTest.php:192-200` requires a Dutch label and a known group for every case.
  - `RecordActivity::handle(ActivityEvent $event, ?Model $subject = null, array $properties = [], array $changes = [], ?User $causer = null, ?Organisation $organisation = null, bool $defaultCauser = true): Activity`.
- **Organisations:**
  - The column `require_second_factor` already exists (migration, `#[Fillable]`, boolean cast, factory `false`).
  - The edit page is `resources/views/pages/organisations/edit/edit.php`: `#[Locked] int $organisationId`, `name`, `slug`, `?description`, `save(UpdateOrganisation, IssueHandoff)`. Its docblock :24 says "No second-factor setting until M7 (RL-17)".
  - `UpdateOrganisation::handle(User $actor, Organisation $organisation, string $name, string $slug, ?string $description): Organisation`.
  - `OrganisationPolicy::update` = `can(OrganisationsUpdate)` and same organisation; super admins pass through `Gate::before`.
  - `tests/Feature/Organisations/OrganisationEditTest.php:112-127` is the RL-17 test.
- **Routes:**
  - There is no single authenticated group. These are separate `Route::middleware(['auth'])` groups:
    - `routes/web.php:45` (dashboard)
    - `routes/settings.php:8` (profile, appearance, security, email-change)
    - `routes/admin.php:15` (users, invitations, organisations, roles, activity, with `Route::central` at :40; the `Route::tenant` at :67 is the guest accept route, outside the `auth` group)
  - Route names are:
    - `two-factor.{login,login.store,enable,confirm,disable,qr-code,secret-key,recovery-codes,regenerate-recovery-codes}`
    - `passkey.{confirm,confirm-options,login,login-options,store,registration-options,destroy}`, `well-known.passkeys`
    - `password.{confirm,confirm.store,confirmation}`
    - `security.edit`, `auth.handoff`, `auth.handoff.store`, `impersonation.stop`, `logout`
    - `setup.{wizard,herd-status,finish,login}`
    - Livewire's: `default-livewire.update`, `livewire.upload-file`, `livewire.preview-file`
  - `config/skv.php` has no `second_factor` key yet.
- **2FA and passkeys:**
  - `User` uses `TwoFactorAuthenticatable` (`hasEnabledTwoFactorAuthentication()`) and `PasskeyAuthenticatable` (`passkeys()`).
  - `Skv::passkeysEnabled()`, `Impersonation::isActive()`, `Livewire::isLivewireRequest()`.
  - `UserFactory::withTwoFactor()` exists.
- **Activity viewer** `resources/views/pages/activity/index/{index.php,index.blade.php}`:
  - `#[Url]` strings `from`, `until`, `event`, `subject`, `causer`, `organisation` (`const FILTERS`).
  - Private `baseQuery()` (:280, `withTrashed()` on central) and `filteredQuery()` (:298).
  - `row()` (:345) hides IP and user agent for platform staff on a tenant host (RL-7).
  - `causerLabel()`, `subjectLabel()`, `organisationNames()`, `details()`.
  - It reads through `HostScope::read()`. `Permission::ActivityLogView`.
  - Livewire downloads exist only in `recovery-codes.php:54` (`streamDownload`).
- **Invitations:**
  - `InviteUser::handle(User $actor, Organisation $organisation, string $email, array $roleIds, Locale $locale): Invitation`. It authorises itself, validates with `emailRules()` and `roles required|array|min:1`, maps a unique violation to "This address can't be used.", queues the mail and counts no limit.
  - `users.invite` (`resources/views/pages/users/invite/*`): `email`, `locale`, `array $roles`, `?int $organisationId`; `roleOptions()`, `organisationOptions()`, `returnRoute()`. The roles are a Pro listbox or a free `flux:checkbox.group`. It uses `UserActionLimit::invite()->around()`.
- **UserActionLimit** (`app/Support/RateLimits/UserActionLimit.php`, final readonly):
  - `__construct(string $name, int $maxAttempts, int $decaySeconds)`
  - factories `emailChange`, `passwordUpdate`, `twoFactorConfirm`, `invite`, `remind`
  - `key()`, `attempt()`, `reserve()`, `release()`, `around()`, `ensureAvailable()`, `hit()`
  - `assertCost()` throws when the cost passes `maxAttempts`.
- **Users and storage:**
  - `users` has no avatar column. `User::initials()` exists. Avatars are rendered only in `components/user-menu/user-menu.blade.php` (:5, :7, :12).
  - The `public` disk is `storage/app/public` with url `APP_URL/storage`.
  - `PurgeOrganisation` deletes no files; `DeleteUser` is a soft delete.
  - `GdCheck` (doctor key `gd`) exists. No image library is installed.
- **FM-19:**
  - `users.edit`'s `saveAccount()` (`resources/views/pages/users/edit/edit.php:81`) calls `UpdateUser::handle(User $actor, User $target, string $name, string $email)`, which queues `EmailChangedByAdministrator` to the old address.
  - `organisations.create`'s `create()` (`pages/organisations/create/create.php:84`) calls `CreateOrganisation::handle(...)`, which invites the first admin through `InviteUser`. The page remaps the `email` error to `adminEmail`.
- **Flux free stubs** have `input` with `type="file"`, `avatar`, `profile` and `sidebar.profile` (with `avatar` props). They do not have `file-upload` or `file-item`, which are Pro.
- **Tests:**
  - `RouteHostMatrixTest::matrixRoutes()` is a flat list of names; `routeMatrixOutcome()` holds the outcomes; a second test requires every named GET route to be in the matrix or `$excluded`.
  - No test uploads a file yet.
  - `Navigation::items()` is unchanged in M7d.

## Cross-task notes from planning

1. **File ownership.** Tasks run in order, and each task's code is written against `main` `e791111` plus the earlier tasks. Where two areas meet, one task owns the file:
   - `resources/views/pages/settings/security/*`: Task 1 adds the `<livewire:pages::settings.sessions />` line; Task 3 adds the second-factor callout and is written against Task 1's version.
   - `resources/views/pages/settings/sessions/*`, `app/Support/UserAgent.php`: Task 1.
   - `app/Enums/ActivityEvent.php`: Task 1 adds `OtherSessionsEnded`; Task 4 adds `ActivityExported` and the `audit` group, written against Task 1's version.
   - `app/Support/RateLimits/UserActionLimit.php`: Task 1 adds `logoutOtherDevices()`, Task 5 `bulkInvite()`, Task 7 `addressChange()`; each is written against the earlier tasks' version. M7d's factories go directly after the constructor, alphabetically among themselves, before the existing `emailChange()`.
   - `tests/Feature/FluxPro/FreeVariantsTest.php`, `tests/Feature/FluxPro/ProVariantsTest.php`: Task 5 adds its lines, Task 6 appends after them.
   - `tests/Feature/Authorization/LockOrderTest.php`: Task 2 updates its `UpdateOrganisation` row; Task 6 adds two rows for the avatar actions.
   - `resources/views/pages/organisations/edit/*`, `app/Actions/Organisations/UpdateOrganisation.php` and its callers in `tests/Feature/Organisations/UpdateOrganisationTest.php` and `tests/Feature/Authorization/LockOrderTest.php`: Task 2.
   - `app/Tenancy/Auth/SecondFactor.php` and `tests/Feature/Auth/SecondFactorTest.php`: Task 2 creates them with `satisfiedBy()` (CD-3's "own second factor first" rule needs it); Task 3 adds `requiredFor()`, `missingFor()` and `explanation()`.
   - `config/skv.php`, `.env.example`, `phpunit.xml`, `routes/web.php`, `routes/settings.php`, `routes/admin.php` (the middleware): Task 3. Task 4 adds the `activity.export` route and Task 5 the `invitations.bulk` route to `routes/admin.php`, written against Task 3's version.
   - `resources/views/pages/activity/index/*`, `app/Support/Audit/*`, the `activity-export` limiter at the end of `FortifyServiceProvider::configureRateLimiting()`: Task 4.
   - `resources/views/pages/invitations/bulk/*`, `resources/views/pages/invitations/pending/pending.blade.php` (the button), `resources/views/pages/users/invite/invite.php` (moved onto the new `App\Concerns\ChoosesInvitationOrganisation`), `app/Actions/Invitations/InviteUser.php` (a public `addressRules(Organisation)` the preview shares): Task 5.
   - `composer.json`, `composer.lock`, `database/migrations/*_add_avatar_path_to_users_table.php`, `app/Models/User.php`, `resources/views/pages/settings/profile/*`, `resources/views/components/user-menu/*`, `resources/views/pages/users/index/index.blade.php` (the avatar cell), `app/Actions/Organisations/PurgeOrganisation.php`: Task 6.
   - `resources/views/pages/users/edit/edit.php`, `resources/views/pages/organisations/create/create.php`: Task 7.
   - `tests/Feature/Tenancy/RouteHostMatrixTest.php`: Task 4, then Task 5.
   - `lang/nl.json`, `lang/en.json`, `CHANGELOG.md`: every task adds its own keys and lines. `README.md`, `docs/handleiding.html`, `.ai/guidelines/skv3.md`, `docs/decisions.md` and the spec: Task 8 only (tasks list what Task 8 must say in their report).
2. **Spec edits** are Task 8's, in one pass, because M7d changes no existing behaviour the spec describes, only adds what §6.6 already describes.
3. **New named GET routes:** exactly `activity.export` (Task 4) and `invitations.bulk` (Task 5).

---

### Task 1: Sessions (§6.6 sessions, §4.2 AuthenticateSession, §4.8; CD-2, CD-9)

The security page gains a list of the signed-in user's sessions and a "Log out other devices" action (§6.6). The list reads the user's rows in the `sessions` table (the kit's database driver), shows the browser and platform from a small in-house parser, the IP, the last activity and "This device", and identifies a row to the client only by a keyed hash. "Log out other devices" asks for the current password in a modal, runs under its own `UserActionLimit`, rehashes the password through `Auth::logoutOtherDevices()` (so `AuthenticateSession`, already in the global `web` group, signs every other session and "remember me" cookie out on its next request), deletes the user's other rows and records `security.other_sessions_ended` with the count. During an impersonation the section is hidden and every request to it answers 403.

**Files:**
- Create:
  - `app/Support/UserAgent.php` (the parser: browser, platform, desktop/mobile/tablet)
  - `resources/views/pages/settings/sessions/sessions.php` (the nested component `pages::settings.sessions`)
  - `resources/views/pages/settings/sessions/sessions.blade.php`
- Modify:
  - `app/Support/RateLimits/UserActionLimit.php` (`logoutOtherDevices()`, directly after the constructor)
  - `app/Enums/ActivityEvent.php` (case `OtherSessionsEnded` and its label)
  - `resources/views/pages/settings/security/security.blade.php` (the `<livewire:pages::settings.sessions />` line, after the passkeys section)
  - `lang/nl.json`, `lang/en.json` (11 keys)
  - `tests/Pest.php` (the global helper `sessionRowFor()`, reserved for this task)
  - `CHANGELOG.md`
- Test:
  - `tests/Unit/UserAgentTest.php` (new)
  - `tests/Feature/Settings/SessionsTest.php` (new)
  - `tests/Feature/Audit/ActivityLogTest.php` (unchanged; its dataset over `ActivityEvent::cases()` checks the new case's Dutch label and group)

**Interfaces:**
- Consumes:
  - `App\Concerns\ResolvesCurrentUser::currentUser(): User`; `App\Concerns\PasswordValidationRules::currentPasswordRules(): array` (`['required', 'string', 'current_password']`)
  - `App\Support\RateLimits\UserActionLimit::attempt(User $user, string $field, int $cost = 1): void`
  - `App\Actions\Audit\RecordActivity::handle(ActivityEvent $event, ?Model $subject = null, array $properties = [], …): Activity`
  - `App\Tenancy\Auth\Impersonation::isActive(): bool`
  - `Illuminate\Support\Facades\Auth::logoutOtherDevices(string $password)` (`SessionGuard`: checks the password, force-rehashes it, re-queues this device's remember cookie when it has one, fires `OtherDeviceLogout`)
  - test helpers: `onTenant()`, `asMember()`, `pageSnapshot()`, `callPageAction()`, `recordedActivity()`, `startImpersonatingOverHttp()`, `useImpersonationModule()`, `usePasskeysModule()`
- Produces:
  - `App\Support\UserAgent` (final readonly): `__construct(public ?string $browser, public ?string $platform, public string $device)` with `$device` typed `'desktop'|'mobile'|'tablet'`; `public static function parse(?string $header): self`. Browsers: `Edge`, `Opera`, `Samsung Internet`, `Firefox`, `Chrome`, `Safari`, else null. Platforms: `iOS`, `Android`, `Windows`, `ChromeOS`, `macOS`, `Linux`, else null.
  - `UserActionLimit::logoutOtherDevices(): self` = `new self('logout-other-devices', 5, 60)`, the first factory after the constructor. Task 5 inserts `bulkInvite()` before it, Task 7 `addressChange()` before that.
  - `ActivityEvent::OtherSessionsEnded = 'security.other_sessions_ended'` (group `security`, tagged by host), label "Other devices signed out", properties exactly `['count' => int]` (the rows deleted), subject and causer the user. Placed right after `PasskeyDeleted` in the cases and in `label()`; Task 4 writes against this version.
  - The component `pages::settings.sessions`: public `string $password`; `#[Computed] sessions(): list<array{key: string, browser: ?string, platform: ?string, device: 'desktop'|'mobile'|'tablet', ip: ?string, last_active: string, current: bool}>`; action `logoutOtherDevices(RecordActivity $recordActivity): void`; the Flux modal `logout-other-devices`. `mount()` and `hydrate()` abort 403 during an impersonation.
  - The security page renders `<livewire:pages::settings.sessions />` inside `@unless (\App\Tenancy\Auth\Impersonation::isActive())`, after the passkeys section's `@endif` and before `</x-pages::settings.layout>`. Task 3 adds its callout to this version.
  - Global test helper `sessionRowFor(User $user, array $attributes = []): string` (`tests/Pest.php`): inserts a `sessions` row (default: a Chrome on Windows at `203.0.113.7`, active a minute ago, empty payload) and returns its id; `$attributes` overrides `ip_address`, `user_agent` and `last_activity`.

Background for the implementer:
- **Which rows are listed.** The rows whose `user_id` is the signed-in user and whose `last_activity` is within `session.lifetime` minutes, newest first. A row older than the lifetime is no session any more: `DatabaseSessionHandler::read()` treats it as empty, and garbage collection deletes it only on a lottery win, so on a quiet site expired rows would otherwise show up as "Last active 3 days ago". "Log out other devices" deletes every other row of the user, expired ones included, and `count` is the number of rows it deleted. Rows carry no host, so sessions on the user's other hosts (an organisation host and `admin.{apex}` for a super admin) are listed and ended too.
- **Why the session id never reaches the client.** It is the bearer secret of a session. The rows live in a `#[Computed]` property, so they are never in the snapshot, and the only per-row identifier rendered is `hash_hmac('sha256', $id, config('app.key'))` as the `wire:key`. Nothing else needs a row id: there is no per-row action.
- **Why no `passwordConfirmationRequired()`.** The action checks the password itself (CD-2). `UserActionLimit::attempt()` counts every attempt before the password is checked, so the modal cannot be used to guess it.
- **The transaction** follows DEC-34's lock order: `Auth::logoutOtherDevices()` updates the `users` row, then the `sessions` rows are deleted, then the activity row is inserted.
- **During an impersonation** `ImpersonationAwareSessionHandler` writes the impersonator's id into `sessions.user_id`, and ending sessions takes the account owner's password, so the section is hidden. `hydrate()` refuses every update, so a snapshot taken before the impersonation started cannot be replayed during it. `Impersonation::isActive()` is independent of the module flag, as everywhere.
- **With another session driver** (`file`, `redis`) the table is empty, so the list renders nothing; the button still works, because the rehash alone makes `AuthenticateSession` sign every other session out. The kit requires `SESSION_DRIVER=database` (spec §5.4, the `session-driver` doctor check).
- **The test harness (read this before changing the tests).**
  - `phpunit.xml` sets `SESSION_DRIVER=array`, which writes no `sessions` rows. `SessionsTest` switches to `database` in `beforeEach`, before any request resolves a driver, and turns the garbage-collection lottery off: a lottery win (2 in 100 requests) deletes the rows a test inserted with an old `last_activity`. (A prototype with a row exactly `session.lifetime` minutes old failed 3 runs in 100 for that reason.)
  - Laravel's test client sends no cookies by itself; without a session cookie every request starts a new session id, so "This device" would never match a stored row. Each simulated browser therefore signs in through `login.store`, keeps the cookies the response set (still encrypted) and sends them: this device through `withCredentials()` + `withUnencryptedCookies()` (`callPageAction()` posts JSON, which carries cookies only with `withCredentials()`), another browser through `call(..., $cookies)`.
  - Between the requests of one test the app keeps in memory what a real PHP process would not: the session store and its attributes (in the manager and the `session.store` singleton), the guard and its user (in the auth manager and the `auth.driver` singleton, which the database handler asks for `user_id`), and the cookie jar's queued cookies (a remember cookie queued at one browser's sign-in would be re-sent to every later response). The file-local `$newProcess` closure forgets all five before each browser switches in. Without the `session.store` reset the laptop in the second test stays signed in through the previous browser's session.
  - `callPageAction()` runs the real middleware stack, so `AuthenticateSession`, the impersonation re-check and the tenant host rules all apply.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/UserAgentTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §6.6 */

use App\Support\UserAgent;

it('reads the browser, platform and device of the headers people sign in with (CD-2)', function (string $header, ?string $browser, ?string $platform, string $device): void {
    $agent = UserAgent::parse($header);

    expect([$agent->browser, $agent->platform, $agent->device])->toBe([$browser, $platform, $device]);
})->with([
    'Chrome on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', 'Chrome', 'Windows', 'desktop'],
    'Chrome on macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', 'Chrome', 'macOS', 'desktop'],
    'Chrome on Linux' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', 'Chrome', 'Linux', 'desktop'],
    'Chrome on ChromeOS' => ['Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', 'Chrome', 'ChromeOS', 'desktop'],
    'Chrome on an Android phone' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36', 'Chrome', 'Android', 'mobile'],
    'Chrome on an Android tablet' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', 'Chrome', 'Android', 'tablet'],
    'Chrome on an iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/130.0.6723.90 Mobile/15E148 Safari/604.1', 'Chrome', 'iOS', 'mobile'],
    'Edge on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.2849.68', 'Edge', 'Windows', 'desktop'],
    'Edge on macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.2849.68', 'Edge', 'macOS', 'desktop'],
    'Edge on an Android phone' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36 EdgA/130.0.0.0', 'Edge', 'Android', 'mobile'],
    'Edge on an iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 EdgiOS/130.2849.80 Mobile/15E148 Safari/605.1.15', 'Edge', 'iOS', 'mobile'],
    'Firefox on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0', 'Firefox', 'Windows', 'desktop'],
    'Firefox on macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:132.0) Gecko/20100101 Firefox/132.0', 'Firefox', 'macOS', 'desktop'],
    'Firefox on Linux' => ['Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0', 'Firefox', 'Linux', 'desktop'],
    'Firefox on an Android phone' => ['Mozilla/5.0 (Android 14; Mobile; rv:132.0) Gecko/132.0 Firefox/132.0', 'Firefox', 'Android', 'mobile'],
    'Firefox on an Android tablet' => ['Mozilla/5.0 (Android 14; Tablet; rv:132.0) Gecko/132.0 Firefox/132.0', 'Firefox', 'Android', 'tablet'],
    'Firefox on an iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/132.0 Mobile/15E148 Safari/605.1.15', 'Firefox', 'iOS', 'mobile'],
    'Safari on macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15', 'Safari', 'macOS', 'desktop'],
    'Safari on an iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1', 'Safari', 'iOS', 'mobile'],
    'Safari on an iPad' => ['Mozilla/5.0 (iPad; CPU OS 17_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.7 Mobile/15E148 Safari/604.1', 'Safari', 'iOS', 'tablet'],
    'Samsung Internet on a phone' => ['Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36', 'Samsung Internet', 'Android', 'mobile'],
    'Samsung Internet on a tablet' => ['Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Safari/537.36', 'Samsung Internet', 'Android', 'tablet'],
    'Opera on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 OPR/114.0.0.0', 'Opera', 'Windows', 'desktop'],
    'Opera on an Android phone' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36 OPR/85.0.0.0', 'Opera', 'Android', 'mobile'],
    'curl' => ['curl/8.7.1', null, null, 'desktop'],
    'a crawler' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', null, null, 'desktop'],
    'an empty header' => ['', null, null, 'desktop'],
]);

it('reads a missing header as an unknown desktop (CD-2)', function (): void {
    expect(UserAgent::parse(null))->toEqual(new UserAgent(null, null, 'desktop'));
});

it('reads only the start of an oversized header (CD-2)', function (): void {
    $agent = UserAgent::parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64)'.str_repeat(' padding', 100).' Firefox/132.0');

    expect([$agent->browser, $agent->platform])->toBe([null, 'Windows']);
});
```

The strings are the current real headers of each browser (Chrome's and Safari's reduced user-agent strings included: Chrome on Android always says `Android 10; K`, Safari on macOS always `Mac OS X 10_15_7`). Unit tests run without the framework, which `UserAgent` does not need.

`tests/Pest.php`: add this helper right after `startImpersonatingOverHttp()` (before `browserUser()`). `Str`, `DB` and `User` are already imported.

```php
/**
 * Inserts a `sessions` row for $user, as the database session driver writes one for a device that signed in
 * elsewhere (§6.6, CD-2), and returns its id. The payload is an empty session: the row is listed and ended, never
 * read. By default the row is a Chrome on Windows at 203.0.113.7 that was active a minute ago.
 *
 * @param  array{ip_address?: string|null, user_agent?: string|null, last_activity?: int}  $attributes
 */
function sessionRowFor(User $user, array $attributes = []): string
{
    $id = Str::random(40);

    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '203.0.113.7',
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->subMinute()->getTimestamp(),
        ...$attributes,
    ]);

    return $id;
}
```

Create `tests/Feature/Settings/SessionsTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §6.6 */

use App\Authorization\Role;
use App\Enums\ActivityEvent;
use App\Models\Organisation;
use App\Models\User;
use App\Support\RateLimits\UserActionLimit;
use App\Tenancy\Auth\Impersonation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\call;
use function Pest\Laravel\post;
use function Pest\Laravel\withCredentials;
use function Pest\Laravel\withSession;
use function Pest\Laravel\withUnencryptedCookies;

/*
| The suite runs on the array session driver (phpunit.xml), which writes no `sessions` rows. The list and "log out
| other devices" read and delete those rows, so these tests use the kit's own driver, `database` (with
| ImpersonationAwareSessionHandler). Its garbage collection is off, so a lottery win never deletes a row a test
| inserted. The test client sends no cookie by itself, and without one every request starts a new session, so each
| browser here keeps its cookies and sends them.
*/
beforeEach(function (): void {
    config(['session.driver' => 'database']);
    config(['session.lottery' => [0, 100]]);
    $this->freezeSecond();
});

/**
 * Forgets what the test's earlier requests left in memory, as a new PHP process would: between the requests of
 * one test the session manager and the `session.store` singleton keep their store and its attributes, the auth
 * manager and the `auth.driver` singleton (which the session handler asks for the user id) keep their guard and
 * its user, and the cookie jar keeps the cookies it queued.
 */
$newProcess = function (): void {
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    Auth::forgetGuards();
    app()->forgetInstance('auth.driver');
    app('cookie')->flushQueuedCookies();
};

/**
 * Signs $user in through the login form in a new browser and returns the cookies that browser then holds, encrypted
 * as the response set them: the session and, with $remember, the "remember me" cookie.
 *
 * @return array<string, string>
 */
$signIn = function (User $user, bool $remember = false) use ($newProcess): array {
    $newProcess();
    $response = post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => $remember])->assertRedirect();

    $cookies = [];

    foreach ($response->headers->getCookies() as $cookie) {
        $cookies[$cookie->getName()] = (string) $cookie->getValue();
    }

    return $cookies;
};

/**
 * Makes the test's own requests (get(), pageSnapshot(), callPageAction()) come from the browser that holds $cookies,
 * with a fresh password confirmation for the security page. callPageAction() posts JSON, which carries cookies only
 * with withCredentials(). A test uses it for one browser only: the cookies merge with those set before. Other
 * browsers send their cookies with call(), which adds none of these.
 *
 * @param  array<string, string>  $cookies
 */
$useBrowser = function (array $cookies) use ($newProcess): void {
    $newProcess();
    withCredentials();
    withUnencryptedCookies($cookies);
    withSession(['auth.password_confirmed_at' => time()]);
};

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

/**
 * The ids of $user's `sessions` rows, sorted.
 *
 * @return list<string>
 */
$rowsOf = fn (User $user): array => DB::table('sessions')->where('user_id', $user->id)->orderBy('id')->pluck('id')
    ->map(fn (mixed $id): string => (string) $id)->values()->all();

it('lists the user\'s live sessions with browser, platform, IP and last activity, marks this device and never shows a session id (CD-2)', function () use ($signIn, $useBrowser, $rowsOf): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $colleague = User::factory()->for($organisation)->create();
    onTenant($organisation);
    $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15');
    $useBrowser($signIn($member));
    $thisDevice = $rowsOf($member);
    $phone = sessionRowFor($member, [
        'ip_address' => '198.51.100.23',
        'user_agent' => 'Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36',
        'last_activity' => now()->subMinutes(30)->getTimestamp(),
    ]);
    // Older than the session lifetime: the driver reads this row as an empty session.
    sessionRowFor($member, [
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0',
        'last_activity' => now()->subMinutes((int) config('session.lifetime') + 1)->getTimestamp(),
    ]);
    sessionRowFor($colleague, ['user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 OPR/114.0.0.0']);

    $response = $this->get(route('security.edit'))->assertOk();

    $html = (string) $response->getContent();
    $response->assertSeeInOrder(['Safari on macOS', 'This device', '127.0.0.1', 'Samsung Internet on Android', '198.51.100.23', 'Last active 30 minutes ago'])
        ->assertDontSee('Firefox on Windows')
        ->assertDontSee('Opera on Linux')
        ->assertSee(hash_hmac('sha256', $phone, (string) config('app.key')), false);
    expect($thisDevice)->toHaveCount(1)
        ->and(substr_count($html, 'This device'))->toBe(1)
        ->and($html)->not->toContain($phone)
        ->and($html)->not->toContain($thisDevice[0]);
});

it('signs a second "remember me" browser out on its next request and keeps this device signed in (§6.6)', function () use ($newProcess, $signIn, $useBrowser, $errorsOf): void {
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
    expect($errorsOf(callPageAction($snapshot, 'logoutOtherDevices', updates: ['password' => 'password'])))->toBe([]);

    // Its session row is gone, and its remember cookie holds the old password hash, which AuthenticateSession refuses.
    $newProcess();
    call('GET', route('dashboard'), [], $laptop)->assertRedirect(route('login'));
    $this->assertGuest();

    $newProcess();
    call('GET', route('dashboard'), [], $thisDevice)->assertOk();
    $this->assertAuthenticatedAs($member);
});

it('ends only the user\'s other sessions and records how many (§4.8, CD-9)', function () use ($signIn, $useBrowser, $errorsOf, $rowsOf): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $colleague = User::factory()->for($organisation)->create();
    onTenant($organisation);
    $useBrowser($signIn($member));
    $thisDevice = $rowsOf($member);
    sessionRowFor($member);
    sessionRowFor($member, ['user_agent' => null, 'ip_address' => null]);
    $colleagueRow = sessionRowFor($colleague);
    $passwordHash = (string) $member->fresh()?->password;
    $snapshot = pageSnapshot(route('security.edit'), 'pages::settings.sessions');

    expect($errorsOf(callPageAction($snapshot, 'logoutOtherDevices', updates: ['password' => 'password'])))->toBe([]);

    expect($rowsOf($member))->toBe($thisDevice)
        ->and($rowsOf($colleague))->toBe([$colleagueRow])
        ->and((string) $member->fresh()?->password)->not->toBe($passwordHash);
    $entry = recordedActivity(ActivityEvent::OtherSessionsEnded)->sole();
    expect([$entry->subject_id, $entry->causer_id, $entry->organisation_id])->toBe([$member->id, $member->id, $organisation->id])
        ->and($entry->properties?->all())->toBe(['count' => 2]);
});

it('refuses a wrong password on the password field and ends nothing (CD-2)', function () use ($signIn, $useBrowser, $errorsOf, $rowsOf): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    $useBrowser($signIn($member));
    sessionRowFor($member);
    $rows = $rowsOf($member);
    $passwordHash = (string) $member->fresh()?->password;
    $snapshot = pageSnapshot(route('security.edit'), 'pages::settings.sessions');

    expect($errorsOf(callPageAction($snapshot, 'logoutOtherDevices', updates: ['password' => 'wrong-password'])))->toHaveKey('password');

    expect($rowsOf($member))->toBe($rows)
        ->and((string) $member->fresh()?->password)->toBe($passwordHash)
        ->and(recordedActivity(ActivityEvent::OtherSessionsEnded))->toBeEmpty();
});

it('counts every attempt before it checks the password and refuses the sixth in a minute (CD-2)', function () use ($signIn, $useBrowser, $errorsOf, $rowsOf): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    $useBrowser($signIn($member));
    sessionRowFor($member);
    $rows = $rowsOf($member);
    $snapshot = pageSnapshot(route('security.edit'), 'pages::settings.sessions');

    foreach (range(1, 5) as $attempt) {
        expect($errorsOf(callPageAction($snapshot, 'logoutOtherDevices', updates: ['password' => 'wrong-password'])))->toHaveKey('password');
    }

    expect($errorsOf(callPageAction($snapshot, 'logoutOtherDevices', updates: ['password' => 'password'])))
        ->toBe(['password' => [__('Too many attempts. Try again in :seconds seconds.', ['seconds' => 60])]])
        ->and($rowsOf($member))->toBe($rows)
        ->and(Hash::check('password', (string) $member->fresh()?->password))->toBeTrue()
        ->and(recordedActivity(ActivityEvent::OtherSessionsEnded))->toBeEmpty()
        ->and(UserActionLimit::logoutOtherDevices())->toEqual(new UserActionLimit('logout-other-devices', 5, 60));
});

it('hides the sessions during an impersonation and refuses an update replayed from before it (CD-2)', function () use ($newProcess, $rowsOf): void {
    useImpersonationModule(true);
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn (): User => $admin->assignRole(Role::OrganisationAdmin->value));
    $admin->unsetRelation('roles');
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    sessionRowFor($member);
    $this->actingAs($member);
    withSession(['auth.password_confirmed_at' => time()]);
    $snapshot = pageSnapshot(route('security.edit'), 'pages::settings.sessions');
    $newProcess();

    startImpersonatingOverHttp($admin, $member);
    withSession(['auth.password_confirmed_at' => time()]);

    $this->get(route('security.edit'))->assertOk()
        ->assertSee('Update password')
        ->assertDontSee('Log out other devices');
    Auth::guard('web')->forgetUser();
    $rows = $rowsOf($member);

    callPageAction($snapshot, 'logoutOtherDevices', updates: ['password' => 'password'])->assertForbidden();

    expect(Impersonation::isActive())->toBeTrue()
        ->and($rowsOf($member))->toBe($rows)
        ->and(recordedActivity(ActivityEvent::OtherSessionsEnded))->toBeEmpty();
});

it('shows the sessions with two-factor authentication and passkeys off (§6.8)', function (): void {
    usePasskeysModule(false);
    config(['fortify.features' => []]);
    asMember(Organisation::factory()->create(['slug' => 'acme']));
    withSession(['auth.password_confirmed_at' => time()]);

    $this->get(route('security.edit'))->assertOk()->assertSee('Log out other devices');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Unit/UserAgentTest.php tests/Feature/Settings/SessionsTest.php`

Expected: FAIL, as follows:
- Every `UserAgentTest` row errors with `Class "App\Support\UserAgent" not found` (29 tests).
- 'lists the user's live sessions…' fails on `assertSeeInOrder`: the page does not contain "Safari on macOS".
- 'shows the sessions with two-factor authentication and passkeys off' fails: the page does not contain "Log out other devices".
- The other five throw `LogicException`: "The page [http://acme.localhost/settings/security] renders no [pages::settings.sessions] component."

- [ ] **Step 3: The user-agent parser**

Create `app/Support/UserAgent.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The browser, platform and kind of device behind a User-Agent header, for the sessions list (§6.6, CD-2). A small
 * in-house parser: it knows the browsers and platforms people sign in with and answers null for anything else
 * (curl, bots, an empty header). It is a label for people, never a security decision: the header is
 * client-chosen. An iPad in Safari's desktop mode sends a macOS header and is read as a Mac desktop.
 */
final readonly class UserAgent
{
    /** Longer headers are cut before matching; every marker sits well inside the first 512 bytes. */
    private const int MAX_LENGTH = 512;

    /**
     * Checked in order: Edge, Opera and Samsung Internet also send "Chrome/", Chrome also sends "Safari/".
     *
     * @var array<string, string>
     */
    private const array BROWSERS = [
        '/\bEdg(?:e|A|iOS)?\//' => 'Edge',
        '/\bOPR\/|\bOpera\b/' => 'Opera',
        '/\bSamsungBrowser\//' => 'Samsung Internet',
        '/\bFirefox\/|\bFxiOS\//' => 'Firefox',
        '/\bChrome\/|\bCriOS\//' => 'Chrome',
        '/\bVersion\/[\d.]+.*\bSafari\//' => 'Safari',
    ];

    /**
     * Checked in order: iOS headers say "like Mac OS X", Android headers say "Linux".
     *
     * @var array<string, string>
     */
    private const array PLATFORMS = [
        '/\b(?:iPhone|iPad|iPod)\b/' => 'iOS',
        '/\bAndroid\b/' => 'Android',
        '/\bWindows\b/' => 'Windows',
        '/\bCrOS\b/' => 'ChromeOS',
        '/\bMac OS X\b|\bMacintosh\b/' => 'macOS',
        '/\bLinux\b/' => 'Linux',
    ];

    public function __construct(
        public ?string $browser,
        public ?string $platform,
        /** @var 'desktop'|'mobile'|'tablet' */
        public string $device,
    ) {}

    public static function parse(?string $header): self
    {
        $header = substr((string) $header, 0, self::MAX_LENGTH);

        return new self(
            self::firstMatch(self::BROWSERS, $header),
            self::firstMatch(self::PLATFORMS, $header),
            self::device($header),
        );
    }

    /**
     * A tablet says so (iPad, Firefox's "Tablet"), or is an Android device without "Mobile": Android phones send
     * "Mobile", Android tablets do not.
     *
     * @return 'desktop'|'mobile'|'tablet'
     */
    private static function device(string $header): string
    {
        if (preg_match('/\biPad\b|\bTablet\b/', $header) === 1
            || (preg_match('/\bAndroid\b/', $header) === 1 && preg_match('/\bMobile\b/', $header) !== 1)) {
            return 'tablet';
        }

        return preg_match('/\bMobile\b|\biPhone\b|\biPod\b/', $header) === 1 ? 'mobile' : 'desktop';
    }

    /** @param  array<string, string>  $patterns */
    private static function firstMatch(array $patterns, string $header): ?string
    {
        foreach ($patterns as $pattern => $name) {
            if (preg_match($pattern, $header) === 1) {
                return $name;
            }
        }

        return null;
    }
}
```

The order of `BROWSERS` matters: Edge, Opera and Samsung Internet headers also contain `Chrome/`, and Chrome's contain `Safari/`; Safari is recognised only by `Version/… Safari/`. In `PLATFORMS`, iOS comes before macOS (iOS headers say "like Mac OS X") and Android before Linux. The `@var` on the promoted `$device` is what Larastan reads as its type.

- [ ] **Step 4: The limit and the audit event**

`app/Support/RateLimits/UserActionLimit.php`: insert directly after the constructor, before `emailChange()`:

```php
    /** Log out other devices on the security page: 5 attempts per minute, counted before the password is checked. */
    public static function logoutOtherDevices(): self
    {
        return new self('logout-other-devices', 5, 60);
    }
```

M7d's new factories sit directly after the constructor, alphabetically among themselves, before the existing ones: Task 5 inserts `bulkInvite()` before this one and Task 7 `addressChange()` before that.

`app/Enums/ActivityEvent.php`: after `case PasskeyDeleted = 'security.passkey_deleted';` add

```php
    case OtherSessionsEnded = 'security.other_sessions_ended';
```

and in `label()`, after `self::PasskeyDeleted => __('Passkey removed'),` add

```php
            self::OtherSessionsEnded => __('Other devices signed out'),
```

The prefix `security` gives the group `security` and host tagging (RL-5); `group()` needs no change.

- [ ] **Step 5: The sessions component**

Create `resources/views/pages/settings/sessions/sessions.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\Audit\RecordActivity;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ResolvesCurrentUser;
use App\Enums\ActivityEvent;
use App\Support\RateLimits\UserActionLimit;
use App\Support\UserAgent;
use App\Tenancy\Auth\Impersonation;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The signed-in user's sessions on the security page (§6.6, CD-2): their live rows in the `sessions` table (the
 * kit's database driver), newest first, with the browser and platform, the IP, the last activity and "This device".
 * A row reaches the client only as a keyed hash of its id, never as the session id itself. "Log out other devices"
 * checks the password itself, so it needs no password confirmation.
 *
 * Hidden during an impersonation, and every request to it answers 403 then: the impersonator's id is in
 * sessions.user_id (ImpersonationAwareSessionHandler), and ending sessions takes the account owner's password.
 */
new class extends Component
{
    use PasswordValidationRules;
    use ResolvesCurrentUser;

    public string $password = '';

    public function mount(): void
    {
        $this->ensureNotImpersonating();
    }

    /** Every update, whatever it calls: a snapshot taken before an impersonation started is replayed during it. */
    public function hydrate(): void
    {
        $this->ensureNotImpersonating();
    }

    /**
     * The user's sessions that have not expired, newest first. A row older than the session lifetime is no session
     * any more (the driver reads it as empty), so it is not listed.
     *
     * @return list<array{key: string, browser: string|null, platform: string|null, device: 'desktop'|'mobile'|'tablet', ip: string|null, last_active: string, current: bool}>
     */
    #[Computed]
    public function sessions(): array
    {
        $currentId = session()->getId();
        $rows = DB::table('sessions')
            ->where('user_id', $this->currentUser()->id)
            ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime'))->getTimestamp())
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity']);

        $sessions = [];

        foreach ($rows as $row) {
            $agent = UserAgent::parse(is_string($row->user_agent) ? $row->user_agent : null);

            $sessions[] = [
                // A keyed hash: the page never holds a session id (CD-2).
                'key' => hash_hmac('sha256', (string) $row->id, (string) config('app.key')),
                'browser' => $agent->browser,
                'platform' => $agent->platform,
                'device' => $agent->device,
                'ip' => is_string($row->ip_address) && $row->ip_address !== '' ? $row->ip_address : null,
                'last_active' => Carbon::createFromTimestamp((int) $row->last_activity)->diffForHumans(),
                'current' => hash_equals($currentId, (string) $row->id),
            ];
        }

        return $sessions;
    }

    /**
     * Logs out every other device (§6.6): the password is rehashed, so AuthenticateSession signs out every other
     * session and "remember me" cookie on its next request, and this device gets a fresh remember cookie
     * (Auth::logoutOtherDevices()); then the user's other rows are deleted. Every attempt counts against the
     * user's limit before the password is checked (CD-2), so the form cannot be used to guess it. One transaction
     * in DEC-34's lock order: users, then sessions, then the activity insert.
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

            $ended = DB::table('sessions')
                ->where('user_id', $user->id)
                ->where('id', '!=', session()->getId())
                ->delete();

            $recordActivity->handle(ActivityEvent::OtherSessionsEnded, $user, ['count' => $ended]);
        });

        $this->reset('password');
        unset($this->sessions);

        Flux::modal('logout-other-devices')->close();
        Flux::toast(variant: 'success', text: __('You were logged out on your other devices.'));
    }

    private function ensureNotImpersonating(): void
    {
        abort_if(Impersonation::isActive(), 403);
    }
};
```

Create `resources/views/pages/settings/sessions/sessions.blade.php`:

```blade
<section class="mt-12">
    <flux:heading>{{ __('Sessions') }}</flux:heading>
    <flux:subheading>{{ __('The devices where you are signed in') }}</flux:subheading>

    <div class="mt-6 flex flex-col w-full mx-auto space-y-6 text-sm">
        @if ($this->sessions !== [])
            <div class="border rounded-lg border-zinc-200 dark:border-zinc-700 overflow-hidden">
                @foreach ($this->sessions as $session)
                    <div wire:key="session-{{ $session['key'] }}" class="flex items-center gap-4 p-4 {{ ! $loop->last ? 'border-b border-zinc-200 dark:border-zinc-700' : '' }}">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-zinc-100 dark:bg-zinc-800">
                            @if ($session['device'] === 'mobile')
                                <flux:icon.device-phone-mobile class="size-5 text-zinc-500 dark:text-zinc-400" />
                            @elseif ($session['device'] === 'tablet')
                                <flux:icon.device-tablet class="size-5 text-zinc-500 dark:text-zinc-400" />
                            @else
                                <flux:icon.computer-desktop class="size-5 text-zinc-500 dark:text-zinc-400" />
                            @endif
                        </div>
                        <div class="space-y-1">
                            <div class="flex items-center gap-2.5">
                                <p class="font-medium tracking-tight">{{ __(':browser on :platform', ['browser' => $session['browser'] ?? __('Unknown browser'), 'platform' => $session['platform'] ?? __('Unknown platform')]) }}</p>
                                @if ($session['current'])
                                    <flux:badge size="sm" color="green">{{ __('This device') }}</flux:badge>
                                @endif
                            </div>
                            <p class="text-zinc-500 dark:text-zinc-400 text-xs">
                                @if ($session['ip'] !== null)
                                    {{ $session['ip'] }}
                                    <span class="opacity-50 mx-1">/</span>
                                @endif
                                {{ __('Last active :time', ['time' => $session['last_active']]) }}
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <div>
            <flux:modal.trigger name="logout-other-devices">
                <flux:button variant="filled">{{ __('Log out other devices') }}</flux:button>
            </flux:modal.trigger>
        </div>
    </div>

    <flux:modal name="logout-other-devices" class="max-w-md md:min-w-md">
        <form wire:submit="logoutOtherDevices" class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Log out other devices') }}</flux:heading>
                <flux:text>{{ __('Enter your password to log out on your other devices. You stay signed in on this device.') }}</flux:text>
            </div>

            <flux:input
                wire:model="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="current-password"
                viewable
            />

            <div class="flex gap-3 justify-end">
                <flux:modal.close>
                    <flux:button variant="outline">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Log out other devices') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
```

`flux:icon.computer-desktop`, `flux:icon.device-phone-mobile` and `flux:icon.device-tablet` are in the free Flux stubs; the component uses no Pro component. The rows are read once per request (`#[Computed]`); `unset($this->sessions)` after the action makes the re-render read them again.

- [ ] **Step 6: Render it on the security page**

`resources/views/pages/settings/security/security.blade.php`: replace

```blade
                    <x-passkey-registration />
                </div>
            </section>
        @endif
    </x-pages::settings.layout>
```

with

```blade
                    <x-passkey-registration />
                </div>
            </section>
        @endif

        {{-- §6.6, CD-2: not during an impersonation, where the component answers 403. --}}
        @unless (\App\Tenancy\Auth\Impersonation::isActive())
            <livewire:pages::settings.sessions />
        @endunless
    </x-pages::settings.layout>
```

It sits outside the two-factor and passkeys `@if`s, so it renders whatever modules and Fortify features are on. `security.php` does not change.

- [ ] **Step 7: Copy**

Lang keys, in both files, at their sorted positions (PHP `sort()` order). In `en.json` each value equals its key.

| Key | nl | Position |
|---|---|---|
| `:browser on :platform` | `:browser op :platform` | right after `2FA recovery codes`, before `:count checks failed` |
| `Enter your password to log out on your other devices. You stay signed in on this device.` | `Vul je wachtwoord in om op je andere apparaten uit te loggen. Op dit apparaat blijf je ingelogd.` | right after `Enter your email to receive a password reset link`, before `Environment file` |
| `Last active :time` | `Laatst actief :time` | right after `Language`, before `Last backup` |
| `Log out other devices` | `Andere apparaten uitloggen` | right after `Log out first to accept this invitation.`, before `Logged in as :name` |
| `Other devices signed out` | `Andere apparaten uitgelogd` | right after `Organisations and their addresses`, before `Overview` |
| `Sessions` | `Sessies` | right after `Session driver`, before `Sessions are stored in the database` |
| `The devices where you are signed in` | `De apparaten waarop je bent ingelogd` | right after `The database is reachable (:connection)`, before `The environment file [:path] does not exist.` |
| `This device` | `Dit apparaat` | right after `This demo account no longer uses the demo password.`, before `This invitation has already been accepted.` |
| `Unknown browser` | `Onbekende browser` | right after `Unknown accent :accent; using neutral.`, before `Unknown platform` |
| `Unknown platform` | `Onbekend platform` | right after `Unknown browser`, before `Unknown setup answers: :keys` |
| `You were logged out on your other devices.` | `Je bent op je andere apparaten uitgelogd.` | right after `You have been invited to join :organisation. Use the button below to choose your name and a password.`, before `You will be prompted for a secure, random pin during login, …` |

`Password`, `Cancel` and `Too many attempts. Try again in :seconds seconds.` already exist. The validation message for a wrong password is `current_password`'s, already in `lang/nl/validation.php`.

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Unit/UserAgentTest.php tests/Feature/Settings tests/Feature/Audit tests/Feature/LangFilesTest.php tests/Feature/LangCoverageTest.php tests/Feature/Auth tests/Feature/Impersonation tests/Feature/Passkeys tests/Arch`

Expected: PASS. `ActivityLogTest`'s 'gives every activity event a Dutch label and a group' now has an `OtherSessionsEnded` row; `SecurityTest`, `SecurityActionsTest`, `ActionLimitsTest` and `AuthenticateSessionTest` stay green on the array driver (the list is simply empty there).

- [ ] **Step 9: Verify by mutation**

Revert each change locally, run the named test, expect it to fail, then restore the change. Never use `git stash`: copy the file aside (`cp <file> <scratchpad>/<name>.bak`), edit it, restore it with `cp`, and run `php artisan view:clear` after a Blade or component change.
1. In `logoutOtherDevices()`, drop `->where('id', '!=', session()->getId())`. Expect 'signs a second "remember me" browser out…' to fail with 302 instead of 200 for this device, and 'ends only the user's other sessions…' to fail on `$rowsOf($member)` (`[]`).
2. Replace the delete with `$ended = 0;`. Expect 'ends only the user's other sessions…' to fail on the rows (three instead of one). The remember-me test still passes: the rehash alone signs the laptop out.
3. Drop `Auth::logoutOtherDevices($this->password);`. Expect the remember-me test to fail with 200 instead of a redirect (the laptop's remember cookie signs it in again) and 'ends only…' on the unchanged password hash.
4. Drop `->where('user_id', $user->id)` from the delete. Expect 'ends only…' to fail: the colleague's row is gone.
5. Empty `hydrate()`. Expect 'hides the sessions during an impersonation…' to fail with 200 instead of 403.
6. Move `UserActionLimit::logoutOtherDevices()->attempt(...)` after `$this->validate(...)`. Expect 'counts every attempt…' to fail: the sixth attempt has no error.
7. Drop the `last_activity` filter from `sessions()`. Expect 'lists the user's live sessions…' to fail: the page contains "Firefox on Windows".
8. Use `'key' => (string) $row->id`. Expect 'lists the user's live sessions…' to fail: the page does not contain the keyed hash (and would contain the id).
9. Use `'current' => true`. Expect 'lists the user's live sessions…' to fail: "This device" appears twice.
10. In `security.blade.php`, drop the `@unless`/`@endunless` around the component. Expect 'hides the sessions during an impersonation…' to fail with 403 instead of 200 for the page (the component's `mount()` aborts it).
11. Move the component line inside the passkeys `@if ($canManagePasskeys)`. Expect 'shows the sessions with two-factor authentication and passkeys off' to fail.
12. In `UserAgent::BROWSERS`, delete the Edge line. Expect four `UserAgentTest` rows to fail (Edge read as Chrome or Safari).
13. In `UserAgent::device()`, drop the Android-without-Mobile condition. Expect the two Android tablet rows to fail with `desktop`.
14. In `UserAgent::parse()`, drop the `substr()`. Expect 'reads only the start of an oversized header' to fail with `Firefox`.

- [ ] **Step 10: CHANGELOG**

Under `## [Unreleased]`, `### Added`, append:

```markdown
- Settings → Security lists your sessions: browser and platform, IP address, last activity and *This device*. *Log out other devices* asks for your password (at most 5 attempts a minute), signs out every other session and "remember me" login on its next request, and is recorded in the audit log (*Other devices signed out*). It is hidden during an impersonation.
```

- [ ] **Step 11: The gate, then commit**

```bash
composer fix
vendor/bin/phpstan analyse app/Support/UserAgent.php resources/views/pages/settings/sessions/sessions.php tests/Feature/Settings/SessionsTest.php tests/Unit/UserAgentTest.php tests/Pest.php
composer check
composer test:pgsql
php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser
npm run build && composer test:browser
npm run build:setup && git diff --exit-code resources/setup/setup.css
php artisan config:clear
```

Run the `phpstan` line before `composer check`: the closures in `SessionsTest.php` call `withCredentials()`/`withUnencryptedCookies()` through `Pest\Laravel`'s functions, because `test()->withCredentials()` does not type-check (`TestCall|HigherOrderTapProxy`), and `sessions()` builds its list with `foreach`, because `Collection::map()` widens the `device` literal to `string`. Task 1 changes no setup view, so expect no stylesheet diff.

```bash
git add app/Support/UserAgent.php app/Support/RateLimits/UserActionLimit.php app/Enums/ActivityEvent.php \
    resources/views/pages/settings/sessions/sessions.php resources/views/pages/settings/sessions/sessions.blade.php \
    resources/views/pages/settings/security/security.blade.php lang/nl.json lang/en.json CHANGELOG.md \
    tests/Pest.php tests/Unit/UserAgentTest.php tests/Feature/Settings/SessionsTest.php
git commit -m "feat: list the user's sessions and log out other devices

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: The second-factor setting (§6.6 second factor (setting), §4.3; CD-3)

Organisation admins and super admins switch `organisations.require_second_factor` on the organisation's edit page. The column, its cast, `#[Fillable]` entry and factory default (`false`) exist since M4; until now no page or action wrote it, and M4 pinned that with the RL-17 test, which this task rewrites into tests of the setting. The flag rides on `UpdateOrganisation`, so it is locked, re-checked and audited like the other fields (`organisation.updated`'s changes, CD-9). A change in either direction asks for a fresh password confirmation, because the switch decides how every member signs in. An organisation admin who has no second factor of their own cannot turn the rule on (the page they are on would then send them away, CD-3). Task 3 enforces the rule; this task only stores it.

This task also creates `App\Tenancy\Auth\SecondFactor` with `satisfiedBy()`, because the "own second factor first" rule needs it here, one task before the middleware. Task 3 adds `requiredFor()`, `missingFor()` and `explanation()` to the same class.

**Files:**
- Create:
  - `app/Tenancy/Auth/SecondFactor.php` (`satisfiedBy()` only; Task 3 extends it)
- Modify:
  - `app/Actions/Organisations/UpdateOrganisation.php` (a last parameter `bool $requireSecondFactor`; the own-second-factor rule inside the transaction, after the lock; the flag is filled with the other fields, so it lands in the changes)
  - `resources/views/pages/organisations/edit/edit.php` (`ConfirmsPasswords`; `bool $requireSecondFactor`, set in `mount()` and after a save; `save()` checks the own-second-factor rule, then asks for the password, only when the flag changes; the docblock's "No second-factor setting until M7 (RL-17)" is replaced)
  - `resources/views/pages/organisations/edit/edit.blade.php` (a free `flux:switch` below the description; its description names a passkey only while the passkeys module is on)
  - `lang/nl.json`, `lang/en.json` (four keys)
  - `CHANGELOG.md`
- Test:
  - `tests/Feature/Auth/SecondFactorTest.php` (new: `satisfiedBy()`)
  - `tests/Feature/Organisations/UpdateOrganisationTest.php` (every existing call passes `false`; the audit and the own-second-factor rule)
  - `tests/Feature/Organisations/OrganisationEditTest.php` (the RL-17 test rewritten into four tests of the setting, over HTTP where the password confirmation matters)
  - `tests/Feature/Authorization/LockOrderTest.php` (its one `UpdateOrganisation` call passes `false`)

**Interfaces:**
- Consumes:
  - `App\Concerns\ConfirmsPasswords::passwordConfirmationRequired(?string $returnTo = null): bool`
  - `Organisation::$require_second_factor` (boolean cast, fillable, factory `false`)
  - `User::hasEnabledTwoFactorAuthentication()` (Fortify; with `confirm => true` it needs `two_factor_confirmed_at`), `User::passkeys()`, `User::isSuperAdmin()`
  - `App\Support\Skv::passkeysEnabled(): bool`
  - `HostScope::lockForWrite(Organisation $organisation): Organisation`, `RecordActivity::handle(...)`
  - test helpers: `asOrgAdmin()`, `asSuperAdmin()`, `onAdminHost()`, `usePasskeysModule()`, `pageSnapshot()`, `callPageAction()`, `recordedActivity()`
- Produces:
  - `App\Tenancy\Auth\SecondFactor::satisfiedBy(User $user): bool` (final class, static): true for a confirmed TOTP secret, or, only while `Skv::passkeysEnabled()`, for at least one passkey.
  - `UpdateOrganisation::handle(User $actor, Organisation $organisation, string $name, string $slug, ?string $description, bool $requireSecondFactor): Organisation`. Turning the rule on (stored `false`, argument `true`) as a non-super admin without `SecondFactor::satisfiedBy($actor)` throws `ValidationException` with exactly `['requireSecondFactor' => ['Set up a second factor for your own account first.']]` and writes nothing. A changed flag is recorded in `organisation.updated` as `old.require_second_factor` / `attributes.require_second_factor` (booleans).
  - `pages::organisations.edit`: public `bool $requireSecondFactor`; the switch `data-test="organisation-require-second-factor"`.

Background for the implementer:
- **Where the rule is checked:** `UpdateOrganisation` is the authority (actions authorise and validate themselves). The page checks the same condition first, before it asks for the password, so an organisation admin without a second factor is not sent through a password confirmation that the action would then refuse. The condition compares with the stored flag: an admin without a second factor may turn the rule off, and may rename an organisation whose rule is already on.
- **Super admins** are not members (`organisation_id` null, or kept from a promotion but ignored, CD-4), so the organisation's rule never applies to them and the check skips them.
- **Password confirmation:** `password.confirm` guards the page request only; the Livewire `save()` asks again when the flag changes (CD-3, `AGENTS.md` › Tenancy and authorization). The return URL is built on the server with `absolute: false`. Unchanged flags keep today's behaviour: no confirmation for a rename. The other unsaved fields are not parked across the confirmation (unlike `users.edit`): the flag is a single switch, and the page reloads with the stored values.
- **Error key:** the action throws on `requireSecondFactor`, the component property's name, as it throws `slug` and `name`; the free `flux:switch` with a `label` renders `<flux:error>` for its `wire:model` (`vendor/livewire/flux/stubs/resources/views/flux/with-reversed-inline-field.blade.php`).
- **After Task 3** the edit page itself is behind the rule. The tests below give the organisation admin a confirmed TOTP secret wherever the rule is on and the admin then requests a page, so they stay green once `EnsureSecondFactor` is in place.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Auth/SecondFactorTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §6.6, §6.8 */

use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Auth\SecondFactor;

it('counts a confirmed TOTP secret, and a passkey only while the passkeys module is on (§6.6, §6.8)', function (string $factor, bool $passkeys, bool $satisfied): void {
    usePasskeysModule($passkeys);
    $user = User::factory()->for(Organisation::factory())->create();

    match ($factor) {
        'a confirmed TOTP secret' => $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save(),
        // Fortify's confirm option is on: a secret whose setup was never confirmed is no second factor.
        'an unconfirmed TOTP secret' => $user->forceFill(['two_factor_secret' => encrypt('secret')])->save(),
        'a passkey' => $user->passkeys()->create(['name' => 'Laptop', 'credential_id' => 'credential-1', 'credential' => []]),
        default => null, // 'nothing'
    };

    expect(SecondFactor::satisfiedBy($user))->toBe($satisfied);
})->with([
    'nothing' => ['nothing', true, false],
    'an unconfirmed TOTP secret' => ['an unconfirmed TOTP secret', true, false],
    'a confirmed TOTP secret' => ['a confirmed TOTP secret', true, true],
    'a confirmed TOTP secret, passkeys off' => ['a confirmed TOTP secret', false, true],
    'a passkey' => ['a passkey', true, true],
    'a passkey, passkeys off' => ['a passkey', false, false],
]);
```

`tests/Feature/Organisations/UpdateOrganisationTest.php`:
- The docblock becomes `/** covers §4.3, §4.8, §6.6 */`.
- Every existing `app(UpdateOrganisation::class)->handle(...)` call gains `false` as its last argument (11 calls, at :20, :31, :47, :60, :80, :97, :113, :127, :139, :148 and :157 on `main`). For example :20 becomes

```php
    $updated = app(UpdateOrganisation::class)->handle($admin, $organisation, 'Acme Rockets', 'acme', 'Anvils too', false);
```

  and :127 becomes

```php
        app(UpdateOrganisation::class)->handle($admin, $organisation, $organisation->name, 'acme-new', $organisation->description, false);
```

  The factory default is `false`, so none of these tests changes the flag, and 'records organisation.updated with the changes' keeps its exact `old` / `attributes` arrays.
- Append (the imports it needs, `ValidationException`, `User`, `ActivityEvent`, are already there):

```php
it('records the second-factor rule in organisation.updated\'s changes (§6.6, CD-3)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $superAdmin = asSuperAdmin();
    onAdminHost();

    app(UpdateOrganisation::class)->handle($superAdmin, $organisation, $organisation->name, 'acme', $organisation->description, true);

    $entry = recordedActivity(ActivityEvent::OrganisationUpdated)->sole();
    expect($organisation->fresh()?->require_second_factor)->toBeTrue()
        ->and($entry->attribute_changes?->get('old'))->toBe(['require_second_factor' => false])
        ->and($entry->attribute_changes?->get('attributes'))->toBe(['require_second_factor' => true]);

    app(UpdateOrganisation::class)->handle($superAdmin, $organisation, $organisation->name, 'acme', $organisation->description, false);

    expect($organisation->fresh()?->require_second_factor)->toBeFalse()
        ->and(recordedActivity(ActivityEvent::OrganisationUpdated)->last()?->attribute_changes?->get('attributes'))->toBe(['require_second_factor' => false]);
});

it('refuses an organisation admin without a second factor of their own turning the rule on (CD-3)', function (): void {
    $organisation = Organisation::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $admin = asOrgAdmin($organisation);

    try {
        app(UpdateOrganisation::class)->handle($admin, $organisation, 'Acme Rockets', 'acme', $organisation->description, true);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe([
            'requireSecondFactor' => [__('Set up a second factor for your own account first.')],
        ]);
    }

    expect($organisation->fresh()?->only(['name', 'require_second_factor']))->toBe(['name' => 'Acme', 'require_second_factor' => false])
        ->and(recordedActivity(ActivityEvent::OrganisationUpdated))->toBeEmpty();
});

it('asks for an own second factor only when an organisation admin turns the rule on (CD-3)', function (string $actor, bool $before, bool $after): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme', 'require_second_factor' => $before]);

    $user = match ($actor) {
        'an organisation admin with a confirmed TOTP secret' => tap(asOrgAdmin($organisation), function (User $admin): void {
            $admin->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
        }),
        // Super admins are not members: the rule never applies to them through an organisation (CD-3, CD-4).
        'a super admin without a second factor' => tap(asSuperAdmin(), function (): void {
            onAdminHost();
        }),
        default => asOrgAdmin($organisation), // 'an organisation admin without a second factor'
    };

    app(UpdateOrganisation::class)->handle($user, $organisation, 'Acme Rockets', 'acme', $organisation->description, $after);

    expect($organisation->fresh()?->only(['name', 'require_second_factor']))->toBe(['name' => 'Acme Rockets', 'require_second_factor' => $after]);
})->with([
    'an organisation admin with a second factor turns it on' => ['an organisation admin with a confirmed TOTP secret', false, true],
    'a super admin without one turns it on' => ['a super admin without a second factor', false, true],
    'an organisation admin without one turns it off' => ['an organisation admin without a second factor', true, false],
    'an organisation admin without one keeps it on while renaming' => ['an organisation admin without a second factor', true, true],
]);
```

`tests/Feature/Authorization/LockOrderTest.php`: the `'UpdateOrganisation'` row's closure (:226 on `main`) becomes

```php
        return fn () => app(UpdateOrganisation::class)->handle($superAdmin, $organisation, 'Renamed', $organisation->slug, null, false);
```

`tests/Feature/Organisations/OrganisationEditTest.php`:
- The docblock becomes `/** covers §4.3, §6.2, §6.4, §6.6 */`.
- Replace the whole test `it('has no second-factor setting yet (RL-17)', …);` (:112-127 on `main`) with these four tests. They rewrite it, never delete it: the first one keeps its RL-17 tag and now pins the setting the RL-17 test said did not exist yet.

```php
it('offers the second-factor rule to organisation admins and super admins, set as stored (§6.6, RL-17, CD-3)', function (string $actor): void {
    $acme = Organisation::factory()->create(['slug' => 'acme', 'require_second_factor' => true]);

    if ($actor === 'a super admin') {
        asSuperAdmin();
        onAdminHost();
    } else {
        // A second factor of their own: the rule is on, and this page is behind it.
        asOrgAdmin($acme)->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    }

    $page = route('organisations.edit', $acme->id);

    $this->get($page)
        ->assertOk()
        ->assertSee('wire:model="requireSecondFactor"', escape: false)
        ->assertSee(__('Require a second factor'));
    expect(pageSnapshot($page, 'pages::organisations.edit')['data']['requireSecondFactor'] ?? null)->toBeTrue();
})->with(['an organisation admin', 'a super admin']);

it('names a passkey in the rule\'s description only while the passkeys module is on (§6.8, CD-3)', function (bool $passkeys): void {
    usePasskeysModule($passkeys);
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    asOrgAdmin($acme);

    $withPasskey = __('Members must set up two-factor authentication or a passkey before they can continue. Changing this asks for your password.');
    $totpOnly = __('Members must set up two-factor authentication before they can continue. Changing this asks for your password.');

    $this->get(route('organisations.edit', $acme->id))
        ->assertOk()
        ->assertSee($passkeys ? $withPasskey : $totpOnly)
        ->assertDontSee($passkeys ? $totpOnly : $withPasskey);
})->with(['passkeys on' => true, 'passkeys off' => false]);

it('asks for a fresh password confirmation before changing the second-factor rule and comes back to this page (CD-3)', function (bool $before): void {
    $acme = Organisation::factory()->create(['slug' => 'acme', 'require_second_factor' => $before]);
    asOrgAdmin($acme)->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $page = route('organisations.edit', $acme->id);
    $snapshot = pageSnapshot($page, 'pages::organisations.edit');

    callPageAction($snapshot, 'save', updates: ['requireSecondFactor' => ! $before])
        ->assertOk()
        ->assertJsonPath('components.0.effects.redirect', route('password.confirm'));

    expect($acme->fresh()?->require_second_factor)->toBe($before);

    $this->post(route('password.confirm.store'), ['password' => 'password'])->assertRedirect($page);
    callPageAction($snapshot, 'save', updates: ['requireSecondFactor' => ! $before])
        ->assertOk()
        ->assertJsonMissingPath('components.0.effects.redirect');

    expect($acme->fresh()?->require_second_factor)->toBe(! $before);
})->with(['turning it on' => false, 'turning it off' => true]);

it('tells an organisation admin without a second factor to set one up before asking for the password (CD-3)', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    asOrgAdmin($acme);

    Livewire::test('pages::organisations.edit', ['organisation' => $acme->id])
        ->set('requireSecondFactor', true)
        ->call('save')
        ->assertHasErrors(['requireSecondFactor' => __('Set up a second factor for your own account first.')])
        ->assertNoRedirect();

    expect($acme->fresh()?->require_second_factor)->toBeFalse();
});
```

The password-confirmation test runs over HTTP (`pageSnapshot()` + `callPageAction()`), as `UsersEditTest`'s RL-18 tests do. The last test may use `Livewire::test()`: it proves the error comes before the confirmation (no session confirmation is set, so a confirmation first would redirect), not a middleware rule. The existing 'edits the own organisation as its admin' keeps proving that a save without a flag change asks for no confirmation (`assertNoRedirect()` without a confirmed password).

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Auth/SecondFactorTest.php tests/Feature/Organisations tests/Feature/Authorization/LockOrderTest.php`

Expected: FAIL, as follows:
- `SecondFactorTest`: all six rows error with `Class "App\Tenancy\Auth\SecondFactor" not found`.
- 'offers the second-factor rule…' (both rows) and 'names a passkey…' (both rows) fail on `assertSee('wire:model="requireSecondFactor"')` / the description.
- 'asks for a fresh password confirmation…' (both rows) gets a 500 instead of 200: `Public property [$requireSecondFactor] not found`.
- 'tells an organisation admin…' errors with "Unable to set component data. Public property [$requireSecondFactor] not found".
- 'records the second-factor rule…' throws `ItemNotFoundException` from `sole()`: PHP ignores the extra argument, so nothing changes and nothing is recorded.
- 'refuses an organisation admin without a second factor…' fails with "Expected a ValidationException."
- 'asks for an own second factor only…': the first three rows fail on `require_second_factor`; 'keeps it on while renaming' already passes (it guards against a check that ignores the stored value).
- Every other test in these files, `LockOrderTest` included, passes: the extra `false` is ignored until Step 4.

- [ ] **Step 3: `SecondFactor::satisfiedBy()`**

Create `app/Tenancy/Auth/SecondFactor.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy\Auth;

use App\Models\User;
use App\Support\Skv;

/**
 * The second factor (§6.6, §6.8): a confirmed TOTP secret, or, only while the passkeys module is on, at least
 * one passkey. With the module off a passkey no longer signs anyone in, so it no longer counts either.
 */
final class SecondFactor
{
    public static function satisfiedBy(User $user): bool
    {
        if ($user->hasEnabledTwoFactorAuthentication()) {
            return true;
        }

        return Skv::passkeysEnabled() && $user->passkeys()->exists();
    }
}
```

`hasEnabledTwoFactorAuthentication()` needs `two_factor_confirmed_at` because `config/fortify.php` enables 2FA with `'confirm' => true`, so a secret whose setup was abandoned does not count. `passkeys()` is bound to `UnscopedPasskey` (not a tenant model), so the query works on every host.

- [ ] **Step 4: `UpdateOrganisation` takes the flag**

`app/Actions/Organisations/UpdateOrganisation.php`. Add `use App\Tenancy\Auth\SecondFactor;` after `use App\Models\User;`. Replace the class docblock with:

```php
/**
 * Edits an organisation (§4.3): name, description and the second-factor rule (§6.6) for its organisation admins
 * and super admins; the slug for super admins only (OD-3 = B), because a new slug moves the organisation to a new
 * host. The row is locked and re-checked, so an archived or another tenant's organisation is refused (RK-I11). The
 * old slug is free at once. An organisation admin turns the rule on only with a second factor of their own, so the
 * rule never sends them away from the page they set it on (CD-3); super admins are not members and are not asked.
 * Records organisation.updated with the changed fields only.
 */
```

Replace the signature and the transaction's opening line:

```php
    public function handle(User $actor, Organisation $organisation, string $name, string $slug, ?string $description, bool $requireSecondFactor): Organisation
    {
```

```php
        return DB::transaction(function () use ($actor, $organisation, $name, $slug, $description, $requireSecondFactor): Organisation {
```

After the slug check (`throw ValidationException::withMessages(['slug' => …]); }`), insert:

```php

            if ($requireSecondFactor && ! $locked->require_second_factor && ! $actor->isSuperAdmin() && ! SecondFactor::satisfiedBy($actor)) {
                throw ValidationException::withMessages(['requireSecondFactor' => __('Set up a second factor for your own account first.')]);
            }
```

Replace the `fill()` line:

```php
            $locked->fill(['name' => $name, 'slug' => $slug, 'description' => $description, 'require_second_factor' => $requireSecondFactor]);
```

The rest is unchanged: `getDirty()` now includes `require_second_factor` when it changes, so the entry's `old` and `attributes` carry it next to the other changed fields, and a save that changes nothing records nothing. The check runs after `lockForWrite()`, so it compares with the locked row (CD-5's lock order is unchanged: the organisation row is still the only lock).

- [ ] **Step 5: The switch on the edit page**

`resources/views/pages/organisations/edit/edit.php`. The imports become:

```php
use App\Actions\Auth\IssueHandoff;
use App\Actions\Organisations\UpdateOrganisation;
use App\Concerns\ConfirmsPasswords;
use App\Concerns\OrganisationValidationRules;
use App\Concerns\ResolvesCurrentUser;
use App\Models\Organisation;
use App\Tenancy\Auth\SecondFactor;
use App\Tenancy\Facades\Tenant;
use App\Tenancy\Hosts;
use App\Tenancy\HostScope;
use Flux\Flux;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
```

Replace the docblock's last line, ` * No second-factor setting until M7 (RL-17).`, so the docblock reads:

```php
/**
 * Organisation edit page (§4.3). Takes a plain id and resolves it per request inside the host's scope
 * (OR-M5). Organisation admins edit name and description; only super admins change the slug (OD-3 = B).
 * A rename made on the organisation's own host continues on the new host through a handoff (ORG-16).
 * Both also switch the second-factor rule (§6.6, CD-3). A change in either direction asks for a fresh password
 * confirmation first, because the switch decides how every member signs in; an organisation admin turns the rule
 * on only with a second factor of their own, which this page checks before asking and UpdateOrganisation again.
 */
```

The traits become:

```php
    use ConfirmsPasswords;
    use OrganisationValidationRules;
    use ResolvesCurrentUser;
```

After `public ?string $description = null;` add:

```php

    public bool $requireSecondFactor = false;
```

In `mount()`, after `$this->description = $model->description;` add:

```php
        $this->requireSecondFactor = $model->require_second_factor;
```

In `save()`, between `$this->authorize('update', $organisation);` and `$previousSlug = $organisation->slug;`, insert:

```php

        if ($this->requireSecondFactor !== $organisation->require_second_factor) {
            // Before the password: a confirmation the action would then refuse is a wasted round trip.
            if ($this->requireSecondFactor && ! $actor->isSuperAdmin() && ! SecondFactor::satisfiedBy($actor)) {
                throw ValidationException::withMessages(['requireSecondFactor' => __('Set up a second factor for your own account first.')]);
            }

            if ($this->passwordConfirmationRequired(route('organisations.edit', $this->organisationId, absolute: false))) {
                return;
            }
        }
```

Replace the action call:

```php
        $updated = $updateOrganisation->handle($actor, $organisation, $this->name, $this->slug, $this->description, $this->requireSecondFactor);
```

And at the end of `save()`, after `$this->slug = $updated->slug;`:

```php
        $this->requireSecondFactor = $updated->require_second_factor;
```

`$organisation` is the page's per-request read (`organisation()`), so the comparison uses the stored value, never a client-sent one; the action compares again with the locked row.

`resources/views/pages/organisations/edit/edit.blade.php`: after the `flux:textarea` for the description, add:

```blade

        <flux:switch
            wire:model="requireSecondFactor"
            :label="__('Require a second factor')"
            :description="Skv::passkeysEnabled()
                ? __('Members must set up two-factor authentication or a passkey before they can continue. Changing this asks for your password.')
                : __('Members must set up two-factor authentication before they can continue. Changing this asks for your password.')"
            data-test="organisation-require-second-factor"
        />
```

`flux:switch` is in the free stubs (`vendor/livewire/flux/stubs/resources/views/flux/switch.blade.php`), so no Pro branch is needed; with a `label` it renders its own `<flux:error name="requireSecondFactor">`. `Skv` is the global alias (RL-1).

- [ ] **Step 6: Lang keys**

Add to both files at their sorted places (PHP `sort()`, byte-wise): the two "Members must…" keys after `"Management"`, "Require a second factor" after `"Remove passkey"`, and "Set up a second factor…" after `"Set up Claude Code"` (neighbours on `main`; earlier tasks' keys may sit between them).

`lang/nl.json`:

```json
    "Members must set up two-factor authentication before they can continue. Changing this asks for your password.": "Leden moeten tweestapsverificatie instellen voordat ze verder kunnen. Voor deze wijziging vragen we je wachtwoord.",
    "Members must set up two-factor authentication or a passkey before they can continue. Changing this asks for your password.": "Leden moeten tweestapsverificatie of een passkey instellen voordat ze verder kunnen. Voor deze wijziging vragen we je wachtwoord.",
    "Require a second factor": "Tweede factor verplicht stellen",
    "Set up a second factor for your own account first.": "Stel eerst een tweede factor in voor je eigen account.",
```

`lang/en.json`:

```json
    "Members must set up two-factor authentication before they can continue. Changing this asks for your password.": "Members must set up two-factor authentication before they can continue. Changing this asks for your password.",
    "Members must set up two-factor authentication or a passkey before they can continue. Changing this asks for your password.": "Members must set up two-factor authentication or a passkey before they can continue. Changing this asks for your password.",
    "Require a second factor": "Require a second factor",
    "Set up a second factor for your own account first.": "Set up a second factor for your own account first.",
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Auth/SecondFactorTest.php tests/Feature/Organisations tests/Feature/Authorization/LockOrderTest.php tests/Feature/LangFilesTest.php tests/Feature/LangCoverageTest.php tests/Arch`

Expected: PASS.

- [ ] **Step 8: Verify by mutation**

Revert each change locally, run the named test, expect it to fail, then restore the change. Never use `git stash`: copy the file aside with `cp` and copy it back; run `php artisan view:clear` after a Blade mutation.
1. In `UpdateOrganisation`, drop `! $actor->isSuperAdmin() &&` from the new check. Expect 'asks for an own second factor only…' row 'a super admin without one turns it on' and 'records the second-factor rule…' to fail with the `requireSecondFactor` `ValidationException`.
2. In `UpdateOrganisation`, drop `! $locked->require_second_factor &&`. Expect the row 'an organisation admin without one keeps it on while renaming' to fail.
3. In `UpdateOrganisation`, drop `$requireSecondFactor &&`. Expect ten existing tests whose organisation admin has no second factor and passes `false` to fail with the `requireSecondFactor` error (among them 'updates name and description of the own organisation for its admin', 'records organisation.updated with the changes' and the five Markdown rows).
4. In `UpdateOrganisation`, remove the whole new `if` block. Expect 'refuses an organisation admin without a second factor…' to fail with "Expected a ValidationException."
5. In `UpdateOrganisation`, drop `'require_second_factor' => $requireSecondFactor` from `fill()`. Expect 'records the second-factor rule…' and the first three rows of 'asks for an own second factor only…' to fail.
6. In `edit.php`, replace the page's own-second-factor condition with `false`. Expect 'tells an organisation admin without a second factor…' to fail (the page redirects to `password.confirm` instead).
7. In `edit.php`, make `passwordConfirmationRequired(...)` unreachable (`if (false && …)`). Expect both rows of 'asks for a fresh password confirmation…' to fail on the missing redirect.
8. In `edit.php`, replace `$this->requireSecondFactor !== $organisation->require_second_factor` with `true`. Expect 'edits the own organisation as its admin', the two slug tests, 'shows the slug read-only…' and 'shows the archived-organisation error…' to fail: a save without a flag change must not ask for the password.
9. In `edit.php`, remove `$this->requireSecondFactor = $model->require_second_factor;` from `mount()`. Expect both rows of 'offers the second-factor rule…' to fail on `requireSecondFactor`.
10. In `edit.blade.php`, negate `Skv::passkeysEnabled()`. Expect both rows of 'names a passkey in the rule's description…' to fail.
11. In `SecondFactor::satisfiedBy()`, drop `Skv::passkeysEnabled() &&`. Expect the row 'a passkey, passkeys off' to fail.
12. In `SecondFactor::satisfiedBy()`, replace `$user->hasEnabledTwoFactorAuthentication()` with `$user->two_factor_secret !== null`. Expect the row 'an unconfirmed TOTP secret' to fail.

- [ ] **Step 9: CHANGELOG**

Under `## [Unreleased]`, `### Added`, append:

```markdown
- Organisations can require a second factor of their members: organisation admins and super admins switch it on the organisation's edit page after confirming their password. An organisation admin needs a second factor of their own before turning it on. The change is recorded in the audit log.
```

- [ ] **Step 10: The gate, then commit**

```bash
composer fix
vendor/bin/phpstan analyse tests/Feature/Auth/SecondFactorTest.php tests/Feature/Organisations/UpdateOrganisationTest.php
composer check
composer test:pgsql
php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser
npm run build && composer test:browser
npm run build:setup && git diff --exit-code resources/setup/setup.css
php artisan config:clear
```

Run the `phpstan` line before `composer check`: the `match` in `SecondFactorTest` and `UpdateOrganisationTest` needs its `default =>` arm (`match.unhandled`). Task 2 changes no setup view, so expect no stylesheet diff.

```bash
git add app/Tenancy/Auth/SecondFactor.php app/Actions/Organisations/UpdateOrganisation.php \
    resources/views/pages/organisations/edit/edit.php resources/views/pages/organisations/edit/edit.blade.php \
    lang/nl.json lang/en.json CHANGELOG.md \
    tests/Feature/Auth/SecondFactorTest.php tests/Feature/Organisations/UpdateOrganisationTest.php \
    tests/Feature/Organisations/OrganisationEditTest.php tests/Feature/Authorization/LockOrderTest.php
git commit -m "feat: let organisation admins and super admins require a second factor

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: EnsureSecondFactor (§6.6 second factor (enforcement), §6.8 passkeys off; CD-4)

The rule Task 2 stores is enforced here. `App\Http\Middleware\EnsureSecondFactor` joins the three authenticated route groups (not the global `web` group, §6.6). A user the rule applies to who has no second factor is sent to the security page with an explanation on a GET and refused with a 403 on any other method, until they set up two-factor authentication or, while the passkeys module is on, a passkey. Who has a factor and who needs one is decided in one place, `App\Tenancy\Auth\SecondFactor` (Task 2 created it with `satisfiedBy()`; this task adds `requiredFor()`, `missingFor()` and `explanation()`). Super admins follow `config('skv.second_factor.super_admins')` (`REQUIRE_SECOND_FACTOR_SUPER_ADMINS`, default false) on every host. The security page shows the same explanation as a callout while the rule applies and is unmet.

**Files:**
- Create:
  - `app/Http/Middleware/EnsureSecondFactor.php`
- Modify:
  - `app/Tenancy/Auth/SecondFactor.php` (Task 2's file; replaced whole: `requiredFor()`, `missingFor()`, `explanation()` added, `satisfiedBy()` unchanged)
  - `config/skv.php` (`second_factor.super_admins`, `second_factor.exempt_routes`)
  - `.env.example` (`REQUIRE_SECOND_FACTOR_SUPER_ADMINS=false`)
  - `phpunit.xml` (the same key, forced `false`, so the owner's `.env` cannot switch it on for the suite)
  - `routes/web.php`, `routes/settings.php`, `routes/admin.php` (the `auth` groups become `['auth', EnsureSecondFactor::class]`)
  - `resources/views/pages/settings/security/security.php` (a `#[Computed] secondFactorNotice()`)
  - `resources/views/pages/settings/security/security.blade.php` (Task 1's version: the callout at the top of the settings layout; Task 1's `<livewire:pages::settings.sessions />` line below the passkeys section is not touched)
  - `lang/nl.json`, `lang/en.json` (four keys)
  - `CHANGELOG.md`
- Test:
  - `tests/Feature/Auth/EnsureSecondFactorTest.php` (new)
  - `tests/Feature/Auth/SecondFactorTest.php` (Task 2's file: `requiredFor()`)

**Interfaces:**
- Consumes:
  - Task 2: `App\Tenancy\Auth\SecondFactor::satisfiedBy(User $user): bool`, `tests/Feature/Auth/SecondFactorTest.php`, and `organisations.require_second_factor` being editable.
  - Task 1: `resources/views/pages/settings/security/security.blade.php` with the `<livewire:pages::settings.sessions />` line; nothing else of Task 1.
  - `App\Tenancy\Auth\Impersonation::isActive(): bool`, `App\Support\Skv::passkeysEnabled(): bool`, `User::isSuperAdmin()`
  - `Illuminate\Http\Request::routeIs(...$patterns)`
  - test helpers: `asMember()`, `asOrgAdmin()`, `asSuperAdmin()`, `onTenant()`, `onApex()`, `onAdminHost()`, `usePasskeysModule()`, `useImpersonationModule()`, `startImpersonatingOverHttp()`, `pageSnapshot()`, `callPageAction()`; `Tests\Support\SoftwareAuthenticator`
- Produces:
  - `SecondFactor::requiredFor(User $user): bool`: for a super admin `config('skv.second_factor.super_admins') === true`, whatever their `organisation_id`; for anyone else their own organisation's `require_second_factor` (false without an organisation, or for an archived one). Read the same on every host.
  - `SecondFactor::missingFor(User $user): bool` = `requiredFor() && ! satisfiedBy()`.
  - `SecondFactor::explanation(User $user): string`: one of four translated sentences (organisation or super admin; with or without "or a passkey").
  - `App\Http\Middleware\EnsureSecondFactor::handle(Request $request, Closure $next): Response` (final class).
  - `config('skv.second_factor.super_admins')` (bool, env `REQUIRE_SECOND_FACTOR_SUPER_ADMINS`, default false) and `config('skv.second_factor.exempt_routes')` (list of `routeIs()` patterns).
  - The security page's callout `data-test="second-factor-required"`.

Background for the implementer:
- **Which routes get it.** Appending the middleware to the three `auth` groups puts it on exactly these routes (`php artisan route:list` after this task): `dashboard`; the unnamed `settings` redirect (any method), `profile.edit`, `appearance.edit`, `security.edit`, `email-change.confirm`; `users.index`, `users.edit`, `users.invite`, `invitations.pending`, `organisations.index`, `organisations.create`, `system.index`, `organisations.edit`, `roles.index`, `activity.index`. Of the exempt names only `security.edit` is among them; the others (`password.confirm*`, `two-factor.*`, `passkey.*`, `well-known.passkeys`, `logout`, `auth.handoff*`, `impersonation.stop`, `setup.*`) live in Fortify's, the passkeys, the impersonation and the setup route files, outside these groups, so they are never blocked; the exemption keeps them open if someone moves them into a group or appends the middleware wider. Tasks 4 and 5 add `activity.export` and `invitations.bulk` inside `routes/admin.php`'s group, so they get the middleware without any change, and the coverage test below keeps holding for them.
- **Order.** The group's middleware runs before the route's own `password.confirm` and `can:` (`Authorize` is in the priority list after it, and `RequirePassword` comes from the route), so a user without a factor is redirected before a permission check answers 403. `VerifyTenantMembership`, `EnforceImpersonation` and `AuthenticateSession` run earlier, in the `web` group.
- **No loop.** `security.edit` is exempt; its own `password.confirm` sends the user to `password.confirm` (not in any group) and back to `security.edit`. The flash from the first redirect does not survive that extra request, which is why the security page shows the same text as a callout, read on every render.
- **Livewire.** The factor is set up through Livewire updates on the security page (the 2FA setup modal) and through the `passkey.*` JSON routes. Livewire's update, upload and preview routes are outside the groups, and `EnsureSecondFactor` is not Livewire persistent middleware, so updates never run it. The middleware still lets those routes through by **route name** (`*livewire.update`, `livewire.upload-file`, `livewire.preview-file`). It never uses `Livewire::isLivewireRequest()`: that is `request()->hasHeader('X-Livewire')` (`vendor/livewire/livewire/src/Mechanisms/HandleRequests/HandleRequests.php:119-122`), so any client could skip the rule by sending the header with `GET /dashboard`. A test pins that.
- **Impersonation.** During an impersonation the impersonator acts and cannot set up the target's factor, so the session passes (§6.6). `Impersonation::isActive()` reads the session.
- **Super admins.** `requiredFor()` checks `isSuperAdmin()` first: a super admin promoted from an organisation keeps its `organisation_id` (`SetSuperAdmin`), and that organisation's rule no longer applies to them. CD-4's copy ("Your organisation requires…") does not fit a super admin, who has no organisation, so `explanation()` has a super-admin sentence too.
- **Removing the last factor** stays allowed (CD-4): the security page's `disable()` and passkey removal are unchanged; the next page request sends the user back.
- **Existing tests and the browser suite.** No factory state, seeder, demo account or browser test sets `require_second_factor` (`OrganisationFactory` sets `false`; `grep -rn require_second_factor tests/Browser database/seeders` finds nothing), and `phpunit.xml` forces `REQUIRE_SECOND_FACTOR_SUPER_ADMINS=false`, so no existing flow is redirected. Task 2's tests give the organisation admin a TOTP secret wherever the rule is on. The prototype ran the whole suite (SQLite) and the modules-off run green with this task in place.
- **`email-change.confirm`** is in the settings group and not exempt: a user who still needs a second factor and opens an address-change link is sent to the security page first; the link keeps working until it expires. That is deliberate (the link changes the account's address), and Task 8 documents it.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Auth/EnsureSecondFactorTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §6.6, §6.8 */

use App\Authorization\Role;
use App\Http\Middleware\EnsureSecondFactor;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\SoftwareAuthenticator;

/** A signed-in member of acme, whose organisation requires a second factor; they have none yet. */
$memberUnderTheRule = fn (): User => asMember(Organisation::factory()->create(['slug' => 'acme', 'require_second_factor' => true]));

it('sends a member without a second factor from the dashboard to the security page, saying why (§6.6)', function () use ($memberUnderTheRule): void {
    usePasskeysModule(true);
    $memberUnderTheRule();

    $this->get(route('dashboard'))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('status', __('Your organisation requires a second factor. Set up two-factor authentication or a passkey to continue.'));
});

it('confirms the password on the way without a loop and explains the rule on the security page (§6.6)', function () use ($memberUnderTheRule): void {
    usePasskeysModule(true);
    $memberUnderTheRule();

    $this->get(route('dashboard'))->assertRedirect(route('security.edit'));
    $this->get(route('security.edit'))->assertRedirect(route('password.confirm'));
    $this->get(route('password.confirm'))->assertOk();
    $this->post(route('password.confirm.store'), ['password' => 'password'])->assertRedirect(route('security.edit'));

    $this->get(route('security.edit'))
        ->assertOk()
        ->assertSee('data-test="second-factor-required"', escape: false)
        ->assertSee(__('Your organisation requires a second factor. Set up two-factor authentication or a passkey to continue.'));
});

it('is satisfied by two-factor authentication set up on the security page, after which the dashboard opens (§6.6)', function () use ($memberUnderTheRule): void {
    $member = $memberUnderTheRule();
    $this->withSession(['auth.password_confirmed_at' => time()]);
    $snapshot = pageSnapshot(route('security.edit'), 'pages::settings.two-factor-setup-modal');

    // The setup runs as Livewire updates, which the rule lets through.
    callPageAction($snapshot, 'startTwoFactorSetup')->assertOk();

    // A secret whose setup is not confirmed is no second factor yet.
    $this->get(route('dashboard'))->assertRedirect(route('security.edit'));

    $secret = (string) Fortify::currentEncrypter()->decrypt((string) $member->fresh()?->two_factor_secret);
    callPageAction($snapshot, 'confirmTwoFactor', updates: ['code' => (string) app(Google2FA::class)->getCurrentOtp($secret)])->assertOk();

    $this->get(route('dashboard'))->assertOk();
    $this->get(route('security.edit'))->assertOk()->assertDontSee('data-test="second-factor-required"', escape: false);
});

it('is satisfied by a registered passkey, after which the dashboard opens (§6.6)', function () use ($memberUnderTheRule): void {
    usePasskeysModule(true);
    $memberUnderTheRule();
    $this->withSession(['auth.password_confirmed_at' => time()]);

    $this->get(route('dashboard'))->assertRedirect(route('security.edit'));

    /** @var array<string, mixed> $options */
    $options = $this->getJson(route('passkey.registration-options'))->assertOk()->json('options');
    $this->postJson(route('passkey.store'), ['name' => 'Laptop', 'credential' => new SoftwareAuthenticator()->attest($options, 'http://acme.localhost')])
        ->assertSuccessful();

    $this->get(route('dashboard'))->assertOk();
});

it('keeps sending a user without a second factor back to the security page from every other page (§6.6)', function (string $page): void {
    $acme = Organisation::factory()->create(['slug' => 'acme', 'require_second_factor' => true]);
    // An organisation admin, so every page below would open without the rule.
    asOrgAdmin($acme);
    $this->withSession(['auth.password_confirmed_at' => time()]);

    $this->get(match ($page) {
        'organisations.edit' => route('organisations.edit', $acme->id),
        'settings' => url('settings'),
        default => route($page),
    })->assertRedirect(route('security.edit'));

    $this->get(route('security.edit'))->assertOk();
})->with(['dashboard', 'profile.edit', 'appearance.edit', 'settings', 'users.index', 'users.invite', 'invitations.pending', 'organisations.index', 'organisations.edit', 'roles.index', 'activity.index']);

it('refuses any other method with 403 (§6.6)', function (string $method) use ($memberUnderTheRule): void {
    $memberUnderTheRule();

    $this->call($method, url('settings'))->assertForbidden();
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);

it('counts only two-factor authentication and says so while the passkeys module is off (§6.8)', function () use ($memberUnderTheRule): void {
    usePasskeysModule(false);
    $member = $memberUnderTheRule();
    // A passkey kept from before the module was switched off signs nobody in, so it is no second factor.
    $member->passkeys()->create(['name' => 'Laptop', 'credential_id' => 'credential-1', 'credential' => []]);
    $this->withSession(['auth.password_confirmed_at' => time()]);
    $totpOnly = __('Your organisation requires a second factor. Set up two-factor authentication to continue.');

    $this->get(route('dashboard'))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('status', $totpOnly);
    $this->get(route('security.edit'))
        ->assertOk()
        ->assertSee($totpOnly)
        ->assertDontSee(__('Your organisation requires a second factor. Set up two-factor authentication or a passkey to continue.'));
});

it('applies REQUIRE_SECOND_FACTOR_SUPER_ADMINS to super admins on every host (§6.6, CD-4)', function (string $host, bool $required): void {
    usePasskeysModule(true);
    config(['skv.second_factor.super_admins' => $required]);
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $superAdmin = asSuperAdmin();
    match ($host) {
        'tenant' => onTenant($acme),
        'apex' => onApex(),
        default => onAdminHost(),
    };

    if (! $required) {
        $this->get(route('organisations.index'))->assertOk();

        return;
    }

    $this->get(route('organisations.index'))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('status', __('Super admins need a second factor. Set up two-factor authentication or a passkey to continue.'));

    $superAdmin->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();

    $this->get(route('organisations.index'))->assertOk();
})->with(['tenant', 'apex', 'admin'])->with(['required' => true, 'not required' => false]);

it('lets an impersonation session through: the impersonator acts and cannot set up the target\'s factor (§6.6, CD-4)', function (): void {
    useImpersonationModule(true);
    $acme = Organisation::factory()->create(['slug' => 'acme', 'require_second_factor' => true]);
    $admin = User::factory()->for($acme)->create();
    withPermissionsTeam($acme->id, fn (): User => $admin->assignRole(Role::OrganisationAdmin->value));
    $member = User::factory()->for($acme)->create();
    onTenant($acme);

    startImpersonatingOverHttp($admin, $member);

    $this->get(route('dashboard'))->assertOk();
    $this->assertAuthenticatedAs($member);
});

it('recognises Livewire\'s update, upload and preview requests by their route (CD-4)', function (string $name) use ($memberUnderTheRule): void {
    $member = $memberUnderTheRule();
    $route = app(Router::class)->getRoutes()->getByName($name) ?? throw new LogicException("No route [{$name}].");
    $request = Request::create('/'.$route->uri(), $route->methods()[0]);
    $request->setRouteResolver(fn (): Route => $route);
    $request->setUserResolver(fn (): User => $member);
    $request->setLaravelSession(app('session.store'));

    $response = app(EnsureSecondFactor::class)->handle($request, fn (): Response => response('passed'));

    expect($response->getContent())->toBe('passed');
})->with(['default-livewire.update', 'livewire.upload-file', 'livewire.preview-file']);

it('does not take a page request\'s X-Livewire header as a Livewire request (CD-4)', function () use ($memberUnderTheRule): void {
    $memberUnderTheRule();

    $this->get(route('dashboard'), ['X-Livewire' => '1'])->assertRedirect(route('security.edit'));
});

it('lets the configured exempt routes through, and only those (CD-4)', function () use ($memberUnderTheRule): void {
    $memberUnderTheRule();
    $this->withSession(['auth.password_confirmed_at' => time()]);

    $this->get(route('profile.edit'))->assertRedirect(route('security.edit'));

    config(['skv.second_factor.exempt_routes' => ['security.edit', 'profile.*']]);

    $this->get(route('profile.edit'))->assertOk();
    $this->get(route('appearance.edit'))->assertRedirect(route('security.edit'));
});

it('exempts setting up a factor, the password confirmation, signing out, handoffs, ending an impersonation and setup by default (CD-4)', function (): void {
    expect(config('skv.second_factor.exempt_routes'))->toBe([
        'security.edit',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'two-factor.*',
        'passkey.*',
        'well-known.passkeys',
        'logout',
        'auth.handoff',
        'auth.handoff.store',
        'impersonation.stop',
        'setup.*',
    ])->and((string) file_get_contents(base_path('.env.example')))->toContain("\nREQUIRE_SECOND_FACTOR_SUPER_ADMINS=false\n");
});

it('runs on every authenticated route that is not exempt, and not in the global web group (§6.6, CD-4)', function (): void {
    $router = app(Router::class);
    /** @var list<string> $exempt */
    $exempt = config('skv.second_factor.exempt_routes');
    $unguarded = [];

    foreach ($router->getRoutes()->getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();
        $authenticated = array_filter($middleware, fn (mixed $name): bool => $name === 'auth' || (is_string($name) && str_starts_with($name, 'auth:')));
        $name = $route->getName();

        if ($authenticated === [] || in_array(EnsureSecondFactor::class, $middleware, true) || ($name !== null && Str::is($exempt, $name))) {
            continue;
        }

        $unguarded[] = $name ?? $route->uri();
    }

    expect($unguarded)->toBe([])
        ->and($router->getRoutes()->getByName('dashboard')?->gatherMiddleware())->toContain(EnsureSecondFactor::class)
        ->and($router->getMiddlewareGroups()['web'])->not->toContain(EnsureSecondFactor::class);
});

it('never affects a member of an organisation without the rule (§6.6)', function (string $page): void {
    Organisation::factory()->create(['slug' => 'acme', 'require_second_factor' => true]);
    $beta = Organisation::factory()->create(['slug' => 'beta']);
    asOrgAdmin($beta);

    $this->get($page === 'organisations.edit' ? route('organisations.edit', $beta->id) : route($page))->assertOk();
})->with(['dashboard', 'profile.edit', 'users.index', 'organisations.edit', 'activity.index']);
```

`tests/Feature/Auth/SecondFactorTest.php` (Task 2's file): add `use App\Authorization\SetSuperAdmin;` before `use App\Models\Organisation;`, and append:

```php

it('requires a second factor of a member only when their own organisation says so, on every host (§6.6, CD-4)', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme', 'require_second_factor' => true]);
    $beta = Organisation::factory()->create(['slug' => 'beta']);
    $acmeMember = User::factory()->for($acme)->create();
    $betaMember = User::factory()->for($beta)->create();

    foreach ([fn (): Organisation => onTenant($acme), fn (): Organisation => onTenant($beta), onApex(...), onAdminHost(...)] as $host) {
        $host();

        expect(SecondFactor::requiredFor($acmeMember))->toBeTrue()
            ->and(SecondFactor::requiredFor($betaMember))->toBeFalse();
    }
});

it('requires a second factor of super admins only when the super-admin switch is on, whatever their organisation (CD-4)', function (bool $configured): void {
    config(['skv.second_factor.super_admins' => $configured]);
    $acme = Organisation::factory()->create(['require_second_factor' => true]);
    $platformAdmin = User::factory()->superAdmin()->create();
    // SetSuperAdmin keeps the organisation_id of a promoted member; their organisation's rule no longer applies.
    $promoted = tap(User::factory()->for($acme)->create(), fn (User $user) => app(SetSuperAdmin::class)->handle($user, true));

    expect(SecondFactor::requiredFor($platformAdmin))->toBe($configured)
        ->and(SecondFactor::requiredFor($promoted))->toBe($configured);
})->with(['on' => true, 'off' => false]);
```

Notes on the tests:
- The TOTP test drives the real setup modal over HTTP (`startTwoFactorSetup`, then `confirmTwoFactor` with a code from `Google2FA`), as `ActionLimitsTest` does, so it also proves that the Livewire updates of an unsatisfied user pass. It starts the setup after the snapshot because the security page clears an unconfirmed secret when it mounts.
- The passkey test registers through the real `passkey.registration-options` / `passkey.store` routes with `SoftwareAuthenticator`, as `PasskeyTest`'s `registerPasskeyFor()` does; that file-level function is not available here (each test file declares its own), and M7d adds no global helper for it.
- The Livewire-route test calls the middleware directly with a request bound to Livewire's own routes, because those routes are outside the groups and an HTTP request would never reach the middleware; it is what makes the route-name check mutation-testable.
- `/settings` is the only non-GET-capable route in the groups (`Route::redirect` answers every method), so the 403 test uses it.
- No test title names `skv.*` (`tests/Arch/NameLeakTest.php` allows the kit name only in `config('skv.…')` forms).

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Auth/EnsureSecondFactorTest.php tests/Feature/Auth/SecondFactorTest.php`

Expected: FAIL (33 of 48), as follows:
- Every redirect expectation gets 200 ("Expected response status code [201, 301, 302, 303, 307, 308] but received 200"): the dashboard test, the password-confirmation test, the TOTP and passkey tests (at their first `assertRedirect`), ten rows of 'keeps sending a user…' (the `settings` row is redirected to `settings/profile` instead), the module-off test, the three 'required' rows of the super-admin test, the `X-Livewire` test and 'lets the configured exempt routes through…'.
- The 403 rows get 302 (the `settings` redirect).
- 'exempts … by default' fails on `null`; 'runs on every authenticated route…' lists every authenticated route (`logout`, `password.confirm`, `two-factor.enable`, …, `dashboard`).
- The three Livewire-route rows error with "Target class [App\Http\Middleware\EnsureSecondFactor] does not exist."
- The two `requiredFor()` tests error with "Call to undefined method App\Tenancy\Auth\SecondFactor::requiredFor()".
- Already passing (guards against an over-broad fix): the three 'not required' rows, the impersonation test, the five rows of 'never affects a member of an organisation without the rule' and Task 2's six `satisfiedBy()` rows.

- [ ] **Step 3: `SecondFactor` decides who must have one**

Replace `app/Tenancy/Auth/SecondFactor.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy\Auth;

use App\Models\Organisation;
use App\Models\User;
use App\Support\Skv;

/**
 * The second factor (§6.6, §6.8, CD-4). Who has one: a confirmed TOTP secret, or, only while the passkeys module
 * is on, at least one passkey (with the module off a passkey signs nobody in, so it no longer counts either). Who
 * must have one: a super admin when config('skv.second_factor.super_admins') says so, whatever their
 * organisation_id; anyone else when their own organisation's require_second_factor is on. Both hold on every host.
 * EnsureSecondFactor enforces it; the security page and UpdateOrganisation read it.
 */
final class SecondFactor
{
    public static function satisfiedBy(User $user): bool
    {
        if ($user->hasEnabledTwoFactorAuthentication()) {
            return true;
        }

        return Skv::passkeysEnabled() && $user->passkeys()->exists();
    }

    public static function requiredFor(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return config('skv.second_factor.super_admins') === true;
        }

        if ($user->organisation_id === null) {
            return false;
        }

        // The organisations table is central: no tenant scope, so this reads the same row on every host. An archived
        // organisation (soft-deleted) has no members who can sign in, and is read as no rule.
        return Organisation::query()->whereKey($user->organisation_id)->value('require_second_factor') === true;
    }

    /** The rule applies to $user and they have not met it yet. */
    public static function missingFor(User $user): bool
    {
        return self::requiredFor($user) && ! self::satisfiedBy($user);
    }

    /**
     * What the user must do, for the redirect's flash and the security page's callout. With the passkeys module
     * off only two-factor authentication is offered (§6.8).
     */
    public static function explanation(User $user): string
    {
        $passkeys = Skv::passkeysEnabled();

        if ($user->isSuperAdmin()) {
            return $passkeys
                ? __('Super admins need a second factor. Set up two-factor authentication or a passkey to continue.')
                : __('Super admins need a second factor. Set up two-factor authentication to continue.');
        }

        return $passkeys
            ? __('Your organisation requires a second factor. Set up two-factor authentication or a passkey to continue.')
            : __('Your organisation requires a second factor. Set up two-factor authentication to continue.');
    }
}
```

`value()` on the Eloquent builder reads the column through the model (`Builder::value()` returns `$result->{$column}`), so the boolean cast applies and `=== true` holds on SQLite (`1`) and PostgreSQL (`true`) alike. `Model::shouldBeStrict()` is on outside production, so the method queries instead of lazy-loading `$user->organisation`. The organisations table is central: no tenant scope and no `Tenant::bypass()` needed (Livewire pages call this too).

- [ ] **Step 4: The middleware**

Create `app/Http/Middleware/EnsureSecondFactor.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Tenancy\Auth\Impersonation;
use App\Tenancy\Auth\SecondFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the second factor (§6.6, CD-4) on the authenticated route groups (routes/web.php, settings.php and
 * admin.php), not on the global web group. A user the rule applies to who has no second factor yet is sent to the
 * security page with an explanation on a GET, and refused (403) on any other method. It lets through: guests and
 * users the rule does not apply to; an impersonation session (the impersonator acts, and cannot set up the
 * target's factor); Livewire's update, upload and preview routes (the factor is set up through Livewire updates on
 * the security page); and the routes named in config('skv.second_factor.exempt_routes'), which set up a factor,
 * confirm a password, sign out, finish a handoff, end an impersonation or run the setup wizard.
 *
 * Livewire requests are recognised by their route, never by the X-Livewire header, which any client can send with
 * an ordinary page request. This middleware is not Livewire persistent middleware, so updates never run it.
 */
final class EnsureSecondFactor
{
    /** Livewire's own routes; the update route's name carries a prefix ("default-livewire.update"). */
    private const array LIVEWIRE_ROUTES = ['*livewire.update', 'livewire.upload-file', 'livewire.preview-file'];

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $this->exempt($request) || ! SecondFactor::missingFor($user)) {
            return $next($request);
        }

        abort_unless(in_array($request->getMethod(), ['GET', 'HEAD'], true), 403);

        return redirect()->route('security.edit')->with('status', SecondFactor::explanation($user));
    }

    private function exempt(Request $request): bool
    {
        if (Impersonation::isActive() || $request->routeIs(...self::LIVEWIRE_ROUTES)) {
            return true;
        }

        /** @var list<string> $routes */
        $routes = config('skv.second_factor.exempt_routes', []);

        return $routes !== [] && $request->routeIs(...$routes);
    }
}
```

`HEAD` is a GET without a body, so it is redirected like a GET; every other method (`POST`, `PUT`, `PATCH`, `DELETE`, `OPTIONS`, `QUERY` on the `settings` redirect) gets the 403. The redirect stays on the current host (`route()` with the request's root). `status` is the flash key the base layout turns into a toast (§6.5).

- [ ] **Step 5: Configuration**

`config/skv.php`: between the `'modules'` block and the `/health-check` comment, add:

```php
    /*
    | The second factor (§6.6, CD-4). An organisation's require_second_factor applies to its own members, on every
    | host; super_admins applies the rule to every super admin, on every host, whatever their organisation. With
    | the passkeys module off only two-factor authentication counts (§6.8). EnsureSecondFactor lets the
    | exempt_routes through (Request::routeIs() patterns): setting up a factor, confirming the password, signing
    | out, finishing a handoff, ending an impersonation and the setup wizard.
    */
    'second_factor' => [
        'super_admins' => (bool) env('REQUIRE_SECOND_FACTOR_SUPER_ADMINS', false),
        'exempt_routes' => [
            'security.edit',
            'password.confirm',
            'password.confirm.store',
            'password.confirmation',
            'two-factor.*',
            'passkey.*',
            'well-known.passkeys',
            'logout',
            'auth.handoff',
            'auth.handoff.store',
            'impersonation.stop',
            'setup.*',
        ],
    ],
```

`.env.example`: before `# Optional modules.`, add:

```dotenv
# Require a second factor (two-factor authentication or a passkey) of every super admin, on every host.
# Organisations set their own rule on their edit page.
REQUIRE_SECOND_FACTOR_SUPER_ADMINS=false

```

`phpunit.xml`: after `<env name="MODULE_PASSKEYS" value="true"/>`, add:

```xml
        <!-- CD-4: the owner's .env may require a second factor of super admins; tests turn it on per test. -->
        <env name="REQUIRE_SECOND_FACTOR_SUPER_ADMINS" value="false" force="true"/>
```

`force="true"` like the other owner-overridable keys (`BRANDING_*`): without it, an owner who sets the key in `.env` would see every super-admin test redirected. CI does not need the key to vary; the tests set `config(['skv.second_factor.super_admins' => …])` themselves.

- [ ] **Step 6: The route groups**

`routes/web.php`: add `use App\Http\Middleware\EnsureSecondFactor;` after `use App\Http\Controllers\LocaleController;`, and replace the dashboard group's first line:

```php
// §6.6: every authenticated group also runs EnsureSecondFactor (CD-4); the global web group does not.
Route::middleware(['auth', EnsureSecondFactor::class])->group(function (): void {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
});
```

`routes/settings.php`: add `use App\Http\Middleware\EnsureSecondFactor;` after `use App\Http\Controllers\Settings\ConfirmEmailChangeController;`, and replace `Route::middleware(['auth'])->group(function (): void {` with:

```php
// §6.6, CD-4: security.edit is exempt, so a user who still needs a second factor can set one up there.
Route::middleware(['auth', EnsureSecondFactor::class])->group(function (): void {
```

`routes/admin.php`: add `use App\Http\Middleware\EnsureSecondFactor;` after `use App\Authorization\Permission;`, and replace `Route::middleware(['auth'])->group(function (): void {` with:

```php
// §6.6, CD-4: EnsureSecondFactor runs before every can: check.
Route::middleware(['auth', EnsureSecondFactor::class])->group(function (): void {
```

`bootstrap/app.php` is not touched: the middleware is not in the `web` group and not Livewire persistent middleware.

- [ ] **Step 7: The security page's callout**

`resources/views/pages/settings/security/security.php`: add `use App\Tenancy\Auth\SecondFactor;` after `use App\Support\RateLimits\UserActionLimit;`, and `use Livewire\Attributes\Computed;` after `use Laravel\Passkeys\Actions\DeletePasskey;`. After `disable()` (the last public method, before the private `ensurePasskeysEnabled()`), add:

```php
    /**
     * Why this page opened instead of the one asked for (§6.6, CD-4): the explanation while the second-factor rule
     * applies to the user and is unmet, otherwise null. Read on every render, so it goes away as soon as a factor is
     * set up on this page.
     */
    #[Computed]
    public function secondFactorNotice(): ?string
    {
        $user = $this->currentUser();

        return SecondFactor::missingFor($user) ? SecondFactor::explanation($user) : null;
    }
```

`resources/views/pages/settings/security/security.blade.php` (Task 1's version): directly after the opening `<x-pages::settings.layout :heading="__('Update password')" …>` tag, before the password `<form>`, add:

```blade
        {{-- §6.6, CD-4: why EnsureSecondFactor sent the user here; gone once a factor is set up. --}}
        @if ($this->secondFactorNotice !== null)
            <flux:callout variant="warning" icon="shield-exclamation" :heading="$this->secondFactorNotice" class="mt-6" data-test="second-factor-required" />
        @endif

```

`flux:callout` and the `shield-exclamation` icon are in the free stubs. Enabling 2FA in the modal dispatches `two-factor-enabled`, which re-renders this page, so the callout disappears without a reload; a registered passkey reloads the passkeys list, with the same effect.

- [ ] **Step 8: Lang keys**

Add to both files at their sorted places (PHP `sort()`): the two "Super admins need…" keys after `"Super admins manage every organisation. Changing this asks for your password."`, and the two "Your organisation requires…" keys after `"Your email address is about to change"` (neighbours on `main`).

`lang/nl.json`:

```json
    "Super admins need a second factor. Set up two-factor authentication or a passkey to continue.": "Superbeheerders hebben een tweede factor nodig. Stel tweestapsverificatie of een passkey in om verder te gaan.",
    "Super admins need a second factor. Set up two-factor authentication to continue.": "Superbeheerders hebben een tweede factor nodig. Stel tweestapsverificatie in om verder te gaan.",
```

```json
    "Your organisation requires a second factor. Set up two-factor authentication or a passkey to continue.": "Je organisatie vereist een tweede factor. Stel tweestapsverificatie of een passkey in om verder te gaan.",
    "Your organisation requires a second factor. Set up two-factor authentication to continue.": "Je organisatie vereist een tweede factor. Stel tweestapsverificatie in om verder te gaan.",
```

`lang/en.json`:

```json
    "Super admins need a second factor. Set up two-factor authentication or a passkey to continue.": "Super admins need a second factor. Set up two-factor authentication or a passkey to continue.",
    "Super admins need a second factor. Set up two-factor authentication to continue.": "Super admins need a second factor. Set up two-factor authentication to continue.",
```

```json
    "Your organisation requires a second factor. Set up two-factor authentication or a passkey to continue.": "Your organisation requires a second factor. Set up two-factor authentication or a passkey to continue.",
    "Your organisation requires a second factor. Set up two-factor authentication to continue.": "Your organisation requires a second factor. Set up two-factor authentication to continue.",
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `php artisan config:clear && php artisan test --compact tests/Feature/Auth tests/Feature/Organisations tests/Feature/Settings tests/Feature/Tenancy tests/Feature/Impersonation tests/Feature/LangFilesTest.php tests/Feature/LangCoverageTest.php tests/Feature/Support/ModulesConfigTest.php tests/Arch`

Expected: PASS. Then run the whole suite once (`vendor/bin/pest --parallel --exclude-group=browser`): no existing test is redirected (see Background).

- [ ] **Step 10: Verify by mutation**

Revert each change locally, run `tests/Feature/Auth/EnsureSecondFactorTest.php` (and `SecondFactorTest.php` where named), expect the named tests to fail, then restore the change. Never `git stash`: copy the file aside with `cp`; run `php artisan view:clear` after a Blade mutation and `php artisan config:clear` after a config one.
1. In `EnsureSecondFactor::exempt()`, drop `Impersonation::isActive() ||`. Expect 'lets an impersonation session through…' to fail on the redirect.
2. Drop `|| $request->routeIs(...self::LIVEWIRE_ROUTES)`. Expect the three rows of 'recognises Livewire's update, upload and preview requests…' to fail (403 for the POST routes, a redirect for the preview).
3. Replace the route check with `\Livewire\Livewire::isLivewireRequest()` (CD-4's wording). Expect 'does not take a page request's X-Livewire header…' to fail with 200, and the Livewire-route rows too (the direct requests carry no header).
4. Replace `return $routes !== [] && $request->routeIs(...$routes);` with `return false;`. Expect the password-confirmation test, the TOTP test, the module-off test, every row of 'keeps sending…' and 'lets the configured exempt routes through…' to fail: `security.edit` now redirects to itself.
5. Remove the `abort_unless(...)` line. Expect the four rows of 'refuses any other method with 403' to fail with 302.
6. In `SecondFactor::missingFor()`, drop `&& ! self::satisfiedBy($user)`. Expect the TOTP test, the passkey test and the three 'required' super-admin rows to fail at their final `assertOk()`.
7. In `requiredFor()`, return `false` for super admins. Expect the three 'required' rows and `SecondFactorTest`'s 'on' row to fail.
8. In `requiredFor()`, decide super admins by `$user->organisation_id === null` instead of `isSuperAdmin()` (so a promoted super admin falls through to the organisation's rule). Expect `SecondFactorTest`'s 'off' row to fail on the promoted super admin.
9. In `requiredFor()`, replace `->value('require_second_factor') === true` with `->exists()`. Expect the five rows of 'never affects a member of an organisation without the rule' and 'requires a second factor of a member only when their own organisation says so…' to fail.
10. In `explanation()`, replace `$passkeys = Skv::passkeysEnabled();` with `$passkeys = true;`. Expect 'counts only two-factor authentication and says so while the passkeys module is off' to fail.
11. In `routes/admin.php`, drop `EnsureSecondFactor::class` from the group. Expect seven rows of 'keeps sending…', the three 'required' super-admin rows and 'runs on every authenticated route that is not exempt…' to fail.
12. In `security.blade.php`, replace the callout's condition with `false`. Expect 'confirms the password on the way without a loop…' to fail on `data-test="second-factor-required"`.
13. In `EnsureSecondFactor::handle()`, drop `->with('status', …)`. Expect the dashboard test, the module-off test and the three 'required' rows to fail on `assertSessionHas('status', …)`.

- [ ] **Step 11: CHANGELOG**

Under `## [Unreleased]`, `### Added`, append:

```markdown
- Members of an organisation that requires a second factor are sent to Settings → Security, with an explanation, until they set up two-factor authentication or a passkey (with passkeys off, two-factor authentication only). `REQUIRE_SECOND_FACTOR_SUPER_ADMINS=true` applies the same rule to super admins on every host. Impersonation sessions, Livewire requests and the routes that set up a factor, confirm the password, sign out or hand off are let through.
```

- [ ] **Step 12: The gate, then commit**

```bash
composer fix
vendor/bin/phpstan analyse tests/Feature/Auth/EnsureSecondFactorTest.php tests/Feature/Auth/SecondFactorTest.php
composer check
composer test:pgsql
php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser
npm run build && composer test:browser
npm run build:setup && git diff --exit-code resources/setup/setup.css
php artisan config:clear
```

The `match` arms in the new test file end with `default =>` (Larastan `match.unhandled`). Task 3 changes no setup view, so expect no stylesheet diff.

```bash
git add app/Http/Middleware/EnsureSecondFactor.php app/Tenancy/Auth/SecondFactor.php config/skv.php .env.example phpunit.xml \
    routes/web.php routes/settings.php routes/admin.php \
    resources/views/pages/settings/security/security.php resources/views/pages/settings/security/security.blade.php \
    lang/nl.json lang/en.json CHANGELOG.md \
    tests/Feature/Auth/EnsureSecondFactorTest.php tests/Feature/Auth/SecondFactorTest.php
git commit -m "feat: require the second factor an organisation or the super-admin switch asks for

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Audit CSV export (§6.6 CSV export, §4.8; CD-5, CD-9)

The activity viewer gets an "Export CSV" button. It downloads the entries that match the page's current filters, with the same scope as the page: a tenant host exports only its own organisation's entries, and a super admin on a central host exports every entry, including archived organisations' entries. The export is audited and limited.

The viewer's filter rule and its display rules (names, labels, RL-7's hidden IP address and user agent) live today as private methods of the page. The export must apply them exactly. So this task first moves them, unchanged, into two shared classes:
- `App\Support\Audit\ActivityFilters` (CD-5) turns the six filter strings into a query and ignores invalid values exactly as the page does today.
- `App\Support\Audit\ActivityRows` turns entries into display rows: causer, subject, organisation, changes, details, and IP address and user agent hidden where RL-7 hides them.

The page then calls both classes, and every existing `ActivityViewerTest` stays green.

How the export works:
- **The route and controller.** It is a plain GET route, `activity.export`, served by `App\Http\Controllers\ActivityExportController`. It is not a Livewire action, because Livewire buffers a download into its JSON update response, so the whole file would sit in memory.
- **Streaming.** The controller returns `response()->streamDownload()` with `text/csv; charset=UTF-8`. Inside `HostScope::read()` it reads the filtered entries in id order with `lazyById(500)`, 500 per query. Each chunk of 500 is turned into rows with one name lookup, one organisation lookup and one member lookup, then written with `fputcsv` to `php://output`.
- **Memory.** Memory stays flat however large the log is. At most one chunk of 500 models and its lookups is in memory at a time, and the CSV goes straight to the output buffer, never into a string. The test proves the chunking: 501 entries take two `limit 500` reads.
- **Large exports.** A very large export is still bound by PHP's `max_execution_time` and holds one database connection while it streams. Retention (`ACTIVITYLOG_RETENTION_DAYS`, 365 by default) bounds the log's size.
- **Columns:** date (ISO 8601 in the app's time zone), event key, event label, done by, subject, organisation (central host only), IP address, user agent, and properties (JSON). The header row and the labels are in the viewer's language, because `SetLocale` has already run when the body streams.
  - IP address and user agent are empty wherever the viewer hides them (RL-7).
  - The change summary is not a column (CD-5 does not list one). RL-7 also hides a staff user's own field changes from tenant viewers, so leaving the column out keeps the export from showing what the page hides.
- **BOM and formulas.** The file starts with a UTF-8 BOM, so Excel reads it. Any cell that starts with `=`, `+`, `-`, `@`, a tab or a carriage return gets a leading `'` (CSV formula injection).
- **Audit entry.** Before the first byte, the controller records `ActivityEvent::ActivityExported` (`audit.exported`) with `properties.filters` = the filters that take effect. Ignored values are left out.
  - The new group `audit` is a host-mode group, like `authentication`, `security` and `impersonation`. A tenant host's export is tagged with that organisation, and a central export is a platform entry.
  - Because the entry is recorded first, an unfiltered export lists its own entry as its last row.
- **Limit.** The named limiter `activity-export` allows 5 requests per minute per user. It counts every request, also refused ones that reach it.
- **Host rules.** These are those of `activity.index`:
  - `can:` `Permission::ActivityLogView` on the route.
  - On a central host, `VerifyTenantMembership` lets only super admins through.
  - `HostScope::read()` scopes or bypasses as the page does.

**Files:**
- Create:
  - `app/Support/Audit/ActivityFilters.php`: the filter rule (CD-5)
  - `app/Support/Audit/ActivityRows.php`: the display rows, RL-7 included, moved out of the page
  - `app/Http/Controllers/ActivityExportController.php`
  - `tests/Feature/Audit/ActivityExportTest.php`
- Modify:
  - `resources/views/pages/activity/index/index.php`: the whole class, now calling the two shared classes (Step 3), plus the `exportUrl()` computed property (Step 6)
  - `resources/views/pages/activity/index/index.blade.php`: the "Export CSV" button
  - `app/Enums/ActivityEvent.php`: `ActivityExported`, its label, the `audit` group, and `tagsByHost()`. Written against Task 1's version, which adds `OtherSessionsEnded`; every edit below anchors on lines Task 1 does not touch.
  - `app/Providers/FortifyServiceProvider.php`: the `activity-export` limiter in `configureRateLimiting()`, where every named limiter lives, `handoff` included
  - `routes/admin.php`: the `activity.export` route, written against Task 3's version (the `auth` group carries `EnsureSecondFactor`; this edit anchors on the `activity.index` line)
  - `lang/nl.json`, `lang/en.json`
  - `CHANGELOG.md`
  - `tests/Feature/Audit/ActivityLogTest.php`: the group list gains `audit`
  - `tests/Feature/Tenancy/RouteHostMatrixTest.php`: `activity.export` in `matrixRoutes()` and in the member's `forbidden` list
- Test:
  - `tests/Feature/Audit/ActivityExportTest.php`
  - `tests/Feature/Audit/ActivityViewerTest.php` (unchanged, must stay green: it pins the moved rules)
  - `tests/Feature/Audit/ActivityLogTest.php`, `tests/Feature/Tenancy/RouteHostMatrixTest.php`

**Interfaces:**
- Consumes:
  - `App\Tenancy\HostScope::read(Closure $callback): mixed` and `HostScope::organisationId(): ?int`
  - `App\Actions\Audit\RecordActivity::handle(ActivityEvent $event, ?Model $subject = null, array $properties = [], array $changes = [], ?User $causer = null, ?Organisation $organisation = null, bool $defaultCauser = true): Activity`
  - `App\Tenancy\UserNames::of(array $ids): array<int, string>`
  - `App\Support\RateLimits\ThrottleKey::of(string $limiter, string $key): string` (tests only)
  - Task 1's `app/Enums/ActivityEvent.php` with `OtherSessionsEnded` (`security.other_sessions_ended`)
  - Task 3's `routes/admin.php`, where the `auth` group also carries `EnsureSecondFactor`. The export route sits inside that group, so the second-factor rule applies to it like every authenticated page.
- Produces:
  - `App\Support\Audit\ActivityFilters` (final readonly):
    - `public const array NAMES = ['from', 'until', 'event', 'subject', 'causer', 'organisation']`
    - `__construct(string $from = '', string $until = '', string $event = '', string $subject = '', string $causer = '', string $organisation = '')`
    - `static fromQuery(array $query): self`. A non-string value counts as empty.
    - `static isCentral(): bool`
    - `static baseQuery(): Builder<Activity>`. Call it inside `HostScope::read()`.
    - `static subjectTypes(): list<string>` and `static causerIds(): list<int>`. Each reads through `HostScope::read()` itself.
    - `query(): Builder<Activity>`: unordered. Call it inside `HostScope::read()`.
    - `applied(): array<string, string>`: the valid filters, in `NAMES` order.
    - `toQuery(): array<string, string>`: the non-empty filters.
  - `App\Support\Audit\ActivityRows` (final):
    - `static of(list<Activity> $activities): list<ActivityRow>`, in input order
    - `static subjectTypeLabel(string $type): string`
    - The `ActivityRow` shape is `array{id: int, when: string, event: string, event_key: string, causer: string, subject: string, organisation: string|null, changes: list<array{attribute: string, old: string, new: string}>, details: string|null, ip_address: string|null, user_agent: string|null, properties: string}`.
  - `App\Http\Controllers\ActivityExportController::__invoke(Request $request, RecordActivity $recordActivity): StreamedResponse`
  - The named GET route `activity.export` (`/activity/export`, any host, `throttle:activity-export`, `can:` `Permission::ActivityLogView`)
  - The named limiter `activity-export`: `Limit::perMinute(5)->by('activity-export:'.$userId)`. Its cache key is `ThrottleKey::of('activity-export', 'activity-export:'.$userId)`.
  - `ActivityEvent::ActivityExported = 'audit.exported'`, with the label "Activity log exported", the group `audit`, and `tagsByHost() === true`
  - The page's `#[Computed] exportUrl(): string` (a relative URL) and the button `data-test="activity-export"`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Audit/ActivityExportTest.php` (new):

```php

<?php

declare(strict_types=1);

/** covers §4.8, §6.6 */

use App\Actions\Audit\RecordActivity;
use App\Authorization\SetSuperAdmin;
use App\Enums\ActivityEvent;
use App\Enums\Locale;
use App\Models\Activity;
use App\Models\Organisation;
use App\Models\User;
use App\Support\RateLimits\ThrottleKey;
use App\Tenancy\Facades\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role as RoleModel;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Records an entry whose properties.reason holds a marker; the export's properties column carries it, so tests
 * find rows by their marker. Subject-mode events are tagged with $organisation.
 */
$record = fn (ActivityEvent $event, ?Organisation $organisation, string $marker, ?User $causer = null, ?Model $subject = null): Activity => app(RecordActivity::class)->handle(
    $event,
    $subject,
    ['reason' => $marker],
    causer: $causer,
    organisation: $organisation,
    defaultCauser: false,
);

/**
 * The streamed body without its byte order mark, as rows of cells.
 *
 * @return list<list<string>>
 */
$csv = function (TestResponse $response): array {
    $content = $response->streamedContent();
    expect($content)->toStartWith("\u{FEFF}");

    $file = new SplTempFileObject;
    $file->fwrite(substr($content, 3));
    $file->rewind();
    $file->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY);
    $file->setCsvControl(',', '"', '');
    $rows = [];

    foreach ($file as $cells) {
        if (is_array($cells)) {
            $rows[] = array_map(fn (mixed $cell): string => (string) $cell, array_values($cells));
        }
    }

    return $rows;
};

/** The data rows (header dropped) whose properties column holds one of the markers, as marker => row. */
$byMarker = function (array $rows): array {
    $found = [];

    foreach (array_slice($rows, 1) as $row) {
        $properties = json_decode((string) end($row), true);
        $marker = is_array($properties) ? ($properties['reason'] ?? null) : null;

        if (is_string($marker)) {
            $found[$marker] = $row;
        }
    }

    return $found;
};

it('streams a CSV download with a byte order mark and a header row (§6.6)', function () use ($record, $csv): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create(['name' => 'Mia Member']);
    $entry = $record(ActivityEvent::UserUpdated, $organisation, 'the-entry', $member, $member);
    $entry->forceFill(['ip_address' => '203.0.113.5', 'user_agent' => 'Agent/1.0'])->save();
    asOrgAdmin($organisation);

    $response = $this->get(route('activity.export'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertDownload();

    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class)
        ->and((string) $response->headers->get('Content-Disposition'))->toMatch('/filename=activity-log-\d{4}-\d{2}-\d{2}-\d{6}\.csv/');

    $rows = $csv($response);

    expect($rows[0])->toBe(['Date', 'Event key', 'Action', 'Done by', 'Subject', 'IP address', 'User agent', 'Properties'])
        ->and($rows[1])->toBe([
            $entry->created_at?->toIso8601String(),
            'user.updated',
            'User updated',
            'Mia Member',
            'User #'.$member->id.' · Mia Member',
            '203.0.113.5',
            'Agent/1.0',
            '{"reason":"the-entry"}',
        ]);
});

it('writes the header and labels in the viewer\'s language', function () use ($record, $csv): void {
    $organisation = Organisation::factory()->create();
    $record(ActivityEvent::OrganisationUpdated, $organisation, 'dutch-entry');
    asOrgAdmin($organisation)->forceFill(['locale' => Locale::Dutch])->save();

    $rows = $csv($this->get(route('activity.export'))->assertOk());

    expect($rows[0])->toBe(['Datum', 'Gebeurtenissleutel', 'Actie', 'Uitgevoerd door', 'Onderwerp', 'IP-adres', 'Browser', 'Eigenschappen'])
        ->and($rows[1][2])->toBe(__('Organisation updated', [], 'nl'));
});

it('streams the entries in id order, whatever their dates', function () use ($record, $csv, $byMarker): void {
    $organisation = Organisation::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00:00'));
    $record(ActivityEvent::OrganisationUpdated, $organisation, 'first-id');
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00'));
    $record(ActivityEvent::OrganisationUpdated, $organisation, 'second-id');
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00'));
    $record(ActivityEvent::OrganisationUpdated, $organisation, 'third-id');
    $this->travelBack();
    asOrgAdmin($organisation);

    expect(array_keys($byMarker($csv($this->get(route('activity.export'))->assertOk()))))
        ->toBe(['first-id', 'second-id', 'third-id']);
});

it('reads the log 500 entries per query while it streams, so a large log never sits in memory', function () use ($csv): void {
    $organisation = Organisation::factory()->create();
    Activity::factory()->count(501)->for($organisation)->create();
    asOrgAdmin($organisation);
    $response = $this->get(route('activity.export'))->assertOk();
    $chunkQueries = 0;
    DB::listen(function (QueryExecuted $query) use (&$chunkQueries): void {
        if (str_contains($query->sql, 'activity_log') && str_contains($query->sql, 'limit 500')) {
            $chunkQueries++;
        }
    });

    // 501 entries and the export's own audit entry, recorded before the first byte: two reads of at most 500.
    expect($csv($response))->toHaveCount(1 + 502)
        ->and($chunkQueries)->toBe(2);
});

it('honours each of the viewer\'s filters', function (array $query, array $expected) use ($record, $csv, $byMarker): void {
    $first = Organisation::factory()->create(['name' => 'First Org']);
    $second = Organisation::factory()->create(['name' => 'Second Org']);
    $member = User::factory()->for($first)->create();
    $actor = User::factory()->for($second)->create(['name' => 'Ada Actor']);
    $role = RoleModel::query()->create(['name' => 'Support', 'guard_name' => 'web', 'team_id' => $first->id]);

    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00'));
    $record(ActivityEvent::UserUpdated, $first, 'october-first', $member, $member);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00'));
    $record(ActivityEvent::RoleCreated, $first, 'october-fifth', null, $role);
    $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00:00'));
    $record(ActivityEvent::UserUpdated, $second, 'october-ninth', $actor, $actor);
    $this->travelBack();
    asSuperAdmin();
    onAdminHost();

    $query = array_map(fn (string $value): string => match ($value) {
        'actor' => (string) $actor->id,
        'second' => (string) $second->id,
        default => $value,
    }, $query);

    $markers = array_keys($byMarker($csv($this->get(route('activity.export', $query))->assertOk())));
    sort($markers);

    expect($markers)->toBe($expected);
})->with([
    'from and until' => [['from' => '2026-10-02', 'until' => '2026-10-05'], ['october-fifth']],
    'until, inclusive of the whole day' => [['until' => '2026-10-01'], ['october-first']],
    'action' => [['event' => 'role.created'], ['october-fifth']],
    'subject type' => [['subject' => User::class], ['october-first', 'october-ninth']],
    'done by' => [['causer' => 'actor'], ['october-ninth']],
    'organisation' => [['organisation' => 'second'], ['october-ninth']],
    'invalid values are ignored, as on the page' => [
        ['from' => '2026-13-45', 'event' => 'nope', 'subject' => 'Nope\\Model', 'causer' => 'abc', 'organisation' => 'x'],
        ['october-fifth', 'october-first', 'october-ninth'],
    ],
]);

it('answers 200 with every entry for a hostile query string', function (string $host) use ($record, $csv, $byMarker): void {
    $organisation = Organisation::factory()->create();
    $record(ActivityEvent::OrganisationUpdated, $organisation, 'some-entry');

    if ($host === 'tenant') {
        asOrgAdmin($organisation);
    } else {
        asSuperAdmin();
        onAdminHost();
    }

    $response = $this->get(route('activity.export').'?from[]=x&until=2026-13-45&event[]=y&subject=Nope%5CModel&causer=abc&organisation[]=1')
        ->assertOk();

    expect($byMarker($csv($response)))->toHaveKey('some-entry');
})->with(['tenant', 'admin']);

it('exports only the organisation\'s own entries on a tenant host, whatever the query says (AUD-3, RK-I4, RK-M4)', function () use ($record, $csv, $byMarker): void {
    $own = Organisation::factory()->create();
    $other = Organisation::factory()->create(['name' => 'Other Org']);
    $foreigner = User::factory()->for($other)->create(['name' => 'Bruno Foreign']);
    $record(ActivityEvent::OrganisationUpdated, $own, 'own-entry');
    $record(ActivityEvent::OrganisationUpdated, $other, 'foreign-entry', $foreigner);
    $record(ActivityEvent::OrganisationCreated, null, 'platform-entry');
    asOrgAdmin($own);

    foreach ([[], ['organisation' => $other->id], ['causer' => $foreigner->id]] as $query) {
        $response = $this->get(route('activity.export', $query))->assertOk();
        $content = $response->streamedContent();
        $markers = array_keys($byMarker($csv($response)));

        expect($markers)->toBe(['own-entry'])
            ->and($content)->not->toContain('Bruno Foreign')
            ->not->toContain('Other Org');
    }
});

it('exports every entry with its organisation to a super admin on a central host, archived organisations\' ones included (AUD-11, RL-7)', function () use ($record, $csv, $byMarker): void {
    $active = Organisation::factory()->create(['name' => 'Active Org']);
    $archived = Organisation::factory()->create(['name' => 'Archived Org']);
    $record(ActivityEvent::OrganisationUpdated, $active, 'active-entry');
    $record(ActivityEvent::OrganisationUpdated, $archived, 'archived-entry');
    $record(ActivityEvent::OrganisationCreated, null, 'platform-entry');
    $archived->delete(); // the cascade soft-deletes its activity rows (AUD-12)
    asSuperAdmin();
    onAdminHost();

    $rows = $csv($this->get(route('activity.export'))->assertOk());
    $found = $byMarker($rows);

    expect($rows[0])->toBe(['Date', 'Event key', 'Action', 'Done by', 'Subject', 'Organisation', 'IP address', 'User agent', 'Properties'])
        ->and(array_keys($found))->toEqualCanonicalizing(['active-entry', 'archived-entry', 'platform-entry'])
        ->and($found['active-entry'][5])->toBe('Active Org')
        ->and($found['archived-entry'][5])->toBe('Archived Org')
        ->and($found['platform-entry'][5])->toBe('Platform');
});

it('leaves platform staff\'s IP address and user agent empty for tenant viewers, not for central ones (RL-7)', function () use ($csv): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $promoted = User::factory()->for($organisation)->create();
    app(SetSuperAdmin::class)->handle($promoted, true);
    $superAdmin = User::factory()->superAdmin()->create();
    $located = function (Activity $activity, string $marker, string $ip): void {
        $activity->forceFill([
            'ip_address' => $ip,
            'user_agent' => 'Agent/'.$ip,
            'properties' => [...$activity->properties?->all() ?? [], 'reason' => $marker],
        ])->save();
    };
    $located(Tenant::run($organisation, fn (): Activity => app(RecordActivity::class)->handle(
        ActivityEvent::Login,
        $superAdmin,
        causer: $superAdmin,
        defaultCauser: false,
    )), 'staff-sign-in', '198.51.100.11');
    $located(app(RecordActivity::class)->handle(
        ActivityEvent::UserUpdated,
        $member,
        causer: $superAdmin,
        organisation: $organisation,
        defaultCauser: false,
    ), 'staff-edit', '198.51.100.22');
    $located(app(RecordActivity::class)->handle(
        ActivityEvent::UserUpdated,
        $promoted,
        causer: $promoted,
        organisation: $organisation,
        defaultCauser: false,
    ), 'promoted-self', '198.51.100.33');
    $located(app(RecordActivity::class)->handle(
        ActivityEvent::UserUpdated,
        $member,
        causer: $member,
        organisation: $organisation,
        defaultCauser: false,
    ), 'member-self', '203.0.113.44');
    asOrgAdmin($organisation);

    $connection = [];

    foreach (array_slice($csv($this->get(route('activity.export'))->assertOk()), 1) as $row) {
        $properties = json_decode($row[7], true);

        if (is_array($properties) && is_string($properties['reason'] ?? null)) {
            $connection[$properties['reason']] = [$row[5], $row[6]];
        }
    }

    expect($connection)->toBe([
        'staff-sign-in' => ['', ''],
        'staff-edit' => ['', ''],
        'promoted-self' => ['', ''],
        'member-self' => ['203.0.113.44', 'Agent/203.0.113.44'],
    ]);

    asSuperAdmin();
    onAdminHost();

    $central = $csv($this->get(route('activity.export'))->assertOk());
    $centralIps = array_column(array_slice($central, 1), 6);

    expect($centralIps)->toContain('198.51.100.11', '198.51.100.22', '198.51.100.33', '203.0.113.44');
});

it('prefixes a cell that starts like a formula with an apostrophe (CSV injection)', function (string $name) use ($record, $csv, $byMarker): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create(['name' => $name]);
    $record(ActivityEvent::UserUpdated, $organisation, 'formula-entry', $member, $member);
    asOrgAdmin($organisation);

    $row = $byMarker($csv($this->get(route('activity.export'))->assertOk()))['formula-entry'];

    expect($row[3])->toBe("'".$name)
        ->and($row[4])->toBe('User #'.$member->id.' · '.$name);
})->with([
    'equals' => ['=HYPERLINK("https://evil.example","Click")'],
    'plus' => ['+1+1'],
    'minus' => ['-2+3'],
    'at' => ['@SUM(A1:A2)'],
    'tab' => ["\t=1+1"],
    'carriage return' => ["\r=1+1"],
]);

it('records the export with the filters it applied, before the first byte', function () use ($record): void {
    $organisation = Organisation::factory()->create();
    $record(ActivityEvent::OrganisationUpdated, $organisation, 'some-entry');
    $admin = asOrgAdmin($organisation);

    // The body is never read: the entry exists as soon as the response does.
    $this->get(route('activity.export', ['event' => 'organisation.updated', 'from' => '2026-10-01', 'until' => 'not-a-date', 'organisation' => '99']))
        ->assertOk();

    $entry = recordedActivity(ActivityEvent::ActivityExported)->sole();

    expect($entry->log_name)->toBe('audit')
        ->and($entry->causer_id)->toBe($admin->id)
        ->and($entry->organisation_id)->toBe($organisation->id)
        ->and($entry->subject_type)->toBeNull()
        ->and($entry->getProperty('filters'))->toBe(['from' => '2026-10-01', 'event' => 'organisation.updated']);
});

it('records a central export without an organisation', function (): void {
    $superAdmin = asSuperAdmin();
    onAdminHost();

    $this->get(route('activity.export'))->assertOk();

    $entry = recordedActivity(ActivityEvent::ActivityExported)->sole();

    expect($entry->causer_id)->toBe($superAdmin->id)
        ->and($entry->organisation_id)->toBeNull()
        ->and($entry->getProperty('filters'))->toBe([]);
});

it('allows five exports a minute per user and refuses the sixth', function (): void {
    $organisation = Organisation::factory()->create();
    $admin = asOrgAdmin($organisation);

    foreach (range(1, 5) as $attempt) {
        $this->get(route('activity.export'))->assertOk();
    }

    $this->get(route('activity.export'))->assertTooManyRequests();

    expect(RateLimiter::attempts(ThrottleKey::of('activity-export', 'activity-export:'.$admin->id)))->toBe(5)
        ->and(recordedActivity(ActivityEvent::ActivityExported))->toHaveCount(5);

    // Another administrator of the same organisation has a bucket of their own.
    asOrgAdmin($organisation);

    $this->get(route('activity.export'))->assertOk();
});

it('refuses a user without the activity_log.view permission', function (): void {
    $organisation = Organisation::factory()->create();
    asMember($organisation);

    $this->get(route('activity.export'))->assertForbidden();

    expect(recordedActivity(ActivityEvent::ActivityExported))->toHaveCount(0);
});

it('links "Export CSV" to the export with the page\'s current filters', function () use ($record): void {
    $organisation = Organisation::factory()->create();
    $record(ActivityEvent::OrganisationUpdated, $organisation, 'some-entry');
    asOrgAdmin($organisation);

    $this->get(route('activity.index'))
        ->assertOk()
        ->assertSee('data-test="activity-export"', false)
        ->assertSee('href="'.e(route('activity.export', absolute: false)).'"', false);

    $this->get(route('activity.index', ['event' => 'organisation.updated', 'from' => '2026-10-01']))
        ->assertOk()
        ->assertSee('href="'.e(route('activity.export', ['from' => '2026-10-01', 'event' => 'organisation.updated'], absolute: false)).'"', false);
});
```

Notes on the test file:
- `streamedContent()` runs the stream callback after the request has finished, so the stream sees the tenant, the locale and the signed-in user the middleware left behind. The same happens in production, where `send()` runs before `terminate()`.
- The CSV is parsed with `SplTempFileObject` (`READ_CSV`, the escape character `''`), so cells that hold a tab or a carriage return parse correctly. The classes are global, so the file imports nothing for them (as other tests use `RuntimeException`).
- The query-count test listens only after the request. Recording the audit entry and the request's own reads are not counted, only the stream's reads.

`tests/Feature/Audit/ActivityLogTest.php`, in 'gives every activity event a Dutch label and a group', replace

```php
        ->and($event->group())->toBeIn(['authentication', 'security', 'users', 'invitations', 'organisations', 'roles', 'impersonation'])
        ->and($event->tagsByHost())->toBe(in_array($event->group(), ['authentication', 'security', 'impersonation'], true));
```

with:

```php
        ->and($event->group())->toBeIn(['authentication', 'security', 'users', 'invitations', 'organisations', 'roles', 'impersonation', 'audit'])
        ->and($event->tagsByHost())->toBe(in_array($event->group(), ['authentication', 'security', 'impersonation', 'audit'], true));
```

`tests/Feature/Tenancy/RouteHostMatrixTest.php`: in `matrixRoutes()`, replace

```php
        'roles.index', 'activity.index', 'system.index',
```

with:

```php
        'roles.index', 'activity.index', 'activity.export', 'system.index',
```

In `routeMatrixOutcome()`, replace

```php
            'organisations.edit', 'roles.index', 'activity.index',
        ], true) => 'forbidden',                          // the matrix member holds no permission
```

with:

```php
            'organisations.edit', 'roles.index', 'activity.index', 'activity.export',
        ], true) => 'forbidden',                          // the matrix member holds no permission
```

`activity.export` behaves like `activity.index` everywhere else:
- On a foreign host, it logs out and answers `login`.
- A guest gets `login`.
- The member gets `forbidden`.
- An organisation admin on the tenant host and a super admin on every host get `ok`: a 200 `StreamedResponse` whose body the matrix never reads.

Task 5 adds `invitations.bulk` to the same two places after this task.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Audit/ActivityExportTest.php tests/Feature/Tenancy/RouteHostMatrixTest.php tests/Feature/Audit/ActivityLogTest.php`

Expected: 39 failures:
- **26 rows of `ActivityExportTest`** (every test and dataset row except the link test): `Route [activity.export] not defined.`
- **'links "Export CSV" to the export with the page\'s current filters':** the page has no `data-test="activity-export"`.
- **12 rows of 'answers every route on every host for every role' with `activity.export`:** `Route [activity.export] not defined.`

`ActivityLogTest` stays green at this point, because its group list only grew. It pins the new case once Step 4 adds it.

- [ ] **Step 3: Move the viewer's rules into `ActivityFilters` and `ActivityRows`**

This step changes no behaviour. The bodies are the page's private `baseQuery()`, `filteredQuery()`, `row()`, `userIdsOf()`, `memberIdsAmong()`, `organisationNames()`, `causerLabel()`, `subjectLabel()`, `subjectTypeLabel()`, `organisationLabel()`, `details()`, `displayValue()`, `isUserType()`, `userId()`, `digits()` and `day()`, moved unchanged. The only changes are `self::` for `$this->`, and `ActivityFilters::isCentral()` for the page's `isCentral()`.

Two details keep it exact:
- **The causer filter.** The page validated `?causer=` against its `causerOptions()`, which are `UserNames::of()` of the causer ids. A purged user's id names nobody, so it was ignored. `applied()` keeps that check.
- **The organisation column.** `ActivityRows` shows it only on a central host. It reads "central" from `memberIdsAmong()` returning null, which happens on a central host only.

`app/Support/Audit/ActivityFilters.php` (new):

```php

<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Models\User;
use App\Tenancy\HostScope;
use App\Tenancy\UserNames;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;

/**
 * The activity viewer's six filters as one query rule, shared by the page and the CSV export (§4.8, §6.6, CD-5).
 * Values arrive as raw strings from the URL; one that is not valid for this viewer is ignored, never an error
 * (AUD-8): a date that is not Y-m-d, an unknown event, a subject type or causer that occurs in none of this
 * viewer's entries (RK-M4), and an organisation on a tenant host (RK-I4). Every query method runs its queries
 * itself, so callers on a central host call them inside HostScope::read().
 */
final readonly class ActivityFilters
{
    /** @var list<string> The filter names, as the page's #[Url] properties and the export's query string spell them. */
    public const array NAMES = ['from', 'until', 'event', 'subject', 'causer', 'organisation'];

    public function __construct(
        public string $from = '',
        public string $until = '',
        public string $event = '',
        public string $subject = '',
        public string $causer = '',
        public string $organisation = '',
    ) {}

    /**
     * The filters from a query string; an array or any other non-string value counts as empty, never as a 500.
     *
     * @param  array<array-key, mixed>  $query
     */
    public static function fromQuery(array $query): self
    {
        $value = fn (string $name): string => is_string($query[$name] ?? null) ? $query[$name] : '';

        return new self(
            $value('from'),
            $value('until'),
            $value('event'),
            $value('subject'),
            $value('causer'),
            $value('organisation'),
        );
    }

    /**
     * True for a super admin on a central host: every organisation's entries and the central ones.
     * Anyone else on a central host gets an AuthorizationException.
     */
    public static function isCentral(): bool
    {
        return HostScope::organisationId() === null;
    }

    /**
     * The viewer's entries without filters. Call inside HostScope::read().
     *
     * @return Builder<Activity>
     */
    public static function baseQuery(): Builder
    {
        $query = Activity::query();

        if (self::isCentral()) {
            // Central viewers also see archived organisations' rows, soft-deleted by the cascade (RL-7).
            $query->withTrashed();
        }

        return $query;
    }

    /**
     * The subject types that occur in this viewer's entries, sorted.
     *
     * @return list<string>
     */
    public static function subjectTypes(): array
    {
        return HostScope::read(fn (): array => array_values(self::baseQuery()
            ->whereNotNull('subject_type')
            ->distinct()
            ->orderBy('subject_type')
            ->pluck('subject_type')
            ->map(fn (mixed $type): string => (string) $type)
            ->all()));
    }

    /**
     * The ids of the users who caused entries this viewer may read; a causer outside this list is ignored (RK-M4).
     *
     * @return list<int>
     */
    public static function causerIds(): array
    {
        return HostScope::read(fn (): array => array_values(self::baseQuery()
            ->where('causer_type', new User()->getMorphClass())
            ->whereNotNull('causer_id')
            ->distinct()
            ->pluck('causer_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all()));
    }

    /**
     * The entries with every valid filter applied, unordered. Call inside HostScope::read().
     *
     * @return Builder<Activity>
     */
    public function query(): Builder
    {
        $query = self::baseQuery();
        $applied = $this->applied();
        $from = $this->day($applied['from'] ?? '');
        $until = $this->day($applied['until'] ?? '');

        if ($from instanceof DateTimeImmutable) {
            $query->where('created_at', '>=', $from);
        }

        if ($until instanceof DateTimeImmutable) {
            $query->where('created_at', '<', $until->add(new DateInterval('P1D')));
        }

        if (isset($applied['event'])) {
            $query->where('event', $applied['event']);
        }

        if (isset($applied['subject'])) {
            $query->where('subject_type', $applied['subject']);
        }

        if (isset($applied['causer'])) {
            $query->where('causer_type', new User()->getMorphClass())->where('causer_id', (int) $applied['causer']);
        }

        if (isset($applied['organisation'])) {
            $query->where('organisation_id', (int) $applied['organisation']);
        }

        return $query;
    }

    /**
     * The filters that take effect, by name, in NAMES order; ignored values are left out. The export records
     * these in its audit entry.
     *
     * @return array<string, string>
     */
    public function applied(): array
    {
        $causerId = $this->digits($this->causer);
        $valid = [
            'from' => $this->day($this->from) instanceof DateTimeImmutable,
            'until' => $this->day($this->until) instanceof DateTimeImmutable,
            'event' => ActivityEvent::tryFrom($this->event) !== null,
            'subject' => $this->subject !== '' && in_array($this->subject, self::subjectTypes(), true),
            // As the page's causer list: a purged user's id names nobody, so it is ignored too.
            'causer' => $causerId !== null && array_key_exists($causerId, UserNames::of(self::causerIds())),
            'organisation' => $this->digits($this->organisation) !== null && self::isCentral(),
        ];
        $applied = [];

        foreach ($this->values() as $name => $value) {
            if ($valid[$name]) {
                $applied[$name] = $value;
            }
        }

        return $applied;
    }

    /**
     * The non-empty filters as a query string array, for the export link. The export validates them again.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter($this->values(), fn (string $value): bool => $value !== '');
    }

    /**
     * Every filter by name, in NAMES order.
     *
     * @return array{from: string, until: string, event: string, subject: string, causer: string, organisation: string}
     */
    private function values(): array
    {
        return [
            'from' => $this->from,
            'until' => $this->until,
            'event' => $this->event,
            'subject' => $this->subject,
            'causer' => $this->causer,
            'organisation' => $this->organisation,
        ];
    }

    private function digits(string $value): ?int
    {
        return ctype_digit($value) ? (int) $value : null;
    }

    /**
     * A Y-m-d filter value as the start of that day in the app timezone; anything else is ignored.
     */
    private function day(string $value): ?DateTimeImmutable
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone((string) config('app.timezone')));

        return $day !== false && $day->format('Y-m-d') === $value ? $day : null;
    }
}
```

`app/Support/Audit/ActivityRows.php` (new):

```php

<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\Activity;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\HostScope;
use App\Tenancy\UserNames;
use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * Audit entries as display rows, for the activity viewer and the CSV export (§4.8, §6.6): one rule for names,
 * labels and what a tenant viewer may see. Platform staff show up in a tenant's log by name only (RL-7, RK-D1):
 * an entry that names anyone other than this organisation's own users who are not super admins shows no IP
 * address or user agent, and such a user's own field changes stay hidden. Callers pass only entries they read
 * through HostScope::read(); names are looked up for the ids in those entries only (RL-9, RK-M4).
 *
 * @phpstan-type ActivityRow array{id: int, when: string, event: string, event_key: string, causer: string, subject: string, organisation: string|null, changes: list<array{attribute: string, old: string, new: string}>, details: string|null, ip_address: string|null, user_agent: string|null, properties: string}
 */
final class ActivityRows
{
    /**
     * The entries as rows, in the order given, with one lookup each for names, organisations and members.
     *
     * @param  list<Activity>  $activities
     * @return list<ActivityRow>
     */
    public static function of(array $activities): array
    {
        $userIds = self::userIdsOf($activities);
        $names = UserNames::of($userIds);
        $organisations = self::organisationNames($activities);
        $members = self::memberIdsAmong($userIds);
        $rows = [];

        foreach ($activities as $activity) {
            $rows[] = self::row($activity, $names, $organisations, $members);
        }

        return $rows;
    }

    /** A subject type's label: User, Organisation, Invitation, Role, else the class's base name. */
    public static function subjectTypeLabel(string $type): string
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        return match ($class) {
            User::class => __('User'),
            Organisation::class => __('Organisation'),
            Invitation::class => __('Invitation'),
            RoleModel::class => __('Role'),
            default => class_basename($class),
        };
    }

    /**
     * @param  array<int, string>  $names
     * @param  array<int, string>  $organisations
     * @param  list<int>|null  $members  see memberIdsAmong()
     * @return ActivityRow
     */
    private static function row(Activity $activity, array $names, array $organisations, ?array $members): array
    {
        $hidesConnection = $members !== null && array_diff(self::userIdsOf([$activity]), $members) !== [];
        $subjectId = self::isUserType($activity->subject_type) ? (int) $activity->subject_id : null;
        $hidesChanges = $members !== null && $subjectId !== null && ! in_array($subjectId, $members, true);
        $changes = [];

        foreach ($hidesChanges ? [] : $activity->changeSummary() as $change) {
            $changes[] = [
                'attribute' => $change['attribute'],
                'old' => self::displayValue($change['old']),
                'new' => self::displayValue($change['new']),
            ];
        }

        $key = (string) ($activity->event ?? $activity->description);

        return [
            'id' => (int) $activity->getKey(),
            'when' => $activity->created_at?->format('Y-m-d H:i') ?? '',
            // An unknown key (older rows) renders raw.
            'event' => $activity->kitEvent()?->label() ?? $key,
            'event_key' => $key,
            'causer' => self::causerLabel($activity, $names),
            'subject' => self::subjectLabel($activity, $names),
            // memberIdsAmong() gives null on a central host only, the one place with an organisation column.
            'organisation' => $members === null ? self::organisationLabel($activity->organisation_id, $organisations) : null,
            'changes' => $changes,
            'details' => self::details($activity),
            'ip_address' => $hidesConnection ? null : $activity->ip_address,
            'user_agent' => $hidesConnection ? null : $activity->user_agent,
            'properties' => json_encode(
                $activity->properties?->toArray() ?? [],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
        ];
    }

    /**
     * User ids the rows refer to: causers, user subjects, impersonated users and impersonators.
     *
     * @param  array<int, Activity>  $activities
     * @return list<int>
     */
    private static function userIdsOf(array $activities): array
    {
        $ids = [];

        foreach ($activities as $activity) {
            $ids[] = self::isUserType($activity->causer_type) ? $activity->causer_id : null;
            $ids[] = self::isUserType($activity->subject_type) ? $activity->subject_id : null;
            $ids[] = self::userId($activity->getProperty('impersonated_as'));
            $ids[] = self::userId($activity->getProperty('impersonator_id'));
        }

        return array_values(array_unique(array_filter($ids, fn (?int $id): bool => $id !== null)));
    }

    /**
     * On a tenant host, the ids among $ids of this organisation's own users who are not super admins, deleted
     * ones included, read through the host scope; null on a central host, where every entry shows in full.
     *
     * @param  list<int>  $ids
     * @return list<int>|null
     */
    private static function memberIdsAmong(array $ids): ?array
    {
        if (ActivityFilters::isCentral()) {
            return null;
        }

        if ($ids === []) {
            return [];
        }

        return HostScope::read(fn (): array => array_values(User::withTrashed()
            ->whereKey($ids)
            ->where('is_super_admin', false)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all()));
    }

    /**
     * Names of the organisations of the given rows, archived ones included; central only.
     *
     * @param  array<int, Activity>  $activities
     * @return array<int, string>
     */
    private static function organisationNames(array $activities): array
    {
        if (! ActivityFilters::isCentral()) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map(
            fn (Activity $activity): ?int => $activity->organisation_id,
            $activities,
        ))));
        $names = [];

        foreach (Organisation::withTrashed()->whereKey($ids)->get(['id', 'name']) as $organisation) {
            $names[$organisation->id] = $organisation->name;
        }

        return $names;
    }

    /**
     * The causer's name, "as :name" while impersonating; the impersonator of a stop that was logged without a
     * causer; "Command line" or ":name via the command line" for entries a command wrote (CD-7); "System" for the
     * rest without one.
     *
     * @param  array<int, string>  $names
     */
    private static function causerLabel(Activity $activity, array $names): string
    {
        $causerId = self::isUserType($activity->causer_type) ? $activity->causer_id : null;
        $impersonatorId = self::userId($activity->getProperty('impersonator_id'));
        $console = $activity->getProperty('via') === 'console';

        $label = match (true) {
            $causerId !== null => $names[$causerId] ?? __('Deleted user'),
            $impersonatorId !== null => $names[$impersonatorId] ?? __('Deleted user'),
            $console => __('Command line'),
            default => __('System'),
        };

        if ($console && ($causerId !== null || $impersonatorId !== null)) {
            $label = __(':name via the command line', ['name' => $label]);
        }

        $impersonatedId = self::userId($activity->getProperty('impersonated_as'));

        if ($impersonatedId === null) {
            return $label;
        }

        return $label.' '.__('as :name', ['name' => $names[$impersonatedId] ?? __('Deleted user')]);
    }

    /**
     * The subject's type and id, with the user's name or a stored name where there is one.
     *
     * @param  array<int, string>  $names
     */
    private static function subjectLabel(Activity $activity, array $names): string
    {
        if ($activity->subject_type === null) {
            return '—';
        }

        $label = self::subjectTypeLabel($activity->subject_type).' #'.$activity->subject_id;
        $storedName = $activity->getProperty('name');
        $name = self::isUserType($activity->subject_type)
            ? ($names[(int) $activity->subject_id] ?? null)
            : (is_string($storedName) ? $storedName : null);

        return $name === null ? $label : $label.' · '.$name;
    }

    /**
     * Central rows without an organisation are platform entries; a missing one was purged.
     *
     * @param  array<int, string>  $organisations
     */
    private static function organisationLabel(?int $organisationId, array $organisations): string
    {
        if ($organisationId === null) {
            return __('Platform');
        }

        return $organisations[$organisationId] ?? __('Purged organisation');
    }

    /**
     * The reason column: the stated reason, or what a failed sign-in typed (RL-7).
     */
    private static function details(Activity $activity): ?string
    {
        $reason = $activity->getProperty('reason');

        if (is_string($reason) && $reason !== '') {
            return $reason;
        }

        if ($activity->getProperty('invalid_address') === true) {
            return __('Not an email address');
        }

        $email = $activity->getProperty('email');

        return is_string($email) && $email !== '' ? $email : null;
    }

    private static function displayValue(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? __('Yes') : __('No'),
            is_scalar($value) => (string) $value,
            is_array($value) && array_is_list($value) && array_filter($value, fn (mixed $item): bool => ! is_scalar($item)) === [] => implode(', ', array_map(fn (mixed $item): string => (string) $item, $value)),
            default => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        };
    }

    private static function isUserType(?string $type): bool
    {
        return $type !== null && (Relation::getMorphedModel($type) ?? $type) === User::class;
    }

    private static function userId(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }
}
```

`resources/views/pages/activity/index/index.php`: replace the whole file with:

```php

<?php

declare(strict_types=1);

use App\Authorization\Permission;
use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Models\Organisation;
use App\Support\Audit\ActivityFilters;
use App\Support\Audit\ActivityRows;
use App\Tenancy\HostScope;
use App\Tenancy\UserNames;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Activity log')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    #[Url]
    public string $event = '';

    #[Url]
    public string $subject = '';

    #[Url]
    public string $causer = '';

    /** Central hosts only; ignored on a tenant host (RK-I4). */
    #[Url]
    public string $organisation = '';

    #[Locked]
    public ?int $detailId = null;

    public bool $detailOpen = false;

    /**
     * The current page, read once per request; private properties are never dehydrated.
     *
     * @var LengthAwarePaginator<int, Activity>|null
     */
    private ?LengthAwarePaginator $activitiesPage = null;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        Gate::authorize(Permission::ActivityLogView);
    }

    /**
     * Back to the first page whenever a filter changes (AUD-8).
     */
    public function updated(string $property): void
    {
        if (in_array($property, ActivityFilters::NAMES, true)) {
            $this->resetPage();
        }
    }

    /**
     * Open the detail flyout for an entry this viewer may read; any other id answers 404.
     */
    public function showDetail(int $activityId): void
    {
        $activity = HostScope::read(fn () => ActivityFilters::baseQuery()->findOrFail($activityId));

        $this->detailId = (int) $activity->getKey();
        $this->detailOpen = true;
    }

    /**
     * Close the detail flyout.
     */
    public function closeDetail(): void
    {
        $this->detailOpen = false;
        $this->detailId = null;
    }

    /**
     * The filtered entries, 50 per page, newest first (AUD-3).
     *
     * Memoised in $activitiesPage so the paginate query runs once per request. PHP code calls this method,
     * never the magic computed property (Larastan level 8 has no Livewire computed support).
     *
     * @return LengthAwarePaginator<int, Activity>
     */
    #[Computed]
    public function activities(): LengthAwarePaginator
    {
        if ($this->activitiesPage instanceof LengthAwarePaginator) {
            return $this->activitiesPage;
        }

        /** @var LengthAwarePaginator<int, Activity> $activities */
        $activities = HostScope::read(fn (): LengthAwarePaginator => $this->filters()->query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50));

        return $this->activitiesPage = $activities;
    }

    /**
     * The page's entries as display rows (RL-7: App\Support\Audit\ActivityRows).
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function rows(): array
    {
        return ActivityRows::of(array_values($this->activities()->items()));
    }

    /**
     * The entry in the detail flyout, read again through the host scope.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function detail(): ?array
    {
        if ($this->detailId === null) {
            return null;
        }

        $activity = HostScope::read(fn () => ActivityFilters::baseQuery()->find($this->detailId));

        if (! $activity instanceof Activity) {
            return null;
        }

        return ActivityRows::of([$activity])[0];
    }

    /**
     * Every action of the audit vocabulary with its label.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function eventOptions(): array
    {
        $options = [];

        foreach (ActivityEvent::cases() as $event) {
            $options[$event->value] = $event->label();
        }

        return $options;
    }

    /**
     * The subject types that occur in this viewer's entries.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function subjectOptions(): array
    {
        $options = [];

        foreach (ActivityFilters::subjectTypes() as $type) {
            $options[$type] = ActivityRows::subjectTypeLabel($type);
        }

        return $options;
    }

    /**
     * The users who caused entries this viewer may read; a ?causer= outside this list is ignored (RK-M4).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function causerOptions(): array
    {
        $names = UserNames::of(ActivityFilters::causerIds());
        asort($names);

        return $names;
    }

    /**
     * Every organisation, archived ones included, for the central filter; empty on a tenant host.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function organisationOptions(): array
    {
        if (! $this->isCentral()) {
            return [];
        }

        $options = [];

        foreach (Organisation::withTrashed()->orderBy('name')->get(['id', 'name']) as $organisation) {
            $options[$organisation->id] = $organisation->name;
        }

        return $options;
    }

    /**
     * True for a super admin on a central host: every organisation's entries and the central ones.
     */
    #[Computed]
    public function isCentral(): bool
    {
        return ActivityFilters::isCentral();
    }

    private function filters(): ActivityFilters
    {
        return new ActivityFilters($this->from, $this->until, $this->event, $this->subject, $this->causer, $this->organisation);
    }
};
```

What changed in the page:
- `const FILTERS` became `ActivityFilters::NAMES`.
- The memoised `activities()` paginates `$this->filters()->query()`.
- `rows()` and `detail()` call `ActivityRows::of()`.
- The public `#[Computed] causerNames()` is gone: only `rows()` used it, and `ActivityRows::of()` looks the names up itself. No Blade file or test reads it (`grep -rn causerNames resources tests` finds nothing).
- The private `filters()` builds the shared filter object from the six `#[Url]` properties.

Run: `php artisan test --compact tests/Feature/Audit/ActivityViewerTest.php tests/Feature/Audit/ConsoleOriginTest.php tests/Feature/FluxPro tests/Feature/Navigation tests/Feature/Dashboard`

Expected: PASS. The viewer's twelve tests pin the moved rules: tenant scope, archived rows, every filter, hostile query strings, cross-scope names, "as :name", RL-7 and the flyout.

Commit the refactor on its own:

```bash
git add app/Support/Audit/ActivityFilters.php app/Support/Audit/ActivityRows.php resources/views/pages/activity/index/index.php
git commit -m "refactor: share the activity viewer's filters and rows

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

The pre-commit hook runs Pint and Larastan on the three staged files. The new test file is not staged yet.

- [ ] **Step 4: The audit event (CD-9)**

`app/Enums/ActivityEvent.php`: in the class docblock, replace

```php
 * log name. Entries of the authentication, security and impersonation groups are tagged with the host's
```

with:

```php
 * log name. Entries of the authentication, security, impersonation and audit groups are tagged with the host's
```

Add the case after the last one:

```php
    case ImpersonationStopped = 'impersonation.stop';
    case ActivityExported = 'audit.exported';
```

Add its label after the last arm of `label()`:

```php
            self::ImpersonationStopped => __('Impersonation stopped'),
            self::ActivityExported => __('Activity log exported'),
```

Replace `group()`'s docblock and add the arm:

```php
    /** The log name: authentication, security, users, invitations, organisations, roles, impersonation or audit. */
```

```php
            'impersonation' => 'impersonation',
            'audit' => 'audit',
```

In `tagsByHost()`, replace

```php
        return in_array($this->group(), ['authentication', 'security', 'impersonation'], true);
```

with:

```php
        return in_array($this->group(), ['authentication', 'security', 'impersonation', 'audit'], true);
```

The export has no subject, so subject mode would also fall back to the bound tenant. Host mode states the rule outright: an export belongs to the host it ran on. `ActivityLogTest` pins it.

- [ ] **Step 5: The limiter, the route and the controller**

`app/Providers/FortifyServiceProvider.php`: at the end of `configureRateLimiting()`, after the `handoff` limiter, add:

```php

        // §6.6, CD-5: the audit CSV export, every request, per user; each download reads the whole filtered log.
        RateLimiter::for('activity-export', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('activity-export:'.$request->user()?->getAuthIdentifier()));
```

`routes/admin.php`: add the import

```php
use App\Http\Controllers\ActivityExportController;
```

after `use App\Authorization\Permission;`. Then, inside the `auth` group, right after the `activity.index` line, add:

```php

    // §6.6, CD-5: the viewer's entries as a streamed CSV, with the same filters and scope; any host, like the viewer.
    Route::get('activity/export', ActivityExportController::class)
        ->middleware('throttle:activity-export')
        ->can(Permission::ActivityLogView)
        ->name('activity.export');
```

Middleware priority runs `ThrottleRequests` before `Authorize`, so a refused 403 also counts toward the limit. The limiter keys on the user, never on the request, so a client cannot pick a fresh bucket.

`app/Http/Controllers/ActivityExportController.php` (new):

```php

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Audit\RecordActivity;
use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Support\Audit\ActivityFilters;
use App\Support\Audit\ActivityRows;
use App\Tenancy\HostScope;
use Illuminate\Http\Request;
use Illuminate\Support\LazyCollection;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /activity/export (§4.8, §6.6, CD-5): the activity log as CSV, with the viewer's filters and the host's scope.
 * The route checks activity_log.view and the `activity-export` limiter; on a central host VerifyTenantMembership
 * lets only super admins through. The rows stream in id order, 500 per query (lazyById), so a large log is never
 * held in memory: at most one chunk of entries and its name lookups at a time. Not a Livewire download, which
 * would buffer the whole file into the update's JSON response.
 */
final class ActivityExportController extends Controller
{
    /** Cells that start like a formula are prefixed with an apostrophe, so a spreadsheet shows them as text. */
    private const array FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public function __invoke(Request $request, RecordActivity $recordActivity): StreamedResponse
    {
        $filters = ActivityFilters::fromQuery($request->query());
        $central = ActivityFilters::isCentral();

        // Recorded before the first byte, with the filters that take effect; ignored values are left out.
        $recordActivity->handle(ActivityEvent::ActivityExported, properties: ['filters' => $filters->applied()]);

        return response()->streamDownload(function () use ($filters, $central): void {
            $output = fopen('php://output', 'wb') ?: throw new RuntimeException('The export cannot open the output stream.');

            // A UTF-8 byte order mark, so Excel reads the names and labels as UTF-8.
            fwrite($output, "\u{FEFF}");
            $this->write($output, $this->header($central));

            HostScope::read(function () use ($filters, $central, $output): void {
                $filters->query()->lazyById(500)->chunk(500)->each(function (LazyCollection $chunk) use ($central, $output): void {
                    /** @var list<Activity> $activities */
                    $activities = $chunk->values()->all();

                    foreach (ActivityRows::of($activities) as $index => $row) {
                        $this->write($output, $this->line($activities[$index], $row, $central));
                    }
                });
            });

            fclose($output);
        }, 'activity-log-'.now()->format('Y-m-d-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** @return list<string> */
    private function header(bool $central): array
    {
        return [
            __('Date'),
            __('Event key'),
            __('Action'),
            __('Done by'),
            __('Subject'),
            ...($central ? [__('Organisation')] : []),
            __('IP address'),
            __('User agent'),
            __('Properties'),
        ];
    }

    /**
     * One entry as CSV cells; IP address and user agent are empty where the viewer hides them (RL-7).
     *
     * @param  array{event: string, event_key: string, causer: string, subject: string, organisation: string|null, ip_address: string|null, user_agent: string|null}  $row
     * @return list<string>
     */
    private function line(Activity $activity, array $row, bool $central): array
    {
        return [
            $activity->created_at?->toIso8601String() ?? '',
            $row['event_key'],
            $row['event'],
            $row['causer'],
            $row['subject'],
            ...($central ? [(string) $row['organisation']] : []),
            (string) $row['ip_address'],
            (string) $row['user_agent'],
            json_encode($activity->properties?->toArray() ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @param  resource  $output
     * @param  list<string>  $cells
     */
    private function write($output, array $cells): void
    {
        fputcsv($output, array_map($this->neutralise(...), $cells), ',', '"', '');
    }

    /** CSV formula injection: a cell a spreadsheet would run as a formula starts with an apostrophe instead. */
    private function neutralise(string $cell): string
    {
        return $cell !== '' && in_array($cell[0], self::FORMULA_PREFIXES, true) ? "'".$cell : $cell;
    }
}
```

Notes:
- **`fputcsv`** is called with the escape character `''`. That is RFC 4180 quoting, and PHP 8.4 deprecates leaving it out (the default is a backslash).
- **The formula guard** looks at the first byte of every cell. The header labels, ISO dates, event keys, IP addresses and JSON never start with one of the six prefixes, so in practice only names, labels, user agents and stored names are rewritten.
- **The scope while streaming.** `HostScope::read()` runs inside the stream callback, while the response is sent and after the middleware returned. `InitializeTenancy` never forgets the tenant on the way out, so the callback sees the request's tenant, or the central bypass for a super admin, as the page does.
- **The audit filters.** `applied()` reads `subjectTypes()` and `causerIds()` through `HostScope::read()` itself, so the audit line needs no wrapper.

- [ ] **Step 6: The button**

`resources/views/pages/activity/index/index.php`: after `isCentral()`, before the private `filters()`, add:

```php
    /**
     * The "Export CSV" link: the export route with the current filters (CD-5). A plain GET download, never a
     * Livewire action, which would buffer the whole file into its JSON response.
     */
    #[Computed]
    public function exportUrl(): string
    {
        return route('activity.export', $this->filters()->toQuery(), absolute: false);
    }
```

`resources/views/pages/activity/index/index.blade.php`: between the heading block's closing `</div>` and the filter grid, add:

```blade
    {{-- A plain download link with the current filters (CD-5), never wire:navigate or a Livewire action. --}}
    <div class="flex justify-end">
        <flux:button :href="$this->exportUrl" icon="arrow-down-tray" data-test="activity-export">{{ __('Export CSV') }}</flux:button>
    </div>
```

so that the file begins:

```blade
<section class="w-full space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Activity log') }}</flux:heading>
        <flux:subheading size="lg" class="mb-6">{{ __('See who did what and when') }}</flux:subheading>
        <flux:separator variant="subtle" />
    </div>

    {{-- A plain download link with the current filters (CD-5), never wire:navigate or a Livewire action. --}}
    <div class="flex justify-end">
        <flux:button :href="$this->exportUrl" icon="arrow-down-tray" data-test="activity-export">{{ __('Export CSV') }}</flux:button>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
```

The filters are `wire:model.live`, so every filter change re-renders the link with the new query string. The link is relative (`absolute: false`), so it stays on the host the page is on. `flux:button` with `href` is free Flux; there is no Pro variant.

- [ ] **Step 7: Copy and CHANGELOG**

Lang keys, in both files, at their sorted positions (PHP `sort()` order):

| Key | nl | Position |
|---|---|---|
| `Activity log exported` | `Activiteitenlog geëxporteerd` | right after `Activity log`, before `Add a passkey to sign in without a password` |
| `Date` | `Datum` | right after `Database ready: :name`, before `Delete` |
| `Export CSV` | `CSV exporteren` | right after `Expires`, before `FAIL` |

In `en.json` each value equals its key. These keys already exist: `Event key`, `Action`, `Done by`, `Subject`, `Organisation`, `IP address`, `User agent`, `Properties` and `Platform`. If Task 1 put a key between these neighbours, keep the sorted order: the position follows from the key, and the neighbours only help find it.

`CHANGELOG.md`, at the end of `[Unreleased]` › `### Added`:

```markdown
- An *Export CSV* button on the activity log: it downloads the entries that match the current filters, from the same organisations the log shows, as a streamed CSV that Excel opens directly. IP addresses and user agents stay empty where the log hides them, cells that look like a formula are neutralised, and every export is recorded in the log and limited to five a minute per user.
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Audit tests/Feature/Tenancy/RouteHostMatrixTest.php tests/Feature/LangFilesTest.php tests/Feature/LangCoverageTest.php tests/Feature/Navigation tests/Feature/FluxPro tests/Arch`

Expected: PASS. The arch rules confirm that:
- the controller uses no `Tenant::bypass()` (`TenancyRulesTest`)
- the controller passes the Laravel preset (an invokable `*Controller`)
- the audit entry is written through `RecordActivity` (`AuditRulesTest`)

- [ ] **Step 9: Verify by mutation**

Revert each change locally (copy the file aside with `cp`, never `git stash`), run the named test, expect it to fail, then restore the change. After a Blade or component mutation, run `php artisan view:clear`.
1. **The controller:** `neutralise()` returns `$cell` unchanged. Expect all six rows of 'prefixes a cell that starts like a formula …' to fail.
2. **The controller:** drop `fwrite($output, "\u{FEFF}");`. Expect 'streams a CSV download with a byte order mark …' to fail on `toStartWith`.
3. **`ActivityRows::row()`:** `'ip_address' => $activity->ip_address,` without the `$hidesConnection` check. Expect the export's 'leaves platform staff\'s IP address …' and the viewer's 'hides platform staff\'s IP address …' to fail: one rule, two consumers.
4. **The controller:** `->cursor()->chunk(500)` instead of `->lazyById(500)->chunk(500)`. Expect 'reads the log 500 entries per query …' to fail: 0 chunk reads instead of 2.
5. **The controller:** `lazyByIdDesc(500)`. Expect 'streams the entries in id order …' to fail.
6. **`ActivityFilters::applied()`:** drop `&& self::isCentral()` from the organisation check. Expect the export's 'exports only the organisation\'s own entries on a tenant host …' and the viewer's 'ignores ?organisation= on a tenant host …' to fail.
7. **The controller:** move `$recordActivity->handle(...)` into the stream callback. Expect 'records the export with the filters it applied, before the first byte' and 'records a central export without an organisation' to error: `sole()` finds nothing, because the body is never read.
8. **The controller:** record `$filters->toQuery()` instead of `applied()`. Expect 'records the export with the filters it applied …' to fail: `until` and `organisation` appear.
9. **The limiter:** `perMinute(6)`. Expect 'allows five exports a minute per user and refuses the sixth' to fail. Then key it `->by('activity-export:')` without the user: the same test fails on the second administrator.
10. **The controller:** `$central = true;`. Expect 'streams a CSV download …' to fail: the tenant header gains `Organisation`.
11. **The page:** `route('activity.export', absolute: false)` without the filters. Expect 'links "Export CSV" …' to fail.
12. **The route:** drop `->can(Permission::ActivityLogView)`. Expect 'refuses a user without the activity_log.view permission' to fail with 200.
13. **`ActivityEvent::tagsByHost()`:** drop `'audit'`. Expect `ActivityLogTest`'s 'gives every activity event a Dutch label and a group' to fail for `ActivityExported`.
14. **`ActivityFilters::query()`:** `$until` instead of `$until->add(new DateInterval('P1D'))`. Expect the export's 'until, inclusive of the whole day' row and the viewer's 'filters by date range …' to fail.
15. **The controller:** replace `HostScope::read(function () … { … });` in the stream with a plain immediately-invoked closure. Expect 'exports every entry … to a super admin on a central host …' to error with "No tenant is bound while querying [App\Models\Activity]": strict mode throws without the bypass.

- [ ] **Step 10: The gate, then commit**

Run the Global Constraints gate:
- `composer fix`
- `composer check`
- `composer test:pgsql`
- `php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser`
- `npm run build && composer test:browser`
- `npm run build:setup && git diff --exit-code resources/setup/setup.css` (no setup view changes, so expect no diff)
- `php artisan config:clear`

```bash
git add app/Http/Controllers/ActivityExportController.php app/Enums/ActivityEvent.php \
    app/Providers/FortifyServiceProvider.php routes/admin.php \
    resources/views/pages/activity/index/index.php resources/views/pages/activity/index/index.blade.php \
    lang/nl.json lang/en.json CHANGELOG.md \
    tests/Feature/Audit/ActivityExportTest.php tests/Feature/Audit/ActivityLogTest.php \
    tests/Feature/Tenancy/RouteHostMatrixTest.php
git commit -m "feat: export the activity log as a streamed csv

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---


### Task 5: Bulk invitations (§6.6 bulk invitations, §4.5, §6.2 `invitations.bulk`, §6.7; CD-6, CD-10)

Administrators invite several people in one go: they paste addresses or upload a CSV file, choose the roles and the default language once, check the list, and send it. The page reuses everything `users.invite` already has: the same authorisation (the `create` ability on `Invitation` for the host's organisation, or a super admin with an organisation picker on a central host), the same free/Pro role controls, and `InviteUser` for every row, so each invitation is locked, validated, audited (`invitation.sent`) and mailed exactly as a single one (CD-9). The preview is `#[Locked]` and reviewed again before sending. An address `InviteUser` would refuse gets one generic message, "This address can't be invited.", whatever the reason, so a list never tells which addresses exist in this or another organisation (§4.5). Sending runs under a new per-user limit, `UserActionLimit::bulkInvite()` (100 per hour), that reserves the ready rows and gives back the rows the action refuses; the single-invite limit is not consumed.

Two small pieces are shared with `users.invite` instead of copied:
- `App\Concerns\ChoosesInvitationOrganisation`, a component trait that holds `users.invite`'s organisation picker, role options, return route and authorisation. `users.invite` switches to it with no change in behaviour (its tests stay as they are).
- `InviteUser::addressRules()`, a public reader of the address rules `InviteUser` validates with, so the preview checks exactly what the send will check.

**Files:**
- Create:
  - `app/Support/Invitations/InvitationList.php`
  - `app/Concerns/ChoosesInvitationOrganisation.php`
  - `resources/views/pages/invitations/bulk/bulk.php`
  - `resources/views/pages/invitations/bulk/bulk.blade.php`
  - `tests/Unit/InvitationListTest.php`
  - `tests/Feature/Invitations/BulkInvitationsTest.php`
- Modify:
  - `app/Support/RateLimits/UserActionLimit.php` (written against Task 1's version: `bulkInvite()` goes directly after the constructor, before Task 1's `logoutOtherDevices()`)
  - `app/Actions/Invitations/InviteUser.php` (`addressRules()`; `invite()` validates with it; the class docblock's last sentence)
  - `resources/views/pages/users/invite/invite.php` (uses `ChoosesInvitationOrganisation`; the moved methods leave)
  - `routes/admin.php` (written against Task 3's version, whose `auth` group also carries `EnsureSecondFactor`: the `invitations.bulk` route right after `users.invite`)
  - `resources/views/pages/invitations/pending/pending.blade.php` (the "Invite several people" button)
  - `lang/nl.json`, `lang/en.json`, `CHANGELOG.md`
- Test:
  - `tests/Unit/InvitationListTest.php` (new)
  - `tests/Feature/Invitations/BulkInvitationsTest.php` (new)
  - `tests/Feature/Tenancy/RouteHostMatrixTest.php` (written against Task 4's version: `invitations.bulk` in `matrixRoutes()` and in the member's `forbidden` list)
  - `tests/Feature/FluxPro/FreeVariantsTest.php`, `tests/Feature/FluxPro/ProVariantsTest.php` (one line each; Task 6 appends its own lines after them)

**Interfaces:**
- Consumes:
  - `InviteUser::handle(User $actor, Organisation $organisation, string $email, array $roleIds, Locale $locale): Invitation`. It authorises itself, locks the organisation (`HostScope::lockForWrite()`, which throws a `ValidationException` on `organisation` with "This organisation is archived."), validates the address and the roles, maps a unique violation to a `ValidationException` on `email`, records `invitation.sent` and queues `InvitationMail` in the invitee's locale after commit.
  - `ProfileValidationRules::emailRules(?int $userId = null, ?int $organisationId = null): array` (through `InviteUser`), with `App\Rules\AvailableEmail`.
  - `UserActionLimit` (Task 1's version): `__construct(string $name, int $maxAttempts, int $decaySeconds)`, `key()`, `ensureAvailable(User $user, string $field, int $cost = 1): void`, `reserve(User $user, string $field, int $cost = 1): void`, `release(User $user, int $cost = 1): void`, `hit(User $user, int $cost = 1): void`.
  - `Locale::default()`, `Locale::tryFrom()`, `Locale::from()`, `Locale::label()`.
  - `InvitationPolicy::create(User $actor, Organisation $organisation): bool` (users.create, roles.assign and the same organisation; super admins pass through `Gate::before`).
  - Task 3's `routes/admin.php` (the `auth` group with `EnsureSecondFactor`); Task 4's `RouteHostMatrixTest.php` (with `activity.export`). Nothing else of Tasks 1–4 is used.
  - Test helpers: `asOrgAdmin()`, `asMember()`, `asSuperAdmin()`, `onApex()`, `onAdminHost()`, `inviteMember()`, `recordedActivity()`, `pageSnapshot()`, `callPageAction()`.
- Produces:
  - `App\Support\Invitations\InvitationList` (final, static):
    - `public const int MAX_ROWS = 100;`
    - `public static function fromText(string $text): array` → `list<array{email: string, locale: string|null}>`: a UTF-8 BOM stripped, split on line breaks, commas and semicolons, each value trimmed and lower-cased, empty values dropped, `locale` always `null`.
    - `public static function fromCsv(string $contents): array` → the same shape: BOM stripped, blank lines dropped, delimiter `;` when the first line has more semicolons than commas, else `,`; cells read with `str_getcsv($line, $delimiter, '"', '')`; the first line is a header when none of its cells contains `@` and one names a column (the email column is the first cell containing `mail`; the locale column is a cell named `locale`, `language`, `lang` or `taal`); with a header its columns are used (email defaults to the first, no locale column means no locale); without one, column 1 is the address and column 2 the locale. Rows without an address are dropped, empty locales are `null`.
  - `UserActionLimit::bulkInvite(): self` = `new self('bulk-invite', 100, 3600)`.
  - `InviteUser::addressRules(Organisation $organisation): array` (`array<int, ValidationRule|Stringable|array<mixed>|string>`) = `$this->emailRules(organisationId: $organisation->id)`.
  - `App\Concerns\ChoosesInvitationOrganisation` (trait, `@phpstan-require-extends Component`, uses `ResolvesCurrentUser`; the page declares `public ?int $organisationId` and `public array $roles`): `updatedOrganisationId(): void`, `#[Computed] returnRoute(): string`, `#[Computed] organisationOptions(): array<int, string>`, `#[Computed] roleOptions(): array<int, string>`, `protected authorizeInvitePage(): void`, `protected organisationToInvite(): Organisation` (now also authorises `create` on the organisation it returns), `protected pickedOrganisationId(): ?int`.
  - Route `invitations.bulk`: GET `invitations/bulk`, `pages::invitations.bulk`, `can:` `Permission::UsersCreate`, any host, inside the `auth` group.
  - Component `pages::invitations.bulk`: public `string $addresses`, `?TemporaryUploadedFile $csv`, `string $locale`, `array $roles`, `?int $organisationId`; `#[Locked] array $rows` (`list<array{email: string, locale: string, reason: string|null}>`, `reason` null = "Ready"); `#[Locked] ?array $summary` (`array{sent: list<string>, skipped: list<array{email: string, reason: string}>}|null`); actions `check()`, `send()`, `removeCsv()`, `startOver()`; `#[Computed] readyCount(): int`. Errors: `addresses` (empty list, more than 100 rows, "Check the list first.", nothing ready, the limit), `csv` (`nullable|file|mimes:csv,txt|max:512`), `roles` ("Choose at least one role."), `locale`, `organisationId` ("Choose an organisation first.").

Background for the implementer:
- **Why the preview is locked and reviewed twice.** `check()` stores what the server parsed and decided; the client can neither add a row nor flip a reason (a forged update throws `CannotUpdateLockedPropertyException`). Any change to the addresses, the file, the language, the roles or the organisation drops the preview (`updated()`), so "Send" never sends a list other than the one on screen. `send()` reviews the locked rows again against the organisation it resolves now, because an address may have been taken since.
- **The generic message (§4.5).** `AvailableEmail` names in-organisation conflicts ("already used in this organisation", "already has an open invitation") and gives everything else "This address can't be used." On a list, a page that differs per row would still enumerate, so every failure of `InviteUser::addressRules()` is "This address can't be invited." in the preview, and an `email` error from `InviteUser` at send time (a unique violation from a concurrent sign-up) is shown the same way. Other refusals from the action (an organisation archived meanwhile, a role gone) keep the action's own message.
- **The limit.** `ensureAvailable()` first, so a list larger than what is left of the hour is refused without counting (a smaller list still fits); then `reserve()` counts all ready rows atomically, so a parallel send cannot overrun the limit; the `finally` gives back the rows that were not sent (refused by the action, or not reached because something else threw). The refusal is "Too many attempts. Try again in :seconds seconds." on `addresses`, and nothing is sent. The cost of a batch is at most 100 (`MAX_ROWS`), which `assertCost()` accepts.
- **Uploads.** The CSV is read as text (`TemporaryUploadedFile::get()`) and parsed with `str_getcsv`; nothing is stored. `mimes:csv,txt` accepts what Laravel guesses as `csv` or `txt` from the content (a CSV is `text/plain` or `text/csv`), so a PDF renamed to `.csv` is still refused. Livewire's upload endpoint (`livewire.upload-file`) is exempt from `EnsureSecondFactor` (Task 3), like every Livewire request.
- **Pro (CD-10, corrected).** Rendering a Pro branch with `Skv::useFluxPro(true)` throws without `livewire/flux-pro`: `AppServiceProvider::configureFluxProBranches()` strips the Pro branches at compile time and puts a `LogicException` ("Flux Pro was installed after this view was compiled…") in their place. So the Pro upload is covered by `tests/Arch/FluxProLayerTest.php` (it scans every view: Pro tags only inside the branch, a free `@else`, the same `wire:model` names, here `[csv]` and `[roles]`) and by one line in `ProVariantsTest`, which runs only where Pro is installed. The free branch is covered by one line in `FreeVariantsTest`. The Pro markup follows the Flux docs for `flux:file-upload` (`wire:model`, `label`, `description`), `flux:file-upload.dropzone` (`heading`, `text`, `inline`) and `flux:file-item` (`heading`, `size`, `icon`, the `actions` slot with `flux:file-item.remove`).

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/InvitationListTest.php` (pure parsing, no application needed):

```php
<?php

declare(strict_types=1);

/** covers §6.6 */

use App\Support\Invitations\InvitationList;

it('reads pasted addresses one per line or separated by commas or semicolons (CD-6)', function (string $text, array $expected): void {
    expect(InvitationList::fromText($text))->toBe($expected);
})->with([
    'one per line' => ["ada@example.com\nbob@example.com", [
        ['email' => 'ada@example.com', 'locale' => null],
        ['email' => 'bob@example.com', 'locale' => null],
    ]],
    'commas, semicolons and Windows line ends' => ["ada@example.com, bob@example.com;cleo@example.com\r\ndan@example.com", [
        ['email' => 'ada@example.com', 'locale' => null],
        ['email' => 'bob@example.com', 'locale' => null],
        ['email' => 'cleo@example.com', 'locale' => null],
        ['email' => 'dan@example.com', 'locale' => null],
    ]],
    'blank lines, spaces, capitals and a BOM' => ["\u{FEFF}  Ada@Example.COM  \n\n \n;,\nBOB@example.com\n", [
        ['email' => 'ada@example.com', 'locale' => null],
        ['email' => 'bob@example.com', 'locale' => null],
    ]],
    'nothing but separators' => [" \n,;\r\n", []],
    'an invalid address is kept for the page to refuse' => ['not-an-address', [['email' => 'not-an-address', 'locale' => null]]],
]);

it('reads a CSV file with or without a header, comma- or semicolon-separated (CD-6)', function (string $contents, array $expected): void {
    expect(InvitationList::fromCsv($contents))->toBe($expected);
})->with([
    'addresses only, no header' => ["ada@example.com\nbob@example.com\n", [
        ['email' => 'ada@example.com', 'locale' => null],
        ['email' => 'bob@example.com', 'locale' => null],
    ]],
    'address and locale, no header' => ["ada@example.com,nl\nbob@example.com,EN\ncleo@example.com,\n", [
        ['email' => 'ada@example.com', 'locale' => 'nl'],
        ['email' => 'bob@example.com', 'locale' => 'en'],
        ['email' => 'cleo@example.com', 'locale' => null],
    ]],
    'a header, semicolons and a BOM, as Excel writes it' => ["\u{FEFF}E-mailadres;Taal\r\nAda@Example.com;nl\r\n\r\nbob@example.com;en\r\n", [
        ['email' => 'ada@example.com', 'locale' => 'nl'],
        ['email' => 'bob@example.com', 'locale' => 'en'],
    ]],
    'a header that puts the columns elsewhere' => ["name,language,email\nAda,nl,ada@example.com\nBob,,bob@example.com\n", [
        ['email' => 'ada@example.com', 'locale' => 'nl'],
        ['email' => 'bob@example.com', 'locale' => null],
    ]],
    'a header without a locale column' => ["email,name\nada@example.com,Ada\n", [
        ['email' => 'ada@example.com', 'locale' => null],
    ]],
    'quoted cells with spaces' => ["\"email\",\"locale\"\n\" ada@example.com \",\" nl \"\n", [
        ['email' => 'ada@example.com', 'locale' => 'nl'],
    ]],
    'rows without an address' => ["email;locale\n;nl\n   \nbob@example.com;en\n", [
        ['email' => 'bob@example.com', 'locale' => 'en'],
    ]],
    'a header that names only the locale column' => ["address;taal\nada@example.com;nl\n", [
        ['email' => 'ada@example.com', 'locale' => 'nl'],
    ]],
    'a mistyped first address is a row, not a header' => ["ada.example.com,nl\nbob@example.com\n", [
        ['email' => 'ada.example.com', 'locale' => 'nl'],
        ['email' => 'bob@example.com', 'locale' => null],
    ]],
    'only a header' => ["email,locale\n", []],
    'empty' => ['', []],
]);

it('keeps every row, so the page can refuse a list longer than the maximum (CD-6)', function (): void {
    $text = implode("\n", array_map(fn (int $index): string => "person{$index}@example.com", range(1, InvitationList::MAX_ROWS + 1)));

    expect(InvitationList::fromText($text))->toHaveCount(101)
        ->and(InvitationList::fromCsv($text))->toHaveCount(101)
        ->and(InvitationList::MAX_ROWS)->toBe(100);
});
```

Create `tests/Feature/Invitations/BulkInvitationsTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §6.6, §4.5, §6.2 */

use App\Authorization\Permission;
use App\Authorization\Role;
use App\Enums\ActivityEvent;
use App\Enums\Locale;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Support\RateLimits\UserActionLimit;
use App\Tenancy\Facades\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

beforeEach(function (): void {
    $this->freezeTime();
});

$templateRoleId = fn (): string => (string) RoleModel::query()->whereNull('team_id')->where('name', Role::OrganisationAdmin->value)->value('id');

// The messages the array mailer delivered (the suite's sync queue; never Mail::fake(), RK-I8).
$deliveredMails = function (): array {
    $transport = Mail::driver()->getSymfonyTransport();
    $mails = [];

    if ($transport instanceof ArrayTransport) {
        foreach ($transport->messages() as $sent) {
            $message = $sent instanceof SentMessage ? $sent->getOriginalMessage() : null;

            if ($message instanceof Email) {
                $mails[] = $message;
            }
        }
    }

    return $mails;
};

/**
 * The bulk page's state after a Livewire update over HTTP.
 *
 * @param  TestResponse<Response>  $response
 * @return array{data: array<string, mixed>, memo: array{errors?: array<string, list<string>>}}
 */
$snapshotOf = function (TestResponse $response): array {
    /** @var array{data: array<string, mixed>, memo: array{errors?: array<string, list<string>>}} $snapshot */
    $snapshot = json_decode((string) $response->assertOk()->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR);

    return $snapshot;
};

it('reviews every row: ready, invalid, listed twice, an unknown language and addresses it cannot invite (CD-6)', function () use ($templateRoleId): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $beta = Organisation::factory()->create(['slug' => 'beta']);
    User::factory()->for($acme)->create(['email' => 'member@example.com']);
    User::factory()->for($beta)->create(['email' => 'elsewhere@example.com']);
    inviteMember($acme, email: 'pending@example.com');
    asOrgAdmin($acme);

    $rows = Livewire::test('pages::invitations.bulk')
        ->set('locale', Locale::English->value)
        ->set('roles', [$templateRoleId()])
        ->set('addresses', "New@Example.com\nnot-an-address\nnew@example.com\nmember@example.com\nelsewhere@example.com\npending@example.com")
        ->set('csv', UploadedFile::fake()->createWithContent('list.csv', "email;locale\nlater@example.com;nl\nodd@example.com;fr\n"))
        ->call('check')
        ->assertHasNoErrors()
        ->get('rows');

    $cannot = __("This address can't be invited.");
    expect($rows)->toBe([
        ['email' => 'new@example.com', 'locale' => 'en', 'reason' => null],
        ['email' => 'not-an-address', 'locale' => 'en', 'reason' => __('Not a valid email address.')],
        ['email' => 'new@example.com', 'locale' => 'en', 'reason' => __('Listed more than once.')],
        // §4.5: the same message for this organisation's member, its open invitation and another organisation's user.
        ['email' => 'member@example.com', 'locale' => 'en', 'reason' => $cannot],
        ['email' => 'elsewhere@example.com', 'locale' => 'en', 'reason' => $cannot],
        ['email' => 'pending@example.com', 'locale' => 'en', 'reason' => $cannot],
        ['email' => 'later@example.com', 'locale' => 'nl', 'reason' => null],
        ['email' => 'odd@example.com', 'locale' => 'fr', 'reason' => __('Unknown language.')],
    ]);
    expect(DB::table('users')->whereIn('email', ['new@example.com', 'later@example.com'])->exists())->toBeFalse()
        ->and(Tenant::run($acme, fn (): int => Invitation::query()->count()))->toBe(1);
});

it('refuses an empty list, a list of more than 100 rows, no role and a file that is not a CSV (CD-6)', function (string $input) use ($templateRoleId): void {
    asOrgAdmin(Organisation::factory()->create(['slug' => 'acme']));
    $page = Livewire::test('pages::invitations.bulk')->set('roles', [$templateRoleId()]);

    match ($input) {
        'nothing' => $page->set('addresses', " \n;"),
        '101 rows' => $page->set('addresses', implode("\n", array_map(fn (int $index): string => "person{$index}@example.com", range(1, 101)))),
        'no role' => $page->set('addresses', 'ada@example.com')->set('roles', []),
        default => $page->set('csv', UploadedFile::fake()->createWithContent('list.pdf', "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n")), // 'not a CSV'
    };

    $page->call('check')
        ->assertHasErrors(match ($input) {
            'nothing' => ['addresses' => __('Paste addresses or choose a CSV file.')],
            '101 rows' => ['addresses' => __('Add at most :max addresses at a time.', ['max' => 100])],
            'no role' => ['roles' => __('Choose at least one role.')],
            default => ['csv' => 'mimes'], // the rule: Livewire reads a message with a colon as a rule and its parameters
        })
        ->assertSet('rows', []);
})->with(['nothing', '101 rows', 'no role', 'not a CSV']);

it('drops the preview when the input changes, so only a checked list is sent (CD-6)', function (string $change) use ($templateRoleId): void {
    asOrgAdmin(Organisation::factory()->create(['slug' => 'acme']));
    $page = Livewire::test('pages::invitations.bulk')
        ->set('roles', [$templateRoleId()])
        ->set('addresses', 'ada@example.com')
        ->call('check')
        ->assertCount('rows', 1);

    match ($change) {
        'addresses' => $page->set('addresses', 'bob@example.com'),
        'file' => $page->set('csv', UploadedFile::fake()->createWithContent('list.csv', "bob@example.com\n")),
        'language' => $page->set('locale', Locale::Dutch->value),
        default => $page->set('roles', []), // 'roles'
    };

    $page->assertSet('rows', [])
        ->call('send')
        ->assertHasErrors(['addresses' => __('Check the list first.')]);
})->with(['addresses', 'file', 'language', 'roles']);

it('sends one queued mail per ready row, in the row\'s language, and summarises the skipped rows (CD-6)', function () use ($templateRoleId, $deliveredMails): void {
    $acme = Organisation::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    User::factory()->for($acme)->create(['email' => 'member@example.com']);
    $admin = asOrgAdmin($acme);

    Livewire::test('pages::invitations.bulk')
        ->set('locale', Locale::English->value)
        ->set('roles', [$templateRoleId()])
        ->set('csv', UploadedFile::fake()->createWithContent('list.csv', "\u{FEFF}ada@example.com;nl\r\nbob@example.com;\r\nmember@example.com;en\r\n"))
        ->call('check')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('rows', [])
        ->assertSet('summary', [
            'sent' => ['ada@example.com', 'bob@example.com'],
            'skipped' => [['email' => 'member@example.com', 'reason' => __("This address can't be invited.")]],
        ])
        ->assertSee(__('Sent: :count', ['count' => 2]))
        ->assertSee(__('Skipped: :count', ['count' => 1]));

    $subjects = collect($deliveredMails())->mapWithKeys(fn (Email $mail): array => [$mail->getTo()[0]->getAddress() => $mail->getSubject()])->all();
    expect($subjects)->toBe([
        'ada@example.com' => 'Je bent uitgenodigd voor Acme',
        'bob@example.com' => 'You have been invited to Acme',
    ])
        ->and(recordedActivity(ActivityEvent::InvitationSent)->pluck('causer_id')->all())->toBe([$admin->id, $admin->id])
        ->and(DB::table('users')->where('email', 'ada@example.com')->value('locale'))->toBe('nl')
        // The bulk limit counts the sent rows; the single-invite limit is not consumed.
        ->and(RateLimiter::attempts(UserActionLimit::bulkInvite()->key($admin)))->toBe(2)
        ->and(RateLimiter::attempts(UserActionLimit::invite()->key($admin)))->toBe(0);
});

it('skips a row the action refuses at send time with the generic message, sends the rest and gives its attempt back (CD-6)', function () use ($templateRoleId, $deliveredMails): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $beta = Organisation::factory()->create(['slug' => 'beta']);
    $admin = asOrgAdmin($acme);
    // A sign-up elsewhere takes the address between the review and the insert: InviteUser maps the unique violation.
    User::creating(function (User $user) use ($beta): void {
        if ($user->email === 'raced@example.com') {
            DB::table('users')->insert([
                'organisation_id' => $beta->id, 'name' => 'Racer', 'email' => 'raced@example.com', 'password' => 'x',
                'status' => 'active', 'locale' => 'en', 'is_super_admin' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    Livewire::test('pages::invitations.bulk')
        ->set('roles', [$templateRoleId()])
        ->set('addresses', 'ada@example.com, raced@example.com, cleo@example.com')
        ->call('check')
        ->assertSet('rows.1.reason', null)
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('summary', [
            'sent' => ['ada@example.com', 'cleo@example.com'],
            'skipped' => [['email' => 'raced@example.com', 'reason' => __("This address can't be invited.")]],
        ]);

    expect(collect($deliveredMails())->map(fn (Email $mail): string => $mail->getTo()[0]->getAddress())->all())->toBe(['ada@example.com', 'cleo@example.com'])
        ->and(RateLimiter::attempts(UserActionLimit::bulkInvite()->key($admin)))->toBe(2);
});

it('reviews the rows again when it sends and skips an organisation archived meanwhile with the action\'s message (CD-6)', function () use ($templateRoleId, $deliveredMails): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($acme);
    $page = Livewire::test('pages::invitations.bulk')
        ->set('roles', [$templateRoleId()])
        ->set('addresses', "ada@example.com\nbob@example.com")
        ->call('check');

    // Bob signed up in the meantime; then the organisation was archived.
    User::factory()->for($acme)->create(['email' => 'bob@example.com']);
    DB::table('organisations')->where('id', $acme->id)->update(['deleted_at' => now()]);

    $page->call('send')->assertSet('summary', [
        'sent' => [],
        'skipped' => [
            ['email' => 'bob@example.com', 'reason' => __("This address can't be invited.")],
            ['email' => 'ada@example.com', 'reason' => __('This organisation is archived.')],
        ],
    ]);

    expect($deliveredMails())->toBe([])
        ->and(RateLimiter::attempts(UserActionLimit::bulkInvite()->key($admin)))->toBe(0);
});

it('refuses a list larger than what is left of the bulk limit and sends nothing (CD-6)', function () use ($templateRoleId, $deliveredMails): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($acme);
    UserActionLimit::bulkInvite()->hit($admin, cost: 98);

    $page = Livewire::test('pages::invitations.bulk')
        ->set('roles', [$templateRoleId()])
        ->set('addresses', 'ada@example.com, bob@example.com, cleo@example.com')
        ->call('check')
        ->call('send')
        ->assertHasErrors(['addresses' => __('Too many attempts. Try again in :seconds seconds.', ['seconds' => 3600])])
        ->assertSet('summary', null);

    expect($deliveredMails())->toBe([])
        ->and(DB::table('users')->where('email', 'ada@example.com')->exists())->toBeFalse()
        // A refused list costs nothing, so a smaller one still fits.
        ->and(RateLimiter::attempts(UserActionLimit::bulkInvite()->key($admin)))->toBe(98);

    $page->set('addresses', 'ada@example.com, bob@example.com')->call('check')->call('send')->assertHasNoErrors();

    expect($deliveredMails())->toHaveCount(2)
        ->and(RateLimiter::attempts(UserActionLimit::bulkInvite()->key($admin)))->toBe(100);
});

it('sends only the rows the server checked, whatever a forged update says (CD-6)', function () use ($templateRoleId, $snapshotOf, $deliveredMails): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $beta = Organisation::factory()->create(['slug' => 'beta']);
    User::factory()->for($beta)->create(['email' => 'elsewhere@example.com']);
    asOrgAdmin($acme);
    $snapshot = pageSnapshot(route('invitations.bulk'), 'pages::invitations.bulk');

    $checked = $snapshotOf(callPageAction($snapshot, 'check', updates: ['addresses' => 'ada@example.com, elsewhere@example.com', 'roles' => [$templateRoleId()]]));
    expect($checked['memo']['errors'] ?? [])->toBe([]);
    $this->withoutExceptionHandling();

    // The preview cannot be written by the client: not before a check, and not after one.
    expect(fn (): TestResponse => callPageAction($snapshot, 'send', updates: ['rows' => [['email' => 'elsewhere@example.com', 'locale' => 'en', 'reason' => null]]]))
        ->toThrow(CannotUpdateLockedPropertyException::class)
        ->and(fn (): TestResponse => callPageAction($checked, 'send', updates: ['rows.1.reason' => null]))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect($deliveredMails())->toBe([])
        ->and(DB::table('users')->where('email', 'ada@example.com')->exists())->toBeFalse();
});

it('serves organisation admins on their host and refuses members (§6.2)', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    asOrgAdmin($acme);

    $this->get(route('invitations.bulk'))->assertOk()->assertSee(__('Invite several people'))->assertDontSee('data-test="bulk-organisation"', false);

    asMember($acme);
    $this->get(route('invitations.bulk'))->assertForbidden();

    // users.create without roles.assign may not invite (OR-M7).
    $creator = User::factory()->for($acme)->create();
    $role = RoleModel::query()->create(['name' => 'creator', 'guard_name' => 'web', 'team_id' => $acme->id]);
    $role->givePermissionTo([Permission::UsersView->value, Permission::UsersCreate->value]);
    withPermissionsTeam($acme->id, fn () => $creator->assignRole($role));
    $this->actingAs($creator);
    $this->get(route('invitations.bulk'))->assertForbidden();
});

it('lets a super admin on a central host invite after picking an organisation (§6.2, INV-17)', function () use ($templateRoleId): void {
    $acme = Organisation::factory()->create(['name' => 'Acme Corp', 'slug' => 'acme']);
    asSuperAdmin();
    onAdminHost();

    $this->get(route('invitations.bulk'))->assertOk()->assertSee('data-test="bulk-organisation"', false)->assertSee('Acme Corp');

    $page = Livewire::test('pages::invitations.bulk')
        ->set('addresses', 'first@example.com')
        ->set('roles', [$templateRoleId()])
        ->call('check')
        ->assertHasErrors(['organisationId' => __('Choose an organisation first.')]);

    // A new pick clears the roles and the preview.
    $page->set('organisationId', $acme->id)
        ->assertSet('roles', [])
        ->set('roles', [$templateRoleId()])
        ->call('check')
        ->call('send')
        ->assertHasNoErrors();

    expect(Tenant::run($acme, fn (): int => Invitation::query()->count()))->toBe(1);
});

it('refuses a signed-in user who is not a super admin on a central host (§6.2)', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    asOrgAdmin($acme);
    onApex();

    // Over HTTP the membership middleware signs them out first; the page refuses them itself too.
    $this->get(route('invitations.bulk'))->assertRedirect(route('login'));

    asOrgAdmin($acme);
    onApex();
    Livewire::test('pages::invitations.bulk')->assertForbidden();
});

it('ignores a forged organisation id on a tenant host', function () use ($templateRoleId): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $beta = Organisation::factory()->create(['slug' => 'beta']);
    asOrgAdmin($acme);

    Livewire::test('pages::invitations.bulk')
        ->set('organisationId', $beta->id)
        ->set('roles', [$templateRoleId()])
        ->set('addresses', 'someone@example.com')
        ->call('check')
        ->call('send')
        ->assertHasNoErrors();

    expect(DB::table('invitations')->where('organisation_id', $acme->id)->count())->toBe(1)
        ->and(DB::table('invitations')->where('organisation_id', $beta->id)->count())->toBe(0);
});

it('offers "Invite several people" on the pending list only to users who may invite (OR-M7)', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    asOrgAdmin($acme);

    $this->get(route('invitations.pending'))->assertOk()
        ->assertSee('data-test="bulk-invite-button"', false)
        ->assertSee('href="'.route('invitations.bulk').'"', false);

    $viewer = User::factory()->for($acme)->create();
    $role = RoleModel::query()->create(['name' => 'viewer', 'guard_name' => 'web', 'team_id' => $acme->id]);
    $role->givePermissionTo([Permission::UsersView->value, Permission::UsersCreate->value]);
    withPermissionsTeam($acme->id, fn () => $viewer->assignRole($role));
    $this->actingAs($viewer);

    $this->get(route('invitations.pending'))->assertOk()->assertDontSee('data-test="bulk-invite-button"', false);
});
```

Notes on the tests:
- The suite runs with `QUEUE_CONNECTION=sync` and `MAIL_MAILER=array` (`phpunit.xml`), so `$deliveredMails()` reads what the queued `InvitationMail`s actually delivered after commit; never `Mail::fake()` (RK-I8). The subjects are the ones `InvitationMailTest` pins for `nl` and `en`.
- 'skips a row the action refuses at send time…' simulates a concurrent sign-up: a `User::creating` listener inserts the address for another organisation just before `InviteUser` inserts its pending user, so the insert hits `users_email_unique` and `InviteUser` maps the `UniqueConstraintViolationException` to an `email` error. The listener's insert runs inside `InviteUser`'s transaction and is rolled back with it.
- 'sends only the rows the server checked…' goes over HTTP (`pageSnapshot()` + `callPageAction()`), as a forged request would; `withoutExceptionHandling()` lets `toThrow()` see Livewire's `CannotUpdateLockedPropertyException`, before the check and after it.
- 'refuses a signed-in user who is not a super admin on a central host' checks both layers: over HTTP the membership middleware signs the organisation admin out (the matrix pins the same); `Livewire::test()` skips middleware and reaches `mount()`, which answers 403.
- The `'not a CSV'` row asserts the rule name `mimes`: Livewire's `assertHasErrors()` reads an expected message that contains a colon as a rule with parameters, and the `mimes` message does ("…of type: csv, txt.").

`tests/Feature/Tenancy/RouteHostMatrixTest.php` (Task 4's version): in `matrixRoutes()`, insert `'invitations.bulk'` directly after `'users.invite'` on its line, so the line reads

```php
        'users.invite', 'invitations.bulk', 'invitations.pending', 'organisations.index', 'organisations.create', 'organisations.edit',
```

In `routeMatrixOutcome()`'s member list, insert it after `'users.invite'` on its line, so the line reads

```php
            'users.index', 'users.edit', 'users.invite', 'invitations.bulk', 'invitations.pending', 'organisations.index',
```

The next lines, which Task 4 changed for `activity.export`, stay as Task 4 left them. `invitations.bulk` behaves like `users.invite` everywhere: `login` on a foreign host or for a guest, `forbidden` for the member, `ok` for an organisation admin on the tenant host and for a super admin on every host.

`tests/Feature/FluxPro/FreeVariantsTest.php`: in 'renders the free variants when Flux Pro is absent', after the `users.invite` line, add

```php
    $this->get(route('invitations.bulk'))->assertOk()->assertSee('type="file"', false)->assertSee('wire:model="csv"', false)->assertSee('data-flux-checkbox-group', false);
```

`tests/Feature/FluxPro/ProVariantsTest.php`: in 'renders the Pro variants when Flux Pro is installed', after the `users.invite` line, add

```php
    $this->get(route('invitations.bulk'))->assertOk()->assertSee('data-flux-file-upload', false)->assertSee('listbox', false);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Unit/InvitationListTest.php tests/Feature/Invitations/BulkInvitationsTest.php tests/Feature/Tenancy/RouteHostMatrixTest.php tests/Feature/FluxPro`

Expected: FAIL, as follows:
- All 17 rows of `InvitationListTest`: `Class "App\Support\Invitations\InvitationList" not found`.
- Every `BulkInvitationsTest` test that starts with `Livewire::test()` (13 rows): `Unable to find component: [pages::invitations.bulk]`. 'refuses a list larger than what is left of the bulk limit…' fails first on `Call to undefined method App\Support\RateLimits\UserActionLimit::bulkInvite()`.
- The `BulkInvitationsTest` tests that start over HTTP (the forged update, the tenant and central host rules, the central refusal, the pending button) and the `FreeVariantsTest` test: `Route [invitations.bulk] not defined.`
- The 12 `invitations.bulk` rows of 'answers every route on every host for every role': `Route [invitations.bulk] not defined.`
- `ProVariantsTest` stays skipped without Pro.

- [ ] **Step 3: The parser**

Create `app/Support/Invitations/InvitationList.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Invitations;

/**
 * Turns the bulk invitation input into rows (§6.6, CD-6): pasted text (addresses one per line, or separated by
 * commas or semicolons) and a CSV file (an email column and an optional locale column, with or without a header
 * row, comma- or semicolon-separated). A UTF-8 BOM is stripped, blank lines and cells are skipped, and every value
 * is trimmed and lower-cased. The content is only read as text, never included or evaluated. Nothing is validated
 * here: the page checks each row, and refuses a list longer than MAX_ROWS.
 *
 * @phpstan-type InvitationRow array{email: string, locale: string|null}
 */
final class InvitationList
{
    /** The most rows one batch may hold (§6.6, CD-6). */
    public const int MAX_ROWS = 100;

    private const string BOM = "\u{FEFF}";

    /** Header cells that name the locale column; the email column is the first header cell that contains "mail". */
    private const array LOCALE_HEADERS = ['locale', 'language', 'lang', 'taal'];

    /**
     * Pasted addresses; a pasted list carries no locale.
     *
     * @return list<InvitationRow>
     */
    public static function fromText(string $text): array
    {
        $rows = [];

        foreach (preg_split('/[\r\n,;]+/', self::withoutBom($text)) ?: [] as $value) {
            $email = self::clean($value);

            if ($email !== '') {
                $rows[] = ['email' => $email, 'locale' => null];
            }
        }

        return $rows;
    }

    /**
     * A CSV file's contents. The delimiter is the one the first line uses most (a semicolon, as Excel writes in
     * Dutch, or a comma). The first line is a header when no cell of it holds an "@" and one names a column (see
     * header()); the header then decides which columns hold the address and the locale. Without a header, the first
     * column is the address and the second the locale, so a first row with a mistyped address is kept and refused
     * by the page instead of dropped as a header.
     *
     * @return list<InvitationRow>
     */
    public static function fromCsv(string $contents): array
    {
        $lines = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', self::withoutBom($contents)) ?: [],
            fn (string $line): bool => trim($line) !== '',
        ));

        if ($lines === []) {
            return [];
        }

        $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $records = array_map(fn (string $line): array => str_getcsv($line, $delimiter, '"', ''), $lines);
        $header = self::header($records[0]);

        if ($header !== null) {
            array_shift($records);
        }

        [$emailColumn, $localeColumn] = $header ?? [0, 1];

        $rows = [];

        foreach ($records as $record) {
            $email = self::clean((string) ($record[$emailColumn] ?? ''));

            if ($email === '') {
                continue;
            }

            $locale = $localeColumn === null ? '' : self::clean((string) ($record[$localeColumn] ?? ''));
            $rows[] = ['email' => $email, 'locale' => $locale === '' ? null : $locale];
        }

        return $rows;
    }

    /**
     * The columns a header row names, or null when the record is not a header: it holds an "@", or none of its cells
     * names a column. The email column is the first cell that contains "mail" (email, e-mail, e-mailadres …) and
     * defaults to the first column; a header that names no locale column means the file has none.
     *
     * @param  array<int, string|null>  $record
     * @return array{0: int, 1: int|null}|null
     */
    private static function header(array $record): ?array
    {
        $names = array_map(fn (?string $cell): string => self::clean((string) $cell), $record);
        $email = null;
        $locale = null;

        foreach ($names as $index => $name) {
            if (str_contains($name, '@')) {
                return null;
            }

            if ($email === null && str_contains($name, 'mail')) {
                $email = $index;
            } elseif ($locale === null && in_array($name, self::LOCALE_HEADERS, true)) {
                $locale = $index;
            }
        }

        return $email === null && $locale === null ? null : [$email ?? 0, $locale];
    }

    private static function clean(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private static function withoutBom(string $text): string
    {
        return str_starts_with($text, self::BOM) ? substr($text, strlen(self::BOM)) : $text;
    }
}
```

`str_getcsv()`'s fourth argument (`escape: ''`) is explicit: PHP 8.4 deprecates relying on its default.

- [ ] **Step 4: The bulk limit**

`app/Support/RateLimits/UserActionLimit.php` (Task 1's version). Insert this factory directly after the constructor, before Task 1's `logoutOtherDevices()` (M7d's factories sit right after the constructor in alphabetical order; Task 7 later inserts `addressChange()` before this one):

```php
    /**
     * Send a checked list on the bulk invitation page (CD-6): 100 invitations per hour, reserved for every ready row
     * before the first send; the rows InviteUser refuses are given back. The invite() limit is not consumed.
     */
    public static function bulkInvite(): self
    {
        return new self('bulk-invite', 100, 3600);
    }
```

- [ ] **Step 5: `InviteUser`'s address rules**

`app/Actions/Invitations/InviteUser.php`: add the imports `use Illuminate\Contracts\Validation\ValidationRule;` (after `use Illuminate\Auth\Access\AuthorizationException;`) and `use Stringable;` (after `use Spatie\Permission\Models\Role as RoleModel;`).

In the class docblock, replace

```php
 * are restored on failure (W11). Bulk invitations (M7) reuse this action.
```

with

```php
 * are restored on failure (W11). Bulk invitations reuse this action and its address rules (CD-6).
```

Add this public method right after `handle()`, before the private `invite()`:

```php
    /**
     * The rules an address must pass to be invited into $organisation (W9). The bulk invitation page checks its rows
     * with them before it sends, and shows every failure as one generic message (§4.5, CD-6).
     *
     * @return array<int, ValidationRule|Stringable|array<mixed>|string>
     */
    public function addressRules(Organisation $organisation): array
    {
        return $this->emailRules(organisationId: $organisation->id);
    }
```

In `invite()`, replace

```php
                'email' => $this->emailRules(organisationId: $organisation->id),
```

with

```php
                'email' => $this->addressRules($organisation),
```

- [ ] **Step 6: The shared invite-page trait, and `users.invite` on it**

Create `app/Concerns/ChoosesInvitationOrganisation.php`:

```php
<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Authorization\Permission;
use App\Authorization\Role;
use App\Authorization\SyncPermissions;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Tenancy\Facades\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * The organisation and roles of the invite pages, users.invite and invitations.bulk (§4.5, §6.2): a tenant host
 * invites into its own organisation, a central host lets a super admin pick one (INV-17). The page declares
 * `public ?int $organisationId` and `public array $roles`.
 *
 * @phpstan-require-extends Component
 */
trait ChoosesInvitationOrganisation
{
    use ResolvesCurrentUser;

    /** Roles belong to one organisation, so a new pick clears them. */
    public function updatedOrganisationId(): void
    {
        $this->roles = [];
    }

    /** The pending list needs users.view; an inviter without it goes back to the dashboard instead of a 403. */
    #[Computed]
    public function returnRoute(): string
    {
        return $this->currentUser()->can(Permission::UsersView) ? 'invitations.pending' : 'dashboard';
    }

    /** @return array<int, string> Active organisations, for the central picker only. */
    #[Computed]
    public function organisationOptions(): array
    {
        if (Tenant::check()) {
            return [];
        }

        return Organisation::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(fn (mixed $name, mixed $id): array => [(int) $id => (string) $name])
            ->all();
    }

    /** @return array<int, string> The organisation_admin template and the team roles of the host's or the picked organisation (RK-I4). */
    #[Computed]
    public function roleOptions(): array
    {
        $current = Tenant::current();
        $teamId = $current instanceof Organisation ? $current->id : $this->pickedOrganisationId();

        if ($teamId === null) {
            return [];
        }

        return RoleModel::query()
            ->where('guard_name', SyncPermissions::GUARD)
            ->where(function (Builder $query) use ($teamId): void {
                $query->where('team_id', $teamId)
                    ->orWhere(fn (Builder $template): Builder => $template->whereNull('team_id')->where('name', Role::OrganisationAdmin->value));
            })
            ->orderBy('name')
            ->get(['id', 'name', 'team_id'])
            ->mapWithKeys(fn (RoleModel $role): array => [
                (int) $role->id => $role->team_id === null ? Role::OrganisationAdmin->label() : (string) $role->name,
            ])
            ->all();
    }

    /** Tenant host: users.create and roles.assign in this organisation (OR-M7); central hosts: super admins only. */
    protected function authorizeInvitePage(): void
    {
        $organisation = Tenant::current();

        if ($organisation instanceof Organisation) {
            $this->authorize('create', [Invitation::class, $organisation]);
        } else {
            abort_unless($this->currentUser()->isSuperAdmin(), 403);
        }
    }

    /**
     * The host's organisation, or the central pick, authorised for inviting. Archived picks are passed on on
     * purpose: InviteUser refuses them under its own lock (RK-I11). Super admins pass through Gate::before
     * ('create' is not always checked).
     *
     * @throws ValidationException
     */
    protected function organisationToInvite(): Organisation
    {
        $current = Tenant::current();

        if ($current instanceof Organisation) {
            $organisation = $current;
        } else {
            $organisationId = $this->pickedOrganisationId();
            $organisation = ($organisationId === null ? null : Organisation::withTrashed()->find($organisationId))
                ?? throw ValidationException::withMessages(['organisationId' => __('Choose an organisation first.')]);
        }

        $this->authorize('create', [Invitation::class, $organisation]);

        return $organisation;
    }

    /** Livewire unsets a typed int property that receives an empty string, so read it null-safely. */
    protected function pickedOrganisationId(): ?int
    {
        return $this->organisationId ?? null;
    }
}
```

The methods are `users.invite`'s, moved unchanged, with two differences: `mount()`'s body is now `authorizeInvitePage()`, and `organisationToInvite()` authorises the organisation it returns (the call that followed it in `invite()`), so no caller can forget it.

Replace `resources/views/pages/users/invite/invite.php` with:

```php
<?php

declare(strict_types=1);

use App\Actions\Invitations\InviteUser;
use App\Concerns\ChoosesInvitationOrganisation;
use App\Enums\Locale;
use App\Models\Invitation;
use App\Support\RateLimits\UserActionLimit;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Invite a user by email with a language and at least one role (§4.5). A tenant host invites into its own
 * organisation; on a central host a super admin picks the organisation first (INV-17). InviteUser
 * authorises, locks the organisation and validates the address and the roles; this page only collects
 * the input.
 */
new #[Title('Invite user')] class extends Component
{
    use ChoosesInvitationOrganisation;

    public string $email = '';

    public string $locale = '';

    /** @var list<int|string> Checkbox values arrive as strings. */
    public array $roles = [];

    /** The organisation picked on a central host; a tenant host always uses its own organisation (RK-I4). */
    public ?int $organisationId = null;

    public function mount(): void
    {
        $this->authorizeInvitePage();

        $this->locale = Locale::default()->value;
    }

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
};
```

`invite.blade.php` is unchanged: it reads `$this->organisationOptions`, `$this->roleOptions` and `$this->returnRoute`, which the trait provides.

- [ ] **Step 7: The page and its route**

Create `resources/views/pages/invitations/bulk/bulk.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\Invitations\InviteUser;
use App\Concerns\ChoosesInvitationOrganisation;
use App\Enums\Locale;
use App\Models\Organisation;
use App\Support\Invitations\InvitationList;
use App\Support\RateLimits\UserActionLimit;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Invite several people at once (§6.6, §4.5, CD-6): paste addresses or upload a CSV file, choose the roles and the
 * default language once, "Check" the list, then "Send" it. Authorised as users.invite: on a tenant host for the
 * host's organisation, on a central host super admins only, with an organisation picker.
 *
 * "Check" parses the input with InvitationList and reviews every row: the address format, a duplicate in the list,
 * an unknown language and InviteUser's address rules. An address InviteUser would refuse gets one generic message,
 * whatever the reason, so the list never tells which addresses exist elsewhere (§4.5). The preview is #[Locked]:
 * "Send" sends only the rows the server checked, and reviews them again first. Each ready row reserves one attempt of
 * the bulk limit before the first send (UserActionLimit::bulkInvite()); the rows InviteUser refuses are given back.
 */
new #[Title('Invite several people')] class extends Component
{
    use ChoosesInvitationOrganisation, WithFileUploads;

    /** Pasted addresses: one per line, or separated by commas or semicolons. */
    public string $addresses = '';

    /** A CSV file with an email column and an optional locale column. */
    public ?TemporaryUploadedFile $csv = null;

    /** The language of every row that names none. */
    public string $locale = '';

    /** @var list<int|string> Checkbox values arrive as strings. */
    public array $roles = [];

    /** The organisation picked on a central host; a tenant host always uses its own organisation (RK-I4). */
    public ?int $organisationId = null;

    /** @var list<array{email: string, locale: string, reason: string|null}> The checked rows; the client never changes them. */
    #[Locked]
    public array $rows = [];

    /** @var array{sent: list<string>, skipped: list<array{email: string, reason: string}>}|null The outcome of the last send. */
    #[Locked]
    public ?array $summary = null;

    public function mount(): void
    {
        $this->authorizeInvitePage();

        $this->locale = Locale::default()->value;
    }

    /** A changed input makes the preview stale: it is checked again before anything is sent. */
    public function updated(string $property): void
    {
        if (in_array($property, ['addresses', 'csv', 'locale', 'organisationId'], true) || str_starts_with($property, 'roles')) {
            $this->rows = [];
        }
    }

    public function removeCsv(): void
    {
        $this->csv = null;
        $this->rows = [];
    }

    /** Parses the input and reviews every row; nothing is written or sent. */
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

        $this->rows = $this->review($entries, $organisation, $inviteUser);
    }

    /**
     * Sends the checked rows that are still ready, one InviteUser call each. A row InviteUser refuses is skipped with
     * its message and gives its reserved attempt back.
     */
    public function send(InviteUser $inviteUser): void
    {
        if ($this->rows === []) {
            throw ValidationException::withMessages(['addresses' => __('Check the list first.')]);
        }

        $this->validateBatch();
        $organisation = $this->organisationToInvite();
        $actor = $this->currentUser();
        $roleIds = array_map(fn (int|string $roleId): int => (int) $roleId, $this->roles);

        // Reviewed again: the list may have changed since it was checked, by this page or another.
        $rows = $this->review($this->rows, $organisation, $inviteUser);
        $ready = [];
        $skipped = [];

        foreach ($rows as $row) {
            if ($row['reason'] === null) {
                $ready[] = $row;
            } else {
                $skipped[] = ['email' => $row['email'], 'reason' => $row['reason']];
            }
        }

        if ($ready === []) {
            $this->rows = $rows;

            throw ValidationException::withMessages(['addresses' => __('No address in this list can be invited.')]);
        }

        // A list larger than what is left of the window is refused before it counts, so a smaller one still fits;
        // reserve() then counts atomically, and refuses a parallel send that took the room meanwhile.
        $limit = UserActionLimit::bulkInvite();
        $limit->ensureAvailable($actor, 'addresses', count($ready));
        $limit->reserve($actor, 'addresses', count($ready));
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
            // Refused rows, and the rows a failure stopped before they were sent, give their attempts back.
            $unused = count($ready) - count($sent);

            if ($unused > 0) {
                $limit->release($actor, $unused);
            }
        }

        $this->rows = [];
        $this->addresses = '';
        $this->csv = null;
        $this->summary = ['sent' => $sent, 'skipped' => $skipped];
    }

    /** The preview's rows that "Send" would send. */
    #[Computed]
    public function readyCount(): int
    {
        return count(array_filter($this->rows, fn (array $row): bool => $row['reason'] === null));
    }

    /** Back to an empty form after a summary. */
    public function startOver(): void
    {
        $this->summary = null;
    }

    /**
     * The batch's language and roles; roles are required as InviteUser requires them, so the preview is not all
     * "Ready" for a list that cannot be sent.
     *
     * @throws ValidationException
     */
    private function validateBatch(): void
    {
        $this->validate([
            'locale' => ['required', 'string', Rule::enum(Locale::class)],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['integer'],
        ], [
            'roles.required' => __('Choose at least one role.'),
            'roles.min' => __('Choose at least one role.'),
        ]);
    }

    /**
     * Each row with "Ready" (reason null) or the reason it is skipped. A row without a language gets the batch's.
     *
     * @param  list<array{email: string, locale: string|null, reason?: string|null}>  $entries
     * @return list<array{email: string, locale: string, reason: string|null}>
     */
    private function review(array $entries, Organisation $organisation, InviteUser $inviteUser): array
    {
        $addressRules = $inviteUser->addressRules($organisation);
        $seen = [];
        $rows = [];

        foreach ($entries as $entry) {
            $email = $entry['email'];
            $locale = $entry['locale'] ?? $this->locale;
            $rows[] = ['email' => $email, 'locale' => $locale, 'reason' => $this->skipReason($email, $locale, isset($seen[$email]), $addressRules)];
            $seen[$email] = true;
        }

        return $rows;
    }

    /**
     * Why a row is skipped, or null when it is ready. Every refusal of InviteUser's address rules is one generic
     * message: the list never tells whether an address is a member here, invited here or used elsewhere (§4.5).
     *
     * @param  array<int, mixed>  $addressRules
     */
    private function skipReason(string $email, string $locale, bool $listedBefore, array $addressRules): ?string
    {
        if (Validator::make(['email' => $email], ['email' => ['required', 'string', 'email', 'max:255']])->fails()) {
            return __('Not a valid email address.');
        }

        if ($listedBefore) {
            return __('Listed more than once.');
        }

        if (! Locale::tryFrom($locale) instanceof Locale) {
            return __('Unknown language.');
        }

        if (Validator::make(['email' => $email], ['email' => $addressRules])->fails()) {
            return __("This address can't be invited.");
        }

        return null;
    }

    /**
     * InviteUser's refusal of one row. An address refusal is the generic message, as in the preview (§4.5); any other
     * (an organisation archived meanwhile, a role that is gone) is the action's own message.
     */
    private function reasonFor(ValidationException $exception): string
    {
        $errors = $exception->errors();
        $message = Arr::first(Arr::flatten($errors));

        return isset($errors['email']) || ! is_string($message) ? __("This address can't be invited.") : $message;
    }
};
```

`skipReason()` uses early returns instead of a `match`: Larastan types `__()` as a benevolent `array|string`, which a `string` return accepts but a `string|null` union built by `match` does not.

Create `resources/views/pages/invitations/bulk/bulk.blade.php`:

```blade
<section class="w-full max-w-3xl">
    <flux:heading size="xl" level="1">{{ __('Invite several people') }}</flux:heading>
    <flux:subheading size="lg" class="mb-6">{{ __('Paste addresses or upload a CSV file, check the list, then send the invitations.') }}</flux:subheading>
    <flux:separator variant="subtle" />

    @if ($summary !== null)
        <div class="my-6 space-y-6" data-test="bulk-summary">
            <flux:callout icon="paper-airplane" variant="success" :heading="__('Sent: :count', ['count' => count($summary['sent'])])" />

            @if ($summary['skipped'] !== [])
                <div class="space-y-2">
                    <flux:heading size="lg">{{ __('Skipped: :count', ['count' => count($summary['skipped'])]) }}</flux:heading>
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Email address') }}</flux:table.column>
                            <flux:table.column>{{ __('Reason') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($summary['skipped'] as $skipped)
                                <flux:table.row :key="'skipped-'.$loop->index">
                                    <flux:table.cell variant="strong">{{ $skipped['email'] }}</flux:table.cell>
                                    <flux:table.cell>{{ $skipped['reason'] }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif

            <div class="flex items-center gap-4">
                <flux:button variant="primary" wire:click="startOver" data-test="bulk-start-over">{{ __('Invite more people') }}</flux:button>
                <flux:button variant="ghost" :href="route($this->returnRoute)" wire:navigate>{{ __('Done') }}</flux:button>
            </div>
        </div>
    @else
        <form wire:submit="check" class="my-6 w-full space-y-6">
            @unless (\App\Tenancy\Facades\Tenant::check())
                <div class="space-y-2">
                    <flux:select wire:model.live="organisationId" :label="__('Organisation')" :placeholder="__('Choose an organisation')" data-test="bulk-organisation">
                        @foreach ($this->organisationOptions as $id => $name)
                            <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="organisation" />
                </div>
            @endunless

            <flux:textarea
                wire:model="addresses"
                :label="__('Email addresses')"
                :description="__('One per line, or separated by commas or semicolons.')"
                rows="6"
                data-test="bulk-addresses"
            />

            @if (Skv::hasFluxPro())
                <div class="space-y-3">
                    <flux:file-upload wire:model="csv" :label="__('CSV file')" :description="__('An email column and an optional locale column (nl or en), with or without a header row; at most 512 KB.')">
                        <flux:file-upload.dropzone :heading="__('Drop a CSV file here or click to browse')" :text="__('CSV or text, up to 512 KB')" inline />
                    </flux:file-upload>

                    @if ($csv)
                        <flux:file-item :heading="$csv->getClientOriginalName()" :size="$csv->getSize()" icon="document-text">
                            <x-slot name="actions">
                                <flux:file-item.remove wire:click="removeCsv" :aria-label="__('Remove file')" />
                            </x-slot>
                        </flux:file-item>
                    @endif
                </div>
            @else
                <flux:input
                    type="file"
                    wire:model="csv"
                    accept=".csv,.txt,text/csv,text/plain"
                    :label="__('CSV file')"
                    :description="__('An email column and an optional locale column (nl or en), with or without a header row; at most 512 KB.')"
                    data-test="bulk-csv"
                />
            @endif

            <flux:select wire:model="locale" :label="__('Language')" :description="__('For every address that names no language.')">
                @foreach (\App\Enums\Locale::cases() as $option)
                    <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if (Skv::hasFluxPro())
                <flux:select variant="listbox" multiple searchable indicator="checkbox" wire:model="roles" :label="__('Roles')" data-test="bulk-roles">
                    @foreach ($this->roleOptions as $id => $label)
                        <flux:select.option :value="(string) $id">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            @else
                <flux:checkbox.group wire:model="roles" :label="__('Roles')" data-test="bulk-roles">
                    @foreach ($this->roleOptions as $id => $label)
                        <flux:checkbox :value="$id" :label="$label" />
                    @endforeach
                </flux:checkbox.group>
            @endif

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit" data-test="bulk-check">{{ __('Check the list') }}</flux:button>
                <flux:button variant="ghost" :href="route($this->returnRoute)" wire:navigate>{{ __('Cancel') }}</flux:button>
            </div>
        </form>

        @if ($rows !== [])
            <div class="space-y-4" data-test="bulk-preview">
                <flux:heading size="lg">{{ __('Ready: :ready of :total', ['ready' => $this->readyCount, 'total' => count($rows)]) }}</flux:heading>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Email address') }}</flux:table.column>
                        <flux:table.column>{{ __('Language') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($rows as $row)
                            <flux:table.row :key="'row-'.$loop->index">
                                <flux:table.cell variant="strong">{{ $row['email'] }}</flux:table.cell>
                                <flux:table.cell>{{ \App\Enums\Locale::tryFrom($row['locale'])?->label() ?? $row['locale'] }}</flux:table.cell>
                                <flux:table.cell>
                                    @if ($row['reason'] === null)
                                        <flux:badge size="sm" color="green">{{ __('Ready') }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="zinc">{{ $row['reason'] }}</flux:badge>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>

                <flux:button variant="primary" icon="paper-airplane" wire:click="send" :disabled="$this->readyCount === 0" data-test="bulk-send">
                    {{ __('Send invitations (:count)', ['count' => $this->readyCount]) }}
                </flux:button>
            </div>
        @endif
    @endif
</section>
```

Both `@if (Skv::hasFluxPro())` blocks have a complete free `@else` that binds the same property (`csv`, `roles`), as `FluxProLayerTest` requires. `$csv` is a public property, so the Pro branch reads it as `$csv`.

`routes/admin.php` (Task 3's version): directly after the `users.invite` route, inside the `auth` group, add

```php
    // §6.6, §6.2: any host, authorised as users.invite; on a central host a super admin picks the organisation.
    Route::livewire('invitations/bulk', 'pages::invitations.bulk')
        ->can(Permission::UsersCreate)
        ->name('invitations.bulk');
```

The route sits before `invitations/pending` and outside `Route::tenant()`/`Route::central()`: it answers on every host, like `users.invite` (§6.2 :351). `EnsureSecondFactor` (Task 3) applies to it through the group.

- [ ] **Step 8: The button on the pending list**

`resources/views/pages/invitations/pending/pending.blade.php`: replace

```blade
        @if ($this->canInvite)
            <flux:button variant="primary" icon="envelope" :href="route('users.invite')" wire:navigate data-test="invite-user-button">
                {{ __('Invite user') }}
            </flux:button>
        @endif
```

with

```blade
        @if ($this->canInvite)
            <div class="flex flex-wrap gap-2">
                <flux:button variant="filled" icon="users" :href="route('invitations.bulk')" wire:navigate data-test="bulk-invite-button">
                    {{ __('Invite several people') }}
                </flux:button>
                <flux:button variant="primary" icon="envelope" :href="route('users.invite')" wire:navigate data-test="invite-user-button">
                    {{ __('Invite user') }}
                </flux:button>
            </div>
        @endif
```

`canInvite` is the invite button's existing rule (tenant host: `create` on `Invitation` for the host's organisation; central: super admins), so both buttons appear for the same people. There is no navigation item (CD-6).

- [ ] **Step 9: Copy and CHANGELOG**

Lang keys, in both files, at their sorted positions (PHP `sort()` order). The "after" column names the neighbour on `main`; keys Tasks 1–4 added may sit in between, so sort, do not anchor.

| Key | nl | After (on `main`) |
|---|---|---|
| `Add at most :max addresses at a time.` | `Voeg maximaal :max adressen tegelijk toe.` | `Add a passkey to sign in without a password` |
| `An email column and an optional locale column (nl or en), with or without a header row; at most 512 KB.` | `Een kolom met e-mailadressen en eventueel een kolom met de taal (nl of en), met of zonder kopregel; maximaal 512 KB.` | `An administrator changed your email address` |
| `CSV file` | `CSV-bestand` | `CLAUDE.md includes AGENTS.md` |
| `CSV or text, up to 512 KB` | `CSV of tekst, maximaal 512 KB` | `CLAUDE.md includes AGENTS.md` |
| `Check the list` | `Lijst controleren` | `Check QUEUE_CONNECTION in .env` |
| `Check the list first.` | `Controleer eerst de lijst.` | `Check QUEUE_CONNECTION in .env` |
| `Done` | `Klaar` | `Doctor` |
| `Drop a CSV file here or click to browse` | `Sleep een CSV-bestand hierheen of klik om te bladeren` | `Download` |
| `Email addresses` | `E-mailadressen` | `Email address` |
| `For every address that names no language.` | `Voor elk adres zonder taal.` | `Flux Pro was not installed: the command was interrupted.` |
| `Invite more people` | `Meer mensen uitnodigen` | `Invite link` |
| `Invite several people` | `Meerdere mensen uitnodigen` | `Invite new users by email` |
| `Listed more than once.` | `Staat meer dan eens in de lijst.` | `Light` |
| `No address in this list can be invited.` | `Geen enkel adres in deze lijst kan worden uitgenodigd.` | `No activity yet.` |
| `Not a valid email address.` | `Geen geldig e-mailadres.` | `None yet` |
| `One per line, or separated by commas or semicolons.` | `Eén per regel, of gescheiden door komma's of puntkomma's.` | `One or more roles are not available for this organisation.` |
| `Paste addresses or choose a CSV file.` | `Plak adressen of kies een CSV-bestand.` | `Password updated.` |
| `Paste addresses or upload a CSV file, check the list, then send the invitations.` | `Plak adressen of upload een CSV-bestand, controleer de lijst en verstuur dan de uitnodigingen.` | `Password updated.` |
| `Ready` | `Gereed` | `Re-check` |
| `Ready: :ready of :total` | `Gereed: :ready van :total` | `Re-check` |
| `Remove file` | `Bestand verwijderen` | `Remove database/schema/pgsql-schema.* first: setup runs plain migrations (§7.3).` |
| `Send invitations (:count)` | `Uitnodigingen versturen (:count)` | `Send invitation` |
| `Sent: :count` | `Verstuurd: :count` | `Send invitation` |
| `Skipped: :count` | `Overgeslagen: :count` | `Site served by Herd` |
| `This address can't be invited.` | `Dit adres kan niet worden uitgenodigd.` | `This address already has an open invitation.` |
| `Unknown language.` | `Onbekende taal.` | `Unknown accent :accent; using neutral.` |

In `en.json` each value equals its key. "Check" already exists as a noun ("Controle", the doctor's column), so the button uses the new key "Check the list".

`CHANGELOG.md`, under `## [Unreleased]`:
- `### Added`: `- Bulk invitations: paste addresses or upload a CSV file (an email column and an optional language column, with or without a header, comma- or semicolon-separated, at most 512 KB), choose the roles and the language once, check the list (ready, or skipped with a reason) and send up to 100 invitations at a time, at most 100 per hour per administrator; reached from "Invite several people" on the pending invitations page. Addresses that cannot be invited all get the same message. With Flux Pro the file can be dropped on an upload zone.`

- [ ] **Step 10: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Unit/InvitationListTest.php tests/Feature/Invitations tests/Feature/Tenancy/RouteHostMatrixTest.php tests/Feature/FluxPro tests/Feature/Auth/ActionLimitsTest.php tests/Feature/Support tests/Feature/Navigation tests/Arch tests/Feature/LangFilesTest.php tests/Feature/LangCoverageTest.php`

Expected: PASS (the `flux-pro` group skipped). `InvitePageTest`, `InviteUserTest` and the invite rows of `ActionLimitsTest` pass unchanged, which shows the move to the trait kept `users.invite`'s behaviour.

- [ ] **Step 11: Verify by mutation**

For each mutation: copy the file aside with `cp` (into the scratchpad), make the change, `php artisan view:clear` after a Blade or component change, run the named test and confirm it fails, then `cp` the original back and confirm it passes. Never `git stash`. Name each mutation and its result in the task report.
1. In `bulk.php`, drop `#[Locked]` from `$rows`. Expect 'sends only the rows the server checked…' to fail: `CannotUpdateLockedPropertyException` not thrown.
2. In `skipReason()`, replace the `addressRules` condition with `false`. Expect 'reviews every row…' to fail (the member, the open invitation and the other organisation's address show `null`).
3. In `skipReason()`, replace `if ($listedBefore) {` with `if (false) {`. Expect 'reviews every row…' to fail (the second `new@example.com` is ready).
4. In `send()`, replace `$rows = $this->review($this->rows, $organisation, $inviteUser);` with `$rows = $this->rows;`. Expect 'reviews the rows again when it sends…' to fail (both rows reach `InviteUser` and both get "This organisation is archived.").
5. In `send()`'s `finally`, remove the `$limit->release($actor, $unused);` call. Expect 'skips a row the action refuses at send time…' to fail with 3 instead of 2.
6. In `send()`, remove the `ensureAvailable()` line. Expect 'refuses a list larger than what is left of the bulk limit…' to fail with 101 instead of 98.
7. In `reasonFor()`, drop `isset($errors['email']) || `. Expect 'skips a row the action refuses at send time…' to fail (the raced row shows "This address can't be used.").
8. In `send()`, replace `Locale::from($row['locale'])` with `Locale::from($this->locale)`. Expect 'sends one queued mail per ready row, in the row's language…' to fail (Ada's mail is English).
9. In `updated()`, remove `$this->rows = [];`. Expect all four rows of 'drops the preview when the input changes…' to fail.
10. In `bulk.php`'s `mount()`, remove `$this->authorizeInvitePage();`. Expect 'refuses a signed-in user who is not a super admin on a central host' to fail with 200 instead of 403.
11. In `pending.blade.php`, replace `@if ($this->canInvite)` with `@if (true)`. Expect 'offers "Invite several people" on the pending list only to users who may invite' to fail.
12. In `check()`, replace `if (count($entries) > InvitationList::MAX_ROWS) {` with `if (false) {`. Expect the `101 rows` row of 'refuses an empty list…' to fail ("Component has no errors.").
13. In `InvitationList::withoutBom()`, return `$text`. Expect the BOM rows of `InvitationListTest` to fail.
14. In `InvitationList::header()`, return `[$email ?? 0, $locale]` unconditionally. Expect 'addresses only, no header' and the other header-less rows to fail (the first address is dropped as a header).
15. In `bulk.blade.php`'s free branch, change `type="file"` to `type="text"`. Expect `FreeVariantsTest` to fail.
16. In `RouteHostMatrixTest::matrixRoutes()`, remove `'invitations.bulk'`. Expect 'lists every named GET route in the matrix or the exclusions' to fail with `['invitations.bulk']`.

- [ ] **Step 12: The gate, then commit**

```bash
composer fix
vendor/bin/phpstan analyse resources/views/pages/invitations/bulk/bulk.php app/Concerns/ChoosesInvitationOrganisation.php tests/Feature/Invitations/BulkInvitationsTest.php
composer check
composer test:pgsql
php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser
npm run build && composer test:browser
npm run build:setup && git diff --exit-code resources/setup/setup.css
php artisan config:clear
```

Run the `phpstan` line before `composer check`: the component's array shapes and `__()`'s benevolent return type are where Larastan level 8 complains first. Task 5 changes no setup view, so expect no stylesheet diff. Under `composer test:pgsql`, watch 'skips a row the action refuses at send time…': the unique violation must roll back only `InviteUser`'s savepoint, so the rows after it are still sent.

```bash
git add app/Support/Invitations/InvitationList.php app/Concerns/ChoosesInvitationOrganisation.php \
    app/Support/RateLimits/UserActionLimit.php app/Actions/Invitations/InviteUser.php routes/admin.php \
    resources/views/pages/invitations/bulk/bulk.php resources/views/pages/invitations/bulk/bulk.blade.php \
    resources/views/pages/users/invite/invite.php resources/views/pages/invitations/pending/pending.blade.php \
    lang/nl.json lang/en.json CHANGELOG.md \
    tests/Unit/InvitationListTest.php tests/Feature/Invitations/BulkInvitationsTest.php \
    tests/Feature/Tenancy/RouteHostMatrixTest.php tests/Feature/FluxPro/FreeVariantsTest.php tests/Feature/FluxPro/ProVariantsTest.php
git commit -m "feat: invite several people at once from a pasted list or a csv file

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Avatars (§6.6 avatar, §4.3 purge, §4.4, §4.8, §6.7, §3; CD-1 (revised), CD-7, CD-9, CD-10)

A user uploads their own profile photo on the profile page. The kit never stores the upload as sent. `UpdateAvatar` reads it with Laravel 13's own image API (`Illuminate\Image`, on `intervention/image` ^4) on the GD driver, turns it by its EXIF orientation, crops it to a 512 × 512 square and stores it as WebP on the `public` disk. The photo then shows in the user menu, in the users index and in the profile preview, each with the initials as fallback. Replacing or removing a photo deletes the old file after commit. Purging an organisation deletes its users' files, while deleting a user keeps the file, because a deleted user can be restored. Changes are audited on `user.updated` as `changes.avatar` = `set` / `removed`, never the path. During an impersonation the section is hidden and its actions answer 403. The free variant is `flux:input type="file"`, and the Pro variant is `flux:file-upload` with a `flux:file-item`.

**Files:**
- Create:
  - `database/migrations/0001_01_01_000009_add_avatar_path_to_users_table.php` (`users.avatar_path`, nullable string; the next number after `…000008_add_appearance_to_users_table.php`)
  - `app/Actions/Users/UpdateAvatar.php`
  - `app/Actions/Users/RemoveAvatar.php`
  - `tests/Feature/Settings/AvatarTest.php`
- Modify:
  - `composer.json`, `composer.lock` (`composer require intervention/image:^4`; the only task that changes them)
  - `app/Models/User.php` (`@property string|null $avatar_path`, the `$attributes` default, `avatarUrl()`)
  - `app/Actions/Organisations/PurgeOrganisation.php` (reads `avatar_path` with the locked rows and deletes the deleted users' files after commit)
  - `resources/views/pages/settings/profile/profile.php` (`WithFileUploads`, `?TemporaryUploadedFile $avatar`, `#[Locked] bool $canManageAvatar`, `avatarUrl` / `initials` computed, `saveAvatar()`, `removeAvatar()`, `validationAttributes()`, `ensureAvatarManageable()`)
  - `resources/views/pages/settings/profile/profile.blade.php` (the "Profile photo" section)
  - `resources/views/components/user-menu/user-menu.php` (`#[On('avatar-updated')] refreshAvatar()`)
  - `resources/views/components/user-menu/user-menu.blade.php` (`avatar` / `src` on `flux:sidebar.profile`, `flux:profile` and `flux:avatar`)
  - `resources/views/pages/users/index/index.blade.php` (the name cell gets a `flux:avatar`)
  - `lang/nl.json`, `lang/en.json`, `CHANGELOG.md`
- Test:
  - `tests/Feature/Settings/AvatarTest.php` (new)
  - `tests/Feature/Authorization/LockOrderTest.php` (two dataset rows, PostgreSQL only)
  - `tests/Feature/FluxPro/FreeVariantsTest.php`, `tests/Feature/FluxPro/ProVariantsTest.php` (one line each)

**Interfaces:**
- Consumes:
  - `RecordActivity::handle(ActivityEvent $event, ?Model $subject = null, array $properties = [], array $changes = [], ?User $causer = null, ?Organisation $organisation = null, bool $defaultCauser = true): Activity`
  - `ActivityEvent::UserUpdated` (`user.updated`, group `user`; no new case)
  - `App\Tenancy\Auth\Impersonation::isActive(): bool`, `Impersonation::SESSION_KEY`
  - `App\Concerns\ResolvesCurrentUser::currentUser(): User`
  - `User::initials(): string`
  - `UserActivityObserver` (an allow-list, `AUDITED = ['name', 'email']`, so `avatar_path` never reaches its `changes`; nothing to add to it)
  - `PurgeOrganisation::handle(User $actor, Organisation $organisation, string $confirmation): void`, `DeleteUser`, `ArchiveOrganisation`, `SetSuperAdmin`
  - test helpers: `asMember()`, `asOrgAdmin()`, `asSuperAdmin()`, `onTenant()`, `onAdminHost()`, `pageSnapshot()`, `callPageAction()`, `startImpersonatingOverHttp()`, `useImpersonationModule()`, `recordedActivity()`, `lockStatements()`
  - Laravel 13.35's image API, `vendor/laravel/framework/src/Illuminate/Image` (verified with intervention/image 4.3.5):
    - `Illuminate\Image\ImageManager`: registered as `image` by the deferred `ImageServiceProvider` (in `DefaultProviders`), aliased to the class, so it is constructor-injectable. Its `fromBytes(string $contents): Image` reads raw bytes. `fromUpload()` reads `$file->getContent()`, a local path, so the action reads the bytes itself.
    - `Illuminate\Image\Image`: an immutable pipeline. `usingGd(): static` picks the driver (the default is `config('images.default', 'gd')`; the kit has no `config/images.php` and adds none). `orient()`, `cover(int $width, int $height)`, `toWebp()` and `quality(int $quality)` (default 70) queue steps, and `toBytes(): string` runs them.
    - `Illuminate\Image\Drivers\GdDriver` (`InterventionDriver`): `Intervention\Image\ImageManager::usingDriver(Gd\Driver::class)` with the default config, `decode()`, then each step: `Orient` → `orient()`, `Cover` → `cover()`. It encodes through `WebpEncoder($quality)`.
    - `Illuminate\Image\ImageException` (extends `RuntimeException`) is what `toBytes()` throws:
      - without a previous exception for a format the driver refuses (`finfo` check: "The image format [text/plain] is not supported.")
      - wrapping the driver's throwable otherwise ("Failed to process image: …"), for a failed decode `Intervention\Image\Exceptions\ImageDecoderException` (a `DecoderException`)
    - `ImageManager::transformUsing(string $driver, string $transformation, callable $callback): static` replaces one step's handler; the test uses it to simulate a server-side failure.
- Produces:
  - column `users.avatar_path` (`string`, nullable, no index)
  - `User::avatarUrl(): ?string`, which is `Storage::disk('public')->url($this->avatar_path)` or null
  - `App\Actions\Users\UpdateAvatar::handle(User $user, UploadedFile $file): void` (final readonly; constants `SIZE = 512`, `QUALITY = 85`, `MAX_SIDE = 4096`). It throws `AuthorizationException` during an impersonation, and `ValidationException` on `avatar` with "This image could not be processed." for an unreadable image or a header that declares more than 4096 px on a side. It stores `avatars/{organisation_id|platform}/{user id}-{Str::random(20)}.webp` and records `user.updated` with `attribute_changes = {"attributes": {"avatar": "set"}}`.
  - `App\Actions\Users\RemoveAvatar::handle(User $user): void` (final readonly). It throws `AuthorizationException` during an impersonation; without a stored photo it writes and records nothing; otherwise it records `{"attributes": {"avatar": "removed"}}`.
  - Livewire event `avatar-updated`, dispatched by the profile page and handled by the user menu
  - `pages::settings.profile` public actions `saveAvatar()` / `removeAvatar()` and property `avatar`

Background for the implementer:
- **The image library (CD-1):**
  - CD-1 (revised by the controller): Laravel's first-party image API on `intervention/image` ^4, whose 4.x the framework itself requires for it (`require-dev` and `suggest`: `^4.0`). The spec's "^3 (latest at M1)" meant the current major, which Task 8 corrects.
  - GD is the driver, chosen explicitly with `usingGd()`.
  - The orientation is applied twice, harmlessly:
    - intervention 4's decoder turns the image itself (`Config::$autoOrientation = true` by default)
    - the action's `orient()` step turns it again only if needed: `OrientModifier` resets the EXIF orientation to 1 after turning, so a second turn is a no-op
  - `orient()` is the kit's own guarantee: in the sandbox, with the vendor default switched to `false`, the test still passed with `orient()` and failed without it.
  - The orientation is read with `exif_read_data()`. Without ext-exif, `extractExifData()` returns an empty collection and the photo keeps its stored orientation. ext-exif is loaded on Herd's PHP 8.4 and on the CI images. The orientation test skips without it, and no `ext-exif` requirement is added (Global Constraints).
  - GD on this PHP reports `WebP Support => 1` (`gd_info()`).
  - Only a decode problem becomes "This image could not be processed.":
    - an `ImageException` without a previous exception
    - one whose previous is an intervention `DecoderException`
  - Any other `ImageException`, such as a GD build without WebP support, is rethrown and reported as a server error. The user's image was not at fault.
  - `getimagesizefromstring()` reads only the header. The action uses it before decoding, so a small file that declares a huge canvas never reaches GD.
  - WebP from GD carries no EXIF or XMP chunk. The test checks that neither `Exif` nor `EXIF` appears in the stored bytes.
- **The lock (CD-5):**
  - Both actions write only the user's own row, but they read the previous path to delete it. Without a lock, two parallel saves both read the same old path: both delete it, and one new file stays orphaned.
  - So each action locks the user's row alone, by id: `select "avatar_path" from "users" where "id" = ? limit 1 for update`. This is CD-5 step 3 as a batch of one. Then it writes the row and inserts the activity row last.
  - No organisation lock: nothing about the organisation is decided. `LockOrderTest` reads the lock as one users row by id (its `oneRowById` check), so no `order by` is needed.
- **The file and the transaction:**
  - The new file is written before the transaction and deleted again in a `catch (Throwable)` that rethrows.
  - The old file is deleted with `DB::afterCommit()`. When a surrounding transaction rolls back, the old photo stays and so does its file.
  - `RefreshDatabase` runs after-commit callbacks when the action's own transaction commits, as `UpdateUser`'s cache-forget test already relies on.
- **Strict mode:** `Model::preventAccessingMissingAttributes()` is on, and a user created by a factory in the same request has no `avatar_path` attribute until it is read back. Without `'avatar_path' => null` in `User::$attributes`, the user menu answers 500 ("The attribute [avatar_path] either does not exist or was not retrieved"). `appearance` has the same default for the same reason (DEC-12).
- **Audit (CD-9):**
  - `RecordActivity`'s `$changes` shape is `{attributes?, old?}`, and the viewer renders it as `attribute: old → new`. The avatar entry is `{"attributes": {"avatar": "set"}}`, which renders as "avatar: — → set".
  - The actions save with `saveQuietly()` and record the entry themselves. `UserActivityObserver` is an allow-list, so a plain `save()` of `avatar_path` records nothing either. A test pins that.
- **The users index:** rows are full `User` models (`paginate(25)` selects every column), so `$user->avatarUrl()` needs no extra query.
- **Livewire uploads in tests:**
  - `Livewire::test()->set('avatar', UploadedFile::fake()->image(...))` stores the file on Livewire's faked temporary disk.
  - `TemporaryUploadedFile::getSize()` reads the fake size from the `-size=` part of the file name, so `->size(2049)` reaches the `max:2048` rule.
  - `UpdateAvatar` reads the bytes with `$file->get()`, never `getRealPath()`, so a temporary disk on S3 works too.
  - Livewire answers a validation error with 200. The forged-update test therefore expects 403 from the guard, not from validation.
- **Flux:**
  - The free `flux:avatar` renders `<img src>` when `src` is set, and `<span class="select-none">{initials}</span>` otherwise.
  - `flux:sidebar.profile` and `flux:profile` pass their `avatar` prop to the inner avatar's `src`.
  - The free `flux:input type="file"` renders `data-flux-input-file` and passes `accept` and `wire:model` to the `<input>`.
  - Pro (2.15 stubs, checked in skv2's vendor): `<flux:file-upload wire:model="…" :label>` wraps `<flux:file-upload.dropzone :heading :text inline />`, and `<flux:file-item :heading :size :image icon>` renders `data-flux-file-item`.
  - The free file input is `@blaze(fold: true)`, so its own "Choose file" / "No file chosen" copy is folded at compile time and stays English. That is the G3-08 class (won't fix, upstream). The kit adds no keys for it.
- **Pro rendering (CD-10):**
  - Without `livewire/flux-pro` installed, `AppServiceProvider::configureFluxProBranches()` strips every Pro branch at compile time and replaces it with a `LogicException` ("Flux Pro was installed after this view was compiled"). That follows `InstalledVersions`, not `Skv::useFluxPro()`.
  - So `Skv::useFluxPro(true)` cannot render a Pro branch in this repository. The Pro branch is covered by:
    - `FluxProLayerTest` (placement, the `@else`, the same `wire:model` name `avatar`)
    - one line in `ProVariantsTest`, which runs only where Pro is installed
  - The free branch is covered by `FreeVariantsTest` and `AvatarTest`.

- [ ] **Step 1: Add the dependency (CD-1)**

```bash
composer require intervention/image:^4
```

Expected:
- `composer.json` gains `"intervention/image": "^4",` as the second `require` line (after `"php"`, `sort-packages`).
- `composer.lock` gains two packages and changes nothing else ("2 installs, 0 updates, 0 removals"): `intervention/image` 4.3.x and its dependency `intervention/gif` 5.0.x. Both resolved without conflicts on 2026-10-17: 4.3.5 and 5.0.1.
- `laravel/framework` lists `intervention/image ^4.0` only under `require-dev` and `suggest`, so nothing pins it lower.
- "No security vulnerability advisories found."

No test pins the `require` list. `ComposerScriptsTest`, `DoctorCommandTest` and `CiWorkflowTest` read only `scripts`, `config.audit` and the absence of `flux-pro`. No doctor check reads `composer.json` or `composer.lock`, and `GdCheck` (`gd`) already requires ext-gd. Confirm GD's WebP support on the machine:

```bash
php -r 'var_dump(gd_info()["WebP Support"] ?? false, function_exists("exif_read_data"));'
```

Expected: `bool(true) bool(true)`.

```bash
git add composer.json composer.lock
git commit -m "build: add intervention/image for profile photos

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/Settings/AvatarTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §6.6, §4.3, §4.8, §6.7 */

use App\Actions\Organisations\ArchiveOrganisation;
use App\Actions\Organisations\PurgeOrganisation;
use App\Actions\Users\DeleteUser;
use App\Actions\Users\RemoveAvatar;
use App\Actions\Users\UpdateAvatar;
use App\Authorization\Role;
use App\Authorization\SetSuperAdmin;
use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Models\Organisation;
use App\Models\User;
use App\Support\Skv;
use App\Tenancy\Auth\Impersonation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Image\ImageException;
use Illuminate\Image\ImageManager;
use Illuminate\Image\Transformations\Cover;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('public');
    Skv::useFluxPro(false);
});

afterEach(fn () => Skv::useFluxPro(null));

/**
 * A 40 × 20 JPEG, left half red and right half blue, with an EXIF APP1 segment whose IFD0 holds one Orientation
 * tag (0x0112, SHORT): what a phone writes instead of turning the pixels. 6 means "turn 90° clockwise to show".
 */
$orientedJpeg = function (int $orientation): string {
    $image = imagecreatetruecolor(40, 20);
    imagefilledrectangle($image, 0, 0, 19, 19, (int) imagecolorallocate($image, 255, 0, 0));
    imagefilledrectangle($image, 20, 0, 39, 19, (int) imagecolorallocate($image, 0, 0, 255));
    ob_start();
    imagejpeg($image, null, 95);
    $jpeg = (string) ob_get_clean();

    // Big-endian TIFF header, one IFD0 entry, no next IFD.
    $tiff = "MM\x00\x2A\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01".pack('n', $orientation)."\x00\x00\x00\x00\x00\x00";
    $app1 = "\xFF\xE1".pack('n', 2 + 6 + strlen($tiff))."Exif\x00\x00".$tiff;

    return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
};

/** A PNG whose signature and header are valid (finfo, getimagesize) but whose pixel data is missing: it passes every rule and cannot be decoded. */
$brokenPng = function (): string {
    $image = imagecreatetruecolor(64, 64);
    ob_start();
    imagepng($image);

    return substr((string) ob_get_clean(), 0, 33).str_repeat("\x00", 64);
};

/** The red, green and blue of one pixel of an encoded image. */
$pixel = function (string $bytes, int $x, int $y): array {
    $image = imagecreatefromstring($bytes) ?: throw new LogicException('Not an image.');
    $colour = imagecolorsforindex($image, (int) imagecolorat($image, $x, $y));

    return [$colour['red'], $colour['green'], $colour['blue']];
};

/** A member of $organisation with a stored photo, as UpdateAvatar leaves it. */
$memberWithPhoto = function (Organisation $organisation): User {
    $member = User::factory()->for($organisation)->create();
    $path = "avatars/{$organisation->id}/{$member->id}-existing.webp";
    Storage::disk('public')->put($path, 'old photo');
    $member->forceFill(['avatar_path' => $path])->saveQuietly();

    return $member;
};

it('stores an uploaded photo as a 512 × 512 WebP on the public disk under the organisation (§6.6)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = asMember($organisation);

    Livewire::test('pages::settings.profile')
        ->set('avatar', UploadedFile::fake()->image('photo.jpg', 800, 600))
        ->call('saveAvatar')
        ->assertHasNoErrors()
        ->assertSet('avatar', null)
        ->assertDispatched('avatar-updated');

    $path = (string) $member->fresh()?->avatar_path;
    $size = getimagesizefromstring((string) Storage::disk('public')->get($path));
    expect($path)->toMatch('#^avatars/'.$organisation->id.'/'.$member->id.'-[A-Za-z0-9]{20}\.webp$#')
        ->and([$size[0] ?? null, $size[1] ?? null, $size['mime'] ?? null])->toBe([512, 512, 'image/webp'])
        ->and($member->fresh()?->avatarUrl())->toBe(Storage::disk('public')->url($path))
        ->and(Storage::disk('public')->allFiles())->toBe([$path]);
});

it('stores a super admin\'s photo under avatars/platform', function (): void {
    $superAdmin = asSuperAdmin();
    onAdminHost();

    Livewire::test('pages::settings.profile')
        ->set('avatar', UploadedFile::fake()->image('photo.png', 600, 600))
        ->call('saveAvatar')
        ->assertHasNoErrors();

    expect((string) $superAdmin->fresh()?->avatar_path)->toStartWith('avatars/platform/'.$superAdmin->id.'-');
});

it('turns the photo by its EXIF orientation before the square crop and drops the metadata (§6.6)', function () use ($orientedJpeg, $pixel): void {
    $member = asMember(Organisation::factory()->create(['slug' => 'acme']));

    Livewire::test('pages::settings.profile')
        ->set('avatar', UploadedFile::fake()->createWithContent('phone.jpg', $orientedJpeg(6)))
        ->call('saveAvatar')
        ->assertHasNoErrors();

    $stored = (string) Storage::disk('public')->get((string) $member->fresh()?->avatar_path);
    [$topRight, $bottomLeft] = [$pixel($stored, 448, 64), $pixel($stored, 64, 448)];

    // Turned 90° clockwise, the red left half is on top and the blue right half below; unturned it would be the reverse.
    expect($topRight[0])->toBeGreaterThan(200)->and($topRight[2])->toBeLessThan(60)
        ->and($bottomLeft[2])->toBeGreaterThan(200)->and($bottomLeft[0])->toBeLessThan(60)
        ->and(str_contains($stored, 'Exif'))->toBeFalse()
        ->and(str_contains($stored, 'EXIF'))->toBeFalse();
})->skip(fn (): bool => ! function_exists('exif_read_data'), 'reading the orientation needs ext-exif');

it('refuses a photo that is too large, of another type or with too many pixels, and stores nothing (§6.6)', function (Closure $file, string $rule): void {
    $member = asMember(Organisation::factory()->create(['slug' => 'acme']));

    Livewire::test('pages::settings.profile')
        ->set('avatar', $file())
        ->call('saveAvatar')
        ->assertHasErrors(['avatar' => $rule]);

    expect($member->fresh()?->avatar_path)->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and(recordedActivity(ActivityEvent::UserUpdated))->toBeEmpty();
})->with([
    'more than 2 MB' => [fn (): UploadedFile => UploadedFile::fake()->image('photo.jpg', 100, 100)->size(2049), 'max'],
    'a GIF' => [fn (): UploadedFile => UploadedFile::fake()->image('photo.gif', 100, 100), 'mimes'],
    'a PDF' => [fn (): UploadedFile => UploadedFile::fake()->create('photo.pdf', 10, 'application/pdf'), 'image'],
    'wider than 4096 pixels' => [fn (): UploadedFile => UploadedFile::fake()->image('photo.png', 4097, 10), 'dimensions'],
    'nothing chosen' => [fn (): null => null, 'required'],
]);

it('refuses an image it cannot read with "This image could not be processed." (§6.6)', function () use ($brokenPng): void {
    $member = asMember(Organisation::factory()->create(['slug' => 'acme']));

    Livewire::test('pages::settings.profile')
        ->set('avatar', UploadedFile::fake()->createWithContent('photo.png', $brokenPng()))
        ->call('saveAvatar')
        ->assertHasErrors(['avatar' => __('This image could not be processed.')]);

    expect($member->fresh()?->avatar_path)->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('refuses an image wider than 4096 pixels in the action itself, before decoding it', function (): void {
    $member = User::factory()->for(Organisation::factory())->create();

    expect(fn () => app(UpdateAvatar::class)->handle($member, UploadedFile::fake()->image('photo.png', 4097, 10)))
        ->toThrow(ValidationException::class, __('This image could not be processed.'));

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('leaves a failure of the server\'s image processing a server error, not the user\'s image', function (): void {
    $member = User::factory()->for(Organisation::factory())->create();
    app(ImageManager::class)->transformUsing('gd', Cover::class, fn (): never => throw new RuntimeException('GD broke.'));

    expect(fn () => app(UpdateAvatar::class)->handle($member, UploadedFile::fake()->image('photo.png', 600, 600)))
        ->toThrow(ImageException::class, 'GD broke.');

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('deletes the previous photo once a new one is saved (§6.6)', function () use ($memberWithPhoto): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = $memberWithPhoto($organisation);
    $previous = (string) $member->avatar_path;
    $this->actingAs($member);
    onTenant($organisation);

    Livewire::test('pages::settings.profile')
        ->set('avatar', UploadedFile::fake()->image('new.png', 600, 600))
        ->call('saveAvatar')
        ->assertHasNoErrors();

    $current = (string) $member->fresh()?->avatar_path;
    expect($current)->not->toBe($previous)
        ->and(Storage::disk('public')->allFiles())->toBe([$current]);
});

it('keeps the previous photo and removes the new file when the save fails', function () use ($memberWithPhoto): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = $memberWithPhoto($organisation);
    $previous = (string) $member->avatar_path;
    onTenant($organisation);
    Activity::creating(function (): void {
        throw new RuntimeException('The audit log is unavailable.');
    });

    expect(fn () => app(UpdateAvatar::class)->handle($member, UploadedFile::fake()->image('new.png', 600, 600)))
        ->toThrow(RuntimeException::class);

    expect(DB::table('users')->where('id', $member->id)->value('avatar_path'))->toBe($previous)
        ->and(Storage::disk('public')->allFiles())->toBe([$previous]);
});

it('removes the photo and its file and falls back to the initials (§6.6)', function () use ($memberWithPhoto): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = $memberWithPhoto($organisation);
    $member->forceFill(['name' => 'Mia Member'])->saveQuietly();
    $this->actingAs($member);
    onTenant($organisation);

    Livewire::test('pages::settings.profile')
        ->call('removeAvatar')
        ->assertHasNoErrors()
        ->assertDispatched('avatar-updated');

    expect($member->fresh()?->avatar_path)->toBeNull()
        ->and($member->fresh()?->avatarUrl())->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBe([]);

    $this->get(route('profile.edit'))->assertOk()
        ->assertSee('data-test="profile-photo-preview"', false)
        ->assertSee('<span class="select-none">MM</span>', false)
        ->assertDontSee('data-test="remove-avatar-button"', false);
});

it('records user.updated with changes.avatar "set" or "removed" and never the path (§4.8)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = asMember($organisation);

    Livewire::test('pages::settings.profile')
        ->set('avatar', UploadedFile::fake()->image('photo.png', 600, 600))
        ->call('saveAvatar')
        ->call('removeAvatar')
        ->call('removeAvatar'); // nothing left to remove: no third entry

    $entries = recordedActivity(ActivityEvent::UserUpdated);
    expect($entries)->toHaveCount(2)
        ->and($entries->map(fn (Activity $entry): mixed => $entry->attribute_changes?->toArray())->all())->toBe([
            ['attributes' => ['avatar' => 'set']],
            ['attributes' => ['avatar' => 'removed']],
        ])
        ->and($entries->map(fn (Activity $entry): array => [$entry->subject_id, $entry->causer_id, $entry->organisation_id])->unique()->all())
        ->toBe([[$member->id, $member->id, $organisation->id]])
        ->and((string) json_encode($entries->toArray()))->not->toContain('avatars');
});

it('records nothing when only the stored path changes in a plain save (§4.8)', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    $member->forceFill(['avatar_path' => 'avatars/1/1-abc.webp'])->save();

    expect(recordedActivity(ActivityEvent::UserUpdated))->toBeEmpty();
});

it('hides the photo section during an impersonation and refuses its actions with 403 (§4.7)', function () use ($memberWithPhoto): void {
    useImpersonationModule(true);
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn (): User => $admin->assignRole(Role::OrganisationAdmin->value));
    $member = $memberWithPhoto($organisation);
    $path = $member->avatar_path;
    onTenant($organisation);
    startImpersonatingOverHttp($admin, $member);

    $this->get(route('profile.edit'))->assertOk()->assertDontSee('data-test="profile-photo"', false);
    $snapshot = pageSnapshot(route('profile.edit'), 'pages::settings.profile');

    callPageAction($snapshot, 'removeAvatar')->assertForbidden();
    callPageAction($snapshot, 'saveAvatar')->assertForbidden();

    expect($member->fresh()?->avatar_path)->toBe($path)
        ->and(Storage::disk('public')->exists((string) $path))->toBeTrue()
        ->and(recordedActivity(ActivityEvent::UserUpdated))->toBeEmpty();
});

it('refuses both actions themselves while the session impersonates', function () use ($memberWithPhoto): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = $memberWithPhoto($organisation);
    onTenant($organisation);
    Session::put(Impersonation::SESSION_KEY, $member->id + 1);

    expect(fn () => app(UpdateAvatar::class)->handle($member, UploadedFile::fake()->image('new.png', 600, 600)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RemoveAvatar::class)->handle($member))->toThrow(AuthorizationException::class)
        ->and($member->fresh()?->avatar_path)->not->toBeNull();
});

it('renders the free file input with the accepted types and no Pro upload', function (): void {
    asMember(Organisation::factory()->create(['slug' => 'acme']));

    $this->get(route('profile.edit'))->assertOk()
        ->assertSee('data-test="profile-photo"', false)
        ->assertSee('accept="image/jpeg,image/png,image/webp"', false)
        ->assertSee('wire:model="avatar"', false)
        ->assertDontSee('data-flux-file-upload', false);
});

it('shows the photo in the user menu, the users index and the profile preview, else the initials (§6.6)', function () use ($memberWithPhoto): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $withPhoto = $memberWithPhoto($organisation);
    $withPhoto->forceFill(['name' => 'Pia Photo'])->saveQuietly();
    $withoutPhoto = User::factory()->for($organisation)->create(['name' => 'Ivo Initials']);
    $url = (string) $withPhoto->avatarUrl();
    $admin = User::factory()->for($organisation)->create(['name' => 'Ada Admin']);
    withPermissionsTeam($organisation->id, fn (): User => $admin->assignRole(Role::OrganisationAdmin->value));
    $admin->unsetRelation('roles');
    onTenant($organisation);

    $this->actingAs($admin)->get(route('users.index'))->assertOk()
        ->assertSee('src="'.$url.'"', false)
        ->assertSee('<span class="select-none">II</span>', false)
        ->assertSee('<span class="select-none">AA</span>', false);

    $this->actingAs($withPhoto)->get(route('dashboard'))->assertOk()
        ->assertSee('data-flux-sidebar-profile', false)
        ->assertSee('src="'.$url.'"', false)
        ->assertDontSee('<span class="select-none">PP</span>', false);

    $this->actingAs($withPhoto)->get(route('profile.edit'))->assertOk()
        ->assertSee('src="'.$url.'"', false)
        ->assertSee('data-test="remove-avatar-button"', false);

    $this->actingAs($withoutPhoto)->get(route('dashboard'))->assertOk()
        ->assertSee('<span class="select-none">II</span>', false)
        ->assertDontSee('src="'.$url.'"', false);
});

it('re-renders the user menu when the photo changes', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $this->actingAs($member);
    onTenant($organisation);
    $menu = Livewire::test('user-menu');
    $path = "avatars/{$organisation->id}/{$member->id}-later.webp";
    $member->forceFill(['avatar_path' => $path])->saveQuietly();

    $menu->dispatch('avatar-updated')->assertSee('src="'.Storage::disk('public')->url($path).'"', false);
});

it('deletes the purged users\' photo files after commit and keeps a detached super admin\'s (§4.3)', function () use ($memberWithPhoto): void {
    $superAdmin = asSuperAdmin();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = $memberWithPhoto($organisation);
    $deleted = $memberWithPhoto($organisation);
    $deleted->delete();
    $promoted = $memberWithPhoto($organisation);
    app(SetSuperAdmin::class)->handle($promoted, true);
    app(ArchiveOrganisation::class)->handle($superAdmin, $organisation);

    app(PurgeOrganisation::class)->handle($superAdmin, Organisation::withTrashed()->findOrFail($organisation->id), 'acme');

    expect(Storage::disk('public')->exists((string) $member->avatar_path))->toBeFalse()
        ->and(Storage::disk('public')->exists((string) $deleted->avatar_path))->toBeFalse()
        ->and(Storage::disk('public')->allFiles())->toBe([(string) $promoted->avatar_path]);
});

it('keeps the photo file of a deleted user, who can be restored (§4.4)', function () use ($memberWithPhoto): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = $memberWithPhoto($organisation);
    $admin = asOrgAdmin($organisation);

    app(DeleteUser::class)->handle($admin, $member);

    expect(Storage::disk('public')->exists((string) $member->avatar_path))->toBeTrue();
});
```

How the test inputs work:
- The server-failure test replaces the GD driver's `Cover` step through `ImageManager::transformUsing('gd', Cover::class, …)` with a closure that throws. `toBytes()` wraps that throw in an `ImageException` whose previous is the `RuntimeException`, not a `DecoderException`. The action must rethrow it rather than blame the image. The manager is scoped, so the action resolves the same instance within the test.
- The EXIF fixture is built in the test, so no binary file is committed. GD writes a plain JFIF JPEG, and the closure splices a 34-byte APP1 segment in right after the SOI marker: `Exif\0\0`, a big-endian TIFF header, IFD0 with one SHORT entry `0x0112 = 6`, and no next IFD.
- `exif_read_data()` reads the result as `Orientation => 6`, while `getimagesize()` still reports 40 × 20.
- After the 90° clockwise turn the image is 20 × 40. `cover(512, 512)` scales it to 512 × 1024 and keeps the middle, so (448, 64) is red and (64, 448) is blue. Without the turn it is 1024 × 512 cropped to the middle, so (448, 64) is blue and (64, 448) is red.

`tests/Feature/Authorization/LockOrderTest.php`: add these imports at their sorted places:
- `use App\Actions\Users\RemoveAvatar;` after `use App\Actions\Users\DeleteUser;`
- `use App\Actions\Users\UpdateAvatar;` after `use App\Actions\Users\RestoreUser;`
- `use Illuminate\Http\UploadedFile;` after `use App\Models\User;`
- `use Illuminate\Support\Facades\Storage;` after `use Illuminate\Support\Facades\DB;`

In the dataset of 'takes its row locks in the one order of CD-5', insert after the `'UpdateUser, changing a member\'s address (FU2-5)'` row:

```php
    'UpdateAvatar, a member\'s own photo (§6.6)' => [function (): Closure {
        Storage::fake('public');
        $member = asMember(Organisation::factory()->create());

        return fn () => app(UpdateAvatar::class)->handle($member, UploadedFile::fake()->image('photo.png', 600, 600));
    }, ['users']],
    'RemoveAvatar, a member\'s own photo (§6.6)' => [function (): Closure {
        Storage::fake('public');
        $member = asMember(Organisation::factory()->create());
        $member->forceFill(['avatar_path' => 'avatars/1/1-photo.webp'])->saveQuietly();

        return fn () => app(RemoveAvatar::class)->handle($member);
    }, ['users']],
```

`tests/Feature/FluxPro/FreeVariantsTest.php`: in 'renders the free variants when Flux Pro is absent', append after the last `$this->get(...)` line (Task 5 may have added the bulk-invitations line before it):

```php
    $this->get(route('profile.edit'))->assertOk()->assertSee('data-flux-input-file', false)->assertDontSee('data-flux-file-upload', false);
```

`tests/Feature/FluxPro/ProVariantsTest.php`: in 'renders the Pro variants when Flux Pro is installed', append after its last `$this->get(...)` line:

```php
    $this->get(route('profile.edit'))->assertOk()->assertSee('data-flux-file-upload', false)->assertDontSee('data-flux-input-file', false);
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Settings/AvatarTest.php tests/Feature/FluxPro`

Expected: FAIL.
- The upload tests fail with `MissingFileUploadsTraitException` ("Cannot handle file upload without [Livewire\WithFileUploads] trait"); the 'nothing chosen' row fails with `PublicPropertyNotFoundException` (no `$avatar`).
- Every test that writes `avatar_path` fails with "no such column: avatar_path".
- The free-input test and the `FreeVariantsTest` line fail on `assertSee('data-flux-input-file')` / `data-test="profile-photo"`.
- `ProVariantsTest` stays skipped without Pro.
- The two action-level tests ('refuses an image wider than 4096 pixels in the action itself…', 'leaves a failure of the server's image processing…') fail with "Target class [App\Actions\Users\UpdateAvatar] does not exist".
- `LockOrderTest` skips on SQLite. On PostgreSQL its two new rows fail with "Class "App\Actions\Users\UpdateAvatar" not found" until Step 5.

- [ ] **Step 4: The column and the model**

Create `database/migrations/0001_01_01_000009_add_avatar_path_to_users_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** §6.6: the profile photo's path on the public disk; null shows the initials. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('avatar_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('avatar_path');
        });
    }
};
```

The migration runs only through `RefreshDatabase` here. Never `migrate` the development database (Global Constraints).

`app/Models/User.php`:
- Add `use Illuminate\Support\Facades\Storage;` after `use Illuminate\Support\Carbon;`.
- In the class docblock, add ` * @property string|null $avatar_path` after ` * @property Appearance $appearance`.
- Replace the `$attributes` block with:

```php
    /**
     * Mirror the column defaults, so a user created in this request renders layouts/base and the user menu in strict
     * mode (DEC-12, §6.6).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'appearance' => Appearance::System->value,
        'avatar_path' => null,
    ];
```

Add this method after `initials()`:

```php
    /**
     * The public URL of the profile photo (§6.6), or null for the initials. The public disk's URL comes from
     * APP_URL, so a photo on a tenant host loads from the apex's /storage.
     */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path === null ? null : Storage::disk('public')->url($this->avatar_path);
    }
```

`avatar_path` stays out of `#[Fillable]`; only the two actions write it, with `forceFill()`.

- [ ] **Step 5: The actions**

Create `app/Actions/Users/UpdateAvatar.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Audit\RecordActivity;
use App\Enums\ActivityEvent;
use App\Models\User;
use App\Tenancy\Auth\Impersonation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Image\ImageException;
use Illuminate\Image\ImageManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Exceptions\DecoderException;
use RuntimeException;
use Throwable;

/**
 * Stores a user's own profile photo (§6.6). The upload is never stored as sent: an image whose header declares more
 * than 4096 pixels on a side is refused before decoding; the rest goes through Laravel's image API on the GD driver
 * (intervention/image ^4), which turns it by its EXIF orientation (ext-exif; without it the photo keeps its stored
 * orientation), crops it to a 512 × 512 square from the centre and encodes it as WebP, which carries no metadata.
 * The file goes to the public disk under avatars/{organisation id or "platform"}/{user id}-{random}.webp before the
 * transaction and is removed again when the transaction fails. Inside it the user's row is locked alone (CD-5 step
 * 3) to read the previous path, the new path is saved quietly and user.updated is recorded with changes.avatar =
 * "set", never the path (§4.8); the previous file is deleted after commit. The page validates the upload first
 * (type, size, dimensions); an image that cannot be decoded is a validation error on "avatar". Never during an
 * impersonation.
 */
final readonly class UpdateAvatar
{
    public const int SIZE = 512;

    public const int QUALITY = 85;

    /** The longest side decoded: the page's dimensions rule, checked again here before GD allocates the pixels. */
    public const int MAX_SIDE = 4096;

    public function __construct(
        private RecordActivity $recordActivity,
        private ImageManager $images,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, UploadedFile $file): void
    {
        if (Impersonation::isActive()) {
            throw new AuthorizationException('The profile photo is not changed during an impersonation.');
        }

        $encoded = $this->encode($file);
        $disk = Storage::disk('public');
        $path = sprintf('avatars/%s/%d-%s.webp', $user->organisation_id ?? 'platform', $user->id, Str::random(20));

        if (! $disk->put($path, $encoded)) {
            throw new RuntimeException('The profile photo could not be stored.');
        }

        try {
            DB::transaction(function () use ($user, $path, $disk): void {
                $previous = DB::table('users')->where('id', $user->id)->lockForUpdate()->value('avatar_path');

                $user->forceFill(['avatar_path' => $path])->saveQuietly();
                $this->recordActivity->handle(ActivityEvent::UserUpdated, $user, changes: ['attributes' => ['avatar' => 'set']]);

                if (is_string($previous) && $previous !== '') {
                    DB::afterCommit(fn (): bool => $disk->delete($previous));
                }
            });
        } catch (Throwable $exception) {
            $disk->delete($path);

            throw $exception;
        }
    }

    /** @throws ValidationException */
    private function encode(UploadedFile $file): string
    {
        // get(), not getContent(): a Livewire upload on a remote temporary disk has no local path.
        $contents = $file->get();

        if (! is_string($contents) || $contents === '') {
            throw $this->unreadable();
        }

        // The header alone, before decoding: a small file can declare a huge canvas (a decompression bomb).
        $size = @getimagesizefromstring($contents);

        if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[0] > self::MAX_SIDE || $size[1] > self::MAX_SIDE) {
            throw $this->unreadable();
        }

        try {
            return $this->images->fromBytes($contents)
                ->usingGd()
                ->orient()
                ->cover(self::SIZE, self::SIZE)
                ->toWebp()
                ->quality(self::QUALITY)
                ->toBytes();
        } catch (ImageException $exception) {
            // A format the driver refuses (no previous) or a failed decode is the upload's fault; anything else, such
            // as a GD build without WebP, is the server's and stays a reported error.
            $cause = $exception->getPrevious();

            if (! $cause instanceof Throwable || $cause instanceof DecoderException) {
                throw $this->unreadable();
            }

            throw $exception;
        }
    }

    private function unreadable(): ValidationException
    {
        return ValidationException::withMessages(['avatar' => [__('This image could not be processed.')]]);
    }
}
```

Create `app/Actions/Users/RemoveAvatar.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Audit\RecordActivity;
use App\Enums\ActivityEvent;
use App\Models\User;
use App\Tenancy\Auth\Impersonation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Removes a user's own profile photo (§6.6): the user's row is locked alone (CD-5 step 3) to read the stored path,
 * the path is cleared quietly, user.updated is recorded with changes.avatar = "removed" (never the path, §4.8) and
 * the file is deleted after commit. Without a stored photo nothing is written or recorded. Never during an
 * impersonation.
 */
final readonly class RemoveAvatar
{
    public function __construct(private RecordActivity $recordActivity) {}

    /** @throws AuthorizationException */
    public function handle(User $user): void
    {
        if (Impersonation::isActive()) {
            throw new AuthorizationException('The profile photo is not changed during an impersonation.');
        }

        DB::transaction(function () use ($user): void {
            $previous = DB::table('users')->where('id', $user->id)->lockForUpdate()->value('avatar_path');

            if (! is_string($previous) || $previous === '') {
                return;
            }

            $user->forceFill(['avatar_path' => null])->saveQuietly();
            $this->recordActivity->handle(ActivityEvent::UserUpdated, $user, changes: ['attributes' => ['avatar' => 'removed']]);

            DB::afterCommit(fn (): bool => Storage::disk('public')->delete($previous));
        });
    }
}
```

Neither action takes an actor or calls `Gate`: they act on the signed-in user's own account, like `UpdateLocale` and `RequestEmailChange` (self-service, not administrative). The page passes `currentUser()`.

`UpdateAvatar` takes `Illuminate\Image\ImageManager` by injection rather than the `Image` facade. The container alias `image` → `ImageManager` makes it resolvable, and the class name keeps `Illuminate\Image\Image` and the facade apart. The bytes go in through `fromBytes()`, read once with `$file->get()`, which the header guard also uses.

The lock is `DB::table('users')`, not Eloquent:
- It reads one column of one row by id without the tenant scope. A super admin on a central host has no bound tenant, and strict mode would refuse an Eloquent read there.
- It compiles to `select "avatar_path" from "users" where "id" = ? limit 1 for update`, which `LockOrderTest` counts as one users row by id.

- [ ] **Step 6: Purge deletes the photos (§4.3)**

`app/Actions/Organisations/PurgeOrganisation.php`: add `use Illuminate\Support\Facades\Storage;` after `use Illuminate\Support\Facades\Gate;`. In the class docblock, replace

```php
 * team's role assignments and roles, and the organisation are deleted. Activity rows are kept (ORG-12).
```

with

```php
 * team's role assignments and roles, and the organisation are deleted. Activity rows are kept (ORG-12). The
 * deleted users' profile photos are deleted from the public disk after commit (§6.6).
```

Replace

```php
                ->get(['id', 'email', 'is_super_admin']);

            $detached = [];
            $users = [];

            foreach ($rows as $row) {
                if ((bool) $row->is_super_admin) {
                    $detached[] = (int) $row->id;
                } else {
                    $users[(int) $row->id] = (string) $row->email;
                }
            }
```

with

```php
                ->get(['id', 'email', 'is_super_admin', 'avatar_path']);

            $detached = [];
            $users = [];
            $avatars = [];

            foreach ($rows as $row) {
                if ((bool) $row->is_super_admin) {
                    $detached[] = (int) $row->id;
                } else {
                    $users[(int) $row->id] = (string) $row->email;

                    if (is_string($row->avatar_path) && $row->avatar_path !== '') {
                        $avatars[] = $row->avatar_path;
                    }
                }
            }
```

and replace

```php
            DB::afterCommit(fn (): bool => $this->permissionRegistrar->forgetCachedPermissions());
        });
```

with

```php
            DB::afterCommit(fn (): bool => $this->permissionRegistrar->forgetCachedPermissions());

            // §4.3: the deleted users' profile photos go once the purge is committed; a detached super admin keeps theirs.
            if ($avatars !== []) {
                DB::afterCommit(fn (): bool => Storage::disk('public')->delete($avatars));
            }
        });
```

The paths come from the rows the purge already locks (one statement, in id order), so the lock order does not change. `DeleteUser` is untouched: a soft-deleted user keeps the file, because `RestoreUser` can bring them back.

- [ ] **Step 7: The profile page (CD-7, CD-10)**

`resources/views/pages/settings/profile/profile.php` becomes:

```php
<?php

declare(strict_types=1);

use App\Actions\Settings\RequestEmailChange;
use App\Actions\Settings\UpdateLocale;
use App\Actions\Users\RemoveAvatar;
use App\Actions\Users\UpdateAvatar;
use App\Concerns\ProfileValidationRules;
use App\Concerns\ResolvesCurrentUser;
use App\Enums\Locale;
use App\Support\RateLimits\UserActionLimit;
use App\Tenancy\Auth\Impersonation;
use Flux\Flux;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('Profile settings')] class extends Component
{
    use ProfileValidationRules;
    use ResolvesCurrentUser;
    use WithFileUploads;

    public string $name = '';

    public string $email = '';

    public string $locale = '';

    /** The chosen profile photo, uploaded to Livewire's temporary disk until saveAvatar() stores it (§6.6). */
    public ?TemporaryUploadedFile $avatar = null;

    /** Whether the photo section renders: never during an impersonation; the actions check again on every request. */
    #[Locked]
    public bool $canManageAvatar = false;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $user = $this->currentUser();

        $this->name = $user->name;
        $this->email = $user->email;
        $this->locale = Impersonation::localeFor($user)->value;
        $this->canManageAvatar = ! Impersonation::isActive();
    }

    /** The stored photo's URL for the preview, or null for the initials. */
    #[Computed]
    public function avatarUrl(): ?string
    {
        return $this->currentUser()->avatarUrl();
    }

    /** The initials the preview shows without a photo. */
    #[Computed]
    public function initials(): string
    {
        return $this->currentUser()->initials();
    }

    /**
     * Save the name and the language; a new email address only applies after it is confirmed through the
     * mailed link (E1). A new language reloads the page, so it renders in that language (§4.10); the result
     * is then flashed in that language for the reloaded page instead of a toast the reload would drop (G3-06).
     * Sending the confirmation link is limited per user (CD-3): the slot is reserved before anything is saved,
     * so a refusal saves nothing, and given back when any part of the save throws (UserActionLimit::around()).
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

    /**
     * Store the chosen photo as the user's own (§6.6): validated here, re-encoded and stored by UpdateAvatar, whose
     * "could not be processed" error lands on the same field. The user menu re-renders on avatar-updated.
     */
    public function saveAvatar(UpdateAvatar $updateAvatar): void
    {
        $this->ensureAvatarManageable();

        $this->validate([
            'avatar' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096'],
        ]);

        $file = $this->avatar ?? throw new LogicException('The validated photo is missing.');

        $updateAvatar->handle($this->currentUser(), $file);

        $this->reset('avatar');
        unset($this->avatarUrl);
        $this->dispatch('avatar-updated');
        Flux::toast(variant: 'success', text: __('Profile photo saved.'));
    }

    /** Remove the user's own photo; the initials show again (§6.6). */
    public function removeAvatar(RemoveAvatar $removeAvatar): void
    {
        $this->ensureAvatarManageable();

        $removeAvatar->handle($this->currentUser());

        $this->reset('avatar');
        unset($this->avatarUrl);
        $this->dispatch('avatar-updated');
        Flux::toast(variant: 'success', text: __('Profile photo removed.'));
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return ['avatar' => __('profile photo')];
    }

    /**
     * §6.6, CD-7: the impersonated user's photo is theirs, so the photo actions answer 403 during an impersonation,
     * read on every request rather than from the locked snapshot.
     */
    private function ensureAvatarManageable(): void
    {
        abort_if(Impersonation::isActive(), 403);
    }
};
```

Only the imports, the two properties, the last line of `mount()`, the two computed properties and the methods after `updateProfileInformation()` are new. `updateProfileInformation()` is unchanged. `LogicException` needs no import: the class lives in the global namespace (DEC-34 (16) (a)).

The rules are CD-7's, word for word. Laravel's `image` rule refuses SVG, `mimes:jpeg` also accepts `jpg`, and `dimensions` reads the header with `getimagesize()`. `validationAttributes()` makes the messages say "profile photo" / "profielfoto" instead of "avatar".

`resources/views/pages/settings/profile/profile.blade.php`: replace

```blade
        </form>
    </x-pages::settings.layout>
```

with

```blade
        </form>

        @if ($canManageAvatar)
            <div class="my-6 w-full space-y-6" data-test="profile-photo">
                <flux:separator variant="subtle" />

                <div>
                    <flux:heading level="3">{{ __('Profile photo') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('JPEG, PNG or WebP, at most 2 MB and 4096 × 4096 pixels. We crop it to a square.') }}</flux:text>
                </div>

                <div class="flex items-center gap-4">
                    <flux:avatar size="xl" :src="$this->avatarUrl" :name="$name" :initials="$this->initials" data-test="profile-photo-preview" />

                    @if ($this->avatarUrl !== null)
                        <flux:button variant="ghost" size="sm" icon="trash" wire:click="removeAvatar" data-test="remove-avatar-button">
                            {{ __('Remove photo') }}
                        </flux:button>
                    @endif
                </div>

                <form wire:submit="saveAvatar" class="space-y-4">
                    @if (Skv::hasFluxPro())
                        <flux:file-upload wire:model="avatar" :label="__('New photo')">
                            <flux:file-upload.dropzone :heading="__('Drop a photo here or click to choose one')" :text="__('JPEG, PNG or WebP, at most 2 MB')" inline />
                        </flux:file-upload>

                        @if ($avatar !== null)
                            <flux:file-item :heading="$avatar->getClientOriginalName()" :size="$avatar->getSize()" icon="photo" />
                        @endif
                    @else
                        <flux:input type="file" accept="image/jpeg,image/png,image/webp" wire:model="avatar" :label="__('New photo')" />
                    @endif

                    <flux:button variant="primary" type="submit" data-test="save-avatar-button">{{ __('Save photo') }}</flux:button>
                </form>
            </div>
        @endif
    </x-pages::settings.layout>
```

How the section works:
- The preview is the stored photo (`flux:avatar` with `src`, else the initials).
- The Pro `flux:file-item` shows the chosen file's name and size. It does not show `temporaryUrl()`: Livewire's `preview_mimes` include `svg`, and the file has not been validated yet.
- Both branches bind only `wire:model="avatar"`, so `FluxProLayerTest` sees the same properties.
- `Skv` is the existing Blade alias, used by every other Pro branch.

- [ ] **Step 8: The user menu and the users index (§6.6)**

`resources/views/components/user-menu/user-menu.blade.php`: replace the three avatar lines:

```blade
            <flux:sidebar.profile :name="$user->name" :avatar="$user->avatarUrl()" :initials="$user->initials()" icon:trailing="chevrons-up-down" data-test="sidebar-menu-button" />
```

```blade
            <flux:profile :avatar="$user->avatarUrl()" :initials="$user->initials()" icon-trailing="chevron-down" />
```

```blade
                <flux:avatar :src="$user->avatarUrl()" :name="$user->name" :initials="$user->initials()" />
```

`resources/views/components/user-menu/user-menu.php`: add `use Livewire\Attributes\On;` after `use Livewire\Attributes\Locked;`, and add this method before `with()`:

```php
    /** §6.6: the profile page saved or removed the photo; rendering again reads the user's new photo. */
    #[On('avatar-updated')]
    public function refreshAvatar(): void {}
```

`with()` already reads `currentUser()`, the same `Auth::user()` instance the action updated.

`resources/views/pages/users/index/index.blade.php`: replace the name cell

```blade
                    <flux:table.cell variant="strong">
                        @if ($user->status === \App\Enums\UserStatus::PendingActivation)
                            <flux:link :href="route('invitations.pending')" wire:navigate data-test="pending-user-link-{{ $user->id }}">{{ $user->name }}</flux:link>
                        @elseif ($abilities['view'] && $this->view !== 'archived')
                            <flux:link :href="route('users.edit', ['user' => $user->id])" wire:navigate>{{ $user->name }}</flux:link>
                        @else
                            <div>{{ $user->name }}</div>
                        @endif
                        <flux:text size="sm">{{ $user->email }}</flux:text>
                    </flux:table.cell>
```

with

```blade
                    <flux:table.cell variant="strong">
                        <div class="flex items-center gap-3">
                            <flux:avatar size="sm" :src="$user->avatarUrl()" :name="$user->name" :initials="$user->initials()" alt="" />
                            <div>
                                @if ($user->status === \App\Enums\UserStatus::PendingActivation)
                                    <flux:link :href="route('invitations.pending')" wire:navigate data-test="pending-user-link-{{ $user->id }}">{{ $user->name }}</flux:link>
                                @elseif ($abilities['view'] && $this->view !== 'archived')
                                    <flux:link :href="route('users.edit', ['user' => $user->id])" wire:navigate>{{ $user->name }}</flux:link>
                                @else
                                    <div>{{ $user->name }}</div>
                                @endif
                                <flux:text size="sm">{{ $user->email }}</flux:text>
                            </div>
                        </div>
                    </flux:table.cell>
```

`alt=""` marks the photo as decorative, because the name is next to it.

- [ ] **Step 9: Lang keys**

Add to both files, at their sorted places (PHP `sort()`, byte-wise; the lower-case `profile photo` lands between `pg_dump was not found` and `psql and pg_dump are available for the schema dump`):

| Key | `nl.json` | `en.json` |
|---|---|---|
| `Drop a photo here or click to choose one` | `Sleep hier een foto heen of klik om er een te kiezen` | `Drop a photo here or click to choose one` |
| `JPEG, PNG or WebP, at most 2 MB` | `JPEG, PNG of WebP, maximaal 2 MB` | `JPEG, PNG or WebP, at most 2 MB` |
| `JPEG, PNG or WebP, at most 2 MB and 4096 × 4096 pixels. We crop it to a square.` | `JPEG, PNG of WebP, maximaal 2 MB en 4096 × 4096 pixels. We snijden hem bij tot een vierkant.` | `JPEG, PNG or WebP, at most 2 MB and 4096 × 4096 pixels. We crop it to a square.` |
| `New photo` | `Nieuwe foto` | `New photo` |
| `Profile photo` | `Profielfoto` | `Profile photo` |
| `Profile photo removed.` | `Profielfoto verwijderd.` | `Profile photo removed.` |
| `Profile photo saved.` | `Profielfoto opgeslagen.` | `Profile photo saved.` |
| `Remove photo` | `Foto verwijderen` | `Remove photo` |
| `Save photo` | `Foto opslaan` | `Save photo` |
| `This image could not be processed.` | `Deze afbeelding kon niet worden verwerkt.` | `This image could not be processed.` |
| `profile photo` | `profielfoto` | `profile photo` |

- [ ] **Step 10: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Settings tests/Feature/FluxPro tests/Feature/Navigation tests/Feature/Users tests/Feature/Organisations tests/Feature/Audit tests/Feature/LangCoverageTest.php tests/Feature/LangFilesTest.php tests/Arch`

Expected: PASS. `ProVariantsTest` is skipped without Pro, and `LockOrderTest` skips on SQLite; it runs in the gate's `composer test:pgsql`. A focused run over several paths does not take `--parallel` (DEC-34 (16)).

- [ ] **Step 11: Verify by mutation**

Copy each file aside with `cp`, never `git stash`. Run `php artisan view:clear` after a Blade mutation. Each mutation must make the named test fail:

| # | Mutation | Fails | How |
|---|---|---|---|
| 1 | `UpdateAvatar`: `->orient()` → `->rotate(180)` | 'turns the photo by its EXIF orientation…' | "Failed asserting that 0 is greater than 200": the test sees which way up the photo is stored. Dropping `->orient()` alone passes, because intervention 4's decoder already turns the image (see Background). |
| 2 | `UpdateAvatar`: remove the `->cover(self::SIZE, self::SIZE)` line | 'stores an uploaded photo as a 512 × 512 WebP…' | "two arrays are identical" (`[800, 600, …]`) |
| 2a | `UpdateAvatar`: remove the `->toWebp()` line | the same test | the stored mime is `image/jpeg` |
| 3 | `UpdateAvatar`: remove the `DB::afterCommit(fn (): bool => $disk->delete($previous));` line | 'deletes the previous photo once a new one is saved' | two files |
| 4 | `UpdateAvatar`: remove `$disk->delete($path);` in the `catch` | 'keeps the previous photo and removes the new file when the save fails' | two files |
| 5 | `UpdateAvatar`: in the `catch (ImageException …)`, remove the `if` that throws `unreadable()` | 'refuses an image it cannot read…' | `Illuminate\Image\ImageException: Failed to process image: Failed to decode unsupported image format from binary data` |
| 5a | `UpdateAvatar`: make that `if` always true (`if (true) {`) | 'leaves a failure of the server's image processing a server error…' | the message is "This image could not be processed." instead of "GD broke." |
| 6 | `UpdateAvatar`: drop `\|\| $size[0] > self::MAX_SIDE \|\| $size[1] > self::MAX_SIDE` | 'refuses an image wider than 4096 pixels in the action itself…' | "Exception [ValidationException] not thrown" |
| 7 | profile page: drop `'dimensions:max_width=4096,max_height=4096'` | the 'wider than 4096 pixels' row | "Component has no matching failed rule … [dimensions]": the action's own guard answers "could not be processed" instead, which is why both exist |
| 8 | `PurgeOrganisation`: remove the avatars `DB::afterCommit(...)` | 'deletes the purged users' photo files…' | "true is false" |
| 9 | `PurgeOrganisation`: also collect `$row->avatar_path` for a super admin row | the same test | the promoted admin's file is gone |
| 10 | profile page: empty `ensureAvatarManageable()` | 'hides the photo section during an impersonation…' | the forged `saveAvatar` answers 200 with a validation error |
| 11 | profile page: `$this->canManageAvatar = true;` | the same test | `assertDontSee('data-test="profile-photo"')` fails |
| 12 | `RemoveAvatar`: remove the impersonation `if` | 'refuses both actions themselves…' | "Exception [AuthorizationException] not thrown" |
| 13 | `RemoveAvatar`: remove the early `return` for a missing path | 'records user.updated with changes.avatar…' | `TypeError` in `delete(null)` / three entries |
| 14 | `UpdateAvatar`: record `['avatar' => $path]` instead of `'set'` | the same test | "two arrays are identical" |
| 15 | user menu: remove `#[On('avatar-updated')]` | 're-renders the user menu…' | `EventHandlerDoesNotExist` |
| 16 | user menu Blade: drop `:avatar="$user->avatarUrl()"` on `flux:sidebar.profile` | 'shows the photo in the user menu, the users index…' | no `src` on the dashboard |
| 17 | users index: drop `:src="$user->avatarUrl()"` | the same test | no `src` on the users index |
| 18 | profile Blade: drop `accept="image/jpeg,image/png,image/webp"` | 'renders the free file input…' | `assertSee('accept=…')` fails |

Two tests are guards that already pass before the change, by design:
- 'records nothing when only the stored path changes in a plain save': the observer is an allow-list.
- 'keeps the photo file of a deleted user': `DeleteUser` never touched files.

Each passes as soon as the column exists, and keeps a later change from leaking the path or deleting a restorable user's photo.

- [ ] **Step 12: CHANGELOG**

Under `## [Unreleased]`, `### Added`, append:

```markdown
- Profile photos: upload a JPEG, PNG or WebP (at most 2 MB and 4096 × 4096 pixels) on the profile page. The photo is turned upright, cropped to a 512 × 512 square and stored as WebP without its metadata. It shows in the user menu and the users list, with the initials when there is none. Replacing or removing it deletes the old file, and purging an organisation deletes its users' photos. Not available during an impersonation. With Flux Pro the upload is a drop zone.
```

Under `### Changed`, append:

```markdown
- New dependency `intervention/image` ^4, through Laravel's own image API on the GD driver, for profile photos. GD needs WebP support; ext-exif is used, when loaded, to turn photos upright.
```

- [ ] **Step 13: The gate, then commit**

```bash
composer fix
vendor/bin/phpstan analyse tests/Feature/Settings/AvatarTest.php tests/Feature/Authorization/LockOrderTest.php
composer check
composer test:pgsql
php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser
npm run build && composer test:browser
npm run build:setup && git diff --exit-code resources/setup/setup.css
php artisan config:clear
```

On PostgreSQL, `LockOrderTest`'s two new rows must pass with `['users']`, and the SQL must end in ` limit 1 for update` (one row by id). Task 6 changes no setup view, so expect no stylesheet diff. The browser suite has no avatar test, but its smoke and settings pages render the new section.

```bash
git add database/migrations/0001_01_01_000009_add_avatar_path_to_users_table.php app/Models/User.php \
    app/Actions/Users/UpdateAvatar.php app/Actions/Users/RemoveAvatar.php app/Actions/Organisations/PurgeOrganisation.php \
    resources/views/pages/settings/profile/profile.php resources/views/pages/settings/profile/profile.blade.php \
    resources/views/components/user-menu/user-menu.php resources/views/components/user-menu/user-menu.blade.php \
    resources/views/pages/users/index/index.blade.php lang/nl.json lang/en.json CHANGELOG.md \
    tests/Feature/Settings/AvatarTest.php tests/Feature/Authorization/LockOrderTest.php \
    tests/Feature/FluxPro/FreeVariantsTest.php tests/Feature/FluxPro/ProVariantsTest.php
git commit -m "feat: add profile photos with a WebP square, initials fallback and cleanup on replace and purge

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: FM-19 limits (FM-19 = DEC-34 (16); CD-8)

M7c left two administrative mail senders without a limit (DEC-34 (16), FM-19): an administrator's change of another user's address on `users.edit`, which mails the old address (`EmailChangedByAdministrator`), and the organisation wizard, whose `CreateOrganisation` invites the first admin through `InviteUser`. This task puts both under `UserActionLimit` in the component, never in the action (CD-3 of M7c):
- `users.edit`'s `saveAccount()` runs the save under a new `UserActionLimit::addressChange()` (10 per 3600 s per acting administrator, `around()`, field `email`), but only when the address really changes, and only after the RL-18 password confirmation: a save parked for the confirmation sends nothing, so it counts nothing.
- `organisations.create`'s `create()` runs `CreateOrganisation::handle()` under the existing `UserActionLimit::invite()` (`around()`, field `adminEmail`), so the wizard and the invite page share one counter per inviter.

In both, the slot is reserved before anything is written, so a refusal saves or creates nothing, and given back when the save throws (a status the transaction refuses, an address taken between validation and save, a failed invitation), so only a completed send counts.

**Files:**
- Create:
  - `tests/Feature/Auth/AdministratorActionLimitsTest.php`
- Modify:
  - `app/Support/RateLimits/UserActionLimit.php` (the `addressChange()` factory, inserted as the first factory, alphabetically before Task 5's `bulkInvite()` and `emailChange()`; the `invite()` docblock names the wizard)
  - `resources/views/pages/users/edit/edit.php` (`saveAccount()`: the limit around the transaction when the address changes; the `UserActionLimit` import)
  - `resources/views/pages/organisations/create/create.php` (`create()`: the limit around `CreateOrganisation::handle()` inside the existing `try`; the `UserActionLimit` import)
  - `CHANGELOG.md`
- Test:
  - `tests/Feature/Auth/AdministratorActionLimitsTest.php` (new)
  - unchanged and green: `tests/Feature/Users/UsersEditTest.php`, `tests/Feature/Users/UpdateUserTest.php`, `tests/Feature/Organisations/OrganisationWizardTest.php`, `tests/Feature/Organisations/CreateOrganisationTest.php`, `tests/Feature/Auth/ActionLimitsTest.php`, `tests/Feature/Support/UserActionLimitTest.php`

**Interfaces:**
- Consumes:
  - `App\Support\RateLimits\UserActionLimit` as Task 5 leaves it: `around(User $user, string $field, Closure $action, int $cost = 1): mixed`, `hit(User $user, int $cost = 1): void`, `key(User $user): string`, `invite()` (10 / 60 s); Task 1's `logoutOtherDevices()` and Task 5's `bulkInvite()` only matter for where the new factory goes
  - `App\Actions\Users\UpdateUser::handle(User $actor, User $target, string $name, string $email): void`, `App\Actions\Users\UpdateUserStatus::handle(...)`, `App\Actions\Organisations\CreateOrganisation::handle(User $actor, string $name, string $slug, ?string $description, string $adminEmail, Locale $adminLocale): Organisation` (all unchanged)
  - `pageSnapshot()`, `callPageAction()`, `asOrgAdmin()`, `asSuperAdmin()`, `onAdminHost()`, `withPermissionsTeam()` (`tests/Pest.php`, helpers)
- Produces:
  - `UserActionLimit::addressChange(): self` = `new self('address-change', 10, 3600)`; key `address-change:{user id}`

**Notes for the implementer:**
- **Where the limit sits in `saveAccount()`.** The order stays: authorise, validate, decide `$email` / `$status`, authorise `updateStatus`, park for the password confirmation (`confirmationParked()`, RL-18), then save. The limit wraps only the save (the `DB::transaction()` with `UpdateUserStatus` and `UpdateUser`), because that is the part that queues `EmailChangedByAdministrator` (a `ShouldQueue` notification sent after commit). So:
  - a validation error (a taken or malformed address) never reserves a slot
  - a save parked for the confirmation counts nothing; the save after the confirmation (from the restored draft) counts once
  - a refusal throws before the transaction, so the name, the status and the address all stay (G4-08's all or nothing)
  - a save that throws inside the transaction (`UpdateUserStatus` refuses to disable the last admin, or `UpdateUser`'s unique-violation race, FU3-8) gives the slot back through `around()`'s `catch (Throwable)`
  - `$changesEmail` compares after the page's `Str::lower(trim())`, so the same address in other letters is no change and counts nothing; your own and a pending user's address are never editable (`$email = $target->email`), so they never count
- **A refused attempt counts.** `around()` reserves with `attempt()`, which counts before it checks; a refused reservation is never given back (M7c CD-3). The test asserts 11 after the refusal on purpose.
- **The wizard's refusal lands on `adminEmail`.** `around()` sits inside the existing `try`, with the field `adminEmail`; the `catch` remaps only `email`, so the refusal passes through unchanged and the invitation's own `email` errors still become `adminEmail`. Livewire keeps only errors on component properties in the snapshot, and `adminEmail` is one, so the test reads the refusal from the snapshot. A refusal throws before `CreateOrganisation` opens its transaction, so no organisation, pending user or invitation exists.
- **One counter for invitations.** The wizard counts under `invite()`, the invite page's limit, so a super admin's ten invitations a minute include first admins. Task 5's bulk page counts under `bulkInvite()` and never consumes `invite()` (CD-6); this task does not change that.
- **Central reads in tests.** On the admin host no tenant is bound, so `User::query()` throws under strict mode (`TenantNotResolvedException`); the tests read `users` with `DB::table()`.
- **No lang keys:** the refusal reuses M7c's "Too many attempts. Try again in :seconds seconds.".
- **No `git stash`.** For mutation checks copy the file aside with `cp`, change it, run the test, and copy it back.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Auth/AdministratorActionLimitsTest.php` (new). Every limit is exercised over HTTP (`pageSnapshot()` + `callPageAction()`), because `Livewire::test()` skips middleware and `users.edit` needs the password confirmation of RL-18:

```php
<?php

declare(strict_types=1);

/** covers §4.3, §4.4, §4.5 */

use App\Authorization\Role;
use App\Enums\UserStatus;
use App\Models\Organisation;
use App\Models\User;
use App\Notifications\EmailChangedByAdministrator;
use App\Support\RateLimits\UserActionLimit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
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

/** The users.edit page of $target as the signed-in administrator sees it, with a fresh password confirmation (RL-18). */
$editPage = function (User $target): array {
    withSession(['auth.password_confirmed_at' => time()]);

    return pageSnapshot(route('users.edit', ['user' => $target->id]), 'pages::users.edit');
};

/** Creates an organisation through the wizard over HTTP, as the signed-in super admin on the admin host. */
$createOrganisation = fn (string $slug, string $adminEmail): TestResponse => callPageAction(
    pageSnapshot(route('organisations.create'), 'pages::organisations.create'),
    'create',
    updates: ['name' => ucfirst($slug), 'slug' => $slug, 'adminEmail' => $adminEmail],
);

it('changes ten addresses an hour per administrator, refuses the eleventh without saving anything and never counts a kept address (FM-19)', function () use ($errorsOf, $throttled): void {
    Notification::fake();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create(['name' => 'Mila', 'email' => 'mila@acme.test']);
    $admin = asOrgAdmin($organisation);
    $key = UserActionLimit::addressChange()->key($admin);
    $page = route('users.edit', ['user' => $member->id]);
    UserActionLimit::addressChange()->hit($admin, cost: 9);

    // Without a fresh confirmation the page parks the form and sends the administrator to confirm first (RL-18):
    // nothing is sent, so nothing counts.
    callPageAction(pageSnapshot($page, 'pages::users.edit'), 'saveAccount', updates: ['email' => 'tenth@acme.test'])
        ->assertOk()
        ->assertJsonPath('components.0.effects.redirect', route('password.confirm'));

    expect(RateLimiter::attempts($key))->toBe(9)
        ->and($key)->toBe('address-change:'.$admin->id);

    $this->post(route('password.confirm.store'), ['password' => 'password'])->assertRedirect($page);

    expect($errorsOf(callPageAction(pageSnapshot($page, 'pages::users.edit'), 'saveAccount')))->toBe([])
        ->and($member->fresh()?->email)->toBe('tenth@acme.test')
        ->and(RateLimiter::attempts($key))->toBe(10);

    $this->travel(10)->minutes();

    $refused = callPageAction(pageSnapshot($page, 'pages::users.edit'), 'saveAccount', updates: [
        'name' => 'Mila de Vries',
        'email' => 'eleventh@acme.test',
        'status' => UserStatus::Disabled->value,
    ]);

    expect($errorsOf($refused))->toBe(['email' => [$throttled(3000)]])
        ->and($member->fresh()?->only(['name', 'email']))->toBe(['name' => 'Mila', 'email' => 'tenth@acme.test'])
        ->and($member->fresh()?->status)->toBe(UserStatus::Active);
    Notification::assertSentOnDemandTimes(EmailChangedByAdministrator::class, 1);

    // The refusal counted (attempt()). A save that keeps the address, here in other letters, sends nothing: it is
    // never refused and adds nothing.
    $kept = callPageAction(pageSnapshot($page, 'pages::users.edit'), 'saveAccount', updates: [
        'name' => 'Mila de Vries',
        'email' => 'TENTH@acme.test',
        'status' => UserStatus::Disabled->value,
    ]);

    expect($errorsOf($kept))->toBe([])
        ->and($member->fresh()?->only(['name', 'email']))->toBe(['name' => 'Mila de Vries', 'email' => 'tenth@acme.test'])
        ->and($member->fresh()?->status)->toBe(UserStatus::Disabled)
        ->and(RateLimiter::attempts($key))->toBe(11);
});

it('gives the slot back when the address is refused or the save fails (FM-19)', function () use ($errorsOf, $editPage): void {
    Notification::fake();
    $organisation = Organisation::factory()->create();
    $onlyAdmin = User::factory()->for($organisation)->create(['name' => 'Olga', 'email' => 'olga@acme.test']);
    withPermissionsTeam($organisation->id, fn (): User => $onlyAdmin->assignRole(Role::OrganisationAdmin->value));
    User::factory()->for($organisation)->create(['email' => 'taken@acme.test']);
    $superAdmin = asSuperAdmin();
    onAdminHost();
    $key = UserActionLimit::addressChange()->key($superAdmin);
    $snapshot = $editPage($onlyAdmin);
    UserActionLimit::addressChange()->hit($superAdmin, cost: 9);

    // An address the validation refuses.
    expect($errorsOf(callPageAction($snapshot, 'saveAccount', updates: ['email' => 'taken@acme.test'])))->toHaveKey('email')
        ->and(RateLimiter::attempts($key))->toBe(9);

    // A valid address whose save fails inside the transaction: UpdateUserStatus refuses to disable the last admin (G4-08).
    expect($errorsOf(callPageAction($snapshot, 'saveAccount', updates: ['email' => 'olga.new@acme.test', 'status' => UserStatus::Disabled->value])))->toHaveKey('status')
        ->and(RateLimiter::attempts($key))->toBe(9)
        ->and($onlyAdmin->fresh()?->email)->toBe('olga@acme.test');

    expect($errorsOf(callPageAction($snapshot, 'saveAccount', updates: ['email' => 'olga.new@acme.test'])))->toBe([])
        ->and(RateLimiter::attempts($key))->toBe(10)
        ->and($onlyAdmin->fresh()?->email)->toBe('olga.new@acme.test');
    Notification::assertSentOnDemandTimes(EmailChangedByAdministrator::class, 1);
});

it('creates ten organisations a minute per super admin, gives a failed invitation\'s slot back and refuses the eleventh on the admin address (FM-19)', function () use ($errorsOf, $throttled, $createOrganisation): void {
    $superAdmin = asSuperAdmin();
    onAdminHost();
    $key = UserActionLimit::invite()->key($superAdmin);
    // The wizard's invitation shares the invite page's counter.
    UserActionLimit::invite()->hit($superAdmin, cost: 9);
    // The address is taken between the page's validation and the invitation (FU3-8): InviteUser refuses it and the
    // organisation rolls back.
    Organisation::created(function (Organisation $organisation): void {
        if ($organisation->slug === 'raced') {
            DB::table('users')->insert([
                'organisation_id' => null,
                'name' => 'Racer',
                'email' => 'first@raced.test',
                'password' => 'x',
                'status' => UserStatus::Active->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    expect($errorsOf($createOrganisation('raced', 'first@raced.test')))->toHaveKey('adminEmail')
        ->and(Organisation::query()->where('slug', 'raced')->exists())->toBeFalse()
        ->and(RateLimiter::attempts($key))->toBe(9);

    expect($errorsOf($createOrganisation('tenth', 'first@tenth.test')))->toBe([])
        ->and(Organisation::query()->where('slug', 'tenth')->exists())->toBeTrue()
        ->and(RateLimiter::attempts($key))->toBe(10);

    $this->travel(20)->seconds();

    expect($errorsOf($createOrganisation('eleventh', 'first@eleventh.test')))->toBe(['adminEmail' => [$throttled(40)]])
        ->and(Organisation::query()->where('slug', 'eleventh')->exists())->toBeFalse()
        ->and(DB::table('users')->where('email', 'first@eleventh.test')->exists())->toBeFalse();
});

it('counts both limits per acting administrator (FM-19)', function () use ($errorsOf, $throttled, $editPage, $createOrganisation): void {
    Notification::fake();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create(['email' => 'mila@acme.test']);
    $busyAdmin = asOrgAdmin($organisation);
    UserActionLimit::addressChange()->hit($busyAdmin, cost: 10);

    expect($errorsOf(callPageAction($editPage($member), 'saveAccount', updates: ['email' => 'refused@acme.test'])))->toBe(['email' => [$throttled(3600)]]);

    asOrgAdmin($organisation);

    expect($errorsOf(callPageAction($editPage($member), 'saveAccount', updates: ['email' => 'mila.new@acme.test'])))->toBe([])
        ->and($member->fresh()?->email)->toBe('mila.new@acme.test');

    $busySuperAdmin = asSuperAdmin();
    onAdminHost();
    UserActionLimit::invite()->hit($busySuperAdmin, cost: 10);

    expect($errorsOf($createOrganisation('refused', 'first@refused.test')))->toBe(['adminEmail' => [$throttled(60)]]);

    asSuperAdmin();

    expect($errorsOf($createOrganisation('created', 'first@created.test')))->toBe([])
        ->and(Organisation::query()->where('slug', 'created')->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Auth/AdministratorActionLimitsTest.php`

Expected: FAIL, all four:
- the three `users.edit` tests with "Call to undefined method App\Support\RateLimits\UserActionLimit::addressChange()"
- 'creates ten organisations a minute per super admin, …' with "Failed asserting that 9 is identical to 10." (the wizard counts nothing yet)

Once Step 3 has added the factory alone, the `users.edit` tests fail on behaviour instead: "Failed asserting that 9 is identical to 10." (the address change counts nothing), and 'counts both limits per acting administrator' because the busy administrator's change goes through.

- [ ] **Step 3: `UserActionLimit::addressChange()`**

`app/Support/RateLimits/UserActionLimit.php`: insert the factory as the first named factory, directly after the constructor (before `bulkInvite()` from Task 5, which comes before `emailChange()`):

```php
    /**
     * Change another user's address on users.edit: 10 changes per hour per administrator (FM-19). Each change mails
     * the old address, so the limit counts sends as emailChange() does.
     */
    public static function addressChange(): self
    {
        return new self('address-change', 10, 3600);
    }
```

and replace the `invite()` docblock:

```php
    /** Invite a user from the invite page: 10 invitations per minute. */
```

```php
    /** Invite a user from the invite page, or an organisation's first admin from the wizard (FM-19): 10 invitations per minute. */
```

- [ ] **Step 4: The limit on `users.edit`'s address change**

`resources/views/pages/users/edit/edit.php`: add `use App\Support\RateLimits\UserActionLimit;` after `use App\Models\User;`, and replace the whole `saveAccount()` method, docblock included:

```php
    /**
     * Save name, address and status (USR-12), all or nothing (G4-08). Your own and a pending user's address are
     * read-only and never sent (E1); another user's new address needs a fresh password confirmation first (RL-18).
     * A new address mails the old one, so it is limited per administrator (FM-19): the slot is reserved after the
     * confirmation and before anything is saved, so a refusal saves nothing, and given back when the save throws
     * (UserActionLimit::around()). A save that keeps the address counts nothing.
     */
    public function saveAccount(UpdateUser $updateUser, UpdateUserStatus $updateUserStatus): void
    {
        $actor = $this->currentUser();
        $target = $this->target();
        $this->authorize('update', $target);

        $pending = $target->status === UserStatus::PendingActivation;
        $emailEditable = $actor->isNot($target) && ! $pending;
        $this->email = Str::lower(trim($this->email));

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

- [ ] **Step 5: The limit on the organisation wizard**

`resources/views/pages/organisations/create/create.php`: add `use App\Support\RateLimits\UserActionLimit;` after `use App\Rules\AvailableEmail;`, and replace the whole `create()` method:

```php
    public function create(CreateOrganisation $createOrganisation): void
    {
        $this->authorize('create', Organisation::class);

        $actor = $this->currentUser();

        $this->validate([
            'name' => $this->organisationNameRules(null, $actor->isSuperAdmin()),
            'slug' => $this->organisationSlugRules(null, $actor->isSuperAdmin()),
            'description' => $this->organisationDescriptionRules(),
            // A new organisation has no users yet: any holder of the address is elsewhere (W9 generic message).
            'adminEmail' => ['required', 'string', 'email', 'max:255', new AvailableEmail(null)],
            'adminLocale' => ['required', 'string', Rule::enum(Locale::class)],
        ]);

        try {
            // FM-19: the first admin's invitation counts under the invite page's limit, here and not in the action.
            // The slot is reserved before anything is created and given back when the creation throws; a refusal
            // lands on adminEmail and creates nothing.
            $organisation = UserActionLimit::invite()->around($actor, 'adminEmail', fn (): Organisation => $createOrganisation->handle(
                $actor,
                $this->name,
                $this->slug,
                $this->description,
                $this->adminEmail,
                Locale::from($this->adminLocale),
            ));
        } catch (ValidationException $exception) {
            // The invitation step reports the address as "email"; this form calls it "adminEmail".
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => [$field === 'email' ? 'adminEmail' : $field => $messages])
                ->all());
        }

        session()->flash('status', __('Organisation :name created. Its first admin has been invited.', ['name' => $organisation->name]));
        $this->redirectRoute('organisations.index', navigate: true);
    }
```

- [ ] **Step 6: CHANGELOG**

`CHANGELOG.md`, under `## [Unreleased]` › `### Added`, directly after the line that starts with `- Per-user limits on sending email-change links`:

```markdown
- Per-administrator limits on changing another user's address (10 per hour) and on creating organisations, whose first-admin invitation counts under the invitation limit (10 per minute); a refused or failed change or creation saves nothing and does not count.
```

No lang keys.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Auth/AdministratorActionLimitsTest.php tests/Feature/Auth/ActionLimitsTest.php tests/Feature/Support/UserActionLimitTest.php tests/Feature/Users tests/Feature/Organisations`

Expected: PASS (the existing `users.edit` and wizard tests make at most one address change or creation each, so they stay under the limits).

- [ ] **Step 8: Verify by mutation**

For each mutation: copy the file aside with `cp`, change it, run `php artisan test --compact tests/Feature/Auth/AdministratorActionLimitsTest.php` and confirm the named tests fail, then copy the original back and confirm the file passes (never `git stash`). Name each mutation and its result in the task report.
1. `users.edit`: replace `UserActionLimit::addressChange()->around($actor, 'email', $save);` with `$save();`. Expect 'changes ten addresses an hour …' and 'gives the slot back …' ("9 is identical to 10") and 'counts both limits per acting administrator' (no `email` refusal) to fail.
2. `users.edit`: call the limit in the `else` branch too (`UserActionLimit::addressChange()->around($actor, 'email', $save);` instead of `$save();`). Expect 'changes ten addresses an hour …' to fail on the kept-address save (refused with the limit message).
3. `users.edit`: replace the `around()` call with `UserActionLimit::addressChange()->attempt($actor, 'email'); $save();` (no release). Expect 'gives the slot back …' to fail ("10 is identical to 9" after the refused status).
4. `users.edit`: count before the confirmation: insert `if ($changesEmail) { UserActionLimit::addressChange()->attempt($actor, 'email'); }` before `if ($changesEmail && $this->confirmationParked($target))` and replace the `around()` call with `$save();`. Expect 'changes ten addresses an hour …' ("10 is identical to 9" after the parked save) and 'gives the slot back …' to fail.
5. `organisations.create`: call `$createOrganisation->handle(...)` directly again, without `around()`. Expect 'creates ten organisations a minute …' ("9 is identical to 10") and 'counts both limits per acting administrator' (no `adminEmail` refusal) to fail.
6. `organisations.create`: reserve outside the remap on the wrong field: insert `UserActionLimit::invite()->reserve($actor, 'email');` before the `try`, call `handle()` directly inside it and add `UserActionLimit::invite()->release($actor);` as the first line of the `catch`. Expect 'creates ten organisations a minute …' (no `adminEmail` refusal on the eleventh) and 'counts both limits per acting administrator' to fail.
7. `organisations.create`: replace `around()` with `UserActionLimit::invite()->attempt($actor, 'adminEmail');` before a direct `handle()` call (no release). Expect 'creates ten organisations a minute …' to fail ("10 is identical to 9" after the raced invitation).

- [ ] **Step 9: The gate, then commit**

Run the Global Constraints gate:
- `composer check`
- `composer test:pgsql` (the rate limiter runs on the default cache store, so PostgreSQL changes nothing here; the transaction refusal and the raced invitation roll back the same way)
- the modules-off run
- `npm run build && composer test:browser`
- `npm run build:setup && git diff --exit-code resources/setup/setup.css` (no setup view changes, so expect no diff)
- `php artisan config:clear`

```bash
git add app/Support/RateLimits/UserActionLimit.php resources/views/pages/users/edit/edit.php \
    resources/views/pages/organisations/create/create.php tests/Feature/Auth/AdministratorActionLimitsTest.php CHANGELOG.md
git commit -m "feat: limit an administrator's address changes and the organisation wizard's invitations

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Documentation, the spec amendments and DEC-35 (every task's docs; CD-1…CD-10)

**Files:**
- Modify:
  - `README.md` (Step 1)
  - `docs/handleiding.html` (Step 2, the same content in Dutch, plus the status lines)
  - `.ai/guidelines/skv3.md` (Step 3); then regenerate the git-ignored `AGENTS.md`
  - `docs/superpowers/specs/2026-10-10-skv3-design.md` (Step 4)
  - `docs/decisions.md` (DEC-35, new, Step 5)
  - `CHANGELOG.md` (only to fix lines Tasks 1–7 left inconsistent; Step 6)
- Test: none new. `LangCoverageTest`, `tests/Arch/*` and the docs-pinning tests under `tests/Feature/Tooling` must stay green.

**Interfaces:**
- Consumes: the task reports of Tasks 1–7 (`.superpowers/sdd/2026-10-17-m7d-account-extras/task-N-report.md`, each one's "Deviations from the brief") and the ledger `progress.md`, plus the code as merged on the branch. Where a statement below and the code disagree, **the code wins** and the report records the difference.
- Produces: no code.

- [ ] **Step 1: README**

Write each statement in the section named, in the README's existing register (short, imperative where it instructs). Never quote a secret value.

- **Security settings, sessions (Task 1):**
  - Settings → Security lists your sessions: browser and platform, IP, last activity and "This device". Only sessions within `SESSION_LIFETIME` are listed.
  - "Log out other devices" asks for your password (5 attempts a minute). On their next request it signs out every other session and every remember-me cookie, on every host.
  - The section is hidden during an impersonation.
  - The list needs `SESSION_DRIVER=database`. With another driver the list is empty, but the button still works, because it rehashes the password.
- **Second factor (Tasks 2, 3):**
  - On an organisation's edit page, its administrators and super admins can switch on "Require a second factor". The change asks for your password. An organisation admin needs a second factor of their own first.
  - Members without one are sent to Settings → Security with a notice until they set up two-factor authentication or, with passkeys on, a passkey.
  - Impersonation sessions are not affected.
  - `REQUIRE_SECOND_FACTOR_SUPER_ADMINS` (default `false`) requires one of every super admin, on every host.
  - Routes that stay reachable are listed in `config('skv.second_factor.exempt_routes')`.
- **Activity log export (Task 4):**
  - "Export CSV" downloads exactly what the filters show, as a streamed CSV: UTF-8 with BOM, ISO 8601 dates in the app's time zone.
  - The columns are date, event key, action, done by, subject, organisation (central hosts only), IP address, user agent and properties (JSON).
  - IP and user agent stay empty where the log hides them for platform staff.
  - Each export is in the log (`audit.exported`). Exports are limited to 5 per minute per user.
- **Bulk invitations (Task 5):**
  - Reach it through "Invite several people" on the pending invitations page. On a central host, a super admin first picks the organisation.
  - Paste addresses (one per line, or separated by commas or semicolons) or upload a CSV of at most 512 KB. The CSV has an email column and an optional `nl`/`en` locale column; the header row is optional, recognised by names like email/e-mailadres and locale/language/lang/taal. Commas or semicolons are fine, and a BOM is allowed.
  - Roles and the default language are chosen once. A list has at most 100 rows.
  - The preview marks each row Ready, or skipped with its reason: invalid address, listed twice, unknown language, or "This address can't be invited."
  - The summary shows what was sent and what was skipped. Mails are queued in each row's language.
  - The limit is 100 invitations per hour per administrator. Skipped rows do not count, and bulk invitations do not use the single-invite limit.
  - With Flux Pro the file can be dropped on an upload zone.
- **Profile photo (Task 6):**
  - Upload a JPEG, PNG or WebP of at most 2 MB and 4096 × 4096 px. It is turned upright, cropped to 512 × 512 and stored as WebP without metadata.
  - It is stored at `APP_URL/storage/avatars/{organisation id|platform}/{id}-{random}.webp`: a public but unguessable URL.
  - It needs `public/storage` (setup creates the link; the doctor's `storage-link` check repairs it) and GD with WebP support. Without WebP support an upload fails with a reported server error. ext-exif is optional; without it a photo keeps the orientation it was stored with.
  - Initials show wherever there is no photo.
  - Purging an organisation deletes its users' photos. Deleting a user keeps the photo for a restore.
  - The section is hidden during an impersonation.
- **Limits (Tasks 1, 5, 7):** add to the existing limits list:
  - "Log out other devices": 5 per minute.
  - Bulk invitations: 100 per hour.
  - An administrator changing another user's address: 10 per hour.
  - The invite limit also covers the first administrator's invitation from the organisation wizard.
- **Dependencies:** `intervention/image` ^4, used through Laravel's `Illuminate\Image` on the GD driver.

- [ ] **Step 2: Handleiding (Dutch)**

`docs/handleiding.html`, in its informal register ("je"):
- Replace the line at :911 ("Een overzicht van je sessies met 'andere apparaten uitloggen' komt in M7d.") with the sessions list and "Andere apparaten uitloggen". Cover the password, the limit of 5 per minute, the log entry "Andere apparaten uitgelogd", that it is hidden during an impersonation, and the `SESSION_DRIVER=database` note.
- A section "Tweede factor verplicht":
  - how to switch it on, and the password prompt
  - an administrator needs their own second factor first
  - what members see: they are sent to Beveiliging with a notice
  - with passkeys off only two-factor authentication counts, and the text says so
  - removing your last factor sends you back to Beveiliging
  - an impersonation is not affected
  - `REQUIRE_SECOND_FACTOR_SUPER_ADMINS`
  - a newly invited member of such an organisation lands on Beveiliging right after accepting, and is asked for the password they just chose
- Activiteitenlog: "CSV exporteren".
  - It exports exactly what the filters show and opens directly in Excel.
  - IP and browser stay empty where the log hides them for platform staff.
  - Cells starting with `=`, `+`, `-`, `@`, a tab or a CR get a leading `'`.
  - Every export appears in the log; at most five per minute.
- Uitnodigingen: "Meerdere mensen uitnodigen", with the content of Step 1's bulk bullets.
- Instellingen › Profiel: "Profielfoto", with the content of Step 1's photo bullets. With Flux Pro it is a drop zone.
- Limieten:
  - Add "Andere apparaten uitloggen: 5 keer per minuut", "Meerdere uitnodigingen: 100 per uur per beheerder" and "Het e-mailadres van iemand anders wijzigen (als beheerder): 10 keer per uur".
  - Extend the invite bullet with "(ook de eerste beheerder bij een nieuwe organisatie)".
  - Remove the sentence at :1339 saying that two administrative actions have no limit yet.
- Bronnen: add `resources/views/pages/settings/sessions/*`, `app/Support/UserAgent.php`, `app/Tenancy/Auth/SecondFactor.php`, `app/Http/Middleware/EnsureSecondFactor.php`, `app/Http/Controllers/ActivityExportController.php`, `app/Support/Audit/{ActivityFilters,ActivityRows}.php`, `resources/views/pages/invitations/bulk/*`, `app/Support/Invitations/InvitationList.php`, `app/Concerns/ChoosesInvitationOrganisation.php`, `app/Actions/Users/{UpdateAvatar,RemoveAvatar}.php` and `tests/Feature/Auth/AdministratorActionLimitsTest.php`.
- Status lines:
  - At :1570, change the text to "Milestones M0 tot en met M7d zitten in `main`. Daarna volgt M8, de release." The M7d row in the table at :1597 is marked done.
  - The line about the current milestone follows the existing pattern.

- [ ] **Step 3: Kit guideline**

`.ai/guidelines/skv3.md`, in the sections named. Then run `php artisan boost:update` (or the command the README names) to regenerate the git-ignored `AGENTS.md`, and check it shows the new lines.
- **Tenancy and authorization:**
  - "Who has or needs a second factor" is `App\Tenancy\Auth\SecondFactor` (`satisfiedBy`, `requiredFor`); never re-implement it.
  - A new authenticated route group adds `EnsureSecondFactor` to its middleware.
  - A route that must stay reachable without a second factor goes into `config('skv.second_factor.exempt_routes')`.
  - Never detect a Livewire request by the `X-Livewire` header; use the route name.
  - `UpdateOrganisation::handle()` takes `bool $requireSecondFactor` last.
  - Tests set `config(['skv.second_factor.super_admins' => …])`.
- **Limits:** extend the `UserActionLimit` bullet with:
  - `logoutOtherDevices()`: 5/60, `attempt()`.
  - `bulkInvite()`: 100/3600. The bulk page calls `ensureAvailable()` and then `reserve()` for the ready rows, and releases the unsent ones.
  - `addressChange()`: 10/3600, `around()`, only when the address really changes.
  - The organisation wizard's first-admin invitation counts under `invite()`.
  - Route-level limits are named `RateLimiter::for()` limiters in `FortifyServiceProvider::configureRateLimiting()` (the `activity-export` limiter: 5 per minute per user).
  - Remove the parenthetical that says two administrative mail senders have no limit yet.
- **Sessions:**
  - Device labels come from `App\Support\UserAgent::parse()`.
  - A session id is never rendered; rows are identified by `hash_hmac('sha256', $id, config('app.key'))`.
  - Tests that need `sessions` rows set `config(['session.driver' => 'database'])`, turn the lottery off, send cookies per browser and use `sessionRowFor()`.
  - Add `sessionRowFor` to the list of global test helpers.
- **Audit:**
  - The viewer's filter rule is `App\Support\Audit\ActivityFilters`, and its display and RL-7 rule is `App\Support\Audit\ActivityRows`; never re-implement them.
  - Downloads are plain GET routes with `streamDownload()` and `lazyById()` inside `HostScope::read()`, never a Livewire download.
  - The `audit` group tags by host.
- **Invitations:**
  - Invite pages share `App\Concerns\ChoosesInvitationOrganisation`.
  - A bulk caller checks rows with `InviteUser::addressRules()` and shows every address refusal as the one generic message.
  - A CSV is read with `App\Support\Invitations\InvitationList` and never stored.
- **Users and UI:**
  - Profile photos change only through `App\Actions\Users\{UpdateAvatar,RemoveAvatar}`. Both are self-service without an actor, and each locks only the user's own row (lock-order step 3).
  - Render them with `User::avatarUrl()` and `flux:avatar :src` plus `:initials`.
  - Files go only under `avatars/` on the `public` disk.
  - The audit is `user.updated` with `attributes.avatar` = `set`/`removed`, never the path.
  - Tests use `Storage::fake('public')` and `UploadedFile::fake()->image()`.
  - Pro upload variants are covered by `FluxProLayerTest` and `ProVariantsTest`; never render a Pro branch with `Skv::useFluxPro(true)` without the package.

- [ ] **Step 4: Spec amendments**

`docs/superpowers/specs/2026-10-10-skv3-design.md`. Keep the "→" marker style the spec uses for as-built notes.
- **§3, the dependency row:** `intervention/image` | ^4 | avatar resize through Laravel's `Illuminate\Image`, GD driver; `ext-gd` required (WebP support).
- **§4.2 :116:** `AuthenticateSession` runs in the `web` group, so on every authenticated route.
- **§4.3:** "→ The second-factor rule is edited on `organisations.edit` (§6.6)." The purge deletes its users' avatar files after commit; this is built.
- **§4.4:** the avatar is `users.avatar_path`, nullable.
- **§4.5 :151:** "→ Bulk invite built (§6.6, M7d)."
- **§4.8:** the event list gains `security.other_sessions_ended` (with `properties.count`) and `audit.exported` (a new group `audit`, which tags by host). "CSV export (§6.6)" is now built.
- **§6.2 :351:** `activity.export` joins the any-host row of `activity.index`.
- **§6.6 sessions:**
  - the 5/60 limit, counted before the password is checked
  - only rows within the session lifetime are listed; "log out other devices" deletes every other row and audits how many
  - hidden, and 403 on every request, during an impersonation
  - rows are identified by a keyed hash, never the session id
  - no extra password confirmation, because the action checks the password itself
- **§6.6 second factor:**
  - The exempt list becomes `security.edit`, `password.confirm`, `password.confirm.store`, `password.confirmation`, `two-factor.*`, `passkey.*`, `well-known.passkeys`, `logout`, `auth.handoff`, `auth.handoff.store`, `impersonation.stop`, `setup.*`. This replaces `passkeys.*`, which names no route.
  - A non-GET request gets a 403.
  - Livewire requests are recognised by route name, never by the header.
  - Super admins follow `config('skv.second_factor.super_admins')` only, whatever their `organisation_id`.
  - An organisation admin can switch the rule on only with a second factor of their own; changing it either way asks for the password.
  - The security page shows the explanation as a callout. Super admins get their own message.
- **§6.6 CSV export:**
  - the route `activity.export` with `can:` `activity_log.view`, and the limiter `activity-export` at 5 per minute per user
  - the columns, the BOM and the formula guard
  - `audit.exported` is recorded before streaming, with `properties.filters`
  - it streams with `lazyById(500)` inside `HostScope::read()`; it is not a Livewire download
- **§6.6 bulk invitations:**
  - the CSV rules (512 KB, the columns, the header names, the separators) and at most 100 rows
  - the locked preview is checked again on send
  - the generic message
  - the limit: 100/hour, cost = ready rows, unsent rows released
  - the summary
- **§6.6 avatar:**
  - the exact rules `image|mimes:jpeg,png,webp|max:2048|dimensions:max_width=4096,max_height=4096`
  - the header guard before decoding
  - WebP at 512 px, quality 85, EXIF orientation applied
  - the path scheme
  - hidden, and 403, during an impersonation
  - the user menu's `avatar-updated` event
- **§6.7 table:** the avatar and CSV upload's Pro variant is `flux:file-upload` with `flux:file-item`; the bulk page's role control is the same pair as `users.invite`.
- **§11.2 :519:** the M7 row reads "M7a ops, M7b setup hardening, M7c security hardening, M7d the §6.6 extras (DEC-32, DEC-33, DEC-34, DEC-35)".

- [ ] **Step 5: DEC-35**

Add `### DEC-35 — Account extras build rulings (M7d)` after DEC-34, in DEC-34's form: **Context**, **Question**, **Answer** (numbered items) and **Consequence**. Add its row to the decision index table at the top, in the existing format.

- **Context:** DEC-34's Consequence deferred the §6.6 extras and FM-19 to M7d.
- **Question:** how were the sessions list, the second factor per organisation, the audit export, bulk invitations, avatars and their Pro variants built, and how was FM-19 closed?
- **Answer:** items (1)–(10) are the controller's decisions CD-1…CD-10 as built, under the owner's delegation; the owner can overrule each of them. Write each with what the task report says was built:
  - **(1) CD-1:** `intervention/image ^4` through `Illuminate\Image` on GD. It was revised from the plan's first `^3`, because Laravel 13.35's image API requires `^4` and spec §3's "^3 (latest at M1)" meant the current major. Composer added intervention/image and intervention/gif.
  - **(2) CD-2, sessions:**
    - the lifetime filter, and `count` as the number of rows deleted
    - the transaction order: users, then sessions, then activity
    - the `hydrate()` guard, and 403 rather than 404 during an impersonation
    - the keyed hash and `hash_equals`
    - the test harness: the database driver, the GC lottery off, cookies per browser, and the reset between simulated browsers of five in-memory pieces (the session manager's drivers, `session.store`, the auth guards, `auth.driver`, queued cookies)
  - **(3) CD-3, the setting:**
    - `SecondFactor` is created in Task 2 (`satisfiedBy()`)
    - the "own factor first" rule is checked by the page before the password prompt and again by the action
    - the password confirmation runs in both directions and does not keep unsaved fields across the confirmation (unlike `users.edit`)
  - **(4) CD-4, the middleware:**
    - Livewire is recognised by route name instead of `Livewire::isLivewireRequest()`, which only reads a header any client can send
    - the super-admin message
    - `phpunit.xml` forces `REQUIRE_SECOND_FACTOR_SUPER_ADMINS=false`
    - `email-change.confirm` is deliberately not exempt: a user who still has to set up a factor is sent to Security first, and the link keeps working until it expires
    - a flash does not survive a password confirmation in between, which is why the security page has its own callout
    - removing the last factor stays allowed
  - **(5) CD-5, the export:**
    - `ActivityRows` was extracted beside `ActivityFilters`
    - the `audit` group tags by host
    - the causer filter is still checked against the known causers, so a purged user's id is ignored as before
    - there is no change-summary column, because of RL-7
    - an unfiltered export includes its own entry
    - memory stays flat at one chunk of 500, but an export is still bound by `max_execution_time` and holds one database connection while it streams
  - **(6) CD-6, bulk invitations:**
    - the shared trait `ChoosesInvitationOrganisation` and `InviteUser::addressRules()`
    - pasted and CSV rows are combined, and any change to the input clears the preview
    - the generic message appears in the preview and at send time
    - `ensureAvailable()` runs before `reserve()`, so a list too big for what remains of the hour costs nothing
    - one transaction per row, so CD-5 of M7c holds per `InviteUser` call
    - a header row is recognised only by a known column name, so a mistyped first address is kept as a row
  - **(7) CD-7, avatars:**
    - the one-row lock, because two parallel saves would both delete the same old file and orphan one new file
    - the deletes after commit, and the cleanup of the new file when the save fails
    - the header guard against decompression bombs
    - the audit shape `attributes.avatar`
    - the `User::$attributes` default `avatar_path => null`, needed in strict mode
    - ext-exif is optional, and the orientation test skips without it
    - a decode failure is the user's error; any other image failure, such as GD without WebP, is a reported server error
    - the free file input's own "Choose file" copy stays in the browser's language
  - **(8) CD-8, FM-19:**
    - `addressChange` (key `address-change:<user id>`, 10 per 3600 s, `around()`, field `email`) runs only when the address really changes (compared after lower-casing), after the RL-18 confirmation, and wraps the transaction that queues `EmailChangedByAdministrator`
    - the wizard counts under `invite()`, `around()`, field `adminEmail`
    - refusals save or create nothing, and a failed save gives the slot back
    - DEC-28 (30)'s residual stays accepted
  - **(9) CD-9:** the audit events as built.
  - **(10) CD-10:** Pro branches are covered statically by `FluxProLayerTest` and by `ProVariantsTest`, which runs only with Pro installed. Rendering with `Skv::useFluxPro(true)` cannot work without the package, because the branches are stripped at compile time.
  - **(11) Deviations from the spec,** with their reasons: the §6.6 exempt list, `AuthenticateSession` in the `web` group, the dependency version.
  - **(12) Deviations from the plan, and the controller's rulings during the run:** copy every "Deviations from the brief" section of the task reports and every "Ruling:" line of the ledger. The final review, its fix rounds and the controller's rulings after it: (filled in after execution).
  - **(13) The documentation** (this task): the files and sections changed.
  - **(14) The controller's real verification** (Task 9): (filled in after execution).
- **Consequence:**
  - FM-19 is closed.
  - Deferred to M8: FM-18's probe limit; plan review K9; a doctor note for GD without WebP; M7c's carried minors; the release check, the CI runs of the e2e jobs, publication.
  - The deferred minors of the M7d reviews: (filled in after execution).
- **Amended in place:**
  - DEC-34 (16)'s FM-19 sentence: "→ closed in M7d (DEC-35 (8))".
  - DEC-11's row: "built in M7d (DEC-35)".

- [ ] **Step 6: CHANGELOG check**

Read `CHANGELOG.md`'s `[Unreleased]` section. Each of Tasks 1–7 added its own lines. Fix any line the later tasks made inconsistent, for example the dependency version, the factory names or the copy, and fold duplicates. Add no new feature lines here.

- [ ] **Step 7: Checks**

```bash
command grep -n "komt in M7d\|nog geen limiet\|no limit yet" docs/handleiding.html .ai/guidelines/skv3.md README.md   # prints nothing
command grep -nF 'passkeys.*' docs/superpowers/specs/2026-10-10-skv3-design.md                                    # prints nothing
command grep -c "DEC-35" docs/decisions.md                                                                         # at least 3 (index row, heading, amendments)
php artisan test --compact tests/Feature/Tooling tests/Arch
composer check
```

Expected: the first two print nothing; the tests pass. Then run the full per-task gate.

- [ ] **Step 8: Commit**

```bash
git add README.md docs/handleiding.html .ai/guidelines/skv3.md docs/superpowers/specs/2026-10-10-skv3-design.md docs/decisions.md CHANGELOG.md
git commit -m "docs: document the account extras, amend the spec and record DEC-35

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 9: Milestone gate and the controller's real verification

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
grep -rnE "(^|[[ ,(])['\"]\*['\"] *=>|Process::fake\(\)" tests/ | grep -v KitRulesTest
grep -L "covers §" $(git diff --name-only --diff-filter=A main -- 'tests/**/*.php' | grep -v -e Fixtures -e Support)
git diff main -- composer.json | grep -E '^[-+][^-+]'
git diff main --stat -- package.json package-lock.json
```

Expected:
- four green suites
- the stylesheet as committed
- a clean tree
- `0` and `0`
- no catch-all fake
- an empty `grep -L`
- the `composer.json` diff is exactly one added `"intervention/image": "^3…"` line in `require`
- no npm change

- [ ] **Step 2: The real run (PostgreSQL + Herd Pro + HTTPS)**

Every command names the throwaway site by its absolute path; artisan runs from inside `"$HOME/Herd/m7d-e2e"`. Never run `artisan`, `stat` or `ls` on a relative path from the repository.

1. **Install.**
   - Run `scripts/e2e-local.sh "$HOME/Herd/m7d-e2e" --entry=herd --phase1-only`. Every phase-1 check passes, and the site is not the repository.
   - Then the wizard (Playwright, as in M7c's Task 15): PostgreSQL, the demo data, passkeys + backups + impersonation, HTTPS, Claude Code off. It reaches its summary with no JavaScript errors.
   - Herd Pro's mail store may keep no mails: switch the throwaway site's `MAIL_MAILER` to `log` without reading `.env` (`perl -i -pe 's/^MAIL_MAILER=.*/MAIL_MAILER=log/'` followed by `chmod 600`).
2. **Sessions (Task 1).**
   - Sign `user@demo1…` in from two separate browser contexts, the second with "remember me".
   - The security page of the first lists two rows, one "This device", with browser and platform, and no session id in the HTML.
   - "Log out other devices" with the right password. The second context's next request lands on the login page, and the first stays signed in.
   - A wrong password gives the error.
   - The activity log shows the entry.
3. **The second factor (Tasks 2, 3).**
   - As `admin@demo1…`, turning the rule on is refused while the admin has no second factor.
   - Set up TOTP for the admin. Its code is computed in the driver from the secret the setup modal shows, and the secret is never logged.
   - Then turn the rule on, after the password confirmation.
   - `user@demo1…` is sent from `/dashboard` to the security page with the explanation, can reach the password confirmation without a loop, and is sent back when it opens `/users`.
   - After registering a passkey (Playwright's virtual authenticator through CDP `WebAuthn.addVirtualAuthenticator`), `/dashboard` opens.
   - Turn the rule off again.
4. **Export (Task 4).**
   - As `admin@demo1…`, filter the activity log on one event and click "Export CSV". The download starts with a BOM and a header row, holds only demo1's rows of that event and no other organisation's rows.
   - The sixth export within a minute gets the 429 page.
   - The log shows "audit.exported".
5. **Bulk invitations (Task 5).**
   - As `admin@demo1…`, paste 3 new addresses, `admin@demo2…` and a duplicate. The preview shows 3 Ready rows and 2 skipped ones, the second with "This address can't be invited."
   - Send. The summary says 3 sent, and the log mailer holds 3 invitation mails (record only the subjects and recipients' domains, never the accept URLs).
   - Upload a 3-row CSV with a `nl`/`en` locale column. The mails use the row's language.
6. **Avatars (Task 6).**
   - Upload a JPEG photo on the profile page. The user menu shows it.
   - The stored file under `storage/app/public/avatars/{organisation id}/` is a 512×512 WebP (`sips -g pixelWidth -g pixelHeight -g format`).
   - Its URL loads over `https://m7d-e2e.test/storage/…`.
   - Replace it: the old file is gone. Remove it: the initials show.
7. **FM-19 (Task 7).** As `superadmin@…`, the 11th address change within an hour is refused, and the 11th `organisations.create` within a minute is refused on the first-admin address. Organisations created for this check are archived and purged afterwards. The purge also removes their users' avatar files, which checks §4.3.
8. **Record** every output in the ledger, never a password, secret, token, session id or accept URL.

Clean up as M7c's Task 15 did:
- `herd unsecure m7d-e2e`, and `herd unisolate --site=m7d-e2e` only if `herd isolated` lists it
- `rm -rf "$HOME/Herd/m7d-e2e"` and the script's temp directory
- drop `m7d_e2e` and `m7d_e2e_test` with a PDO script in the scratchpad that first checks that `pg_database` lists exactly those two names, then drops them by literal name
- delete the downloaded CSV, the browser state and any saved TOTP secret

- [ ] **Step 3: Hand-off**

- Fix what Step 2 finds, test-first, then re-run Step 1.
- Fill in DEC-35's "(filled in after execution)" markers.
- Stop before merging: the owner merges.
- Update the memory notes: M7d done, its deferred minors, M8 next.
