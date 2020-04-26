<?php

declare(strict_types = 1);

namespace AvtoDev\RoadRunnerLaravel;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Spiral\RoadRunner\PSR7Client;
use Spiral\Goridge\RelayInterface;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Bootstrap\SetRequestForConsole;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher as EventsDispatcher;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;

/**
 * Idea is taken from the package: https://github.com/swooletw/laravel-swoole.
 */
class Worker implements WorkerInterface
{
    /**
     * Laravel application base path.
     *
     * @var string
     */
    protected $base_path;

    /**
     * Create a new Worker instance.
     *
     * @param string $base_path Laravel application base path
     */
    public function __construct(string $base_path)
    {
        $this->base_path = $base_path;
    }

    /**
     * {@inheritdoc}
     */
    public function start(bool $refresh_app = false): void
    {
        $psr7_client  = $this->createPsr7Client($this->createStreamRelay());
        $psr7_factory = $this->createPsr7Factory();
        $http_factory = $this->createHttpFactory();

        $app = $this->createApplication($this->base_path);
        $this->bootstrapApplication($app, $psr7_client);

        $this->fireEvent($app, new Events\BeforeLoopStartedEvent($app));

        while ($req = $psr7_client->acceptRequest()) {
            if ($refresh_app === true) {
                $sandbox = $this->createApplication($this->base_path);
                $this->bootstrapApplication($sandbox, $psr7_client);
            } else {
                $sandbox = clone $app;
            }

            $this->setApplicationInstance($sandbox);

            /** @var HttpKernelContract|\Laravel\Lumen\Application $http_kernel */
            $http_kernel = getHttpKernel($sandbox);

            try {
                $this->fireEvent($sandbox, new Events\BeforeLoopIterationEvent($sandbox, $req));
                $request = Request::createFromBase($http_factory->createRequest($req));

                $this->fireEvent($sandbox, new Events\BeforeRequestHandlingEvent($sandbox, $request));
                $response = $http_kernel->handle($request);
                $this->fireEvent($sandbox, new Events\AfterRequestHandlingEvent($sandbox, $request, $response));

                $psr7_response = $psr7_factory->createResponse($response);
                $psr7_client->respond($psr7_response);

                if (!isLumenEnvironment()) {
                    $http_kernel->terminate($request, $response); // @phpstan-ignore-line
                }

                $this->fireEvent($sandbox, new Events\AfterLoopIterationEvent($sandbox, $request, $response));
            } catch (\Throwable $e) {
                $psr7_client->getWorker()->error((string) $e);
            } finally {
                unset($http_kernel, $response, $request, $sandbox);

                $this->setApplicationInstance($app);
            }
        }

        $this->fireEvent($app, new Events\AfterLoopStoppedEvent($app));
    }

