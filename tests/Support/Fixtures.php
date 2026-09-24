<?php

declare(strict_types=1);

namespace FileHutch\Tests\Support;

use FileHutch\Client;

final class Fixtures
{
    public const BASE = 'https://filehutch.test';
    public const STORAGE = 'https://bucket.storage.test';
    public const FILE_ID = 'file_abcdefghij0123456789';
    public const OTHER_ID = 'file_zyxwvutsrq9876543210';
    public const API_KEY = 'fh_testTESTtestTESTtestTESTtestTESTtestTEST';

    public static function client(FakeHttp $http, array $options = []): Client
    {
        return new Client(...array_merge(['apiKey' => self::API_KEY, 'url' => self::BASE, 'httpClient' => $http], $options));
    }

    /** @return array<string, mixed> */
    public static function file(array $overrides = []): array
    {
        return array_merge([
            'id' => self::FILE_ID, 'object' => 'file', 'filename' => 'report.pdf', 'content_type' => 'application/pdf',
            'byte_size' => 11, 'checksum' => 'md5:abc', 'visibility' => 'private', 'status' => 'ready',
            'metadata' => [], 'policy' => 'documents', 'environment' => 'production', 'storage_connection_id' => 'conn_x',
            'url' => null, 'transforms' => [],
            'created_at' => '2026-09-04T12:00:00.000Z', 'updated_at' => '2026-09-04T12:00:01.000Z',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    public static function created(string $id = self::FILE_ID, array $headers = []): array
    {
        return [
            'upload' => [
                'id' => $id, 'object' => 'upload', 'file_id' => $id, 'method' => 'PUT',
                'url' => self::STORAGE . "/{$id}?sig=1", 'headers' => $headers, 'expires_at' => '2099-01-01T00:00:00.000Z',
            ],
            'file' => self::file(['id' => $id, 'status' => 'pending']),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function transforms(): array
    {
        return [
            ['id' => 'trn_avatar', 'object' => 'transform', 'name' => 'avatar', 'width' => 200, 'height' => 200, 'fit' => 'cover', 'quality' => null, 'format' => 'auto', 'created_at' => '2026-09-01T00:00:00.000Z'],
            ['id' => 'trn_thumb', 'object' => 'transform', 'name' => 'thumb', 'width' => 400, 'height' => null, 'fit' => 'scale_down', 'quality' => 80, 'format' => 'webp', 'created_at' => '2026-09-01T00:00:00.000Z'],
        ];
    }

    /** @return array<string, mixed> */
    public static function project(): array
    {
        return [
            'id' => 'proj_x', 'object' => 'project', 'name' => 'Demo', 'team_id' => 'team_x', 'storage_ready' => true,
            'environment' => ['id' => 'env_x', 'name' => 'production'], 'environments' => ['production'],
            'active_storage_connection' => ['id' => 'conn_x', 'object' => 'storage_connection', 'name' => 'Managed', 'mode' => 'managed', 'provider' => 'cloudflare_r2', 'status' => 'verified'],
            'upload_policies' => [
                ['id' => 'pol_docs', 'object' => 'upload_policy', 'name' => 'documents', 'allowed_content_types' => ['application/pdf'], 'maximum_size' => 25_000_000, 'visibility' => 'private', 'created_at' => '2026-09-01T00:00:00.000Z'],
                ['id' => 'pol_avatars', 'object' => 'upload_policy', 'name' => 'avatars', 'allowed_content_types' => ['image/*'], 'maximum_size' => 1_000, 'visibility' => 'public', 'created_at' => '2026-09-01T00:00:00.000Z'],
            ],
            'transforms' => self::transforms(),
            'plan' => ['key' => 'free', 'name' => 'Free', 'storage_bytes' => 1_000_000_000, 'project_limit' => 1],
            'usage' => ['storage_bytes_used' => 42, 'projects_used' => 1],
            'created_at' => '2026-09-01T00:00:00.000Z',
        ];
    }

    /** @return array<string, mixed> */
    public static function error(string $code, string $message = 'nope', mixed $details = null): array
    {
        return ['error' => array_filter(['code' => $code, 'message' => $message, 'details' => $details], static fn ($v) => $v !== null)];
    }
}
