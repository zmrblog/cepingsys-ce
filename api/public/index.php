<?php
declare(strict_types=1);

header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noai, noimageai', true);

$installLockFile = __DIR__ . '/../storage/.installed';
$installPhpLock = dirname(__DIR__, 2) . '/install.lock';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$isInstallRequest = str_starts_with($requestUri, '/api/install/') || str_starts_with($requestUri, '/install/') || str_starts_with($requestUri, '/install.php');
$isInstalled = file_exists($installLockFile) || file_exists($installPhpLock);

if (!$isInstalled && !$isInstallRequest) {
    $installPath = '/install.php';
    if (PHP_SAPI === 'cli') {
        echo "System not installed. Please run the installer first.\n";
        echo "Visit: {$installPath}\n";
        exit(1);
    }
    header("Location: {$installPath}");
    exit;
}

use DI\ContainerBuilder;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Factory\AppFactory;
use Slim\Middleware\BodyParsingMiddleware;

require __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('Asia/Shanghai');

$basePath = str_replace('\\', '/', dirname(__DIR__));
$basePath = rtrim($basePath, '/');

$dotenv = Dotenv\Dotenv::createImmutable($basePath);
$dotenv->safeLoad();

$containerBuilder = new ContainerBuilder();

$containerBuilder->addDefinitions([
    'config' => function () use ($basePath) {
        return require $basePath . '/config/config.php';
    },
    'db' => function ($c) {
        $config = $c->get('config')['database'];

        $capsule = new \Illuminate\Database\Capsule\Manager();
        $capsule->addConnection($config);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        return $capsule;
    },
    'logger' => function ($c) {
        $config = $c->get('config')['logging'];

        $logger = new \Monolog\Logger('examine-system');
        $fileHandler = new \Monolog\Handler\StreamHandler($config['path'], $config['level']);
        $logger->pushHandler($fileHandler);

        return $logger;
    },
    ResponseFactoryInterface::class => function ($c) {
        return $c->get(\Slim\Psr7\Factory\ResponseFactory::class);
    },
]);

$container = $containerBuilder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();

$db = $container->get('db');

$app->setBasePath('/api');

$app->addRoutingMiddleware();

$app->add(new BodyParsingMiddleware());

$errorMiddleware = $app->addErrorMiddleware(
    $container->get('config')['app']['debug'] ?? false,
    true,
    true
);

$errorMiddleware->setDefaultErrorHandler(function (
    \Psr\Http\Message\ServerRequestInterface $request,
    \Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails
) use ($container) {
    $payload = [
        'code' => 500,
        'message' => $displayErrorDetails ? $exception->getMessage() : 'Internal Server Error',
    ];

    if ($logErrors) {
        try {
            $container->get('logger')->error($exception->getMessage(), [
                'exception' => $exception,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'request' => $request->getMethod() . ' ' . $request->getUri()->getPath(),
            ]);
        } catch (\Throwable $e) {
        }
    }

    $responseFactory = $container->get(ResponseFactoryInterface::class);
    $response = $responseFactory->createResponse(500);
    $json = @json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($json === false || strlen($json) < 2) {
        $json = '{"code":500,"message":"Internal Server Error"}';
    }
    $response->getBody()->write($json);
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

require __DIR__ . '/../app/middleware.php';
require __DIR__ . '/../app/routes.php';

$app->run();
