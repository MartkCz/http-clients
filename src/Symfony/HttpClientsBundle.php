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
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class HttpClientsBundle extends AbstractBundle implements CompilerPassInterface
{
    /**
     * @param mixed[] $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $this->buildMainClient($container);
        $this->buildCacheKeyMaker($container);
        $this->buildInternalServices($container, $builder);
        $this->buildClients($container);
        $this->buildConfigManager($container);
        $this->buildCache($container);
        $this->buildClientFactory($container);
        $this->buildHttpClient($container);
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass($this);
    }

    public function process(ContainerBuilder $container): void
    {
        if (! $container->hasDefinition('event_dispatcher')) {
            $container->removeDefinition($this->prefix('middleware.event'));
        }
    }

    private function buildMainClient(ContainerConfigurator $container): void
    {
        $services = $container->services();

        if (class_exists(Client::class)) {
            $class = Client::class;
        } elseif (class_exists(Psr18Client::class)) {
            $class = Psr18Client::class;
        } else {
            throw new InvalidStateException('No HTTP client available. Please install Guzzle or Symfony HttpClient.');
        }

        $services->set($this->prefix('main.client'), $class)
            ->autowire();
    }

    private function buildClients(ContainerConfigurator $container): void
    {
        $prefix = fn (string $id): string => $this->prefix('middleware.' . $id);

        $services = $container->services()
            ->defaults()
            ->autowire();

        $services->set($prefix('cacheResponse'), CacheResponseClientFactory::class)
            ->args([
                service($this->prefix('cache')),
                service($this->prefix('serializable.response.service')),
                service($this->prefix('config.manager')),
            ])
            ->tag('httpClients.middleware');

        $services->set($prefix('store'), StoreClientFactory::class)
            ->args([service($this->prefix('save.for.phpstorm')), service($this->prefix('config.manager'))])
            ->tag('httpClients.middleware');

        $services->set($prefix('sleep'), SleepClientFactory::class)
            ->args([service($this->prefix('config.manager'))])
            ->tag('httpClients.middleware');

        $services->set($prefix('retry'), RetryClientFactory::class)
            ->args([service($this->prefix('config.manager'))])
            ->tag('httpClients.middleware');

        $services->set($prefix('customizeRequest'), CustomizeRequestClientFactory::class)
            ->args([service($this->prefix('config.manager'))])
            ->tag('httpClients.middleware');

        $services->set($prefix('customResponse'), CustomResponseClientFactory::class)
            ->args([service($this->prefix('config.manager'))])
            ->tag('httpClients.middleware');

        $services->set($prefix('event'), EventClientFactory::class)
            ->args([service('event_dispatcher'), service($this->prefix('config.manager'))])
            ->tag('httpClients.middleware');
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

    private function buildClientFactory(ContainerConfigurator $container): void
    {
        $services = $container->services()
            ->defaults();

        $services->set($this->prefix('client.factory'), ClientsFactory::class)
            ->args([service($this->prefix('main.client')), tagged_iterator('httpClients.middleware')])
            ->alias(ClientsFactoryContract::class, $this->prefix('client.factory'));
    }

    private function buildHttpClient(ContainerConfigurator $container): void
    {
        $services = $container->services();

        $services->set($this->prefix('client'), ClientInterface::class)
            ->factory([service($this->prefix('client.factory')), 'create']);
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
        return sprintf('%s.%s', 'httpClients', $name);
    }
}
