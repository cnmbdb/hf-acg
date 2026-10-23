(function () {
    'use strict';
    if (window.__xiaohuSuiteSettings) return;
    window.__xiaohuSuiteSettings = true;
    const components = [
        ['content_center_enabled','内容中心','ContentCenter · 文章与文档内容管理','▤'],
        ['fans_gurus_enabled','FansGurus 上游货源','FansGurus · 上游服务与订单扩展','↗'],
        ['hstock_plus_enabled','HStockPlus 上游货源','HStockPlus · 商品与库存扩展','▦'],
        ['manual_shop_page_enabled','默认商品页','ManualShopPage · 商品页展示设置','▣'],
        ['xiaohu_guard_enabled','小虎主题保护','XiaohuGuard · 定制文件保护','◇']
    ];
    const request = async (url, data) => {
        const response = await fetch(url, {method:'POST',credentials:'same-origin',headers:{'Accept':'application/json','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:new URLSearchParams(data).toString()});
        let result;
        try { result = JSON.parse(await response.text()); }
        catch (_) { throw new Error('登录状态可能已过期，请刷新后台后重试。'); }
        if (!response.ok || result.code !== 200) throw new Error(result.msg || '请求失败，请稍后重试。');
        return result;
    };
    let loading = false;
    const open = async trigger => {
        if (loading || document.querySelector('.xh-settings')) return;
        loading = true;
        trigger.disabled = true;
        try {
            const result = await request('/admin/api/plugin/getPlugins', {keywords:'Xiaohu'});
            const plugin = ((result.data || {}).list || []).find(item=>item.id==='XiaohuTheme');
            if (!plugin) throw new Error('未找到小虎主题套件，请刷新插件列表。');
            const config = plugin.PLUGIN_CONFIG || {};
            const legacy = Number(config.components_enabled == null ? 1 : config.components_enabled) === 1;
            const checked = key => Number(config[key] == null ? (legacy ? 1 : 0) : config[key]) === 1;
            const dialog = document.createElement('dialog');
            dialog.className = 'xh-settings';
            dialog.setAttribute('aria-label','小虎主题套件配置');
            dialog.innerHTML = `<form class="xh-settings-form"><header class="xh-settings-head"><button class="xh-settings-close" type="button" aria-label="关闭配置">×</button></header><div class="xh-settings-body"><details class="xh-settings-card"><summary class="xh-settings-summary"><span aria-hidden="true">▦</span><div><strong>配套子插件</strong></div><span class="xh-settings-chip" data-component-count></span></summary>${components.map(([key,title,desc,icon]) => `<label class="xh-settings-component"><span aria-hidden="true">${icon}</span><div><strong>${title}</strong><small>${desc}</small></div><input class="xh-settings-toggle" type="checkbox" role="switch" name="${key}" aria-label="${title}" ${checked(key)?'checked':''}></label>`).join('')}<div class="xh-settings-component"><p>打开并保存后启用插件；关闭并保存后停用，并从通用插件列表隐藏。</p></div></details><section class="xh-settings-card xh-settings-license"><div class="xh-settings-card-title"><div style="display:flex;align-items:center;gap:12px"><label class="xh-settings-field" for="xh-license-code">授权码</label><a href="https://tes.txsw.top" target="_blank" rel="noopener noreferrer" style="font-size:12px;color:#2563eb;text-decoration:none">获取授权码 ↗</a></div><span class="xh-settings-chip" data-license-state>未验证</span></div><div class="xh-settings-license-row"><div class="xh-settings-input-wrap"><input id="xh-license-code" class="xh-settings-code" name="license_code" type="password" placeholder="填写你的主题授权码" autocomplete="off" spellcheck="false" maxlength="512"><button type="button" class="xh-settings-reveal" aria-label="显示授权码" aria-pressed="false">显示</button></div><button type="button" class="xh-settings-button" data-license-verify>验证授权</button></div></section><details class="xh-settings-card"><summary class="xh-settings-summary"><span aria-hidden="true">⚙</span><div><strong>维护工具</strong></div></summary><label class="xh-settings-component"><div><strong>修复套件组件</strong></div><input class="xh-settings-toggle" name="repair_components" type="checkbox" role="switch" aria-label="修复套件组件" ${Number(config.repair_components || 0) === 1 ? 'checked' : ''}></label></details></div><footer class="xh-settings-footer"><span class="xh-settings-feedback" role="status" aria-live="polite"></span><button type="button" class="xh-settings-button" data-cancel>取消</button><button type="submit" class="xh-settings-button xh-settings-primary">保存设置</button></footer></form>`;
            document.body.appendChild(dialog);
            const code = dialog.querySelector('[name="license_code"]');
            code.value = String(config.license_code || '');
            let verificationSequence = 0;
            const verifyLicense = async () => {
                const sequence = ++verificationSequence;
                const key = code.value.trim();
                const isCurrent = () => sequence === verificationSequence && dialog.isConnected && code.value.trim() === key;
                const badge = dialog.querySelector('[data-license-state]');
                const button = dialog.querySelector('[data-license-verify]');
                button.disabled = true; badge.textContent = '验证中…';
                try {
                    const result = await request('/plugin/XiaohuTheme/api/license', {license_code:key});
                    if (!isCurrent()) return;
                    const state = result.data || {};
                    badge.textContent = state.valid ? (state.expiresAt ? '已验证 · '+new Date(state.expiresAt).toLocaleDateString() : '已验证') : (state.message || '未验证');
                    badge.title = state.message || '';
                } catch(error) { if (isCurrent()) badge.textContent = error.message; }
                finally { if (isCurrent()) button.disabled = false; }
            };
            code.addEventListener('input', () => {
                verificationSequence++;
                const badge = dialog.querySelector('[data-license-state]');
                badge.textContent = '未验证';
                badge.title = '';
                dialog.querySelector('[data-license-verify]').disabled = false;
            });
            dialog.querySelector('[data-license-verify]').addEventListener('click', verifyLicense);
            const updateCount = () => { dialog.querySelector('[data-component-count]').textContent = components.filter(([key])=>dialog.querySelector(`[name="${key}"]`).checked).length+' / 5 已开启'; };
            updateCount();
            dialog.querySelectorAll('.xh-settings-toggle').forEach(input=>input.addEventListener('change',updateCount));
            dialog.querySelector('.xh-settings-reveal').addEventListener('click',event=>{
                const show = code.type === 'password';code.type=show?'text':'password';event.currentTarget.textContent=show?'隐藏':'显示';event.currentTarget.setAttribute('aria-label',show?'隐藏授权码':'显示授权码');event.currentTarget.setAttribute('aria-pressed',String(show));
            });
            const close = () => dialog.close();
            dialog.querySelector('.xh-settings-close').addEventListener('click',close);
            dialog.querySelector('[data-cancel]').addEventListener('click',close);
            dialog.addEventListener('close',()=>{dialog.remove();trigger.focus();},{once:true});
            dialog.querySelector('form').addEventListener('submit',async event=>{
                event.preventDefault();
                const save = dialog.querySelector('[type="submit"]'),feedback=dialog.querySelector('.xh-settings-feedback');
                const data={license_code:code.value.trim(),repair_components:dialog.querySelector('[name="repair_components"]').checked?'1':'0'};
                components.forEach(([key])=>{data[key]=dialog.querySelector(`[name="${key}"]`).checked?'1':'0';});
                save.disabled=true;save.textContent='保存中…';feedback.className='xh-settings-feedback';feedback.textContent='正在应用设置…';
                try {
                    await request('/admin/api/plugin/setConfig?id=XiaohuTheme',data);
                    feedback.className='xh-settings-feedback xh-settings-success';feedback.textContent='已保存，插件设置已生效';
                    await verifyLicense();
                    if(window.jQuery && typeof window.jQuery('#plugin-table').bootstrapTable==='function') window.jQuery('#plugin-table').bootstrapTable('refresh',{silent:true});
                } catch(error) {feedback.className='xh-settings-feedback xh-settings-error';feedback.textContent=error.message;}
                finally {save.disabled=false;save.textContent='保存设置';}
            });
            dialog.showModal();
            if (code.value.trim()) void verifyLicense();
        } catch(error) {
            if(window.message && typeof window.message.error==='function') window.message.error(error.message);else window.alert(error.message);
        } finally {loading=false;trigger.disabled=false;}
    };
    document.addEventListener('click',event=>{
        if(location.pathname!=='/admin/plugin/index')return;
        const button=event.target.closest('button,a');
        if(!button || (button.getAttribute('data-btn-title')!=='配置' && button.textContent.trim()!=='配置'))return;
        const row=button.closest('tr');
        if(!row || !row.querySelector('.plugin-state[data-id="XiaohuTheme"]'))return;
        event.preventDefault();event.stopImmediatePropagation();open(button);
    },true);
    const align = () => {
        if(location.pathname!=='/admin/plugin/index')return;
        const state=document.querySelector('.plugin-state[data-id="XiaohuTheme"]');
        const row=state && state.closest('tr'),cell=row && row.children[3];
        if(!cell)return;
        let bar=cell.querySelector('.xh-suite-actionbar');
        if(!bar){bar=document.createElement('span');bar.className='xh-suite-actionbar';cell.appendChild(bar);}
        [...cell.childNodes].filter(node=>node!==bar).forEach(node=>bar.appendChild(node));
    };
    new MutationObserver(align).observe(document.body,{childList:true,subtree:true});
    align();
})();
