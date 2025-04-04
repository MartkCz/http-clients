<?php declare(strict_types = 1);

namespace StrictPhp\HttpClients\Symfony;

use GuzzleHttp\Client;
use Psr\Http\Client\ClientInterface;
use StrictPhp\HttpClients\Actions\FindExtensionFromHeadersAction;
use StrictPhp\HttpClients\Actions\StreamAction;
use StrictPhp\HttpClients\Clients\CacheResponse\Actions\CacheKeyMakerAction;
use StrictPhp\HttpClients\Clients\CacheResponse\CacheResponseClientFactory;
use StrictPhp\HttpClients\Clients\CustomizeRequest\CustomizeRequestClientFactory;
use StrictPhp\HttpClients\Clients\CustomResponse\CustomResponseClientFactory;
use StrictPhp\HttpClients\Clients\Event\Actions\MakePathAction;
use StrictPhp\HttpClients\Clients\Event\EventClientFactory;
use StrictPhp\HttpClients\Clients\Retry\RetryClientFactory;
use StrictPhp\HttpClients\Clients\Sleep\SleepClientFactory;
use StrictPhp\HttpClients\Clients\Store\StoreClientFactory;
use StrictPhp\HttpClients\Contracts\CacheKeyMakerActionContract;
use StrictPhp\HttpClients\Contracts\ClientsFactoryContract;
use StrictPhp\HttpClients\Contracts\FindExtensionFromHeadersActionContract;
use StrictPhp\HttpClients\Contracts\MakePathActionContract;
use StrictPhp\HttpClients\Contracts\StreamActionContract;
use StrictPhp\HttpClients\Exceptions\InvalidStateException;
use StrictPhp\HttpClients\Factories\ClientsFactory;
use StrictPhp\HttpClients\Filesystem\Factories\FileFactory;
use StrictPhp\HttpClients\Helpers\Filesystem;
use StrictPhp\HttpClients\Managers\ConfigManager;
use StrictPhp\HttpClients\Requests\SaveForPhpstormRequest;
use StrictPhp\HttpClients\Responses\SaveResponse;
use StrictPhp\HttpClients\Services\CachePsr16Service;
use StrictPhp\HttpClients\Services\FilesystemService;
use StrictPhp\HttpClients\Services\SerializableResponseService;
use StrictPhp\HttpClients\Transformers\CacheKeyToFileInfoTransformer;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ReferenceConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class HttpClientsBundle extends AbstractBundle implements CompilerPassInterface
{
    /**
     * @param mixed[] $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /** @var string[] $factories */
        $factories = $config['factories'];

        $this->buildMainClient($container);
        $this->buildCacheKeyMaker($container);
        $this->buildInternalServices($container, $builder);
        $this->buildClients($container);
        $this->buildConfigManager($container);
        $this->buildCache($container);
        $this->buildClientFactory($container, $factories);
        $this->buildHttpClient($container);
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass($this);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $definition->rootNode();
        $rootNode // @phpstan-ignore-line
            ->children()
            ->arrayNode('factories')
            ->stringPrototype()
            ->end()
            ->end()
            ->end();
    }

    public function process(ContainerBuilder $container): void
    {
        if (! $container->hasDefinition('event_dispatcher')) {
            $container->removeDefinition($this->prefix('middleware.event'));
        }

        $mainClient = $container->getDefinition($this->prefix('main.client'));

        if ($mainClient->getClass() === null) {
            $mainClient->setClass($this->tryToResolveMainClientClass());
        }
    }

    /**
     * @return class-string<ClientInterface>
     */
    private function tryToResolveMainClientClass(): string
    {
        if (class_exists(Client::class)) {
            return Client::class;
        } elseif (class_exists(Psr18Client::class)) {
            return Psr18Client::class;
        }

        throw new InvalidStateException(sprintf(
            'Register http client like service name %s.',
            $this->prefix('main.client'),
        ));
    }

    private function buildMainClient(ContainerConfigurator $container): void
    {
        $services = $container->services();

        $services->set($this->prefix('main.client'))
            ->autowire();
    }

    private function buildClients(ContainerConfigurator $container): void
    {
        $prefix = fn (string $id): string => $this->prefix('middleware.' . $id);

        $services = $container->services()
            ->defaults()
            ->autowire();

        $services->set($prefix('cache_response'), CacheResponseClientFactory::class)
            ->args([
                service($this->prefix('cache')),
                service($this->prefix('serializable.response.service')),
                service($this->prefix('config.manager')),
            ]);

        $services->set($prefix('store'), StoreClientFactory::class)
            ->args([service($this->prefix('save.for.phpstorm')), service($this->prefix('config.manager'))]);

        $services->set($prefix('sleep'), SleepClientFactory::class)
            ->args([service($this->prefix('config.manager'))]);

        $services->set($prefix('retry'), RetryClientFactory::class)
            ->args([service($this->prefix('config.manager'))]);

        $services->set($prefix('customize_request'), CustomizeRequestClientFactory::class)
            ->args([service($this->prefix('config.manager'))]);

        $services->set($prefix('custom_response'), CustomResponseClientFactory::class)
            ->args([service($this->prefix('config.manager'))]);

        $services->set($prefix('event'), EventClientFactory::class)
            ->args([service('event_dispatcher'), service($this->prefix('config.manager'))]);
    }

    private function buildConfigManager(ContainerConfigurator $container): void
    {
        $services = $container->services();

        $services->set($this->prefix('config.manager'), ConfigManager::class);
    }

    private function buildCache(ContainerConfigurator $container): void
    {
        $services = $container->services();

        $services->set($this->prefix('cache'), CachePsr16Service::class)
            ->autowire()
            ->args([service($this->prefix('file.factory.temp'))]);
    }

    /**
     * @param string[] $factories
     */
    private function buildClientFactory(ContainerConfigurator $container, array $factories): void
    {
        $services = $container->services()
            ->defaults();

        $services->set($this->prefix('clients.factory'), ClientsFactory::class)
            ->args([service($this->prefix('main.client')), $this->createServiceReferences($factories)])
            ->alias(ClientsFactoryContract::class, $this->prefix('clients.factory'));
    }

    /**
     * @param string[] $services
     * @return ReferenceConfigurator[]
     */
    private function createServiceReferences(array $services): array
    {
        $references = [];

        foreach ($services as $service) {
            if (str_starts_with($service, '@')) {
                $service = substr($service, 1);
            }

            $references[] = service($service);
        }

        return $references;
    }

    private function buildHttpClient(ContainerConfigurator $container): void
    {
        $services = $container->services();

        $services->set($this->prefix('client'), ClientInterface::class)
            ->factory([service($this->prefix('clients.factory')), 'create'])
            ->alias(ClientInterface::class, $this->prefix('client'));
    }

    private function buildCacheKeyMaker(ContainerConfigurator $container): void
    {
        $services = $container->services();

        $services->set($this->prefix('cache.key.maker'), CacheKeyMakerAction::class)
            ->autowire()
            ->alias(CacheKeyMakerActionContract::class, $this->prefix('cache.key.maker'));
    }

    private function buildInternalServices(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $cacheDir = $builder->getParameter('kernel.cache_dir');
        $logsDir = $builder->getParameter('kernel.logs_dir');

        assert(is_string($cacheDir));
        assert(is_string($logsDir));

        $services = $container->services();
        $services->defaults()
            ->autowire();

        $services->set($this->prefix('file.info.transformer'), CacheKeyToFileInfoTransformer::class)
            ->alias(CacheKeyToFileInfoTransformer::class, $this->prefix('file.info.transformer'));

        $services->set($this->prefix('serializable.response.service'), SerializableResponseService::class)
            ->args([
                service($this->prefix('file.info.transformer')),
                service($this->prefix('file.factory.temp')),
                service($this->prefix('extension.header')),
            ]);

        $services->set($this->prefix('filesystem.temp'), FilesystemService::class)
            ->args([Filesystem::addSlash($cacheDir)]);

        $services->set($this->prefix('file.factory.temp'), FileFactory::class)
            ->args([service($this->prefix('filesystem.temp'))]);

        $services->set($this->prefix('filesystem.log'), FilesystemService::class)
            ->args([Filesystem::addSlash($logsDir)]);

        $services->set($this->prefix('file.factory.log'), FileFactory::class)
            ->args([service($this->prefix('filesystem.log'))]);

        $services->set($this->prefix('make.path'), MakePathAction::class)
            ->alias(MakePathActionContract::class, $this->prefix('make.path'));

        $services->set($this->prefix('extension.header'), FindExtensionFromHeadersAction::class)
            ->alias(FindExtensionFromHeadersActionContract::class, $this->prefix('extension.header'));

        $services->set($this->prefix('stream'), StreamAction::class)
            ->alias(StreamActionContract::class, $this->prefix('stream'));

        $services->set($this->prefix('save.response'), SaveResponse::class)
            ->args([
                service($this->prefix('file.factory.log')),
                service($this->prefix('make.path')),
                service($this->prefix('extension.header')),
                service($this->prefix('stream')),
            ]);

        $services->set($this->prefix('save.for.phpstorm'), SaveForPhpstormRequest::class)
            ->args([
                service($this->prefix('file.factory.log')),
                service($this->prefix('make.path')),
                service($this->prefix('save.response')),
                service($this->prefix('stream')),
            ]);
    }

    private function prefix(string $name): string
    {
        return sprintf('%s.%s', 'http_clients', $name);
    }
}
