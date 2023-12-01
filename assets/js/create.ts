import initDatePickers from "../../../../assets/js/datePickers";

window.addEventListener('load', () => {
    initDatePickers();

    const teamOnlyControls: NodeListOf<HTMLElement> = document.querySelectorAll('.team-only');
    const formatInputs: NodeListOf<HTMLInputElement> = document.querySelectorAll('input[type="radio"][name="format"]');
    formatInputs.forEach(input => {
        input.addEventListener('change', () => {
            toggleTeamOnlyControls();
        });
    });

    function toggleTeamOnlyControls() {
        let isTeam: boolean = false;
        formatInputs.forEach(input => {
            if (input.checked) {
                isTeam = input.value === 'TEAM';
            }
        });

        teamOnlyControls.forEach(control => {
            if (isTeam) {
                control.classList.remove('d-none');
                return;
            }
            control.classList.add('d-none');
        });
    }
});