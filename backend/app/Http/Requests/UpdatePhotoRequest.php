<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdatePhotoRequest extends FormRequest
{
    /**
     * L'autorizzazione precede la validazione: chi non è della famiglia riceve
     * 404 senza vedere errori di validazione sulla foto.
     */
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('photo'));
    }

    protected function prepareForValidation(): void
    {
        foreach (['data_speciale', 'is_draft'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => $this->boolean($field)]);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'data' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'didascalia' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'data_speciale' => ['sometimes', 'boolean'],
            'is_draft' => ['sometimes', 'boolean'],
        ];
    }
}
