<?php

namespace App;

use PfinalClub\AsyncioGamekit\Room;
use PfinalClub\AsyncioGamekit\Player as BasePlayer;
use PfinalClub\AsyncioGamekit\Exceptions\RoomException;
use function PfinalClub\Asyncio\{sleep, create_task};
use Generator;
use GatewayWorker\Lib\Gateway;

/**
 * 球球大作战游戏房间
 */
class GameRoom extends Room
{
    private array $gamePlayers = []; // App\Player 实例
    private array $foods = [];
    private array $gameConfig;
    private int $lastFoodSpawnTime = 0;
    private int $lastStateUpdateTime = 0;
    private const UPDATE_INTERVAL = 33; // 约30fps
    private ?SpatialGrid $spatialGrid = null;
    private $gameLoopTask = null; // 保存游戏循环任务引用，用于清理
    // 已采用 GatewayWorker 单进程路由，无需副本/状态同步回调

    public function __construct(string $id, array $config = [])
    {
        $this->gameConfig = $config;
        parent::__construct($id, [
            'auto_start' => true,
            'min_players' => $config['min_players'] ?? 1,
            'max_players' => $config['max_players'] ?? 50,
        ]);
    }

    /**
     * 游戏主循环（框架要求实现的抽象方法）
     */
    protected function run(): mixed
    {
        // run() 方法已被 onStart() 中的 create_task 替代
        // 这里返回 null 即可，游戏循环在 onStart() 中使用 create_task 异步执行
        return null;
    }
    
    /**
     * 获取房间状态（用于序列化）
     */
    public function getState(): array
    {
        $playersState = [];
        foreach ($this->gamePlayers as $player) {
            $playersState[$player->id] = $player->toArray();
        }
        
        $foodsState = [];
        foreach ($this->foods as $food) {
            $foodsState[$food->id] = $food->toArray();
        }
        
        return [
            'players' => $playersState,
            'foods' => $foodsState,
            'lastFoodSpawnTime' => $this->lastFoodSpawnTime,
            'lastStateUpdateTime' => $this->lastStateUpdateTime,
        ];
    }
    
    /**
     * 恢复房间状态（从序列化数据）
     */
    public function restoreState(array $state): void
    {
        // 如果是房间副本，需要保留本地玩家的状态
        $localPlayerIds = [];
        if ($this->isReplica) {
            // 获取当前房间中的所有玩家ID（本地玩家）
            foreach ($this->getPlayers() as $player) {
                $localPlayerIds[] = $player->getId();
            }
        }
        
        // 恢复玩家状态
        // 如果是房间副本，保留本地玩家状态，只更新其他玩家
        if ($this->isReplica && !empty($localPlayerIds)) {
            // 保留本地玩家，只更新其他玩家
            $playersToKeep = [];
            foreach ($localPlayerIds as $localId) {
                if (isset($this->gamePlayers[$localId])) {
                    $playersToKeep[$localId] = $this->gamePlayers[$localId];
                }
            }
            
            // 更新其他玩家状态（从 SQLite）
            if (isset($state['players'])) {
                foreach ($state['players'] as $playerId => $playerData) {
                    // 跳过本地玩家
                    if (in_array($playerId, $localPlayerIds)) {
                        continue;
                    }
                    
                    // 更新或创建其他玩家
                    if (isset($this->gamePlayers[$playerId])) {
                        // 更新现有玩家状态
                        $player = $this->gamePlayers[$playerId];
                        $player->x = $playerData['x'];
                        $player->y = $playerData['y'];
                        $player->size = $playerData['size'];
                        $player->color = $playerData['color'];
                        $player->isDead = $playerData['isDead'] ?? false;
                        $player->respawnTime = $playerData['respawnTime'] ?? 0;
                        $player->isSplitting = $playerData['isSplitting'] ?? false;
                        $player->splitBalls = $playerData['splitBalls'] ?? [];
                        $player->score = $playerData['score'] ?? 0;
                    } else {
                        // 创建新玩家
                        $player = new Player($playerId, $playerData['name']);
                        $player->x = $playerData['x'];
                        $player->y = $playerData['y'];
                        $player->size = $playerData['size'];
                        $player->color = $playerData['color'];
                        $player->isDead = $playerData['isDead'] ?? false;
                        $player->respawnTime = $playerData['respawnTime'] ?? 0;
                        $player->isSplitting = $playerData['isSplitting'] ?? false;
                        $player->splitBalls = $playerData['splitBalls'] ?? [];
                        $player->score = $playerData['score'] ?? 0;
                        $this->gamePlayers[$playerId] = $player;
                    }
                }
            }
            
            // 移除不在状态中的其他玩家（已离开）
            foreach ($this->gamePlayers as $playerId => $player) {
                if (!in_array($playerId, $localPlayerIds) && !isset($state['players'][$playerId])) {
                    unset($this->gamePlayers[$playerId]);
                }
            }
        } else {
            // 原始房间或没有本地玩家，完全恢复状态
            $this->gamePlayers = [];
            if (isset($state['players'])) {
                foreach ($state['players'] as $playerId => $playerData) {
                    $player = new Player($playerId, $playerData['name']);
                    $player->x = $playerData['x'];
                    $player->y = $playerData['y'];
                    $player->size = $playerData['size'];
                    $player->color = $playerData['color'];
                    $player->isDead = $playerData['isDead'] ?? false;
                    $player->respawnTime = $playerData['respawnTime'] ?? 0;
                    $player->isSplitting = $playerData['isSplitting'] ?? false;
                    $player->splitBalls = $playerData['splitBalls'] ?? [];
                    $player->score = $playerData['score'] ?? 0;
                    $this->gamePlayers[$playerId] = $player;
                }
            }
        }
        
        // 恢复食物状态（所有房间都同步食物）
        $this->foods = [];
        if (isset($state['foods'])) {
            foreach ($state['foods'] as $foodId => $foodData) {
                $food = new Food($foodData['x'], $foodData['y'], $foodData['size'], $foodData['color']);
                $food->id = $foodId;
                $this->foods[$foodId] = $food;
            }
        }
        
        $this->lastFoodSpawnTime = $state['lastFoodSpawnTime'] ?? time();
        // 不覆盖 lastStateUpdateTime，保持本地时间戳
    }

