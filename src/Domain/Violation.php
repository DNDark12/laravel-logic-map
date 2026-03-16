<?php

namespace dndark\LogicMap\Domain;

class Violation
{
    public function __construct(
        public string $type,
        public string $severity,
        public string $nodeId,
        public string $message,
        public array $details = [],
    ) {}

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'severity' => $this->severity,
            'node_id' => $this->nodeId,
            'message' => $this->message,
            'details' => $this->details,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'],
            severity: $data['severity'],
            nodeId: $data['node_id'],
            message: $data['message'],
            details: $data['details'] ?? [],
        );
    }
}
