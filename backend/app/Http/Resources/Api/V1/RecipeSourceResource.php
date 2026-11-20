<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecipeSourceResource extends JsonResource
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
            'source_name' => $this->source_name,
            'source_identifier' => $this->source_identifier,
            'source_record_identifier' => $this->source_record_identifier,
            'source_location' => $this->source_location,
            'source_uri' => $this->source_uri,
            'attribution' => $this->attribution,
            'notes' => $this->notes,
        ];
    }
}
