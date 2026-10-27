<?php
declare(strict_types=1);
namespace Pivot;
use PDO;
final class Db
{
    public PDO $pdo;
    public function __construct(array $config) {
        $this->pdo = new PDO($config['dsn'], $config['db_user'], $config['db_password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
        $this->pdo->exec("SET time_zone = '+05:00'");
    }
    public function run(string $sql, array $params = []): \PDOStatement { $s=$this->pdo->prepare($sql); $s->execute($params); return $s; }
    public function one(string $sql, array $params = []): ?array { return $this->run($sql,$params)->fetch() ?: null; }
    public function all(string $sql, array $params = []): array { return $this->run($sql,$params)->fetchAll(); }
    public function value(string $sql, array $params = []): mixed { return $this->run($sql,$params)->fetchColumn(); }
    public function settings(): array { return $this->run('SELECT `key`,value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR); }
    public function audit(?int $admin, ?int $app, string $action, string $details): void {
        $this->run('INSERT INTO audit(admin_id,application_id,action,details,created_at) VALUES(?,?,?,?,NOW())',[$admin,$app,$action,$details]);
    }
}
