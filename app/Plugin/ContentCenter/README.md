# 内容中心（Mintlify）

后台左侧只有一个“博客管理”入口，页面顶部可切换“博客管理”和“页面管理”。所有内容先安全保存到 MySQL；点击发布后，服务器将 MDX 提交到**独立的** GitHub 文档仓库，Mintlify GitHub App 随后自动部署。

## 首次接入

1. 新建一个仅用于文档的私有 GitHub 仓库，复制本目录 `DocsStarter/docs.json` 到仓库根目录。
2. 在 Mintlify 创建项目，连接这个仓库和发布分支，并安装 Mintlify GitHub App。
3. 在本插件设置中填写 Owner、仓库名、分支、文档目录（根目录留空）和线上文档地址。
4. 仅在服务器 `.env` 中添加 `CONTENT_CENTER_GITHUB_TOKEN=`。令牌必须是细粒度 Token，且只授予该文档仓库 `Contents: Read and write`；绝不要填写到插件设置或前端代码中。
5. 首次发布一篇博客和一个页面。系统会生成 `blog/<slug>.mdx` 或 `pages/<slug>.mdx`，并同步更新 `blog/index.mdx`、`pages/index.mdx`，所以两类内容都能从 Mintlify 导航进入。

发布前可在文档仓库本地执行 `mint validate` 与 `mint broken-links`。删除后台记录不会删除 GitHub 文件，以避免误删已上线页面；需要下线时请在后台编辑后重新发布，或在文档仓库走审查后的删除提交。
