<?php
declare(strict_types=1);

namespace App\Plugin\ContentCenter\Controller;

use App\Controller\Base\View\ManagePlugin;
use App\Interceptor\ManageSession;
use App\Util\Plugin;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Exception\ViewException;

#[Interceptor(ManageSession::class)]
class Admin extends ManagePlugin
{
    /** @throws JSONException|ViewException */
    public function blog(): string
    {
        return $this->contentPage('blog', '博客管理');
    }

    /** @throws JSONException|ViewException */
    public function page(): string
    {
        return $this->contentPage('page', '页面管理');
    }

    /** @throws JSONException|ViewException */
    private function contentPage(string $contentType, string $title): string
    {
        require_once BASE_PATH . '/app/View/Admin/Helper.php';
        $config = Plugin::getConfig('ContentCenter', false);
        return $this->render($title, 'Admin/Content.html', [
            'contentType' => $contentType,
            'toolbar' => [
                ['name' => '博客管理', 'url' => '/plugin/ContentCenter/admin/blog'],
                ['name' => '页面管理', 'url' => '/plugin/ContentCenter/admin/page'],
            ],
            'docsUrl' => rtrim((string)($config['docs_url'] ?? ''), '/'),
            'adminHeader' => BASE_PATH . '/app/View/Admin/Header.html',
            'adminToolbar' => BASE_PATH . '/app/View/Admin/Toolbar.html',
            'adminFooter' => BASE_PATH . '/app/View/Admin/Footer.html',
        ], true);
    }
}
