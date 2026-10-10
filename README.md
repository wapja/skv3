# skv3

An opinionated, multi-tenant Laravel starter kit: organisations by subdomain, invitations, roles and permissions, impersonation, audit logging and a Flux UI — set up from Laravel Herd in minutes.

> **Status:** under construction (milestone M1 — foundation). The Herd one-click setup arrives in M6. See `docs/superpowers/specs/2026-10-10-skv3-design.md`.

## Requirements

- PHP 8.4+, Composer 2, Node 20.19+ or 22.12+
- Laravel Herd (Herd Pro recommended for PostgreSQL and mail)

## Develop the kit

```bash
composer setup          # install, .env, key, SQLite, migrate, git hooks, assets
composer dev            # Vite, queue, logs, scheduler (Herd serves the site)
composer check          # Pint, Rector, Larastan, Pest (SQLite)
composer test:pgsql     # Pest on PostgreSQL (Herd Pro)
composer fix            # Rector + Pint
```

## License

MIT
