<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class B1ValidationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('annotation.validate') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'record' => ['required', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $record = $this->input('record');
            if (! is_array($record)) {
                return;
            }
            $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded !== false && strlen($encoded) > 1048576) {
                $validator->errors()->add('record', 'The record payload is too large.');
            }
        });
    }
}
