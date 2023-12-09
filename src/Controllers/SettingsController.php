<?php

namespace LAC\Modules\Tournament\Controllers;

use App\Core\Info;
use Lsr\Core\App;
use Lsr\Core\Controller;
use Lsr\Core\Exceptions\ValidationException;
use Lsr\Core\Requests\Request;
use Lsr\Exceptions\TemplateDoesNotExistException;

class SettingsController extends Controller
{
	/**
	 * @return void
	 * @throws ValidationException
	 * @throws TemplateDoesNotExistException
	 */
	public function tournaments(): void {
		$this->view('../modules/Tournament/templates/settings');
	}

	public function saveTournament(Request $request): never {
		$mode = (string)$request->getPost('game_mode', '0-TEAM_Turnaj');
		Info::set('tournament_game_mode', $mode);

		if (empty($request->errors)) {
			if ($request->isAjax()) {
				$this->respond(['status' => 'ok']);
			}
			$request->passNotices[] = ['type' => 'success', 'content' => lang('Úspěšně uloženo')];
			App::redirect(['settings', 'tournament'], $request);
		}

		if ($request->isAjax()) {
			$this->respond(['errors' => $request->errors], 500);
		}

		$request->passErrors = $request->errors;
		App::redirect(['settings', 'tournament'], $request);
	}
}