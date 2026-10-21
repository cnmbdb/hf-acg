<?php
declare(strict_types=1);
namespace App\Plugin\XiaohuTheme\Service;
use GuzzleHttp\Client;
use Kernel\Exception\JSONException;
final class GitHubReleases
{
    private const OWNER='cnmbdb';
    private const REPO='hf-acg';
    private const API='https://api.github.com';
    private const MAX_RELEASES_BYTES=2_000_000;
    private ?Client $client=null;
    public function __construct(private readonly ?string $root=null) {}
    public function latest(): array
    {
        $rows=json_decode($this->request(self::API.'/repos/'.self::OWNER.'/'.self::REPO.'/releases?per_page=100',self::MAX_RELEASES_BYTES),true);
        if (!is_array($rows)) throw new JSONException('GitHub Releases 返回格式无效');
        $candidates=[];
        foreach ($rows as $release) {
            if (!is_array($release) || !empty($release['draft']) || !empty($release['prerelease'])) continue;
            $tag=(string)($release['tag_name']??'');
            if (!preg_match('/^xiaohu-v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D',$tag,$m)) continue;
            $version=$m[1].'.'.$m[2].'.'.$m[3];
            $assets=[];
            foreach ((array)($release['assets']??[]) as $asset) {
                if (is_array($asset) && preg_match('/^[1-9][0-9]*$/D',(string)($asset['id']??''))) $assets[(string)($asset['name']??'')]=$asset;
            }
            if (!isset($assets['xiaohu-update.json'],$assets['XiaohuTheme-'.$version.'.zip'])) continue;
            $candidates[]=['version'=>$version,'tag'=>$tag,'body'=>mb_substr((string)($release['body']??''),0,6000),'assets'=>$assets];
        }
        usort($candidates,static fn(array $a,array $b):int=>version_compare($b['version'],$a['version']));
        if (!$candidates) throw new JSONException('私有仓库暂无可用的 xiaohu-v 版本 Release');
        $item=$candidates[0];
        $manifestBytes=$this->assetBytes($item['assets']['xiaohu-update.json'],65536);
        $manifest=json_decode($manifestBytes,true);
        if (!is_array($manifest) || ($manifest['schema']??null)!==1 || ($manifest['plugin']??null)!=='XiaohuTheme' || ($manifest['version']??null)!==$item['version'] || ($manifest['tag']??null)!==$item['tag']) throw new JSONException('更新清单与 Release 标签不匹配');
        $archive=$manifest['archive']??null;
        $expectedName='XiaohuTheme-'.$item['version'].'.zip';
        $asset=$item['assets'][$expectedName];
        if (!is_array($archive) || ($archive['name']??null)!==$expectedName || !preg_match('/^[a-f0-9]{64}$/D',(string)($archive['sha256']??'')) || (int)($archive['size']??0)<1 || (int)($archive['size']??0)>50*1024*1024 || (int)($asset['size']??0)!==(int)$archive['size']) throw new JSONException('更新包信息无效或大小不匹配');
        return ['version'=>$item['version'],'tag'=>$item['tag'],'notes'=>$item['body'],'manifest'=>$manifest,'asset'=>$asset];
    }
    public function downloadArchive(array $release,string $destination): void
    {
        $manifest=$release['manifest']??[];
        $asset=$release['asset']??[];
        $id=(string)($asset['id']??'');
        if (!preg_match('/^[1-9][0-9]*$/D',$id)) throw new JSONException('Release 更新包标识无效');
        $url=self::API.'/repos/'.self::OWNER.'/'.self::REPO.'/releases/assets/'.$id;
        $this->request($url,50*1024*1024,$destination,true);
        $size=filesize($destination);
        if ($size===false || $size!==(int)$manifest['archive']['size'] || !hash_equals((string)$manifest['archive']['sha256'],hash_file('sha256',$destination)?:'')) {
            @unlink($destination);
            throw new JSONException('更新包完整性校验失败，未安装任何文件');
        }
    }
    private function assetBytes(array $asset,int $limit): string
    {
        $id=(string)($asset['id']??'');
        if (!preg_match('/^[1-9][0-9]*$/D',$id)) throw new JSONException('Release 清单标识无效');
        $url=self::API.'/repos/'.self::OWNER.'/'.self::REPO.'/releases/assets/'.$id;
        return $this->request($url,$limit,null,true);
    }
    private function token(): string
    {
        $token=trim((string)($_ENV['XIAOHU_GITHUB_TOKEN']??$_SERVER['XIAOHU_GITHUB_TOKEN']??getenv('XIAOHU_GITHUB_TOKEN')?:''));
        if ($token==='' || strlen($token)>512 || preg_match('/[\r\n\x00-\x20]/',$token)) throw new JSONException('请在站点 .env 配置仅限 cnmbdb/hf-acg、Contents 只读的 XIAOHU_GITHUB_TOKEN');
        return $token;
    }
    private function request(string $url,int $limit,?string $destination=null,bool $asset=false): string
    {
        $token=$this->token();
        $client=$this->client??=new Client(['verify'=>true,'timeout'=>30,'connect_timeout'=>10,'http_errors'=>false,'allow_redirects'=>false]);
        $current=$url;$authenticated=true;$redirects=0;$total=0;$buffer='';$out=null;
        try {
            while (true) {
                $parts=parse_url($current);$host=strtolower((string)($parts['host']??''));
                if (($parts['scheme']??'')!=='https' || ($parts['port']??443)!==443) throw new JSONException('GitHub 返回了不安全的下载地址');
                $allowed=$authenticated ? $host==='api.github.com' : in_array($host,['objects.githubusercontent.com','release-assets.githubusercontent.com','github-releases.githubusercontent.com'],true);
                if (!$allowed) throw new JSONException('GitHub Release 重定向目标不在允许列表');
                $headers=['User-Agent'=>'XiaohuTheme-Updater','Accept'=>$asset?'application/octet-stream':'application/vnd.github+json','X-GitHub-Api-Version'=>'2022-11-28'];
                if ($authenticated) $headers['Authorization']='Bearer '.$token;
                $response=$client->request('GET',$current,['headers'=>$headers,'stream'=>true]);
                $status=$response->getStatusCode();
                if (in_array($status,[301,302,303,307,308],true)) {
                    if (++$redirects>4) throw new JSONException('GitHub Release 下载重定向次数超限');
                    $location=$response->getHeaderLine('Location');
                    if ($location==='' || preg_match('/[\r\n]/',$location)) throw new JSONException('GitHub Release 下载地址无效');
                    if (!str_starts_with($location,'https://')) throw new JSONException('GitHub Release 下载地址必须使用 HTTPS');
                    $current=$location;$authenticated=false;continue;
                }
                if ($status!==200) throw new JSONException($status===401||$status===403?'GitHub 拒绝访问私有 Releases，请检查 Token 的 Contents 只读权限和仓库范围':'GitHub Releases 请求失败（HTTP '.$status.'）');
                $length=$response->getHeaderLine('Content-Length');
                if ($length!=='' && ctype_digit($length) && (int)$length>$limit) throw new JSONException('GitHub 返回的数据超过大小限制');
                if ($destination!==null) {
                    $out=@fopen($destination,'xb');
                    if (!$out) throw new JSONException('无法创建临时更新包');
                }
                $body=$response->getBody();
                while (!$body->eof()) {
                    $chunk=$body->read(8192);if ($chunk==='') break;
                    $total+=strlen($chunk);
                    if ($total>$limit) throw new JSONException('GitHub 返回的数据超过大小限制');
                    if (is_resource($out)) { if (fwrite($out,$chunk)!==strlen($chunk)) throw new JSONException('写入临时更新包失败'); }
                    else $buffer.=$chunk;
                }
                if (is_resource($out)) { fflush($out);fclose($out);$out=null; }
                return $buffer;
            }
        } catch (JSONException $e) {
            if (is_resource($out)) fclose($out);
            if ($destination!==null && is_file($destination)) @unlink($destination);
            throw $e;
        } catch (\Throwable $e) {
            if (is_resource($out)) fclose($out);
            if ($destination!==null && is_file($destination)) @unlink($destination);
            throw new JSONException('连接 GitHub Releases 失败，请确认沙盒网络策略和站点 Token 配置');
        }
    }
}
