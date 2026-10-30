<?php
require_once '../includes/functions.php';
$persons = $pdo->query("SELECT id, first_name, middle_name, last_name, maiden_name, gender, birth_date, death_date, is_deceased, photo FROM persons")->fetchAll();
$rels = $pdo->query("SELECT * FROM relationships")->fetchAll();

$gens = []; foreach ($persons as $p) $gens[$p['id']] = 0;
$hasParent = []; foreach ($rels as $r) if ($r['relation_type'] === 'parent') $hasParent[$r['to_person_id']] = true;
$roots = []; foreach ($persons as $p) if (!isset($hasParent[$p['id']])) $roots[] = $p['id'];
if (empty($roots)) $roots = [$persons[0]['id']];

$queue = $roots; $visited = [];
foreach ($roots as $r) { $gens[$r] = 0; $visited[$r] = true; }
while (!empty($queue)) {
    $cur = array_shift($queue);
    foreach ($rels as $r) {
        $next = null; $nextGen = $gens[$cur];
        if ($r['from_person_id'] == $cur && $r['relation_type'] === 'parent') { $next = $r['to_person_id']; $nextGen++; }
        elseif ($r['to_person_id'] == $cur && $r['relation_type'] === 'parent') { $next = $r['from_person_id']; $nextGen--; }
        elseif ($r['from_person_id'] == $cur && $r['relation_type'] === 'child') { $next = $r['to_person_id']; $nextGen++; }
        if ($next !== null && !isset($visited[$next])) { $visited[$next] = true; $gens[$next] = $nextGen; $queue[] = $next; }
    }
}
foreach ($rels as $r) {
    if ($r['relation_type'] === 'spouse') {
        $min = min($gens[$r['from_person_id']] ?? 0, $gens[$r['to_person_id']] ?? 0);
        $gens[$r['from_person_id']] = $min; $gens[$r['to_person_id']] = $min;
    }
}

$nodes = [];
foreach ($persons as $p) {
    $fullName = getFullName($p);
    $maiden = !empty($p['maiden_name']) ? "\n(ур. " . $p['maiden_name'] . ")" : "";
    $dates = "\n" . getLifeDates($p);
    
    $nodes[] = [
        'data' => [
            'id' => 'p' . $p['id'], 
            'label' => $fullName . $maiden . $dates, // Многострочная метка для дерева
            'full_name' => $fullName,
            'life_dates' => getLifeDates($p),
            'maiden_name' => $p['maiden_name'],
            'gender' => $p['gender'],  
            'status' => getLifeStatus($p), 
            'photo' => $p['photo'], 
            'generation' => $gens[$p['id']] ?? 0
        ]
    ];
}
$edges = [];
foreach ($rels as $r) {
    $edges[] = ['data' => ['id' => 'e' . $r['id'], 'source' => 'p' . $r['from_person_id'], 'target' => 'p' . $r['to_person_id'], 'type' => $r['relation_type']]];
}
jsonResponse(['nodes' => $nodes, 'edges' => $edges]);