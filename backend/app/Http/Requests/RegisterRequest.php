<?php

namespace App\Http\Requests;

use App\Models\Family;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'slug' => mb_strtolower(trim((string) $this->input('slug'))),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'family_name' => ['required', 'string', 'max:255'],
            'slug' => Family::slugRules(),
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'terms' => ['accepted'],
            // Quello del browser: decide che giorno è "oggi" per il diario.
            'timezone' => ['nullable', 'timezone:all'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...Family::slugMessages(),
            'email.unique' => 'Questa email ha già un account PhotoDaily: entra dal tuo diario.',
            'terms.accepted' => 'Per creare il diario devi accettare termini e informativa sulla privacy.',
        ];
    }
}
