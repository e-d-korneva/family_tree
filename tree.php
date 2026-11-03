<?php
require_once __DIR__ . '/includes/functions.php';

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
        
        // Check if relationship exists
        $check = $pdo->prepare("SELECT id FROM relationships WHERE from_person_id = ? AND to_person_id = ? AND relation_type = ?");
        $check->execute([$fromId, $toId, $relType]);
        
        if ($check->fetch()) {
            // Update existing
            $stmt = $pdo->prepare("UPDATE relationships SET 
                marriage_date = ?, 
                divorce_date = ?, 
                is_adopted = ?, 
                is_step = ?, 
                shared_parent = ?,
                is_widowed = ?
                WHERE from_person_id = ? AND to_person_id = ? AND relation_type = ?");
            $stmt->execute([
                $_POST['marriage_date'] ?? null,
                $_POST['divorce_date'] ?? null,
                isset($_POST['is_adopted']) ? 1 : 0,
                isset($_POST['is_step']) ? 1 : 0,
                $_POST['shared_parent'] ?? 'both',
                isset($_POST['is_widowed']) ? 1 : 0,
                $fromId, $toId, $relType
            ]);
        } else {
            // Insert new
            $stmt = $pdo->prepare("INSERT INTO relationships (from_person_id, to_person_id, relation_type, marriage_date, divorce_date, is_adopted, is_step, shared_parent, is_widowed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $fromId, $toId, $relType,
                $_POST['marriage_date'] ?? null,
                $_POST['divorce_date'] ?? null,
                isset($_POST['is_adopted']) ? 1 : 0,
                isset($_POST['is_step']) ? 1 : 0,
                $_POST['shared_parent'] ?? 'both',
                isset($_POST['is_widowed']) ? 1 : 0
            ]);
        }
        jsonResponse(['success' => true]);
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
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Семейное древо</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <script src="https://unpkg.com/cytoscape@3.28.1/dist/cytoscape.min.js"></script>
</head>
<body>
<nav class="navbar navbar-dark bg-dark">
    <div class="container-fluid px-3">
        <a class="navbar-brand" href="index.php">Семейное древо</a>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-outline-light btn-sm">Люди</a>
            <a href="event.php" class="btn btn-outline-light btn-sm">События</a>
            <a href="media.php" class="btn btn-outline-light btn-sm">Медиатека</a>
            <div class="position-relative">
                <input type="text" id="globalSearch" class="form-control form-control-sm" placeholder="Поиск по ФИО..." style="width: 250px;" autocomplete="off">
                <div id="searchResults" class="dropdown-menu show" style="display: none; position: absolute; top: 100%; left: 0; width: 100%; z-index: 1050; max-height: 300px; overflow-y: auto;"></div>
            </div>
            <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addPersonModal" onclick="openAddModal()">+ Добавить человека</button>
            <button class="btn btn-info btn-sm" id="kinshipBtn">Показать родство</button>
        </div>
    </div>
</nav>

<div id="tree" style="width:100%; height: calc(100vh - 56px);"></div>
<div id="kinshipResult" class="kinship-result" style="display:none"></div>

