<?php
require_once __DIR__ . '/../includes/functions.php';

// Очищаем любой предыдущий вывод
ob_clean();

// Подавляем вывод warning/notice
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

// Устанавливаем заголовок JSON
header('Content-Type: application/json; charset=utf-8');

$action = $_REQUEST['action'] ?? '';

if ($action === 'create') {
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("INSERT INTO events (event_type, event_date, event_place, description) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            trim($_POST['event_type'] ?? ''),
            $_POST['event_date'] ?: null,
            trim($_POST['event_place'] ?? ''),
            trim($_POST['description'] ?? '')
        ]);
        $eventId = (int)$pdo->lastInsertId();
        
        // Обработка участников
        $participants = json_decode($_POST['participants'] ?? '[]', true);
        if (is_array($participants)) {
            $stmtP = $pdo->prepare("INSERT INTO event_participants (event_id, person_id, role) VALUES (?, ?, ?)");
            foreach ($participants as $p) {
                if (!empty($p['id'])) {
                    $stmtP->execute([$eventId, (int)$p['id'], trim($p['role'] ?? '')]);
                }
            }
        }
        
        $pdo->commit();
        echo json_encode(['success' => true, 'id' => $eventId], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($action === 'get') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$id]);
    $event = $stmt->fetch();
    if (!$event) {
        http_response_code(404);
        echo json_encode(['error' => 'Не найдено'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT ep.*, p.first_name, p.last_name, p.middle_name 
                           FROM event_participants ep 
                           JOIN persons p ON p.id = ep.person_id 
                           WHERE ep.event_id = ?");
    $stmt->execute([$id]);
    $event['participants'] = $stmt->fetchAll();
    echo json_encode($event, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM event_participants WHERE event_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM events WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// список событий для селекта в загрузке медиа
if ($action === 'list') {
    try {
        $stmt = $pdo->query("SELECT id, event_type, event_date FROM events ORDER BY event_date DESC");
        $events = $stmt->fetchAll();
        echo json_encode($events, JSON_UNESCAPED_UNICODE);
        exit;
    } catch (PDOException $e) {
        // Если таблица events не существует — возвращаем пустой массив
        echo json_encode([], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Не указан ID события'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. Обновляем основные данные события
        $stmt = $pdo->prepare("UPDATE events SET event_type = ?, event_date = ?, event_place = ?, description = ? WHERE id = ?");
        $stmt->execute([
            trim($_POST['event_type'] ?? ''),
            $_POST['event_date'] ?: null,
            trim($_POST['event_place'] ?? ''),
            trim($_POST['description'] ?? ''),
            $id
        ]);

        // 2. Полностью перезаписываем участников (удаляем старых, добавляем новых)
        $pdo->prepare("DELETE FROM event_participants WHERE event_id = ?")->execute([$id]);
        
        $participants = json_decode($_POST['participants'] ?? '[]', true);
        if (is_array($participants)) {
            $stmtP = $pdo->prepare("INSERT INTO event_participants (event_id, person_id, role) VALUES (?, ?, ?)");
            foreach ($participants as $p) {
                if (!empty($p['id'])) {
                    $stmtP->execute([$id, (int)$p['id'], trim($p['role'] ?? '')]);
                }
            }
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие: ' . $action], JSON_UNESCAPED_UNICODE);
exit;

