<?php

declare(strict_types=1);

namespace Flagmint\Tests\Integration;

use Flagmint\ConfigSync\AslEcdh;
use Flagmint\ConfigSync\SignPayload;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 mock that completes ASL ECDH and returns signed config payloads.
 */
final class MockFlagmintHttp implements ClientInterface
{
    private string $macKey = '';

    /** @var list<array<string, mixed>> */
    private array $configPayloads;

    private int $eventsFlushed = 0;

    private int $handshakeCount = 0;

    /**
     * @param list<array<string, mixed>> $configPayloads Unsigned payloads (signature added)
     */
    public function __construct(array $configPayloads)
    {
        $this->configPayloads = $configPayloads;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (str_contains($path, 'asl-handshake')) {
            return $this->handshake($request);
        }
        if (str_contains($path, '/evaluator/v2/flags/config')) {
            return $this->config();
        }
        if (str_contains($path, '/evaluator/events')) {
            $this->eventsFlushed++;

            return new Response(202, [], '{"ok":true}');
        }

        return new Response(404, [], '{"error":"not_found"}');
    }

    public function getEventsFlushed(): int
    {
        return $this->eventsFlushed;
    }

    public function getHandshakeCount(): int
    {
        return $this->handshakeCount;
    }

    private function handshake(RequestInterface $request): ResponseInterface
    {
        $this->handshakeCount++;
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $clientPublicKey = (string) ($body['clientPublicKey'] ?? '');
        if ($clientPublicKey === '') {
            return new Response(400, [], '{"error":"config_mac_required"}');
        }

        $server = AslEcdh::generateClientKeyPair();
        $salt = bin2hex(random_bytes(16));
        $this->macKey = AslEcdh::deriveMacKey($server['privateKey'], $clientPublicKey, $salt);

        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'sessionId' => 'fm_asl_test_' . $this->handshakeCount,
            'serverPublicKey' => $server['publicKeyHex'],
            'salt' => $salt,
            'keyAgreement' => AslEcdh::KEY_AGREEMENT,
        ], JSON_THROW_ON_ERROR));
    }

    private function config(): ResponseInterface
    {
        $payloads = [];
        foreach ($this->configPayloads as $payload) {
            $signed = $payload;
            $signed['signature'] = SignPayload::sign($signed, $this->macKey);
            $payloads[] = $signed;
        }

        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'payloads' => $payloads,
        ], JSON_THROW_ON_ERROR));
    }
}
