<?php

declare(strict_types=1);

namespace App\Http\Requests\Bapi\Users;

use Illuminate\Foundation\Http\FormRequest;

final class VerifyTotpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:6', 'max:8'],
        ];
    }
}
