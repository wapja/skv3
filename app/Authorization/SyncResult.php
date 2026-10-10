<?php

declare(strict_types=1);

namespace App\Authorization;

final readonly class SyncResult
{
    /**
     * @param  list<string>  $created
     * @param  list<string>  $pruned
     */
    public function __construct(
        public array $created,
        public array $pruned,
        public bool $organisationAdminChanged,
        public int $superOnlyDetached,
        public int $badgesRepaired,
    ) {}

    public function changed(): bool
    {
        return $this->created !== []
            || $this->pruned !== []
            || $this->organisationAdminChanged
            || $this->superOnlyDetached > 0
            || $this->badgesRepaired > 0;
    }
}
