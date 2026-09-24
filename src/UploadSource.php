<?php

declare(strict_types=1);

namespace FileHutch;

use FileHutch\Exceptions\ConfigurationError;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Whatever the caller handed to upload(), as one seekable stream with a known
 * size and name. Internal: its shape may change between releases.
 *
 * @internal
 */
final class UploadSource
{
    private function __construct(
        private readonly StreamInterface $body,
        public readonly string $filename,
        public readonly int $byteSize,
        public readonly ?string $contentType,
    ) {
    }

    public static function from(mixed $source, ?string $filename, StreamFactoryInterface $streams): self
    {
        $contentType = null;

        if ($source instanceof \SplFileInfo) {
            // Laravel's and Symfony's UploadedFile carry the name the user picked,
            // which beats the temp file's; the MIME type is theirs to sniff.
            if (method_exists($source, 'getClientOriginalName')) {
                $filename ??= $source->getClientOriginalName();
            }
            if (method_exists($source, 'getMimeType')) {
                $contentType = self::present($source->getMimeType());
            }
            $filename ??= $source->getFilename();
            $path = $source->getRealPath();
            if ($path === false || !is_readable($path)) {
                throw new ConfigurationError("Cannot read {$source->getPathname()}.");
            }
            $stream = $streams->createStreamFromFile($path, 'rb');
        } elseif ($source instanceof StreamInterface) {
            $stream = $source;
        } elseif (is_resource($source)) {
            $stream = $streams->createStreamFromResource($source);
        } elseif (is_string($source)) {
            $stream = $streams->createStream($source);
        } else {
            throw new ConfigurationError('Upload source must be a string of bytes, a stream resource, a PSR-7 stream, or an SplFileInfo.');
        }

        if ($filename === null || trim($filename) === '') {
            throw new ConfigurationError('upload() needs a filename for bytes and streams.');
        }

        if (!$stream->isSeekable()) {
            // The bytes are read twice, once for the digest and once for the PUT,
            // so a pipe or socket is spooled to a temp stream first.
            $spooled = $streams->createStreamFromResource(fopen('php://temp', 'w+b'));
            while (!$stream->eof()) {
                $spooled->write($stream->read(1 << 20));
            }
            $stream = $spooled;
        }
        $stream->rewind();

        $size = $stream->getSize() ?? self::measure($stream);

        return new self($stream, $filename, $size, $contentType);
    }

    /** Hex MD5 of the bytes, read in chunks. */
    public function md5(): string
    {
        $this->body->rewind();
        $context = hash_init('md5');
        while (!$this->body->eof()) {
            hash_update($context, $this->body->read(1 << 20));
        }
        $this->body->rewind();

        return hash_final($context);
    }

    public function stream(): StreamInterface
    {
        $this->body->rewind();

        return $this->body;
    }

    private static function measure(StreamInterface $stream): int
    {
        $size = 0;
        while (!$stream->eof()) {
            $size += strlen($stream->read(1 << 20));
        }
        $stream->rewind();

        return $size;
    }

    private static function present(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
