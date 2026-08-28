<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\SuperadminModel;

final class AuthController extends BaseController
{
    public function loginForm(): void
    {
        $superadminModel = new SuperadminModel($this->db());
        if (!$superadminModel->tableExists() || !$superadminModel->hasAny()) {
            $this->redirect('/setup');
        }

        if ($this->currentSuperadmin() !== null) {
            $this->redirect('/admin/dashboard');
        }

        $this->render('admin/login', [
            'title' => 'Superadmin login',
        ]);
    }

    public function login(): void
    {
        $superadminModel = new SuperadminModel($this->db());
        if (!$superadminModel->tableExists() || !$superadminModel->hasAny()) {
            $this->redirect('/setup');
        }

        $rateScope = 'superadmin';
        if (!$this->reserveLoginAttempt($rateScope)) {
            $this->setFlash('error', 'Invalid credentials.');
            $this->redirect('/admin/login');
        }

        $username = $this->requestPostString('username');
        $password = $this->requestPostRawString('password');

        $superadmin = $username !== '' && strlen($username) <= 100
            ? $superadminModel->findByUsername($username)
            : null;
        $passwordHash = is_array($superadmin) ? (string) ($superadmin['password_hash'] ?? '') : null;
        $passwordVerified = $this->verifyPasswordOrDummy($password, $passwordHash);
        if ($superadmin === null || !$passwordVerified) {
            $this->setFlash('error', 'Invalid credentials.');
            $this->redirect('/admin/login');
        }

        if (password_needs_rehash($passwordHash, PASSWORD_DEFAULT)) {
            $superadminModel->rehashPassword((int) $superadmin['id'], $password);
        }

        $this->resetLoginThrottle($rateScope);
        $this->establishAuthentication('superadmin', [
            'id' => (int) $superadmin['id'],
            'username' => (string) $superadmin['username'],
        ]);
        $this->logSecurityEvent('superadmin_login_succeeded', [
            'superadmin_id' => (int) $superadmin['id'],
        ]);

        $this->setFlash('success', 'You are signed in.');
        $this->redirect('/admin/dashboard');
    }

    public function logout(): void
    {
        $superadmin = $this->currentSuperadmin();
        $this->endAuthentication();
        if (is_array($superadmin)) {
            $this->logSecurityEvent('superadmin_logout', [
                'superadmin_id' => (int) $superadmin['id'],
            ]);
        }

        $this->setFlash('success', 'You are signed out.');
        $this->redirect('/admin/login');
    }
}
