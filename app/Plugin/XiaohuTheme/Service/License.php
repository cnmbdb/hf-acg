<?php
declare(strict_types=1);
namespace App\Plugin\XiaohuTheme\Service;

use GuzzleHttp\Client;
use Kernel\Exception\JSONException;

/** Online authorization gates administrative mutations only; never storefront requests. */
final class License
{
    private const ENDPOINT = 'https://ets-web-admin.pages.dev/api/authorization/verify';
    private const EXTENSION_ID = '4f5c8d46-dc0b-4453-8838-5b7a906661c6';
    private string $root;
    private ?\Closure $transport;

    public function __construct(?string $root = null, ?callable $transport = null)
    {
        $this->root = rtrim($root ?? BASE_PATH, '/');
        $this->transport = $transport === null ? null : \Closure::fromCallable($transport);
    }

    public function verify(?string $key = null): array
    {
        if ($key === null) {
            $file = $this->root.'/app/Plugin/XiaohuTheme/Config/Config.php';
            $config = is_file($file) ? (array) require $file : [];
            $key = (string)($config['license_code'] ?? '');
        }
        $key = trim($key);
        if ($key === '') return $this->denied('KEY_REQUIRED', '请填写 ETS 账号授权码');
        if (!preg_match('/^ets_ak_[A-Za-z0-9_-]{43}$/D', $key)) return $this->denied('INVALID_KEY', '授权码格式无效，请复制 ETS 账号中的完整 KEY');
        $payload = ['key' => $key, 'extensionId' => self::EXTENSION_ID];
        try {
            if ($this->transport !== null) {
                [$status, $bytes] = ($this->transport)(self::ENDPOINT, $payload);
            } else {
                $response = (new Client())->post(self::ENDPOINT, [
                    'json' => $payload,
                    'headers' => ['Accept' => 'application/json'],
                    'connect_timeout' => 3, 'timeout' => 8,
                    'allow_redirects' => false, 'http_errors' => false, 'verify' => true,
                    'stream' => true,
                ]);
                $status = $response->getStatusCode();
                $stream = $response->getBody();
                try { $bytes = $stream->read(4097); } finally { $stream->close(); }
            }
            if (!is_string($bytes) || strlen($bytes) > 4096) return $this->denied('SERVICE_UNAVAILABLE', '授权服务响应异常，请稍后重试');
            $body = json_decode($bytes, true);
            if (!is_array($body)) return $this->denied('SERVICE_UNAVAILABLE', '授权服务暂不可用，请稍后重试');
            if ($status !== 200 || ($body['valid'] ?? null) !== true) {
                $code = (string)($body['code'] ?? ($status === 401 ? 'INVALID_KEY' : 'SERVICE_UNAVAILABLE'));
                $messages = ['INVALID_KEY'=>'授权码无效或已撤销', 'PRODUCT_UNAVAILABLE'=>'ETS 尚未上架或启用小虎主题', 'ENTITLEMENT_REQUIRED'=>'当前账号未获得小虎主题授权，或授权已过期'];
                return $this->denied(isset($messages[$code]) ? $code : 'SERVICE_UNAVAILABLE', $messages[$code] ?? '授权服务暂不可用，请稍后重试');
            }
            if (!is_string($body['userId'] ?? null) || $body['userId'] === '') return $this->denied('SERVICE_UNAVAILABLE', '授权服务响应无效');
            // ETS checks the selected product grant and expiry before returning valid.
            // Its current unified response does not expose the expiry timestamp.
            $expiry = $body['expiresAt'] ?? null;
            if ($expiry !== null) {
                if (!is_string($expiry) || !preg_match('/^\d{4}-\d{2}-\d{2}T/', $expiry) || strtotime($expiry) === false) return $this->denied('SERVICE_UNAVAILABLE', '授权有效期格式异常');
                if (strtotime($expiry) <= time()) return $this->denied('ENTITLEMENT_REQUIRED', '小虎主题授权已过期');
            }
            return ['valid'=>true, 'code'=>'AUTHORIZED', 'message'=>'已授权', 'expiresAt'=>$expiry];
        } catch (\Throwable) {
            return $this->denied('SERVICE_UNAVAILABLE', '无法连接授权服务，请稍后重试');
        }
    }

    public function assertAllowed(?string $key = null): void
    {
        $state = $this->verify($key);
        if (!$state['valid']) throw new JSONException($state['message'].'；已有订单、后台和当前前台展示不受影响');
    }

    private function denied(string $code, string $message): array
    {
        return ['valid'=>false, 'code'=>$code, 'message'=>$message, 'expiresAt'=>null];
    }
}
