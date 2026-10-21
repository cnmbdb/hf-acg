<?php
declare(strict_types=1);

namespace App\Plugin\ContentCenter\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $template_key
 * @property string $name
 * @property string|null $page_title
 * @property string|null $page_description
 * @property string $body_mdx
 * @property array|string|null $settings_json
 * @property string|null $published_page_title
 * @property string|null $published_page_description
 * @property string|null $published_body_mdx
 * @property array|string|null $published_settings_json
 * @property string $status
 * @property string|null $published_at
 * @property int $author_id
 * @property string|null $git_sha
 * @property string|null $publish_error
 * @property string $create_time
 * @property string $update_time
 */
class Template extends Model
{
    protected $table = 'content_center_template';

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'author_id' => 'integer',
        'settings_json' => 'array',
        'published_settings_json' => 'array',
    ];
}
