<?php

namespace LAC\Modules\Tournament\Services;

use App\Services\FontAwesomeManager;
use LAC\Modules\Core\MenuExtensionInterface;

class MenuExtension implements MenuExtensionInterface
{
    public function __construct(
        private readonly FontAwesomeManager $fontawesome,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function extend(array &$menu): void {
        $menu['tournaments'] = [
            'name' => lang('Turnaje'),
            'icon' => $this->fontawesome->solid('trophy'),
            'route' => 'tournaments',
            'order' => 50,
        ];

        if (!isset($menu['settings'])) {
            return;
        }
        $menu['settings']['children'][] = [
            'name'  => lang('Turnaje'),
            'route' => 'settings-tournament',
        ];
    }
}
