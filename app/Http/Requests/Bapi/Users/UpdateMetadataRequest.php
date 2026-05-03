<?php

declare(strict_types=1);

namespace App\Http\Requests\Bapi\Users;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateMetadataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'public_metadata' => ['nullable', 'array'],
            'private_metadata' => ['nullable', 'array'],
            'unsafe_metadata' => ['nullable', 'array'],
        ];
    }
}
