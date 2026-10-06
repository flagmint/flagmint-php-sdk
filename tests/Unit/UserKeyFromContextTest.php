<?php

declare(strict_types=1);

namespace Flagmint\Tests\Unit;

use Flagmint\Support\UserKey;
use PHPUnit\Framework\TestCase;

final class UserKeyFromContextTest extends TestCase
{
    public function testPrefersNestedUserKeyThenUserKeyThenKey(): void
    {
        $this->assertSame('nested', UserKey::fromContext([
            'user' => ['key' => 'nested'],
            'userKey' => 'flat',
            'key' => 'top',
        ]));
        $this->assertSame('flat', UserKey::fromContext([
            'userKey' => 'flat',
            'key' => 'top',
        ]));
        $this->assertSame('top', UserKey::fromContext(['key' => 'top']));
        $this->assertNull(UserKey::fromContext(['user' => ['key' => '']]));
        $this->assertNull(UserKey::fromContext(null));
    }

    public function testMultiContextUsesNestedUserKey(): void
    {
        $this->assertSame('u1', UserKey::fromContext([
            'kind' => 'multi',
            'user' => ['kind' => 'user', 'key' => 'u1'],
            'organization' => ['kind' => 'organization', 'key' => 'acme'],
        ]));
    }
}
