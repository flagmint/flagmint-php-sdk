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

    /** @var array<string, mixed>|null */
    private ?array $lastHandshakeBody = null;

    /** @var array<string, string> */
    private array $lastHandshakeHeaders = [];

    private string $lastConfigUrl = '';

    /** @var array<string, string> */
    private array $lastConfigHeaders = [];

    /** @var array<string, mixed>|null */
    private ?array $lastEventsBody = null;

    /** @var array<string, string> */
    private array $lastEventsHeaders = [];

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
            return $this->config($request);
        }
        if (str_contains($path, '/evaluator/events')) {
            return $this->events($request);
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

    /**
     * @return array<string, mixed>|null
     */
    public function getLastHandshakeBody(): ?array
    {
        return $this->lastHandshakeBody;
    }

    /**
     * @return array<string, string>
     */
    public function getLastHandshakeHeaders(): array
    {
        return $this->lastHandshakeHeaders;
    }

    public function getLastConfigUrl(): string
    {
        return $this->lastConfigUrl;
    }

    /**
     * @return array<string, string>
     */
    public function getLastConfigHeaders(): array
    {
        return $this->lastConfigHeaders;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLastEventsBody(): ?array
    {
        return $this->lastEventsBody;
    }

    /**
     * @return array<string, string>
     */
    public function getLastEventsHeaders(): array
    {
        return $this->lastEventsHeaders;
    }

    private function handshake(RequestInterface $request): ResponseInterface
    {
        $this->handshakeCount++;
        $this->lastHandshakeHeaders = $this->flattenHeaders($request);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->lastHandshakeBody = $body;
        $clientPublicKey = (string) ($body['clientPublicKey'] ?? '');
        if ($clientPublicKey === '') {
            return new Response(400, [], '{"error":"config_mac_required"}');
        }

        $server = AslEcdh::generateClientKeyPair();
        $salt = bin2hex(random_bytes(16));
        $this->macKey = AslEcdh::deriveMacKey($server['privateKey'], $clientPublicKey, $salt);

        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'statusCode' => 200,
            'message' => 'ok',
            'data' => [
                'sessionId' => 'fm_asl_test_' . $this->handshakeCount,
                'serverPublicKey' => $server['publicKeyHex'],
                'salt' => $salt,
                'keyAgreement' => AslEcdh::KEY_AGREEMENT,
            ],
        ], JSON_THROW_ON_ERROR));
    }

    private function config(RequestInterface $request): ResponseInterface
    {
        $this->lastConfigUrl = (string) $request->getUri();
        $this->lastConfigHeaders = $this->flattenHeaders($request);

        $payloads = [];
        foreach ($this->configPayloads as $payload) {
            $signed = $payload;
            $signed['signature'] = SignPayload::sign($signed, $this->macKey);
            $payloads[] = $signed;
        }

        // Match production envelope; single payload goes in data, multi in data.payloads.
        $data = count($payloads) === 1
            ? $payloads[0]
            : ['payloads' => $payloads];

        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'statusCode' => 200,
            'message' => 'Resource Found',
            'data' => $data,
        ], JSON_THROW_ON_ERROR));
    }

    private function events(RequestInterface $request): ResponseInterface
    {
        $this->eventsFlushed++;
        $this->lastEventsHeaders = $this->flattenHeaders($request);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->lastEventsBody = $body;

        return new Response(202, [], '{"ok":true}');
    }

    /**
     * @return array<string, string>
     */
    private function flattenHeaders(RequestInterface $request): array
    {
        $out = [];
        foreach ($request->getHeaders() as $name => $values) {
            $out[strtolower((string) $name)] = implode(', ', $values);
        }

        return $out;
    }
}
