<?php
declare(strict_types=1);
namespace Pivot;
interface TelegramTransport {
    public function call(string $method, array $data): array;
    public function download(string $fileId, string $destination): int;
}
