<?php
/**
 * Поиск кратчайшего пути между двумя людьми (BFS)
 */
function findPath(PDO $pdo, int $p1, int $p2): ?array {
    if ($p1 === $p2) return ['path' => [$p1], 'edges' => [], 'types' => []];

    // Загружаем все связи
    $allRels = $pdo->query("SELECT * FROM relationships")->fetchAll();
    $adj = [];
    
    foreach ($allRels as $r) {
        // Прямое направление
        $adj[$r['from_person_id']][] = [
            'to' => $r['to_person_id'],
            'type' => $r['relation_type'],
            'id' => $r['id'],
            'is_adopted' => $r['is_adopted'],
            'is_step' => $r['is_step'],
            'shared_parent' => $r['shared_parent'],
        ];
        
        // Обратное направление
        $reverse = $r['relation_type'] === 'parent' ? 'child' : 
                   ($r['relation_type'] === 'child' ? 'parent' : 'spouse');
        $adj[$r['to_person_id']][] = [
            'to' => $r['from_person_id'],
            'type' => $reverse,
            'id' => $r['id'],
            'is_adopted' => $r['is_adopted'],
            'is_step' => $r['is_step'],
            'shared_parent' => $r['shared_parent'],
        ];
    }

    // BFS
    $queue = [[$p1, [], [], []]]; // [текущий, путь узлов, путь рёбер, типы связей]
    $visited = [$p1 => true];

    while (!empty($queue)) {
        [$cur, $path, $edges, $types] = array_shift($queue);
        
        if ($cur == $p2) {
            return [
                'path' => array_merge($path, [$p2]),
                'edges' => $edges,
                'types' => array_merge($types, [$cur]),
            ];
        }

        foreach ($adj[$cur] ?? [] as $link) {
            if (!isset($visited[$link['to']])) {
                $visited[$link['to']] = true;
                $queue[] = [
                    $link['to'],
                    array_merge($path, [$cur]),
                    array_merge($edges, [$link['id']]),
                    array_merge($types, [$link['type'] . ':' . ($link['is_adopted'] ? 'A' : '') . ':' . ($link['is_step'] ? 'S' : '')]),
                ];
            }
        }
    }
    
    return null;
}

/**
 * Определение пола человека
 */
function getPersonGender(PDO $pdo, int $personId): string {
    $stmt = $pdo->prepare("SELECT gender FROM persons WHERE id = ?");
    $stmt->execute([$personId]);
    $result = $stmt->fetch();
    return $result['gender'] ?? 'U';
}

/**
 * Определение термина родства по цепочке связей
 */
