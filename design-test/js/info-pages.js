(() => {
    const params = new URLSearchParams(window.location.search);
    document.body.dataset.authenticated = params.get('connected') === '1' ? 'true' : 'false';

    const links = [...document.querySelectorAll('[data-section-link]')];
    const sections = links
        .map((link) => document.querySelector(link.getAttribute('href')))
        .filter(Boolean);

    if (!links.length || !('IntersectionObserver' in window)) return;

    const observer = new IntersectionObserver(
        (entries) => {
            const visible = entries
                .filter((entry) => entry.isIntersecting)
                .sort((left, right) => right.intersectionRatio - left.intersectionRatio)[0];

            if (!visible) return;

            links.forEach((link) => {
                const active = link.getAttribute('href') === `#${visible.target.id}`;
                if (active) link.setAttribute('aria-current', 'true');
                else link.removeAttribute('aria-current');
            });
        },
        { rootMargin: '-15% 0px -65% 0px', threshold: [0, 0.25, 0.75] },
    );

    sections.forEach((section) => observer.observe(section));
})();
