<?php

declare(strict_types=1);

namespace LAC\Modules\Tournament\Dto;

use DateTimeInterface;
use LAC\Modules\Tournament\Models\TournamentPoints;
use Lsr\Lg\Results\Enums\GameModeType;

class ApiTournament
{
    public int $id;
    public string $name;
    public ?string $image = null;
    public ?string $description = null;
    public GameModeType $format = GameModeType::TEAM;
    public int $teamSize;
    public int $teamsInGame;
    public ?ApiLeague $league = null;
    public int $subCount = 0;
    public bool $active;
    public TournamentPoints $points;
    public DateTimeInterface $start;
    public ?DateTimeInterface $end = null;
}
