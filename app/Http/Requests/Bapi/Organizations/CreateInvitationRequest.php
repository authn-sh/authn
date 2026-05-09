<?php

declare(strict_types=1);

namespace App\Http\Requests\Bapi\Organizations;

use Illuminate\Foundation\Http\FormRequest;

final class CreateInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email_address' => ['required', 'email'],
            'role' => ['required', 'string', 'max:96'],
            'inviter_user_id' => ['nullable', 'string'],
            'redirect_url' => ['nullable', 'url'],
            'public_metadata' => ['nullable', 'array'],
            'expires_at' => ['nullable', 'date'],
        ];
    }
}
