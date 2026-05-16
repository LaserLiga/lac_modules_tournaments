import mermaid from 'mermaid';

window.addEventListener('load', () => {
    initTournamentBracket();
});

window.addEventListener('tournament:bracket-updated', () => {
    initTournamentBracket();
});

function initTournamentBracket(): void {
    const diagrams = document.querySelectorAll<HTMLElement>('.tournament-mermaid');
    if (diagrams.length === 0) {
        return;
    }

    mermaid.initialize({
        startOnLoad: false,
        securityLevel: 'strict',
        theme: 'base',
    });

    mermaid.run({
        nodes: diagrams,
    });
}
