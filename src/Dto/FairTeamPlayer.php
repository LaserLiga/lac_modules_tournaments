<?php
declare(strict_types=1);

namespace LAC\Modules\Tournament\Dto;

use LAC\Modules\Tournament\Models\Player;

final class FairTeamPlayer
{

    public function __construct(
        public readonly Player $player,
        public int             $score,
    )
    {
    }

}