    /**
     * 房间销毁时调用（清理资源）
     */
    protected function onDestroy(): mixed
    {
        // 清理游戏循环
        // 注意：PHP Fiber 会自动清理，但我们需要确保循环退出
        // 游戏循环中的 while(true) 已经检查了房间状态，会自动退出
        
        // 清理空间网格
        $this->spatialGrid = null;
        
        // 清理玩家和食物数据
        $this->gamePlayers = [];
        $this->foods = [];
        
        return null;
    }

    /**
     * 房间创建时调用
     */
    protected function onCreate(): mixed
    {
        // 房间创建时的初始化
        return null;
    }

    /**
     * 游戏开始时调用
     */
    protected function onStart(): mixed
    {
        // 游戏开始时的准备工作
        // 广播游戏开始消息
        $this->broadcast('game:start', [
            'message' => '游戏开始！',
            'players' => count($this->getPlayers())
        ]);
        
        // 初始化空间分区系统
        // 网格大小设为最大物体大小的2倍（约60，最大玩家大小约30）
        $gridSize = 60;
        $this->spatialGrid = new SpatialGrid(
            $gridSize,
            $this->gameConfig['map_width'],
            $this->gameConfig['map_height']
        );
        
        // 使用 create_task 异步执行游戏循环，保存任务引用
        $this->gameLoopTask = create_task(function() {
            // 初始化游戏（生成食物等）
            $this->initializeGame();
            
            // 初始化时间戳
            $this->lastStateUpdateTime = (int)(microtime(true) * 1000);
            $this->lastFoodSpawnTime = time();
            $this->lastStateReadTime = $this->lastStateUpdateTime; // 初始化状态读取时间

            // 游戏主循环
            while (true) {
                // 检查房间是否还在运行
                if ($this->status === 'finished') {
                    break; // 房间已结束，退出循环
                }
                
                $currentTime = (int)(microtime(true) * 1000);

                // 更新游戏状态（约30fps）
                if ($currentTime - $this->lastStateUpdateTime >= self::UPDATE_INTERVAL) {
                    $this->updateGameState();
                    $this->checkCollisions();
                    $this->updateRespawns();
                    $this->spawnFoodIfNeeded();
                    $this->broadcastGameState();
                    $this->lastStateUpdateTime = $currentTime;
                }

                sleep(0.01); // 10ms
            }
        });
        
        return null;
    }

