<?php
declare(strict_types=1);

namespace App\Plugin\ContentCenter\Service;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;

final class Schema
{
    public static function ensure(): void
    {
        $schema = DB::schema();
        if (!$schema->hasTable('content_center_content')) {
            $schema->create('content_center_content', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('content_type', 16);
                $table->string('title', 160);
                $table->string('slug', 160);
                $table->text('excerpt')->nullable();
                $table->longText('body_mdx');
                $table->string('cover', 512)->nullable();
                $table->text('tags')->nullable();
                $table->string('status', 16)->default('draft');
                $table->dateTime('published_at')->nullable();
                $table->unsignedBigInteger('author_id')->default(0);
                $table->string('git_sha', 80)->nullable();
                $table->text('publish_error')->nullable();
                $table->dateTime('create_time');
                $table->dateTime('update_time');
                $table->unique(['content_type', 'slug'], 'content_center_type_slug_unique');
                $table->index(['content_type', 'status', 'published_at'], 'content_center_listing_index');
            });
        }

        if (!$schema->hasTable('content_center_template')) {
            $schema->create('content_center_template', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('template_key', 32)->unique();
                $table->string('name', 80);
                $table->string('page_title', 160)->nullable();
                $table->text('page_description')->nullable();
                $table->longText('body_mdx');
                $table->string('published_page_title', 160)->nullable();
                $table->text('published_page_description')->nullable();
                $table->longText('published_body_mdx')->nullable();
                $table->string('status', 16)->default('draft');
                $table->dateTime('published_at')->nullable();
                $table->unsignedBigInteger('author_id')->default(0);
                $table->string('git_sha', 80)->nullable();
                $table->text('publish_error')->nullable();
                $table->dateTime('create_time');
                $table->dateTime('update_time');
            });
        }

        self::seedTemplates();
    }

    private static function seedTemplates(): void
    {
        $now = date('Y-m-d H:i:s');
        $templates = [
            'blog_home' => [
                'name' => '文章首页',
                'page_title' => '乐多博客',
                'page_description' => '文章、教程与项目更新',
                'body_mdx' => "# 欢迎来到乐多博客\n\n在这里查看最新文章、教程和项目更新。\n\n## 最新文章\n\n{{latest_articles}}\n",
            ],
            'blog_detail' => [
                'name' => '文章详情页',
                'page_title' => null,
                'page_description' => null,
                'body_mdx' => "# {{title}}\n\n{{content}}\n",
            ],
        ];

        foreach ($templates as $key => $template) {
            if (DB::table('content_center_template')->where('template_key', $key)->exists()) {
                continue;
            }
            DB::table('content_center_template')->insert([
                'template_key' => $key,
                'name' => $template['name'],
                'page_title' => $template['page_title'],
                'page_description' => $template['page_description'],
                'body_mdx' => $template['body_mdx'],
                'published_page_title' => $template['page_title'],
                'published_page_description' => $template['page_description'],
                'published_body_mdx' => $template['body_mdx'],
                'status' => 'published',
                'published_at' => $now,
                'author_id' => 0,
                'git_sha' => null,
                'publish_error' => null,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }
    }
}
