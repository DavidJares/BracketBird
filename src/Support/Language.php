<?php

declare(strict_types=1);

namespace App\Support;

final class Language
{
    public const COOKIE_NAME = 'bracketbird_lang';
    public const DEFAULT_LANGUAGE = 'en';

    /**
     * @return array<string, array{label: string, native_label: string, short_label: string}>
     */
    public static function available(): array
    {
        $languages = require __DIR__ . '/../Config/languages.php';
        if (!is_array($languages)) {
            return [];
        }

        $normalized = [];
        foreach ($languages as $code => $meta) {
            if (!is_string($code) || !is_array($meta)) {
                continue;
            }

            $normalizedCode = strtolower(trim($code));
            if ($normalizedCode === '') {
                continue;
            }

            $label = $meta['label'] ?? '';
            $nativeLabel = $meta['native_label'] ?? '';
            $shortLabel = $meta['short_label'] ?? '';
            if (!is_string($label) || !is_string($nativeLabel) || !is_string($shortLabel)) {
                continue;
            }

            $normalized[$normalizedCode] = [
                'label' => $label,
                'native_label' => $nativeLabel,
                'short_label' => strtoupper($shortLabel),
            ];
        }

        return $normalized;
    }

    public static function normalize(?string $language): string
    {
        $language = strtolower(trim((string) $language));
        $languages = self::available();

        return isset($languages[$language]) ? $language : self::DEFAULT_LANGUAGE;
    }

    /**
     * @param array<string, mixed>|null $cookies
     * @param array<string, mixed>|null $server
     */
    public static function resolve(?array $cookies = null, ?array $server = null): string
    {
        $cookies ??= $_COOKIE;
        $server ??= $_SERVER;

        $cookieLanguage = $cookies[self::COOKIE_NAME] ?? null;
        if (is_string($cookieLanguage)) {
            return self::normalize($cookieLanguage);
        }

        $acceptLanguage = $server['HTTP_ACCEPT_LANGUAGE'] ?? '';
        if (is_string($acceptLanguage) && preg_match('/^\s*cs(?:-|,|;|$)/i', $acceptLanguage) === 1) {
            return 'cs';
        }

        return self::DEFAULT_LANGUAGE;
    }
}