    /**
     * 重写 addPlayer 方法，允许在房间运行时继续加入玩家
     */
    public function addPlayer(BasePlayer $player): bool
    {
        if (count($this->players) >= $this->config['max_players']) {
            throw RoomException::roomFull($this->id, $this->config['max_players']);
        }

        if (isset($this->players[$player->getId()])) {
            throw RoomException::playerAlreadyInRoom($player->getId(), $this->id);
        }

        // 移除对 running 状态的检查，允许运行时加入
        // if ($this->status === 'running') {
        //     throw RoomException::roomAlreadyStarted($this->id);
        // }

        $this->players[$player->getId()] = $player;
        $player->setRoom($this);

        $this->onPlayerJoin($player);
        $this->broadcast('player:join', $player->toArray());

        // 只有在等待状态且满足条件时才自动开始
        if ($this->status === 'waiting' && ($this->config['auto_start'] ?? false) && $this->canStart()) {
            create_task(fn() => $this->start());
        }

        return true;
    }

    /**
     * 玩家加入房间
     */
    protected function onPlayerJoin(BasePlayer $player): void
    {
        parent::onPlayerJoin($player);

        $gamePlayer = new Player($player->getId(), $player->getName());
        
        // 随机初始位置
        $gamePlayer->x = rand(
            (int)$gamePlayer->size,
            (int)($this->gameConfig['map_width'] - $gamePlayer->size)
        );
        $gamePlayer->y = rand(
            (int)$gamePlayer->size,
            (int)($this->gameConfig['map_height'] - $gamePlayer->size)
        );
        $gamePlayer->targetX = $gamePlayer->x;
        $gamePlayer->targetY = $gamePlayer->y;

        $this->gamePlayers[$player->getId()] = $gamePlayer;

        // 广播玩家加入
        $this->broadcast('player:joined', [
            'player' => $gamePlayer->toArray(),
            'totalPlayers' => count($this->gamePlayers),
            'rankings' => $this->calculateRankings() // 同时发送最新的排行榜
        ]);
    }

    /**
     * 玩家离开房间
     */
    protected function onPlayerLeave(BasePlayer $player): void
    {
        $playerId = $player->getId();
        
        if (isset($this->gamePlayers[$playerId])) {
            unset($this->gamePlayers[$playerId]);
        }

        parent::onPlayerLeave($player);

        // 广播玩家离开
        $this->broadcast('player:left', [
            'playerId' => $playerId,
            'totalPlayers' => count($this->gamePlayers)
        ]);
    }

    /**
     * 重命名玩家（更新房间内显示与排行榜）
     */
    public function renamePlayer(BasePlayer $player, string $newName): void
    {
        $playerId = $player->getId();
        if (isset($this->gamePlayers[$playerId])) {
            $this->gamePlayers[$playerId]->name = $newName;
            // 广播重命名并同步最新排行榜
            $this->broadcast('player:renamed', [
                'playerId' => $playerId,
                'name' => $newName,
                'rankings' => $this->calculateRankings()
            ]);
        }
    }

    /**
     * 断线强制移除玩家，避免残留球体
     */
    public function disconnectPlayerById(string $playerId): void
    {
        // 从基础玩家列表移除
        if (isset($this->players[$playerId])) {
            unset($this->players[$playerId]);
        }
        // 从游戏实体列表移除
        if (isset($this->gamePlayers[$playerId])) {
            unset($this->gamePlayers[$playerId]);
            $this->broadcast('player:left', [
                'playerId' => $playerId,
                'totalPlayers' => count($this->gamePlayers)
            ]);
        }
    }

    public function onPlayerMessage(BasePlayer $player, string $event, mixed $data): mixed
    {
        $playerId = $player->getId();
        
        if (!isset($this->gamePlayers[$playerId])) {
            return null;
        }

        $gamePlayer = $this->gamePlayers[$playerId];
        $data = is_array($data) ? $data : [];

        switch ($event) {
            case 'player:move':
                $this->onPlayerMove($gamePlayer, $data);
                break;
            case 'player:split':
                $this->onPlayerSplit($gamePlayer);
                break;
            case 'player:merge':
                $this->onPlayerMerge($gamePlayer);
                break;
            case 'set_name':
                $this->onSetName($gamePlayer, $data);
                break;
        }
        
        return null;
    }

