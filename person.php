<?php
require_once 'includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
$person = getPerson($pdo, $id);
if (!$person) { 
    die('<div class="container mt-5"><div class="alert alert-danger">Человек не найден</div></div>'); 
}

$relatives = getRelatives($pdo, $id);
$events = getEvents($pdo, $id);
$media = getMedia($pdo, $id);
$status = getLifeStatus($person);

// Все люди для выбора связей (исключая текущего)
$stmt = $pdo->prepare("SELECT id, first_name, middle_name, last_name FROM persons WHERE id != ? ORDER BY last_name, first_name");
$stmt->execute([$id]);
$allPersons = $stmt->fetchAll();

// Вспомогательная функция для разбора гибкой даты при редактировании
function parseFlexibleDate($date, &$accuracy) {
    if (!$date) return '';
    $date = trim($date);
    if (strpos($date, 'около ') === 0) { $accuracy = 'approx'; return substr($date, 6); }
    if (strpos($date, 'до ') === 0) { $accuracy = 'before'; return substr($date, 3); }
    if (strpos($date, 'после ') === 0) { $accuracy = 'after'; return substr($date, 7); }
    $accuracy = 'exact';
    return $date;
}

$birth_accuracy = 'exact';
$birth_date_clean = parseFlexibleDate($person['birth_date'], $birth_accuracy);

