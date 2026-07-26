<?php
declare(strict_types=1);

namespace App\Plugin\ContentCenter\Service;

use App\Plugin\ContentCenter\Model\Content;
use App\Plugin\ContentCenter\Model\Template;
use App\Util\Plugin;
use GuzzleHttp\Client;
use Kernel\Exception\JSONException;

final class GitHubPublisher
{
    public function publish(Content $content): string
    {
        if ($content->content_type !== 'blog') {
            throw new JSONException('当前内容中心只发布博客文章');
        }

        [$client, $owner, $repo, $branch, $root] = $this->context();
        $templates = new TemplateService();
        $detailTemplate = $templates->effective('blog_detail');
        $homeTemplate = $templates->effective('blog_home');

        $articleEndpoint = $this->endpoint($owner, $repo, $this->fullPath($root, 'blog/' . $content->slug . '.mdx'));
        $articleSha = $this->putFile(
            $client,
            $articleEndpoint,
            $branch,
            $this->articleMdx($content, $detailTemplate),
            'docs: publish article ' . $content->slug
        );

        $articles = $this->articles($content);
        $this->putFile(
            $client,
            $this->endpoint($owner, $repo, $this->fullPath($root, 'index.mdx')),
            $branch,
            $this->homeMdx($homeTemplate, $articles),
            'docs: update article homepage'
        );
        $this->putFile(
            $client,
            $this->endpoint($owner, $repo, $this->fullPath($root, 'blog/index.mdx')),
            $branch,
            $this->listMdx($articles),
            'docs: update article list'
        );

        return $articleSha;
    }

    public function publishTemplate(Template $template): string
    {
        [$client, $owner, $repo, $branch, $root] = $this->context();
        $draft = [
            'page_title' => $template->page_title,
            'page_description' => $template->page_description,
            'body_mdx' => $template->body_mdx,
        ];
        $articles = $this->articles();

        if ($template->template_key === 'blog_home') {
            return $this->putFile(
                $client,
                $this->endpoint($owner, $repo, $this->fullPath($root, 'index.mdx')),
                $branch,
                $this->homeMdx($draft, $articles),
                'docs: publish article homepage template'
            );
        }
        if ($template->template_key !== 'blog_detail') {
            throw new JSONException('页面模板无效');
        }

        $sha = '';
        foreach ($articles as $article) {
            $sha = $this->putFile(
                $client,
                $this->endpoint($owner, $repo, $this->fullPath($root, 'blog/' . $article->slug . '.mdx')),
                $branch,
                $this->articleMdx($article, $draft),
                'docs: apply article detail template to ' . $article->slug
            );
        }
        return $sha;
    }

