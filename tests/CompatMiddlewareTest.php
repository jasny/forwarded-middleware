<?php

namespace Jasny\Forwarded\Tests;

use Jasny\TestHelper;
use Jasny\Forwarded\CompatMiddleware;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @covers \Jasny\Forwarded\CompatMiddleware
 */
class CompatMiddlewareTest extends TestCase
{
    use TestHelper;

    /** @var MockObject&ServerRequestInterface */
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

    public function testProcess()
    {
        $this->initHandler();

        /** @var MockObject&ServerRequestInterface $request */
        $request = $this->createMock(ServerRequestInterface::class);
        $expectedArgs = [['X-Forwarded-For'], ['X-Forwarded-Proto'], ['X-Forwarded-Host'], ['X-Forwarded-Port']];
        $request->expects($this->exactly(4))->method('hasHeader')
            ->withConsecutive(...$expectedArgs)
            ->willReturnOnConsecutiveCalls(true, true, true, true);
        $request->expects($this->exactly(4))->method('getHeaderLine')
            ->withConsecutive(...$expectedArgs)
            ->willReturnOnConsecutiveCalls('30.16.61.2', 'https', 'example.com', '443');

        $request->expects($this->once())->method('withHeader')
            ->with('Forwarded', 'for=30.16.61.2;proto=https;host=example.com;port=443')
            ->willReturn($this->updatedRequest);

        $middleware = new CompatMiddleware();

        $middleware->process($request, $this->handler);
    }

    public function testDoublePass()
    {
        $this->updatedRequest = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        /** @var MockObject&ServerRequestInterface $request */
        $request = $this->createMock(ServerRequestInterface::class);
        $expectedArgs = [['X-Forwarded-For'], ['X-Forwarded-Proto'], ['X-Forwarded-Host'], ['X-Forwarded-Port']];
        $request->expects($this->exactly(4))->method('hasHeader')
            ->withConsecutive(...$expectedArgs)
            ->willReturnOnConsecutiveCalls(true, true, true, true);
        $request->expects($this->exactly(4))->method('getHeaderLine')
            ->withConsecutive(...$expectedArgs)
            ->willReturnOnConsecutiveCalls('30.16.61.2', 'https', 'example.com', '443');

        $request->expects($this->once())->method('withHeader')
            ->with('Forwarded', 'for=30.16.61.2;proto=https;host=example.com;port=443')
            ->willReturn($this->updatedRequest);

        $middleware = new CompatMiddleware();

        $next = function ($arg1 = null, $arg2 = null) use ($response) {
            $this->assertSame($arg1, $this->updatedRequest);
            $this->assertSame($arg2, $response);

            return $response;
        };

        $doublePass = $middleware->asDoublePass();
        $ret = $doublePass($request, $response, $next);

        $this->assertSame($response, $ret);
    }

    public function testWithSomeHeaders()
    {
        $this->initHandler();

        /** @var MockObject&ServerRequestInterface $request */
        $request = $this->createMock(ServerRequestInterface::class);
        $expectedArgs = [['X-Forwarded-For'], ['X-Forwarded-Proto'], ['X-Forwarded-Host'], ['X-Forwarded-Port']];
        $request->expects($this->exactly(4))->method('hasHeader')
            ->withConsecutive(...$expectedArgs)
            ->willReturnOnConsecutiveCalls(true, false, true, false);
        $request->expects($this->exactly(2))->method('getHeaderLine')
            ->withConsecutive(['X-Forwarded-For'], ['X-Forwarded-Host'])
            ->willReturnOnConsecutiveCalls('30.16.61.2', 'example.com');

        $request->expects($this->once())->method('withHeader')
            ->with('Forwarded', 'for=30.16.61.2;host=example.com')
            ->willReturn($this->updatedRequest);

        $middleware = new CompatMiddleware();

        $middleware->process($request, $this->handler);
    }

    public function testWithCustomMap()
    {
        $this->initHandler();

        /** @var MockObject&ServerRequestInterface $request */
        $request = $this->createMock(ServerRequestInterface::class);
        $expectedArgs = [['X-Client-Ip'], ['Original-Path'], ['X-Proxy-Secret']];
        $request->expects($this->exactly(3))->method('hasHeader')
            ->withConsecutive(...$expectedArgs)
            ->willReturnOnConsecutiveCalls(true, true, true);
        $request->expects($this->exactly(3))->method('getHeaderLine')
            ->withConsecutive(...$expectedArgs)
            ->willReturnOnConsecutiveCalls('30.16.61.2', '/a', 'abc');

        $request->expects($this->once())->method('withHeader')
            ->with('Forwarded', 'for=30.16.61.2;path=/a;secret=abc')
            ->willReturn($this->updatedRequest);

        $middleware = new CompatMiddleware([
            'X-Client-Ip' => 'for',
            'Original-Ip' => 'for',  // Not be used since request has an X-Client-Ip header
            'Original-Path' => 'path',
            'X-Proxy-Secret' => 'secret',
        ]);

        $middleware->process($request, $this->handler);
    }

