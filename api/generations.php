<?php
require_once '../includes/functions.php';

/**
 * Рассчитывает поколение для каждого человека.
 * Поколение 0 = корень (самый старший предок или выбранный человек)
 * Поколение +1 = дети, +2 = внуки и т.д.
 * Поколение -1 = родители, -2 = дедушки и т.д.
 */
function calculateGenerations(PDO $pdo, ?int $rootId = null): array {
    $persons = $pdo->query("SELECT id FROM persons")->fetchAll(PDO::FETCH_COLUMN);
    $rels = $pdo->query("SELECT * FROM relationships")->fetchAll();
    
    // Строим граф связей
    $adj = [];
    foreach ($rels as $r) {
        $adj[$r['from_person_id']][] = ['to' => $r['to_person_id'], 'type' => $r['relation_type']];
        $adj[$r['to_person_id']][] = ['to' => $r['from_person_id'], 'type' => $r['relation_type']];
    }
    
    // Если не указан корень, находим самого старшего (без родителей)
    if (!$rootId) {
        $rootId = findRoot($pdo, $persons, $rels);
    }
    
    // BFS от корня
    $generations = [$rootId => 0];
    $queue = [$rootId];
    $visited = [$rootId => true];
    
    while (!empty($queue)) {
        $current = array_shift($queue);
        $currentGen = $generations[$current];
        
        foreach ($adj[$current] ?? [] as $link) {
            if (!isset($visited[$link['to']])) {
                $visited[$link['to']] = true;
                // Если current -> to это parent (current родитель to), то to на поколение ниже
                if ($link['type'] === 'parent' && $current === $link['to']) {
                    // current это ребёнок, to это родитель -> поколение выше
                    $generations[$link['to']] = $currentGen - 1;
                } else {
                    // В остальных случаях поколение ниже
                    $generations[$link['to']] = $currentGen + 1;
                }
                $queue[] = $link['to'];
            }
        }
    }
    
    return $generations;
}

/**
 * Найти корень дерева (человек без родителей или самый старший)
 */
function findRoot(PDO $pdo, array $persons, array $rels): int {
    // Находим людей без родителей
    $hasParent = [];
    foreach ($rels as $r) {
        if ($r['relation_type'] === 'parent') {
            $hasParent[$r['to_person_id']] = true;
        }
    }
    
    $roots = array_filter($persons, fn($id) => !isset($hasParent[$id]));
    
    if (empty($roots)) {
        return $persons[0]; // Если все связаны, берём первого
    }
    
    // Если несколько корней, берём самого старого
    $stmt = $pdo->prepare("SELECT id FROM persons WHERE id IN (" . implode(',', $roots) . ") ORDER BY birth_date ASC LIMIT 1");
    $stmt->execute();
    return (int)$stmt->fetchColumn();
}

// Возвращаем JSON
$rootId = (int)($_GET['root'] ?? 0);
$generations = calculateGenerations($pdo, $rootId ?: null);
jsonResponse($generations);