<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Events\Organizations\OrganizationDomainUpdated;
use App\Events\Organizations\OrganizationDomainVerified;
use App\Http\Resources\ChallengeResource;
use App\Jobs\Organizations\VerifyOrganizationDomain;
use App\Models\Challenge;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\Verification;
use App\Services\Domains\DnsTxtResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generalized Challenge sub-resource for OrganizationDomain DNS-TXT
 * verification — replaces the per-action `/verify` endpoint. The Challenge
 * carries the operator-facing nonce (the TXT value to publish); answer()
 * triggers an immediate DnsTxtResolver lookup, and the background
 * VerifyOrganizationDomain job keeps polling regardless.
 */
final class OrganizationDomainChallengeController
{
    public const TTL_SECONDS = 14 * 24 * 60 * 60;

    public function store(string $organizationId, string $domainId): JsonResponse
    {
        $env = app(Environment::class);
        $domain = $this->find($organizationId, $domainId);
        if ($domain === null) {
            return $this->error(404, 'organization_domain_not_found', 'No domain matches that id in this organization.');
        }

        $challenge = $this->issueDnsTxtChallenge($env, $domain);

        return response()->json(ChallengeResource::from($challenge), 201)
            ->header('Cache-Control', 'no-store');
    }

    public function answer(string $organizationId, string $domainId, string $cid): JsonResponse
    {
        $domain = $this->find($organizationId, $domainId);
        if ($domain === null) {
            return $this->error(404, 'organization_domain_not_found', 'No domain matches that id in this organization.');
        }

        $challenge = $this->loadChallenge($cid, $domain);
        if ($challenge === null) {
            return $this->error(404, 'challenge_not_found', 'Challenge not found.');
        }

        $verification = Verification::query()->withoutGlobalScopes()->where('id', $challenge->verification_id)->first();
        if ($verification === null) {
            return $this->error(422, 'verification_failed', 'Underlying verification missing.');
        }

        if ($challenge->status === Challenge::STATUS_VERIFIED) {
            // Terminal — return the body so the SDK can reconcile.
            return response()->json(ChallengeResource::from($challenge))
                ->header('Cache-Control', 'no-store');
        }

        $this->runDnsLookup($domain, $challenge, $verification);

        // Fire-and-forget: background poller continues even after a miss so
        // the caller doesn't have to retry manually.
        VerifyOrganizationDomain::dispatch($domain->id);

        return response()->json(ChallengeResource::from($challenge->fresh() ?? $challenge))
            ->header('Cache-Control', 'no-store');
    }

    public function show(string $organizationId, string $domainId, string $cid): JsonResponse
    {
        $domain = $this->find($organizationId, $domainId);
        if ($domain === null) {
            return $this->error(404, 'organization_domain_not_found', 'No domain matches that id in this organization.');
        }

        $challenge = $this->loadChallenge($cid, $domain);
        if ($challenge === null) {
            return $this->error(404, 'challenge_not_found', 'Challenge not found.');
        }

        $this->refreshChallengeFromVerification($challenge);

        return response()->json(ChallengeResource::from($challenge->fresh() ?? $challenge))
            ->header('Cache-Control', 'no-store');
    }

    private function issueDnsTxtChallenge(Environment $env, OrganizationDomain $domain): Challenge
    {
        $nonce = 'authn-domain-verify='.Str::lower(Str::random(40));

        return DB::transaction(function () use ($env, $domain, $nonce): Challenge {
            $verification = Verification::query()->withoutGlobalScopes()->create([
                'environment_id' => $env->id,
                'verifiable_type' => $domain->getMorphClass(),
                'verifiable_id' => $domain->id,
                'strategy' => Verification::STRATEGY_DOMAIN_DNS_TXT,
                'status' => Verification::STATUS_UNVERIFIED,
                'attempts' => 0,
                'expire_at' => now()->addSeconds(self::TTL_SECONDS),
                'nonce' => $nonce,
            ]);

            $challenge = Challenge::query()->withoutGlobalScopes()->create([
                'environment_id' => $env->id,
                'parent_type' => Challenge::PARENT_ORGANIZATION_DOMAIN,
                'parent_id' => $domain->id,
                'step' => Challenge::STEP_SINGLE,
                'strategy' => Verification::STRATEGY_DOMAIN_DNS_TXT,
                'status' => Challenge::STATUS_PENDING,
                'verification_id' => $verification->id,
                'attempts' => 0,
                'nonce' => $nonce,
                'expire_at' => $verification->expire_at,
            ]);

            $domain->forceFill(['current_challenge_id' => $challenge->id])->save();

            return $challenge;
        });
    }

