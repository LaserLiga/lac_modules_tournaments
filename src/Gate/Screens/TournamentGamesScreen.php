<?php

namespace LAC\Modules\Tournament\Gate\Screens;

use LAC\Modules\Tournament\Models\Game;
use LAC\Modules\Tournament\Models\Tournament;
use Psr\Http\Message\ResponseInterface;

class TournamentGamesScreen extends AbstractTournamentScreen
{
    private const int MAX_VISIBLE_GAMES = 21;
    private const int MAX_VISIBLE_FINISHED_GAMES = 3;

    public static function getName(): string
    {
        return lang('Rozpis turnajových her', domain: 'gate', context: 'screens');
    }

    public static function getDiKey(): string
    {
        return 'gate.tournament.games';
    }

    public function run(): ResponseInterface
    {
        $tournament = $this->getTournament();
        $games = $this->getVisibleGames($tournament);

        return $this->view(
            '../modules/Tournament/templates/gate/games',
            [
                'tournament' => $tournament,
                'games' => $games,
                'nextGameId' => $this->getNextGameId($games),
                'addJs' => ['modules/tournament/gate.js'],
                'addCss' => ['modules/tournament/gate.css'],
            ]
        );
    }

    /**
     * @return Game[]
     */
    private function getVisibleGames(Tournament $tournament): array
    {
        $games = $tournament->getGames();
        usort($games, static fn(Game $a, Game $b) => $a->start->getTimestamp() <=> $b->start->getTimestamp());

        if (count($games) <= self::MAX_VISIBLE_GAMES) {
            return $games;
        }

        $finishedGames = [];
        $upcomingGames = [];
        foreach ($games as $game) {
            if ($game->hasScores()) {
                $finishedGames[] = $game;
                continue;
            }

            $upcomingGames[] = $game;
        }

        $upcomingLimit = min(count($upcomingGames), self::MAX_VISIBLE_GAMES);
        $finishedLimit = min(
            self::MAX_VISIBLE_FINISHED_GAMES,
            self::MAX_VISIBLE_GAMES - $upcomingLimit,
        );

        $visibleGames = [
            ...($finishedLimit > 0 ? array_slice($finishedGames, -$finishedLimit) : []),
            ...array_slice($upcomingGames, 0, self::MAX_VISIBLE_GAMES - $finishedLimit),
        ];
        usort($visibleGames, static fn(Game $a, Game $b) => $a->start->getTimestamp() <=> $b->start->getTimestamp());

        return $visibleGames;
    }

    /**
     * @param Game[] $games
     */
    private function getNextGameId(array $games): ?int
    {
        foreach ($games as $game) {
            if (!$game->hasScores()) {
                return $game->id;
            }
        }

        return null;
    }
}
