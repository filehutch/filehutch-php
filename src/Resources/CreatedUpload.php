<?php

declare(strict_types=1);

namespace FileHutch\Resources;

/** Step one of an upload: where to PUT the bytes, and the pending file they will become. */
final class CreatedUpload extends Resource
{
    /** @param array<string, mixed> $raw */
    protected function __construct(
        public readonly UploadAuthorization $upload,
        public readonly File $file,
        array $raw,
    ) {
        parent::__construct($raw);
    }

    public static function fromArray(array $data): static
    {
        return new self(UploadAuthorization::fromArray($data['upload']), File::fromArray($data['file']), $data);
    }
}
