<?php

namespace App;

use GatewayWorker\Lib\Gateway;
use PfinalClub\AsyncioGamekit\RoomManager;
use PfinalClub\AsyncioGamekit\Player as BasePlayer;

class Events
{
    private static ?SharedRoomManager $shared = null;
    private static ?RoomManager $roomManager = null;
    private static array $clientNames = [];

    public static function onWorkerStart($businessWorker)
    {
        // 加载配置并初始化共享存储（SQLite）
        $config = require __DIR__ . '/../config/server.php';
        $sqlite = SqliteAdapter::create(
            $config['sqlite']['db_path'] ?? __DIR__ . '/../storage/shared.db',
            $config['sqlite']['prefix'] ?? 'qiuqiu:'
        );
        
        // 初始化 RoomManager
        self::$roomManager = new RoomManager();
        
        // 初始化共享房间管理器
        self::$shared = new SharedRoomManager(self::$roomManager, $sqlite, getmypid());
        
        echo "[BusinessWorker] Worker started (PID: " . getmypid() . ")\n";
    }

    public static function onConnect($clientId)
    {
        Gateway::sendToClient($clientId, json_encode([
            'event' => 'connected', 
            'data' => ['client_id' => $clientId]
        ]));
    }

    public static function onMessage($clientId, $message)
    {
        $payload = json_decode($message, true);
        if (!is_array($payload) || !isset($payload['event'])) {
            Gateway::sendToClient($clientId, json_encode([
                'event' => 'error', 
                'data' => ['message' => 'invalid message']
            ]));
            return;
        }

        $event = $payload['event'];
        $data = $payload['data'] ?? [];

        switch ($event) {
            case 'set_name':
                self::handleSetName($clientId, $data);
                break;

            case 'quick_match':
                self::handleQuickMatch($clientId, $data);
                break;

            case 'player:move':
            case 'player:split':
            case 'player:merge':
                // 房间消息，需要转发到房间所在的 BusinessWorker
                self::handleRoomMessage($clientId, $event, $data);
                break;

            default:
                Gateway::sendToClient($clientId, json_encode([
                    'event' => 'error', 
                    'data' => ['message' => 'unknown event: ' . $event]
                ]));
                break;
        }
    }

    private static function handleSetName(string $clientId, array $data): void
    {
        $raw = $data['name'] ?? ($data['nickname'] ?? ($data['username'] ?? ($data['nick'] ?? '')));
        $name = trim((string)$raw);
        if ($name === '') {
            $name = '玩家' . rand(100, 999);
        }
        // 限长与简单过滤
        $name = mb_substr($name, 0, 20);
        Gateway::updateSession($clientId, ['name' => $name]);
        self::$clientNames[$clientId] = $name;
        Gateway::sendToClient($clientId, json_encode([
            'event' => 'set_name', 
            'data' => ['success' => true, 'name' => $name]
        ]));

        // 如果玩家已在房间中，同步更新房间内显示的名称
        $uid = Gateway::getUidByClientId($clientId);
        if ($uid) {
            $room = self::$roomManager->getRoom($uid);
            if ($room instanceof GameRoom) {
                $player = new BasePlayer($clientId, $name);
                $room->renamePlayer($player, $name);
            }
        }
    }

