<?php

namespace LAC\Modules\Tournament\Controllers;

use App\Core\Info;
use App\GameModels\Game\Enums\GameModeType;
use App\GameModels\Game\Team;
use App\GameModels\Vest;
use App\Models\Auth\Player as LigaPlayer;
use App\Models\MusicMode;
use DateInterval;
use DateTimeImmutable;
use LAC\Modules\Tournament\Models\Game;
use LAC\Modules\Tournament\Models\GameTeam;
use LAC\Modules\Tournament\Models\Group;
use LAC\Modules\Tournament\Models\Player;
use LAC\Modules\Tournament\Models\Progression;
use LAC\Modules\Tournament\Models\Team as TournamentTeam;
use LAC\Modules\Tournament\Models\Tournament;
use LAC\Modules\Tournament\Models\TournamentPresetType;
use LAC\Modules\Tournament\Services\TournamentProvider;
use Lsr\Core\App;
use Lsr\Core\Caching\Cache;
use Lsr\Core\Controller;
use Lsr\Core\Exceptions\ModelNotFoundException;
use Lsr\Core\Exceptions\ValidationException;
use Lsr\Core\Requests\Request;
use Lsr\Core\Templating\Latte;
use Lsr\Helpers\Tools\Strings;
use Lsr\Logging\Exceptions\DirectoryCreationException;
use TournamentGenerator\BlankTeam;
use TournamentGenerator\Progression as ProgressionRozlos;

class TournamentController extends Controller
{

	public const EVO5_TEAM_COLORS = [0 => 1, 1 => 2, 2 => 0, 3 => 3, 4 => 4, 5 => 5];

	public function __construct(
		Latte                               $latte,
		private readonly TournamentProvider $tournamentProvider
	) {
		parent::__construct($latte);
	}

	public function index(): void {
		$this->params['tournaments'] = Tournament::query()->where('[active] = 1 AND DATE([start]) >= CURDATE()')->get();
		$this->view('../modules/Tournament/templates/index');
	}

	public function oldTournaments(): void {
		$this->params['tournaments'] = Tournament::query()
		                                         ->where('[active] = 1 AND DATE([start]) < CURDATE()')
		                                         ->orderBy(
			                                         'start'
		                                         )
		                                         ->desc()
		                                         ->get();
		$this->view('../modules/Tournament/templates/old');
	}

	public function show(Tournament $tournament): void {
		$this->params['tournament'] = $tournament;
		$this->view('../modules/Tournament/templates/show');
	}

	public function rozlos(Tournament $tournament): void {
		$this->params['tournament'] = $tournament;
		$this->params['groups'] = $tournament->groups;
		$this->params['teams'] = $tournament->getTeams();
		$this->params['games'] = $tournament->getGames();
		$this->params['addJs'] = ['modules/tournament/rozlos.js'];
		$this->view('../modules/Tournament/templates/rozlos');
	}