    /**
     * 处理玩家移动
     */
    private function onPlayerMove(Player $player, array $data): void
    {
        if ($player->isDead) {
            return;
        }

        if (isset($data['x']) && isset($data['y'])) {
            $player->setTarget((float)$data['x'], (float)$data['y']);
        }
    }

    /**
     * 处理玩家分裂
     */
    private function onPlayerSplit(Player $player): void
    {
        if ($player->isDead || $player->isSplitting || $player->size < 40) {
            return;
        }

        $player->isSplitting = true;
        $player->size *= 0.6;

        // 创建分裂小球
        $splitCount = 2;
        for ($i = 0; $i < $splitCount; $i++) {
            $angle = ($i / $splitCount) * M_PI * 2;
            $distance = $player->size * 1.5;
            
            $player->splitBalls[] = [
                'x' => $player->x + cos($angle) * $distance,
                'y' => $player->y + sin($angle) * $distance,
                'size' => $player->size,
                'color' => $player->color
            ];
        }

        // 5秒后自动合并
        $this->set('split_' . $player->id, time() + 5);
    }

    /**
     * 处理玩家合并
     */
    private function onPlayerMerge(Player $player): void
    {
        if (!$player->isSplitting || empty($player->splitBalls)) {
            return;
        }

        // 计算总质量
        $totalSize = $player->size * $player->size;
        foreach ($player->splitBalls as $ball) {
            $totalSize += $ball['size'] * $ball['size'];
        }

        $player->size = sqrt($totalSize);
        $player->isSplitting = false;
        $player->splitBalls = [];
    }

    /**
     * 处理设置名称
     */
    private function onSetName(Player $player, array $data): void
    {
        if (isset($data['name']) && !empty($data['name'])) {
            $player->name = substr($data['name'], 0, 20);
        }
    }

    /**
     * 更新游戏状态
     */
    private function updateGameState(): void
    {
        $speed = $this->gameConfig['player_speed'];

        foreach ($this->gamePlayers as $player) {
            if (!$player->isDead) {
                $player->updatePosition(
                    $speed,
                    $this->gameConfig['map_width'],
                    $this->gameConfig['map_height']
                );
            }

            // 处理分裂状态
            if ($player->isSplitting) {
                $splitKey = 'split_' . $player->id;
                if ($this->get($splitKey) !== null && time() >= $this->get($splitKey)) {
                    $this->onPlayerMerge($player);
                    $this->set($splitKey, null);
                }

                // 更新分裂小球位置
                foreach ($player->splitBalls as &$ball) {
                    $dx = $player->x - $ball['x'];
                    $dy = $player->y - $ball['y'];
                    $distance = sqrt($dx * $dx + $dy * $dy);

                    if ($distance > 10) {
                        $ballSpeed = $speed * 1.5;
                        $ball['x'] += ($dx / $distance) * $ballSpeed;
                        $ball['y'] += ($dy / $distance) * $ballSpeed;
                    }
                }
            }
        }
    }

    /**
     * 初始化游戏
     */
    private function initializeGame(): void
    {
        // 生成初始食物
        $this->generateInitialFood();
    }

    /**
     * 生成初始食物
     */
    private function generateInitialFood(): void
    {
        $foodCount = $this->gameConfig['food_count'];
        for ($i = 0; $i < $foodCount; $i++) {
            $this->spawnFood();
        }
    }

    /**
     * 生成单个食物
     */
    private function spawnFood(): void
    {
        $size = $this->gameConfig['food_size_min'] + 
                rand(0, (int)($this->gameConfig['food_size_max'] - $this->gameConfig['food_size_min']));
        
        $x = $size + rand(0, (int)($this->gameConfig['map_width'] - 2 * $size));
        $y = $size + rand(0, (int)($this->gameConfig['map_height'] - 2 * $size));

        $food = new Food($x, $y, $size);
        $this->foods[$food->id] = $food;
    }

    /**
     * 根据需要生成食物
     */
    private function spawnFoodIfNeeded(): void
    {
        $currentTime = time();
        
        // 每秒生成一个新食物，直到达到上限
        if ($currentTime - $this->lastFoodSpawnTime >= 1 && 
            count($this->foods) < $this->gameConfig['food_count']) {
            $this->spawnFood();
            $this->lastFoodSpawnTime = $currentTime;
        }
    }

