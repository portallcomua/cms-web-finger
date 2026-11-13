<?php
/**
 * CMS Web Finder — модуль контактів.
 * Версія модуля: 1.1
 * Виконавець: ChatGPT
 * Оновлено: 13.11.2026
 *
 * Функції:
 * - сканування публічних сторінок сайту;
 * - збереження email та URL джерела;
 * - перегляд, пошук, фільтрація та видалення адрес;
 * - CSV-експорт;
 * - глобальний блеклист;
 * - кампанії на основі наявних шаблонів;
 * - публічна відписка за персональним токеном.
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ---------------------------------------------------------
 * 1. Назви таблиць модуля
 * --------------------------------------------------------- */

function cmsfc_table($name) {
    global $wpdb;
    return $wpdb->prefix . 'cmsfc_' . $name;
}

/* ---------------------------------------------------------
 * 2. Створення таблиць бази даних
 * --------------------------------------------------------- */

function cmsf_contacts_install() {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset = $wpdb->get_charset_collate();

    // Списки сайтів, які скануються.
    dbDelta("CREATE TABLE " . cmsfc_table('lists') . " (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(190) NOT NULL,
        domain varchar(190) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'queued',
        max_pages int(11) NOT NULL DEFAULT 100,
        max_depth int(11) NOT NULL DEFAULT 3,
        pages_done int(11) NOT NULL DEFAULT 0,
        emails_found int(11) NOT NULL DEFAULT 0,
        queue longtext NULL,
        visited longtext NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY status (status)
    ) $charset;");

    // Кожна адреса зберігається окремим записом із джерелом.
    dbDelta("CREATE TABLE " . cmsfc_table('contacts') . " (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        list_id bigint(20) unsigned NOT NULL,
        email varchar(255) NOT NULL,
        source_url varchar(1000) NOT NULL DEFAULT '',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY list_email (list_id,email(190)),
        KEY list_id (list_id),
        KEY email (email(190))
    ) $charset;");

    // Єдиний глобальний блеклист для всіх списків і кампаній.
    dbDelta("CREATE TABLE " . cmsfc_table('blacklist') . " (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        email varchar(255) NOT NULL,
        reason varchar(255) NOT NULL DEFAULT '',
        source varchar(30) NOT NULL DEFAULT 'manual',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY email (email(190))
    ) $charset;");

    // Кампанії розсилки.
    dbDelta("CREATE TABLE " . cmsfc_table('campaigns') . " (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        list_id bigint(20) unsigned NOT NULL,
        template_id bigint(20) unsigned NOT NULL,
        name varchar(190) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'queued',
        sent int(11) NOT NULL DEFAULT 0,
        failed int(11) NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY list_id (list_id),
        KEY status (status)
    ) $charset;");

    // Одержувачі кампанії та персональні токени відписки.
    dbDelta("CREATE TABLE " . cmsfc_table('recipients') . " (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        campaign_id bigint(20) unsigned NOT NULL,
        email varchar(255) NOT NULL,
        token varchar(64) NOT NULL,
        source_url varchar(1000) NOT NULL DEFAULT '',
        status varchar(20) NOT NULL DEFAULT 'queued',
        error varchar(255) NOT NULL DEFAULT '',
        created_at datetime NOT NULL,
        sent_at datetime NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY campaign_email (campaign_id,email(190)),
        UNIQUE KEY token (token),
        KEY campaign_status (campaign_id,status)
    ) $charset;");
}

/* ---------------------------------------------------------
 * 3. Допоміжні функції контактів
 * --------------------------------------------------------- */

// Перевіряє, чи є email у глобальному блеклисті.
function cmsf_contact_is_blocked($email) {
    global $wpdb;

    $email = strtolower(sanitize_email($email));

    if (!is_email($email)) {
        return true;
    }

    return (bool)$wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM " . cmsfc_table('blacklist') . " WHERE email=%s LIMIT 1",
            $email
        )
    );
}

// Додає адресу до блеклиста. Повторне додавання не створює дублікат.
function cmsf_contact_blacklist_add($email, $reason = '', $source = 'manual') {
    global $wpdb;

    $email = strtolower(sanitize_email($email));

    if (!is_email($email)) {
        return false;
    }

    $source = in_array($source, ['manual', 'unsubscribe'], true)
        ? $source
        : 'manual';

    $wpdb->query(
        $wpdb->prepare(
            "INSERT INTO " . cmsfc_table('blacklist') . "
            (email,reason,source,created_at)
            VALUES (%s,%s,%s,%s)
            ON DUPLICATE KEY UPDATE
            reason=VALUES(reason)",
            $email,
            sanitize_text_field($reason),
            $source,
            current_time('mysql')
        )
    );

    return true;
}

