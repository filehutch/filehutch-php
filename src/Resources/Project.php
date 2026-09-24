<?php

declare(strict_types=1);

namespace FileHutch\Resources;

final class Project extends Resource
{
    /**
     * @param array{id: string, name: string}|null $environment
     * @param list<string> $environments
     * @param list<UploadPolicy> $uploadPolicies
     * @param list<Transform> $transforms
     * @param array{key: string, name: string, storageBytes: int, projectLimit: ?int}|null $plan
     * @param array{storageBytesUsed: int, projectsUsed: int}|null $usage
     * @param array<string, mixed> $raw
     */
    protected function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $teamId,
        /** The environment the API key is scoped to. */
        public readonly ?array $environment,
        public readonly array $environments,
        public readonly bool $storageReady,
        public readonly ?StorageConnection $activeStorageConnection,
        public readonly array $uploadPolicies,
        public readonly array $transforms,
        public readonly ?array $plan,
        public readonly ?array $usage,
        public readonly ?string $createdAt,
        array $raw,
    ) {
        parent::__construct($raw);
    }

    public static function fromArray(array $data): static
    {
        $plan = $data['plan'] ?? null;
        $usage = $data['usage'] ?? null;
        $environment = $data['environment'] ?? null;

        return new self(
            id: (string) $data['id'],
            name: (string) ($data['name'] ?? ''),
            teamId: $data['team_id'] ?? null,
            environment: is_array($environment) ? ['id' => (string) $environment['id'], 'name' => (string) $environment['name']] : null,
            environments: array_values((array) ($data['environments'] ?? [])),
            storageReady: ($data['storage_ready'] ?? false) === true,
            activeStorageConnection: is_array($data['active_storage_connection'] ?? null)
                ? StorageConnection::fromArray($data['active_storage_connection'])
                : null,
            uploadPolicies: self::mapList($data['upload_policies'] ?? null, UploadPolicy::fromArray(...)),
            transforms: self::mapList($data['transforms'] ?? null, Transform::fromArray(...)),
            plan: is_array($plan) ? [
                'key' => (string) $plan['key'],
                'name' => (string) $plan['name'],
                'storageBytes' => (int) $plan['storage_bytes'],
                'projectLimit' => isset($plan['project_limit']) ? (int) $plan['project_limit'] : null,
            ] : null,
            usage: is_array($usage) ? [
                'storageBytesUsed' => (int) $usage['storage_bytes_used'],
                'projectsUsed' => (int) $usage['projects_used'],
            ] : null,
            createdAt: $data['created_at'] ?? null,
            raw: $data,
        );
    }

    public function uploadPolicy(string $name): ?UploadPolicy
    {
        foreach ($this->uploadPolicies as $policy) {
            if ($policy->name === $name || $policy->id === $name) {
                return $policy;
            }
        }

        return null;
    }

    public function transform(string $name): ?Transform
    {
        foreach ($this->transforms as $transform) {
            if ($transform->name === $name || $transform->id === $name) {
                return $transform;
            }
        }

        return null;
    }
}
