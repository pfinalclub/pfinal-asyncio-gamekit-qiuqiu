<?php

namespace App;

use PfinalClub\AsyncioGamekit\Room;
use PfinalClub\AsyncioGamekit\Player as BasePlayer;
use PfinalClub\AsyncioGamekit\RoomManager;
use PfinalClub\AsyncioGamekit\Persistence\PersistenceAdapterInterface;
use SQLite3;

/**
 * 共享房间管理器（基于 SQLite/Redis）
 * 支持多进程间共享房间信息
 */
class SharedRoomManager
{
    private RoomManager $localRoomManager;
    private PersistenceAdapterInterface $storage;
    private int $workerId;
    private string $roomIndexKey = 'rooms:index';
    private string $roomMetaPrefix = 'room:meta:';
    private string $workerRoomsPrefix = 'worker:rooms:';
    private string $roomStatePrefix = 'room:state:';

    public function __construct(
        RoomManager $localRoomManager,
        PersistenceAdapterInterface $storage,
        ?int $workerId = null
    ) {
        $this->localRoomManager = $localRoomManager;
        $this->storage = $storage;
        // 使用传入的 workerId，如果没有则使用进程 ID
        $this->workerId = $workerId ?? getmypid();
    }

    /**
     * 创建房间并注册到共享存储
     */
    public function createRoom(string $roomClass, ?string $roomId = null, array $config = []): Room
    {
        // 在本地创建房间
        $room = $this->localRoomManager->createRoom($roomClass, $roomId, $config);
        
        // 注册到共享存储
        $this->registerRoom($room);
        
        return $room;
    }

    /**
     * 注册房间到共享存储
     */
    private function registerRoom(Room $room): void
    {
        $roomId = $room->getId();
        $meta = [
            'id' => $roomId,
            'class' => get_class($room),
            'worker_id' => $this->workerId,
            'status' => $room->getStatus(),
            'player_count' => $room->getPlayerCount(),
            'max_players' => $room->toArray()['config']['max_players'] ?? 50,
            'created_at' => time(),
        ];
        
        // 保存房间元数据
        $this->storage->set($this->roomMetaPrefix . $roomId, $meta, 86400); // 24小时过期
        
        // 添加到房间索引
        $this->addToRoomIndex($roomId);
        
        // 添加到 Worker 房间列表
        $workerRooms = $this->storage->get($this->workerRoomsPrefix . $this->workerId, []);
        if (!in_array($roomId, $workerRooms)) {
            $workerRooms[] = $roomId;
            $this->storage->set($this->workerRoomsPrefix . $this->workerId, $workerRooms, 86400);
        }
    }

    /**
     * 更新房间元数据（同时同步房间状态）
     */
    public function updateRoomMeta(Room $room): void
    {
        $roomId = $room->getId();
        $meta = $this->storage->get($this->roomMetaPrefix . $roomId);
        
        if ($meta) {
            $meta['status'] = $room->getStatus();
            $meta['player_count'] = $room->getPlayerCount();
            $this->storage->set($this->roomMetaPrefix . $roomId, $meta, 86400);
        } else {
            // 如果没有元数据，重新注册
            $this->registerRoom($room);
        }
        
        // 同步房间状态（如果是 GameRoom）
        if ($room instanceof \App\GameRoom) {
            $state = $room->getState();
            $this->storage->set($this->roomStatePrefix . $roomId, $state, 3600); // 1小时过期
        }
    }
    
    /**
     * 获取房间状态（从共享存储）
     */
    public function getRoomState(string $roomId): ?array
    {
        return $this->storage->get($this->roomStatePrefix . $roomId);
    }
    
    /**
     * 恢复房间状态（从共享存储加载并应用到房间）
     */
    public function restoreRoomState(Room $room): bool
    {
        if (!($room instanceof \App\GameRoom)) {
            return false;
        }
        
        $state = $this->getRoomState($room->getId());
        if ($state) {
            $room->restoreState($state);
            return true;
        }
        
        return false;
    }

    /**
     * 从共享存储中移除房间
     */
    public function unregisterRoom(string $roomId): void
    {
        // 删除房间元数据
        $this->storage->delete($this->roomMetaPrefix . $roomId);
        
        // 从房间索引中移除
        $this->removeFromRoomIndex($roomId);
        
        // 从 Worker 房间列表中移除
        $workerRooms = $this->storage->get($this->workerRoomsPrefix . $this->workerId, []);
        $workerRooms = array_filter($workerRooms, fn($id) => $id !== $roomId);
        $this->storage->set($this->workerRoomsPrefix . $this->workerId, array_values($workerRooms), 86400);
    }

    /**
     * 添加到房间索引（使用 SQLite 原子操作）
     */
    private function addToRoomIndex(string $roomId): void
    {
        // 如果 storage 是 SqliteAdapter，使用 SQLite 操作
        if ($this->storage instanceof \App\SqliteAdapter) {
            $db = $this->storage->getDb();
            // 使用 SQLite 的 INSERT OR IGNORE 实现原子操作
            $stmt = $db->prepare("INSERT OR IGNORE INTO room_index (room_id) VALUES (:room_id)");
            $stmt->bindValue(':room_id', $roomId, SQLITE3_TEXT);
            $stmt->execute();
        } elseif ($this->storage instanceof \PfinalClub\AsyncioGamekit\Persistence\RedisAdapter) {
            // Redis 方式
            $redis = $this->storage->getRedis();
            $redis->sAdd($this->roomIndexKey, $roomId);
            $redis->expire($this->roomIndexKey, 86400);
        } else {
            // 降级到数组操作
            $index = $this->storage->get($this->roomIndexKey, []);
            if (!in_array($roomId, $index)) {
                $index[] = $roomId;
                $this->storage->set($this->roomIndexKey, $index, 86400);
            }
        }
    }

