import {initUserAutocomplete} from "../../../../assets/js/components/userPlayerSearch";

window.addEventListener('load', () => {
    const teamsWrapper = document.getElementById('players') as HTMLDivElement;
    const teamTemplate = document.getElementById('player-card-template') as HTMLTemplateElement;

    (teamsWrapper.querySelectorAll('.player') as NodeListOf<HTMLDivElement>).forEach(initPlayerInputs)

    let playerCounter = 0;

    addPlayer();

    function addPlayer() {
        const wrapper = document.createElement('div');
        wrapper.innerHTML = teamTemplate.innerHTML.replaceAll('#', `n${playerCounter}`);
        const newPlayer = wrapper.firstElementChild as HTMLDivElement;
        teamsWrapper.appendChild(newPlayer);
        (newPlayer.querySelectorAll('.player') as NodeListOf<HTMLDivElement>).forEach(player => {
            initPlayerInputs(player, playerCounter);
        })
        playerCounter++;
    }

    function initPlayerInputs(wrapper: HTMLDivElement, playerId: number) {
        const codeInput: HTMLInputElement = wrapper.querySelector('.player-code');
        const nicknameInput: HTMLInputElement = wrapper.querySelector('.player-nickname');

        nicknameInput.addEventListener('input', () => {
            codeInput.value = '';
            nicknameInput.classList.remove('fw-bold');
            if (nicknameInput.value.trim() !== '' && playerId === playerCounter - 1) {
                addPlayer();
            }
        });

        initUserAutocomplete(nicknameInput, (name, code) => {
            nicknameInput.value = name;
            codeInput.value = code;
            nicknameInput.classList.add('fw-bold');
        });
    }
});