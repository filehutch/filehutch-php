<?php

declare(strict_types=1);

namespace FileHutch\Laravel;

use FileHutch\Client;
use FileHutch\Exceptions\FileHutchError;
use FileHutch\Resources\UploadPolicy;

/**
 * Upload policies, fetched once per process for HasHutch's local checks. A miss
 * or an outage returns null and the local check is skipped: the server enforces
 * the policy either way, this only saves a round trip.
 *
 * @internal
 */
final class Policies
{
    /** @var array<string, UploadPolicy|null> */
    private static array $cache = [];

    public static function find(Client $client, string $name): ?UploadPolicy
    {
        if (array_key_exists($name, self::$cache)) {
            return self::$cache[$name];
        }

        try {
            $project = $client->project();
        } catch (FileHutchError $e) {
            if (function_exists('logger')) {
                logger()->warning("[filehutch] could not load upload policies: {$e->getMessage()}");
            }

            return null;
        }
        foreach ($project->uploadPolicies as $policy) {
            self::$cache[$policy->name] = $policy;
        }

        return self::$cache[$name] ??= null;
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