<!-- ========== МОДАЛКА ПРОСМОТРА ЧЕЛОВЕКА ========== -->
<div class="modal fade" id="personModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="pm_name">—</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs" id="pmTabs" role="tablist">
                    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-main">Основное</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-rel">Родственники</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-events">События</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-media">Медиа</a></li>
                </ul>

                <div class="tab-content mt-3">
                    <div class="tab-pane fade show active" id="tab-main">
                        <div class="row">
                            <div class="col-md-3 text-center">
                                <div id="pm_photo_container" class="text-center mb-2">
                                    <div id="pm_photo" class="placeholder-photo mb-2"> 👤 </div>
                                </div>
                                <div id="pm_status_badge" class="badge"></div>
                            </div>
                            <div class="col-md-9">
                                <table class="table table-borderless">
                                    <tr><th style="width:180px">ФИО:</th><td id="pm_fullname"></td></tr>
                                    <tr><th>Девичья фамилия:</th><td id="pm_maiden">—</td></tr>
                                    <tr><th>Даты жизни:</th><td id="pm_dates"></td></tr>
                                    <tr><th>Пол:</th><td id="pm_gender"></td></tr>
                                    <tr><th>Место рождения:</th><td id="pm_birthplace">—</td></tr>
                                    <tr><th>Место смерти:</th><td id="pm_deathplace">—</td></tr>
                                </table>
                                <div id="pm_bio_block" style="display:none">
                                    <h6>Биография:</h6>
                                    <p id="pm_bio" class="border rounded p-2 bg-light"></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="tab-rel">
                        <div class="row">
                            <div class="col-md-6"><h6>Родители</h6><ul class="list-group mb-3" id="pm_parents"></ul></div>
                            <div class="col-md-6"><h6>Супруги</h6><ul class="list-group mb-3" id="pm_spouses"></ul></div>
                            <div class="col-md-6"><h6>Дети</h6><ul class="list-group mb-3" id="pm_children"></ul></div>
                            <div class="col-md-6"><h6>Братья и сёстры</h6><ul class="list-group mb-3" id="pm_siblings"></ul></div>
                        </div>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addRelModal" onclick="prepareAddRel()">+ Добавить связь</button>
                    </div>

                    <div class="tab-pane fade" id="tab-events">
                        <div id="pm_events_list" class="mb-3"></div>
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addEventModal" onclick="prepareAddEventModal()">+ Добавить событие</button>
                    </div>

                    <div class="tab-pane fade" id="tab-media">
                        <form id="pm_upload_form" class="mb-3 border p-3 rounded">
                            <input type="hidden" name="action" value="upload_media">
                            <input type="hidden" name="person_id" id="pm_upload_person_id">
                            <div class="row g-2">
                                <div class="col-md-6"><input type="file" name="files[]" class="form-control" multiple accept="image/*"></div>
                                <div class="col-md-4"><input type="text" name="caption" class="form-control" placeholder="Подпись (необязательно)"></div>
                                <div class="col-md-2"><button type="submit" class="btn btn-primary w-100">Загрузить</button></div>
                            </div>
                        </form>
                        <div class="row" id="pm_media_gallery"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-warning" onclick="openEditModal(currentPersonId)">✏ Редактировать</button>
                <button class="btn btn-danger" onclick="deletePerson(currentPersonId)">🗑 Удалить</button>
                <button class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
            </div>
        </div>
    </div>
</div>

