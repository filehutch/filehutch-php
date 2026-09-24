<?php

declare(strict_types=1);

namespace FileHutch\Laravel\Facades;

use FileHutch\Client;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \FileHutch\Resources\Project project()
 * @method static list<\FileHutch\Resources\Transform> transforms()
 * @method static \FileHutch\Resources\File file(string $id)
 * @method static \FileHutch\Resources\File upload(mixed $source, string $policy, ?string $filename = null, ?string $contentType = null, array $metadata = [], bool $verify = true)
 * @method static \FileHutch\Resources\CreatedUpload createUpload(string $policy, string $filename, string $contentType, int $byteSize, array $metadata = [], ?string $checksum = null)
 * @method static \FileHutch\Resources\File completeUpload(string $id)
 * @method static \FileHutch\Resources\DeliveryUrl signedUrl(string $id, ?int $expiresIn = null, ?string $disposition = null)
 * @method static \FileHutch\Resources\DeliveryUrl transformUrl(string $id, string $transform, ?int $expiresIn = null)
 * @method static bool deleteFile(string $id)
 * @method static array config()
 * @method static array planConfig(array $config, bool $prune = false)
 * @method static array applyConfig(array $config, bool $prune = false)
 * @method static \FileHutch\Resources\ManifestPage manifest(?string $after = null, ?int $limit = null)
 *
 * @see Client
 */
final class FileHutch extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
