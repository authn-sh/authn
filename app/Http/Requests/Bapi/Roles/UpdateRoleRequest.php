<?php

declare(strict_types=1);

namespace App\Http\Requests\Bapi\Roles;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:512'],
            'is_creator_eligible' => ['sometimes', 'boolean'],
        ];
    }
}
