<?php

declare(strict_types=1);

namespace Flagmint\Support;

/**
 * SDK identity sent on handshake, config refresh, and event posts.
 *
 * Mirrors JS `sdkVersion` / `platform` / `wrapperName` / `wrapperVersion` so
 * Flagmint can attribute connections and traffic by client.
 */
final class SdkIdentity
{
    /** Packaged core SDK semver (keep in sync with CHANGELOG). */
    public const VERSION = '0.1.1';

    public const PLATFORM = 'php';

    /**
     * @param string|null $wrapperName e.g. `flagmint-laravel`
     * @param string|null $wrapperVersion Wrapper package version
     * @param string|null $sdkVersion Override core version (tests)
     */
    public function __construct(
        public readonly ?string $wrapperName = null,
        public readonly ?string $wrapperVersion = null,
        public readonly ?string $sdkVersion = null,
    ) {
    }

    /**
     * Core SDK version string.
     */
    public function version(): string
    {
        return $this->sdkVersion ?? self::VERSION;
    }

    /**
     * Query / body fields for Flagmint telemetry.
     *
     * @return array{
     *   sdkVersion: string,
     *   platform: string,
     *   wrapperName: string,
     *   wrapperVersion: string
     * }
     */
    public function toQueryParams(): array
    {
        return [
            'sdkVersion' => $this->version(),
            'platform' => self::PLATFORM,
            'wrapperName' => $this->wrapperName ?? 'native-php',
            'wrapperVersion' => $this->wrapperVersion ?? 'none',
        ];
    }

    /**
     * HTTP headers for the same identity (survives when query schemas strip unknowns).
     *
     * @return array<string, string>
     */
    public function toHeaders(): array
    {
        $params = $this->toQueryParams();

        return [
            'X-Flagmint-Sdk-Version' => $params['sdkVersion'],
            'X-Flagmint-Platform' => $params['platform'],
            'X-Flagmint-Wrapper-Name' => $params['wrapperName'],
            'X-Flagmint-Wrapper-Version' => $params['wrapperVersion'],
            'User-Agent' => sprintf(
                'Flagmint-PHP/%s (%s/%s)',
                $params['sdkVersion'],
                $params['wrapperName'],
                $params['wrapperVersion'],
            ),
        ];
    }
}
