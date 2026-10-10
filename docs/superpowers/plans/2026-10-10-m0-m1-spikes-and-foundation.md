# M0 + M1: Spikes and Foundation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Answer the six design spikes (S1–S6) and build the skv3 foundation: a renamed, cleaned-up official Livewire starter kit with the full quality toolchain, CI and AI configuration, green on SQLite and PostgreSQL.

**Architecture:** M0 runs throwaway experiments outside the repo (scratch dirs and one temporary Herd site) and records each answer as a decision in `docs/decisions.md`. M1 scaffolds the official Livewire starter kit into `~/Herd/skv3`, strips what skv3 does not use (registration, email verification, self-delete, Vite+), converts Livewire pages to multi-file components, and adds Pint/Rector/Larastan/Pest arch tests, composer scripts, git hooks, `ci.yml` and the Claude/Boost setup.

**Tech Stack:** PHP 8.4, Laravel 13, Livewire 4, Flux 2 (free), Fortify, Pest 5, Larastan 3 (PHPStan 2), Rector 2 + rector-laravel, Pint, Laravel Boost 2, Vite 8, Tailwind 4, Laravel Herd 1.30, PostgreSQL 18 (Herd Pro), SQLite.

**Spec:** `docs/superpowers/specs/2026-10-10-skv3-design.md` (read §2, §3, §5.2, §6.1, §7, §8, §9, §11 before starting).

## Global Constraints

