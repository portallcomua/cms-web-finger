/**
 * CMS Web Finder 2.3.0
 * Керування збором email, переглядом контактів і кампаніями.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const app = document.getElementById('cmsfc-app');

        if (!app || typeof CMSFC === 'undefined') {
            return;
        }

        let currentListId = 0;
        let currentPage = 1;
        let totalPages = 1;
        let scanRunning = false;
        let campaignRunning = false;
        let campaignId = 0;

        const $ = function (id) {
            return document.getElementById(id);
        };

        // Надсилає захищений AJAX-запит до WordPress.
        async function request(action, values) {
            const body = new URLSearchParams();

            body.append('action', action);
            body.append('nonce', CMSFC.nonce);

            Object.keys(values || {}).forEach(function (key) {
                const value = values[key];

                if (Array.isArray(value)) {
                    value.forEach(function (item) {
                        body.append(key + '[]', String(item));
                    });
                } else {
                    body.append(key, value == null ? '' : String(value));
                }
            });

            const response = await fetch(CMSFC.ajax, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: body.toString()
            });

            const result = await response.json();

            if (!result || !result.success) {
                const message = result && result.data
                    ? result.data
                    : 'Помилка сервера.';

                throw new Error(
                    typeof message === 'string'
                        ? message
                        : 'Не вдалося виконати операцію.'
                );
            }

            return result.data;
        }

        // Виводить звичайний текст повідомлення.
        function message(id, text) {
            if ($(id)) {
                $(id).textContent = text || '';
            }
        }

        // Встановлює ширину індикатора прогресу.
        function progress(id, value) {
            if ($(id)) {
                const n = Math.max(0, Math.min(100, Number(value) || 0));
                $(id).style.width = n + '%';
            }
        }

        // Завантажує всі збережені списки.
        async function loadLists() {
            const rows = await request('cmsfc_lists', {});
            const view = $('cmsfc-view-list');
            const campaign = $('cmsfc-campaign-list');
            const table = app.querySelector('.cmsfc-table-wrap table tbody');

            const oldView = view ? view.value : '';
            const oldCampaign = campaign ? campaign.value : '';

            if (view) {
                view.innerHTML = '<option value="">— Виберіть список —</option>';
            }

            if (campaign) {
                campaign.innerHTML = '<option value="">— Виберіть список —</option>';
            }

            if (table) {
                table.innerHTML = '';
            }

            rows.forEach(function (item) {
                const label = item.name + ' (' + item.emails_found + ' email)';

                if (view) {
                    const option = document.createElement('option');
                    option.value = item.id;
                    option.textContent = label;
                    view.appendChild(option);
                }

                if (campaign) {
                    const option = document.createElement('option');
                    option.value = item.id;
                    option.textContent = label;
                    campaign.appendChild(option);
                }

                if (table) {
                    const tr = document.createElement('tr');

                    [
                        item.name,
                        item.domain,
                        item.pages_done,
                        item.emails_found,
                        item.status
                    ].forEach(function (value) {
                        const td = document.createElement('td');
                        td.textContent = String(value);
                        tr.appendChild(td);
                    });

                    table.appendChild(tr);
                }
            });

            if (view && oldView) {
                view.value = oldView;
            }

            if (campaign && oldCampaign) {
                campaign.value = oldCampaign;
            }

            return rows;
        }

        // Завантажує одну сторінку таблиці контактів.
        async function loadContacts() {
            const listId = Number($('cmsfc-view-list')?.value || 0);

            if (!listId) {
                message('cmsfc-email-info', 'Спочатку виберіть список.');
                return;
            }

            currentListId = listId;

            const search = $('cmsfc-email-search')?.value || '';
            const filter = $('cmsfc-email-filter')?.value || 'all';

            message('cmsfc-email-info', 'Завантаження адрес…');

            try {
                const data = await request('cmsfc_contacts', {
                    list_id: listId,
                    page: currentPage,
                    search: search,
                    filter: filter
                });

                totalPages = Number(data.pages || 1);

                const tbody = $('cmsfc-email-table').querySelector('tbody');
                tbody.innerHTML = '';

                if (!data.rows || !data.rows.length) {
                    const tr = document.createElement('tr');
                    const td = document.createElement('td');

                    td.colSpan = 5;
                    td.textContent = 'За цими параметрами адрес не знайдено.';

                    tr.appendChild(td);
                    tbody.appendChild(tr);
                } else {
                    data.rows.forEach(function (item) {
                        const tr = document.createElement('tr');

                        const selectCell = document.createElement('td');
                        const checkbox = document.createElement('input');

                        checkbox.type = 'checkbox';
                        checkbox.className = 'cmsfc-contact-select';
                        checkbox.value = item.id;

                        selectCell.appendChild(checkbox);
                        tr.appendChild(selectCell);

                        const emailCell = document.createElement('td');
                        emailCell.textContent = item.email;
                        tr.appendChild(emailCell);

                        const sourceCell = document.createElement('td');
                        const link = document.createElement('a');

                        link.href = item.source_url;
                        link.target = '_blank';
                        link.rel = 'noopener noreferrer';
                        link.textContent = item.source_url;

                        sourceCell.appendChild(link);
                        tr.appendChild(sourceCell);

                        const dateCell = document.createElement('td');
                        dateCell.textContent = item.created_at;
                        tr.appendChild(dateCell);

                        const blockedCell = document.createElement('td');
                        blockedCell.textContent = Number(item.blocked)
                            ? 'У блеклисті'
                            : 'Дозволена';

                        blockedCell.className = Number(item.blocked)
                            ? 'cmsfc-blocked'
                            : 'cmsfc-allowed';

                        tr.appendChild(blockedCell);
                        tbody.appendChild(tr);
                    });
                }

                message(
                    'cmsfc-email-info',
                    'Знайдено записів: ' + data.total +
                    '. Сторінка ' + data.page + ' із ' + data.pages + '.'
                );

                message(
                    'cmsfc-email-pages',
                    'Сторінка ' + data.page + ' із ' + data.pages
                );

                $('cmsfc-select-all').checked = false;

            } catch (error) {
                message('cmsfc-email-info', 'Помилка: ' + error.message);
            }
        }

        // Запуск збору email.
        $('cmsfc-start').addEventListener('click', async function () {
            if (scanRunning) {
                return;
            }

            const domain = $('cmsfc-domain').value.trim();

            if (!domain) {
                message('cmsfc-status', 'Вкажіть домен.');
                return;
            }

            scanRunning = true;
            progress('cmsfc-progress-fill', 0);
            message('cmsfc-status', 'Створення списку…');

            try {
                const data = await request('cmsfc_start', {
                    domain: domain,
                    name: $('cmsfc-name').value.trim(),
                    max_pages: $('cmsfc-max-pages').value,
                    max_depth: $('cmsfc-max-depth').value
                });

                currentListId = Number(data.list_id);

                if (!currentListId) {
                    throw new Error('Сервер не повернув номер списку.');
                }

                await loadLists();

                $('cmsfc-view-list').value = String(currentListId);
                currentPage = 1;

                message('cmsfc-status', 'Сканування розпочато.');
                await scanStep();

            } catch (error) {
                scanRunning = false;
                message('cmsfc-status', 'Помилка: ' + error.message);
            }
        });

        // Обробляє чергові три сторінки сайту.
        async function scanStep() {
            if (!scanRunning || !currentListId) {
                return;
            }

            try {
                const data = await request('cmsfc_step', {
                    list_id: currentListId
                });

                const max = Number($('cmsfc-max-pages').value || 100);
                const percent = max > 0
                    ? Math.min(100, data.pages / max * 100)
                    : 0;

                progress('cmsfc-progress-fill', data.done ? 100 : percent);

                message(
                    'cmsfc-status',
                    data.done
                        ? 'Сканування завершено.'
                        : 'Сканування триває…'
                );

                message(
                    'cmsfc-counters',
                    'Перевірено сторінок: ' + data.pages +
                    ' · Знайдено email: ' + data.emails +
                    ' · У черзі сторінок: ' + data.remaining
                );

                await loadLists();

                if ($('cmsfc-view-list')) {
                    $('cmsfc-view-list').value = String(currentListId);
                }

                await loadContacts();

                if (data.done) {
                    scanRunning = false;
                    return;
                }

                window.setTimeout(scanStep, 500);

            } catch (error) {
                scanRunning = false;
                message('cmsfc-status', 'Помилка сканування: ' + error.message);
            }
        }

        // Пауза, продовження або зупинка сканування.
        async function controlScan(command) {
            if (!currentListId) {
                message('cmsfc-status', 'Спочатку запустіть сканування.');
                return;
            }

            try {
                await request('cmsfc_control', {
                    list_id: currentListId,
                    command: command
                });

                if (command === 'pause' || command === 'stop') {
                    scanRunning = false;
                    message(
                        'cmsfc-status',
                        command === 'pause'
                            ? 'Сканування призупинено.'
                            : 'Сканування зупинено.'
                    );
                }

                if (command === 'resume') {
                    scanRunning = true;
                    message('cmsfc-status', 'Сканування продовжено.');
                    await scanStep();
                }

                await loadLists();

            } catch (error) {
                message('cmsfc-status', 'Помилка: ' + error.message);
            }
        }

        $('cmsfc-pause').addEventListener('click', function () {
            controlScan('pause');
        });

        $('cmsfc-resume').addEventListener('click', function () {
            controlScan('resume');
        });

        $('cmsfc-stop').addEventListener('click', function () {
            controlScan('stop');
        });

        // Вибір списку та оновлення таблиці email.
        $('cmsfc-email-refresh').addEventListener('click', function () {
            currentPage = 1;
            loadContacts();
        });

        $('cmsfc-view-list').addEventListener('change', function () {
            currentPage = 1;
            loadContacts();
        });

        $('cmsfc-email-filter').addEventListener('change', function () {
            currentPage = 1;
            loadContacts();
        });

        // Пошук запускається після натискання Enter.
        $('cmsfc-email-search').addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                currentPage = 1;
                loadContacts();
            }
        });

        // Перехід між сторінками таблиці.
        $('cmsfc-email-prev').addEventListener('click', function () {
            if (currentPage > 1) {
                currentPage--;
                loadContacts();
            }
        });

        $('cmsfc-email-next').addEventListener('click', function () {
            if (currentPage < totalPages) {
                currentPage++;
                loadContacts();
            }
        });

        // Позначає або знімає позначки з усіх рядків поточної сторінки.
        $('cmsfc-select-all').addEventListener('change', function () {
            app.querySelectorAll('.cmsfc-contact-select').forEach(function (checkbox) {
                checkbox.checked = $('cmsfc-select-all').checked;
            });
        });

        // Видаляє тільки вибрані записи контактів.
        $('cmsfc-email-delete').addEventListener('click', async function () {
            const ids = Array.from(
                app.querySelectorAll('.cmsfc-contact-select:checked')
            ).map(function (checkbox) {
                return checkbox.value;
            });

            if (!ids.length) {
                message('cmsfc-email-info', 'Спочатку позначте адреси для видалення.');
                return;
            }

            if (!window.confirm('Видалити вибрані адреси зі списку?')) {
                return;
            }

            try {
                const result = await request('cmsfc_contacts_delete', {
                    ids: ids
                });

                message(
                    'cmsfc-email-info',
                    'Видалено записів: ' + result.deleted
                );

                await loadContacts();
                await loadLists();

            } catch (error) {
                message('cmsfc-email-info', 'Помилка: ' + error.message);
            }
        });

        // Копіює всі адреси вибраного списку в буфер обміну.
        $('cmsfc-email-copy').addEventListener('click', async function () {
            const listId = Number($('cmsfc-view-list').value || 0);

            if (!listId) {
                message('cmsfc-email-info', 'Виберіть список.');
                return;
            }

            try {
                const data = await request('cmsfc_contacts_copy', {
                    list_id: listId
                });

                if (!data.emails) {
                    message('cmsfc-email-info', 'У списку поки немає адрес.');
                    return;
                }

                await navigator.clipboard.writeText(data.emails);

                message(
                    'cmsfc-email-info',
                    'Скопійовано адрес: ' + data.count
                );

            } catch (error) {
                message(
                    'cmsfc-email-info',
                    'Не вдалося скопіювати. ' + error.message
                );
            }
        });

        // Відкриває готовий CSV-файл для завантаження.
        $('cmsfc-email-export').addEventListener('click', function () {
            const listId = Number($('cmsfc-view-list').value || 0);

            if (!listId) {
                message('cmsfc-email-info', 'Виберіть список.');
                return;
            }

            const url = new URL(CMSFC.exportUrl, window.location.origin);

            url.searchParams.set('action', 'cmsfc_export');
            url.searchParams.set('list_id', String(listId));
            url.searchParams.set('_wpnonce', CMSFC.exportNonce);

            window.location.href = url.toString();
        });

        // Додає адресу до глобального блеклиста.
        $('cmsfc-blacklist-add').addEventListener('click', async function () {
            const email = $('cmsfc-blacklist-email').value.trim();
            const reason = $('cmsfc-blacklist-reason').value.trim();

            if (!email) {
                message('cmsfc-blacklist-result', 'Введіть email.');
                return;
            }

            try {
                const result = await request('cmsfc_blacklist_add', {
                    email: email,
                    reason: reason
                });

                message('cmsfc-blacklist-result', result);
                $('cmsfc-blacklist-email').value = '';
                $('cmsfc-blacklist-reason').value = '';

                await loadBlacklist();
                await loadContacts();

            } catch (error) {
                message('cmsfc-blacklist-result', 'Помилка: ' + error.message);
            }
        });

        // Завантажує та показує глобальний блеклист.
        async function loadBlacklist() {
            const rows = await request('cmsfc_blacklist', {});
            const target = $('cmsfc-blacklist-result');

            target.innerHTML = '';

            if (!rows.length) {
                target.textContent = 'Блеклист поки порожній.';
                return;
            }

            const table = document.createElement('table');
            table.className = 'widefat striped';

            const head = document.createElement('thead');
            const header = document.createElement('tr');

            ['Email', 'Причина', 'Джерело', 'Дата', 'Дія'].forEach(function (label) {
                const th = document.createElement('th');
                th.textContent = label;
                header.appendChild(th);
            });

            head.appendChild(header);
            table.appendChild(head);

            const tbody = document.createElement('tbody');

            rows.forEach(function (item) {
                const tr = document.createElement('tr');

                [item.email, item.reason, item.source, item.created_at].forEach(function (value) {
                    const td = document.createElement('td');
                    td.textContent = value || '';
                    tr.appendChild(td);
                });

                const action = document.createElement('td');
                const button = document.createElement('button');

                button.type = 'button';
                button.className = 'button button-small';
                button.textContent = 'Розблокувати';

                button.addEventListener('click', async function () {
                    if (!window.confirm('Розблокувати адресу ' + item.email + '?')) {
                        return;
                    }

                    try {
                        await request('cmsfc_blacklist_remove', {
                            id: item.id
                        });

                        await loadBlacklist();
                        await loadContacts();

                    } catch (error) {
                        message('cmsfc-blacklist-result', error.message);
                    }
                });

                action.appendChild(button);
                tr.appendChild(action);
                tbody.appendChild(tr);
            });

            table.appendChild(tbody);
            target.appendChild(table);
        }

        // Створює кампанію та запускає відправлення малими пакетами.
        $('cmsfc-campaign-start').addEventListener('click', async function () {
            if (campaignRunning) {
                return;
            }

            const listId = $('cmsfc-campaign-list').value;
            const templateId = $('cmsfc-template').value;

            if (!listId || !templateId) {
                message('cmsfc-campaign-status', 'Оберіть список і шаблон.');
                return;
            }

            if (!window.confirm(
                'Запустити кампанію? Надсилання піде лише адресам, які не перебувають у блеклисті.'
            )) {
                return;
            }

            campaignRunning = true;
            progress('cmsfc-campaign-fill', 0);
            message('cmsfc-campaign-status', 'Створення кампанії…');

            try {
                const data = await request('cmsfc_campaign_start', {
                    list_id: listId,
                    template_id: templateId,
                    name: $('cmsfc-campaign-name').value.trim()
                });

                campaignId = Number(data.campaign_id);

                if (!campaignId) {
                    throw new Error('Не отримано номер кампанії.');
                }

                await campaignStep();

            } catch (error) {
                campaignRunning = false;
                message('cmsfc-campaign-status', 'Помилка: ' + error.message);
            }
        });

        // Надсилає наступні три листи кампанії.
        async function campaignStep() {
            if (!campaignRunning || !campaignId) {
                return;
            }

            try {
                const data = await request('cmsfc_campaign_step', {
                    campaign_id: campaignId
                });

                const total = data.sent + data.failed + data.pending;
                const percent = total
                    ? (data.sent + data.failed) / total * 100
                    : 100;

                progress('cmsfc-campaign-fill', data.done ? 100 : percent);

                message(
                    'cmsfc-campaign-status',
                    'Надіслано: ' + data.sent +
                    ' · Помилки або пропуски: ' + data.failed +
                    ' · У черзі: ' + data.pending
                );

                if (data.done) {
                    campaignRunning = false;
                    message(
                        'cmsfc-campaign-status',
                        'Кампанію завершено. Надіслано: ' +
                        data.sent + ', помилки або пропуски: ' + data.failed
                    );
                    return;
                }

                window.setTimeout(campaignStep, 1000);

            } catch (error) {
                campaignRunning = false;
                message('cmsfc-campaign-status', 'Помилка: ' + error.message);
            }
        }

        // Початкове завантаження.
        loadLists().catch(function (error) {
            message('cmsfc-status', 'Не вдалося завантажити списки: ' + error.message);
        });

        loadBlacklist().catch(function (error) {
            message('cmsfc-blacklist-result', 'Помилка блеклиста: ' + error.message);
        });
    });
})();
