<?php

declare(strict_types=1);

namespace Flagmint;

use Flagmint\Cache\ArrayMemoryAdapter;
use Flagmint\Cache\CacheAdapter;
use Flagmint\Cache\RulesSnapshot;
use Flagmint\ConfigSync\AslEcdh;
use Flagmint\ConfigSync\RulesStore;
use Flagmint\Eval\Evaluator;
use Flagmint\Events\EventBuffer;
use Flagmint\Http\HttpTransport;
use Flagmint\Support\ErrorCode;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Main Flagmint client for PHP apps (local evaluation).
 *
 * Typical flow in a long-lived process or at request boot:
 *
 * ```php
 * $client = new Client(['apiKey' => getenv('FLAGMINT_SDK_KEY')]);
 * $client->ready(); // handshake + pull rules (never throws)
 *
 * if ($client->isEnabled('new-checkout', [
 *     'kind' => 'user',
 *     'key' => (string) $userId,
 *     'plan' => 'premium',
 * ])) {
 *     // …
 * }
 * ```
 *
 * **How it works:** rules are fetched over REST (`GET /evaluator/v2/flags/config`)
 * after an ASL ECDH handshake, stored via {@see CacheAdapter}, then evaluated
 * in-process. There is no per-`getFlag` network call.
 *
 * **Fail-closed:** when the lease expires or the store is not ready, typed
 * readers return your `$fallback` and optionally invoke `onError`.
 *
 * **Context:** always pass per-call context under concurrency (PHP-FPM). Do not
 * rely on a single shared mutable context across requests.
 */
final class Client
{
    private string $apiKey;

    private bool $enableFlagmint;

    private string $restEndpoint;

    private string $handshakeEndpoint;

    private CacheAdapter $cacheAdapter;

    private RulesStore $rulesStore;

    private Evaluator $evaluator;

    private EventBuffer $eventBuffer;

    private HttpTransport $transport;

    /** @var callable|null */
    private $onError;

    private bool $ready = false;

    private ?string $sessionId = null;

    private ?string $privateKey = null;

    /**
     * @param array{
     *   apiKey: string,
     *   enableFlagmint?: bool,
     *   restEndpoint?: string,
     *   handshakeEndpoint?: string,
     *   cacheAdapter?: CacheAdapter,
     *   httpClient?: ClientInterface,
     *   requestFactory?: RequestFactoryInterface,
     *   streamFactory?: StreamFactoryInterface,
     *   onError?: callable(array{code: string, message: string}): void,
     *   env?: string
     * } $options Client options:
     *   - `apiKey` (required): environment SDK key (`fm_…`)
     *   - `enableFlagmint`: when false, all reads return fallbacks (offline / kill switch)
     *   - `env`: `production` | `staging` | `local` — picks default API hosts
     *   - `restEndpoint` / `handshakeEndpoint`: override hosts (tests, self-hosted)
     *   - `cacheAdapter`: {@see CacheAdapter}; defaults to {@see ArrayMemoryAdapter}
     *   - `httpClient` + PSR-17 factories: inject mocks in tests; otherwise Guzzle is used
     *   - `onError`: `fn (array{code: string, message: string}): void` for soft failures
     *
     * @throws \InvalidArgumentException When `apiKey` is empty or `cacheAdapter` is not a CacheAdapter
     * @throws \RuntimeException When no HTTP client is provided and Guzzle is not installed
     */
    public function __construct(array $options)
    {
        $apiKey = $options['apiKey'] ?? '';
        if (!is_string($apiKey) || $apiKey === '') {
            throw new \InvalidArgumentException('Flagmint Client requires a non-empty apiKey');
        }

        $cache = $options['cacheAdapter'] ?? new ArrayMemoryAdapter();
        if (!$cache instanceof CacheAdapter) {
            throw new \InvalidArgumentException('cacheAdapter must implement Flagmint\\Cache\\CacheAdapter');
        }

        $this->apiKey = $apiKey;
        $this->enableFlagmint = (bool) ($options['enableFlagmint'] ?? true);
        $this->cacheAdapter = $cache;
        $this->onError = $options['onError'] ?? null;
        $this->rulesStore = new RulesStore();
        $this->evaluator = new Evaluator();
        $this->eventBuffer = new EventBuffer();

        $env = (string) ($options['env'] ?? 'production');
        $defaults = self::endpointsForEnv($env);
        $this->restEndpoint = rtrim((string) ($options['restEndpoint'] ?? $defaults['rest']), '/');
        $this->handshakeEndpoint = (string) ($options['handshakeEndpoint'] ?? $defaults['handshake']);

        $http = $options['httpClient'] ?? null;
        $requestFactory = $options['requestFactory'] ?? null;
        $streamFactory = $options['streamFactory'] ?? null;
        if ($http === null || $requestFactory === null || $streamFactory === null) {
            if (!class_exists(GuzzleClient::class)) {
                throw new \RuntimeException('Provide PSR-18 httpClient/factories or install guzzlehttp/guzzle');
            }
            $http ??= new GuzzleClient(['http_errors' => false, 'timeout' => 15]);
            $factory = new HttpFactory();
            $requestFactory ??= $factory;
            $streamFactory ??= $factory;
        }

        $this->transport = new HttpTransport($http, $requestFactory, $streamFactory);

        $snapshot = $this->cacheAdapter->loadRulesSnapshot($this->apiKey);
        if ($snapshot instanceof RulesSnapshot) {
            $this->rulesStore->hydrateFromSnapshot($snapshot);
        }
    }