- PHP floor `^8.4`; `laravel/framework ^13.35`; Pest `^5.3`; Larastan level **8**, **no baseline**.
- Pint: `laravel` preset + `declare_strict_types`, `strict_comparison`, `ordered_class_elements`. No blanket `final`.
- Livewire components are **multi-file** (MFC) with **no ⚡** in any path; components never call `$this->view()`.
- Full-page components live in `resources/views/pages/**` (`pages::` namespace).
- **Vite+ (`vp`) is not used**: scripts call `vite` directly. No `concurrently`, no Sail, no Chisel, no Telescope.
- `livewire/flux-pro` and `composer.fluxui.dev` must never appear in the committed `composer.json` / `composer.lock`.
- No hard-coded kit/site name (`skv`) in app-identity values (`APP_NAME`, `APP_URL`, DB names, mail from, seed emails).
- CI lives in `.github/workflows/ci.yml`; PHP versions only via `matrix.php` (never a quoted `php-version: 'x.y'` literal). No `tests.yml` unless spike S3 proves the installer needs one.
- Every milestone ends with `composer check` green on SQLite **and** `composer test:pgsql` green on PostgreSQL.
- Code, comments, docs: English. UI copy: `nl` default with `en`.
- Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Never run `herd sites` (prints every site's `.env`). Never modify `~/.composer/config.json`; use a scratch `COMPOSER_HOME` where a custom repository is needed.

## Conventions used in this plan

- `SKV3=~/Herd/skv3` (the repo). `SCRATCH=$(mktemp -d)/skv3-spikes` is created once in Task 1; reuse the same path in later spike tasks (write it into `docs/decisions.md` scratch notes is NOT needed — just keep the shell variable or re-create a fresh dir).
- All M1 work happens on branch `m1-foundation`; M0 decision records are committed on the same branch.
- Herd's Postgres accepts `root` with an empty password on `127.0.0.1:5432` (verified 2026-10-10).

## File map (M1)

| Path | Responsibility |
|---|---|
| `composer.json`, `composer.lock` | identity `frankcornet/skv3`, dependencies, scripts |
| `package.json`, `vite.config.js` | plain Vite 8 build (no `vp`) |
| `.gitignore` | commit AI config (DEC-16), ignore generated agent files |
| `config/fortify.php`, `app/Providers/FortifyServiceProvider.php` | invite-only Fortify (no registration / verification) |
| `config/livewire.php` | MFC, no emoji |
| `resources/views/pages/**` | converted MFC pages |
| `routes/web.php`, `routes/settings.php` | no `verified`, `/` redirect |
| `app/Providers/AppServiceProvider.php` | app defaults + dev command set |
| `pint.json`, `rector.php`, `phpstan.neon` | quality tooling |
| `tests/Pest.php`, `tests/Arch/*`, `tests/Feature/**` | Pest functional tests + arch rules |
| `scripts/create-pgsql-test-database.php` | creates the Postgres test DB for `composer test:pgsql` |
| `.githooks/pre-commit` | fast staged-file checks |
| `.github/workflows/ci.yml`, `.github/dependabot.yml` | CI |
| `CLAUDE.md`, `.ai/guidelines/skv3.md`, `.claude/settings.json`, `boost.json` | AI developer experience |
| `README.md`, `CHANGELOG.md`, `docs/decisions.md` | docs |

---

## M0 — Spikes

Each spike is throwaway. Its only deliverable is a decision entry in `docs/decisions.md`. Do not copy spike code into the repo.

### Task 1: Decision log + spike S5 (passkeys package pairing)

**Files:**
- Create: `docs/decisions.md`

**Interfaces:**
- Produces: `docs/decisions.md` with DEC-01…DEC-18 copied from spec §2 and a `## Spike outcomes` section that later tasks append to (format: `### DEC-NN — <title>` + **Question**, **Answer**, **Evidence**, **Consequence**).

- [ ] **Step 1: Create the branch and the decision log**

```bash
cd ~/Herd/skv3
git switch -c m1-foundation
```

Create `docs/decisions.md`:

```markdown
# Decisions

Owner decisions from the design spec (`docs/superpowers/specs/2026-10-10-skv3-design.md` §2) and decisions made during the build. Newest last.

## Owner decisions

<!-- Copy the DEC-01 … DEC-18 table from spec §2 verbatim here. -->

## Spike outcomes
```

Then replace the HTML comment with the exact DEC-01 … DEC-18 table copied from spec §2 (header row included).

- [ ] **Step 2: Scaffold a scratch copy of the official kit**

```bash
export SCRATCH="$(mktemp -d)/skv3-spikes" && mkdir -p "$SCRATCH" && echo "$SCRATCH"
cd "$SCRATCH"
laravel new official --livewire --pest --database=sqlite --npm --no-boost --no-interaction --no-ansi
```

Expected: JSON line with `"success":true`.

- [ ] **Step 3: Read the pairing the official kit uses**

```bash
cd "$SCRATCH/official"
composer show laravel/passkeys | grep -E '^(name|versions)'
node -e "console.log(require('./node_modules/@laravel/passkeys/package.json').version)"
npm view @laravel/passkeys versions --json | tail -5
composer show laravel/fortify | grep -E '^versions'
```

Record the PHP `laravel/passkeys` version, the installed npm `@laravel/passkeys` version and the npm constraint in `package.json` (`^0.2.0` at time of writing).

- [ ] **Step 4: Record DEC-19**

Append to `docs/decisions.md`:

```markdown
### DEC-19 — Passkeys package pairing (spike S5)

**Question:** Which `laravel/passkeys` (PHP) / `@laravel/passkeys` (npm) pair do we ship?
**Answer:** The official Livewire starter kit's pairing: PHP `laravel/passkeys <version from step 3>` (via `laravel/fortify <version>`), npm `@laravel/passkeys <installed version>` with constraint `<constraint from package.json>`.
**Evidence:** `composer show` / `node_modules` output of a fresh `laravel new --livewire` on 2026-10-10.
**Consequence:** Do not bump `@laravel/passkeys` beyond the official kit's constraint until the official kit does.
```

Fill in the three values from Step 3 (no angle brackets left).

- [ ] **Step 5: Commit**

```bash
cd ~/Herd/skv3
git add docs/decisions.md
git commit -m "docs: add decision log with passkeys pairing (S5)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 2: Spike S4 — Flux free vs Pro component inventory

**Files:**
- Modify: `docs/decisions.md`

- [ ] **Step 1: Install Flux Pro in the scratch kit (uses the owner's global Composer credentials)**

```bash
cd "$SCRATCH/official"
composer config repositories.flux composer https://composer.fluxui.dev
composer require livewire/flux-pro -n
```

Expected: `livewire/flux-pro` installed. If it fails with 401/403, record "S4 blocked: no credentials" in DEC-20 and use the skv2 inventory (`~/Herd/skv2/vendor/livewire/flux-pro/stubs/resources/views/flux`) instead — read-only.

- [ ] **Step 2: List Pro-only components and select variants**

```bash
FREE=vendor/livewire/flux/stubs/resources/views/flux
PRO=vendor/livewire/flux-pro/stubs/resources/views/flux
echo "Top-level Pro-only:"; comm -13 <(ls "$FREE" | sort) <(ls "$PRO" | sort)
echo "Pro files inside shared folders:"; (cd "$PRO" && find . -type f | sort) > /tmp/pro.txt; (cd "$FREE" && find . -type f | sort) > /tmp/free.txt; comm -13 /tmp/free.txt /tmp/pro.txt
composer show livewire/flux livewire/flux-pro | grep -E '^(name|versions)'
```

- [ ] **Step 3: Record DEC-20**

```markdown
### DEC-20 — Pro-only Flux components (spike S4)

**Question:** Which Flux components exist only in `livewire/flux-pro`?
**Answer:** <exact list from step 2: top-level names, plus Pro-only files inside shared folders such as select variants>.
**Evidence:** `comm` of `flux` <version> vs `flux-pro` <version> stub folders, 2026-10-10.
**Consequence:** These names may only be used inside `@if (Skv::hasFluxPro())` branches (spec §6.7). The lint test in M5 uses the free stub folder as its allow-list, so this list is informational.
```

Also: the appearance question from S4 is already answered in spec §6.5 (`localStorage['flux.appearance']`); write one line under DEC-20: "Appearance persistence: confirmed `flux.appearance` key in `vendor/livewire/flux/src/AssetManager.php`" after running `grep -n "flux.appearance" vendor/livewire/flux/src/AssetManager.php`.

- [ ] **Step 4: Commit**

```bash
cd ~/Herd/skv3 && git add docs/decisions.md
git commit -m "docs: record Flux Pro component inventory (S4)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 3: Spike S2 — Spatie global template role per team (PostgreSQL)

**Files:**
- Modify: `docs/decisions.md`

- [ ] **Step 1: Prepare a scratch app on Postgres**

```bash
cd "$SCRATCH" && laravel new s2 --pest --database=sqlite --no-node --no-boost --no-interaction --no-ansi
cd s2
php -r "(new PDO('pgsql:host=127.0.0.1;port=5432;dbname=postgres','root',''))->exec('CREATE DATABASE skv3_spike_s2');"
sed -i '' -e 's/^DB_CONNECTION=.*/DB_CONNECTION=pgsql/' -e 's/^# DB_HOST=.*/DB_HOST=127.0.0.1/' -e 's/^# DB_PORT=.*/DB_PORT=5432/' -e 's/^# DB_DATABASE=.*/DB_DATABASE=skv3_spike_s2/' -e 's/^# DB_USERNAME=.*/DB_USERNAME=root/' -e 's/^# DB_PASSWORD=.*/DB_PASSWORD=/' .env
composer require spatie/laravel-permission -n
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" -n
sed -i '' "s/'teams' => false/'teams' => true/" config/permission.php
```

- [ ] **Step 2: Apply skv2's D28 primary keys**

Open the published `database/migrations/*_create_permission_tables.php`. In the `model_has_permissions` and `model_has_roles` table definitions, replace the `if ($teams) { … $table->primary([...team key..., ...]) } else { … }` primary-key blocks so both tables use the non-team key and keep `team_id` nullable:

```php
$table->unsignedBigInteger($columnNames['team_foreign_key'])->nullable();
$table->index($columnNames['team_foreign_key'], 'model_has_roles_team_foreign_key_index');
$table->primary([$pivotRole, $columnNames['model_morph_key'], 'model_type'],
    'model_has_roles_role_model_type_primary');
```

(and the equivalent for `model_has_permissions` with `$pivotPermission` and index/primary names `model_has_permissions_*`). Also add `use Spatie\Permission\Traits\HasRoles;` + `use HasRoles;` to `app/Models/User.php`. Then `php artisan migrate`.

Expected: migrations run on Postgres without errors.

- [ ] **Step 3: Write the spike test**

Create `tests/Feature/TemplateRoleTest.php`:

```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function team(?int $id): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($id);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

it('assigns one global template role per team', function () {
    team(null);
    Permission::create(['name' => 'users.view']);
    $template = Role::create(['name' => 'organisation_admin', 'team_id' => null]);
    $template->givePermissionTo('users.view');

    $alice = User::factory()->create();
    $bob = User::factory()->create();

    team(1); $alice->assignRole('organisation_admin');
    team(2); $bob->assignRole('organisation_admin');

    team(1);
    expect($alice->fresh()->hasRole('organisation_admin'))->toBeTrue()
        ->and($alice->fresh()->can('users.view'))->toBeTrue()
        ->and($bob->fresh()->hasRole('organisation_admin'))->toBeFalse();

    team(2);
    expect($bob->fresh()->hasRole('organisation_admin'))->toBeTrue()
        ->and($alice->fresh()->hasRole('organisation_admin'))->toBeFalse();

    expect(Role::where('name', 'organisation_admin')->count())->toBe(1)
        ->and(DB::table('model_has_roles')->pluck('team_id')->sort()->values()->all())->toBe([1, 2]);
});

it('lets a global role be attached with a null team (super_admin badge)', function () {
    team(null);
    Role::create(['name' => 'super_admin', 'team_id' => null]);
    $root = User::factory()->create();
    $root->assignRole('super_admin');

    expect(DB::table('model_has_roles')->whereNull('team_id')->count())->toBe(1);
});

it('propagates template permission changes to every team', function () {
    team(null);
    Permission::create(['name' => 'users.view']);
    Permission::create(['name' => 'roles.view']);
    $template = Role::create(['name' => 'organisation_admin', 'team_id' => null]);
    $alice = User::factory()->create();
    team(7); $alice->assignRole('organisation_admin');

    $template->givePermissionTo('roles.view');
    team(7);
    expect($alice->fresh()->can('roles.view'))->toBeTrue();
});
```

In `tests/Pest.php` make sure Feature tests use `RefreshDatabase` (it does by default in the official kit).

- [ ] **Step 4: Run it**

```bash
php artisan config:clear && ./vendor/bin/pest tests/Feature/TemplateRoleTest.php
```

Record pass/fail per test and any exception text.

- [ ] **Step 5: Record DEC-21 and clean up**

```markdown
### DEC-21 — Organisation admin role model (spike S2)

**Question:** Can `organisation_admin` be one global template role (`team_id = NULL`) assigned per team, with pivot primary keys that exclude `team_id` (skv2 D28), on PostgreSQL?
**Answer:** <Option A (template) or Option B (one row per organisation)> — <one sentence why, citing the test results>.
**Evidence:** `TemplateRoleTest` on PostgreSQL 18, spatie/laravel-permission <version>: <pass/fail per test>.
**Consequence:** Spec §6.3 option <A|B> applies to `UpdateUserRoles`, `permissions:sync`, the doctor check and the roles index (M2).
```

Choose **A** only if all three tests pass; otherwise **B**.

```bash
php -r "(new PDO('pgsql:host=127.0.0.1;port=5432;dbname=postgres','root',''))->exec('DROP DATABASE IF EXISTS skv3_spike_s2');"
cd ~/Herd/skv3 && git add docs/decisions.md
git commit -m "docs: record organisation admin role model decision (S2)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 4: Spike S3 — what `laravel new --using` does to a Pest kit

**Files:**
- Modify: `docs/decisions.md`

- [ ] **Step 1: Turn the scratch official kit into a fake community kit**

```bash
cd "$SCRATCH" && rm -rf probe-kit && cp -R official probe-kit && cd probe-kit
if grep -q flux-pro composer.json; then composer remove livewire/flux-pro -n && composer config --unset repositories.flux; fi
rm -rf vendor node_modules .env database/database.sqlite public/build
composer config name frankcornet/skv3-probe
mkdir -p .github/workflows && git rm -q --cached .github/workflows/tests.yml 2>/dev/null; rm -f .github/workflows/tests.yml
cat > .github/workflows/ci.yml <<'YAML'
name: ci
on: [push]
jobs:
  main:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['8.4', '8.5']
    steps:
      - uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962 # 2.40.0
        with:
          php-version: ${{ matrix.php }}
YAML
php -r '$c=json_decode(file_get_contents("composer.json"),true);$c["scripts"]["post-create-project-cmd"][]="@php -r \"file_put_contents(\x27hook-post-create-project-cmd.txt\x27, getenv(\x27LARAVEL_INSTALLER_DEFER_HOOKS\x27) ?: \x27unset\x27);\"";$c["extra"]["laravel"]["installer"]["post-create-project"]=["@php -r \"file_put_contents(\x27hook-installer.txt\x27, getenv(\x27LARAVEL_INSTALLER_DEFER_HOOKS\x27) ?: \x27unset\x27);\""];file_put_contents("composer.json",json_encode($c,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");'
git init -q -b main && git add -A && git commit -qm probe
```

- [ ] **Step 2: Point a scratch Composer home at it**

```bash
export S3HOME="$SCRATCH/composer-home" && mkdir -p "$S3HOME"
export COMPOSER_CACHE_DIR="$(composer config --global cache-dir)"
COMPOSER_HOME="$S3HOME" composer config -g repositories.probe vcs "$SCRATCH/probe-kit"
```

- [ ] **Step 3: Run Herd's exact command (Pest + Boost + git defaults)**

```bash
cd "$SCRATCH" && rm -rf herd-like
COMPOSER_HOME="$S3HOME" laravel new herd-like --using=frankcornet/skv3-probe:dev-main --no-interaction --no-ansi --database=sqlite --npm --pest --git --boost > herd-like.log 2>&1; echo "exit $?"
```

- [ ] **Step 4: Collect the evidence**

```bash
cd "$SCRATCH"
cat herd-like/hook-*.txt; echo
diff -ru --exclude=vendor --exclude=node_modules --exclude=.git --exclude=.env --exclude=database.sqlite --exclude=composer.lock --exclude=package-lock.json --exclude=build --exclude=hook-*.txt probe-kit herd-like > herd-like.diff; wc -l herd-like.diff
grep -n "php-version" herd-like/.github/workflows/ci.yml
ls herd-like/.github/workflows/
grep -c "boost:update" herd-like/composer.json
(cd herd-like && git log --oneline | head -3)
tail -20 herd-like.log
```

- [ ] **Step 5: Repeat with the PHPUnit / no-Boost variant and without `--database`**

```bash
COMPOSER_HOME="$S3HOME" laravel new variant-phpunit --using=frankcornet/skv3-probe:dev-main --no-interaction --no-ansi --database=sqlite --npm --phpunit --no-boost > variant-phpunit.log 2>&1; echo "exit $?"
COMPOSER_HOME="$S3HOME" laravel new variant-nodb --using=frankcornet/skv3-probe:dev-main --no-interaction --no-ansi --no-node --no-boost > variant-nodb.log 2>&1; echo "exit $?"
ls variant-nodb/database/database.sqlite 2>&1; cat variant-nodb/hook-*.txt
```

- [ ] **Step 6: Record DEC-22**

```markdown
### DEC-22 — Installer behaviour on a Pest kit without tests.yml (spike S3)

**Question:** What does `laravel new --using` (Herd's flags) change in a kit that already ships Pest, has no `tests.yml`, and keeps CI in `ci.yml` with `matrix.php`?
**Answer:** <exit codes of the three runs; which hook files exist and their LARAVEL_INSTALLER_DEFER_HOOKS value; whether the absence of tests.yml caused an error; summary of herd-like.diff (files changed and why); ci.yml php-version lines unchanged yes/no; number of boost:update entries; composer.json constraint changes; whether variant-nodb has database.sqlite>.
**Evidence:** `$SCRATCH/herd-like.diff` (summarised), installer <version>, 2026-10-10.
**Consequence:** <list each kit rule confirmed or changed: e.g. "ship no tests.yml" / "ship a stub tests.yml"; "skv:bootstrap must create database.sqlite when --database is absent"; Pest-file edits that must be made no-ops in M1 Task 13>.
```

- [ ] **Step 7: Commit**

```bash
cd ~/Herd/skv3 && git add docs/decisions.md
git commit -m "docs: record installer behaviour for --using kits (S3)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 5: Spike S1 + S6 — shell tools, detached processes and `herd share` under Herd's php-fpm

This task creates a temporary site `~/Herd/skv3-spike` (served as `skv3-spike.test`). Running `herd secure` restarts Herd's nginx for a few seconds; other local sites blip during that time. The site is deleted at the end.

**Files:**
- Modify: `docs/decisions.md`

- [ ] **Step 1: Create the probe site**

```bash
mkdir -p ~/Herd/skv3-spike/public
cat > ~/Herd/skv3-spike/public/index.php <<'PHP'
<?php
header('Content-Type: application/json');
$home = getenv('HOME') ?: '/Users/'.get_current_user();
$find = fn (string $bin): string => trim((string) shell_exec('command -v '.escapeshellarg($bin).' 2>/dev/null'));
$herd = $find('herd') ?: $home.'/Library/Application Support/Herd/bin/herd';
$out = [
    'user' => get_current_user(),
    'path' => getenv('PATH'),
    'home' => getenv('HOME'),
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
    'https' => $_SERVER['HTTPS'] ?? null,
    'headers' => array_filter($_SERVER, fn ($k) => str_starts_with((string) $k, 'HTTP_'), ARRAY_FILTER_USE_KEY),
    'bins' => ['herd' => $find('herd'), 'npm' => $find('npm'), 'node' => $find('node'), 'git' => $find('git'), 'claude' => $find('claude'), 'herd_fallback_exists' => is_executable($herd)],
    'max_execution_time' => ini_get('max_execution_time'),
];
if (isset($_GET['run'])) {
    exec(escapeshellarg($herd).' --version 2>&1', $o, $code);
    $out['herd_version'] = $o; $out['herd_exit'] = $code;
    exec('npm --version 2>&1', $n, $ncode); $out['npm'] = [$n, $ncode];
}
if (isset($_GET['spawn'])) {
    $log = dirname(__DIR__).'/spawn.log';
    exec('nohup sh -c '.escapeshellarg('sleep 15; date >> '.$log).' > /dev/null 2>&1 &');
    $out['spawned'] = true;
}
if (isset($_GET['secure'])) {
    $dir = dirname(__DIR__);
    exec('nohup sh -c '.escapeshellarg(escapeshellarg($herd).' secure skv3-spike > '.$dir.'/secure.log 2>&1; echo $? > '.$dir.'/secure.exit').' > /dev/null 2>&1 &');
    $out['secure_started'] = true;
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
PHP
curl -s http://skv3-spike.test/ | head -40
```

Expected: JSON with `remote_addr` (record it), `path` and the `bins` map.

- [ ] **Step 2: Can php-fpm run the tools?**

```bash
curl -s "http://skv3-spike.test/?run=1" | python3 -m json.tool | grep -A3 -E 'herd_version|herd_exit|"npm"'
```

Record whether `herd --version` and `npm --version` ran (exit 0) and which PATH php-fpm has.

- [ ] **Step 3: Does a detached process outlive the request?**

```bash
rm -f ~/Herd/skv3-spike/spawn.log
curl -s "http://skv3-spike.test/?spawn=1" > /dev/null; sleep 20; cat ~/Herd/skv3-spike/spawn.log
```

Expected (if yes): one timestamp line.

- [ ] **Step 4: Does a detached `herd secure` survive the nginx restart it causes?**

```bash
rm -f ~/Herd/skv3-spike/secure.*
curl -s "http://skv3-spike.test/?secure=1" > /dev/null
for i in $(seq 1 30); do sleep 2; curl -sk -o /dev/null -w "%{http_code}\n" https://skv3-spike.test/ && break; done
cat ~/Herd/skv3-spike/secure.exit ~/Herd/skv3-spike/secure.log
curl -s -o /dev/null -w "http -> %{http_code} %{redirect_url}\n" -X POST http://skv3-spike.test/
```

Record: exit code, how long until https answered, and the http POST response (expected `301` to https — confirms the wizard must navigate, not POST, after securing).

- [ ] **Step 5: S6 — headers added by `herd share`**

```bash
cd ~/Herd/skv3-spike && (herd share > share.log 2>&1 &) ; sleep 15; head -20 share.log
```

If the log shows a public URL, run `curl -s <url> | python3 -m json.tool | grep -A30 '"headers"'` and record every `HTTP_X_*` header plus `remote_addr`. If it asks for a token/login or fails, record "herd share unavailable on this machine (needs Expose token)". Then stop it: `pkill -f "expose share" || true`.

- [ ] **Step 6: Clean up**

```bash
herd unsecure skv3-spike
rm -rf ~/Herd/skv3-spike
```

- [ ] **Step 7: Record DEC-23 and DEC-24**

```markdown
### DEC-23 — Shell tools from the browser wizard (spike S1)

**Question:** Can a php-fpm request under Herd run `herd`, `npm`, `git`, `claude`, and does a detached process outlive the request and the restart caused by `herd secure`?
**Answer:** <per tool: runs yes/no (exit code, PATH seen by php-fpm)>; detached process survives request: <yes/no>; detached `herd secure` completes: <yes/no, exit code, seconds until https answered>; http POST after securing: <status + redirect>.
**Evidence:** probe site `skv3-spike.test`, Herd 1.30.2, 2026-10-10.
**Consequence:** <which setup steps run in-process / detached / as `StepResult::manual()` in the browser; the absolute herd path to use if `command -v herd` is empty>.

### DEC-24 — Headers added by `herd share` (spike S6)

**Question:** Which request headers mark traffic tunnelled through `herd share`?
**Answer:** <list of HTTP_X_* headers and REMOTE_ADDR, or "not testable: <reason>">.
**Consequence:** The wizard rejects requests carrying <those headers / the spec's default list>; the one-time token remains the primary gate.
```

- [ ] **Step 8: Commit**

```bash
cd ~/Herd/skv3 && git add docs/decisions.md
git commit -m "docs: record wizard shell and herd share findings (S1, S6)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## M1 — Foundation

### Task 6: Scaffold the official kit into skv3 and set its identity

**Files:**
- Create: everything produced by the official Livewire starter kit (except `.git`, `vendor`, `node_modules`, `.env`, `database/database.sqlite`)
- Modify: `composer.json`, `package.json`, `vite.config.js`, `.gitignore`, `.env.example`
- Delete: `.github/workflows/tests.yml` (unless DEC-22 says to keep a stub)
- Test: `tests/Feature/AppDefaultsTest.php`

**Interfaces:**
- Produces: a booting Laravel app named `frankcornet/skv3`; `tests/Pest.php` binding `Tests\TestCase` + `RefreshDatabase` to `Feature`; Pest functional-style tests.

- [ ] **Step 1: Scaffold fresh into a scratch dir and copy in**

```bash
BASE="$(mktemp -d)" && cd "$BASE"
laravel new base --livewire --pest --database=sqlite --no-node --no-boost --no-interaction --no-ansi
rsync -a --exclude .git --exclude vendor --exclude node_modules --exclude .env --exclude database/database.sqlite "$BASE/base/" ~/Herd/skv3/
cd ~/Herd/skv3 && git status --short | head -50
```

Expected: the kit's files appear; `docs/` is untouched.

- [ ] **Step 2: Convert the class-style tests to Pest functions while drift is still installed**

```bash
composer install -n
./vendor/bin/pest --drift
find tests -name '*Test.php' -exec grep -L -E '^(it|test)\(' {} +
```

Expected: the last command prints nothing (every test file uses `it(`/`test(`). If any file is listed, convert it by hand to the same style.

- [ ] **Step 3: Set identity and dependency floors**

```bash
composer config name frankcornet/skv3
composer config description "An opinionated, multi-tenant Laravel starter kit: organisations by subdomain, invitations, roles and permissions, impersonation, audit logging and a Flux UI, set up from Herd in minutes."
composer require "php:^8.4" "laravel/framework:^13.35" --no-update -n
composer remove laravel/chisel laravel/sail pestphp/pest-plugin-drift --no-update -n
composer update -W -n
```

Expected: update succeeds; `composer validate --strict` prints `./composer.json is valid`.

- [ ] **Step 4: Replace Vite+ with plain Vite**

```bash
npm uninstall vite-plus concurrently
npm pkg set scripts.build="vite build" scripts.dev="vite"
```

Replace `vite.config.js` with:

```js
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/passkeys.js',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        cors: true,
        watch: {
            ignored: [
                '**/.claude/**',
                '**/storage/framework/views/**',
                '**/vendor/**',
            ],
        },
    },
});
```

```bash
npm install && npm run build
```

Expected: `public/build/manifest.json` exists.

- [ ] **Step 5: Commit AI config, ignore generated agent files (DEC-16)**

In `.gitignore`, delete these lines: `/.claude`, `/CLAUDE.md`, `/boost.json`, `/.agents`, `/.amp`, `/.cursor`, `/.factory`, `/.grok`, `/.junie`, `/.kiro`, `/.pi`, `/opencode.json`, `/opencode.jsonc`. Keep `/.mcp.json` and `/AGENTS.md`. Append:

```gitignore
/.claude/settings.local.json
/.superpowers/sdd/
```

- [ ] **Step 6: Neutral `.env.example` defaults**

In `.env.example` change only these lines (keep every other line exactly as the kit ships it, spec §5.2):

```dotenv
APP_URL=http://localhost
APP_LOCALE=nl
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=nl_NL
```

and add after `SESSION_DOMAIN=null`:

```dotenv
SESSION_COOKIE=app_session
```

- [ ] **Step 7: Write the app-defaults test (spec §4.0)**

Create `tests/Feature/AppDefaultsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('uses immutable dates', function () {
    expect(now())->toBeInstanceOf(CarbonImmutable::class);
});

it('prohibits destructive database commands in production', function () {
    app()->detectEnvironment(fn () => 'production');

    try {
        (new AppServiceProvider(app()))->boot();

        $this->artisan('db:wipe', ['--force' => true])->assertFailed();
        $this->artisan('migrate:fresh', ['--force' => true])->assertFailed();
    } finally {
        // The prohibition is a process-wide static flag: reset it, or later tests
        // (RefreshDatabase runs migrate:fresh on PostgreSQL) would be refused too.
        app()->detectEnvironment(fn () => 'testing');
        DB::prohibitDestructiveCommands(false);
    }
});

it('allows destructive database commands outside production', function () {
    expect(app()->isProduction())->toBeFalse();
});
```

- [ ] **Step 8: Boot and run the suite**

```bash
cp .env.example .env && php artisan key:generate -n && touch database/database.sqlite && php artisan migrate -n
php artisan test --compact
```

Expected: all tests pass (the kit's own tests + `AppDefaultsTest`).

- [ ] **Step 9: Handle the kit's `tests.yml`**

If DEC-22 says "ship no tests.yml": `git rm -q --cached .github/workflows/tests.yml 2>/dev/null; rm -f .github/workflows/tests.yml`. If DEC-22 says a stub is required, replace its contents with the stub DEC-22 specifies. (`ci.yml` is added in Task 13.)

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "chore: scaffold skv3 from the official Livewire starter kit

Rename to frankcornet/skv3, raise floors to PHP 8.4 / Laravel 13.35,
drop Chisel, Sail and Vite+, convert tests to Pest functions, commit
AI configuration (DEC-16) and add app-default tests (spec 4.0).

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 7: Invite-only auth surface (no registration, verification or self-delete)

**Files:**
- Modify: `config/fortify.php`, `app/Providers/FortifyServiceProvider.php`, `routes/web.php`, `routes/settings.php`, `app/Models/User.php`, `resources/views/pages/settings/⚡profile.blade.php`
- Delete: `app/Actions/Fortify/CreateNewUser.php`, `resources/views/pages/auth/register.blade.php`, `resources/views/pages/auth/verify-email.blade.php`, `resources/views/pages/settings/⚡delete-user-form.blade.php`, `resources/views/pages/settings/⚡delete-user-modal.blade.php`, `resources/views/welcome.blade.php`, `tests/Feature/Auth/RegistrationTest.php`, `tests/Feature/Auth/EmailVerificationTest.php`
- Test: `tests/Feature/Auth/InviteOnlyTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Auth/InviteOnlyTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

it('has no registration', function () {
    expect(Route::has('register'))->toBeFalse();
    $this->get('/register')->assertNotFound();
});

it('has no email verification', function () {
    expect(Route::has('verification.notice'))->toBeFalse()
        ->and(Route::has('verification.verify'))->toBeFalse();
});

it('offers no self-service account deletion', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee('delete-user', escape: false);

    $deleteViews = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($file) => str_contains($file->getPathname(), 'delete-user'));

    expect($deleteViews)->toBeEmpty();
});

