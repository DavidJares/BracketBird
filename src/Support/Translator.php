<?php

declare(strict_types=1);

namespace App\Support;

final class Translator
{
    /**
     * @var array<string, array<string, string>>
     */
    private static array $catalogues = [];

    private string $language;

    public function __construct(string $language)
    {
        $this->language = Language::normalize($language);
    }

    /**
     * @param array<string, string|int|float> $params
     */
    public function translate(string $key, array $params = []): string
    {
        $catalogue = self::catalogue($this->language);
        $fallbackCatalogue = self::catalogue(Language::DEFAULT_LANGUAGE);
        $value = $catalogue[$key] ?? $fallbackCatalogue[$key] ?? $key;

        foreach ($params as $name => $replacement) {
            $value = str_replace('{' . $name . '}', (string) $replacement, $value);
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private static function catalogue(string $language): array
    {
        $language = Language::normalize($language);
        if (isset(self::$catalogues[$language])) {
            return self::$catalogues[$language];
        }

        $path = __DIR__ . '/../../resources/lang/' . $language . '.json';
        if (!is_file($path)) {
            self::$catalogues[$language] = [];
            return self::$catalogues[$language];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            self::$catalogues[$language] = [];
            return self::$catalogues[$language];
        }

        $catalogue = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $catalogue[$key] = $value;
            }
        }

        self::$catalogues[$language] = $catalogue;
        return self::$catalogues[$language];
    }
}
