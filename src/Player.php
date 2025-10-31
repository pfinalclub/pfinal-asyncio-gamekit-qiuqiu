<?php

namespace App;

/**
 * 玩家实体类
 */
class Player
{
    public string $id;
    public string $name;
    public float $x;
    public float $y;
    public float $size;
    public string $color;
    public float $targetX;
    public float $targetY;
    public float $velocityX;
    public float $velocityY;
    public bool $isDead;
    public int $respawnTime;
    public array $splitBalls;
    public bool $isSplitting;
    public int $score;
    public int $lastUpdateTime;

    public function __construct(string $id, string $name)
    {
        $this->id = $id;
        $this->name = $name;
        $this->x = 0;
        $this->y = 0;
        $this->size = 30;
        $this->color = $this->generateRandomColor();
        $this->targetX = 0;
        $this->targetY = 0;
        $this->velocityX = 0;
        $this->velocityY = 0;
        $this->isDead = false;
        $this->respawnTime = 0;
        $this->splitBalls = [];
        $this->isSplitting = false;
        $this->score = 0;
        $this->lastUpdateTime = time();
    }

    /**
     * 生成随机颜色
     */
    private function generateRandomColor(): string
    {
        $colors = [
            '#f72585', '#4cc9f0', '#4361ee', '#3a0ca3',
            '#fca311', '#e63946', '#457b9d', '#1d3557', '#a8dadc'
        ];
        return $colors[array_rand($colors)];
    }

    /**
     * 转换为数组（用于序列化）
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'x' => $this->x,
            'y' => $this->y,
            'size' => $this->size,
            'color' => $this->color,
            'isDead' => $this->isDead,
            'respawnTime' => $this->respawnTime,
            'isSplitting' => $this->isSplitting,
            'splitBalls' => $this->splitBalls,
            'score' => $this->score,
        ];
    }

    /**
     * 设置目标位置
     */
    public function setTarget(float $x, float $y): void
    {
        $this->targetX = $x;
        $this->targetY = $y;
    }

    /**
     * 更新位置
     */
    public function updatePosition(float $speed, float $mapWidth, float $mapHeight): void
    {
        if ($this->isDead) {
            return;
        }

        $dx = $this->targetX - $this->x;
        $dy = $this->targetY - $this->y;
        $distance = sqrt($dx * $dx + $dy * $dy);

        if ($distance > 10) {
            // 速度随体积减小（调整公式，让速度更快）
            $adjustedSpeed = $speed * max(0.5, 1 - $this->size / 300);
            $this->velocityX = ($dx / $distance) * $adjustedSpeed;
            $this->velocityY = ($dy / $distance) * $adjustedSpeed;
        } else {
            $this->velocityX = 0;
            $this->velocityY = 0;
        }

        $this->x += $this->velocityX;
        $this->y += $this->velocityY;

        // 边界检查
        $this->x = max($this->size, min($mapWidth - $this->size, $this->x));
        $this->y = max($this->size, min($mapHeight - $this->size, $this->y));
    }

    /**
     * 吞噬食物
     */
    public function eatFood(Food $food): void
    {
        $this->size += $food->size * 0.2;
        $this->score += (int)$food->size;
    }

    /**
     * 吞噬玩家
     */
    public function eatPlayer(Player $other): void
    {
        $this->size += sqrt($other->size * $other->size * 0.5);
        $this->score += (int)($other->size * 2);
    }

    /**
     * 被吞噬
     */
    public function beEaten(int $respawnDelay = 30): void
    {
        $this->isDead = true;
        $this->respawnTime = time() + $respawnDelay;
        $this->splitBalls = [];
        $this->isSplitting = false;
    }

    /**
     * 检查是否可以重生
     */
    public function canRespawn(): bool
    {
        return $this->isDead && time() >= $this->respawnTime;
    }

    /**
     * 重生
     */
    public function respawn(float $mapWidth, float $mapHeight): void
    {
        $this->isDead = false;
        $this->respawnTime = 0;
        $this->size = 30;
        $this->x = rand((int)$this->size, (int)($mapWidth - $this->size));
        $this->y = rand((int)$this->size, (int)($mapHeight - $this->size));
        $this->targetX = $this->x;
        $this->targetY = $this->y;
        $this->velocityX = 0;
        $this->velocityY = 0;
        $this->score = 0;
    }
}