    /**
     * Bootstrap: ASL handshake + first rules refresh.
     *
     * Safe to call once at process/request start. Never throws — failures are
     * reported via `onError`. Returns whether the rules store is ready for local
     * eval (cached rules with a valid lease may still make this true after a
     * failed network refresh).
     *
     * @return bool True when {@see RulesStore::isReady()} after the attempt
     */
    public function ready(): bool
    {
        if (!$this->enableFlagmint) {
            $this->ready = true;

            return true;
        }

        try {
            $this->refresh();
            $this->ready = $this->rulesStore->isReady();

            return $this->ready;
        } catch (\Throwable $e) {
            $this->emitError(ErrorCode::INTERNAL, $e->getMessage());
            $this->ready = $this->rulesStore->isReady();

            return $this->ready;
        }
    }

    /**
     * Re-handshake and pull config (`fullConfig` or delta catch-up).
     *
     * Call periodically (Artisan / Supervisor worker) or after you know flags
     * changed. On success, persists a {@see RulesSnapshot} through the cache adapter.
     * Soft failures (401/429/network) emit `onError` and leave prior rules in place
     * until the lease expires.
     */
    public function refresh(): void
    {
        if (!$this->enableFlagmint) {
            return;
        }

        $this->handshake();
        $sinceVersion = $this->rulesStore->getState()->needsFullConfig
            ? null
            : $this->rulesStore->getState()->version;

        $query = ['sessionId' => $this->sessionId];
        if ($sinceVersion !== null && $sinceVersion > 0) {
            $query['sinceVersion'] = (string) $sinceVersion;
        }
        $url = $this->restEndpoint . '/evaluator/v2/flags/config?' . http_build_query($query);

        $response = $this->transport->request('GET', $url, [
            'x-api-key' => $this->apiKey,
            'Accept' => 'application/json',
        ]);

        if ($response['status'] === 401 || $response['status'] === 403) {
            $this->emitError(ErrorCode::AUTH, 'Config refresh unauthorized: HTTP ' . $response['status']);

            return;
        }
        if ($response['status'] === 429) {
            $this->emitError(ErrorCode::RATE_LIMITED, 'Config refresh rate limited');

            return;
        }
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['body'])) {
            $this->emitError(ErrorCode::NETWORK, 'Config refresh failed: HTTP ' . $response['status']);

            return;
        }

        $this->applyConfigResponse($response['body']);
        if ($this->rulesStore->isReady()) {
            $this->cacheAdapter->saveRulesSnapshot($this->apiKey, $this->rulesStore->toSnapshot());
        }
    }

    /**
     * Whether a boolean flag is on for the given visitor context.
     *
     * Alias of {@see bool()} with argument order matching dashboard snippets.
     *
     * @param string $flagKey Flag key from the Flagmint dashboard
     * @param array<string, mixed>|null $context Evaluation context, e.g.
     *     `['kind' => 'user', 'key' => 'u_123', 'plan' => 'premium']`
     * @param bool $fallback Returned when Flagmint is disabled, the lease is
     *     expired, or the flag key is unknown
     */
    public function isEnabled(string $flagKey, ?array $context = null, bool $fallback = false): bool
    {
        return (bool) $this->bool($flagKey, $fallback, $context);
    }

    /**
     * Evaluate any flag type and return the variation value (or `$fallback`).
     *
     * @param string $flagKey Flag key
     * @param mixed $fallback Safe default when eval cannot run
     * @param array<string, mixed>|null $context Per-call evaluation context
     * @return mixed Variation value (bool|string|number|array) or fallback
     */
    public function getFlag(string $flagKey, mixed $fallback = null, ?array $context = null): mixed
    {
        return $this->evaluate($flagKey, $fallback, $context);
    }

    /**
     * Locally evaluate a boolean flag.
     *
     * @param string $flagKey Flag key
     * @param bool $fallback Default when not ready / missing / disabled
     * @param array<string, mixed>|null $context Per-call evaluation context
     */
    public function bool(string $flagKey, bool $fallback = false, ?array $context = null): bool
    {
        return (bool) $this->evaluate($flagKey, $fallback, $context);
    }

    /**
     * Locally evaluate a string flag.
     *
     * @param string $flagKey Flag key
     * @param string $fallback Default when not ready / missing / disabled
     * @param array<string, mixed>|null $context Per-call evaluation context
     */
    public function string(string $flagKey, string $fallback = '', ?array $context = null): string
    {
        return (string) $this->evaluate($flagKey, $fallback, $context);
    }

    /**
     * Locally evaluate a number flag (int or float).
     *
     * @param string $flagKey Flag key
     * @param int|float $fallback Default when not ready / missing / disabled
     * @param array<string, mixed>|null $context Per-call evaluation context
     */
    public function number(string $flagKey, int|float $fallback = 0, ?array $context = null): int|float
    {
        $value = $this->evaluate($flagKey, $fallback, $context);

        return is_numeric($value) ? $value + 0 : $fallback;
    }

    /**
     * Locally evaluate a JSON flag (array / object payload).
     *
     * @param string $flagKey Flag key
     * @param mixed $fallback Default when not ready / missing / disabled
     * @param array<string, mixed>|null $context Per-call evaluation context
     * @return array<string, mixed>|list<mixed>|mixed|null
     */
    public function json(string $flagKey, mixed $fallback = null, ?array $context = null): mixed
    {
        return $this->evaluate($flagKey, $fallback, $context);
    }

    /**
     * Buffer a custom analytics event (`kind: custom`) for later flush.
     *
     * Does not hit the network until {@see flushEvents()} (or the Laravel queue job).
     *
     * @param string $flagKey Related flag key (dashboard attribution)
     * @param array<string, mixed> $properties Arbitrary JSON-serializable props
     */
    public function track(string $flagKey, array $properties = []): void
    {
        $this->eventBuffer->push([
            'flagKey' => $flagKey,
            'kind' => 'custom',
            'properties' => $properties,
            'timestamp' => (int) (microtime(true) * 1000),
        ]);
    }

    /**
     * Buffer an application error event (`kind: error`) for later flush.
     *
     * @param string $flagKey Related flag key
     * @param array<string, mixed> $properties e.g. `['message' => '…', 'code' => '…']`
     */
    public function trackError(string $flagKey, array $properties = []): void
    {
        $this->eventBuffer->push([
            'flagKey' => $flagKey,
            'kind' => 'error',
            'properties' => $properties,
            'timestamp' => (int) (microtime(true) * 1000),
        ]);
    }

    /**
     * POST buffered events to `/evaluator/events` and clear the buffer.
     *
     * @return bool False when the HTTP call failed (events already drained)
     */
    public function flushEvents(): bool
    {
        $events = $this->eventBuffer->drain();
        if ($events === [] || !$this->enableFlagmint) {
            return true;
        }

        $response = $this->transport->request(
            'POST',
            $this->restEndpoint . '/evaluator/events',
            ['x-api-key' => $this->apiKey, 'Accept' => 'application/json'],
            ['events' => $events],
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $this->emitError(ErrorCode::NETWORK, 'Event flush failed: HTTP ' . $response['status']);

            return false;
        }

        return true;
    }

    /**
     * Access the in-memory rules store (advanced / diagnostics).
     */
    public function getRulesStore(): RulesStore
    {
        return $this->rulesStore;
    }

    /**
     * Access the in-process event buffer (advanced / Laravel queue drain).
     */
    public function getEventBuffer(): EventBuffer
    {
        return $this->eventBuffer;
    }

    /**
     * The cache adapter bound at construction time.
     */
    public function getCacheAdapter(): CacheAdapter
    {
        return $this->cacheAdapter;
    }

    /**
     * Shared local-eval path for typed readers.
     *
     * @param array<string, mixed>|null $context
     */
    private function evaluate(string $flagKey, mixed $fallback, ?array $context): mixed
    {
        if (!$this->enableFlagmint) {
            return $fallback;
        }

        if (!$this->rulesStore->isReady()) {
            $this->emitError(ErrorCode::LEASE_EXPIRED, 'Rules lease expired or not ready; using fallback');

            return $fallback;
        }

        $flag = $this->rulesStore->getFlag($flagKey);
        if ($flag === null) {
            return $fallback;
        }

        try {
            return $this->evaluator->evaluate($flag, $context, $this->rulesStore->getState()->segments);
        } catch (\Throwable $e) {
            $this->emitError(ErrorCode::INTERNAL, $e->getMessage());

            return $fallback;
        }
    }

    private function handshake(): void
    {
        $pair = AslEcdh::generateClientKeyPair();
        $this->privateKey = $pair['privateKey'];

        $response = $this->transport->request(
            'POST',
            $this->handshakeEndpoint,
            ['x-api-key' => $this->apiKey, 'Accept' => 'application/json'],
            ['clientPublicKey' => $pair['publicKeyHex']],
        );

        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['body'])) {
            throw new \RuntimeException('ASL handshake failed: HTTP ' . $response['status']);
        }

        $body = $response['body'];
        $sessionId = $body['sessionId'] ?? null;
        $serverPublicKey = $body['serverPublicKey'] ?? null;
        $salt = $body['salt'] ?? null;
        if (!is_string($sessionId) || !is_string($serverPublicKey) || !is_string($salt)) {
            throw new \RuntimeException('ASL ECDH incomplete: serverPublicKey/salt/sessionId missing');
        }

        $keyAgreement = $body['keyAgreement'] ?? AslEcdh::KEY_AGREEMENT;
        if ($keyAgreement !== AslEcdh::KEY_AGREEMENT) {
            throw new \RuntimeException('ASL ECDH unsupported key agreement: ' . (string) $keyAgreement);
        }

        $macKey = AslEcdh::deriveMacKey($this->privateKey, $serverPublicKey, $salt);
        $this->rulesStore->setMacKey($macKey);
        $this->sessionId = $sessionId;
        AslEcdh::wipe($this->privateKey);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyConfigResponse(array $body): void
    {
        // REST may return a single payload or { payloads: [...] } / nested types.
        if (isset($body['type']) && is_string($body['type'])) {
            $this->rulesStore->apply($body);

            return;
        }

        foreach (['lease', 'fullConfig', 'delta', 'deltas'] as $key) {
            if (isset($body[$key]) && is_array($body[$key])) {
                $payload = $body[$key];
                if (!isset($payload['type'])) {
                    $payload['type'] = $key;
                }
                $this->rulesStore->apply($payload);
            }
        }

        if (isset($body['payloads']) && is_array($body['payloads'])) {
            foreach ($body['payloads'] as $payload) {
                if (is_array($payload)) {
                    $this->rulesStore->apply($payload);
                }
            }
        }
    }

    /**
     * @param string $code
     * @param string $message
     */
    private function emitError(string $code, string $message): void
    {
        if ($this->onError !== null) {
            ($this->onError)(['code' => $code, 'message' => $message]);
        }
    }

    /**
     * @return array{rest: string, handshake: string}
     */
    private static function endpointsForEnv(string $env): array
    {
        return match ($env) {
            'staging' => [
                'rest' => 'https://staging-api.flagmint.com',
                'handshake' => 'https://staging-api.flagmint.com/auth/asl-handshake',
            ],
            'local' => [
                'rest' => 'http://localhost:3000',
                'handshake' => 'http://localhost:3000/auth/asl-handshake',
            ],
            default => [
                'rest' => 'https://api.flagmint.com',
                'handshake' => 'https://api.flagmint.com/auth/asl-handshake',
            ],
        };
    }
}
