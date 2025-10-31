<?php

namespace App;

use PfinalClub\AsyncioGamekit\GameServer as BaseGameServer;
use PfinalClub\AsyncioGamekit\Player as BasePlayer;
use Workerman\Connection\TcpConnection;

/**
 * 球球大作战 WebSocket 服务器
 */
class GameServer extends BaseGameServer
{
    private array $gameConfig;
    private ?SharedRoomManager $sharedRoomManager = null;

    public function __construct(array $config)
    {
        $this->gameConfig = $config;
        
        parent::__construct(
            $config['host'],
            $config['port'],
            [
                'name' => 'QiuQiuGameServer',
                'count' => $config['worker_count'],
                'protocol' => 'websocket',
            ]
        );
        
        // 初始化共享房间管理器（如果配置了 SQLite）
        if (isset($config['sqlite'])) {
            try {
                $sqliteAdapter = SqliteAdapter::create(
                    $config['sqlite']['db_path'] ?? __DIR__ . '/../storage/shared.db',
                    $config['sqlite']['prefix'] ?? 'qiuqiu:'
                );
                
                $this->sharedRoomManager = new SharedRoomManager(
                    $this->getRoomManager(),
                    $sqliteAdapter
                );
                
                echo "SharedRoomManager initialized with SQLite\n";
            } catch (\Exception $e) {
                echo "Failed to initialize SQLite: {$e->getMessage()}\n";
                echo "Falling back to local RoomManager (single process mode)\n";
            }
        }
    }

    /**
     * 连接建立时
     */
    protected function onConnect(TcpConnection $connection): void
    {
        parent::onConnect($connection);
        echo "New connection: {$connection->id}\n";
    }

    /**
     * 收到消息时
     */
    protected function onMessage(TcpConnection $connection, string $data): void
    {
        // 调用父类处理系统事件和房间消息转发
        parent::onMessage($connection, $data);
    }

    /**
     * 连接关闭时
     */
    protected function onClose(TcpConnection $connection): void
    {
        $player = $this->getConnectionPlayer($connection);
        if ($player) {
            $room = $this->getRoomManager()->getPlayerRoom($player);
            
            // 使用 RoomManager 的 leaveRoom 方法
            $this->getRoomManager()->leaveRoom($player);
            
            // 更新共享存储中的房间元数据
            if ($this->sharedRoomManager && $room instanceof GameRoom) {
                $this->sharedRoomManager->updateRoomMeta($room);
                
                // 如果房间为空，从共享存储移除
                if ($room->getPlayerCount() === 0) {
                    $this->sharedRoomManager->unregisterRoom($room->getId());
                }
            }
        }
        
        parent::onClose($connection);
        echo "Connection closed: {$connection->id}\n";
    }

    /**
     * 处理系统事件（重写以支持快速匹配）
     */
    protected function handleSystemEvent(BasePlayer $player, string $event, mixed $data): void
    {
        switch ($event) {
            case 'set_name':
                $player->setName($data['name'] ?? 'Anonymous');
                $player->send('set_name', ['success' => true, 'name' => $player->getName()]);
                break;

            case 'quick_match':
                $this->handleQuickMatch($player);
                break;

            case 'create_room':
                $this->handleCreateRoom($player, $data ?? []);
                break;

            case 'join_room':
                $this->handleJoinRoom($player, $data ?? []);
                break;

            case 'leave_room':
                $this->handleLeaveRoom($player);
                break;

            case 'get_rooms':
                $this->handleGetRooms($player);
                break;

            case 'get_stats':
                $this->handleGetStats($player);
                break;

            default:
                // 调用父类处理其他系统事件
                parent::handleSystemEvent($player, $event, $data);
                break;
        }
    }

    /**
     * 创建房间并设置状态同步回调
     */
    private function createRoomWithSync(GameRoom $room): void
    {
        if ($room instanceof GameRoom && $this->sharedRoomManager) {
            $room->setStateSyncCallback(function(GameRoom $room) {
                if ($this->sharedRoomManager) {
                    $this->sharedRoomManager->updateRoomMeta($room);
                }
            });
        }
    }
    
    /**
     * 获取连接对应的玩家
     */
    private function getConnectionPlayer(TcpConnection $connection): ?BasePlayer
    {
        // 通过反射或访问受保护属性来获取玩家
        // 框架已经在 onConnect 中创建了玩家并存储在 connections 中
        try {
            $reflection = new \ReflectionClass(parent::class);
            $property = $reflection->getProperty('connections');
            $property->setAccessible(true);
            $connections = $property->getValue($this);
            return $connections[$connection->id] ?? null;
        } catch (\ReflectionException $e) {
            return null;
        }
    }

