<?php

namespace Telegram\Bot\Testing;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert as PHPUnit;
use Telegram\Bot\Api;
use Telegram\Bot\Bot;
use Telegram\Bot\Commands\CommandHandler;
use Telegram\Bot\Commands\Contracts\CallableContract;
use Telegram\Bot\Commands\Contracts\CommandContract;
use Telegram\Bot\Events\EventFactory;
use Telegram\Bot\Http\GuzzleHttpClient;
use Telegram\Bot\Objects\ResponseObject;
use Telegram\Bot\Testing\Requests\TestRequest;

class BotFake extends Bot
{
    /** @var array<string, array> Tracks commands processed by CommandHandler */
    private array $processedCommands = [];

    private bool $failWhenEmpty = false;

    private ?CommandHandler $commandHandler = null;

    private array $config = []; // Mock config storage

    protected ?Container $container = null; // Mock container

    private ?EventFactory $eventFactory = null; // Mock EventFactory

    private Api $api;

    public function __construct(array $responses = [])
    {
        parent::__construct([
            'bot' => 'fake',
            'token' => 'fake-token-for-testing', // Dummy token for parent constructor
            'global' => [
                'http' => [
                    'client' => GuzzleHttpClient::class,
                    'api_url' => 'https://api.telegram.org',
                    'file_url' => '',
                    'config' => [],
                    'async' => false,
                ],
            ],
            'listen' => [],
        ]);

        $this->setApi(new ApiFake($responses));

        // TODO - do I need this
        $this->getCommandHandler()->setBot($this);
    }

    public function setApi(Api $api): self
    {
        $this->api = $api; // This sets the private property

        return $this;
    }

    public function getApi(): Api
    {
        return $this->api;
    }

    /**
     * Provides a fluent interface for setting up commands for testing
     *
     *
     * @return $this
     */
    public function registerCommand(string $name, string|callable|CommandContract $handler): self
    {
        $this->getCommandHandler()->command($name, $handler);

        return $this;
    }

    public function command(
        string $command,
        array|string|callable|CommandContract $handler
    ): CommandContract|CallableContract {
        return $this->getCommandHandler()->command($command, $handler);
    }

    public function dispatchUpdateEvent(ResponseObject $response): ResponseObject
    {
        // In a fake, dispatching updates often directly leads to command processing for simplicity
        // or can be used to trigger mock event listeners.
        // For command testing, we can simply pass to command handler.
        return $this->getCommandHandler()->handler($response);
    }

    /**
     * Simulate webhook processing (alias for processCommand for clarity).
     */
    public function processUpdate(ResponseObject $update): static
    {
        $this->getCommandHandler()->processCommand($update);

        return $this;
    }

    public function addResponses(array $responses): void
    {
        $this->getApi()->addResponses($responses);
    }

    // Assertions Begin Here

    public function assertSent(string $method, array|callable|null $constraint = null): void
    {
        PHPUnit::assertNotSame(
            $this->sent($method, $constraint), // Pass the constraint directly
            [],
            "The expected [{$method}] request was not sent."
        );
    }

    public function assertNotSent(string $method, array|callable|null $constraint = null): void
    {
        PHPUnit::assertCount(
            0,
            $this->sent($method, $constraint),
            "The unexpected [{$method}] request was sent."
        );
    }

    public function assertSentTimes(string $method, int $times = 1): void
    {
        $count = count($this->sentByMethod($method));

        PHPUnit::assertSame(
            $times,
            $count,
            "The expected [{$method}] method was sent {$count} times instead of {$times} times."
        );
    }

    public function assertNothingSent(): void
    {
        $methodNames = implode(
            separator: ', ',
            array: array_map(fn (TestRequest $request): string => $request->method(), $this->getApi()->getRequests())
        );

        PHPUnit::assertEmpty($this->getApi()->getRequests(), 'The following requests were sent unexpectedly: '.$methodNames);
    }

