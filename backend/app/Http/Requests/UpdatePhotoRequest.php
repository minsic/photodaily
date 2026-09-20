<?php

namespace App\Http\Requests;

use App\Rules\CaptionLength;
use App\Support\CaptionHtml;
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

        if ($this->has('didascalia')) {
            $this->merge(['didascalia' => CaptionHtml::sanitize($this->string('didascalia')->toString())]);
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
            'didascalia' => ['sometimes', 'nullable', 'string', 'max:20000', new CaptionLength],
            'data_speciale' => ['sometimes', 'boolean'],
            'is_draft' => ['sometimes', 'boolean'],
        ];
    }
}
