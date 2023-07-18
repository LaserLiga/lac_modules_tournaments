<?php

namespace LAC\Modules\Tournament\Services;

use App\GameModels\Game\Game;
use App\Tools\AbstractResultsParser;
use LAC\Modules\Core\ResultParserExtensionInterface;
use LAC\Modules\Tournament\Models\Game as TournamentGame;
use LAC\Modules\Tournament\Models\Player as TournamentPlayer;
use LAC\Modules\Tournament\Models\Tournament;
use Lsr\Core\App;
use Lsr\Core\Exceptions\ModelNotFoundException;

class ResultParserExtension implements ResultParserExtensionInterface
{

	/**
	 * @inheritDoc
	 */
	public function parse(Game $game, array $meta, AbstractResultsParser $parser): void {
		if (!empty($meta['tournament'])) {
			try {
				$tournament = Tournament::get((int)$meta['tournament']);
				$group = $tournament->getGroup();
				$meta['group'] = $group->id;
				$game->group = $group;
				$group->clearCache();
				$game->tournamentGame = TournamentGame::get((int)$meta['tournament_game']);

				$win = $game->mode?->getWin($game);

				foreach ($game->getTeams() as $team) {
					foreach ($game->tournamentGame->teams as $gameTeam) {
						if (((int)($meta['t' . $team->color . 'tournament'] ?? 0)) !== $gameTeam->id) {
							continue;
						}
						$gameTeam->score = $team->score;
						$gameTeam->position = $team->position;
						if (!isset($win)) {
							$gameTeam->points = $tournament->points->draw;
						}
						else if ($win === $team) {
							$gameTeam->points = $tournament->points->win;
						}
						else {
							$gameTeam->points = $tournament->points->loss;
						}
						if (isset($gameTeam->team)) {
							$team->tournamentTeam = $gameTeam->team;
						}
					}
				}

				foreach ($game->getPlayers() as $player) {
					if (empty($meta['p' . $player->vest . 'tournament'])) {
						continue;
					}
					$player->tournamentPlayer = TournamentPlayer::get((int)$meta['p' . $player->vest . 'tournament']);
				}

				// Recalculate points for all tournament teams
				$tournamentProvider = App::getServiceByType(TournamentProvider::class);
				$tournamentProvider->recalcTeamPoints($tournament);
			} catch (ModelNotFoundException) {
			}
		}
	}
}