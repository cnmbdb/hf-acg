<?php
declare(strict_types=1);

namespace App\Pay\Epay\Impl;

use App\Entity\PayEntity;
use App\Pay\Base;
use App\Util\Client;
use App\Util\Http;
use Kernel\Exception\JSONException;

/**
 * Class Pay
 * @package App\Pay\Kvmpay\Impl
 */
class Pay extends Base implements \App\Pay\Pay
{

    /**
     * @return PayEntity
     * @throws JSONException
     */
    public function trade(): PayEntity
    {

        if (!$this->config['url'] ||
            !$this->config['pid'] ||
            !isset($this->config['version']) ||
            ($this->config['version'] == 1 && (!$this->config['private_key'] || !$this->config['platform_public_key'])) ||
            ($this->config['version'] == 0 && !$this->config['key'])) {
            throw new JSONException("重要参数缺失，请检查插件配置文件！");
        }

        $title = isset($this->config['order_title']) ? str_replace('${trade_no}', $this->tradeNo, $this->config['order_title']) : "商品订单号:{$this->tradeNo}";

        $param = [
            'pid' => $this->config['pid'],
            'name' => $title,
            'type' => $this->code,
            'money' => $this->amount,
            'out_trade_no' => $this->tradeNo,
            'notify_url' => $this->callbackUrl,
            'return_url' => $this->returnUrl,
            'sitename' => $this->tradeNo,
            'clientip' => $this->clientIp,
            'device' => Client::isMobile() ? 'mobile' : 'pc'
        ];


        $url = trim($this->config['url'], "/");

        if ($this->config['version'] == 1) {
            $param['method'] = 'jump';
            $param['timestamp'] = time();
            $param['sign'] = Signature::rsa($param, $this->config['private_key']);
            $param['sign_type'] = "RSA";
            $url .= $this->config['mapi'] == 1 ? "/api/pay/create" : "/api/pay/submit";
            $code = 0;

        } elseif ($this->config['version'] == 0) {
            $param['sign'] = Signature::generateSignature($param, $this->config['key']);
            $param['sign_type'] = "MD5";
            $url .= $this->config['mapi'] == 1 ? "/mapi.php" : "/submit.php";
            $code = 1;
        } else {
            throw new JSONException("支付接口出错，下单失败！");
        }

        $payEntity = new PayEntity();

        if ($this->config['mapi'] == 1) {
            try {
                $response = Http::make()->post($url, [
                    "form_params" => $param
                ]);

                $json = json_decode($response->getBody()->getContents(), true);

                if (empty($json)) {
                    throw new JSONException("下单失败#0");
                }

                if (!isset($json['code']) || $json['code'] != $code) {
                    throw new JSONException($json['msg'] ?? "下单失败#1");
                }

                if ($this->config['version'] == 1) {
                    if (!isset($json['pay_info'])) {
                        throw new JSONException("下单失败#2");
                    }
                    $payEntity->setUrl($json['pay_info']);
                    $payEntity->setType(self::TYPE_REDIRECT);
                    return $payEntity;
                } elseif ($this->config['version'] == 0) {
                    if (isset($json['qrcode'])) {
                        $payEntity->setUrl($json['qrcode']);
                        $payEntity->setOption(['returnUrl' => $this->returnUrl]);
                        $payEntity->setType(self::TYPE_LOCAL_RENDER);
                        return $payEntity;
                    } elseif (isset($json['payurl'])) {
                        $payEntity->setUrl($json['payurl']);
                        $payEntity->setType(self::TYPE_REDIRECT);
                        return $payEntity;
                    }
                }
            } catch (\Throwable $e) {
                throw new JSONException("支付接口出错，请查看插件日志");
            }
        }

        $payEntity->setType(self::TYPE_SUBMIT);
        $payEntity->setOption($param);
        $payEntity->setUrl($url);
        return $payEntity;
    }
}