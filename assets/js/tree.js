document.addEventListener('DOMContentLoaded', function () {
    let cy;
    let selectedNodes = [];
    let currentPersonId = null;
    let allPersonsCache = [];

    // ====== 1. ИНИЦИАЛИЗАЦИЯ ДЕРЕВА ======
    fetch('api/tree_data.php', {
        cache: 'no-store',
        headers: { 'Cache-Control': 'no-cache, no-store, must-revalidate', 'Pragma': 'no-cache', 'Expires': '0' }
    })
    .then(r => {
        if (!r.ok) throw new Error('Ошибка сети: ' + r.status);
        return r.json();
    })
    .then(data => {
        if (!data.nodes || !data.edges) throw new Error('Неверный формат данных от сервера');

        cy = cytoscape({
            container: document.getElementById('tree'),
            elements: [...data.nodes, ...data.edges],
            style: [
                {
                    selector: 'node',
                    style: {
                        'label': function (ele) { return ele.data('label') || ''; },
                        'text-wrap': 'wrap', 'text-max-width': '170px', 'text-valign': 'center', 'text-halign': 'center',
                        'font-size': '11px', 'font-weight': '500',
                        'background-color': function (ele) { return ele.data('status') === 'alive' ? '#F2F5EF' : '#FAFAF7'; },
                        'border-width': 3,
                        'border-color': function (ele) { return ele.data('status') === 'alive' ? '#A8B5A0' : '#C7D3C1'; },
                        'color': '#2d3a2e', 'shape': 'round-rectangle', 'width': 190, 'height': 85, 'padding': '8px'
                    }
                },
                {
                    selector: 'edge',
                    style: {
                        'width': 2,
                        'line-color': function (ele) { return ele.data('type') === 'spouse' ? '#C7D3C1' : '#A8B5A0'; },
                        'line-style': function (ele) { return ele.data('type') === 'spouse' ? 'dashed' : 'solid'; },
                        'target-arrow-shape': function (ele) { return ele.data('type') === 'parent' ? 'triangle' : 'none'; },
                        'curve-style': 'bezier'
                    }
                },
                { selector: 'node:selected', style: { 'border-color': '#8a9a82', 'border-width': 5 } },
                { selector: '.highlighted', style: { 'border-color': '#8a9a82', 'border-width': 5 } },
                { selector: 'edge.highlighted', style: { 'width': 4, 'line-color': '#8a9a82', 'line-style': 'solid' } }
            ],
            layout: { name: 'breadthfirst', directed: true, padding: 30, spacingFactor: 1.2, nodeDimensionsIncludeLabels: true, avoidOverlap: true, animate: true, animationDuration: 500 },
            userZoomingEnabled: true, userPanningEnabled: true
        });

        cy.on('tap', 'node', function (evt) {
            const id = evt.target.id().substring(1);
            if (document.getElementById('kinshipBtn').dataset.mode === 'on') {
                evt.target.toggleClass('highlighted');
                selectedNodes.push(id);
                if (selectedNodes.length === 2) { showKinship(selectedNodes[0], selectedNodes[1]); }
                return;
            }
            showPersonModal(id);
        });

        cy.on('tap', function (evt) {
            if (evt.target === cy) { cy.elements().removeClass('highlighted'); selectedNodes = []; }
        });
    })
    .catch(err => {
        console.error('Критическая ошибка:', err);
        document.getElementById('tree').innerHTML = '<div style="padding: 50px; text-align: center; color: red;"><h3>Ошибка загрузки дерева</h3><p>' + err.message + '</p></div>';
    });

    // ====== 2. ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ======
    async function loadAllPersons() {
        const res = await fetch('api/tree_data.php', { cache: 'no-store' });
        const data = await res.json();
        allPersonsCache = data.nodes.map(n => ({ id: n.data.id.substring(1), label: n.data.label, full_name: n.data.full_name }));
    }

    function fillSelect(selectId, excludeId = null) {
        const sel = document.getElementById(selectId);
        if (!sel) return;
        sel.innerHTML = '';
        allPersonsCache.forEach(p => {
            if (p.id == excludeId) return;
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = p.full_name;
            sel.appendChild(opt);
        });
    }

    async function openAddModal() {
        await loadAllPersons();
        document.getElementById('addModalTitle').textContent = 'Добавить человека';
        document.getElementById('form_action').value = 'create';
        document.getElementById('form_id').value = '';
        document.getElementById('personForm').reset();
        document.getElementById('form_action').value = 'create'; // restore after reset
        fillSelect('sel_parents'); fillSelect('sel_spouses'); fillSelect('sel_children');
    }

    async function openEditModal(personId) {
        await loadAllPersons();
        const res = await fetch(`api/person.php?action=get&id=${personId}`, { cache: 'no-store' });
        const p = await res.json();

        document.getElementById('addModalTitle').textContent = 'Редактировать: ' + p.full_name;
        document.getElementById('form_action').value = 'update';
        document.getElementById('form_id').value = p.id;

        const f = document.getElementById('personForm');
        f.last_name.value = p.last_name; f.first_name.value = p.first_name; f.middle_name.value = p.middle_name || '';
        f.maiden_name.value = p.maiden_name || ''; f.gender.value = p.gender;
        f.birth_date.value = p.birth_date || ''; f.death_date.value = p.death_date || '';
        f.birth_place.value = p.birth_place || ''; f.bio.value = p.bio || '';
        document.getElementById('form_is_deceased').checked = !!p.is_deceased;

        fillSelect('sel_parents', p.id); fillSelect('sel_spouses', p.id); fillSelect('sel_children', p.id);
        setMultiSelect('sel_parents', p.relatives.parents.map(r => r.id));
        setMultiSelect('sel_spouses', p.relatives.spouses.map(r => r.id));
        setMultiSelect('sel_children', p.relatives.children.map(r => r.id));

        bootstrap.Modal.getInstance(document.getElementById('personModal')).hide();
        new bootstrap.Modal(document.getElementById('addPersonModal')).show();
    }

    function setMultiSelect(selectId, ids) {
        const sel = document.getElementById(selectId);
        if (!sel) return;
        Array.from(sel.options).forEach(opt => { opt.selected = ids.includes(parseInt(opt.value)); });
    }

    // ====== 3. ОБРАБОТЧИКИ ФОРМ ======
    document.getElementById('personForm').addEventListener('submit', async e => {
        e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
        const fd = new FormData(e.target);
        fd.set('ajax', '1');
        
        try {
            const res = await fetch('api/person.php', { method: 'POST', body: fd, cache: 'no-store' });
            const data = await res.json();
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('addPersonModal')).hide();
                await reloadTree();
                if (fd.get('action') === 'update') showPersonModal(data.id);
            } else { alert('Ошибка: ' + (data.error || 'Неизвестная ошибка')); }
        } catch (err) { alert('Ошибка сети: ' + err.message); }
    });

    // ====== 4. МОДАЛКА ЧЕЛОВЕКА И УДАЛЕНИЕ СВЯЗЕЙ ======
    async function showPersonModal(id) {
        currentPersonId = id;
        const res = await fetch(`api/person.php?action=get&id=${id}`, { cache: 'no-store' });
        const p = await res.json();

        document.getElementById('pm_name').textContent = p.full_name;
        document.getElementById('pm_fullname').textContent = p.full_name;
        document.getElementById('pm_maiden').textContent = p.maiden_name || '—';
        document.getElementById('pm_dates').textContent = p.life_dates;
        document.getElementById('pm_gender').textContent = { M: 'Мужской', F: 'Женский', U: 'Неизвестно' }[p.gender];
        document.getElementById('pm_birthplace').textContent = p.birth_place || '—';
        document.getElementById('pm_deathplace').textContent = p.death_place || '—';

        // Всегда пересоздаём содержимое контейнера — это устраняет дублирование
        const photoContainer = document.getElementById('pm_photo_container');
        if (photoContainer) {
            if (p.photo) {
                photoContainer.innerHTML = `<img id="pm_photo" src="${p.photo}" class="img-fluid rounded mb-2" style="max-height:200px">`;
            } else {
                photoContainer.innerHTML = `<div id="pm_photo" class="placeholder-photo mb-2"> 👤 </div>`;
            }
        }

        const statusMap = { alive: { text: '🟢 Живой', cls: 'bg-success' }, deceased: { text: '⚫ Умерший', cls: 'bg-secondary' }, unknown: { text: '⚪ Неизвестно', cls: 'bg-warning text-dark' } };
        const st = statusMap[p.status] || statusMap.unknown;
        const badge = document.getElementById('pm_status_badge');
        badge.className = 'badge ' + st.cls; badge.textContent = st.text;

        if (p.bio) { document.getElementById('pm_bio_block').style.display = 'block'; document.getElementById('pm_bio').innerHTML = p.bio.replace(/\n/g, '<br>'); } 
        else { document.getElementById('pm_bio_block').style.display = 'none'; }

        renderRelList('pm_parents', p.relatives.parents);
        renderRelList('pm_spouses', p.relatives.spouses, r => r.marriage_date ? ` (брак: ${r.marriage_date})` : '');
        renderRelList('pm_children', p.relatives.children);
        renderRelList('pm_siblings', p.relatives.siblings);

        document.getElementById('pm_upload_person_id').value = p.id;
        const gal = document.getElementById('pm_media_gallery');
        if (p.media && p.media.length) {
            gal.innerHTML = p.media.map(m => `<div class="col-md-3 mb-2"><img src="${m.thumb_path || m.file_path}" class="img-fluid rounded" style="cursor:pointer" onclick="window.open('${m.file_path}')">${m.caption ? `<small class="d-block text-muted">${m.caption}</small>` : ''}</div>`).join('');
        } else { gal.innerHTML = '<p class="text-muted">Нет медиафайлов</p>'; }

        document.querySelector('#pmTabs a[href="#tab-main"]').click();
        new bootstrap.Modal(document.getElementById('personModal')).show();
    }

    function renderRelList(containerId, list, extraFn = (r) => '') {
        const ul = document.getElementById(containerId);
        if (!list || !list.length) { ul.innerHTML = '<li class="list-group-item text-muted">Нет данных</li>'; return; }
        ul.innerHTML = list.map(r => {
            let extraText = extraFn(r);
            if (r.shared_parent === 'father') extraText += ' <span class="badge bg-info text-dark">единокровный</span>';
            if (r.shared_parent === 'mother') extraText += ' <span class="badge bg-info text-dark">единоутробный</span>';
            if (r.shared_parent === 'none') extraText += ' <span class="badge bg-secondary">сводный</span>';

            return `<li class="list-group-item d-flex justify-content-between align-items-center">
                <a href="#" onclick="showPersonModal(${r.id}); return false;">${r.first_name} ${r.last_name}</a>
                <div class="d-flex align-items-center">
                    <span class="text-muted small me-2">${extraText}</span>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteRelationship(${r.rel_id}, ${currentPersonId})" title="Удалить связь">✕</button>
                </div>
            </li>`;
        }).join('');
    }

    window.deleteRelationship = async function(relId, personId) {
        if (!confirm('Вы уверены, что хотите удалить эту родственную связь?')) return;
        const fd = new FormData(); fd.append('action', 'delete_relationship'); fd.append('rel_id', relId);
        try {
            const res = await fetch('api/person.php', { method: 'POST', body: fd, cache: 'no-store' });
            const data = await res.json();
            if (data.success) { showPersonModal(personId); await reloadTree(); } 
            else { alert('Ошибка: ' + data.error); }
        } catch (err) { alert('Ошибка сети: ' + err.message); }
    };

    window.deletePerson = async function(id) {
        if (!confirm('Удалить этого человека и все его связи?')) return;
        const fd = new FormData(); fd.append('action', 'delete'); fd.append('id', id); fd.append('ajax', '1');
        try {
            const res = await fetch('api/person.php', { method: 'POST', body: fd, cache: 'no-store' });
            const data = await res.json();
            if (data.success) { bootstrap.Modal.getInstance(document.getElementById('personModal')).hide(); await reloadTree(); } 
            else { alert('Ошибка удаления: ' + data.error); }
        } catch (err) { alert('Ошибка: ' + err.message); }
    };

    // ====== 5. ДОБАВЛЕНИЕ СВЯЗИ ======
    window.prepareAddRel = async function() {
        await loadAllPersons();
        document.getElementById('rel_from').value = currentPersonId;
        fillSelect('rel_to', currentPersonId);
        document.getElementById('rel_type').value = 'parent';
        document.getElementById('marriage_date_block').style.display = 'none';
        document.getElementById('shared_parent_block').style.display = 'block'; // Показываем для родителя/ребенка
        document.getElementById('rel_adopted').checked = false;
        document.getElementById('rel_step').checked = false;
    };

    document.getElementById('rel_type').addEventListener('change', function () {
        const type = this.value;
        document.getElementById('marriage_date_block').style.display = type === 'spouse' ? 'block' : 'none';
        // Показываем выбор типа родства для родителей и детей (для определения единоутробных/единокровных)
        document.getElementById('shared_parent_block').style.display = (type === 'parent' || type === 'child') ? 'block' : 'none';
    });

    document.getElementById('addRelForm').addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(e.target);
        if (!fd.has('is_adopted')) fd.append('is_adopted', '0');
        if (!fd.has('is_step')) fd.append('is_step', '0');
        if (!fd.has('shared_parent')) fd.append('shared_parent', 'both');

        const res = await fetch('api/person.php', { method: 'POST', body: fd, cache: 'no-store' });
        const data = await res.json();
        if (data.success) { bootstrap.Modal.getInstance(document.getElementById('addRelModal')).hide(); showPersonModal(currentPersonId); await reloadTree(); } 
        else { alert(data.error || 'Ошибка'); }
    });

    // ====== 6. ПЕРЕЗАГРУЗКА И РОДСТВО ======
    window.reloadTree = async function() {
        try {
            const res = await fetch('api/tree_data.php', { cache: 'no-store' });
            const data = await res.json();
            cy.elements().remove(); cy.add([...data.nodes, ...data.edges]);
            cy.layout({ name: 'breadthfirst', directed: true, padding: 30, spacingFactor: 1.2, avoidOverlap: true, animate: true, animationDuration: 500 }).run();
            cy.fit();
        } catch (err) { console.error('Ошибка перезагрузки дерева:', err); }
    };

    document.getElementById('kinshipBtn').addEventListener('click', function () {
        const mode = this.dataset.mode === 'on' ? 'off' : 'on';
        this.dataset.mode = mode;
        this.textContent = mode === 'on' ? 'Выберите 2 человек...' : '🔗 Показать родство';
        this.classList.toggle('btn-warning', mode === 'on'); this.classList.toggle('btn-info', mode === 'off');
        selectedNodes = []; cy.elements().removeClass('highlighted');
    });

    async function showKinship(id1, id2) {
        const res = await fetch(`api/kinship.php?p1=${id1}&p2=${id2}`, { cache: 'no-store' });
        const data = await res.json();
        const box = document.getElementById('kinshipResult');
        if (data.kinship) {
            box.innerHTML = `<strong>${data.person1}</strong> и <strong>${data.person2}</strong>: ${data.kinship}`;
            cy.elements().removeClass('highlighted');
            (data.path || []).forEach(nid => cy.getElementById('p' + nid).addClass('highlighted'));
            (data.pathEdges || []).forEach(eid => cy.getElementById('e' + eid).addClass('highlighted'));
        } else { box.innerHTML = 'Родственные связи не найдены'; }
        box.style.display = 'block'; setTimeout(() => box.style.display = 'none', 8000);
    }

    // ====== 7. ПОИСК ПО ДЕРЕВУ ======
    const searchInput = document.getElementById('globalSearch');
    const searchResults = document.getElementById('searchResults');

    if (searchInput && searchResults) {
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();
            if (query.length < 2) { searchResults.style.display = 'none'; return; }

            searchTimeout = setTimeout(async () => {
                const res = await fetch(`api/search.php?q=${encodeURIComponent(query)}`, { cache: 'no-store' });
                const data = await res.json();
                searchResults.innerHTML = '';
                
                if (data.length === 0) {
                    searchResults.innerHTML = '<div class="dropdown-item text-muted">Ничего не найдено</div>';
                } else {
                    data.forEach(person => {
                        const div = document.createElement('div');
                        div.className = 'dropdown-item'; div.style.cursor = 'pointer'; div.textContent = person.text;
                        div.addEventListener('click', () => {
                            const nodeId = 'p' + person.id;
                            const node = cy.getElementById(nodeId);
                            if (node.length > 0) {
                                node.select();
                                cy.animate({ fit: { els: node, padding: 50 }, duration: 500 });
                                searchResults.style.display = 'none';
                                searchInput.value = '';
                            }
                        });
                        searchResults.appendChild(div);
                    });
                }
                searchResults.style.display = 'block';
            }, 300);
        });

        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) { searchResults.style.display = 'none'; }
        });
    }

    // Экспорт функций в глобальную область для onclick в HTML
    window.openAddModal = openAddModal;
    window.openEditModal = openEditModal;
    window.showPersonModal = showPersonModal;
});