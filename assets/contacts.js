
/**
 * CMS Web Finder 2.3.0
 * Повний JavaScript модуля збору контактів.
 *
 * Функції:
 * - запуск і керування скануванням;
 * - перегляд збережених списків;
 * - перегляд усіх знайдених email;
 * - пошук і фільтрація;
 * - видалення вибраних адрес;
 * - копіювання та CSV;
 * - глобальний блеклист;
 * - запуск email-кампаній.
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

        let scanTimer = null;
        let campaignTimer = null;

        function $(id) {
            return document.getElementById(id);
        }

        /* --------------------------------------------------
         * 1. Повідомлення та індикатори
         * -------------------------------------------------- */

        function message(id, text) {
            const element = $(id);

            if (element) {
                element.textContent = text || '';
            }
        }

        function progress(id, value) {
            const element = $(id);

            if (!element) {
                return;
            }

            const number = Math.max(
                0,
                Math.min(100, Number(value) || 0)
            );

            element.style.width = number + '%';
        }

        function showError(id, error) {
            const text = error && error.message
                ? error.message
                : String(error || 'Невідома помилка');

            message(id, 'Помилка: ' + text);
        }

        /* --------------------------------------------------
         * 2. Основна функція AJAX
         *
         * Перевіряє відповідь сервера до JSON.parse.
         * Якщо WordPress повернув HTML або PHP-помилку,
         * показує її текст, а не Unexpected token '<'.
         * -------------------------------------------------- */

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

                    body.append(
                        key,
                        value == null ? '' : String(value)
                    );
                }
            });

            let response;

            try {

                response = await fetch(CMSFC.ajax, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type':
                            'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: body.toString()
                });

            } catch (error) {

                throw new Error(
                    'Не вдалося з’єднатися з WordPress. ' +
                    error.message
                );
            }

            const raw = await response.text();

            let result;

            try {

                result = JSON.parse(raw);

            } catch (error) {

                const details = raw
                    .replace(/<script[\s\S]*?<\/script>/gi, '')
                    .replace(/<style[\s\S]*?<\/style>/gi, '')
                    .replace(/<[^>]*>/g, ' ')
                    .replace(/&nbsp;/gi, ' ')
                    .replace(/&amp;/gi, '&')
                    .replace(/\s+/g, ' ')
                    .trim()
                    .substring(0, 1500);

                throw new Error(
                    'Сервер повернув HTML замість JSON. ' +
                    'HTTP ' + response.status + '. ' +
                    'Відповідь: ' +
                    (details || raw.substring(0, 500))
                );
            }

            if (!response.ok) {

                throw new Error(
                    'HTTP помилка ' + response.status
                );
            }

            if (!result || result.success !== true) {

                const errorData = result && result.data
                    ? result.data
                    : 'WordPress повернув невідому помилку.';

                throw new Error(
                    typeof errorData === 'string'
                        ? errorData
                        : JSON.stringify(errorData)
                );
            }

            return result.data;
        }

        /* --------------------------------------------------
         * 3. Завантаження збережених списків
         * -------------------------------------------------- */

        async function loadLists() {

            const rows = await request('cmsfc_lists', {});

            const viewSelect = $('cmsfc-view-list');
            const campaignSelect = $('cmsfc-campaign-list');

            const tables = app.querySelectorAll(
                '.cmsfc-table-wrap table'
            );

            const listTable = tables.length
                ? tables[0].querySelector('tbody')
                : null;

            const oldView = viewSelect
                ? viewSelect.value
                : '';

            const oldCampaign = campaignSelect
                ? campaignSelect.value
                : '';

            if (viewSelect) {
                viewSelect.innerHTML =
                    '<option value="">— Виберіть список —</option>';
            }

            if (campaignSelect) {
                campaignSelect.innerHTML =
                    '<option value="">— Виберіть список —</option>';
            }

            if (listTable) {
                listTable.innerHTML = '';
            }

            if (!Array.isArray(rows)) {
                throw new Error(
                    'Сервер повернув неправильний формат списків.'
                );
            }

            rows.forEach(function (item) {

                const label =
                    item.name +
                    ' (' + item.emails_found + ' email)';

                if (viewSelect) {

                    const option = document.createElement('option');

                    option.value = item.id;
                    option.textContent = label;

                    viewSelect.appendChild(option);
                }

                if (campaignSelect) {

                    const option = document.createElement('option');

                    option.value = item.id;
                    option.textContent = label;

                    campaignSelect.appendChild(option);
                }

                if (listTable) {

                    const tr = document.createElement('tr');

                    [
                        item.name,
                        item.domain,
                        item.pages_done,
                        item.emails_found,
                        item.status
                    ].forEach(function (value) {

                        const td = document.createElement('td');

                        td.textContent = String(value ?? '');

                        tr.appendChild(td);
                    });

                    listTable.appendChild(tr);
                }
            });

            if (viewSelect && oldView) {
                viewSelect.value = oldView;
            }

            if (campaignSelect && oldCampaign) {
                campaignSelect.value = oldCampaign;
            }

            return rows;
        }

        /* --------------------------------------------------
         * 4. Перегляд знайдених email
         * -------------------------------------------------- */

        async function loadContacts() {

            const listId = Number(
                $('cmsfc-view-list').value || 0
            );

            if (!listId) {

                message(
                    'cmsfc-email-info',
                    'Спочатку виберіть список.'
                );

                return;
            }

            currentListId = listId;

            const search = $('cmsfc-email-search').value || '';
            const filter = $('cmsfc-email-filter').value || 'all';

            message(
                'cmsfc-email-info',
                'Завантаження адрес...'
            );

            try {

                const data = await request('cmsfc_contacts', {

                    list_id: listId,
                    page: currentPage,
                    search: search,
                    filter: filter

                });

                if (!data || !Array.isArray(data.rows)) {
                    throw new Error(
                        'Неправильний формат відповіді зі списком адрес.'
                    );
                }

                totalPages = Number(data.pages || 1);

                const tbody = $('cmsfc-email-table')
                    .querySelector('tbody');

                tbody.innerHTML = '';

                if (!data.rows.length) {

                    const tr = document.createElement('tr');
                    const td = document.createElement('td');

                    td.colSpan = 5;

                    td.textContent =
                        'За цими параметрами адрес не знайдено.';

                    tr.appendChild(td);
                    tbody.appendChild(tr);

                } else {

                    data.rows.forEach(function (item) {

                        const tr = document.createElement('tr');

                        // Прапорець вибору.
                        const selectCell =
                            document.createElement('td');

                        const checkbox =
                            document.createElement('input');

                        checkbox.type = 'checkbox';
                        checkbox.className =
                            'cmsfc-contact-select';

                        checkbox.value = item.id;

                        selectCell.appendChild(checkbox);
                        tr.appendChild(selectCell);

                        // Email.
                        const emailCell =
                            document.createElement('td');

                        emailCell.textContent = item.email;

                        tr.appendChild(emailCell);

                        // Сторінка-джерело.
                        const sourceCell =
                            document.createElement('td');

                        if (item.source_url) {

                            const link =
                                document.createElement('a');

                            link.href = item.source_url;
                            link.target = '_blank';
                            link.rel = 'noopener noreferrer';

                            link.textContent = item.source_url;

                            sourceCell.appendChild(link);

                        } else {

                            sourceCell.textContent = 'Немає даних';
                        }

                        tr.appendChild(sourceCell);

                        // Дата.
                        const dateCell =
                            document.createElement('td');

                        dateCell.textContent =
                            item.created_at || '';

                        tr.appendChild(dateCell);

                        // Статус блеклиста.
                        const blockedCell =
                            document.createElement('td');

                        if (Number(item.blocked)) {

                            blockedCell.textContent =
                                'У блеклисті';

                            blockedCell.className =
                                'cmsfc-blocked';

                        } else {

                            blockedCell.textContent =
                                'Не заблокована';

                            blockedCell.className =
                                'cmsfc-allowed';
                        }

                        tr.appendChild(blockedCell);

                        tbody.appendChild(tr);
                    });
                }

                message(
                    'cmsfc-email-info',
                    'Знайдено записів: ' +
                    data.total +
                    '. Сторінка ' +
                    data.page +
                    ' із ' +
                    data.pages + '.'
                );

                message(
                    'cmsfc-email-pages',
                    'Сторінка ' +
                    data.page +
                    ' із ' +
                    data.pages
                );

                $('cmsfc-select-all').checked = false;

            } catch (error) {

                showError('cmsfc-email-info', error);
            }
        }

        /* --------------------------------------------------
         * 5. Запуск сканування
         * -------------------------------------------------- */

        $('cmsfc-start').addEventListener(
            'click',
            async function () {

                if (scanRunning) {
                    return;
                }

                const domain =
                    $('cmsfc-domain').value.trim();

                if (!domain) {

                    message(
                        'cmsfc-status',
                        'Вкажіть домен сайту.'
                    );

                    return;
                }

                scanRunning = true;

                progress('cmsfc-progress-fill', 0);

                message(
                    'cmsfc-status',
                    'Створення списку...'
                );

                try {

                    const data = await request(
                        'cmsfc_start',
                        {
                            domain: domain,
                            name: $('cmsfc-name').value.trim(),
                            max_pages:
                                $('cmsfc-max-pages').value,
                            max_depth:
                                $('cmsfc-max-depth').value
                        }
                    );

                    currentListId = Number(data.list_id);

                    if (!currentListId) {
                        throw new Error(
                            'Сервер не повернув ID списку.'
                        );
                    }

                    currentPage = 1;

                    await loadLists();

                    $('cmsfc-view-list').value =
                        String(currentListId);

                    message(
                        'cmsfc-status',
                        'Сканування розпочато.'
                    );

                    await scanStep();

                } catch (error) {

                    scanRunning = false;

                    showError('cmsfc-status', error);
                }
            }
        );

        /* --------------------------------------------------
         * 6. Черговий крок сканування
         * -------------------------------------------------- */

        async function scanStep() {

            if (!scanRunning || !currentListId) {
                return;
            }

            try {

                const data = await request(
                    'cmsfc_step',
                    {
                        list_id: currentListId
                    }
                );

                const maxPages = Number(
                    $('cmsfc-max-pages').value || 100
                );

                const percent = maxPages > 0
                    ? Math.min(
                        100,
                        data.pages / maxPages * 100
                    )
                    : 0;

                progress(
                    'cmsfc-progress-fill',
                    data.done ? 100 : percent
                );

                message(
                    'cmsfc-status',
                    data.done
                        ? 'Сканування завершено.'
                        : 'Сканування триває...'
                );

                message(
                    'cmsfc-counters',
                    'Перевірено сторінок: ' +
                    data.pages +
                    ' · Знайдено email: ' +
                    data.emails +
                    ' · У черзі: ' +
                    data.remaining
                );

                await loadLists();

                $('cmsfc-view-list').value =
                    String(currentListId);

                await loadContacts();

                if (data.done) {

                    scanRunning = false;

                    message(
                        'cmsfc-status',
                        'Сканування завершено.'
                    );

                    return;
                }

                scanTimer = window.setTimeout(
                    scanStep,
                    700
                );

            } catch (error) {

                scanRunning = false;

                showError('cmsfc-status', error);
            }
        }

        /* --------------------------------------------------
         * 7. Пауза, продовження та зупинка
         * -------------------------------------------------- */

        async function controlScan(command) {

            if (!currentListId) {

                message(
                    'cmsfc-status',
                    'Спочатку запустіть сканування.'
                );

                return;
            }

            try {

                await request(
                    'cmsfc_control',
                    {
                        list_id: currentListId,
                        command: command
                    }
                );

                if (command === 'pause') {

                    scanRunning = false;

                    window.clearTimeout(scanTimer);

                    message(
                        'cmsfc-status',
                        'Сканування призупинено.'
                    );
                }

                if (command === 'stop') {

                    scanRunning = false;

                    window.clearTimeout(scanTimer);

                    message(
                        'cmsfc-status',
                        'Сканування зупинено.'
                    );
                }

                if (command === 'resume') {

                    scanRunning = true;

                    message(
                        'cmsfc-status',
                        'Сканування продовжено.'
                    );

                    await scanStep();
                }

                await loadLists();

            } catch (error) {

                showError('cmsfc-status', error);
            }
        }

        $('cmsfc-pause').addEventListener(
            'click',
            function () {
                controlScan('pause');
            }
        );

        $('cmsfc-resume').addEventListener(
            'click',
            function () {
                controlScan('resume');
            }
        );

        $('cmsfc-stop').addEventListener(
            'click',
            function () {
                controlScan('stop');
            }
        );

        /* --------------------------------------------------
         * 8. Оновлення таблиці контактів
         * -------------------------------------------------- */

        $('cmsfc-email-refresh').addEventListener(
            'click',
            function () {
                currentPage = 1;
                loadContacts();
            }
        );

        $('cmsfc-view-list').addEventListener(
            'change',
            function () {
                currentPage = 1;
                loadContacts();
            }
        );

        $('cmsfc-email-filter').addEventListener(
            'change',
            function () {
                currentPage = 1;
                loadContacts();
            }
        );

        $('cmsfc-email-search').addEventListener(
            'keydown',
            function (event) {

                if (event.key === 'Enter') {

                    currentPage = 1;

                    loadContacts();
                }
            }
        );

        /* --------------------------------------------------
         * 9. Перемикання сторінок таблиці
         * -------------------------------------------------- */

        $('cmsfc-email-prev').addEventListener(
            'click',
            function () {

                if (currentPage > 1) {

                    currentPage--;

                    loadContacts();
                }
            }
        );

        $('cmsfc-email-next').addEventListener(
            'click',
            function () {

                if (currentPage < totalPages) {

                    currentPage++;

                    loadContacts();
                }
            }
        );

        /* --------------------------------------------------
         * 10. Вибрати всі адреси на поточній сторінці
         * -------------------------------------------------- */

        $('cmsfc-select-all').addEventListener(
            'change',
            function () {

                app.querySelectorAll(
                    '.cmsfc-contact-select'
                ).forEach(function (checkbox) {

                    checkbox.checked =
                        $('cmsfc-select-all').checked;
                });
            }
        );

        /* --------------------------------------------------
         * 11. Видалення вибраних адрес
         * -------------------------------------------------- */

        $('cmsfc-email-delete').addEventListener(
            'click',
            async function () {

                const ids = Array.from(
                    app.querySelectorAll(
                        '.cmsfc-contact-select:checked'
                    )
                ).map(function (checkbox) {
                    return checkbox.value;
                });

                if (!ids.length) {

                    message(
                        'cmsfc-email-info',
                        'Позначте адреси для видалення.'
                    );

                    return;
                }

                if (!window.confirm(
                    'Видалити вибрані адреси зі списку?'
                )) {
                    return;
                }

                try {

                    const result = await request(
                        'cmsfc_contacts_delete',
                        {
                            ids: ids
                        }
                    );

                    message(
                        'cmsfc-email-info',
                        'Видалено записів: ' +
                        result.deleted
                    );

                    await loadContacts();
                    await loadLists();

                } catch (error) {

                    showError('cmsfc-email-info', error);
                }
            }
        );

        /* --------------------------------------------------
         * 12. Копіювання адрес
         * -------------------------------------------------- */

        async function copyText(text) {

            if (navigator.clipboard &&
                window.isSecureContext) {

                await navigator.clipboard.writeText(text);

                return true;
            }

            const textarea =
                document.createElement('textarea');

            textarea.value = text;

            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            textarea.style.top = '0';

            document.body.appendChild(textarea);

            textarea.focus();
            textarea.select();

            const success =
                document.execCommand('copy');

            document.body.removeChild(textarea);

            if (!success) {
                throw new Error(
                    'Браузер не дозволив скопіювати текст.'
                );
            }

            return true;
        }

        $('cmsfc-email-copy').addEventListener(
            'click',
            async function () {

                const listId = Number(
                    $('cmsfc-view-list').value || 0
                );

                if (!listId) {

                    message(
                        'cmsfc-email-info',
                        'Спочатку виберіть список.'
                    );

                    return;
                }

                try {

                    const data = await request(
                        'cmsfc_contacts_copy',
                        {
                            list_id: listId
                        }
                    );

                    if (!data.emails) {

                        message(
                            'cmsfc-email-info',
                            'У списку поки немає адрес.'
                        );

                        return;
                    }

                    await copyText(data.emails);

                    message(
                        'cmsfc-email-info',
                        'Скопійовано адрес: ' +
                        data.count
                    );

                } catch (error) {

                    showError('cmsfc-email-info', error);
                }
            }
        );

        /* --------------------------------------------------
         * 13. CSV-експорт
         * -------------------------------------------------- */

        $('cmsfc-email-export').addEventListener(
            'click',
            function () {

                const listId = Number(
                    $('cmsfc-view-list').value || 0
                );

                if (!listId) {

                    message(
                        'cmsfc-email-info',
                        'Спочатку виберіть список.'
                    );

                    return;
                }

                const url = new URL(
                    CMSFC.exportUrl,
                    window.location.origin
                );

                url.searchParams.set(
                    'action',
                    'cmsfc_export'
                );

                url.searchParams.set(
                    'list_id',
                    String(listId)
                );

                url.searchParams.set(
                    '_wpnonce',
                    CMSFC.exportNonce
                );

                window.location.href = url.toString();
            }
        );

        /* --------------------------------------------------
         * 14. Додавання адреси до блеклиста
         * -------------------------------------------------- */

        $('cmsfc-blacklist-add').addEventListener(
            'click',
            async function () {

                const email =
                    $('cmsfc-blacklist-email').value.trim();

                const reason =
                    $('cmsfc-blacklist-reason').value.trim();

                if (!email) {

                    message(
                        'cmsfc-blacklist-result',
                        'Введіть email.'
                    );

                    return;
                }

                try {

                    const result = await request(
                        'cmsfc_blacklist_add',
                        {
                            email: email,
                            reason: reason
                        }
                    );

                    message(
                        'cmsfc-blacklist-result',
                        result
                    );

                    $('cmsfc-blacklist-email').value = '';
                    $('cmsfc-blacklist-reason').value = '';

                    await loadBlacklist();
                    await loadContacts();

                } catch (error) {

                    showError(
                        'cmsfc-blacklist-result',
                        error
                    );
                }
            }
        );

        /* --------------------------------------------------
         * 15. Завантаження глобального блеклиста
         * -------------------------------------------------- */

        async function loadBlacklist() {

            const rows = await request(
                'cmsfc_blacklist',
                {}
            );

            const target =
                $('cmsfc-blacklist-result');

            target.innerHTML = '';

            if (!Array.isArray(rows) || !rows.length) {

                target.textContent =
                    'Блеклист поки порожній.';

                return;
            }

            const table =
                document.createElement('table');

            table.className =
                'widefat striped';

            const thead =
                document.createElement('thead');

            const header =
                document.createElement('tr');

            [
                'Email',
                'Причина',
                'Джерело',
                'Дата',
                'Дія'
            ].forEach(function (label) {

                const th =
                    document.createElement('th');

                th.textContent = label;

                header.appendChild(th);
            });

            thead.appendChild(header);
            table.appendChild(thead);

            const tbody =
                document.createElement('tbody');

            rows.forEach(function (item) {

                const tr =
                    document.createElement('tr');

                [
                    item.email,
                    item.reason,
                    item.source,
                    item.created_at
                ].forEach(function (value) {

                    const td =
                        document.createElement('td');

                    td.textContent = value || '';

                    tr.appendChild(td);
                });

                const action =
                    document.createElement('td');

                const button =
                    document.createElement('button');

                button.type = 'button';
                button.className =
                    'button button-small';

                button.textContent =
                    'Розблокувати';

                button.addEventListener(
                    'click',
                    async function () {

                        if (!window.confirm(
                            'Розблокувати ' +
                            item.email + '?'
                        )) {
                            return;
                        }

                        try {

                            await request(
                                'cmsfc_blacklist_remove',
                                {
                                    id: item.id
                                }
                            );

                            await loadBlacklist();
                            await loadContacts();

                        } catch (error) {

                            showError(
                                'cmsfc-blacklist-result',
                                error
                            );
                        }
                    }
                );

                action.appendChild(button);
                tr.appendChild(action);

                tbody.appendChild(tr);
            });

            table.appendChild(tbody);
            target.appendChild(table);
        }

        /* --------------------------------------------------
         * 16. Створення кампанії
         * -------------------------------------------------- */

        $('cmsfc-campaign-start').addEventListener(
            'click',
            async function () {

                if (campaignRunning) {
                    return;
                }

                const listId =
                    $('cmsfc-campaign-list').value;

                const templateId =
                    $('cmsfc-template').value;

                if (!listId || !templateId) {

                    message(
                        'cmsfc-campaign-status',
                        'Оберіть список і шаблон листа.'
                    );

                    return;
                }

                if (!window.confirm(
                    'Запустити кампанію? ' +
                    'Перед відправленням кожна адреса ' +
                    'перевірятиметься за блеклистом.'
                )) {
                    return;
                }

                campaignRunning = true;

                progress(
                    'cmsfc-campaign-fill',
                    0
                );

                message(
                    'cmsfc-campaign-status',
                    'Створення кампанії...'
                );

                try {

                    const data = await request(
                        'cmsfc_campaign_start',
                        {
                            list_id: listId,
                            template_id: templateId,
                            name:
                                $('cmsfc-campaign-name').value.trim()
                        }
                    );

                    campaignId =
                        Number(data.campaign_id);

                    if (!campaignId) {
                        throw new Error(
                            'Сервер не повернув ID кампанії.'
                        );
                    }

                    await campaignStep();

                } catch (error) {

                    campaignRunning = false;

                    showError(
                        'cmsfc-campaign-status',
                        error
                    );
                }
            }
        );

        /* --------------------------------------------------
         * 17. Відправлення наступної порції листів
         * -------------------------------------------------- */

        async function campaignStep() {

            if (!campaignRunning || !campaignId) {
                return;
            }

            try {

                const data = await request(
                    'cmsfc_campaign_step',
                    {
                        campaign_id: campaignId
                    }
                );

                const sent = Number(data.sent || 0);
                const failed = Number(data.failed || 0);
                const pending = Number(data.pending || 0);

                const total =
                    sent + failed + pending;

                const percent = total > 0
                    ? (sent + failed) / total * 100
                    : 100;

                progress(
                    'cmsfc-campaign-fill',
                    data.done ? 100 : percent
                );

                message(
                    'cmsfc-campaign-status',
                    'Надіслано: ' + sent +
                    ' · Помилки або пропуски: ' + failed +
                    ' · У черзі: ' + pending
                );

                if (data.done) {

                    campaignRunning = false;

                    message(
                        'cmsfc-campaign-status',
                        'Кампанію завершено. ' +
                        'Надіслано: ' + sent +
                        '. Помилки або пропуски: ' + failed
                    );

                    return;
                }

                campaignTimer = window.setTimeout(
                    campaignStep,
                    1000
                );

            } catch (error) {

                campaignRunning = false;

                showError(
                    'cmsfc-campaign-status',
                    error
                );
            }
        }

        /* --------------------------------------------------
         * 18. Початкове завантаження сторінки
         * -------------------------------------------------- */

        async function initialize() {

            try {

                await loadLists();

            } catch (error) {

                showError(
                    'cmsfc-status',
                    error
                );
            }

            try {

                await loadBlacklist();

            } catch (error) {

                showError(
                    'cmsfc-blacklist-result',
                    error
                );
            }
        }

        initialize();

    });

})();
