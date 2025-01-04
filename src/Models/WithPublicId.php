<?php

namespace LAC\Modules\Tournament\Models;

use Lsr\Orm\Attributes\Hooks\AfterInsert;
use Lsr\Orm\Attributes\Hooks\AfterUpdate;

trait WithPublicId
{
    /** @var array<int,static> */
    protected static array $publicIdMap = [];
    public ?int $idPublic = null;

    /**
     * @param int $publicId
     * @return static|null
     * @post Entity is cached in static array
     */
    public static function getByPublicId(int $publicId): ?static {
        static::$publicIdMap[$publicId] ??= static::query()->where('[id_public] = %i', $publicId)->first();
        return static::$publicIdMap[$publicId];
    }

    #[AfterUpdate, AfterInsert]
    public function updatePublicIdMap() : void {
        if ($this->idPublic !== null) {
            static::$publicIdMap[$this->idPublic] = $this;
        }
    }
}
