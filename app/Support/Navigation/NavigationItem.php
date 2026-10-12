<?php

declare(strict_types=1);

namespace App\Support\Navigation;

use App\Authorization\Permission;
use App\Enums\NavigationGroup;

/** One navigation entry (§6.5, RL-6): the sidebar, its active state and the ⌘K palette read it. */
final readonly class NavigationItem
{
    public function __construct(
        public string $route,
        public string $label,
        public string $icon,
        public ?Permission $permission,
        public string $match,
        public NavigationGroup $group,
        public ?string $module = null,
        public bool $centralOnly = false,
    ) {}

    public function label(): string
    {
        return __($this->label);
    }

    public function url(): string
    {
        return route($this->route);
    }

    public function isCurrent(): bool
    {
        return request()->routeIs($this->match);
    }
}
