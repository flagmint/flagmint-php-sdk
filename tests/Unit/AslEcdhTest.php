<?php

declare(strict_types=1);

namespace Flagmint\Tests\Unit;

use Flagmint\ConfigSync\AslEcdh;
use PHPUnit\Framework\TestCase;

final class AslEcdhTest extends TestCase
{
    public function testGenerateKeyPairAndDeriveSharedMac(): void
    {
        $alice = AslEcdh::generateClientKeyPair();
        $bob = AslEcdh::generateClientKeyPair();
        $salt = bin2hex(random_bytes(16));

        $macAlice = AslEcdh::deriveMacKey($alice['privateKey'], $bob['publicKeyHex'], $salt);
        $macBob = AslEcdh::deriveMacKey($bob['privateKey'], $alice['publicKeyHex'], $salt);

        $this->assertSame(32, strlen($macAlice));
        $this->assertSame($macAlice, $macBob);
        $this->assertSame(64, strlen($alice['publicKeyHex']));
    }

    public function testRejectsInvalidPeerPublicKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AslEcdh::parsePeerPublicKeyHex('deadbeef');
    }

    public function testRejectsInvalidSalt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AslEcdh::parseSaltHex('xyz');
    }
}
