<?php

declare(strict_types=1);

namespace Flagmint\Support;

/**
 * Resolve the visitor id used for unique-user HyperLogLog from evaluation context.
 * Prefers nested `user.key`, then `userKey`, then top-level `key` (JS parity).
 *
 * Prefer {@see UserKey::fromContext()} — this function is a thin alias for callers
 * that already imported it. Requires Composer `files` autoload.
 *
 * @param array<string, mixed>|null $ctx Evaluation context from the SDK
 * @return string|null Plaintext user key, or null when none is present
 */
function userKeyFromContext(?array $ctx): ?string
{
    return UserKey::fromContext($ctx);
}
