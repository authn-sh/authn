<?php

declare(strict_types=1);

namespace App\Http\Requests\Bapi\Organizations;

use Illuminate\Foundation\Http\FormRequest;

final class CreateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/'],
            'created_by' => ['nullable', 'string'],
            'max_allowed_memberships' => ['nullable', 'integer', 'min:0'],
            'admin_delete_enabled' => ['nullable', 'boolean'],
            'public_metadata' => ['nullable', 'array'],
            'private_metadata' => ['nullable', 'array'],
        ];
    }
}