it('redirects the root url to the dashboard', function () {
    $this->get('/')->assertRedirect('/dashboard');
});

it('sends guests from the dashboard to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});
```

- [ ] **Step 2: Run to see them fail**

Run: `php artisan test --compact --filter=InviteOnly`
Expected: FAIL (register route exists, verification routes exist, `/` returns 200).

- [ ] **Step 3: Implement**

`config/fortify.php` — the `features` array becomes:

```php
'features' => [
    Features::resetPasswords(),
    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]),
    Features::passkeys([
        'confirmPassword' => true,
    ]),
],
```

`app/Providers/FortifyServiceProvider.php`: remove the `CreateNewUser` import and the `Fortify::createUsersUsing(...)`, `Fortify::verifyEmailView(...)` and `Fortify::registerView(...)` lines.

`routes/web.php`:

```php
<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
```

`routes/settings.php`:

```php
<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');

    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware(['password.confirm'])
        ->name('security.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
```

`app/Models/User.php`: remove `implements MustVerifyEmail` and its import if present.

In `resources/views/pages/settings/⚡profile.blade.php`: delete the `<livewire:pages::settings.delete-user-form />` line, the `resendVerificationNotification()` method, the `hasUnverifiedEmail` computed property and the Blade block that uses it, and the `MustVerifyEmail` import.

```bash
git rm -q app/Actions/Fortify/CreateNewUser.php resources/views/pages/auth/register.blade.php resources/views/pages/auth/verify-email.blade.php "resources/views/pages/settings/⚡delete-user-form.blade.php" "resources/views/pages/settings/⚡delete-user-modal.blade.php" resources/views/welcome.blade.php tests/Feature/Auth/RegistrationTest.php tests/Feature/Auth/EmailVerificationTest.php
grep -rn "register\b\|verification\.\|delete-user\|welcome" resources/views app routes | grep -v "Route::has('register')" || true
```

Fix any remaining reference the grep prints (e.g. a "Sign up" link not guarded by `Route::has('register')`).

- [ ] **Step 4: Run the suite**

Run: `php artisan view:clear -q && php artisan test --compact`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: make authentication invite-only

Remove registration, email verification and self-service account
deletion (skv2 D6, D33); the root URL redirects to the dashboard.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 8: Livewire multi-file components without emoji

**Files:**
- Create: `config/livewire.php` (published), `tests/Arch/LivewireConventionsTest.php`
- Modify: `phpunit.xml` (Arch suite), every `resources/views/pages/**/⚡*.blade.php` (converted + renamed)

**Interfaces:**
- Produces: MFC pages at `resources/views/pages/settings/{appearance,profile,security,two-factor-setup-modal}/` and `resources/views/pages/settings/two-factor/recovery-codes/`, each with `<name>.php` + `<name>.blade.php`. Component names (`pages::settings.profile`, …) are unchanged.

- [ ] **Step 1: Write the failing arch tests**

Create `tests/Arch/LivewireConventionsTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/** @return \Illuminate\Support\Collection<int, SplFileInfo> */
function viewFiles(): \Illuminate\Support\Collection
{
    return collect(File::allFiles(resource_path('views')));
}

it('has no emoji in any view path', function () {
    expect(viewFiles()->filter(fn (SplFileInfo $f) => str_contains($f->getRelativePathname(), '⚡')))->toBeEmpty();
});

it('keeps component PHP out of Blade files (multi-file components only)', function () {
    $singleFile = viewFiles()
        ->filter(fn (SplFileInfo $f) => str_ends_with($f->getFilename(), '.blade.php'))
        ->filter(fn (SplFileInfo $f) => preg_match('/new\s+(#\[[^\]]*\]\s*)*class\s+extends\s+Component/', $f->getContents()) === 1)
        ->map(fn (SplFileInfo $f) => $f->getRelativePathname());

    expect($singleFile->values()->all())->toBe([]);
});

it('never calls $this->view() in component classes', function () {
    $offenders = viewFiles()
        ->filter(fn (SplFileInfo $f) => str_ends_with($f->getFilename(), '.php') && ! str_ends_with($f->getFilename(), '.blade.php'))
        ->filter(fn (SplFileInfo $f) => str_contains($f->getContents(), '$this->view('))
        ->map(fn (SplFileInfo $f) => $f->getRelativePathname());

    expect($offenders->values()->all())->toBe([]);
});

it('generates multi-file components without emoji', function () {
    expect(config('livewire.make_command.type'))->toBe('mfc')
        ->and(config('livewire.make_command.emoji'))->toBeFalse();
});
```

In `phpunit.xml`, add inside `<testsuites>`:

```xml
<testsuite name="Arch">
    <directory>tests/Arch</directory>
</testsuite>
```

In `tests/Pest.php`, below the existing `pest()->extend(...)->in('Feature');`, add:

```php
pest()->extend(TestCase::class)->in('Arch');
```

- [ ] **Step 2: Run to see them fail**

Run: `php artisan test --compact tests/Arch`
Expected: FAIL (emoji paths, single-file components, config defaults).

- [ ] **Step 3: Publish config and set MFC defaults**

```bash
php artisan livewire:config -n
```

In `config/livewire.php` set:

```php
'make_command' => [
    'type' => 'mfc',
    'emoji' => false,
    'with' => [
        'js' => false,
        'css' => false,
        'test' => false,
    ],
],
```

- [ ] **Step 4: Convert and rename every page component**

```bash
for c in settings.appearance settings.profile settings.security settings.two-factor-setup-modal settings.two-factor.recovery-codes; do
  php artisan livewire:convert "pages::$c" --mfc --emoji=false -n
done
find resources/views -name '⚡*' -print0 | sort -rz | while IFS= read -r -d '' p; do mv "$p" "$(dirname "$p")/$(basename "$p" | sed 's/^⚡//')"; done
find resources/views/pages -type f | sort
```

Expected listing contains e.g. `resources/views/pages/settings/profile/profile.php` and `profile.blade.php`, and no `⚡`.

- [ ] **Step 5: Run the full suite**

Run: `php artisan view:clear -q && php artisan test --compact`
Expected: PASS (arch tests + all feature tests; component names resolve without emoji).

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "refactor: convert Livewire pages to multi-file components without emoji

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 9: Pint and Rector

**Files:**
- Modify: `pint.json`
- Create: `rector.php`
- Modify: every PHP file touched by the formatters

- [ ] **Step 1: Install Rector**

```bash
composer require --dev rector/rector driftingly/rector-laravel -n
```

- [ ] **Step 2: Configure Pint**

`pint.json`:

```json
{
    "preset": "laravel",
    "rules": {
        "declare_strict_types": true,
        "strict_comparison": true,
        "ordered_class_elements": true
    },
    "exclude": [
        "bootstrap/cache",
        "storage"
    ]
}
```

- [ ] **Step 3: Configure Rector**

`rector.php`:

```php
<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap/app.php',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
        __DIR__.'/resources/views',
    ])
    ->withSkip([
        '*.blade.php',
        __DIR__.'/bootstrap/cache',
    ])
    ->withPhpSets(php84: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )
    ->withComposerBased(laravel: true)
    ->withCache(__DIR__.'/storage/framework/cache/rector');
