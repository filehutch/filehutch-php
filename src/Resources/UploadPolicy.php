<?php

declare(strict_types=1);

namespace FileHutch\Resources;

final class UploadPolicy extends Resource
{
    /** @param list<string> $allowedContentTypes @param array<string, mixed> $raw */
    protected function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $allowedContentTypes,
        public readonly int $maximumSize,
        public readonly string $visibility,
        public readonly ?string $createdAt,
        array $raw,
    ) {
        parent::__construct($raw);
    }

    public static function fromArray(array $data): static
    {
        return new self(
            id: (string) $data['id'],
            name: (string) $data['name'],
            allowedContentTypes: array_values((array) ($data['allowed_content_types'] ?? [])),
            maximumSize: (int) ($data['maximum_size'] ?? 0),
            visibility: (string) ($data['visibility'] ?? 'private'),
            createdAt: $data['created_at'] ?? null,
            raw: $data,
        );
    }

    /** Same rule the server applies: exact match, or a `type/*` wildcard. An empty list allows anything. */
    public function allowsContentType(string $contentType): bool
    {
        if ($this->allowedContentTypes === []) {
            return true;
        }
        $type = strtolower($contentType);
        foreach ($this->allowedContentTypes as $allowed) {
            if ($allowed === $type || (str_ends_with($allowed, '/*') && str_starts_with($type, substr($allowed, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    public function allowsByteSize(int $size): bool
    {
        return $size > 0 && $size <= $this->maximumSize;
    }
}
