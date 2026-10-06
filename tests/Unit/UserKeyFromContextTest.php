<?php

declare(strict_types=1);

namespace Flagmint\Tests\Unit;

use PHPUnit\Framework\TestCase;
use function Flagmint\Support\userKeyFromContext;

final class UserKeyFromContextTest extends TestCase
{
    public function testPrefersNestedUserKeyThenUserKeyThenKey(): void
    {
        $this->assertSame('nested', userKeyFromContext([
            'user' => ['key' => 'nested'],
            'userKey' => 'flat',
            'key' => 'top',
        ]));
        $this->assertSame('flat', userKeyFromContext([
            'userKey' => 'flat',
            'key' => 'top',
        ]));
        $this->assertSame('top', userKeyFromContext(['key' => 'top']));
        $this->assertNull(userKeyFromContext(['user' => ['key' => '']]));
        $this->assertNull(userKeyFromContext(null));
    }
}
