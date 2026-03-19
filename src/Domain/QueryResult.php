<?php

namespace dndark\LogicMap\Domain;

class QueryResult
{
    /**
     * @param array<int, ErrorItem|array<string, mixed>>|null $errors
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public bool $ok,
        public mixed $data = null,
        public ?string $message = null,
        public ?array $errors = null,
        public int $httpStatus = 200,
        public array $meta = [],
    ) {
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function success(
        mixed $data = null,
        int $httpStatus = 200,
        ?string $message = null,
        array $meta = [],
    ): self {
        return new self(
            ok: true,
            data: $data,
            message: $message,
            errors: null,
            httpStatus: $httpStatus,
            meta: $meta,
        );
    }

    /**
     * @param array<int, ErrorItem|array<string, mixed>> $errors
     * @param array<string, mixed> $meta
     */
    public static function error(
        string $message,
        int $httpStatus = 400,
        array $errors = [],
        mixed $data = null,
        array $meta = [],
    ): self {
        return new self(
            ok: false,
            data: $data,
            message: $message,
            errors: $errors,
            httpStatus: $httpStatus,
            meta: $meta,
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function typedError(
        string $type,
        string $message,
        int $httpStatus = 400,
        array $meta = [],
        mixed $data = null,
    ): self {
        return self::error(
            message: $message,
            httpStatus: $httpStatus,
            errors: [new ErrorItem($type, $message, $meta)],
            data: $data,
        );
    }

    /**
     * Add resolution metadata to array payloads without polluting controller logic.
     *
     * @param array<string, mixed> $resolution
     */
    public function withResolution(array $resolution): self
    {
        if (!is_array($this->data)) {
            return $this;
        }

        $clone = clone $this;
        $clone->data['_resolution'] = $resolution;

        return $clone;
    }

    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'data' => $this->data,
            'message' => $this->message,
            'errors' => $this->errors === null
                ? null
                : array_map(
                    fn(ErrorItem|array $error) => $error instanceof ErrorItem ? $error->toArray() : $error,
                    $this->errors
                ),
        ];
    }
}
