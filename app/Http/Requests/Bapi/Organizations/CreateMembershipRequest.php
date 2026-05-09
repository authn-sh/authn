<?php

declare(strict_types=1);

namespace App\Http\Requests\Bapi\Organizations;

use Illuminate\Foundation\Http\FormRequest;

final class CreateMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'string'],
            'role' => ['required', 'string', 'max:96'],
            'public_metadata' => ['nullable', 'array'],
            'private_metadata' => ['nullable', 'array'],
        ];
    }
}
