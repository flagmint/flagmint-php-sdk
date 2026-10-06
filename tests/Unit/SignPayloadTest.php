<?php

declare(strict_types=1);

namespace Flagmint\Tests\Unit;

use Flagmint\ConfigSync\SignPayload;
use PHPUnit\Framework\TestCase;

final class SignPayloadTest extends TestCase
{
    public function testCanonicalizeSortsKeys(): void
    {
        $a = SignPayload::canonicalize(['b' => 1, 'a' => 2]);
        $b = SignPayload::canonicalize(['a' => 2, 'b' => 1]);
        $this->assertSame($a, $b);
    }

    public function testSignAndVerify(): void
    {
        $body = ['type' => 'lease', 'version' => 1, 'expiresAt' => 4102444800000, 'serverNow' => 1];
        $secret = random_bytes(32);
        $body['signature'] = SignPayload::sign($body, $secret);

        $this->assertTrue(SignPayload::verify($body, $secret));

        $body['version'] = 2;
        $this->assertFalse(SignPayload::verify($body, $secret));
    }

    public function testIsExpired(): void
    {
        $this->assertTrue(SignPayload::isExpired(1000, 1000));
        $this->assertFalse(SignPayload::isExpired(2000, 1000));
    }

    public function testEmptyObjectCanonicalizesAsObjectNotArray(): void
    {
        $withObject = ['type' => 'fullConfig', 'segments' => new \stdClass()];
        $withArray = ['type' => 'fullConfig', 'segments' => []];
        $this->assertSame('{"segments":{},"type":"fullConfig"}', SignPayload::canonicalize($withObject));
        $this->assertSame('{"segments":[],"type":"fullConfig"}', SignPayload::canonicalize($withArray));
    }

    public function testNonEmptyStdClassPropertiesAreIncludedInSignature(): void
    {
        $secret = random_bytes(32);
        $flag = new \stdClass();
        $flag->key = 'checkout';
        $flag->is_active = true;

        $body = [
            'type' => 'fullConfig',
            'version' => 1,
            'flags' => [$flag],
            'segments' => new \stdClass(),
        ];
        $body['signature'] = SignPayload::sign($body, $secret);
        $this->assertTrue(SignPayload::verify($body, $secret));

        $tampered = clone $flag;
        $tampered->is_active = false;
        $body['flags'] = [$tampered];
        $this->assertFalse(SignPayload::verify($body, $secret));

        $canonical = SignPayload::canonicalize([
            'flags' => [$flag],
            'segments' => new \stdClass(),
            'type' => 'fullConfig',
            'version' => 1,
        ]);
        $this->assertStringContainsString('"is_active":true', $canonical);
        $this->assertStringContainsString('"key":"checkout"', $canonical);
        $this->assertStringContainsString('"segments":{}', $canonical);
    }
}
