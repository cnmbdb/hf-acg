<?php
declare(strict_types=1);

namespace App\Plugin\ContentCenter\Controller;

use App\Controller\Base\API\Manage;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Plugin\ContentCenter\Model\Content;
use App\Plugin\ContentCenter\Service\ContentService;
use App\Plugin\ContentCenter\Service\Schema;
use Illuminate\Database\Eloquent\Builder;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Api extends Manage
{
    public function data(): array
    {
        Schema::ensure();
        $type = $this->type((string)$this->request->post('content_type'));
        $page = max(1, (int)$this->request->post('page'));
        $limit = min(100, max(1, (int)$this->request->post('limit') ?: 20));
        $keyword = trim((string)$this->request->post('keyword'));
        $status = trim((string)$this->request->post('status'));
        $query = Content::query()->where('content_type', $type);
        if ($keyword !== '') {
            $query->where(static function (Builder $builder) use ($keyword): void {
                $builder->where('title', 'like', '%' . $keyword . '%')->orWhere('slug', 'like', '%' . $keyword . '%');
            });
        }
        if (in_array($status, ['draft', 'published', 'failed'], true)) {
            $query->where('status', $status);
        }
        $total = $query->count();
        $list = $query->orderByDesc('update_time')->forPage($page, $limit)->get()->map(static fn(Content $item): array => self::row($item))->all();
        return $this->json(200, 'success', ['list' => $list, 'total' => $total]);
    }

    public function detail(): array
    {
        Schema::ensure();
        $content = Content::query()->find((int)$this->request->post('id'));
        if (!$content) {
            throw new JSONException('内容不存在');
        }
        return $this->json(200, 'success', self::row($content, true));
    }

    public function save(): array
    {
        $input = (array)$this->request->unsafePost();
        $content = (new ContentService())->save($input, (int)($this->getManage()?->id ?? 0));
        $this->log('保存了' . $this->label($content) . '《' . $content->title . '》');
        return $this->json(200, '保存成功', self::row($content, true));
    }

    public function publish(): array
    {
        Schema::ensure();
        $content = Content::query()->find((int)$this->request->post('id'));
        if (!$content) {
            throw new JSONException('内容不存在');
        }
        $content = (new ContentService())->publish($content);
        $this->log('发布了' . $this->label($content) . '《' . $content->title . '》，Git commit: ' . $content->git_sha);
        return $this->json(200, '已提交到 GitHub，Mintlify 将自动部署', self::row($content, true));
    }

    public function remove(): array
    {
        Schema::ensure();
        $content = Content::query()->find((int)$this->request->post('id'));
        if (!$content) {
            throw new JSONException('内容不存在');
        }
        $label = $this->label($content);
        $title = $content->title;
        $content->delete();
        $this->log('删除了' . $label . '《' . $title . '》的本地记录');
        return $this->json(200, '已删除本地记录');
    }

    private function type(string $type): string
    {
        if (!in_array($type, ['blog', 'page'], true)) {
            throw new JSONException('内容类型无效');
        }
        return $type;
    }

    private function log(string $message): void
    {
        $manage = $this->getManage();
        if ($manage) {
            ManageLog::log($manage, $message);
        }
    }

    private function label(Content $content): string
    {
        return $content->content_type === 'blog' ? '博客文章' : '文档页面';
    }

    private static function row(Content $content, bool $full = false): array
    {
        $row = [
            'id' => $content->id,
            'content_type' => $content->content_type,
            'title' => $content->title,
            'slug' => $content->slug,
            'excerpt' => $content->excerpt,
            'cover' => $content->cover,
            'tags' => is_array($content->tags) ? $content->tags : [],
            'status' => $content->status,
            'published_at' => $content->published_at,
            'git_sha' => $content->git_sha,
            'publish_error' => $content->publish_error,
            'create_time' => $content->create_time,
            'update_time' => $content->update_time,
        ];
        if ($full) {
            $row['body_mdx'] = $content->body_mdx;
        }
        return $row;
    }
}