	public function rozlosProcess(Tournament $tournament, Request $request): void {
		$teams = $tournament->getTeams();
		$type = TournamentPresetType::tryFrom($request->getPost('tournament-type', ''));
		if ($type === null) {
			$request->addPassError(lang('Neplatný typ turnaje'));
			App::redirect(['tournament', $tournament->id, 'rozlos'], $request);
		}
		$tournament->gameLength = (int)$request->getPost('game-length', 15);
		$tournament->gamePause = (int)$request->getPost('game-pause', 5);
		$tournamentStart = (int)$request->getPost('tournament-start', 30);
		$iterations = (int)$request->getPost('game-repeat', 1);

		$tournamentRozlos = $this->tournamentProvider->createTournamentFromPreset($type, $tournament, $iterations);

		$this->tournamentProvider->reset($tournament);

		$rounds = $tournamentRozlos->getRounds();
		/** @var Group[] $groups */
		$groups = [];
		/** @var ProgressionRozlos $progressions */
		$progressions = [];
		foreach ($rounds as $round) {
			foreach ($round->getGroups() as $groupRozlos) {
				$group = new Group();
				$roundName = $round->getName();
				$group->round = !empty($roundName) ? $roundName : null;
				$group->name = (!empty($roundName) ? $roundName . ' - ' : '') . $groupRozlos->getName();
				$group->tournament = $tournament;
				$group->save();
				$groups[$group->id] = $group;
				$groupRozlos->setId($group->id);
				foreach ($groupRozlos->getProgressions() as $progression) {
					$progressions[] = $progression;
				}
			}
		}

		$start = new DateTimeImmutable(
			$tournament->start->format('Y-m-d H:i:s') . ' + ' . $tournamentStart . ' minutes'
		);
		$addInterval = new DateInterval('PT' . ($tournament->gameLength + $tournament->gamePause) . 'M');

		$groupTeamKey = [];
		foreach ($tournamentRozlos->getRounds() as $round) {
			/** @var \TournamentGenerator\Game $roundGames */
			$roundGames = [];
			/** @var \TournamentGenerator\Game[][] $roundGroupGames */
			$roundGroupGames = [];
			$gameCount = 0;
			foreach ($round->getGroups() as $group) {
				$games = $group->getInGame() > 2 ? $group->orderGames() : $group->getGames();
				$gameCount += count($games);
				$roundGroupGames[] = $games;
			}
			$i = 0;
			while ($i < $gameCount) {
				foreach ($roundGroupGames as $key => $games) {
					if (count($games) === 0) {
						continue;
					}
					$roundGames[] = array_shift($roundGroupGames[$key]);
					$i++;
				}
			}

			foreach ($roundGames as $gameRozlos) {
				$game = new Game();
				$game->tournament = $tournament;
				$game->group = $groups[$gameRozlos->getGroup()->getId()];
				if (!isset($groupTeamKey[$game->group->id])) {
					$groupTeamKey[$game->group->id] = [];
				}
				$game->start = $start;
				foreach ($gameRozlos->getTeams() as $teamRozlos) {
					$gameTeam = new GameTeam();
					$gameTeam->game = $game;
					if ($teamRozlos instanceof BlankTeam) {
						if (!isset($groupTeamKey[$game->group->id][$teamRozlos->getId()])) {
							$groupTeamKey[$game->group->id][$teamRozlos->getId()] = count(
								$groupTeamKey[$game->group->id]
							);
						}
						$gameTeam->key = $groupTeamKey[$game->group->id][$teamRozlos->getId()];
					}
					else {
						$gameTeam->team = $teams[$teamRozlos->getId()];
						if (!isset($groupTeamKey[$game->group->id][$gameTeam->team->id])) {
							$groupTeamKey[$game->group->id][$gameTeam->team->id] = count(
								$groupTeamKey[$game->group->id]
							);
						}
						$gameTeam->key = $groupTeamKey[$game->group->id][$gameTeam->team->id];
					}
					$game->teams[] = $gameTeam;
				}
				$game->save();
				$start = $start->add($addInterval);
			}
		}

		foreach ($progressions as $progressionRozlos) {
			$progression = new Progression();
			$progression->tournament = $tournament;
			$progression->from = $groups[$progressionRozlos->getFrom()->getId()];
			$progression->to = $groups[$progressionRozlos->getTo()->getId()];
			$progression->start = $progressionRozlos->getStart();
			$progression->length = $progressionRozlos->getLen();
			$progression->filters = serialize($progressionRozlos->getFilters());
			$progression->points = $progressionRozlos->getPoints() ?? 0;

			$keys = [];
			$count = $progression->length;
			if (!isset($count) && isset($progression->start)) {
				$count = $progressionRozlos->getFrom()->getTeamContainer()->count() - $progression->start;
			}
			if ($count > 0) {
				for ($i = 0; $i < $count; $i++) {
					$keys[] = array_shift($groupTeamKey[$progression->to->id]);
				}
			}
			$progression->setKeys($keys);

			$progression->save();
		}
		$request->passNotices[] = ['type' => 'success', 'content' => lang('Vygenerováno')];
		App::redirect(['tournament', $tournament->id, 'rozlos'], $request);
	}

	public function rozlosClear(Tournament $tournament, Request $request): void {
		$this->tournamentProvider->reset($tournament);
		$request->passNotices[] = ['type' => 'success', 'content' => lang('Rozlosování bylo smazáno')];
		App::redirect(['tournament', $tournament->id, 'rozlos'], $request);
	}

