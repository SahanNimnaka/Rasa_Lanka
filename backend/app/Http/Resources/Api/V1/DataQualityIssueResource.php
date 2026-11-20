<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DataQualityIssueResource extends JsonResource
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
            'source_file' => $this->source_file,
            'source_row' => $this->source_row,
            'entity_type' => $this->entity_type,
            'entity_reference' => $this->entity_reference,
            'field_name' => $this->field_name,
            'severity' => $this->severity,
            'issue_type' => $this->issue_type,
            'description' => $this->description,
            'recommended_action' => $this->recommended_action,
            'resolution_status' => $this->resolution_status,
            'recipe_id' => $this->recipe_id,
            'ingredient_id' => $this->ingredient_id,
        ];
    }
}
