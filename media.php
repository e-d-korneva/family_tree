<?php
require_once 'includes/functions.php';

// Получаем статистику для шапки
$stats = $pdo->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN file_type = 'photo' THEN 1 ELSE 0 END) as photos,
        SUM(CASE WHEN file_type = 'video' THEN 1 ELSE 0 END) as videos,
        SUM(CASE WHEN file_type = 'audio' THEN 1 ELSE 0 END) as audios
    FROM media
")->fetch();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Медиатека — Семейное древо</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        /* ====== ГАЛЕРЕЯ ====== */
        .media-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 1.2rem;
        }
        .media-card {
            background: #FAFAF7;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 3px 12px rgba(168, 181, 160, 0.15);
            transition: all 0.25s ease;
            cursor: pointer;
            position: relative;
        }
        .media-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(168, 181, 160, 0.3);
        }
        .media-thumb {
            width: 100%;
            height: 180px;
            background: linear-gradient(135deg, #C7D3C1 0%, #A8B5A0 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 50px;
            color: #FAFAF7;
            position: relative;
            overflow: hidden;
        }
        .media-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .media-type-badge {
            position: absolute;
            top: 8px;
            left: 8px;
            background: rgba(45, 58, 46, 0.75);
            color: #FAFAF7;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
            backdrop-filter: blur(4px);
        }
        .media-body {
            padding: 0.9rem 1rem;
        }
        .media-caption {
            font-size: 0.9rem;
            color: #2d3a2e;
            margin-bottom: 0.4rem;
            min-height: 1.4em;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .meta-info {
            font-size: 0.78rem;
            color: #8a9a82;
        }
        .meta-info a {
            color: #A8B5A0;
            text-decoration: none;
        }
        .meta-info a:hover {
            text-decoration: underline;
        }

        /* ====== ПЛЕЙСХОЛДЕРЫ ====== */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #8a9a82;
        }
        .empty-state-icon {
            font-size: 80px;
            opacity: 0.4;
            margin-bottom: 1rem;
        }

        /* ====== МОДАЛКА ПРОСМОТРА ====== */
        .media-viewer-image {
            max-width: 100%;
            max-height: 70vh;
            display: block;
            margin: 0 auto;
            border-radius: 8px;
        }
        .media-viewer-video,
        .media-viewer-audio {
            width: 100%;
            max-height: 70vh;
            border-radius: 8px;
        }

        /* ====== ФИЛЬТРЫ ====== */
        .filter-bar {
            background: #FAFAF7;
            border-radius: 14px;
            padding: 1.2rem;
            box-shadow: 0 3px 12px rgba(168, 181, 160, 0.12);
            margin-bottom: 1.5rem;
        }
        .filter-chip {
            display: inline-block;
            padding: 0.4rem 1rem;
            border-radius: 20px;
            background: #F2F5EF;
            color: #2d3a2e;
            border: 1px solid #C7D3C1;
            cursor: pointer;
            font-size: 0.88rem;
            transition: all 0.2s ease;
            margin-right: 0.4rem;
            margin-bottom: 0.4rem;
        }
        .filter-chip:hover {
            background: #E8EDE5;
        }
        .filter-chip.active {
            background: #A8B5A0;
            color: #FAFAF7;
            border-color: #A8B5A0;
        }

        /* ====== ЛАЙТБОКС ====== */
        .lightbox {
            position: fixed;
            inset: 0;
            background: rgba(45, 58, 46, 0.92);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            backdrop-filter: blur(6px);
        }
        .lightbox.show {
            display: flex;
        }
        .lightbox-content {
            max-width: 90vw;
            max-height: 90vh;
            position: relative;
        }
        .lightbox-content img,
        .lightbox-content video {
            max-width: 90vw;
            max-height: 85vh;
            border-radius: 8px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        }
        .lightbox-close {
            position: absolute;
            top: -40px;
            right: 0;
            background: transparent;
            border: none;
            color: #FAFAF7;
            font-size: 2rem;
            cursor: pointer;
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
            <a href="event.php" class="btn btn-outline-light btn-sm">События</a>
            <a href="media.php" class="btn btn-outline-light btn-sm">Медиатека</a>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <!-- Заголовок со статистикой -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h2 class="mb-1">Медиатека</h2>
            <p class="text-muted mb-0">
                Всего файлов: <strong><?= (int)$stats['total'] ?></strong> · 
                Фото: <strong><?= (int)$stats['photos'] ?></strong> · 
                Видео: <strong><?= (int)$stats['videos'] ?></strong> · 
                Аудио: <strong><?= (int)$stats['audios'] ?></strong>
            </p>
        </div>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#uploadMediaModal">
            + Загрузить файл
        </button>
    </div>

    <!-- Фильтры -->
    <div class="filter-bar">
        <div class="row g-2 align-items-center">
            <div class="col-md-4">
                <input type="text" id="mediaSearch" class="form-control" 
                       placeholder="🔍 Поиск по подписи, имени человека...">
            </div>
            <div class="col-md-8">
                <span class="filter-chip active" data-type="all">Все</span>
                <span class="filter-chip" data-type="photo">Фото</span>
                <span class="filter-chip" data-type="video">Видео</span>
                <span class="filter-chip" data-type="audio">Аудио</span>
                <span class="filter-chip" data-type="document"> Документы</span>
                <span class="filter-chip" data-type="no-caption">Без подписи</span>
            </div>
        </div>
    </div>

    <!-- Галерея -->
    <div id="mediaGallery" class="media-grid">
        <!-- Заполняется через JS -->
    </div>

    <!-- Индикатор загрузки -->
    <div id="loadingState" class="empty-state">
        <div class="spinner-border text-secondary" role="status"></div>
        <p class="mt-3">Загрузка медиатеки...</p>
    </div>

    <!-- Пустое состояние -->
    <div id="emptyState" class="empty-state" style="display:none">
        <div class="empty-state-icon">📷</div>
        <h4>Медиатека пуста</h4>
        <p class="text-muted">Загрузите первое фото, видео или аудио, чтобы начать</p>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#uploadMediaModal">
            + Загрузить файл
        </button>
    </div>
</div>

<!-- ========== МОДАЛКА ПРОСМОТРА МЕДИА ========== -->
<div class="modal fade" id="viewMediaModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="vm_title">—</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="vm_content" class="text-center mb-3">
                    <!-- Сюда вставляется фото/видео/аудио -->
                </div>
                <hr>
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label"><strong>Примечание / подпись</strong></label>
                        <textarea id="vm_caption" class="form-control" rows="3" 
                                  placeholder="Добавьте описание, историю, дату, место..."></textarea>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label mb-0"><strong>Информация</strong></label>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="editMediaInfo()">Редактировать</button>
                        </div>
                        <div class="border rounded p-2 bg-light small" id="vm_info_display">
                            <div><strong>Тип:</strong> <span id="vm_type">—</span></div>
                            <div><strong>Загружено:</strong> <span id="vm_date">—</span></div>
                            <div><strong>Человек:</strong> <span id="vm_person">—</span></div>
                            <div><strong>Событие:</strong> <span id="vm_event">—</span></div>
                        </div>
                        
                        <!-- Форма редактирования (скрыта по умолчанию) -->
                        <div class="border rounded p-2 mt-2 small" id="vm_info_edit" style="display:none">
                            <div class="mb-2">
                                <label class="form-label small">Привязать к человеку:</label>
                                <select id="vm_edit_person" class="form-select form-select-sm">
                                    <option value="">— Не привязывать —</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small">Привязать к событию:</label>
                                <select id="vm_edit_event" class="form-select form-select-sm">
                                    <option value="">— Не привязывать —</option>
                                </select>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-sm btn-primary flex-grow-1" onclick="saveMediaInfo()">Сохранить</button>
                                <button type="button" class="btn btn-sm btn-secondary" onclick="cancelEditMediaInfo()"></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-danger me-auto" onclick="deleteCurrentMedia()">Удалить</button>
                <button class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                <button class="btn btn-primary" onclick="saveCaption()">Сохранить примечание</button>
            </div>
        </div>
    </div>
</div>

<!-- ========== МОДАЛКА ЗАГРУЗКИ ========== -->
<div class="modal fade" id="uploadMediaModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" id="uploadForm" enctype="multipart/form-data">
            <div class="modal-header">
                <h5 class="modal-title">Загрузить медиафайл</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="upload_general">
                
                <div class="mb-3">
                    <label class="form-label">Файлы *</label>
                    <input type="file" name="files[]" class="form-control" multiple 
                           accept="image/*,video/*,audio/*,.pdf,.doc,.docx" required>
                    <small class="text-muted">Можно выбрать несколько файлов. Фото, видео, аудио, документы.</small>
                </div>

                <div class="mb-3">
                    <label class="form-label">Общее примечание</label>
                    <textarea name="caption" class="form-control" rows="2" 
                              placeholder="Описание, дата, место, история..."></textarea>
                    <small class="text-muted">Можно будет отредактировать для каждого файла отдельно</small>
                </div>

                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label">Привязать к человеку (необязательно)</label>
                        <select name="person_id" class="form-select" id="upload_person">
                            <option value="">— Не привязывать —</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Привязать к событию (необязательно)</label>
                        <select name="event_id" class="form-select" id="upload_event">
                            <option value="">— Не привязывать —</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary" id="uploadBtn">Загрузить</button>
            </div>
        </form>
    </div>
</div>

<!-- Лайтбокс для полноэкранного просмотра -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
    <div class="lightbox-content" onclick="event.stopPropagation()">
        <button class="lightbox-close" onclick="closeLightbox()">✕</button>
        <div id="lightboxContent"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
let allMedia = [];
let currentFilter = 'all';
let currentSearch = '';
let currentMediaId = null;

// ====== ЗАГРУЗКА МЕДИА ======
async function loadMedia() {
    document.getElementById('loadingState').style.display = 'block';
    document.getElementById('emptyState').style.display = 'none';
    document.getElementById('mediaGallery').innerHTML = '';
    
    try {
        const res = await fetch('api/media_list.php', { cache: 'no-store' });
        allMedia = await res.json();
        renderMedia();
    } catch (err) {
        document.getElementById('mediaGallery').innerHTML = 
            '<div class="alert alert-danger">Ошибка загрузки: ' + err.message + '</div>';
    } finally {
        document.getElementById('loadingState').style.display = 'none';
    }
}

// ====== РЕНДЕР ГАЛЕРЕИ ======
function renderMedia() {
    const gallery = document.getElementById('mediaGallery');
    
    const filtered = allMedia.filter(m => {
        // Фильтр по типу
        if (currentFilter === 'no-caption') {
            if (m.caption && m.caption.trim()) return false;
        } else if (currentFilter !== 'all' && m.file_type !== currentFilter) {
            return false;
        }
        
        // Поиск
        if (currentSearch) {
            const q = currentSearch.toLowerCase();
            const text = [
                m.caption || '',
                m.person_name || '',
                m.event_type || '',
                m.file_name || ''
            ].join(' ').toLowerCase();
            if (!text.includes(q)) return false;
        }
        
        return true;
    });
    
    if (filtered.length === 0) {
        if (allMedia.length === 0) {
            document.getElementById('emptyState').style.display = 'block';
        } else {
            gallery.innerHTML = '<div class="empty-state"><h4>Ничего не найдено</h4><p class="text-muted">Попробуйте изменить фильтры</p></div>';
        }
        return;
    }
    
    gallery.innerHTML = filtered.map(m => {
        const typeIcons = { photo: '📷', video: '🎬', audio: '🎵', document: '📄' };
        const icon = typeIcons[m.file_type] || '📎';
        
        let thumbHtml;
        if (m.file_type === 'photo' && m.thumb_path) {
            thumbHtml = `<img src="${m.thumb_path}" alt="${escapeHtml(m.caption || '')}" loading="lazy">`;
        } else {
            thumbHtml = icon;
        }
        
        const typeLabels = { photo: 'Фото', video: 'Видео', audio: 'Аудио', document: 'Документ' };
        
        return `
            <div class="media-card" onclick="viewMedia(${m.id})">
                <div class="media-thumb">
                    ${thumbHtml}
                    <span class="media-type-badge">${typeLabels[m.file_type] || m.file_type}</span>
                </div>
                <div class="media-body">
                    <div class="media-caption">
                        ${m.caption ? escapeHtml(m.caption) : '<em class="text-muted">Без подписи</em>'}
                    </div>
                    <div class="meta-info">
                        ${m.person_name ? ` <a href="person.php?id=${m.person_id}" onclick="event.stopPropagation()">${escapeHtml(m.person_name)}</a>` : ''}
                        ${m.person_name && m.event_type ? ' · ' : ''}
                        ${m.event_type ? ` <a href="event.php" onclick="event.stopPropagation()">${escapeHtml(m.event_type)}</a>` : ''}
                        ${!m.person_name && !m.event_type ? `<span class="text-muted">Общий файл</span>` : ''}
                        <br>
                        <small>${formatDate(m.created_at)}</small>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

// ====== ПРОСМОТР МЕДИА ======
async function viewMedia(id) {
    const m = allMedia.find(x => x.id === id);
    if (!m) return;
    currentMediaId = id;
    
    document.getElementById('vm_title').textContent = m.caption || 'Без подписи';
    document.getElementById('vm_caption').value = m.caption || '';
    document.getElementById('vm_type').textContent = { photo: 'Фото', video: 'Видео', audio: 'Аудио', document: 'Документ' }[m.file_type] || m.file_type;
    document.getElementById('vm_date').textContent = formatDate(m.created_at);
    document.getElementById('vm_person').innerHTML = m.person_name 
        ? `<a href="person.php?id=${m.person_id}">${escapeHtml(m.person_name)}</a>` 
        : '—';
    document.getElementById('vm_event').innerHTML = m.event_type 
        ? `<a href="event.php">${escapeHtml(m.event_type)}</a>` 
        : '—';
    
    const content = document.getElementById('vm_content');
    if (m.file_type === 'photo') {
        content.innerHTML = `<img src="${m.file_path}" class="media-viewer-image" 
            onclick="openLightbox('${m.file_path}', 'image')">`;
    } else if (m.file_type === 'video') {
        content.innerHTML = `<video src="${m.file_path}" class="media-viewer-video" controls></video>`;
    } else if (m.file_type === 'audio') {
        content.innerHTML = `<div class="p-4 bg-light rounded">
            <div style="font-size:60px" class="mb-3">🎵</div>
            <audio src="${m.file_path}" class="media-viewer-audio" controls></audio>
        </div>`;
    } else {
        content.innerHTML = `<div class="p-4 bg-light rounded">
            <div style="font-size:60px" class="mb-3"></div>
            <a href="${m.file_path}" target="_blank" class="btn btn-primary">📥 Открыть файл</a>
        </div>`;
    }
    
    new bootstrap.Modal(document.getElementById('viewMediaModal')).show();
}

// ====== СОХРАНЕНИЕ ПРИМЕЧАНИЯ ======
async function saveCaption() {
    const caption = document.getElementById('vm_caption').value;
    const btn = event.target;
    btn.disabled = true;
    btn.textContent = 'Сохранение...';
    
    try {
        const fd = new FormData();
        fd.append('action', 'update_caption');
        fd.append('id', currentMediaId);
        fd.append('caption', caption);
        
        const res = await fetch('api/media.php', { method: 'POST', body: fd });
        const data = await res.json();
        
        if (data.success) {
            // Обновляем в кэше
            const m = allMedia.find(x => x.id === currentMediaId);
            if (m) m.caption = caption;
            renderMedia();
            document.getElementById('vm_title').textContent = caption || 'Без подписи';
            showToast('Примечание сохранено');
        } else {
            alert('Ошибка: ' + (data.error || 'Не удалось сохранить'));
        }
    } catch (err) {
        alert('Ошибка сети: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Сохранить примечание';
    }
}

// ====== УДАЛЕНИЕ МЕДИА ======
async function deleteCurrentMedia() {
    if (!confirm('Удалить этот файл? Действие необратимо.')) return;
    
    try {
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', currentMediaId);
        
        const res = await fetch('api/media.php', { method: 'POST', body: fd });
        const data = await res.json();
        
        if (data.success) {
            allMedia = allMedia.filter(m => m.id !== currentMediaId);
            bootstrap.Modal.getInstance(document.getElementById('viewMediaModal')).hide();
            renderMedia();
            showToast('Файл удалён');
        } else {
            alert('Ошибка: ' + (data.error || 'Не удалось удалить'));
        }
    } catch (err) {
        alert('Ошибка: ' + err.message);
    }
}

// ====== ЛАЙТБОКС ======
function openLightbox(src, type) {
    const content = document.getElementById('lightboxContent');
    if (type === 'image') {
        content.innerHTML = `<img src="${src}" style="max-width:90vw;max-height:85vh;border-radius:8px">`;
    }
    document.getElementById('lightbox').classList.add('show');
}

function closeLightbox() {
    document.getElementById('lightbox').classList.remove('show');
    document.getElementById('lightboxContent').innerHTML = '';
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeLightbox();
});

// ====== ФИЛЬТРЫ ======
document.querySelectorAll('.filter-chip').forEach(chip => {
    chip.addEventListener('click', function() {
        document.querySelectorAll('.filter-chip').forEach(c => c.classList.remove('active'));
        this.classList.add('active');
        currentFilter = this.dataset.type;
        renderMedia();
    });
});

// ====== ПОИСК ======
let searchTimeout;
document.getElementById('mediaSearch').addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const q = this.value.trim();
    searchTimeout = setTimeout(() => {
        currentSearch = q;
        renderMedia();
    }, 300);
});

// ====== ЗАГРУЗКА ФАЙЛОВ ======
document.getElementById('uploadForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('uploadBtn');
    btn.disabled = true;
    btn.textContent = 'Загрузка...';
    
    const fd = new FormData(this);
    
    // ВАЖНО: Явно устанавливаем action, чтобы он точно передался
    fd.set('action', 'upload_general');
    
    // Для отладки - выводим что отправляется
    console.log('Отправляемые данные:');
    for (let [key, value] of fd.entries()) {
        console.log(key, value);
    }
    
    try {
        const res = await fetch('api/media.php', { 
            method: 'POST', 
            body: fd,
            // НЕ устанавливаем Content-Type - браузер сам установит multipart/form-data с boundary
        });
        
        // Проверяем тип ответа
        const contentType = res.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const text = await res.text();
            console.error('Сервер вернул не JSON:', text);
            throw new Error('Сервер вернул некорректный ответ');
        }
        
        const data = await res.json();
        
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('uploadMediaModal')).hide();
            this.reset();
            await loadMedia();
            showToast('Файлы загружены');
        } else {
            alert('Ошибка: ' + (data.error || 'Не удалось загрузить'));
        }
    } catch (err) {
        console.error('Ошибка:', err);
        alert('Ошибка сети: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.textContent = '📤 Загрузить';
    }
});

// Заполнение селектов при открытии модалки загрузки
document.getElementById('uploadMediaModal').addEventListener('shown.bs.modal', async function() {
    try {
        // Люди
        const resP = await fetch('api/tree_data.php', { cache: 'no-store' });
        const dataP = await resP.json();
        const selP = document.getElementById('upload_person');
        selP.innerHTML = '<option value="">— Не привязывать —</option>';
        dataP.nodes.forEach(n => {
            const opt = document.createElement('option');
            opt.value = n.data.id.replace('p', '');
            opt.textContent = n.data.full_name;
            selP.appendChild(opt);
        });
        
        // События (если есть API)
    try {
        const resE = await fetch('api/event.php?action=list', { cache: 'no-store' });
        
        // Проверяем, что ответ успешный и это JSON
        if (!resE.ok) {
            console.warn('API событий недоступен (статус ' + resE.status + ')');
            throw new Error('HTTP ' + resE.status);
        }
        
        const contentType = resE.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            console.warn('API событий вернул не-JSON ответ');
            throw new Error('Не JSON');
        }
        
        const events = await resE.json();
        const selE = document.getElementById('upload_event');
        if (selE) {
            selE.innerHTML = '<option value="">— Не привязывать —</option>';
            if (Array.isArray(events)) {
                events.forEach(ev => {
                    const opt = document.createElement('option');
                    opt.value = ev.id;
                    opt.textContent = (ev.event_type || 'Событие') + (ev.event_date ? ' (' + ev.event_date + ')' : '');
                    selE.appendChild(opt);
                });
            }
        }
    } catch (e) {
        console.warn('Не удалось загрузить список событий:', e.message);
        // События не обязательны — просто оставляем селект пустым
    }
    } catch (err) {
        console.error('Ошибка загрузки списков:', err);
    }
});

// ====== УТИЛИТЫ ======
function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, m => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[m]));
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    try {
        const d = new Date(dateStr);
        return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
    } catch (e) {
        return dateStr;
    }
}

function showToast(message) {
    const toast = document.createElement('div');
    toast.style.cssText = `
        position: fixed; bottom: 30px; right: 30px;
        background: #A8B5A0; color: #FAFAF7;
        padding: 12px 24px; border-radius: 12px;
        box-shadow: 0 8px 25px rgba(0,0,0,0.2);
        z-index: 3000; font-weight: 500;
        animation: slideUp 0.3s ease;
    `;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s';
        setTimeout(() => toast.remove(), 300);
    }, 2500);
}

// ====== ИНИЦИАЛИЗАЦИЯ ======
document.addEventListener('DOMContentLoaded', loadMedia);

// ====== РЕДАКТИРОВАНИЕ ИНФОРМАЦИИ О МЕДИА ======
let mediaEditOriginal = { person_id: null, event_id: null };

async function editMediaInfo() {
    const m = allMedia.find(x => x.id === currentMediaId);
    if (!m) return;
    
    // Сохраняем оригинальные значения
    mediaEditOriginal = {
        person_id: m.person_id,
        event_id: m.event_id
    };
    
    // Показываем форму редактирования
    document.getElementById('vm_info_display').style.display = 'none';
    document.getElementById('vm_info_edit').style.display = 'block';
    
    // Заполняем селекты
    try {
        // Загружаем список людей
        const resP = await fetch('api/tree_data.php', { cache: 'no-store' });
        const dataP = await resP.json();
        const selP = document.getElementById('vm_edit_person');
        selP.innerHTML = '<option value="">— Не привязывать —</option>';
        dataP.nodes.forEach(n => {
            const opt = document.createElement('option');
            opt.value = n.data.id.replace('p', '');
            opt.textContent = n.data.full_name;
            if (m.person_id && opt.value == m.person_id) opt.selected = true;
            selP.appendChild(opt);
        });
        
        // Загружаем список событий
        try {
            const resE = await fetch('api/event.php?action=list', { cache: 'no-store' });
            if (resE.ok) {
                const events = await resE.json();
                const selE = document.getElementById('vm_edit_event');
                selE.innerHTML = '<option value="">— Не привязывать —</option>';
                if (Array.isArray(events)) {
                    events.forEach(ev => {
                        const opt = document.createElement('option');
                        opt.value = ev.id;
                        opt.textContent = (ev.event_type || 'Событие') + (ev.event_date ? ' (' + ev.event_date + ')' : '');
                        if (m.event_id && opt.value == m.event_id) opt.selected = true;
                        selE.appendChild(opt);
                    });
                }
            }
        } catch (e) {
            console.warn('Не удалось загрузить список событий:', e.message);
        }
    } catch (err) {
        console.error('Ошибка загрузки списков:', err);
        alert('Не удалось загрузить списки для редактирования');
        cancelEditMediaInfo();
    }
}

function cancelEditMediaInfo() {
    document.getElementById('vm_info_display').style.display = 'block';
    document.getElementById('vm_info_edit').style.display = 'none';
}

async function saveMediaInfo() {
    const personId = document.getElementById('vm_edit_person').value || '';
    const eventId = document.getElementById('vm_edit_event').value || '';
    
    const btn = event.target;
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Сохранение...';
    
    try {
        const fd = new FormData();
        fd.append('action', 'update_media_info');
        fd.append('id', currentMediaId);
        fd.append('person_id', personId);
        fd.append('event_id', eventId);
        
        const res = await fetch('api/media.php', { 
            method: 'POST', 
            body: fd,
            headers: {
                'Accept': 'application/json'
            }
        });
        
        // Проверяем тип ответа
        const contentType = res.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const text = await res.text();
            console.error('Сервер вернул не JSON:', text);
            throw new Error('Сервер вернул некорректный ответ');
        }
        
        const data = await res.json();
        
        if (data.success) {
            // Обновляем в кэше
            const m = allMedia.find(x => x.id === currentMediaId);
            if (m) {
                m.person_id = personId ? parseInt(personId) : null;
                m.event_id = eventId ? parseInt(eventId) : null;
                
                // Обновляем имена для отображения
                const selP = document.getElementById('vm_edit_person');
                const selE = document.getElementById('vm_edit_event');
                m.person_name = (personId && selP.options[selP.selectedIndex]) ? selP.options[selP.selectedIndex].text : null;
                m.event_type = (eventId && selE.options[selE.selectedIndex]) ? selE.options[selE.selectedIndex].text : null;
            }
            
            // Закрываем форму редактирования
            cancelEditMediaInfo();
            
            // Перерисовываем галерею
            renderMedia();
            
            // Обновляем модальное окно просмотра
            await viewMedia(currentMediaId);
            
            showToast('✅ Информация обновлена');
        } else {
            alert('Ошибка: ' + (data.error || 'Не удалось сохранить'));
        }
    } catch (err) {
        console.error('Ошибка сохранения:', err);
        alert('Ошибка сети: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.textContent = originalText;
    }
}
</script>
</body>
</html>