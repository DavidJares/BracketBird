<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\MigrationModel;
use App\Models\SuperadminModel;
use Throwable;

final class SetupController extends BaseController
{
    public function index(): void
    {
        if ($this->configuredSetupToken() === null) {
            $this->notFound();
            return;
        }

        if (!$this->allMigrationsAreComplete()) {
            $this->renderMigrationsUnavailable();
            return;
        }

        $superadminModel = new SuperadminModel($this->db());

        if (!$superadminModel->tableExists()) {
            $this->render('setup/unavailable', [
                'title' => 'Setup unavailable',
                'message' => 'Database tables are missing. Run migrations first (php scripts/migrate.php).',
            ]);
            return;
        }

        if ($superadminModel->hasAny()) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            echo '404 Not Found';
            return;
        }

        $this->render('setup/index', [
            'title' => 'Initial setup',
        ]);
    }

    public function store(): void
    {
        $configuredSetupToken = $this->configuredSetupToken();
        if ($configuredSetupToken === null) {
            $this->notFound();
            return;
        }

        if (!$this->allMigrationsAreComplete()) {
            $this->renderMigrationsUnavailable();
            return;
        }

        $superadminModel = new SuperadminModel($this->db());

        if (!$superadminModel->tableExists()) {
            $this->render('setup/unavailable', [
                'title' => 'Setup unavailable',
                'message' => 'Database tables are missing. Run migrations first (php scripts/migrate.php).',
            ]);
            return;
        }

        if ($superadminModel->hasAny()) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            echo '404 Not Found';
            return;
        }

        $submittedSetupToken = $this->requestPostRawString('setup_token');
        if (
            strlen($submittedSetupToken) > 1024
            || !hash_equals($configuredSetupToken, $submittedSetupToken)
        ) {
            $this->setFlash('error', 'Setup could not be completed.');
            $this->redirect('/setup');
        }

        $username = $this->requestPostString('username');
        $password = $this->requestPostRawString('password');

        if ($username === '' || strlen($username) > 100 || $password === '') {
            $this->setFlash('error', 'Username and password are required.');
            $this->redirect('/setup');
        }

        if (strlen($password) < 8 || !$this->passwordInputIsValid($password)) {
            $this->setFlash('error', 'Password must contain between 8 and 72 bytes.');
            $this->redirect('/setup');
        }

        try {
            $createdId = $superadminModel->createFirst($username, $password);
        } catch (Throwable $throwable) {
            $this->setFlash('error', 'Superadmin could not be created. Username may already exist.');
            $this->redirect('/setup');
        }

        if ($createdId === null) {
            $this->notFound();
            return;
        }

        $this->logSecurityEvent('first_admin_created', [
            'superadmin_id' => $createdId,
        ]);
        $this->rotateCsrfToken();
        $this->setFlash('success', 'Superadmin created. You can now sign in.');
        $this->redirect('/admin/login');
    }

    private function configuredSetupToken(): ?string
    {
        $environmentToken = getenv('APP_SETUP_TOKEN');
        if (is_string($environmentToken)) {
            $candidate = $environmentToken;
        } else {
            $config = $this->services['config'] ?? [];
            $candidate = is_array($config) && is_array($config['app'] ?? null)
                ? ($config['app']['setup_token'] ?? '')
                : '';
        }

        if (!is_string($candidate) || strlen($candidate) < 32) {
            return null;
        }

        return $candidate;
    }

    private function allMigrationsAreComplete(): bool
    {
        try {
            $migrationModel = new MigrationModel($this->db());

            return $migrationModel->allMigrationsAreComplete(__DIR__ . '/../migrations');
        } catch (Throwable) {
            return false;
        }
    }

    private function renderMigrationsUnavailable(): void
    {
        $this->render('setup/unavailable', [
            'title' => 'Setup unavailable',
            'message' => 'Database migrations are incomplete or could not be verified. Run migrations first (php scripts/migrate.php).',
        ]);
    }

    private function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '404 Not Found';
    }
}