```

- [ ] **Step 4: Apply Rector, then Pint**

```bash
vendor/bin/rector process --no-progress-bar
vendor/bin/pint --parallel
git diff --stat | tail -3
```

- [ ] **Step 5: Verify behaviour is unchanged**

Run: `php artisan view:clear -q && php artisan test --compact`
Expected: PASS. If a Rector rule broke a test, add that rule class to `withSkip([...])` with a one-line comment naming the failing test, re-run Step 4–5.

- [ ] **Step 6: Verify the tools are idempotent**

```bash
vendor/bin/rector process --dry-run --no-progress-bar && vendor/bin/pint --test --parallel
```

Expected: both exit 0.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "chore: add strict Pint rules and Rector

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 10: Larastan level 8 including component PHP

**Files:**
- Modify: `phpstan.neon`
- Modify: any file PHPStan reports

- [ ] **Step 1: Install the Pest PHPStan extension**

```bash
composer require --dev pestphp/pest-plugin-phpstan -n
```

- [ ] **Step 2: Configure**

`phpstan.neon`:

```neon
includes:
    - vendor/larastan/larastan/extension.neon
    - vendor/nesbot/carbon/extension.neon
    - vendor/pestphp/pest-plugin-phpstan/extension.neon

parameters:
    level: 8
    tmpDir: storage/framework/cache/phpstan
    paths:
        - app/
        - bootstrap/app.php
        - config/
        - database/
        - routes/
        - tests/
        - resources/views/
    excludePaths:
        - resources/views/*.blade.php
    ignoreErrors:
        # Livewire multi-file components are a bare `new class extends Component {}` expression
        # that Livewire's compiler evaluates; PHPStan sees an unused expression. Spec §7.1.
        -
            identifier: expr.resultUnused
            path: resources/views/*
```

- [ ] **Step 3: Run and fix**

```bash
mkdir -p storage/framework/cache/phpstan
vendor/bin/phpstan analyse --memory-limit=1G --no-progress
```

Fix every reported error in code (typed properties, return types, null checks, `@var` on `Auth::user()` usage via `/** @var \App\Models\User $user */` where the route is behind `auth`). Do not add further `ignoreErrors` entries and do not create a baseline. Re-run until `[OK] No errors`.

- [ ] **Step 4: Run the suite**

Run: `php artisan test --compact && vendor/bin/pint --test --parallel`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "chore: run Larastan at level 8 over app, tests and component PHP

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 11: Pest arch presets and kit arch rules

**Files:**
- Create: `tests/Arch/PresetsTest.php`, `tests/Arch/KitRulesTest.php`

- [ ] **Step 1: Write the arch tests**

`tests/Arch/PresetsTest.php`:

```php
<?php

