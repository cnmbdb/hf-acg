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
        $assets=BASE_PATH.'/app/Plugin/XiaohuTheme/Assets/';
        $style=is_file($assets.'settings.css')?(string)file_get_contents($assets.'settings.css'):'';
        $settings=is_file($assets.'settings.js')?(string)file_get_contents($assets.'settings.js'):'';
        $motion=is_file($assets.'settings-motion.js')?(string)file_get_contents($assets.'settings-motion.js'):'';
        $vendor=is_file($assets.'vendor/gsap.min.js')?'<script src="/app/Plugin/XiaohuTheme/Assets/vendor/gsap.min.js?v=3.15.0"></script>':'';
        return '<style>'.$style.'</style>'.$vendor.'<script>'.$script.'</script><script>'.$settings.'</script><script>'.$motion.'</script>';
    }
}
