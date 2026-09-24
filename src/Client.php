<?php

declare(strict_types=1);

namespace FileHutch;

use FileHutch\Exceptions\ApiError;
use FileHutch\Exceptions\ConfigurationError;
use FileHutch\Exceptions\ConnectionError;
use FileHutch\Resources\CreatedUpload;
use FileHutch\Resources\DeliveryUrl;
use FileHutch\Resources\File;
use FileHutch\Resources\ManifestPage;
use FileHutch\Resources\Project;
use FileHutch\Resources\Transform;
use FileHutch\Resources\UploadAuthorization;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Server-side client for the FileHutch v1 API.
 *
 * The API key is project-scoped and must stay on your server. Browsers upload
 * through your own endpoints, which call this client — see the README.
 *
 *   $hutch = new FileHutch\Client(apiKey: getenv('FILE_HUTCH_API_KEY'));
 *   $file = $hutch->upload($bytes, policy: 'avatars', filename: 'me.png');
 *   $file->id; // "file_8fK2…" — the only thing you store
 */
final class Client
{
    public const VERSION = '0.1.0';
    public const DEFAULT_URL = 'https://api.filehutch.com';
    private const ID_PATTERN = '/\A[a-z]+_[0-9A-Za-z]{20}\z/';

    public readonly string $url;
    private readonly string $apiKey;
    private readonly string $userAgent;
    private readonly ClientInterface $http;
    private readonly RequestFactoryInterface $requests;
    private readonly StreamFactoryInterface $streams;

    /**
     * @param string|null $apiKey Project-scoped key from Dashboard → API keys. Defaults to FILE_HUTCH_API_KEY.
     * @param string|null $url Defaults to FILE_HUTCH_URL, then https://api.filehutch.com.
     * @param float $timeout Seconds. Applied when the SDK builds its own Guzzle client; a client you pass keeps its own.
     * @param ClientInterface|null $httpClient Any PSR-18 client (tests, proxies, instrumentation).
     * @param string|null $userAgent Appended to the SDK's own User-Agent.
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $url = null,
        public readonly float $timeout = 30.0,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?string $userAgent = null,
    ) {
        $apiKey = self::present($apiKey) ?? self::env('FILE_HUTCH_API_KEY');
        if ($apiKey === null) {
            throw new ConfigurationError('No API key. Pass apiKey, or set FILE_HUTCH_API_KEY.');
        }
        $url = self::present($url) ?? self::env('FILE_HUTCH_URL') ?? self::DEFAULT_URL;
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new ConfigurationError("{$url} is not a valid URL.");
        }

        $this->apiKey = $apiKey;
        $this->url = rtrim($url, '/');
        $this->userAgent = trim('filehutch-php/' . self::VERSION . ' php/' . PHP_VERSION . ' ' . ($userAgent ?? ''));
        $this->http = $httpClient ?? self::defaultHttpClient($timeout);
        $this->requests = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streams = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    // -- Resources ----------------------------------------------------------------

    public function project(): Project
    {
        return Project::fromArray($this->request('GET', '/api/v1/project')['project']);
    }

    /** @return list<Transform> The project's named image sizes. Your code references them by name. */
    public function transforms(): array
    {
        $body = $this->request('GET', '/api/v1/transforms');

        return array_values(array_map(Transform::fromArray(...), $body['transforms'] ?? []));
    }

    // -- Declarative config -------------------------------------------------------
    // The file format is the API's; keys stay snake_case because people write them.

    /** @return array<string, mixed> The project as a config: uploads, transforms, environments. */
    public function config(): array
    {
        return $this->request('GET', '/api/v1/config')['config'];
    }

