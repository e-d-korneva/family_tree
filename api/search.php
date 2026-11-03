<?php
require_once __DIR__ . '/../includes/functions.php';

$query = trim($_GET['q'] ?? '');
if (strlen($query) < 2) {
    jsonResponse([]);
}

// ИСПРАВЛЕНО: экранирование SQL-wildcards
$escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $query);
$searchTerm = "%$escaped%";

$stmt = $pdo->prepare("
    SELECT id, first_name, middle_name, last_name, maiden_name, birth_date, death_date 
    FROM persons 
    WHERE last_name LIKE ? OR first_name LIKE ? OR maiden_name LIKE ? OR middle_name LIKE ?
    ORDER BY last_name, first_name 
    LIMIT 20
");
$stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
$results = $stmt->fetchAll();

$formatted = array_map(function($p) {
    $name = trim(($p['last_name'] ?? '') . ' ' . ($p['first_name'] ?? '') . ' ' . ($p['middle_name'] ?? ''));
    if ($p['maiden_name']) $name .= ' (ур. ' . $p['maiden_name'] . ')';
    return [
        'id' => $p['id'],
        'text' => $name . ' (' . getLifeDates($p) . ')'
    ];
}, $results);

jsonResponse($formatted);