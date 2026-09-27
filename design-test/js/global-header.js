(() => {
    const params = new URLSearchParams(window.location.search);
    const explicitlyConnected = document.body.dataset.authenticated === 'true';
    document.body.dataset.authenticated = explicitlyConnected || params.get('connected') === '1' ? 'true' : 'false';
})();
