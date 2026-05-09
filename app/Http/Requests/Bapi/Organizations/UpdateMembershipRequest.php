<?php

declare(strict_types=1);

namespace App\Http\Requests\Bapi\Organizations;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => ['required', 'string', 'max:96'],
            'public_metadata' => ['sometimes', 'array'],
            'private_metadata' => ['sometimes', 'array'],
        ];
    }
}