    /**
     * What applyConfig would change. Touches nothing; a read-only key is enough.
     *
     * @param array<string, mixed> $config
     * @return array{prune: bool, changes: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function planConfig(array $config, bool $prune = false): array
    {
        return $this->request('POST', '/api/v1/config/plan', ['config' => $config, 'prune' => $prune])['plan'];
    }

    /**
     * Make the project match the config, one change at a time. Nothing is deleted unless $prune.
     *
     * @param array<string, mixed> $config
     * @return array{prune: bool, results: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function applyConfig(array $config, bool $prune = false): array
    {
        return $this->request('POST', '/api/v1/config/apply', ['config' => $config, 'prune' => $prune])['apply'];
    }

    /** One page of the export: every ready file in this key's environment with its object key. */
    public function manifest(?string $after = null, ?int $limit = null): ManifestPage
    {
        $query = http_build_query(array_filter(['after' => $after, 'limit' => $limit], static fn ($v) => $v !== null && $v !== ''));

        return ManifestPage::fromArray($this->request('GET', '/api/v1/manifest' . ($query === '' ? '' : "?{$query}")));
    }

    // -- Files --------------------------------------------------------------------

    public function file(string $id): File
    {
        return File::fromArray($this->request('GET', '/api/v1/files/' . $this->pathId($id))['file']);
    }

    /**
     * Step one: ask for somewhere to PUT the bytes.
     *
     * @param array<string, mixed> $metadata
     * @param string|null $checksum MD5 of the bytes (hex). FileHutch refuses the upload if what arrives differs.
     */
    public function createUpload(
        string $policy,
        string $filename,
        string $contentType,
        int $byteSize,
        array $metadata = [],
        ?string $checksum = null,
    ): CreatedUpload {
        $body = ['policy' => $policy, 'filename' => $filename, 'content_type' => $contentType, 'byte_size' => $byteSize];
        if ($checksum !== null && $checksum !== '') {
            $body['checksum'] = $checksum;
        }
        if ($metadata !== []) {
            $body['metadata'] = $metadata;
        }

        return CreatedUpload::fromArray($this->request('POST', '/api/v1/uploads', $body));
    }

    /** Step three: FileHutch checks what landed in storage and marks the file ready. */
    public function completeUpload(string $id): File
    {
        return File::fromArray($this->request('POST', '/api/v1/uploads/' . $this->pathId($id) . '/complete')['file']);
    }

    /**
     * Short-lived URL that works for private and public files alike.
     *
     * @param string|null $disposition "inline" or "attachment"
     */
    public function signedUrl(string $id, ?int $expiresIn = null, ?string $disposition = null): DeliveryUrl
    {
        $body = array_filter(['expires_in' => $expiresIn, 'disposition' => $disposition], static fn ($v) => $v !== null && $v !== '');
        $response = $this->request('POST', '/api/v1/files/' . $this->pathId($id) . '/signed_url', (object) $body);

        return DeliveryUrl::fromArray($response);
    }

    /**
     * URL for one named transform. `expiresAt` is null for public files, which are
     * delivered from a stable URL. Public images already carry their transform URLs
     * on the file payload, so prefer `$file->transforms[$name]` when you have the file.
     */
    public function transformUrl(string $id, string $transform, ?int $expiresIn = null): DeliveryUrl
    {
        $body = ['transform' => $transform];
        if ($expiresIn !== null) {
            $body['expires_in'] = $expiresIn;
        }

        return DeliveryUrl::fromArray($this->request('POST', '/api/v1/files/' . $this->pathId($id) . '/transform_url', $body));
    }

    /** Deletes the bytes. The id keeps resolving, with status "deleted". */
    public function deleteFile(string $id): bool
    {
        $this->request('DELETE', '/api/v1/files/' . $this->pathId($id));

        return true;
    }

    // -- The whole upload flow ------------------------------------------------------

