<?php

namespace LAC\Modules\Tournament\Services;

use App\Core\App;
use LAC\Modules\Tournament\Models\Game as TournamentGame;
use LAC\Modules\Tournament\Models\GameTeam;
use LAC\Modules\Tournament\Models\Player as TournamentPlayer;
use LAC\Modules\Tournament\Models\Tournament;
use Lsr\Lg\Results\AbstractResultsParser;
use Lsr\Lg\Results\Interface\Models\GameInterface;
use Lsr\Lg\Results\Interface\ResultParserExtensionInterface;
use Lsr\Logging\Logger;
use Lsr\Orm\Exceptions\ModelNotFoundException;

class ResultParserExtension implements ResultParserExtensionInterface
{
    /**
     * @inheritDoc
     */
    public function parse(GameInterface $game, array $meta, AbstractResultsParser $parser) : void {
        if (!empty($meta['tournament'])) {
            try {
                $logger = new Logger(LOG_DIR . 'results/', 'import');
                $logger->debug(
                    'Tournament parser extension started',
                    ['gameClass' => $game::class, 'tournament' => (int)$meta['tournament']]
                );
                $tournament = Tournament::get((int)$meta['tournament']);
                $group = $tournament->getGroup();
                $meta['group'] = $group->id;
                $game->group = $group;
                $group->clearCache();
                $game->tournamentGame = TournamentGame::get((int)$meta['tournament_game']);

          /** @var Team $win */
                $win = $game->mode?->getWin($game);

                /** @var GameTeam[] $positions */
                $positions = [];
                foreach ($game->teams as $team) {
                    foreach ($game->tournamentGame->teams as $gameTeam) {
                        if (((int)($meta['t' . $team->color . 'tournament'] ?? 0)) !== $gameTeam->id) {
                            continue;
                        }
                        $gameTeam->score = $team->score;
                        $gameTeam->position = $team->position;
                        $gameTeam->color = $team->color;
                        if (isset($gameTeam->team)) {
                            $team->tournamentTeam = $gameTeam->team;
                        }
                        $positions[$gameTeam->position] = $gameTeam;
                        break;
                    }
                }

                ksort($positions);
                foreach ($positions as $position => $gameTeam) {
                    switch ($tournament->teamsInGame) {
                        case 4:
                            if (
                                $position > 1
                                && isset($positions[$position - 1])
                                && $positions[$position - 1]->score === $gameTeam->score
                            ) {
                                $position--;
                            }
                            $gameTeam->points = match ($position) {
                                1       => $tournament->points->win,
                                2       => $tournament->points->second,
                                3       => $tournament->points->third,
                                4       => $tournament->points->loss,
                                default => $tournament->points->draw
                            };
                            break;
                        case 3:
                            if (
                                $position > 1
                                && isset($positions[$position - 1])
                                && $positions[$position - 1]->score === $gameTeam->score
                            ) {
                                $position--;
                            }
                            $gameTeam->points = match ($position) {
                                1       => $tournament->points->win,
                                2       => $tournament->points->second,
                                3       => $tournament->points->loss,
                                default => $tournament->points->draw
                            };
                            break;
                        default:
                            if (!isset($win)) {
                                $gameTeam->points = $tournament->points->draw;
                            } elseif ($win->color === $gameTeam->color) {
                                $gameTeam->points = $tournament->points->win;
                            } else {
                                $gameTeam->points = $tournament->points->loss;
                            }
                            break;
                    }
                }

                foreach ($game->players as $player) {
                    if (empty($meta['p' . $player->vest . 'tournament'])) {
                        continue;
                    }
                    $player->tournamentPlayer = TournamentPlayer::get((int)$meta['p' . $player->vest . 'tournament']);
                }

                // Recalculate points for all tournament teams
                $tournamentProvider = App::getServiceByType(TournamentProvider::class);
                $logger->debug('Tournament parser extension recalculating team points', ['tournament' => $tournament->id ?? null]);
                $tournamentProvider->recalcTeamPoints($tournament);
                $logger->debug('Tournament parser extension finished', ['tournament' => $tournament->id ?? null]);
            } catch (ModelNotFoundException) {
            }
        }
    }
}
