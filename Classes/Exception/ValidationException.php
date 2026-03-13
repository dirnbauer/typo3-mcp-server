<?php

declare(strict_types=1);

namespace Hn\McpServer\Exception;

use Throwable;

/**
 * Exception for validation errors
 *
 * Thrown when input data fails validation rules.
 * Maps to HTTP 400 Bad Request status.
 */
class ValidationException extends McpException
{
    /**
     * @param list<string> $errors Array of validation error messages
     * @param Throwable|null $previous Previous exception for chaining
     */
    public function __construct(private readonly array $errors, ?Throwable $previous = null)
    {
        $errorList = implode(', ', $this->errors);

        parent::__construct(
            "Validation failed: {$errorList}",
            "Invalid input: {$errorList}",
            400,
            $previous,
            ['errors' => $this->errors],
        );
    }

    /**
     * Get the validation errors
     *
     * @return list<string> Array of validation error messages
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
