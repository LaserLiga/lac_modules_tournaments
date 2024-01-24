<?php

namespace LAC\Modules\Tournament\Controllers;

use App\Controllers\CommonGateMethods;
use App\Core\Info;
use App\GameModels\Factory\GameFactory;
use App\GameModels\Game\Evo5\Player;
use App\GameModels\Game\Game;
use App\GameModels\Game\PrintStyle;
use LAC\Modules\Tournament\Models\Player as TournamentPlayer;
use LAC\Modules\Tournament\Models\Team;
use LAC\Modules\Tournament\Models\Tournament;
use Lsr\Core\Constants;
use Lsr\Core\Controllers\Controller;
use Lsr\Core\DB;

class TournamentResults extends Controller
{

	use CommonGateMethods;

	public function results(Tournament $tournament): void {
		$this->params['tournament'] = $tournament;
		$this->params['teams'] = $tournament->getTeams();
		$this->params['games'] = $tournament->getGames();

		usort($this->params['teams'], static function (Team $a, Team $b) {
			$diff = $b->points - $a->points;
			if ($diff !== 0) {
				return $diff;
			}
			return $b->getScore() - $a->getScore();
		});

		$this->params['bestPlayers'] = [];
		$this->params['accuracyPlayers'] = [];
		$this->params['shotsPlayers'] = [];
		$this->params['hitsOwnPlayers'] = [];

		$playerIds = [];
		foreach ($this->params['teams'] as $team) {
			foreach ($team->getPlayers() as $player) {
				$playerIds[] = $player->id;
			}
		}

		$bestPlayers = DB::select(Player::TABLE, '[id_tournament_player], [name], AVG([skill]) as [skill]')
			->where('[id_tournament_player] IN %in', $playerIds)
			->groupBy('id_tournament_player')
			->orderBy('skill')
			->desc()
			->cacheTags(Player::TABLE, ...Player::CACHE_TAGS)
			->fetchAll();
		foreach ($bestPlayers as $row) {
			$this->params['bestPlayers'][] = ['player' => TournamentPlayer::get($row->id_tournament_player), 'value' => $row->skill];
		}

		$accuracyPlayers = DB::select(Player::TABLE, '[id_tournament_player], [name], MAX([accuracy]) as [accuracy]')
			->where('[id_tournament_player] IN %in', $playerIds)
			->groupBy('id_tournament_player')
			->orderBy('accuracy')
			->desc()
			->cacheTags(Player::TABLE, ...Player::CACHE_TAGS)
			->fetchAll();
		foreach ($accuracyPlayers as $row) {
			$this->params['accuracyPlayers'][] = ['player' => TournamentPlayer::get($row->id_tournament_player), 'value' => $row->accuracy];
		}

		$shotsPlayers = DB::select(Player::TABLE, '[id_tournament_player], [name], SUM([shots]) as [shots]')
			->where('[id_tournament_player] IN %in', $playerIds)
			->groupBy('id_tournament_player')
			->orderBy('shots')
			->desc()
			->cacheTags(Player::TABLE, ...Player::CACHE_TAGS)
			->fetchAll();
		foreach ($shotsPlayers as $row) {
			$this->params['shotsPlayers'][] = ['player' => TournamentPlayer::get($row->id_tournament_player), 'value' => $row->shots];
		}

		$hitsOwnPlayers = DB::select(Player::TABLE, '[id_tournament_player], [name], SUM([hits_own]) as [hitsOwn]')
			->where('[id_tournament_player] IN %in', $playerIds)
			->groupBy('id_tournament_player')
			->orderBy('hitsOwn')
			->desc()
			->cacheTags(Player::TABLE, ...Player::CACHE_TAGS)
			->fetchAll();
		foreach ($hitsOwnPlayers as $row) {
			$this->params['hitsOwnPlayers'][] = ['player' => TournamentPlayer::get($row->id_tournament_player), 'value' => $row->hitsOwn];
		}

		$this->view('../modules/Tournament/templates/results');
	}

	public function gate(Tournament $tournament): void {
		$this->params['tournament'] = $tournament;
		$this->params['style'] = PrintStyle::getActiveStyle();
		$this->params['addJs'] = ['modules/tournament/gate.js'];
		$this->params['addCss'] = ['modules/tournament/gate.css'];

		// Allow for filtering games just from one system
		$system = $_GET['system'] ?? 'all';
		$systems = [$system];

		// Fallback to all available systems
		if ($system === 'all') {
			$systems = GameFactory::getSupportedSystems();
		}

		$now = time();

		/** @var Game|null $test */
		$test = Info::get('gate-game');
		/** @var int $gateTime */
		$gateTime = Info::get('gate-time', $now);
		if (isset($test) && ($now - $gateTime) <= Constants::TMP_GAME_RESULTS_TIME) {
			$this->params['reloadTimer'] = Constants::TMP_GAME_RESULTS_TIME - ($now - $gateTime) + 2;
			header('X-Reload-Time: ' . $this->params['reloadTimer']);
			$this->game = $test;
			if ($this->checkTournamentGame($tournament)) {
				$this->getResults();
				return;
			}
		}

		// Get the results of the last game played if it had finished in the last 2 minutes
		$lastGame = GameFactory::getLastGame($system);
		if (isset($lastGame) && ($now - $lastGame->end?->getTimestamp()) <= Constants::GAME_RESULTS_TIME) {
			$this->params['reloadTimer'] = Constants::GAME_RESULTS_TIME - ($now - $lastGame->end?->getTimestamp()) + 2;
			header('X-Reload-Time: ' . $this->params['reloadTimer']);
			$this->game = $lastGame;
			$this->getResults();
			return;
		}

		// Try to find the last loaded or started games in selected systems
		foreach ($systems as $system) {
			/** @var Game|null $started */
			$started = Info::get($system . '-game-started');
			if (isset($started) && ($now - $started->start?->getTimestamp()) <= Constants::GAME_STARTED_TIME) {
				if (isset($this->game) && $this->game->fileTime > $started->fileTime) {
					continue;
				}
				$this->params['reloadTimer'] = Constants::GAME_STARTED_TIME - ($now - $started->start?->getTimestamp()) + 2;
				$started->end = null;
				$started->finished = false;
				$this->game = $started;
			}
		}

		if (isset($this->params['reloadTimer'])) {
			header('X-Reload-Time: ' . $this->params['reloadTimer']);
		}

		$this->getIdle($tournament);
	}

	private function checkTournamentGame(Tournament $tournament): bool {
		return isset($this->game, $this->game->tournamentGame) && $this->game->tournamentGame->tournament->id === $tournament->id;
	}

	private function getIdle(Tournament $tournament): void {
		$this->params['game'] = $this->game;
		$this->params['games'] = $tournament->getGames();
		$this->params['teams'] = $tournament->getTeams();
		$this->params['addJs'] = ['modules/tournament/gate.js'];
		$this->params['addCss'] = ['modules/tournament/gate.css'];

		usort($this->params['teams'], static function (Team $a, Team $b) {
			$diff = $b->points - $a->points;
			if ($diff !== 0) {
				return $diff;
			}
			return $b->getScore() - $a->getScore();
		});

		$this->view('../modules/Tournament/templates/gate');
	}

}