<?php

declare(strict_types=1);

namespace FileHutch;

use FileHutch\Exceptions\SignatureVerificationError;

/**
 * Verifies the signature FileHutch puts on every webhook delivery:
 *
 *   FileHutch-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(secret, "<t>.<body>")>
 *
 *   $event = Webhook::constructEvent($rawBody, $request->header('FileHutch-Signature'), $secret);
 */
final class Webhook
{
    public const SIGNATURE_HEADER = 'FileHutch-Signature';
    public const DEFAULT_TOLERANCE = 300;

    public static function computeSignature(int $timestamp, string $payload, string $secret): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
    }

    /**
     * Throws SignatureVerificationError unless `$payload` was signed with `$secret`
     * within `$tolerance` seconds of `$now` (unix seconds; defaults to the clock).
     */
    public static function verifySignature(
        string $payload,
        ?string $header,
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): void {
        if ($secret === '') {
            throw new SignatureVerificationError('webhook secret is missing');
        }
        $now ??= time();

        $parts = [];
        foreach (explode(',', $header ?? '') as $part) {
            $index = strpos($part, '=');
            if ($index !== false && $index > 0) {
                $parts[substr($part, 0, $index)] = substr($part, $index + 1);
            }
        }
        $t = $parts['t'] ?? '';
        $given = $parts['v1'] ?? '';
        if (preg_match('/\A[0-9]+\z/', $t) !== 1 || (int) $t <= 0 || $given === '') {
            throw new SignatureVerificationError('missing or malformed ' . self::SIGNATURE_HEADER . ' header');
        }
        $timestamp = (int) $t;
        if (abs($now - $timestamp) > $tolerance) {
            throw new SignatureVerificationError("signature timestamp is outside the {$tolerance}s tolerance");
        }

        if (!hash_equals(self::computeSignature($timestamp, $payload, $secret), $given)) {
            throw new SignatureVerificationError('signature does not match');
        }
    }

    /**
     * Verifies and decodes a delivery. `$payload` must be the raw request body,
     * not re-encoded JSON. Deduplicate on the event `id`: deliveries are at-least-once.
     *
     * @return array{id: string, object: string, type: string, created_at: string, project_id: string, environment: ?string, data: array<string, mixed>}
     */
    public static function constructEvent(
        string $payload,
        ?string $header,
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): array {
        self::verifySignature($payload, $header, $secret, $tolerance, $now);

        try {
            return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SignatureVerificationError('signed payload is not JSON', 0, $e);
        }
    }
}
