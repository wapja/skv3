# skv3

An opinionated, multi-tenant Laravel starter kit: organisations by subdomain, invitations, roles and permissions, impersonation, audit logging and a Flux UI — set up from Laravel Herd in minutes.

> **Status:** ready for v1.0.0: milestones M0–M8a are done. Publication on GitHub and Packagist and the `v1.0.0` tag (M8b) follow once the owner approves them. The design is in `docs/superpowers/specs/2026-10-10-skv3-design.md`, the decisions are in `docs/decisions.md` and the changes in `CHANGELOG.md`.

## Requirements

- PHP 8.4+ with the `zip` extension (spatie/laravel-backup needs it, also on SQLite) and GD with WebP support (profile photos), Composer 2, Node 22.13+ (required by `composer dev`)
- Laravel Herd (Herd Pro recommended for PostgreSQL and mail)

## Quickstart

Every route ends in the same setup, in the browser or in the terminal. The setup wizard and `php artisan skv:install` run the same steps: they ask a few questions, then write `.env`, create and seed the database, write `herd.yml` and the branding, build the frontend, set the git hooks, set up Claude Code when you choose it, run the doctor, write the install marker, link the Herd site (and secure it when HTTPS is on) and show a summary with the addresses, the logins and the next steps.

- **Herd** (no terminal needed): *New site* → *Custom Starter Kit* → `frankcornet/skv3` → *Open site*, or start from the deep link <https://herd.laravel.com/new/frankcornet/skv3>. The site's home page opens the [setup wizard](#the-setup-wizard).
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
  git clone https://github.com/frankcornet/skv3.git myapp && cd myapp && composer setup
  ```

  `composer setup` runs `composer install`, copies `.env.example` when `.env` is missing, then `php artisan skv:bootstrap` and `php artisan skv:install`, so it ends in the terminal. To use the wizard on a clone instead, run those commands up to and including `skv:bootstrap` yourself and open the site.

The first three routes install the package from Packagist. In every route `skv:bootstrap` runs first, from the Composer and installer hooks or from `composer setup`. It creates `.env` and `APP_KEY`, points a `localhost` `APP_URL` at the Herd site and migrates the SQLite file, so the app boots before setup runs. While the app is not installed it also writes the one-time setup token, and it prints "Open `<APP_URL>` to finish setting up in the browser, or run php artisan skv:install." `skv:install` runs the same bootstrap again as its step 0, so a bare `php artisan skv:install` on a clone works too.

## The setup wizard

The wizard lives at `/welcome-setup`, but you never type that address: open the site's home page and it sends you there with the token.

- **When it opens.** Only in *setup mode*: `APP_ENV=local`, no install marker (`storage/app/skv-installed`) and a setup token. Every other request to `/welcome-setup` gets a 404. So does a request from another machine: only loopback addresses (`127.0.0.0/8`, such as `127.0.0.1`, and `::1`, but not an IPv4-mapped address such as `::ffff:127.0.0.1`) get in, also when the site listens on every address. So does a request that arrives through a proxy or a tunnel such as `herd share`, which carries an `X-Forwarded-*`, `X-Expose-*`, `CF-*`, `Tailscale-*`, `Forwarded`, `X-Forwarded`, `Forwarded-For`, `Via`, `X-Original-Host`, `X-Original-URL`, `X-Host`, `X-Real-IP`, `True-Client-IP`, `X-Client-IP`, `Client-IP`, `X-Cluster-Client-IP` or `Fastly-Client-IP` header. `/` hands out the token only while no super admin exists, and only on a host the wizard accepts. Accepting the token starts a fresh session (nothing of an earlier session carries over, and its file is deleted) that keeps a hash of the token, so a session id from before the token never gets in, and removes the token from the address bar and from the session's previous URL. A tunnel or port forward that adds no such header (`ssh -R`, `ngrok tcp`, `socat`, a router's port forward to `127.0.0.1`) arrives from loopback and does get in, so never expose the site through a tunnel or port forward before setup has finished.
- **While it runs.** Every web request uses a file session, a file cache and the sync queue, so the wizard never depends on the database it is setting up, and organisation hosts are not resolved yet.
- **The host.** Under Herd, open the setup on `myapp.test` or `admin.myapp.test`; without Herd on `localhost` or a name ending in `.localhost` or `.test`, with the port you serve it on (`APP_URL` keeps that port). On any other host `/` hands out no token and sends you to the address to use: without Herd to `http://localhost:<port>/`, also from an IP address such as `127.0.0.1` (the address `php artisan serve` prints), and under Herd to `http://myapp.test/`. Where no address can be named, `/` answers 409 with a page that names the problem: a folder whose name is not a valid host name (for example `my_app`) cannot be served as `my_app.test`, so the page asks you to rename the folder (`my-app`) and start the setup again; when `herd tld` does not answer, it asks you to reload instead of guessing. Under Herd the app's address is pinned to `myapp.test`, whichever of the two you started on. From then on every wizard and Herd-stage request must use that host or its `admin.` form.
- **The questions** are the [answers schema](#the-answers-schema), pre-filled with the detected defaults: the app name, the database, what to start with (demo data or your own super admin with an email and a password), the modules, HTTPS, Playwright and Claude Code. The password is hashed on entry; only the hash is stored until the super admin exists. With a super admin already in the database the demo data is not added by default, as `skv:install` asks for `"start_with": "demo"`: the wizard says so, and clicking *Start setup* again adds it anyway (or choose *My own super admin*).
- **The steps** run one at a time, each in its own request, and the page shows each one as done, skipped, manual or waiting. The long steps (the frontend build and Claude Code) run in the background as `php artisan skv:run-step <step>`, and the page checks on them every two seconds. A reload continues where the setup was, and neither a reload nor a second tab runs a step twice: one request at a time holds the setup lock, and the others keep waiting for its step. A step that fails shows *Setup stopped* with its message and *Try again*, and a start that cannot write its state shows its message under the questions, never an error page. A long step whose process still runs cannot be re-checked until it ends; after four hours its process is no longer trusted, and *Re-check* works again.
- **Manual commands.** A step that cannot run by itself shows its command with *Copy* and *Re-check*: run the command in a terminal, then click *Re-check*. When a step ended this way, the wizard stops in a review after the doctor step (step 8), so *Re-check* still works; *Finish setup* goes on, and the summary lists every command that is left. Without one it goes straight on.
- **The Herd stage.** *Finish setup* writes the install marker, which closes the wizard (from then on `/welcome-setup` answers 404), and runs the Herd step in the background: it links the site when Herd does not serve it yet and secures it when HTTPS is on. Herd may restart nginx and PHP meanwhile; the page waits up to 60 seconds and moves to `https://` by itself once the site is secured. The summary then shows the addresses, the logins and the next steps (`composer dev`, signing in, `php artisan skv:doctor`). *Log in as `<email>`* signs your new super admin in on the admin host with a single-use link (a super admin who already confirmed two-factor sign-in goes to the admin host's login page instead, so the second factor is never skipped); with demo data, *Go to the login page* takes you to the [quick login](#local-quick-login). These pages only work in the browser that ran the wizard, on the pinned host, for at most 30 minutes, and the login works once; they send `Referrer-Policy: no-referrer`, so the secret in their address never leaves in a Referer header. When the Herd step cannot start at all, the page goes straight to the command to run yourself. When something fails after the marker is written, the page says *Setup is done, but the Herd step could not start.* and links to the login page.
- **"Continue without HTTPS".** When Herd did not confirm HTTPS in time, the page shows the command that is left: `herd secure <name>`, `herd link <name>` while Herd does not serve the site yet, or `herd isolate 8.4 --site=<name>` while Herd serves it with an older PHP. A name with characters a shell would split is quoted. Run it and click *Reload*, or click *Continue without HTTPS*: `APP_URL` stays `http://`, the marker records `https: false`, passkeys are switched off when they were on (they need HTTPS), and the summary says how to secure the site later. The doctor's `herd-secured` row then reports `skipped`, so the install is still green. Without HTTPS chosen, the page says *Herd did not confirm the site in time.* and its button is *Continue* (passkeys are off already); without Herd there is no command, and the page says so.

## Local quick login

After a local install with demo data, the login page lists the demo accounts under *Quick login (local only)*: one click signs you in on the account's own host. It shows only when `APP_ENV=local`, the install marker records `start_with: demo` and the request comes straight from this machine (not through a proxy or a tunnel such as `herd share`). It lists only demo accounts that exist, are active and have no confirmed two-factor authentication (the quick login skips the second factor). An account whose password you changed stays in the list, but clicking it takes you back to the login page with a message. Everywhere else `POST /quick-login` answers 404, also for a malformed request.

## The setup files

| File | What it holds | Deleted by |
|---|---|---|
| `storage/app/private/skv-setup-token` | the one-time setup token | step 9 (the marker); `php artisan skv:doctor --fix` deletes one that is left after setup |
| `storage/app/private/skv-setup.json` | the wizard's pinned host, answers (the password only as a hash), the detection taken at the start, step results and notes | step 9 |
| `storage/app/private/skv-setup/` | per long step its result (`<step>.json`), output (`<step>.log`), exit code (`<step>.exit`) and the process id of its child (`<step>.started`) | step 9 |
| `storage/app/private/skv-herd.json` | the Herd stage: a hash of its secret, the host, the answers without the password, the detection taken at the start, the results and, once Herd finished, its result | the summary's login button; after 30 minutes it is stale and you can delete it |
| `storage/app/private/skv-herd.exit` | the Herd step's exit code | the summary's login button |
| `storage/app/private/skv-setup.lock` | nothing: an empty file whose lock lets one wizard request at a time change the wizard state or the Herd state | the summary's login button |
| `storage/logs/skv-herd.log` | the Herd step's output | nothing: delete it yourself when you no longer need it |
| `storage/app/skv-installed` | the install marker: kit version, answers without secrets, `start_with`, time and summary | nothing: it marks the app as installed |

The state files are written atomically with mode 0600. The logs and exit codes of the long steps and the Herd step, which their shell writes, get mode 0600 too, but are not written atomically. A state file that is a symbolic link or cannot be read counts as damaged. Only the token file holds a secret in plain text; the others hold hashes. Never write them by hand.

Setup also writes `.env.testing` with mode 0600 and creates a missing `.env` with mode 0600, whatever the mode of `.env.example` (`skv:bootstrap` and Composer's `.env` hook do the same for `.env`), and creates the `public/storage` link in its environment step. An `.env` that already exists keeps its mode, so run `chmod 600 .env` on one you copied by hand or kept from an older install.

## Commands

| Command | What it does |
|---|---|
| `php artisan skv:install [--answers=JSON\|@file\|-] [--no-interaction] [--force]` | Runs the setup steps in order. Complete steps are skipped unless `--force`; a re-run keeps the answers of the earlier install and names the `.env` keys it changed. A step that cannot run prints its command to run yourself. `--answers` takes inline JSON, `@path` (a file, relative to the working directory) or `-` (standard input); inline JSON with a non-empty `super_admin_password` (a number too) is refused before anything runs, so a password never shows in the process list or the shell history. It runs only in the `local`, `testing` and `staging` environments and refuses any other `APP_ENV` before anything is written; outside local development (`local`, `testing`) it also refuses `start_with=demo`. Staging uses the strict password rule (12 or more characters, letters, mixed case, a number, a symbol, checked against haveibeenpwned over the network). |
| `php artisan skv:bootstrap` | The phase-1 bootstrap the Composer and installer hooks run: `.env`, `APP_KEY`, the Herd `APP_URL`, the SQLite migration and, while the app is not installed, the setup token. Prints where to finish setting up. In production (the `.env` or the process environment names an `APP_ENV` outside `local`, `testing` and `staging`, such as `production`, `prod` or `live`, or no source names one at all, which Laravel treats as `production` too) it only prepares `.env`, `APP_KEY` and the blank generated secrets (`HEALTH_CHECK_KEY`, `BACKUP_ARCHIVE_PASSWORD`; it names the keys, never their values) and points you at the [production checklist](#production-checklist). |
| `php artisan skv:doctor [--fix] [--json]` | Checks the installation and prints the fix for every problem. Green means no `fail`. `--fix` performs only the safe repairs (`permissions:sync`, flag and role drift, `storage:link` (with `--force` only over a wrong or dangling link; a directory or a file in its place is never removed), `core.hooksPath`, the asset build, a missing `HEALTH_CHECK_KEY` or one shorter than 32 characters (replaced by a random 40-character key), a missing `BACKUP_ARCHIVE_PASSWORD` while the backups module is on, and the `setup-token` fix, which deletes a setup token left after setup). The `marker` row fails when `storage/app/skv-installed` is not valid JSON, cannot be read or is a symbolic link; while the database is unreachable, `database` fails and `queue` and `failed-jobs` are skipped instead of reporting a missing table; the `failed-jobs` row warns while failed jobs are stored, and the `backup-password` row fails while the backups module is on and `BACKUP_ARCHIVE_PASSWORD` is blank. The `storage-link` row warns while `public/storage` is missing and fails while it is anything but a link to `storage/app/public` (a link elsewhere, a dangling link, a directory or a file; a link to the project root would serve `.env` under `/storage`), and the `gd` row warns when GD cannot write WebP, which profile photos need. `--json` prints the report and exits 1 when it is not green. During setup, step 8 reports the `herd-site`, `herd-secured` and `setup-token` rows as `skipped` ("Checked after the Herd step"), because steps 9 and 10 have not run yet. |
| `php artisan skv:create-super-admin [email] [--name=] [--password-stdin]` | Creates a super admin, or promotes an existing active user; a `--name`, or a password given with `--password-stdin`, replaces an existing user's own. `--password-stdin` reads the password from the first line of standard input: `printf '%s\n' "$PASSWORD" \| php artisan skv:create-super-admin you@example.com --password-stdin --no-interaction`. Prompts for what is missing; with `--no-interaction` the email and `--password-stdin` are required for a new user. `--password=` is gone: a password in argv shows in the process list and the shell history. On a terminal, `--password-stdin` refuses to read, because the password would echo: pipe it in. A password hash given in place of the password is refused. |
| `php artisan skv:create-organisation [name] [slug] [admin-email] [--description=] [--locale=] [--as=]` | Creates an organisation and invites its first admin. It acts as an active super admin: `--as=<email>` picks which one, the default is the oldest active one. `--locale` is the first admin's language and defaults to the app's default language (`APP_LOCALE`). |
| `php artisan skv:flux-pro` | Installs Flux Pro with your own Composer credentials (see [Flux Pro](#flux-pro)). |
| `php artisan permissions:sync` | Syncs the permission catalogue and the system roles, and prints every change it made. |
| `composer doctor` | Runs `php artisan skv:doctor`. |

Audit entries written while an Artisan command runs are tagged with it (`via: console` and the command name in their properties), and the audit log shows them as *Command line*, or as the causer's name followed by *via the command line* when they have one.

## Configuration

- **`APP_URL`** must be the public origin, scheme and port included. The links the kit builds with `Hosts::url()` and `tenant_url()` take their scheme and port from it, never from the request: the reset, email-change, invitation and handoff links, the links and redirects to another host, and the passkey origin. Behind a TLS-terminating proxy it is `https://…`, because `X-Forwarded-Proto` does not make those links https, and a passkey on another port than `APP_URL`'s fails.
- **`TRUSTED_PROXIES`** is still needed behind a load balancer, CDN or TLS-terminating proxy: its addresses (comma-separated) or `*`. A page's own links, form actions and redirects on its host come from the request (Laravel's `route()` and `url()`), and the app reads the client IP, the host, the scheme and the port from the `X-Forwarded-*` headers of a trusted proxy only. Without it the limits and the logs see the proxy's address, and behind a TLS proxy an https page posts its forms to `http://…`. Behind a TLS proxy, set both `APP_URL=https://…` and `TRUSTED_PROXIES`.
- **`APP_LOCALE`** is the default language and defaults to `nl`.

## Accounts and security

- **Limits** (per user). Requesting an email change: 3 per 10 minutes. Changing the password, confirming two-factor sign-in and *Log out other devices*: 5 per minute, every attempt counts. Inviting and reminding: 10 per minute each (the invite limit also covers the first administrator's invitation from the organisation wizard), and one reminder per invitation per 10 minutes. Bulk invitations: 100 per hour per administrator. An administrator changing another user's address: 10 per hour. Exporting the activity log: 5 per minute. Saving a profile photo: 10 per 10 minutes, every attempt counts. Uploading a file (the photo or a bulk invitation list): 10 per minute and 60 per hour (`livewire-upload`). The two-factor management routes and removing a passkey: 10 per minute (`account-security`). Looking up whether an address can be used: 500 addresses a day, every lookup counts, also one that is refused: a new address on the profile page or on an administrator's *Edit user*, the address on *Invite user*, and every row of a bulk invitation's *Check the list* (sending a checked list adds nothing); during an impersonation the lookups count for the impersonator, and the profile page refuses a new address before it looks anything up. Above a limit the form says *Too many attempts. Try again in … seconds.*
- **Sign-in limits** (per address and IP address). Five failed sign-ins a minute per address and IP address, and 20 failed sign-ins a minute per IP address. A successful sign-in, also with a second factor or a passkey, clears that address's failed attempts from that IP address. A throttled sign-in returns to the login page.
- **Passkeys and the security page.** With `MODULE_PASSKEYS=false` the passkey actions answer 404, also when called directly. A passkey whose signature counter did not increase (possibly a cloned authenticator) is refused and writes one warning line to the log. A failed passkey sign-in appears in the audit log as a failed sign-in with `method: passkey`; it names the account only for the host's own members, and on the central hosts. After an `APP_KEY` rotation without `APP_PREVIOUS_KEYS`, and for codes stored in a form Fortify never writes, the recovery codes show "Failed to load recovery codes": regenerate them.
- **Cross-host sign-in links.** *Open* on the organisations list, an organisation's new address after a rename, the setup wizard's last step and the demo quick login sign you in on another host with a link that works once, for 60 seconds, only on the host it was made for, and only while your password is the one you had when it was made: changing it on the security page, resetting it or setting it from the command line (`skv:create-super-admin --password-stdin`) ends every link made before. Opening links is limited to 10 a minute per IP address.
- **Sessions.** Settings → Security lists your sessions: browser and platform, IP address, last activity and *This device*. Only sessions within `SESSION_LIFETIME` are listed. *Log out other devices* asks for your password (5 attempts a minute). On their next request it signs out every other session and every "remember me" cookie, on every host, and it ends your unused cross-host sign-in links. It also gives this device a new session, so a copy of this browser's session cookie is signed out too. The audit log records how many sessions it ended (*Other devices signed out*). The section is hidden during an impersonation. The list needs `SESSION_DRIVER=database`: with another driver the list is empty, but the button still works, because it rehashes the password.
- **Second factor.** On an organisation's edit page, its administrators (anyone who may edit the organisation) and super admins can switch on *Require a second factor*. Changing it, either way, asks for your password; after the confirmation the page shows your unsaved changes again (*Your unsaved changes are back. Save again to apply them.*), and Save applies them. An organisation admin needs a second factor of their own before switching it on. Members without one are sent to Settings → Security with a notice until they set up two-factor authentication or, with passkeys on, a passkey. A Livewire action on another page sends them there too, and any other request that is not a GET gets a 403. Impersonation sessions are not affected, and during one the security page shows no second-factor notice. Fortify asks for a two-factor code only after the password, so a user the rule applies to whose only second factor is a passkey signs in with the passkey: once the password is right, the password sign-in is refused with *Sign in with your passkey: a password alone is not enough for this account.* A user without any factor yet still signs in with the password and is sent to set one up. Someone who loses their only passkey cannot sign in until an administrator switches the rule off, so also setting up two-factor authentication, with its recovery codes, is the fallback. `REQUIRE_SECOND_FACTOR_SUPER_ADMINS` (default `false`) requires one of every super admin, on every host. The routes that stay reachable (setting up a factor, confirming the password, signing out, the cross-host sign-in, ending an impersonation, the setup wizard) are listed in `config('skv.second_factor.exempt_routes')`. A link from an email-change mail is not among them: a user who still has to set up a factor goes to the security page first, and the link keeps working until it expires.
- **Activity log export.** *Export CSV* downloads exactly what the filters show, from the organisations the log shows, as a streamed CSV: UTF-8 with a BOM, ISO 8601 dates in the app's time zone. The columns are date, event key, action, done by, subject, organisation (central hosts only), IP address, user agent and properties (JSON). IP address and user agent stay empty where the log hides them for platform staff. Where a spreadsheet could start a formula (at the start of a cell, or after a `;`, tab or line break inside it: `=`, `+`, `-`, `@`, a tab, a CR or their full-width forms), the export puts a `'` in front, so an apostrophe inside a value is the export's doing. Each export is recorded in the log (`audit.exported`, with the filters it applied). Exports are limited to 5 per minute per user, refused requests included. A very large export is bound by PHP's `max_execution_time`.
- **Bulk invitations.** Reach them through *Invite several people* on the pending invitations page, with the same rights as *Invite user*; on a central host a super admin first picks the organisation. Paste addresses (one per line, or separated by commas or semicolons; at most 30,000 characters) or upload a CSV of at most 512 KB, or both. The CSV has an email column and an optional `nl`/`en` locale column. The header row is optional and recognised only by its column names: the first row is a header when none of its cells holds an `@` and a cell contains "mail" without a dot (email, e-mail, e-mailadres …) or is `locale`, `language`, `lang` or `taal`; without one, the first column is the address and the second the locale. Commas or semicolons are fine, and a BOM is allowed. The file is only read as text and never stored by the kit: Livewire's temporary upload is deleted once the list is sent or the file is removed or replaced (a file left on an abandoned page is removed by the hourly `livewire-tmp-cleanup` once it is 24 hours old, see [Scheduler](#scheduler)). Roles and the default language are chosen once, and a list has at most 100 rows. *Check the list* marks each row *Ready*, or skipped with its reason: not a valid address, listed more than once, unknown language, or *This address can't be invited.* (one message for every address refusal, so the list never tells which addresses exist elsewhere). Any change to the input clears the preview, and the rows are checked again when sent. The summary shows what was sent and what was skipped; when something unexpected fails halfway through, it is reported, and the rows not reached yet are skipped as *Not sent: something went wrong.* without counting against the limit. Mails are queued in each row's language. The limit is 100 invitations per hour per administrator: only the rows sent count, a list larger than what is left of the hour is refused before anything is sent, and bulk invitations do not use the single-invite limit. With Flux Pro the file can be dropped on an upload zone.
- **Profile photo.** Upload a JPEG, PNG or WebP of at most 2 MB and 4096 × 4096 pixels on the profile page. It is processed with `intervention/image` ^4 through Laravel's `Illuminate\Image` on the GD driver (`UpdateAvatar` also catches Intervention's own `DecoderException`, to tell an image it cannot read from a server fault): turned upright, cropped to 512 × 512 and stored as WebP without metadata, at `APP_URL/storage/avatars/{organisation id|platform}/{user id}-{random}.webp`: a public but unguessable URL. Processing a photo close to 4096 pixels needs about 270 MB; `UpdateAvatar` raises `memory_limit` for that request (to the memory in use plus about 320 MB), and refuses the photo when the host forbids raising it (`php_admin_value`). It needs `public/storage` (setup creates the link; `skv:doctor --fix` repairs a missing, wrong or dangling one) and GD with WebP support. Without WebP support an upload fails with a reported server error, and the doctor's `gd` row warns. ext-exif is optional; without it a photo keeps the orientation it was stored with. The initials show wherever there is no photo (the user menu, the users list). Replacing or removing a photo deletes the old file, and purging an organisation deletes its users' photos; deleting a user keeps the photo for a restore. Saving a photo is limited to 10 attempts per 10 minutes per user, counted before the photo is checked, because each one may decode up to 4096 × 4096 pixels. The uploaded original, with its metadata, is deleted from Livewire's temporary uploads once it is processed, or when another photo is chosen or the photo removed. The section is hidden during an impersonation. The free upload is a file input, whose own *Choose file* text stays English; with Flux Pro it is a drop zone.
- **Uploads.** Livewire's temporary-upload endpoint (`config/livewire.php`) takes one file of at most 2 MB per request, only from a signed-in user, at most 10 a minute and 60 an hour per user (the `livewire-upload` limiter); `App\Http\Middleware\LimitTemporaryUploads` refuses a request with more files (422). The pages check the type and size again (a bulk invitation list is at most 512 KB), and `UpdateAvatar` refuses any image type but JPEG, PNG and WebP before it decodes one. Livewire's temporary uploads older than 24 hours are removed every hour (`livewire-tmp-cleanup`, see [Scheduler](#scheduler)).
- **Passwords.** A new password is never a password hash: the invitation, the reset, the security page and `skv:create-super-admin` refuse one, because the `hashed` cast would store it as the account's hash.
- **Impersonation.** A language or appearance chosen during an impersonation lasts until it ends and never changes the target's account. An impersonation cannot start an email change for the target. An impersonation that ends because the impersonator is disabled, deleted, archived or purged, or because the session expires, writes no `impersonation.stop` entry: the session is gone, and the start entry (with the status change, if any) is the record.
- **Languages.** The user menu's language switch reloads the current page, query string and filters included. The message after a forced sign-out or an ended impersonation is in the language of the page that shows it. The email-change mails and the notice after an administrator changed your address are in the account's language, also from a queue worker. Known limitation: the copy inside the Flux components that Blaze folds when a view compiles stays English in both languages (the password field's visibility toggle, an input's clear and copy buttons, a modal's close button, the one-time-code input's character label and the free file input's *Choose file* and *No file chosen*), because a folded component translates once, for everyone (DEC-29).
- **Error pages.** 401, 402, 403, 404, 419, 429, 500 and 503 have their own page, and every other 4xx and 5xx status (405, 413, 502 …) a fallback, all in the visitor's language. *Try again* reloads the page (inside Livewire's error dialog, the top page) as a link on the same origin; after a refused or expired form post, *Try again* and the 419 page's *Reload the page* go back to the page the form was on; *Back* never leaves the site.
- **Audit log.** The stored user agent is cut to 500 characters of valid UTF-8, without NUL bytes. Invitation mails skip an invitation that was cancelled, accepted or expired before the queue worker ran; a mail for an organisation archived meanwhile still fails, by design.

## Health check

- **`/up`** is liveness only: the framework answers 200 while the app boots.
- **`/health-check`** needs the header `X-Health-Check-Key` with the value of `HEALTH_CHECK_KEY`. Setup and the production `skv:bootstrap` generate the key, and `skv:doctor --fix` writes one when it is missing. A missing or wrong header, and a blank `HEALTH_CHECK_KEY`, always get 403. A key shorter than 32 characters counts as no key: the endpoint answers 403, the doctor's `health-check-key` row fails and `skv:doctor --fix` replaces it with a random 40-character key. A key you set by hand must be random (`openssl rand -hex 20`, or let `php artisan skv:doctor --fix` write one), because the per-key limit below does not slow down guessing.
- It answers 200 when every check passes and 503 when one fails, with a JSON body listing `database`, `queue`, `failed-jobs`, `mail` and `last-backup` (each with a label, a status and a message). Results are cached for 30 seconds; when the ops store cannot be written, the endpoint still answers with a fresh report. Requests with the right key may come 60 times a minute for that key, and all requests without it share one limit of 300 a minute, so strangers can never lock your monitor out. A throttled request gets 429 with `Retry-After` and `Cache-Control: no-store`. The per-key count is kept under an HMAC of the key with `APP_KEY`, so rotating `APP_KEY` resets it. While the ops store is broken the limits are off: every request goes through and writes one warning line to the log (the exception's class only). It runs outside the `web` group: no session, cookies, organisation or language.
- A stored failed job makes `failed-jobs` warn, but the endpoint stays 200; `queue:prune-failed` clears old ones every night. `last-backup` is skipped while the backups module is off.
- With the module on, `last-backup` fails while a backup disk holds no backup yet, so a new deploy, or a module switched on later, answers 503 until the first backup: run `php artisan backup:run --only-db` once after deploying. The newest backup may be up to a day and two hours old (`skv.health_check.backup_grace_minutes`, 120), because an archive is named after the time its job starts: the nightly run at 02:00 never turns the endpoint into a 503 while it works.
- A PostgreSQL server that does not answer costs at most `DB_CONNECT_TIMEOUT` seconds (5) per report: `database` fails, and `queue` and `failed-jobs` are skipped instead of reporting a missing table.
- The endpoint and the System page judge the running configuration (the mail settings, `HEALTH_CHECK_KEY`). `skv:doctor` and setup read `.env`, because they judge the file they repair.

```bash
printf 'X-Health-Check-Key: %s\n' "$HEALTH_CHECK_KEY" | curl -fsS -H @- https://example.com/health-check
```

The key reaches curl on standard input (curl 7.55 or later), so it never shows in the process list.

## Backups

- **The module.** `MODULE_BACKUPS` (default `true`) switches it; it is always off on SQLite. Switch it in `.env`, then run `php artisan skv:doctor --fix`.
- **What is backed up:** the database only, as an encrypted zip on the `backups` disk (`storage/app/backups/<name>/`, where `<name>` is `APP_NAME` as a slug). No files are backed up: back up the profile photos in `storage/app/public/avatars` yourself. A database restored without them points at photos that are gone, which show as broken images until each user saves a new photo or removes theirs. On the local disk the archives are written 0600 and their folders 0700; older archives keep their mode until the clean-up removes them.
- **`BACKUP_ARCHIVE_PASSWORD`** encrypts the archives (AES-256). Setup's environment step, the production `skv:bootstrap` and `skv:doctor --fix` each write a random one when it is blank. The package would write unencrypted backups for a blank password, so while the running configuration has none, `backup:run` refuses to start (also by hand: it prints one error line and exits 1 before anything is dumped), the schedule does not start the nightly one, and the doctor's `backup-password` row and the health check's `last-backup` row fail. `backup:run` reads the configuration, not `.env`: on a server with a config cache, run `php artisan config:cache` after `skv:doctor --fix` has written a password. **Keep a copy outside the server**: without it no archive can be opened.
- **`BACKUP_NOTIFY_EMAIL`** receives the failure mails only (a failed backup, an unhealthy backup, a failed clean-up). Blank means `MAIL_FROM_ADDRESS`.
- **`PG_DUMP_BINARY_PATH`** is the directory that holds `pg_dump`. Blank finds it: when the `pgsql` connection's host (or `DB_URL`'s) is `127.0.0.1`, `localhost` or `::1`, Herd's newest client comes first, because that server is the one being dumped, then the one on PATH; for any other server the one on PATH comes first, then Herd's. Both work under cron, which has no Herd PATH. A Herd binary is used only when it and every directory above it up to and including `/Users/Shared/Herd` are no symbolic links, belong to you or root and are not group- or world-writable. `pg_dump` uses the connection's `sslmode` (`DB_SSLMODE`, or the `sslmode` in `DB_URL`); `backup:run --config` and `--db-name` use the sources they dump. All dumps of one run share one `PGSSLMODE`: with several PostgreSQL sources the strictest mode applies to every source, so a source that cannot meet it needs its own `--config`.
- **The temporary dump.** `backup:run` writes the plain dump to `storage/app/backup-temp` (`backup.backup.temporary_directory`) before it zips it. A backup makes that directory private (mode 0700) when it starts, and every hour at :15 the scheduler removes the files older than six hours from the package's working directory under it (`storage/app/backup-temp/temp`), also while the backups module is off. Neither follows a symbolic link or touches a directory another account owns. When the directory cannot be made private (a symbolic link, a shared or the system temporary directory, another account's), `backup:run` refuses to start with one error line. Only a killed backup leaves files there; the next `backup:run` empties the directory too. A `backup:run` as root (a root crontab) accepts only a temporary directory inside `storage/`.
- **ext-posix** is not required, but without it the backups cannot tell this account's directories: `backup:run` refuses to start, Herd's `pg_dump` counts only when root owns it, and the doctor's `pg-dump` row fails and says why.
- **Commands:** `php artisan backup:run --only-db` (a backup now; it refuses to start while the archive would not be encrypted; the health check's `last-backup` row names the one cause: no password, encryption switched off, or a PHP zip without AES-256), `backup:list` (the backups and their health), `backup:monitor` (mails when the newest backup is older than a day or the backups use more than 5000 MB) and `backup:clean` (applies the retention of `config/backup.php`'s `cleanup`: every backup for 7 days, then daily for 16 days, weekly for 8 weeks, monthly for 4 months and yearly for 2 years).
- **Restoring:** unzip the archive with the password, using a tool that reads AES-256 zips (for example `7z x <archive>.zip`), then load `db-dumps/postgresql-<database>.sql` into an empty database: `psql -d <empty-database> < db-dumps/postgresql-<database>.sql`.
- **Off the server:** point the `backups` disk at S3 (or another remote disk) in `config/filesystems.php`; the backup, the monitor and the System page all read that disk.

## Scheduler

The kit schedules the ops jobs in `App\Support\Scheduler\OpsSchedule::define()`, which `routes/console.php` calls; the System page reads the same definition without booting the console kernel. Every job but `backup-temp-cleanup` and `livewire-tmp-cleanup` runs on one server (`onOneServer()`), because each server has its own temporary directory and its own temporary uploads, and every job but the heartbeat and those two never twice at once (`withoutOverlapping()`). The backup jobs' overlap lock expires after 12 hours instead of 24, so a killed backup never skips the next night. The times are in `config('app.timezone')` (UTC by default).

| Job | When | Runs |
|---|---|---|
| `activitylog:clean --force` | 01:00 | always (removes audit entries older than `ACTIVITYLOG_RETENTION_DAYS`) |
| `backup:clean` | 01:30 | while the backups module is on |
| `backup:run --only-db` | 02:00 | while the backups module is on and the archive would be encrypted |
| `backup:monitor` | 03:00 | while the backups module is on |
| `queue:prune-failed` | 03:30 | always |
| `model:prune` | 04:00 | always (removes expired cross-host sign-in links) |
| `backup-temp-cleanup` | every hour at :15 | always, also while the backups module is off (removes files older than six hours from `storage/app/backup-temp/temp`) |
| `livewire-tmp-cleanup` | every hour at :45 | always (removes Livewire's temporary uploads older than 24 hours, such as a photo or a list of addresses left on an abandoned page; on an S3 temporary-upload disk it does nothing: run `php artisan livewire:configure-s3-upload-cleanup` once, so the bucket expires them; and nothing while `livewire.temporary_file_upload.cleanup` is false) |
| `auth:clear-resets` | every 15 minutes | always |
| `scheduler-heartbeat` | every minute | always |

The backup jobs stay registered while the module is off; `php artisan schedule:list` and the System page show them as off, so switching the module on in `.env` needs no deploy. Run the scheduler with one cron line per server:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

The heartbeat stores the time of its last run, and the System page shows it, so a missing cron line shows there.

## System page

`/system` (*System status*, in the *System* group of the sidebar, *Systeem* in Dutch) works on the admin host and the apex, for super admins only; on an organisation's host it answers 404. It shows:

- **Health:** the same cached report as `/health-check`, with the time it was checked and *Refresh*.
- **Backups:** per backup disk whether it is reachable, the number of backups, the newest one and the storage used, or why the module is off.
- **Schedule:** every scheduled job with its cron expression, its next run and whether it is currently on, and the scheduler's last heartbeat.
- **Doctor:** *Run checks* runs a selection of the doctor checks that start no process; the development-only ones stay out. The selection: `php`, `gd`, `app-key`, `marker`, `apex`, `database`, `migrations`, `permissions`, `organisation-admin-role`, `super-admin`, `super-admin-badges`, `session-driver`, `authenticate-session`, `health-check-key`, `backup-password`, `assets`, `mail`, `pg-dump`, `queue`, `failed-jobs` and `storage-link`. Their repairs are not offered there: run `php artisan skv:doctor --fix`.

*Refresh* and *Run checks* allow six runs a minute per user; above that the page keeps its rows and says *Too many attempts. Try again in a minute.* When the ops store or a backup disk fails, the page still opens: it says the health report is unavailable, or shows that disk as unreachable.

## The answers schema

`skv:install --answers='{"database":"sqlite"}'` takes a JSON object with these keys; `skv:install --no-interaction` uses the defaults as-is. `tests/Fixtures/e2e-answers.json` uses every key. `super_admin_password` goes in a file or on standard input, never inline on the command line. Keep that file outside the project, readable only by you, and remove it afterwards (`/answers.json` is git-ignored as a safety net):

```bash
(umask 077 && "${EDITOR:-vi}" ~/setup-answers.json)
php artisan skv:install --no-interaction --answers=@"$HOME/setup-answers.json"   # or: --answers=- < ~/setup-answers.json
rm ~/setup-answers.json
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

After an install, the answers of that install take the place of these defaults on a re-run, and the super admin it created needs no password again. Every run that finishes stores its answers, so the next re-run keeps what it changed. The `MODULE_*` flags in `.env` stand in for the `modules` answer, so a module you switched in `.env` stays switched. A `super_admin_password` given for an account that already exists replaces its password, also a demo account's; the same password again changes nothing (no new remember token, no audit entry).

## Demo accounts

With `start_with: demo` (the default), the demo seeder creates a super admin and the organisations `demo1` and `demo2`, each with an admin, a user and one pending invitation. The emails are derived from the apex, for example `superadmin@myapp.test`, `admin@demo1.myapp.test` and `user@demo1.myapp.test`. Every demo account has the password `password`, and the setup summary prints them. The demo accounts and invitations use the app's default language (`APP_LOCALE`).

The demo data runs only in the `local` and `testing` environments: the demo seeder refuses everywhere else, `DatabaseSeeder` skips it there, and `skv:install` refuses `start_with=demo` there before anything is written. Never publish the demo accounts or their password (skv2 finding R2): do not put them in a public page, a shared manual or a deployed environment.

## Flux Pro

The kit works fully on free Flux. To switch on the Pro components in your app, add your Flux Pro licence to your global Composer credentials and install the package:

```bash
composer config --global http-basic.composer.fluxui.dev <email> <license-key> && php artisan skv:flux-pro
```

`skv:flux-pro` never writes or prints your credentials, and runs `view:clear` after it installs the package. Before it requires the package it marks `composer.json` with `"extra": {"skv": {"flux-pro": true}}`, so the lock stays current, and it removes the mark and the `repositories.flux-pro` entry again when the install fails or is interrupted (Ctrl-C or a Composer timeout included), but only what it added: a `repositories.flux-pro` entry or other `extra.skv` keys that `composer.json` already held stay; when a removal fails, it prints the `composer config --unset` command to run. Commit both Composer files to keep Pro in your app: the kit's test "never references Flux Pro in the committed composer files" then skips, because your commit carries the mark.

## Local subdomains without Herd

Herd serves `myapp.test` and every subdomain of it. Without Herd, the apex, `admin.` and every organisation must resolve to your machine:

- add the `/etc/hosts` lines that `php artisan skv:doctor` prints when `probe.{apex}` does not resolve (the apex, the admin host and every organisation's host), or
- run dnsmasq with a wildcard for the apex; names under `localhost` resolve to your machine in browsers without any entry.

Set `TENANCY_APEX_DOMAIN` to that apex (for example `myapp.localhost`) and `APP_URL` to the same host.

## Production checklist

- Never run `php artisan skv:install` there (it runs only in `local`, `testing` and `staging`); `php artisan skv:bootstrap` only prepares `.env`, `APP_KEY` and the generated secrets, also when only the server's environment sets `APP_ENV` (any name but `local`, `testing` and `staging`), and when no source sets one at all, because Laravel then runs as `production`. Demo data never runs outside local and testing.
- Set `APP_URL` to the public origin, `https://` behind a TLS-terminating proxy (see [Configuration](#configuration)).
- Behind a load balancer, CDN or TLS-terminating proxy, set `TRUSTED_PROXIES` to its addresses (or `*`), so the limits and the logs see each visitor's IP and the pages' own links, forms and redirects keep the public scheme, host and port (see [Configuration](#configuration)).
- Keep `.env` at mode 0600: setup creates it so, but an `.env` that already existed keeps its mode (`chmod 600 .env`). If php-fpm runs as another account than the one that ran `skv:bootstrap`, `chown` the file to that account, or use 0640 with a shared group.
- Run `php artisan permissions:sync` on every deploy.
- Set a random `HEALTH_CHECK_KEY` of at least 32 characters (setup and the production `skv:bootstrap` generate a 40-character one; a shorter key counts as none), and point an uptime monitor at `/health-check` with the `X-Health-Check-Key` header; expect 200 (see [Health check](#health-check)). With backups on, run `php artisan backup:run --only-db` once after the first deploy: until the first backup exists, `last-backup` fails and the endpoint answers 503.
- Set a non-empty `BACKUP_ARCHIVE_PASSWORD`: without one `backup:run` refuses to start, because the backups would be unencrypted (`php artisan skv:doctor --fix` writes one; then run `php artisan config:cache` again). Keep a copy off the server, and set `BACKUP_NOTIFY_EMAIL`.
- Back up `storage/app/public/avatars` (the profile photos) next to the database backups, which hold the database only (see [Backups](#backups)).
- On several servers, set `OPS_CACHE_STORE` to a shared store (`redis`), so the heartbeat and the health report are the same on every server.
- Keep `SESSION_DRIVER=database`.
- Run a queue worker (`php artisan queue:work`), because invitation mails are queued. The queue can use Redis: `QUEUE_CONNECTION=redis` with the `REDIS_*` keys and phpredis or predis.
- Add the cron line from [Scheduler](#scheduler): `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`.
- Run the scheduler, and so `backup:run`, as the web server's account, never from root's crontab: as root a backup accepts only a temporary directory inside `storage/`, but the web account that owns `storage/app` could still swap that directory for a symbolic link while root's backup works in it.

## AI

- `.claude/settings.json` denies dangerous commands with Bash deny rules, but a deny rule cannot stop every bypass (for example `--env`, `/usr/bin/php` or `sh -c`), as the Claude Code documentation says (DEC-25).
- Setup registers the Herd MCP server as `herd` in `.mcp.json`, so the deny on `mcp__herd__get_all_sites` applies. It registers it without `SITE_PATH`: with that variable (Herd's own AI integration adds it) the server also offers the tool `get_site_information` and the resource `herd://sites/information`, which return this site's `.env`, and, when `herd.yml` links a Forge site, tools that return the Forge site's environment and deployment output. `.claude/settings.json` denies those tools as well, but no deny rule covers the resource, so leave `SITE_PATH` out.
- Project plugins (superpowers) activate only after you accept Claude Code's workspace-trust dialog (§9).
- `AGENTS.md`, which `CLAUDE.md` imports, is written by setup's Claude step (`php artisan boost:install`); with the Claude answer off, setup writes none. Run `php artisan boost:install` yourself when you add Claude Code later.
- The kit's own skills live in `.ai/skills/<name>/SKILL.md`: `add-tenant-resource`, `add-permission`, `add-settings-page` and `write-tenant-test`. `php artisan boost:update` (and setup's Claude step) publishes them, next to Boost's own skills, to the git-ignored `.claude/skills/`. Each points at the kit rules and the files to copy, and they ship with every app made from the kit.
- `.claude/settings.json` approves the `laravel-boost` and `herd` servers of `.mcp.json` (`enabledMcpjsonServers`), so Claude Code does not ask about them on first use, and allows `composer doctor` and `npm run build:setup` next to the test and build commands.

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
php scripts/traceability.php   # spec §4 bullets no test cites (exits 1 while there are any)
```

The tests never touch your development database: `Tests\TestCase` refuses to run unless `DB_DATABASE` is `:memory:`, a name ending in `_test`, a file under `storage/framework/testing` or the `DB_TEST_DATABASE` that `composer test:pgsql` was given, and it refuses any non-empty `DB_URL` and any configuration cache (run `php artisan config:clear` first), so neither an exported `DB_DATABASE` nor a cached configuration can let `RefreshDatabase` wipe your own database. To run one browser test, call Pest directly, because Composer appends extra arguments to every line of `composer test:browser`: `php artisan config:clear && vendor/bin/pest --testsuite=Browser --filter='<name>'`. With `REQUIRE_POSTGRES=true`, as CI sets it, the tests that need PostgreSQL or a usable `pg_dump` fail instead of skipping when it is missing. Every test file starts with a `covers` docblock line; for the spec's §4 it cites the bullet ids (`§4.5.2`), and `php scripts/traceability.php` (run by `TraceabilityTest`) fails while a §4 bullet has no test that cites it.

The setup pages render before `npm run build` has run, so `layouts/setup` does not use Vite: it inlines `resources/setup/setup.css`, a committed stylesheet built from `resources/css/setup.css` (Tailwind, Flux's CSS and the kit's `resources/css/theme.css`) by `vite.setup.config.js`. Run `npm run build:setup` and commit the result after you change the setup views (`resources/views/layouts/setup.blade.php`, `resources/views/pages/setup`, `resources/views/setup`) or the theme (`resources/css/theme.css`, `resources/css/setup.css`), and after an update of `tailwindcss`, `@tailwindcss/vite`, `vite` or `livewire/flux` (a Dependabot pull request included). CI fails when the committed file is not current.

## License

MIT, see [LICENSE](LICENSE).
