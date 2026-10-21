<?php
declare(strict_types=1);

namespace App\Plugin\ContentCenter\Controller;

use App\Controller\Base\View\User;
use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use App\Model\Business;
use App\Model\Config;
use App\Plugin\ContentCenter\Model\Content;
use App\Plugin\ContentCenter\Service\BlogHomeSettings;
use App\Plugin\ContentCenter\Service\MarkdownRenderer;
use App\Plugin\ContentCenter\Service\Schema;
use App\Plugin\ContentCenter\Service\TemplateService;
use App\Plugin\ManualShopPage\Service\Navigation as ManualShopNavigation;
use App\Plugin\XiaohuGuard\Service\ThemeLock;
use App\Util\Client;
use App\Util\Theme;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Exception\NotFoundException;
use Kernel\Exception\ViewException;
use Kernel\Util\View;

#[Interceptor([Waf::class, UserVisitor::class])]
class Index extends User
{
    private const ARTICLES_PER_PAGE = 20;

    /** @throws JSONException|ViewException|\ReflectionException */
    public function blog(): string
    {
        Schema::ensure();
        $data = $this->viewData('博客');

        $effective = (new TemplateService())->effective('blog_home');
        $settings = $effective['settings_json'];
        $published = Content::query()
            ->where('content_type', 'blog')
            ->where('status', 'published')
            ->orderByDesc('sort_order')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get(['id', 'title', 'slug', 'excerpt', 'cover', 'tags', 'published_at', 'sort_order']);
        $totalArticles = $published->count();
        $totalPages = max(1, (int)ceil($totalArticles / self::ARTICLES_PER_PAGE));
        $currentPage = min($totalPages, max(1, (int)($_GET['page'] ?? 1)));
        $offset = ($currentPage - 1) * self::ARTICLES_PER_PAGE;

        $fallbackImages = $this->fallbackImages($settings);

        $allArticleRows = [];
        $articleRows = [];
        $articleById = [];
        $categories = [];
        foreach ($published as $index => $article) {
            $row = $this->articleRow($article, $index, $fallbackImages);
            $allArticleRows[] = $row;
            $articleById[$article->id] = $row;
            foreach ($row['tags'] as $tag) {
                $tag = trim((string)$tag);
                if ($tag === '') {
                    continue;
                }
                $categories[$tag] = $tag;
            }
            if ($index >= $offset && $index < $offset + self::ARTICLES_PER_PAGE) {
                $articleRows[] = $row;
            }
        }

        $heroSlides = [];
        foreach ($settings['hero_slides'] as $slideIndex => $slide) {
            $article = $articleById[(int)$slide['article_id']] ?? ($allArticleRows[$slideIndex] ?? null);
            if (!$article) {
                continue;
            }
            $article['cover'] = $slide['image'] ?: $article['cover'];
            $heroSlides[] = $article;
        }
        if (!$heroSlides) {
            $heroSlides = array_slice($allArticleRows, 0, 3);
        }

        $pageTitle = $effective['page_title'] ?: '乐多博客';
        $data['pageTitle'] = $currentPage > 1 ? $pageTitle . ' - 第 ' . $currentPage . ' 页' : $pageTitle;
        $data['pageDescription'] = $effective['page_description'] ?: '文章、教程与项目更新';
        $data['blogSettings'] = $settings;
        $data['articles'] = $articleRows;
        $data['tickerArticles'] = array_slice($allArticleRows, 0, self::ARTICLES_PER_PAGE);
        $data['heroSlides'] = $heroSlides;
        $data['categories'] = array_values($categories);
        $data['articleCount'] = count($articleRows);
        $data['totalArticleCount'] = $totalArticles;
        $data['articleRangeStart'] = $totalArticles > 0 ? $offset + 1 : 0;
        $data['articleRangeEnd'] = $offset + count($articleRows);
        $data['currentPage'] = $currentPage;
        $data['totalPages'] = $totalPages;
        $data['previousPage'] = max(1, $currentPage - 1);
        $data['nextPage'] = min($totalPages, $currentPage + 1);
        $data['paginationItems'] = $this->paginationItems($currentPage, $totalPages);

        return $this->renderPluginView('User/Blog.html', $data);
    }

    /** @throws JSONException|ViewException|NotFoundException|\ReflectionException */
    public function article(): string
    {
        Schema::ensure();
        $slug = strtolower(trim((string)($_GET['_PARAMETER'][0] ?? '')));
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            http_response_code(404);
            throw new NotFoundException('404 Not Found');
        }

        $published = Content::query()
            ->where('content_type', 'blog')
            ->where('status', 'published')
            ->orderByDesc('sort_order')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get();
        $position = $published->search(static fn(Content $item): bool => $item->slug === $slug);
        if ($position === false) {
            http_response_code(404);
            throw new NotFoundException('404 Not Found');
        }

        /** @var Content $content */
        $content = $published->get($position);
        $templates = new TemplateService();
        $homeSettings = $templates->effective('blog_home')['settings_json'];
        $detailSettings = $templates->effective('blog_detail')['settings_json'];
        $fallbackImages = $this->fallbackImages($homeSettings);
        $article = $this->articleRow($content, (int)$position, $fallbackImages);
        $article['body_html'] = (new MarkdownRenderer())->render($content->body_mdx);

