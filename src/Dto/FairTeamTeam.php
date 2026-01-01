<?php
declare(strict_types=1);

namespace LAC\Modules\Tournament\Dto;

class FairTeamTeam
{

    public int $score {
        get {
            $score = 0;
            foreach ($this->players as $player) {
                $score += $player->score;
            }
            return $score;
        }
    }

    /**
     * @param FairTeamPlayer[] $players
     */
    public function __construct(
        public array $players = [],
    )
    {
    }

    public function addPlayer(FairTeamPlayer $player): void
    {
        $this->players[] = $player;
    }

    public function removePlayer(FairTeamPlayer $player): void
    {
        $index = array_search($player, $this->players, true);
        if ($index !== false) {
            array_splice($this->players, $index, 1);
        }
    }

    public function randomPlayer(): FairTeamPlayer
    {
        if (empty($this->players)) {
            throw new \RuntimeException('No players in team');
        }
        $index = array_rand($this->players);
        return $this->players[$index];
    }

}