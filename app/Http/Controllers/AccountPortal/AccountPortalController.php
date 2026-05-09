<?php

declare(strict_types=1);

namespace App\Http\Controllers\AccountPortal;

use App\Models\Client;
use App\Models\Environment;
use App\Models\Session;
use App\Services\Client\ClientResolver;
use App\Services\Sessions\SessionLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Hosted Account Portal pages — thin Inertia + React wrappers.
 *
 *   GET  /sign-in[/{step?}]    AccountPortal/SignIn
 *   GET  /sign-up[/{step?}]    AccountPortal/SignUp
 *   GET  /user[/{section?}]    AccountPortal/UserProfile (signed-in only)
 *   GET  /verify               AccountPortal/Verify
 *   POST /sign-out             ends current session, clears __session cookie
 *
 * Server logic is intentionally minimal: the SDK component on the page owns
 * the state machines and talks to FAPI directly. The only server jobs:
 *
 *   - Skip the SDK boot flicker for already-authed visitors on /sign-in /
 *     /sign-up (immediate 302 to after_sign_in_url).
 *   - Bounce signed-out visitors away from /user.
 *   - Provide the no-JS sign-out fallback.
 */
final class AccountPortalController
{
    public function __construct(
        private readonly ClientResolver $clientResolver,
        private readonly SessionLifecycle $lifecycle,
    ) {}

    public function signIn(Request $request, ?string $step = null): InertiaResponse|RedirectResponse
    {
        $env = app(Environment::class);
        if (($redirect = $this->signedInRedirect($request, $env)) !== null) {
            return redirect()->away($redirect);
        }

        return Inertia::render('AccountPortal/SignIn', [
            'step' => $step,
        ]);
    }

    public function signUp(Request $request, ?string $step = null): InertiaResponse|RedirectResponse
    {
        $env = app(Environment::class);
        if (($redirect = $this->signedInRedirect($request, $env)) !== null) {
            return redirect()->away($redirect);
        }

        return Inertia::render('AccountPortal/SignUp', [
            'step' => $step,
        ]);
    }

    public function userProfile(Request $request, ?string $section = null): InertiaResponse|RedirectResponse
    {
        $env = app(Environment::class);
        $client = $this->resolveClient($request, $env);
        if ($client === null || ! $this->hasLiveSession($client)) {
            return redirect('/sign-in');
        }

        return Inertia::render('AccountPortal/UserProfile', [
            'section' => $section,
        ]);
    }

    public function verify(): InertiaResponse
    {
        return Inertia::render('AccountPortal/Verify', []);
    }

    public function organizationList(Request $request): InertiaResponse|RedirectResponse
    {
        $env = app(Environment::class);
        $client = $this->resolveClient($request, $env);
        if ($client === null || ! $this->hasLiveSession($client)) {
            return redirect('/sign-in?redirect_url='.urlencode((string) $request->fullUrl()));
        }

        return Inertia::render('AccountPortal/OrganizationList', []);
    }

    public function createOrganization(Request $request): InertiaResponse|RedirectResponse
    {
        $env = app(Environment::class);
        $client = $this->resolveClient($request, $env);
        if ($client === null || ! $this->hasLiveSession($client)) {
            return redirect('/sign-in?redirect_url='.urlencode((string) $request->fullUrl()));
        }

        return Inertia::render('AccountPortal/CreateOrganization', []);
    }

    public function organizationProfile(Request $request): InertiaResponse|RedirectResponse
    {
        $env = app(Environment::class);
        $client = $this->resolveClient($request, $env);
        if ($client === null || ! $this->hasLiveSession($client)) {
            return redirect('/sign-in?redirect_url='.urlencode((string) $request->fullUrl()));
        }

        $id = $request->route('id');
        $tab = $request->route('tab');

        return Inertia::render('AccountPortal/OrganizationProfile', [
            'organizationId' => is_string($id) && $id !== '' ? $id : null,
            'tab' => is_string($tab) && $tab !== '' ? $tab : null,
        ]);
    }

    public function signOut(Request $request): RedirectResponse
    {
        $env = app(Environment::class);
        $client = $this->resolveClient($request, $env);
        if ($client !== null) {
            $client->sessions()
                ->whereIn('status', Session::LIVE_STATUSES)
                ->each(fn (Session $session) => $this->lifecycle->end($session));
        }

        $appearance = is_array($env->appearance) ? $env->appearance : [];
        $paths = is_array($appearance['paths'] ?? null) ? $appearance['paths'] : [];
        $afterSignOut = (string) ($paths['after_sign_out_url']
            ?? $appearance['home_url']
            ?? $env->home_url
            ?? '/sign-in');

        return redirect()->away($afterSignOut)
            ->withCookie(Cookie::forget('__session'));
    }

    private function resolveClient(Request $request, Environment $env): ?Client
    {
        $cookie = $request->cookie('__client');

        return $this->clientResolver->fromCookie(is_string($cookie) ? $cookie : null, $env);
    }

    private function hasLiveSession(Client $client): bool
    {
        // Live + a still-existing User. A deleted user leaves an orphan
        // Session row, which would otherwise loop the operator: dashboard
        // bounces them to /sign-in (no user), /sign-in bounces them back to
        // home_url (live session present). Joining on users breaks the cycle.
        return $client->sessions()
            ->whereIn('status', Session::LIVE_STATUSES)
            ->whereExists(function ($q): void {
                $q->select(\DB::raw(1))
                    ->from('users')
                    ->whereColumn('users.id', 'sessions.user_id')
                    ->whereNull('users.deleted_at');
            })
            ->exists();
    }

    private function signedInRedirect(Request $request, Environment $env): ?string
    {
        $client = $this->resolveClient($request, $env);
        if ($client === null || ! $this->hasLiveSession($client)) {
            return null;
        }
        $appearance = is_array($env->appearance) ? $env->appearance : [];
        $paths = is_array($appearance['paths'] ?? null) ? $appearance['paths'] : [];

        return (string) ($paths['after_sign_in_url']
            ?? $appearance['home_url']
            ?? $env->home_url
            ?? '/');
    }
}
