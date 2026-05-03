<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only log of session-touch events. One row per heartbeat (debounced
 * by the SessionLifecycle::touch helper that lands in AU-6).
 *
 * Internal-only. Pure auto-increment id; no prefix; no `updated_at`.
 *
 * @property int $id
 * @property string $session_id
 * @property ?string $device_type
 * @property ?bool $is_mobile
 * @property ?string $browser_name
 * @property ?string $browser_version
 * @property ?string $os_name
 * @property ?string $ip_address
 * @property ?string $city
 * @property ?string $country
 * @property \DateTimeInterface $created_at
 */
class SessionActivity extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'session_id',
        'device_type',
        'is_mobile',
        'browser_name',
        'browser_version',
        'os_name',
        'ip_address',
        'city',
        'country',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'is_mobile' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }
}
