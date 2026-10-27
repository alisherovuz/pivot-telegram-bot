<?php
declare(strict_types=1);
namespace Pivot;

final class Worker
{
    public function __construct(private Db $db, private TelegramTransport $telegram, private array $config) {}

    public static function userId(array $update): int {
        return (int)($update['callback_query']['from']['id'] ?? $update['message']['from']['id'] ?? 0);
    }

    public function processUser(int $user, int $limit = 10, float $seconds = 12): int {
        $lock = 'pivot-user-'.substr(hash('sha256', $this->config['dsn'].':'.$user), 0, 48);
        if ((int)$this->db->value('SELECT GET_LOCK(?,0)', [$lock]) !== 1) return 0;
        $start = microtime(true);
        $processed = 0;
        try {
            // Durable updates remain ordered within each conversation, including retries.
            $rows = $this->db->all("SELECT id,attempts,next_attempt_at FROM updates
                WHERE processed_at IS NULL AND COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(payload,'$.callback_query.from.id')),
                    JSON_UNQUOTE(JSON_EXTRACT(payload,'$.message.from.id')), '0') = ?
                ORDER BY id LIMIT ".max(1, min($limit, 100)), [(string)$user]);
            $bot = new Bot($this->db, $this->telegram, $this->config);
            foreach ($rows as $r) {
                if (microtime(true)-$start >= $seconds || (int)$r['attempts'] >= 8 || $r['next_attempt_at'] > date('Y-m-d H:i:s')) break;
                try {
                    if ($bot->process((int)$r['id'])) $processed++;
                } catch (\Throwable $e) {
                    $this->db->run('UPDATE updates SET attempts=attempts+1,last_error=?,next_attempt_at=DATE_ADD(NOW(),INTERVAL 60 SECOND) WHERE id=? AND processed_at IS NULL',
                        [$e instanceof TelegramException ? $e->getMessage() : 'Processing error; inspect private application log', $r['id']]);
                    error_log('Pivot update '.$r['id'].' failed: '.get_class($e));
                    break;
                }
            }
        } finally {
            $this->db->run('SELECT RELEASE_LOCK(?)', [$lock]);
        }
        return $processed;
    }

    public function respond(array $update): void {
        $user = self::userId($update);
        $start = microtime(true);
        $this->processUser($user);
        $queue = new Queue($this->db, $this->telegram);
        // Reply now, only to this conversation. Cron handles backoff and remaining work.
        for ($i = 0; $i < 8 && microtime(true)-$start < 20; $i++) {
            if (!$queue->deliver(1, $user)) break;
        }
    }
}