// Створює випадковий токен відписки.
function cmsf_contact_make_token() {
    return bin2hex(random_bytes(24));
}

// Формує публічне посилання відписки.
function cmsf_contact_unsubscribe_url($token) {
    return add_query_arg(
        'cmsfc_unsubscribe',
        rawurlencode($token),
        home_url('/')
    );
}

/* ---------------------------------------------------------
 * 4. Публічна відписка без входу в адмінку
 * --------------------------------------------------------- */

add_action('init', function () {
    if (empty($_GET['cmsfc_unsubscribe'])) {
        return;
    }

    global $wpdb;

    $token = sanitize_text_field(wp_unslash($_GET['cmsfc_unsubscribe']));

    $recipient = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM " . cmsfc_table('recipients') . " WHERE token=%s LIMIT 1",
            $token
        ),
        ARRAY_A
    );

    if (!$recipient) {
        wp_die(
            'Посилання недійсне або вже неактивне.',
            'Відписка',
            ['response' => 404]
        );
    }

    cmsf_contact_blacklist_add(
        $recipient['email'],
        'Користувач натиснув посилання відписки',
        'unsubscribe'
    );

    $wpdb->update(
        cmsfc_table('recipients'),
        ['status' => 'unsubscribed'],
        ['id' => (int)$recipient['id']]
    );

    wp_die(
        '<div style="font-family:Arial;max-width:600px;margin:60px auto;padding:25px;border:1px solid #ddd;border-radius:10px">' .
        '<h2>Відписку підтверджено</h2>' .
        '<p>Цю адресу додано до списку заборони розсилок.</p>' .
        '<p>Дякуємо, що повідомили про своє рішення.</p>' .
        '</div>',
        'Відписка підтверджена',
        ['response' => 200]
    );
});

/* ---------------------------------------------------------
 * 5. Безпечна перевірка URL для сканування
 * --------------------------------------------------------- */

function cmsfc_normalize_host($host) {
    $host = strtolower(trim((string)$host));
    return preg_replace('/^www\./', '', $host);
}

function cmsfc_same_domain($url, $root_domain) {
    $host = wp_parse_url($url, PHP_URL_HOST);

    if (!$host) {
        return false;
    }

    $host = cmsfc_normalize_host($host);
    $root = cmsfc_normalize_host($root_domain);

    return $host === $root || substr($host, -strlen('.' . $root)) === '.' . $root;
}

// Не дозволяємо сканеру звертатися до локальних та приватних IP.
function cmsfc_safe_url($url, $root_domain) {
    $url = esc_url_raw($url);

    if (!$url || !in_array(wp_parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
        return false;
    }

    if (!cmsfc_same_domain($url, $root_domain)) {
        return false;
    }

    $host = wp_parse_url($url, PHP_URL_HOST);

    if (!$host) {
        return false;
    }

    $ip = gethostbyname($host);

    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        if (!filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        )) {
            return false;
        }
    }

    return $url;
}

// Завантажує одну публічну сторінку з обмеженим часом очікування.
function cmsfc_fetch_page($url, $root_domain) {
    $url = cmsfc_safe_url($url, $root_domain);

    if (!$url) {
        return '';
    }

    $response = wp_safe_remote_get($url, [
        'timeout' => 12,
        'redirection' => 3,
        'limit_response_size' => 1500000,
        'headers' => [
            'User-Agent' => 'CMS-Web-Finder/2.3 (+public website contact scanner)'
        ],
    ]);

    if (is_wp_error($response)) {
        return '';
    }

    $code = (int)wp_remote_retrieve_response_code($response);

    if ($code < 200 || $code >= 400) {
        return '';
    }

    $type = strtolower((string)wp_remote_retrieve_header($response, 'content-type'));

    if ($type && strpos($type, 'text/html') === false && strpos($type, 'text/plain') === false) {
        return '';
    }

    return (string)wp_remote_retrieve_body($response);
}

/* ---------------------------------------------------------
 * 6. Витягування email і посилань зі сторінки
 * --------------------------------------------------------- */

