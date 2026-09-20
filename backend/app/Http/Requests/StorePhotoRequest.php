<?php

namespace App\Http\Requests;

use App\Models\Photo;
use App\Rules\CaptionLength;
use App\Support\CaptionHtml;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StorePhotoRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('create', Photo::class);
    }

    /**
     * I campi arrivano da multipart/form-data: normalizza "true"/"false" in booleani.
     */
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
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('photodaily.max_upload_kb')],
            'data' => ['required', 'date_format:Y-m-d'],
            // Il tetto in caratteri sta in CaptionLength: qui resta solo una
            // cintura di sicurezza sul peso dell'HTML sanificato.
            'didascalia' => ['nullable', 'string', 'max:20000', new CaptionLength],
            'data_speciale' => ['sometimes', 'boolean'],
            'is_draft' => ['sometimes', 'boolean'],
        ];
    }
}
