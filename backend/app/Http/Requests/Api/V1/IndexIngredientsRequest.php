<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexIngredientsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->include)) {
            $this->merge([
                'include' => array_values(array_filter(array_map('trim', explode(',', $this->include)))),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:200'],
            'ingredient_category_id' => [
                'sometimes',
                'integer',
                Rule::exists('ingredient_categories', 'id'),
            ],
            'include' => ['sometimes', 'array'],
            'include.*' => [Rule::in(['categories', 'recipes_count'])],
        ];
    }
}