declare(strict_types=1);

arch()->preset()->php();

arch()->preset()->security();

arch()->preset()->laravel();
```

`tests/Arch/KitRulesTest.php`:

```php
<?php

declare(strict_types=1);

arch('app code reads configuration, never the environment')
    ->expect(['App', 'Database'])
    ->not->toUse(['env']);

arch('no debugging leftovers')
    ->expect(['App', 'Database', 'Tests'])
    ->not->toUse(['dd', 'dump', 'ray', 'var_dump']);

arch('strict types everywhere in app code')
    ->expect('App')
    ->toUseStrictTypes();
```

- [ ] **Step 2: Run**

Run: `php artisan test --compact tests/Arch`
Expected: either PASS, or a list of violations.

- [ ] **Step 3: Resolve each violation**

For each violation: fix the code if it is a real problem. If the rule conflicts with a framework extension point (for example the `laravel()` preset's controller-suffix rule hitting `App\Livewire\Actions\Logout`), add a targeted `->ignoring(<exact class or namespace>)` to that preset line with a `// why:` comment on the line above. Example:

```php
// why: Livewire action classes are invokable helpers, not HTTP controllers.
arch()->preset()->laravel()->ignoring('App\Livewire\Actions');
```

Re-run Step 2 until PASS.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "test: add Pest arch presets and kit rules

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 12: Composer scripts, PostgreSQL test run, dev commands and git hooks

