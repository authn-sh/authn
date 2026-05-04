<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Environment;
use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active Project + Environment for FAPI / Account Portal
 * requests and binds them into the container as singletons. Downstream
 * handlers read them via `app(Environment::class)` / `app(Project::class)`.
 *
 * Subdomain mode: the FAPI host is the leading subdomain label of the
 *   request host. Look up the Environment by `frontend_api_host`.
 * Path mode: the slug is the first path segment after the application
 *   host. Look up the Environment by `slug`. The segment is left in the
 *   request URI so route definitions like `/{env_slug}/v1/...` can still
 *   match (Laravel's router handles the binding).
 *
 * On a reserved label or an unresolved env slug we 404 with the standard
 * `errors[]` envelope using `code = environment_not_found`.
 */
final class ResolveProjectFromHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $this->extractEnvSlug($request);

        // Bare host (subdomain mode) / bare root (path mode) → `_admin` env.
        // The operator's hosted Account Portal lives at the bare URL; the
        // `_admin` slug is reserved and never reachable as a tenant.
        if ($slug === null) {
            $environment = Environment::query()
                ->with('project')
                ->whereHas('project', fn ($q) => $q->where('slug', Project::SYSTEM_SLUG)->where('is_system', true))
                ->first();
        } else {
            if ($this->isReserved($slug)) {
                return $this->notFound();
            }
            $environment = $this->resolveEnvironment($request, $slug);
        }

        if ($environment === null) {
            return $this->notFound();
        }

        app()->instance(Environment::class, $environment);
        app()->instance(Project::class, $environment->project);

        return $next($request);
    }

    private function extractEnvSlug(Request $request): ?string
    {
        if ((string) config('authn.routing_mode') === 'subdomain') {
            $host = $request->getHost();
            $appHost = (string) config('authn.app_host');
            $suffix = '.'.$appHost;

            // Bare host → admin context.
            if ($host === $appHost) {
                return null;
            }
            if (! str_ends_with($host, $suffix)) {
                return null;
            }

            $label = substr($host, 0, -strlen($suffix));

            return $label === '' ? null : $label;
        }

        // Path mode: first segment after the leading slash. The bare-root
        // mount serves the `_admin` env's Account Portal + FAPI paths
        // (sign-in, sign-up, user, verify, sign-out, /v1/..., /account/...,
        // /.well-known/...). Tenants get the leading {env_slug}/ prefix.
        $segments = explode('/', ltrim($request->path(), '/'));
        $first = $segments[0] ?? '';
        if ($first === '' || in_array($first, $this->bareRootSegments(), true)) {
            return null;
        }

        return $first;
    }

    private function isReserved(string $slug): bool
    {
        $reserved = (array) config('authn.reserved_env_slugs', []);

        return in_array($slug, $reserved, true);
    }

    /**
     * Path-mode helper: top-level URL segments owned by the bare-root mount
     * (the `_admin` env's Account Portal + FAPI). Anything else is treated
     * as a tenant env slug.
     *
     * @return list<string>
     */
    private function bareRootSegments(): array
    {
        return ['sign-in', 'sign-up', 'user', 'verify', 'sign-out', 'v1', 'account', '.well-known'];
    }

    private function resolveEnvironment(Request $request, string $label): ?Environment
    {
        // Both modes look up by the opaque `routing_label`. Subdomain mode
        // strips the leading host label (the part before `.<app_host>`);
        // path mode pulls the first URL segment. Either way the same
        // routing identity column drives dispatch.
        return Environment::query()->with('project')
            ->where('routing_label', $label)
            ->first();
    }

    private function notFound(): Response
    {
        return response()->json([
            'errors' => [[
                'code' => 'environment_not_found',
                'message' => 'No environment matches this host.',
                'long_message' => 'The host or env slug supplied in this request does not match any environment in this installation. If you control the environment, double-check that its frontend_api_host (subdomain mode) or slug (path mode) is set correctly.',
                'meta' => [],
            ]],
            'trace_id' => null,
        ], 404);
    }
}
