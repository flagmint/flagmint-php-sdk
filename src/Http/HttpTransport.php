<?php

declare(strict_types=1);

namespace Flagmint\Http;

use Flagmint\Support\Json;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Thin PSR-18 helper for Flagmint REST calls.
 */
final class HttpTransport
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $jsonBody
     * @return array{status: int, body: array<string, mixed>|list<mixed>|null, raw: string}
     */
    public function request(string $method, string $url, array $headers = [], ?array $jsonBody = null): array
    {
        $request = $this->requestFactory->createRequest($method, $url);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($jsonBody !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream(json_encode($jsonBody, JSON_THROW_ON_ERROR)));
        }

        $response = $this->http->sendRequest($request);
        $raw = (string) $response->getBody();
        $decoded = null;
        if ($raw !== '') {
            try {
                $decoded = Json::decodePreservingEmptyObjects($raw);
            } catch (\Throwable) {
                $decoded = null;
            }
        }

        return [
            'status' => $response->getStatusCode(),
            'body' => is_array($decoded) ? $decoded : null,
            'raw' => $raw,
        ];
    }
}
