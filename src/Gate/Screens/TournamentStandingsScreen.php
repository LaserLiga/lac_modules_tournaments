<?php

namespace LAC\Modules\Tournament\Gate\Screens;

use App\Core\Info;
use App\GameModels\Game\Game as AppGame;
use App\Models\System;
use LAC\Modules\Tournament\Models\Game;
use LAC\Modules\Tournament\Models\GameTeam;
use LAC\Modules\Tournament\Models\Team;
use LAC\Modules\Tournament\Models\Tournament;
use Psr\Http\Message\ResponseInterface;

class TournamentStandingsScreen extends AbstractTournamentScreen
{
    public static function getName(): string
    {
        return lang('Výsledky turnaje', domain: 'gate', context: 'screens');
    }

    public static function getDiKey(): string
    {
        return 'gate.tournament.standings';
    }

    public function run(): ResponseInterface
    {
        $tournament = $this->getTournament();
        $teams = $this->getTeamsSorted($tournament);
        $playingGameIds = $this->getPlayingTournamentGameIds($tournament);
        $upcomingGames = $this->getUpcomingGames($tournament, $playingGameIds);
        $nextGame = $upcomingGames[0] ?? null;

        return $this->view(
            '../modules/Tournament/templates/gate/standings',
            [
                'tournament' => $tournament,
                'teams' => $teams,
                'stats' => $this->getTeamStats($tournament, $teams),
                'teamNextGames' => $this->getTeamNextGames($upcomingGames),
                'prepareTeamIds' => $nextGame !== null ? $this->getGameTeamIds($nextGame) : [],
                'playingTeamIds' => $this->getPlayingTeamIds($tournament, $playingGameIds),
                'addJs' => ['modules/tournament/gate.js'],
                'addCss' => ['modules/tournament/gate.css'],
            ]
        );
    }

    /**
     * @return int[]
     */
    private function getPlayingTournamentGameIds(Tournament $tournament): array
    {
        $gameIds = [];
        foreach ($this->getActiveSystems() as $system) {
            $startedGame = Info::get($system . '-game-started', useCache: false);
            if (!$startedGame instanceof AppGame || !$startedGame->isStarted() || $startedGame->isFinished()) {
                continue;
            }

            $meta = $startedGame->getMeta();
            if ((int)($meta['tournament'] ?? 0) !== $tournament->id) {
                continue;
            }

            $tournamentGameId = (int)($meta['tournament_game'] ?? 0);
            if ($tournamentGameId > 0) {
                $gameIds[] = $tournamentGameId;
            }
        }

        return array_values(array_unique($gameIds));
    }

    /**
     * @return string[]
     */
    private function getActiveSystems(): array
    {
        if ($this->systems !== []) {
            return $this->systems;
        }

        return array_map(
            static fn(System $system) => $system->type->value,
            System::getActive(false)
        );
    }

    /**
     * @return Game[]
     */

    /**
     * @param int[] $excludeGameIds
     * @return Game[]
     */
    private function getUpcomingGames(Tournament $tournament, array $excludeGameIds = []): array
    {
        $games = array_filter(
            $tournament->getGames(),
            static fn(Game $game) => !$game->hasScores() && !in_array($game->id, $excludeGameIds, true)
        );
        usort($games, static fn(Game $a, Game $b) => $a->start->getTimestamp() <=> $b->start->getTimestamp());

        return array_values($games);
    }

    /**
     * @param Team[] $teams
     * @return array<int,array{played:int,wins:int,seconds:int,thirds:int,draws:int,losses:int}>
     */
    private function getTeamStats(Tournament $tournament, array $teams): array
    {
        $stats = [];
        foreach ($teams as $team) {
            $stats[$team->id] = [
                'played' => 0,
                'wins' => 0,
                'seconds' => 0,
                'thirds' => 0,
                'draws' => 0,
                'losses' => 0,
            ];
        }

        foreach ($tournament->getGames() as $game) {
            if (!$game->hasScores()) {
                continue;
            }

            $this->addGameStats($game, $stats);
        }

        return $stats;
    }

    /**
     * @param array<int,array{played:int,wins:int,seconds:int,thirds:int,draws:int,losses:int}> $stats
     */
    private function addGameStats(Game $game, array &$stats): void
    {
        $teams = [];
        foreach ($game->teams as $gameTeam) {
            if (!isset($gameTeam->team->id, $gameTeam->score, $stats[$gameTeam->team->id])) {
                continue;
            }

            $teams[] = $gameTeam;
        }

        usort(
            $teams,
            static fn(GameTeam $a, GameTeam $b) => ($b->score ?? 0) <=> ($a->score ?? 0)
        );

        $scores = array_map(static fn(GameTeam $team) => $team->score ?? 0, $teams);
        $lastIndex = count($teams) - 1;
        foreach ($teams as $index => $gameTeam) {
            $teamId = $gameTeam->team->id;
            $score = $gameTeam->score ?? 0;
            $isDraw = count(array_keys($scores, $score, true)) > 1;
            $stats[$teamId]['played']++;

            if ($isDraw) {
                $stats[$teamId]['draws']++;
                continue;
            }

            match ($index) {
                0 => $stats[$teamId]['wins']++,
                1 => $stats[$teamId]['seconds']++,
                2 => $stats[$teamId]['thirds']++,
                default => null,
            };

            if ($index === $lastIndex) {
                $stats[$teamId]['losses']++;
            }
        }
    }

    /**
     * @param Game[] $upcomingGames
     * @return array<int,Game>
     */
    private function getTeamNextGames(array $upcomingGames): array
    {
        $teamNextGames = [];
        foreach ($upcomingGames as $game) {
            foreach ($game->teams as $gameTeam) {
                if (!isset($gameTeam->team->id) || isset($teamNextGames[$gameTeam->team->id])) {
                    continue;
                }

                $teamNextGames[$gameTeam->team->id] = $game;
            }
        }

        return $teamNextGames;
    }

    /**
     * @return int[]
     */
    private function getGameTeamIds(Game $game): array
    {
        $teamIds = [];
        foreach ($game->teams as $gameTeam) {
            if (isset($gameTeam->team->id)) {
                $teamIds[] = $gameTeam->team->id;
            }
        }

        return $teamIds;
    }

    /**
     * @param int[] $playingGameIds
     * @return int[]
     */
    private function getPlayingTeamIds(Tournament $tournament, array $playingGameIds): array
    {
        if ($playingGameIds === []) {
            return [];
        }

        $teamIds = [];
        foreach ($tournament->getGames() as $game) {
            if (!in_array($game->id, $playingGameIds, true)) {
                continue;
            }

            foreach ($this->getGameTeamIds($game) as $teamId) {
                $teamIds[] = $teamId;
            }
        }

        return array_values(array_unique($teamIds));
    }
}
