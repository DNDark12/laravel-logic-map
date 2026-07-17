<?php

namespace DNDark\LogicMap\Services\Query;

use Illuminate\Http\JsonResponse;

final readonly class ApiResult
{
    private function __construct(
        private bool $ok,
        private mixed $data,
        private ?string $message,
        private ?array $errors,
        private array $meta,
        private int $status,
    ) {
    }

    public static function success(mixed $data, array $meta = [], ?string $message = null): self
    {
        return new self(true, $data, $message, null, $meta, 200);
    }

    public static function failure(string $message, array $errors, int $status, array $meta = []): self
    {
        return new self(false, null, $message, $errors, $meta, $status);
    }

    public function toResponse(): JsonResponse
    {
        return response()->json([
            'ok' => $this->ok,
            'data' => $this->data,
            'message' => $this->message,
            'errors' => $this->errors,
            'meta' => $this->meta === [] ? (object) [] : $this->meta,
        ], $this->status);
    }
}
