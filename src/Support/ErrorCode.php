<?php

declare(strict_types=1);

namespace Flagmint\Support;

/**
 * Stable `code` strings passed to Client `onError` callbacks.
 *
 * ```php
 * new Client([
 *     'apiKey' => $key,
 *     'onError' => function (array $err): void {
 *         // $err = ['code' => ErrorCode::AUTH, 'message' => '…']
 *         Log::warning('flagmint', $err);
 *     },
 * ]);
 * ```
 *
 * These are soft failures — evaluation methods still return your fallbacks.
 */
final class ErrorCode
{
    /** SDK key rejected or config-sync not allowed for the org (HTTP 401/403). */
    public const AUTH = 'ERR_AUTH';

    /** Too many requests to the evaluator API (HTTP 429). */
    public const RATE_LIMITED = 'ERR_RATE_LIMITED';

    /** Evaluation context failed validation (reserved for future strict checks). */
    public const INVALID_CONTEXT = 'ERR_INVALID_CONTEXT';

    /** Unexpected client/runtime error during refresh or eval. */
    public const INTERNAL = 'ERR_INTERNAL';

    /** Rules lease expired or store not ready — local eval refused. */
    public const LEASE_EXPIRED = 'ERR_LEASE_EXPIRED';

    /** Transport / non-2xx response during refresh or event flush. */
    public const NETWORK = 'ERR_NETWORK';
}
