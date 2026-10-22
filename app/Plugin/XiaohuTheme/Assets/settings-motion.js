(function () {
    'use strict';
    if (window.__xiaohuSettingsMotion || !window.gsap) return;
    window.__xiaohuSettingsMotion = true;
    const gsap = window.gsap;
    const preference = window.matchMedia('(prefers-reduced-motion: reduce)');
    const live = new Set();
    const registered = new WeakSet();
    const enabled = () => !preference.matches;

    const attach = dialog => {
        if (registered.has(dialog) || !dialog.open) return;
        registered.add(dialog);
        const states = [];
        const state = {dialog, closing:false, dispose:null, settle:null};
        const clear = () => {
            gsap.killTweensOf(dialog);
            gsap.killTweensOf(dialog.querySelectorAll('*'));
            gsap.set(dialog, {clearProps:'transform,opacity,pointerEvents'});
            states.forEach(item => {
                item.details.open = item.expanded;
                item.content.inert = !item.expanded;
                item.content.setAttribute('aria-hidden', String(!item.expanded));
                gsap.set(item.details, {clearProps:'height,overflow'});
            });
        };
        const close = () => {
            if (state.closing) return;
            state.closing = true;
            if (!enabled()) { dialog.close(); return; }
            gsap.killTweensOf(dialog);
            dialog.classList.add('xh-motion-closing');
            gsap.to(dialog, {opacity:0, y:8, scale:0.985, duration:0.16, ease:'power2.in',
                onComplete:() => { if (dialog.open) dialog.close(); }});
        };
        const interceptClose = event => {
            if (!event.target.closest('.xh-settings-close,[data-cancel]')) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            close();
        };
        const cancel = event => { event.preventDefault(); close(); };
        dialog.addEventListener('click',interceptClose,true);
        dialog.addEventListener('cancel',cancel);

        dialog.querySelectorAll('details.xh-settings-card').forEach(details => {
            const summary = details.querySelector('summary');
            const content = document.createElement('div');
            content.className = 'xh-settings-accordion-content';
            [...details.childNodes].filter(node => node !== summary).forEach(node => content.appendChild(node));
            details.appendChild(content);
            const item = {details, content, summary, expanded:details.open};
            states.push(item);
            content.inert = !item.expanded;
            content.setAttribute('aria-hidden',String(!item.expanded));
            details.dataset.xhExpanded = String(item.expanded);
            summary.addEventListener('click',event => {
                event.preventDefault();
                if (state.closing) return;
                item.expanded = !item.expanded;
                details.dataset.xhExpanded = String(item.expanded);
                content.inert = !item.expanded;
                content.setAttribute('aria-hidden',String(!item.expanded));
                gsap.killTweensOf(details);
                if (!enabled()) {
                    details.open = item.expanded;
                    gsap.set(details,{clearProps:'height,overflow'});
                    return;
                }
                const start = details.getBoundingClientRect().height;
                // Keep native details open until the closing animation finishes.
                details.open = true;
                details.style.height = 'auto';
                const border = parseFloat(getComputedStyle(details).borderTopWidth || 0)
                    + parseFloat(getComputedStyle(details).borderBottomWidth || 0);
                const end = item.expanded ? details.getBoundingClientRect().height
                    : summary.getBoundingClientRect().height + border;
                gsap.fromTo(details, {height:start,overflow:'hidden'}, {
                    height:end, duration:0.28, ease:'power2.inOut', overwrite:true,
                    onComplete:() => {
                        details.open = item.expanded;
                        gsap.set(details,{clearProps:'height,overflow'});
                    }
                });
            });
        });
        const feedback = dialog.querySelector('.xh-settings-feedback');
        const feedbackObserver = new MutationObserver(() => {
            if (!feedback.textContent || !enabled() || state.closing) return;
            gsap.fromTo(feedback,{opacity:0,y:3},{opacity:1,y:0,duration:0.18,ease:'power2.out',overwrite:true,clearProps:'transform,opacity'});
        });
        feedbackObserver.observe(feedback,{childList:true,characterData:true,subtree:true});
        const resize = () => { if (!state.closing) clear(); };
        window.addEventListener('resize',resize,{passive:true});
        state.settle = () => {
            clear();
            if (state.closing && dialog.open) dialog.close();
        };
        state.dispose = () => {
            feedbackObserver.disconnect();
            window.removeEventListener('resize',resize);
            dialog.removeEventListener('click',interceptClose,true);
            dialog.removeEventListener('cancel',cancel);
            clear();
            live.delete(state);
        };
        dialog.addEventListener('close',state.dispose,{once:true});
        live.add(state);
        if (enabled()) {
            gsap.fromTo(dialog,{opacity:0,y:12,scale:0.985},{opacity:1,y:0,scale:1,duration:0.24,ease:'power2.out',clearProps:'transform,opacity'});
        }
    };
    preference.addEventListener('change',() => {
        if (preference.matches) [...live].forEach(state => state.settle());
    });
    const discover = node => {
        if (!(node instanceof Element)) return;
        if (node.matches('.xh-settings')) attach(node);
        node.querySelectorAll('.xh-settings').forEach(attach);
    };
    new MutationObserver(records => {
        records.forEach(record => {
            if (record.type === 'attributes') discover(record.target);
            else record.addedNodes.forEach(discover);
        });
        // A removed dialog may not dispatch close, for example during navigation.
        [...live].filter(state => !state.dialog.isConnected).forEach(state => state.dispose());
    }).observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['open']});
    document.querySelectorAll('.xh-settings').forEach(attach);
})();
