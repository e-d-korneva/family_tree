<?php
require_once __DIR__ . '/db.php';

function getFullName(array $p): string {
    return trim(($p['first_name'] ?? '') . ' ' . ($p['middle_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
}

function formatDateFlexible(?string $date): string {
    if (!$date) return '?';
    $date = trim($date);
    $cleanDate = $date; $prefix = '';
    if (strpos($date, 'около ') === 0) { $prefix = 'около '; $cleanDate = substr($date, 6); }
    elseif (strpos($date, 'до ') === 0) { $prefix = 'до '; $cleanDate = substr($date, 3); }
    elseif (strpos($date, 'после ') === 0) { $prefix = 'после '; $cleanDate = substr($date, 7); }
    
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $cleanDate)) return $prefix . date('d.m.Y', strtotime($cleanDate));
    if (preg_match('/^\d{4}$/', $cleanDate)) return $prefix . $cleanDate . ' г.';
    if (preg_match('/^\d{4}-\d{2}$/', $cleanDate)) {
        $parts = explode('-', $cleanDate);
        $months = ['', 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
        return $prefix . $months[(int)$parts[1]] . ' ' . $parts[0] . ' г.';
    }
    return $date;
}

function getLifeDates(array $p): string {
    $birth = $p['birth_date'] ? formatDateFlexible($p['birth_date']) : '?';
    $death = $p['death_date'] ? formatDateFlexible($p['death_date']) : '';
    if ($death === '' && !empty($p['is_deceased'])) $death = 'умер';
    return $death ? "$birth — $death" : $birth . " — наст. время";
}

function getLifeStatus(array $p): string {
    if (!empty($p['is_deceased']) || !empty($p['death_date'])) return 'deceased';
    if (!empty($p['birth_date'])) {
        $clean = $p['birth_date'];
        foreach (['около ', 'до ', 'после '] as $pref) if (strpos($clean, $pref) === 0) $clean = substr($clean, strlen($pref));
        if (preg_match('/^\d{4}/', $clean, $m) && ((int)date('Y') - (int)$m[0] > 120)) return 'deceased';
    }
    return empty($p['birth_date']) ? 'unknown' : 'alive';
}

function getRelatives(PDO $pdo, int $personId): array {
    $res = ['parents' => [], 'spouses' => [], 'children' => [], 'siblings' => []];
    
    // Родители
    $stmt = $pdo->prepare("SELECT p.*, r.id as rel_id, r.relation_type, r.shared_parent, r.is_adopted, r.is_step, r.marriage_date, r.divorce_date, r.is_widowed 
                           FROM persons p JOIN relationships r ON r.from_person_id = p.id 
                           WHERE r.to_person_id = ? AND r.relation_type = 'parent'");
    $stmt->execute([$personId]); 
    $res['parents'] = $stmt->fetchAll();

    // Супруги
    $stmt = $pdo->prepare("SELECT p.*, r.id as rel_id, r.relation_type, r.marriage_date, r.divorce_date, r.is_step, r.is_widowed 
                           FROM persons p JOIN relationships r ON ((r.from_person_id = p.id AND r.to_person_id = ?) OR (r.to_person_id = p.id AND r.from_person_id = ?)) 
                           WHERE r.relation_type = 'spouse'");
    $stmt->execute([$personId, $personId]); 
    $res['spouses'] = $stmt->fetchAll();

    // Дети
    $stmt = $pdo->prepare("SELECT p.*, r.id as rel_id, r.relation_type, r.shared_parent, r.is_adopted, r.is_step, r.marriage_date, r.divorce_date, r.is_widowed 
                           FROM persons p JOIN relationships r ON r.to_person_id = p.id 
                           WHERE r.from_person_id = ? AND r.relation_type = 'parent'");
    $stmt->execute([$personId]); 
    $res['children'] = $stmt->fetchAll();

    // Братья и сёстры
    $stmt = $pdo->prepare("SELECT DISTINCT p.*, r1.id as rel_id, r1.relation_type, r1.shared_parent, r1.is_adopted, r1.is_step 
                           FROM persons p 
                           JOIN relationships r1 ON r1.to_person_id = p.id AND r1.relation_type = 'parent' 
                           JOIN relationships r2 ON r2.from_person_id = r1.from_person_id AND r2.to_person_id = ? AND r2.relation_type = 'parent' 
                           WHERE p.id != ?");
    $stmt->execute([$personId, $personId]); 
    $res['siblings'] = $stmt->fetchAll();
    
    return $res;
}

function getEvents(PDO $pdo, int $personId): array {
    // GROUP BY e.id предотвращает дублирование событий, если у человека несколько ролей
    $stmt = $pdo->prepare("SELECT e.*, ep.role FROM events e 
                           JOIN event_participants ep ON e.id = ep.event_id 
                           WHERE ep.person_id = ? 
                           GROUP BY e.id 
                           ORDER BY e.event_date DESC");
    $stmt->execute([$personId]); 
    return $stmt->fetchAll();
}

function getMedia(PDO $pdo, int $personId): array {
    $stmt = $pdo->prepare("SELECT * FROM media WHERE person_id = ? ORDER BY created_at DESC");
    $stmt->execute([$personId]); 
    return $stmt->fetchAll();
}

function getPerson(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM persons WHERE id = ?");
    $stmt->execute([$id]); 
    return $stmt->fetch() ?: null;
}

function jsonResponse(array|object $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== АЛГОРИТМ РОДСТВА ======
function findPath(PDO $pdo, int $p1, int $p2): ?array {
    if ($p1 === $p2) return ['path' => [$p1], 'edges' => [], 'types' => []];
    $rels = $pdo->query("SELECT * FROM relationships")->fetchAll();
    $adj = [];
    foreach ($rels as $r) {
        // Прямое направление
        $adj[$r['from_person_id']][] = [
            'to' => $r['to_person_id'], 'type' => $r['relation_type'], 'id' => $r['id'], 
            'shared' => $r['shared_parent'], 'step' => $r['is_step'], 'adopted' => $r['is_adopted'],
            'divorced' => $r['divorce_date'], 'widowed' => $r['is_widowed']
        ];
        // Обратное направление
        $rev = $r['relation_type'] === 'parent' ? 'child' : ($r['relation_type'] === 'child' ? 'parent' : 'spouse');
        $adj[$r['to_person_id']][] = [
            'to' => $r['from_person_id'], 'type' => $rev, 'id' => $r['id'], 
            'shared' => $r['shared_parent'], 'step' => $r['is_step'], 'adopted' => $r['is_adopted'],
            'divorced' => $r['divorce_date'], 'widowed' => $r['is_widowed']
        ];
    }
    $queue = [[$p1, [], [], []]]; 
    $visited = [$p1 => true];
    while (!empty($queue)) {
        [$cur, $path, $edges, $types] = array_shift($queue);
        if ($cur == $p2) return ['path' => array_merge($path, [$p2]), 'edges' => $edges, 'types' => array_merge($types, [$cur])];
        foreach ($adj[$cur] ?? [] as $link) {
            if (!isset($visited[$link['to']])) {
                $visited[$link['to']] = true;
                // Формируем строку: type|shared|step|adopted|divorced|widowed
                $typeStr = $link['type'] . '|' . $link['shared'] . '|' . $link['step'] . '|' . $link['adopted'] . '|' . ($link['divorced'] ? '1' : '0') . '|' . $link['widowed'];
                $queue[] = [$link['to'], array_merge($path, [$cur]), array_merge($edges, [$link['id']]), array_merge($types, [$typeStr])];
            }
        }
    }
    return null;
}

function determineKinship(PDO $pdo, array $path, array $types): string {
    if (count($path) < 2) return 'это один и тот же человек';
    $p1 = $path[0]; $p2 = $path[count($path) - 1];
    $stmt = $pdo->prepare("SELECT gender FROM persons WHERE id = ?");
    $stmt->execute([$p2]); $g2 = $stmt->fetchColumn() ?: 'U';
    $stmt->execute([$p1]); $g1 = $stmt->fetchColumn() ?: 'U';

    $up = 0; $down = 0; $spouse = 0; $step = false; $adopted = false; $shared = 'both'; $divorced = false; $widowed = false;
    
    foreach ($types as $t) {
        $parts = explode('|', $t);
        if ($parts[0] === 'parent') $up++;
        elseif ($parts[0] === 'child') $down++;
        elseif ($parts[0] === 'spouse') $spouse++;
        
        if ($parts[1] !== 'both' && $parts[1] !== '') $shared = $parts[1];
        if ($parts[2] == '1') $step = true;
        if ($parts[3] == '1') $adopted = true;
        if ($parts[4] == '1') $divorced = true;
        if ($parts[5] == '1') $widowed = true;
    }

    // 1. Прямая линия вверх (Родители, Деды)
    if ($up > 0 && $down === 0 && $spouse === 0) {
        if ($step) return $g2 === 'M' ? 'Отчим' : ($g2 === 'F' ? 'Мачеха' : 'Отчим/Мачеха');
        if ($shared === 'godparent') return $g2 === 'M' ? 'Крёстный отец' : ($g2 === 'F' ? 'Крёстная мать' : 'Крёстный родитель');
        if ($up === 1) return $g2 === 'M' ? 'Отец' : ($g2 === 'F' ? 'Мать' : 'Родитель');
        if ($up === 2) return $g2 === 'M' ? 'Дед' : ($g2 === 'F' ? 'Бабушка' : 'Дед/Бабушка');
        if ($up === 3) return $g2 === 'M' ? 'Прадед' : ($g2 === 'F' ? 'Прабабушка' : 'Прадед/Прабабушка');
        return $g2 === 'M' ? "Предок ($up поколений)" : "Предок ($up поколений)";
    }
    
    // 2. Прямая линия вниз (Дети, Внуки)
    if ($down > 0 && $up === 0 && $spouse === 0) {
        if ($step) return $g2 === 'M' ? 'Пасынок' : ($g2 === 'F' ? 'Падчерица' : 'Пасынок/Падчерица');
        if ($shared === 'godchild') return $g2 === 'M' ? 'Крёстник' : ($g2 === 'F' ? 'Крёстница' : 'Крёстный ребёнок');
        if ($down === 1) return $g2 === 'M' ? 'Сын' : ($g2 === 'F' ? 'Дочь' : 'Ребёнок');
        if ($down === 2) return $g2 === 'M' ? 'Внук' : ($g2 === 'F' ? 'Внучка' : 'Внук/Внучка');
        if ($down === 3) return $g2 === 'M' ? 'Правнук' : ($g2 === 'F' ? 'Правнучка' : 'Правнук/Правнучка');
    }
    
    // 3. Супруги
    if ($up === 0 && $down === 0 && $spouse > 0) {
        if ($divorced) return $g2 === 'M' ? 'Бывший муж' : ($g2 === 'F' ? 'Бывшая жена' : 'Бывший супруг(а)');
        if ($widowed) return $g2 === 'M' ? 'Покойный муж' : ($g2 === 'F' ? 'Покойная жена' : 'Покойный супруг(а)');
        return $g2 === 'M' ? 'Муж' : ($g2 === 'F' ? 'Жена' : 'Супруг(а)');
    }

    // 4. Братья и сёстры
    if ($up === 1 && $down === 1 && $spouse === 0) {
        $prefix = '';
        if ($adopted) $prefix = 'Названый ';
        elseif ($step || $shared === 'none') $prefix = 'Сводный ';
        elseif ($shared === 'father') $prefix = 'Единокровный ';
        elseif ($shared === 'mother') $prefix = 'Единоутробный ';
        return $prefix . ($g2 === 'M' ? 'брат' : ($g2 === 'F' ? 'сестра' : 'брат/сестра'));
    }

    // 5. Дядя/Тётя, Племянник/Племянница
    if ($up === 2 && $down === 1 && $spouse === 0) return $g2 === 'M' ? 'Дядя' : ($g2 === 'F' ? 'Тётя' : 'Дядя/Тётя');
    if ($up === 1 && $down === 2 && $spouse === 0) return $g2 === 'M' ? 'Племянник' : ($g2 === 'F' ? 'Племянница' : 'Племянник/Племянница');
    
    // 6. Двоюродные и троюродные
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

function getRelationshipBadge(array $rel): string {
    $badges = [];
    if ($rel['relation_type'] === 'spouse') {
        if (!empty($rel['divorce_date'])) {
            $badges[] = '<span class="badge bg-warning text-dark ms-1">бывший</span>';
        } elseif (!empty($rel['is_widowed'])) {
            $badges[] = '<span class="badge bg-secondary ms-1">вдовец/вдова</span>';
        } else {
            $badges[] = '<span class="badge bg-success ms-1">нынешний</span>';
        }
    } 
    if (in_array($rel['relation_type'], ['parent', 'child', 'sibling'])) {
        if (!empty($rel['is_adopted'])) $badges[] = '<span class="badge bg-primary ms-1">приёмный</span>';
        if (!empty($rel['is_step'])) $badges[] = '<span class="badge bg-info text-dark ms-1">сводный</span>';
        if ($rel['shared_parent'] === 'father') $badges[] = '<span class="badge bg-info text-dark ms-1">единокровный</span>';
        elseif ($rel['shared_parent'] === 'mother') $badges[] = '<span class="badge bg-info text-dark ms-1">единоутробный</span>';
        elseif ($rel['shared_parent'] === 'none') $badges[] = '<span class="badge bg-secondary ms-1">сводный</span>';
        elseif ($rel['shared_parent'] === 'godparent') $badges[] = '<span class="badge bg-light text-dark border ms-1">крёстный</span>';
        elseif ($rel['shared_parent'] === 'godchild') $badges[] = '<span class="badge bg-light text-dark border ms-1">крёстник/крёстница</span>';
    }
    return implode(' ', $badges);
}

function processFlexibleDate(string $date, string $accuracy): string {
    $date = trim($date); if (!$date) return '';
    $prefix = match($accuracy) { 'approx' => 'около ', 'before' => 'до ', 'after' => 'после ', 'between' => 'между ', default => '' };
    return $prefix . $date;
}

function uploadPersonPhoto(array $file, int $personId): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!in_array(finfo_file($finfo, $file['tmp_name']), ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) { finfo_close($finfo); return null; }
    finfo_close($finfo);
    $dir = __DIR__ . '/../uploads/photos/'; if (!file_exists($dir)) mkdir($dir, 0755, true);
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $name = 'p' . $personId . '_' . time() . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], $dir . $name)) {
        $tDir = __DIR__ . '/../uploads/thumbs/'; if (!file_exists($tDir)) mkdir($tDir, 0755, true);
        copy($dir . $name, $tDir . $name); 
        return 'uploads/photos/' . $name;
    }
    return null;
}

function createRelations(PDO $pdo, int $fromId, array $toList, string $type): void {
    if (empty($toList)) return;
    $stmt = $pdo->prepare("INSERT IGNORE INTO relationships (from_person_id, to_person_id, relation_type) VALUES (?, ?, ?)");
    foreach ($toList as $toId) { $toId = (int)$toId; if ($toId > 0) $stmt->execute([$fromId, $toId, $type]); }
}

function refreshRelations(PDO $pdo, int $personId, array $newList, string $type): void {
    $pdo->prepare("DELETE FROM relationships WHERE from_person_id = ? AND relation_type = ?")->execute([$personId, $type]);
    createRelations($pdo, $personId, $newList, $type);
}