<?php

declare(strict_types=1);

namespace App;

final class Router
{
    private $notFoundHandler = null;

    /**
     * @var array<string, array<string, callable>>
     */
    private array $staticRoutes = [];

    /**
     * @var array<string, list<array{
     *     path: string,
     *     handler: callable,
     *     regex: string,
     *     param_names: list<string>
     * }>>
     */
    private array $dynamicRoutes = [];

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function add(string $method, string $path, callable $handler): void
    {
        $normalizedPath = $this->normalizePath($path);
        $normalizedMethod = strtoupper($method);
        $compiled = $this->compileDynamicPattern($normalizedPath);

        if ($compiled === null) {
            $this->staticRoutes[$normalizedMethod][$normalizedPath] = $handler;
            return;
        }

        $this->dynamicRoutes[$normalizedMethod][] = [
            'path' => $normalizedPath,
            'handler' => $handler,
            'regex' => $compiled['regex'],
            'param_names' => $compiled['param_names'],
        ];
    }

    public function setNotFoundHandler(callable $handler): void
    {
        $this->notFoundHandler = $handler;
    }

    public function dispatch(string $method, string $path): void
    {
        $normalizedPath = $this->normalizePath($path);
        $normalizedMethod = strtoupper($method);
        $_SERVER['_route_params'] = [];
        $handler = $this->staticRoutes[$normalizedMethod][$normalizedPath] ?? null;

        if ($handler === null) {
            $dynamicRoutes = $this->dynamicRoutes[$normalizedMethod] ?? [];
            foreach ($dynamicRoutes as $route) {
                $matches = [];
                if (preg_match($route['regex'], $normalizedPath, $matches) !== 1) {
                    continue;
                }

                $params = [];
                foreach ($route['param_names'] as $index => $name) {
                    $value = $matches[$index + 1] ?? null;
                    if (!is_string($value) || $value === '') {
                        continue;
                    }

                    $params[$name] = $value;
                }

                $_SERVER['_route_params'] = $params;
                $handler = $route['handler'];
                break;
            }
        }

        if ($handler === null) {
            if (is_callable($this->notFoundHandler)) {
                ($this->notFoundHandler)();
                return;
            }

            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            echo '404 Not Found';
            return;
        }

        $handler();
    }

    private function normalizePath(string $path): string
    {
        $normalizedPath = '/' . trim($path, '/');
        return $normalizedPath === '//' ? '/' : $normalizedPath;
    }

    /**
     * @return array{regex: string, param_names: list<string>}|null
     */
    private function compileDynamicPattern(string $path): ?array
    {
        if (strpos($path, '{') === false) {
            return null;
        }

        $paramNames = [];
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $matches) use (&$paramNames): string {
                $paramNames[] = $matches[1];
                return '([^/]+)';
            },
            $path
        );

        if (!is_string($pattern) || $pattern === '') {
            return null;
        }

        return [
            'regex' => '#^' . $pattern . '$#',
            'param_names' => $paramNames,
        ];
    }
}