    public function assertMessageSent(string $text, ?string $chatId = null): void
    {
        $this->assertSent('sendMessage', function (array $params) use ($text, $chatId) {
            $actualText = $params['text'] ?? '';
            $textMatches = ($actualText === $text);
            $chatIdMatches = $chatId ? ((string) ($params['chat_id'] ?? '') === $chatId) : true;

            return $textMatches && $chatIdMatches;
        });
    }

    public function assertMessageContains(string $text, ?string $chatId = null): void
    {
        $this->assertSent('sendMessage', function (array $params) use ($text, $chatId) {
            $actualText = $params['text'] ?? '';
            $textMatches = Str::contains($actualText, $text);
            $chatIdMatches = $chatId ? ((string) ($params['chat_id'] ?? '') === $chatId) : true;

            return $textMatches && $chatIdMatches;
        });
    }

    public function assertMessageSentCount(int $count): void
    {
        PHPUnit::assertSame(
            $count,
            count($this->sentByMethod('sendMessage')), // Directly count all 'sendMessage' calls
            'The sendMessage method was called '.count($this->sentByMethod('sendMessage'))." times instead of {$count} times."
        );
    }

    public function assertCommandHandled(string $commandName, ?callable $callback = null): void
    {
        $matchingCommands = array_filter($this->processedCommands,
            function ($commandRecord) use ($commandName, $callback) {
                if ($commandRecord['name'] !== strtolower($commandName)) {
                    return false;
                }
                if ($callback === null) {
                    return true;
                }

                // Pass the arguments that the command received to the callback
                return $callback($commandRecord['arguments']);
            });

        PHPUnit::assertNotSame(
            [],
            $matchingCommands,
            "The expected [{$commandName}] command was not handled."
        );
    }

    private function sent(string $method, array|callable|null $constraint = null): array
    {
        $requestsByMethod = $this->sentByMethod($method); // NEW: Get filtered requests first

        // If no constraint, return all requests for this method
        if ($constraint === null) {
            return $requestsByMethod;
        }

        // Default callback if constraint is an array - this is where the array logic comes in
        $callback = fn (array $params): bool => (new Collection($constraint))->every(function ($value, $key) use (
            $params
        ) {
            // Using array_key_exists for strictness, but allowing null values to match if key exists and is null.
            return array_key_exists($key, $params) && $params[$key] === $value;
        });

        if (is_callable($constraint)) {
            $callback = $constraint;
        }

        return array_filter($requestsByMethod, fn (TestRequest $request) => $callback($request->parameters()));
    }

    /**
     * @return array<array-key, TestRequest>
     */
    private function sentByMethod(string $method): array
    {
        return array_filter($this->getApi()->getRequests(),
            fn (TestRequest $request): bool => $request->method() === $method);
    }

    public function recordCommandHandled(string $commandName, array $arguments = []): void
    {
        $this->processedCommands[] = [
            'name' => strtolower($commandName),
            'arguments' => $arguments,
        ];
    }

    public function getProcessedCommands(string|array|null $commandNames = null): array
    {
        if ($commandNames === null) {
            return $this->processedCommands;
        }

        $filterNames = is_array($commandNames)
            ? array_map('strtolower', $commandNames)
            : [strtolower($commandNames)];

        return array_filter(
            $this->processedCommands,
            fn ($record) => in_array($record['name'], $filterNames, true)
        );
    }

    private function isEmpty(): bool
    {
        return $this->responses === [];
    }

    public function bot(?string $string = null): static
    {
        return $this;
    }

    public function failWhenEmpty(): static
    {
        $this->getApi()->failWhenEmpty();

        return $this;
    }

    public function __call($method, $parameters)
    {
        // If it's one of our assertion methods, let them run.
        if (method_exists($this, $method) && str_starts_with($method, 'assert')) {
            return call_user_func_array([$this, $method], $parameters);
        }

        // Proxy all other calls to ApiFake's fakeCall method to avoid hitting real API methods
        return $this->getApi()->fakeCall($method, $parameters[0] ?? []);
    }
}
