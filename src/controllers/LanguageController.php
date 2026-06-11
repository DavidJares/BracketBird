<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Language;

final class LanguageController extends BaseController
{
    public function switch(): void
    {
        $language = Language::normalize($this->requestPostString('lang'));
        $this->storeLanguageCookie($language);
        $this->redirect($this->safeRedirectPath($this->requestPostString('redirect')));
    }

    private function storeLanguageCookie(string $language): void
    {
        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || ((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $cookiePath = $this->basePath() !== '' ? $this->basePath() : '/';

        setcookie(Language::COOKIE_NAME, $language, [
            'expires' => time() + 31536000,
            'path' => $cookiePath,
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $_COOKIE[Language::COOKIE_NAME] = $language;
    }

    private function safeRedirectPath(string $rawRedirect): string
    {
        $fallback = '/admin/dashboard';
        $rawRedirect = trim($rawRedirect);
        if ($rawRedirect === '' || str_contains($rawRedirect, "\r") || str_contains($rawRedirect, "\n")) {
            return $fallback;
        }

        $parts = parse_url($rawRedirect);
        if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host'])) {
            return $fallback;
        }

        $path = $parts['path'] ?? '/';
        if (!is_string($path) || $path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
            return $fallback;
        }

        $basePath = $this->basePath();
        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $path = substr($path, strlen($basePath));
            if ($path === '') {
                $path = '/';
            }
        }

        $query = $parts['query'] ?? '';
        return is_string($query) && $query !== '' ? $path . '?' . $query : $path;
    }
}
