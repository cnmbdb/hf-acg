!function () {
    var root = document.querySelector('[data-cc-blog]');
    if (!root) return;

    var gsap = window.gsap || null;

    if (window.ContentCenterBlog && typeof window.ContentCenterBlog.destroy === 'function') {
        window.ContentCenterBlog.destroy();
    }

    var slides = Array.prototype.slice.call(root.querySelectorAll('[data-hero-slide]'));
    var dots = Array.prototype.slice.call(root.querySelectorAll('[data-hero-dot]'));
    var cards = Array.prototype.slice.call(root.querySelectorAll('[data-article-card]'));
    var categoryButtons = Array.prototype.slice.call(root.querySelectorAll('[data-category]'));
    var tickerTrack = root.querySelector('[data-ticker-track]');
    var tickerViewport = root.querySelector('[data-ticker-viewport]');
    var countNode = root.querySelector('[data-visible-count]');
    var enabled = root.getAttribute('data-animation-enabled') !== '0';
    var current = Math.max(0, slides.findIndex(function (slide) { return slide.classList.contains('is-active'); }));
    var timer = 0;
    var tickerTween = null;
    var introTimeline = null;
    var media = gsap && typeof gsap.matchMedia === 'function' ? gsap.matchMedia() : null;
    var reduceMotion = false;

    function updateSlide(index, direction) {
        if (!slides.length || index === current) return;
        var previous = slides[current];
        var next = slides[index];
        dots.forEach(function (dot, dotIndex) { dot.classList.toggle('is-active', dotIndex === index); });

        if (enabled && !reduceMotion && gsap) {
            gsap.killTweensOf([previous, next]);
            next.classList.add('is-active');
            next.setAttribute('aria-hidden', 'false');
            gsap.set(next, {autoAlpha: 1, xPercent: direction > 0 ? 4 : -4, scale: 1.025});
            gsap.timeline({defaults: {duration: .72, ease: 'power3.inOut'}, onComplete: function () {
                previous.classList.remove('is-active');
                previous.setAttribute('aria-hidden', 'true');
                gsap.set(previous, {clearProps: 'all'});
            }})
                .to(previous, {autoAlpha: 0, xPercent: direction > 0 ? -3 : 3, scale: 1.01}, 0)
                .to(next, {autoAlpha: 1, xPercent: 0, scale: 1}, 0);
        } else {
            slides.forEach(function (slide, slideIndex) {
                slide.classList.toggle('is-active', slideIndex === index);
                slide.setAttribute('aria-hidden', slideIndex === index ? 'false' : 'true');
            });
        }
        current = index;
    }

    function nextSlide(direction) {
        if (slides.length < 2) return;
        var nextIndex = (current + direction + slides.length) % slides.length;
        updateSlide(nextIndex, direction);
    }

    function restartTimer() {
        window.clearInterval(timer);
        if (slides.length < 2 || reduceMotion) return;
        var interval = Math.max(3000, Number(root.getAttribute('data-hero-interval')) || 6500);
        timer = window.setInterval(function () { nextSlide(1); }, interval);
    }

    function cardMatches(card, category) {
        if (category === 'all') return true;
        return Array.prototype.some.call(card.querySelectorAll('[data-article-tag]'), function (tag) {
            return tag.textContent.trim() === category;
        });
    }

    function filterArticles(category) {
        var visible = cards.filter(function (card) { return cardMatches(card, category); });
        categoryButtons.forEach(function (button) {
            var selected = button.getAttribute('data-category') === category;
            button.classList.toggle('is-active', selected);
            button.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });

        if (!enabled || reduceMotion || !gsap) {
            cards.forEach(function (card) { card.hidden = !cardMatches(card, category); });
        } else {
            gsap.to(cards, {
                autoAlpha: 0,
                y: -8,
                duration: .18,
                stagger: .018,
                overwrite: 'auto',
                onComplete: function () {
                    cards.forEach(function (card) { card.hidden = !cardMatches(card, category); });
                    gsap.fromTo(visible, {autoAlpha: 0, y: 18}, {autoAlpha: 1, y: 0, duration: .44, stagger: .07, ease: 'power2.out', overwrite: 'auto'});
                }
            });
        }
        if (countNode) countNode.textContent = String(visible.length);
    }

    function startTicker() {
        if (!gsap || !tickerTrack || !tickerViewport || reduceMotion || !enabled) return;
        tickerTween = gsap.fromTo(tickerTrack, {xPercent: 0}, {
            xPercent: -50,
            duration: Math.max(12, Number(root.getAttribute('data-ticker-duration')) || 28),
            ease: 'none',
            repeat: -1
        });
        tickerViewport.addEventListener('mouseenter', pauseTicker);
        tickerViewport.addEventListener('mouseleave', resumeTicker);
    }

    function pauseTicker() { if (tickerTween) tickerTween.pause(); }
    function resumeTicker() { if (tickerTween) tickerTween.play(); }

    function intro() {
        if (!gsap || !enabled || reduceMotion) return;
        introTimeline = gsap.timeline({defaults: {ease: 'power3.out'}});
        introTimeline
            .from('.cc-blog-intro > *', {autoAlpha: 0, y: 24, duration: .68, stagger: .09}, 0)
            .from('.cc-hero', {autoAlpha: 0, y: 28, duration: .78}, '-=.42')
            .from('.cc-ticker, .cc-categories', {autoAlpha: 0, y: 22, duration: .62, stagger: .12}, '-=.32')
            .from(cards, {autoAlpha: 0, y: 22, duration: .55, stagger: .08}, '-=.25');
    }

    var prev = root.querySelector('[data-hero-prev]');
    var next = root.querySelector('[data-hero-next]');
    if (prev) prev.addEventListener('click', function () { nextSlide(-1); restartTimer(); });
    if (next) next.addEventListener('click', function () { nextSlide(1); restartTimer(); });
    dots.forEach(function (dot) {
        dot.addEventListener('click', function () {
            var index = Number(dot.getAttribute('data-hero-dot')) || 0;
            updateSlide(index, index >= current ? 1 : -1);
            restartTimer();
        });
    });
    categoryButtons.forEach(function (button) {
        button.addEventListener('click', function () { filterArticles(button.getAttribute('data-category') || 'all'); });
    });

    if (media) {
        media.add({all: '(min-width: 0px)', reduce: '(prefers-reduced-motion: reduce)'}, function (context) {
            reduceMotion = context.conditions.reduce;
            restartTimer();
            if (tickerTween) tickerTween.kill();
            tickerTween = null;
            startTicker();
            intro();
        });
    } else {
        reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        restartTimer();
    }

    window.ContentCenterBlog = {
        destroy: function () {
            window.clearInterval(timer);
            if (tickerTween) tickerTween.kill();
            if (introTimeline) introTimeline.kill();
            if (media) media.revert();
            if (tickerViewport) {
                tickerViewport.removeEventListener('mouseenter', pauseTicker);
                tickerViewport.removeEventListener('mouseleave', resumeTicker);
            }
            if (gsap) gsap.killTweensOf(slides.concat(cards));
        }
    };
}(window);