**Files:**
- Modify: `composer.json` (scripts), `app/Providers/AppServiceProvider.php`
- Create: `scripts/create-pgsql-test-database.php`, `.githooks/pre-commit`
- Test: `tests/Feature/Tooling/DevCommandsTest.php`, `tests/Feature/Tooling/GitHooksTest.php`

**Interfaces:**
- Produces: `composer setup|dev|fix|check|test:pgsql|test:browser`; `.githooks/pre-commit`; the dev command set (`vite`, `queue`, `logs`, `schedule`, no `server` when Herd is present).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Tooling/DevCommandsTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\DevCommands;

it('runs the scheduler and never artisan serve under Herd', function () {
    $names = array_keys(DevCommands::commands());

    expect($names)->toContain('schedule')
        ->and($names)->not->toContain('server');
})->skip(fn () => ! is_dir((getenv('HOME') ?: '').'/Library/Application Support/Herd'), 'Herd not installed');
```

`tests/Feature/Tooling/GitHooksTest.php`:

```php
<?php

declare(strict_types=1);

it('ships an executable pre-commit hook that checks staged PHP files', function () {
    $hook = base_path('.githooks/pre-commit');

    expect(is_file($hook))->toBeTrue()
        ->and(is_executable($hook))->toBeTrue()
        ->and(file_get_contents($hook))->toContain('vendor/bin/pint --test')
        ->and(file_get_contents($hook))->toContain('vendor/bin/phpstan analyse');
});
```

Run: `php artisan test --compact tests/Feature/Tooling`
Expected: FAIL (`DevCommands::commands()` keys don't match; hook missing). If `DevCommands::commands()` returns entries keyed differently, read `vendor/laravel/framework/src/Illuminate/Foundation/DevCommands.php` (`commands()` method) and adapt the test's key extraction to its documented return shape before continuing.

- [ ] **Step 2: Dev command set**

In `app/Providers/AppServiceProvider.php` add to `boot()` (after `configureDefaults()`):

```php
$this->configureDevCommands();
```

and the method:

```php
/**
 * Configure `php artisan dev`: Herd already serves the site, and the scheduler must run locally.
 */
protected function configureDevCommands(): void
{
    DevCommands::artisan('schedule:work', 'schedule');

    if (is_dir((getenv('HOME') ?: '').'/Library/Application Support/Herd')) {
        DevCommands::except('server');
    }
}
```

with `use Illuminate\Foundation\DevCommands;`.

- [ ] **Step 3: PostgreSQL test database helper**

`scripts/create-pgsql-test-database.php`:

```php
<?php

declare(strict_types=1);

/*
 * Creates the PostgreSQL test database used by `composer test:pgsql`.
 * Name: $DB_TEST_DATABASE, or "<project folder>_test".
 */

$database = getenv('DB_TEST_DATABASE') ?: basename(dirname(__DIR__)).'_test';
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '5432';
$username = getenv('DB_USERNAME') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';

$pdo = new PDO("pgsql:host={$host};port={$port};dbname=postgres", $username, $password);
$exists = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
$exists->execute([$database]);

if ($exists->fetchColumn() === false) {
    $pdo->exec('CREATE DATABASE "'.str_replace('"', '""', $database).'"');
    fwrite(STDOUT, "Created database {$database}\n");
}
```

- [ ] **Step 4: Git hook**

`.githooks/pre-commit`:

```sh
#!/bin/sh
# Fast checks on staged PHP files. The full suite runs in CI (composer check).
set -e

FILES=$(git diff --cached --name-only --diff-filter=ACMR -- '*.php' | grep -v '\.blade\.php$' || true)
[ -z "$FILES" ] && exit 0

vendor/bin/pint --test $FILES
vendor/bin/phpstan analyse --no-progress --memory-limit=1G $FILES
```

```bash
chmod +x .githooks/pre-commit
git config core.hooksPath .githooks
```

- [ ] **Step 5: Composer scripts**

Replace the `scripts` entries `setup`, `dev`, `lint`, `lint:check`, `ci:check`, `types:check`, `test` in `composer.json` with (keep the `post-*` and `pre-package-uninstall` hooks unchanged):

```json
"setup": [
    "Composer\\Config::disableProcessTimeout",
    "composer install",
    "@php -r \"file_exists('.env') || copy('.env.example', '.env');\"",
    "@php artisan key:generate --ansi",
    "@php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\"",
    "@php artisan migrate --force --ansi",
    "@php -r \"is_dir('.git') && exec('git config core.hooksPath .githooks');\"",
    "npm ci",
    "npm run build"
],
"dev": [
    "Composer\\Config::disableProcessTimeout",
    "@php artisan dev"
],
"fix": [
    "rector process --no-progress-bar",
    "pint --parallel"
],
"check": [
    "Composer\\Config::disableProcessTimeout",
    "@php artisan config:clear --ansi",
    "pint --parallel --test",
    "rector process --dry-run --no-progress-bar",
    "phpstan analyse --memory-limit=1G --no-progress",
    "pest --parallel --exclude-group=browser"
],
"test:pgsql": [
    "Composer\\Config::disableProcessTimeout",
    "@php artisan config:clear --ansi",
    "@php scripts/create-pgsql-test-database.php",
    "DB_CONNECTION=pgsql DB_HOST=${DB_HOST:-127.0.0.1} DB_PORT=${DB_PORT:-5432} DB_DATABASE=${DB_TEST_DATABASE:-$(basename \"$PWD\")_test} DB_USERNAME=${DB_USERNAME:-root} DB_PASSWORD=${DB_PASSWORD:-} pest --parallel --exclude-group=browser"
],
"test:browser": [
    "Composer\\Config::disableProcessTimeout",
    "pest --group=browser"
]
```

(The M6 setup engine replaces `setup` with `skv:bootstrap` + `skv:install`, spec §5.3.3.)

- [ ] **Step 6: Run everything**

```bash
php artisan test --compact tests/Feature/Tooling
composer check
composer test:pgsql
```

Expected: all three PASS. `composer test:pgsql` prints `Created database skv3_test` the first time.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "chore: add composer scripts, Postgres test run, dev commands and git hooks

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 13: CI workflow and Dependabot

**Files:**
- Create: `.github/workflows/ci.yml`
- Modify: `.github/dependabot.yml`
- Test: `tests/Feature/Tooling/CiWorkflowTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Tooling/CiWorkflowTest.php`:

```php
<?php

declare(strict_types=1);

it('keeps CI in ci.yml with matrix PHP versions only', function () {
    $ci = file_get_contents(base_path('.github/workflows/ci.yml'));

    expect($ci)->toContain('php-version: ${{ matrix.php }}')
        ->and(preg_match("/php-version:\s*'\d+\.\d+'/", (string) $ci))->toBe(0)
        ->and($ci)->toContain('postgres:18')
        ->and($ci)->toContain('composer check');
});