    /** @return array{0:Client,1:string,2:string,3:string,4:string} */
    private function context(): array
    {
        $config = Plugin::getConfig('ContentCenter', false);
        $owner = trim((string)($config['github_owner'] ?? ''));
        $repo = trim((string)($config['github_repo'] ?? ''));
        $branch = trim((string)($config['github_branch'] ?? 'main')) ?: 'main';
        $root = trim((string)($config['docs_path'] ?? ''), '/');
        $token = Environment::get('CONTENT_CENTER_GITHUB_TOKEN');

        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $owner) || !preg_match('/^[A-Za-z0-9_.-]+$/', $repo)) {
            throw new JSONException('请先在内容中心插件设置中填写 GitHub 仓库的 Owner 和仓库名');
        }
        if ($token === '') {
            throw new JSONException('未配置 CONTENT_CENTER_GITHUB_TOKEN，发布凭据只能放在服务器 .env 中');
        }

        return [
            new Client([
                'base_uri' => 'https://api.github.com/',
                'timeout' => 20,
                'http_errors' => false,
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'Authorization' => 'Bearer ' . $token,
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'User-Agent' => 'acg-faka-content-center',
                ],
            ]),
            $owner,
            $repo,
            $branch,
            $root,
        ];
    }

    /** @return array<int, Content> */
    private function articles(?Content $current = null): array
    {
        $items = Content::query()
            ->where('content_type', 'blog')
            ->where('status', 'published')
            ->orderByDesc('sort_order')
            ->orderByDesc('id')
            ->get()
            ->keyBy('id');
        if ($current) {
            $items->put($current->id, $current);
        }
        $items = $items->values()->all();
        usort($items, static fn(Content $a, Content $b): int =>
            ((int)$b->sort_order <=> (int)$a->sort_order)
            ?: ((int)$b->id <=> (int)$a->id)
        );
        return array_values($items);
    }

    /** @param array{page_title:?string,page_description:?string,body_mdx:string} $template */
    private function articleMdx(Content $content, array $template): string
    {
        $body = strtr($template['body_mdx'], [
            '{{title}}' => (string)$content->title,
            '{{excerpt}}' => (string)($content->excerpt ?: ''),
            '{{content}}' => trim((string)$content->body_mdx),
            '{{slug}}' => (string)$content->slug,
            '{{published_at}}' => substr((string)($content->published_at ?: date('Y-m-d H:i:s')), 0, 10),
            '{{cover}}' => (string)($content->cover ?: ''),
            '{{tags}}' => implode(', ', is_array($content->tags) ? $content->tags : []),
        ]);

        $metadata = [
            'title' => $content->title,
            'description' => (string)($content->excerpt ?: $content->title),
            'date' => substr((string)($content->published_at ?: date('Y-m-d H:i:s')), 0, 10),
        ];
        if ($content->cover) {
            $metadata['image'] = $content->cover;
        }
        $tags = is_array($content->tags) ? $content->tags : [];
        if ($tags) {
            $metadata['tags'] = implode(', ', $tags);
        }
        return $this->document($metadata, $body);
    }

    /** @param array{page_title:?string,page_description:?string,body_mdx:string} $template @param array<int,Content> $articles */
    private function homeMdx(array $template, array $articles): string
    {
        $latest = array_slice($articles, 0, 5);
        $body = strtr($template['body_mdx'], [
            '{{latest_articles}}' => $this->articleLinks($latest),
            '{{articles}}' => $this->articleLinks($articles),
            '{{article_count}}' => (string)count($articles),
        ]);
        return $this->document([
            'title' => (string)($template['page_title'] ?: '乐多博客'),
            'description' => (string)($template['page_description'] ?: '文章、教程与项目更新'),
        ], $body);
    }

    /** @param array<int,Content> $articles */
    private function listMdx(array $articles): string
    {
        return $this->document([
            'title' => '全部文章',
            'description' => '查看全部已发布文章',
        ], "# 全部文章\n\n" . $this->articleLinks($articles));
    }

    /** @param array<int,Content> $articles */
    private function articleLinks(array $articles): string
    {
        if (!$articles) {
            return '暂无已发布文章。';
        }
        $lines = [];
        foreach ($articles as $article) {
            $line = '- [' . $article->title . '](/blog/' . $article->slug . ')';
            $excerpt = trim((string)$article->excerpt);
            $lines[] = $excerpt === '' ? $line : $line . ' — ' . str_replace(["\r", "\n"], ' ', $excerpt);
        }
        return implode("\n", $lines);
    }

    /** @param array<string,string> $metadata */
    private function document(array $metadata, string $body): string
    {
        $frontmatter = [];
        foreach ($metadata as $key => $value) {
            $value = str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ' '], (string)$value);
            $frontmatter[] = $key . ': "' . $value . '"';
        }
        return "---\n" . implode("\n", $frontmatter) . "\n---\n\n" . trim($body) . "\n";
    }

    private function fullPath(string $root, string $path): string
    {
        return ($root === '' ? '' : $root . '/') . ltrim($path, '/');
    }

    private function endpoint(string $owner, string $repo, string $path): string
    {
        return sprintf(
            'repos/%s/%s/contents/%s',
            rawurlencode($owner),
            rawurlencode($repo),
            implode('/', array_map('rawurlencode', explode('/', $path)))
        );
    }

    private function putFile(Client $client, string $endpoint, string $branch, string $contents, string $message): string
    {
        $existing = $client->get($endpoint, ['query' => ['ref' => $branch]]);
        $existingPayload = json_decode((string)$existing->getBody(), true);
        if ($existing->getStatusCode() !== 200 && $existing->getStatusCode() !== 404) {
            throw new JSONException('无法读取文档仓库：' . $this->message($existingPayload, $existing->getStatusCode()));
        }
        $payload = ['message' => $message, 'content' => base64_encode($contents), 'branch' => $branch];
        if (is_array($existingPayload) && !empty($existingPayload['sha'])) {
            $payload['sha'] = $existingPayload['sha'];
        }
        $response = $client->put($endpoint, ['json' => $payload]);
        $responsePayload = json_decode((string)$response->getBody(), true);
        if (!in_array($response->getStatusCode(), [200, 201], true)) {
            throw new JSONException('GitHub 提交失败：' . $this->message($responsePayload, $response->getStatusCode()));
        }
        $sha = (string)($responsePayload['commit']['sha'] ?? '');
        if ($sha === '') {
            throw new JSONException('GitHub 未返回提交编号');
        }
        return $sha;
    }

    private function message(mixed $payload, int $status): string
    {
        if (is_array($payload) && isset($payload['message']) && is_scalar($payload['message'])) {
            return (string)$payload['message'];
        }
        return 'HTTP ' . $status;
    }
}
