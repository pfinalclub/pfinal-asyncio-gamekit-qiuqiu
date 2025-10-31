# 多人对战球球大作战

基于 `pfinal-asyncio-gamekit` 框架开发的多人对战球球大作战游戏。
![](https://raw.githubusercontent.com/pfinal-nc/iGallery/master/blog/202510311610506.png)
![](https://raw.githubusercontent.com/pfinal-nc/iGallery/master/blog/202510311610932.png)


## 功能特性

- ✅ 支持 1-50 人多人对战
- ✅ 快速匹配系统
- ✅ 服务端碰撞检测和验证
- ✅ 玩家吞噬机制（大的吞噬小的）
- ✅ 重生机制（被吞噬后30秒重生，名字变红）
- ✅ 分裂和合并功能
- ✅ 实时排行榜
- ✅ WebSocket 实时通信

## 安装

```bash
# 安装依赖
composer install

# 创建必要的目录
mkdir -p logs storage runtime
```

## 启动服务器

```bash
php start.php start
```

服务器将在 `0.0.0.0:2345` 启动。

## 访问游戏

打开浏览器访问 `public/index.html`，或使用本地服务器：

```bash
# 使用 PHP 内置服务器
cd public
php -S localhost:8000
```

然后访问 `http://localhost:8000`

## 配置

编辑 `config/server.php` 可以修改服务器配置：

```php
return [
    'host' => '0.0.0.0',
    'port' => 2345,
    'worker_count' => 4,
    'game' => [
        'max_players' => 50,
        'min_players' => 1,
        'map_width' => 2000,
        'map_height' => 2000,
        'food_count' => 100,
        'respawn_time' => 30,
    ]
];
```

## 游戏玩法

1. 连接服务器后输入玩家名称
2. 使用鼠标或触摸摇杆控制小球移动
3. 吞噬比自己小的球来增大体积
4. 使用分裂功能可以提高移动速度
5. 被吞噬后等待30秒重生（名字会变红显示倒计时）

## 技术栈

- 后端：PHP + pfinal-asyncio-gamekit + Workerman
- 前端：HTML5 Canvas + WebSocket + Tailwind CSS

## 项目结构

```
├── src/
│   ├── GameRoom.php      # 游戏房间逻辑
│   ├── Player.php        # 玩家实体
│   ├── Food.php          # 食物实体
│   └── GameServer.php    # WebSocket 服务器
├── public/
│   └── index.html        # 前端游戏界面
├── config/
│   └── server.php        # 服务器配置
├── logs/                 # 日志目录
├── storage/              # 存储目录
└── start.php             # 启动脚本
```

## 开发说明

### 后端消息协议

**客户端发送：**
- `set_name`: 设置玩家名称
- `quick_match`: 快速匹配
- `player:move`: 移动目标位置
- `player:split`: 分裂
- `player:merge`: 合并

**服务端发送：**
- `game:state`: 游戏状态更新（包含所有玩家、食物、排行榜）
- `player:eaten`: 玩家被吞噬事件
- `player:respawn`: 玩家重生事件
- `quick_match`: 匹配成功

### 前端 WebSocket 地址

默认连接 `ws://localhost:2345`，可在 `public/index.html` 中修改 `WS_URL` 常量。

## 许可证

MIT License

