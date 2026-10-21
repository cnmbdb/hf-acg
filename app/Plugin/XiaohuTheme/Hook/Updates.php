<?php
declare(strict_types=1);
namespace App\Plugin\XiaohuTheme\Hook;
use Kernel\Annotation\Hook;
final class Updates
{
    #[Hook(point:\App\Consts\Hook::ADMIN_VIEW_FOOTER)]
    public function adminFooter(): string
    {
        $file=BASE_PATH.'/app/Plugin/XiaohuTheme/Assets/update.js';
        if (!is_file($file)) return '';
        $script=(string)file_get_contents($file);
        return '<script>'.$script.'</script>';
    }
}
