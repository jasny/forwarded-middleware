Forwarded Middleware
===

[![Build Status](https://travis-ci.org/jasny/forwarded-middleware.svg?branch=master)](https://travis-ci.org/jasny/forwarded-middleware)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/jasny/forwarded-middleware/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/jasny/forwarded-middleware/?branch=master)
[![Code Coverage](https://scrutinizer-ci.com/g/jasny/forwarded-middleware/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/jasny/forwarded-middleware/?branch=master)
[![Packagist Stable Version](https://img.shields.io/packagist/v/jasny/forwarded-middleware.svg)](https://packagist.org/packages/jasny/forwarded-middleware)
[![Packagist License](https://img.shields.io/packagist/l/jasny/forwarded-middleware.svg)](https://packagist.org/packages/jasny/forwarded-middleware)

Server middleware to process the `Forwarded` header for PSR-7 requests. Works both as PSR-15 and double pass middleware.

The middleware set the `client_ip` and `original_url` attributes for the server request. Also supports
non-standard `X-Forwarded-*` and other custom headers. 

Installation
---

    composer require jasny/forwarded-middleware

Usage
---

```php
use Wikimedia\IPSet;
use Jasny\Forwarded;
use Zend\Stratigility\MiddlewarePipe;
use Zend\Diactoros\ResponseFactory;

$trustIps = new IPSet(['208.80.154.0/26', '2620:0:861:1::/64', '10.64.0.0/22']);

$middleware = new Forwarded\Middleware(function (string $ip, array $forward) use ($trustedIps) {
    return $trustedIps->match($ip);
});

$app = new MiddlewarePipe();
$app->pipe($middleware);
```

#### Trusted proxies

The constructor takes a callback as only argument, which is called for each forward (separated by a comma) in the
`Forwarded` header. Each forward should have a `for` directive and may have other directives like `port` and `proto`.

The first argument of the callback is the ip of the proxy server. The second argument contains the directives of this
forward as associative array.

The initial value of the ip is taken from the `REMOTE_ADDR` server parameter. For each subsequent call gives the value
of the `for` directive from the previous trusted forward.

> **Example**
> 
>     Forwarded: for=75.84.3.2,for=92.53.34.1,for=32.5.86.102,for=10.64.0.23
>     Client IP (REMOTE_ADDR) is 208.80.154.8 
>
> This results in the following calls
> 
> ```php
> fn("208.80.154.8", ['for' => "10.64.0.23"]);  // true
> fn("10.64.0.23", ['for' => "32.5.86.102"]);   // true
> fn("32.5.86.102", ['for' => "92.53.34.1"]);   // false
> ```
> 
> The `client_id` is set to `"32.5.86.102"`. Note that the `for=75.84.3.2` forward isn't considered.

It's not required to trust based on IP. Alternatively you can check if the proxy has set a secret.

```php
$middleware = new Forwarded\Middleware(function (string $ip, array $forward) {
    return $forward === getenv('PROXY_SECRET');
});
```

#### Original uri

Besides `client_ip`, the middleware will also set the `original_uri` attribute. This attribute is a PSR-7 URI object
based on the URI of the request.

The `proto` and `host`, as well as the non-standard `path` and `port` directives are applied to create the original uri.
If the `port` is the standard port for the `proto` (80 for "http" and 443 for https), it's omitted.

Only the directives of the last trusted proxy are used;

> **Example**
> 
>     HTTP/1.1 GET /foo
>     Host: x9.example.com
>     Forwarded: for=92.53.34.1, for=32.5.86.102;proto=https;port=443;host=example.com;path=/x/foo, for=10.64.0.23;proto=http;port=8080
>
> The `original_uri` attribute will be "https://example.com/x/foo"

The uri of the server request is not altered.

### Non-standard headers

Use `CompatMiddleware` in case your proxy sets `X-Forwarded-*` headers. This middleware will convert these headers to a
`Forwarded` header.

```php
use Wikimedia\IPSet;
use Jasny\Forwarded;
use Zend\Stratigility\MiddlewarePipe;
use Zend\Diactoros\ResponseFactory;

$trustIps = new IPSet(['208.80.154.0/26', '2620:0:861:1::/64', '10.64.0.0/22']);

$compatMiddleware = new Forwarded\CompatMiddleware();
$middleware = new Forwarded\Middleware(function (string $ip, array $forward) use ($trustedIps) {
    return $trustedIps->match($ip);
});

$app = new MiddlewarePipe();
$app->pipe($compatMiddleware);
$app->pipe($middleware);
```

By default the compat middleware uses the following headers

- `X-Forwared-For`
- `X-Forwarded-Proto`
- `X-Forwarded-Host`

#### Custom headers

If your proxy uses other headers, you can pass custom mapping to constructor 

```php
$compatMiddleware = new Forwarded\CompatMiddleware([
    'X-Client-IP' => 'for,
    'X-Forwarded-For' => 'for',
    'X-Forwarded-Proto' => 'proto',
    'X-Forwarded-Port' => 'port',
]);
```

If there are multiple headers for the same directive, the first header that's found is used. In the example above, if
there is an `X-Client-IP`, the `X-Forwarded-For` header is not used.

The compat middleware supports multiple entries for any header that maps to the `for` directive. All other directives
are applied to the first entry. This may not work as expected if `X-Forwarded-For` contains entries for both trusted and
untrusted proxies.

The compat middleware will always replace or remove an existing `Forwarded` header. Typically a proxy either sets the
`Forwarded` header or uses non-standard headers. Allowing both can lead to a security issue.

### Double pass middleware

Some PHP libraries support double pass middleware instead of PSR-15 and a callable with the following signature;

```php
fn(ServerRequestInterface $request, ResponseInterface $response, callable $next): ResponseInterface
```

To get a callback to be used by libraries as [Jasny Router](https://github.com/jasny/router) and
[Relay v1](http://relayphp.com/), use the `asDoublePass()` method.

```php
use Jasny\Forwarded;
use Relay\RelayBuilder;

$middleware = new Forwarded\Middleware(function ($ip, $forward) { /* ... */ });

$relayBuilder = new RelayBuilder($resolver);
$relay = $relayBuilder->newInstance([
    $middleware->asDoublePass(),
]);

$response = $relay($request, $baseResponse);
```

`CompatMiddleware` as has an `asDoublePass()` method.
