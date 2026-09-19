<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IndexPhotosRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'anno' => ['nullable', 'integer', 'between:1900,2100'],
            'speciali' => ['nullable', 'boolean'],
            'stato' => ['nullable', 'in:pubblicate,bozze,tutte'],
            'ordine' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'between:1,500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('speciali')) {
            $this->merge(['speciali' => $this->boolean('speciali')]);
        }
    }
}
