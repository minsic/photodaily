<?php

namespace App\Http\Requests;

use App\Models\Photo;
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
            'didascalia' => ['nullable', 'string', 'max:5000'],
            'data_speciale' => ['sometimes', 'boolean'],
            'is_draft' => ['sometimes', 'boolean'],
        ];
    }
}
