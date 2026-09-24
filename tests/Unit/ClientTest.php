<?php

declare(strict_types=1);

namespace FileHutch\Tests\Unit;

use FileHutch\Client;
use FileHutch\Exceptions\ApiError;
use FileHutch\Exceptions\AuthenticationError;
use FileHutch\Exceptions\ConfigurationError;
use FileHutch\Exceptions\ConnectionError;
use FileHutch\Exceptions\FileHutchError;
use FileHutch\Exceptions\InvalidRequestError;
use FileHutch\Exceptions\InvalidStateError;
use FileHutch\Exceptions\NotFoundError;
use FileHutch\Exceptions\PermissionError;
use FileHutch\Exceptions\PlanLimitError;
use FileHutch\Exceptions\PolicyError;
use FileHutch\Exceptions\RateLimitError;
use FileHutch\Exceptions\ServerError;
use FileHutch\Exceptions\StorageError;
use FileHutch\Exceptions\TransformError;
use FileHutch\Exceptions\TransformsUnsupportedError;
use FileHutch\Exceptions\UploadError;
use FileHutch\Tests\Support\FakeHttp;
use FileHutch\Tests\Support\Fixtures as F;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    public function testRequiresAnApiKeyAndAUsableUrl(): void
    {
        $this->assertThrows(ConfigurationError::class, fn () => new Client(apiKey: '', httpClient: new FakeHttp()));
        $this->assertThrows(ConfigurationError::class, fn () => new Client(apiKey: 'fh_x', url: 'not a url', httpClient: new FakeHttp()));
    }

    public function testReadsTheKeyAndUrlFromTheEnvironment(): void
    {
        $_ENV['FILE_HUTCH_API_KEY'] = 'fh_fromenv';
        $_ENV['FILE_HUTCH_URL'] = 'https://self-hosted.test/';
        try {
            $http = new FakeHttp(['GET https://self-hosted.test/api/v1/project' => FakeHttp::json200(['project' => F::project()])]);
            (new Client(httpClient: $http))->project();
            $this->assertSame('Bearer fh_fromenv', $http->calls[0]['headers']['Authorization']);
        } finally {
            unset($_ENV['FILE_HUTCH_API_KEY'], $_ENV['FILE_HUTCH_URL']);
        }
    }

    public function testProjectMapsStoragePoliciesAndTransformsIntoCamelCase(): void
    {
        $http = new FakeHttp(['GET ' . F::BASE . '/api/v1/project' => FakeHttp::json200(['project' => F::project()])]);
        $project = F::client($http)->project();

        $this->assertTrue($project->storageReady);
        $this->assertSame('managed', $project->activeStorageConnection?->mode);
        $this->assertSame(['documents', 'avatars'], array_map(fn ($p) => $p->name, $project->uploadPolicies));
        $this->assertSame(['avatar', 'thumb'], array_map(fn ($t) => $t->name, $project->transforms));
        $this->assertSame(25_000_000, $project->uploadPolicies[0]->maximumSize);
        $this->assertSame(1_000_000_000, $project->plan['storageBytes']);
        $this->assertSame(42, $project->usage['storageBytesUsed']);
        $this->assertSame('avatars', $project->uploadPolicy('pol_avatars')?->name);
    }

    public function testTransformsListsNamedSizesWithNullsPreserved(): void
    {
        $http = new FakeHttp(['GET ' . F::BASE . '/api/v1/transforms' => FakeHttp::json200(['transforms' => F::transforms()])]);
        $thumb = F::client($http)->transforms()[1];

        $this->assertSame(400, $thumb->width);
        $this->assertNull($thumb->height);
        $this->assertSame('scale_down', $thumb->fit);
        $this->assertSame('webp', $thumb->format);
    }

    public function testFileKeepsCallerOwnedKeysVerbatimWhileMappingTheEnvelope(): void
    {
        $http = new FakeHttp(['GET ' . F::BASE . '/api/v1/files/' . F::FILE_ID => FakeHttp::json200(['file' => F::file([
            'visibility' => 'public', 'content_type' => 'image/png',
            'metadata' => ['order_id' => 'ord_1', 'customer_ref' => 'AB-9'],
            'transforms' => ['avatar' => 'https://cdn.test/a.png', 'hero_wide' => 'https://cdn.test/h.png'],
            'url' => 'https://cdn.test/me.png',
        ])])]);
        $file = F::client($http)->file(F::FILE_ID);

        $this->assertSame('image/png', $file->contentType);
        $this->assertSame(11, $file->byteSize);
        $this->assertSame('https://cdn.test/me.png', $file->url);
        // Metadata and transform names belong to the caller; snake_case stays as written.
        $this->assertSame(['order_id' => 'ord_1', 'customer_ref' => 'AB-9'], $file->metadata);
        $this->assertSame(['avatar', 'hero_wide'], array_keys($file->transforms));
        $this->assertTrue($file->isImage() && $file->isPublic() && $file->isReady());
    }

    public function testRejectsAnythingThatIsNotAFileHutchIdBeforeSendingARequest(): void
    {
        $http = new FakeHttp();
        $hutch = F::client($http);

        $this->assertThrows(ConfigurationError::class, fn () => $hutch->file('../../etc/passwd'));
        $this->assertThrows(ConfigurationError::class, fn () => $hutch->file(''));
        $this->assertSame([], $http->calls);
    }

    public function testSignedUrlSendsOnlyWhatWasAskedFor(): void
    {
        $route = 'POST ' . F::BASE . '/api/v1/files/' . F::FILE_ID . '/signed_url';
        $http = new FakeHttp([$route => FakeHttp::json200(['url' => F::STORAGE . '/x?signed=1', 'expires_at' => '2026-09-04T13:00:00.000Z'])]);
        $hutch = F::client($http);

        $result = $hutch->signedUrl(F::FILE_ID, expiresIn: 120, disposition: 'attachment');
        $this->assertSame('2026-09-04T13:00:00.000Z', $result->expiresAt);
        $this->assertSame(['expires_in' => 120, 'disposition' => 'attachment'], $http->json(0));

        $hutch->signedUrl(F::FILE_ID);
        $this->assertSame('{}', $http->calls[1]['body'], 'no options is an empty object, not an array');
    }

    public function testTransformUrlNamesATransformAndReportsNoExpiryForPublicFiles(): void
    {
        $http = new FakeHttp(['POST ' . F::BASE . '/api/v1/files/' . F::FILE_ID . '/transform_url' => FakeHttp::json200([
            'url' => F::STORAGE . '/cdn-cgi/image/width=200/x.png', 'expires_at' => null,
        ])]);
        $result = F::client($http)->transformUrl(F::FILE_ID, 'avatar');

        $this->assertNull($result->expiresAt);
        $this->assertStringContainsString('cdn-cgi/image', (string) $result);
        // The name is the whole request: no width, no format, no provider syntax.
        $this->assertSame(['transform' => 'avatar'], $http->json(0));
    }

    public function testUploadRunsTheThreeStepsAndPutsBytesStraightToStorage(): void
    {
        $http = self::uploadRoutes(headers: ['Content-Type' => 'image/png']);
        $file = F::client($http)->upload("\x01\x02\x03", policy: 'avatars', filename: 'me.png', metadata: ['order_id' => 'ord_1']);

        $this->assertSame('ready', $file->status);
        $this->assertSame([
            'POST ' . F::BASE . '/api/v1/uploads',
            'PUT ' . F::STORAGE . '/' . F::FILE_ID . '?sig=1',
            'POST ' . F::BASE . '/api/v1/uploads/' . F::FILE_ID . '/complete',
        ], $http->requested());

        $created = $http->json(0);
        $this->assertSame('image/png', $created['content_type'], 'guessed from the filename');
        $this->assertSame(3, $created['byte_size']);
        $this->assertSame(['order_id' => 'ord_1'], $created['metadata']);
        $this->assertSame(md5("\x01\x02\x03"), $created['checksum'], 'the MD5 has to describe the bytes actually sent');

        // The storage PUT carries the bucket's headers and the bytes, and no FileHutch credentials.
        $this->assertSame('image/png', $http->calls[1]['headers']['Content-Type']);
        $this->assertArrayNotHasKey('Authorization', $http->calls[1]['headers']);
        $this->assertSame("\x01\x02\x03", $http->calls[1]['body']);
    }

    public function testUploadDigestsAFileWithoutLosingItsBytesOrName(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fh');
        $bytes = random_bytes(3 * 1024 * 1024 + 7); // more than one read chunk
        file_put_contents($path, $bytes);
        try {
            $http = self::uploadRoutes();
            F::client($http)->upload(new \SplFileInfo($path), policy: 'documents', filename: 'big.pdf');

            $this->assertSame(md5($bytes), $http->json(0)['checksum']);
            $this->assertSame(strlen($bytes), $http->json(0)['byte_size']);
            $this->assertSame('application/pdf', $http->json(0)['content_type']);
            $this->assertSame(md5($bytes), md5($http->calls[1]['body']), 'the PUT sends every byte after digesting them');
        } finally {
            unlink($path);
        }
    }

    public function testUploadSpoolsANonSeekableStreamSoItCanBeDigestedAndSent(): void
    {
        $pipe = popen('printf hello', 'r');
        $http = self::uploadRoutes();
        F::client($http)->upload($pipe, policy: 'documents', filename: 'a.txt');

        $this->assertSame(md5('hello'), $http->json(0)['checksum']);
        $this->assertSame(5, $http->json(0)['byte_size']);
        $this->assertSame('hello', $http->calls[1]['body']);
    }

    public function testUploadNeedsAFilenameForBytes(): void
    {
        $http = new FakeHttp();
        $this->assertThrows(ConfigurationError::class, fn () => F::client($http)->upload('hello', policy: 'documents'));
        $this->assertSame([], $http->calls);
    }

    public function testVerificationCanBeTurnedOffAndThenNothingIsClaimed(): void
    {
        $http = self::uploadRoutes();
        F::client($http)->upload("\x01\x02\x03", policy: 'avatars', filename: 'me.png', verify: false);

        $this->assertArrayNotHasKey('checksum', $http->json(0));
    }

    public function testAStorageRejectionIsAnUploadFailureNotAControlPlaneOne(): void
    {
        $http = self::uploadRoutes();
        $http->on('PUT ' . F::STORAGE . '/' . F::FILE_ID . '?sig=1', FakeHttp::text(403, '<Error>AccessDenied</Error>'));

        $error = $this->assertThrows(UploadError::class, fn () => F::client($http)->upload('hello', policy: 'documents', filename: 'a.txt'));
        // A 403 from the bucket is not a read-only API key.
        $this->assertNotInstanceOf(PermissionError::class, $error);
        $this->assertSame('storage_rejected', $error->errorCode);
        $this->assertSame(403, $error->status);
        $this->assertStringContainsString('AccessDenied', $error->getMessage());
        $this->assertCount(2, $http->calls, 'nothing is completed after a rejected PUT');
    }

    /** @return iterable<string, array{string, int, class-string<ApiError>}> */
    public static function errorCodes(): iterable
    {
        yield 'unauthorized' => ['unauthorized', 401, AuthenticationError::class];
        yield 'not_found' => ['not_found', 404, NotFoundError::class];
        yield 'policy_violation' => ['policy_violation', 422, PolicyError::class];
        yield 'policy_not_found' => ['policy_not_found', 422, PolicyError::class];
        yield 'not_ready' => ['not_ready', 409, InvalidStateError::class];
        yield 'not_public (403, but not a permission problem)' => ['not_public', 403, InvalidStateError::class];
        yield 'transform_not_found' => ['transform_not_found', 422, TransformError::class];
        yield 'transforms_unsupported' => ['transforms_unsupported', 409, TransformsUnsupportedError::class];
        yield 'plan_limit' => ['plan_limit', 402, PlanLimitError::class];
        yield 'read_only_key' => ['read_only_key', 403, PermissionError::class];
        yield 'checksum_mismatch' => ['checksum_mismatch', 422, UploadError::class];
        yield 'storage_error' => ['storage_error', 502, StorageError::class];
        yield 'code beats status' => ['plan_limit', 422, PlanLimitError::class];
    }

    /** @param class-string<ApiError> $class */
    #[DataProvider('errorCodes')]
    public function testErrorCodesMapToTypedErrorsAheadOfStatus(string $code, int $status, string $class): void
    {
        $http = new FakeHttp(['GET ' . F::BASE . '/api/v1/files/' . F::FILE_ID => FakeHttp::json200(F::error($code, 'boom'), $status)]);
        $error = $this->assertThrows(ApiError::class, fn () => F::client($http)->file(F::FILE_ID));

        $this->assertSame($class, $error::class, "{$code} should be {$class}");
        $this->assertSame($code, $error->errorCode);
        $this->assertSame($status, $error->status);
        $this->assertSame('boom', $error->getMessage());
    }

    public function testUnknownCodesAndUnparseableBodiesStillRaiseAUsefulError(): void
    {
        $http = new FakeHttp(['GET ' . F::BASE . '/api/v1/project' => FakeHttp::json200(F::error('slow_down'), 429)]);
        $this->assertThrows(RateLimitError::class, fn () => F::client($http)->project());

        $http = new FakeHttp(['GET ' . F::BASE . '/api/v1/project' => FakeHttp::text(500, '<html>502 Bad Gateway</html>')]);
        $error = $this->assertThrows(ServerError::class, fn () => F::client($http)->project());
        $this->assertStringContainsString('HTTP 500', $error->getMessage());
        $this->assertSame('server_error', $error->errorCode);

        $http = new FakeHttp(['GET ' . F::BASE . '/api/v1/project' => FakeHttp::text(418, '')]);
        $this->assertSame(ApiError::class, $this->assertThrows(ApiError::class, fn () => F::client($http)->project())::class);
    }

    public function testDetailsFromTheApiSurviveOntoTheError(): void
    {
        $http = new FakeHttp(['GET ' . F::BASE . '/api/v1/files/' . F::FILE_ID => FakeHttp::json200(F::error('invalid', 'bad', ['filename' => ['is required']]), 422)]);
        $error = $this->assertThrows(InvalidRequestError::class, fn () => F::client($http)->file(F::FILE_ID));

        $this->assertSame(['filename' => ['is required']], $error->details);
    }

    public function testATransportFailureIsAConnectionErrorNamingTheHost(): void
    {
        $http = new FakeHttp(['GET ' . F::BASE . '/api/v1/project' => new ConnectException('cURL error 6', new Request('GET', F::BASE))]);
        $error = $this->assertThrows(ConnectionError::class, fn () => F::client($http)->project());

        $this->assertStringContainsString('filehutch.test', $error->getMessage());
        $this->assertInstanceOf(FileHutchError::class, $error);
    }

    public function testDeleteReturnsTrueAnd204BodiesDoNotBreakParsing(): void
    {
        $http = new FakeHttp(['DELETE ' . F::BASE . '/api/v1/files/' . F::FILE_ID => FakeHttp::text(204)]);
        $this->assertTrue(F::client($http)->deleteFile(F::FILE_ID));
    }

    public function testEveryRequestCarriesTheBearerTokenAndTheSdkUserAgent(): void
    {
        $http = new FakeHttp(['GET ' . F::BASE . '/api/v1/project' => FakeHttp::json200(['project' => F::project()])]);
        F::client($http, ['userAgent' => 'acme-web/2.0'])->project();

        $this->assertMatchesRegularExpression('/^Bearer fh_/', $http->calls[0]['headers']['Authorization']);
        $this->assertMatchesRegularExpression('#^filehutch-php/\d+\.\d+\.\d+ php/\S+ acme-web/2\.0$#', $http->calls[0]['headers']['User-Agent']);
    }

    public function testATrailingSlashOnTheUrlDoesNotDoubleUpInPaths(): void
    {
        $http = new FakeHttp(['GET ' . F::BASE . '/api/v1/project' => FakeHttp::json200(['project' => F::project()])]);
        F::client($http, ['url' => F::BASE . '///'])->project();

        $this->assertSame(F::BASE . '/api/v1/project', $http->calls[0]['url']);
    }

    public function testConfigPlanAndApplySendPruneExplicitly(): void
    {
        $config = ['uploads' => ['exports' => ['types' => ['text/csv'], 'max_size' => '1MB']]];
        $http = new FakeHttp([
            'POST ' . F::BASE . '/api/v1/config/plan' => FakeHttp::json200(['plan' => ['prune' => false, 'changes' => [], 'summary' => ['create' => 1]]]),
            'POST ' . F::BASE . '/api/v1/config/apply' => FakeHttp::json200(['apply' => ['prune' => true, 'results' => [], 'summary' => ['applied' => 1]]]),
        ]);
        $hutch = F::client($http);

        $this->assertSame(1, $hutch->planConfig($config)['summary']['create']);
        $this->assertSame(['config' => $config, 'prune' => false], $http->json(0));
        $hutch->applyConfig($config, prune: true);
        $this->assertTrue($http->json(1)['prune']);
    }

    public function testManifestPagesWithACursor(): void
    {
        $http = new FakeHttp(['GET ' . F::BASE . '/api/v1/manifest?after=file_x&limit=2' => FakeHttp::json200([
            'environment' => 'production', 'generated_at' => '2026-09-04T12:00:00.000Z', 'has_more' => true, 'next_after' => F::FILE_ID,
            'files' => [F::file(['storage' => ['connection_id' => 'conn_x', 'mode' => 'byo', 'provider' => 's3', 'bucket' => 'b', 'key' => 'k/1']])],
        ])]);
        $page = F::client($http)->manifest(after: 'file_x', limit: 2);

        $this->assertTrue($page->hasMore);
        $this->assertSame(F::FILE_ID, $page->nextAfter);
        $this->assertSame('k/1', $page->files[0]->storage['key']);
        $this->assertNull($page->files[0]->storage['region']);
    }

    private static function uploadRoutes(array $headers = []): FakeHttp
    {
        return new FakeHttp([
            'POST ' . F::BASE . '/api/v1/uploads' => FakeHttp::json200(F::created(F::FILE_ID, $headers), 201),
            'PUT ' . F::STORAGE . '/' . F::FILE_ID . '?sig=1' => FakeHttp::text(200),
            'POST ' . F::BASE . '/api/v1/uploads/' . F::FILE_ID . '/complete' => FakeHttp::json200(['file' => F::file(['status' => 'ready'])]),
        ]);
    }

    /**
     * @template T of \Throwable
     * @param class-string<T> $class
     * @return T
     */
    private function assertThrows(string $class, callable $fn): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e, $e->getMessage());

            return $e;
        }
        $this->fail("Expected {$class}, nothing was thrown");
    }
}
