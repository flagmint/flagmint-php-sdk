<?php

declare(strict_types=1);

namespace Flagmint\ConfigSync;

/**
 * Client half of the ASL ECDH handshake used for config-sync.
 *
 * Flow:
 * 1. {@see generateClientKeyPair()} → send `publicKeyHex` in `POST /auth/asl-handshake`
 * 2. Server returns `serverPublicKey`, `salt`, `sessionId`
 * 3. {@see deriveMacKey()} → 32-byte session key used to verify lease/fullConfig/delta MACs
 *
 * Wire format and HKDF info string **must** match FF-EU `asl-ecdh.ts` and the JS SDK.
 * The MAC key is never sent on the wire.
 */
final class AslEcdh
{
    /** Raw X25519 public keys are 32 bytes → 64 hex chars. */
    public const CLIENT_PUBLIC_KEY_HEX_LENGTH = 64;

    /** HKDF info label shared with the Flagmint API. */
    public const HKDF_INFO = 'flagmint-asl-config-sync-mac-v1';

    /** Expected `keyAgreement` value from the handshake response. */
    public const KEY_AGREEMENT = 'x25519-hkdf-sha256';

    /**
     * Generate an ephemeral X25519 keypair for one handshake.
     *
     * Keep `privateKey` only in memory for {@see deriveMacKey()}, then wipe it.
     *
     * @return array{publicKeyHex: string, privateKey: string}
     *     `publicKeyHex` is lowercase hex; `privateKey` is raw 32 bytes
     */
    public static function generateClientKeyPair(): array
    {
        $privateKey = random_bytes(SODIUM_CRYPTO_SCALARMULT_SCALARBYTES);
        $publicKey = sodium_crypto_scalarmult_base($privateKey);

        return [
            'publicKeyHex' => bin2hex($publicKey),
            'privateKey' => $privateKey,
        ];
    }

    /**
     * Derive the 32-byte session MAC key from ECDH + HKDF-SHA256.
     *
     * @param string $privateKey Raw 32-byte private key
     * @param string $peerPublicKeyHex 64-char hex peer public key
     * @param string $saltHex Even-length hex salt from handshake
     * @return string Raw 32-byte MAC key
     */
    public static function deriveMacKey(string $privateKey, string $peerPublicKeyHex, string $saltHex): string
    {
        $peerPublicKey = self::parsePeerPublicKeyHex($peerPublicKeyHex);
        $salt = self::parseSaltHex($saltHex);
        $shared = sodium_crypto_scalarmult($privateKey, $peerPublicKey);

        return hash_hkdf('sha256', $shared, 32, self::HKDF_INFO, $salt);
    }

    /**
     * @param string $hex
     */
    public static function parsePeerPublicKeyHex(string $hex): string
    {
        $normalized = strtolower(trim($hex));
        if (!preg_match('/^[0-9a-f]{64}$/', $normalized)) {
            throw new \InvalidArgumentException('ASL ECDH: peer public key must be 64 hex characters (raw X25519)');
        }

        return hex2bin($normalized);
    }

    /**
     * @param string $hex
     */
    public static function parseSaltHex(string $hex): string
    {
        $normalized = strtolower(trim($hex));
        if (!preg_match('/^([0-9a-f]{2})+$/', $normalized)) {
            throw new \InvalidArgumentException('ASL ECDH: salt must be an even-length hex string');
        }

        return hex2bin($normalized);
    }

    /**
     * Best-effort zeroize of key material.
     *
     * @param string|null $key
     */
    public static function wipe(?string &$key): void
    {
        if ($key === null || $key === '') {
            return;
        }
        try {
            sodium_memzero($key);
        } catch (\Throwable) {
            $key = str_repeat("\0", strlen($key));
        }
        $key = null;
    }
}
