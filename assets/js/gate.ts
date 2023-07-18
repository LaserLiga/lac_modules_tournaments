import EventServerInstance from "../../../../assets/js/EventServer";
import {loadContent, tipsRotations} from "../../../../assets/js/components/gate";

export let reloadTimeout: { timeout: null | NodeJS.Timeout } = {timeout: null};
window.addEventListener('load', () => {

	if (reloadTimer && reloadTimer > 0) {
		reloadTimeout.timeout = setTimeout(() => {
			loadContent(window.location.pathname, reloadTimeout);
		}, reloadTimer * 1000);
	}

	// WebSocket event listener
	EventServerInstance.addEventListener(['game-imported', 'gate-reload'], () => {
		loadContent(window.location.pathname, reloadTimeout);
	});

	tipsRotations();
});