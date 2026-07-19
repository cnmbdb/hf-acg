<?php
declare(strict_types=1);

namespace App\Plugin\ContentCenter\Service;

use App\Plugin\ContentCenter\Model\Content;
use App\Util\Date;
use Kernel\Exception\JSONException;

final class ContentService
{
    public function save(array $input, int $authorId): Content
    {
        Schema::ensure();
        $id = (int)($input['id'] ?? 0);
        $content = $id > 0 ? Content::query()->find($id) : new Content();
        if (!$content) {
            throw new JSONException('内容不存在');
        }

        $type = (string)($input['content_type'] ?? $content->content_type ?? '');
        if (!in_array($type, ['blog', 'page'], true)) {
            throw new JSONException('内容类型无效');
        }
        $title = $this->text($input['title'] ?? '', 160, '标题');
        $slug = strtolower(trim((string)($input['slug'] ?? '')));
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) > 160) {
            throw new JSONException('Slug 只能包含小写字母、数字和连字符');
        }
        if ($slug === 'index') {
            throw new JSONException('Slug 不能为 index，它由内容中心自动维护为目录页');
        }
        $body = trim((string)($input['body_mdx'] ?? ''));
        if ($body === '' || strlen($body) > 512000 || str_contains($body, "\0")) {
            throw new JSONException('正文不能为空、不能含空字节且不能超过 500KB');
        }
        $exists = Content::query()->where('content_type', $type)->where('slug', $slug);
        if ($content->exists) {
            $exists->where('id', '<>', $content->id);
        }
        if ($exists->exists()) {
            throw new JSONException('同类型下 Slug 已存在');
        }

        $content->content_type = $type;
        $content->title = $title;
        $content->slug = $slug;
        $content->excerpt = $this->nullableText($input['excerpt'] ?? '', 500, '摘要');
        $content->body_mdx = $body;
        $content->cover = $this->nullableUrl($input['cover'] ?? '');
        $content->tags = $this->tags($input['tags'] ?? '');
        $content->status = 'draft';
        $content->author_id = $authorId;
        $content->update_time = Date::current();
        if (!$content->exists) {
            $content->create_time = Date::current();
        }
        $content->save();
        return $content;
    }

    public function publish(Content $content): Content
    {
        $content->publish_error = null;
        try {
            $content->git_sha = (new GitHubPublisher())->publish($content);
            $content->status = 'published';
            $content->published_at = $content->published_at ?: Date::current();
        } catch (\Throwable $e) {
            $content->status = 'failed';
            $content->publish_error = mb_substr($e->getMessage(), 0, 2000);
            $content->update_time = Date::current();
            $content->save();
            throw $e;
        }
        $content->update_time = Date::current();
        $content->save();
        return $content;
    }

    private function text(mixed $value, int $limit, string $label): string
    {
        $value = trim(strip_tags((string)$value));
        if ($value === '' || mb_strlen($value) > $limit || str_contains($value, "\0")) {
            throw new JSONException($label . '不能为空且不能超过 ' . $limit . ' 个字符');
        }
        return $value;
    }

    private function nullableText(mixed $value, int $limit, string $label): ?string
    {
        $value = trim(strip_tags((string)$value));
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $limit || str_contains($value, "\0")) {
            throw new JSONException($label . '不能超过 ' . $limit . ' 个字符');
        }
        return $value;
    }

    private function nullableUrl(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > 512 || !preg_match('#^(?:https://|/)[^\s]+$#', $value)) {
            throw new JSONException('封面必须是 HTTPS 地址或本站绝对路径');
        }
        return $value;
    }

    private function tags(mixed $raw): array
    {
        $values = is_array($raw) ? $raw : explode(',', (string)$raw);
        $tags = [];
        foreach ($values as $tag) {
            $tag = trim(strip_tags((string)$tag));
            if ($tag === '') {
                continue;
            }
            if (mb_strlen($tag) > 32) {
                throw new JSONException('每个标签不能超过 32 个字符');
            }
            $tags[$tag] = $tag;
        }
        if (count($tags) > 10) {
            throw new JSONException('最多设置 10 个标签');
        }
        return array_values($tags);
    }
}
