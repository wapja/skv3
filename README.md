# skv3

An opinionated, multi-tenant Laravel starter kit: organisations by subdomain, invitations, roles and permissions, impersonation, audit logging and a Flux UI — set up from Laravel Herd in minutes.

> **Status:** under construction (milestone M7a — the operations layer: the health check, backups, the scheduler and the System page). See `docs/superpowers/specs/2026-10-10-skv3-design.md`.

## Requirements

- PHP 8.4+, Composer 2, Node 22.13+ (required by `composer dev`)
- Laravel Herd (Herd Pro recommended for PostgreSQL and mail)

## Quickstart

Every route ends in the same setup, in the browser or in the terminal. The setup wizard and `php artisan skv:install` run the same steps: they ask a few questions, then write `.env`, create and seed the database, write `herd.yml` and the branding, build the frontend, set the git hooks, set up Claude Code when you choose it, run the doctor, write the install marker, link the Herd site (and secure it when HTTPS is on) and show a summary with the addresses, the logins and the next steps.

- **Herd** (no terminal needed): *New site* → *Custom Starter Kit* → `frankcornet/skv3` → *Open site*. The site's home page opens the [setup wizard](#the-setup-wizard).
- **Laravel installer** (Herd's *Custom Starter Kit* runs the same command):

  ```bash
  laravel new myapp --using=frankcornet/skv3
  ```

  Then open the address it prints, or run `cd myapp && php artisan skv:install` to set up in the terminal.

- **Composer:**

  ```bash
  composer create-project frankcornet/skv3 myapp
  ```

  Then open the address it prints, or run `cd myapp && php artisan skv:install`.

- **Clone:**

  ```bash
  git clone … myapp && cd myapp && composer setup
  ```

  `composer setup` runs `composer install`, copies `.env.example` when `.env` is missing, then `php artisan skv:bootstrap` and `php artisan skv:install`, so it ends in the terminal. To use the wizard on a clone instead, run those commands up to and including `skv:bootstrap` yourself and open the site.

The first three routes install the package from Packagist, so they work once it is published (M8). In every route `skv:bootstrap` runs first, from the Composer and installer hooks or from `composer setup`. It creates `.env` and `APP_KEY`, points a `localhost` `APP_URL` at the Herd site and migrates the SQLite file, so the app boots before setup runs. While the app is not installed it also writes the one-time setup token, and it prints "Open `<APP_URL>` to finish setting up in the browser, or run php artisan skv:install." `skv:install` runs the same bootstrap again as its step 0, so a bare `php artisan skv:install` on a clone works too.

## The setup wizard

The wizard lives at `/welcome-setup`, but you never type that address: open the site's home page and it sends you there with the token.

- **When it opens.** Only in *setup mode*: `APP_ENV=local`, no install marker (`storage/app/skv-installed`) and a setup token. Every other request to `/welcome-setup` gets a 404, and so does a request that arrives through a proxy or a tunnel such as `herd share` (it carries an `X-Forwarded-*`, `Forwarded`, `X-Original-Host` or `X-Expose-*` header). `/` hands out the token only while no super admin exists. The wizard keeps the token in its session and removes it from the address bar.
- **While it runs.** Every web request uses a file session, a file cache and the sync queue, so the wizard never depends on the database it is setting up, and organisation hosts are not resolved yet.
- **The host.** Under Herd, open the setup on `myapp.test` or `admin.myapp.test`; without Herd on `localhost`, `127.0.0.1` or a name ending in `.localhost` or `.test`. On any other host the wizard names the address to use and does not start. Under Herd the app's address is pinned to `myapp.test`, whichever of the two you started on.
- **The questions** are the [answers schema](#the-answers-schema), pre-filled with the detected defaults: the app name, the database, what to start with (demo data or your own super admin with an email and a password), the modules, HTTPS, Playwright and Claude Code. The password is hashed on entry; only the hash is stored until the super admin exists.
- **The steps** run one at a time, each in its own request, and the page shows each one as done, skipped, manual or waiting. The long steps (the frontend build and Claude Code) run in the background as `php artisan skv:run-step <step>`, and the page checks on them every two seconds. A reload continues where the setup was, and neither a reload nor a second tab runs a step twice: one request at a time holds the setup lock, and the others keep waiting for its step. A step that fails shows *Setup stopped* with its message and *Try again*.
- **Manual commands.** A step that cannot run by itself shows its command with *Copy* and *Re-check*: run the command in a terminal, then click *Re-check*. When a step ended this way, the wizard stops in a review after the doctor step (step 8), so *Re-check* still works; *Finish setup* goes on, and the summary lists every command that is left. Without one it goes straight on.
- **The Herd stage.** *Finish setup* writes the install marker, which closes the wizard (from then on `/welcome-setup` answers 404), and runs the Herd step in the background: it links the site when Herd does not serve it yet and secures it when HTTPS is on. Herd may restart nginx and PHP meanwhile; the page waits up to 60 seconds and moves to `https://` by itself once the site is secured. The summary then shows the addresses, the logins and the next steps (`composer dev`, signing in, `php artisan skv:doctor`). *Log in as `<email>`* signs your new super admin in on the admin host with a single-use link; with demo data, *Go to the login page* takes you to the [quick login](#local-quick-login). These pages only work in the browser that ran the wizard, for at most 30 minutes, and the login works once.
- **"Continue without HTTPS".** When Herd did not confirm HTTPS in time, the page shows `herd secure <name>`. Run it and click *Reload*, or click *Continue without HTTPS*: `APP_URL` stays `http://`, the marker records `https: false`, passkeys are switched off when they were on (they need HTTPS), and the summary says how to secure the site later. The doctor's `herd-secured` row then reports `skipped`, so the install is still green.

## Local quick login

After a local install with demo data, the login page lists the demo accounts under *Quick login (local only)*: one click signs you in on the account's own host. It shows only when `APP_ENV=local` and the install marker records `start_with: demo`, and it lists only demo accounts that exist, are active and have no confirmed two-factor authentication (the quick login skips the second factor). An account whose password you changed drops out of the quick login: clicking it takes you back to the login page with a message. Everywhere else `POST /quick-login` answers 404.

## The setup files

| File | What it holds | Deleted by |
|---|---|---|
| `storage/app/private/skv-setup-token` | the one-time setup token | step 9 (the marker); `php artisan skv:doctor --fix` deletes one that is left after setup |
| `storage/app/private/skv-setup.json` | the wizard's pinned host, answers (the password only as a hash), step results and notes | step 9 |
| `storage/app/private/skv-setup/` | per long step its result (`<step>.json`), output (`<step>.log`) and exit code (`<step>.exit`) | step 9 |
| `storage/app/private/skv-herd.json` | the Herd stage: a hash of its secret, the host, the answers without the password, the results and, once Herd finished, its result | the summary's login button; after 30 minutes it is stale and you can delete it |
| `storage/app/private/skv-herd.exit` | the Herd step's exit code | the summary's login button |
| `storage/app/private/skv-setup.lock` | nothing: an empty file whose lock lets one wizard request at a time change the wizard state or the Herd state | the summary's login button |
| `storage/logs/skv-herd.log` | the Herd step's output | nothing: delete it yourself when you no longer need it |
| `storage/app/skv-installed` | the install marker: kit version, answers without secrets, `start_with`, time and summary | nothing: it marks the app as installed |

The state files are written atomically with mode 0600; only the token file holds a secret in plain text, the others hold hashes. Never write them by hand.

## Commands

| Command | What it does |
|---|---|
| `php artisan skv:install [--answers=JSON\|@file\|-] [--no-interaction] [--force]` | Runs the setup steps in order. Complete steps are skipped unless `--force`; a re-run keeps the answers of the earlier install and names the `.env` keys it changed. A step that cannot run prints its command to run yourself. `--answers` takes inline JSON, `@path` (a file, relative to the working directory) or `-` (standard input); inline JSON with a `super_admin_password` is refused before anything runs, so a password never shows in the process list or the shell history. It refuses to run in production (`APP_ENV=production`), and outside local development (`local`, `testing`) it refuses `start_with=demo` before anything is written. |
| `php artisan skv:bootstrap` | The phase-1 bootstrap the Composer and installer hooks run: `.env`, `APP_KEY`, the Herd `APP_URL`, the SQLite migration and, while the app is not installed, the setup token. Prints where to finish setting up. In production (the `.env` says `APP_ENV=production`) it only prepares `.env`, `APP_KEY` and the blank generated secrets (`HEALTH_CHECK_KEY`, `BACKUP_ARCHIVE_PASSWORD`; it names the keys, never their values) and points you at the [production checklist](#production-checklist). |
| `php artisan skv:doctor [--fix] [--json]` | Checks the installation and prints the fix for every problem. Green means no `fail`. `--fix` performs only the safe repairs (`permissions:sync`, flag and role drift, `storage:link`, `core.hooksPath`, the asset build, a missing `HEALTH_CHECK_KEY`, a missing `BACKUP_ARCHIVE_PASSWORD` while the backups module is on, and the `setup-token` fix, which deletes a setup token left after setup). The `marker` row fails when `storage/app/skv-installed` is not valid JSON, the `failed-jobs` row warns while failed jobs are stored, and the `backup-password` row fails while the backups module is on and `BACKUP_ARCHIVE_PASSWORD` is blank. `--json` prints the report and exits 1 when it is not green. During setup, step 8 reports the `herd-site`, `herd-secured` and `setup-token` rows as `skipped` ("Checked after the Herd step"), because steps 9 and 10 have not run yet. |
| `php artisan skv:create-super-admin [email] [--name=] [--password-stdin]` | Creates a super admin, or promotes an existing active user; a `--name`, or a password given with `--password-stdin`, replaces an existing user's own. `--password-stdin` reads the password from the first line of standard input: `printf '%s\n' "$PASSWORD" \| php artisan skv:create-super-admin you@example.com --password-stdin --no-interaction`. Prompts for what is missing; with `--no-interaction` the email and `--password-stdin` are required for a new user. `--password=` is gone: a password in argv shows in the process list and the shell history. |
| `php artisan skv:create-organisation [name] [slug] [admin-email] [--description=] [--locale=] [--as=]` | Creates an organisation and invites its first admin. It acts as an active super admin: `--as=<email>` picks which one, the default is the oldest active one. `--locale` is the first admin's language and defaults to the app's default language (`APP_LOCALE`). |
| `php artisan skv:flux-pro` | Installs Flux Pro with your own Composer credentials (see [Flux Pro](#flux-pro)). |
| `php artisan permissions:sync` | Syncs the permission catalogue and the system roles, and prints every change it made. |
| `composer doctor` | Runs `php artisan skv:doctor`. |

Audit entries written while an Artisan command runs are tagged with it (`via: console` and the command name in their properties), and the audit log shows them as *Command line*, or as the causer's name followed by *via the command line* when they have one.

## Health check

- **`/up`** is liveness only: the framework answers 200 while the app boots.
- **`/health-check`** needs the header `X-Health-Check-Key` with the value of `HEALTH_CHECK_KEY`. Setup and the production `skv:bootstrap` generate the key, and `skv:doctor --fix` writes one when it is missing. A missing or wrong header, and a blank `HEALTH_CHECK_KEY`, always get 403.
- It answers 200 when every check passes and 503 when one fails, with a JSON body listing `database`, `queue`, `failed-jobs`, `mail` and `last-backup` (each with a label, a status and a message). Results are cached for 30 seconds, and the endpoint is throttled to 60 requests a minute per address. It runs outside the `web` group: no session, cookies, organisation or language.
- A stored failed job makes `failed-jobs` warn, but the endpoint stays 200; `queue:prune-failed` clears old ones every night. `last-backup` is skipped while the backups module is off.

```bash
curl -fsS -H "X-Health-Check-Key: $HEALTH_CHECK_KEY" https://example.com/health-check
```

## Backups

- **The module.** `MODULE_BACKUPS` (default `true`) switches it; it is always off on SQLite. Switch it in `.env`, then run `php artisan skv:doctor --fix`.
- **What is backed up:** the database only, as an encrypted zip on the `backups` disk (`storage/app/backups/<name>/`, where `<name>` is `APP_NAME` as a slug). No files are backed up.
- **`BACKUP_ARCHIVE_PASSWORD`** encrypts the archives (AES-256). Setup's environment step, the production `skv:bootstrap` and `skv:doctor --fix` each write a random one when it is blank. A blank password makes unencrypted backups, so while it is blank the doctor's `backup-password` row and the health check's `last-backup` row fail, and the nightly `backup:run` does not start. **Keep a copy outside the server**: without it no archive can be opened.
- **`BACKUP_NOTIFY_EMAIL`** receives the failure mails only (a failed backup, an unhealthy backup, a failed clean-up). Blank means `MAIL_FROM_ADDRESS`.
- **`PG_DUMP_BINARY_PATH`** is the directory that holds `pg_dump`. Blank finds `pg_dump` on PATH or in Herd's PostgreSQL services, also under cron, which has no Herd PATH.
- **Commands:** `php artisan backup:run --only-db` (a backup now), `backup:list` (the backups and their health), `backup:monitor` (mails when the newest backup is older than a day or the backups use more than 5000 MB) and `backup:clean` (applies the retention of `config/backup.php`'s `cleanup`: every backup for 7 days, then daily for 16 days, weekly for 8 weeks, monthly for 4 months and yearly for 2 years).
- **Restoring:** unzip the archive with the password, using a tool that reads AES-256 zips (for example `7z x <archive>.zip`), then load `db-dumps/postgresql-<database>.sql` into an empty database: `psql -d <empty-database> < db-dumps/postgresql-<database>.sql`.
- **Off the server:** point the `backups` disk at S3 (or another remote disk) in `config/filesystems.php`; the backup, the monitor and the System page all read that disk.

## Scheduler

The kit schedules the ops jobs in `routes/console.php`. Every job runs on one server (`onOneServer()`), and every job but the heartbeat never twice at once (`withoutOverlapping()`). The times are in `config('app.timezone')` (UTC by default).

| Job | When | Runs |
|---|---|---|
| `activitylog:clean --force` | 01:00 | always (removes audit entries older than `ACTIVITYLOG_RETENTION_DAYS`) |
| `backup:clean` | 01:30 | while the backups module is on |
| `backup:run --only-db` | 02:00 | while the backups module is on and the archive would be encrypted |
| `backup:monitor` | 03:00 | while the backups module is on |
| `queue:prune-failed` | 03:30 | always |
| `model:prune` | 04:00 | always (removes expired cross-host sign-in links) |
| `auth:clear-resets` | every 15 minutes | always |
| `scheduler-heartbeat` | every minute | always |

The backup jobs stay registered while the module is off; `php artisan schedule:list` and the System page show them as off, so switching the module on in `.env` needs no deploy. Run the scheduler with one cron line per server:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

The heartbeat stores the time of its last run, and the System page shows it, so a missing cron line shows there.

## System page

`/system` (*System status*, in the *Systeem* group of the sidebar) works on the admin host and the apex, for super admins only; on an organisation's host it answers 404. It shows:

- **Health:** the same cached report as `/health-check`, with the time it was checked and *Refresh*.
- **Backups:** per backup disk whether it is reachable, the number of backups, the newest one and the storage used, or why the module is off.
- **Schedule:** every scheduled job with its cron expression, its next run and whether it is currently on, and the scheduler's last heartbeat.
- **Doctor:** *Run checks* runs the doctor checks that start no process: `php`, `gd`, `app-key`, `marker`, `database`, `migrations`, `permissions`, `organisation-admin-role`, `super-admin`, `super-admin-badges`, `session-driver`, `authenticate-session`, `health-check-key`, `backup-password`, `assets`, `mail`, `pg-dump`, `queue`, `failed-jobs` and `storage-link`. Their repairs are not offered there: run `php artisan skv:doctor --fix`.

## The answers schema

`skv:install --answers='{"database":"sqlite"}'` takes a JSON object with these keys; `skv:install --no-interaction` uses the defaults as-is. `tests/Fixtures/e2e-answers.json` uses every key. `super_admin_password` goes in a file or on standard input, never inline on the command line:

```bash
php artisan skv:install --no-interaction --answers=@answers.json
php artisan skv:install --no-interaction --answers=- < answers.json
```

| Key | Type | Default |
|---|---|---|
| `app_name` | string | `Str::headline(basename(base_path()))` |
| `database` | `pgsql` \| `sqlite` | `pgsql` if a reachable Postgres was detected, else `sqlite` |
| `start_with` | `demo` \| `super_admin` | `demo`; outside local development (`local`, `testing`) `demo` is refused. With `super_admin`, `super_admin_email` + `super_admin_password` are required; with `--no-interaction` and either missing, the command fails before any step runs |
| `modules` | subset of `passkeys`, `backups`, `impersonation` | all three; `backups` removed on sqlite |
| `https` | bool | true; forced true while `passkeys` is on |
| `playwright` | bool | false |
| `claude` | bool | true if `claude` is on PATH, else false |

After an install, the answers of that install take the place of these defaults on a re-run, and the super admin it created needs no password again. Every run that finishes stores its answers, so the next re-run keeps what it changed. The `MODULE_*` flags in `.env` stand in for the `modules` answer, so a module you switched in `.env` stays switched. A `super_admin_password` given for an account that already exists replaces its password, also a demo account's.

## Demo accounts

With `start_with: demo` (the default), the demo seeder creates a super admin and the organisations `demo1` and `demo2`, each with an admin, a user and one pending invitation. The emails are derived from the apex, for example `superadmin@myapp.test`, `admin@demo1.myapp.test` and `user@demo1.myapp.test`. Every demo account has the password `password`, and the setup summary prints them. The demo accounts and invitations use the app's default language (`APP_LOCALE`).

The demo data runs only in the `local` and `testing` environments: the demo seeder refuses everywhere else, `DatabaseSeeder` skips it there, and `skv:install` refuses `start_with=demo` there before anything is written. Never publish the demo accounts or their password (skv2 finding R2): do not put them in a public page, a shared manual or a deployed environment.

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

- Never run `php artisan skv:install` there (it refuses in production); `php artisan skv:bootstrap` only prepares `.env`, `APP_KEY` and the generated secrets. Demo data never runs outside local and testing.
- Run `php artisan permissions:sync` on every deploy.
- Set a non-empty `HEALTH_CHECK_KEY` (setup and the production `skv:bootstrap` generate one), and point an uptime monitor at `/health-check` with the `X-Health-Check-Key` header; expect 200 (see [Health check](#health-check)).
- Set a non-empty `BACKUP_ARCHIVE_PASSWORD`: a blank one makes unencrypted backups (`php artisan skv:doctor --fix` writes one). Keep a copy off the server, and set `BACKUP_NOTIFY_EMAIL`.
- On several servers, set `OPS_CACHE_STORE` to a shared store (`redis`), so the heartbeat and the health report are the same on every server.
- Keep `SESSION_DRIVER=database`.
- Run a queue worker (`php artisan queue:work`), because invitation mails are queued. The queue can use Redis: `QUEUE_CONNECTION=redis` with the `REDIS_*` keys and phpredis or predis.
- Add the cron line from [Scheduler](#scheduler): `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`.

## AI

- `.claude/settings.json` denies dangerous commands with Bash deny rules, but a deny rule cannot stop every bypass (for example `--env`, `/usr/bin/php` or `sh -c`), as the Claude Code documentation says (DEC-25).
- Setup registers the Herd MCP server as `herd` in `.mcp.json`, so the deny on `mcp__herd__get_all_sites` applies. It registers it without `SITE_PATH`: with that variable (Herd's own AI integration adds it) the server also offers the tool `get_site_information` and the resource `herd://sites/information`, which return this site's `.env`, and, when `herd.yml` links a Forge site, tools that return the Forge site's environment and deployment output. `.claude/settings.json` denies those tools as well, but no deny rule covers the resource, so leave `SITE_PATH` out.
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
npm run build:setup     # rebuild resources/setup/setup.css
```

The setup pages render before `npm run build` has run, so `layouts/setup` does not use Vite: it inlines `resources/setup/setup.css`, a committed stylesheet built from `resources/css/setup.css` (Tailwind, Flux's CSS and the kit's `resources/css/theme.css`) by `vite.setup.config.js`. Run `npm run build:setup` and commit the result after you change the setup views (`resources/views/layouts/setup.blade.php`, `resources/views/pages/setup`, `resources/views/setup`) or the theme (`resources/css/theme.css`, `resources/css/setup.css`). CI fails when the committed file is not current.

## License

MIT
