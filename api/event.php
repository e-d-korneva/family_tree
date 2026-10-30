<?php
require_once 'includes/functions.php';

$events = $pdo->query("SELECT e.*, COUNT(ep.id) as participants_count 
                       FROM events e 
                       LEFT JOIN event_participants ep ON e.id = ep.event_id 
                       GROUP BY e.id 
                       ORDER BY e.event_date DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>События — Семейное древо</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="index.php">🌳 Семейное древо</a>
        <div class="d-flex gap-2">
            <a href="tree.php" class="btn btn-outline-light btn-sm">Дерево</a>
            <a href="index.php" class="btn btn-outline-light btn-sm">Люди</a>
            <a href="events.php" class="btn btn-light btn-sm">События</a>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>События</h2>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addEventModal">+ Добавить событие</button>
    </div>

    <div class="row">
        <?php foreach ($events as $event): ?>
        <div class="col-md-6 mb-3">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title"><?= htmlspecialchars($event['event_type']) ?></h5>
                    <?php if ($event['event_date']): ?>
                        <p class="text-muted">📅 <?= htmlspecialchars($event['event_date']) ?></p>
                    <?php endif; ?>
                    <?php if ($event['event_place']): ?>
                        <p>📍 <?= htmlspecialchars($event['event_place']) ?></p>
                    <?php endif; ?>
                    <p>👥 Участников: <?= $event['participants_count'] ?></p>
                    <?php if ($event['description']): ?>
                        <p><?= nl2br(htmlspecialchars($event['description'])) ?></p>
                    <?php endif; ?>
                    <a href="#" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#eventDetailsModal" onclick="loadEventDetails(<?= $event['id'] ?>)">Подробнее</a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Модалка добавления события -->
<div class="modal fade" id="addEventModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" id="addEventForm" action="api/event.php" method="POST">
            <div class="modal-header">
                <h5>Добавить событие</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="create">
                <div class="mb-3">
                    <label class="form-label">Тип события *</label>
                    <input type="text" name="event_type" class="form-control" required placeholder="Например: Свадьба, Рождение">
                </div>
                <div class="mb-3">
                    <label class="form-label">Дата</label>
                    <input type="text" name="event_date" class="form-control" placeholder="1985 или 12.05.1985">
                </div>
                <div class="mb-3">
                    <label class="form-label">Место</label>
                    <input type="text" name="event_place" class="form-control" placeholder="Москва">
                </div>
                <div class="mb-3">
                    <label class="form-label">Описание</label>
                    <textarea name="description" class="form-control" rows="3"></textarea>
                </div>
                <hr>
                <h6>Участники</h6>
                <div id="eventParticipants"></div>
                <input type="hidden" name="participants" id="participantsJson" value="[]">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Здесь JavaScript для работы с событиями
</script>
</body>
</html>