# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- Foundation scaffolded from the official Laravel Livewire starter kit (PHP 8.4, Laravel 13.35, Livewire 4, Flux 2, Pest 5).
- Quality toolchain: strict Pint, Rector, Larastan level 8 (incl. component PHP), Pest arch presets and kit rules.
- Composer scripts `setup`, `dev`, `fix`, `check`, `test:pgsql`, `test:browser`; pre-commit hook.
- CI matrix (PostgreSQL 18, SQLite, PHP 8.5) and Dependabot for actions, composer and npm.
- Laravel Boost, `CLAUDE.md`, kit guideline and Claude Code settings with the superpowers plugin.
- Multi-tenancy: organisations on their own subdomain, a strict tenant scope, `Route::tenant()` / `Route::central()` groups, tenant-aware queues and a friendly page for unknown organisations.
- Roles and permissions: a fixed permission catalogue, `permissions:sync`, a global organisation admin role and a super-admin flag with guards against lock-out and privilege escalation.
- Host-aware sign-in: password, two-factor and passkey sign-in only on hosts a user belongs to; failed attempts are rate limited.
- Passkeys that work on every subdomain, password reset links for the user's own host, email changes confirmed by link, and a single-use cross-host sign-in link.
- Email changes and security-page actions (disabling 2FA, removing a passkey, viewing or regenerating recovery codes) ask for a fresh password confirmation.
- Failed sign-ins take at least `AUTH_TIMEBOX_DURATION` (500 ms by default), so response times do not reveal which addresses exist; `TRUSTED_PROXIES` lets rate limits see each visitor's IP behind a load balancer.
- Organisation administration: a wizard that creates an organisation and invites its first admin, editing (only super admins change an organisation's address), archive, restore and purge, and "Open" to sign in on an organisation's own address.
- User administration: search, filters and pagination, an edit page that doubles as the user's profile, status changes, delete and restore. Administrators only manage users whose permissions they hold themselves; changing someone else's address asks for your password and notifies the old address.
- Invitations with roles and language: invite, remind, cancel, copy the invite link and a list of pending invitations. Invitees choose their own name and password on the organisation's address and are signed in right away. Links stay valid for `INVITATION_EXPIRES_IN_DAYS` (7 by default).
- Custom roles per organisation, managed by super admins; the organisation admin and super admin roles are protected.
- Optional impersonation (`MODULE_IMPERSONATION`): sign in as a member with a reason, a banner with a stop button, checked again on every request and fully audited.
- An audit log of sign-ins, security changes and every administrative change, with a filterable viewer and a retention clean-up after `ACTIVITYLOG_RETENTION_DAYS` (365 by default).
- A cross-host sign-in link opened while someone else is signed in asks before it switches accounts.
- A new base layout for every page; light, dark or system appearance is saved per user, without a flash, and is the same on every subdomain.
- Branding with Flux accent colours and an optional logo (`BRANDING_ACCENT`, `BRANDING_LOGO`); an organisation's host shows its name in the brand.
- A collapsible sidebar with three groups (Overzicht, Beheer, Systeem), driven by one navigation definition that hides what you may not open.
- One user menu with settings, language, appearance and log out.
- Dutch and English on every screen and page title: your language is saved in your profile, guests follow their browser and get a language toggle on the sign-in pages.
- Friendly, self-contained error pages (403, 404, 419, 429, 500, 503) in the visitor's language, and a clear page for an invitation link that is no longer valid.
- A dashboard with your organisation and roles, counts for users, pending invitations and organisations, recent activity and a getting-started checklist for administrators.
- Optional passkeys module (`MODULE_PASSKEYS`): when it is off, passkey routes, buttons and the settings section are gone.
- An optional Flux Pro layer: with `livewire/flux-pro` installed you get a listbox for roles, tabs, date pickers, a ⌘K command palette and a sign-in chart; without it everything works on free Flux.
- Browser tests in Chromium with the Pest browser plugin (`composer test:browser`), including a CI job.
- The setup engine: `php artisan skv:install` (questions or `--answers=JSON`, resumable, `--force`), `skv:bootstrap` for the Composer and installer hooks, `skv:doctor` (`--fix`, `--json`, also `composer doctor`), `skv:create-super-admin`, `skv:create-organisation` and `skv:flux-pro`.
- A demo seeder with a super admin and the organisations `demo1` and `demo2`, each with an admin, a user and a pending invitation; it never runs in production.
- An installer end-to-end CI job (`laravel new --using` with Herd's flags, then `skv:install`, `skv:doctor` and `composer check`) and `scripts/e2e-local.sh` to run it locally.
- A browser setup wizard at `/welcome-setup`: Herd's *Open site* leads to it, it asks the setup questions with the detected defaults filled in, runs the same steps as `skv:install` one request at a time (the frontend build and Claude Code in the background, the page polling them), shows every manual command with *Copy* and *Re-check*, and ends with a Herd stage that secures the site, shows the summary and signs the new super admin in once. When Herd does not confirm HTTPS in time, *Continue without HTTPS* finishes on http.
- Setup mode: while a local app has no install marker but has its one-time setup token, every web request uses a file session, a file cache and the sync queue, organisation hosts are not resolved, and `/` leads a direct visitor to the wizard until a super admin exists. The wizard answers 404 outside setup mode, without the token and through a proxy or tunnel.
- `skv:bootstrap` writes the one-time setup token while the app is not installed, and prints the address to finish setting up in the browser.
- `php artisan skv:run-step`, a hidden command that runs one setup step in the background for the wizard.
- `npm run build:setup`, which rebuilds the committed `resources/setup/setup.css`, so the wizard renders without `public/build`; CI checks the file is current.
- A local quick login: after a local install with demo data, the login page signs you into a demo account with one click. Accounts that are not active, have confirmed two-factor authentication or no longer use the demo password are not let in.
- A phase-1 CI job and `scripts/e2e-local.sh --entry=…` check the wizard after each of the four entry points (`laravel new` with and without `--database`, `composer create-project`, and a clone with the commands of `composer setup`).

### Changed
- `composer setup` runs `skv:bootstrap` and `skv:install`; the temporary development login (`test@example.com`) is gone.
- `permissions:sync` also reports the permissions it strips from the super admin badge.
- When you chose HTTPS and the Herd step could not secure the site, `APP_URL` stays http and the install records `https: false` whether or not passkeys are on (passkeys are switched off when they were on); the summary says how to secure the site later, and the doctor no longer fails on the missing certificate.
- After securing the site, the Herd step writes `APP_URL=https://<folder>.<tld>`, the name it secured (lower-cased), instead of the address setup was started on.
- The doctor step of setup checks the Herd site, HTTPS and the setup token after the Herd step instead of before it.
- The install marker is written atomically with mode 0600.

### Fixed
- CI: `composer audit` reports abandoned packages instead of failing (`config.audit.abandoned = report`); the Composer cache step uses `composer config cache-files-dir`.
- `composer setup` keeps an existing `APP_KEY` (2FA secrets and passkey handles depend on it).
- `composer setup` runs `boost:update`, so a fresh clone gets the Boost guidelines (`AGENTS.md`) that `CLAUDE.md` imports.
- Profile emails are stored lower-cased, so a capitalised address no longer locks the user out.
- Real `tests/Feature/ExampleTest.php` and `tests/Unit/ExampleTest.php` are shipped, so the installer's `pest --init` step leaves the test suite green.
- Roles can only be changed through the roles form, never to the super admin badge, another organisation's role or a role with permissions the administrator lacks, and roles of another organisation cannot be edited (skv2 W1).
- Nobody can delete or disable themselves or the last active super admin (skv2 W2).
- Impersonating a pending or disabled user is refused, and an impersonation that is no longer allowed ends cleanly and is logged instead of signing the impersonator out (skv2 W3).
- The pending invitations list keeps working when the inviter was deleted or a user was set to pending by hand (skv2 W4).
- Accepted invitations can no longer be resent, and the accept page says "already accepted" instead of "expired" (skv2 W5).
- The organisation admin role cannot be renamed, deleted or reused as a role name, and organisation admins cannot impersonate each other (skv2 W6).
- A user created without an organisation never becomes a super admin (skv2 W8).
- Deleted users no longer block an address, and the invite and edit forms no longer reveal addresses used in other organisations (skv2 W9).
- Invitation mails are sent only after everything is saved, and a mail failure no longer leaves an orphan pending user (skv2 W10).
- A failed invitation no longer leaves the wrong permission team active (skv2 W11).
- Administrators cannot remove their own admin role, disable themselves or demote the last organisation admin (skv2 W12).
- Invitation tokens are stored once, hashed, with an encrypted copy for the invite link that is removed on acceptance (skv2 W13).
- Invitations need at least one role, and their link works only on the organisation's own address (skv2 W14).
- The admin subdomain is never treated as an organisation, also when `TENANCY_ADMIN_SUBDOMAIN` is changed.
- Downloading recovery codes uses the codes that are stored, and copying them waits for the clipboard and says when it failed.
- The passkey relying-party id always comes from the apex (`Hosts::apex()`) and is lower-cased.
- The kit's own versions of Fortify routes still replace Fortify's when `fortify.prefix` or `fortify.domain` is set.
- A failed passkey check no longer writes a stack trace to the log.
- `composer test:pgsql` uses the same test-database name as the setup and the doctor, also for a folder with a hyphen (`my-app` → `my_app_test`).
- Child processes that setup starts (`npm`, `claude`, the background steps) no longer inherit the project's `.env` values such as `APP_KEY` or `DB_PASSWORD`, and a child `php artisan` reads the current `.env`.
- Setup no longer hangs when Herd does not answer, and reads `herd tld` one way everywhere.
- Setup error messages no longer show PostgreSQL's `DETAIL` lines, which can hold a password hash.

### Removed
- Registration, email verification and self-service account deletion (invite-only kit).
- Vite+, Sail, Chisel and `concurrently`.
