<?php
/**
 * GET /api/categories
 * 返回嵌套结构：大版块 → 子版块
 */

$pdo = getDB();

// 所有版块
$all = $pdo->query('SELECT * FROM categories ORDER BY sort_order ASC')->fetchAll();

// 分组：大版块(parent_id IS NULL) → 子版块(parent_id = 大版块.id)
$parents = [];
$children = [];
foreach ($all as $cat) {
    $cat['id'] = (int)$cat['id'];
    $cat['parent_id'] = $cat['parent_id'] ? (int)$cat['parent_id'] : null;
    $cat['sort_order'] = (int)$cat['sort_order'];
    $cat['post_count'] = (int)$cat['post_count'];
    if (!$cat['parent_id']) {
        $cat['children'] = [];
        $parents[$cat['id']] = $cat;
    } else {
        $children[] = $cat;
    }
}
foreach ($children as $child) {
    if (isset($parents[$child['parent_id']])) {
        $parents[$child['parent_id']]['children'][] = $child;
    }
}

success(array_values($parents));
