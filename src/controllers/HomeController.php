<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\SuperadminModel;

final class HomeController extends BaseController
{
    public function index(): void
    {
        $superadminModel = new SuperadminModel($this->db());

        if (!$superadminModel->tableExists() || !$superadminModel->hasAny()) {
            $this->redirect('/setup');
        }

        if ($this->currentSuperadmin() !== null) {
            $this->redirect('/admin/dashboard');
        }

        $currentTournamentAdmin = $this->currentTournamentAdmin();
        if ($currentTournamentAdmin !== null) {
            $this->redirect('/tournament/' . $currentTournamentAdmin['slug'] . '/admin');
        }

        $enteredSlug = '';
        $slugError = false;
        $requestedSlug = $_GET['tournament_slug'] ?? '';
        if (is_string($requestedSlug) && trim($requestedSlug) !== '') {
            $enteredSlug = strtolower(trim($requestedSlug));
            if (
                strlen($enteredSlug) <= 150
                && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $enteredSlug) === 1
            ) {
                $this->redirect('/tournament/' . $enteredSlug . '/login');
            }

            $slugError = true;
        }

        $this->render('home', [
            'title' => 'BracketBird',
            'enteredSlug' => $enteredSlug,
            'slugError' => $slugError,
        ]);
    }

    public function notFound(): void
    {
        http_response_code(404);
        $this->render('errors/status', [
            'title' => $this->translate('error.not_found_title'),
            'statusCode' => 404,
            'statusTitleKey' => 'error.not_found_title',
            'statusMessageKey' => 'error.not_found_help',
        ]);
    }

    public function forbidden(): void
    {
        http_response_code(403);
        $this->render('errors/status', [
            'title' => $this->translate('error.forbidden_title'),
            'statusCode' => 403,
            'statusTitleKey' => 'error.forbidden_title',
            'statusMessageKey' => 'error.forbidden_help',
        ]);
        exit;
    }
}
