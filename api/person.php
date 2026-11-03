<?php
require_once '../includes/functions.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$ajax = isset($_POST['ajax']) || isset($_GET['ajax']);

if ($action === 'create') {
    try {
        $b_date = processFlexibleDate($_POST['birth_date'] ?? '', $_POST['birth_date_accuracy'] ?? 'exact');
        $d_date = processFlexibleDate($_POST['death_date'] ?? '', $_POST['death_date_accuracy'] ?? 'exact');
        
        $stmt = $pdo->prepare("INSERT INTO persons (first_name, middle_name, last_name, maiden_name, gender, birth_date, death_date, birth_place, bio, is_deceased) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            trim($_POST['first_name']),
            trim($_POST['middle_name'] ?? ''),
            trim($_POST['last_name']),
            trim($_POST['maiden_name'] ?? ''),
            $_POST['gender'] ?? 'U',
            $b_date ?: null,
            $d_date ?: null,
            trim($_POST['birth_place'] ?? ''),
            trim($_POST['bio'] ?? ''),
            isset($_POST['is_deceased']) ? 1 : 0
        ]);
        $newId = (int)$pdo->lastInsertId();

        if (isset($_FILES['new_photo']) && $_FILES['new_photo']['error'] === UPLOAD_ERR_OK) {
            $path = uploadPersonPhoto($_FILES['new_photo'], $newId);
            if ($path) $pdo->prepare("UPDATE persons SET photo = ? WHERE id = ?")->execute([$path, $newId]);
        }

        $parents = isset($_POST['parents']) ? (array)$_POST['parents'] : [];
        foreach ($parents as $pId) createRelations($pdo, (int)$pId, [$newId], 'parent');
        createRelations($pdo, $newId, isset($_POST['spouses']) ? (array)$_POST['spouses'] : [], 'spouse');
        createRelations($pdo, $newId, isset($_POST['children']) ? (array)$_POST['children'] : [], 'parent');

        $ajax ? jsonResponse(['success' => true, 'id' => $newId]) : header("Location: ../person.php?id=$newId");
    } catch (PDOException $e) { 
        $ajax ? jsonResponse(['success' => false, 'error' => $e->getMessage()], 500) : die('Ошибка: ' . $e->getMessage()); 
    }
}

if ($action === 'update') {
    $id = (int)$_POST['id'];
    try {
        $b_date = processFlexibleDate($_POST['birth_date'] ?? '', $_POST['birth_date_accuracy'] ?? 'exact');
        $d_date = processFlexibleDate($_POST['death_date'] ?? '', $_POST['death_date_accuracy'] ?? 'exact');
        
        $stmt = $pdo->prepare("UPDATE persons SET first_name=?, middle_name=?, last_name=?, maiden_name=?, gender=?, birth_date=?, death_date=?, birth_place=?, bio=?, is_deceased=? WHERE id=?");
        $stmt->execute([
            trim($_POST['first_name']),
            trim($_POST['middle_name'] ?? ''),
            trim($_POST['last_name']),
            trim($_POST['maiden_name'] ?? ''),
            $_POST['gender'] ?? 'U',
            $b_date ?: null,
            $d_date ?: null,
            trim($_POST['birth_place'] ?? ''),
            trim($_POST['bio'] ?? ''),
            isset($_POST['is_deceased']) ? 1 : 0,
            $id
        ]);
        
        refreshRelations($pdo, $id, isset($_POST['parents']) ? (array)$_POST['parents'] : [], 'parent');
        refreshRelations($pdo, $id, isset($_POST['spouses']) ? (array)$_POST['spouses'] : [], 'spouse');
        refreshRelations($pdo, $id, isset($_POST['children']) ? (array)$_POST['children'] : [], 'parent');
        
        $ajax ? jsonResponse(['success' => true, 'id' => $id]) : header("Location: ../person.php?id=$id");
    } catch (PDOException $e) { 
        $ajax ? jsonResponse(['success' => false, 'error' => $e->getMessage()], 500) : die('Ошибка: ' . $e->getMessage()); 
    }
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    $pdo->prepare("DELETE FROM persons WHERE id = ?")->execute([$id]);
    $ajax ? jsonResponse(['success' => true]) : header('Location: ../index.php');
}

