<?php
require_once 'includes/functions.php';

// Получаем всех людей для отображения в списке
$stmt = $pdo->query("SELECT * FROM persons ORDER BY last_name, first_name");
$persons = $stmt->fetchAll();

// Получаем список всех людей специально для селектов в модальном окне
$allPersons = $pdo->query("SELECT id, first_name, middle_name, last_name FROM persons ORDER BY last_name, first_name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Семейное древо — Главная</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-dark">
    <div class="container-fluid px-3">
        <a class="navbar-brand" href="index.php">Семейное древо</a>
        <div class="d-flex gap-2">
            <a href="event.php" class="btn btn-outline-light btn-sm">События</a>
            <a href="media.php" class="btn btn-outline-light btn-sm">Медиатека</a>
            <a href="tree.php" class="btn btn-outline-light btn-sm">Дерево</a>
            <div class="position-relative">
                <input type="text" id="globalSearch" class="form-control form-control-sm" placeholder="Поиск по ФИО..." style="width: 250px;" autocomplete="off">
                <div id="searchResults" class="dropdown-menu show" style="display: none; position: absolute; top: 100%; left: 0; width: 100%; z-index: 1050; max-height: 300px; overflow-y: auto;"></div>
            </div>
            <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addPersonModal" onclick="prepareAddModal()">+ Добавить человека</button>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <h2 class="mb-4">Список членов семьи (<?= count($persons) ?>)</h2>

    <div class="row">
        <?php foreach ($persons as $p): 
            $status = getLifeStatus($p);
        ?>
        <div class="col-md-4 mb-3">
            <div class="card person-card <?= $status ?>">
                <div class="card-body">
                    <h5 class="card-title"><?= htmlspecialchars(getFullName($p)) ?></h5>
                    <?php if ($p['maiden_name']): ?>
                        <p class="text-muted small mb-1">(ур. <?= htmlspecialchars($p['maiden_name']) ?>)</p>
                    <?php endif; ?>
                    <p class="card-text"><?= getLifeDates($p) ?></p>
                    <a href="person.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary">Подробнее</a>
                </div>
                <div class="status-badge">
                    <?= $status === 'alive' ? '● живой' : ($status === 'deceased' ? '● умерший' : '● ?') ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ========== МОДАЛКА ДОБАВЛЕНИЯ (идентична tree.php) ========== -->
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
                    <div class="col-md-4">
                        <label class="form-label">Фамилия *</label>
                        <input type="text" name="last_name" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Имя *</label>
                        <input type="text" name="first_name" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Отчество</label>
                        <input type="text" name="middle_name" class="form-control">
                    </div>
                </div>

                <div class="row g-2 mt-1">
                    <div class="col-md-6">
                        <label class="form-label">Девичья фамилия</label>
                        <input type="text" name="maiden_name" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Пол</label>
                        <select name="gender" class="form-select">
                            <option value="U">Неизвестно</option>
                            <option value="M">Мужской</option>
                            <option value="F">Женский</option>
                        </select>
                    </div>
                </div>

                <!-- ДАТА РОЖДЕНИЯ -->
                <div class="row g-2 mt-1">
                    <div class="col-md-8">
                        <label class="form-label">Дата рождения</label>
                        <div class="input-group">
                            <input type="text" name="birth_date" class="form-control" 
                                placeholder="например: 1930, 05-1930, 1930-05-12, около 1930">
                        </div>
                        <small class="text-muted">Формат: ГГГГ-ММ-ДД или ГГГГ или ММ-ГГГГ</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Точность</label>
                        <select name="birth_date_accuracy" class="form-select">
                            <option value="exact">Точно</option>
                            <option value="approx">Примерно</option>
                            <option value="before">До</option>
                            <option value="after">После</option>
                            <option value="between">Между</option>
                        </select>
                    </div>
                </div>

                <!-- ДАТА СМЕРТИ -->
                <div class="row g-2 mt-1">
                    <div class="col-md-8">
                        <label class="form-label">Дата смерти</label>
                        <div class="input-group">
                            <input type="text" name="death_date" class="form-control" 
                                placeholder="например: 2005, 08-2005, 2005-08-20">
                        </div>
                        <small class="text-muted">Оставьте пустым, если человек жив</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Точность</label>
                        <select name="death_date_accuracy" class="form-select">
                            <option value="exact">Точно</option>
                            <option value="approx">Примерно</option>
                            <option value="before">До</option>
                            <option value="after">После</option>
                        </select>
                    </div>
                </div>

                <div class="form-check mt-2">
                    <input type="checkbox" name="is_deceased" value="1" class="form-check-input" id="form_is_deceased">
                    <label class="form-check-label" for="form_is_deceased">Умерший</label>
                </div>

                <div class="mt-2">
                    <label class="form-label">Место рождения</label>
                    <input type="text" name="birth_place" class="form-control">
                </div>
                <div class="mt-2">
                    <label class="form-label">Биография</label>
                    <textarea name="bio" class="form-control" rows="3"></textarea>
                </div>

                <hr>
                <h6>Родственники (из уже добавленных людей)</h6>
                <p class="text-muted small">Можно выбрать несколько, удерживая Ctrl / Cmd</p>

                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label">Родители</label>
                        <select name="parents[]" class="form-select" multiple size="4" id="sel_parents"></select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Супруги</label>
                        <select name="spouses[]" class="form-select" multiple size="4" id="sel_spouses"></select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Дети</label>
                        <select name="children[]" class="form-select" multiple size="4" id="sel_children"></select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary" id="form_submit_btn">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Получаем данные из PHP
    const allPersonsCache = <?= json_encode($allPersons) ?>;
    console.log('Данные из PHP (allPersonsCache):', allPersonsCache);

    // 2. Функция заполнения селектов
    function fillSelect(selectId, excludeId = null) {
        const sel = document.getElementById(selectId);
        if (!sel) {
            console.error('Ошибка: элемент с id="' + selectId + '" не найден в HTML!');
            return;
        }
        
        sel.innerHTML = ''; // Очищаем список
        
        if (!allPersonsCache || allPersonsCache.length === 0) {
            const opt = document.createElement('option');
            opt.textContent = 'В базе пока нет людей';
            opt.disabled = true;
            sel.appendChild(opt);
            console.warn('Список людей пуст!');
            return;
        }

        let count = 0;
        allPersonsCache.forEach(p => {
            if (p.id == excludeId) return;
            const opt = document.createElement('option');
            opt.value = p.id;
            const fullName = p.last_name + ' ' + p.first_name + (p.middle_name ? ' ' + p.middle_name : '');
            opt.textContent = fullName;
            sel.appendChild(opt);
            count++;
        });
        console.log('Селект "' + selectId + '" заполнен. Добавлено опций: ' + count);
    }

    // 3. Функция подготовки модального окна
    window.prepareAddModal = function() {
        console.log('Открыто модальное окно добавления');
        
        document.getElementById('addModalTitle').textContent = 'Добавить человека';
        document.getElementById('form_action').value = 'create';
        document.getElementById('form_id').value = '';
        
        // Сбрасываем форму
        const form = document.getElementById('personForm');
        form.reset();
        
        // Восстанавливаем скрытые поля, которые сбрасывает form.reset()
        document.getElementById('form_action').value = 'create';
        document.getElementById('ajax').value = '1';

        // Заполняем селекты
        fillSelect('sel_parents');
        fillSelect('sel_spouses');
        fillSelect('sel_children');
    };

    // 4. Обработчик отправки формы
    const form = document.getElementById('personForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            
            console.log('Начало отправки формы через AJAX...');
            
            const fd = new FormData(this);
            fd.set('ajax', '1'); // Гарантируем, что сервер вернёт JSON
            
            // Для отладки: выводим, что отправляется
            const formDataObj = {};
            fd.forEach((value, key) => { formDataObj[key] = value; });
            console.log('Отправляемые данные:', formDataObj);
            
            fetch('api/person.php', { 
                method: 'POST', 
                body: fd,
                cache: 'no-store'
            })
            .then(res => {
                console.log('Статус ответа сервера:', res.status);
                return res.text();
            })
            .then(text => {
                console.log('Сырой ответ сервера:', text.substring(0, 300));
                try {
                    const data = JSON.parse(text);
                    if (data.success) {
                        const modal = bootstrap.Modal.getInstance(document.getElementById('addPersonModal'));
                        if (modal) modal.hide();
                        setTimeout(() => { window.location.reload(); }, 300);
                    } else {
                        alert('Ошибка: ' + (data.error || 'Неизвестная ошибка'));
                    }
                } catch (err) {
                    console.error('Ошибка парсинга JSON. Сервер вернул HTML или текст:', text);
                    alert('Ошибка сервера. Откройте консоль (F12) для деталей.');
                }
            })
            .catch(err => {
                console.error('Сетевая ошибка:', err);
                alert('Ошибка сети: ' + err.message);
            });
            
            return false;
        }, true);
    } else {
        console.error('Критическая ошибка: форма с id="personForm" не найдена в HTML!');
    }
});

// Простой фильтр на стороне клиента для списка
const indexSearchInput = document.getElementById('globalSearch');
if (indexSearchInput) {
    indexSearchInput.addEventListener('input', function() {
        const query = this.value.toLowerCase();
        const cards = document.querySelectorAll('.person-card');
        
        cards.forEach(card => {
            const text = card.textContent.toLowerCase();
            if (text.includes(query)) {
                card.parentElement.style.display = 'block';
            } else {
                card.parentElement.style.display = 'none';
            }
        });
    });
}
</script>
</body>
</html>