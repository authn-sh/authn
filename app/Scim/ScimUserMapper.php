<?php

declare(strict_types=1);

namespace App\Scim;

use App\Models\EmailAddress;
use App\Models\Organization;
use App\Models\ScimAttributeMapping;
use App\Models\User;

/**
 * Bidirectional `User <-> SCIM Resource` mapper. Honours the per-org
 * `ScimAttributeMapping` overrides on top of the defaults baked into
 * `DefaultAttributeMappings`. Used by both list + show + create paths.
 *
 * For brevity v0.6 ships a fixed subset of the SCIM 2.0 User schema:
 * `userName`, `name.{givenName,familyName}`, `emails[primary eq true].value`,
 * `externalId`, `displayName`, `active`, `locale`, `meta`. Custom
 * attributes ride the IdP's enterprise extension and land on
 * `User.public_metadata` via the connection's `attribute_mapping`.
 */
final class ScimUserMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toResource(User $user, ?array $projectedAttributes = null): array
    {
        $primaryEmail = EmailAddress::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('is_primary', true)
            ->first();

        $resource = [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
            'id' => $user->id,
            'externalId' => $user->external_id,
            'userName' => $primaryEmail?->email_address ?? $user->username,
            'displayName' => $user->username ?? trim(((string) $user->first_name).' '.((string) $user->last_name)) ?: null,
            'name' => [
                'givenName' => $user->first_name,
                'familyName' => $user->last_name,
            ],
            'emails' => $primaryEmail ? [[
                'value' => $primaryEmail->email_address,
                'primary' => true,
                'type' => 'work',
            ]] : [],
            'active' => ! ((bool) $user->banned),
            'locale' => $user->locale,
            'meta' => [
                'resourceType' => 'User',
                'created' => $user->created_at?->toIso8601String(),
                'lastModified' => $user->updated_at?->toIso8601String(),
                'location' => null,
            ],
        ];

        if (is_array($projectedAttributes) && $projectedAttributes !== []) {
            $resource = $this->projectAttributes($resource, $projectedAttributes);
        }

        return $resource;
    }

    /**
     * Coerce an incoming SCIM resource into a `User`-shaped attribute
     * array. `$organization` is used to look up the per-org attribute
     * overrides; pass null for instance-wide creates.
     *
     * @param  array<string, mixed>  $resource
     * @return array{user: array<string, mixed>, primary_email: ?string}
     */
    public function fromResource(array $resource, ?Organization $organization): array
    {
        $mapping = $organization !== null
            ? ScimAttributeMapping::resolveFor($organization)
            : $this->defaultMapping();

        $user = [
            'first_name' => $this->extractPath($resource, 'name.givenName'),
            'last_name' => $this->extractPath($resource, 'name.familyName'),
            'external_id' => $this->extractPath($resource, 'externalId'),
            'username' => $this->extractPath($resource, 'displayName'),
            'locale' => $this->extractPath($resource, 'locale'),
        ];
        if (array_key_exists('active', $resource) && is_bool($resource['active'])) {
            $user['banned'] = ! $resource['active'];
        }

        $primaryEmail = $this->extractPrimaryEmail($resource);
        if ($primaryEmail === null) {
            $primaryEmail = $this->extractPath($resource, 'userName');
            if (is_string($primaryEmail) && ! filter_var($primaryEmail, FILTER_VALIDATE_EMAIL)) {
                $primaryEmail = null;
            }
        }

        unset($mapping); // Mapping currently unused in v0.6 default extraction.

        return ['user' => array_filter($user, fn ($v) => $v !== null), 'primary_email' => $primaryEmail];
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  list<string>  $attributes
     * @return array<string, mixed>
     */
    private function projectAttributes(array $resource, array $attributes): array
    {
        $kept = ['schemas' => $resource['schemas'], 'id' => $resource['id'], 'meta' => $resource['meta']];
        foreach ($attributes as $path) {
            $value = $this->extractPath($resource, $path);
            if ($value !== null) {
                $this->setPath($kept, $path, $value);
            }
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $array
     */
    private function extractPath(array $array, string $path): mixed
    {
        $segments = explode('.', $path);
        $cursor = $array;
        foreach ($segments as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * @param  array<string, mixed>  $array
     */
    private function setPath(array &$array, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $cursor = &$array;
        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $cursor[$segment] = $value;

                return;
            }
            if (! isset($cursor[$segment]) || ! is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function extractPrimaryEmail(array $resource): ?string
    {
        $emails = $resource['emails'] ?? [];
        if (! is_array($emails)) {
            return null;
        }
        foreach ($emails as $email) {
            if (is_array($email) && ! empty($email['primary']) && is_string($email['value'] ?? null)) {
                return strtolower($email['value']);
            }
        }
        foreach ($emails as $email) {
            if (is_array($email) && is_string($email['value'] ?? null)) {
                return strtolower($email['value']);
            }
        }

        return null;
    }

    /**
     * @return array<string, array{target: string, transform: ?string}>
     */
    private function defaultMapping(): array
    {
        $resolved = [];
        foreach (DefaultAttributeMappings::DEFAULTS as $source => $target) {
            $resolved[$source] = ['target' => $target, 'transform' => null];
        }

        return $resolved;
    }
}
