<?php
declare(strict_types=1);
namespace App\Plugin\XiaohuTheme\Service;
use Kernel\Exception\JSONException;
use ZipArchive;
final class Updater
{
    private string $root;
    public function __construct(?string $root=null,private readonly ?GitHubReleases $github=null) { $this->root=rtrim($root??BASE_PATH,'/'); }
    public function check(): array
    {
        $release=($this->github??new GitHubReleases($this->root))->latest();
        $this->assertCore($release['manifest']);
        $current=$this->version();
        return ['current'=>$current,'latest'=>$release['version'],'tag'=>$release['tag'],'has_update'=>version_compare($release['version'],$current,'>'),'notes'=>$release['notes']];
    }
    public function apply(string $expectedVersion): array
    {
        if (!preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D',$expectedVersion)) throw new JSONException('更新版本号无效');
        $state=$this->root.'/runtime/XiaohuTheme';
        if (!is_dir($state) && !mkdir($state,0700,true)) throw new JSONException('无法创建更新状态目录');
        $lock=fopen($state.'/install.lock','c');
        if (!$lock || !flock($lock,LOCK_EX)) throw new JSONException('当前套件正在安装或更新，请稍后重试');
        $zipPath=$state.'/download-'.bin2hex(random_bytes(8)).'.zip';
        try {
            $github=$this->github??new GitHubReleases($this->root);
            $release=$github->latest();
            if (!hash_equals($release['version'],$expectedVersion) || !version_compare($release['version'],$this->version(),'>')) throw new JSONException('Release 版本已变化或已是最新，请重新检查更新');
            $this->assertCore($release['manifest']);
            $github->downloadArchive($release,$zipPath);
            $backup=$state.'/updates/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
            if (!mkdir($backup,0700,true)) throw new JSONException('无法创建更新回滚备份');
            $this->installArchive($zipPath,$backup);
            return ['version'=>$release['version'],'backup'=>basename($backup)];
        } finally {
            if (is_file($zipPath)) @unlink($zipPath);
            flock($lock,LOCK_UN);fclose($lock);
        }
    }
    private function version(): string
    {
        $info=$this->root.'/app/Plugin/XiaohuTheme/Config/Info.php';
        $data=is_file($info)?require $info:[];
        $version=is_array($data)?(string)($data['VERSION']??'0.0.0'):'0.0.0';
        return preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D',$version)?$version:'0.0.0';
    }
    private function assertCore(array $manifest): void
    {
        $range=$manifest['core']??null;
        if (!is_array($range) || !preg_match('/^\d+\.\d+\.\d+$/D',(string)($range['min']??'')) || !preg_match('/^\d+\.\d+\.\d+$/D',(string)($range['max']??''))) throw new JSONException('Release 缺少有效的核心版本兼容范围');
        $core=$this->coreVersion();
        if (version_compare($core,(string)$range['min'],'<') || version_compare($core,(string)$range['max'],'>')) throw new JSONException('该 Xiaohu 更新要求 ACG 核心 '.$range['min'].' 至 '.$range['max'].'，当前核心为 '.$core);
    }
    private function coreVersion(): string
    {
        foreach (['APP_VERSION','ACG_VERSION','VERSION'] as $constant) if (defined($constant) && preg_match('/^\d+\.\d+\.\d+$/D',(string)constant($constant))) return (string)constant($constant);
        $appFile=$this->root.'/config/app.php';
        if (is_file($appFile)) { $app=require $appFile;$version=is_array($app)?(string)($app['version']??''):'';if (preg_match('/^\d+\.\d+\.\d+$/D',$version)) return $version; }
        throw new JSONException('无法识别当前 ACG 核心版本，已停止更新');
    }
    private function installArchive(string $zipPath,string $backup): void
    {
        $zip=new ZipArchive();
        if ($zip->open($zipPath)!==true) throw new JSONException('更新包不是有效 ZIP');
        $stage=$this->root.'/runtime/XiaohuTheme/stage-'.bin2hex(random_bytes(6));
        if (!mkdir($stage,0700,true)) { $zip->close();throw new JSONException('无法创建更新暂存目录'); }
        $undo=[];$infoDestination=$this->root.'/app/Plugin/XiaohuTheme/Config/Info.php';
        try {
            $seen=[];$total=0;$count=0;
            for ($i=0;$i<$zip->numFiles;$i++) {
                $stat=$zip->statIndex($i);if (!is_array($stat)) throw new JSONException('更新包目录损坏');
                $name=(string)$stat['name'];
                if (str_ends_with($name,'/')) continue;
                if (++$count>2000) throw new JSONException('更新包文件数量超过限制');
                $prefix='app/Plugin/XiaohuTheme/';
                if (!str_starts_with($name,$prefix) || str_contains($name,'\\') || str_contains($name,"\0") || preg_match('/[\x00-\x1f\x7f:]/',$name)) throw new JSONException('更新包包含未授权路径');
                $relative=substr($name,strlen($prefix));$parts=explode('/',$relative);
                foreach ($parts as $part) if ($part===''||$part==='.'||$part==='..') throw new JSONException('更新包包含路径穿越条目');
                if (in_array('Snapshot',$parts,true)||in_array('Db',$parts,true)||in_array('runtime',$parts,true)||in_array('.env',$parts,true)) throw new JSONException('更新包包含禁止覆盖的数据目录');
                if ((int)$stat['size']<0 || (int)$stat['size']>50*1024*1024 || ($total+=(int)$stat['size'])>50*1024*1024) throw new JSONException('更新包解压大小超过限制');
                $ops=0;$attrs=0;$zip->getExternalAttributesIndex($i,$ops,$attrs);
                $type=(($attrs>>16)&0170000);
                if ($type!==0 && $type!==0100000) throw new JSONException('更新包包含非普通文件');
                if (isset($seen[$relative])) throw new JSONException('更新包包含重复文件路径');$seen[$relative]=true;
                if ($relative==='Config/Config.php'||$relative==='runtime.log') continue;
                $path=$stage.'/'.$relative;
                if (!is_dir(dirname($path))&&!mkdir(dirname($path),0700,true)) throw new JSONException('更新包暂存失败');
                $in=$zip->getStream($name);$out=fopen($path,'xb');
                if (!$in||!$out) throw new JSONException('无法读取更新包条目');
                $written=0;
                try { while (!feof($in)) { $chunk=fread($in,8192);if ($chunk===false) throw new JSONException('读取更新包失败');if ($chunk==='') break;$written+=strlen($chunk);if ($written>(int)$stat['size']||$written>50*1024*1024||fwrite($out,$chunk)!==strlen($chunk)) throw new JSONException('更新包条目长度异常'); } }
                finally { fclose($in);fclose($out); }
                if ($written!==(int)$stat['size']) throw new JSONException('更新包条目长度不匹配');
            }
            $zip->close();
            if (!$count || !isset($seen['Config/Info.php'])) throw new JSONException('更新包缺少套件版本信息');
            $this->installStagedFiles($stage,$seen,$backup,$undo,$infoDestination);
        } catch (\Throwable $e) {
            $zip->close();$this->rollback($undo);$this->removeTree($stage);
            if ($e instanceof JSONException) throw $e;
            throw new JSONException('更新失败，已尝试恢复更新前文件');
        }
        $this->removeTree($stage);
    }
    private function installStagedFiles(string $stage,array $seen,string $backup,array &$undo,string $infoDestination): void
    {
        $base='app/Plugin/XiaohuTheme/';
        $paths=array_keys($seen);sort($paths,SORT_STRING);
        foreach ($paths as $relative) if ($relative!=='Config/Info.php') $this->replace($stage.'/'.$relative,$this->root.'/'.$base.$relative,$backup,$undo);
        $this->mirrorBundle($stage.'/Bundle/Theme/Xiaohu',$this->root.'/app/View/User/Theme/Xiaohu',['Config.php','Setting.php'],$backup,$undo);
        $this->mirrorBundle($stage.'/Bundle/Support',$this->root.'/app/Plugin/Support',[],$backup,$undo);
        foreach (Suite::COMPONENTS as $component) $this->mirrorBundle($stage.'/Bundle/Plugins/'.$component,$this->root.'/app/Plugin/'.$component,['Config/Config.php'],$backup,$undo);
        $this->replace($stage.'/Config/Info.php',$infoDestination,$backup,$undo);
    }
    private function mirrorBundle(string $source,string $destination,array $exclude,array $backup,array &$undo): void
    {
        if (!is_dir($source)) throw new JSONException('更新包缺少套件组件目录');
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source,\FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()||$file->isLink()) continue;
            $relative=substr($file->getPathname(),strlen($source)+1);
            foreach ($exclude as $blocked) if ($relative===$blocked) continue 2;
            $segments=explode('/',$relative);
            if (in_array('Db',$segments,true)||in_array('runtime',$segments,true)||in_array('Snapshot',$segments,true)||in_array('.env',$segments,true)) continue;
            $this->replace($file->getPathname(),$destination.'/'.$relative,$backup,$undo);
        }
    }
    private function replace(string $source,string $destination,string $backup,array &$undo): void
    {
        $root=$this->root.'/';
        if (!str_starts_with($destination,$root)) throw new JSONException('更新目标路径无效');
        $relative=substr($destination,strlen($root));$cursor=$this->root;
        foreach (explode('/',$relative) as $part) {
            $cursor.='/'.$part;
            if (is_link($cursor)) throw new JSONException('更新目标包含符号链接，已停止更新');
        }
        if (is_dir($destination)) throw new JSONException('更新目标已是目录');
        $existed=is_file($destination);$backupPath=null;
        if ($existed) {
            $backupPath=$backup.'/'.hash('sha256',$relative);
            if (!copy($destination,$backupPath)) throw new JSONException('无法备份更新前文件');
        }
        $undo[]=['destination'=>$destination,'backup'=>$backupPath,'existed'=>$existed];
        $dir=dirname($destination);
        if (!is_dir($dir)&&!mkdir($dir,0755,true)) throw new JSONException('无法创建更新目录');
        $temp=$dir.'/.xiaohu-update-'.bin2hex(random_bytes(5));
        if (!copy($source,$temp)) throw new JSONException('无法准备更新文件');
        chmod($temp,$existed?(fileperms($destination)&0777):0644);
        if (!rename($temp,$destination)) { @unlink($temp);throw new JSONException('无法原子替换更新文件'); }
        if (function_exists('opcache_invalidate')) @opcache_invalidate($destination,true);
    }
    private function rollback(array $undo): void
    {
        foreach (array_reverse($undo) as $item) {
            $destination=$item['destination'];
            if ($item['existed'] && is_string($item['backup']) && is_file($item['backup'])) {
                $temp=$destination.'.rollback-'.bin2hex(random_bytes(4));
                if (@copy($item['backup'],$temp)) { @chmod($temp,fileperms($item['backup'])&0777);@rename($temp,$destination); }
            } elseif (!$item['existed']) @unlink($destination);
        }
    }
    private function removeTree(string $path): void
    {
        if (!is_dir($path)||is_link($path)) return;
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) { if ($item->isDir()&&!$item->isLink()) @rmdir($item->getPathname());else @unlink($item->getPathname()); }
        @rmdir($path);
    }
}