    private static function handleQuickMatch(string $clientId, array $data = []): void
    {
        $maxPlayers = 50;

        // 若 quick_match 携带 name，优先使用
        $raw = $data['name'] ?? ($data['nickname'] ?? ($data['username'] ?? ($data['nick'] ?? '')));
        $name = trim((string)$raw);
        if ($name !== '') {
            $name = mb_substr($name, 0, 20);
            Gateway::updateSession($clientId, ['name' => $name]);
            self::$clientNames[$clientId] = $name;
        }
        
        // 查找可用房间（按 room_数字 顺序优先填满第一个房间，再开第二个房间）
        $availableRoomMeta = null;
        $allRooms = self::$shared->getAllRooms();
        if (!empty($allRooms)) {
            // 提取 room_* 的数字序号并排序
            usort($allRooms, function($a, $b) {
                $ia = 999999; $ib = 999999;
                if (preg_match('/^room_(\d+)/', $a['id'] ?? '', $ma)) $ia = (int)$ma[1];
                if (preg_match('/^room_(\d+)/', $b['id'] ?? '', $mb)) $ib = (int)$mb[1];
                if ($ia === $ib) return strcmp($a['id'], $b['id']);
                return $ia <=> $ib;
            });
            foreach ($allRooms as $meta) {
                $pc = (int)($meta['player_count'] ?? 0);
                if ($pc < $maxPlayers) { $availableRoomMeta = $meta; break; }
            }
        }
        
        if ($availableRoomMeta) {
            $roomId = $availableRoomMeta['id'];
            $targetWorkerId = $availableRoomMeta['worker_id'];
            
            echo "[QuickMatch] Found available room: {$roomId} (worker_id: {$targetWorkerId}, current: " . getmypid() . ")\n";
            
            // 关键：在查找房间之前就绑定 UID，这样 Gateway 会将后续消息路由到房间所在的 Worker
            // 但是，当前消息已经在某个 Worker 中处理了，所以我们需要在当前 Worker 创建房间副本
            Gateway::bindUid($clientId, $roomId);
            Gateway::joinGroup($clientId, $roomId);
            
            // 获取房间对象（可能在当前 Worker 或其他 Worker）
            $room = self::$roomManager->getRoom($roomId);
            
            if (!$room) {
                // 房间不在当前 Worker，创建副本
                // 注意：由于 GatewayWorker 的路由机制，后续消息会被路由到房间所在的 Worker
                // 但当前消息已经在当前 Worker 中，所以我们需要创建副本以便处理
                try {
                    $room = self::$shared->getLocalRoomManager()->createRoom(
                        GameRoom::class,
                        $roomId,
                        self::getGameConfig()
                    );
                    
                    // 无需从共享存储恢复状态（单进程路由下房间应在本进程）
                    
                    // 无需设置状态同步回调（已使用单进程路由）
                    
                    echo "[QuickMatch] Created/restored room {$roomId} in Worker " . getmypid() . "\n";
                } catch (\Exception $e) {
                    echo "[QuickMatch] Failed to create/restore room: {$e->getMessage()}\n";
                    Gateway::unbindUid($clientId);
                    Gateway::leaveGroup($clientId, $roomId);
                    $availableRoomMeta = null;
                }
            }
            
            if ($room) {
                // 加入房间
                self::joinPlayerToRoom($clientId, $room);
                return;
            }
        }
        
        // 没有可用房间或加入失败，创建新房间（固定顺序 room_1, room_2, ...）
        echo "[QuickMatch] Creating new room for client {$clientId}\n";
        
        try {
            // 关键：使用固定的房间 ID（第一个房间总是 room_1；满员后 room_2，依次类推）
            $roomIndex = 1;
            if (!empty($allRooms)) {
                $maxIndex = 0;
                foreach ($allRooms as $meta) {
                    if (preg_match('/^room_(\d+)/', $meta['id'] ?? '', $m)) {
                        $maxIndex = max($maxIndex, (int)$m[1]);
                    }
                }
                $roomIndex = $maxIndex + 1;
            }
            $roomId = 'room_' . $roomIndex;
            
            // 关键：在创建房间之前就绑定 UID，这样 Gateway 会将后续绑定到相同 UID 的客户端路由到同一个 BusinessWorker
            Gateway::bindUid($clientId, $roomId);
            
            // 加入 Gateway 组（用于跨进程广播）
            Gateway::joinGroup($clientId, $roomId);
            
            // 现在创建房间（此时房间会在当前 Worker 创建，因为我们已经绑定了 UID）
            $room = self::$shared->getLocalRoomManager()->createRoom(
                GameRoom::class,
                $roomId,
                self::getGameConfig()
            );
            
            // 无需设置状态同步回调（已使用单进程路由）
            
            // 加入房间
            self::joinPlayerToRoom($clientId, $room);
        } catch (\Exception $e) {
            echo "[QuickMatch] Failed to create room: {$e->getMessage()}\n";
            Gateway::sendToClient($clientId, json_encode([
                'event' => 'error',
                'data' => ['message' => 'Failed to create room: ' . $e->getMessage()]
            ]));
        }
    }
    
    /**
     * 将玩家加入房间的辅助方法
     */
    private static function joinPlayerToRoom(string $clientId, GameRoom $room): void
    {
        try {
            $roomId = $room->getId();
            
            // 确保绑定到房间 ID
            Gateway::bindUid($clientId, $roomId);
            Gateway::joinGroup($clientId, $roomId);
            
            // 获取玩家名称（优先本进程缓存，其次 Session）
            $session = Gateway::getSession($clientId);
            $playerName = self::$clientNames[$clientId] ?? ($session['name'] ?? ('玩家' . rand(100, 999)));
            
            // 创建玩家对象
            $player = new BasePlayer($clientId, $playerName);
            
            // 加入房间
            self::$roomManager->joinRoom($player, $roomId);
            
            // 更新房间元数据
            self::$shared->updateRoomMeta($room);
            
            // 发送匹配成功消息
            Gateway::sendToClient($clientId, json_encode([
                'event' => 'quick_match',
                'data' => [
                    'room_id' => $roomId,
                    'player_id' => $clientId,
                    'config' => $room->getConfig()
                ]
            ]));
            
            echo "[QuickMatch] Player {$clientId} ({$playerName}) joined room {$roomId} in Worker " . getmypid() . "\n";
        } catch (\Exception $e) {
            echo "[QuickMatch] Failed to join room: {$e->getMessage()}\n";
            Gateway::sendToClient($clientId, json_encode([
                'event' => 'error',
                'data' => ['message' => 'Failed to join room: ' . $e->getMessage()]
            ]));
        }
    }

