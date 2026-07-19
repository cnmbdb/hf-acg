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
        if ($schema->hasTable('content_center_content')) {
            return;
        }

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
}
