const labels = {
    party: 'Party dots',
    spotlight: 'Projecteur',
    frames: 'Mur de cadres',
    curtain: 'Rideau pop',
    arcade: 'Grille arcade',
};

const previewFrame = document.querySelector('#preview-frame');
const variantName = document.querySelector('#variant-name');
const openLink = document.querySelector('#open-link');

document.querySelectorAll('[data-bg]').forEach((button) => {
    button.addEventListener('click', () => {
        const background = button.dataset.bg;

        document.querySelectorAll('[data-bg]').forEach((candidate) => {
            candidate.classList.toggle(
                'background-option--active',
                candidate === button,
            );
        });

        previewFrame.src = `index.html?bg=${background}`;
        previewFrame.title = `Aperçu de la variante ${labels[background]}`;
        variantName.textContent = labels[background];
        openLink.href = `index.html?bg=${background}`;
    });
});
