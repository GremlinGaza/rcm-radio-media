<?php
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
echo json_encode(get_programs($pdo), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);