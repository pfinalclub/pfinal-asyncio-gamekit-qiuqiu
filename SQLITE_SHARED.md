# SQLite 多进程房间共享配置

## 概述

项目使用 SQLite 数据库来在多进程环境下共享房间信息，替代 Redis。SQLite 是 PHP 内置的，无需额外安装扩展（SQLite3 扩展已包含在 PHP 中）。

## 配置

在 `config/server.php` 中配置 SQLite：

```php
'sqlite' => [
    'db_path' => __DIR__ . '/../storage/shared.db',
    'prefix' => 'qiuqiu:',
],
```

- `db_path`: SQLite 数据库文件路径
- `prefix`: 键前缀，用于区分不同应用的数据

## 工作原理

### 数据库结构

SQLite 数据库包含两个表：

1. **cache 表**：存储房间元数据和其他缓存数据
   - `key`: 主键，带前缀的键名
   - `value`: 序列化的值
   - `expire_at`: 过期时间戳（0 表示永不过期）
   - `created_at`: 创建时间戳

2. **room_index 表**：快速查找所有房间ID
   - `room_id`: 房间ID（主键）
   - `created_at`: 创建时间戳

### WAL 模式

SQLite 使用 WAL（Write-Ahead Logging）模式来支持并发读写：

```php
$db->exec('PRAGMA journal_mode=WAL;');
```

WAL 模式允许多个进程同时读取，而写入操作会排队执行，提高了并发性能。

### 原子操作

房间索引操作使用 SQLite 的事务和 `INSERT OR IGNORE` 来保证原子性：

```php
// 添加到索引（原子操作）
$stmt = $db->prepare("INSERT OR IGNORE INTO room_index (room_id) VALUES (:room_id)");
```

## 性能考虑

### 优点

1. **无需额外服务**：SQLite 是文件数据库，无需运行独立的数据库服务
2. **PHP 内置**：SQLite3 扩展已包含在 PHP 中
3. **简单配置**：只需指定数据库文件路径
4. **WAL 模式**：支持多进程并发读取

### 局限性

1. **写入性能**：写入操作会排队，高并发写入时可能成为瓶颈
2. **文件锁**：SQLite 使用文件锁，在高并发写入时可能影响性能
3. **单机限制**：SQLite 是文件数据库，无法跨服务器共享

### 适用场景

- 中小型多进程应用（4-8 个 Worker 进程）
- 房间数量较少（< 1000 个房间）
- 读取操作远多于写入操作

## 使用示例

### 初始化适配器

```php
use App\SqliteAdapter;

$adapter = SqliteAdapter::create(
    __DIR__ . '/storage/shared.db',
    'qiuqiu:'
);
```

### 基本操作

```php
// 设置数据（带过期时间）
$adapter->set('room:meta:room_123', [
    'id' => 'room_123',
    'player_count' => 5,
    'max_players' => 50
], 86400); // 24小时过期

// 获取数据
$meta = $adapter->get('room:meta:room_123');

// 删除数据
$adapter->delete('room:meta:room_123');
```

## 清理和维护

### 清理过期数据

SQLite 适配器提供了 `cleanExpired()` 方法来清理过期数据：

```php
$adapter->cleanExpired();
```

建议定期（如每小时）执行一次清理操作。

### 数据库文件位置

默认数据库文件位于 `storage/shared.db`，确保：

1. `storage` 目录可写
2. 多进程可以访问同一文件路径
3. 定期备份数据库文件（如需要）

## 故障排除

### 1. 数据库文件无法创建

**错误**：`Unable to open database file`

**解决**：
- 检查 `storage` 目录是否存在且可写
- 检查文件路径是否正确
- 检查文件系统权限

### 2. 数据库锁定

**错误**：`database is locked`

**解决**：
- 检查是否有其他进程长时间占用数据库
- 增加 `busyTimeout`（默认 5 秒）
- 检查 WAL 模式是否已启用

### 3. 房间索引不同步

**问题**：房间索引表与实际房间数据不一致

**解决**：
- 检查 `room_index` 表是否正确创建
- 检查事务是否正确提交
- 重新初始化数据库（删除 `storage/shared.db` 文件）

## 与 Redis 对比

| 特性 | SQLite | Redis |
|------|--------|-------|
| 安装要求 | PHP 内置 | 需要安装 Redis 扩展和服务器 |
| 配置复杂度 | 简单（只需文件路径） | 中等（需要配置连接信息） |
| 性能 | 中等（文件 I/O） | 高（内存数据库） |
| 并发读写 | WAL 模式支持 | 原生支持 |
| 跨服务器 | 不支持 | 支持 |
| 数据持久化 | 文件数据库 | 可选持久化 |
| 适用场景 | 中小型应用 | 大型应用 |

## 迁移说明

如果之前使用 Redis，迁移到 SQLite：

1. 更新 `config/server.php`：
   ```php
   // 移除
   'redis' => [...],
   
   // 添加
   'sqlite' => [
       'db_path' => __DIR__ . '/../storage/shared.db',
       'prefix' => 'qiuqiu:',
   ],
   ```

2. 删除旧的 Redis 依赖（如果不再使用）

3. 重启服务器，SQLite 数据库会自动创建

## 注意事项

1. **文件权限**：确保多进程可以读写 `storage/shared.db` 文件
2. **备份**：定期备份数据库文件（如需要持久化数据）
3. **清理**：定期清理过期数据，避免数据库文件过大
4. **监控**：监控数据库文件大小和写入性能

