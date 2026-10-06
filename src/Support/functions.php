<?php

declare(strict_types=1);

namespace Flagmint\Support;

/**
 * Resolve the visitor id used for unique-user HyperLogLog from evaluation context.
 * Prefers nested `user.key`, then `userKey`, then top-level `key` (JS parity).
 *
 * @param array<string, mixed>|null $ctx Evaluation context from the SDK
 * @return string|null Plaintext user key, or null when none is present
 */
function userKeyFromContext(?array $ctx): ?string
{
    if ($ctx === null) {
        return null;
    }

    $user = $ctx['user'] ?? null;
    if (is_array($user) && isset($user['key']) && is_string($user['key']) && $user['key'] !== '') {
        return $user['key'];
    }
    if (isset($ctx['userKey']) && is_string($ctx['userKey']) && $ctx['userKey'] !== '') {
        return $ctx['userKey'];
    }
    if (isset($ctx['key']) && is_string($ctx['key']) && $ctx['key'] !== '') {
        return $ctx['key'];
    }

    return null;
}
