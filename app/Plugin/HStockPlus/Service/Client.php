<?php
declare(strict_types=1);

namespace App\Plugin\HStockPlus\Service;

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
    public function categories(array $filters = []): array
    {
        return $this->request(['action' => 'categories'] + $filters);
    }

    /**
     * @throws JSONException
     */
    public function services(array $filters = []): array
    {
        return $this->request(['action' => 'services'] + $filters);
    }

    /**
     * @throws JSONException
     */
    public function products(array $filters = []): array
    {
        return $this->request(['action' => 'products'] + $filters);
    }

    /**
     * @throws JSONException
     */
    public function inventory(int|string $service): array
    {
        return $this->request([
            'action' => 'inventory',
            'service' => $service,
        ]);
    }

    /**
     * @throws JSONException
     */
    public function add(int|string $service, string $link, int $quantity, array $extra = []): array
    {
        return $this->request([
                'action' => 'add',
                'service' => $service,
                'link' => $link,
                'quantity' => $quantity,
            ] + $extra);
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
        return $this->request(['action' => 'balance']);
    }

    /**
     * @throws JSONException
     */
    private function request(array $data): array
    {
        if ($this->apiKey === '') {
            throw new JSONException('HStockPlus API Key 未配置');
        }

        $data['key'] = $this->apiKey;

        try {
            $response = Http::make()->post($this->apiUrl, [
                'form_params' => $data,
                'timeout' => max(5, $this->timeout),
            ]);
            $body = trim((string)$response->getBody()->getContents());
        } catch (\Throwable $e) {
            throw new JSONException('HStockPlus API 请求失败：' . $e->getMessage());
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new JSONException('HStockPlus API 返回格式异常：' . mb_substr($body, 0, 200));
        }

        if (isset($json['error'])) {
            throw new JSONException('HStockPlus API 错误：' . (is_scalar($json['error']) ? (string)$json['error'] : json_encode($json['error'], JSON_UNESCAPED_UNICODE)));
        }

        return $json;
    }
}
