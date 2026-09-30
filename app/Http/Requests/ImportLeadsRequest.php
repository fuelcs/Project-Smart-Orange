<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportLeadsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate the file presence and format before passing it to the service.
     */
    public function rules(): array
    {
        return [
            // The file size limit is specified in kilobytes: 20480 KiB = 20 MiB.
            'file' => ['required', 'file', 'mimes:xlsx', 'extensions:xlsx', 'max:20480'],
        ];
    }
}
