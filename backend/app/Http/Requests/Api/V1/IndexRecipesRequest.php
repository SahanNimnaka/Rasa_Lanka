<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexRecipesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:255'],
            'recipe_category_id' => [
                'sometimes',
                'integer',
                Rule::exists('recipe_categories', 'id'),
            ],
            'ingredient_id' => [
                'sometimes',
                'integer',
                Rule::exists('ingredients', 'id'),
            ],
            'recipe_type' => ['sometimes', 'string', 'max:120'],
            'region' => ['sometimes', 'string', 'max:160'],
            'sort' => [Rule::in(['name', 'created_at', 'id'])],
            'include' => [Rule::in([])],
        ];
    }
}