    /**
     * 从房间索引中移除（使用 SQLite 原子操作）
     */
    private function removeFromRoomIndex(string $roomId): void
    {
        // 如果 storage 是 SqliteAdapter，使用 SQLite 操作
        if ($this->storage instanceof \App\SqliteAdapter) {
            $db = $this->storage->getDb();
            $stmt = $db->prepare("DELETE FROM room_index WHERE room_id = :room_id");
            $stmt->bindValue(':room_id', $roomId, SQLITE3_TEXT);
            $stmt->execute();
        } elseif ($this->storage instanceof \PfinalClub\AsyncioGamekit\Persistence\RedisAdapter) {
            // Redis 方式
            $redis = $this->storage->getRedis();
            $redis->sRem($this->roomIndexKey, $roomId);
        } else {
            // 降级到数组操作
            $index = $this->storage->get($this->roomIndexKey, []);
            $index = array_filter($index, fn($id) => $id !== $roomId);
            $this->storage->set($this->roomIndexKey, array_values($index), 86400);
        }
    }

    /**
     * 获取所有可用房间（从共享存储查询）
     */
    public function getAllRooms(): array
    {
        // 如果 storage 是 SqliteAdapter，使用 SQLite 查询
        if ($this->storage instanceof \App\SqliteAdapter) {
            $db = $this->storage->getDb();
            $stmt = $db->prepare("SELECT room_id FROM room_index");
            $result = $stmt->execute();
            
            $roomIds = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $roomIds[] = $row['room_id'];
            }
        } elseif ($this->storage instanceof \PfinalClub\AsyncioGamekit\Persistence\RedisAdapter) {
            // Redis 方式
            $redis = $this->storage->getRedis();
            $roomIds = $redis->sMembers($this->roomIndexKey);
        } else {
            // 降级到数组操作
            $roomIds = $this->storage->get($this->roomIndexKey, []);
        }
        
        if (empty($roomIds)) {
            return [];
        }
        
        $rooms = [];
        foreach ($roomIds as $roomId) {
            $meta = $this->storage->get($this->roomMetaPrefix . $roomId);
            if ($meta) {
                $rooms[$roomId] = $meta;
            }
        }
        
        return $rooms;
    }

    /**
     * 查找可用房间（跨进程）
     */
    public function findAvailableRoom(string $roomClass, int $maxPlayers): ?array
    {
        $allRooms = $this->getAllRooms();
        
        echo "[SharedRoomManager] Found " . count($allRooms) . " rooms in shared storage\n";
        
        // 按房间ID排序，确保第一个房间优先
        ksort($allRooms);
        
        foreach ($allRooms as $roomId => $meta) {
            // 检查房间类型
            if ($meta['class'] !== $roomClass) {
                continue;
            }
            
            // 检查是否未满
            if ($meta['player_count'] < $maxPlayers) {
                echo "[SharedRoomManager] Found available room: {$roomId} (worker_id: {$meta['worker_id']}, players: {$meta['player_count']}/{$maxPlayers})\n";
                return $meta;
            }
        }
        
        echo "[SharedRoomManager] No available room found\n";
        return null;
    }

    /**
     * 获取房间元数据
     */
    public function getRoomMeta(string $roomId): ?array
    {
        return $this->storage->get($this->roomMetaPrefix . $roomId);
    }

    /**
     * 玩家加入房间
     */
    public function joinRoom(BasePlayer $player, string $roomId): bool
    {
        $meta = $this->getRoomMeta($roomId);
        
        if (!$meta) {
            // 房间不在共享存储中，可能已被删除或不存在
            return false;
        }
        
        // 先检查本地是否有这个房间（可能在本地）
        $room = $this->localRoomManager->getRoom($roomId);
        if ($room) {
            // 房间在本地，直接加入
            try {
                $result = $this->localRoomManager->joinRoom($player, $roomId);
                if ($result) {
                    $this->updateRoomMeta($room);
                    return true;
                }
            } catch (\Exception $e) {
                // 加入失败，更新元数据
                $this->updateRoomMeta($room);
                throw $e;
            }
        }
        
        // 如果房间在当前 Worker（根据元数据），但本地没有找到，可能是元数据过时
        // 或者房间在其他 Worker，但我们仍然尝试加入（RoomManager 可能会创建）
        if ($meta['worker_id'] === $this->workerId) {
            // 房间应该在这个 Worker，但本地没有，可能是房间已被删除
            // 更新元数据，标记为不存在
            $this->unregisterRoom($roomId);
            return false;
        }
        
        // 房间在其他 Worker，返回 false
        // 注意：在多进程环境下，应该通过消息队列或进程间通信将玩家路由到正确的 Worker
        // 这里简化处理，如果房间在其他 Worker，返回 false
        return false;
    }

    /**
     * 获取本地 RoomManager（用于直接操作）
     */
    public function getLocalRoomManager(): RoomManager
    {
        return $this->localRoomManager;
    }

    /**
     * 清理当前 Worker 的所有房间（Worker 退出时调用）
     */
    public function cleanupWorkerRooms(): void
    {
        $workerRooms = $this->storage->get($this->workerRoomsPrefix . $this->workerId, []);
        
        foreach ($workerRooms as $roomId) {
            $this->unregisterRoom($roomId);
        }
        
        $this->storage->delete($this->workerRoomsPrefix . $this->workerId);
    }
}

