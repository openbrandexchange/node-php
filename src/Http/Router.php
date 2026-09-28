<?php

declare(strict_types=1);

namespace Obx\Node\Http;

/**
 * Minimal path router. Patterns use {name} placeholders for one path segment; values are
 * percent-decoded. Matching is exact (no trailing-slash tolerance beyond a single "/" root).
 */
final class Router
{
    /** @var list<array{method: string, pattern: string, regex: string, names: list<string>, handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $names = [];
        $regex = preg_replace_callback('/\\\{([a-z0-9_]+)\\\}/', static function (array $m) use (&$names): string {
            $names[] = $m[1];
            return '([^/]+)';
        }, preg_quote($pattern, '#'));
        $this->routes[] = ['method' => $method, 'pattern' => $pattern, 'regex' => '#^' . $regex . '$#', 'names' => $names, 'handler' => $handler];
    }

    /**
     * @return array{0: callable, 1: array<string, string>, 2: string}|null  handler, params, pattern; null when no path matched
     * @throws ApiError method_not_allowed when the path matched with another method
     */
    public function match(string $method, string $path): ?array
    {
        $allowed = [];
        foreach ($this->routes as $r) {
            if (!preg_match($r['regex'], $path, $m)) {
                continue;
            }
            if ($r['method'] !== $method && !($method === 'HEAD' && $r['method'] === 'GET')) {
                $allowed[] = $r['method'];
                continue;
            }
            $params = [];
            foreach ($r['names'] as $i => $name) {
                $params[$name] = rawurldecode($m[$i + 1]);
            }
            return [$r['handler'], $params, $r['pattern']];
        }
        if ($allowed !== []) {
            // Prefer an exact HEAD route registered later (blobs); otherwise 405.
            throw new ApiError('method_not_allowed', 'Use ' . implode(', ', array_unique($allowed)) . ' on this path.', [], null, ['Allow' => implode(', ', array_unique($allowed))]);
        }
        return null;
    }
}
