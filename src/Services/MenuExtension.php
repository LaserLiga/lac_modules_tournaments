<?php

namespace LAC\Modules\Tournament\Services;

use LAC\Modules\Core\MenuExtensionInterface;

class MenuExtension implements MenuExtensionInterface
{

	/**
	 * @inheritDoc
	 */
	public function extend(array &$menu): void {
		$menu['tournaments'] = [
			'name' => lang('Turnaje'),
			'icon' => 'fa-solid fa-trophy',
			'route' => 'tournaments',
			'order' => 50,
		];
	}
}