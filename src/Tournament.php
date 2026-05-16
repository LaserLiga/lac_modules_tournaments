<?php

namespace LAC\Modules\Tournament;

use App\Gate\Logic\ScreenTriggerType;
use App\Gate\Models\GateScreenModel;
use App\Gate\Models\GateType;
use App\Gate\Screens\Results\ResultsScreen;
use App\Gate\Settings\ResultsSettings;
use LAC\Modules\Core\Module;
use LAC\Modules\Tournament\Gate\Screens\TournamentGamesScreen;
use LAC\Modules\Tournament\Gate\Screens\TournamentPlayersScreen;
use LAC\Modules\Tournament\Gate\Screens\TournamentResultsScreen;
use LAC\Modules\Tournament\Gate\Screens\TournamentStandingsScreen;

class Tournament extends Module
{
    public const string NAME = 'Turnaje';

    public function install(): void {
        $gateType = GateType::getBySlug('tournament_default');
        if (!isset($gateType)) {
            $gateType = new GateType();
            $gateType->setName('Turnaj')
                     ->setSlug('tournament_default')
                ->setLocked(true)
                     ->setDescription('Základní výsledková tabule turnajových výsledků');

            $games = new GateScreenModel();
            $games->setOrder(90)
                ->setScreenSerialized(TournamentGamesScreen::getDiKey())
                ->setTrigger(ScreenTriggerType::DEFAULT);

            $standings = new GateScreenModel();
            $standings->setOrder(95)
                ->setScreenSerialized(TournamentStandingsScreen::getDiKey())
                ->setTrigger(ScreenTriggerType::DEFAULT);

            $players = new GateScreenModel();
            $players->setOrder(100)
                ->setScreenSerialized(TournamentPlayersScreen::getDiKey())
                ->setTrigger(ScreenTriggerType::DEFAULT);

            $idle = new GateScreenModel();
            $idle->setOrder(105)
                 ->setScreenSerialized(TournamentResultsScreen::getDiKey())
                 ->setTrigger(ScreenTriggerType::DEFAULT);

            $results = new GateScreenModel();
            $results->setOrder(10)
                    ->setTrigger(ScreenTriggerType::GAME_ENDED)
                    ->setScreenSerialized(ResultsScreen::getDiKey())
                    ->setSettings(new ResultsSettings());

            $gateType->addScreenModel($games, $standings, $players, $idle, $results);

            $gateType->save();
        }

        $this->addScreenIfMissing($gateType, TournamentGamesScreen::getDiKey(), 90);
        $this->addScreenIfMissing($gateType, TournamentStandingsScreen::getDiKey(), 95);
        $this->addScreenIfMissing($gateType, TournamentPlayersScreen::getDiKey(), 100);
        $gateType->save();

        parent::install();
    }

    private function addScreenIfMissing(GateType $gateType, string $screen, int $order): void
    {
        foreach ($gateType->screens as $screenModel) {
            if ($screenModel->screenSerialized === $screen) {
                return;
            }
        }

        $screenModel = new GateScreenModel();
        $screenModel->setOrder($order)
            ->setScreenSerialized($screen)
            ->setTrigger(ScreenTriggerType::DEFAULT);
        $gateType->addScreenModel($screenModel);
    }
}