function cmsfc_extract_emails($html) {
    $found = [];

    // Декодуємо HTML-сутності, щоб знайти адреси в тексті та mailto.
    $text = html_entity_decode((string)$html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Витягуємо звичайні адреси.
    preg_match_all(
        '/[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+/',
        $text,
        $matches
    );

    foreach (($matches[0] ?? []) as $email) {
        $email = strtolower(trim($email, " \t\n\r\0\x0B.,;:()[]<>"));

        if (is_email($email)) {
            $found[$email] = $email;
        }
    }

    return array_values($found);
}

function cmsfc_extract_links($html, $page_url, $root_domain) {
    $links = [];

    if (!class_exists('DOMDocument')) {
        return $links;
    }

    libxml_use_internal_errors(true);

    $dom = new DOMDocument();

    if (!$dom->loadHTML('<?xml encoding="UTF-8">' . $html)) {
        libxml_clear_errors();
        return $links;
    }

    libxml_clear_errors();

    foreach ($dom->getElementsByTagName('a') as $a) {
        $href = trim($a->getAttribute('href'));

        if ($href === '' || strpos($href, 'mailto:') === 0 || strpos($href, 'tel:') === 0) {
            continue;
        }

        // Перетворюємо відносне посилання на абсолютне.
        $absolute = WP_Http::make_absolute_url($href, $page_url);

        if (!$absolute || !cmsfc_same_domain($absolute, $root_domain)) {
            continue;
        }

        // Не скануємо файли, зображення та службові адреси.
        $path = strtolower((string)wp_parse_url($absolute, PHP_URL_PATH));

        if (preg_match('/\.(pdf|jpg|jpeg|png|gif|webp|svg|zip|rar|7z|mp4|mp3|css|js|xml)$/i', $path)) {
            continue;
        }

        $absolute = strtok($absolute, '#');

        if ($absolute) {
            $links[$absolute] = $absolute;
        }
    }

    return array_values($links);
}

/* ---------------------------------------------------------
 * 7. Створення списку та початок сканування
 * --------------------------------------------------------- */

function cmsfc_create_list($domain, $name, $max_pages, $max_depth) {
    global $wpdb;

    $domain = strtolower(trim($domain));
    $domain = preg_replace('#^https?://#i', '', $domain);
    $domain = preg_replace('#/.*$#', '', $domain);
    $domain = cmsfc_normalize_host($domain);

    if (!$domain || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) {
        return new WP_Error('bad_domain', 'Вкажіть коректний домен.');
    }

    $name = sanitize_text_field($name);

    if ($name === '') {
        $name = $domain . ' — контакти';
    }

    $home = 'https://' . $domain . '/';

    $queue = [[
        'url' => $home,
        'depth' => 0
    ]];

    $now = current_time('mysql');

    $wpdb->insert(
        cmsfc_table('lists'),
        [
            'name' => $name,
            'domain' => $domain,
            'status' => 'queued',
            'max_pages' => max(1, min(5000, (int)$max_pages)),
            'max_depth' => max(0, min(10, (int)$max_depth)),
            'pages_done' => 0,
            'emails_found' => 0,
            'queue' => wp_json_encode($queue),
            'visited' => wp_json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]
    );

    if (!$wpdb->insert_id) {
        return new WP_Error('db_error', 'Не вдалося створити список.');
    }

    return (int)$wpdb->insert_id;
}

/* ---------------------------------------------------------
 * 8. Один крок сканування
 * --------------------------------------------------------- */

function cmsfc_scan_step($list_id) {
    global $wpdb;

    $list = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM " . cmsfc_table('lists') . " WHERE id=%d",
            $list_id
        ),
        ARRAY_A
    );

    if (!$list) {
        return new WP_Error('missing_list', 'Список не знайдено.');
    }

    if (in_array($list['status'], ['paused', 'stopped', 'done'], true)) {
        return [
            'done' => $list['status'] === 'done',
            'status' => $list['status'],
            'pages' => (int)$list['pages_done'],
            'emails' => (int)$list['emails_found'],
            'remaining' => 0
        ];
    }

    $queue = json_decode($list['queue'], true);
    $visited = json_decode($list['visited'], true);

    if (!is_array($queue)) $queue = [];
    if (!is_array($visited)) $visited = [];

    $pages_processed = 0;

    // За один AJAX-запит обробляємо максимум три сторінки.
    while ($queue && $pages_processed < 3) {
        if ((int)$list['pages_done'] >= (int)$list['max_pages']) {
            $queue = [];
            break;
        }

        $item = array_shift($queue);
        $url = esc_url_raw($item['url'] ?? '');
        $depth = (int)($item['depth'] ?? 0);

        if (!$url || isset($visited[$url])) {
            continue;
        }

        $visited[$url] = 1;

        $html = cmsfc_fetch_page($url, $list['domain']);

        if ($html === '') {
            continue;
        }

        // Знаходимо адреси та зберігаємо кожну окремо.
        $emails = cmsfc_extract_emails($html);

        foreach ($emails as $email) {
            if (cmsf_contact_is_blocked($email)) {
                continue;
            }

            $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO " . cmsfc_table('contacts') . "
                    (list_id,email,source_url,created_at)
                    VALUES (%d,%s,%s,%s)",
                    $list_id,
                    $email,
                    $url,
                    current_time('mysql')
                )
            );
        }

        // Додаємо посилання на наступні сторінки, якщо не перевищено глибину.
        if ($depth < (int)$list['max_depth']) {
            foreach (cmsfc_extract_links($html, $url, $list['domain']) as $link) {
                if (!isset($visited[$link])) {
                    $queue[] = [
                        'url' => $link,
                        'depth' => $depth + 1
                    ];
                }
            }
        }

        $pages_processed++;
        $list['pages_done']++;
    }

    $emails_count = (int)$wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM " . cmsfc_table('contacts') . " WHERE list_id=%d",
            $list_id
        )
    );

    $done = empty($queue) || (int)$list['pages_done'] >= (int)$list['max_pages'];

    $wpdb->update(
        cmsfc_table('lists'),
        [
            'queue' => wp_json_encode(array_values($queue)),
            'visited' => wp_json_encode($visited),
            'pages_done' => (int)$list['pages_done'],
            'emails_found' => $emails_count,
            'status' => $done ? 'done' : 'running',
            'updated_at' => current_time('mysql')
        ],
        ['id' => $list_id]
    );

    return [
        'done' => $done,
        'status' => $done ? 'done' : 'running',
        'pages' => (int)$list['pages_done'],
        'emails' => $emails_count,
        'remaining' => count($queue)
    ];
}

