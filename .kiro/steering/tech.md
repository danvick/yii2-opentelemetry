# Tech Stack & Build

## Language & Runtime
- PHP >= 8.0
- Uses PHP 8 features: `match` expressions, union types (`string|false`), named arguments, constructor promotion

## Framework
- Yii2 (>= 2.0.16, < 2.1.0) — this is a `yii2-extension` type Composer package
- Hooks into Yii2 via `yii\base\Event::on()` class-level events and `BootstrapInterface`

## Dependencies
- `open-telemetry/sdk` ^1.0 || 2.x-dev — core OTEL API and SDK
- Optional: `yiisoft/yii2-redis`, `yiisoft/yii2-queue`, `yiisoft/yii2-httpclient`

## Autoloading
- PSR-4: `danvick\yii2\otel\` → `src/`
- Test namespace: `danvick\yii2\otel\tests\` → `tests/`

## Testing
- PHPUnit 10.x (schema version 10.5 in phpunit.xml)
- Test bootstrap: `tests/bootstrap.php` (loads Composer autoloader)
- Test directory: `tests/unit/`

## Common Commands

```bash
# Install dependencies
composer install

# Run tests
./vendor/bin/phpunit

# Run a specific test file
./vendor/bin/phpunit tests/unit/SomeTest.php
```
