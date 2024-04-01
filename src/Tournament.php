<?php

namespace LAC\Modules\Tournament;

use App\Gate\Logic\ScreenTriggerType;
use App\Gate\Models\GateScreenModel;
use App\Gate\Models\GateType;
use App\Gate\Screens\Results\ResultsScreen;
use App\Gate\Settings\ResultsSettings;
use LAC\Modules\Core\Module;
use LAC\Modules\Tournament\Gate\Screens\TournamentResultsScreen;

class Tournament extends Module
{

	public const NAME = 'Turnaje';

	public function install() : void {
		$gateType = GateType::getBySlug('tournament_default');
		if (!isset($gateType)) {
			$gateType = new GateType();
			$gateType->setName('Turnaj')
			         ->setSlug('tournament_default')
				->setLocked(true)
			         ->setDescription('Základní výsledková tabule turnajových výsledků');

			$idle = new GateScreenModel();
			$idle->setOrder(99)
			     ->setScreenSerialized(TournamentResultsScreen::getDiKey())
			     ->setTrigger(ScreenTriggerType::DEFAULT);

			$results = new GateScreenModel();
			$results->setOrder(10)
			        ->setTrigger(ScreenTriggerType::GAME_ENDED)
			        ->setScreenSerialized(ResultsScreen::getDiKey())
			        ->setSettings(new ResultsSettings());

			$gateType->addScreenModel($idle, $results);

			$gateType->save();
		}

		parent::install();
	}

}