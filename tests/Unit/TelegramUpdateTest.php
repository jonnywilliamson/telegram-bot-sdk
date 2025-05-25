<?php

use Illuminate\Support\Collection;
use Telegram\Bot\Objects\ResponseObject;
use Telegram\Bot\Testing\Responses\TelegramUpdate;

describe('TelegramUpdate Magic Methods', function () {
    it('supports withXYZ magic methods for payload customization', function () {
        $customChatId = '555666777';
        $customUserId = '111222333';

        $update = TelegramUpdate::create()->textMessage('Test message')
            ->withMessage(['chat' => ['id' => $customChatId]])
            ->withMessage(['from' => ['id' => $customUserId]])->get();

        expect($update)->toBeInstanceOf(ResponseObject::class)
            ->and($update->message->chat->id)->toBe($customChatId)
            ->and($update->message->from->id)->toBe($customUserId)
            ->and($update->message->text)->toBe('Test message');
    });

    it('converts CamelCase to snake_case in magic methods', function () {
        $update = TelegramUpdate::create()->textMessage('Test')
            ->withUpdateId(999888777)->get();

        expect($update->update_id)->toBe(999888777);
    });

    it('can chain multiple withXYZ methods', function () {
        $update = TelegramUpdate::create()->commandMessage('test')
            ->withUpdateId(123)
            ->withMessage(['date' => 1640995200])
            ->withMessage(['chat' => ['type' => 'group']])->get();

        expect($update->update_id)->toBe(123)
            ->and($update->message->date)->toBe(1640995200)
            ->and($update->message->chat->type)->toBe('group');
    });

    it('throws exception when calling withXYZ before setting payload template', function () {
        $class = new \ReflectionClass(TelegramUpdate::class);
        $instance = $class->newInstanceWithoutConstructor();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No base payload template set');

        $instance->withMessage(['some' => 'data']);
    });
});

describe('TelegramUpdate Callback Query Payloads', function () {
    it('generates fully resolved callback query ResponseObject with TelegramUpdate::callbackQuery', function () {
        $update = TelegramUpdate::create()->callbackQuery('test_callback')->get();

        expect($update)->toBeInstanceOf(ResponseObject::class);

        $cq = $update->callback_query;
        expect($cq)->toBeObject()
            ->and($cq->toArray())->toHaveKeys(['id', 'from', 'message', 'chat_instance', 'data'])
            ->and($cq->data)->toBe('test_callback');

        $message = $cq->message;
        expect($message)->toBeObject()
            ->and($message->toArray())->toHaveKey('text')
            ->and(is_string($message->text))->toBeTrue()
            ->and($cq->from->is_bot)->toBeFalse()
            ->and($message->from->is_bot)->toBeTrue();
    });

    it('correctly merges custom data in callback query payload', function () {
        $customData = 'custom_callback_data';
        $customChatId = 987654321;

        $update = TelegramUpdate::create()->callbackQuery($customData, [
            'callback_query' => [
                'data' => $customData,
                'message' => [
                    'chat' => ['id' => $customChatId],
                ],
            ],
            'update_id' => 123456789,
        ])->get();

        expect($update->update_id)->toBe(123456789)
            ->and($update->callback_query->data)->toBe($customData)
            ->and($update->callback_query->message->chat->id)->toBe($customChatId);
    });

    it('can generate multiple callback queries consistently using times()', function () {
        $updates = TelegramUpdate::create()->callbackQuery('multi_test')->times(3)->asCollection();

        expect($updates)->toBeInstanceOf(Collection::class)
            ->and($updates)->toHaveCount(3);

        $updates->each(function ($update) {
            expect($update)->toBeInstanceOf(ResponseObject::class)
                ->and($update->callback_query->data)->toBe('multi_test');
        });
    });

    it('produces correct JSON and array representations', function () {
        $responseObj = TelegramUpdate::create()->callbackQuery('json_test')->get();

        $array = $responseObj->toArray();
        expect($array)->toBeArray()
            ->and($array['callback_query']['data'])->toBe('json_test');

        $json = TelegramUpdate::create()->callbackQuery('json_test')->asJson();

        expect($json)->toBeString();

        $decoded = json_decode($json, true);
        expect($decoded['callback_query']['data'])->toBe('json_test');
    });
});

describe('TelegramUpdate Fluent Interface', function () {
    it('can chain multiple withXYZ methods and resets state after get', function () {
        $update = TelegramUpdate::create()->commandMessage('test')
            ->withUpdateId(123)
            ->withMessage(['date' => 1640995200])
            ->withMessage(['chat' => ['type' => 'group']]);

        $result = $update->get();

        $this->assertEquals(123, $result->update_id);
        $this->assertEquals(1640995200, $result->message->date);
        $this->assertEquals('group', $result->message->chat->type);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No base payload template set');

        $update->withMessage(['test' => 'fail']);
    });
});
