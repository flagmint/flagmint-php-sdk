# Config-sync parity notes

Aligned with:

- `FF-EU/documentation/CONFIG_SYNC_QA.md`
- `flagmint-js-sdk/sdk/core/config-sync/*`
- Go `evaluate` package for targeting semantics

## Crypto

- X25519 ECDH (`sodium_crypto_scalarmult`)
- HKDF-SHA256 info: `flagmint-asl-config-sync-mac-v1`
- HMAC-SHA256 over canonicalized JSON (sorted keys), hex digest

## Rules reduce

- `lease` renews expiry only; version mismatch sets `needsFullConfig` without advancing bookmark
- `fullConfig` replaces flags/segments
- `delta` / `deltas` require contiguous `fromVersion`
- Expired payloads mark store not ready

## REST

`GET /evaluator/v2/flags/config?sessionId=&sinceVersion=` with `x-api-key`.
