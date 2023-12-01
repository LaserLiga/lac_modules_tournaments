import {initUserAutocomplete} from "../../../../assets/js/components/userPlayerSearch";

window.addEventListener('load', () => {
    const teamsWrapper = document.getElementById('teams') as HTMLDivElement;
    const addTeamBtn = document.getElementById('addTeam') as HTMLButtonElement;
    const teamTemplate = document.getElementById('team-card-template') as HTMLTemplateElement;

    (teamsWrapper.querySelectorAll('.player') as NodeListOf<HTMLDivElement>).forEach(initPlayerInputs)

    let teamCounter = 0;

    addTeamBtn.addEventListener('click', () => {
        const wrapper = document.createElement('div');
        wrapper.innerHTML = teamTemplate.innerHTML.replaceAll('#', `n${teamCounter++}`);
        const newTeam = wrapper.firstElementChild as HTMLDivElement;
        teamsWrapper.appendChild(newTeam);
        (newTeam.querySelectorAll('.player') as NodeListOf<HTMLDivElement>).forEach(initPlayerInputs)
    });
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