<!-- ========== МОДАЛКА ДОБАВЛЕНИЯ/РЕДАКТИРОВАНИЯ ========== -->
<div class="modal fade" id="addPersonModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" id="personForm">
            <div class="modal-header">
                <h5 class="modal-title" id="addModalTitle">Добавить человека</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="create" id="form_action">
                <input type="hidden" name="id" id="form_id">
                <input type="hidden" name="ajax" id="ajax" value="1">

                <div class="row g-2">
                    <div class="col-md-4"><label class="form-label">Фамилия *</label><input type="text" name="last_name" class="form-control" required></div>
                    <div class="col-md-4"><label class="form-label">Имя *</label><input type="text" name="first_name" class="form-control" required></div>
                    <div class="col-md-4"><label class="form-label">Отчество</label><input type="text" name="middle_name" class="form-control"></div>
                </div>
                <div class="row g-2 mt-1">
                    <div class="col-md-6"><label class="form-label">Девичья фамилия</label><input type="text" name="maiden_name" class="form-control"></div>
                    <div class="col-md-6"><label class="form-label">Пол</label>
                        <select name="gender" class="form-select">
                            <option value="U">Неизвестно</option><option value="M">Мужской</option><option value="F">Женский</option>
                        </select>
                    </div>
                </div>
                <div class="row g-2 mt-1">
                    <div class="col-md-8">
                        <label class="form-label">Дата рождения</label>
                        <input type="text" name="birth_date" class="form-control" placeholder="например: 1930, 05-1930, 1930-05-12">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Точность</label>
                        <select name="birth_date_accuracy" class="form-select">
                            <option value="exact">Точно</option><option value="approx">Примерно</option><option value="before">До</option><option value="after">После</option>
                        </select>
                    </div>
                </div>
                <div class="row g-2 mt-1">
                    <div class="col-md-8">
                        <label class="form-label">Дата смерти</label>
                        <input type="text" name="death_date" class="form-control" placeholder="например: 2005, 08-2005, 2005-08-20">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Точность</label>
                        <select name="death_date_accuracy" class="form-select">
                            <option value="exact">Точно</option><option value="approx">Примерно</option><option value="before">До</option><option value="after">После</option>
                        </select>
                    </div>
                </div>
                <div class="form-check mt-2">
                    <input type="checkbox" name="is_deceased" value="1" class="form-check-input" id="form_is_deceased">
                    <label class="form-check-label" for="form_is_deceased">Умерший</label>
                </div>
                <div class="mt-2"><label class="form-label">Место рождения</label><input type="text" name="birth_place" class="form-control"></div>
                <div class="mt-2"><label class="form-label">Биография</label><textarea name="bio" class="form-control" rows="3"></textarea></div>
                
                <div class="mt-2">
                    <label class="form-label">Фотография</label>
                    <input type="file" name="new_photo" class="form-control" accept="image/*">
                    <small class="text-muted">JPG, PNG, GIF или WEBP</small>
                </div>

                <hr>
                <h6>Родственники (из уже добавленных людей)</h6>
                <p class="text-muted small">Можно выбрать несколько, удерживая Ctrl / Cmd</p>
                <div class="row g-2">
                    <div class="col-md-4"><label class="form-label">Родители</label><select name="parents[]" class="form-select" multiple size="4" id="sel_parents"></select></div>
                    <div class="col-md-4"><label class="form-label">Супруги</label><select name="spouses[]" class="form-select" multiple size="4" id="sel_spouses"></select></div>
                    <div class="col-md-4"><label class="form-label">Дети</label><select name="children[]" class="form-select" multiple size="4" id="sel_children"></select></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary" id="form_submit_btn">Сохранить</button>
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
                <input type="hidden" name="action" value="add_relationship">
                <input type="hidden" name="from_person_id" id="rel_from">
                
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
                    <!-- Опции заполняются через JS -->
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

<!-- ========== МОДАЛКА ДОБАВЛЕНИЯ СОБЫТИЯ ========== -->
<div class="modal fade" id="addEventModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" id="addEventForm">
            <div class="modal-header">
                <h5 class="modal-title">Добавить событие</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="create">
                <div class="row g-2 mb-3">
                    <div class="col-md-6"><label class="form-label">Тип события *</label><input type="text" name="event_type" class="form-control" placeholder="Напр: Свадьба, Рождение" required></div>
                    <div class="col-md-6"><label class="form-label">Дата события</label><input type="text" name="event_date" class="form-control" placeholder="Напр: 1985, 12.05.1985"></div>
                </div>
                <div class="mb-3"><label class="form-label">Место</label><input type="text" name="event_place" class="form-control" placeholder="Напр: Москва"></div>
                <div class="mb-3"><label class="form-label">Описание</label><textarea name="description" class="form-control" rows="2"></textarea></div>
                <hr>
                <h6>Участники события</h6>
                <div class="row g-2 mb-2">
                    <div class="col-md-6"><select id="event_person_select" class="form-select"><option value="">Выберите человека...</option></select></div>
                    <div class="col-md-4"><input type="text" id="event_person_role" class="form-control" placeholder="Роль (напр. Жених)"></div>
                    <div class="col-md-2"><button type="button" class="btn btn-outline-primary w-100" onclick="addPersonToEventTemp()">Добавить</button></div>
                </div>
                <ul id="temp_event_participants" class="list-group"></ul>
                <input type="hidden" name="participants" id="event_participants_json">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Сохранить событие</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/tree.js"></script>
<script>
// 1. Переключение видимости блоков в зависимости от типа связи
window.toggleRelOptions = function() {
    const type = document.getElementById('rel_type').value;
    document.getElementById('parentOptions').style.display = (type === 'parent') ? 'block' : 'none';
    document.getElementById('childOptions').style.display = (type === 'child') ? 'block' : 'none';
    document.getElementById('spouseOptions').style.display = (type === 'spouse') ? 'block' : 'none';
    document.getElementById('siblingOptions').style.display = (type === 'sibling') ? 'block' : 'none';
};

