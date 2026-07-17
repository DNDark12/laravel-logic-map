<?php

namespace DNDark\LogicMap\Domain\Snapshot;

use DNDark\LogicMap\Support\RelativePath;
use InvalidArgumentException;

final readonly class Diagnostic
{
    private const MESSAGE_MAX_LENGTH = 2000;

    public ?string $file;

    public string $message;

    public function __construct(
        public DiagnosticCode $code,
        public string $phase,
        ?string $file,
        public ?int $startLine,
        public ?int $endLine,
        string $message,
        public array $attributes = [],
    ) {
        if (trim($phase) === '') {
            throw new InvalidArgumentException('Diagnostic phase is required.');
        }

        if (trim($message) === '') {
            throw new InvalidArgumentException('Diagnostic message is required.');
        }

        $this->file = $file === null ? null : RelativePath::normalize($file);
        $this->message = substr($message, 0, self::MESSAGE_MAX_LENGTH);

        if (($startLine === null) !== ($endLine === null)) {
            throw new InvalidArgumentException('Diagnostic spans require both start and end lines.');
        }

        if ($this->file === null && ($startLine !== null || $endLine !== null)) {
            throw new InvalidArgumentException('Diagnostic line spans require a file.');
        }

        if ($startLine !== null && ($startLine < 1 || $endLine < $startLine)) {
            throw new InvalidArgumentException('Diagnostic line spans must be valid.');
        }
    }

    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'phase' => $this->phase,
            'file' => $this->file,
            'start_line' => $this->startLine,
            'end_line' => $this->endLine,
            'message' => $this->message,
            'attributes' => $this->attributes,
        ];
    }
}
