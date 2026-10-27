<?php
declare(strict_types=1);
namespace Pivot;
final class TelegramException extends \RuntimeException {
    public function __construct(public int $apiCode, public int $retryAfter=0) { parent::__construct('Telegram API error '.$apiCode); }
}
