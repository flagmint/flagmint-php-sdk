# flagmint/php-sdk

Flagmint PHP SDK with **local evaluation** and REST config-sync.

```bash
composer require flagmint/php-sdk
composer require guzzlehttp/guzzle # PSR-18 client (or provide your own)
```

```php
use Flagmint\Client;

$client = new Client([
    'apiKey' => getenv('FLAGMINT_SDK_KEY'),
]);
$client->ready();

if ($client->isEnabled('new-checkout', ['kind' => 'user', 'key' => 'u1'])) {
    // …
}
```

## Docs

- Product docs: [docs.flagmint.com/sdks/php](https://docs.flagmint.com/sdks/php)
- Laravel package: [flagmint/laravel](https://github.com/flagmint/flagmint-laravel)
- Architecture notes: [docs/](docs/)
- Changelog: [CHANGELOG.md](CHANGELOG.md)
