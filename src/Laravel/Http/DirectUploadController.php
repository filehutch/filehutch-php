<?php

declare(strict_types=1);

namespace FileHutch\Laravel\Http;

use FileHutch\Client;
use FileHutch\Exceptions\ApiError;
use FileHutch\Exceptions\FileHutchError;
use FileHutch\Laravel\DirectUploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Proxies the two control-plane calls of a browser-direct upload. The bytes
 * still go from the browser straight to storage, and the API key stays here.
 *
 *   POST <prefix>/uploads               {policy, filename, content_type, byte_size} → {upload, file}
 *   POST <prefix>/uploads/{id}/complete                                               → {file}
 */
final class DirectUploadController extends Controller
{
    public function __construct(private readonly Client $hutch)
    {
    }

    public function create(Request $request): JsonResponse
    {
        $missing = array_values(array_filter(
            ['policy', 'filename', 'content_type', 'byte_size'],
            static fn (string $key) => !$request->filled($key),
        ));
        if ($missing !== []) {
            return self::error('invalid', 'param is missing or the value is empty: ' . implode(', ', $missing), 422);
        }
        if (!is_numeric($request->input('byte_size'))) {
            return self::error('invalid', 'byte_size must be a number', 422);
        }

        $policy = (string) $request->input('policy');
        if ($denied = $this->deny($request, static fn () => $policy)) {
            return $denied;
        }

        try {
            $created = $this->hutch->createUpload(
                policy: $policy,
                filename: (string) $request->input('filename'),
                contentType: (string) $request->input('content_type'),
                byteSize: (int) $request->input('byte_size'),
            );
        } catch (FileHutchError $e) {
            return self::fromException($e);
        }

        return new JsonResponse(['upload' => $created->upload->toArray(), 'file' => $created->file->toArray()], 201);
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->deny($request, fn () => $this->policyOf($id))) {
            return $denied;
        }

        try {
            $file = $this->hutch->completeUpload($id);
        } catch (FileHutchError $e) {
            return self::fromException($e);
        }

        return new JsonResponse(['file' => $file->toArray()]);
    }

    /** @param \Closure(): string $policy only called when the authorizer takes a policy */
    private function deny(Request $request, \Closure $policy): ?JsonResponse
    {
        $authorizer = DirectUploads::authorizer();
        if ($authorizer === null) {
            return self::error('forbidden', 'Direct uploads are disabled: call FileHutch\Laravel\DirectUploads::authorize() in a service provider', 403);
        }

        $allowed = DirectUploads::wantsPolicy($authorizer) ? $authorizer($request, $policy()) : $authorizer($request);

        return $allowed ? null : self::error('forbidden', 'Not allowed to upload here', 403);
    }

    private function policyOf(string $id): string
    {
        try {
            return (string) $this->hutch->file($id)->policy;
        } catch (\Throwable) {
            // A lookup that fails must not become a 500. An empty policy matches
            // nothing, so an authorizer that checks the policy denies.
            return '';
        }
    }

    private static function fromException(FileHutchError $e): JsonResponse
    {
        $status = $e instanceof ApiError && $e->status >= 400 && $e->status <= 599 ? $e->status : 422;
        $code = $e instanceof ApiError ? $e->errorCode : 'error';

        return self::error($code, $e->getMessage(), $status);
    }

    private static function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
