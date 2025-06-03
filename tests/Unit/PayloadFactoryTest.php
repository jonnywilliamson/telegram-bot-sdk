<?php

namespace Tests\Unit;

use Illuminate\Support\Collection;
use Telegram\Bot\Objects\ResponseObject;
use Telegram\Bot\Testing\Responses\TelegramUpdate;

// --- Tests for PayloadFactory ---
describe('PayloadFactory', function () {
    it('generates a complete command message update payload', function () {
        $update = TelegramUpdate::create()->commandMessage('testcommand', 'arg1 value')->get();

        expect($update)->toBeInstanceOf(ResponseObject::class)
            ->and($update->update_id)->toBeInt()
            ->and($update->message)->toBeObject()
            ->and($update->message->text)->toBe('/testcommand arg1 value')
            ->and($update->message->entities)->toHaveCount(1)
            ->and($update->message->entities[0]->toArray())->toMatchArray([
                'offset' => 0,
                'length' => 12,
                'type' => 'bot_command',
            ])
            ->and($update->message->from)->toBeObject()
            ->and($update->message->chat)->toBeObject()
            ->and($update->message->date)->toBeInt();
    });

    it('generates a complete plain text message update payload', function () {
        $text = 'This is a test message.';
        $update = TelegramUpdate::create()->textMessage($text)->get();

        expect($update)->toBeInstanceOf(ResponseObject::class)
            ->and($update->message->text)->toBe($text)
            ->and($update->message->entities)->toBeEmpty();
    });

    it('generates a complete callback query update payload', function () {
        $data = 'action_item_123';
        $update = TelegramUpdate::create()->callbackQuery($data)->get();

        expect($update)->toBeInstanceOf(ResponseObject::class)
            ->and($update->callback_query)->toBeObject()
            ->and($update->callback_query->data)->toBe($data)
            ->and((string) $update->callback_query->id)->toHaveLength(16)
            ->and($update->callback_query->from)->toBeObject()
            ->and($update->callback_query->message)->toBeObject();
    });

    it('generates a complete photo message update payload', function () {
        $update = TelegramUpdate::create()->photoMessage()->get();

        expect($update)->toBeInstanceOf(ResponseObject::class)
            ->and($update->message)->toBeObject()
            ->and($update->message->photo)->toHaveCount(1)
            ->and($update->message->photo[0])->toBeObject()
            ->and($update->message->photo[0]->file_id)->toBeString()->toHaveLength(36)
            ->and($update->message->photo[0]->file_unique_id)->toBeString()->toHaveLength(16)
            ->and($update->message->photo[0]->width)->toBeInt()
            ->and($update->message->photo[0]->height)->toBeInt()
            ->and($update->message->photo[0]->file_size)->toBeInt();
    });

    it('generates a complete document message update payload', function () {
        $update = TelegramUpdate::create()->documentMessage()->get();

        expect($update)->toBeInstanceOf(ResponseObject::class)
            ->and($update->message)->toBeObject()
            ->and($update->message->document)->toBeObject()
            ->and($update->message->document->file_id)->toBeString()->toHaveLength(36)
            ->and($update->message->document->file_unique_id)->toBeString()->toHaveLength(16)
            ->and($update->message->document->file_name)->toBeString()
            ->and($update->message->document->mime_type)->toBeString()->toContain('/')
            ->and($update->message->document->file_size)->toBeInt();
    });

    it('merges custom payload data correctly', function () {
        $userId = '987654321';
        $chatId = '1122334455';
        $customText = 'Override this text!';

        $update = TelegramUpdate::create()->textMessage('Original text', [
            'message' => [
                'from' => ['id' => $userId],
                'chat' => ['id' => $chatId],
                'text' => $customText,
            ],
            'update_id' => 123456789,
        ])->get();

        expect($update->update_id)->toBe(123456789)
            ->and($update->message->from->id)->toBe($userId)
            ->and($update->message->chat->id)->toBe($chatId)
            ->and($update->message->text)->toBe($customText);
    });

    it('can generate multiple payloads using times()', function () {
        $updates = TelegramUpdate::create()->times(3)->textMessage('Hello')->asCollection();

        expect($updates)->toBeInstanceOf(Collection::class)->toHaveCount(3);

        $updates->each(function ($update) {
            expect($update)->toBeInstanceOf(ResponseObject::class)
                ->and($update->message->text)->toBe('Hello');
        });
    });

    it('handles different payload generation and conversion types', function () {
        // Test raw array
        $payload = TelegramUpdate::create()->textMessage('raw')->toArray();
        expect($payload)->toBeArray()
            ->and($payload['message']['text'])->toBe('raw');

        // Test result format
        $result = TelegramUpdate::create()->textMessage('result')->asResult();
        expect($result)->toBeArray()
            ->and($result['ok'])->toBeTrue()
            ->and($result['result']['message']['text'])->toBe('result');

        // Test ResponseObject (DEFAULT BEHAVIOUR)
        $responseObject = TelegramUpdate::create()->textMessage('response object')->get();
        expect($responseObject)->toBeInstanceOf(ResponseObject::class)
            ->and($responseObject->message->text)->toBe('response object');

        // Test JSON string
        $json = TelegramUpdate::create()->textMessage('json')->asJson();
        expect($json)->toBeString();
        $decodedJson = json_decode($json, true);
        expect($decodedJson['message']['text'])->toBe('json');
    });

    it('handles times() with different output formats consistently', function () {
        // Multiple items as array
        $arrays = TelegramUpdate::create()->times(2)->textMessage('Hello')->toArray();
        expect($arrays)->toBeArray()->toHaveCount(2)
            ->and($arrays[0]['message']['text'])->toBe('Hello')
            ->and($arrays[1]['message']['text'])->toBe('Hello');

        // Multiple items as ResponseObject array
        $responseObjects = TelegramUpdate::create()->times(2)->textMessage('Hello')->get();
        expect($responseObjects)->toBeArray()->toHaveCount(2)
            ->and($responseObjects[0])->toBeInstanceOf(ResponseObject::class)
            ->and($responseObjects[1])->toBeInstanceOf(ResponseObject::class)
            ->and($responseObjects[0]->message->text)->toBe('Hello')
            ->and($responseObjects[1]->message->text)->toBe('Hello');

        // Single item as ResponseObject
        $singleResponse = TelegramUpdate::create()->times(1)->textMessage('Hello')->get();
        expect($singleResponse)->toBeInstanceOf(ResponseObject::class)
            ->and($singleResponse->message->text)->toBe('Hello');
    });

    it('resets count and template after generation', function () {
        // Generate first payload with times(3)
        $firstBatch = TelegramUpdate::create()->times(3)->textMessage('First')->asCollection();
        expect($firstBatch)->toHaveCount(3);

        // Generate second payload without times() - should default to 1
        $secondBatch = TelegramUpdate::create()->textMessage('Second')->asCollection();
        expect($secondBatch)->toHaveCount(1)
            ->and($secondBatch->first()->message->text)->toBe('Second');
    });

    it('can use seed for reproducible results', function () {
        $first = TelegramUpdate::create()->textMessage('test')->seed(999)->get();
        $second = TelegramUpdate::create()->textMessage('test')->seed(999)->get();

        expect($first->update_id)->toBe($second->update_id)
            ->and($first->message->from->id)->toBe($second->message->from->id)
            ->and($first->message->chat->id)->toBe($second->message->chat->id);
    });

    it('generates different results without seed', function () {
        $first = TelegramUpdate::create()->textMessage('test')->get();
        $second = TelegramUpdate::create()->textMessage('test')->get();

        expect($first->update_id)->not->toBe($second->update_id);
    });

    it('can call payload methods directly', function () {
        $user = TelegramUpdate::create()->user()->get();
        expect($user)->toBeInstanceOf(ResponseObject::class)
            ->and($user->id)->toBeInt()
            ->and($user->first_name)->toBeString();

        $message = TelegramUpdate::create()->message()->get();
        expect($message)->toBeInstanceOf(ResponseObject::class)
            ->and($message->message_id)->toBeInt()
            ->and($message->text)->toBeString()
            ->and($message->from)->toBeObject()
            ->and($message->chat)->toBeObject();
    });
});
