<?php

namespace Jasny\Forwarded\Tests;

use Jasny\TestHelper;
use Jasny\Forwarded\Middleware;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @covers \Jasny\Forwarded\Middleware
 */
class MiddlewareTest extends TestCase
{
    use TestHelper;

    /** @var MockObject&ServerRequestInterface  */
    protected $updatedRequest;
    /** @var MockObject&RequestHandlerInterface */
    protected $handler;

    protected function initHandler(): void
    {
        $this->updatedRequest = $this->createMock(ServerRequestInterface::class);
        $this->updatedRequest->expects($this->never())->method('withAttribute');

        $this->handler = $this->createMock(RequestHandlerInterface::class);
        $this->handler->expects($this->once())->method('handle')
            ->with($this->identicalTo($this->updatedRequest))
            ->willReturn($this->createMock(ResponseInterface::class));
    }

    protected function createMockRequest(string $clientIp, string $forwardedHeader, array $directives = [])
    {
        /** @var MockObject&ServerRequestInterface $request */
        $request = $this->createMock(ServerRequestInterface::class);

        $request->expects($this->once())->method('getServerParams')
            ->willReturn(['REMOTE_ADDR' => $clientIp]);
        $request->expects($this->once())->method('getHeaderLine')
            ->with('Forwarded')
            ->willReturn($forwardedHeader);

        /** @var MockObject&UriInterface $uri */
        $uri = $this->createMock(UriInterface::class);
        $request->expects($this->once())->method('getUri')->willReturn($uri);

        $uri->expects($this->once())->method('withScheme')->with($directives['proto'] ?? 'https')->willReturnSelf();
        $uri->expects($this->once())->method('withHost')->with($directives['host'] ?? 'example.com')->willReturnSelf();
        $uri->expects($this->once())->method('withPort')->with($directives['port'] ?? 111)->willReturnSelf();
        $uri->expects($this->once())->method('withPath')->with($directives['path'] ?? '/a')->willReturnSelf();

        $request->expects($this->exactly(2))->method('withAttribute')
            ->withConsecutive(
                ['client_ip', '30.16.61.2'],
                ['original_uri', $uri]
            )
            ->willReturnOnConsecutiveCalls($this->returnSelf(), $this->updatedRequest);

        return $request;
    }

    public function proxyProvider()
    {
        $first = 'for=30.16.61.2;proto=https;host=example.com;port=111;path=/a;secret=X';
        $second = 'for=10.0.0.2;path=/b;secret=X';
        $third = 'for=10.0.0.2;path=/c;secret=X';

        $ignored = 'for=18.150.148.11;path=/q';

        return [
            'one proxy' => ['10.0.0.1', $first],
            'two proxies' => ['10.0.0.1', join(',', [$first, $second])],
            'three proxies' => ['10.0.0.1', join(',', [$first, $second, $third])],
            'untrusted proxy' => ['10.0.0.1', join(',', [$ignored, $first, $second])],

            'quotes' => ['10.0.0.1', 'for="30.16.61.2";proto=https;host="example.com";port=111;path=/a;secret=X'],
            'spaces' => [
                '10.0.0.1',
                ' for = 30.16.61.2 ; proto = https;host =example.com;secret= X ; port = 111 ; path = "/a" '
            ],
        ];
    }

    /**
     * @dataProvider proxyProvider
     */
    public function testProcess(string $clientIp, string $forwardedHeader)
    {
        $this->initHandler();
        $request = $this->createMockRequest($clientIp, $forwardedHeader);

        $middleware = new Middleware(function (string $by): bool {
            return preg_replace('/\.\d+$/', '', $by) === '10.0.0';
        });

        $middleware->process($request, $this->handler);
    }