    /**
     * 处理快速匹配（支持多进程共享）
     */
    private function handleQuickMatch(BasePlayer $player): void
    {
        $maxPlayers = $this->gameConfig['game']['max_players'];
        
        // 如果使用了共享房间管理器
        if ($this->sharedRoomManager) {
            // 从共享存储查找可用房间
            $availableRoomMeta = $this->sharedRoomManager->findAvailableRoom(GameRoom::class, $maxPlayers);
            
            echo "[QuickMatch] Searching for available room. Found: " . ($availableRoomMeta ? "room {$availableRoomMeta['id']} (worker_id: {$availableRoomMeta['worker_id']}, players: {$availableRoomMeta['player_count']}/{$maxPlayers})" : "none") . "\n";
            
            if ($availableRoomMeta) {
                $roomId = $availableRoomMeta['id'];
                
                // 先尝试通过 SharedRoomManager 加入（如果房间在当前 Worker）
                $success = $this->sharedRoomManager->joinRoom($player, $roomId);
                
                echo "[QuickMatch] SharedRoomManager->joinRoom result: " . ($success ? "success" : "failed") . "\n";
                
                if ($success) {
                    $room = $this->getRoomManager()->getRoom($roomId);
                    if ($room) {
                        $this->sharedRoomManager->updateRoomMeta($room);
                        
                        $player->send('quick_match', [
                            'room_id' => $roomId,
                            'player_id' => $player->getId(),
                            'config' => $room->getConfig()
                        ]);
                        echo "[QuickMatch] Player {$player->getId()} joined existing room {$roomId}\n";
                        return;
                    }
                }
                
                // 如果 SharedRoomManager 返回 false（房间在其他 Worker），尝试直接通过 RoomManager 加入
                // 先检查本地是否有这个房间（可能房间刚被创建但还未同步）
                try {
                    $room = $this->getRoomManager()->getRoom($roomId);
                    if ($room && count($room->getPlayers()) < $maxPlayers) {
                        // 房间存在于本地，直接加入
                        $this->getRoomManager()->joinRoom($player, $roomId);
                        $this->sharedRoomManager->updateRoomMeta($room);
                        
                        $player->send('quick_match', [
                            'room_id' => $roomId,
                            'player_id' => $player->getId(),
                            'config' => $room->getConfig()
                        ]);
                        echo "[QuickMatch] Player {$player->getId()} joined existing room {$roomId} (local)\n";
                        return;
                    }
                } catch (\Exception $e) {
                    echo "[QuickMatch] Failed to join room {$roomId} (local): {$e->getMessage()}\n";
                }
                
                // 如果本地也没有房间，但共享存储中有元数据，说明房间在其他 Worker
                // 单进程稳定模式下不创建房间副本，直接走创建新房间流程
                echo "[QuickMatch] Room {$roomId} exists in other Worker ({$availableRoomMeta['worker_id']}), creating a new room locally instead.\n";
            }
            
            // 没有可用房间或加入失败，创建新房间
            echo "[QuickMatch] Creating new room for player {$player->getId()}\n";
            $room = $this->sharedRoomManager->createRoom(GameRoom::class, null, $this->gameConfig['game']);
            
            // 设置状态同步回调
            $this->createRoomWithSync($room);
            
            $this->getRoomManager()->joinRoom($player, $room->getId());
            $this->sharedRoomManager->updateRoomMeta($room);
            
            $player->send('quick_match', [
                'room_id' => $room->getId(),
                'player_id' => $player->getId(),
                'config' => $room->getConfig()
            ]);
            echo "[QuickMatch] Player {$player->getId()} joined new room {$room->getId()}\n";
            return;
        }
        
        // 如果没有使用共享存储，使用本地 RoomManager（单进程模式）
        $roomManager = $this->getRoomManager();
        
        // 获取所有 GameRoom 类型的房间
        $allRooms = $roomManager->getRooms();
        $gameRooms = [];
        
        foreach ($allRooms as $roomId => $room) {
            if ($room instanceof GameRoom) {
                $gameRooms[$roomId] = $room;
            }
        }
        
        // 按房间ID排序，确保第一个房间优先
        ksort($gameRooms);
        
        // 查找第一个未满的房间
        $availableRoom = null;
        foreach ($gameRooms as $room) {
            $playerCount = count($room->getPlayers());
            
            if ($playerCount < $maxPlayers) {
                $availableRoom = $room;
                break;
            }
        }
        
        // 如果所有房间都满了，创建新房间
        if (!$availableRoom) {
            $roomId = uniqid('room_');
            $availableRoom = $roomManager->createRoom(GameRoom::class, $roomId, $this->gameConfig['game']);
        }

        // 玩家加入房间
        try {
            $roomManager->joinRoom($player, $availableRoom->getId());
            
            $player->send('quick_match', [
                'room_id' => $availableRoom->getId(),
                'player_id' => $player->getId(),
                'config' => $availableRoom->getConfig()
            ]);
        } catch (\Exception $e) {
            // 如果加入失败，尝试创建新房间
            echo "[QuickMatch] Failed to join room: {$e->getMessage()}\n";
            $roomId = uniqid('room_');
            $newRoom = $roomManager->createRoom(GameRoom::class, $roomId, $this->gameConfig['game']);
            $roomManager->joinRoom($player, $newRoom->getId());
            
            $player->send('quick_match', [
                'room_id' => $newRoom->getId(),
                'player_id' => $player->getId(),
                'config' => $newRoom->getConfig()
            ]);
        }
    }

