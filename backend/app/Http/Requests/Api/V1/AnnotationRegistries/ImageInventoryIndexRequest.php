<?php

namespace App\Http\Requests\Api\V1\AnnotationRegistries;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ImageInventoryIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('annotation.access') ?? false;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('annotation.registry_inventory_max_per_page', 100)],
            'image_id' => ['sometimes', 'string', 'regex:/^IMG-\d{6}$/'],
            'relative_path' => ['sometimes', 'string', 'max:512', 'regex:/^[A-Za-z0-9 _.\-()]+\/[A-Za-z0-9 _.\-()]+\.jpg$/i'],
            'original_folder' => ['sometimes', 'string', 'max:255'],
            'mapping_status' => ['sometimes', 'string', 'max:64'],
            'scan_status' => ['sometimes', 'string', 'max:64'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $path = $this->input('relative_path');
            if (is_string($path) && (str_contains($path, '..') || str_contains($path, '\\') || str_starts_with($path, '/'))) {
                $validator->errors()->add('relative_path', 'The relative path is invalid.');
            }
        });
    }
}
