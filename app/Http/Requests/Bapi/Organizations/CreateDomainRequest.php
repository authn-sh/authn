<?php

declare(strict_types=1);

namespace App\Http\Requests\Bapi\Organizations;

use App\Models\OrganizationDomain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:253', 'regex:/^(?=.{1,253}$)(?!-)[a-z0-9-]{1,63}(\.[a-z0-9-]{1,63})+$/i'],
            'enrollment_mode' => ['nullable', Rule::in(OrganizationDomain::ENROLLMENT_MODES)],
            'affiliation_email_address' => ['nullable', 'email'],
        ];
    }
}
