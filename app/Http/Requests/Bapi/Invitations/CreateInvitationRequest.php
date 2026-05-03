<?php

declare(strict_types=1);

namespace App\Http\Requests\Bapi\Invitations;

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
            'redirect_url' => ['nullable', 'url'],
            'public_metadata' => ['nullable', 'array'],
            'expires_at' => ['nullable', 'date'],
            'template_slug' => ['nullable', 'string'],
        ];
    }
}
