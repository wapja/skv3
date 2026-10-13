# skv3

An opinionated, multi-tenant Laravel starter kit: organisations by subdomain, invitations, roles and permissions, impersonation, audit logging and a Flux UI — set up from Laravel Herd in minutes.

> **Status:** under construction (milestone M6a — the setup engine and its CLI). Setup runs from the terminal for now; the browser setup wizard arrives in M6b. See `docs/superpowers/specs/2026-10-10-skv3-design.md`.

## Requirements

- PHP 8.4+, Composer 2, Node 22.13+ (required by `composer dev`)
- Laravel Herd (Herd Pro recommended for PostgreSQL and mail)

## Quickstart

Every route ends in the same setup: `php artisan skv:install` asks a few questions (or reads `--answers`), then writes `.env`, creates and seeds the database, writes `herd.yml` and the branding, builds the frontend, sets the git hooks, sets up Claude Code when you choose it, runs the doctor, links the Herd site (and secures it when HTTPS is on) and prints a summary with the addresses and logins. The browser wizard that does the same arrives in M6b.

- **Laravel installer** (Herd's *New site → Custom Starter Kit* runs the same command):

  ```bash
  laravel new myapp --using=frankcornet/skv3
  cd myapp && php artisan skv:install
  ```

- **Composer:**

  ```bash
  composer create-project frankcornet/skv3 myapp
  cd myapp && php artisan skv:install
  ```

- **Clone:**

  ```bash
  git clone … myapp && cd myapp && composer setup
  ```

  `composer setup` runs `composer install`, copies `.env.example` when `.env` is missing, then `php artisan skv:bootstrap` and `php artisan skv:install`.

The first two routes install the package from Packagist, so they work once it is published (M8). In every route `skv:bootstrap` runs first, from the Composer and installer hooks or from `composer setup`. It creates `.env` and `APP_KEY`, points a `localhost` `APP_URL` at the Herd site and migrates the SQLite file, so the app boots before `skv:install` runs. `skv:install` runs the same bootstrap again as its step 0, so a bare `php artisan skv:install` on a clone works too.

## Commands

| Command | What it does |
|---|---|
| `php artisan skv:install [--answers=JSON] [--no-interaction] [--force]` | Runs the setup steps in order. Complete steps are skipped unless `--force`; a re-run keeps the answers of the earlier install and names the `.env` keys it changed. A step that cannot run prints its command to run yourself. |
| `php artisan skv:doctor [--fix] [--json]` | Checks the installation and prints the fix for every problem. Green means no `fail`. `--fix` performs only the safe repairs (`permissions:sync`, flag and role drift, `storage:link`, `core.hooksPath`, the asset build). `--json` prints the report and exits 1 when it is not green. |
| `php artisan skv:create-super-admin` | Creates a super admin, or promotes an existing active user; a `--name` or `--password` given for an existing user replaces its own. Prompts for what is missing; with `--no-interaction` the email and `--password` are required for a new user. |
| `php artisan skv:create-organisation` | Creates an organisation and invites its first admin. Needs an active super admin. |
| `php artisan skv:flux-pro` | Installs Flux Pro with your own Composer credentials (see [Flux Pro](#flux-pro)). |
| `php artisan permissions:sync` | Syncs the permission catalogue and the system roles, and prints every change it made. |
| `composer doctor` | Runs `php artisan skv:doctor`. |

## The answers schema

`skv:install --answers='{"database":"sqlite"}'` takes a JSON object with these keys; `skv:install --no-interaction` uses the defaults as-is. `tests/Fixtures/e2e-answers.json` uses every key.

| Key | Type | Default |
|---|---|---|
| `app_name` | string | `Str::headline(basename(base_path()))` |
| `database` | `pgsql` \| `sqlite` | `pgsql` if a reachable Postgres was detected, else `sqlite` |
| `start_with` | `demo` \| `super_admin` | `demo`. With `super_admin`, `super_admin_email` + `super_admin_password` are required; with `--no-interaction` and either missing, the command fails before any step runs |
| `modules` | subset of `passkeys`, `backups`, `impersonation` | all three; `backups` removed on sqlite |
| `https` | bool | true; forced true while `passkeys` is on |
| `playwright` | bool | false |
| `claude` | bool | true if `claude` is on PATH, else false |

After an install, the answers of that install take the place of these defaults on a re-run, and the super admin it created needs no password again. Every run that finishes stores its answers, so the next re-run keeps what it changed. The `MODULE_*` flags in `.env` stand in for the `modules` answer, so a module you switched in `.env` stays switched. A `super_admin_password` given for an account that already exists replaces its password, also a demo account's.

## Demo accounts

With `start_with: demo` (the default), the demo seeder creates a super admin and the organisations `demo1` and `demo2`, each with an admin, a user and one pending invitation. The emails are derived from the apex, for example `superadmin@myapp.test`, `admin@demo1.myapp.test` and `user@demo1.myapp.test`. Every demo account has the password `password`, and the setup summary prints them.

The demo seeder refuses to run in production, and `DatabaseSeeder` skips it there. Never publish the demo accounts or their password (skv2 finding R2): do not put them in a public page, a shared manual or a deployed environment.

## Flux Pro

The kit works fully on free Flux. To switch on the Pro components in your app, add your Flux Pro licence to your global Composer credentials and install the package:

```bash
composer config --global http-basic.composer.fluxui.dev <email> <license-key> && php artisan skv:flux-pro
```

`skv:flux-pro` never writes or prints your credentials, and runs `view:clear` after it installs the package.

## Local subdomains without Herd

Herd serves `myapp.test` and every subdomain of it. Without Herd, the apex, `admin.` and every organisation must resolve to your machine:

- add the `/etc/hosts` lines that `php artisan skv:doctor` prints when `probe.{apex}` does not resolve (the apex, the admin host and every organisation's host), or
- run dnsmasq with a wildcard for the apex; names under `localhost` resolve to your machine in browsers without any entry.

Set `TENANCY_APEX_DOMAIN` to that apex (for example `myapp.localhost`) and `APP_URL` to the same host.

## Production checklist

- Run `php artisan permissions:sync` on every deploy.
- Set a non-empty `HEALTH_CHECK_KEY` (setup generates one).
- Keep `SESSION_DRIVER=database`.
- Never run the demo seeder.
- Run a queue worker (`php artisan queue:work`), because invitation mails are queued.
- Add a cron entry that runs `php artisan schedule:run` every minute.

## AI

- `.claude/settings.json` denies dangerous commands with Bash deny rules, but a deny rule cannot stop every bypass (for example `--env`, `/usr/bin/php` or `sh -c`), as the Claude Code documentation says (DEC-25).
- Setup registers the Herd MCP server as `herd` in `.mcp.json`, so the deny on `mcp__herd__get_all_sites` applies. It registers it without `SITE_PATH`: with that variable (Herd's own AI integration adds it) the server also offers the tool `get_site_information` and the resource `herd://sites/information`, which return this site's `.env`, and, when `herd.yml` links a Forge site, tools that return the Forge site's environment and deployment output. `.claude/settings.json` does not deny those, so leave `SITE_PATH` out.
- Project plugins (superpowers) activate only after you accept Claude Code's workspace-trust dialog (§9).

## Develop the kit

```bash
composer setup          # install, .env, skv:bootstrap and skv:install
composer dev            # Vite, queue, logs, scheduler (Herd serves the site)
composer check          # Pint, Rector, Larastan, Pest (SQLite)
composer test:pgsql     # Pest on PostgreSQL (Herd Pro)
composer test:browser   # Pest browser tests in Chromium
composer doctor         # skv:doctor
composer fix            # Rector + Pint
```

## License

MIT
