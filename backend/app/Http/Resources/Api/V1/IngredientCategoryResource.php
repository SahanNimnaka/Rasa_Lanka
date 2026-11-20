<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IngredientCategoryResource extends JsonResource
{
    /**
     * Responses are already wrapped by the API base controller.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'ingredients_count' => $this->whenCounted('ingredients'),
            'provenance' => $this->when(isset($this->resource->pivot), function () {
                $pivot = $this->resource->pivot;

                return [
                    'source_identifier' => $pivot->source_identifier,
                    'source_record_identifier' => $pivot->source_record_identifier,
                    'source_category_text' => $pivot->source_category_text,
                ];
            }),
        ];
    }
}
