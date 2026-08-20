<?php

namespace Imel\Api;

use RuntimeException;

/** Exception carrying the HTTP status the client should receive. */
final class ApiException extends RuntimeException
{
    public function __construct(string $message, private int $status = 400, private array $extra = [])
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function extra(): array
    {
        return $this->extra;
    }

    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return new self($message, 401);
    }

    public static function notFound(string $message = 'Not found'): self
    {
        return new self($message, 404);
    }

    public static function validation(string $message, array $fields = []): self
    {
        return new self($message, 422, $fields ? ['fields' => $fields] : []);
    }
}
