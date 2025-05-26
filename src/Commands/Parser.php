<?php

namespace Telegram\Bot\Commands;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Telegram\Bot\Commands\Contracts\CallableContract;
use Telegram\Bot\Commands\Contracts\CommandContract;
use Telegram\Bot\Exceptions\TelegramCommandException;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Helpers\Entity;
use Telegram\Bot\Helpers\Reflector;
use Telegram\Bot\Traits\HasUpdate;

/**
 * Class Parser
 */
final class Parser
{
    use HasUpdate;

    /** @var array|null Details of the current entity this command is responding to - offset, length, type etc */
    private ?array $entity = null;

    private CommandContract|string $command;

    /** @var Collection|null Hold command params */
    private ?Collection $params = null;

    public static function parse(CommandContract|string $command): self
    {
        return (new self)->setCommand($command);
    }

    public function getCommand(): CommandContract|string
    {
        return $this->command;
    }

    /**
     * @return $this
     */
    public function setCommand(CommandContract|string $command): self
    {
        $this->command = $command;

        return $this;
    }

    public function getEntity(): ?array
    {
        return $this->entity;
    }

    public function setEntity(?array $entity): self
    {
        $this->entity = $entity;

        return $this;
    }

    /**
     * Parse Command Arguments.
     *
     * @throws TelegramCommandException|TelegramSDKException
     */
    public function arguments(): array
    {
        $fullMessageText = Entity::from($this->getUpdate())->text();
        $currentEntity = $this->getEntity();

        if ($currentEntity === null) {
            // This case should ideally not happen if setEntity is always called.
            // However, if it does, it means we don't know which command to parse args for.
            return [];
        }

        // relevantSubString gives the text from the start of the current command
        // up to the start of the next command, or to the end of the message.
        $commandRelevantText = $this->relevantSubString($fullMessageText);

        // The command itself (e.g., /mycommand) is at the beginning of commandRelevantText.
        // Its length is given by $currentEntity['length'].
        // We need to adjust length if the command was part of a group like /command@botname
        // The $currentEntity['length'] refers to the length of the command in the original message text.
        // When relevantSubString is used, it effectively "starts" the string at the command's beginning.
        // So, the command part within commandRelevantText has length $currentEntity['length'].
        $commandLocalLength = $currentEntity['length'];

        // Extract the substring after the command.
        $rawArgumentsString = mb_substr($commandRelevantText, $commandLocalLength, null, 'UTF-8');

        $trimmedArgsString = trim($rawArgumentsString);

        if ($trimmedArgsString === '') {
            return [];
        }

        // Split arguments by one or more spaces.
        return preg_split('/\s+/', $trimmedArgsString);
    }

    /**
     * Get all command handle params except type-hinted classes.
     *
     * @throws TelegramCommandException
     */
    public function allParams(): Collection
    {
        if ($this->params instanceof Collection) {
            return $this->params;
        }

        if ($this->command instanceof CallableContract) {
            $params = Reflector::getParameters($this->command->getCommandHandler());
        } else {
            try {
                $handle = new ReflectionMethod($this->command, 'handle');
                $params = $handle->getParameters();
            } catch (ReflectionException $e) {
                throw TelegramCommandException::commandMethodDoesNotExist($e);
            }
        }

        return $this->params = collect($params)
            ->reject(function (ReflectionParameter $param): ReflectionClass|null|bool {
                $type = $param->getType();
                if (! $type instanceof ReflectionType) {
                    return false;
                }
                if ($type instanceof ReflectionNamedType && $type->isBuiltin()) {
                    return false;
                }

                if ($type instanceof ReflectionUnionType) {
                    return collect($type->getTypes())
                        ->reject(fn (ReflectionNamedType $namedType): bool => ! $namedType->isBuiltin())
                        ->isEmpty();
                }

                return true;
            });
    }

    /**
     * Get all REQUIRED params of a command handle.
     *
     * @throws TelegramCommandException
     */
    public function requiredParams(): Collection
    {
        return $this->allParams()
            ->reject(fn ($parameter): bool => $parameter->isDefaultValueAvailable() || $parameter->isVariadic())
            ->pluck('name');
    }

    /**
     * Get params that are required but have not been provided.
     *
     *
     * @throws TelegramCommandException
     */
    public function requiredParamsNotProvided(array $params): Collection
    {
        return $this->requiredParams()->diff($params)->values();
    }

    /**
     * Get Nullified Regex Params.
     *
     * @throws TelegramCommandException
     */
    public function nullifiedRegexParams(): Collection
    {
        return $this->allParams()
            ->filter(fn (ReflectionParameter $param): bool => $this->isRegexParam($param))
            ->mapWithKeys(fn (ReflectionParameter $param): array => [$param->getName() => null]);
    }

    /**
     * Make command arguments regex pattern.
     *
     * @throws TelegramCommandException
     */
    private function argumentsPattern(): string
    {
        $pattern = $this->allParams()->map(function (ReflectionParameter $param): string {
            $regex = $this->isRegexParam($param)
                ? self::between($param->getDefaultValue(), '{', '}')
                : '[^ ]++';

            return sprintf('(?P<%s>%s)?', $param->getName(), $regex);
        })->implode('(?:\s+)?');

        // Ex: /start@Somebot <arg> ...<arg>
        // Ex: /start <arg> ...<arg>
        return "%/[\w]+(?:@.+?bot)?(?:\s+)?{$pattern}%si";
    }

    private function isRegexParam(ReflectionParameter $param): bool
    {
        if (! $param->isDefaultValueAvailable()) {
            return false;
        }

        return Str::is('{*}', $param->getDefaultValue());
    }

    /**
     * @throws TelegramSDKException
     */
    private function relevantSubString(string $fullString): string
    {
        $commandOffsets = $this->allCommandOffsets();

        // Find the start point for this command and, if it exists, the start point (offset) of the NEXT bot_command entity
        $splicePoints = $commandOffsets->splice(
            $commandOffsets->search($this->entity['offset']),
            2
        );

        return $splicePoints->count() === 2
            ? $this->cutTextBetween($splicePoints, $fullString)
            : $this->cutTextFrom($splicePoints, $fullString);
    }

    private function cutTextBetween(Collection $splicePoints, string $fullString): string
    {
        return mb_substr(
            $fullString,
            $splicePoints->first(),
            $splicePoints->last() - $splicePoints->first(),
            'UTF-8'
        );
    }

    private function cutTextFrom(Collection $splicePoints, string $fullString): string
    {
        return mb_substr($fullString, $splicePoints->first(), null, 'UTF-8');
    }

    /**
     * @throws TelegramSDKException
     */
    private function allCommandOffsets(): Collection
    {
        return Entity::from($this->getUpdate())->commandEntities()->pluck('offset');
    }

    /**
     * Get the portion of a string between a given values.
     */
    public static function between(string $subject, string $before, string $after): string
    {
        if ($before === '') {
            return $subject;
        }
        if ($after === '') {
            return $subject;
        }

        return Str::beforeLast(Str::after($subject, $before), $after);
    }
}
