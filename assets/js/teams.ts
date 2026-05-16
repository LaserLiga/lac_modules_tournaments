import {initUserAutocomplete} from "../../../../assets/js/components/userPlayerSearch";

window.addEventListener('load', () => {
    const teamsWrapper = document.getElementById('teams') as HTMLDivElement;
    const addTeamBtn = document.getElementById('addTeam') as HTMLButtonElement;
    const teamTemplate = document.getElementById('team-card-template') as HTMLTemplateElement;
    const playerTemplate = document.getElementById('team-player-template') as HTMLTemplateElement;

    (teamsWrapper.querySelectorAll('.player') as NodeListOf<HTMLDivElement>).forEach(initPlayerInputs)
    teamsWrapper.querySelectorAll<HTMLButtonElement>('.add-player').forEach(button => initAddPlayerButton(button, playerTemplate));

    let teamCounter = 0;
    let playerCounter = 0;

    addTeamBtn.addEventListener('click', () => {
        const teamKey = `n${teamCounter++}`;
        const wrapper = document.createElement('div');
        wrapper.innerHTML = teamTemplate.innerHTML.replaceAll('#', teamKey);
        const newTeam = wrapper.firstElementChild as HTMLDivElement;
        teamsWrapper.appendChild(newTeam);
        (newTeam.querySelectorAll('.player') as NodeListOf<HTMLDivElement>).forEach(initPlayerInputs)
        newTeam.querySelectorAll<HTMLButtonElement>('.add-player').forEach(button => {
            button.dataset.team = teamKey;
            initAddPlayerButton(button, playerTemplate);
        });
    });

    function initAddPlayerButton(button: HTMLButtonElement, template: HTMLTemplateElement): void {
        button.addEventListener('click', () => {
            const team = button.dataset.team;
            const playersWrapper = button.closest('.team')?.querySelector('.team-players') as HTMLDivElement | null;
            if (!team || !playersWrapper) {
                return;
            }

            const playerKey = `np${playerCounter++}`;
            const wrapper = document.createElement('div');
            wrapper.innerHTML = template.innerHTML
                .replaceAll('#team#', team)
                .replaceAll('#player#', playerKey);
            const newPlayer = wrapper.firstElementChild as HTMLDivElement;
            playersWrapper.appendChild(newPlayer);
            initPlayerInputs(newPlayer);
        });
    }
});

function initPlayerInputs(wrapper: HTMLDivElement) {
    const codeInput: HTMLInputElement = wrapper.querySelector('.player-code');
    const nicknameInput: HTMLInputElement = wrapper.querySelector('.player-nickname');

    nicknameInput.addEventListener('input', () => {
        codeInput.value = '';
        nicknameInput.classList.remove('fw-bold');
    });

    initUserAutocomplete(nicknameInput, (name, code) => {
        nicknameInput.value = name;
        codeInput.value = code;
        nicknameInput.classList.add('fw-bold');
    });
}