/* ---------------------------------------------------------
 * 9. AJAX: перевірка прав та nonce
 * --------------------------------------------------------- */

function cmsfc_ajax_guard() {
    check_ajax_referer('cmsfc', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Недостатньо прав.');
    }
}

/* ---------------------------------------------------------
 * 10. AJAX: запуск і керування скануванням
 * --------------------------------------------------------- */

add_action('wp_ajax_cmsfc_start', function () {
    cmsfc_ajax_guard();

    $id = cmsfc_create_list(
        sanitize_text_field(wp_unslash($_POST['domain'] ?? '')),
        sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
        (int)($_POST['max_pages'] ?? 100),
        (int)($_POST['max_depth'] ?? 3)
    );

    if (is_wp_error($id)) {
        wp_send_json_error($id->get_error_message());
    }

    wp_send_json_success(['list_id' => $id]);
});

add_action('wp_ajax_cmsfc_step', function () {
    cmsfc_ajax_guard();

    $result = cmsfc_scan_step((int)($_POST['list_id'] ?? 0));

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    wp_send_json_success($result);
});

add_action('wp_ajax_cmsfc_control', function () {
    cmsfc_ajax_guard();

    global $wpdb;

    $id = (int)($_POST['list_id'] ?? 0);
    $command = sanitize_key($_POST['command'] ?? '');

    $status = [
        'pause' => 'paused',
        'resume' => 'running',
        'stop' => 'stopped'
    ][$command] ?? '';

    if (!$status) {
        wp_send_json_error('Невідома команда.');
    }

    $wpdb->update(
        cmsfc_table('lists'),
        [
            'status' => $status,
            'updated_at' => current_time('mysql')
        ],
        ['id' => $id]
    );

    wp_send_json_success(['status' => $status]);
});

/* ---------------------------------------------------------
 * 11. AJAX: список списків контактів
 * --------------------------------------------------------- */

add_action('wp_ajax_cmsfc_lists', function () {
    cmsfc_ajax_guard();

    global $wpdb;

    $rows = $wpdb->get_results(
        "SELECT * FROM " . cmsfc_table('lists') . " ORDER BY id DESC",
        ARRAY_A
    );

    $out = [];

    foreach ($rows as $row) {
        $out[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'domain' => $row['domain'],
            'status' => $row['status'],
            'pages_done' => (int)$row['pages_done'],
            'emails_found' => (int)$row['emails_found'],
            'contacts_count' => (int)$row['emails_found']
        ];
    }

    wp_send_json_success($out);
});

