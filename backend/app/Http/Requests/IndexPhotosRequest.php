<?php

namespace App\Http\Requests;

use App\Models\Photo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Applica anno, speciali e ordinamento. Quali foto siano visibili (bozze
     * comprese o no) lo decide il chiamante: sulle rotte pubbliche le bozze
     * sono sempre escluse, qualunque cosa arrivi in query string.
     *
     * @param  Builder<Photo>  $query
     * @return Builder<Photo>
     */
    public function applyFilters(Builder $query): Builder
    {
        $ordine = $this->input('ordine', 'desc');

        return $query
            ->when($this->filled('anno'), function (Builder $query) {
                $anno = $this->integer('anno');

                $query->whereBetween('data', ["{$anno}-01-01", "{$anno}-12-31"]);
            })
            ->when($this->boolean('speciali'), fn (Builder $query) => $query->where('data_speciale', true))
            ->orderBy('data', $ordine)
            ->orderBy('id', $ordine);
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 50);
    }
}
