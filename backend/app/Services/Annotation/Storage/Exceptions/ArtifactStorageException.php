<?php

namespace App\Services\Annotation\Storage\Exceptions;

use RuntimeException;

class ArtifactStorageException extends RuntimeException
{
    public function __construct(string $message = 'Annotation artifact storage error.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
