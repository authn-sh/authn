<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-env SMS template (Liquid-style placeholders, single `body` column —
 * no MJML / HTML cache, since SMS is plain-text). Sibling of
 * `EmailTemplate`.
 *
 *   - `slug` is the canonical identifier looked up by the AU-12 send jobs.
 *   - `body` is the Liquid source.
 *   - `delivered_by_us = false` routes the rendered payload into a
 *     webhook event (`sms.created`) instead of the configured driver, so
 *     operators can ship through their own gateway.
 *   - `from_number_override` lets a specific template ship from a
 *     different sender than the system default; null falls back to
 *     `config('authn-sms.default_from_number')`.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $slug
 * @property string $body
 * @property bool $delivered_by_us
 * @property ?string $from_number_override
 * @property ?string $last_modified_by_user_id
 */
class SmsTemplate extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    public const SLUG_VERIFICATION_CODE = 'verification_code';

    public const SLUG_RESET_PASSWORD_CODE = 'reset_password_code';

    public const SLUG_INVITATION = 'invitation';

    /**
     * Active set seeded at env creation. Placeholders use `{{var}}` /
     * `{{var.path}}`; the renderer HTML-escapes user-supplied substitutions
     * the same way the email side does.
     *
     * @var array<string, array{body:string}>
     */
    public const DEFAULT_TEMPLATES = [
        self::SLUG_VERIFICATION_CODE => [
            'body' => '{{app.name}}: your verification code is {{otp_code}}. Expires in {{expiry_minutes}} minutes.',
        ],
        self::SLUG_RESET_PASSWORD_CODE => [
            'body' => '{{app.name}}: your password reset code is {{otp_code}}. Expires in {{expiry_minutes}} minutes.',
        ],
        self::SLUG_INVITATION => [
            'body' => "{{app.name}}: you've been invited to {{organization.name}}. Accept: {{action_url}}",
        ],
    ];

    protected string $idPrefix = 'tmpl_';

    protected $table = 'sms_templates';

    protected $fillable = [
        'environment_id',
        'slug',
        'body',
        'delivered_by_us',
        'from_number_override',
        'last_modified_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'delivered_by_us' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EnvironmentScope);
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function lastModifiedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_modified_by_user_id');
    }
}
