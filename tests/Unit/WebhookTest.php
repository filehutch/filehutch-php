<?php

declare(strict_types=1);

namespace FileHutch\Tests\Unit;

use FileHutch\Exceptions\FileHutchError;
use FileHutch\Exceptions\SignatureVerificationError;
use FileHutch\Webhook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test';
    private const BODY = '{"id":"whd_1","object":"event","type":"file.created","data":{"file":{"id":"file_1"}}}';
    private const NOW = 1_800_000_000;

    private static function header(string $body = self::BODY, string $secret = self::SECRET, int $at = self::NOW): string
    {
        return "t={$at},v1=" . Webhook::computeSignature($at, $body, $secret);
    }

    public function testMatchesTheServersSignatureByteForByte(): void
    {
        // HMAC-SHA256(secret, "<t>.<body>") in hex: what Webhooks::Signature.compute
        // produces on the server and what the TS and Ruby SDKs verify.
        $this->assertSame(hash_hmac('sha256', self::NOW . '.' . self::BODY, self::SECRET), Webhook::computeSignature(self::NOW, self::BODY, self::SECRET));
    }

    public function testConstructEventVerifiesAndParses(): void
    {
        $event = Webhook::constructEvent(self::BODY, self::header(), self::SECRET, now: self::NOW + 60);

        $this->assertSame('file.created', $event['type']);
        $this->assertSame('file_1', $event['data']['file']['id']);
    }

    public function testTamperingWrongSecretAndReplayAreRefused(): void
    {
        $this->assertRefused(fn () => Webhook::constructEvent(self::BODY . ' ', self::header(), self::SECRET, now: self::NOW), 'does not match');
        $this->assertRefused(fn () => Webhook::constructEvent(self::BODY, self::header(self::BODY, 'whsec_other'), self::SECRET, now: self::NOW), 'does not match');
        $this->assertRefused(fn () => Webhook::constructEvent(self::BODY, self::header(), self::SECRET, now: self::NOW + 301), 'tolerance');
        $this->assertRefused(fn () => Webhook::constructEvent(self::BODY, self::header(), self::SECRET, now: self::NOW - 301), 'tolerance');

        Webhook::verifySignature(self::BODY, self::header(), self::SECRET, tolerance: 600, now: self::NOW + 301);
        $this->addToAssertionCount(1);
    }

    /** @return iterable<array{?string}> */
    public static function malformed(): iterable
    {
        foreach ([null, '', 'garbage', 't=abc,v1=', 'v1=deadbeef', 't=1800000000', 't=1.5,v1=ab', 't=-5,v1=ab', '=1800000000,v1=ab'] as $header) {
            yield var_export($header, true) => [$header];
        }
    }

    #[DataProvider('malformed')]
    public function testMalformedHeadersFailClosed(?string $header): void
    {
        $this->assertRefused(fn () => Webhook::verifySignature(self::BODY, $header, self::SECRET, now: self::NOW), 'missing or malformed');
    }

    public function testAMissingSecretFailsClosed(): void
    {
        $this->assertRefused(fn () => Webhook::verifySignature(self::BODY, self::header(), '', now: self::NOW), 'secret is missing');
    }

    public function testTheErrorIsAFileHutchError(): void
    {
        $this->assertInstanceOf(FileHutchError::class, new SignatureVerificationError('x'));
    }

    private function assertRefused(callable $fn, string $message): void
    {
        try {
            $fn();
        } catch (SignatureVerificationError $e) {
            $this->assertStringContainsString($message, $e->getMessage());

            return;
        }
        $this->fail('Expected the signature to be refused');
    }
}
