<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityEvent;
use App\Tenancy\Concerns\BelongsToOrganisation;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity as VendorActivity;

/**
 * An audit entry (§4.8): a tenant model, so tenant hosts read only their own organisation's rows and
 * central hosts read it inside a tenant bypass. Rows with organisation_id NULL are central events. The
 * event column is a plain string: the vendor writes its own names (updated, …) into it, so an enum cast
 * would break those writes (RK-B1); kitEvent() reads it as an ActivityEvent.
 *
 * @property int|null $organisation_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $deleted_at
 */
class Activity extends VendorActivity
{
    /** @use HasFactory<ActivityFactory> */
    use BelongsToOrganisation, HasFactory, SoftDeletes;

    /**
     * Public because the vendor model declares it public. organisation_id is set only by the log action.
     *
     * @var list<string>
     */
    public $guarded = ['id', 'organisation_id'];

    public function kitEvent(): ?ActivityEvent
    {
        return $this->event === null ? null : ActivityEvent::tryFrom($this->event);
    }

    /**
     * The recorded diff as one row per attribute, for the viewer (§4.8). Attributes present on
     * only one side get null on the other.
     *
     * @return list<array{attribute: string, old: mixed, new: mixed}>
     */
    public function changeSummary(): array
    {
        $changes = $this->attribute_changes?->toArray() ?? [];
        $old = is_array($changes['old'] ?? null) ? $changes['old'] : [];
        $new = is_array($changes['attributes'] ?? null) ? $changes['attributes'] : [];
        $summary = [];

        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $attribute) {
            $summary[] = [
                'attribute' => (string) $attribute,
                'old' => $old[$attribute] ?? null,
                'new' => $new[$attribute] ?? null,
            ];
        }

        return $summary;
    }

    /** Central events (super admins on the admin host, failed sign-ins on the apex) have no organisation. */
    public function mayExistWithoutOrganisation(): bool
    {
        return true;
    }
}
