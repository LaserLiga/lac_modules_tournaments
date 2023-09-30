<?php

use LAC\Modules\Tournament\Controllers\Api\Tournaments;
use LAC\Modules\Tournament\Controllers\TournamentController;
use LAC\Modules\Tournament\Controllers\TournamentResults;
use Lsr\Core\Routing\Route;

Route::group('tournament')
	->get('', [TournamentController::class, 'index'])->name('tournaments')
                                                   ->get('old', [TournamentController::class, 'oldTournaments'])
	->get('sync', [TournamentController::class, 'sync'])
	->get('{id}', [TournamentController::class, 'show'])
	->get('{id}/rozlos', [TournamentController::class, 'rozlos'])->name('tournament-rozlos')
	->get('{id}/results', [TournamentResults::class, 'results'])->name('tournament-results')
	->get('{id}/gate', [TournamentResults::class, 'gate'])->name('tournament-gate')
	->post('{id}/rozlos', [TournamentController::class, 'rozlosProcess'])
	->get('{id}/rozlos/clear', [TournamentController::class, 'rozlosClear'])
	->get('{id}/play', [TournamentController::class, 'play'])->name('tournament-play')
	->get('{id}/play/list', [TournamentController::class, 'playList'])->name('tournament-play-list')
	->post('{id}/progress', [TournamentController::class, 'progress'])
	->get('{id}/play/{gameId}', [TournamentController::class, 'play'])->name('tournament-play-game')
	->get('{id}/play/{gameId}/results', [TournamentController::class, 'playResults'])
	->post('{id}/play/{gameId}', [TournamentController::class, 'playProcess'])
	->post('{id}/play/{gameId}/bonus', [TournamentController::class, 'updateBonusScore'])
	->post('{id}/play/{gameId}/reset', [TournamentController::class, 'resetGame']);

Route::group('api/tournament')
	->get('/', [Tournaments::class, 'getAll'])
	->get('/{id}', [Tournaments::class, 'get'])
	->post('/sync', [Tournaments::class, 'sync'])
	->post('/{id}/sync/games', [Tournaments::class, 'syncGames'])
	->post('/{id}/recalc', [Tournaments::class, 'recalculatePoints']);