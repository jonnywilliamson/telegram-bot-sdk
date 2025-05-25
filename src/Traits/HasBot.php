<?php

namespace Telegram\Bot\Traits;

use Telegram\Bot\Contracts\BotInterface;

/**
 * Class HasBot.
 */
trait HasBot
{
    /** @var BotInterface|null Telegram Bot. */
    protected ?BotInterface $bot = null;

    /**
     * Determine if Telegram Bot is set.
     */
    public function hasBot(): bool
    {
        return $this->bot !== null;
    }

    /**
     * Get the Telegram Bot.
     */
    public function getBot(): ?BotInterface
    {
        return $this->bot;
    }

    /**
     * Set the Telegram Bot.
     *
     *
     * @return $this
     */
    public function setBot(BotInterface $bot): self
    {
        $this->bot = $bot;

        return $this;
    }
}
