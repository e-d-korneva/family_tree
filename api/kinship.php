<?php
require_once '../includes/functions.php';

$p1 = (int)($_GET['p1'] ?? 0); 
$p2 = (int)($_GET['p2'] ?? 0);

if (!$p1 || !$p2) {
    jsonResponse(['error' => 'Укажите двух людей'], 400);
}

$res = findPath($pdo, $p1, $p2);
if (!$res) {
    jsonResponse([
        'kinship' => 'Родственные связи не найдены', 
        'person1' => getFullName(getPerson($pdo, $p1) ?: []), 
        'person2' => getFullName(getPerson($pdo, $p2) ?: [])
    ]);
} else {
    jsonResponse([
        'kinship' => determineKinship($pdo, $res['path'], $res['types']),
        'person1' => getFullName(getPerson($pdo, $p1) ?: []),
        'person2' => getFullName(getPerson($pdo, $p2) ?: []),
        'path' => $res['path'],
        'pathEdges' => $res['edges']
    ]);
}

function findPath(PDO $pdo, int $p1, int $p2): ?array {
    if ($p1 === $p2) return ['path' => [$p1], 'edges' => [], 'types' => []];
    
    $allRels = $pdo->query("SELECT * FROM relationships")->fetchAll();
    $adj = [];
    foreach ($allRels as $r) {
        // Прямое направление
        $adj[$r['from_person_id']][] = [
            'to' => $r['to_person_id'], 'type' => $r['relation_type'], 'id' => $r['id'],
            'is_adopted' => $r['is_adopted'], 'is_step' => $r['is_step'], 'shared_parent' => $r['shared_parent'],
            'divorce_date' => $r['divorce_date'], 'is_widowed' => $r['is_widowed']
        ];
        // Обратное направление
        $reverse = $r['relation_type'] === 'parent' ? 'child' : ($r['relation_type'] === 'child' ? 'parent' : 'spouse');
        $adj[$r['to_person_id']][] = [
            'to' => $r['from_person_id'], 'type' => $reverse, 'id' => $r['id'],
            'is_adopted' => $r['is_adopted'], 'is_step' => $r['is_step'], 'shared_parent' => $r['shared_parent'],
            'divorce_date' => $r['divorce_date'], 'is_widowed' => $r['is_widowed']
        ];
    }
    
    $queue = [[$p1, [], [], []]]; // [текущий, путь узлов, путь рёбер, типы связей]
    $visited = [$p1 => true];
    
    while (!empty($queue)) {
        [$cur, $path, $edges, $types] = array_shift($queue);
        if ($cur == $p2) {
            return ['path' => array_merge($path, [$p2]), 'edges' => $edges, 'types' => array_merge($types, [$cur])];
        }
        foreach ($adj[$cur] ?? [] as $link) {
            if (!isset($visited[$link['to']])) {
                $visited[$link['to']] = true;
                $queue[] = [
                    $link['to'],
                    array_merge($path, [$cur]),
                    array_merge($edges, [$link['id']]),
                    array_merge($types, [$link['type'] . '|' . ($link['is_adopted']?'A':'') . '|' . ($link['is_step']?'S':'') . '|' . $link['shared_parent'] . '|' . ($link['divorce_date']?'D':'') . '|' . ($link['is_widowed']?'W':'')])
                ];
            }
        }
    }
    return null;
}

