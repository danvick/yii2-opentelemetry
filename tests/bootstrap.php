<?php

require dirname(__DIR__) . '/vendor/autoload.php';

// Bootstrap a minimal Yii application context for tests
defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV')   or define('YII_ENV', 'test');

// Minimal Yii bootstrap — provides Yii::$app, Yii::warning(), etc.
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';

// Stubs for app-specific classes referenced in tests but not available in the package
if (!class_exists('common\components\MultiTenantJob')) {
    eval('namespace common\components;
    class MultiTenantJob extends \yii\base\BaseObject {
        public $schoolId;
        public $schoolName;
        public $initiatorUserId;
        public $initiatorUsername;
        public $initiatorFullName;
    }');
}

if (!class_exists('common\components\otel\TenantSpanAttributes')) {
    eval('namespace common\components\otel;
    class TenantSpanAttributes implements \danvick\yii2\otel\SpanAttributeProviderInterface {
        public function getAttributes(): array { return []; }
    }');
}
