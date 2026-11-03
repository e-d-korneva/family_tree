<?php
require_once 'includes/functions.php';

// Получаем события с количеством участников
try {
    $events = $pdo->query("SELECT e.*, COUNT(ep.person_id) as participants_count 
                            FROM events e 
                            LEFT JOIN event_participants ep ON e.id = ep.event_id  
                            GROUP BY e.id  
                            ORDER BY e.event_date DESC")->fetchAll();
} catch (PDOException $e) {
    // Если таблица event_participants не существует, используем упрощенный запрос
    $events = $pdo->query("SELECT e.*, 0 as participants_count 
                            FROM events e 
                            ORDER BY e.event_date DESC")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>События — Семейное древо</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        .search-bar {
            background: #FAFAF7;
            border-radius: 14px;
            padding: 1.2rem;
            box-shadow: 0 3px 12px rgba(168, 181, 160, 0.12);
            margin-bottom: 1.5rem;
        }
        .event-card {
            transition: all 0.3s ease;
        }
        .event-card.hidden {
            display: none;
        }
        .no-results {
            text-align: center;
            padding: 3rem 2rem;
            color: #8a9a82;
            display: none;
        }
        .no-results.show {
            display: block;
        }
        .search-stats {
            font-size: 0.9rem;
            color: #8a9a82;
            margin-top: 0.5rem;
        }
    </style>
</head>
<body>
<nav class="navbar navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="index.php">Семейное древо</a>
        <div class="d-flex gap-2">            
            <a href="index.php" class="btn btn-outline-light btn-sm">Люди</a>            
            <a href="tree.php" class="btn btn-outline-light btn-sm">Дерево</a>
            <a href="event.php" class="btn btn-light btn-sm">События</a>
            <a href="media.php" class="btn btn-outline-light btn-sm">Медиатека</a>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>События</h2>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addEventModal">+ Добавить событие</button>
    </div>
    
    <!-- Панель поиска -->
    <div class="search-bar">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label"><strong>Поиск</strong></label>
                <input type="text" id="eventSearch" class="form-control" 
                       placeholder="Введите тип, место или описание...">
            </div>
            <div class="col-md-3">
                <label class="form-label"><strong>Дата от</strong></label>
                <input type="date" id="dateFrom" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label"><strong>Дата до</strong></label>
                <input type="date" id="dateTo" class="form-control">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-outline-secondary w-100" onclick="clearSearch()">Очистить</button>
            </div>
        </div>
        <div class="search-stats" id="searchStats"></div>
    </div>
    
    <div class="row" id="eventsContainer">
        <?php foreach ($events as $event): ?>
        <div class="col-md-6 mb-3 event-card" 
             data-event-type="<?= htmlspecialchars(strtolower($event['event_type'])) ?>"
             data-event-place="<?= htmlspecialchars(strtolower($event['event_place'] ?? '')) ?>"
             data-event-date="<?= htmlspecialchars($event['event_date'] ?? '') ?>"
             data-event-description="<?= htmlspecialchars(strtolower($event['description'] ?? '')) ?>">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title"><?= htmlspecialchars($event['event_type']) ?></h5>
                    <?php if ($event['event_date']): ?>
                        <p class="text-muted mb-1"><?= htmlspecialchars($event['event_date']) ?></p>
                    <?php endif; ?>
                    <?php if ($event['event_place']): ?>
                        <p class="mb-1"><?= htmlspecialchars($event['event_place']) ?></p>
                    <?php endif; ?>
                    <p class="mb-2">Участников: <?= $event['participants_count'] ?></p>
                    <?php if ($event['description']): ?>
                        <p class="text-muted small mb-3"><?= nl2br(htmlspecialchars(mb_substr($event['description'], 0, 100))) ?><?= mb_strlen($event['description']) > 100 ? '...' : '' ?></p>
                    <?php endif; ?>
                    <a href="#" class="btn btn-sm btn-outline-primary" 
                       data-bs-toggle="modal" 
                       data-bs-target="#eventDetailsModal" 
                       onclick="loadEventDetails(<?= $event['id'] ?>)">Подробнее</a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Сообщение об отсутствии результатов -->
    <div class="no-results" id="noResults">
        <div style="font-size: 60px; opacity: 0.4; margin-bottom: 1rem;">🔍</div>
        <h4>Ничего не найдено</h4>
        <p class="text-muted">Попробуйте изменить параметры поиска</p>
    </div>
</div>

<!-- ========== МОДАЛКА ПРОСМОТРА ДЕТАЛЕЙ СОБЫТИЯ (НОВАЯ) ========== -->
<div class="modal fade" id="eventDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="eventDetailsTitle">Детали события</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="eventDetailsBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2 text-muted">Загрузка данных...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-warning me-auto" onclick="openEditEventModal()">Редактировать</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
            </div>
        </div>
    </div>
</div>

<!-- ========== МОДАЛКА ДОБАВЛЕНИЯ СОБЫТИЯ ========== -->
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

<!-- ========== МОДАЛКА РЕДАКТИРОВАНИЯ СОБЫТИЯ ========== -->
<div class="modal fade" id="editEventModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" id="editEventForm">
            <div class="modal-header">
                <h5>Редактировать событие</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_event_id">
                
                <div class="mb-3">
                    <label class="form-label">Тип события *</label>
                    <input type="text" name="event_type" id="edit_event_type" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Дата</label>
                    <input type="text" name="event_date" id="edit_event_date" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label">Место</label>
                    <input type="text" name="event_place" id="edit_event_place" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label">Описание</label>
                    <textarea name="description" id="edit_event_description" class="form-control" rows="3"></textarea>
                </div>
                <hr>
                <h6>Участники</h6>
                <div class="row g-2 mb-2">
                    <div class="col-md-6">
                        <select id="edit_event_person_select" class="form-select">
                            <option value="">Выберите человека...</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <input type="text" id="edit_event_person_role" class="form-control" placeholder="Роль (напр. Жених)">
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-outline-primary w-100" onclick="addPersonToEditEventTemp()">Добавить</button>
                    </div>
                </div>
                <ul id="edit_temp_event_participants" class="list-group mb-2"></ul>
                <input type="hidden" name="participants" id="edit_participants_json" value="[]">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Сохранить изменения</button>
            </div>
        </form>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ====== 1. ЗАГРУЗКА ДЕТАЛЕЙ СОБЫТИЯ (НОВАЯ ФУНКЦИЯ) ======
async function loadEventDetails(eventId) {
    const modalBody = document.getElementById('eventDetailsBody');
    const modalTitle = document.getElementById('eventDetailsTitle');
    
    // Показываем индикатор загрузки
    modalBody.innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2 text-muted">Загрузка данных...</p>
        </div>
    `;
    modalTitle.textContent = 'Загрузка...';

    try {
        const res = await fetch(`api/event.php?action=get&id=${eventId}`, { cache: 'no-store' });
        if (!res.ok) throw new Error('Ошибка сети');
        const data = await res.json();
        
        if (data.error) {
            modalBody.innerHTML = `<div class="alert alert-danger">${data.error}</div>`;
            modalTitle.textContent = 'Ошибка';
            return;
        }

        modalTitle.textContent = data.event_type || 'Детали события';
        
        let participantsHtml = '<p class="text-muted">Участники не указаны</p>';
        if (data.participants && data.participants.length > 0) {
            participantsHtml = '<ul class="list-group">';
            data.participants.forEach(p => {
                const fullName = `${p.last_name || ''} ${p.first_name || ''} ${p.middle_name || ''}`.trim() || 'Неизвестно';
                const role = p.role ? `<span class="badge bg-info text-dark ms-2">${p.role}</span>` : '';
                participantsHtml += `
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <a href="person.php?id=${p.person_id}" class="text-decoration-none fw-bold">${fullName}</a>
                        ${role}
                    </li>
                `;
            });
            participantsHtml += '</ul>';
        }

        // Вспомогательная функция для переноса строк
        function nl2br(str) {
            return str ? str.replace(/\n/g, '<br>') : '';
        }

        modalBody.innerHTML = `
            <div class="mb-3">
                <h6 class="text-muted text-uppercase small">Тип события</h6>
                <p class="fs-5 mb-0">${data.event_type || 'Не указано'}</p>
            </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <h6 class="text-muted text-uppercase small">Дата</h6>
                    <p class="mb-0 fs-6">${data.event_date || 'Не указана'}</p>
                </div>
                <div class="col-md-6">
                    <h6 class="text-muted text-uppercase small">Место</h6>
                    <p class="mb-0 fs-6">${data.event_place || 'Не указано'}</p>
                </div>
            </div>
            <div class="mb-3">
                <h6 class="text-muted text-uppercase small">Описание</h6>
                <p class="mb-0">${nl2br(data.description) || 'Нет описания'}</p>
            </div>
            <hr>
            <h6 class="mb-3">Участники (${data.participants ? data.participants.length : 0})</h6>
            ${participantsHtml}
        `;
    } catch (err) {
        console.error('Ошибка загрузки деталей события:', err);
        modalBody.innerHTML = `<div class="alert alert-danger">Не удалось загрузить данные: ${err.message}</div>`;
        modalTitle.textContent = 'Ошибка';
    }
}

// ====== 2. ПОИСК ПО СОБЫТИЯМ ======
const searchInput = document.getElementById('eventSearch');
const dateFromInput = document.getElementById('dateFrom');
const dateToInput = document.getElementById('dateTo');
const eventCards = document.querySelectorAll('.event-card');
const noResults = document.getElementById('noResults');
const searchStats = document.getElementById('searchStats');
const eventsContainer = document.getElementById('eventsContainer');

let searchTimeout;

function performSearch() {
    const searchTerm = searchInput.value.toLowerCase().trim();
    const dateFrom = dateFromInput.value;
    const dateTo = dateToInput.value;
    
    let visibleCount = 0;
    
    eventCards.forEach(card => {
        const eventType = card.dataset.eventType || '';
        const eventPlace = card.dataset.eventPlace || '';
        const eventDate = card.dataset.eventDate || '';
        const eventDescription = card.dataset.eventDescription || '';
        
        const matchesSearch = !searchTerm || 
            eventType.includes(searchTerm) || 
            eventPlace.includes(searchTerm) || 
            eventDescription.includes(searchTerm);
        
        let matchesDateRange = true;
        if (eventDate) {
            const eventDateObj = parseDate(eventDate);
            if (eventDateObj) {
                if (dateFrom) {
                    const fromDate = new Date(dateFrom);
                    if (eventDateObj < fromDate) matchesDateRange = false;
                }
                if (dateTo) {
                    const toDate = new Date(dateTo);
                    if (eventDateObj > toDate) matchesDateRange = false;
                }
            }
        }
        
        if (matchesSearch && matchesDateRange) {
            card.classList.remove('hidden');
            visibleCount++;
        } else {
            card.classList.add('hidden');
        }
    });
    
    if (searchTerm || dateFrom || dateTo) {
        searchStats.textContent = `Найдено: ${visibleCount} из ${eventCards.length} событий`;
    } else {
        searchStats.textContent = '';
    }
    
    if (visibleCount === 0 && (searchTerm || dateFrom || dateTo)) {
        noResults.classList.add('show');
        eventsContainer.style.display = 'none';
    } else {
        noResults.classList.remove('show');
        eventsContainer.style.display = 'block';
    }
}

function parseDate(dateStr) {
    if (!dateStr) return null;
    dateStr = dateStr.trim();
    if (/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) return new Date(dateStr);
    if (/^\d{2}\.\d{2}\.\d{4}$/.test(dateStr)) {
        const parts = dateStr.split('.');
        return new Date(parts[2], parts[1] - 1, parts[0]);
    }
    if (/^\d{4}$/.test(dateStr)) return new Date(dateStr, 0, 1);
    if (/^\d{2}\.\d{4}$/.test(dateStr)) {
        const parts = dateStr.split('.');
        return new Date(parts[1], parts[0] - 1, 1);
    }
    const date = new Date(dateStr);
    return isNaN(date.getTime()) ? null : date;
}

function clearSearch() {
    searchInput.value = '';
    dateFromInput.value = '';
    dateToInput.value = '';
    performSearch();
}

searchInput.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(performSearch, 300);
});

dateFromInput.addEventListener('change', performSearch);
dateToInput.addEventListener('change', performSearch);

document.addEventListener('DOMContentLoaded', function() {
    performSearch();
});

// ====== 3. ЛОГИКА РЕДАКТИРОВАНИЯ СОБЫТИЯ ======
let currentEventData = null; // Храним данные текущего открытого события
let editTempParticipants = []; // Участники для формы редактирования

// Модифицируем существующую функцию loadEventDetails, чтобы сохранять данные
const originalLoadEventDetails = window.loadEventDetails;
window.loadEventDetails = async function(eventId) {
    await originalLoadEventDetails(eventId); // Выполняем оригинальную загрузку
    
    // После загрузки сохраняем данные глобально (небольшая задержка, чтобы DOM обновился)
    setTimeout(async () => {
        try {
            const res = await fetch(`api/event.php?action=get&id=${eventId}`, { cache: 'no-store' });
            currentEventData = await res.json();
        } catch (e) {
            console.error('Не удалось сохранить данные для редактирования', e);
        }
    }, 100);
};

window.openEditEventModal = async function() {
    if (!currentEventData) {
        alert('Данные события не загружены');
        return;
    }

    // Заполняем основные поля
    document.getElementById('edit_event_id').value = currentEventData.id;
    document.getElementById('edit_event_type').value = currentEventData.event_type || '';
    document.getElementById('edit_event_date').value = currentEventData.event_date || '';
    document.getElementById('edit_event_place').value = currentEventData.event_place || '';
    document.getElementById('edit_event_description').value = currentEventData.description || '';

    // Очищаем и заполняем список участников
    editTempParticipants = [];
    if (currentEventData.participants && currentEventData.participants.length > 0) {
        currentEventData.participants.forEach(p => {
            editTempParticipants.push({
                id: p.person_id,
                name: `${p.last_name || ''} ${p.first_name || ''} ${p.middle_name || ''}`.trim() || 'Неизвестно',
                role: p.role || ''
            });
        });
    }
    renderEditTempParticipants();

    // Загружаем список всех людей в селект
    try {
        const resP = await fetch('api/tree_data.php', { cache: 'no-store' });
        const dataP = await resP.json();
        const selP = document.getElementById('edit_event_person_select');
        selP.innerHTML = '<option value="">Выберите человека...</option>';
        dataP.nodes.forEach(n => {
            const opt = document.createElement('option');
            opt.value = n.data.id.replace('p', '');
            opt.textContent = n.data.full_name;
            selP.appendChild(opt);
        });
    } catch (err) {
        console.error('Ошибка загрузки списка людей:', err);
    }

    // Открываем модалку
    new bootstrap.Modal(document.getElementById('editEventModal')).show();
};

window.addPersonToEditEventTemp = function() {
    const sel = document.getElementById('edit_event_person_select');
    const role = document.getElementById('edit_event_person_role').value;
    
    if (!sel.value) {
        alert('Выберите человека');
        return;
    }
    
    // Проверка на дубликаты
    if (editTempParticipants.some(p => p.id == sel.value)) {
        alert('Этот человек уже добавлен в список участников');
        return;
    }

    const name = sel.options[sel.selectedIndex].text;
    editTempParticipants.push({
        id: parseInt(sel.value),
        name: name,
        role: role
    });
    
    renderEditTempParticipants();
    sel.value = '';
    document.getElementById('edit_event_person_role').value = '';
};

window.removeEditParticipant = function(i) {
    editTempParticipants.splice(i, 1);
    renderEditTempParticipants();
};

function renderEditTempParticipants() {
    const ul = document.getElementById('edit_temp_event_participants');
    ul.innerHTML = editTempParticipants.map((p, i) => `
        <li class="list-group-item d-flex justify-content-between align-items-center">
            <span>${p.name} ${p.role ? '<small class="text-muted">(' + p.role + ')</small>' : ''}</span>
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeEditParticipant(${i})">✕</button>
        </li>
    `).join('');
    document.getElementById('edit_participants_json').value = JSON.stringify(editTempParticipants);
}

// Обработка отправки формы редактирования
document.getElementById('editEventForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    const btn = e.target.querySelector('button[type="submit"]');
    
    btn.disabled = true;
    btn.textContent = 'Сохранение...';
    
    try {
        const res = await fetch('api/event.php', { method: 'POST', body: fd });
        const data = await res.json();
        
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('editEventModal')).hide();
            bootstrap.Modal.getInstance(document.getElementById('eventDetailsModal')).hide();
            
            // Перезагружаем страницу, чтобы обновить карточку в списке
            window.location.reload();
        } else {
            alert('Ошибка: ' + (data.error || 'Не удалось сохранить событие'));
        }
    } catch (err) {
        alert('Ошибка сети: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Сохранить изменения';
    }
});
</script>
</body>
</html>