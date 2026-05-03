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

        if ($slug === null || $this->isReserved($slug)) {
            return $this->notFound();
        }

        $environment = $this->resolveEnvironment($request, $slug);

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

            if (! str_ends_with($host, $suffix)) {
                return null;
            }

            $label = substr($host, 0, -strlen($suffix));

            return $label === '' ? null : $label;
        }

        // Path mode: first segment after the leading slash.
        $segments = explode('/', ltrim($request->path(), '/'));
        $first = $segments[0] ?? '';

        return $first === '' ? null : $first;
    }

    private function isReserved(string $slug): bool
    {
        $reserved = (array) config('authn.reserved_env_slugs', []);

        return in_array($slug, $reserved, true);
    }

    private function resolveEnvironment(Request $request, string $slug): ?Environment
    {
        $query = Environment::query()->with('project');

        if ((string) config('authn.routing_mode') === 'subdomain') {
            return $query->where('frontend_api_host', $request->getHost())->first();
        }

        return $query->where('slug', $slug)->first();
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
