<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$method = new ReflectionMethod(App\Plugin\XiaohuTheme\Controller\Api::class, 'apply');
foreach ($method->getAttributes() as $attribute) {
    // Mirrors Kernel\Annotation\Collector::methodParse(), which instantiates each
    // method attribute before dispatching the controller action.
    $attribute->newInstance();
}

echo "PASS Xiaohu updater controller method attributes are valid\n";
