!function ($) {
    const api = '/plugin/ContentCenter/api/';
    const escape = value => $('<div>').text(value == null ? '' : String(value)).html();
    const request = (action, data = {}) => $.ajax({url: api + action, method: 'POST', dataType: 'json', data});
    const notify = (message, error = false) => window.toastr ? (error ? toastr.error(message) : toastr.success(message)) : alert(message);
    const statusText = value => ({draft: '草稿', published: '已发布', failed: '发布失败'}[value] || value);
    const meta = {
        blog_home: {
            route: '/',
            description: '博客入口页，可设置介绍内容并展示最近发布的文章。',
            placeholders: ['{{latest_articles}} 最近 5 篇文章', '{{articles}} 全部文章', '{{article_count}} 文章总数']
        },
        blog_list: {
            route: '/blog',
            description: '全部文章的统一列表页，发布文章后会自动刷新。',
            placeholders: ['{{articles}} 全部文章', '{{latest_articles}} 最近 5 篇文章', '{{article_count}} 文章总数']
        },
        blog_detail: {
            route: '/blog/{slug}',
            description: '所有文章共用此详情模板，文章标题、摘要和正文会自动填充。',
            placeholders: ['{{title}} 标题', '{{excerpt}} 摘要', '{{content}} 正文', '{{published_at}} 发布日期', '{{cover}} 封面', '{{tags}} 标签']
        }
    };

    function load() {
        request('templates').done(res => {
            if (res.code !== 200) return notify(res.msg || '加载失败', true);
            const cards = (res.data || []).map(item => {
                const config = meta[item.template_key] || {};
                const badge = item.status === 'published' ? 'success' : item.status === 'failed' ? 'danger' : 'warning';
                return `<div class="col-12 col-xl-4">
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
            <div class="mb-3">
                <label class="form-label required">MDX 页面模板</label>
                <textarea class="form-control font-monospace" name="body_mdx" rows="20">${escape(item.body_mdx || '')}</textarea>
                <div class="form-text mt-3">可用占位符：</div>
                <div class="mt-2">${placeholderHelp}</div>
            </div>
        </div>`;
        layer.open({type: 1, title: '编辑' + item.name, area: ['min(920px, 96vw)', 'min(92vh, 920px)'], content: html, btn: ['保存草稿', '取消'], yes(index, dom) {
            const data = {};
            dom.find('.content-center-template-editor :input[name]').serializeArray().forEach(pair => data[pair.name] = pair.value);
            request('saveTemplate', data).done(res => {
                if (res.code !== 200) return notify(res.msg || '保存失败', true);
                notify(res.msg || '保存成功');
                layer.close(index);
                load();
            }).fail(xhr => notify(xhr.responseJSON?.msg || '保存失败', true));
        }});
    }

    $(document).on('click', '.edit-template', function () {
        request('templateDetail', {template_key: $(this).data('key')}).done(res => {
            if (res.code !== 200) return notify(res.msg || '读取失败', true);
            openEditor(res.data);
        }).fail(xhr => notify(xhr.responseJSON?.msg || '读取失败', true));
    });

    $(document).on('click', '.publish-template', function () {
        const key = $(this).data('key');
        const config = meta[key] || {};
        layer.confirm('确认发布该页面模板吗？' + (key === 'blog_detail' ? '所有已发布文章都会重新应用这套详情模板。' : '对应页面会立即提交到 GitHub 并触发 Mintlify 更新。'), () => {
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
