# PHP SDK architecture

## Local evaluation

PHP-FPM requests are short-lived. The SDK:

1. Performs ASL ECDH handshake (`POST /auth/asl-handshake` + `clientPublicKey`)
2. Fetches signed rules via `GET /evaluator/v2/flags/config`
3. Stores rules + lease in a `CacheAdapter`
4. Evaluates flags in-process with per-call context

Fail-closed: when lease `expiresAt` passes, reads return fallbacks until a successful refresh.

## Packages

- `flagmint/php-sdk` — core client
- `flagmint/laravel` — Laravel bindings (provider, facade, Blade, middleware, queue flush)

## Fixture format

Golden cases live under `packages/php-sdk/tests/fixtures/`:

- `eval/*.json` — `{ flag, context, segments, expected }`
- `config/*.json` — unsigned config-sync payloads for RulesStore reduce tests

Parity contract: when JS `evaluateSdkFlag` golden cases change, update these fixtures so PHP CI stays aligned.
