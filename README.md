# skv3

An opinionated, multi-tenant Laravel starter kit: organisations by subdomain, invitations, roles and permissions, impersonation, audit logging and a Flux UI — set up from Laravel Herd in minutes.

> **Status:** under construction (milestone M6b — the browser setup wizard and the local quick login). See `docs/superpowers/specs/2026-10-10-skv3-design.md`.

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
| `php artisan skv:install [--answers=JSON] [--no-interaction] [--force]` | Runs the setup steps in order. Complete steps are skipped unless `--force`; a re-run keeps the answers of the earlier install and names the `.env` keys it changed. A step that cannot run prints its command to run yourself. |
| `php artisan skv:bootstrap` | The phase-1 bootstrap the Composer and installer hooks run: `.env`, `APP_KEY`, the Herd `APP_URL`, the SQLite migration and, while the app is not installed, the setup token. Prints where to finish setting up. |
| `php artisan skv:doctor [--fix] [--json]` | Checks the installation and prints the fix for every problem. Green means no `fail`. `--fix` performs only the safe repairs (`permissions:sync`, flag and role drift, `storage:link`, `core.hooksPath`, the asset build, a missing `HEALTH_CHECK_KEY`, and the `setup-token` fix, which deletes a setup token left after setup). The `marker` row fails when `storage/app/skv-installed` is not valid JSON. `--json` prints the report and exits 1 when it is not green. During setup, step 8 reports the `herd-site`, `herd-secured` and `setup-token` rows as `skipped` ("Checked after the Herd step"), because steps 9 and 10 have not run yet. |
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
