<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Dashboard\Concerns\ResolvesDashboardEnv;
use App\Models\JwtTemplate;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class JwtTemplatesController
{
    use ResolvesDashboardEnv;

    public function storeJwtTemplate(Request $request, string $project_slug, string $env_slug): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_-]{0,63}$/'],
            'claims' => ['required', 'array'],
            'lifetime' => ['sometimes', 'integer', 'between:1,86400'],
            'allowed_clock_skew' => ['sometimes', 'integer', 'between:0,300'],
            'signing_algorithm' => ['sometimes', 'string', 'in:RS256,ES256,HS256'],
            'custom_signing_key' => ['sometimes', 'nullable', 'string'],
        ]);

        JwtTemplate::query()->withoutGlobalScopes()->create([
            'environment_id' => $env->id,
            'name' => $data['name'],
            'claims' => $data['claims'],
            'lifetime' => $data['lifetime'] ?? 60,
            'allowed_clock_skew' => $data['allowed_clock_skew'] ?? 5,
            'signing_algorithm' => $data['signing_algorithm'] ?? JwtTemplate::ALG_RS256,
            'custom_signing_key' => $data['custom_signing_key'] ?? null,
        ]);

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/jwt-templates")
            ->with('jwt_template_saved', true);
    }

    public function updateJwtTemplate(Request $request, string $project_slug, string $env_slug, string $jwt_template_id): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $row = JwtTemplate::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $jwt_template_id)
            ->whereNull('removed_at')
            ->first();
        if ($row === null) {
            return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/jwt-templates");
        }

        $data = $request->validate([
            'claims' => ['sometimes', 'array'],
            'lifetime' => ['sometimes', 'integer', 'between:1,86400'],
            'allowed_clock_skew' => ['sometimes', 'integer', 'between:0,300'],
            'custom_signing_key' => ['sometimes', 'nullable', 'string'],
        ]);

        $row->fill(array_intersect_key($data, array_flip(['claims', 'lifetime', 'allowed_clock_skew'])));
        if (array_key_exists('custom_signing_key', $data)) {
            $row->custom_signing_key = $data['custom_signing_key'];
        }
        $row->save();

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/jwt-templates")
            ->with('jwt_template_saved', true);
    }

    public function destroyJwtTemplate(string $project_slug, string $env_slug, string $jwt_template_id): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $row = JwtTemplate::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $jwt_template_id)
            ->whereNull('removed_at')
            ->first();
        if ($row !== null) {
            $row->delete();
        }

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/jwt-templates")
            ->with('jwt_template_deleted', true);
    }
}
