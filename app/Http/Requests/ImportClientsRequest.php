<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportClientsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:csv,txt',
                'mimetypes:text/csv,text/plain,application/csv',
            ],
        ];
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Please select a CSV file to upload.',
            'file.file' => 'The uploaded file is invalid.',
            'file.mimes' => 'Only CSV files are allowed.',
            'file.mimetypes' => 'Only CSV files are allowed.',
        ];
    }
}
