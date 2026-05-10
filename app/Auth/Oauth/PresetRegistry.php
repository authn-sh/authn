<?php

declare(strict_types=1);

namespace App\Auth\Oauth;

use App\Auth\Oauth\Presets\ApplePreset;
use App\Auth\Oauth\Presets\GitHubPreset;
use App\Auth\Oauth\Presets\GooglePreset;
use App\Auth\Oauth\Presets\MicrosoftPreset;
use App\Auth\Oauth\Presets\PresetContract;

/**
 * Canonical list of preset providers shipped with v0.4. Adding a new
 * preset is a one-line change here plus the corresponding implementation
 * under `app/Auth/Oauth/Presets/`.
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
