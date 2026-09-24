<?php

declare(strict_types=1);

namespace FileHutch\Resources;

final class ManifestPage extends Resource
{
    /** @param list<ManifestEntry> $files @param array<string, mixed> $raw */
    protected function __construct(
        public readonly ?string $environment,
        public readonly ?string $generatedAt,
        public readonly array $files,
        public readonly bool $hasMore,
        public readonly ?string $nextAfter,
        array $raw,
    ) {
        parent::__construct($raw);
    }

    public static function fromArray(array $data): static
    {
        return new self(
            environment: $data['environment'] ?? null,
            generatedAt: $data['generated_at'] ?? null,
            files: self::mapList($data['files'] ?? null, ManifestEntry::fromArray(...)),
            hasMore: ($data['has_more'] ?? false) === true,
            nextAfter: $data['next_after'] ?? null,
            raw: $data,
        );
    }
}