/* ---------------------------------------------------------
 * 12. AJAX: перегляд і пошук email у вибраному списку
 * --------------------------------------------------------- */

add_action('wp_ajax_cmsfc_contacts', function () {
    cmsfc_ajax_guard();

    global $wpdb;

    $list_id = (int)($_POST['list_id'] ?? 0);
    $page = max(1, (int)($_POST['page'] ?? 1));
    $search = sanitize_text_field(wp_unslash($_POST['search'] ?? ''));
    $filter = sanitize_key($_POST['filter'] ?? 'all');

    $where = ['c.list_id=%d'];
    $args = [$list_id];

    if ($search !== '') {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where[] = '(c.email LIKE %s OR c.source_url LIKE %s)';
        $args[] = $like;
        $args[] = $like;
    }

    if ($filter === 'blocked') {
        $where[] = 'b.id IS NOT NULL';
    } elseif ($filter === 'allowed') {
        $where[] = 'b.id IS NULL';
    }

    $where_sql = implode(' AND ', $where);
    $table_contacts = cmsfc_table('contacts');
    $table_blacklist = cmsfc_table('blacklist');

    $total = (int)$wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_contacts c
             LEFT JOIN $table_blacklist b ON b.email=c.email
             WHERE $where_sql",
            $args
        )
    );

    $per_page = 50;
    $offset = ($page - 1) * $per_page;

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT c.id,c.email,c.source_url,c.created_at,
             IF(b.id IS NULL,0,1) AS blocked
             FROM $table_contacts c
             LEFT JOIN $table_blacklist b ON b.email=c.email
             WHERE $where_sql
             ORDER BY c.id DESC
             LIMIT %d OFFSET %d",
            array_merge($args, [$per_page, $offset])
        ),
        ARRAY_A
    );

    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['blocked'] = (int)$row['blocked'];
    }

    wp_send_json_success([
        'rows' => $rows,
        'total' => $total,
        'page' => $page,
        'pages' => max(1, (int)ceil($total / $per_page))
    ]);
});

/* ---------------------------------------------------------
 * 13. AJAX: видалення одного або кількох контактів
 * --------------------------------------------------------- */

add_action('wp_ajax_cmsfc_contacts_delete', function () {
    cmsfc_ajax_guard();

    global $wpdb;

    $ids = array_values(array_filter(array_map(
        'intval',
        (array)($_POST['ids'] ?? [])
    )));

    if (!$ids) {
        wp_send_json_error('Не вибрано адрес.');
    }

    $wpdb->query(
        "DELETE FROM " . cmsfc_table('contacts') .
        " WHERE id IN (" . implode(',', $ids) . ")"
    );

    wp_send_json_success(['deleted' => count($ids)]);
});

/* ---------------------------------------------------------
 * 14. AJAX: копіювання адрес і CSV-експорт
 * --------------------------------------------------------- */

add_action('wp_ajax_cmsfc_contacts_copy', function () {
    cmsfc_ajax_guard();

    global $wpdb;

    $list_id = (int)($_POST['list_id'] ?? 0);

    $emails = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT email FROM " . cmsfc_table('contacts') . "
             WHERE list_id=%d ORDER BY email ASC",
            $list_id
        )
    );

    wp_send_json_success([
        'emails' => implode("\n", $emails),
        'count' => count($emails)
    ]);
});

add_action('admin_post_cmsfc_export', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Недостатньо прав.');
    }

    check_admin_referer('cmsfc_export');

    global $wpdb;

    $list_id = (int)($_GET['list_id'] ?? 0);

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT email,source_url,created_at
             FROM " . cmsfc_table('contacts') . "
             WHERE list_id=%d ORDER BY email ASC",
            $list_id
        ),
        ARRAY_A
    );

    while (ob_get_level()) {
        ob_end_clean();
    }

    nocache_headers();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cms-web-finder-emails.csv"');

    $out = fopen('php://output', 'w');

    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, ['Email', 'Сторінка-джерело', 'Дата збору'], ';');

    foreach ($rows as $row) {
        fputcsv($out, [
            $row['email'],
            $row['source_url'],
            $row['created_at']
        ], ';');
    }

    fclose($out);
    exit;
});

/* ---------------------------------------------------------
 * 15. AJAX: глобальний блеклист
 * --------------------------------------------------------- */

add_action('wp_ajax_cmsfc_blacklist_add', function () {
    cmsfc_ajax_guard();

    $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
    $reason = sanitize_text_field(wp_unslash($_POST['reason'] ?? ''));

    if (!is_email($email)) {
        wp_send_json_error('Некоректна email-адреса.');
    }

    cmsf_contact_blacklist_add($email, $reason, 'manual');

    wp_send_json_success('Адресу додано до глобального блеклиста.');
});

