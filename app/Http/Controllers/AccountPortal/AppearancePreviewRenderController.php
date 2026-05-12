<?php

declare(strict_types=1);

namespace App\Http\Controllers\AccountPortal;

use App\Models\Environment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Account-Portal-side preview render. The dashboard editor mints a token
 * via AppearancePreviewController, then loads `/_preview/appearance/{token}`
 * inside an iframe so operators see their unsaved appearance changes
 * applied to the real bundled `<SignIn />` component.
 *
 * Tokens are validated against the cache, scoped to the env currently
 * resolved by the FAPI host middleware (so cross-env replay is rejected
 * even if the token leaks). On success we stash the draft appearance in
 * the request attributes — HandleAccountPortalInertia reads it during
 * its `appearance` share and swaps in the override for this render only.
 *
 *   GET /_preview/appearance/{token}
 */
final class AppearancePreviewRenderController
{
    public const REQUEST_ATTRIBUTE = '_authn_appearance_preview_override';

    public function show(Request $request): InertiaResponse|Response
    {
        $env = app()->bound(Environment::class) ? app(Environment::class) : null;
        if (! $env instanceof Environment) {
            return response('environment unavailable', 404);
        }

        // FAPI routes carry `{env_slug}` as a domain/path placeholder; reading
        // route params positionally would shift `$token` to the env_slug.
        $token = (string) $request->route('token');

        $row = DB::table('appearance_preview_drafts')
            ->where('token', $token)
            ->where('environment_id', $env->id)
            ->where('expires_at', '>', now())
            ->first();
        if ($row === null) {
            return response('preview expired or invalid', 404)
                ->header('Cache-Control', 'no-store');
        }

        $draft = is_array(json_decode((string) $row->draft, true)) ? json_decode((string) $row->draft, true) : [];
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $draft);

        return Inertia::render('AccountPortal/SignIn', [
            'step' => null,
            'is_preview' => true,
        ]);
    }
}
