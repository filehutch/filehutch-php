<?php

declare(strict_types=1);

namespace FileHutch\Resources;

/** One ready file with where its bytes live. The export path, if you ever want to leave. */
final class ManifestEntry extends Resource
{
    /**
     * @param array<string, mixed> $metadata
     * @param array{connectionId: string, mode: string, provider: string, bucket: ?string, endpoint: ?string, region: ?string, key: string} $storage
     * @param array<string, mixed> $raw
     */
    protected function __construct(
        public readonly string $id,
        public readonly string $filename,
        public readonly string $contentType,
        public readonly int $byteSize,
        public readonly ?string $checksum,
        public readonly string $visibility,
        public readonly string $status,
        public readonly array $metadata,
        public readonly ?string $policy,
        public readonly ?string $environment,
        public readonly array $storage,
        public readonly ?string $createdAt,
        array $raw,
    ) {
        parent::__construct($raw);
    }

    public static function fromArray(array $data): static
    {
        $storage = (array) ($data['storage'] ?? []);

        return new self(
            id: (string) $data['id'],
            filename: (string) ($data['filename'] ?? ''),
            contentType: (string) ($data['content_type'] ?? ''),
            byteSize: (int) ($data['byte_size'] ?? 0),
            checksum: $data['checksum'] ?? null,
            visibility: (string) ($data['visibility'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            metadata: (array) ($data['metadata'] ?? []),
            policy: $data['policy'] ?? null,
            environment: $data['environment'] ?? null,
            storage: [
                'connectionId' => (string) ($storage['connection_id'] ?? ''),
                'mode' => (string) ($storage['mode'] ?? ''),
                'provider' => (string) ($storage['provider'] ?? ''),
                'bucket' => $storage['bucket'] ?? null,
                'endpoint' => $storage['endpoint'] ?? null,
                'region' => $storage['region'] ?? null,
                'key' => (string) ($storage['key'] ?? ''),
            ],
            createdAt: $data['created_at'] ?? null,
            raw: $data,
        );
    }
}
