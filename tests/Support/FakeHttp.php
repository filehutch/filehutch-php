<?php

declare(strict_types=1);

namespace FileHutch\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that answers from a route table ("METHOD url") and records
 * every request. An unstubbed request fails the test loudly.
 */
final class FakeHttp implements ClientInterface
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    public array $calls = [];

    /** @var array<string, list<ResponseInterface|\Throwable>> */
    private array $routes = [];

    /** @param array<string, ResponseInterface|\Throwable|list<ResponseInterface|\Throwable>> $routes */
    public function __construct(array $routes = [])
    {
        foreach ($routes as $key => $value) {
            $this->on($key, $value);
        }
    }

    /** @param ResponseInterface|\Throwable|list<ResponseInterface|\Throwable> $response */
    public function on(string $key, ResponseInterface|\Throwable|array $response): self
    {
        $this->routes[$key] = is_array($response) ? $response : [$response];

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }
        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $this->calls[] = [
            'method' => $request->getMethod(),
            'url' => (string) $request->getUri(),
            'headers' => $headers,
            'body' => (string) $body,
        ];

        $key = $request->getMethod() . ' ' . $request->getUri();
        $queue = $this->routes[$key] ?? [];
        if ($queue === []) {
            throw new \LogicException("Unstubbed request: {$key}");
        }
        $answer = count($queue) > 1 ? array_shift($this->routes[$key]) : $queue[0];
        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return $answer;
    }

    /** @return list<string> */
    public function requested(): array
    {
        return array_map(static fn ($c) => "{$c['method']} {$c['url']}", $this->calls);
    }

    /** @return array<string, mixed> */
    public function json(int $index): array
    {
        return json_decode($this->calls[$index]['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    public static function json200(mixed $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    public static function text(int $status, string $text = ''): Response
    {
        return new Response($status, [], $text);
    }
}
