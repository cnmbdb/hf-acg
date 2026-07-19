<?php
declare(strict_types=1);

namespace App\Plugin\ContentCenter\Service;

use App\Plugin\ContentCenter\Model\Content;
use App\Util\Plugin;
use GuzzleHttp\Client;
use Kernel\Exception\JSONException;

final class GitHubPublisher
{
    public function publish(Content $content): string
    {
        $config = Plugin::getConfig('ContentCenter', false);
        $owner = trim((string)($config['github_owner'] ?? ''));
        $repo = trim((string)($config['github_repo'] ?? ''));
        $branch = trim((string)($config['github_branch'] ?? 'main')) ?: 'main';
        $token = Environment::get('CONTENT_CENTER_GITHUB_TOKEN');

        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $owner) || !preg_match('/^[A-Za-z0-9_.-]+$/', $repo)) {
            throw new JSONException('请先在内容中心插件设置中填写独立文档仓库的 Owner 和仓库名');
        }
        if ($token === '') {
            throw new JSONException('未配置 CONTENT_CENTER_GITHUB_TOKEN，发布凭据只能放在服务器 .env 中');
        }

        $path = $this->path($content, (string)($config['docs_path'] ?? ''));
        $root = trim((string)($config['docs_path'] ?? ''), '/');
        $client = new Client([
            'base_uri' => 'https://api.github.com/',
            'timeout' => 20,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'Authorization' => 'Bearer ' . $token,
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'acg-faka-content-center',
            ],
        ]);
        $endpoint = sprintf('repos/%s/%s/contents/%s', rawurlencode($owner), rawurlencode($repo), implode('/', array_map('rawurlencode', explode('/', $path))));
        $sha = $this->putFile($client, $endpoint, $branch, $this->mdx($content), sprintf('docs: publish %s %s', $content->content_type, $content->slug));
        $indexPath = ($content->content_type === 'blog' ? 'blog' : 'pages') . '/index.mdx';
        $fullIndexPath = ($root === '' ? '' : $root . '/') . $indexPath;
        $indexEndpoint = sprintf('repos/%s/%s/contents/%s', rawurlencode($owner), rawurlencode($repo), implode('/', array_map('rawurlencode', explode('/', $fullIndexPath))));
        $this->putFile($client, $indexEndpoint, $branch, $this->indexMdx($content), 'docs: update ' . $content->content_type . ' index');
        return $sha;
    }

    private function path(Content $content, string $root): string
    {
        $root = trim($root, '/');
        $directory = $content->content_type === 'blog' ? 'blog' : 'pages';
        return ($root === '' ? '' : $root . '/') . $directory . '/' . $content->slug . '.mdx';
    }

    private function mdx(Content $content): string
    {
        $metadata = [
            'title' => $content->title,
            'description' => (string)($content->excerpt ?: $content->title),
        ];
        if ($content->content_type === 'blog' && $content->published_at) {
            $metadata['date'] = substr((string)$content->published_at, 0, 10);
        }
        if ($content->cover) {
            $metadata['image'] = $content->cover;
        }
        $tags = is_array($content->tags) ? $content->tags : [];
        if ($tags) {
            $metadata['tags'] = implode(', ', $tags);
        }

        $frontmatter = [];
        foreach ($metadata as $key => $value) {
            $value = str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ' '], (string)$value);
            $frontmatter[] = $key . ': "' . $value . '"';
        }
        return "---\n" . implode("\n", $frontmatter) . "\n---\n\n" . trim($content->body_mdx) . "\n";
    }

    private function indexMdx(Content $content): string
    {
        $items = Content::query()
            ->where('content_type', $content->content_type)
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->get()
            ->all();
        $items[$content->id] = $content;
        usort($items, static fn(Content $a, Content $b): int => strcmp((string)($b->published_at ?: date('Y-m-d H:i:s')), (string)($a->published_at ?: date('Y-m-d H:i:s'))));

        $title = $content->content_type === 'blog' ? '博客' : '帮助页面';
        $lines = ['---', 'title: "' . $title . '"', 'description: "由内容中心自动维护的内容目录"', '---', '', '# ' . $title, ''];
        foreach ($items as $item) {
            $excerpt = trim((string)$item->excerpt);
            $line = '- [' . $item->title . '](/' . ($content->content_type === 'blog' ? 'blog' : 'pages') . '/' . $item->slug . ')';
            $lines[] = $excerpt === '' ? $line : $line . ' — ' . str_replace(["\r", "\n"], ' ', $excerpt);
        }
        return implode("\n", $lines) . "\n";
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