if ($action === 'add_relationship') {
    try {
        $fromId = (int)$_POST['from_person_id'];
        $toId = (int)$_POST['to_person_id'];
        $relType = $_POST['relation_type'];
        
        // Проверяем, существует ли уже такая связь
        $check = $pdo->prepare("SELECT id FROM relationships WHERE from_person_id = ? AND to_person_id = ? AND relation_type = ?");
        $check->execute([$fromId, $toId, $relType]);
        
        if ($check->fetch()) {
            // Связь существует - ОБНОВЛЯЕМ её
            $stmt = $pdo->prepare("UPDATE relationships SET 
                marriage_date = ?, 
                divorce_date = ?, 
                is_adopted = ?, 
                is_step = ?, 
                shared_parent = ?,
                is_widowed = ?
                WHERE from_person_id = ? AND to_person_id = ? AND relation_type = ?");
            $stmt->execute([
                $_POST['marriage_date'] ?: null,
                $_POST['divorce_date'] ?: null,
                isset($_POST['is_adopted']) && $_POST['is_adopted'] == '1' ? 1 : 0,
                isset($_POST['is_step']) && $_POST['is_step'] == '1' ? 1 : 0,
                $_POST['shared_parent'] ?? 'both',
                isset($_POST['is_widowed']) && $_POST['is_widowed'] == '1' ? 1 : 0,
                $fromId, $toId, $relType
            ]);
            jsonResponse(['success' => true, 'message' => 'Связь обновлена']);
        } else {
            // Связи не существует - СОЗДАЁМ новую
            try {
                $stmt = $pdo->prepare("INSERT INTO relationships (from_person_id, to_person_id, relation_type, marriage_date, divorce_date, is_adopted, is_step, shared_parent, is_widowed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $fromId, $toId, $relType,
                    $_POST['marriage_date'] ?: null,
                    $_POST['divorce_date'] ?: null,
                    isset($_POST['is_adopted']) && $_POST['is_adopted'] == '1' ? 1 : 0,
                    isset($_POST['is_step']) && $_POST['is_step'] == '1' ? 1 : 0,
                    $_POST['shared_parent'] ?? 'both',
                    isset($_POST['is_widowed']) && $_POST['is_widowed'] == '1' ? 1 : 0
                ]);
                jsonResponse(['success' => true, 'message' => 'Связь создана']);
            } catch (PDOException $e) {
                // Если всё равно произошла ошибка уникальности (гонка данных)
                if ($e->getCode() == 23000) {
                    jsonResponse(['success' => true, 'message' => 'Связь уже существует']);
                } else {
                    throw $e;
                }
            }
        }
    } catch (PDOException $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($action === 'delete_relationship') {
    $pdo->prepare("DELETE FROM relationships WHERE id = ?")->execute([(int)$_POST['rel_id']]);
    jsonResponse(['success' => true]);
}

if ($action === 'get') {
    $p = getPerson($pdo, (int)($_GET['id'] ?? 0));
    if (!$p) jsonResponse(['error' => 'Не найден'], 404);
    $p['relatives'] = getRelatives($pdo, $p['id']);
    $p['events'] = getEvents($pdo, $p['id']);
    $p['media'] = getMedia($pdo, $p['id']);
    $p['full_name'] = getFullName($p);
    $p['life_dates'] = getLifeDates($p);
    $p['status'] = getLifeStatus($p);
    jsonResponse($p);
}

if ($action === 'delete_photo') {
    $personId = (int)($_POST['person_id'] ?? 0);
    if (!$personId) {
        jsonResponse(['success' => false, 'error' => 'Не указан ID человека'], 400);
    }
    
    // Получаем текущий путь к фото
    $stmt = $pdo->prepare("SELECT photo FROM persons WHERE id = ?");
    $stmt->execute([$personId]);
    $person = $stmt->fetch();
    
    if ($person && !empty($person['photo'])) {
        $photoPath = __DIR__ . '/../' . $person['photo'];
        
        // Удаляем основной файл
        if (file_exists($photoPath)) {
            @unlink($photoPath);
        }
        
        // Пытаемся удалить миниатюру, если она существует
        $pathInfo = pathinfo($person['photo']);
        $thumbPath = __DIR__ . '/../uploads/thumbs/thumb_' . $pathInfo['basename'];
        if (file_exists($thumbPath)) {
            @unlink($thumbPath);
        }
    }
    
    // Обнуляем поле photo в базе данных
    $stmt = $pdo->prepare("UPDATE persons SET photo = NULL WHERE id = ?");
    $stmt->execute([$personId]);
    
    jsonResponse(['success' => true]);
}