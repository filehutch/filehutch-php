<?php

declare(strict_types=1);

namespace FileHutch\Resources;

/**
 * The API speaks snake_case; these objects speak camelCase. Converting blindly
 * would also rewrite keys that belong to the caller (metadata, transform names,
 * storage's PUT headers), so each resource is mapped by hand and those maps pass
 * through untouched.
 *
 * `toArray()` hands back the payload exactly as the API sent it, which is what
 * a proxy (the Laravel direct-upload routes) should forward to a browser.
 */
abstract class Resource implements \JsonSerializable
{
    /** @param array<string, mixed> $raw */
    protected function __construct(private readonly array $raw)
    {
    }

    /** @param array<string, mixed> $data */
    abstract public static function fromArray(array $data): static;

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->raw;
    }

    /** @param list<mixed>|null $items @return list<mixed> */
    protected static function mapList(?array $items, callable $map): array
    {
        return array_values(array_map($map, $items ?? []));
    }
}
