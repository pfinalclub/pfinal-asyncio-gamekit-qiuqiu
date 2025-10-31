<?php

namespace App;

/**
 * 食物实体类
 */
class Food
{
    public string $id;
    public float $x;
    public float $y;
    public float $size;
    public string $color;

    public function __construct(float $x, float $y, ?float $size = null, ?string $color = null)
    {
        $this->id = uniqid('food_');
        $this->x = $x;
        $this->y = $y;
        $this->size = $size ?? (5 + rand(0, 10));
        $this->color = $color ?? $this->generateRandomColor();
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
            'x' => $this->x,
            'y' => $this->y,
            'size' => $this->size,
            'color' => $this->color,
        ];
    }
}

