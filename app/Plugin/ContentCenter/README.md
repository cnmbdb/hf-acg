# 内容中心（Mintlify）

后台左侧只有一个“博客管理”入口，顶部包含两个区域：

- **博客管理**：创建、编辑和发布文章。
- **页面管理**：管理三个固定页面——文章首页、文章列表、文章详情页。

文章详情页是一套共享模板，所有文章发布时都会自动套用。页面模板和文章草稿保存在 MySQL，点击发布后由服务器生成 MDX 并提交到 GitHub，Mintlify GitHub App 随后自动部署。

## 输出结构

- `docs/index.mdx`：文章首页，默认展示最近 5 篇文章。
- `docs/blog/index.mdx`：文章列表，展示全部已发布文章。
- `docs/blog/<slug>.mdx`：文章详情，统一使用文章详情页模板。
- `docs/docs.json`：Mintlify 导航和主题配置。

## 模板占位符

文章首页支持 `{{latest_articles}}`、`{{articles}}`、`{{article_count}}`；文章列表支持 `{{articles}}`、`{{latest_articles}}`、`{{article_count}}`；文章详情页支持 `{{title}}`、`{{excerpt}}`、`{{content}}`、`{{published_at}}`、`{{cover}}`、`{{tags}}`。

## 发布凭据

只在服务器 `.env` 中配置 `CONTENT_CENTER_GITHUB_TOKEN=`。令牌应为仅授权当前仓库、仅开放 `Contents: Read and write` 的细粒度 Token，不能写入插件设置、前端代码或 Git 仓库。
