<?php

declare(strict_types=1);

namespace App\Http\Requests\Bapi\Users;

use Illuminate\Foundation\Http\FormRequest;

final class ProfileImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 5 MB cap; mime check is enforced separately against the actual
            // file bytes via getimagesize() in the controller (the supplied
            // MIME type is untrusted).
            'file' => ['required', 'file', 'max:5120'],
        ];
    }
}
