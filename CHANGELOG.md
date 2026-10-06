# Changelog

All notable changes to the Flagmint PHP SDK will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> **Note:** This SDK is pre-stable. Breaking changes may occur before `v1.0.0`.

---

## [0.1.0] — 2026-10-06

### Added

- **Core client** (`Flagmint\FlagmintClient`) with `ready()`, `refresh()`, `isEnabled()`,
  `getFlag()`, typed readers (`bool`, `string`, `number`, `json`), `track()`,
  `trackError()`, and `flushEvents()`.
- **Local evaluation** via REST config-sync (`POST /auth/asl-handshake` with ECDH,
  then `GET /evaluator/v2/flags/config`). Flag reads evaluate in-process after
  rules are warm — no per-`getFlag` network call.
- **Rules store** with lease fail-closed semantics, `fullConfig` / `delta` /
  `deltas` reduce, and version-bookmark handling aligned with the JS SDK.
- **Pluggable `CacheAdapter`** for persisting rules snapshots:
  - `ArrayMemoryAdapter` (default)
  - `RedisAdapter` + `PredisRedisClient` / `RedisClient` for multi-worker share
- **Local evaluator** covering kill-switch, custom/segment targeting, and
  percentage/variant rollouts.
- **Config-sync worker** helper (`ConfigSyncWorker` + `bin/config-sync-worker.php`)
  for Supervisor/systemd refresh loops.
- Unit and integration tests (PHPUnit), including HTTP-mocked handshake → config
  → local eval and optional Redis multi-worker tests.
- BSD-3-Clause license.

[0.1.0]: https://github.com/flagmint/flagmint-php-sdk/releases/tag/v0.1.0
