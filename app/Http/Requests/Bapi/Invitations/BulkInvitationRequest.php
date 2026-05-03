<?php

declare(strict_types=1);

namespace App\Http\Requests\Bapi\Invitations;

use Illuminate\Foundation\Http\FormRequest;

final class BulkInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invitations' => ['required', 'array', 'min:1', 'max:100'],
            'invitations.*.email_address' => ['required', 'email'],
            'invitations.*.redirect_url' => ['nullable', 'url'],
            'invitations.*.public_metadata' => ['nullable', 'array'],
            'invitations.*.expires_at' => ['nullable', 'date'],
            'invitations.*.template_slug' => ['nullable', 'string'],
        ];
    }
}
