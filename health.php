<?php
require_once __DIR__ . '/includes/bootstrap.php';
$pdo->query('SELECT 1');
header('Content-Type: text/plain; charset=utf-8');
echo "ok\n";
