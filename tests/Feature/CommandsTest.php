<?php

namespace Tests\Feature;

use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Testing\BotFake;
use Telegram\Bot\Testing\Responses\PayloadFactory;
use Tests\TestCommands\GreetCommand;
use Tests\TestCommands\PhotoCommand;

beforeAll(function () {
    // Ensure the assets directory and dummy file exist before any tests run
    if (! is_dir(__DIR__.'/../assets')) {
        mkdir(__DIR__.'/../assets', 0777, true);
    }
    if (! file_exists(__DIR__.'/../assets/test_image.jpg')) {
        touch(__DIR__.'/../assets/test_image.jpg');
    }
});

afterAll(function () {
    // Clean up the dummy file and directory after all tests in this file have run
    if (file_exists(__DIR__.'/../assets/test_image.jpg')) {
        unlink(__DIR__.'/../assets/test_image.jpg');
    }
    if (is_dir(__DIR__.'/../assets') && count(scandir(__DIR__.'/../assets')) == 2) { // Check if directory is empty
        rmdir(__DIR__.'/../assets');
    }
});

it('sends a photo when /photo command is received without arguments', function () {
    $fakeBot = new BotFake;
    $fakeBot->command('photo', PhotoCommand::class);

    $chatId = 123456789;
    $userId = 987654321;
    $firstName = 'TestUser';

    // Simulate an update for the /photo command
    $update = PayloadFactory::create()
        ->message([
            'text' => '/photo',
            'from' => ['id' => $userId, 'first_name' => $firstName, 'is_bot' => false],
            'chat' => ['id' => $chatId, 'type' => 'private', 'first_name' => $firstName],
            'entities' => [
                ['type' => 'bot_command', 'offset' => 0, 'length' => 6], // Explicitly define /photo as a command
            ],
        ])
        ->asResponseObject();

    $fakeBot->receiveUpdate($update);

    // Assert that sendPhoto was called with the correct parameters
    $fakeBot->assertSent('sendPhoto', function ($params) use ($chatId) {
        expect($params['chat_id'])->toBe($chatId);
        expect($params['photo'])->toBeInstanceOf(InputFile::class);
        expect($params['caption'])->toBe('Here is your photo!');

        // Verify the filename of the InputFile if necessary, though path might be tricky
        // due to how InputFile is created. For now, type and caption are good checks.
        /** @var InputFile $inputFile */
        $inputFile = $params['photo'];
        expect($inputFile->getFilename())->toBe('test_image.jpg');

        return true; // Indicate that the assertion parameters matched
    });
});

it('processes multiple commands in a single message', function () {
    $fakeBot = new BotFake;
    $fakeBot->command('greet', GreetCommand::class);
    $fakeBot->command('photo', PhotoCommand::class);

    $chatId = 123456789;
    $userId = 987654321;
    $firstName = 'TestUser';
    $nameArgument = 'Alice';

    $fullMessageText = "/greet {$nameArgument} /photo";

    // Calculate offsets and lengths for entities
    $greetCommand = '/greet';
    $photoCommand = '/photo';

    $greetOffset = strpos($fullMessageText, $greetCommand);
    $greetLength = strlen($greetCommand);

    $photoOffset = strpos($fullMessageText, $photoCommand);
    $photoLength = strlen($photoCommand);

    // Simulate an update with multiple commands
    $update = PayloadFactory::create()
        ->message([
            'message_id' => 1001,
            'text' => $fullMessageText,
            'from' => ['id' => $userId, 'first_name' => $firstName, 'is_bot' => false, 'username' => 'testuser'],
            'chat' => ['id' => $chatId, 'type' => 'private', 'first_name' => $firstName, 'username' => 'testuser'],
            'date' => time(),
            'entities' => [
                ['type' => 'bot_command', 'offset' => $greetOffset, 'length' => $greetLength],
                ['type' => 'bot_command', 'offset' => $photoOffset, 'length' => $photoLength],
            ],
        ])
        ->asResponseObject();

    $fakeBot->receiveUpdate($update);

    // Assert GreetCommand was processed
    $fakeBot->assertSent('sendMessage', function ($params) use ($chatId, $nameArgument) {
        expect($params['chat_id'])->toBe($chatId);
        expect($params['text'])->toBe("Hello, {$nameArgument}!");

        return true;
    });

    // Assert PhotoCommand was processed
    $fakeBot->assertSent('sendPhoto', function ($params) use ($chatId) {
        expect($params['chat_id'])->toBe($chatId);
        expect($params['photo'])->toBeInstanceOf(InputFile::class);
        expect($params['caption'])->toBe('Here is your photo!');
        /** @var InputFile $inputFile */
        $inputFile = $params['photo'];
        expect($inputFile->getFilename())->toBe('test_image.jpg');

        return true;
    });
});

it('greets a user with provided name when /greet [name] command is received', function () {
    $fakeBot = new BotFake;
    $fakeBot->command('greet', GreetCommand::class);

    $chatId = 123456789;
    $userId = 987654321;
    $firstName = 'TestUser';
    $nameArgument = 'Jules';

    // Simulate an update for the /greet Jules command
    $update = PayloadFactory::create()
        ->message([
            'text' => "/greet {$nameArgument}",
            'from' => ['id' => $userId, 'first_name' => $firstName, 'is_bot' => false],
            'chat' => ['id' => $chatId, 'type' => 'private', 'first_name' => $firstName],
            // PayloadFactory automatically adds bot_command entity for /greet
        ])
        ->asResponseObject();

    $fakeBot->receiveUpdate($update);

    // Assert that sendMessage was called with the correct parameters
    $fakeBot->assertSent('sendMessage', function ($params) use ($chatId, $nameArgument) {
        expect($params['chat_id'])->toBe($chatId);
        expect($params['text'])->toBe("Hello, {$nameArgument}!");

        return true;
    });
});

it('greets "stranger" when /greet command is received without arguments', function () {
    $fakeBot = new BotFake;
    $fakeBot->command('greet', GreetCommand::class);

    $chatId = 123456789;
    $userId = 987654321;
    $firstName = 'TestUser';

    // Simulate an update for the /greet command
    $update = PayloadFactory::create()
        ->message([
            'text' => '/greet',
            'from' => ['id' => $userId, 'first_name' => $firstName, 'is_bot' => false],
            'chat' => ['id' => $chatId, 'type' => 'private', 'first_name' => $firstName],
            // PayloadFactory automatically adds bot_command entity for /greet
        ])
        ->asResponseObject();

    $fakeBot->receiveUpdate($update);

    // Assert that sendMessage was called with the correct parameters
    $fakeBot->assertSent('sendMessage', function ($params) use ($chatId) {
        expect($params['chat_id'])->toBe($chatId);
        expect($params['text'])->toBe('Hello, stranger!');

        return true;
    });
});
