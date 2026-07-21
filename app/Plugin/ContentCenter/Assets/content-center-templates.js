!function ($) {
    const api = '/plugin/ContentCenter/api/';
    const escape = value => $('<div>').text(value == null ? '' : String(value)).html();
    const request = (action, data = {}) => $.ajax({url: api + action, method: 'POST', dataType: 'json', data});
    const notify = (message, error = false) => window.toastr ? (error ? toastr.error(message) : toastr.success(message)) : alert(message);
    const statusText = value => ({draft: '草稿', published: '已发布', failed: '发布失败'}[value] || value);
    const meta = {
        blog_home: {
            route: '/blog',
            description: '站内博客首页，继承商城顶部导航，可配置大图轮播、横向轮播、分类筛选和综合文章列表。',
            placeholders: ['{{latest_articles}} 最近 5 篇文章', '{{articles}} 全部文章', '{{article_count}} 文章总数']
        },
        blog_detail: {
            route: '/blog/{slug}',
            description: '站内所有文章共用统一详情布局；这里的 MDX 模板继续用于 Mintlify 同步发布。',
            placeholders: ['{{title}} 标题', '{{excerpt}} 摘要', '{{content}} 正文', '{{published_at}} 发布日期', '{{cover}} 封面', '{{tags}} 标签']
        }
    };

    function load() {
        request('templates').done(res => {
            if (res.code !== 200) return notify(res.msg || '加载失败', true);
            const cards = (res.data || []).map(item => {
                const config = meta[item.template_key] || {};
                const badge = item.status === 'published' ? 'success' : item.status === 'failed' ? 'danger' : 'warning';
                return `<div class="col-12 col-xl-6">
                    <div class="border rounded-3 p-5 h-100 bg-white">
                        <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
                            <div>
                                <div class="fw-bold fs-4 mb-1">${escape(item.name)}</div>
                                <code>${escape(config.route || '')}</code>
                            </div>
                            <span class="badge badge-light-${badge}">${escape(statusText(item.status))}</span>
                        </div>
                        <p class="text-muted fs-7 min-h-45px">${escape(config.description || '')}</p>
                        ${item.publish_error ? `<div class="text-danger fs-8 mb-3">${escape(item.publish_error)}</div>` : ''}
                        <div class="text-muted fs-8 mb-4">最后更新：${escape(item.update_time || '-')}</div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-light edit-template" data-key="${escape(item.template_key)}">编辑模板</button>
                            <button class="btn btn-sm btn-light-primary publish-template" data-key="${escape(item.template_key)}">发布页面</button>
                        </div>
                    </div>
                </div>`;
            }).join('');
            $('#template-list').html(cards || '<div class="text-center text-muted py-10">页面模板初始化中</div>');
        }).fail(xhr => notify(xhr.responseJSON?.msg || '加载失败', true));
    }

    function openEditor(item) {
        const config = meta[item.template_key] || {placeholders: []};
        const settings = item.settings_json || {};
        const articleOptions = item.article_options || [];
        const slideOptions = selected => `<option value="0">自动选择最新文章</option>${articleOptions.map(article => `<option value="${article.id}"${Number(selected) === Number(article.id) ? ' selected' : ''}>#${article.id} ${escape(article.title)}</option>`).join('')}`;
        const slides = Array.from({length: 3}, (_, index) => (settings.hero_slides || [])[index] || {});
        const homeSettings = item.template_key !== 'blog_home' ? '' : `
            <div class="card border mb-5">
                <div class="card-header min-h-60px"><div class="card-title"><div><div class="fw-bold">博客首页展示设置</div><div class="text-muted fs-8">前台只读取已发布设置；保存草稿不会立即影响线上博客首页。</div></div></div></div>
                <div class="card-body">
                    <div class="border rounded-3 mb-4">
                        <div class="d-flex justify-content-between align-items-center gap-3 p-4 border-bottom"><div><div class="fw-bold fs-6">1. 幻灯片大图轮播</div><div class="text-muted fs-8 mt-1">首页首屏大图、精选文章与切换节奏</div></div><label class="form-check form-switch form-check-custom form-check-solid"><input class="form-check-input" type="checkbox" name="hero_enabled" value="1"${settings.hero_enabled !== false ? ' checked' : ''}><span class="form-check-label">是否启用</span></label></div>
                        <div class="p-4">
                            <div class="row g-3 mb-3"><div class="col-md-4"><label class="form-label required">精选标签</label><input class="form-control" name="hero_kicker" maxlength="40" value="${escape(settings.hero_kicker || '')}"></div><div class="col-md-4"><label class="form-label">切换间隔（毫秒）</label><input class="form-control" type="number" name="hero_interval" min="3000" max="15000" step="500" value="${escape(settings.hero_interval || 6500)}"></div></div>
                            ${slides.map((slide, index) => `<div class="row g-3 align-items-end mb-3 cc-slide-setting">
                                <div class="col-md-5"><label class="form-label">第 ${index + 1} 张文章</label><select class="form-select" name="hero_slide_article_${index}">${slideOptions(slide.article_id)}</select></div>
                                <div class="col-md-7"><label class="form-label">图片地址</label><input class="form-control" name="hero_slide_image_${index}" value="${escape(slide.image || '')}" placeholder="https://... 或 /assets/..."></div>
                            </div>`).join('')}
                        </div>
                    </div>

                    <div class="border rounded-3 mb-4">
                        <div class="d-flex justify-content-between align-items-center gap-3 p-4 border-bottom"><div><div class="fw-bold fs-6">2. 横向文章轮播</div><div class="text-muted fs-8 mt-1">从右向左滚动的最新文章卡片</div></div><label class="form-check form-switch form-check-custom form-check-solid"><input class="form-check-input" type="checkbox" name="ticker_enabled" value="1"${settings.ticker_enabled !== false ? ' checked' : ''}><span class="form-check-label">是否启用</span></label></div>
                        <div class="p-4"><div class="row g-3"><div class="col-md-6"><label class="form-label required">区块标题</label><input class="form-control" name="ticker_title" maxlength="40" value="${escape(settings.ticker_title || '')}"></div><div class="col-md-6"><label class="form-label">滚动一圈（秒）</label><input class="form-control" type="number" name="ticker_duration" min="12" max="120" value="${escape(settings.ticker_duration || 28)}"></div></div></div>
                    </div>

                    <div class="border rounded-3 mb-4">
                        <div class="d-flex justify-content-between align-items-center gap-3 p-4 border-bottom"><div><div class="fw-bold fs-6">3. 分类卡片</div><div class="text-muted fs-8 mt-1">点击分类后筛选综合文章列表</div></div><label class="form-check form-switch form-check-custom form-check-solid"><input class="form-check-input" type="checkbox" name="category_enabled" value="1"${settings.category_enabled !== false ? ' checked' : ''}><span class="form-check-label">是否启用</span></label></div>
                        <div class="p-4"><div class="row g-3"><div class="col-md-5"><label class="form-label required">区块标题</label><input class="form-control" name="category_title" maxlength="40" value="${escape(settings.category_title || '')}"></div><div class="col-md-7"><label class="form-label">优先分类</label><input class="form-control" name="featured_categories" value="${escape((settings.featured_categories || []).join(', '))}" placeholder="公告, 教程, 产品更新"><div class="form-text">其余分类会从已发布文章标签自动补充。</div></div></div></div>
                    </div>

                    <div class="border rounded-3 mb-4">
                        <div class="d-flex justify-content-between align-items-center gap-3 p-4 border-bottom"><div><div class="fw-bold fs-6">4. 综合文章列表</div><div class="text-muted fs-8 mt-1">默认文章网格、数量和阅读全文入口</div></div><label class="form-check form-switch form-check-custom form-check-solid"><input class="form-check-input" type="checkbox" name="list_enabled" value="1"${settings.list_enabled !== false ? ' checked' : ''}><span class="form-check-label">是否启用</span></label></div>
                        <div class="p-4"><div class="row g-3"><div class="col-md-8"><label class="form-label required">区块标题</label><input class="form-control" name="list_title" maxlength="40" value="${escape(settings.list_title || '')}"></div><div class="col-md-4"><label class="form-label">文章数量</label><input class="form-control" type="number" name="list_limit" min="3" max="30" value="${escape(settings.list_limit || 12)}"></div></div></div>
                    </div>

                    <label class="form-check form-switch form-check-custom form-check-solid"><input class="form-check-input" type="checkbox" name="animation_enabled" value="1"${settings.animation_enabled !== false ? ' checked' : ''}><span class="form-check-label">启用 GSAP 区块进场与筛选动画</span></label>
                </div>
            </div>`;
        const pageFields = item.template_key === 'blog_detail' ? '' : `
            <div class="row g-3 mb-3">
                <div class="col-md-6"><label class="form-label required">页面标题</label><input class="form-control" name="page_title" maxlength="160" value="${escape(item.page_title || '')}"></div>
                <div class="col-md-6"><label class="form-label">页面描述</label><input class="form-control" name="page_description" maxlength="500" value="${escape(item.page_description || '')}"></div>
            </div>`;
        const placeholderHelp = config.placeholders.map(value => `<span class="badge badge-light me-2 mb-2">${escape(value)}</span>`).join('');
        const html = `<div class="p-4 content-center-template-editor">
            <input type="hidden" name="template_key" value="${escape(item.template_key)}">
            <div class="alert alert-light-primary mb-4">
                <div class="fw-bold mb-1">${escape(item.name)} · ${escape(config.route || '')}</div>
                <div class="fs-7">${escape(config.description || '')}</div>
            </div>
            ${pageFields}
            ${homeSettings}
            <div class="mb-3">
                <label class="form-label required">Mintlify MDX 页面模板（高级）</label>
                <textarea class="form-control font-monospace" name="body_mdx" rows="20">${escape(item.body_mdx || '')}</textarea>
                <div class="form-text mt-3">可用占位符：</div>
                <div class="mt-2">${placeholderHelp}</div>
            </div>
        </div>`;
        layer.open({type: 1, title: '编辑' + item.name, area: ['min(920px, 96vw)', 'min(92vh, 920px)'], content: html, btn: ['保存草稿', '取消'], yes(index, dom) {
            const data = {};
            dom.find('.content-center-template-editor :input[name]').serializeArray().forEach(pair => data[pair.name] = pair.value);
            if (item.template_key === 'blog_home') {
                data.settings_json = JSON.stringify({
                    hero_enabled: dom.find('[name="hero_enabled"]').is(':checked'),
                    ticker_enabled: dom.find('[name="ticker_enabled"]').is(':checked'),
                    category_enabled: dom.find('[name="category_enabled"]').is(':checked'),
                    list_enabled: dom.find('[name="list_enabled"]').is(':checked'),
                    hero_kicker: data.hero_kicker,
                    hero_interval: Number(data.hero_interval || 6500),
                    hero_slides: [0, 1, 2].map(slideIndex => ({
                        article_id: Number(data['hero_slide_article_' + slideIndex] || 0),
                        image: data['hero_slide_image_' + slideIndex] || ''
                    })),
                    ticker_title: data.ticker_title,
                    ticker_duration: Number(data.ticker_duration || 28),
                    category_title: data.category_title,
                    featured_categories: String(data.featured_categories || '').split(',').map(value => value.trim()).filter(Boolean),
                    list_title: data.list_title,
                    list_limit: Number(data.list_limit || 12),
                    animation_enabled: dom.find('[name="animation_enabled"]').is(':checked')
                });
            }
            request('saveTemplate', data).done(res => {
                if (res.code !== 200) return notify(res.msg || '保存失败', true);
                notify(res.msg || '保存成功');
                layer.close(index);
                load();
            }).fail(xhr => notify(xhr.responseJSON?.msg || '保存失败', true));
        }});
    }

    const root = $('#kt_content');
    root.off('.contentCenterTemplates');
    root.on('click.contentCenterTemplates', '.edit-template', function () {
        request('templateDetail', {template_key: $(this).data('key')}).done(res => {
            if (res.code !== 200) return notify(res.msg || '读取失败', true);
            openEditor(res.data);
        }).fail(xhr => notify(xhr.responseJSON?.msg || '读取失败', true));
    });

    root.on('click.contentCenterTemplates', '.publish-template', function () {
        const key = $(this).data('key');
        const config = meta[key] || {};
        layer.confirm('确认发布该页面模板吗？' + (key === 'blog_detail' ? '所有已发布文章会重新应用这套 Mintlify MDX 模板，站内详情布局保持统一。' : '站内博客首页设置会立即生效，同时提交 Mintlify 首页模板。'), () => {
            request('publishTemplate', {template_key: key}).done(res => {
                notify(res.msg || '发布成功', res.code !== 200);
                load();
            }).fail(xhr => {
                notify(xhr.responseJSON?.msg || '发布失败', true);
                load();
            });
        });
    });

    $(load);
}(jQuery);