    private static function handleRoomMessage(string $clientId, string $event, array $data): void
    {
        // 获取客户端绑定的房间 ID
        $uid = Gateway::getUidByClientId($clientId);
        if (!$uid) {
            Gateway::sendToClient($clientId, json_encode([
                'event' => 'error',
                'data' => ['message' => 'Player not in any room']
            ]));
            return;
        }
        
        $roomId = $uid; // uid 就是房间 ID
        
        // 获取房间对象
        $room = self::$roomManager->getRoom($roomId);
        if (!$room) {
            Gateway::sendToClient($clientId, json_encode([
                'event' => 'error',
                'data' => ['message' => 'Room not found']
            ]));
            return;
        }
        
        // 创建临时玩家对象（RoomManager 没有 getPlayer 方法，我们需要从房间获取或创建临时对象）
            // 获取玩家名称（优先本进程缓存，其次 Session）
            $session = Gateway::getSession($clientId);
            $playerName = self::$clientNames[$clientId] ?? ($session['name'] ?? ('玩家' . rand(100, 999)));
        
        // 创建临时玩家对象用于消息处理
        $player = new BasePlayer($clientId, $playerName);
        
        // 将消息转发给房间处理
        try {
            if ($room instanceof GameRoom) {
                $room->onPlayerMessage($player, $event, $data);
            } else {
                Gateway::sendToClient($clientId, json_encode([
                    'event' => 'error',
                    'data' => ['message' => 'Invalid room type']
                ]));
            }
        } catch (\Exception $e) {
            echo "[RoomMessage] Error handling {$event}: {$e->getMessage()}\n";
            Gateway::sendToClient($clientId, json_encode([
                'event' => 'error',
                'data' => ['message' => 'Failed to handle message: ' . $e->getMessage()]
            ]));
        }
    }

    public static function onClose($clientId)
    {
        echo "[onClose] Client {$clientId} disconnected.\n";
        
        // 获取客户端绑定的房间 ID
        $uid = Gateway::getUidByClientId($clientId);
        if ($uid) {
            $roomId = $uid;
            
            // 获取房间对象
            $room = self::$roomManager->getRoom($roomId);
            if ($room instanceof GameRoom) {
                $playerCountBefore = $room->getPlayerCount();
                
                // 强制移除该玩家，避免刷新后残留球体
                try {
                    $room->disconnectPlayerById($clientId);
                    $playerCountAfter = $room->getPlayerCount();
                    echo "[onClose] Player {$clientId} left room {$roomId}. Player count: {$playerCountBefore} -> {$playerCountAfter}\n";

                    // 更新共享存储中的房间元数据
                    self::$shared->updateRoomMeta($room);

                    // 如果房间为空，从共享存储移除
                    if ($playerCountAfter === 0) {
                        echo "[onClose] Room {$roomId} is now empty, unregistering from shared storage.\n";
                        self::$shared->unregisterRoom($roomId);
                    }
                } catch (\Exception $e) {
                    echo "[onClose] Error leaving room: {$e->getMessage()}\n";
                }
                
                // 离开 Gateway 组（需要传入房间ID）
                Gateway::leaveGroup($clientId, $roomId);
            } else {
                // 如果房间不存在，尝试离开所有可能的组
                // 但 GatewayWorker 没有获取所有组的方法，所以我们需要知道房间ID
                // 这里如果房间不存在，就不调用 leaveGroup
            }
        }
        
        // 解绑 UID（需要 clientId 与 uid/roomId）
        if (isset($roomId)) {
            Gateway::unbindUid($clientId, $roomId);
        }
        // 清理名称缓存
        unset(self::$clientNames[$clientId]);
    }
    
    /**
     * 获取游戏配置
     */
    private static function getGameConfig(): array
    {
        $config = require __DIR__ . '/../config/server.php';
        return $config['game'] ?? [];
    }
}
