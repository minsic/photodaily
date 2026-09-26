<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MonthRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'anno' => ['required', 'integer', 'between:1900,2100'],
            'mese' => ['required', 'integer', 'between:1,12'],
        ];
    }
}
