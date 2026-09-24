<?php

declare(strict_types=1);

namespace FileHutch\Resources;

final class StorageConnection extends Resource
{
    /** @param array<string, mixed> $raw */
    protected function __construct(
        public readonly string $id,
        public readonly string $name,
        /** managed or byo */
        public readonly string $mode,
        public readonly string $provider,
        /** unverified, verified or failed */
        public readonly string $status,
        array $raw,
    ) {
        parent::__construct($raw);
    }

    public static function fromArray(array $data): static
    {
        return new self(
            id: (string) $data['id'],
            name: (string) ($data['name'] ?? ''),
            mode: (string) ($data['mode'] ?? ''),
            provider: (string) ($data['provider'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            raw: $data,
        );
    }
}
