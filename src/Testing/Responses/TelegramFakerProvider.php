<?php

namespace Telegram\Bot\Testing\Responses;

use Exception;
use Faker\Provider\Base;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class TelegramFakerProvider extends Base
{
    public function id(int $digits = 9): int
    {
        return $this->generator->randomNumber($digits);
    }

    public function botName(): string
    {
        return $this->generator->firstName().' Bot';
    }

    public function botUserName(): string
    {
        return $this->generator->firstName().'Bot';
    }

    /**
     * @return array{id: int, is_bot: false, first_name: string, last_name: string, username: string, language_code: string}
     */
    public function from(): array
    {
        return [
            'id'            => $this->generator->randomNumber(9),
            'is_bot'        => false,
            'first_name'    => $this->generator->firstName(),
            'last_name'     => $this->generator->lastName(),
            'username'      => $this->generator->userName(),
            'language_code' => $this->generator->languageCode(),
        ];
    }

    /**
     * @return array{id: int, first_name: string, last_name: string, username: string, type: string}
     */
    public function chat(): array
    {
        return [
            'id'         => $this->generator->randomNumber(9),
            'first_name' => $this->generator->firstName(),
            'last_name'  => $this->generator->lastName(),
            'username'   => $this->generator->userName(),
            'type'       => 'private',
        ];
    }

    /**
     * @return array{id: int, is_bot: true, first_name: mixed, username: mixed}
     */
    public function botFrom(): array
    {
        return [
            'id'         => $this->generator->randomNumber(9),
            'is_bot'     => true,
            'first_name' => $this->botName(),
            'username'   => $this->botUserName(),
        ];
    }

    public function command(?string $command = null): string
    {
        if (str_contains($command, '?')) {
            return '/'.$this->generator->bothify($command);
        }

        if (str_contains($command, '#')) {
            return '/'.$this->generator->bothify($command);
        }

        $command ??= $this->generator->word();

        return '/'.$command;
    }

    public function commandWithArgs(?string $command = null, string ...$args): string
    {
        $arguments = (new Collection($args))->map(function ($arg) {
            if (str_starts_with($arg, 'faker-')) {
                return $this->fakerArg(str_replace('faker-', '', $arg));
            }

            return $arg;
        })->implode(' ');

        return trim($this->command($command).' '.$arguments);
    }

    private function fakerArg(string $name)
    {
        try {
            if (! str_contains($name, '-')) {
                return $this->generator->$name();
            }

            [$method, $arg] = explode('-', $name, 2);

            return $this->generator->$method($arg);
        } catch (Exception) {
            return $name;
        }
    }

    /**
     * Generates a command entities array for a given command text.
     *
     * @param string|null $commandText The full command text, e.g., "/start" or "/help arg1"
     * @return array<array{offset: int, length: int, type: string}>
     */
    public function commandEntities(?string $commandText = null): array
    {
        if ($commandText === null || !Str::startsWith($commandText, '/')) {
            return [];
        }

        $offset = 0; // Commands always start at offset 0

        // Find the end of the command name (first space or end of string)
        // Use Str::position instead of Str::indexOf
        $firstSpace = Str::position($commandText, ' ');
        $length = ($firstSpace === false) ? Str::length($commandText) : $firstSpace; // Use Str::length for multi-byte safe length

        return [
            [
                'offset' => $offset,
                'length' => $length,
                'type' => 'bot_command',
            ],
        ];
    }
}