add_action('wp_ajax_cmsfc_blacklist', function () {
    cmsfc_ajax_guard();

    global $wpdb;

    $rows = $wpdb->get_results(
        "SELECT * FROM " . cmsfc_table('blacklist') . " ORDER BY id DESC LIMIT 1000",
        ARRAY_A
    );

    wp_send_json_success($rows);
});

add_action('wp_ajax_cmsfc_blacklist_remove', function () {
    cmsfc_ajax_guard();

    global $wpdb;

    $wpdb->delete(
        cmsfc_table('blacklist'),
        ['id' => (int)($_POST['id'] ?? 0)]
    );

    wp_send_json_success('Адресу розблоковано.');
});

/* ---------------------------------------------------------
 * 16. Створення кампанії на основі наявного шаблону
 * --------------------------------------------------------- */

add_action('wp_ajax_cmsfc_campaign_start', function () {
    cmsfc_ajax_guard();

    global $wpdb;

    $list_id = (int)($_POST['list_id'] ?? 0);
    $template_id = (int)($_POST['template_id'] ?? 0);
    $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));

    $list = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM " . cmsfc_table('lists') . " WHERE id=%d",
            $list_id
        ),
        ARRAY_A
    );

    $template = cmsf_template_get($template_id);

    if (!$list || !$template) {
        wp_send_json_error('Список або шаблон не знайдено.');
    }

    if ($name === '') {
        $name = $list['domain'] . ' — ' . $template['name'];
    }

    $wpdb->insert(
        cmsfc_table('campaigns'),
        [
            'list_id' => $list_id,
            'template_id' => $template_id,
            'name' => $name,
            'status' => 'running',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ]
    );

    $campaign_id = (int)$wpdb->insert_id;

    if (!$campaign_id) {
        wp_send_json_error('Не вдалося створити кампанію.');
    }

    // Додаємо до черги лише адреси, які не заблоковані.
    $contacts = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT c.email,c.source_url
             FROM " . cmsfc_table('contacts') . " c
             LEFT JOIN " . cmsfc_table('blacklist') . " b ON b.email=c.email
             WHERE c.list_id=%d AND b.id IS NULL
             ORDER BY c.id ASC",
            $list_id
        ),
        ARRAY_A
    );

    foreach ($contacts as $contact) {
        $wpdb->insert(
            cmsfc_table('recipients'),
            [
                'campaign_id' => $campaign_id,
                'email' => $contact['email'],
                'token' => cmsf_contact_make_token(),
                'source_url' => $contact['source_url'],
                'status' => 'queued',
                'created_at' => current_time('mysql')
            ]
        );
    }

    wp_send_json_success(['campaign_id' => $campaign_id]);
});

/* ---------------------------------------------------------
 * 17. AJAX: відправлення наступної порції кампанії
 * --------------------------------------------------------- */

