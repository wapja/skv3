---
name: add-settings-page
description: "Use when adding a page or section under Settings (the signed-in user's own account: profile, appearance, security and the like): the multi-file Livewire page under pages/settings, its route in routes/settings.php, the settings sub-navigation, the route × host matrix row, the copy in both language files and the tests."
---

# Add a settings page

Settings pages are the signed-in user's own account pages. They answer on every host the user may sign in on. The
rules are in `AGENTS.md` (kit rules, *Tenancy and authorization*, *UI*); this skill is the checklist.

## 1. The component

- `php artisan make:livewire pages::settings.<name> --no-interaction` creates
  `resources/views/pages/settings/<name>/<name>.php` and `<name>.blade.php` (multi-file, no ⚡; the config defaults
  to it). Add `declare(strict_types=1);` to the generated class file. Never a single-file component, never
  `$this->view()`: pass data with `with(): array` or `#[Computed]`.
- Give the class a `#[Title('…')]`, and read the user with `App\Concerns\ResolvesCurrentUser::currentUser()`.
  Model `resources/views/pages/settings/appearance/` (the smallest page) or `resources/views/pages/settings/profile/`
  (a form).
- The Blade view starts with `@include('partials.settings-heading')` and wraps its content in
  `<x-pages::settings.layout :heading="__('…')" :subheading="__('…')">`.
- A settings page is an any-host page: it reads tenant models only through `App\Tenancy\HostScope::read()`.
- An action that changes or reveals a second factor starts with `passwordConfirmationRequired()`
  (`App\Concerns\ConfirmsPasswords`); an action a member can repeat to send mail or test a secret uses
  `App\Support\RateLimits\UserActionLimit` in the component. Display preferences change only through
  `App\Actions\Settings\UpdateLocale` and `App\Actions\Settings\UpdateAppearance`.
- During an impersonation the impersonator acts as the user: hide what only the account owner may do (see the
  sessions section in `resources/views/pages/settings/security/security.blade.php`).

## 2. Route and navigation

- Add the route inside the existing group in `routes/settings.php` (`auth` + `EnsureSecondFactor`):
  `Route::livewire('settings/<name>', 'pages::settings.<name>')->name('<name>.edit');`. No `Route::tenant()` or
  `Route::central()`: settings answer on every host.
- Add the entry to the settings sub-navigation in `resources/views/pages/settings/layout.blade.php` (not
  `Navigation::items()`, which is the sidebar). The user menu keeps its own items.
- Add the route name to `matrixRoutes()` in `tests/Feature/Tenancy/RouteHostMatrixTest.php`; its outcome follows
  `profile.edit` (`ok` for a signed-in user on their own host, `login` for a guest).

## 3. Copy

- Every new `__()` key goes into both `lang/nl.json` and `lang/en.json`, in sorted order (informal Dutch "je").

## 4. Tests

Use the `write-tenant-test` skill. At least:
- the page over HTTP on a tenant host for a member and on the admin host for a super admin, and a guest sent to login;
- the current item in the sub-navigation (`tests/Feature/Settings/SettingsNavigationTest.php`);
- each action through `pageSnapshot()` + `callPageAction()`, so the middleware runs, with its refusals.

Then `composer fix`, `composer check`, `composer test:pgsql` and `composer test:browser`.