	public function sync(Request $request): void {
		if ($this->tournamentProvider->sync() && $this->tournamentProvider->syncUpcomingGames()) {
			$request->passNotices[] = ['type' => 'success', 'content' => lang('Synchronizováno')];
		}
		else {
			$request->addPassError(lang('Synchronizace se nezdařila'));
		}

		App::redirect(['tournament'], $request);
	}

	public function play(Tournament $tournament, ?Game $game = null): void {
		$this->params['tournament'] = $tournament;
		if (!isset($game)) {
			$game = $tournament->getPlannedGame();
		}
		if (!isset($game)) {
			$this->request->addPassError(lang('Nebyla nalezena žádná hra'));
			App::redirect(['tournament', $tournament->id], $this->request);
		}

		$this->params['game'] = $game;
		$this->params['upcomingGames'] = Game::query()->where(
			'[id_tournament] = %i AND [code] IS NULL',
			$tournament->id
		)->limit(20)->get();
		$this->params['vests'] = array_values(Vest::getForSystem('evo5'));
		$this->params['musicModes'] = MusicMode::getAll();
		$this->params['teamColors'] = $this::EVO5_TEAM_COLORS;
		$this->params['addJs'] = ['modules/tournament/play.js'];

		$this->view('../modules/Tournament/templates/play');
	}

	public function playList(Tournament $tournament): void {
		$this->params['tournament'] = $tournament;
		$this->params['games'] = $tournament->getGames();
		$this->view('../modules/Tournament/templates/playList');
	}

	public function playResults(Tournament $tournament, Game $game): never {
		if ($game->getGame() === null) {
			$this->respond(['status' => 'not yet finished']);
		}
		$this->params['tournament'] = $tournament;
		$this->params['game'] = $game;
		$this->params['upcomingGames'] = Game::query()->where(
			'[id_tournament] = %i AND [code] IS NULL',
			$tournament->id
		)->limit(20)->get();
		$this->params['musicModes'] = MusicMode::getAll();
		$this->params['teamColors'] = $this::EVO5_TEAM_COLORS;
		$view = $this->latte->viewToString('../modules/Tournament/templates/components/play', $this->params);
		$this->respond(['status' => 'results', 'view' => $view]);
	}

