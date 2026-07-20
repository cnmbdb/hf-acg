!function ($) {
    const state = window.ContentCenter || {};
    const type = 'blog';
    const api = '/plugin/ContentCenter/api/';
    const label = '文章';
    const escape = value => $('<div>').text(value == null ? '' : String(value)).html();
    const request = (action, data = {}) => $.ajax({url: api + action, method: 'POST', dataType: 'json', data});
    const notify = (message, error = false) => window.toastr ? (error ? toastr.error(message) : toastr.success(message)) : alert(message);
    const status = value => ({draft: '草稿', published: '已发布', failed: '发布失败'}[value] || value);

    function load() {
        request('data', {content_type: type, keyword: $('#content-keyword').val(), status: $('#content-status').val()})
            .done(res => {
                if (res.code !== 200) return notify(res.msg || '加载失败', true);
                const rows = (res.data.list || []).map(item => `<tr>
                    <td><div class="fw-bold">${escape(item.title)}</div><div class="text-muted fs-8">${escape(item.excerpt || '')}</div></td>
                    <td><code>${escape(item.slug)}</code></td>
                    <td><span class="badge badge-light-${item.status === 'published' ? 'success' : item.status === 'failed' ? 'danger' : 'warning'}">${status(item.status)}</span>${item.publish_error ? `<div class="text-danger fs-8 mt-1">${escape(item.publish_error)}</div>` : ''}</td>
                    <td>${escape(item.update_time || '')}</td>
                    <td class="text-end"><button class="btn btn-sm btn-light edit" data-id="${item.id}">编辑</button> <button class="btn btn-sm btn-light-primary publish" data-id="${item.id}">发布</button> <button class="btn btn-sm btn-light-danger remove" data-id="${item.id}">删除</button></td>
                </tr>`).join('');
                $('#content-table tbody').html(rows || '<tr><td colspan="5" class="text-center text-muted py-8">暂无' + label + '</td></tr>');
                $('#content-total').text('共 ' + (res.data.total || 0) + ' 条');
            }).fail(xhr => notify(xhr.responseJSON?.msg || '加载失败', true));
    }

    function editor(item = {}) {
        const coverField = `<div class="mb-3"><label class="form-label">封面</label><input class="form-control" name="cover" value="${escape(item.cover || '')}" placeholder="https://... 或 /assets/cache/..." /></div>`;
        const tagsField = `<div class="mb-3"><label class="form-label">标签</label><input class="form-control" name="tags" value="${escape((item.tags || []).join(', '))}" placeholder="多个标签用英文逗号分隔" /></div>`;
        const html = `<div class="p-4 content-center-editor">
            <input type="hidden" name="id" value="${escape(item.id || '')}"><input type="hidden" name="content_type" value="${type}">
            <div class="mb-3"><label class="form-label required">标题</label><input class="form-control" name="title" value="${escape(item.title || '')}" maxlength="160"></div>
            <div class="mb-3"><label class="form-label required">Slug</label><input class="form-control" name="slug" value="${escape(item.slug || '')}" placeholder="例如 getting-started" maxlength="160"><div class="form-text">只支持小写英文、数字与连字符；发布后会成为 URL 与 MDX 文件名。</div></div>
            <div class="mb-3"><label class="form-label">摘要</label><textarea class="form-control" name="excerpt" rows="2" maxlength="500">${escape(item.excerpt || '')}</textarea></div>
            ${coverField}${tagsField}
            <div class="mb-3"><label class="form-label required">MDX 正文</label><textarea class="form-control font-monospace" name="body_mdx" rows="18" placeholder="# 标题&#10;&#10;正文内容">${escape(item.body_mdx || '')}</textarea><div class="form-text">支持 Mintlify MDX 组件。请不要在正文中再次加入 frontmatter，发布时会自动生成。</div></div>
        </div>`;
        layer.open({type: 1, title: (item.id ? '编辑' : '新建') + label, area: ['min(880px, 96vw)', 'min(92vh, 900px)'], content: html, btn: ['保存草稿', '取消'], yes(index, dom) {
            const data = {};
            dom.find('.content-center-editor :input[name]').serializeArray().forEach(pair => data[pair.name] = pair.value);
            request('save', data).done(res => {
                if (res.code !== 200) return notify(res.msg || '保存失败', true);
                notify(res.msg || '保存成功'); layer.close(index); load();
            }).fail(xhr => notify(xhr.responseJSON?.msg || '保存失败', true));
        }});
    }

    const root = $('#kt_content');
    root.off('.contentCenterArticles');
    root.on('click.contentCenterArticles', '#content-create', () => editor());
    root.on('click.contentCenterArticles', '#content-search', load);
    root.on('keydown.contentCenterArticles', '#content-keyword', event => { if (event.key === 'Enter') load(); });
    root.on('click.contentCenterArticles', '#content-table .edit', function () {
        request('detail', {id: $(this).data('id')}).done(res => res.code === 200 ? editor(res.data) : notify(res.msg || '读取失败', true));
    });
    root.on('click.contentCenterArticles', '#content-table .publish', function () {
        const id = $(this).data('id');
        layer.confirm('发布后会套用统一文章详情页模板，并同步更新文章首页和文章列表。确认发布吗？', () => {
            request('publish', {id}).done(res => { notify(res.msg || '发布成功', res.code !== 200); load(); }).fail(xhr => { notify(xhr.responseJSON?.msg || '发布失败', true); load(); });
        });
    });
    root.on('click.contentCenterArticles', '#content-table .remove', function () {
        const id = $(this).data('id');
        layer.confirm('只删除本系统的内容记录，不会删除已发布的 GitHub MDX 文件。确认继续吗？', () => {
            request('remove', {id}).done(res => { notify(res.msg || '已删除', res.code !== 200); load(); }).fail(xhr => notify(xhr.responseJSON?.msg || '删除失败', true));
        });
    });
    $(load);
}(jQuery);
