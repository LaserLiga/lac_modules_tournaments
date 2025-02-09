<?php

declare(strict_types=1);

namespace LAC\Modules\Tournament\Dto;

use DateTimeInterface;
use Lsr\Lg\Results\Enums\GameModeType;

class ApiTournament
{
    public int $id;
    public string $name;
    public ?string $image = null;
    public ?string $description = null;
    public GameModeType $format = GameModeType::TEAM;
    public int $teamSize;
    public ?ApiLeague $league = null;
    public int $subCount = 0;
    public bool $active;
    public DateTimeInterface $start;
    public ?DateTimeInterface $end = null;
}
