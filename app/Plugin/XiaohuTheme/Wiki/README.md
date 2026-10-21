# Xiaohu 小虎主题套件

在通用插件中启用本套件会安装 Xiaohu 主题和五个配套组件。进入 Xiaohu 小虎主题套件配置页，可分别开关 ContentCenter、FansGurus、HStockPlus、ManualShopPage、XiaohuGuard；启用的组件会运行并显示在通用插件列表，关闭的组件会停用并隐藏。Xiaohu 店铺主题由套件本身管理。

- Cartoon 默认模板不打包、不修改；会员中心选择保持原值。
- 组件各自的设置入口保留，已有 API Key、映射、文章和主题设置不会覆盖。
- 套件启用不触发上游商品全量同步、不下单、不发布文章；在组件设置中单独操作。
- 新安装时五个组件各自默认关闭；从旧版升级且没有各自配置时，沿用 v1.2.0 的组件总开关状态。
- 停用套件恢复首次启用前的店铺主题选择并保留文件和数据；每个关闭的组件保持停用，开启的组件恢复启用套件前的状态。
- 设置中“修复套件组件”可补回缺失文件，不覆盖已有文件。
- 安装包不含生产凭据及运行缓存，默认从干净配置开始；上游接口需自行配置。

## 安装到其他开发站点

将安装包解压到 `app/Plugin/XiaohuTheme/`，在站点根目录执行：

```sh
php app/Plugin/XiaohuTheme/install.php 你的站点域名
```

然后在“通用插件”中启用“Xiaohu 小虎主题套件”。通过原生应用安装流程安装时，INSTALL 生命周期会自动准备组件。

套件单独维护自身与配套组件的挂钩注册，并通过框架的控制器前置挂钩处理套件开关；不改动主程序的插件控制器。其他插件的挂钩会原样保留。停用时保留套件的开关入口，以便再次启用。

## 公开 Releases 独立更新

“通用插件”列表中 Xiaohu 套件这一行提供“检查更新”按钮。更新器只读取 `cnmbdb/hf-acg` 仓库里 `xiaohu-vMAJOR.MINOR.PATCH` 格式的稳定 Release，并校验发布清单中的版本、包大小与 SHA-256 后才安装。当前更新包声明兼容 ACG 3.8.4。

仓库设为公开后，检查与下载 Release 无需配置 GitHub Token；站点只需能通过 HTTPS 访问 GitHub Releases API 和公开附件下载域名。GitHub 匿名 API 有访问频率限制，日常从后台手动检查更新通常足够；不要在请求中添加不必要的 Token。

在 `xiaohu` 分支提交代码后，推送 `xiaohu-vMAJOR.MINOR.PATCH` 标签会触发 `.github/workflows/xiaohu-release.yml`，自动构建只含 Xiaohu 套件的更新 ZIP 与 `xiaohu-update.json`，并发布到公开仓库的 Release 附件。发布包不包含 `.env`、Cartoon、运行数据、数据库或用户配置。更新时会在 `runtime/XiaohuTheme/updates/` 留存更新前文件备份。

沙盒网络策略需要允许 HTTPS 访问 `api.github.com`、`objects.githubusercontent.com`、`release-assets.githubusercontent.com` 与 `github-releases.githubusercontent.com`。

开发环境中，上游 API、真实支付、自动采购及 Mintlify 发布仍需分别配置和验收。本套件不会自动执行这些业务操作。
