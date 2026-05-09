<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use App\Mail\Mjml;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-env email template (MJML source + compiled HTML cache).
 *
 *   - `slug` is the canonical identifier the renderer / job pipeline looks
 *     up; v0.1 uses the active set declared in DEFAULT_TEMPLATES.
 *   - `body_markup` is the MJML source.
 *   - `body_html` is the result of compiling `body_markup` via the mjml CLI;
 *     compilation runs on save (operator edit) so render-time is just
 *     placeholder substitution.
 *   - `delivered_by_us` = false routes the rendered payload into a
 *     webhook event (`email.created`) instead of the configured driver, so
 *     operators can ship through their own ESP.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $slug
 * @property string $subject
 * @property ?string $from_email_name
 * @property ?string $reply_to_email_name
 * @property string $body_markup
 * @property ?string $body_html
 * @property bool $delivered_by_us
 * @property ?string $last_modified_by_user_id
 */
class EmailTemplate extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    public const SLUG_VERIFICATION_CODE = 'verification_code';

    public const SLUG_RESET_PASSWORD_CODE = 'reset_password_code';

    public const SLUG_INVITATION = 'invitation';

    public const SLUG_PASSWORD_CHANGED = 'password_changed';

    public const SLUG_PASSWORD_REMOVED = 'password_removed';

    public const SLUG_PRIMARY_EMAIL_CHANGED = 'primary_email_address_changed';

    public const SLUG_MAGIC_LINK_SIGN_IN = 'magic_link_sign_in';

    public const SLUG_MAGIC_LINK_SIGN_UP = 'magic_link_sign_up';

    public const SLUG_MAGIC_LINK_USER_PROFILE = 'magic_link_user_profile';

    public const SLUG_ORGANIZATION_INVITATION = 'organization_invitation';

    public const SLUG_ORGANIZATION_INVITATION_ACCEPTED = 'organization_invitation_accepted';

    public const SLUG_PASSKEY_ADDED = 'passkey_added';

    public const SLUG_PASSKEY_REMOVED = 'passkey_removed';

    /**
     * Active set seeded at bootstrap. Every entry has a body_markup (MJML
     * source) and a precompiled body_html (the result of running the mjml
     * CLI against the source — embedded so the seed doesn't depend on Node
     * being available at install time).
     *
     * Placeholders use `{{var}}` / `{{var.path}}`; the renderer HTML-
     * escapes user-supplied substitutions.
     *
     * @var array<string, array{subject:string, body_markup:string, body_html:string}>
     */
    public const DEFAULT_TEMPLATES = [
        self::SLUG_VERIFICATION_CODE => [
            'subject' => 'Your {{app.name}} verification code',
            'body_markup' => '<mjml><mj-body><mj-section><mj-column><mj-text>Hi {{user.first_name}},</mj-text><mj-text>Your verification code is <strong>{{code}}</strong>. It expires {{expires_at_human}}.</mj-text></mj-column></mj-section></mj-body></mjml>',
            'body_html' => '<!doctype html><html><body><p>Hi {{user.first_name}},</p><p>Your verification code is <strong>{{code}}</strong>. It expires {{expires_at_human}}.</p></body></html>',
        ],
        self::SLUG_RESET_PASSWORD_CODE => [
            'subject' => 'Reset your {{app.name}} password',
            'body_markup' => '<mjml><mj-body><mj-section><mj-column><mj-text>Hi {{user.first_name}},</mj-text><mj-text>Use this code to reset your password: <strong>{{code}}</strong>. It expires {{expires_at_human}}.</mj-text></mj-column></mj-section></mj-body></mjml>',
            'body_html' => '<!doctype html><html><body><p>Hi {{user.first_name}},</p><p>Use this code to reset your password: <strong>{{code}}</strong>. It expires {{expires_at_human}}.</p></body></html>',
        ],
        self::SLUG_INVITATION => [
            'subject' => "You're invited to {{app.name}}",
            'body_markup' => "<mjml><mj-body><mj-section><mj-column><mj-text>You've been invited to {{app.name}}.</mj-text><mj-button href=\"{{action_url}}\">Accept invitation</mj-button></mj-column></mj-section></mj-body></mjml>",
            'body_html' => "<!doctype html><html><body><p>You've been invited to {{app.name}}.</p><p><a href=\"{{action_url}}\">Accept invitation</a></p></body></html>",
        ],
        self::SLUG_PASSWORD_CHANGED => [
            'subject' => 'Your {{app.name}} password was changed',
            'body_markup' => "<mjml><mj-body><mj-section><mj-column><mj-text>Hi {{user.first_name}},</mj-text><mj-text>Your password was just changed. If this wasn't you, contact {{app.support_email}} immediately.</mj-text></mj-column></mj-section></mj-body></mjml>",
            'body_html' => "<!doctype html><html><body><p>Hi {{user.first_name}},</p><p>Your password was just changed. If this wasn't you, contact {{app.support_email}} immediately.</p></body></html>",
        ],
        self::SLUG_PASSWORD_REMOVED => [
            'subject' => 'Password removed from your {{app.name}} account',
            'body_markup' => '<mjml><mj-body><mj-section><mj-column><mj-text>Hi {{user.first_name}},</mj-text><mj-text>Password sign-in was just disabled on your account. You can still sign in via the other configured methods.</mj-text></mj-column></mj-section></mj-body></mjml>',
            'body_html' => '<!doctype html><html><body><p>Hi {{user.first_name}},</p><p>Password sign-in was just disabled on your account. You can still sign in via the other configured methods.</p></body></html>',
        ],
        self::SLUG_PRIMARY_EMAIL_CHANGED => [
            'subject' => 'Your {{app.name}} primary email was changed',
            'body_markup' => "<mjml><mj-body><mj-section><mj-column><mj-text>Hi {{user.first_name}},</mj-text><mj-text>The primary email on your account was changed. If this wasn't you, contact {{app.support_email}}.</mj-text></mj-column></mj-section></mj-body></mjml>",
            'body_html' => "<!doctype html><html><body><p>Hi {{user.first_name}},</p><p>The primary email on your account was changed. If this wasn't you, contact {{app.support_email}}.</p></body></html>",
        ],
        self::SLUG_MAGIC_LINK_SIGN_IN => [
            'subject' => 'Your sign-in link for {{app.name}}',
            'body_markup' => '<mjml><mj-body><mj-section><mj-column><mj-text>Hi {{user.first_name}},</mj-text><mj-text>Click the button below to sign in to {{app.name}}.</mj-text><mj-button href="{{action_url}}">Sign in to {{app.name}}</mj-button><mj-text>This link expires {{expires_at_human}}. If you didn\'t request it, you can ignore this email.</mj-text></mj-column></mj-section></mj-body></mjml>',
            'body_html' => '<!doctype html><html><body><p>Hi {{user.first_name}},</p><p>Click the button below to sign in to {{app.name}}.</p><p><a href="{{action_url}}" style="display:inline-block;padding:12px 24px;background:#000;color:#fff;text-decoration:none;border-radius:4px;">Sign in to {{app.name}}</a></p><p>This link expires {{expires_at_human}}. If you didn\'t request it, you can ignore this email.</p></body></html>',
        ],
        self::SLUG_MAGIC_LINK_SIGN_UP => [
            'subject' => 'Finish creating your {{app.name}} account',
            'body_markup' => '<mjml><mj-body><mj-section><mj-column><mj-text>Welcome to {{app.name}}.</mj-text><mj-text>Click the button below to finish creating your account.</mj-text><mj-button href="{{action_url}}">Continue to {{app.name}}</mj-button><mj-text>This link expires {{expires_at_human}}. If you didn\'t request it, you can ignore this email.</mj-text></mj-column></mj-section></mj-body></mjml>',
            'body_html' => '<!doctype html><html><body><p>Welcome to {{app.name}}.</p><p>Click the button below to finish creating your account.</p><p><a href="{{action_url}}" style="display:inline-block;padding:12px 24px;background:#000;color:#fff;text-decoration:none;border-radius:4px;">Continue to {{app.name}}</a></p><p>This link expires {{expires_at_human}}. If you didn\'t request it, you can ignore this email.</p></body></html>',
        ],
        self::SLUG_ORGANIZATION_INVITATION => [
            'subject' => "You've been invited to join {{organization.name}}",
            'body_markup' => '<mjml><mj-body><mj-section><mj-column><mj-text>{{inviter.name}} ({{inviter.email}}) has invited you to join <strong>{{organization.name}}</strong> on {{app.name}}.</mj-text><mj-text>You\'ll be added with the <strong>{{role.name}}</strong> role.</mj-text><mj-button href="{{action_url}}">Accept invitation</mj-button><mj-text>This invitation expires {{expires_at_human}}.</mj-text></mj-column></mj-section></mj-body></mjml>',
            'body_html' => '<!doctype html><html><body><p>{{inviter.name}} ({{inviter.email}}) has invited you to join <strong>{{organization.name}}</strong> on {{app.name}}.</p><p>You\'ll be added with the <strong>{{role.name}}</strong> role.</p><p><a href="{{action_url}}" style="display:inline-block;padding:12px 24px;background:#000;color:#fff;text-decoration:none;border-radius:4px;">Accept invitation</a></p><p>This invitation expires {{expires_at_human}}.</p></body></html>',
        ],
    ];

    protected string $idPrefix = 'tmpl_';

    protected $fillable = [
        'environment_id',
        'slug',
        'subject',
        'from_email_name',
        'reply_to_email_name',
        'body_markup',
        'body_html',
        'delivered_by_us',
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

        // Compile MJML on save so render-time is just placeholder
        // substitution. When body_html was set explicitly (e.g. seed data
        // or a manual override) we keep it.
        static::saving(function (self $template): void {
            if (! $template->isDirty('body_markup')) {
                return;
            }
            if ($template->isDirty('body_html')) {
                return;
            }
            $template->body_html = Mjml::compile((string) $template->body_markup);
        });
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
