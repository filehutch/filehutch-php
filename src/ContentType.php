<?php

declare(strict_types=1);

namespace FileHutch;

/** The same extension table as the TypeScript SDK, so both guess alike. */
final class ContentType
{
    private const BY_EXTENSION = [
        'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'svg' => 'image/svg+xml',
        'heic' => 'image/heic', 'txt' => 'text/plain', 'csv' => 'text/csv', 'json' => 'application/json',
        'zip' => 'application/zip', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg',
        'doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    public static function guess(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return self::BY_EXTENSION[$extension] ?? 'application/octet-stream';
    }
}
