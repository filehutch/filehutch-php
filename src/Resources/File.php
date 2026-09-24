<?php

declare(strict_types=1);

namespace FileHutch\Resources;

/** A stored file. `id` ("file_…") is the only thing your app should persist. */
final class File extends Resource
{
    /**
     * @param array<string, mixed> $metadata
     * @param array<string, string> $transforms
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
        public readonly ?string $storageConnectionId,
        /** Stable delivery URL. Present only for ready public files. */
        public readonly ?string $url,
        /** Named transform URLs keyed by name. Filled for ready public images on storage that can render. */
        public readonly array $transforms,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
        array $raw,
    ) {
        parent::__construct($raw);
    }

    public static function fromArray(array $data): static
    {
        return new self(
            id: (string) $data['id'],
            filename: (string) ($data['filename'] ?? ''),
            contentType: (string) ($data['content_type'] ?? 'application/octet-stream'),
            byteSize: (int) ($data['byte_size'] ?? 0),
            checksum: $data['checksum'] ?? null,
            visibility: (string) ($data['visibility'] ?? 'private'),
            status: (string) ($data['status'] ?? 'pending'),
            metadata: (array) ($data['metadata'] ?? []),
            policy: $data['policy'] ?? null,
            environment: $data['environment'] ?? null,
            storageConnectionId: $data['storage_connection_id'] ?? null,
            url: $data['url'] ?? null,
            transforms: (array) ($data['transforms'] ?? []),
            createdAt: $data['created_at'] ?? null,
            updatedAt: $data['updated_at'] ?? null,
            raw: $data,
        );
    }

    public function isReady(): bool { return $this->status === 'ready'; }
    public function isPending(): bool { return $this->status === 'pending'; }
    public function isFailed(): bool { return $this->status === 'failed'; }
    public function isDeleted(): bool { return $this->status === 'deleted'; }
    public function isPublic(): bool { return $this->visibility === 'public'; }
    public function isPrivate(): bool { return $this->visibility === 'private'; }
    public function isImage(): bool { return str_starts_with($this->contentType, 'image/'); }
}
