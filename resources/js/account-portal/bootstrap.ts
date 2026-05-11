import { usePage } from '@inertiajs/react'

/**
 * Shape of the env-derived bootstrap props shared from the server (see
 * App\Http\Middleware\HandleAccountPortalInertia).
 */
export type Appearance = {
    application_name?: string
    brand_color?: string
    logo_url?: string
    favicon_url?: string
    variables?: Record<string, string>
    elements?: Record<string, string>
    layout?: Record<string, unknown>
    etag?: string
    [key: string]: unknown
}

export type Localization = {
    default_locale: string
    supported_locales: string[]
    fallback_locale: string
    override_etag?: string
    catalog?: Record<string, string>
}

export type Paths = {
    sign_in_url: string
    sign_up_url: string
    after_sign_in_url: string
    after_sign_up_url: string
    after_sign_out_url: string
    user_profile_url: string
    [key: string]: string
}

export type AccountPortalEnv = {
    publishable_key: string
    fapi_url: string
    appearance: Appearance
    localization: Localization
    paths: Paths
}

export type SharedProps = {
    environment: AccountPortalEnv | null
}

/**
 * Hook every Account Portal page calls to pull the env bootstrap props +
 * derive the SDK-component prop bundles it needs.
 */
export function useBootstrap() {
    const { environment } = usePage<SharedProps>().props

    if (!environment) {
        return {
            ready: false as const,
            env: null,
            signInProps: null,
            signUpProps: null,
            userProfileProps: null,
        }
    }

    return {
        ready: true as const,
        env: environment,
        signInProps: {
            publishableKey: environment.publishable_key,
            fapiUrl: environment.fapi_url,
            signUpUrl: environment.paths.sign_up_url,
            afterSignInUrl: environment.paths.after_sign_in_url,
            appearance: environment.appearance,
        },
        signUpProps: {
            publishableKey: environment.publishable_key,
            fapiUrl: environment.fapi_url,
            signInUrl: environment.paths.sign_in_url,
            afterSignUpUrl: environment.paths.after_sign_up_url,
            appearance: environment.appearance,
        },
        userProfileProps: {
            publishableKey: environment.publishable_key,
            fapiUrl: environment.fapi_url,
            appearance: environment.appearance,
        },
        localization: environment.localization,
    }
}