    private function runDnsLookup(OrganizationDomain $domain, Challenge $challenge, Verification $verification): void
    {
        $resolver = app(DnsTxtResolver::class);
        $expected = (string) $verification->nonce;
        if ($expected === '') {
            return;
        }

        $records = $resolver->resolve('_authn-verification.'.$domain->name);
        $matched = false;
        foreach ($records as $record) {
            if (str_contains($record, $expected)) {
                $matched = true;
                break;
            }
        }

        if ($matched) {
            $this->flipToVerified($domain, $challenge, $verification);

            return;
        }

        $attempts = (int) $verification->attempts + 1;
        $verification->forceFill(['attempts' => $attempts])->save();
        $challenge->forceFill(['attempts' => $attempts])->save();
    }

    private function flipToVerified(OrganizationDomain $domain, Challenge $challenge, Verification $verification): void
    {
        $wasVerified = (bool) $domain->verified;

        DB::transaction(function () use ($domain, $challenge, $verification): void {
            $verification->forceFill([
                'status' => Verification::STATUS_VERIFIED,
                'verified_at' => now(),
            ])->save();
            $challenge->forceFill([
                'status' => Challenge::STATUS_VERIFIED,
                'attempts' => (int) $verification->attempts,
                'error_code' => null,
                'error_message' => null,
            ])->save();
            $domain->forceFill([
                'verified' => true,
                'current_challenge_id' => null,
            ])->save();
        });

        $fresh = $domain->fresh();
        if (! $wasVerified && $fresh !== null) {
            OrganizationDomainVerified::dispatch($fresh);
        }
        if ($fresh !== null) {
            OrganizationDomainUpdated::dispatch($fresh);
        }
    }

    private function refreshChallengeFromVerification(Challenge $challenge): void
    {
        $verification = Verification::query()->withoutGlobalScopes()->where('id', $challenge->verification_id)->first();
        if ($verification === null) {
            return;
        }
        $challenge->forceFill([
            'status' => match ($verification->status) {
                Verification::STATUS_UNVERIFIED => Challenge::STATUS_PENDING,
                Verification::STATUS_VERIFIED => Challenge::STATUS_VERIFIED,
                Verification::STATUS_FAILED => Challenge::STATUS_FAILED,
                Verification::STATUS_EXPIRED => Challenge::STATUS_EXPIRED,
                default => Challenge::STATUS_PENDING,
            },
            'attempts' => (int) $verification->attempts,
            'nonce' => $verification->nonce,
            'error_code' => $verification->error_code,
            'error_message' => $verification->error_message,
        ])->save();
    }

    private function loadChallenge(string $cid, OrganizationDomain $domain): ?Challenge
    {
        return Challenge::query()
            ->withoutGlobalScopes()
            ->where('id', $cid)
            ->where('parent_type', Challenge::PARENT_ORGANIZATION_DOMAIN)
            ->where('parent_id', $domain->id)
            ->first();
    }

    private function find(string $organizationId, string $domainId): ?OrganizationDomain
    {
        $env = app(Environment::class);
        $org = Organization::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $organizationId)
            ->first();
        if ($org === null) {
            return null;
        }

        return OrganizationDomain::query()
            ->where('organization_id', $org->id)
            ->where('id', $domainId)
            ->first();
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'errors' => [[
                'code' => $code,
                'message' => $message,
                'long_message' => $message,
                'meta' => [],
            ]],
            'trace_id' => null,
        ], $status);
    }
}
