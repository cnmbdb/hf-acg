<?php
declare(strict_types=1);

namespace App\Plugin\ContentCenter\Service;

use App\Plugin\ContentCenter\Model\Template;
use App\Util\Date;
use Kernel\Exception\JSONException;

final class TemplateService
{
    public const KEYS = ['blog_home', 'blog_detail'];

    /** @return array<int, Template> */
    public function all(): array
    {
        Schema::ensure();
        $items = Template::query()->whereIn('template_key', self::KEYS)->get()->keyBy('template_key');
        $ordered = [];
        foreach (self::KEYS as $key) {
            if ($items->has($key)) {
                $ordered[] = $items->get($key);
            }
        }
        return $ordered;
    }

    public function find(string $key): Template
    {
        Schema::ensure();
        if (!in_array($key, self::KEYS, true)) {
            throw new JSONException('页面模板无效');
        }
        $template = Template::query()->where('template_key', $key)->first();
        if (!$template) {
            throw new JSONException('页面模板不存在');
        }
        return $template;
    }

    public function save(array $input, int $authorId): Template
    {
        $template = $this->find(trim((string)($input['template_key'] ?? '')));
        $body = trim((string)($input['body_mdx'] ?? ''));
        if ($body === '' || strlen($body) > 512000 || str_contains($body, "\0")) {
            throw new JSONException('模板内容不能为空、不能含空字节且不能超过 500KB');
        }

        $requiredPlaceholder = [
            'blog_home' => '{{latest_articles}}',
            'blog_detail' => '{{content}}',
        ][$template->template_key];
        if (!str_contains($body, $requiredPlaceholder)) {
            throw new JSONException($template->name . '必须保留占位符 ' . $requiredPlaceholder);
        }

        if ($template->template_key === 'blog_detail') {
            $template->page_title = null;
            $template->page_description = null;
            $template->settings_json = null;
        } else {
            $template->page_title = $this->text($input['page_title'] ?? '', 160, '页面标题');
            $template->page_description = $this->nullableText($input['page_description'] ?? '', 500, '页面描述');
            $template->settings_json = BlogHomeSettings::fromInput($input['settings_json'] ?? []);
        }
        $template->body_mdx = $body;
        $template->status = 'draft';
        $template->publish_error = null;
        $template->author_id = $authorId;
        $template->update_time = Date::current();
        $template->save();
        return $template;
    }

    public function publish(Template $template): Template
    {
        $template->publish_error = null;
        try {
            $sha = (new GitHubPublisher())->publishTemplate($template);
            if ($sha !== '') {
                $template->git_sha = $sha;
            }
            $template->published_page_title = $template->page_title;
            $template->published_page_description = $template->page_description;
            $template->published_body_mdx = $template->body_mdx;
            $template->published_settings_json = $template->settings_json;
            $template->status = 'published';
            $template->published_at = Date::current();
        } catch (\Throwable $e) {
            $template->status = 'failed';
            $template->publish_error = mb_substr($e->getMessage(), 0, 2000);
            $template->update_time = Date::current();
            $template->save();
            throw $e;
        }
        $template->update_time = Date::current();
        $template->save();
        return $template;
    }

    /** @return array{page_title:?string,page_description:?string,body_mdx:string,settings_json:array} */
    public function effective(string $key): array
    {
        $template = $this->find($key);
        return [
            'page_title' => $template->published_page_title ?? $template->page_title,
            'page_description' => $template->published_page_description ?? $template->page_description,
            'body_mdx' => $template->published_body_mdx ?? $template->body_mdx,
            'settings_json' => $key === 'blog_home'
                ? BlogHomeSettings::effective($template->settings_json ?? $template->published_settings_json)
                : [],
        ];
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
}
