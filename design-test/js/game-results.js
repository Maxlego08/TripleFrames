const params = new URLSearchParams(window.location.search);
const requestedName = params.get('name')?.trim().slice(0, 24);
const currentPlayerName = requestedName || 'Marty';

const players = [
    { name: 'Sofia', score: 1350, avatar: 'pink' },
    { name: 'Théo', score: 1280, avatar: 'yellow' },
    { name: 'Inès', score: 1140, avatar: 'cyan' },
    { name: 'Lucas', score: 980, avatar: 'green' },
    { name: 'Jade', score: 920, avatar: 'lavender' },
    { name: currentPlayerName, score: 860, avatar: 'coral', isCurrent: true },
    { name: 'Nora', score: 810, avatar: 'coral' },
    { name: 'Sam', score: 720, avatar: 'cyan' },
    { name: 'Léo', score: 640, avatar: 'yellow' },
    { name: 'Maya', score: 590, avatar: 'pink' },
    { name: 'Noé', score: 470, avatar: 'green' },
    { name: 'Lou', score: 390, avatar: 'lavender' },
];

const podium = document.querySelector('#podium');
const rankingList = document.querySelector('#ranking-list');
const currentResult = document.querySelector('#current-result');

function formatScore(score) {
    return new Intl.NumberFormat('fr-FR').format(score);
}

function createAvatar(variant) {
    const avatar = document.createElement('span');
    avatar.className = `player-avatar player-avatar--${variant}`;
    avatar.setAttribute('aria-hidden', 'true');
    avatar.innerHTML = '<span class="player-avatar__mouth"></span>';
    return avatar;
}

function createPodiumPlayer(player, position) {
    const labels = { 1: '1ère', 2: '2ème', 3: '3ème' };
    const modifiers = { 1: 'first', 2: 'second', 3: 'third' };
    const card = document.createElement('article');
    card.className = `podium__player podium__player--${modifiers[position]}`;
    card.setAttribute('aria-label', `${labels[position]} place, ${player.name}, ${formatScore(player.score)} points`);

    const identity = document.createElement('div');
    identity.className = 'podium__identity';

    if (position === 1) {
        const crown = document.createElement('img');
        crown.className = 'podium__crown';
        crown.src = '../svg/icons/crown.svg';
        crown.alt = '';
        crown.setAttribute('aria-hidden', 'true');
        identity.appendChild(crown);
    }

    const rank = document.createElement('span');
    rank.className = 'podium__rank';
    rank.textContent = labels[position];
    identity.append(rank, createAvatar(player.avatar));

    const name = document.createElement('strong');
    name.className = 'podium__name';
    name.textContent = player.name;
    identity.appendChild(name);

    const step = document.createElement('div');
    step.className = 'podium__step';
    step.innerHTML = `
        <strong class="podium__score">${formatScore(player.score)}</strong>
        <span class="podium__unit">points</span>
    `;

    card.append(identity, step);
    return card;
}

function renderPodium() {
    const podiumOrder = [
        { player: players[1], position: 2 },
        { player: players[0], position: 1 },
        { player: players[2], position: 3 },
    ];

    podium.replaceChildren(...podiumOrder.map(({ player, position }) => createPodiumPlayer(player, position)));
}

function createRankingItem(player, index) {
    const position = index + 1;
    const item = document.createElement('li');
    item.className = `ranking__item${player.isCurrent ? ' ranking__item--current' : ''}`;
    item.setAttribute('aria-label', `${position}e place, ${player.name}, ${formatScore(player.score)} points${player.isCurrent ? ', votre résultat' : ''}`);

    const positionLabel = document.createElement('span');
    positionLabel.className = 'ranking__position';
    positionLabel.textContent = String(position);

    const identity = document.createElement('div');
    identity.className = 'ranking__identity';
    const name = document.createElement('strong');
    name.className = 'ranking__name';
    name.textContent = player.name;
    identity.appendChild(name);

    if (player.isCurrent) {
        const you = document.createElement('span');
        you.className = 'ranking__you';
        you.textContent = 'TOI';
        identity.appendChild(you);
    }

    const score = document.createElement('strong');
    score.className = 'ranking__score';
    score.textContent = `${formatScore(player.score)} pts`;

    item.append(positionLabel, createAvatar(player.avatar), identity, score);
    return item;
}

function renderRanking() {
    rankingList.replaceChildren(...players.slice(3).map((player, index) => createRankingItem(player, index + 3)));
}

function renderCurrentResult() {
    const currentIndex = players.findIndex((player) => player.isCurrent);
    const currentPlayer = players[currentIndex];

    currentResult.innerHTML = `
        <strong class="current-result__position">${currentIndex + 1}</strong>
        <span class="current-result__copy">
            <small>Ton classement</small>
            <strong>${currentPlayer.name}</strong>
        </span>
        <strong class="current-result__score">${formatScore(currentPlayer.score)} pts</strong>
    `;
}

document.querySelectorAll('[data-replay-game]').forEach((button) => {
    button.addEventListener('click', () => {
        window.location.href = `waiting-room.html?host=1&name=${encodeURIComponent(currentPlayerName)}`;
    });
});

renderPodium();
renderRanking();
renderCurrentResult();
