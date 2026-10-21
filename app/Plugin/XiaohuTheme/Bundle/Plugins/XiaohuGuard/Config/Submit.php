<?php
declare(strict_types=1);

return [
    [
        'title' => '启用小虎时自动恢复',
        'name' => 'auto_restore',
        'type' => 'switch',
        'placeholder' => '开启|关闭',
    ],
    [
        'title' => '严格版本保护',
        'name' => 'strict_version',
        'type' => 'switch',
        'placeholder' => '开启|关闭',
    ],
    [
        'title' => '当前保护版本',
        'name' => 'protected_version',
        'type' => 'input',
        'disabled' => true,
        'submit' => false,
    ],
];
