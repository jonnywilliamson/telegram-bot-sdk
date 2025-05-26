<?php

namespace Tests\Unit\Testing;

// Standard for PHPUnit, Pest can work with it or without if using its own helpers
use Telegram\Bot\Testing\BotFake;

// Pest functions are typically available globally if Pest is the test runner.
// No `use function Pest\Laravel\…` or similar is needed unless using specific Pest plugin functions.

it('allows fluent interface for command registration', function () {
    $fakeBot = new BotFake;

    // Call the command() method
    $result = $fakeBot->command('dummy', function () {
        return 'dummy output';
    });

    // Assert that the returned value from command() is the same instance as $fakeBot
    expect($result)->toBeInstanceOf(BotFake::class);
    expect($result)->toBe($fakeBot);

    // Optionally, add another chain to verify
    $anotherResult = $fakeBot->command('another', function () {})->addResponses(['some_mock_response']);
    expect($anotherResult)->toBeInstanceOf(BotFake::class);
    expect($anotherResult)->toBe($fakeBot); // Check if it's still the same original $fakeBot instance
});

// A simple test to ensure BotFake can be instantiated, as a sanity check.
it('can be instantiated', function () {
    expect(new BotFake)->toBeInstanceOf(BotFake::class);
});
