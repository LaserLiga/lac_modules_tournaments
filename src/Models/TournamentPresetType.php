<?php

namespace LAC\Modules\Tournament\Models;

/**
 * @property string $value
 * @method static TournamentPresetType from(string $value)
 * @method static TournamentPresetType|null tryFrom(string $value)
 * @method static TournamentPresetType[] cases()
 */
enum TournamentPresetType : string
{
    case ROUND_ROBIN      = 'rr';
    case TWO_GROUPS_ROBIN = '2grr';
    case TWO_GROUPS_ROBIN_10        = '2grr10';
    case BASE_ROUND_AND_BARRAGE     = 'gBarrage';
    case TWO_BASE_ROUND_AND_BARRAGE = '2gBarrage';

    public function getReadableValue() : string {
        return match ($this) {
            self::ROUND_ROBIN                => lang('Každý s každým', context: 'presets', domain: 'tournament'),
            self::TWO_GROUPS_ROBIN           => lang(
                       'Každý s každým na poloviny',
              context: 'presets',
              domain : 'tournament'
            ),
            self::TWO_GROUPS_ROBIN_10        => lang(
                       'Každý s každým na poloviny - 10 týmů',
              context: 'presets',
              domain : 'tournament'
            ),
            self::BASE_ROUND_AND_BARRAGE     => lang(
                       '3 základní hry a baráž',
              context: 'presets',
              domain : 'tournament'
            ),
            self::TWO_BASE_ROUND_AND_BARRAGE => lang(
                       '3 základní hry na poloviny a baráž',
              context: 'presets',
              domain : 'tournament'
            ),
        };
    }

    /**
     * Retrieves the in-game compatibility for the current instance.
     *
     * @return int[] The in-game compatibility values.
     */
    public function getInGameCompatibility() : array {
        return match ($this) {
            self::TWO_GROUPS_ROBIN, self::TWO_GROUPS_ROBIN_10 => [2],
            self::TWO_BASE_ROUND_AND_BARRAGE                  => [3],
            default                                           => [2, 3, 4],
        };
    }
}
