<?php

declare(strict_types=1);

namespace App\Auth\Oauth;

use App\Auth\Oauth\Presets\ApplePreset;
use App\Auth\Oauth\Presets\DiscordPreset;
use App\Auth\Oauth\Presets\FacebookPreset;
use App\Auth\Oauth\Presets\GitHubPreset;
use App\Auth\Oauth\Presets\GitLabPreset;
use App\Auth\Oauth\Presets\GooglePreset;
use App\Auth\Oauth\Presets\LinkedInPreset;
use App\Auth\Oauth\Presets\MicrosoftPreset;
use App\Auth\Oauth\Presets\PresetContract;
use App\Auth\Oauth\Presets\SlackPreset;
use App\Auth\Oauth\Presets\XPreset;

/**
 * Canonical list of preset providers. Adding a new preset is a one-line
 * change here plus the corresponding implementation under
 * `app/Auth/Oauth/Presets/`. Every registered key gets a row seeded
 * (disabled, blank credentials) on every freshly-created environment by
 * `OauthProviderSeeder`.
 */
final class PresetRegistry
{
    /** @var array<string, PresetContract>|null */
    private ?array $cache = null;

    /**
     * @return array<string, PresetContract>
     */
    public function all(): array
    {
        return $this->cache ??= [
            (new GooglePreset)->key() => new GooglePreset,
            (new GitHubPreset)->key() => new GitHubPreset,
            (new ApplePreset)->key() => new ApplePreset,
            (new MicrosoftPreset)->key() => new MicrosoftPreset,
            (new DiscordPreset)->key() => new DiscordPreset,
            (new FacebookPreset)->key() => new FacebookPreset,
            (new LinkedInPreset)->key() => new LinkedInPreset,
            (new XPreset)->key() => new XPreset,
            (new GitLabPreset)->key() => new GitLabPreset,
            (new SlackPreset)->key() => new SlackPreset,
        ];
    }

    public function get(string $key): ?PresetContract
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }
}
