<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShowIngredientRequest extends FormRequest
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
            'include' => ['sometimes', 'array'],
            'include.*' => [Rule::in(['categories', 'recipe_ingredients', 'data_quality_issues'])],
        ];
    }
}
