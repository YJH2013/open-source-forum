<?php
/**
 * 统一 JSON 响应工具
 */

function jsonResponse($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function success($data = null, string $message = 'ok'): void {
    jsonResponse(['code' => 0, 'message' => $message, 'data' => $data]);
}

function error(string $message, int $code = 400): void {
    jsonResponse(['code' => $code, 'message' => $message, 'data' => null], $code);
}

function paginated(array $list, int $total, int $page, int $limit): void {
    jsonResponse([
        'code' => 0,
        'message' => 'ok',
        'data' => [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'pageSize' => $limit,
            'totalPages' => ceil($total / $limit)
        ]
    ]);
}
