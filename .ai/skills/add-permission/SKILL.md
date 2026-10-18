---
name: add-permission
description: "Use when adding, renaming or removing a permission or gating a page, route, action or navigation entry behind a new ability: the Permission enum case with its label, description and group, the super-only decision, permissions:sync, the route can: middleware, the policy and the tests. Never a permission string."
---

# Add a permission

Permissions live in one place, the `App\Authorization\Permission` enum (`app/Authorization/Permission.php`). The rules
are in `AGENTS.md` › *Tenancy and authorization* (*Permissions*, *Super admin*, *A policy method named like an
always-checked ability*); this skill is the checklist.

## 1. The enum case

- Add a case named like the existing ones (`UsersView = 'users.view'`): `<area>.<action>`, TitleCase key.
- Give it a `label()`, a `description()` and a `group()` arm (`App\Authorization\PermissionGroup`; add a group case only
  for a new area). The roles index renders its badge from these.
- Decide `superOnly()`. A super-only permission never reaches the `organisation_admin` template or a custom role
  (`Permission::organisationAdmin()`, `Permission::assignableToCustomRoles()`). Platform-wide abilities (creating or
  deleting organisations, system health) are super-only.
- Put the label and the description into both `lang/nl.json` and `lang/en.json` (informal Dutch "je").

## 2. Sync

- `php artisan permissions:sync` (`App\Authorization\SyncPermissions`) creates the permission, keeps the global
  `organisation_admin` template on the catalogue minus the super-only permissions, strips super-only permissions
  from custom roles and prunes permissions that left the catalogue. It is idempotent. Production runs it on every
  deploy (README › production checklist), and `composer doctor` reports drift
  (`App\Setup\Doctor\Checks\PermissionsCheck`).
- The `super_admin` role is a display badge and holds no permissions; super admins pass through `Gate::before`
  (`App\Authorization\SuperAdminGate`).

## 3. Use it

- Routes: `->can(Permission::…)` (`routes/admin.php`). A sidebar entry: the permission argument of its
  `App\Support\Navigation\NavigationItem` in `App\Support\Navigation\Navigation::items()`.
- Code: `$user->can(Permission::…)`, `Gate::forUser($actor)->authorize(…)` in actions, and policy methods that check it
  together with the organisation (see `app/Policies/InvitationPolicy.php`).
- Never write the permission's string value anywhere else, Blade included (`tests/Arch/AuthorizationRulesTest.php`).
- Switch the Spatie team only with `withPermissionsTeam($teamId, fn () => …)`.

## 4. Tests and docs

- `tests/Feature/Authorization/PermissionCatalogueTest.php` pins the number of permissions, the organisation-admin
  template's count (`Permission::organisationAdmin()`) and the super-only list: update all three, and the catalogue in
  the spec (§4.6).
- Its dataset over `Permission::cases()` checks the Dutch label, description and group of the new case.
- Test the gate for each role (`asMember()`, `asOrgAdmin()`, `asSuperAdmin()`) over HTTP, and the route's row in
  `tests/Feature/Tenancy/RouteHostMatrixTest.php` (`routeMatrixOutcome()`: the matrix member holds no permission).
- See the `write-tenant-test` skill for the helpers.

Then `composer fix`, `composer check`, `composer test:pgsql` and `composer test:browser`.
