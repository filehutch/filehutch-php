<?php

declare(strict_types=1);

namespace FileHutch\Resources;

/** A named image size. Your code references the name; the vocabulary is provider-neutral by design. */
final class Transform extends Resource
{
    /** @param array<string, mixed> $raw */
    protected function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?int $width,
        public readonly ?int $height,
        /** cover, contain, scale_down, crop, pad */
        public readonly string $fit,
        public readonly ?int $quality,
        /** auto, webp, avif, jpeg, png */
        public readonly ?string $format,
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
            width: isset($data['width']) ? (int) $data['width'] : null,
            height: isset($data['height']) ? (int) $data['height'] : null,
            fit: (string) ($data['fit'] ?? 'cover'),
            quality: isset($data['quality']) ? (int) $data['quality'] : null,
            format: $data['format'] ?? null,
            createdAt: $data['created_at'] ?? null,
            raw: $data,
        );
    }
}
