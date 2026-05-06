<?php

namespace Webkul\DAM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DirectoryPermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role_id'         => ['required', 'integer', 'exists:roles,id'],
            'directories'     => ['nullable', 'array'],
            'directories.*'   => ['integer', 'exists:dam_directories,id'],
        ];
    }
}
