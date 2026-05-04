<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Environment;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\Session;
use App\Models\User;
use App\Services\Sessions\SessionTokenVerifier;
use App\Support\Url;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Operator-auth gate for the Dashboard. Verifies a `__session` JWT against
 * the reserved `_admin` project's signing keys, loads the operator User,
 * and binds:
 *
 *   - Environment (the `_admin` production env)
 *   - User        (the operator)
 *   - Session     (the operator's live session)
 *   - "currentWorkspace" via app()->instance('dashboard.workspace', ...)
 *
 * On any failure (missing token, expired, banned, locked, no live session,
 * no membership in `_admin`) we 302 the visitor at the `_admin` Account
 * Portal sign-in URL.
 */
final class RequireAdminSession
{
    public function __construct(private readonly SessionTokenVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $project = Project::query()->withoutGlobalScopes()->where('slug', Project::SYSTEM_SLUG)->where('is_system', true)->first();
        $env = $project?->environments()
            ->where('kind', Environment::KIND_PRODUCTION)
            ->first();
        if ($env === null) {
            return $this->signInRedirect(null);
        }

        app()->instance(Environment::class, $env);
        app()->instance(Project::class, $project);

        $token = $this->extractJwt($request);
        if ($token === null) {
            return $this->signInRedirect($env, $request);
        }

        $claims = $this->verifier->verify($token, $env);
        if ($claims === null) {
            return $this->signInRedirect($env, $request);
        }

        $sid = $claims['sid'] ?? null;
        $sub = $claims['sub'] ?? null;
        if (! is_string($sid) || ! is_string($sub)) {
            return $this->signInRedirect($env, $request);
        }

        $session = Session::query()->withoutGlobalScopes()->where('id', $sid)->first();
        if ($session === null || ! in_array($session->status, Session::LIVE_STATUSES, true)) {
            return $this->signInRedirect($env, $request);
        }
        $user = User::query()->withoutGlobalScopes()->where('id', $sub)->first();
        if ($user === null || $user->banned || ($user->locked && $user->lockout_expires_at?->isFuture())) {
            return $this->signInRedirect($env, $request);
        }

        $membership = OrganizationMembership::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('user_id', $user->id)
            ->first();
        // No membership → still allow the request through; the Dashboard
        // controller routes the operator to the create-workspace wizard.

        app()->instance(User::class, $user);
        app()->instance(Session::class, $session);
        app()->instance('dashboard.workspace', $membership);

        return $next($request);
    }

    private function extractJwt(Request $request): ?string
    {
        $auth = $request->headers->get('Authorization');
        if (is_string($auth) && preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) === 1) {
            return $m[1];
        }
        $cookie = $request->cookie('__session');
        if (is_string($cookie) && $cookie !== '') {
            return $cookie;
        }

        return null;
    }

    private function signInRedirect(?Environment $env, ?Request $request = null): RedirectResponse
    {
        $signInUrl = $env !== null
            ? Url::fapi($env, '/sign-in')
            : '/sign-in';

        if ($request !== null) {
            $signInUrl .= '?'.http_build_query(['redirect_url' => $request->fullUrl()]);
        }

        return redirect()->away($signInUrl);
    }
}
