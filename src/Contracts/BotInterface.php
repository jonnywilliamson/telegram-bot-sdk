<?php

namespace Telegram\Bot\Contracts;

use Illuminate\Contracts\Container\Container;
use Telegram\Bot\Addon\AddonManager;

// Example, if needed by CommandBus
use Telegram\Bot\Api;
use Telegram\Bot\Commands\CommandHandler;
use Telegram\Bot\Commands\Contracts\CallableContract;
use Telegram\Bot\Commands\Contracts\CommandContract;
use Telegram\Bot\Events\EventFactory;
use Telegram\Bot\Objects\ResponseObject;

interface BotInterface
{

    public function getName(): string;

    public function getApi(): Api;

    public function setApi(Api $api): self;

    public function getCommandHandler(): CommandHandler;

    public function setCommandHandler(CommandHandler $commandHandler): self;

    public function command(string $command, array|string|callable|CommandContract $handler): CommandContract|CallableContract;

    public function getEventFactory(): EventFactory;

    public function setEventFactory(EventFactory $eventFactory): self;

    public function config(array|string|null $key = null, mixed $default = null): mixed;

    public function hasConfig($key): bool;

    public function getContainer(): Container;

    public function setContainer(Container $container): self;

    public function dispatchUpdateEvent(ResponseObject $response): ResponseObject;

    // Add any other methods from Bot.php that CommandBus or other dependent classes
    // might directly call. For example, if CommandBus needs to get a Token directly
    // or handle HTTP settings.
    // public function getToken(): string;
    // public function hasToken(): bool;
    // public function getHttpClientHandler(): \Telegram\Bot\Contracts\HttpClientInterface;
}