it('never references Flux Pro in the committed composer files', function () {
    expect(file_get_contents(base_path('composer.json')))->not->toContain('flux-pro')
        ->and(file_get_contents(base_path('composer.lock')))->not->toContain('flux-pro');
});
```

Run: `php artisan test --compact --filter=CiWorkflow` → Expected: FAIL (file missing).

- [ ] **Step 2: Write `ci.yml`**

```yaml
name: ci

on:
  push:
    branches: [main]
  pull_request:

permissions:
  contents: read

concurrency:
  group: ci-${{ github.ref }}
  cancel-in-progress: true

jobs:
  check:
    name: ${{ matrix.name }}
    runs-on: ubuntu-latest
    timeout-minutes: 20
    strategy:
      fail-fast: false
      matrix:
        include:
          - name: main (PHP 8.4, PostgreSQL)
            php: '8.4'
            db: pgsql
          - name: sqlite-free (PHP 8.4, SQLite)
            php: '8.4'
            db: sqlite
          - name: php85 (PHP 8.5, SQLite)
            php: '8.5'
            db: sqlite

    services:
      postgres:
        image: postgres:18
        env:
          POSTGRES_USER: postgres
          POSTGRES_PASSWORD: postgres
          POSTGRES_DB: app_test
        ports:
          - 5432:5432
        options: >-
          --health-cmd "pg_isready -U postgres"
          --health-interval 5s
          --health-timeout 5s
          --health-retries 10

    steps:
      - name: Checkout
        uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
        with:
          persist-credentials: false

      - name: Setup PHP
        uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962 # 2.40.0
        with:
          php-version: ${{ matrix.php }}
          extensions: pdo_pgsql, pdo_sqlite, gd
          tools: composer:v2
          coverage: none

      - name: Setup Node
        uses: actions/setup-node@949feb2413d6458794dcd2491c4babbbce0c15c1 # v7.1.0
        with:
          node-version: '22'
          cache: npm

      - name: Cache Composer and analysis caches
        uses: actions/cache@55cc8345863c7cc4c66a329aec7e433d2d1c52a9 # v6.1.0
        with:
          path: |
            ~/.composer/cache
            storage/framework/cache/phpstan
            storage/framework/cache/rector
          key: ${{ runner.os }}-php${{ matrix.php }}-${{ hashFiles('composer.lock') }}
          restore-keys: ${{ runner.os }}-php${{ matrix.php }}-

      - name: Install
        run: |
          composer install --no-interaction --prefer-dist --no-progress
          cp .env.example .env
          php artisan key:generate --ansi
          npm ci
          npm run build

      - name: Audit dependencies
        run: composer audit

      - name: Check (SQLite)
        if: matrix.db == 'sqlite'
        run: composer check

      - name: Check (PostgreSQL)
        if: matrix.db == 'pgsql'
        env:
          DB_CONNECTION: pgsql
          DB_HOST: 127.0.0.1
          DB_PORT: 5432
          DB_DATABASE: app_test
          DB_USERNAME: postgres
          DB_PASSWORD: postgres
        run: composer check
```

- [ ] **Step 3: Dependabot**

`.github/dependabot.yml`:

```yaml
version: 2
updates:
  - package-ecosystem: "github-actions"
    directory: "/"
    schedule:
      interval: "weekly"
    cooldown:
      default-days: 5
    groups:
      github-actions:
        patterns: ["*"]
  - package-ecosystem: "composer"
    directory: "/"
    schedule:
      interval: "weekly"
    cooldown:
      default-days: 5
  - package-ecosystem: "npm"
    directory: "/"
    schedule:
      interval: "weekly"
    cooldown:
      default-days: 5
```

- [ ] **Step 4: Validate and run**

```bash
python3 -c "import yaml,sys; [yaml.safe_load(open(p)) for p in sys.argv[1:]]; print('yaml ok')" .github/workflows/ci.yml .github/dependabot.yml
php artisan test --compact --filter=CiWorkflow
```

Expected: `yaml ok` and PASS. (If PyYAML is missing: `python3 -m pip install --user pyyaml` is NOT allowed without asking; use `ruby -ryaml -e 'ARGV.each { |p| YAML.load_file(p) }; puts "yaml ok"' .github/workflows/ci.yml .github/dependabot.yml` instead.)

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "ci: add check matrix (Postgres, SQLite, PHP 8.5) and Dependabot

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 14: AI developer experience (Boost, CLAUDE.md, Claude settings)

**Files:**
- Create: `CLAUDE.md`, `.ai/guidelines/skv3.md`, `.claude/settings.json`, `boost.json` (generated, committed)
- Test: `tests/Feature/Tooling/AiConfigTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Tooling/AiConfigTest.php`:

```php
<?php

declare(strict_types=1);

it('imports the Boost guidelines from CLAUDE.md', function () {
    expect(file_get_contents(base_path('CLAUDE.md')))->toContain('@AGENTS.md');
});

