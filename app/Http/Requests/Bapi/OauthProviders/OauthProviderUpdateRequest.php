<?php

declare(strict_types=1);

namespace App\Http\Requests\Bapi\OauthProviders;

use Illuminate\Foundation\Http\FormRequest;

final class OauthProviderUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'allow_sign_in' => ['sometimes', 'boolean'],
            'allow_sign_up' => ['sometimes', 'boolean'],
            'block_email_subaddresses' => ['sometimes', 'boolean'],
            'client_id' => ['sometimes', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:1024'],
            'scopes' => ['sometimes', 'array'],
            'scopes.*' => ['string'],
            'additional_authorization_params' => ['sometimes', 'array'],
            'attribute_mapping' => ['sometimes', 'array'],
            'attribute_mapping.*' => ['string'],
            'issuer' => ['sometimes', 'url', 'max:512'],
            'authorization_endpoint' => ['sometimes', 'url', 'max:512'],
            'token_endpoint' => ['sometimes', 'url', 'max:512'],
            'userinfo_endpoint' => ['sometimes', 'url', 'max:512'],
            'jwks_uri' => ['sometimes', 'nullable', 'url', 'max:512'],
            'id_token_signing_algs' => ['sometimes', 'array'],
            'id_token_signing_algs.*' => ['string'],
            'userinfo_method' => ['sometimes', 'in:GET,POST'],
            'userinfo_auth' => ['sometimes', 'in:bearer,basic,query'],
            'provider_kind' => ['prohibited'],
            'provider_key' => ['prohibited'],
        ];
    }
}
