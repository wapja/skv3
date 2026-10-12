<?php

declare(strict_types=1);

namespace App\Setup;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The setup questions and the --answers JSON schema (§5.3.2, RL-5). */
final readonly class Answers
{
    public const array KEYS = ['app_name', 'database', 'start_with', 'super_admin_email', 'super_admin_password', 'modules', 'https', 'playwright', 'claude'];

    public const array MODULES = ['passkeys', 'backups', 'impersonation'];

    /** @param  list<string>  $modules */
    private function __construct(
        public string $appName,
        public string $database,
        public string $startWith,
        public ?string $superAdminEmail,
        public ?string $superAdminPasswordHash,
        public array $modules,
        public bool $https,
        public bool $playwright,
        public bool $claude,
    ) {}

    public static function defaults(Detection $detection, string $folderName): self
    {
        return self::fromArray([], $detection, $folderName);
    }

    /**
     * app_name may not hold control characters, a double quote, a dollar sign or a backslash, so EnvFile writes it
     * to .env literally (double-quoted, no escapes, no ${VAR} interpolation).
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function fromArray(array $input, Detection $detection, string $folderName): self
    {
        $unknown = array_diff(array_keys($input), self::KEYS);

        if ($unknown !== []) {
            throw ValidationException::withMessages(['answers' => __('Unknown setup answers: :keys', ['keys' => implode(', ', $unknown)])]);
        }

        $values = array_merge([
            'app_name' => Str::headline($folderName),
            'database' => $detection->postgres ? 'pgsql' : 'sqlite',
            'start_with' => 'demo',
            'super_admin_email' => null,
            'super_admin_password' => null,
            'modules' => self::MODULES,
            'https' => true,
            'playwright' => false,
            'claude' => $detection->claude,
        ], array_filter($input, fn (mixed $value): bool => $value !== null));

        /** @var array{app_name: string, database: string, start_with: string, super_admin_email: ?string, super_admin_password: ?string, modules: list<string>, https: bool, playwright: bool, claude: bool} $valid */
        $valid = Validator::make($values, [
            'app_name' => ['required', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F"$\\\\]/'],
            'database' => ['required', Rule::in(['pgsql', 'sqlite'])],
            'start_with' => ['required', Rule::in(['demo', 'super_admin'])],
            'super_admin_email' => ['nullable', 'required_if:start_with,super_admin', 'email', 'max:255'],
            'super_admin_password' => ['nullable', 'required_if:start_with,super_admin', 'string', 'min:8'],
            'modules' => ['present', 'array'],
            'modules.*' => [Rule::in(self::MODULES)],
            'https' => ['required', 'boolean'],
            'playwright' => ['required', 'boolean'],
            'claude' => ['required', 'boolean'],
        ])->validate();

        $modules = array_values(array_unique($valid['modules']));

        if ($valid['database'] === 'sqlite') {
            $modules = array_values(array_diff($modules, ['backups']));
        }

        $usesSuperAdmin = $valid['start_with'] === 'super_admin';

        return new self(
            appName: trim($valid['app_name']),
            database: $valid['database'],
            startWith: $valid['start_with'],
            superAdminEmail: $usesSuperAdmin ? Str::lower(trim((string) $valid['super_admin_email'])) : null,
            superAdminPasswordHash: $usesSuperAdmin ? Hash::make((string) $valid['super_admin_password']) : null,
            modules: $modules,
            https: (bool) $valid['https'] || in_array('passkeys', $modules, true),
            playwright: (bool) $valid['playwright'],
            claude: (bool) $valid['claude'],
        );
    }

    /** @param  array<int, string>  $modules  any int-keyed module list, e.g. the result of array_diff(); re-indexed here */
    public function withModules(array $modules): self
    {
        return new self($this->appName, $this->database, $this->startWith, $this->superAdminEmail, $this->superAdminPasswordHash, array_values($modules), $this->https, $this->playwright, $this->claude);
    }

    public function withHttps(bool $https): self
    {
        return new self($this->appName, $this->database, $this->startWith, $this->superAdminEmail, $this->superAdminPasswordHash, $this->modules, $https, $this->playwright, $this->claude);
    }

    public function moduleEnabled(string $module): bool
    {
        return in_array($module, $this->modules, true);
    }

    /** @return array<string, mixed> the answers safe to store in the marker: no password, no hash */
    public function toPublicArray(): array
    {
        return [
            'app_name' => $this->appName,
            'database' => $this->database,
            'start_with' => $this->startWith,
            'super_admin_email' => $this->superAdminEmail,
            'modules' => $this->modules,
            'https' => $this->https,
            'playwright' => $this->playwright,
            'claude' => $this->claude,
        ];
    }
}