	public function playProcess(Tournament $tournament, Game $game, Request $request): never {
		/** @var array{
		 *   meta:array<string,string|numeric>,
		 *   players:array{vest:int,name:string,vip:bool,team:int,code?:string}[],
		 *   teams:array{key:int,name:string,playerCount:int}
		 *   } $data
		 */
		$data = [
			'meta' => [
				'mode'       => '0-TEAM_Turnaj',
				'music'      => $request->getPost('music'),
				'tournament' => $tournament->id,
				'tournament_game' => $game->id,
			],
			'players' => [],
		];

		/** @var array<numeric-string, int> $teamCounts */
		$teamCounts = [];
		$teamData = [];
		/** @var Player[] $playersAll */
		$playersAll = [];
		$key = 0;
		foreach ($game->teams as $team) {
			$color = $this::EVO5_TEAM_COLORS[$key];
			foreach ($team->team->getPlayers() as $id => $player) {
				$playersAll[$id] = $player;
			}
			$asciiName = Strings::toAscii($team->getName());
			if ($team->getName() !== $asciiName) {
				$data['meta']['t' . $color . 'n'] = $team->getName();
			}
			$data['meta']['t' . $color . 'tournament'] = $team->id;
			$teamData[$color] = [
				'key'  => $color,
				'name' => $asciiName,
				'playerCount' => 0,
			];
			$key++;
		}

		/** @var array{name:string,vest:numeric-string,team:numeric-string}[] $players */
		$players = $request->getPost('player', []);
		foreach ($players as $id => $player) {
			if (empty($player['vest'])) {
				continue;
			}
			$player['name'] = trim($player['name']);
			$asciiName = substr(Strings::toAscii($player['name']), 0, 12);
			if ($player['name'] !== $asciiName) {
				$data['meta']['p' . $player['vest'] . 'n'] = $player['name'];
			}
			$tournamentPlayer = $playersAll[$id];
			$data['meta']['p' . $player['vest'] . 'tournament'] = $tournamentPlayer->id;
			if (isset($tournamentPlayer->user)) {
				$data['meta']['p' . $player['vest'] . 'u'] = $tournamentPlayer->user->getCode();
			}
			$data['players'][] = [
				'vest' => $player['vest'],
				'name' => $asciiName,
				'team' => $player['team'],
				'vip' => false,
			];
			if (!isset($teamCounts[$player['team']])) {
				$teamCounts[$player['team']] = 0;
			}
			$teamCounts[$player['team']]++;
		}

		foreach ($teamCounts as $color => $count) {
			$teamData[$color]['playerCount'] = $count;
		}

		usort($data['players'], static fn($player1, $player2) => ((int)$player1['vest']) - ((int)$player2['vest']));
		bdump($data['players']);

		$data['teams'] = array_values($teamData);
		$data['meta']['hash'] = md5(json_encode($data['players'], JSON_THROW_ON_ERROR));

		$content = $this->latte->viewToString('gameFiles/evo5', $data);
		$loadDir = LMX_DIR . Info::get('evo5_load_file', 'games/');
		if (file_exists($loadDir) && is_dir($loadDir)) {
			file_put_contents($loadDir . '0000.game', $content);
		}

		if (isset($data['meta']['music'])) {
			try {
				$music = MusicMode::get((int)$data['meta']['music']);
				if (!file_exists($music->fileName)) {
					App::getLogger()->warning('Music file does not exist - ' . $music->fileName);
				}
				else if (!copy($music->fileName, LMX_DIR . 'music/evo5.mp3')) {
					App::getLogger()->warning('Music copy failed - ' . $music->fileName);
				}
			} catch (ModelNotFoundException|ValidationException|DirectoryCreationException) {
				// Not critical, doesn't need to do anything
			}
		}

		$this->respond(['status' => 'ok', 'mode' => $data['meta']['mode']]);
	}

	public function updateBonusScore(Tournament $tournament, Game $game, Request $request): never {
		/** @var array<int,int> $bonus */
		$bonus = $request->getPost('bonus', []);

		$results = $game->getGame();
		if (!isset($results)) {
			$this->respond(['error' => 'Game is not finished yet.'], 400);
		}

		/** @var Team $team */
		foreach ($results->getTeams() as $team) {
			if (!isset($team->tournamentTeam)) {
				$team->bonus = null;
				continue;
			}
			$team->setBonus($bonus[$team->tournamentTeam->id] ?? 0);
		}

		$results->reorder();
		$results->save();

		$this->respond(['success' => true]);
	}

	public function resetGame(Tournament $tournament, Game $game): never {
		$results = $game->getGame();
		if (!isset($results)) {
			$this->respond(['status' => 'No results']);
		}

		foreach ($game->teams as $team) {
			$team->team->points -= $team->points;
			$team->score = null;
			$team->position = null;
			$team->points = null;
			$team->save();
			$team->team->save();
		}
		$game->code = null;
		$game->save();

		/** @var Team $team */
		foreach ($results->getTeams() as $team) {
			$team->tournamentTeam = null;
			$team->save();
		}
		/** @var \App\GameModels\Game\Player $player */
		foreach ($results->getPlayers() as $player) {
			$player->tournamentPlayer = null;
			$player->save();
		}
		$this->respond(['status' => 'ok']);
	}

	public function progress(Tournament $tournament): never {
		$this->respond(['progressed' => $this->tournamentProvider->progress($tournament)]);
	}

	public function create(): void {
		$this->params['addJs'] = ['modules/tournament/create.js'];
		$this->view('../modules/Tournament/templates/create');
	}

