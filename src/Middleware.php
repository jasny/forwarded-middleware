<?php

declare(strict_types=1);

namespace Jasny\Forwarded;

use Psr\Http\Message\ServerRequestInterface as ServerRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Middleware to handle Forwarded header for PSR-7 server requests.
 * Can be used both as single pass (PSR-15) and double pass middleware.
 *
 * Set the `client_ip` and `original_uri` request attributes.
 */
class Middleware implements MiddlewareInterface
{
    /**
     * Logic to check if a proxy is trusted.
     * @var \Closure
     */
    protected $trust;

    /**
     * ServerMiddleware constructor.
     *
     * @param callable $trust Logic to check if a proxy is trusted.
     */
    public function __construct(callable $trust)
    {
        $this->trust = \Closure::fromCallable($trust);
    }

    /**
     * Process an incoming server request (PSR-15).
     *
     * @param ServerRequest  $request
     * @param RequestHandler $handler
     * @return Response
     */
    public function process(ServerRequest $request, RequestHandler $handler): Response
    {
        $updatedRequest = $this->apply($request);

        return $handler->handle($updatedRequest);
    }

    /**
     * Get a callback that can be used as double pass middleware.
     *
     * @return callable
     */
    public function asDoublePass(): callable
    {
        return function (ServerRequest $request, Response $response, callable $next): Response {
            $updatedRequest = $this->apply($request);
            return $next($updatedRequest, $response);
        };
    }


    /**
     * Apply `Forwarded` header to server request.
     *
     * @param ServerRequest $request
     * @return ServerRequest
     */
    protected function apply(ServerRequest $request): ServerRequest
    {
        $clientIp = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
        $forwards = $this->parseForwarded($request->getHeaderLine('Forwarded'));

        $trustedForward = $this->getTrustedForward($clientIp, $forwards);

        return $this->applyForward($request, $trustedForward);
    }

    /**
     * Parse the forwarded header
     *
     * @param string $header  Forwarded header
     * @return array
     */
    protected function parseForwarded(string $header): array
    {
        if ($header === '') {
            return [];
        }

        $forwards = [];
        preg_match_all('/(?:[^",]++|"[^"]++")+/', $header, $matches);

        foreach ($matches[0] as $part) {
            $regex = '/(?P<key>\w+)\s*=\s*(?|(?P<value>[^",;]*[^",;\s])|"(?P<value>[^"]+)")/';
            preg_match_all($regex, $part, $matches, PREG_SET_ORDER);

            $pairs = array_map(static function (array $match) {
                return [$match['key'] => $match['value']];
            }, $matches);

            $forwards[] = array_merge(...$pairs);
        }

        return $forwards;
    }

    /**
     * Iterate over forwards while trusted.
     *
     * @param string $clientIp
     * @param array  $forwards
     * @return array
     */
    protected function getTrustedForward(string $clientIp, array $forwards): array
    {
        $trusted = ['for' => $clientIp];

        foreach (array_reverse($forwards) as $forward) {
            if (!($this->trust)($trusted['for'] ?? 'unknown', $forward)) {
                break;
            }

            $trusted = $forward;
        }

        return $trusted;
    }

    /**
     * Apply trusted forward to server request.
     *
     * @param ServerRequest $request
     * @param string[]      $forward
     * @return ServerRequest
     */
    protected function applyForward(ServerRequest $request, array $forward): ServerRequest
    {
        $uri = $request->getUri();

        if (isset($forward['proto'])) {
            $uri = $uri->withScheme($forward['proto']);
        }
        if (isset($forward['host'])) {
            $uri = $uri->withHost($forward['host']);
        }
        if (isset($forward['port']) && ctype_digit($forward['port'])) {
            $port = (int)$forward['port'];
            $defaultPort = ['http' => 80, 'https' => 443][$uri->getScheme()] ?? null;

            $uri = $uri->withPort($port !== $defaultPort ? $port : null);
        }
        if (isset($forward['path'])) {
            $uri = $uri->withPath($forward['path']);
        }

        return $request
            ->withAttribute('client_ip', $forward['for'] ?? null)
            ->withAttribute('original_uri', $uri);
    }
}
