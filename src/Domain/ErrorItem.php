<?php

namespace dndark\LogicMap\Domain;

class ErrorItem
{
    public function __construct(
        public string $type,
        public string $detail,
        public array $meta = [],
    ) {
    }

    public function toArray(): array
    {
        $payload = [
            'type' => $this->type,
            'detail' => $this->detail,
        ];

        if ($this->meta !== []) {
            $payload['meta'] = $this->meta;
        }

        return $payload;
    }
}