    /**
     * @dataProvider proxyProvider
     */
    public function testDoublePass(string $clientIp, string $forwardedHeader)
    {
        $this->updatedRequest = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $request = $this->createMockRequest($clientIp, $forwardedHeader);

        $middleware = new Middleware(function (string $by): bool {
            return preg_replace('/\.\d+$/', '', $by) === '10.0.0';
        });

        $next = function ($arg1 = null, $arg2 = null) use ($response) {
            $this->assertSame($arg1, $this->updatedRequest);
            $this->assertSame($arg2, $response);

            return $response;
        };

        $doublePass = $middleware->asDoublePass();
        $ret = $doublePass($request, $response, $next);

        $this->assertSame($response, $ret);
    }

    /**
     * @dataProvider proxyProvider
     */
    public function testTrustBySecret(string $clientIp, string $forwardedHeader)
    {
        $this->initHandler();
        $request = $this->createMockRequest($clientIp, $forwardedHeader);

        $middleware = new Middleware(function ($_, array $forward): bool {
            return ($forward['secret'] ?? null) === 'X';
        });

        $middleware->process($request, $this->handler);
    }

    public function testWithUntrustedClient()
    {
        $this->initHandler();

        /** @var MockObject&ServerRequestInterface $request */
        $request = $this->createMock(ServerRequestInterface::class);

        $request->expects($this->once())->method('getServerParams')
            ->willReturn(['REMOTE_ADDR' => '30.16.61.2']);
        $request->expects($this->once())->method('getHeaderLine')
            ->with('Forwarded')
            ->willReturn('for=18.150.148.11;path=/q');

        /** @var MockObject&UriInterface $uri */
        $uri = $this->createMock(UriInterface::class);
        $request->expects($this->once())->method('getUri')->willReturn($uri);

        $uri->expects($this->never())->method('withScheme');
        $uri->expects($this->never())->method('withHost');
        $uri->expects($this->never())->method('withPort');
        $uri->expects($this->never())->method('withPath');

        $request->expects($this->exactly(2))->method('withAttribute')
            ->withConsecutive(
                ['client_ip', '30.16.61.2'],
                ['original_uri', $uri]
            )
            ->willReturnOnConsecutiveCalls($this->returnSelf(), $this->updatedRequest);

        $middleware = new Middleware(function (string $by): bool {
            return preg_replace('/\.\d+$/', '', $by) === '10.0.0';
        });

        $middleware->process($request, $this->handler);
    }

    public function portProvider()
    {
        return [
            'http' => ['http', 80],
            'https' => ['https', 443],
        ];
    }

    public function testWithoutForwardedHeader()
    {
        $this->initHandler();

        /** @var MockObject&ServerRequestInterface $request */
        $request = $this->createMock(ServerRequestInterface::class);

        $request->expects($this->once())->method('getServerParams')
            ->willReturn(['REMOTE_ADDR' => '30.16.61.2']);
        $request->expects($this->once())->method('getHeaderLine')
            ->with('Forwarded')
            ->willReturn('');

        /** @var MockObject&UriInterface $uri */
        $uri = $this->createMock(UriInterface::class);
        $request->expects($this->once())->method('getUri')->willReturn($uri);

        $uri->expects($this->never())->method('withScheme');
        $uri->expects($this->never())->method('withHost');
        $uri->expects($this->never())->method('withPort');
        $uri->expects($this->never())->method('withPath');

        $request->expects($this->exactly(2))->method('withAttribute')
            ->withConsecutive(
                ['client_ip', '30.16.61.2'],
                ['original_uri', $uri]
            )
            ->willReturnOnConsecutiveCalls($this->returnSelf(), $this->updatedRequest);

        $middleware = new Middleware(function (string $by): bool {
            return preg_replace('/\.\d+$/', '', $by) === '10.0.0';
        });

        $middleware->process($request, $this->handler);
    }

    /**
     * @dataProvider portProvider
     */
    public function testWithDefaultPort(string $proto, int $port)
    {
        $this->initHandler();

        $header = "for=30.16.61.2;proto=$proto;host=example.com;port=$port;path=/a";
        $request = $this->createMockRequest('10.0.0.1', $header, ['proto' => $proto, 'port' => $port]);

        $middleware = new Middleware(function (string $by): bool {
            return preg_replace('/\.\d+$/', '', $by) === '10.0.0';
        });

        $middleware->process($request, $this->handler);
    }
}
