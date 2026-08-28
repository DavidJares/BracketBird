<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\TournamentModel;

final class TournamentAdminAuthController extends BaseController
{
    public function loginForm(): void
    {
        $slug = $this->requestRouteString('slug');
        $tournamentModel = new TournamentModel($this->db());
        $tournament = $tournamentModel->findAuthBySlug($slug);

        if ($tournament === null) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            echo '404 Not Found';
            return;
        }

        if ($this->currentSuperadmin() !== null) {
            $this->redirect('/tournament/' . $slug . '/admin');
        }

        $currentTournamentAdmin = $this->currentTournamentAdmin();
        if (is_array($currentTournamentAdmin) && (int) $currentTournamentAdmin['id'] === (int) $tournament['id']) {
            $this->redirect('/tournament/' . $slug . '/admin');
        }

        $this->render('tournament_admin/login', [
            'title' => 'Tournament admin login',
            'tournamentName' => (string) $tournament['name'],
            'slug' => (string) $tournament['slug'],
        ]);
    }

    public function login(): void
    {
        $slug = $this->requestRouteString('slug');
        $password = $this->requestPostRawString('password');
        $tournamentModel = new TournamentModel($this->db());
        $tournament = $tournamentModel->findAuthBySlug($slug);
        $rateScope = $tournament === null
            ? 'tournament_admin:unknown'
            : 'tournament_admin:id:' . (int) $tournament['id'];

        if (!$this->reserveLoginAttempt($rateScope)) {
            if ($tournament === null) {
                $this->notFound();
                return;
            }

            $this->setFlash('error', 'Invalid credentials.');
            $this->redirect('/tournament/' . (string) $tournament['slug'] . '/login');
        }

        if ($tournament === null) {
            $this->verifyPasswordOrDummy($password, null);
            $this->notFound();
            return;
        }

        $passwordHash = (string) ($tournament['admin_password_hash'] ?? '');
        if (!$this->verifyPasswordOrDummy($password, $passwordHash)) {
            $this->setFlash('error', 'Invalid credentials.');
            $this->redirect('/tournament/' . (string) $tournament['slug'] . '/login');
        }

        if (password_needs_rehash($passwordHash, PASSWORD_DEFAULT)) {
            $rehash = $tournamentModel->rehashAdminPassword(
                (int) $tournament['id'],
                $password,
                $passwordHash
            );
            if ($rehash === null) {
                $this->setFlash('error', 'Invalid credentials.');
                $this->redirect('/tournament/' . (string) $tournament['slug'] . '/login');
            }

            $passwordHash = $rehash;
        }

        $credentialFingerprint = hash('sha256', $passwordHash);
        $this->resetLoginThrottle($rateScope);
        $this->establishAuthentication('tournament_admin', [
            'id' => (int) $tournament['id'],
            'slug' => (string) $tournament['slug'],
            'name' => (string) $tournament['name'],
        ], $credentialFingerprint);
        $this->logSecurityEvent('tournament_admin_login_succeeded', [
            'tournament_id' => (int) $tournament['id'],
        ]);

        $this->setFlash('success', 'Tournament admin access granted.');
        $this->redirect('/tournament/' . (string) $tournament['slug'] . '/admin');
    }

    public function logout(): void
    {
        $slug = $this->requestRouteString('slug');
        $tournamentAdmin = $this->currentTournamentAdmin();
        $this->endAuthentication();
        if (is_array($tournamentAdmin)) {
            $this->logSecurityEvent('tournament_admin_logout', [
                'tournament_id' => (int) $tournamentAdmin['id'],
            ]);
        }

        $this->setFlash('success', 'Tournament admin signed out.');
        $this->redirect('/tournament/' . $slug . '/login');
    }

    private function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '404 Not Found';
    }
}