add_action('wp_ajax_cmsfc_campaign_step', function () {
    cmsfc_ajax_guard();

    global $wpdb;

    @set_time_limit(60);

    $campaign_id = (int)($_POST['campaign_id'] ?? 0);

    $campaign = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM " . cmsfc_table('campaigns') . " WHERE id=%d",
            $campaign_id
        ),
        ARRAY_A
    );

    if (!$campaign) {
        wp_send_json_error('Кампанію не знайдено.');
    }

    $template = cmsf_template_get((int)$campaign['template_id']);

    if (!$template) {
        wp_send_json_error('Шаблон не знайдено.');
    }

    $recipients = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM " . cmsfc_table('recipients') . "
             WHERE campaign_id=%d AND status='queued'
             ORDER BY id ASC LIMIT 3",
            $campaign_id
        ),
        ARRAY_A
    );

    foreach ($recipients as $recipient) {
        $email = sanitize_email($recipient['email']);

        // Перевірка блеклиста безпосередньо перед кожною відправкою.
        if (!is_email($email) || cmsf_contact_is_blocked($email)) {
            $wpdb->update(
                cmsfc_table('recipients'),
                [
                    'status' => 'blocked',
                    'error' => 'Адреса заблокована або некоректна'
                ],
                ['id' => (int)$recipient['id']]
            );

            $campaign['failed']++;
            continue;
        }

        $site = [
            'url' => $recipient['source_url'],
            'domain' => wp_parse_url($recipient['source_url'], PHP_URL_HOST),
            'title' => '',
            'cms' => '',
            'version' => '',
            'latest' => '',
            'email' => $email
        ];

        $subject = preg_replace(
            '/[\r\n]+/',
            ' ',
            cmsf_render($template['subject'], $site)
        );

        $body = cmsf_render(
            $template['body'],
            $site,
            !empty($template['is_html'])
        );

        $unsubscribe = cmsf_contact_unsubscribe_url($recipient['token']);

        // Додаємо посилання відписки до кожного листа.
        if (!empty($template['is_html'])) {
            $body .= '<p style="font-size:12px;color:#777;margin-top:25px">' .
                '<a href="' . esc_url($unsubscribe) . '">Відписатися від розсилки</a>' .
                '</p>';
        } else {
            $body .= "\n\nВідписатися від розсилки:\n" . $unsubscribe;
        }

        $headers = cmsf_mail_headers(!empty($template['is_html']));

        $ok = wp_mail($email, $subject, $body, $headers);

        $wpdb->update(
            cmsfc_table('recipients'),
            [
                'status' => $ok ? 'sent' : 'failed',
                'error' => $ok ? '' : 'Помилка wp_mail',
                'sent_at' => current_time('mysql')
            ],
            ['id' => (int)$recipient['id']]
        );

        if ($ok) {
            $campaign['sent']++;
        } else {
            $campaign['failed']++;
        }
    }

    $pending = (int)$wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM " . cmsfc_table('recipients') . "
             WHERE campaign_id=%d AND status='queued'",
            $campaign_id
        )
    );

    $done = $pending === 0;

    $wpdb->update(
        cmsfc_table('campaigns'),
        [
            'sent' => (int)$campaign['sent'],
            'failed' => (int)$campaign['failed'],
            'status' => $done ? 'done' : 'running',
            'updated_at' => current_time('mysql')
        ],
        ['id' => $campaign_id]
    );

    wp_send_json_success([
        'sent' => (int)$campaign['sent'],
        'failed' => (int)$campaign['failed'],
        'pending' => $pending,
        'done' => $done
    ]);
});

/* ---------------------------------------------------------
 * 18. Сторінка модуля в адмінці WordPress
 * --------------------------------------------------------- */

add_action('admin_menu', function () {
    add_submenu_page(
        'cms-checker',
        'Збір email',
        'Збір email',
        'manage_options',
        'cmsf-contacts',
        'cmsfc_admin_page'
    );
});

/* ---------------------------------------------------------
 * 19. Підключення окремих JS та CSS модуля
 * --------------------------------------------------------- */

add_action('admin_enqueue_scripts', function ($hook) {
    if (($_GET['page'] ?? '') !== 'cmsf-contacts') {
        return;
    }

    wp_enqueue_style(
        'cmsfc-contacts',
        CMSF_URL . 'assets/contacts.css',
        [],
        CMSF_VERSION
    );

    wp_enqueue_script(
        'cmsfc-contacts',
        CMSF_URL . 'assets/contacts.js',
        [],
        CMSF_VERSION,
        true
    );

    wp_localize_script('cmsfc-contacts', 'CMSFC', [
        'ajax' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('cmsfc'),
        'exportUrl' => admin_url('admin-post.php'),
        'exportNonce' => wp_create_nonce('cmsfc_export')
    ]);
});

/* ---------------------------------------------------------
 * 20. HTML сторінки: сканування, email, кампанії, блеклист
 * --------------------------------------------------------- */