        $data = $this->viewData($content->title);
        $wordCount = $this->wordCount($article['body_html']);
        $moreArticles = [];
        foreach ($published as $index => $publishedArticle) {
            if ($publishedArticle->id === $content->id) {
                continue;
            }
            $moreArticles[] = $this->articleRow($publishedArticle, (int)$index, $fallbackImages);
            if (count($moreArticles) >= (int)$detailSettings['more_articles_limit']) {
                break;
            }
        }

        $data['config']['description'] = $content->excerpt ?: '阅读《' . $content->title . '》完整内容。';
        $data['article'] = $article;
        $data['detailSettings'] = $detailSettings;
        $data['articleInfo'] = [
            'source' => (string)($data['config']['shop_name'] ?? '本站博客'),
            'word_count' => $wordCount,
            'reading_minutes' => max(1, (int)ceil($wordCount / 300)),
        ];
        $data['moreArticles'] = $moreArticles;
        $data['olderArticle'] = $published->has($position + 1)
            ? $this->articleRow($published->get($position + 1), $position + 1, $fallbackImages)
            : null;
        $data['newerArticle'] = $position > 0
            ? $this->articleRow($published->get($position - 1), $position - 1, $fallbackImages)
            : null;

        return $this->renderPluginView('User/BlogDetail.html', $data);
    }

    /** @return array<string,mixed> */
    private function viewData(string $title): array
    {
        require_once BASE_PATH . '/app/View/User/Helper.php';

        ThemeLock::enforce();
        $data = [
            'title' => $title,
            'app' => ['version' => (string)\config('app')['version']],
            'favicon' => '/favicon.ico',
            'config' => Config::list(),
            'static' => '/app/View/User/Theme/Xiaohu',
            'setting' => Theme::getConfig('Xiaohu')['setting'],
            'themeHeader' => BASE_PATH . '/app/View/User/Theme/Xiaohu/Index/Header.html',
            'themeFooter' => BASE_PATH . '/app/View/User/Theme/Xiaohu/Index/Footer.html',
            'shopSource' => 'blog',
            'from' => 0,
        ];
        $data = array_replace($data, ManualShopNavigation::viewData());

        if (Client::isMobile() && !empty($data['config']['background_mobile_url'])) {
            $data['config']['background_url'] = $data['config']['background_mobile_url'];
        }

        $domain = Client::getDomain();
        $business = Business::query()->where('subdomain', $domain)->first()
            ?? Business::query()->where('topdomain', $domain)->first();
        if ($business) {
            $data['config']['shop_name'] = $business->shop_name;
            $data['config']['title'] = $business->title;
            $data['config']['notice'] = $business->notice;
            $data['config']['service_url'] = $business->service_url !== ''
                ? $business->service_url
                : 'https://wpa.qq.com/msgrd?v=1&uin=' . $business->service_qq;
            $data['from'] = $business->user_id;
            if ($business->user && $business->user->avatar) {
                $data['favicon'] = $business->user->avatar;
            }
        }

        $user = $this->getUser();
        if ($user) {
            $data['user'] = $user;
            $data['group'] = $this->getUserGroup()?->toArray();
        }

        return $data;
    }

    /** @param array<string,mixed> $settings @return list<string> */
    private function fallbackImages(array $settings): array
    {
        $images = array_values(array_filter(array_column($settings['hero_slides'] ?? [], 'image')));
        return $images ?: array_column(BlogHomeSettings::defaults()['hero_slides'], 'image');
    }

    /** @param list<string> $fallbackImages @return array<string,mixed> */
    private function articleRow(Content $article, int $index, array $fallbackImages): array
    {
        $tags = is_array($article->tags) ? array_values($article->tags) : [];
        return [
            'id' => $article->id,
            'title' => $article->title,
            'slug' => $article->slug,
            'excerpt' => $article->excerpt ?: '查看完整文章内容与操作说明。',
            'cover' => $article->cover ?: $fallbackImages[$index % count($fallbackImages)],
            'tags' => $tags,
            'display_tags' => array_slice($tags ?: ['博客'], 0, 3),
            'published_date' => substr((string)$article->published_at, 0, 10),
            'url' => '/blog/' . rawurlencode($article->slug),
        ];
    }

    private function wordCount(string $html): int
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return preg_match_all('/[\p{Han}]|[A-Za-z0-9]+(?:[\'\x{2019}-][A-Za-z0-9]+)*/u', $text, $matches) ?: 0;
    }

    /** @return list<array{type:string,page?:int,current?:bool}> */
    private function paginationItems(int $currentPage, int $totalPages): array
    {
        if ($totalPages <= 1) {
            return [];
        }

        $pages = [1, $totalPages];
        for ($page = max(1, $currentPage - 2); $page <= min($totalPages, $currentPage + 2); $page++) {
            $pages[] = $page;
        }
        $pages = array_values(array_unique($pages));
        sort($pages);

        $items = [];
        $previous = 0;
        foreach ($pages as $page) {
            if ($previous > 0 && $page - $previous > 1) {
                $items[] = ['type' => 'ellipsis'];
            }
            $items[] = ['type' => 'page', 'page' => $page, 'current' => $page === $currentPage];
            $previous = $page;
        }
        return $items;
    }

    /** @param array<string,mixed> $data */
    private function renderPluginView(string $template, array $data): string
    {
        try {
            return View::render($template, $data, BASE_PATH . '/app/Plugin/ContentCenter/View');
        } catch (\SmartyException $e) {
            throw new ViewException($e->getMessage());
        }
    }
}
