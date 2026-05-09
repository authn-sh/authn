<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Events\Organizations\OrganizationDomainCreated;
use App\Events\Organizations\OrganizationDomainDeleted;
use App\Events\Organizations\OrganizationDomainUpdated;
use App\Http\Requests\Bapi\Organizations\CreateDomainRequest;
use App\Http\Requests\Bapi\Organizations\UpdateDomainRequest;
use App\Http\Resources\OrganizationDomainResource;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\Verification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * BAPI organization-domains surface (PLAN §3.1, OA-2).
 *
 *   GET    /v1/organizations/{organization_id}/domains
 *   POST   /v1/organizations/{organization_id}/domains
 *   GET    /v1/organizations/{organization_id}/domains/{domain_id}
 *   PATCH  /v1/organizations/{organization_id}/domains/{domain_id}
 *   DELETE /v1/organizations/{organization_id}/domains/{domain_id}
 *   POST   /v1/organizations/{organization_id}/domains/{domain_id}/verify
 *
 * The actual DNS TXT lookup (and the verified=true transition) is owned by
 * AU-9's domain-verification job. This controller only mints the row + the
 * paired Verification challenge.
 */
final class OrganizationDomainController
{
    public const VERIFICATION_TTL_SECONDS = 14 * 24 * 60 * 60;

    public function index(Request $request, string $organizationId): JsonResponse
    {
        $org = $this->findOrganization($organizationId);
        if ($org === null) {
            return $this->error(404, 'organization_not_found', 'No organization matches that id in this environment.');
        }

        $query = OrganizationDomain::query()->where('organization_id', $org->id);
        if ($request->has('verified')) {
            $query->where('verified', $request->boolean('verified'));
        }
        $query->latest('created_at');

        $limit = max(1, min(500, (int) $request->input('limit', 10)));
        $offset = max(0, (int) $request->input('offset', 0));
        $total = (clone $query)->count();
        $rows = $query->skip($offset)->take($limit)->get();

        return response()->json([
            'data' => $rows->map(fn (OrganizationDomain $d) => OrganizationDomainResource::from($d))->all(),
            'total_count' => $total,
        ])->header('Cache-Control', 'no-store');
    }

    public function store(CreateDomainRequest $request, string $organizationId): JsonResponse
    {
        $env = app(Environment::class);
        $org = $this->findOrganization($organizationId);
        if ($org === null) {
            return $this->error(404, 'organization_not_found', 'No organization matches that id in this environment.');
        }

        $name = strtolower((string) $request->input('name'));
        $duplicate = OrganizationDomain::query()
            ->where('environment_id', $env->id)
            ->where('name', $name)
            ->exists();
        if ($duplicate) {
            return $this->error(409, 'form_identifier_exists', 'A domain with that name is already registered in this environment.');
        }

        [$domain, $verification] = DB::transaction(function () use ($env, $org, $name, $request): array {
            $domain = OrganizationDomain::create([
                'environment_id' => $env->id,
                'organization_id' => $org->id,
                'name' => $name,
                'verified' => false,
                'enrollment_mode' => $request->input('enrollment_mode', OrganizationDomain::MODE_MANUAL_INVITATION),
                'affiliation_email_address' => $request->input('affiliation_email_address'),
            ]);

            $verification = $this->issueDnsTxtVerification($env, $domain);

            return [$domain, $verification];
        });

        OrganizationDomainCreated::dispatch($domain);

        return response()->json(OrganizationDomainResource::from($domain->fresh(), $verification), 201);
    }

    public function show(string $organizationId, string $domainId): JsonResponse
    {
        $domain = $this->find($organizationId, $domainId);
        if ($domain === null) {
            return $this->error(404, 'organization_domain_not_found', 'No domain matches that id in this organization.');
        }

        return response()->json(OrganizationDomainResource::from($domain))
            ->header('Cache-Control', 'no-store');
    }

    public function update(UpdateDomainRequest $request, string $organizationId, string $domainId): JsonResponse
    {
        $domain = $this->find($organizationId, $domainId);
        if ($domain === null) {
            return $this->error(404, 'organization_domain_not_found', 'No domain matches that id in this organization.');
        }

        if ($request->has('enrollment_mode')) {
            $domain->enrollment_mode = (string) $request->input('enrollment_mode');
        }
        if ($request->has('affiliation_email_address')) {
            $domain->affiliation_email_address = $request->input('affiliation_email_address');
        }
        $domain->save();

        OrganizationDomainUpdated::dispatch($domain->fresh());

        return response()->json(OrganizationDomainResource::from($domain->fresh()));
    }

    public function destroy(string $organizationId, string $domainId): JsonResponse
    {
        $domain = $this->find($organizationId, $domainId);
        if ($domain === null) {
            return $this->error(404, 'organization_domain_not_found', 'No domain matches that id in this organization.');
        }

        $copy = $domain->replicate();
        $copy->setRawAttributes($domain->getAttributes(), sync: true);

        $domain->delete();

        OrganizationDomainDeleted::dispatch($copy);

        return response()->json([
            'object' => 'deleted_object',
            'id' => $domainId,
            'deleted' => true,
        ]);
    }

    public function verify(string $organizationId, string $domainId): JsonResponse
    {
        $env = app(Environment::class);
        $domain = $this->find($organizationId, $domainId);
        if ($domain === null) {
            return $this->error(404, 'organization_domain_not_found', 'No domain matches that id in this organization.');
        }

        $verification = $this->issueDnsTxtVerification($env, $domain);

        return response()->json(OrganizationDomainResource::from($domain->fresh(), $verification));
    }

    /* -------------------- helpers -------------------- */

    /**
     * Mint (or re-mint) a domain_dns_txt Verification carrying a fresh nonce
     * the operator must publish as a TXT record. The actual DNS lookup is in
     * AU-9; this issue just keeps the challenge state.
     */
    private function issueDnsTxtVerification(Environment $env, OrganizationDomain $domain): Verification
    {
        $nonce = 'authn-domain-verify='.Str::lower(Str::random(40));

        $verification = Verification::query()->withoutGlobalScopes()->create([
            'environment_id' => $env->id,
            'verifiable_type' => $domain->getMorphClass(),
            'verifiable_id' => $domain->id,
            'strategy' => Verification::STRATEGY_DOMAIN_DNS_TXT,
            'status' => Verification::STATUS_UNVERIFIED,
            'attempts' => 0,
            'expire_at' => now()->addSeconds(self::VERIFICATION_TTL_SECONDS),
            'nonce' => $nonce,
        ]);

        $domain->forceFill(['verification_id' => $verification->id])->save();

        return $verification;
    }

    private function find(string $organizationId, string $domainId): ?OrganizationDomain
    {
        $org = $this->findOrganization($organizationId);
        if ($org === null) {
            return null;
        }

        return OrganizationDomain::query()
            ->where('organization_id', $org->id)
            ->where('id', $domainId)
            ->first();
    }

    private function findOrganization(string $id): ?Organization
    {
        $env = app(Environment::class);

        return Organization::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $id)
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
