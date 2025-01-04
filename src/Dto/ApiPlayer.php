<?php

declare(strict_types=1);

namespace LAC\Modules\Tournament\Dto;

use App\Api\DataObjects\LigaPlayer\LigaPlayerData;
use LAC\Modules\Tournament\Models\PlayerSkill;

class ApiPlayer
{
    public int $id;
    public string $nickname;
    public ?string $name = null;
    public ?string $surname = null;
    public bool $captain = false;
    public bool $sub = false;
    public ?string $email = null;
    public ?string $phone = null;
    public PlayerSkill $skill = PlayerSkill::BEGINNER;
    public ?int $birthYear = null;
    public ?string $image = null;
    public ?LigaPlayerData $user = null;
}
