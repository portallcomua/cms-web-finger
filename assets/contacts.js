/**
 * CMS Web Finder 2.3.0
 * Керування збором контактів, кампаніями та блеклистом.
 * Усі запити виконуються через захищений WordPress AJAX.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const app = document.getElementById('cmsfc-app');

        // Скрипт працює тільки на сторінці нового модуля контактів.
        if (!app || typeof CMSFC === 'undefined') {
            return;
        }

        let currentListId = 0;
        let currentCampaignId = 0;
        let scanRunning = false;
        let campaignRunning = false;
        let scanStopped = false;
        let campaignStopped = false;

        const $ = function (selector) {
            return app.querySelector(selector);
        };

        const setText = function (selector, value) {
            const element = $(selector);
            if (element) {
                element.textContent = value == null ? '' : String(value);
            }
        };

        const setWidth = function (selector, value) {
            const element = $(selector);
            if (element) {
                const number = Math.max(0, Math.min(100, Number(value) || 0));
                element.style.width = number + '%';
            }
        };

        // Єдина функція AJAX для всіх операцій модуля.
        async function request(action, values) {
            const body = new URLSearchParams();

            body.append('action', action);
            body.append('nonce', CMSFC.nonce);

            Object.keys(values || {}).forEach(function (key) {
                body.append(key, values[key] == null ? '' : String(values[key]));
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
                    : 'Сервер повернув помилку.';

                throw new Error(
                    typeof message === 'string'
                        ? message
                        : 'Не вдалося виконати операцію.'
                );
            }

            return result.data;
        }

        // Безпечно показуємо повідомлення, не вставляючи HTML від сервера.
        function showMessage(selector, message, type) {
            const element = $(selector);
            if (!element) {
                return;
            }

            element.textContent = message || '';
            element.className = 'cmsfc-message';

            if (type) {
                element.classList.add('is-' + type);
            }
        }

        // Оновлює таблицю списків і список для вибору кампанії.
        async function loadLists() {
            const data = await request('cmsfc_lists', {});
            const rows = Array.isArray(data) ? data : [];

            const campaignSelect = $('#cmsfc-campaign-list');
            const table = app.querySelector('.cmsfc-table-wrap table');

            if (campaignSelect) {
                const selected = campaignSelect.value;

                campaignSelect.innerHTML = '';

                const first = document.createElement('option');
                first.value = '';
                first.textContent = '— Оберіть список —';
                campaignSelect.appendChild(first);

                rows.forEach(function (item) {
                    const option = document.createElement('option');
                    option.value = item.id;
                    option.textContent =
                        (item.name || item.domain || 'Список') +
                        ' — ' + (item.contacts_count || 0) + ' email';

                    campaignSelect.appendChild(option);
                });

                if (selected) {
                    campaignSelect.value = selected;
                }
            }

            // Оновлюємо тіло таблиці, не змінюючи її заголовки.
            if (table) {
                let tbody = table.querySelector('tbody');

                if (!tbody) {
                    tbody = document.createElement('tbody');
                    table.appendChild(tbody);
                }

                tbody.innerHTML = '';

                if (!rows.length) {
                    const tr = document.createElement('tr');
                    const td = document.createElement('td');
                    td.colSpan = 5;
                    td.textContent = 'Списків поки немає.';
                    tr.appendChild(td);
                    tbody.appendChild(tr);
                } else {
                    rows.forEach(function (item) {
                        const tr = document.createElement('tr');

                        [
                            item.name || '',
                            item.domain || '',
                            item.pages_done || 0,
                            item.emails_found || 0,
                            item.status || ''
                        ].forEach(function (value) {
                            const td = document.createElement('td');
                            td.textContent = String(value);
                            tr.appendChild(td);
                        });

                        tbody.appendChild(tr);
                    });
                }
            }

            return rows;
        }

        // Один крок сканування. Сервер обробляє обмежену кількість сторінок.
        async function scanStep() {
            if (!scanRunning || scanStopped || !currentListId) {
                return;
            }

            try {
                const data = await request('cmsfc_step', {
                    list_id: currentListId
                });

                const pages = Number(data.pages || 0);
                const emails = Number(data.emails || 0);
                const remaining = Number(data.remaining || 0);
                const done = Boolean(data.done);

                setText(
                    '#cmsfc-counters',
                    'Перевірено сторінок: ' + pages +
                    ' · Знайдено email: ' + emails +
                    (remaining ? ' · У черзі: ' + remaining : '')
                );

                const maxPages = Number($('#cmsfc-max-pages')?.value || 100);
                const percent = maxPages > 0
                    ? Math.min(100, (pages / maxPages) * 100)
                    : 0;

                setWidth('#cmsfc-progress-fill', done ? 100 : percent);

                if (done) {
                    scanRunning = false;
                    setText('#cmsfc-status', 'Сканування завершено.');
                    await loadLists();
                    return;
                }

                setText('#cmsfc-status', 'Сканування триває…');

                // Наступний крок виконується окремим запитом, щоб не перевантажувати сервер.
                window.setTimeout(scanStep, 500);

            } catch (error) {
                scanRunning = false;
                setText('#cmsfc-status', 'Помилка: ' + error.message);
            }
        }

        // Стартуємо новий збір email за вказаним доменом.
        const startButton = $('#cmsfc-start');

        if (startButton) {
            startButton.addEventListener('click', async function () {
                if (scanRunning) {
                    return;
                }

                const domain = $('#cmsfc-domain')?.value.trim() || '';
                const name = $('#cmsfc-name')?.value.trim() || '';
                const maxPages = $('#cmsfc-max-pages')?.value || 100;
                const maxDepth = $('#cmsfc-max-depth')?.value || 3;

                if (!domain) {
                    setText('#cmsfc-status', 'Вкажіть домен сайту.');
                    return;
                }

                scanStopped = false;
                setText('#cmsfc-status', 'Створення списку та запуск сканування…');
                setWidth('#cmsfc-progress-fill', 0);

                try {
                    const data = await request('cmsfc_start', {
                        domain: domain,
                        name: name,
                        max_pages: maxPages,
                        max_depth: maxDepth
                    });

                    currentListId = Number(
                        data.list_id || data.id || data.id_list || 0
                    );

                    if (!currentListId) {
                        throw new Error('Сервер не повернув номер створеного списку.');
                    }

                    scanRunning = true;
                    setText('#cmsfc-status', 'Список створено. Сканування почалося.');
                    await loadLists();
                    await scanStep();

                } catch (error) {
                    scanRunning = false;
                    setText('#cmsfc-status', 'Помилка: ' + error.message);
                }
            });
        }

        // Пауза сканування на сервері.
        const pauseButton = $('#cmsfc-pause');

        if (pauseButton) {
            pauseButton.addEventListener('click', async function () {
                if (!currentListId) {
                    setText('#cmsfc-status', 'Спочатку запустіть сканування.');
                    return;
                }

                try {
                    await request('cmsfc_control', {
                        list_id: currentListId,
                        command: 'pause'
                    });

                    scanRunning = false;
                    setText('#cmsfc-status', 'Сканування призупинено.');

                } catch (error) {
                    setText('#cmsfc-status', 'Помилка: ' + error.message);
                }
            });
        }

        // Продовження раніше призупиненого сканування.
        const resumeButton = $('#cmsfc-resume');

        if (resumeButton) {
            resumeButton.addEventListener('click', async function () {
                if (!currentListId) {
                    setText('#cmsfc-status', 'Немає списку для продовження.');
                    return;
                }

                try {
                    await request('cmsfc_control', {
                        list_id: currentListId,
                        command: 'resume'
                    });

                    scanStopped = false;
                    scanRunning = true;
                    setText('#cmsfc-status', 'Сканування продовжено.');
                    await scanStep();

                } catch (error) {
                    scanRunning = false;
                    setText('#cmsfc-status', 'Помилка: ' + error.message);
                }
            });
        }

        // Зупиняє сканування на сервері.
        const stopButton = $('#cmsfc-stop');

        if (stopButton) {
            stopButton.addEventListener('click', async function () {
                if (!currentListId) {
                    setText('#cmsfc-status', 'Немає активного списку.');
                    return;
                }

                try {
                    await request('cmsfc_control', {
                        list_id: currentListId,
                        command: 'stop'
                    });

                    scanStopped = true;
                    scanRunning = false;
                    setText('#cmsfc-status', 'Сканування зупинено.');
                    await loadLists();

                } catch (error) {
                    setText('#cmsfc-status', 'Помилка: ' + error.message);
                }
            });
        }

        // Послідовно обробляємо чергу кампанії невеликими пакетами.
        async function campaignStep() {
            if (!campaignRunning || campaignStopped || !currentCampaignId) {
                return;
            }

            try {
                const data = await request('cmsfc_campaign_step', {
                    campaign_id: currentCampaignId
                });

                const sent = Number(data.sent || 0);
                const failed = Number(data.failed || 0);
                const pending = Number(data.pending || 0);
                const done = Boolean(data.done);

                setText(
                    '#cmsfc-campaign-status',
                    'Надіслано: ' + sent +
                    ' · Помилки/пропуски: ' + failed +
                    (pending ? ' · Залишилося: ' + pending : '')
                );

                if (done) {
                    campaignRunning = false;
                    setWidth('#cmsfc-campaign-fill', 100);
                    setText(
                        '#cmsfc-campaign-status',
                        'Кампанію завершено. Надіслано: ' +
                        sent + ', помилки/пропуски: ' + failed
                    );
                    return;
                }

                const total = sent + failed + pending;
                const percent = total > 0
                    ? ((sent + failed) / total) * 100
                    : 0;

                setWidth('#cmsfc-campaign-fill', percent);

                window.setTimeout(campaignStep, 700);

            } catch (error) {
                campaignRunning = false;
                setText(
                    '#cmsfc-campaign-status',
                    'Помилка кампанії: ' + error.message
                );
            }
        }

        // Запускаємо кампанію за вибраним списком і шаблоном.
        const campaignStart = $('#cmsfc-campaign-start');

        if (campaignStart) {
            campaignStart.addEventListener('click', async function () {
                if (campaignRunning) {
                    return;
                }

                const listId = $('#cmsfc-campaign-list')?.value || '';
                const templateId = $('#cmsfc-template')?.value || '';
                const name = $('#cmsfc-campaign-name')?.value.trim() || '';

                if (!listId) {
                    setText('#cmsfc-campaign-status', 'Оберіть список контактів.');
                    return;
                }

                if (!templateId) {
                    setText('#cmsfc-campaign-status', 'Оберіть шаблон листа.');
                    return;
                }

                campaignStopped = false;
                setWidth('#cmsfc-campaign-fill', 0);
                setText('#cmsfc-campaign-status', 'Створення кампанії…');

                try {
                    const data = await request('cmsfc_campaign_start', {
                        list_id: listId,
                        template_id: templateId,
                        name: name
                    });

                    currentCampaignId = Number(
                        data.campaign_id || data.id || data.id_campaign || 0
                    );

                    if (!currentCampaignId) {
                        throw new Error('Сервер не повернув номер кампанії.');
                    }

                    campaignRunning = true;
                    setText('#cmsfc-campaign-status', 'Кампанію запущено.');
                    await campaignStep();

                } catch (error) {
                    campaignRunning = false;
                    setText(
                        '#cmsfc-campaign-status',
                        'Помилка: ' + error.message
                    );
                }
            });
        }

        // Додаємо email до глобального блеклиста вручну.
        const blacklistAdd = $('#cmsfc-blacklist-add');

        if (blacklistAdd) {
            blacklistAdd.addEventListener('click', async function () {
                const email = $('#cmsfc-blacklist-email')?.value.trim() || '';
                const reason = $('#cmsfc-blacklist-reason')?.value.trim() || '';

                if (!email) {
                    showMessage(
                        '#cmsfc-blacklist-result',
                        'Введіть email.',
                        'error'
                    );
                    return;
                }

                try {
                    const data = await request('cmsfc_blacklist_add', {
                        email: email,
                        reason: reason
                    });

                    showMessage(
                        '#cmsfc-blacklist-result',
                        typeof data === 'string' ? data : 'Адресу додано до блеклиста.',
                        'success'
                    );

                    $('#cmsfc-blacklist-email').value = '';
                    $('#cmsfc-blacklist-reason').value = '';

                    await loadBlacklist();

                } catch (error) {
                    showMessage(
                        '#cmsfc-blacklist-result',
                        error.message,
                        'error'
                    );
                }
            });
        }

        // Показуємо глобальний блеклист.
        async function loadBlacklist() {
            const data = await request('cmsfc_blacklist', {});
            const rows = Array.isArray(data) ? data : [];
            const result = $('#cmsfc-blacklist-result');

            if (!result) {
                return;
            }

            result.innerHTML = '';

            if (!rows.length) {
                result.textContent = 'У блеклисті поки немає адрес.';
                return;
            }

            const table = document.createElement('table');
            table.className = 'widefat striped';

            const thead = document.createElement('thead');
            const headerRow = document.createElement('tr');

            ['Email', 'Причина', 'Джерело', 'Дата', 'Дія'].forEach(function (label) {
                const th = document.createElement('th');
                th.textContent = label;
                headerRow.appendChild(th);
            });

            thead.appendChild(headerRow);
            table.appendChild(thead);

            const tbody = document.createElement('tbody');

            rows.forEach(function (item) {
                const tr = document.createElement('tr');

                [
                    item.email || '',
                    item.reason || '',
                    item.source || '',
                    item.created_at || ''
                ].forEach(function (value) {
                    const td = document.createElement('td');
                    td.textContent = String(value);
                    tr.appendChild(td);
                });

                const actionCell = document.createElement('td');
                const button = document.createElement('button');

                button.type = 'button';
                button.className = 'button button-small';
                button.textContent = 'Розблокувати';

                button.addEventListener('click', async function () {
                    if (!window.confirm('Розблокувати цю адресу?')) {
                        return;
                    }

                    try {
                        await request('cmsfc_blacklist_remove', {
                            id: item.id
                        });

                        await loadBlacklist();

                    } catch (error) {
                        showMessage(
                            '#cmsfc-blacklist-result',
                            error.message,
                            'error'
                        );
                    }
                });

                actionCell.appendChild(button);
                tr.appendChild(actionCell);
                tbody.appendChild(tr);
            });

            table.appendChild(tbody);
            result.appendChild(table);
        }

        // Початкове завантаження списків і блеклиста.
        loadLists().catch(function (error) {
            setText('#cmsfc-status', 'Не вдалося завантажити списки: ' + error.message);
        });

        loadBlacklist().catch(function (error) {
            showMessage('#cmsfc-blacklist-result', error.message, 'error');
        });
    });
})();