    /**
     * @param Application|\Laravel\Lumen\Application $app
     *
     * @return void
     */
    protected function setApplicationInstance($app): void
    {
        $app->instance('app', $app);
        $app->instance(Container::class, $app);

        Container::setInstance($app);

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app); // @phpstan-ignore-line
    }

    /**
     * Create the new application instance.
     *
     * @param string $base_path
     *
     * @throws InvalidArgumentException
     *
     * @return Application|\Laravel\Lumen\Application
     */
    protected function createApplication(string $base_path)
    {
        $path = \implode(\DIRECTORY_SEPARATOR, [\rtrim($base_path, \DIRECTORY_SEPARATOR), 'bootstrap', 'app.php']);

        if (! \is_file($path)) {
            throw new InvalidArgumentException("Application bootstrap file was not found in [{$path}]");
        }

        return require $path;
    }

    /**
     * Bootstrap passed application.
     *
     * @param Application|\Laravel\Lumen\Application $app
     * @param PSR7Client          $psr7_client
     *
     * @throws RuntimeException
     *
     * @return void
     */
    protected function bootstrapApplication($app, PSR7Client $psr7_client): void
    {
        if (isLumenEnvironment()) {
            // Put PSR7 client into container
            $app->instance(PSR7Client::class, $psr7_client);

            $this->preResolveApplicationAbstracts($app);

            return;
        }

        /** @var HttpKernelContract|\Laravel\Lumen\Application $http_kernel */
        $http_kernel = getHttpKernel($app);

        $bootstrappers = $this->getKernelBootstrappers($http_kernel);

        // Insert `SetRequestForConsole` bootstrapper before `RegisterProviders` if it does not exists
        if (! \in_array(SetRequestForConsole::class, $bootstrappers, true)) {
            $register_index = \array_search(RegisterProviders::class, $bootstrappers, true);

            if ($register_index !== false) {
                \array_splice($bootstrappers, $register_index, 0, [SetRequestForConsole::class]);
            }
        }

        // Method `bootstrapWith` declared in interface `\Illuminate\Contracts\Foundation\Application` since
        // `illuminate/contracts:v5.8` - https://git.io/Jvflt -> https://git.io/JvfOq
        if (\method_exists($app, $boot_method = 'bootstrapWith')) {
            $app->{$boot_method}($bootstrappers);
        } else {
            throw new RuntimeException("Required method [{$boot_method}] does not exists on application instance");
        }

        // Put PSR7 client into container
        $app->instance(PSR7Client::class, $psr7_client);

        $this->preResolveApplicationAbstracts($app);
    }

    /**
     * Make configured abstracts pre-resolving.
     *
     * @param Application|\Laravel\Lumen\Application $app
     *
     * @return void
     */
    protected function preResolveApplicationAbstracts($app): void
    {
        /** @var ConfigRepository $config */
        $config = $app->make(ConfigRepository::class);

        // Pre-resolve instances
        foreach ((array) $config->get(ServiceProvider::getConfigRootKey() . '.pre_resolving', []) as $abstract) {
            if (\is_string($abstract) && $app->bound($abstract)) {
                $app->make($abstract);
            }
        }
    }

    /**
     * Get HTTP or Console kernel bootstrappers.
     *
     * @param \Illuminate\Contracts\Http\Kernel|\Laravel\Lumen\Application $kernel
     *
     * @return string[] Bootstrappers class names
     */
    protected function getKernelBootstrappers($kernel): array
    {
        ($method = (new \ReflectionObject($kernel))->getMethod($name = 'bootstrappers'))->setAccessible(true);

        return (array) $method->invoke($kernel);
    }

    /**
     * @param Application|\Laravel\Lumen\Application $app
     * @param object              $event
     *
     * @return void
     */
    protected function fireEvent($app, $event): void
    {
        /** @var EventsDispatcher $events */
        $events = $app->make(EventsDispatcher::class);

        $events->dispatch($event);
    }

    /**
     * @param resource|mixed $in  Must be readable
     * @param resource|mixed $out Must be writable
     *
     * @return RelayInterface
     */
    protected function createStreamRelay($in = \STDIN, $out = \STDOUT): RelayInterface
    {
        return new \Spiral\Goridge\StreamRelay($in, $out);
    }

    /**
     * @param RelayInterface $stream_relay
     *
     * @return PSR7Client
     */
    protected function createPsr7Client(RelayInterface $stream_relay): PSR7Client
    {
        return new PSR7Client(new \Spiral\RoadRunner\Worker($stream_relay));
    }

    /**
     * @return HttpFoundationFactoryInterface
     */
    protected function createHttpFactory(): HttpFoundationFactoryInterface
    {
        return new \Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
    }

    /**
     * @return HttpMessageFactoryInterface
     */
    protected function createPsr7Factory(): HttpMessageFactoryInterface
    {
        return new PsrHttpFactory(
            new \Spiral\RoadRunner\Diactoros\ServerRequestFactory,
            new \Spiral\RoadRunner\Diactoros\StreamFactory,
            new \Spiral\RoadRunner\Diactoros\UploadedFileFactory,
            new \Laminas\Diactoros\ResponseFactory
        );
    }
}
