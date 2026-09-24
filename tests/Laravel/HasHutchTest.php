<?php

declare(strict_types=1);

namespace FileHutch\Tests\Laravel;

use FileHutch\Resources\File;
use FileHutch\Tests\Laravel\Models\SoftUser;
use FileHutch\Tests\Laravel\Models\User;
use FileHutch\Tests\Support\FakeHttp;
use FileHutch\Tests\Support\Fixtures as F;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class HasHutchTest extends TestCase
{
    private const API = F::BASE . '/api/v1';

    public function testAnUploadedFileGoesToStorageOnSaveAndOnlyTheIdIsStored(): void
    {
        $this->stubProject();
        $this->stubUpload(F::FILE_ID, ['visibility' => 'public', 'content_type' => 'image/png', 'policy' => 'avatars', 'url' => 'https://cdn.test/me.png']);

        $user = new User();
        $user->avatar = UploadedFile::fake()->create('me.png', 0.5, 'image/png');
        $this->assertSame([], $this->http->calls, 'nothing leaves the server until save');

        $user->save();

        $this->assertSame(F::FILE_ID, DB::table('users')->value('avatar_file_id'));
        $created = $this->http->json(1);
        $this->assertSame('avatars', $created['policy']);
        $this->assertSame('me.png', $created['filename'], "the user's filename, not the temp file's");
        $this->assertSame('image/png', $created['content_type']);

        $calls = count($this->http->calls);
        $this->assertSame('https://cdn.test/me.png', $user->avatar_url);
        $this->assertInstanceOf(File::class, $user->avatar);
        $this->assertCount($calls, $this->http->calls, 'the uploaded file is cached, not fetched again');
    }

    public function testAWrongContentTypeIsAValidationErrorBeforeAnyUpload(): void
    {
        $this->stubProject();
        $user = new User();
        $user->avatar = UploadedFile::fake()->create('notes.pdf', 0.5, 'application/pdf');

        $errors = $this->assertInvalid(fn () => $user->save());

        $this->assertStringContainsString('application/pdf is not allowed', $errors['avatar'][0]);
        $this->assertSame(['GET ' . self::API . '/project'], $this->http->requested());
        $this->assertSame(0, DB::table('users')->count());
    }

    public function testAnOversizedFileIsAValidationErrorBeforeAnyUpload(): void
    {
        $this->stubProject(); // avatars allows 1,000 bytes
        $user = new User();
        $user->avatar = UploadedFile::fake()->create('big.png', 2, 'image/png');

        $errors = $this->assertInvalid(fn () => $user->save());

        $this->assertStringContainsString('larger than 1000 bytes', $errors['avatar'][0]);
        $this->assertSame(['GET ' . self::API . '/project'], $this->http->requested());
    }

    public function testPoliciesAreFetchedOncePerProcess(): void
    {
        $this->stubProject();
        foreach (['a.pdf', 'b.pdf'] as $name) {
            $user = new User();
            $user->avatar = UploadedFile::fake()->create($name, 0.5, 'application/pdf');
            $this->assertInvalid(fn () => $user->save());
        }

        $this->assertSame(['GET ' . self::API . '/project'], $this->http->requested());
    }

    public function testAnIdFromADirectUploadIsVerifiedOnSave(): void
    {
        $this->http->on('GET ' . self::API . '/files/' . F::FILE_ID, FakeHttp::json200(['file' => F::file(['policy' => 'avatars'])]));

        $user = User::create(['avatar' => F::FILE_ID]);

        $this->assertSame(F::FILE_ID, $user->fresh()->avatar_file_id);
        $this->assertSame(F::FILE_ID, $user->avatar->id);
        $this->assertCount(1, $this->http->calls, 'the verified file is cached');
    }

    public function testAnIdUploadedUnderAnotherPolicyIsRefused(): void
    {
        // A browser could otherwise finish an upload under a loose policy and
        // attach it to a field that demands a strict one.
        $this->http->on('GET ' . self::API . '/files/' . F::FILE_ID, FakeHttp::json200(['file' => F::file(['policy' => 'documents'])]));

        $errors = $this->assertInvalid(fn () => User::create(['avatar' => F::FILE_ID]));

        $this->assertStringContainsString('uploaded under the documents policy', $errors['avatar'][0]);
        $this->assertSame(0, DB::table('users')->count());
    }

    public function testAnIdThatIsNotReadyOrDoesNotExistIsRefused(): void
    {
        $this->http->on('GET ' . self::API . '/files/' . F::FILE_ID, FakeHttp::json200(['file' => F::file(['policy' => 'avatars', 'status' => 'pending'])]));
        $this->assertStringContainsString('upload is pending', $this->assertInvalid(fn () => User::create(['avatar' => F::FILE_ID]))['avatar'][0]);

        $this->http->on('GET ' . self::API . '/files/' . F::OTHER_ID, FakeHttp::json200(F::error('not_found'), 404));
        $this->assertStringContainsString('does not exist', $this->assertInvalid(fn () => User::create(['avatar' => F::OTHER_ID]))['avatar'][0]);
    }

    public function testAnIdAssignedStraightToTheColumnIsVerifiedToo(): void
    {
        $this->http->on('GET ' . self::API . '/files/' . F::FILE_ID, FakeHttp::json200(['file' => F::file(['policy' => 'documents'])]));

        $errors = $this->assertInvalid(fn () => User::create(['avatar_file_id' => F::FILE_ID]));

        $this->assertArrayHasKey('avatar', $errors);
    }

    public function testVerificationOnlyRunsWhenTheIdChanges(): void
    {
        $id = DB::table('users')->insertGetId(['avatar_file_id' => F::FILE_ID]);
        $user = User::find($id);
        $user->name = 'Ada';
        $user->save();

        $this->assertSame([], $this->http->calls);
    }

    public function testAStringThatIsNotAnIdIsRefusedOnAssignment(): void
    {
        $user = new User();
        $this->expectException(\InvalidArgumentException::class);
        $user->avatar = 'https://bucket.example/avatars/me.png';
    }

    public function testSignedAndTransformUrlsComeFromTheRecord(): void
    {
        $user = $this->userWith(F::FILE_ID);
        $this->http->on('POST ' . self::API . '/files/' . F::FILE_ID . '/signed_url', FakeHttp::json200(['url' => F::STORAGE . '/signed', 'expires_at' => '2026-09-04T13:00:00.000Z']));
        $this->http->on('GET ' . self::API . '/files/' . F::FILE_ID, FakeHttp::json200(['file' => F::file(['content_type' => 'image/png'])]));
        $this->http->on('POST ' . self::API . '/files/' . F::FILE_ID . '/transform_url', FakeHttp::json200(['url' => F::STORAGE . '/thumb', 'expires_at' => '2026-09-04T13:00:00.000Z']));

        $this->assertSame(F::STORAGE . '/signed', $user->avatarSignedUrl(expiresIn: 600, disposition: 'attachment'));
        $this->assertSame(['expires_in' => 600, 'disposition' => 'attachment'], $this->http->json(0));

        $this->assertSame(F::STORAGE . '/thumb', $user->avatarTransformUrl('thumb', 60));
        $this->assertSame(['transform' => 'thumb', 'expires_in' => 60], $this->http->json(2));
        $this->assertNull($user->avatar_url, 'a private file has no public URL');
    }

    public function testPublicTransformUrlsComeFromThePayloadForFree(): void
    {
        $user = $this->userWith(F::FILE_ID);
        $this->http->on('GET ' . self::API . '/files/' . F::FILE_ID, FakeHttp::json200(['file' => F::file([
            'visibility' => 'public', 'transforms' => ['thumb' => 'https://cdn.test/thumb.png'],
        ])]));

        $this->assertSame('https://cdn.test/thumb.png', $user->avatarTransformUrl('thumb'));
        $this->assertSame(['GET ' . self::API . '/files/' . F::FILE_ID], $this->http->requested());
    }

    public function testNothingIsRequestedForAnEmptyAttachment(): void
    {
        $user = new User();

        $this->assertNull($user->avatar);
        $this->assertNull($user->avatar_url);
        $this->assertNull($user->avatarSignedUrl());
        $this->assertNull($user->avatarTransformUrl('thumb'));
        $this->assertFalse($user->hutchAttached('avatar'));
        $this->assertSame([], $this->http->calls);
    }

    public function testReplacingAnAttachmentDeletesTheOldFileAfterSave(): void
    {
        $user = $this->userWith(F::FILE_ID);
        $this->http->on('GET ' . self::API . '/files/' . F::OTHER_ID, FakeHttp::json200(['file' => F::file(['id' => F::OTHER_ID, 'policy' => 'avatars'])]));
        $this->http->on('DELETE ' . self::API . '/files/' . F::FILE_ID, FakeHttp::text(204));

        $user->avatar = F::OTHER_ID;
        $this->assertNotContains('DELETE ' . self::API . '/files/' . F::FILE_ID, $this->http->requested(), 'not before the save');
        $user->save();

        $this->assertSame([
            'GET ' . self::API . '/files/' . F::OTHER_ID,
            'DELETE ' . self::API . '/files/' . F::FILE_ID,
        ], $this->http->requested());
    }

    public function testAFailedSaveDeletesNothing(): void
    {
        $user = $this->userWith(F::FILE_ID);
        $this->http->on('GET ' . self::API . '/files/' . F::OTHER_ID, FakeHttp::json200(['file' => F::file(['id' => F::OTHER_ID, 'policy' => 'documents'])]));

        $user->avatar = F::OTHER_ID;
        $this->assertInvalid(fn () => $user->save());

        $this->assertSame(['GET ' . self::API . '/files/' . F::OTHER_ID], $this->http->requested());
    }

    public function testDependentFalseKeepsTheReplacedFile(): void
    {
        $id = DB::table('users')->insertGetId(['contract_file_id' => F::FILE_ID]);
        $user = User::find($id);
        $user->contract = null;
        $user->save();
        $user->delete();

        $this->assertSame([], $this->http->calls);
    }

    public function testDeletingTheRecordDeletesTheFile(): void
    {
        $user = $this->userWith(F::FILE_ID);
        $this->http->on('DELETE ' . self::API . '/files/' . F::FILE_ID, FakeHttp::json200(F::error('already_deleted'), 410));

        $user->delete();

        $this->assertSame(['DELETE ' . self::API . '/files/' . F::FILE_ID], $this->http->requested(), 'and already-deleted is fine');
    }

    public function testASoftDeleteKeepsTheFileAndAForceDeleteRemovesIt(): void
    {
        $id = DB::table('users')->insertGetId(['avatar_file_id' => F::FILE_ID]);
        $user = SoftUser::find($id);
        $this->http->on('DELETE ' . self::API . '/files/' . F::FILE_ID, FakeHttp::text(204));

        $user->delete();
        $this->assertSame([], $this->http->calls);

        $user->forceDelete();
        $this->assertSame(['DELETE ' . self::API . '/files/' . F::FILE_ID], $this->http->requested());
    }

    public function testPurgeDeletesRemotelyAndClearsTheColumn(): void
    {
        $user = $this->userWith(F::FILE_ID);
        $user->name = 'unsaved';
        $this->http->on('DELETE ' . self::API . '/files/' . F::FILE_ID, FakeHttp::text(204));

        $this->assertTrue($user->purgeAvatar());

        $this->assertSame(['DELETE ' . self::API . '/files/' . F::FILE_ID], $this->http->requested());
        $this->assertNull(DB::table('users')->value('avatar_file_id'));
        $this->assertNull(DB::table('users')->value('name'), 'purge touches only its own column');
        $this->assertNull($user->avatar_file_id);
        $this->assertFalse($user->isDirty('avatar_file_id'));
    }

    public function testOtherMethodCallsStillReachTheQueryBuilder(): void
    {
        $this->userWith(F::FILE_ID);

        $this->assertSame(1, (new User())->where('avatar_file_id', F::FILE_ID)->count());
    }

    private function userWith(string $id): User
    {
        return User::find(DB::table('users')->insertGetId(['avatar_file_id' => $id]));
    }

    private function stubProject(): void
    {
        $this->http->on('GET ' . self::API . '/project', FakeHttp::json200(['project' => F::project()]));
    }

    private function stubUpload(string $id, array $file): void
    {
        $this->http->on('POST ' . self::API . '/uploads', FakeHttp::json200(F::created($id), 201));
        $this->http->on('PUT ' . F::STORAGE . "/{$id}?sig=1", FakeHttp::text(200));
        $this->http->on('POST ' . self::API . "/uploads/{$id}/complete", FakeHttp::json200(['file' => F::file(['id' => $id] + $file)]));
    }

    /** @return array<string, list<string>> */
    private function assertInvalid(callable $fn): array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors();
        }
        $this->fail('Expected a ValidationException');
    }
}
