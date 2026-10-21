(function () {
    'use strict';
    if (window.__xiaohuReleaseUpdater) return;
    window.__xiaohuReleaseUpdater = true;
    const api = '/plugin/XiaohuTheme/api/';
    const escapeHtml = value => String(value == null ? '' : value).replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
    const notify = (type, text) => {
        if (window.message && typeof window.message[type] === 'function') window.message[type](text);
        else window.alert(text);
    };
    let styled = false;
    const ensureStyle = () => {
        if (styled) return;
        styled = true;
        const style = document.createElement('style');
        style.textContent = '.xiaohu-release-update{margin-left:6px;padding:4px 9px;border:1px solid #4d9eff;border-radius:6px;background:rgba(43,126,220,.13);color:#56a5ff;cursor:pointer;font-size:12px;white-space:nowrap}.xiaohu-release-update:hover{background:rgba(43,126,220,.24)}.xiaohu-release-update[disabled]{opacity:.6;cursor:wait}';
        document.head.appendChild(style);
    };
    const check = async button => {
        button.disabled = true;
        button.textContent = '检查中…';
        try {
            const response = await fetch(api + 'check', {credentials:'same-origin',headers:{'Accept':'application/json'}});
            const result = await response.json();
            if (!response.ok || result.code !== 200) throw new Error(result.msg || '检查更新失败');
            const data = result.data || {};
            if (!data.has_update) {
                button.textContent = '检查更新';
                button.dataset.version = '';
                notify('info', '小虎主题套件已是最新版本（v' + (data.current || '') + '）');
                return;
            }
            button.textContent = '更新到 v' + data.latest;
            button.dataset.version = data.latest;
            button.dataset.notes = data.notes || '';
            button.title = '发现 Xiaohu 小虎主题套件 v' + data.latest;
            notify('info', '发现小虎主题套件更新：v' + data.latest);
        } catch (error) {
            button.textContent = '检查更新';
            notify('error', error && error.message ? error.message : '检查更新失败');
        } finally {
            button.disabled = false;
        }
    };
    const apply = button => {
        const version = button.dataset.version;
        if (!version) return check(button);
        const notes = button.dataset.notes || '此版本没有发布说明。';
        const content = '<div style="text-align:left"><b>确认更新小虎主题套件至 v' + escapeHtml(version) + '？</b><p style="margin:10px 0 0;white-space:pre-wrap">' + escapeHtml(notes) + '</p><p style="margin:10px 0 0;color:#888">更新前会在沙盒站点 runtime/XiaohuTheme/updates 留存更新前备份。</p></div>';
        const run = () => {
            button.disabled = true;
            button.textContent = '更新中…';
            if (window.util && typeof window.util.post === 'function') {
                window.util.post(api + 'apply', {version:version}, res => {
                    notify('info', res.msg || '更新完成');
                    if (res.code === 200) location.reload(); else { button.disabled = false;button.textContent = '更新到 v' + version; }
                }, res => { button.disabled = false;button.textContent = '更新到 v' + version;notify('error',res.msg || '更新失败'); });
            } else {
                fetch(api + 'apply', {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:'version='+encodeURIComponent(version)})
                    .then(r=>r.json()).then(res=>{if(res.code!==200)throw new Error(res.msg||'更新失败');location.reload();})
                    .catch(error=>{button.disabled=false;button.textContent='更新到 v'+version;notify('error',error.message||'更新失败');});
            }
        };
        if (window.message && typeof window.message.ask === 'function') window.message.ask(content, run, '确认更新', '立即更新');
        else if (window.confirm('确认更新至 v'+version+'？\n\n'+notes)) run();
    };
    const addButton = () => {
        if (location.pathname !== '/admin/plugin/index') return;
        ensureStyle();
        const state = document.querySelector('.plugin-state[data-id="XiaohuTheme"]');
        const row = state && state.closest('tr');
        const cell = row && row.children[3];
        if (!cell || cell.querySelector('.xiaohu-release-update')) return;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'xiaohu-release-update';
        button.textContent = '检查更新';
        button.addEventListener('click', () => button.dataset.version ? apply(button) : check(button));
        cell.appendChild(button);
    };
    const observer = new MutationObserver(addButton);
    observer.observe(document.body, {childList:true,subtree:true});
    addButton();
    document.addEventListener('pjax:complete', addButton);
    document.addEventListener('pjax:end', addButton);
})();