function determineKinship(PDO $pdo, array $path, array $types): string {
    if (count($path) < 2) return 'это один и тот же человек';
    
    $p1 = $path[0];
    $p2 = $path[count($path) - 1];
    $gender1 = getPersonGender($pdo, $p1);
    $gender2 = getPersonGender($pdo, $p2);
    
    // Анализируем цепочку
    $chain = [];
    for ($i = 0; $i < count($types); $i++) {
        $parts = explode(':', $types[$i]);
        $chain[] = [
            'type' => $parts[0],
            'adopted' => isset($parts[1]) && $parts[1] === 'A',
            'step' => isset($parts[2]) && $parts[2] === 'S',
        ];
    }
    
    // Определяем направление (от p1 к p2 или наоборот)
    // Ищем "якорь" - точку, откуда начинаем отсчёт
    $direction = 'forward'; // от p1 к p2
    
    // Считаем поколения вверх и вниз
    $upCount = 0;
    $downCount = 0;
    $spouseCount = 0;
    
    foreach ($chain as $link) {
        if ($link['type'] === 'parent') $upCount++;
        elseif ($link['type'] === 'child') $downCount++;
        elseif ($link['type'] === 'spouse') $spouseCount++;
    }
    
    // Прямые связи
    if ($upCount > 0 && $downCount === 0 && $spouseCount === 0) {
        // Только вверх - предки
        if ($upCount === 1) {
            return $gender2 === 'M' ? 'Отец' : ($gender2 === 'F' ? 'Мать' : 'Родитель');
        } elseif ($upCount === 2) {
            return $gender2 === 'M' ? 'Дед (дедушка)' : ($gender2 === 'F' ? 'Бабушка (бабка)' : 'Дед/бабушка');
        } elseif ($upCount === 3) {
            return $gender2 === 'M' ? 'Прадед' : ($gender2 === 'F' ? 'Прабабушка' : 'Прадед/прабабушка');
        } else {
            return $gender2 === 'M' ? "Прапра...дед ($upCount поколений)" : ($gender2 === 'F' ? "Прапра...бабушка ($upCount поколений)" : "Предок ($upCount поколений)");
        }
    }
    
    if ($downCount > 0 && $upCount === 0 && $spouseCount === 0) {
        // Только вниз - потомки
        if ($downCount === 1) {
            return $gender2 === 'M' ? 'Сын' : ($gender2 === 'F' ? 'Дочь' : 'Ребёнок');
        } elseif ($downCount === 2) {
            return $gender2 === 'M' ? 'Внук' : ($gender2 === 'F' ? 'Внучка' : 'Внук/внучка');
        } elseif ($downCount === 3) {
            return $gender2 === 'M' ? 'Правнук' : ($gender2 === 'F' ? 'Правнучка' : 'Правнук/правнучка');
        } else {
            return $gender2 === 'M' ? "Потомок ($downCount поколений)" : ($gender2 === 'F' ? "Потомок ($downCount поколений)" : "Потомок ($downCount поколений)");
        }
    }
    
    // Супруги
    if ($upCount === 0 && $downCount === 0 && $spouseCount > 0) {
        return $gender2 === 'M' ? 'Муж' : ($gender2 === 'F' ? 'Жена' : 'Супруг(а)');
    }
    
    // Боковые линии (братья/сёстры) - общий родитель
    if ($upCount === 1 && $downCount === 1 && $spouseCount === 0) {
        // Проверяем, полнородные ли
        $isAdopted = false;
        $isStep = false;
        $sharedParent = 'both';
        
        foreach ($chain as $link) {
            if ($link['adopted']) $isAdopted = true;
            if ($link['step']) $isStep = true;
        }
        
        if ($isStep) {
            return 'Сводный ' . ($gender2 === 'M' ? 'брат' : ($gender2 === 'F' ? 'сестра' : 'брат/сестра'));
        }
        if ($isAdopted) {
            return 'Названый ' . ($gender2 === 'M' ? 'брат' : ($gender2 === 'F' ? 'сестра' : 'брат/сестра'));
        }
        
        return $gender2 === 'M' ? 'Брат' : ($gender2 === 'F' ? 'Сестра' : 'Брат/сестра');
    }
    
    // Дядя/тётя (родитель + брат/сестра)
    if ($upCount === 2 && $downCount === 1 && $spouseCount === 0) {
        // p1 -> родитель -> брат/сестра -> p2
        return $gender2 === 'M' ? 'Дядя' : ($gender2 === 'F' ? 'Тётя (тётка)' : 'Дядя/тётя');
    }
    
    // Племянник/племянница
    if ($upCount === 1 && $downCount === 2 && $spouseCount === 0) {
        return $gender2 === 'M' ? 'Племянник' : ($gender2 === 'F' ? 'Племянница' : 'Племянник/племянница');
    }
    
    // Двоюродные братья/сёстры
    if ($upCount === 2 && $downCount === 2 && $spouseCount === 0) {
        return $gender2 === 'M' ? 'Двоюродный брат' : ($gender2 === 'F' ? 'Двоюродная сестра' : 'Двоюродный брат/сестра');
    }
    
    // Троюродные
    if ($upCount === 3 && $downCount === 3 && $spouseCount === 0) {
        return $gender2 === 'M' ? 'Троюродный брат' : ($gender2 === 'F' ? 'Троюродная сестра' : 'Троюродный брат/сестра');
    }
    
    // Двоюродный дядя/тётя
    if ($upCount === 3 && $downCount === 2 && $spouseCount === 0) {
        return $gender2 === 'M' ? 'Двоюродный дядя' : ($gender2 === 'F' ? 'Двоюродная тётя' : 'Двоюродный дядя/тётя');
    }
    
    // Двоюродный дед/бабушка
    if ($upCount === 3 && $downCount === 1 && $spouseCount === 0) {
        return $gender2 === 'M' ? 'Двоюродный дед' : ($gender2 === 'F' ? 'Двоюродная бабушка' : 'Двоюродный дед/бабушка');
    }
    
    // Связи по браку (свойство)
    // Свёкор/свекровь (отец/мать мужа)
    if ($upCount === 1 && $downCount === 0 && $spouseCount === 1) {
        // p1 -> супруг -> родитель
        $spouseGender = getPersonGender($pdo, $path[1]);
        if ($spouseGender === 'M') {
            // Муж -> его отец/мать
            return $gender2 === 'M' ? 'Свёкор' : ($gender2 === 'F' ? 'Свекровь' : 'Свёкор/свекровь');
        } else {
            // Жена -> её отец/мать
            return $gender2 === 'M' ? 'Тесть' : ($gender2 === 'F' ? 'Тёща' : 'Тесть/тёща');
        }
    }
    
    // Зять/невестка
    if ($upCount === 0 && $downCount === 1 && $spouseCount === 1) {
        // p1 -> ребёнок -> супруг
        return $gender2 === 'M' ? 'Зять' : ($gender2 === 'F' ? 'Невестка (сноха)' : 'Зять/невестка');
    }
    
    // Деверь/золовка (брат/сестра мужа)
    if ($upCount === 1 && $downCount === 1 && $spouseCount === 1) {
        $spouseGender = getPersonGender($pdo, $path[1]);
        if ($spouseGender === 'M') {
            // Муж -> его брат/сестра
            return $gender2 === 'M' ? 'Деверь' : ($gender2 === 'F' ? 'Золовка' : 'Деверь/золовка');
        } else {
            // Жена -> её брат/сестра
            return $gender2 === 'M' ? 'Шурин' : ($gender2 === 'F' ? 'Свояченица' : 'Шурин/свояченица');
        }
    }
    
    // Отчим/мачеха
    if ($upCount === 1 && $downCount === 0 && $spouseCount === 1) {
        // Проверяем is_step
        foreach ($chain as $link) {
            if ($link['step']) {
                return $gender2 === 'M' ? 'Отчим' : ($gender2 === 'F' ? 'Мачеха' : 'Отчим/мачеха');
            }
        }
    }
    
    // Пасынок/падчерица
    if ($upCount === 0 && $downCount === 1 && $spouseCount === 1) {
        foreach ($chain as $link) {
            if ($link['step']) {
                return $gender2 === 'M' ? 'Пасынок' : ($gender2 === 'F' ? 'Падчерица' : 'Пасынок/падчерица');
            }
        }
    }
    
    // Сват/сватья (родители супругов между собой)
    if ($upCount === 1 && $downCount === 1 && $spouseCount === 2) {
        return $gender2 === 'M' ? 'Сват' : ($gender2 === 'F' ? 'Сватья' : 'Сват/сватья');
    }
    
    // Сношенница (жёны братьев)
    if ($upCount === 1 && $downCount === 1 && $spouseCount === 1) {
        // Проверяем, обе ли женщины
        if ($gender1 === 'F' && $gender2 === 'F') {
            return 'Сношенница';
        }
    }
    
    // Свояки (мужья сестёр)
    if ($upCount === 1 && $downCount === 1 && $spouseCount === 1) {
        if ($gender1 === 'M' && $gender2 === 'M') {
            return 'Свояк';
        }
    }
    
    // Крёстные связи (проверяем через отдельную таблицу)
    $stmt = $pdo->prepare("
        SELECT 'godparent' as type FROM godparent_relations WHERE godparent_id = ? AND godchild_id = ?
        UNION
        SELECT 'godchild' as type FROM godparent_relations WHERE godchild_id = ? AND godparent_id = ?
    ");
    $stmt->execute([$p1, $p2, $p1, $p2]);
    $godRel = $stmt->fetch();
    
    if ($godRel) {
        if ($godRel['type'] === 'godparent') {
            return $gender1 === 'M' ? 'Крёстный отец (кум)' : 'Крёстная мать (кума)';
        } else {
            return $gender2 === 'M' ? 'Крёстник' : 'Крёстница';
        }
    }
    
    // Названые связи
    $stmt = $pdo->prepare("
        SELECT relation_type FROM adopted_relations WHERE adopter_id = ? AND adopted_id = ?
        UNION
        SELECT relation_type FROM adopted_relations WHERE adopted_id = ? AND adopter_id = ?
    ");
    $stmt->execute([$p1, $p2, $p1, $p2]);
    $adoptRel = $stmt->fetch();
    
    if ($adoptRel) {
        $type = $adoptRel['relation_type'];
        if ($type === 'father') return 'Названый отец';
        if ($type === 'mother') return 'Названая мать';
        if ($type === 'son') return 'Названый сын';
        if ($type === 'daughter') return 'Названая дочь';
    }
    
    // Если не удалось определить точно
    $totalSteps = $upCount + $downCount + $spouseCount;
    return "Родственник (связь через $totalSteps шагов)";
}