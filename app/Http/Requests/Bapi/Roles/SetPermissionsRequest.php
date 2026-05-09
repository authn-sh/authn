<?php

declare(strict_types=1);

namespace App\Http\Requests\Bapi\Roles;

use Illuminate\Foundation\Http\FormRequest;

final class SetPermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string'],
        ];
    }
}
