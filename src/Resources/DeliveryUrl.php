<?php

declare(strict_types=1);

namespace FileHutch\Resources;

/** `expiresAt` is null for public files, which are delivered from a stable URL. */
final class DeliveryUrl extends Resource implements \Stringable
{
    /** @param array<string, mixed> $raw */
    protected function __construct(
        public readonly string $url,
        public readonly ?string $expiresAt,
        array $raw,
    ) {
        parent::__construct($raw);
    }

    public static function fromArray(array $data): static
    {
        return new self((string) $data['url'], $data['expires_at'] ?? null, $data);
    }

    public function __toString(): string
    {
        return $this->url;
    }
}
