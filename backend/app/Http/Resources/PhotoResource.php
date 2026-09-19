<?php

namespace App\Http\Resources;

use App\Models\Photo;
use App\Services\PhotoStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Photo
 */
class PhotoResource extends JsonResource
{
    /**
     * La foto ha un attributo "data" (la data): senza questo flag Laravel lo
     * scambierebbe per il wrapper e non avvolgerebbe la risposta in "data".
     */
    public static bool $forceWrapping = true;

    private bool $withNavigation = false;

    private bool $withOriginal = false;

    /**
     * Aggiunge l'URL dell'immagine a piena risoluzione: negli elenchi si manda
     * solo la miniatura, altrimenti una schermata scarica centinaia di MB.
     */
    public function withOriginal(): static
    {
        $this->withOriginal = true;

        return $this;
    }

    /**
     * Include le foto precedente/successiva (stessa famiglia) per la navigazione.
     */
    public function withNavigation(): static
    {
        $this->withNavigation = true;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'data' => $this->data,
            'data_speciale' => $this->data_speciale,
            'didascalia' => $this->didascalia,
            'is_draft' => $this->is_draft,
            // URL firmati a scadenza: il bucket R2 resta privato.
            'thumbnail_url' => app(PhotoStorage::class)->thumbnailUrl($this->resource),
            $this->mergeWhen(
                $this->withOriginal,
                fn () => ['image_url' => app(PhotoStorage::class)->temporaryUrl($this->resource)],
            ),
            'width' => $this->width,
            'height' => $this->height,
            // Sulle rotte pubbliche non si espone chi ha caricato la foto.
            'uploaded_by' => $this->when($request->user() !== null, fn () => $this->uploaded_by),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            $this->mergeWhen($this->withNavigation, fn () => [
                'precedente' => $this->navigationLink($this->resource->previous()),
                'successiva' => $this->navigationLink($this->resource->next()),
            ]),
        ];
    }

    /**
     * @return array{id: int, data: string}|null
     */
    private function navigationLink(?Photo $photo): ?array
    {
        return $photo ? ['id' => $photo->id, 'data' => $photo->data] : null;
    }
}
