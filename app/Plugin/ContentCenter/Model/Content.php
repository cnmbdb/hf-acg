<?php
declare(strict_types=1);

namespace App\Plugin\ContentCenter\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $content_type
 * @property string $title
 * @property string $slug
 * @property string|null $excerpt
 * @property string $body_mdx
 * @property string|null $cover
 * @property array|string|null $tags
 * @property int $sort_order
 * @property string $status
 * @property string|null $published_at
 * @property int $author_id
 * @property string|null $git_sha
 * @property string|null $publish_error
 * @property string $create_time
 * @property string $update_time
 */
class Content extends Model
{
    protected $table = 'content_center_content';

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'author_id' => 'integer',
        'sort_order' => 'integer',
        'tags' => 'array',
    ];
}
