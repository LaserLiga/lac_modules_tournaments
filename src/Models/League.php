<?php

namespace LAC\Modules\Tournament\Models;

use App\Core\App;
use Lsr\Orm\Attributes\NoDB;
use Lsr\Orm\Attributes\PrimaryKey;

#[PrimaryKey('id_league')]
class League extends \App\Models\BaseModel
{
    use WithPublicId;

    public const string TABLE = 'leagues';

    public ?int $idPublic = null;

    public string $name;
    public ?string $description = null;
    public ?string $image = null;

    /** @var Tournament[] */
    #[NoDB]
    public array $tournaments = [] {
        get {
            if (empty($this->tournaments)) {
                $this->tournaments = Tournament::query()->where('id_league = %i AND active = 1', $this->id)->get();
            }
            return $this->tournaments;
        }
    }

    public function getImageUrl(): ?string {
        if (!isset($this->image)) {
            return null;
        }
        return App::getInstance()->getBaseUrl() . $this->image;
    }
}
