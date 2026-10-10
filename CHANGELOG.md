# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- Foundation scaffolded from the official Laravel Livewire starter kit (PHP 8.4, Laravel 13.35, Livewire 4, Flux 2, Pest 5).
- Quality toolchain: strict Pint, Rector, Larastan level 8 (incl. component PHP), Pest arch presets and kit rules.
- Composer scripts `setup`, `dev`, `fix`, `check`, `test:pgsql`, `test:browser`; pre-commit hook.
- CI matrix (PostgreSQL 18, SQLite, PHP 8.5) and Dependabot for actions, composer and npm.
- Laravel Boost, `CLAUDE.md`, kit guideline and Claude Code settings with the superpowers plugin.

### Removed
- Registration, email verification and self-service account deletion (invite-only kit).
- Vite+, Sail, Chisel and `concurrently`.
