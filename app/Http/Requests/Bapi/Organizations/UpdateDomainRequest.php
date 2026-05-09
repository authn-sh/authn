<?php

declare(strict_types=1);

namespace App\Http\Requests\Bapi\Organizations;

use App\Models\OrganizationDomain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enrollment_mode' => ['sometimes', Rule::in(OrganizationDomain::ENROLLMENT_MODES)],
            'affiliation_email_address' => ['sometimes', 'nullable', 'email'],
        ];
    }
}
