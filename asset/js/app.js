(() => {
    const navigate = (target) => {
        if (!target || target.classList.contains('disabled')) {
            return;
        }

        const href = target.getAttribute('data-nav');
        if (href) {
            window.location.href = href;
        }
    };

    document.addEventListener('click', (event) => {
        const target = event.target.closest('[data-nav]');
        if (!target) {
            return;
        }

        navigate(target);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }

        const target = event.target.closest('[data-nav]');
        if (!target) {
            return;
        }

        event.preventDefault();
        navigate(target);
    });
})();
