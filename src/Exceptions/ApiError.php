<?php

declare(strict_types=1);

namespace FileHutch\Exceptions;

/** FileHutch answered with an error. `code` is stable; match on it, not the message. */
class ApiError extends FileHutchError
{
    /**
     * The API's error codes are the contract; status is only the fallback.
     *
     * @var array<string, class-string<ApiError>>
     */
    private const BY_CODE = [
        'unauthorized' => AuthenticationError::class,
        'not_found' => NotFoundError::class,
        'invalid' => InvalidRequestError::class,
        'policy_violation' => PolicyError::class,
        'policy_not_found' => PolicyError::class,
        'storage_not_ready' => StorageNotReadyError::class,
        'invalid_state' => InvalidStateError::class,
        'not_ready' => InvalidStateError::class,
        'already_deleted' => InvalidStateError::class,
        'not_public' => InvalidStateError::class,
        'no_public_base_url' => InvalidStateError::class,
        'transform_not_found' => TransformError::class,
        'not_transformable' => TransformError::class,
        'transforms_unsupported' => TransformsUnsupportedError::class,
        'upload_expired' => UploadError::class,
        'upload_incomplete' => UploadError::class,
        'size_mismatch' => UploadError::class,
        // The bytes that arrived are not the bytes that were declared. The TS SDK
        // leaves these to the 422 fallback (InvalidRequestError); they are upload
        // failures, and the README tables of every SDK say so.
        'checksum_mismatch' => UploadError::class,
        'checksum_unverifiable' => UploadError::class,
        // Raised by this SDK, not the API, when the bucket refuses the PUT. Mapped
        // by code so a 403 from storage never reads as a read-only API key.
        'storage_rejected' => UploadError::class,
        'storage_error' => StorageError::class,
        'verification_failed' => StorageError::class,
        'plan_limit' => PlanLimitError::class,
        'read_only_key' => PermissionError::class,
        'invalid_config' => ConfigError::class,
        'environment_not_found' => InvalidRequestError::class,
        'not_verified' => InvalidStateError::class,
    ];

    /** @var array<int, class-string<ApiError>> */
    private const BY_STATUS = [
        401 => AuthenticationError::class,
        402 => PlanLimitError::class,
        403 => PermissionError::class,
        404 => NotFoundError::class,
        409 => InvalidStateError::class,
        410 => InvalidStateError::class,
        422 => InvalidRequestError::class,
        429 => RateLimitError::class,
        502 => StorageError::class,
    ];

    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
        public readonly mixed $details = null,
    ) {
        parent::__construct($message, $status);
    }

    /**
     * Picks the most specific class for an error payload. `$body` is the decoded
     * JSON, or null when the response was empty or not JSON.
     */
    public static function fromResponse(int $status, mixed $body): self
    {
        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];
        $code = is_string($error['code'] ?? null) ? $error['code'] : ($status >= 500 ? 'server_error' : 'error');
        $message = is_string($error['message'] ?? null) ? $error['message'] : "FileHutch returned HTTP {$status}";
        $class = self::BY_CODE[$code] ?? self::BY_STATUS[$status] ?? ($status >= 500 ? ServerError::class : self::class);

        return new $class($message, $code, $status, $error['details'] ?? null);
    }
}
