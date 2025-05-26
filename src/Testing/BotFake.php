<?php

namespace Telegram\Bot\Testing;

use Exception;
use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\Assert as PHPUnit;
use Telegram\Bot\Commands\CommandHandler;
use Telegram\Bot\Commands\Contracts\CommandContract;
use Telegram\Bot\Contracts\BotInterface;
use Telegram\Bot\Events\EventFactory;
use Telegram\Bot\Objects\ResponseObject;
use Telegram\Bot\Testing\Requests\TestRequest;
use Throwable;

final class BotFake implements BotInterface
{
    /**
     * @var array<array-key, TestRequest>
     */
    private array $requests = [];

    private bool $failWhenEmpty = false;

    private ?CommandHandler $commandHandler = null;

    private array $config = []; // Mock config storage

    private ?Container $container = null; // Mock container

    private ?EventFactory $eventFactory = null; // Mock EventFactory

    /**
     * @param  array<array-key, string>  $responses
     */
    public function __construct(protected array $responses = [])
    {
        // Set up a minimal config for the fake bot
        $this->config = [
            'bot' => 'fake',
            'token' => 'fake-token-for-testing',
            'global' => [
                'http' => [
                    'client' => \Telegram\Bot\Http\GuzzleHttpClient::class,
                    'api_url' => 'https://api.telegram.org',
                    'file_url' => '',
                    'config' => [],
                    'async' => false,
                ],
            ],
            // Ensure listeners are empty for fake bot unless explicitly configured
            'listen' => [],
        ];
    }

    public function getName(): string
    {
        return $this->config['bot'];
    }

    // For CommandHandler or other parts that expect a real Api,
    // this fake bot can act as its own Api for recording.
    public function getApi(): \Telegram\Bot\Api
    {
        return $this; // BotFake pretends to be the API client
    }

    public function setApi(\Telegram\Bot\Api $api): self
    {
        // In BotFake, we don't set an external API, we are the API.
        // This method might be a no-op or throw an exception if setApi is called
        // for some reason on the fake. For now, it's a no-op.
        return $this;
    }

    public function getCommandHandler(): CommandHandler
    {
        if (! $this->commandHandler) {
            // Lazily initialize CommandHandler for this fake bot
            $this->commandHandler = new CommandHandler($this);
            // Ensure CommandHandler's internal bot reference also points to this fake
            $this->commandHandler->setBot($this);
        }

        return $this->commandHandler;
    }

    public function setCommandHandler(CommandHandler $commandHandler): self
    {
        $this->commandHandler = $commandHandler;
        $this->commandHandler->setBot($this); // Ensure handler uses this fake bot

        return $this;
    }

    // Intentionally returning self for improved testing ergonomics, diverging from BotInterface.
    public function command(string $command, array|string|callable|CommandContract $handler): self
    {
        $this->getCommandHandler()->command($command, $handler);
        return $this; // Return $this for fluent chaining
    }

    public function getEventFactory(): EventFactory
    {
        if (! $this->eventFactory) {
            $this->eventFactory = new EventFactory;
            // Since CommandBus uses EventFactory to dispatch events like CommandNotFoundEvent,
            // we need to ensure the fake bot's EventFactory exists.
        }

        return $this->eventFactory;
    }

    public function setEventFactory(EventFactory $eventFactory): self
    {
        $this->eventFactory = $eventFactory;

        return $this;
    }

    public function config(array|string|null $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }
        if (is_array($key)) {
            foreach ($key as $name => $value) {
                \Illuminate\Support\Arr::set($this->config, $name, $value);
            }

            return true;
        }

