<?php

declare(strict_types=1);

namespace LAC\Modules\Tournament\Dto;

class ApiTeam
{
    public int $id;
    public string $name;
    public ?string $image = null;
    /** @var ApiPlayer[] */
    public array $players = [];

    public function addPlayer(ApiPlayer $player): void {
        $this->players[] = $player;
    }

    public function removePlayer(ApiPlayer $player): void {
        foreach ($this->players as $key => $test) {
            if ($test === $player) {
                unset($this->players[$key]);
                return;
            }
        }
    }

    public function hasPlayers(): bool {
        return count($this->players) > 0;
    }
}
