import Player from './modules/Player';
import {startLoading, stopLoading} from '../../../../assets/js/loaders';
import EventServerInstance from '../../../../assets/js/EventServer';
import Control from '../../../../assets/js/pages/newGame/control';
import {isFeatureEnabled} from '../../../../assets/js/featureConfig';
import {shuffle} from '../../../../assets/js/includes/functions';
import {fetchGet, fetchPost} from '../../../../assets/js/includes/apiClient';
import {triggerNotificationError} from '../../../../assets/js/includes/notifications';

declare global {
	const vests: {
		id: number,
		vestNum: string,
	}[];
}

window.addEventListener('load', () => {
	const form = document.getElementById('tournament-play-form') as HTMLFormElement;
	initContent(form);

	EventServerInstance.addEventListener('game-imported', updateContent);

	function updateContent() {
		fetchGet(form.dataset.results)
			.then((response: { status: string, view?: string }) => {
				if (response.view) {
					form.innerHTML = response.view;
					initContent(form);
				}
			});
	}
});

function initContent(form: HTMLFormElement) {
	let selectedVests = new Set<string>;
	const progressTeams = document.getElementById('progressTeams') as HTMLButtonElement;
	progressTeams.addEventListener('click', () => {
		startLoading();
		fetchPost(progressTeams.dataset.action, {})
			.then((response: { progressed: number }) => {
				stopLoading(true);
				alert(progressTeams.dataset.alert + ' ' + response.progressed);
			})
			.catch(e => {
				triggerNotificationError(e);
				stopLoading(false);
			});
	});

	const results = document.getElementById('results') as HTMLDivElement | undefined;
	if (results) {
		const resetBtn = document.getElementById('reset-game') as HTMLButtonElement;
		resetBtn.addEventListener('click', () => {
			if (!confirm(resetBtn.dataset.confirm)) {
				return;
			}
			startLoading();
			fetchPost(resetBtn.dataset.action, {})
				.then(() => {
					window.location.reload();
				})
				.catch(e => {
					stopLoading(false);
					triggerNotificationError(e);
				});
		});

		const bonusInputs = results.querySelectorAll('.team-bonus') as NodeListOf<HTMLInputElement>;
		const updateBonusBtn = results.querySelector('#updateBonus') as HTMLButtonElement;
		const updateBonusAction = updateBonusBtn.dataset.action;

		bonusInputs.forEach(input => {
			input.addEventListener('input', () => {
				const value = parseInt(input.value);
				if (isNaN(value)) {
					input.classList.remove('text-danger', 'text-success');
				} else if (value > 0) {
					input.classList.remove('text-danger');
					input.classList.add('text-success');
				} else {
					input.classList.add('text-danger');
					input.classList.remove('text-success');
				}
			});
		});

		updateBonusBtn.addEventListener('click', e => {
			e.preventDefault();
			const bonus: { [index: number]: number } = {};
			bonusInputs.forEach(input => {
				if (input.value === '') {
					input.value = '0';
				}
				const value = parseInt(input.value);
				const id = parseInt(input.dataset.team);
				bonus[id] = value;
			});

			startLoading();
			fetchPost(updateBonusAction, {bonus})
				.then(() => {
					window.location.reload();
				})
				.catch(e => {
					stopLoading(false);
					triggerNotificationError(e);
				});
		});

		return;
	}

	const players: Map<number, Player> = new Map();

	const loadBtn = document.getElementById('load') as HTMLButtonElement;
	const startBtn = document.getElementById('start') as HTMLButtonElement;
	const stopBtn = document.getElementById('stop') as HTMLButtonElement;

	let control: Control | null = null;
	if (isFeatureEnabled('control')) {
		control = new Control(loadBtn, startBtn, stopBtn);
	}

	// Send form via ajax
	form.onsubmit = e => {
		e.preventDefault();

		const data = new FormData(form);

		console.log(e.submitter);

		if (!data.get('action')) {
			data.set('action', (e.submitter as HTMLButtonElement).value);
		}

		switch (data.get('action')) {
			case 'load':
				loadGame(data);
				break;
			case 'start':
				if (control) {
					control.startGame(data, loadStartGame);
				}
				break;
			case 'stop':
				if (control) {
					control.stopGame();
				}
				break;
		}
	};

	const checkedVestsRaw: string | null = window.localStorage.getItem('tournamentCheckedVests');
	let checkedVests = new Set<string>;
	for (const vest of (checkedVestsRaw ?? '').split(',')) {
		if (vest === '') {
			continue;
		}
		checkedVests.add(vest);
	}
	const allVestsCheck = document.getElementById('all-vests') as HTMLInputElement;
	const availableVestChecks = document.querySelectorAll<HTMLInputElement>('.available-vests');
	const redistributeVestsBtn = document.getElementById('redistribute-vests') as HTMLButtonElement;
	availableVestChecks.forEach(input => {
		const vestNum = input.value;

		if (vestNum === '') {
			return;
		}

		input.checked = checkedVestsRaw !== null && checkedVests.has(vestNum);

		const addCheckedVest = () => {
			if (input.checked) {
				checkedVests.add(vestNum);
			} else {
				checkedVests.delete(vestNum);
			}
			allVestsCheck.checked = availableVestChecks.length === checkedVests.size;
			window.localStorage.setItem('tournamentCheckedVests', Array.from(checkedVests.values()).join(','));
		};

		addCheckedVest();

		input.addEventListener('change', () => {
			addCheckedVest();
			updateAvailableVests();
		});
	});
	console.log(allVestsCheck, availableVestChecks, checkedVests)
	// allVestsCheck.checked = availableVestChecks.length === checkedVests.size;
	allVestsCheck.addEventListener('change', () => {
		for (const check of availableVestChecks) {
			const vestNum = check.value;
			check.checked = allVestsCheck.checked;

			if (check.checked) {
				checkedVests.add(vestNum);
			} else {
				checkedVests.delete(vestNum);
			}
		}
		window.localStorage.setItem('tournamentCheckedVests', Array.from(checkedVests.values()).join(','));
		updateAvailableVests();
	});
	redistributeVestsBtn.addEventListener('click', () => {
		distributeVests();
	});

	const playersDom = document.querySelectorAll('.player') as NodeListOf<HTMLDivElement>;
	playersDom.forEach(dom => {
		const id = parseInt(dom.dataset.id);
		const player = new Player(id, dom);
		player.vestSelect.addEventListener('change', updateAvailableVests);
		players.set(id, player);
	});

	const musicMode = document.getElementById('music-select') as HTMLSelectElement;
	const $groupMusicModes = document.getElementById('music-mode-grouped') as HTMLInputElement;
	const $groupMusicModesLabel = document.querySelector('label[for="music-mode-grouped"]');
	const playlist = document.getElementById('playlist-select') as HTMLSelectElement;
	const usePlaylist = document.getElementById('playlists') as HTMLInputElement;

	usePlaylist.checked = window.localStorage.getItem('use-playlist') === '1';
	if (usePlaylist.checked) {
		playlist.classList.remove('d-none');
		musicMode.classList.add('d-none');
		$groupMusicModesLabel.classList.add('d-none');
	} else {
		playlist.classList.add('d-none');
		musicMode.classList.remove('d-none');
		$groupMusicModesLabel.classList.remove('d-none');
	}
	$groupMusicModes.checked = window.localStorage.getItem('group-music-mode') === '1';
	if ($groupMusicModes.checked) {
		groupMusicModes();
	} else {
		unGroupMusicModes();
	}

	$groupMusicModes.addEventListener('change', () => {
		window.localStorage.setItem('group-music-mode', $groupMusicModes.checked ? '1' : '0');
		if ($groupMusicModes.checked) {
			groupMusicModes();
		} else {
			unGroupMusicModes();
		}
	});
	playlist.addEventListener('change', () => {
		playlist.dispatchEvent(
			new Event('update', {
				bubbles: true,
			}),
		);
	});
	usePlaylist.addEventListener('change', () => {
		window.localStorage.setItem('use-playlist', usePlaylist.checked ? '1' : '0');
		if (usePlaylist.checked) {
			playlist.classList.remove('d-none');
			musicMode.classList.add('d-none');
			$groupMusicModesLabel.classList.add('d-none');
		} else {
			playlist.classList.add('d-none');
			musicMode.classList.remove('d-none');
			$groupMusicModesLabel.classList.remove('d-none');
		}
	});

	distributeVests();
	updateAvailableVests();

	function groupMusicModes(): void {
		const value = musicMode.value;
		musicMode.querySelectorAll('optgroup').forEach(group => {
			const name = group.label;
			const musicModes: { [key: string]: string } = {};
			let selected = false;
			const newOption = document.createElement('option');
			group.querySelectorAll('option').forEach(option => {
				musicModes[option.value] = option.innerText;
				selected = selected || option.value === value;
				newOption.setAttribute('data-m' + option.value, option.value);
			});
			newOption.value = 'g-' + Object.keys(musicModes).join('-');
			newOption.classList.add('music-group');
			newOption.setAttribute('data-music', JSON.stringify(musicModes));
			newOption.innerText = name;
			group.replaceWith(newOption);
			if (selected) {
				musicMode.value = newOption.value;
			}
		});
	}

	function unGroupMusicModes(): void {
		const value = musicMode.value;
		musicMode.querySelectorAll('.music-group').forEach((groupOption: HTMLOptionElement) => {
			const selected = groupOption.value === value;
			const groupName = groupOption.innerText;
			const musicModes: { [key: string]: string } = JSON.parse(groupOption.getAttribute('data-music'));
			const newOptgroup = document.createElement('optgroup');
			newOptgroup.label = groupName;
			Object.entries(musicModes).forEach(([id, name]) => {
				const option = document.createElement('option');
				option.value = id;
				option.innerText = name;
				newOptgroup.appendChild(option);
			});
			groupOption.replaceWith(newOptgroup);
			if (selected) {
				const ids = Object.keys(musicModes);
				musicMode.value = ids[Math.floor(Math.random() * ids.length)];
			}
		});
	}

	function getAvailableVestsForPlayer(player: Player) {
		if (selectedVests.size === 0) {
			players.forEach(playerCheck => {
				const value = playerCheck.vestSelect.value;
				if (value !== '') {
					selectedVests.add(value);
				}
			});
		}

		const availableVests: string[] = [];
		vests.forEach(vest => {
			if (!selectedVests.has(vest.vestNum) && checkedVests.has(vest.vestNum)) {
				availableVests.push(vest.vestNum);
			}
		});

		if (player.vestSelect.value !== '') {
			availableVests.push(player.vestSelect.value);
		}

		return availableVests;
	}

	function distributeVests() {
		// Unset all selected vests
		players.forEach(player => {
			player.vestSelect.value = '';
		});

		updateAvailableVests();

		const availableVestsWithCount: { [index: number]: [string, number][] } = {};

		players.forEach((player, key) => {
			if (player.sub) {
				return;
			}
			availableVestsWithCount[key] = [];
			const availableVests: string[] = shuffle(getAvailableVestsForPlayer(player));
			for (const availableVest of availableVests) {
				const gamesWithVest = player.vests[availableVest] ?? 0;

				if (gamesWithVest === 0) {
					player.vestSelect.value = availableVest;
					selectedVests.add(availableVest);
					return;
				}

				availableVestsWithCount[key].push([availableVest, gamesWithVest]);
			}
		});

		players.forEach((player, key) => {
			if (player.sub || player.vestSelect.value !== '') {
				return;
			}
			availableVestsWithCount[key] = availableVestsWithCount[key].sort((a, b) => {
				return a[1] - b[1];
			});
			console.log(availableVestsWithCount[key]);
			player.vestSelect.value = availableVestsWithCount[key][0][0];
			selectedVests.add(player.vestSelect.value);
		});
	}

	function updateAvailableVests() {
		selectedVests.clear();

		players.forEach(player => {
			player.setVestOptions(
				getAvailableVestsForPlayer(player),
			);
		});
	}

	function loadGame(data: FormData, callback: null | (() => void) = null): void {
		startLoading();
		fetchPost(form.getAttribute('action'), data)
			.then((response: { status: string, mode?: string }) => {
				stopLoading();
				if (!response.mode || response.mode === '') {
					console.error('Got invalid mode');
					return;
				}
				const mode = response.mode;

				if (control) {
					control.loadGame(mode, callback);
				}
			})
			.catch(e => {
				triggerNotificationError(e);
				stopLoading(false);
			});
	}

	function loadStartGame(data: FormData, callback: null | (() => void) = null): void {
		startLoading();
		fetchPost('/', data)
			.then((response: { status: string, mode?: string }) => {
				stopLoading();
				if (!response.mode || response.mode === '') {
					console.error('Got invalid mode');
					return;
				}
				const mode = response.mode;

				if (control) {
					control.loadStart(mode, callback);
				}
			})
			.catch(e => {
				triggerNotificationError(e);
				stopLoading(false);
			});
	}
}