function determineKinship(PDO $pdo, array $path, array $types): string {
    if (count($path) < 2) return 'это один и тот же человек';
    
    $p1 = $path[0]; 
    $p2 = $path[count($path) - 1];
    
    $stmt = $pdo->prepare("SELECT gender FROM persons WHERE id = ?");
    $stmt->execute([$p2]); $g2 = $stmt->fetchColumn() ?: 'U';
    $stmt->execute([$p1]); $g1 = $stmt->fetchColumn() ?: 'U';

    $up = 0; $down = 0; $spouse = 0; 
    $isStep = false; $isAdopted = false; $shared = 'both'; $isDivorced = false; $isWidowed = false;
    
    foreach ($types as $t) {
        $parts = explode('|', $t);
        if ($parts[0] === 'parent') $up++;
        elseif ($parts[0] === 'child') $down++;
        elseif ($parts[0] === 'spouse') $spouse++;
        
        if (strpos($parts[1], 'A') !== false) $isAdopted = true;
        if (strpos($parts[2], 'S') !== false) $isStep = true;
        if ($parts[3] !== 'both' && $parts[3] !== '') $shared = $parts[3];
        if (strpos($parts[4], 'D') !== false) $isDivorced = true;
        if (strpos($parts[5], 'W') !== false) $isWidowed = true;
    }

    // 1. Прямая линия вверх (Предки)
    if ($up > 0 && $down === 0 && $spouse === 0) {
        if ($isStep) return $g2 === 'M' ? 'Отчим' : ($g2 === 'F' ? 'Мачеха' : 'Отчим/Мачеха');
        if ($up === 1) return $g2 === 'M' ? 'Отец' : ($g2 === 'F' ? 'Мать' : 'Родитель');
        if ($up === 2) return $g2 === 'M' ? 'Дед' : ($g2 === 'F' ? 'Бабушка' : 'Дед/Бабушка');
        if ($up === 3) return $g2 === 'M' ? 'Прадед' : ($g2 === 'F' ? 'Прабабушка' : 'Прадед/Прабабушка');
        return $g2 === 'M' ? "Предок ($up поколений)" : "Предок ($up поколений)";
    }
    
    // 2. Прямая линия вниз (Потомки)
    if ($down > 0 && $up === 0 && $spouse === 0) {
        if ($isStep) return $g2 === 'M' ? 'Пасынок' : ($g2 === 'F' ? 'Падчерица' : 'Пасынок/Падчерица');
        if ($down === 1) return $g2 === 'M' ? 'Сын' : ($g2 === 'F' ? 'Дочь' : 'Ребёнок');
        if ($down === 2) return $g2 === 'M' ? 'Внук' : ($g2 === 'F' ? 'Внучка' : 'Внук/Внучка');
        if ($down === 3) return $g2 === 'M' ? 'Правнук' : ($g2 === 'F' ? 'Правнучка' : 'Правнук/Правнучка');
    }
    
    // 3. Супруги
    if ($up === 0 && $down === 0 && $spouse > 0) {
        if ($isDivorced) return $g2 === 'M' ? 'Бывший муж' : ($g2 === 'F' ? 'Бывшая жена' : 'Бывший супруг(а)');
        if ($isWidowed) return $g2 === 'M' ? 'Покойный муж' : ($g2 === 'F' ? 'Покойная жена' : 'Покойный супруг(а)');
        return $g2 === 'M' ? 'Муж' : ($g2 === 'F' ? 'Жена' : 'Супруг(а)');
    }

    // 4. Братья и сёстры (up=1, down=1, spouse=0)
    if ($up === 1 && $down === 1 && $spouse === 0) {
        $prefix = '';
        if ($isAdopted) $prefix = 'Названый ';
        elseif ($isStep) $prefix = 'Сводный ';
        elseif ($shared === 'father') $prefix = 'Единокровный ';
        elseif ($shared === 'mother') $prefix = 'Единоутробный ';
        return $prefix . ($g2 === 'M' ? 'брат' : ($g2 === 'F' ? 'сестра' : 'брат/сестра'));
    }

    // 5. Дядя/Тётя и Племянник/Племянница
    if ($up === 2 && $down === 1 && $spouse === 0) return $g2 === 'M' ? 'Дядя' : ($g2 === 'F' ? 'Тётя' : 'Дядя/Тётя');
    if ($up === 1 && $down === 2 && $spouse === 0) return $g2 === 'M' ? 'Племянник' : ($g2 === 'F' ? 'Племянница' : 'Племянник/Племянница');
    
    // 6. Двоюродные
    if ($up === 2 && $down === 2 && $spouse === 0) return $g2 === 'M' ? 'Двоюродный брат' : ($g2 === 'F' ? 'Двоюродная сестра' : 'Двоюродный брат/сестра');
    if ($up === 3 && $down === 2 && $spouse === 0) return $g2 === 'M' ? 'Двоюродный дядя' : ($g2 === 'F' ? 'Двоюродная тётя' : 'Двоюродный дядя/тётя');
    if ($up === 3 && $down === 3 && $spouse === 0) return $g2 === 'M' ? 'Троюродный брат' : ($g2 === 'F' ? 'Троюродная сестра' : 'Троюродный брат/сестра');

    // 7. Свойство (по браку)
    if ($up === 1 && $down === 0 && $spouse === 1) {
        $stmt->execute([$path[1]]); $spouseG = $stmt->fetchColumn();
        if ($spouseG === 'M') return $g2 === 'M' ? 'Свёкор' : ($g2 === 'F' ? 'Свекровь' : 'Свёкор/Свекровь');
        return $g2 === 'M' ? 'Тесть' : ($g2 === 'F' ? 'Тёща' : 'Тесть/Тёща');
    }
    if ($up === 0 && $down === 1 && $spouse === 1) return $g2 === 'M' ? 'Зять' : ($g2 === 'F' ? 'Невестка' : 'Зять/Невестка');
    
    if ($up === 1 && $down === 1 && $spouse === 1) {
        $stmt->execute([$path[1]]); $spouseG = $stmt->fetchColumn();
        if ($spouseG === 'M') return $g2 === 'M' ? 'Деверь' : ($g2 === 'F' ? 'Золовка' : 'Деверь/Золовка');
        return $g2 === 'M' ? 'Шурин' : ($g2 === 'F' ? 'Свояченица' : 'Шурин/Свояченица');
    }
    if ($up === 1 && $down === 1 && $spouse === 2) return $g2 === 'M' ? 'Сват' : ($g2 === 'F' ? 'Сватья' : 'Сват/Сватья');

    return "Родственник (связь через " . ($up + $down + $spouse) . " шагов)";
}