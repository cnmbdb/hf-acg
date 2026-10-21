<?php
declare(strict_types=1);

return [
    [
        'title' => 'GitHub Owner',
        'name' => 'github_owner',
        'type' => 'input',
        'placeholder' => '文档独立仓库所属账号或组织，例如 cnmbdb',
    ],
    [
        'title' => '文档仓库',
        'name' => 'github_repo',
        'type' => 'input',
        'placeholder' => '只填写仓库名，例如 my-shop-docs',
    ],
    [
        'title' => '发布分支',
        'name' => 'github_branch',
        'type' => 'input',
        'placeholder' => 'main',
    ],
    [
        'title' => '文档目录',
        'name' => 'docs_path',
        'type' => 'input',
        'placeholder' => '仓库根目录留空；若文档在 docs/ 则填写 docs',
    ],
    [
        'title' => '线上文档地址',
        'name' => 'docs_url',
        'type' => 'input',
        'placeholder' => '例如 https://docs.example.com（用于后台跳转与发布后查看）',
    ],
];