    // 已移除副本/状态同步相关接口
    
    /**
     * 重写广播方法，使用 Gateway 进行跨进程广播
     */
    public function broadcast(string $event, mixed $data = null, ?BasePlayer $except = null): void
    {
        // 检查是否在 GatewayWorker 环境下
        if (class_exists('GatewayWorker\Lib\Gateway')) {
            // 使用 Gateway 进行跨进程广播
            $message = json_encode([
                'event' => $event,
                'data' => $data
            ]);
            
            // 通过房间 ID 广播给所有在房间中的玩家（跨进程）
            // 注意：Gateway::sendToGroup 不支持排除特定玩家，如果需要排除，需要单独处理
            if ($except === null) {
                Gateway::sendToGroup($this->getId(), $message);
            } else {
                // 如果需要排除某个玩家，获取所有玩家并逐个发送
                $players = $this->getPlayers();
                foreach ($players as $player) {
                    if ($player->getId() !== $except->getId()) {
                        Gateway::sendToClient($player->getId(), $message);
                    }
                }
            }
        } else {
            // 回退到基类的广播方法（单进程模式）
            parent::broadcast($event, $data, $except);
        }
    }
    
    /**
     * 广播游戏状态（确保分裂小球状态完整同步）
     */
    private function broadcastGameState(): void
    {
        $playersData = [];
        foreach ($this->gamePlayers as $player) {
            $playerData = $player->toArray();
            
            // 确保分裂小球的状态完整同步
            if ($player->isSplitting && !empty($player->splitBalls)) {
                // 确保 splitBalls 包含完整信息
                $playerData['splitBalls'] = array_map(function($ball) {
                    return [
                        'x' => $ball['x'],
                        'y' => $ball['y'],
                        'size' => $ball['size'],
                        'color' => $ball['color']
                    ];
                }, $player->splitBalls);
            } else {
                // 确保客户端知道没有分裂
                $playerData['splitBalls'] = [];
            }
            
            $playersData[] = $playerData;
        }

        $foodsData = [];
        foreach ($this->foods as $food) {
            $foodsData[] = $food->toArray();
        }

        // 计算排行榜
        $rankings = $this->calculateRankings();

        $this->broadcast('game:state', [
            'players' => $playersData,
            'foods' => $foodsData,
            'rankings' => $rankings,
            'timestamp' => (int)(microtime(true) * 1000)
        ]);
        
        // 单进程路由模式下，无需将状态持续写回共享存储
    }

    /**
     * 计算排行榜
     */
    private function calculateRankings(): array
    {
        $rankings = [];
        
        foreach ($this->gamePlayers as $player) {
            $rankings[] = [
                'id' => $player->id,
                'name' => $player->name,
                'score' => $player->score,
                'size' => $player->size,
                'color' => $player->color,
                'isDead' => $player->isDead
            ];
        }

        // 按分数排序
        usort($rankings, function($a, $b) {
            return $b['score'] - $a['score'];
        });

        return array_slice($rankings, 0, 10); // 返回前10名
    }

