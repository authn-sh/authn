<?php

declare(strict_types=1);

namespace App\Http\Requests\Bapi\OauthProviders;

use App\Models\OauthProvider;
use Illuminate\Foundation\Http\FormRequest;

final class OauthProviderStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $kind = (string) $this->input('provider_kind', '');

        $rules = [
            'provider_kind' => ['required', 'string', 'in:'.implode(',', OauthProvider::KINDS)],
            'provider_key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,63}$/'],
            'name' => ['required', 'string', 'max:255'],
            'enabled' => ['nullable', 'boolean'],
            'allow_sign_in' => ['nullable', 'boolean'],
            'allow_sign_up' => ['nullable', 'boolean'],
            'block_email_subaddresses' => ['nullable', 'boolean'],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:1024'],
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string'],
            'additional_authorization_params' => ['nullable', 'array'],
            'attribute_mapping' => ['nullable', 'array'],
            'attribute_mapping.*' => ['string'],
        ];

        if ($kind === OauthProvider::KIND_CUSTOM_OIDC) {
            $rules['issuer'] = ['required', 'url', 'max:512'];
        }
        if ($kind === OauthProvider::KIND_CUSTOM_OAUTH2) {
            $rules['authorization_endpoint'] = ['required', 'url', 'max:512'];
            $rules['token_endpoint'] = ['required', 'url', 'max:512'];
            $rules['userinfo_endpoint'] = ['required', 'url', 'max:512'];
            $rules['userinfo_method'] = ['required', 'in:GET,POST'];
            $rules['userinfo_auth'] = ['required', 'in:bearer,basic,query'];
        }

        return $rules;
    }
}
