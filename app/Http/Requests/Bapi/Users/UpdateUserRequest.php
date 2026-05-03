<?php

declare(strict_types=1);

namespace App\Http\Requests\Bapi\Users;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'external_id' => ['nullable', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:64'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'image_url' => ['nullable', 'url'],
            'locale' => ['nullable', 'string', 'max:16'],
            'primary_email_address_id' => ['nullable', 'string'],
            'public_metadata' => ['nullable', 'array'],
            'private_metadata' => ['nullable', 'array'],
            'unsafe_metadata' => ['nullable', 'array'],
            'delete_self_enabled' => ['nullable', 'boolean'],
        ];
    }
}
