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
                $table->unsignedBigInteger('sort_order')->default(0);
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
                $table->longText('settings_json')->nullable();
                $table->string('published_page_title', 160)->nullable();
                $table->text('published_page_description')->nullable();
                $table->longText('published_body_mdx')->nullable();
                $table->longText('published_settings_json')->nullable();
                $table->string('status', 16)->default('draft');
                $table->dateTime('published_at')->nullable();
                $table->unsignedBigInteger('author_id')->default(0);
                $table->string('git_sha', 80)->nullable();
                $table->text('publish_error')->nullable();
                $table->dateTime('create_time');
                $table->dateTime('update_time');
            });
        }

        if (!$schema->hasColumn('content_center_template', 'settings_json')) {
            $schema->table('content_center_template', static function (Blueprint $table): void {
                $table->longText('settings_json')->nullable()->after('body_mdx');
            });
        }
        if (!$schema->hasColumn('content_center_template', 'published_settings_json')) {
            $schema->table('content_center_template', static function (Blueprint $table): void {
                $table->longText('published_settings_json')->nullable()->after('published_body_mdx');
            });
        }
        if (!$schema->hasColumn('content_center_content', 'sort_order')) {
            $schema->table('content_center_content', static function (Blueprint $table): void {
                $table->unsignedBigInteger('sort_order')->default(0)->after('tags');
            });
            $items = DB::table('content_center_content')->orderBy('id')->get(['id']);
            foreach ($items as $position => $item) {
                DB::table('content_center_content')
                    ->where('id', (int)$item->id)
                    ->update(['sort_order' => $position + 1]);
            }
        }

        self::seedTemplates();
    }

    private static function seedTemplates(): void
    {
        $now = date('Y-m-d H:i:s');
        $templates = [
            'blog_home' => [
                'name' => '博客首页',
                'page_title' => '乐多博客',
                'page_description' => '文章、教程与项目更新',
                'body_mdx' => "# 欢迎来到乐多博客\n\n在这里查看最新文章、教程和项目更新。\n\n## 最新文章\n\n{{latest_articles}}\n",
                'settings_json' => BlogHomeSettings::defaults(),
            ],
            'blog_detail' => [
                'name' => '文章详情页',
                'page_title' => null,
                'page_description' => null,
                'body_mdx' => "# {{title}}\n\n{{content}}\n",
                'settings_json' => BlogDetailSettings::defaults(),
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
                'settings_json' => $template['settings_json'] === null ? null : json_encode($template['settings_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'published_page_title' => $template['page_title'],
                'published_page_description' => $template['page_description'],
                'published_body_mdx' => $template['body_mdx'],
                'published_settings_json' => $template['settings_json'] === null ? null : json_encode($template['settings_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 'published',
                'published_at' => $now,
                'author_id' => 0,
                'git_sha' => null,
                'publish_error' => null,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }

        $home = DB::table('content_center_template')->where('template_key', 'blog_home');
        $home->update(['name' => '博客首页']);
        $defaults = json_encode(BlogHomeSettings::defaults(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        DB::table('content_center_template')
            ->where('template_key', 'blog_home')
            ->whereNull('settings_json')
            ->update(['settings_json' => $defaults]);
        DB::table('content_center_template')
            ->where('template_key', 'blog_home')
            ->whereNull('published_settings_json')
            ->update(['published_settings_json' => $defaults]);

        $detailDefaults = json_encode(BlogDetailSettings::defaults(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        DB::table('content_center_template')
            ->where('template_key', 'blog_detail')
            ->whereNull('settings_json')
            ->update(['settings_json' => $detailDefaults]);
        DB::table('content_center_template')
            ->where('template_key', 'blog_detail')
            ->whereNull('published_settings_json')
            ->update(['published_settings_json' => $detailDefaults]);
    }
}
