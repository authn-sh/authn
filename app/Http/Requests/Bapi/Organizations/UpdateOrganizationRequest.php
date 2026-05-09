<?php

declare(strict_types=1);

namespace App\Http\Requests\Bapi\Organizations;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:64', 'regex:/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/'],
            'max_allowed_memberships' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'admin_delete_enabled' => ['sometimes', 'boolean'],
            'public_metadata' => ['sometimes', 'array'],
            'private_metadata' => ['sometimes', 'array'],
        ];
    }
}