$death_accuracy = 'exact';
$death_date_clean = parseFlexibleDate($person['death_date'], $death_accuracy);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(getFullName($person)) ?> — Семейное древо</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="index.php">Семейное древо</a>
        <div class="d-flex gap-2">
            <a href="event.php" class="btn btn-outline-light btn-sm">События</a>
            <a href="media.php" class="btn btn-outline-light btn-sm">Медиатека</a>
            <a href="tree.php" class="btn btn-outline-light btn-sm">Дерево</a>
            <a href="index.php" class="btn btn-outline-light btn-sm"><- К Списку</a>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <!-- Карточка человека -->
    <div class="card person-card-large <?= $status ?>">
        <div class="card-body">
            <div class="row">
                <div class="col-md-3 text-center">
                    <?php if (!empty($person['photo'])): ?>
                        <img src="<?= htmlspecialchars($person['photo']) ?>" class="img-fluid rounded mb-2" style="max-height: 250px;" id="personMainPhoto">
                        <button type="button" class="btn btn-sm btn-outline-danger w-100 mb-2" onclick="deletePersonPhoto(<?= $id ?>)">Удалить фото</button>
                    <?php else: ?>
                        <div class="placeholder-photo mb-2" style="font-size: 80px;" id="personMainPhoto"> 👤 </div>
                    <?php endif; ?>
                    
                    <form action="api/media.php" method="POST" enctype="multipart/form-data" class="mt-2" id="uploadPhotoForm">
                        <input type="hidden" name="action" value="upload_photo">
                        <input type="hidden" name="person_id" value="<?= $id ?>">
                        <input type="file" name="photo" accept="image/*" class="form-control form-control-sm" required>
                        <button class="btn btn-sm btn-outline-primary mt-1 w-100">Загрузить фото</button>
                    </form>
                </div>
                <div class="col-md-9">
                    <h2><?= htmlspecialchars(getFullName($person)) ?></h2>
                    <?php if (!empty($person['maiden_name'])): ?>
                        <p class="text-muted">(в девичестве — <?= htmlspecialchars($person['maiden_name']) ?>)</p>
                    <?php endif; ?>
                    <p><strong>Даты жизни:</strong> <?= getLifeDates($person) ?></p>
                    <p><strong>Пол:</strong> <?= ['M'=>'Мужской','F'=>'Женский','U'=>'Неизвестно'][$person['gender']] ?></p>
                    <?php if (!empty($person['birth_place'])): ?>
                        <p><strong>Место рождения:</strong> <?= htmlspecialchars($person['birth_place']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($person['bio'])): ?>
                        <p><strong>Биография:</strong><br><?= nl2br(htmlspecialchars($person['bio'])) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Родственники -->
    <div class="row mt-4">
        <!-- Родители -->
        <div class="col-md-6 mb-3">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Родители</strong>
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addRelModal" onclick="prepareAddRel('parent')">+</button>
                </div>
                <div class="card-body p-0">
                    <?php if (!empty($relatives['parents'])): ?>
                        <?php foreach ($relatives['parents'] as $parent): ?>
                            <div class="d-flex justify-content-between align-items-center p-2 border-bottom">
                                <div>
                                    <a href="person.php?id=<?= $parent['id'] ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($parent['first_name'] . ' ' . $parent['last_name']) ?>
                                    </a>
                                    <?= getRelationshipBadge($parent) ?>
                                </div>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteRelationship(<?= $parent['rel_id'] ?>)" title="Удалить связь">✕</button>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted p-3 mb-0">Нет данных</p>
                    <?php endif; ?>
                </div>                
            </div>
        </div>

        <!-- Супруги -->
        <div class="col-md-6 mb-3">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Супруги</strong>
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addRelModal" onclick="prepareAddRel('spouse')">+</button>
                </div>
                <div class="card-body p-0">
                    <?php if (!empty($relatives['spouses'])): ?>
                        <?php foreach ($relatives['spouses'] as $spouse): ?>
                            <div class="d-flex justify-content-between align-items-center p-2 border-bottom">
                                <div>
                                    <a href="person.php?id=<?= $spouse['id'] ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($spouse['first_name'] . ' ' . $spouse['last_name']) ?>
                                    </a>
                                    <?php if (!empty($spouse['marriage_date'])): ?>
                                        <small class="text-muted d-block">брак: <?= htmlspecialchars($spouse['marriage_date']) ?></small>
                                    <?php endif; ?>
                                    <?= getRelationshipBadge($spouse) ?>
                                </div>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteRelationship(<?= $spouse['rel_id'] ?>)" title="Удалить связь">✕</button>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted p-3 mb-0">Нет данных</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Дети -->
        <div class="col-md-6 mb-3">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Дети</strong>
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addRelModal" onclick="prepareAddRel('child')">+</button>
                </div>
                <div class="card-body p-0">
                    <?php if (!empty($relatives['children'])): ?>
                        <?php foreach ($relatives['children'] as $child): ?>
                            <div class="d-flex justify-content-between align-items-center p-2 border-bottom">
                                <div>
                                    <a href="person.php?id=<?= $child['id'] ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($child['first_name'] . ' ' . $child['last_name']) ?>
                                    </a>
                                    <?= getRelationshipBadge($child) ?>
                                </div>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteRelationship(<?= $child['rel_id'] ?>)" title="Удалить связь">✕</button>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted p-3 mb-0">Нет данных</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Братья и сёстры -->
        <div class="col-md-6 mb-3">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Братья и сёстры</strong>
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addRelModal" onclick="prepareAddRel('sibling')">+</button>
                </div>
                <div class="card-body p-0">
                    <?php if (!empty($relatives['siblings'])): ?>
                        <?php foreach ($relatives['siblings'] as $sibling): ?>
                            <div class="d-flex justify-content-between align-items-center p-2 border-bottom">
                                <div>
                                    <a href="person.php?id=<?= $sibling['id'] ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($sibling['first_name'] . ' ' . $sibling['last_name']) ?>
                                    </a>
                                    <?= getRelationshipBadge($sibling) ?>
                                </div>
                                <!-- Удаление связи для братьев/сестёр может требовать отдельной логики, пока оставляем как есть -->
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted p-3 mb-0">Нет данных</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>


    <!-- Сюда необходимо добавить медиатеку и события (только то где упоминается этот человек) (добавлять и удалять медиа и события (только человека из события)) -->

    <!-- Медиафайлы -->
    <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Медиафайлы</strong>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#mediaUploadBlock">+ Загрузить</button>
        </div>
        <div class="card-body">
            <div class="collapse mb-3" id="mediaUploadBlock">
                <form action="api/media.php" method="POST" enctype="multipart/form-data" class="border p-3 rounded">
                    <input type="hidden" name="action" value="upload_media">
                    <input type="hidden" name="person_id" value="<?= $id ?>">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <input type="file" name="files[]" class="form-control" multiple accept="image/*,video/*,audio/*">
                        </div>
                        <div class="col-md-4">
                            <input type="text" name="caption" class="form-control" placeholder="Подпись (необязательно)">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">Загрузить</button>
                        </div>
                    </div>
                </form>
            </div>
            
            <?php if (!empty($media)): ?>
                <div class="row">
                    <?php foreach ($media as $m): ?>
                        <div class="col-md-3 mb-3">
                            <div class="card h-100">
                                <?php if ($m['file_type'] === 'photo'): ?>
                                    <img src="<?= htmlspecialchars($m['thumb_path'] ?: $m['file_path']) ?>" 
                                        class="card-img-top" style="cursor:pointer" 
                                        onclick="window.open('<?= htmlspecialchars($m['file_path']) ?>')">
                                <?php elseif ($m['file_type'] === 'video'): ?>
                                    <video src="<?= htmlspecialchars($m['file_path']) ?>" class="card-img-top" controls></video>
                                <?php elseif ($m['file_type'] === 'audio'): ?>
                                    <div class="p-3 text-center">🎵 <audio src="<?= htmlspecialchars($m['file_path']) ?>" controls class="w-100 mt-2"></audio></div>
                                <?php else: ?>
                                    <div class="p-3 text-center">📄 <?= htmlspecialchars(basename($m['file_path'])) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($m['caption'])): ?>
                                    <div class="card-body p-2"><small class="text-muted"><?= htmlspecialchars($m['caption']) ?></small></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">Медиафайлы не загружены</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- События -->
    <div class="card mt-4">
        <div class="card-header">
            <strong>События с участием</strong>
        </div>
        <div class="card-body">
            <?php if (!empty($events)): ?>
                <div class="list-group">
                    <?php foreach ($events as $ev): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <strong><?= htmlspecialchars($ev['event_type']) ?></strong>
                                <?php if (!empty($ev['event_date'])): ?>
                                    <small class="text-muted ms-2">📅 <?= htmlspecialchars($ev['event_date']) ?></small>
                                <?php endif; ?>
                                <?php if (!empty($ev['event_place'])): ?>
                                    <small class="text-muted ms-2">📍 <?= htmlspecialchars($ev['event_place']) ?></small>
                                <?php endif; ?>
                                <?php if (!empty($ev['role'])): ?>
                                    <span class="badge bg-info text-dark ms-2"><?= htmlspecialchars($ev['role']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">Этот человек не участвует ни в одном событии</p>
            <?php endif; ?>
        </div>
    </div>



    <!-- Кнопки управления -->
    <div class="mt-4 mb-5 d-flex gap-2">
        <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#editModal">✏ Редактировать</button>
        <form method="POST" action="api/person.php" class="d-inline" onsubmit="return confirm('Удалить этого человека и все его связи безвозвратно?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn-danger">🗑 Удалить человека</button>
        </form>
    </div>
</div>

<!-- ========== МОДАЛКА РЕДАКТИРОВАНИЯ ========== -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="POST" action="api/person.php">
            <div class="modal-header">
                <h5 class="modal-title">Редактировать: <?= htmlspecialchars(getFullName($person)) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= $id ?>">
                
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label">Фамилия *</label>
                        <input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($person['last_name']) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Имя *</label>
                        <input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($person['first_name']) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Отчество</label>
                        <input type="text" name="middle_name" class="form-control" value="<?= htmlspecialchars($person['middle_name'] ?? '') ?>">
                    </div>
                </div>
                
                <div class="row g-2 mt-1">
                    <div class="col-md-6">
                        <label class="form-label">Девичья фамилия</label>
                        <input type="text" name="maiden_name" class="form-control" value="<?= htmlspecialchars($person['maiden_name'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Пол</label>
                        <select name="gender" class="form-select">
                            <option value="U" <?= $person['gender']=='U'?'selected':'' ?>>Неизвестно</option>
                            <option value="M" <?= $person['gender']=='M'?'selected':'' ?>>Мужской</option>
                            <option value="F" <?= $person['gender']=='F'?'selected':'' ?>>Женский</option>
                        </select>
                    </div>
                </div>

                <div class="row g-2 mt-1">
                    <div class="col-md-8">
                        <label class="form-label">Дата рождения</label>
                        <input type="text" name="birth_date" class="form-control" value="<?= htmlspecialchars($birth_date_clean) ?>" placeholder="напр: 1930, 05-1930, 1930-05-12">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Точность</label>
                        <select name="birth_date_accuracy" class="form-select">
                            <option value="exact" <?= $birth_accuracy=='exact'?'selected':'' ?>>Точно</option>
                            <option value="approx" <?= $birth_accuracy=='approx'?'selected':'' ?>>Примерно</option>
                            <option value="before" <?= $birth_accuracy=='before'?'selected':'' ?>>До</option>
                            <option value="after" <?= $birth_accuracy=='after'?'selected':'' ?>>После</option>
                        </select>
                    </div>
                </div>

                <div class="row g-2 mt-1">
                    <div class="col-md-8">
                        <label class="form-label">Дата смерти</label>
                        <input type="text" name="death_date" class="form-control" value="<?= htmlspecialchars($death_date_clean) ?>" placeholder="оставьте пустым, если жив">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Точность</label>
                        <select name="death_date_accuracy" class="form-select">
                            <option value="exact" <?= $death_accuracy=='exact'?'selected':'' ?>>Точно</option>
                            <option value="approx" <?= $death_accuracy=='approx'?'selected':'' ?>>Примерно</option>
                            <option value="before" <?= $death_accuracy=='before'?'selected':'' ?>>До</option>
                            <option value="after" <?= $death_accuracy=='after'?'selected':'' ?>>После</option>
                        </select>
                    </div>
                </div>

                <div class="form-check mt-2">
                    <input type="checkbox" name="is_deceased" value="1" class="form-check-input" id="ed_dec" <?= !empty($person['is_deceased'])?'checked':'' ?>>
                    <label class="form-check-label" for="ed_dec">Умерший</label>
                </div>

                <div class="mt-2">
                    <label class="form-label">Место рождения</label>
                    <input type="text" name="birth_place" class="form-control" value="<?= htmlspecialchars($person['birth_place'] ?? '') ?>">
                </div>
                <div class="mt-2">
                    <label class="form-label">Биография</label>
                    <textarea name="bio" class="form-control" rows="4"><?= htmlspecialchars($person['bio'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Сохранить изменения</button>
            </div>
        </form>
    </div>
</div>


<!-- ========== МОДАЛКА ДОБАВЛЕНИЯ СВЯЗИ ========== -->
<div class="modal fade" id="addRelModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" id="addRelForm">
            <div class="modal-header">
                <h5 class="modal-title">Добавить связь</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- КРИТИЧЕСКИ ВАЖНО: value="<?= $id ?>" задаёт текущего человека -->
                <input type="hidden" name="action" value="add_relationship">
                <input type="hidden" name="from_person_id" id="rel_from" value="<?= $id ?>">
                
                <label class="form-label">Тип связи *</label>
                <select name="relation_type" id="rel_type" class="form-select" required onchange="toggleRelOptions()">
                    <option value="parent">Родитель</option>
                    <option value="child">Ребёнок</option>
                    <option value="spouse">Супруг(а)</option>
                    <option value="sibling">Брат / Сестра</option>
                </select>
                
                <label class="form-label mt-3">Человек *</label>
                <select name="to_person_id" id="rel_to" class="form-select" required>
                    <option value="">— Выберите человека —</option>
                    <?php foreach ($allPersons as $ap): ?>
                        <option value="<?= $ap['id'] ?>">
                            <?= htmlspecialchars(trim($ap['last_name'].' '.$ap['first_name'].' '.($ap['middle_name']??''))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <!-- 1. Опции для РОДИТЕЛЕЙ -->
                <div id="parentOptions" style="display:none" class="mt-3 p-3 bg-light rounded">
                    <label class="form-label"><strong>Тип родителя:</strong></label>
                    <select name="parent_type" id="parent_type" class="form-select mb-2">
                        <option value="biological">Родной</option>
                        <option value="step">Отчим / Мачеха</option>
                        <option value="godparent">Крёстный / Крёстная</option>
                    </select>
                    <div class="form-check">
                        <input type="checkbox" name="is_adopted" value="1" class="form-check-input" id="parent_adopted">
                        <label class="form-check-label" for="parent_adopted">Приёмный (усыновление/удочерение)</label>
                    </div>
                </div>
                
                <!-- 2. Опции для ДЕТЕЙ -->
                <div id="childOptions" style="display:none" class="mt-3 p-3 bg-light rounded">
                    <label class="form-label"><strong>Тип ребёнка:</strong></label>
                    <select name="child_type" id="child_type" class="form-select mb-2">
                        <option value="biological">Родной</option>
                        <option value="step">Пасынок / Падчерица</option>
                        <option value="godchild">Крёстник / Крёстница</option>
                    </select>
                    <div class="form-check">
                        <input type="checkbox" name="is_adopted" value="1" class="form-check-input" id="child_adopted">
                        <label class="form-check-label" for="child_adopted">Приёмный (усыновление/удочерение)</label>
                    </div>
                </div>
                
                <!-- 3. Опции для СУПРУГОВ -->
                <div id="spouseOptions" style="display:none" class="mt-3 p-3 bg-light rounded">
                    <label class="form-label"><strong>Статус брака:</strong></label>
                    <select name="spouse_status" id="spouse_status" class="form-select mb-2" onchange="toggleSpouseFields()">
                        <option value="current">Нынешний (действующий брак)</option>
                        <option value="divorced">Бывший (в разводе)</option>
                        <option value="widowed">Вдовец / Вдова (супруг(а) умер(ла))</option>
                    </select>
                    
                    <div id="divorceDateBlock" style="display:none" class="mb-2">
                        <label class="form-label">Дата развода</label>
                        <input type="text" name="divorce_date" class="form-control" placeholder="напр: 2010 или 15.06.2010">
                    </div>
                    <div id="marriageDateBlock" class="mb-2">
                        <label class="form-label">Дата брака (необязательно)</label>
                        <input type="text" name="marriage_date" class="form-control" placeholder="напр: 2010 или 15.06.2010">
                    </div>
                </div>

                <!-- 4. Опции для БРАТЬЕВ/СЕСТЁР -->
                <div id="siblingOptions" style="display:none" class="mt-3 p-3 bg-light rounded">
                    <label class="form-label"><strong>Тип родства:</strong></label>
                    <select name="shared_parent" id="sibling_shared_parent" class="form-select">
                        <option value="both">Родной (полнородный, оба родителя общие)</option>
                        <option value="father">Единокровный (общий отец)</option>
                        <option value="mother">Единоутробный (общая мать)</option>
                        <option value="none">Сводный (нет общих родителей)</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary" id="saveRelBtn">Добавить связь</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// 1. Переключение видимости полей
window.toggleRelOptions = function() {
    const type = document.getElementById('rel_type').value;
    document.getElementById('parentOptions').style.display = (type === 'parent') ? 'block' : 'none';
    document.getElementById('childOptions').style.display = (type === 'child') ? 'block' : 'none';
    document.getElementById('spouseOptions').style.display = (type === 'spouse') ? 'block' : 'none';
    document.getElementById('siblingOptions').style.display = (type === 'sibling') ? 'block' : 'none';
};

// 2. Логика для полей супруга
window.toggleSpouseFields = function() {
    const status = document.getElementById('spouse_status').value;
    document.getElementById('divorceDateBlock').style.display = (status === 'divorced') ? 'block' : 'none';
};

// 3. Подготовка модалки при нажатии на "+"
window.prepareAddRel = function(type) {
    document.getElementById('rel_type').value = type || 'parent';
    toggleRelOptions();
    toggleSpouseFields();
    document.getElementById('rel_to').value = ''; // Сброс выбора человека
};

// 4. Отправка формы добавления связи (ИСПРАВЛЕНА ЛОГИКА НАПРАВЛЕНИЯ)
document.getElementById('addRelForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('saveRelBtn');
    btn.disabled = true;
    btn.textContent = 'Сохранение...';
    
    const fd = new FormData(this);
    const type = document.getElementById('rel_type').value;
    
    const currentId = parseInt(fd.get('from_person_id')); // ID человека, чью страницу мы смотрим
    const selectedId = parseInt(fd.get('to_person_id'));   // ID выбранного в списке человека
    
    // КРИТИЧЕСКОЕ ИСПРАВЛЕНИЕ: Правильное направление связи для БД
    if (type === 'parent') {
        // Добавляем РОДИТЕЛЯ: Выбранный человек (from) -> Текущий человек (to)
        fd.set('from_person_id', selectedId);
        fd.set('to_person_id', currentId);
        fd.set('relation_type', 'parent');
    } else if (type === 'child') {
        // Добавляем РЕБЁНКА: Текущий человек (from) -> Выбранный человек (to)
        fd.set('from_person_id', currentId);
        fd.set('to_person_id', selectedId);
        fd.set('relation_type', 'parent'); // В БД связь всегда идёт от родителя к ребёнку как 'parent'
    } else if (type === 'spouse') {
        fd.set('from_person_id', currentId);
        fd.set('to_person_id', selectedId);
        fd.set('relation_type', 'spouse');
    } else if (type === 'sibling') {
        fd.set('from_person_id', currentId);
        fd.set('to_person_id', selectedId);
        fd.set('relation_type', 'sibling');
    }
    
    // Преобразуем выбранные подтипы в поля, понятные backend
    if (type === 'parent') {
        const pType = document.getElementById('parent_type').value;
        fd.set('shared_parent', pType === 'step' ? 'none' : 'both');
        if (pType === 'step') fd.set('is_step', '1'); else fd.set('is_step', '0');
        if (pType === 'godparent') fd.set('shared_parent', 'godparent');
    } else if (type === 'child') {
        const cType = document.getElementById('child_type').value;
        fd.set('shared_parent', cType === 'step' ? 'none' : 'both');
        if (cType === 'step') fd.set('is_step', '1'); else fd.set('is_step', '0');
        if (cType === 'godchild') fd.set('shared_parent', 'godchild');
    } else if (type === 'spouse') {
        const status = document.getElementById('spouse_status').value;
        fd.set('is_widowed', (status === 'widowed') ? '1' : '0');
        if (status !== 'divorced') {
            fd.set('divorce_date', ''); // Очищаем дату развода, если не в разводе
        }
    }
    
    // Гарантируем наличие чекбоксов
    if (!fd.has('is_adopted')) fd.set('is_adopted', '0');
    
    try {
        const res = await fetch('api/person.php', { method: 'POST', body: fd, cache: 'no-store' });
        const data = await res.json();
        
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('addRelModal')).hide();
            window.location.reload(); // Перезагрузка страницы покажет новые данные
        } else {
            alert('Ошибка: ' + (data.error || 'Не удалось сохранить связь'));
        }
    } catch (err) {
        alert('Ошибка сети: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Добавить связь';
    }
});

// 5. Удаление связи
window.deleteRelationship = async function(relId) {
    if (!confirm('Вы уверены, что хотите удалить эту родственную связь?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_relationship');
    fd.append('rel_id', relId);
    try {
        const res = await fetch('api/person.php', { method: 'POST', body: fd, cache: 'no-store' });
        const data = await res.json();
        if (data.success) {
            window.location.reload();
        } else {
            alert('Ошибка: ' + (data.error || 'Не удалось удалить связь'));
        }
    } catch (err) {
        alert('Ошибка сети: ' + err.message);
    }
};

// Инициализация при загрузке
document.addEventListener('DOMContentLoaded', function() {
    toggleRelOptions();
    toggleSpouseFields();
});


// ====== УДАЛЕНИЕ ФОТО ПРОФИЛЯ ======
async function deletePersonPhoto(personId) {
    if (!confirm('Вы уверены, что хотите удалить эту фотографию? Действие необратимо.')) {
        return;
    }
    
    const fd = new FormData();
    fd.append('action', 'delete_photo');
    fd.append('person_id', personId);
    
    try {
        const res = await fetch('api/person.php', { 
            method: 'POST', 
            body: fd,
            cache: 'no-store'
        });
        const data = await res.json();
        
        if (data.success) {
            // Перезагружаем страницу, чтобы обновить интерфейс (показать плейсхолдер вместо фото)
            window.location.reload();
        } else {
            alert('Ошибка: ' + (data.error || 'Не удалось удалить фото'));
        }
    } catch (err) {
        console.error('Ошибка сети:', err);
        alert('Ошибка сети: ' + err.message);
    }
}
</script>
</body>
</html>