        return \Illuminate\Support\Arr::get($this->config, $key, $default);
    }

    public function hasConfig($key): bool
    {
        return \Illuminate\Support\Arr::has($this->config, $key);
    }

    public function getContainer(): Container
    {
        return $this->container ?? \Illuminate\Container\Container::getInstance();
    }

    public function setContainer(Container $container): self
    {
        $this->container = $container;

        return $this;
    }

    public function dispatchUpdateEvent(ResponseObject $response): ResponseObject
    {
        // In a fake, dispatching updates often directly leads to command processing for simplicity
        // or can be used to trigger mock event listeners.
        // For command testing, we can simply pass to command handler.
        return $this->getCommandHandler()->handler($response);
    }

    // --- End IBot Interface Implementations ---

    /**
     * Process an update through the command handler.
     */
    public function processCommand(ResponseObject $update): static
    {
        $this->getCommandHandler()->processCommand($update);

        return $this;
    }

    /**
     * Simulate webhook processing (alias for processCommand for clarity).
     */
    public function receiveUpdate(ResponseObject $update): static
    {
        return $this->processCommand($update);
    }

    public function addResponses(array $responses): void
    {
        $this->responses = [...$this->responses, ...$responses];
    }

    public function assertSent(string $method, $callback = null): void
    {
        if (is_int($callback)) {
            $this->assertSentTimes($method, $callback);

            return;
        }

        PHPUnit::assertNotSame(
            $this->sent($method, $callback),
            [],
            "The expected [{$method}] request was not sent."
        );
    }

    private function assertSentTimes(string $method, int $times = 1): void
    {
        $count = count($this->sent($method));

        PHPUnit::assertSame(
            $times,
            $count,
            "The expected [{$method}] method was sent {$count} times instead of {$times} times."
        );
    }

    private function sent(string $method, ?callable $callback = null): array
    {
        if (! $this->hasSent($method)) {
            return [];
        }

        $callback = $callback ?: fn (): bool => true;

        return array_filter($this->methodsOf($method), fn (TestRequest $request) => $callback($request->parameters()));
    }

    private function hasSent(string $method): bool
    {
        return $this->methodsOf($method) !== [];
    }

    public function assertNotSent(string $method, ?callable $callback = null): void
    {
        PHPUnit::assertCount(
            0,
            $this->sent($method, $callback),
            "The unexpected [{$method}] request was sent."
        );
    }

    public function assertNothingSent(): void
    {
        $methodNames = implode(
            separator: ', ',
            array: array_map(fn (TestRequest $request): string => $request->method(), $this->requests)
        );

        PHPUnit::assertEmpty($this->requests, 'The following requests were sent unexpectedly: '.$methodNames);
    }

    /**
     * @return array<array-key, TestRequest>
     */
    private function methodsOf(string $method): array
    {
        return array_filter($this->requests, fn (TestRequest $request): bool => $request->method() === $method);
    }

    public function record(TestRequest $request): mixed
    {
        $this->requests[] = $request;

        if ($this->failWhenEmpty && $this->isEmpty()) {
            throw new Exception('No fake responses left.');
        }

        if (! $this->failWhenEmpty && $this->isEmpty()) {
            return new ResponseObject([
                'ok' => true,
                'result' => true,
            ]);
        }

        $response = array_shift($this->responses);

        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response instanceof ResponseObject ? $response : ResponseObject::make($response);
    }

    public function __call(string $method, array $parameters)
    {
        // IMPORTANT: The CommandHandler will call methods like $this->bot->getApi()->sendMessage(...)
        // Since getApi() returns $this (BotFake), this __call will intercept the API methods.
        // It should NOT call parent::__call(), as parent is the final Bot class.
        // Also, it should NOT intercept testing assertions like assertSent.

        // If it's one of our assertion methods, let them run.
        if (method_exists($this, $method) && str_starts_with($method, 'assert')) {
            return call_user_func_array([$this, $method], $parameters);
        }

        // Otherwise, assume it's an API call and record it.
        $param = $parameters[0] ?? null;

        return $this->record(new TestRequest($method, $param));
    }

    public function bot(?string $string = null): static
    {
        return $this;
    }

    private function isEmpty(): bool
    {
        return $this->responses === [];
    }

    public function failWhenEmpty(): static
    {
        $this->failWhenEmpty = true;

        return $this;
    }
}