    /**
     * Request an upload, PUT the bytes straight to storage, and complete it.
     * The bytes never pass through FileHutch's control plane.
     *
     * Sends an MD5 so FileHutch refuses the upload if what arrives is not what
     * left. Pass `verify: false` to skip it, and only the byte count is checked.
     * Files and streams are digested in chunks, never read whole into memory.
     *
     * @param string|resource|StreamInterface|\SplFileInfo $source Raw bytes, an open stream, or a file
     *        (an \SplFileInfo, which includes Laravel's and Symfony's UploadedFile).
     * @param string|null $filename Required for bytes and streams; a file's own name otherwise.
     * @param array<string, mixed> $metadata
     */
    public function upload(
        mixed $source,
        string $policy,
        ?string $filename = null,
        ?string $contentType = null,
        array $metadata = [],
        bool $verify = true,
    ): File {
        $body = UploadSource::from($source, $filename, $this->streams);
        $contentType ??= $body->contentType ?? ContentType::guess($body->filename);

        $created = $this->createUpload(
            policy: $policy,
            filename: $body->filename,
            contentType: $contentType,
            byteSize: $body->byteSize,
            metadata: $metadata,
            checksum: $verify ? $body->md5() : null,
        );
        $this->putToStorage($created->upload, $body->stream());

        return $this->completeUpload($created->upload->fileId);
    }

    /** PUTs bytes to the storage URL in an authorization. Never touches a FileHutch endpoint. */
    public function putToStorage(UploadAuthorization $upload, StreamInterface|string $body): bool
    {
        $request = $this->requests->createRequest($upload->method, $upload->url)
            ->withBody(is_string($body) ? $this->streams->createStream($body) : $body);
        foreach ($upload->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new ConnectionError('Could not reach storage at ' . self::hostOf($upload->url) . ': ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $detail = substr((string) $response->getBody(), 0, 500);
            throw ApiError::fromResponse($status, ['error' => [
                'code' => 'storage_rejected',
                'message' => trim("Storage rejected the upload: HTTP {$status} {$detail}"),
            ]]);
        }

        return true;
    }

    // -- Transport ----------------------------------------------------------------

    /**
     * One authenticated call to the API. Returns the decoded body, or null for 204.
     *
     * @param array<string, mixed>|object|null $body
     * @return array<string, mixed>|null
     */
    public function request(string $method, string $path, array|object|null $body = null): ?array
    {
        $target = $this->url . $path;
        $request = $this->requests->createRequest($method, $target)
            ->withHeader('Authorization', "Bearer {$this->apiKey}")
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', $this->userAgent);
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')
                ->withBody($this->streams->createStream(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
        }

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new ConnectionError('Could not reach ' . self::hostOf($target) . ': ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        if ($status === 204) {
            return null;
        }
        $text = (string) $response->getBody();
        $payload = $text === '' ? null : json_decode($text, true);
        if ($status < 200 || $status >= 300) {
            throw ApiError::fromResponse($status, $payload);
        }

        return is_array($payload) ? $payload : null;
    }

    /** Whether a string has the shape of a FileHutch id. Says nothing about whether it exists. */
    public static function isId(mixed $value): bool
    {
        return is_string($value) && preg_match(self::ID_PATTERN, $value) === 1;
    }

    private function pathId(string $id): string
    {
        if (!self::isId($id)) {
            throw new ConfigurationError('Expected a FileHutch id, got ' . json_encode($id));
        }

        return rawurlencode($id);
    }

    private static function defaultHttpClient(float $timeout): ClientInterface
    {
        // PSR-18 has no timeout, so the one client we can configure is the one we
        // build. Guzzle is what Laravel ships; anything else comes from discovery.
        if (class_exists(\GuzzleHttp\Client::class)) {
            return new \GuzzleHttp\Client(['timeout' => $timeout, 'connect_timeout' => min($timeout, 10.0), 'http_errors' => false]);
        }

        return Psr18ClientDiscovery::find();
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) ? self::present($value) : null;
    }

    private static function present(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }

    private static function hostOf(string $url): string
    {
        return parse_url($url, PHP_URL_HOST) ?: $url;
    }
}
