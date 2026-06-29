# FansGurus 上游货源插件

本插件对接 FansGurus SMM Panel API。启用后，付款成功的本地订单会按商品映射自动提交到 FansGurus，并把上游订单号写入本地订单发货内容。

## 配置

- API地址：默认 `https://fansgurus.com/api/v2`。
- API Key：FansGurus 后台 API 页面中的 key。
- 自动采购：开启后付款成功自动提交上游订单。
- 接管库存：开启后映射商品使用配置的库存显示值。
- 默认链接来源：`contact` 表示使用下单联系方式；`widget:字段名` 表示使用商品自定义控件；`fixed:内容` 表示固定值。

## 商品映射

配置示例：

```json
{
  "1": {
    "service": 123,
    "link_source": "contact",
    "quantity": "order_num",
    "stock": 999999
  },
  "GOODS_CODE": {
    "service": 456,
    "link_source": "widget:profile_url",
    "quantity": "widget:amount"
  }
}
```

映射键可以是本地商品 ID，也可以是本地商品 code。

`quantity` 支持：

- `order_num`：使用本地购买数量。
- `fixed:100`：固定提交 100。
- `widget:amount`：读取商品自定义控件 `amount`。

建议把对接 FansGurus 的商品设置为手动发货，并把默认发货内容写成“正在提交上游订单”。插件会在上游下单成功后覆盖为真实上游订单信息。
