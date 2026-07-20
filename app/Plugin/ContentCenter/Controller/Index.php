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
    /** @throws JSONException|ViewException|\ReflectionException */
    public function blog(): string
    {
        Schema::ensure();
        $data = $this->viewData('博客');

        $effective = (new TemplateService())->effective('blog_home');
        $settings = $effective['settings_json'];
        $articles = Content::query()
            ->where('content_type', 'blog')
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->limit((int)$settings['list_limit'])
            ->get();

        $fallbackImages = $this->fallbackImages($settings);

        $articleRows = [];
        $articleById = [];
        $categories = [];
        foreach ($articles as $index => $article) {
            $tags = is_array($article->tags) ? array_values($article->tags) : [];
            foreach ($tags as $tag) {
                $categories[$tag] = $tag;
            }
            $row = $this->articleRow($article, $index, $fallbackImages);
            $articleRows[] = $row;
            $articleById[$article->id] = $row;
        }

        $heroSlides = [];
        foreach ($settings['hero_slides'] as $slideIndex => $slide) {
            $article = $articleById[(int)$slide['article_id']] ?? ($articleRows[$slideIndex] ?? null);
            if (!$article) {
                continue;
            }
            $article['cover'] = $slide['image'] ?: $article['cover'];
            $heroSlides[] = $article;
        }
        if (!$heroSlides) {
            $heroSlides = array_slice($articleRows, 0, 3);
        }

        $featuredCategories = [];
        foreach (array_merge($settings['featured_categories'], array_values($categories)) as $category) {
            $category = trim((string)$category);
            if ($category !== '') {
                $featuredCategories[$category] = $category;
            }
            if (count($featuredCategories) >= 10) {
                break;
            }
        }

        $data['pageTitle'] = $effective['page_title'] ?: '乐多博客';
        $data['pageDescription'] = $effective['page_description'] ?: '文章、教程与项目更新';
        $data['blogSettings'] = $settings;
        $data['articles'] = $articleRows;
        $data['heroSlides'] = $heroSlides;
        $data['categories'] = array_values($featuredCategories);
        $data['articleCount'] = count($articleRows);

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
        $settings = (new TemplateService())->effective('blog_home')['settings_json'];
        $fallbackImages = $this->fallbackImages($settings);
        $article = $this->articleRow($content, (int)$position, $fallbackImages);
        $article['body_html'] = (new MarkdownRenderer())->render($content->body_mdx);

        $data = $this->viewData($content->title);
        $data['config']['description'] = $content->excerpt ?: '阅读《' . $content->title . '》完整内容。';
        $data['article'] = $article;
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
