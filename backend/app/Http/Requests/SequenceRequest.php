<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SequenceRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('speciali')) {
            $this->merge(['speciali' => $this->boolean('speciali')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'da' => ['nullable', 'date_format:Y-m-d'],
            'a' => array_filter(['nullable', 'date_format:Y-m-d', $this->filled('da') ? 'after_or_equal:da' : null]),
            'speciali' => ['sometimes', 'boolean'],
            'dopo' => ['nullable', 'string', 'ulid'],
        ];
    }
}