    public function testWithMultipleForDirectives()
    {
        $this->initHandler();

        /** @var MockObject&ServerRequestInterface $request */
        $request = $this->createMock(ServerRequestInterface::class);
        $expectedArgs = [['X-Forwarded-For'], ['X-Forwarded-Proto'], ['X-Forwarded-Host'], ['X-Forwarded-Port']];
        $request->expects($this->exactly(4))->method('hasHeader')
            ->withConsecutive(...$expectedArgs)
            ->willReturnOnConsecutiveCalls(true, true, true, true);
        $request->expects($this->exactly(4))->method('getHeaderLine')
            ->withConsecutive(...$expectedArgs)
            ->willReturnOnConsecutiveCalls('30.16.61.2, 10.0.0.2, 10.0.0.1', 'https', 'example.com', '443');

        $request->expects($this->once())->method('withHeader')
            ->with('Forwarded', 'for=30.16.61.2;proto=https;host=example.com;port=443, for=10.0.0.2, for=10.0.0.1')
            ->willReturn($this->updatedRequest);

        $middleware = new CompatMiddleware();

        $middleware->process($request, $this->handler);
    }

    public function testWithoutForwarding()
    {
        $this->initHandler();

        /** @var MockObject&ServerRequestInterface $request */
        $request = $this->createMock(ServerRequestInterface::class);
        $expectedArgs = [['X-Forwarded-For'], ['X-Forwarded-Proto'], ['X-Forwarded-Host'], ['X-Forwarded-Port']];
        $request->expects($this->exactly(4))->method('hasHeader')
            ->withConsecutive(...$expectedArgs)
            ->willReturn(false);
        $request->expects($this->never())->method('getHeaderLine');

        $request->expects($this->once())->method('withoutHeader')
            ->with('Forwarded')
            ->willReturn($this->updatedRequest);

        $middleware = new CompatMiddleware();

        $middleware->process($request, $this->handler);
    }

    public function testWithIPv6()
    {
        $this->initHandler();

        /** @var MockObject&ServerRequestInterface $request */
        $request = $this->createMock(ServerRequestInterface::class);
        $expectedArgs = [['X-Forwarded-For'], ['X-Forwarded-Proto'], ['X-Forwarded-Host'], ['X-Forwarded-Port']];
        $request->expects($this->exactly(4))->method('hasHeader')
            ->withConsecutive(...$expectedArgs)
            ->willReturnOnConsecutiveCalls(true, false, true, false);
        $request->expects($this->exactly(2))->method('getHeaderLine')
            ->withConsecutive(['X-Forwarded-For'])
            ->willReturnOnConsecutiveCalls('2001:db8:cafe::17, 2001:db8:cafe::18', 'example.com:80');

        $request->expects($this->once())->method('withHeader')
            ->with('Forwarded', 'for="[2001:db8:cafe::17]";host="example.com:80", for="[2001:db8:cafe::18]"')
            ->willReturn($this->updatedRequest);

        $middleware = new CompatMiddleware();

        $middleware->process($request, $this->handler);
    }

    public function testOverwriteForwardedHeader()
    {
        $this->initHandler();

        /** @var MockObject&ServerRequestInterface $request */
        $request = $this->createMock(ServerRequestInterface::class);
        $expectedArgs = [['X-Forwarded-For'], ['X-Forwarded-Proto'], ['X-Forwarded-Host'], ['X-Forwarded-Port']];
        $request->expects($this->exactly(4))->method('hasHeader')
            ->withConsecutive(...$expectedArgs)
            ->willReturnOnConsecutiveCalls(true, false, false, false);
        $request->expects($this->once())->method('getHeaderLine')
            ->with('X-Forwarded-For')
            ->willReturn('30.16.61.2');

        $request->expects($this->once())->method('withHeader')
            ->with('Forwarded', 'for=30.16.61.2')
            ->willReturn($this->updatedRequest);

        $middleware = new CompatMiddleware();

        $middleware->process($request, $this->handler);
    }
}
