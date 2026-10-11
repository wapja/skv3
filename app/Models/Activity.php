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

    /** Central events (super admins on the admin host, failed sign-ins on the apex) have no organisation. */
    public function mayExistWithoutOrganisation(): bool
    {
        return true;
    }
}