    /**
     * 处理创建房间
     */
    private function handleCreateRoom(BasePlayer $player, array $data): void
    {
        $roomId = uniqid('room_');
        $roomConfig = array_merge($this->gameConfig['game'], $data['config'] ?? []);
        
        if ($this->sharedRoomManager) {
            $room = $this->sharedRoomManager->createRoom(GameRoom::class, $roomId, $roomConfig);
            $this->createRoomWithSync($room);
            $this->getRoomManager()->joinRoom($player, $roomId);
            $this->sharedRoomManager->updateRoomMeta($room);
        } else {
            $room = $this->getRoomManager()->createRoom(GameRoom::class, $roomId, $roomConfig);
            $this->getRoomManager()->joinRoom($player, $roomId);
        }
        
        $player->send('room:created', [
            'room_id' => $roomId,
            'config' => $room->getConfig()
        ]);
    }

    /**
     * 处理加入房间
     */
    private function handleJoinRoom(BasePlayer $player, array $data): void
    {
        if (!isset($data['room_id'])) {
            $player->send('error', ['message' => '缺少房间ID']);
            return;
        }

        $roomManager = $this->getRoomManager();
        $room = $roomManager->getRoom($data['room_id']);
        
        if (!$room) {
            $player->send('error', ['message' => '房间不存在']);
            return;
        }

        if (count($room->getPlayers()) >= $this->gameConfig['game']['max_players']) {
            $player->send('error', ['message' => '房间已满']);
            return;
        }

        // 使用 RoomManager 的 joinRoom 方法
        try {
            $roomManager->joinRoom($player, $data['room_id']);
            
            // 更新共享存储中的房间元数据
            if ($this->sharedRoomManager && $room instanceof GameRoom) {
                $this->sharedRoomManager->updateRoomMeta($room);
            }
            
            $player->send('room:joined', [
                'room_id' => $data['room_id'],
                'config' => $room->getConfig()
            ]);
        } catch (\Exception $e) {
            $player->send('error', ['message' => $e->getMessage()]);
        }
    }

    /**
     * 处理离开房间
     */
    private function handleLeaveRoom(BasePlayer $player): void
    {
        $roomManager = $this->getRoomManager();
        $room = $roomManager->getPlayerRoom($player);
        
        // 使用 RoomManager 的 leaveRoom 方法
        $roomManager->leaveRoom($player);
        
        // 更新共享存储中的房间元数据
        if ($this->sharedRoomManager && $room instanceof GameRoom) {
            $this->sharedRoomManager->updateRoomMeta($room);
            
            // 如果房间为空，从共享存储移除
            if ($room->getPlayerCount() === 0) {
                $this->sharedRoomManager->unregisterRoom($room->getId());
            }
        }
        
        $player->send('room:left', ['success' => true]);
    }

    /**
     * 处理获取房间列表
     */
    private function handleGetRooms(BasePlayer $player): void
    {
        $rooms = $this->getRoomManager()->getRooms();
        $roomsData = [];

        foreach ($rooms as $room) {
            if ($room instanceof GameRoom) {
                $roomsData[] = [
                    'id' => $room->getId(),
                    'players' => count($room->getPlayers()),
                    'max_players' => $this->gameConfig['game']['max_players'],
                    'config' => $room->getConfig()
                ];
            }
        }

        $player->send('rooms', ['rooms' => $roomsData]);
    }

    /**
     * 处理获取统计信息
     */
    private function handleGetStats(BasePlayer $player): void
    {
        $stats = $this->getRoomManager()->getStats();
        $player->send('stats', $stats);
    }

    /**
     * 启动服务器
     */
    public function run(): void
    {
        echo "Starting QiuQiu Game Server on {$this->gameConfig['host']}:{$this->gameConfig['port']}\n";
        parent::run();
    }
}

