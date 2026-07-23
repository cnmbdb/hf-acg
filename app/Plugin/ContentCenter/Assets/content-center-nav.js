!function () {
    function createLink(className, mobile) {
        var link = document.createElement('a');
        link.className = className;
        link.href = '/blog';
        link.setAttribute('data-content-center-blog-link', '1');
        link.innerHTML = '<i class="fa-duotone fa-regular fa-newspaper"></i><span>使用教程</span>';
        if (mobile) link.classList.add('xh-mobile-link');
        return link;
    }

    function mount() {
        var active = location.pathname === '/blog'
            || location.pathname.indexOf('/blog/') === 0
            || location.pathname.indexOf('/plugin/ContentCenter/index/blog') === 0
            || location.pathname.indexOf('/plugin/ContentCenter/index/article') === 0;
        var desktop = document.querySelector('.xh-navlinks');
        if (desktop && !desktop.querySelector('[data-content-center-blog-link]')) {
            var desktopLink = createLink('xh-navlink' + (active ? ' is-active' : ''), false);
            var tools = desktop.querySelector('.xh-tools');
            desktop.insertBefore(desktopLink, tools || desktop.lastElementChild);
        }

        var mobilePanel = document.querySelector('.xh-mobile-menu-panel');
        if (mobilePanel && !mobilePanel.querySelector('[data-content-center-blog-link]')) {
            var mobileLink = createLink('xh-mobile-link' + (active ? ' is-active' : ''), true);
            var mobileTools = mobilePanel.querySelector('.xh-mobile-tools');
            mobilePanel.insertBefore(mobileLink, mobileTools || mobilePanel.querySelector('.xh-mobile-auth'));
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
    else mount();
    document.addEventListener('pjax:complete', mount);
}(window);
