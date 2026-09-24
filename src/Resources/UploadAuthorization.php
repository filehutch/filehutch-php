<?php

declare(strict_types=1);

namespace FileHutch\Resources;

/** Everything a client needs to PUT bytes straight to storage. */
final class UploadAuthorization extends Resource
{
    /** @param array<string, string> $headers @param array<string, mixed> $raw */
    protected function __construct(
        public readonly string $id,
        public readonly string $fileId,
        public readonly string $method,
        public readonly string $url,
        /** Storage's own header names; never rewritten. */
        public readonly array $headers,
        public readonly ?string $expiresAt,
        array $raw,
    ) {
        parent::__construct($raw);
    }

    public static function fromArray(array $data): static
    {
        return new self(
            id: (string) $data['id'],
            fileId: (string) ($data['file_id'] ?? $data['id']),
            method: (string) ($data['method'] ?? 'PUT'),
            url: (string) $data['url'],
            headers: array_map('strval', (array) ($data['headers'] ?? [])),
            expiresAt: $data['expires_at'] ?? null,
            raw: $data,
        );
    }
}