	public function createProcess(Request $request): void {
		/** @var array{name:string,start:string,format:string,team_size:int,teams_in_game:int} $values */
		$values = [];
		$errors = [];

		// Validate request
		$values['name'] = (string)$request->getPost('name', '');
		if (empty($values['name'])) {
			$errors['name'] = lang('Název turnaje je povinný');
		}
		else if (strlen($values['name']) > 100) {
			$errors['name'] = lang('Název turnaje nesmí být delší, než 100 znaků');
		}
		$values['start'] = (string)$request->getPost('start', date('d.m.Y H:i'));
		if (empty($values['start'])) {
			$errors['start'] = lang('Začátek turnaje je povinný');
		}
		else if (!strtotime($values['start'])) {
			$errors['start'] = lang('Začátek turnaje musí být datum a čas');
		}
		$values['format'] = (string)$request->getPost('format', 'TEAM');
		if (empty($values['format'])) {
			$errors['format'] = lang('Formát turnaje je povinný');
		}
		else if (GameModeType::tryFrom($values['format']) === null) {
			$errors['format'] = lang('Formát turnaje není validní');
		}
		$values['team_size'] = (int)$request->getPost('team_size', 5);
		if ($values['format'] === GameModeType::TEAM->value && $values['team_size'] < 1) {
			$errors['team_size'] = lang('Velikost týmu je povinná a musí být kladné číslo');
		}
		$values['teams_in_game'] = (int)$request->getPost('teams_in_game', 2);
		$validValues = [2, 3, 4];
		if ($values['format'] === GameModeType::TEAM->value && !in_array(
				$values['teams_in_game'],
				$validValues,
				true
			)) {
			$errors['teams_in_game'] = sprintf(
				lang('Počet týmů ve hře je povinný a musí být validní hodnota (%s).'),
				implode(', ', $validValues)
			);
		}


		if (count($errors) > 0) {
			$this->params['values'] = $values;
			$this->params['errors'] = $errors;
			$this->params['addJs'] = ['modules/tournament/create.js'];
			$this->view('../modules/Tournament/templates/create');
			return;
		}

		// Create tournament
		$tournament = new Tournament();
		$tournament->start = new DateTimeImmutable($values['start']);
		$tournament->format = GameModeType::from($values['format']);
		$tournament->name = $values['name'];
		$tournament->teamSize = $values['team_size'];
		$tournament->teamsInGame = $values['teams_in_game'];

		try {
			if ($tournament->save()) {
				App::redirect(['tournament', $tournament->id], $request);
			}
		} catch (ValidationException $e) {
			$errors[] = $e->getMessage();
		}
		$errors[] = lang('Turnaj se nepodařilo uložit.');
		$this->params['values'] = $values;
		$this->params['errors'] = $errors;
		$this->params['addJs'] = ['modules/tournament/create.js'];
		$this->view('../modules/Tournament/templates/create');
	}

	public function teams(Tournament $tournament, Request $request): void {
		$teams = $request->getPost('teams');
		if (isset($teams) && is_array($teams)) {
			foreach ($teams as $key => $teamData) {
				if (is_numeric($key)) {
					$team = TournamentTeam::get((int)$key);
				}
				else {
					$team = new TournamentTeam();
					$team->tournament = $tournament;
				}
				if (!isset($team)) {
					continue;
				}
				$team->name = $teamData['name'];
				bdump($team);
				$team->save();

				foreach ($teamData['players'] as $pKey => $playerData) {
					if (is_numeric($pKey)) {
						$player = Player::get((int)$pKey);
					}
					else {
						$player = new Player();
						$player->team = $team;
						$player->tournament = $tournament;
					}
					$player->name = $playerData['name'];
					$player->surname = $playerData['surname'];
					$player->nickname = $playerData['nickname'];
					if (!empty($playerData['code'])) {
						$user = LigaPlayer::getByCode($playerData['code']);
						if (isset($user)) {
							$player->user = $user;
							$player->email = $user->email;
						}
					}
					$player->save();
				}
			}
			/** @var Cache $cache */
			$cache = App::getServiceByType(Cache::class);
			$cache->clean(
				[
					Cache::Tags => [
						'tournament-' . $tournament->id . '-teams',
						'tournament-' . $tournament->id . '-players',
					],
				]
			);
			$request->passNotices[] = ['type' => 'success', 'content' => lang('Uloženo')];
			App::redirect(['tournament', $tournament->id, 'teams'], $request);
		}

		$this->params['tournament'] = $tournament;
		$this->params['addJs'] = ['modules/tournament/teams.js'];
		$this->view('../modules/Tournament/templates/teams');
	}
}