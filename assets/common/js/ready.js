window._data_var = {};

function documentReady(callback) {
    if (document.readyState === "complete" || document.readyState === "interactive") {
        callback();
    } else {
        document.addEventListener("DOMContentLoaded", callback, false);
    }
}

function ready(call) {
    documentReady(() => {
        layui.use('form', function () {
            if (typeof call === "function") {
                call();
                return;
            }
            if (!call) return;

            document.querySelectorAll('script[ready]').forEach(s => s.remove());

            const s = document.createElement('script');
            s.src = call;
            s.async = true;
            s.setAttribute('ready', 'true');
            document.body.appendChild(s);

            util.debug(`RELOAD -> ${call}`, "#10d18f");
        });
    });
}

function setVar(name, data) {
    window._data_var[name] = data;
}

function getVar(name) {
    return window._data_var[name];
}

function i18n(text) {
    return text;
}

function evalResults(code) {
    return eval('(' + code + ')');
}

function route(uri) {
    uri = uri.replace(/^\/+|\/+$/g, '');
    const pathname = location.pathname;
    const rt = pathname.trim().split("/").filter(Boolean);
    if (rt[0] !== "plugin") {
        return "";
    }

    if (rt[1] === undefined) {
        return "";
    }

    if (!/^\d+$/.test(rt[1])) {
        //主站

        return `/plugin/${rt[1]}/${uri}`;
    } else {
        //分站
        if (rt[2] === undefined) {
            return "";
        }
        return `/plugin/${rt[1]}/${rt[2]}/${uri}`;
    }
}

// ContentCenter owns the files below; this small bridge makes its public blog
// navigation available even when the framework's compiled plugin hook cache is
// stale after a code-only deployment.
(function preloadContentCenterBlogStyle() {
    const isBlogRoute = location.pathname === '/blog'
        || location.pathname.indexOf('/blog/') === 0
        || location.pathname.indexOf('/plugin/ContentCenter/index/blog') === 0
        || location.pathname.indexOf('/plugin/ContentCenter/index/article') === 0;
    if (!isBlogRoute) return;
    if (document.querySelector('link[data-content-center-blog]')) return;
    const style = document.createElement('link');
    style.rel = 'stylesheet';
    style.href = '/app/Plugin/ContentCenter/Assets/content-center-blog.css?v=20260722-1';
    style.setAttribute('data-content-center-blog', '1');
    document.head.appendChild(style);
}());

documentReady(() => {
    if (!document.body || !document.body.classList.contains('xh-page')) return;

    const blogPage = document.querySelector('[data-cc-blog]');

    if (!document.querySelector('script[data-content-center-nav]')) {
        const navigation = document.createElement('script');
        navigation.src = '/app/Plugin/ContentCenter/Assets/content-center-nav.js?v=20260721-1';
        navigation.setAttribute('data-content-center-nav', '1');
        document.body.appendChild(navigation);
    }

    if (!blogPage || document.querySelector('script[data-content-center-page]')) return;

    const pageScript = document.createElement('script');
    pageScript.src = '/app/Plugin/ContentCenter/Assets/content-center-blog.js?v=20260722-1';
    pageScript.setAttribute('data-content-center-page', '1');

    if (window.gsap) {
        document.body.appendChild(pageScript);
        return;
    }

    const gsapScript = document.createElement('script');
    gsapScript.src = '/app/Plugin/ContentCenter/Assets/vendor/gsap.min.js?v=3.13.0';
    gsapScript.onload = () => document.body.appendChild(pageScript);
    gsapScript.onerror = () => document.body.appendChild(pageScript);
    document.body.appendChild(gsapScript);
});
