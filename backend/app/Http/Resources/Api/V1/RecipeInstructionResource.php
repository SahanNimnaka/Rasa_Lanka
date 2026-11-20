<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecipeInstructionResource extends JsonResource
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
            'recipe_id' => $this->recipe_id,
            'step_number' => $this->step_number,
            'is_method_summary' => (bool) $this->is_method_summary,
            'cooking_method_paragraph' => $this->cooking_method_paragraph,
            'cooking_steps_ordered' => $this->cooking_steps_ordered,
            'instruction_text' => $this->instruction_text,
        ];
    }
}
