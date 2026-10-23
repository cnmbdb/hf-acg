<?php
declare(strict_types=1);
namespace App\Plugin\XiaohuTheme\Controller;
use App\Controller\Base\API\Manage;
use App\Interceptor\ManageSession;
use App\Interceptor\Owner;
use App\Model\ManageLog;
use App\Plugin\XiaohuTheme\Service\Updater;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
#[Interceptor([ManageSession::class,Owner::class],Interceptor::TYPE_API)]
final class Api extends Manage
{
    public function license(Request $request): array
    {
        $key = $request->post('license_code');
        $state = (new \App\Plugin\XiaohuTheme\Service\License())->verify(is_string($key) ? $key : null);
        return $this->json(200, $state['message'], $state);
    }
    public function check(): array
    {
        return $this->json(200,'检查完成',(new Updater())->check());
    }
    public function apply(Request $request): array
    {
        $version=trim((string)$request->post('version'));
        $result=(new Updater())->apply($version);
        ManageLog::log($this->getManage(),'更新了 Xiaohu 小虎主题套件至 '.$result['version']);
        return $this->json(200,'更新完成，刷新页面后生效',['version'=>$result['version']]);
    }
}