function cmsfc_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $templates = cmsf_templates_all();
    ?>

    <div class="wrap cmsfc" id="cmsfc-app">

        <h1>CMS Web Finder — збір контактів</h1>

        <div class="cmsfc-card">
            <h2>1. Зібрати email із сайту</h2>

            <div class="cmsfc-grid">

                <label>
                    Домен сайту
                    <input type="text" id="cmsfc-domain" placeholder="example.com.ua">
                </label>

                <label>
                    Назва списку
                    <input type="text" id="cmsfc-name" placeholder="Автоматично: домен — контакти">
                </label>

                <label>
                    Максимальна кількість сторінок
                    <input type="number" id="cmsfc-max-pages" min="1" max="5000" value="100">
                </label>

                <label>
                    Глибина переходів
                    <input type="number" id="cmsfc-max-depth" min="0" max="10" value="3">
                </label>

            </div>

            <div class="cmsfc-actions">
                <button type="button" class="button button-primary" id="cmsfc-start">Почати збір</button>
                <button type="button" class="button" id="cmsfc-pause">Пауза</button>
                <button type="button" class="button" id="cmsfc-resume">Продовжити</button>
                <button type="button" class="button" id="cmsfc-stop">Зупинити</button>
            </div>

            <div class="cmsfc-progress">
                <span id="cmsfc-progress-fill"></span>
            </div>

            <div id="cmsfc-status">Очікування запуску.</div>
            <div id="cmsfc-counters"></div>
        </div>

        <div class="cmsfc-card">
            <h2>2. Збережені списки контактів</h2>

            <div class="cmsfc-table-wrap">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Назва списку</th>
                            <th>Домен</th>
                            <th>Сторінок</th>
                            <th>Email</th>
                            <th>Статус</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <div class="cmsfc-card">
            <h2>3. Перегляд знайдених email</h2>

            <div class="cmsfc-grid">
                <label>
                    Виберіть список
                    <select id="cmsfc-view-list">
                        <option value="">— Спочатку виберіть список —</option>
                    </select>
                </label>

                <label>
                    Пошук email або сторінки
                    <input type="search" id="cmsfc-email-search" placeholder="Введіть адресу або частину URL">
                </label>

                <label>
                    Фільтр
                    <select id="cmsfc-email-filter">
                        <option value="all">Усі адреси</option>
                        <option value="allowed">Не в блеклисті</option>
                        <option value="blocked">У блеклисті</option>
                    </select>
                </label>
            </div>

            <div class="cmsfc-actions">
                <button type="button" class="button" id="cmsfc-email-refresh">Показати список</button>
                <button type="button" class="button" id="cmsfc-email-copy">Копіювати всі email</button>
                <button type="button" class="button" id="cmsfc-email-export">Експорт CSV</button>
                <button type="button" class="button button-link-delete" id="cmsfc-email-delete">Видалити вибрані</button>
            </div>

            <p id="cmsfc-email-info">Виберіть список, щоб переглянути його адреси.</p>

            <div class="cmsfc-table-wrap">
                <table class="widefat striped" id="cmsfc-email-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="cmsfc-select-all"></th>
                            <th>Email</th>
                            <th>Сторінка, де знайдено</th>
                            <th>Дата</th>
                            <th>Блеклист</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="5">Адреси ще не завантажені.</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="cmsfc-actions">
                <button type="button" class="button" id="cmsfc-email-prev">← Назад</button>
                <span id="cmsfc-email-pages">Сторінка 1</span>
                <button type="button" class="button" id="cmsfc-email-next">Далі →</button>
            </div>
        </div>

        <div class="cmsfc-card">
            <h2>4. Створити кампанію</h2>

            <div class="cmsfc-grid">

                <label>
                    Список адрес
                    <select id="cmsfc-campaign-list">
                        <option value="">— Оберіть список —</option>
                    </select>
                </label>

                <label>
                    Шаблон листа
                    <select id="cmsfc-template">
                        <option value="">— Оберіть шаблон —</option>
                        <?php foreach ($templates as $template): ?>
                            <option value="<?php echo (int)$template['id']; ?>">
                                <?php echo esc_html($template['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="cmsfc-wide">
                    Назва кампанії
                    <input type="text" id="cmsfc-campaign-name" placeholder="Автоматична назва за доменом і шаблоном">
                </label>

            </div>

            <p class="description">
                Перед кожним листом адреса повторно перевіряється за глобальним блеклистом.
                У кожному листі є персональне посилання для відписки.
            </p>

            <div class="cmsfc-actions">
                <button type="button" class="button button-primary" id="cmsfc-campaign-start">Створити та запустити кампанію</button>
            </div>

            <div class="cmsfc-progress">
                <span id="cmsfc-campaign-fill"></span>
            </div>

            <div id="cmsfc-campaign-status"></div>
        </div>

        <div class="cmsfc-card">
            <h2>5. Глобальний блеклист</h2>

            <div class="cmsfc-grid">
                <label>
                    Email
                    <input type="email" id="cmsfc-blacklist-email" placeholder="person@example.com">
                </label>

                <label>
                    Причина блокування
                    <input type="text" id="cmsfc-blacklist-reason" placeholder="Попросили не надсилати листи">
                </label>
            </div>

            <div class="cmsfc-actions">
                <button type="button" class="button button-primary" id="cmsfc-blacklist-add">Додати до блеклиста</button>
            </div>

            <div id="cmsfc-blacklist-result"></div>
        </div>

    </div>

    <?php
}
