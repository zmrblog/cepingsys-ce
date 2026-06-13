<?php
declare(strict_types=1);

$app->add(function ($request, $handler) use ($container) {
    $request = $request->withAttribute('container', $container);
    return $handler->handle($request);
});

$app->add(function ($request, $handler) {
    $response = $handler->handle($request);

    $allowedOrigins = ['http://localhost:2001', 'http://localhost:2002', 'http://localhost:8081'];
    $requestOrigin = $request->getHeaderLine('Origin');

    // 动态匹配：如果请求来源与当前 Host 同源，也允许
    $host = $request->getHeaderLine('Host');
    if ($requestOrigin && $host) {
        $originHost = parse_url($requestOrigin, PHP_URL_HOST);
        $originPort = parse_url($requestOrigin, PHP_URL_PORT);
        $hostParts = explode(':', $host);
        $hostName = $hostParts[0];
        $hostPort = $hostParts[1] ?? null;
        if ($originHost === $hostName && ($originPort === (int)$hostPort || (!$originPort && !$hostPort))) {
            $allowedOrigins[] = $requestOrigin;
        }
    }

    $response = $response
        ->withHeader('Content-Security-Policy',
            "default-src 'self'; " .
            "script-src 'self' 'unsafe-inline'; " .
            "style-src 'self' 'unsafe-inline'; " .
            "img-src 'self' data: blob:; " .
            "font-src 'self'; " .
            "connect-src 'self'; " .
            "frame-ancestors 'none'; " .
            "base-uri 'self'; " .
            "form-action 'self'"
        )
        ->withHeader('X-Frame-Options', 'DENY')
        ->withHeader('X-Content-Type-Options', 'nosniff')
        ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')
        ->withHeader('Cache-Control', 'no-cache, must-revalidate')
        ->withHeader('Pragma', 'no-cache')
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization, X-Device-Fingerprint')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
        ->withHeader('Access-Control-Expose-Headers', 'X-Request-ID');

    if ($requestOrigin && in_array($requestOrigin, $allowedOrigins)) {
        $response = $response->withHeader('Access-Control-Allow-Origin', $requestOrigin);
        $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
    }

    return $response;
});

$app->options('/{routes:.+}', function ($request, $response) {
    return $response;
});

$GLOBALS['container'] = $container;
