<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Auth\ErrorCodes;
use App\Events\Organizations\OrganizationDomainCreated;
use App\Events\Organizations\OrganizationDomainDeleted;
use App\Events\Organizations\OrganizationDomainUpdated;
use App\Http\Requests\Bapi\Organizations\CreateDomainRequest;
use App\Http\Requests\Bapi\Organizations\UpdateDomainRequest;
use App\Http\Resources\ClientResource;
use App\Http\Resources\OrganizationDomainResource;
use App\Models\Client;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrganizationDomainController
{
    public function index(Request $request): JsonResponse
    {
        $org = app(Organization::class);

        $query = OrganizationDomain::query()->where('organization_id', $org->id);
        if ($request->has('verified')) {
            $query->where('verified', $request->boolean('verified'));
        }
        if ($request->has('enrollment_mode')) {
            $query->where('enrollment_mode', (string) $request->input('enrollment_mode'));
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

    public function store(CreateDomainRequest $request): JsonResponse
    {
        $env = app(Environment::class);
        $org = app(Organization::class);

        $name = strtolower((string) $request->input('name'));
        $duplicate = OrganizationDomain::query()
            ->where('environment_id', $env->id)
            ->where('name', $name)
            ->exists();
        if ($duplicate) {
            return $this->error(409, ErrorCodes::FORM_IDENTIFIER_EXISTS, 'A domain with that name is already registered in this environment.');
        }

        $domain = OrganizationDomain::create([
            'environment_id' => $env->id,
            'organization_id' => $org->id,
            'name' => $name,
            'verified' => false,
            'enrollment_mode' => $request->input('enrollment_mode', OrganizationDomain::MODE_MANUAL_INVITATION),
            'affiliation_email_address' => $request->input('affiliation_email_address'),
        ]);

        OrganizationDomainCreated::dispatch($domain);

        return $this->envelope(OrganizationDomainResource::from($domain->fresh()));
    }

    public function show(Request $request): JsonResponse
    {
        $domain = $this->find($request);
        if ($domain === null) {
            return $this->error(404, 'organization_domain_not_found', 'No domain matches that id in this organization.');
        }

        return response()->json(OrganizationDomainResource::from($domain))
            ->header('Cache-Control', 'no-store');
    }

    public function update(UpdateDomainRequest $request): JsonResponse
    {
        $domain = $this->find($request);
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

        return $this->envelope(OrganizationDomainResource::from($domain->fresh()));
    }

    public function destroy(Request $request): JsonResponse
    {
        $domain = $this->find($request);
        if ($domain === null) {
            return $this->error(404, 'organization_domain_not_found', 'No domain matches that id in this organization.');
        }

        $snapshot = OrganizationDomainResource::from($domain);

        $copy = $domain->replicate();
        $copy->setRawAttributes($domain->getAttributes(), sync: true);
        $domain->delete();

        OrganizationDomainDeleted::dispatch($copy);

        return $this->envelope($snapshot);
    }

    private function find(Request $request): ?OrganizationDomain
    {
        $org = app(Organization::class);
        $domainId = (string) $request->route('domain_id');

        return OrganizationDomain::query()
            ->where('organization_id', $org->id)
            ->where('id', $domainId)
            ->first();
    }

    private function envelope(mixed $response, int $status = 200): JsonResponse
    {
        $client = app()->bound(Client::class) ? app(Client::class) : null;

        return response()->json([
            'response' => $response,
            'client' => ClientResource::from($client?->fresh()),
        ], $status)->header('Cache-Control', 'no-store');
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