it('ships Claude settings with superpowers and safety rules', function () {
    /** @var array{permissions: array{deny: list<string>, allow: list<string>}, enabledPlugins: array<string, bool>, extraKnownMarketplaces: array<string, mixed>} $settings */
    $settings = json_decode((string) file_get_contents(base_path('.claude/settings.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($settings['enabledPlugins'])->toHaveKey('superpowers@superpowers-marketplace')
        ->and($settings['extraKnownMarketplaces'])->toHaveKey('superpowers-marketplace')
        ->and($settings['permissions']['deny'])->toContain('Bash(herd sites:*)')
        ->and($settings['permissions']['deny'])->toContain('Bash(php artisan migrate:fresh:*)')
        ->and($settings['permissions']['deny'])->toContain('Bash(php artisan db:wipe:*)')
        ->and($settings['permissions']['deny'])->toContain('Read(./.env*)');
});

it('targets Claude Code in boost.json', function () {
    /** @var array{agents: list<string>} $boost */
    $boost = json_decode((string) file_get_contents(base_path('boost.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($boost['agents'])->toContain('claude_code');
});
```

Run: `php artisan test --compact --filter=AiConfig` → Expected: FAIL.

- [ ] **Step 2: Install Boost non-interactively for Claude Code only (verified 2026-10-10 with Boost 2.10.3)**

Boost writes Claude Code's guidelines into `CLAUDE.md` whenever that file exists, unless `boost.agents.claude_code.guidelines_path` is configured; and `boost:install -n` installs for the agents listed in `boost.json`. So pre-seed both:

```bash
composer require --dev laravel/boost -n
php artisan vendor:publish --tag=boost-config -n
```

In `config/boost.php`, add before the closing `];`:

```php
    'agents' => [
        'claude_code' => [
            'guidelines_path' => 'AGENTS.md',
        ],
    ],
```

```bash
printf '{\n    "agents": ["claude_code"],\n    "guidelines": true,\n    "mcp": true,\n    "skills": []\n}\n' > boost.json
printf '# CLAUDE.md\n\n@AGENTS.md\n' > CLAUDE.md
php artisan boost:install --guidelines --skills --mcp --no-interaction
cat boost.json && head -3 AGENTS.md && cat CLAUDE.md
```

Expected: `boost.json` lists only `"claude_code"` under `agents`; `AGENTS.md` starts with `<laravel-boost-guidelines>`; `CLAUDE.md` is still the two-line stub (Step 4 replaces it); `.mcp.json` has `laravel-boost`; skills are in `.claude/skills/`. Append `/.claude/skills/` to `.gitignore` (Boost regenerates them). Add `"@php artisan boost:update --ansi"` to `post-update-cmd` in `composer.json` only if it is not there yet.

- [ ] **Step 3: Kit guideline for Boost**

`.ai/guidelines/skv3.md`:

```markdown
# skv3 kit rules

- Livewire components are multi-file (`<name>/<name>.php` + `<name>.blade.php`), never single-file, never with ⚡ in the path. Create them with `php artisan make:livewire` (config defaults to MFC without emoji).
- Full-page components live in `resources/views/pages/**` (`pages::` namespace); reusable ones in `resources/views/components/**`.
- Component classes never call `$this->view()`; pass data with `with(): array` or `#[Computed]` properties.
- Every PHP file declares `strict_types=1`. Run `composer fix` after editing PHP.
- Tests are Pest functions (`it(...)`). Feature tests use `RefreshDatabase` via `tests/Pest.php`. Arch rules live in `tests/Arch`.
- Before claiming work is done: `composer check` (SQLite) and `composer test:pgsql` (PostgreSQL) must both pass.
- Never use driver-specific SQL (`ilike`, raw `ALTER TABLE ... CONSTRAINT`); use the query builder (`whereLike(..., caseSensitive: false)`).
- Never call `env()` outside `config/`.
```

```bash
php artisan boost:update --ansi
grep -n "skv3 kit rules" AGENTS.md
```

Expected: the guideline appears in the generated `AGENTS.md`.

- [ ] **Step 4: `CLAUDE.md`**

```markdown
# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

@AGENTS.md

## What this is

skv3 is a multi-tenant Laravel starter kit (successor of skv2). The design spec is `docs/superpowers/specs/2026-10-10-skv3-design.md`; decisions live in `docs/decisions.md`; milestone plans in `docs/superpowers/plans/`.

## Commands

- `composer check` — Pint (test), Rector (dry run), Larastan level 8, Pest in parallel on SQLite. Must pass before any commit claims success.
- `composer test:pgsql` — the same Pest suite on PostgreSQL (Herd Pro, user `root`, empty password). Must also pass.
- `composer fix` — Rector, then Pint.
- `php artisan test --compact --filter=<name>` — run one test; `php artisan test --compact tests/Arch` — arch rules only.
- `composer dev` — Vite, queue, Pail and the scheduler (Herd serves the site; never run `artisan serve`).

## Conventions

- Multi-file Livewire components only, no ⚡ in paths, no `$this->view()` (enforced by `tests/Arch/LivewireConventionsTest.php`).
- `declare(strict_types=1)` everywhere; Larastan level 8 with no baseline — fix errors, never ignore them.
- No hard-coded kit or site names (`skv`) in app-identity values; derive them from `APP_URL` / the folder name.
- `livewire/flux-pro` must never be added to the committed `composer.json` or `composer.lock`; Pro features are an optional layer.
- UI copy defaults to Dutch (`nl`) with English (`en`); code, comments and docs are English.

## Safety

- Never run `herd sites` or the Herd MCP `get_all_sites`: they print every local site's `.env`, including secrets.
- Never run `migrate:fresh` or `db:wipe` against the development database; tests use their own databases.

## Superpowers

Use brainstorming / writing-plans for new features, systematic-debugging for real bugs, and verification-before-completion before committing. Skip skills for questions, small copy or formatting changes, git and read-only work.
```

- [ ] **Step 5: `.claude/settings.json`**

```json
{
    "$schema": "https://json.schemastore.org/claude-code-settings.json",
    "permissions": {
        "allow": [
            "Bash(php artisan:*)",
            "Bash(composer check)",
            "Bash(composer fix)",
            "Bash(composer test:pgsql)",
            "Bash(composer test:browser)",
            "Bash(composer install:*)",
            "Bash(composer show:*)",
            "Bash(vendor/bin/pest:*)",
            "Bash(vendor/bin/pint:*)",
            "Bash(vendor/bin/rector:*)",
            "Bash(vendor/bin/phpstan:*)",
            "Bash(npm run build)",
            "Bash(npm ci)",
            "Bash(git status)",
            "Bash(git diff:*)",
            "Bash(git log:*)"
        ],
        "deny": [
            "Bash(herd sites:*)",
            "Bash(php artisan migrate:fresh:*)",
            "Bash(php artisan db:wipe:*)",
            "Read(./.env*)"
        ]
    },
    "enabledPlugins": {
        "superpowers@superpowers-marketplace": true
    },
    "extraKnownMarketplaces": {
        "superpowers-marketplace": {
            "source": {
                "source": "github",
                "repo": "obra/superpowers-marketplace"
            }
        }
    },
    "hooks": {
        "PostToolUse": [
            {
                "matcher": "Edit|Write",
                "hooks": [
                    {
                        "type": "command",
                        "command": "f=$(php -r '$i=json_decode(stream_get_contents(STDIN),true); echo $i[\"tool_input\"][\"file_path\"] ?? \"\";'); case \"$f\" in *.blade.php) ;; *.php) vendor/bin/pint \"$f\" >/dev/null 2>&1 ;; esac; exit 0",
                        "async": true
                    }
                ]
            }
        ]
    }
}
```

Before committing, check the hook schema against the current Claude Code docs (`claude-code-guide` agent or https://docs.claude.com/en/docs/claude-code/hooks): confirm that `async` is a supported key for command hooks and that the tool input arrives on stdin as JSON with `tool_input.file_path`. If `async` is not supported, remove that key (Pint on one file is fast enough synchronously).

- [ ] **Step 6: Run the tests and the full check**

```bash
php artisan test --compact --filter=AiConfig
composer check
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "chore: add Boost, CLAUDE.md, kit guideline and Claude settings

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 15: README, CHANGELOG and name-leak test

**Files:**
- Modify: `README.md` (replace the kit's README)
- Create: `CHANGELOG.md`, `tests/Arch/NameLeakTest.php`

- [ ] **Step 1: Write the failing name-leak test (spec §7.2)**

`tests/Arch/NameLeakTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('hard-codes no kit or site name in app-identity values', function () {
    $allowed = [
        '/skv:[a-z-]+/',            // artisan command namespace
        "/config\\(['\"]skv\\./",    // config('skv.…') keys
        '/config\/skv\.php/',
        '/\bSkv::/',
        '/skv-[a-z-]+/',            // storage marker / state files
        '/\.ai\/guidelines\/skv3\.md/',
    ];

    $paths = collect([base_path('.env.example'), base_path('phpunit.xml')])
        ->merge(collect(File::allFiles(config_path()))->map->getPathname())
        ->merge(collect(File::allFiles(database_path('seeders')))->map->getPathname())
        ->merge(collect(File::allFiles(base_path('tests')))->map->getPathname()
            ->reject(fn (string $p) => str_ends_with($p, 'NameLeakTest.php')));

    $hits = $paths->flatMap(function (string $path) use ($allowed) {
        $lines = preg_split('/\R/', (string) file_get_contents($path)) ?: [];

        return collect($lines)
            ->map(fn (string $line) => preg_replace($allowed, '', $line))
            ->filter(fn (?string $line) => $line !== null && stripos($line, 'skv') !== false)
            ->map(fn (string $line, int $n) => basename($path).':'.($n + 1).': '.trim($line));
    });

    expect($hits->values()->all())->toBe([]);
});
```

Run: `php artisan test --compact --filter=NameLeak`. Expected: PASS already, or a list of hits to fix (e.g. a hard-coded `skv3_test` default). Fix every hit at the source, re-run until PASS.

- [ ] **Step 2: README (M1 version)**

Replace `README.md` with:

```markdown
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
```

- [ ] **Step 3: CHANGELOG**

`CHANGELOG.md`:

```markdown
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
```

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "docs: add README, changelog and name-leak test

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 16: Milestone gate

- [ ] **Step 1: Fresh-clone verification**

```bash
CLONE="$(mktemp -d)/clone" && git clone -q ~/Herd/skv3 "$CLONE" && cd "$CLONE" && git switch -q m1-foundation
composer setup
composer check
composer test:pgsql
```

Expected: all three succeed from a clean clone (proves nothing depends on untracked files). `composer test:pgsql` uses the clone folder name for its test DB (`clone_test`); drop it afterwards:

```bash
php -r "(new PDO('pgsql:host=127.0.0.1;port=5432;dbname=postgres','root',''))->exec('DROP DATABASE IF EXISTS clone_test');"
```

- [ ] **Step 2: Spec coverage check for M1**

Confirm each item exists (tick in the PR description):
- spec §4.0 app defaults → `AppDefaultsTest`
- §4.10 no self-delete (D33), no registration/verification → `InviteOnlyTest`
- §6.1 MFC, no emoji, `make_command` config → `LivewireConventionsTest`
- §7.1 Larastan 8 incl. `resources/views`, Rector, Pint → `composer check`
- §7.2 arch presets, `env()` rule, name-leak → `tests/Arch/*`
- §7.4 scripts and hooks → `DevCommandsTest`, `GitHooksTest`
- §8 `ci.yml` with matrix PHP, Postgres 18, Dependabot → `CiWorkflowTest`
- §9 `CLAUDE.md` imports `@AGENTS.md`, settings deny list, superpowers → `AiConfigTest`
- DEC-19…DEC-24 recorded in `docs/decisions.md`

- [ ] **Step 3: Code review**

Use superpowers:requesting-code-review on the diff `main..m1-foundation`. Fix confirmed findings, re-run `composer check` and `composer test:pgsql`.

- [ ] **Step 4: Merge to main**

```bash
cd ~/Herd/skv3
git switch main
git merge --no-ff m1-foundation -m "Merge M0 + M1: spikes and foundation

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

(Branch protection on GitHub arrives with publication in M8; until then `main` is local only.)

- [ ] **Step 5: Write the M2 plan**

Use superpowers:writing-plans for milestone M2 (tenancy + authorization), using DEC-21 (S2 outcome) and DEC-22 (S3 outcome).
