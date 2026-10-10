# skv3 kit rules

- Livewire components are multi-file (`<name>/<name>.php` + `<name>.blade.php`), never single-file, never with ⚡ in the path. Create them with `php artisan make:livewire` (config defaults to MFC without emoji).
- Full-page components live in `resources/views/pages/**` (`pages::` namespace); reusable ones in `resources/views/components/**`.
- Component classes never call `$this->view()`; pass data with `with(): array` or `#[Computed]` properties.
- Every PHP file declares `strict_types=1`. Run `composer fix` after editing PHP.
- Tests are Pest functions (`it(...)`). Feature tests use `RefreshDatabase` via `tests/Pest.php`. Arch rules live in `tests/Arch`.
- Before claiming work is done: `composer check` (SQLite) and `composer test:pgsql` (PostgreSQL) must both pass.
- Never use driver-specific SQL (`ilike`, raw `ALTER TABLE ... CONSTRAINT`); use the query builder (`whereLike(..., caseSensitive: false)`).
- Never call `env()` outside `config/`.
