<?php

declare(strict_types=1);

namespace App\Http\Requests\Bapi\Users;

use Illuminate\Foundation\Http\FormRequest;

final class CreateUserRequest extends FormRequest
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
            'email_addresses' => ['nullable', 'array'],
            'email_addresses.*' => ['string', 'email'],
            'password' => ['nullable', 'string', 'min:8'],
            'password_digest' => ['nullable', 'string'],
            'password_hasher' => ['nullable', 'in:bcrypt,argon2id,pbkdf2_sha256,scrypt'],
            'skip_password_checks' => ['nullable', 'boolean'],
            'public_metadata' => ['nullable', 'array'],
            'private_metadata' => ['nullable', 'array'],
            'unsafe_metadata' => ['nullable', 'array'],
        ];
    }
}