// 2. Дополнительная логика для полей супруга
window.toggleSpouseFields = function() {
    const status = document.getElementById('spouse_status').value;
    document.getElementById('divorceDateBlock').style.display = (status === 'divorced') ? 'block' : 'none';
};

// 3. Подготовка модалки (вызывается при нажатии на "+")
window.prepareAddRel = async function(type) {
    document.getElementById('rel_type').value = type || 'parent';
    toggleRelOptions();
    toggleSpouseFields();
    
    // Загрузка списка людей (универсальная для tree.php и person.php)
    try {
        let data;
        if (typeof allPersonsCache !== 'undefined' && allPersonsCache.length > 0) {
            data = { nodes: allPersonsCache.map(p => ({ data: { id: 'p' + p.id, full_name: p.last_name + ' ' + p.first_name } })) };
        } else {
            const res = await fetch('api/tree_data.php', { cache: 'no-store' });
            data = await res.json();
        }
        
        const sel = document.getElementById('rel_to');
        sel.innerHTML = '<option value="">— Выберите человека —</option>';
        const currentId = document.getElementById('rel_from')?.value;
        
        data.nodes.forEach(n => {
            const nodeId = n.data.id.toString().replace('p', '');
            if (nodeId != currentId) {
                const opt = document.createElement('option');
                opt.value = nodeId;
                opt.textContent = n.data.full_name;
                sel.appendChild(opt);
            }
        });
    } catch (err) {
        console.error('Ошибка загрузки списка:', err);
    }
};

// 4. Отправка формы связи
document.getElementById('addRelForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('saveRelBtn');
    btn.disabled = true;
    btn.textContent = 'Сохранение...';
    
    const fd = new FormData(this);
    const type = document.getElementById('rel_type').value;
    
    const currentId = parseInt(fd.get('from_person_id'));
    const selectedId = parseInt(fd.get('to_person_id'));
    
    // КРИТИЧЕСКОЕ ИСПРАВЛЕНИЕ: правильное направление связи для БД
    if (type === 'parent') {
        // Добавляем РОДИТЕЛЯ: Выбранный (from) -> Текущий (to)
        fd.set('from_person_id', selectedId);
        fd.set('to_person_id', currentId);
        fd.set('relation_type', 'parent');
    } else if (type === 'child') {
        // Добавляем РЕБЁНКА: Текущий (from) -> Выбранный (to)
        fd.set('from_person_id', currentId);
        fd.set('to_person_id', selectedId);
        fd.set('relation_type', 'parent');
    } else if (type === 'spouse') {
        fd.set('from_person_id', currentId);
        fd.set('to_person_id', selectedId);
        fd.set('relation_type', 'spouse');
    } else if (type === 'sibling') {
        fd.set('from_person_id', currentId);
        fd.set('to_person_id', selectedId);
        fd.set('relation_type', 'sibling');
    }
    
    // Преобразуем подтипы
    if (type === 'parent') {
        const pType = document.getElementById('parent_type').value;
        fd.set('shared_parent', pType === 'step' ? 'none' : (pType === 'godparent' ? 'godparent' : 'both'));
        fd.set('is_step', pType === 'step' ? '1' : '0');
    } else if (type === 'child') {
        const cType = document.getElementById('child_type').value;
        fd.set('shared_parent', cType === 'step' ? 'none' : (cType === 'godchild' ? 'godchild' : 'both'));
        fd.set('is_step', cType === 'step' ? '1' : '0');
    } else if (type === 'spouse') {
        const status = document.getElementById('spouse_status').value;
        fd.set('is_widowed', status === 'widowed' ? '1' : '0');
        if (status !== 'divorced') fd.set('divorce_date', '');
    }
    
    if (!fd.has('is_adopted')) fd.set('is_adopted', '0');
    
    try {
        const res = await fetch('api/person.php', { method: 'POST', body: fd, cache: 'no-store' });
        const data = await res.json();
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('addRelModal')).hide();
            if (typeof showPersonModal === 'function' && currentPersonId) {
                await showPersonModal(currentPersonId);
            } else {
                window.location.reload();
            }
            if (typeof reloadTree === 'function') await reloadTree();
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

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    toggleRelOptions();
    toggleSpouseFields();
});
</script>
</body>
</html>