    /**
     * 检查碰撞（使用空间分区优化）
     */
    private function checkCollisions(): void
    {
        if (!$this->spatialGrid) {
            return;
        }

        // 清空空间网格
        $this->spatialGrid->clear();

        // 将所有实体插入空间网格
        foreach ($this->gamePlayers as $player) {
            if (!$player->isDead) {
                $this->spatialGrid->insert($player, 'player');
                
                // 分裂小球也插入网格
                if ($player->isSplitting) {
                    foreach ($player->splitBalls as $index => $ball) {
                        // 创建临时对象用于空间分区
                        $ballObj = (object)[
                            'x' => $ball['x'],
                            'y' => $ball['y'],
                            'size' => $ball['size'],
                            'id' => $player->id . '_ball_' . $index
                        ];
                        $this->spatialGrid->insert($ballObj, 'split_ball');
                    }
                }
            }
        }

        foreach ($this->foods as $food) {
            $this->spatialGrid->insert($food, 'food');
        }

        // 玩家与食物碰撞（使用空间分区）
        foreach ($this->gamePlayers as $player) {
            if ($player->isDead) {
                continue;
            }

            if ($player->isSplitting) {
                // 分裂小球与食物碰撞
                foreach ($player->splitBalls as &$ball) {
                    // 只检查附近的食物
                    $nearbyFoods = $this->spatialGrid->getNearby(
                        $ball['x'],
                        $ball['y'],
                        $ball['size'] + 20, // 搜索半径
                        'food'
                    );

                    foreach ($nearbyFoods as $food) {
                        $distance = $this->calculateDistance(
                            $ball['x'], $ball['y'],
                            $food->x, $food->y
                        );

                        if ($distance < $ball['size'] - $food->size) {
                            $ball['size'] += $food->size * 0.2;
                            unset($this->foods[$food->id]);
                            break; // 一个分裂小球一次只能吃一个食物
                        }
                    }
                }
            } else {
                // 玩家与食物碰撞
                $nearbyFoods = $this->spatialGrid->getNearby(
                    $player->x,
                    $player->y,
                    $player->size + 20, // 搜索半径
                    'food'
                );

                foreach ($nearbyFoods as $food) {
                    $distance = $this->calculateDistance(
                        $player->x, $player->y,
                        $food->x, $food->y
                    );

                    if ($distance < $player->size - $food->size) {
                        $player->eatFood($food);
                        unset($this->foods[$food->id]);
                    }
                }
            }
        }

        // 玩家与玩家碰撞（使用空间分区）
        $playerArray = array_values($this->gamePlayers);
        for ($i = 0; $i < count($playerArray); $i++) {
            $player1 = $playerArray[$i];
            
            if ($player1->isDead) {
                continue;
            }

            // 只检查附近的玩家
            $nearbyPlayers = $this->spatialGrid->getNearby(
                $player1->x,
                $player1->y,
                $player1->size * 2, // 搜索半径
                'player'
            );

            foreach ($nearbyPlayers as $player2) {
                // 确保是不同玩家且未死亡
                if ($player2->id === $player1->id || $player2->isDead) {
                    continue;
                }

                // 避免重复检测（只检测一次）
                if ($player2->id < $player1->id) {
                    continue;
                }

                $distance = $this->calculateDistance(
                    $player1->x, $player1->y,
                    $player2->x, $player2->y
                );

                if ($distance < $player1->size + $player2->size) {
                    $this->handlePlayerCollision($player1, $player2);
                }
            }
        }
    }

    /**
     * 处理玩家碰撞
     */
    private function handlePlayerCollision(Player $player1, Player $player2): void
    {
        // 判断大小关系
        if ($player1->size > $player2->size * 1.1) {
            // player1 吞噬 player2
            $player1->eatPlayer($player2);
            $player2->beEaten($this->gameConfig['respawn_time']);
            
            $this->broadcast('player:eaten', [
                'playerId' => $player2->id,
                'killerId' => $player1->id,
                'killerName' => $player1->name
            ]);
        } elseif ($player2->size > $player1->size * 1.1) {
            // player2 吞噬 player1
            $player2->eatPlayer($player1);
            $player1->beEaten($this->gameConfig['respawn_time']);
            
            $this->broadcast('player:eaten', [
                'playerId' => $player1->id,
                'killerId' => $player2->id,
                'killerName' => $player2->name
            ]);
        }
    }

    /**
     * 更新重生状态
     */
    private function updateRespawns(): void
    {
        foreach ($this->gamePlayers as $player) {
            if ($player->canRespawn()) {
                $player->respawn(
                    $this->gameConfig['map_width'],
                    $this->gameConfig['map_height']
                );

                $this->broadcast('player:respawn', [
                    'playerId' => $player->id
                ]);
            }
        }
    }

    /**
     * 计算两点之间的距离
     */
    private function calculateDistance(float $x1, float $y1, float $x2, float $y2): float
    {
        $dx = $x1 - $x2;
        $dy = $y1 - $y2;
        return sqrt($dx * $dx + $dy * $dy);
    }

    /**
     * 获取房间配置
     */
    public function getConfig(): array
    {
        return [
            'max_players' => $this->gameConfig['max_players'],
            'min_players' => $this->gameConfig['min_players'],
            'map_width' => $this->gameConfig['map_width'],
            'map_height' => $this->gameConfig['map_height'],
            'width' => $this->gameConfig['map_width'],  // 前端兼容
            'height' => $this->gameConfig['map_height'], // 前端兼容
        ];
    }
}

