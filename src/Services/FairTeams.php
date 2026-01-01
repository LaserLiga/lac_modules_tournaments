<?php
declare(strict_types=1);

namespace LAC\Modules\Tournament\Services;

use LAC\Modules\Tournament\Dto\FairTeamPlayer;
use LAC\Modules\Tournament\Dto\FairTeamTeam;
use Random\RandomException;

class FairTeams
{

    public const int MAX_ITERATIONS = 500;
    public const int MAX_ITERATIONS_WITHOUT_IMPROVEMENT = 40;

    /**
     * @param FairTeamPlayer[] $players
     */
    public function __construct(
        public array $players
    )
    {
    }

    public function getMaxPlayerScore(): int
    {
        $maxScore = 0;
        foreach ($this->players as $player) {
            if ($player->score > $maxScore) {
                $maxScore = $player->score;
            }
        }
        return $maxScore;
    }

    public function getMinPlayerScore(): int
    {
        if (empty($this->players)) {
            return 0;
        }
        $minScore = $this->players[0]->score;
        foreach ($this->players as $player) {
            if ($player->score < $minScore) {
                $minScore = $player->score;
            }
        }
        return $minScore;
    }

    public function getAveragePlayerScore(): float
    {
        if (empty($this->players)) {
            return 0;
        }
        $totalScore = 0;
        foreach ($this->players as $player) {
            $totalScore += $player->score;
        }
        return $totalScore / count($this->players);
    }

    public function getMedianPlayerScore(): float
    {
        if (empty($this->players)) {
            return 0;
        }
        $scores = array_map(fn($player) => $player->score, $this->players);
        sort($scores);
        $count = count($scores);
        $middle = (int)floor(($count - 1) / 2);
        if ($count % 2) {
            return $scores[$middle];
        }

        return ($scores[$middle] + $scores[$middle + 1]) / 2.0;
    }

    /**
     * Generate fair teams from players based on their scores
     *
     * Uses simulated annealing-like approach to swap players between teams to minimize score difference.
     *
     * @param int $teamCount
     * @return FairTeamTeam[]
     * @throws RandomException
     */
    public function getTeams(int $teamCount): array
    {
        if ($teamCount < 2) {
            return [];
        }

        // Sort players by score
        usort($this->players, static fn($playerA, $playerB) => $playerB->score - $playerA->score);

        /** @var FairTeamTeam[] $teams */
        $teams = [];

        // Create teams
        for ($i = 0; $i < $teamCount; $i++) {
            $teams[] = new FairTeamTeam();
        }

        $scoreSum = 0;

        // Add players to teams
        $i = 0;
        foreach ($this->players as $player) {
            $scoreSum += $player->score;
            $teams[$i]->addPlayer($player);
            $i = ($i + 1) % $teamCount;
        }

        $teamAvg = $scoreSum / $teamCount;

        $iterationsWithoutImprovement = 0;
        for ($it = 0; $it < self::MAX_ITERATIONS && $iterationsWithoutImprovement < self::MAX_ITERATIONS_WITHOUT_IMPROVEMENT; $it++) {
            [$t1, $t2] = array_rand($teams, 2);
            $team1 = $teams[$t1];
            $team2 = $teams[$t2];

            $score = abs($team1->score - $teamAvg) + abs($team2->score - $teamAvg);

            $player1 = $team1->randomPlayer();
            $player2 = $team2->randomPlayer();

            if ($player1->score === $player2->score) {
                if (random_int(1, 2) < 2) { // 50% chance to swap equal players
                    $this->swapPlayers($team1, $team2, $player1, $player2);
                }
                $iterationsWithoutImprovement++;
                continue;
            }

            $score1 = $team1->score - $player1->score + $player2->score;
            $score2 = $team2->score - $player2->score + $player1->score;
            $newScore = abs($score1 - $teamAvg) + abs($score2 - $teamAvg);

            if ($newScore < $score) {
                $this->swapPlayers($team1, $team2, $player1, $player2);

                $iterationsWithoutImprovement = 0;
                continue;
            }

            $iterationsWithoutImprovement++;
        }

        return $teams;
    }

    private function swapPlayers(FairTeamTeam $team1, FairTeamTeam $team2, FairTeamPlayer $player1, FairTeamPlayer $player2): void
    {
        $team1->removePlayer($player1);
        $team2->removePlayer($player2);
        $team1->addPlayer($player2);
        $team2->addPlayer($player1);
    }

}