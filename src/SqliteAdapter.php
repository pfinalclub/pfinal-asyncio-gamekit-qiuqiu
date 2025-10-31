<?php

namespace App;

use SQLite3;
use PfinalClub\AsyncioGamekit\Persistence\PersistenceAdapterInterface;

/**
 * SQLite 持久化适配器
 */
class SqliteAdapter implements PersistenceAdapterInterface
{
    private SQLite3 $db;
    private string $prefix;

    /**
     * @param SQLite3 $db SQLite3实例
     * @param string $prefix 键前缀
     */
    public function __construct(SQLite3 $db, string $prefix = 'gamekit:')
    {
        $this->db = $db;
        $this->prefix = $prefix;
        $this->initTable();
    }

    /**
     * 静态工厂方法
     */
    public static function create(
        string $dbPath = 'storage/shared.db',
        string $prefix = 'gamekit:'
    ): self {
        // 确保目录存在
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $db = new SQLite3($dbPath);
        $db->busyTimeout(5000); // 5秒超时，支持并发
        $db->exec('PRAGMA journal_mode=WAL;'); // 启用 WAL 模式，提高并发性能
        
        return new self($db, $prefix);
    }

    /**
     * 初始化数据表
     */
    private function initTable(): void
    {
        // 缓存表
        $sql = "CREATE TABLE IF NOT EXISTS cache (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            expire_at INTEGER DEFAULT 0,
            created_at INTEGER DEFAULT (strftime('%s', 'now'))
        )";
        
        $this->db->exec($sql);
        
        // 创建索引
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_expire_at ON cache(expire_at)");
        
        // 房间索引表（用于快速查找所有房间）
        $sql2 = "CREATE TABLE IF NOT EXISTS room_index (
            room_id TEXT PRIMARY KEY,
            created_at INTEGER DEFAULT (strftime('%s', 'now'))
        )";
        
        $this->db->exec($sql2);
    }

    /**
     * 获取带前缀的键
     */
    private function getKey(string $key): string
    {
        return $this->prefix . $key;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $prefixedKey = $this->getKey($key);
        $serialized = serialize($value);
        $expireAt = $ttl > 0 ? time() + $ttl : 0;

        $stmt = $this->db->prepare("INSERT OR REPLACE INTO cache (key, value, expire_at) VALUES (:key, :value, :expire_at)");
        $stmt->bindValue(':key', $prefixedKey, SQLITE3_TEXT);
        $stmt->bindValue(':value', $serialized, SQLITE3_TEXT);
        $stmt->bindValue(':expire_at', $expireAt, SQLITE3_INTEGER);

        return $stmt->execute() !== false;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $prefixedKey = $this->getKey($key);
        $now = time();

        $stmt = $this->db->prepare("SELECT value, expire_at FROM cache WHERE key = :key AND (expire_at = 0 OR expire_at > :now)");
        $stmt->bindValue(':key', $prefixedKey, SQLITE3_TEXT);
        $stmt->bindValue(':now', $now, SQLITE3_INTEGER);

        $result = $stmt->execute();
        if (!$result) {
            return $default;
        }

        $row = $result->fetchArray(SQLITE3_ASSOC);
        if ($row === false) {
            return $default;
        }

        return unserialize($row['value']);
    }

    public function has(string $key): bool
    {
        $prefixedKey = $this->getKey($key);
        $now = time();

        $stmt = $this->db->prepare("SELECT 1 FROM cache WHERE key = :key AND (expire_at = 0 OR expire_at > :now) LIMIT 1");
        $stmt->bindValue(':key', $prefixedKey, SQLITE3_TEXT);
        $stmt->bindValue(':now', $now, SQLITE3_INTEGER);

        $result = $stmt->execute();
        if (!$result) {
            return false;
        }

        return $result->fetchArray() !== false;
    }

    public function delete(string $key): bool
    {
        $prefixedKey = $this->getKey($key);

        $stmt = $this->db->prepare("DELETE FROM cache WHERE key = :key");
        $stmt->bindValue(':key', $prefixedKey, SQLITE3_TEXT);

        $result = $stmt->execute();
        return $result !== false;
    }

    public function getMultiple(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }
        return $result;
    }

    public function setMultiple(array $values, int $ttl = 0): bool
    {
        $this->db->exec('BEGIN TRANSACTION');
        
        try {
            foreach ($values as $key => $value) {
                if (!$this->set($key, $value, $ttl)) {
                    $this->db->exec('ROLLBACK');
                    return false;
                }
            }
            
            $this->db->exec('COMMIT');
            return true;
        } catch (\Exception $e) {
            $this->db->exec('ROLLBACK');
            return false;
        }
    }

    public function clear(): bool
    {
        $prefixPattern = $this->prefix . '%';
        $stmt = $this->db->prepare("DELETE FROM cache WHERE key LIKE :pattern");
        $stmt->bindValue(':pattern', $prefixPattern, SQLITE3_TEXT);
        
        return $stmt->execute() !== false;
    }

    /**
     * 清理过期数据
     */
    public function cleanExpired(): void
    {
        $now = time();
        $stmt = $this->db->prepare("DELETE FROM cache WHERE expire_at > 0 AND expire_at <= :now");
        $stmt->bindValue(':now', $now, SQLITE3_INTEGER);
        $stmt->execute();
    }

    /**
     * 获取 SQLite3 实例（用于特殊操作，如 SET 操作）
     */
    public function getDb(): SQLite3
    {
        return $this->db;
    }

    /**
     * 关闭数据库连接
     */
    public function close(): void
    {
        $this->db->close();
    }
}

