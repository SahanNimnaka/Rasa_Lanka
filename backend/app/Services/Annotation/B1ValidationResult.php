<?php

namespace App\Services\Annotation;

class B1ValidationResult
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function __construct(
        public bool $ok,
        public int $httpStatus,
        public array $payload,
    ) {
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function success(array $payload): self
    {
        return new self(true, 200, $payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function infrastructureFailure(array $payload): self
    {
        return new self(false, 503, $payload);
    }
}
