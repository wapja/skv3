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

### Removed
- Registration, email verification and self-service account deletion (invite-only kit).
- Vite+, Sail, Chisel and `concurrently`.
