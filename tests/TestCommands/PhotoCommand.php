<?php

namespace Tests\TestCommands;

use Telegram\Bot\Commands\Command;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Objects\Update;

class PhotoCommand extends Command
{
    protected string $name = 'photo';
    protected string $description = 'Sends a photo.';

    public function handle(array $arguments): void
    {
        // Ensure the update object is available
        $update = $this->getUpdate();
        if (! $update instanceof Update || ! $update->getMessage()) {
            // Optionally log or throw an error if update is not as expected
            return;
        }

        $chatId = $update->getMessage()->getChat()->getId();

        $this->replyWithPhoto([
            'chat_id' => $chatId,
            'photo'   => InputFile::create(__DIR__.'/../assets/test_image.jpg', 'test_image.jpg'),
            'caption' => 'Here is your photo!',
        ]);
    }
}
