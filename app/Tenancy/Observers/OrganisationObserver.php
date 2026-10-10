<?php

declare(strict_types=1);

namespace App\Tenancy\Observers;

use App\Models\Organisation;
use App\Tenancy\Resolvers\SubdomainResolver;
use Illuminate\Support\Facades\Cache;

final class OrganisationObserver
{
    public function saved(Organisation $organisation): void
    {
        $this->forgetSlugs($organisation);
    }

    public function deleted(Organisation $organisation): void
    {
        $this->forgetSlugs($organisation);
    }

    public function restored(Organisation $organisation): void
    {
        $this->forgetSlugs($organisation);
    }

    public function forceDeleted(Organisation $organisation): void
    {
        $this->forgetSlugs($organisation);
    }

    /** In "saved" the original still holds the old slug, so a rename forgets both. */
    private function forgetSlugs(Organisation $organisation): void
    {
        $slugs = array_unique(array_filter([$organisation->slug, $organisation->getOriginal('slug')], is_string(...)));

        foreach ($slugs as $slug) {
            Cache::forget(SubdomainResolver::cacheKey($slug));
        }
    }
}
