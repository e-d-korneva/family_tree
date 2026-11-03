<?php
require_once __DIR__ . '/../includes/functions.php';

$stmt = $pdo->query("
    SELECT 
        m.id,
        m.person_id,
        m.event_id,
        m.file_path,
        m.thumb_path,
        m.file_type,
        m.caption,
        m.created_at,
        CONCAT(p.first_name, ' ', p.last_name) as person_name,
        e.event_type
    FROM media m
    LEFT JOIN persons p ON p.id = m.person_id
    LEFT JOIN events e ON e.id = m.event_id
    ORDER BY m.created_at DESC
");

jsonResponse($stmt->fetchAll());