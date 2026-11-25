<?php

namespace App\Http\Requests\Api\V1\AnnotationRegistries;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ImageIdLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('annotation.access') ?? false;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'image_id' => ['sometimes', 'string', 'regex:/^IMG-\d{6}$/'],
            'relative_path' => ['sometimes', 'string', 'max:512', 'regex:/^[A-Za-z0-9 _.\-()]+\/[A-Za-z0-9 _.\-()]+\.jpg$/i'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasId = $this->filled('image_id');
            $hasPath = $this->filled('relative_path');
            if ($hasId === $hasPath) {
                $validator->errors()->add('lookup', 'Provide exactly one of image_id or relative_path.');
            }
            $path = $this->input('relative_path');
            if (is_string($path) && (str_contains($path, '..') || str_contains($path, '\\') || str_starts_with($path, '/'))) {
                $validator->errors()->add('relative_path', 'The relative path is invalid.');
            }
        });
    }
}
