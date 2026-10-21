<?php
declare(strict_types=1);

namespace App\Plugin\FansGurus\Service;

use App\Util\Http;
use Kernel\Exception\JSONException;

class Client
{
    public function __construct(
        private readonly string $apiUrl,
        private readonly string $apiKey,
        private readonly int    $timeout = 30
    )
    {
    }

    /**
     * @throws JSONException
     */
    public function add(int|string $service, string $link, int $quantity): array
    {
        return $this->request([
            'action' => 'add',
            'service' => $service,
            'link' => $link,
            'quantity' => $quantity,
        ]);
    }

    /**
     * @throws JSONException
     */
    public function status(int|string $orderId): array
    {
        return $this->request([
            'action' => 'status',
            'order' => $orderId,
        ]);
    }

    /**
     * @throws JSONException
     */
    public function balance(): array
    {
        return $this->request([
            'action' => 'balance',
        ]);
    }

    /**
     * @throws JSONException
     */
    public function services(): array
    {
        return $this->request([
            'action' => 'services',
        ]);
    }

    /**
     * @throws JSONException
     */
    private function request(array $data): array
    {
        if ($this->apiKey === '') {
            throw new JSONException('FansGurus API Key 未配置');
        }

        $data['key'] = $this->apiKey;

        try {
            $response = Http::make()->post($this->apiUrl, [
                'form_params' => $data,
                'timeout' => max(5, $this->timeout),
            ]);
            $body = trim((string)$response->getBody()->getContents());
        } catch (\Throwable $e) {
            throw new JSONException('FansGurus API 请求失败：' . $e->getMessage());
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new JSONException('FansGurus API 返回格式异常：' . mb_substr($body, 0, 200));
        }

        if (isset($json['error'])) {
            throw new JSONException('FansGurus API 错误：' . (string)$json['error']);
        }

        return $json;
    }
}
