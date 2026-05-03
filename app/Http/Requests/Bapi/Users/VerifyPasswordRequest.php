<?php

declare(strict_types=1);

namespace App\Http\Requests\Bapi\Users;

use Illuminate\Foundation\Http\FormRequest;

final class VerifyPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
        ];
    }
}
