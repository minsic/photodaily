<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Impostazioni della famiglia modificabili da un admin. Ogni campo è
 * facoltativo nella richiesta: si aggiorna solo quello che arriva.
 */
class UpdateFamilyRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->user()->family);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'protagonist_name' => ['sometimes', 'nullable', 'string', 'max:60'],
            'protagonist_birthdate' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.$this->user()->family->today()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'protagonist_name.max' => 'Il nome può avere al massimo 60 caratteri.',
            'protagonist_birthdate.date_format' => 'La data di nascita non è valida.',
            'protagonist_birthdate.before_or_equal' => 'La data di nascita non può essere nel futuro.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Un nome fatto di soli spazi vale come nessun nome.
        if ($this->has('protagonist_name')) {
            $this->merge(['protagonist_name' => trim((string) $this->input('protagonist_name')) ?: null]);
        }
    }
}
