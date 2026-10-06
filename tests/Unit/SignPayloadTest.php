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
}
