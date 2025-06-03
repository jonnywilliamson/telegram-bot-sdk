<?php

namespace Telegram\Bot\Testing\Responses;

use Faker\Factory as Faker;
use Faker\Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Telegram\Bot\Objects\ResponseObject;

/**
 * @mixin Payload
 */
final class TelegramUpdate
{
    private int $count = 1;

    private ?int $seed = null;

    private ?array $basePayloadTemplate = null;

    private function __construct() {}

    public static function create(): self
    {
        return new self;
    }

    public function __invoke(): ResponseObject|array
    {
        return $this->get();
    }

    // Instance methods to configure generation count and seed
    public function asCollection(): Collection
    {
        $payloads = $this->generatePayloads();

        return new Collection(array_map(fn ($p) => ResponseObject::make($p), $payloads));
    }

    public function asResult(): array
    {
        $payloads = $this->generatePayloads();
        $count = count($payloads);

        return [
            'ok' => true,
            'result' => $count === 1 ? $payloads[0] : $payloads,
        ];
    }

    public function asJson(): string
    {
        $payloads = $this->generatePayloads();
        if (count($payloads) === 1) {
            return ResponseObject::make($payloads[0])->__toJson();
        }

        return json_encode(array_map(fn ($p) => ResponseObject::make($p)->toArray(), $payloads));
    }

    public function toArray(): array
    {
        $payloads = $this->generatePayloads();

        return count($payloads) === 1 ? $payloads[0] : $payloads;
    }

    public function faker(): Generator
    {
        $faker = Faker::create(Faker::DEFAULT_LOCALE);
        if ($this->seed !== null) {
            $faker->seed($this->seed);
        }
        $faker->addProvider(new TelegramFakerProvider($faker));

        return $faker;
    }

    public function commandMessage(string $commandName, ?string $args = null, array $mergePayload = []): self
    {
        $fullCommand = '/'.$commandName.($args ? ' '.$args : '');
        $template = Payload::create()->update();
        $baseMessageTemplate = Payload::create()->message();

        $messageSpecifics = [
            'text' => $fullCommand,
            'entities' => $this->faker()->commandEntities($fullCommand),
        ];

        $mergedMessageSpecifics = $this->mergePayloads($baseMessageTemplate, $messageSpecifics);
        $template['message'] = $mergedMessageSpecifics;

        $this->basePayloadTemplate = $this->mergePayloads($template, $mergePayload);

        return $this;
    }

    public function textMessage(string $text, array $mergePayload = []): self
    {
        $template = Payload::create()->update();
        $baseMessageTemplate = Payload::create()->message();

        $messageSpecifics = [
            'text' => $text,
            'entities' => [],
        ];

        $mergedMessageSpecifics = $this->mergePayloads($baseMessageTemplate, $messageSpecifics);
        $template['message'] = $mergedMessageSpecifics;
        $this->basePayloadTemplate = $this->mergePayloads($template, $mergePayload);

        return $this;
    }

    public function callbackQuery(string $data, array $mergePayload = []): self
    {
        $template = Payload::create()->update();
        $baseCallbackQueryTemplate = Payload::create()->callbackQuery();
        $baseMessageTemplate = Payload::create()->message();

        // Fully resolve message template to get commands, text, etc.
        $resolvedMessage = $this->makeWithFaker($baseMessageTemplate);

        $baseCallbackQueryTemplate['message'] = $resolvedMessage;
        $baseCallbackQueryTemplate['data'] = $data;

        $template['callback_query'] = $baseCallbackQueryTemplate;

        $this->basePayloadTemplate = $this->mergePayloads($template, $mergePayload);

        return $this;
    }

    public function photoMessage(array $mergePayload = []): self
    {
        $template = Payload::create()->update();
        $baseMessageTemplate = Payload::create()->message();
        $basePhotoTemplate = Payload::create()->photo();

        $messageSpecifics = [
            'photo' => [$basePhotoTemplate],
        ];

        $mergedMessageSpecifics = $this->mergePayloads($baseMessageTemplate, $messageSpecifics);
        $template['message'] = $mergedMessageSpecifics;

        $this->basePayloadTemplate = $this->mergePayloads($template, $mergePayload);

        return $this;
    }

    public function documentMessage(array $mergePayload = []): self
    {
        $template = Payload::create()->update();
        $baseMessageTemplate = Payload::create()->message();
        $baseDocumentTemplate = Payload::create()->document();

        $messageSpecifics = [
            'document' => $baseDocumentTemplate,
        ];

        $mergedMessageSpecifics = $this->mergePayloads($baseMessageTemplate, $messageSpecifics);
        $template['message'] = $mergedMessageSpecifics;

        $this->basePayloadTemplate = $this->mergePayloads($template, $mergePayload);

        return $this;
    }

    public function get(): ResponseObject|array
    {
        $payloads = $this->generatePayloads();
        if (count($payloads) === 1) {
            return ResponseObject::make($payloads[0]);
        }

        return array_map(fn ($p) => ResponseObject::make($p), $payloads);
    }

    public function times(int $count): self
    {
        $this->count = $count;

        return $this;
    }

    public function seed(int $seed): self
    {
        $this->seed = $seed;

        return $this;
    }

    public function __call(string $name, array $arguments)
    {
        // Handle withXYZ() methods
        if (str_starts_with($name, 'with') && count($arguments) > 0) {
            $key = Str::snake(substr($name, 4)); // Remove 'with' and convert to snake_case
            $value = $arguments[0];

            if ($this->basePayloadTemplate === null) {
                throw new RuntimeException('No base payload template set. Call a payload generation method first.');
            }

            $this->basePayloadTemplate = $this->mergePayloads($this->basePayloadTemplate, [$key => $value]);

            return $this;
        }

        if (method_exists(Payload::class, $name)) {
            $this->basePayloadTemplate = Payload::create()->{$name}(...$arguments);

            return $this;
        }

        throw new RuntimeException("Method {$name} does not exist in PayloadFactory or Payload.");
    }

    /**
     * Internal method to perform the actual payload generation loop and faker replacement.
     */
    private function generatePayloads(): array
    {
        if ($this->basePayloadTemplate === null) {
            throw new RuntimeException('No base payload template set. Call a payload generation method like commandMessage(), textMessage(), or user() first.');
        }

        $currentCount = $this->count;
        $generatedPayloads = [];
        for ($i = 0; $i < $currentCount; $i++) {
            $data = $this->makeWithFaker($this->basePayloadTemplate);
            $generatedPayloads[] = $data;
        }

        // Reset state after generation
        $this->resetState();

        return $generatedPayloads;
    }

    private function resetState(): void
    {
        $this->basePayloadTemplate = null;
        $this->count = 1;
    }

    private function makeWithFaker(array $payloadFormat): array
    {
        return (new Collection($payloadFormat))->map(function ($value, $key) {
            if (is_string($value)) {
                $format = Str::of($value)->explode(':');
                $method = $format->shift();
                $args = $format->toArray();

                try {
                    return $this->faker()->$method(...$args);
                } catch (InvalidArgumentException) {
                    if (method_exists(Payload::class, $method) && empty($args)) {
                        return $this->makeWithFaker(Payload::create()->{$method}());
                    }

                    return $value;
                }
            }

            if (is_array($value)) {
                return $this->makeWithFaker($value);
            }

            return $value;
        })->toArray();
    }

    private function mergePayloads(array $array1, array $array2): array
    {
        $merged = $array1;
        foreach ($array2 as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = $this->mergePayloads($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }
}
