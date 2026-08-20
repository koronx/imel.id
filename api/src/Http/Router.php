<?php

namespace Imel\Api\Http;

use Imel\Api\ApiException;

/**
 * Tiny routing table: METHOD + pattern with {placeholders} => handler.
 */
final class Router
{
    /** @var array<int,array{method:string,regex:string,keys:string[],handler:callable,auth:bool}> */
    private array $routes = [];

    public function get(string $path, callable $handler, bool $auth = true): void
    {
        $this->add('GET', $path, $handler, $auth);
    }

    public function post(string $path, callable $handler, bool $auth = true): void
    {
        $this->add('POST', $path, $handler, $auth);
    }

    public function put(string $path, callable $handler, bool $auth = true): void
    {
        $this->add('PUT', $path, $handler, $auth);
    }

    public function patch(string $path, callable $handler, bool $auth = true): void
    {
        $this->add('PATCH', $path, $handler, $auth);
    }

    public function delete(string $path, callable $handler, bool $auth = true): void
    {
        $this->add('DELETE', $path, $handler, $auth);
    }

    private function add(string $method, string $path, callable $handler, bool $auth): void
    {
        $keys = [];
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_]+)\}/',
            static function (array $m) use (&$keys): string {
                $keys[] = $m[1];
                return '([^/]+)';
            },
            '/' . trim($path, '/')
        );

        $this->routes[] = [
            'method'  => $method,
            'regex'   => '#^' . $regex . '$#',
            'keys'    => $keys,
            'handler' => $handler,
            'auth'    => $auth,
        ];
    }

    /** @return array{handler:callable,auth:bool}|null */
    public function match(Request $request): ?array
    {
        $path = $request->path();
        $allowedForPath = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            $allowedForPath[] = $route['method'];
            if ($route['method'] !== $request->method()) {
                continue;
            }

            array_shift($matches);
            $request->params = array_combine($route['keys'], $matches) ?: [];
            return ['handler' => $route['handler'], 'auth' => $route['auth']];
        }

        if ($allowedForPath) {
            throw new ApiException('Method not allowed', 405);
        }
        return null;
    }
}
