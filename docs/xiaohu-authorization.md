# 小虎主题 ETS 授权接入（开发版）

## 约定

- 用户使用 ETS 账号现有的 `ets_ak_…` KEY；不新建授权码或账号表。
- 授权码获取入口：https://tes.txsw.top（新标签页）。
- 服务端校验接口：`POST https://ets-web-admin.pages.dev/api/authorization/verify`，JSON `{ "key": "…", "extensionId": "4f5c8d46-dc0b-4453-8838-5b7a906661c6" }`。KEY 不放入 URL 或日志。
- 使用 ETS 已上架的“XUIHU主题”产品 ID；该产品目前为免费、启用状态，插件标识为 xiaohu-theme。
- 复用 ETS 用户启用状态、KEY 撤销状态、产品状态、免费产品规则、付费产品 `extension_users` 授权和有效期。
- 复用统一接口的 `valid` 与错误码；ETS 服务端校验产品有效期，当前接口不返回到期日期。套件后台不转发用户身份。

## 行为

- 启用套件、通过套件开关新启用配套组件、安装更新之前在线校验。
- 无授权、过期、服务不可用、HTML/错误产品/无效有效期等响应均拒绝上述操作。
- 不在前台或订单请求中校验；不自动停用正在运行的主题，不改订单，不阻止后台登录和停用。
- 检查可用更新仍可使用；实际安装更新需要有效授权。
- 授权设置由现有管理员会话和 Owner 拦截器保护。

## 开发验证

- PHP：`php tests/xiaohu_license.php` 和 `php tests/xiaohu_theme_suite.php`。
- ETS：`node --test tests/free-extension-activation.test.cjs tests/aoste-authorization.test.cjs`（app 目录）。
- 测试采用隔离数据及模拟授权返回，未操作 ETS 正式数据库。
- 不新增 ETS 接口或数据库表，无需 ETS 部署；仍需用用户自行填写的真实 KEY 完成有效/过期/撤销端到端验证。
