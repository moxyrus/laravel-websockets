<?php

namespace BeyondCode\LaravelWebSockets\Statistics\Logger;

use App\Repositories\ChatRepository;
use BeyondCode\LaravelWebSockets\Apps\App;
use BeyondCode\LaravelWebSockets\Statistics\Http\Controllers\WebSocketStatisticsEntriesController;
use BeyondCode\LaravelWebSockets\Statistics\Statistic;
use BeyondCode\LaravelWebSockets\WebSockets\Channels\ChannelManager;
use Clue\React\Buzz\Browser;
use Psy\Util\Json;
use function GuzzleHttp\Psr7\stream_for;
use Ratchet\ConnectionInterface;

class HttpStatisticsLogger implements StatisticsLogger
{
    /** @var \BeyondCode\LaravelWebSockets\Statistics\Statistic[] */
    protected $statistics = [];

    /** @var \BeyondCode\LaravelWebSockets\WebSockets\Channels\ChannelManager */
    protected $channelManager;

    /** @var \Clue\React\Buzz\Browser */
    protected $browser;

    /** @var ChatRepository */
    protected $chatRepository;

    public function __construct(ChannelManager $channelManager, Browser $browser, ChatRepository $repository)
    {
        $this->channelManager = $channelManager;

        $this->browser = $browser;

        $this->chatRepository = $repository;
    }

    public function webSocketMessage(ConnectionInterface $connection)
    {
        $this
            ->findOrMakeStatisticForAppId($connection->app->id)
            ->webSocketMessage();
    }

    public function apiMessage($appId)
    {
        $this
            ->findOrMakeStatisticForAppId($appId)
            ->apiMessage();
    }

    public function connection(ConnectionInterface $connection)
    {
        $this
            ->findOrMakeStatisticForAppId($connection->app->id)
            ->connection();
    }

    public function disconnection(ConnectionInterface $connection)
    {
        $this
            ->findOrMakeStatisticForAppId($connection->app->id)
            ->disconnection();
    }

    protected function findOrMakeStatisticForAppId($appId): Statistic
    {
        if (!isset($this->statistics[$appId])) {
            $this->statistics[$appId] = new Statistic($appId);
        }

        return $this->statistics[$appId];
    }

    public function save()
    {
        foreach ($this->statistics as $appId => $statistic) {
            if (!$statistic->isEnabled()) {
                continue;
            }

            $chatsCollection = collect($this->channelManager->getChannels($appId));

            $postData = array_merge($statistic->toArray(), [
                'secret' => App::findById($appId)->secret,
                'chats'  => $chatsCollection->map(function ($chat) {
                    return (object)[
                        'peak_connection_count' => count($chat->getSubscribedConnections()),
                        'chat_id'               => $this->chatRepository->getChatIdByWebsocketChannel($chat->getChannelName())
                    ];
                })->values()
            ]);

            $this->browser
                ->post(
                    action([WebSocketStatisticsEntriesController::class, 'store']),
                    ['Content-Type' => 'application/json'],
                    stream_for(json_encode($postData))
                );

            $currentConnectionCount = $this->channelManager->getConnectionCount($appId);
            $statistic->reset($currentConnectionCount);
        }
    }
}
