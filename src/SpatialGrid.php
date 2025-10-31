<?php

namespace App;

/**
 * 空间网格系统 - 用于优化碰撞检测
 * 将地图划分为网格，只检测相邻网格的物体，大幅降低碰撞检测复杂度
 */
class SpatialGrid
{
    private array $grid = [];
    private float $cellSize;
    private float $mapWidth;
    private float $mapHeight;
    private int $cols;
    private int $rows;

    /**
     * @param float $cellSize 网格大小（建议为最大物体大小的2-3倍）
     * @param float $mapWidth 地图宽度
     * @param float $mapHeight 地图高度
     */
    public function __construct(float $cellSize, float $mapWidth, float $mapHeight)
    {
        $this->cellSize = $cellSize;
        $this->mapWidth = $mapWidth;
        $this->mapHeight = $mapHeight;
        $this->cols = (int)ceil($mapWidth / $cellSize);
        $this->rows = (int)ceil($mapHeight / $cellSize);
        
        // 初始化网格
        $this->grid = [];
        for ($x = 0; $x < $this->cols; $x++) {
            for ($y = 0; $y < $this->rows; $y++) {
                $this->grid[$x][$y] = [];
            }
        }
    }

    /**
     * 将坐标转换为网格索引
     */
    private function getGridIndex(float $x, float $y): array
    {
        $col = (int)floor($x / $this->cellSize);
        $row = (int)floor($y / $this->cellSize);
        
        // 边界检查
        $col = max(0, min($col, $this->cols - 1));
        $row = max(0, min($row, $this->rows - 1));
        
        return [$col, $row];
    }

    /**
     * 清空所有网格
     */
    public function clear(): void
    {
        for ($x = 0; $x < $this->cols; $x++) {
            for ($y = 0; $y < $this->rows; $y++) {
                $this->grid[$x][$y] = [];
            }
        }
    }

    /**
     * 插入实体到网格
     * 
     * @param mixed $entity 实体对象（必须有 x, y, size 属性）
     * @param string $type 实体类型（'player' 或 'food'）
     */
    public function insert($entity, string $type): void
    {
        if (!isset($entity->x) || !isset($entity->y)) {
            return;
        }

        $size = $entity->size ?? 0;
        
        // 计算实体占用的网格范围
        $minX = max(0, floor(($entity->x - $size) / $this->cellSize));
        $maxX = min($this->cols - 1, floor(($entity->x + $size) / $this->cellSize));
        $minY = max(0, floor(($entity->y - $size) / $this->cellSize));
        $maxY = min($this->rows - 1, floor(($entity->y + $size) / $this->cellSize));

        // 将实体添加到所有覆盖的网格中
        for ($x = $minX; $x <= $maxX; $x++) {
            for ($y = $minY; $y <= $maxY; $y++) {
                $this->grid[$x][$y][] = [
                    'entity' => $entity,
                    'type' => $type
                ];
            }
        }
    }

    /**
     * 获取指定位置附近的实体
     * 
     * @param float $x X坐标
     * @param float $y Y坐标
     * @param float $radius 搜索半径
     * @param string|null $type 过滤类型（null表示所有类型）
     * @return array 实体列表
     */
    public function getNearby(float $x, float $y, float $radius, ?string $type = null): array
    {
        $result = [];
        $seen = []; // 用于去重

        // 计算搜索范围
        $minX = max(0, floor(($x - $radius) / $this->cellSize));
        $maxX = min($this->cols - 1, floor(($x + $radius) / $this->cellSize));
        $minY = max(0, floor(($y - $radius) / $this->cellSize));
        $maxY = min($this->rows - 1, floor(($y + $radius) / $this->cellSize));

        // 遍历相关网格
        for ($gridX = $minX; $gridX <= $maxX; $gridX++) {
            for ($gridY = $minY; $gridY <= $maxY; $gridY++) {
                foreach ($this->grid[$gridX][$gridY] as $item) {
                    $entity = $item['entity'];
                    $entityType = $item['type'];
                    
                    // 类型过滤
                    if ($type !== null && $entityType !== $type) {
                        continue;
                    }
                    
                    // 使用对象ID或hash去重
                    $entityId = $this->getEntityId($entity, $entityType);
                    if (isset($seen[$entityId])) {
                        continue;
                    }
                    
                    // 精确距离检查（避免边界误判）
                    $dx = $entity->x - $x;
                    $dy = $entity->y - $y;
                    $distance = sqrt($dx * $dx + $dy * $dy);
                    
                    if ($distance <= $radius) {
                        $result[] = $entity;
                        $seen[$entityId] = true;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * 获取实体的唯一标识
     */
    private function getEntityId($entity, string $type): string
    {
        if (isset($entity->id)) {
            return $type . '_' . $entity->id;
        }
        // 如果没有ID，使用对象hash
        return $type . '_' . spl_object_hash($entity);
    }
}

