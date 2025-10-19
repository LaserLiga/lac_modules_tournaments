<?php

namespace LAC\Modules\Tournament\Models;

use App\Core\App;
use App\Models\Auth\Player as LigaPlayer;
use App\Models\BaseModel;
use Lsr\Db\DB;
use Lsr\ObjectValidation\Attributes\Email;
use Lsr\Orm\Attributes\NoDB;
use Lsr\Orm\Attributes\PrimaryKey;
use Lsr\Orm\Attributes\Relations\ManyToOne;
use Lsr\Orm\ModelTraits\WithCreatedAt;
use Lsr\Orm\ModelTraits\WithUpdatedAt;

#[PrimaryKey('id_player')]
class Player extends BaseModel
{
    use WithPublicId;
    use WithUpdatedAt;
    use WithCreatedAt;

    public const string TABLE = 'tournament_players';

    public string $nickname;
    public ?string $name = null;
    public ?string $surname = null;

    public PlayerSkill $skill = PlayerSkill::BEGINNER;

    public ?string $image = null;

    public bool $captain = false;
    public bool $sub = false;
    #[Email]
    public ?string $email = null;
    public ?string $phone = null;
    public ?int $birthYear = null;

    #[ManyToOne]
    public Tournament $tournament;
    #[ManyToOne]
    public ?Team $team = null;
    #[ManyToOne]
    public ?LigaPlayer $user = null;


    /** @var int[] */
    #[NoDB]
    public array $vests {
        get {
            if (!isset($this->vests)) {
                $this->vests = DB::select(
                  \App\GameModels\Game\Lasermaxx\Evo5\Player::TABLE,
                  '[vest], COUNT(*) as [count]'
                )
                                 ->where('id_tournament_player = %i', $this->id)
                                 ->groupBy('vest')
                                 ->fetchPairs('vest', 'count');
            }
            return $this->vests;
        }
    }

    /**
     * @return string|null
     */
    public function getImageUrl() : ?string {
        if (empty($this->image)) {
            return null;
        }
        return App::getInstance()->getBaseUrl().$this->image;
    }
}
