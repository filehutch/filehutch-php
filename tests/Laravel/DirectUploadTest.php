<?php

declare(strict_types=1);

namespace FileHutch\Tests\Laravel;

use FileHutch\Laravel\DirectUploads;
use FileHutch\Tests\Support\FakeHttp;
use FileHutch\Tests\Support\Fixtures as F;
use Illuminate\Http\Request;

final class DirectUploadTest extends TestCase
{
    private const API = F::BASE . '/api/v1';
    private const PARAMS = ['policy' => 'avatars', 'filename' => 'me.png', 'content_type' => 'image/png', 'byte_size' => 3];

    public function testClosedUntilAnAuthorizerIsSet(): void
    {
        $this->postJson('/file_hutch/uploads', self::PARAMS)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden')
            ->assertJsonPath('error.message', fn (string $m) => str_contains($m, 'DirectUploads::authorize'));

        $this->postJson('/file_hutch/uploads/' . F::FILE_ID . '/complete')->assertStatus(403);
        $this->assertSame([], $this->http->calls);
    }

    public function testCreateProxiesToFileHutchAndHandsTheBrowserOnlyTheUpload(): void
    {
        $seen = [];
        DirectUploads::authorize(function (Request $request, string $policy) use (&$seen) {
            $seen[] = $policy;

            return true;
        });
        $this->http->on('POST ' . self::API . '/uploads', FakeHttp::json200(F::created(F::FILE_ID, ['Content-Type' => 'image/png']), 201));

        $response = $this->postJson('/file_hutch/uploads', self::PARAMS + ['metadata' => ['admin' => true]]);

        $response->assertStatus(201)
            ->assertJsonPath('upload.file_id', F::FILE_ID)
            ->assertJsonPath('upload.url', F::STORAGE . '/' . F::FILE_ID . '?sig=1')
            ->assertJsonPath('upload.headers', ['Content-Type' => 'image/png'])
            ->assertJsonPath('file.status', 'pending');
        $this->assertSame(['avatars'], $seen);
        $this->assertStringNotContainsString(F::API_KEY, $response->getContent());
        $this->assertSame(self::PARAMS, $this->http->json(0), 'the browser cannot set metadata or anything else');
    }

    public function testMissingParamsAre422WithoutACallToFileHutch(): void
    {
        DirectUploads::authorize(fn () => true);

        $this->postJson('/file_hutch/uploads', ['policy' => 'avatars'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid');
        $this->assertSame([], $this->http->calls);
    }

    public function testAnAuthorizerThatSaysNoIsA403(): void
    {
        DirectUploads::authorize(fn (Request $request, string $policy) => $policy === 'documents');

        $this->postJson('/file_hutch/uploads', self::PARAMS)->assertStatus(403)->assertJsonPath('error.message', 'Not allowed to upload here');
        $this->assertSame([], $this->http->calls);
    }

    public function testCompleteLooksThePolicyUpFromTheFileRatherThanTrustingTheClient(): void
    {
        DirectUploads::authorize(fn (Request $request, string $policy) => $policy === 'avatars');
        $this->http->on('GET ' . self::API . '/files/' . F::FILE_ID, FakeHttp::json200(['file' => F::file(['policy' => 'documents', 'status' => 'pending'])]));

        // The client names a policy it may use; the file was made under one it may not.
        $this->postJson('/file_hutch/uploads/' . F::FILE_ID . '/complete', ['policy' => 'avatars'])->assertStatus(403);

        $this->assertSame(['GET ' . self::API . '/files/' . F::FILE_ID], $this->http->requested(), 'never completed');
    }

    public function testCompleteFinishesAnUploadThePolicyAllows(): void
    {
        DirectUploads::authorize(fn (Request $request, string $policy) => $policy === 'avatars');
        $this->http->on('GET ' . self::API . '/files/' . F::FILE_ID, FakeHttp::json200(['file' => F::file(['policy' => 'avatars', 'status' => 'pending'])]));
        $this->http->on('POST ' . self::API . '/uploads/' . F::FILE_ID . '/complete', FakeHttp::json200(['file' => F::file(['policy' => 'avatars'])]));

        $this->postJson('/file_hutch/uploads/' . F::FILE_ID . '/complete')
            ->assertOk()
            ->assertJsonPath('file.id', F::FILE_ID)
            ->assertJsonPath('file.status', 'ready');
    }

    public function testAnAuthorizerThatOnlyChecksTheRequestSkipsTheLookup(): void
    {
        DirectUploads::authorize(fn (Request $request) => true);
        $this->http->on('POST ' . self::API . '/uploads/' . F::FILE_ID . '/complete', FakeHttp::json200(['file' => F::file()]));

        $this->postJson('/file_hutch/uploads/' . F::FILE_ID . '/complete')->assertOk();

        $this->assertSame(['POST ' . self::API . '/uploads/' . F::FILE_ID . '/complete'], $this->http->requested());
    }

    public function testAFailedPolicyLookupDeniesRatherThanErrors(): void
    {
        DirectUploads::authorize(fn (Request $request, string $policy) => $policy !== 'documents');
        $this->http->on('GET ' . self::API . '/files/' . F::FILE_ID, FakeHttp::json200(F::error('not_found'), 404));

        // An empty policy is passed, and this authorizer happens to allow it,
        // so the real answer comes from FileHutch's complete call.
        $this->http->on('POST ' . self::API . '/uploads/' . F::FILE_ID . '/complete', FakeHttp::json200(F::error('not_found', 'No such resource'), 404));
        $this->postJson('/file_hutch/uploads/' . F::FILE_ID . '/complete')->assertStatus(404)->assertJsonPath('error.code', 'not_found');

        DirectUploads::authorize(fn (Request $request, string $policy) => $policy === 'avatars');
        $this->postJson('/file_hutch/uploads/' . F::FILE_ID . '/complete')->assertStatus(403);
    }

    public function testFileHutchErrorsReachTheBrowserWithTheirCodeAndStatus(): void
    {
        DirectUploads::authorize(fn () => true);
        $this->http->on('POST ' . self::API . '/uploads', FakeHttp::json200(F::error('policy_violation', 'image/tiff is not allowed'), 422));

        $this->postJson('/file_hutch/uploads', self::PARAMS)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'policy_violation')
            ->assertJsonPath('error.message', 'image/tiff is not allowed');
    }
}
