# HStockPlus 上游货源插件

本插件对接 HStockPlus Customer API。启用后可同步上游服务/产品到本地商品，付款成功后自动向上游提交订单，并把上游订单号或已交付账号写入本地订单发货内容。

## 配置

- API地址：默认 `https://hstockplus.com/api/v2`。
- API Key：HStockPlus 后台 Customer API 页面中的 key。
- 自动采购：开启后付款成功自动提交上游订单。
- 接管库存：开启后映射商品优先使用 HStockPlus 库存。
- 查询时刷新状态：用户查询订单卡密时自动调用上游 `status`，如果返回 `accounts` 会回填账号列表。
- 同步来源：`services` 同步全部服务与产品，`products` 只同步产品货源。
- 实体类型过滤：留空同步全部，`product` 只同步产品，`smm` 只同步增长服务。

## 商品映射

配置示例：

```json
{
  "1": {
    "service": 12345,
    "link_source": "contact",
    "quantity": "order_num",
    "entity_type": "product",
    "stock": 50
  },
  "HSP_12345": {
    "service": 12345,
    "link_source": "widget:profile_url",
    "quantity": "widget:amount",
    "entity_type": "smm"
  }
}
```

映射键可以是本地商品 ID，也可以是本地商品 code。

`quantity` 支持：

- `order_num`：使用本地购买数量。
- `fixed:100`：固定提交 100。
- `widget:amount`：读取商品自定义控件 `amount`。

`link_source` 支持：

- `contact`：使用下单联系方式。
- `widget:字段名`：使用商品自定义控件。
- `fixed:内容`：固定提交内容。

HStockPlus 文档说明产品订单的 `link` 可选，SMM 订单必填；如果产品映射 `entity_type` 为 `product` 且链接为空，插件会允许空链接提交。
