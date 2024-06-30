<?php declare(strict_types=1);

namespace StrictPhp\HttpClients\Clients\Event;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use StrictPhp\HttpClients\Clients\Event\Entities\HttpStateEntity;
use StrictPhp\HttpClients\Clients\Event\Events\BeforeRequestEvent;
use StrictPhp\HttpClients\Clients\Event\Events\FailedRequestEvent;
use StrictPhp\HttpClients\Clients\Event\Events\SuccessRequestEvent;
use StrictPhp\HttpClients\Managers\ConfigManager;
use Throwable;

final class EventClient implements ClientInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly ConfigManager $configManager,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $config = $this->configManager->get(Config::class, $request->getUri()->getHost());

        if ($config->enabled === false) {
            return $this->client->sendRequest($request);
        }

        $httpState = new HttpStateEntity($request);

        $this->eventDispatcher->dispatch(new BeforeRequestEvent($httpState));
        try {
            $response = $this->client->sendRequest($request);
        } catch (Throwable $throwable) {
            $this->eventDispatcher->dispatch(new FailedRequestEvent($httpState->finish(), $throwable));
            throw $throwable;
        }

        // keep out of try-catch block
        $this->eventDispatcher->dispatch(new SuccessRequestEvent($httpState->finish(), $response));

        return $response;
    }
}
