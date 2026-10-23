<?php
declare(strict_types=1);
namespace Kernel\Exception { class JSONException extends \RuntimeException {} }
namespace {
require dirname(__DIR__).'/app/Plugin/XiaohuTheme/Service/License.php';
use App\Plugin\XiaohuTheme\Service\License;
function check(bool $ok,string $message): void {if (!$ok) throw new \RuntimeException($message); echo "PASS $message\n";}
$key='ets_ak_'.str_repeat('a',43);
function verifier(int $status,string $body): License {return new License('/tmp/no-license-config',static fn($key)=>[$status,$body]);}
foreach (['', 'wrong', str_repeat('x',600)] as $invalid) check(!verifier(200,'{}')->verify($invalid)['valid'],'missing or malformed key denied');
$valid=json_encode(['valid'=>true,'userId'=>'test-user']);
check(verifier(200,$valid)->verify($key)['valid'],'active perpetual grant accepted');
check(verifier(200,json_encode(['valid'=>true,'userId'=>'test-user','expiresAt'=>'2099-01-01T00:00:00Z']))->verify($key)['valid'],'future grant accepted');
foreach (['2020-01-01T00:00:00Z','bad',false] as $expiry) check(!verifier(200,json_encode(['valid'=>true,'userId'=>'test-user','expiresAt'=>$expiry]))->verify($key)['valid'],'expired or malformed expiry denied');
foreach ([401,403,500,503] as $status) check(!verifier($status,$valid)->verify($key)['valid'],'HTTP failure cannot authorize');
foreach (['<!doctype html>',str_repeat('x',5000),'{"valid":true,"product":"aoste","expiresAt":null}','{"valid":true}','{"valid":"true","product":"xiaohu","expiresAt":null}'] as $body) check(!verifier(200,$body)->verify($key)['valid'],'malformed or mismatched response denied');
$license=new License('/tmp/no-license-config',static function(){throw new \RuntimeException('secret connection details');});
$captured=[];
$contract=new License('/tmp/no-license-config',static function($url,$payload) use(&$captured,$valid){$captured=[$url,$payload];return [200,$valid];});
check($contract->verify($key)['valid'],'unified response is accepted');
check($captured[0]==='https://ets-web-admin.pages.dev/api/authorization/verify' && $captured[1]===['key'=>$key,'extensionId'=>'4f5c8d46-dc0b-4453-8838-5b7a906661c6'],'request binds key to the published XUIHU product');
$state=$license->verify($key);check(!$state['valid'] && !str_contains(json_encode($state),'secret'),'network errors fail closed without leaking details');
try {$license->assertAllowed($key);throw new \RuntimeException('guard missed');}catch(\Kernel\Exception\JSONException $e){check(true,'mutation guard rejects invalid authorization');}
require dirname(__DIR__).'/app/Plugin/XiaohuTheme/Service/Updater.php';
try {(new \App\Plugin\XiaohuTheme\Service\Updater('/tmp/no-license-config'))->apply('9.9.9');throw new \RuntimeException('unlicensed update accepted');}catch(\Kernel\Exception\JSONException $e){check(!is_dir('/tmp/no-license-config/runtime'),'update rejected before downloading or changing files');}
echo "ALL LICENSE TESTS PASSED\n";
}
