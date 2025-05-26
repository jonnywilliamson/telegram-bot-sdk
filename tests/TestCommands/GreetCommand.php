<?php

namespace Tests\TestCommands;

use Telegram\Bot\Commands\Command;
use Telegram\Bot\Objects\Update;

class GreetCommand extends Command
{
    protected string $name = 'greet';

    protected string $description = 'Greets a person.';

    public function handle(array $arguments): void
    {
        $name = $arguments[0] ?? 'stranger';

        // Ensure the update object is available
        $update = $this->getUpdate();
        if (! $update instanceof Update || ! $update->getMessage()) {
            // Optionally log or throw an error if update is not as expected
            return;
        }

        $chatId = $update->getMessage()->getChat()->getId();

        $this->replyWithMessage([
            'chat_id' => $chatId,
            'text' => "Hello, {$name}!",
        ]);
    }
}
