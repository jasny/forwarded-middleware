<?php

declare(strict_types=1);

namespace Jasny\Forwarded;

use Psr\Http\Message\ServerRequestInterface as ServerRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Middleware to convert X-Forwarded-* headers to Forwarded header.
 * Can be used both as single pass (PSR-15) and double pass middleware.
 */
class CompatMiddleware implements MiddlewareInterface
{
    protected const DEFAULT_MAP = [
        'X-Forwarded-For' => 'for',
        'X-Forwarded-Proto' => 'proto',
        'X-Forwarded-Host' => 'host',
        'X-Forwarded-Port' => 'port',
    ];

    /**
     * Map header to directive.
     * @var array
     */
    protected $map;

    /**
     * CompatMiddleware constructor.
     *
     * @param array|null $map  Map header to directive.
     */
    public function __construct(?array $map = null)
    {
        $this->map = $map ?? self::DEFAULT_MAP;
    }

    /**
     * Process an incoming server request (PSR-15).
     *
     * @param ServerRequest $request
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
        $directives = [];

        foreach ($this->map as $header => $directive) {
            if (isset($directives[$directive]) || !$request->hasHeader($header)) {
                continue;
            }

            $directives[$directive] = $request->getHeaderLine($header);
        }

        $forwarded = $this->createForwardedHeader($directives);

        return $forwarded !== ''
            ? $request->withHeader('Forwarded', $forwarded)
            : $request->withoutHeader('Forwarded');
    }

    /**
     * Create a Forwarded header from directives.
     *
     * @param array $directives
     * @return string
     */
    protected function createForwardedHeader(array $directives): string
    {
        // Multiple 'for' ips. Other directives apply to first proxy.
        if (isset($directives['for']) && strpos($directives['for'], ',') !== false) {
            $for = array_map('trim', explode(',', $directives['for']));
            $directives['for'] = array_shift($for);
        } else {
            $for = [];
        }

        $pair = static function (string $key, string $value): string {
            if ((bool)preg_match('/^([A-f0-9:]+:+)+[A-f0-9]+$/i', $value)) { // IPv6
                $value = sprintf('"[%s]"', $value);
            } elseif ((bool)preg_match('/[,;:=]/', $value)) {
                $value = sprintf('"%s"', $value);
            }

            return "$key=$value";
        };

        $header = join(';', array_map($pair, array_keys($directives), array_values($directives)));

        foreach ($for as $forIp) {
            $header .= ", " . $pair('for', $forIp);
        }

        return $header;
    }
}
