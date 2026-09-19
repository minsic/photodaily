<?php

namespace App\Http\Requests;

use App\Enums\AccessMode;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateAccessModeRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('updateAccessMode', $this->user()->family);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'access_mode' => ['required', Rule::enum(AccessMode::class)],
            // Serve solo per la modalità "password"; negli altri casi viene ignorata.
            'password' => ['exclude_unless:access_mode,password', 'required', 'string', 'min:8', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.required' => 'La modalità "password" richiede una password condivisa di almeno 8 caratteri.',
            'password.min' => 'La password condivisa deve avere almeno 8 caratteri.',
        ];
    }
}
