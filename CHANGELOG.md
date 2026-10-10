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

### Fixed
- CI: `composer audit` reports abandoned packages instead of failing (`config.audit.abandoned = report`); the Composer cache step uses `composer config cache-files-dir`.
- `composer setup` keeps an existing `APP_KEY` (2FA secrets and passkey handles depend on it).
- `composer setup` runs `boost:update`, so a fresh clone gets the Boost guidelines (`AGENTS.md`) that `CLAUDE.md` imports.
- Profile emails are stored lower-cased, so a capitalised address no longer locks the user out.
- Real `tests/Feature/ExampleTest.php` and `tests/Unit/ExampleTest.php` are shipped, so the installer's `pest --init` step leaves the test suite green.

### Removed
- Registration, email verification and self-service account deletion (invite-only kit).
- Vite+, Sail, Chisel and `concurrently`.
