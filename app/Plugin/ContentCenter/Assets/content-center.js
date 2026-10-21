!function ($) {
    const type = 'blog';
    const api = '/plugin/ContentCenter/api/';
    const label = '文章';
    const escape = value => $('<div>').text(value == null ? '' : String(value)).html();
    const request = (action, data = {}) => $.ajax({url: api + action, method: 'POST', dataType: 'json', data});
    const notify = (message, error = false) => window.toastr ? (error ? toastr.error(message) : toastr.success(message)) : alert(message);
    const status = value => ({draft: '草稿', published: '已发布', failed: '发布失败'}[value] || value);
    const coverUploadUrl = '/admin/api/upload/send?mime=image';
    const coverTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    const maxCoverSize = 8 * 1024 * 1024;
    let draggedRow = null;
    let originalOrder = [];
    let sorting = false;

    function renderCoverPreview(editor, value) {
        const preview = editor.find('[data-cover-preview]');
        const image = preview.find('img');
        const empty = preview.find('[data-cover-empty]');
        const url = String(value || '').trim();

        preview.removeClass('has-error');
        if (!url) {
            image.removeAttr('src').addClass('d-none');
            empty.removeClass('d-none').find('span').text('暂未设置封面');
            return;
        }

        empty.addClass('d-none');
        image.removeClass('d-none').attr('src', url);
    }

    function bindCoverUploader(editor) {
        const coverInput = editor.find('input[name="cover"]');
        const fileInput = editor.find('[data-cover-file]');
        const uploadButton = editor.find('[data-cover-upload]');
        const preview = editor.find('[data-cover-preview]');
        const image = preview.find('img');

        coverInput.on('input change', () => renderCoverPreview(editor, coverInput.val()));
        uploadButton.on('click', () => fileInput.trigger('click'));
        editor.find('[data-cover-remove]').on('click', () => {
            coverInput.val('').trigger('change');
            fileInput.val('');
        });
        image.on('load', () => preview.removeClass('has-error'));
        image.on('error', () => {
            if (!image.attr('src')) return;
            image.addClass('d-none');
            preview.addClass('has-error').find('[data-cover-empty]').removeClass('d-none').find('span').text('图片无法预览，请检查地址');
        });
        renderCoverPreview(editor, coverInput.val());

        fileInput.on('change', function () {
            const file = this.files && this.files[0];
            if (!file) return;
            if (!coverTypes.includes(file.type)) {
                this.value = '';
                return notify('仅支持 JPG、PNG、WebP 或 GIF 图片', true);
            }
            if (file.size > maxCoverSize) {
                this.value = '';
                return notify('封面图片不能超过 8MB', true);
            }

            const data = new FormData();
            data.append('file', file);
            uploadButton.prop('disabled', true).html('<i class="fa-duotone fa-regular fa-spinner-third fa-spin"></i> 上传中');
            $.ajax({
                url: coverUploadUrl,
                method: 'POST',
                data,
                dataType: 'json',
                contentType: false,
                processData: false
            }).done(res => {
                if (res.code !== 200 || !res.data?.url) return notify(res.msg || '封面上传失败', true);
                coverInput.val(res.data.url).trigger('change');
                notify('封面上传成功，保存文章后生效');
            }).fail(xhr => notify(xhr.responseJSON?.msg || '封面上传失败，请稍后重试', true))
                .always(() => {
                    uploadButton.prop('disabled', false).html('<i class="fa-duotone fa-regular fa-cloud-arrow-up"></i> 上传封面');
                    fileInput.val('');
                });
        });
    }

    function load() {
        const keyword = String($('#content-keyword').val() || '').trim();
        const selectedStatus = String($('#content-status').val() || '');
        request('data', {content_type: type, keyword, status: selectedStatus, limit: 1000})
            .done(res => {
                if (res.code !== 200) return notify(res.msg || '加载失败', true);
                const list = res.data.list || [];
                const total = Number(res.data.total || 0);
                const sortable = keyword === '' && selectedStatus === '' && list.length === total;
                const rows = list.map(item => {
                    const previewUrl = item.status === 'published' ? '/blog/' + encodeURIComponent(item.slug) : '';
                    const previewButton = previewUrl ? `<a class="btn btn-sm btn-light-success" href="${escape(previewUrl)}" target="_blank" rel="noopener">站内预览</a> ` : '';
                    const sortHandle = sortable
                        ? '<button class="cc-drag-handle" type="button" draggable="true" title="按住拖动排序" aria-label="拖动文章排序"><i class="fa-duotone fa-regular fa-grip-dots-vertical"></i></button>'
                        : '<span class="text-muted" title="清除筛选后可拖动排序"><i class="fa-duotone fa-regular fa-grip-dots-vertical"></i></span>';
                    return `<tr data-id="${Number(item.id)}">
                    <td class="cc-sort-cell">${sortHandle}</td>
                    <td><div class="fw-bold">${escape(item.title)}</div><div class="text-muted fs-8">${escape(item.excerpt || '')}</div></td>
                    <td><code>${escape(item.slug)}</code></td>
                    <td><span class="badge badge-light-${item.status === 'published' ? 'success' : item.status === 'failed' ? 'danger' : 'warning'}">${status(item.status)}</span>${item.publish_error ? `<div class="text-danger fs-8 mt-1">${escape(item.publish_error)}</div>` : ''}</td>
                    <td>${escape(item.update_time || '')}</td>
                    <td class="text-end">${previewButton}<button class="btn btn-sm btn-light edit" data-id="${item.id}">编辑</button> <button class="btn btn-sm btn-light-primary publish" data-id="${item.id}">发布</button> <button class="btn btn-sm btn-light-danger remove" data-id="${item.id}">删除</button></td>
                </tr>`;
                }).join('');
                $('#content-table tbody').html(rows || '<tr><td colspan="6" class="text-center text-muted py-8">暂无' + label + '</td></tr>');
                $('#content-total').text('共 ' + total + ' 条 · ' + (sortable ? '按住左侧手柄拖动排序，松开后自动保存' : '清除搜索和状态筛选后可拖动排序'));
            }).fail(xhr => notify(xhr.responseJSON?.msg || '加载失败', true));
    }

    function tableOrder() {
        return $('#content-table tbody tr[data-id]').map(function () {
            return Number($(this).data('id'));
        }).get();
    }

    function clearDragState() {
        $('#content-table tbody tr').removeClass('is-dragging is-sorting');
        draggedRow = null;
        originalOrder = [];
    }

    function saveOrder(ids) {
        if (sorting) return;
        sorting = true;
        $('#content-table .cc-drag-handle').prop('disabled', true);
        request('reorder', {ids: ids.join(',')}).done(res => {
            if (res.code !== 200) {
                notify(res.msg || '排序保存失败', true);
            } else {
                notify(res.msg || '文章顺序已保存');
            }
            load();
        }).fail(xhr => {
            notify(xhr.responseJSON?.msg || '排序保存失败', true);
            load();
        }).always(() => {
            sorting = false;
            clearDragState();
        });
    }

    function editor(item = {}) {
        const coverField = `<div class="mb-4">
            <label class="form-label">文章封面图</label>
            <div class="cc-cover-uploader">
                <div class="cc-cover-preview" data-cover-preview>
                    <img class="d-none" alt="文章封面预览">
                    <div class="cc-cover-empty" data-cover-empty><i class="fa-duotone fa-regular fa-image"></i><span>暂未设置封面</span></div>
                </div>
                <div class="cc-cover-controls">
                    <input class="form-control" name="cover" value="${escape(item.cover || '')}" placeholder="上传图片后自动填写，也可粘贴 HTTPS 地址或本站路径">
                    <input class="d-none" type="file" accept="image/jpeg,image/png,image/webp,image/gif" data-cover-file>
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <button class="btn btn-sm btn-primary" type="button" data-cover-upload><i class="fa-duotone fa-regular fa-cloud-arrow-up"></i> 上传封面</button>
                        <button class="btn btn-sm btn-light-danger" type="button" data-cover-remove><i class="fa-duotone fa-regular fa-trash-can"></i> 移除</button>
                    </div>
                    <div class="form-text mt-2">支持 JPG、PNG、WebP、GIF，最大 8MB；建议使用 16:9 横图。</div>
                </div>
            </div>
        </div>`;
        const tagsField = `<div class="mb-3"><label class="form-label">文章分类标题</label><input class="form-control" name="tags" value="${escape((item.tags || []).join(', '))}" placeholder="多个分类标题用英文逗号分隔" /></div>`;
        const html = `<div class="p-4 content-center-editor">
            <input type="hidden" name="id" value="${escape(item.id || '')}"><input type="hidden" name="content_type" value="${type}">
            <div class="mb-3"><label class="form-label required">标题</label><input class="form-control" name="title" value="${escape(item.title || '')}" maxlength="160"></div>
            <div class="mb-3"><label class="form-label required">Slug</label><input class="form-control" name="slug" value="${escape(item.slug || '')}" placeholder="例如 getting-started" maxlength="160"><div class="form-text">只支持小写英文、数字与连字符；发布后会成为 URL 与 MDX 文件名。</div></div>
            <div class="mb-3"><label class="form-label">摘要</label><textarea class="form-control" name="excerpt" rows="2" maxlength="500">${escape(item.excerpt || '')}</textarea></div>
            ${coverField}${tagsField}
            <div class="mb-3"><label class="form-label required">Markdown / MDX 正文</label><textarea class="form-control font-monospace" name="body_mdx" rows="18" placeholder="# 标题&#10;&#10;正文内容">${escape(item.body_mdx || '')}</textarea><div class="form-text">Markdown 会直接渲染到本站文章详情页，并继续同步 Mintlify。请不要在正文中再次加入 frontmatter。</div></div>
        </div>`;
        layer.open({type: 1, title: (item.id ? '编辑' : '新建') + label, area: ['min(880px, 96vw)', 'min(92vh, 900px)'], content: html, btn: ['保存草稿', '取消'], success(dom) {
            bindCoverUploader(dom.find('.content-center-editor'));
        }, yes(index, dom) {
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
    root.on('dragstart.contentCenterArticles', '#content-table .cc-drag-handle', function (event) {
        if (sorting) {
            event.preventDefault();
            return;
        }
        draggedRow = $(this).closest('tr')[0];
        originalOrder = tableOrder();
        draggedRow.classList.add('is-dragging');
        event.originalEvent.dataTransfer.effectAllowed = 'move';
        event.originalEvent.dataTransfer.setData('text/plain', draggedRow.dataset.id || '');
    });
    root.on('dragover.contentCenterArticles', '#content-table tbody tr[data-id]', function (event) {
        if (!draggedRow || this === draggedRow) return;
        event.preventDefault();
        event.originalEvent.dataTransfer.dropEffect = 'move';
        const rect = this.getBoundingClientRect();
        const insertAfter = event.originalEvent.clientY > rect.top + rect.height / 2;
        this.parentNode.insertBefore(draggedRow, insertAfter ? this.nextSibling : this);
        $('#content-table tbody tr').removeClass('is-sorting');
        this.classList.add('is-sorting');
    });
    root.on('drop.contentCenterArticles', '#content-table tbody', function (event) {
        if (!draggedRow) return;
        event.preventDefault();
        const ids = tableOrder();
        const changed = ids.join(',') !== originalOrder.join(',');
        clearDragState();
        if (changed) saveOrder(ids);
    });
    root.on('dragend.contentCenterArticles', '#content-table .cc-drag-handle', function () {
        const cancelled = draggedRow && tableOrder().join(',') !== originalOrder.join(',');
        clearDragState();
        if (cancelled) load();
    });
    root.on('click.contentCenterArticles', '#content-table .edit', function () {
        request('detail', {id: $(this).data('id')}).done(res => res.code === 200 ? editor(res.data) : notify(res.msg || '读取失败', true));
    });
    root.on('click.contentCenterArticles', '#content-table .publish', function () {
        const id = $(this).data('id');
        layer.confirm('发布后本站文章详情页会立即可访问，并同步更新 GitHub、Mintlify 和文章列表。确认发布吗？', () => {
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
