<?php

declare(strict_types=1);

namespace Flagmint\Tests\Unit;

use Flagmint\Support\SdkIdentity;
use PHPUnit\Framework\TestCase;

final class SdkIdentityTest extends TestCase
{
    public function testNativeDefaults(): void
    {
        $id = new SdkIdentity();
        $params = $id->toQueryParams();

        $this->assertSame(SdkIdentity::VERSION, $params['sdkVersion']);
        $this->assertSame('php', $params['platform']);
        $this->assertSame('native-php', $params['wrapperName']);
        $this->assertSame('none', $params['wrapperVersion']);
    }

    public function testWrapperAndVersionOverride(): void
    {
        $id = new SdkIdentity(
            wrapperName: 'flagmint-laravel',
            wrapperVersion: '0.1.1',
            sdkVersion: '9.9.9',
        );
        $params = $id->toQueryParams();

        $this->assertSame('9.9.9', $params['sdkVersion']);
        $this->assertSame('flagmint-laravel', $params['wrapperName']);
        $this->assertSame('0.1.1', $params['wrapperVersion']);

        $headers = $id->toHeaders();
        $this->assertSame('9.9.9', $headers['X-Flagmint-Sdk-Version']);
        $this->assertSame('php', $headers['X-Flagmint-Platform']);
        $this->assertSame('flagmint-laravel', $headers['X-Flagmint-Wrapper-Name']);
        $this->assertSame('0.1.1', $headers['X-Flagmint-Wrapper-Version']);
        $this->assertSame('Flagmint-PHP/9.9.9 (flagmint-laravel/0.1.1)', $headers['User-Agent']);
    }
}
