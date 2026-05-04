<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (event, endpoint). Lives until the event is GC'd.
 *
 * @property string $id
 * @property string $webhook_endpoint_id
 * @property string $webhook_event_id
 * @property int $attempt
 * @property string $status
 * @property ?int $response_status
 * @property ?string $response_body
 * @property ?array $response_headers
 * @property ?\DateTimeInterface $requested_at
 * @property ?\DateTimeInterface $completed_at
 * @property ?\DateTimeInterface $next_retry_at
 */
class WebhookDelivery extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ABANDONED = 'abandoned';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_IN_PROGRESS,
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
        self::STATUS_ABANDONED,
    ];

    public const RESPONSE_BODY_LIMIT = 8 * 1024;

    /**
     * Backoff schedule in seconds (PLAN §14.5). The job advances the cursor
     * by the `attempt` index; once exhausted the row goes to `abandoned`.
     */
    public const RETRY_DELAYS_SECONDS = [
        5,
        30,
        5 * 60,
        30 * 60,
        2 * 60 * 60,
        6 * 60 * 60,
        12 * 60 * 60,
        24 * 60 * 60,
        48 * 60 * 60,
        96 * 60 * 60,
    ];

    protected string $idPrefix = 'whd_';

    protected $fillable = [
        'webhook_endpoint_id',
        'webhook_event_id',
        'attempt',
        'status',
        'response_status',
        'response_body',
        'response_headers',
        'requested_at',
        'completed_at',
        'next_retry_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt' => 'int',
            'response_status' => 'int',
            'response_headers' => 'array',
            'requested_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'next_retry_at' => 'immutable_datetime',
        ];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class, 'webhook_event_id');
    }
}
