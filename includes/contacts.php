<?php
/**
 * CMS Web Finder — модуль збору публічних контактів.
 *
 * Відповідає за:
 * - створення окремих списків контактів;
 * - сканування відкритих сторінок сайту та його піддоменів;
 * - збереження знайдених email без дублікатів;
 * - паузу, продовження та зупинку сканування;
 * - глобальний список заборонених адрес;
 * - створення кампаній на основі наявних шаблонів;
 * - відписку за персональним посиланням без авторизації.
 */

if (!defined('ABSPATH')) exit;

/* =========================================================
 * 1. ТАБЛИЦІ БАЗИ ДАНИХ
 * ======================================================= */

/**
 * Створює таблиці модуля.
 * Викликається під час активації та оновлення плагіна.
 */
function cmsf_contacts_install() {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset = $wpdb->get_charset_collate();

    // Списки сайтів, які скануються для пошуку контактів.
    dbDelta("CREATE TABLE " . cmsf_t('contact_lists') . " (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(190) NOT NULL,
        domain varchar(190) NOT NULL,
        site_title varchar(255) NOT NULL DEFAULT '',
        status varchar(20) NOT NULL DEFAULT 'pending',
        max_pages int(11) NOT NULL DEFAULT 100,
        max_depth int(11) NOT NULL DEFAULT 3,
        pages_done int(11) NOT NULL DEFAULT 0,
        emails_found int(11) NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY status (status)
    ) $charset;");

    // Черга сторінок, які потрібно перевірити.
    dbDelta("CREATE TABLE " . cmsf_t('contact_pages') . " (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        list_id bigint(20) unsigned NOT NULL,
        url varchar(500) NOT NULL,
        depth int(11) NOT NULL DEFAULT 0,
        status varchar(20) NOT NULL DEFAULT 'pending',
        PRIMARY KEY  (id),
        UNIQUE KEY list_url (list_id,url(191)),
        KEY list_status (list_id,status)
    ) $charset;");

    // Знайдені публічні email.
    dbDelta("CREATE TABLE " . cmsf_t('contacts') . " (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        list_id bigint(20) unsigned NOT NULL,
        email varchar(255) NOT NULL,
        source_url varchar(500) NOT NULL DEFAULT '',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY list_email (list_id,email),
        KEY email (email(191))
    ) $charset;");

    // Глобальний блеклист — діє на всі списки та кампанії.
    dbDelta("CREATE TABLE " . cmsf_t('contact_blacklist') . " (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        email varchar(255) NOT NULL,
        token varchar(64) NOT NULL DEFAULT '',
        reason varchar(255) NOT NULL DEFAULT '',
        source varchar(30) NOT NULL DEFAULT 'manual',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY email (email),
        KEY token (token)
    ) $charset;");

    // Кампанії розсилки.
    dbDelta("CREATE TABLE " . cmsf_t('contact_campaigns') . " (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(190) NOT NULL,
        list_id bigint(20) unsigned NOT NULL,
        template_id bigint(20) unsigned NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        total int(11) NOT NULL DEFAULT 0,
        sent int(11) NOT NULL DEFAULT 0,
        failed int(11) NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id)
    ) $charset;");

    // Окрема черга одержувачів для кожної кампанії.
    dbDelta("CREATE TABLE " . cmsf_t('contact_recipients') . " (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        campaign_id bigint(20) unsigned NOT NULL,
        email varchar(255) NOT NULL,
        token varchar(64) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        error varchar(255) NOT NULL DEFAULT '',
        sent_at datetime NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY campaign_email (campaign_id,email),
        KEY campaign_status (campaign_id,status),
        KEY token (token)
    ) $charset;");

    update_option('cmsf_contacts_db_version', '1.0');
}

/* =========================================================
 * 2. ДОПОМІЖНІ ФУНКЦІЇ
 * ======================================================= */

/**
 * Перевіряє, чи адреса вже внесена до глобального блеклиста.
 */
function cmsf_contact_is_blocked($email) {
    global $wpdb;

    $email = strtolower(trim($email));

    if (!is_email($email)) {
        return true;
    }

    return (bool)$wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM " . cmsf_t('contact_blacklist') . " WHERE email=%s LIMIT 1",
            $email
        )
    );
}

/**
 * Створює або повертає персональний токен відписки.
 * Сам email у посилання не потрапляє.
 */
function cmsf_contact_unsubscribe_token($email) {
    global $wpdb;

    $email = strtolower(trim($email));
    $table = cmsf_t('contact_blacklist');

    // Якщо адреса вже у блеклисті, повторний токен не потрібен.
    $existing = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT token FROM $table WHERE email=%s LIMIT 1",
            $email
        )
    );

    if ($existing) {
        return $existing;
    }

    // Для посилання використовується випадковий токен.
    return hash_hmac(
        'sha256',
        $email,
        wp_salt('auth') . wp_generate_password(20, true, true)
    );
}

/**
 * Повертає URL сторінки відписки.
 */
function cmsf_contact_unsubscribe_url($email, $token = '') {
    global $wpdb;

    $email = strtolower(trim($email));

    // Знаходимо вже збережений токен для цієї адреси.
    $token = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT token FROM " . cmsf_t('contact_recipients') . "
             WHERE email=%s AND token<>'' ORDER BY id DESC LIMIT 1",
            $email
        )
    );

    // Якщо кампаній для адреси ще не було, створюємо токен.
    if (!$token) {
        $token = wp_generate_password(48, false, false);
    }

    return add_query_arg(
        ['cmsf_unsubscribe' => $token],
        home_url('/')
    );
}

/**
 * Додає адресу до глобального блеклиста.
 */
function cmsf_contact_blacklist_add($email, $reason = '', $source = 'manual') {
    global $wpdb;

    $email = strtolower(trim(sanitize_email($email)));

    if (!is_email($email)) {
        return false;
    }

    $source = in_array($source, ['manual', 'automatic'], true)
        ? $source
        : 'manual';

    $table = cmsf_t('contact_blacklist');

    $existing = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM $table WHERE email=%s",
            $email
        )
    );

    if ($existing) {
        return true;
    }

    $wpdb->insert($table, [
        'email' => $email,
        'token' => '',
        'reason' => sanitize_text_field($reason),
        'source' => $source,
        'created_at' => cmsf_now(),
    ]);

    return (bool)$wpdb->insert_id;
}

/* =========================================================
 * 3. АВТОМАТИЧНА ВІДПИСКА
 * ======================================================= */

/**
 * Обробляє публічне посилання відписки.
 * Відвідувачу не потрібно входити до WordPress.
 */
add_action('init', function () {
    if (empty($_GET['cmsf_unsubscribe'])) {
        return;
    }

    global $wpdb;

    $token = sanitize_text_field(wp_unslash($_GET['cmsf_unsubscribe']));

    if (strlen($token) < 20 || strlen($token) > 100) {
        wp_die('Посилання відписки некоректне.', 'Відписка', ['response' => 400]);
    }

    $recipient = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT email FROM " . cmsf_t('contact_recipients') . "
             WHERE token=%s LIMIT 1",
            $token
        ),
        ARRAY_A
    );

    if (!$recipient || !is_email($recipient['email'])) {
        wp_die('Посилання недійсне або вже неактивне.', 'Відписка', ['response' => 404]);
    }

    // Додаємо адресу до глобального блеклиста.
    cmsf_contact_blacklist_add(
        $recipient['email'],
        'Користувач скористався посиланням відписки',
        'automatic'
    );

    // Відображаємо просту сторінку підтвердження.
    wp_die(
        '<div style="max-width:600px;margin:60px auto;padding:25px;font:16px Arial;text-align:center">
            <h2>Відписку оформлено</h2>
            <p>Цю адресу додано до списку заборони розсилок.</p>
            <p>Нові листи на цю адресу надсилатися не повинні.</p>
        </div>',
        'Відписку оформлено',
        ['response' => 200]
    );
});

/* =========================================================
 * 4. БЕЗПЕЧНЕ СКАНУВАННЯ ПУБЛІЧНИХ СТОРІНОК
 * ======================================================= */

/**
 * Перевіряє адресу перед HTTP-запитом.
 * Не дозволяє сканувати localhost та приватні IP.
 */
function cmsf_contact_safe_url($url) {
    $url = esc_url_raw($url);
    $parts = wp_parse_url($url);

    if (
        empty($parts['host']) ||
        empty($parts['scheme']) ||
        !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
    ) {
        return false;
    }

    $host = strtolower($parts['host']);

    if (
        $host === 'localhost' ||
        substr($host, -6) === '.local' ||
        filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
        && filter_var($host, FILTER_VALIDATE_IP)
    ) {
        return false;
    }

    return $url;
}

/**
 * Завантажує одну публічну сторінку.
 */
function cmsf_contact_fetch_page($url) {
    $url = cmsf_contact_safe_url($url);

    if (!$url) {
        return false;
    }

    $response = wp_safe_remote_get($url, [
        'timeout' => 12,
        'redirection' => 3,
        'limit_response_size' => 1500000,
        'headers' => [
            'User-Agent' => 'CMS-Web-Finder-ContactScanner/1.0',
            'Accept' => 'text/html,application/xhtml+xml,text/plain',
        ],
    ]);

    if (is_wp_error($response)) {
        return false;
    }

    $code = (int)wp_remote_retrieve_response_code($response);

    if ($code < 200 || $code >= 300) {
        return false;
    }

    $type = wp_remote_retrieve_header($response, 'content-type');

    // Не завантажуємо картинки, архіви, відео та інші бінарні файли.
    if ($type && !preg_match('/text\/html|application\/xhtml|text\/plain/i', $type)) {
        return false;
    }

    return (string)wp_remote_retrieve_body($response);
}

/**
 * Визначає, чи належить посилання до основного домену або його піддоменів.
 */
function cmsf_contact_same_domain($host, $root) {
    $host = strtolower(trim($host, '.'));
    $root = strtolower(trim($root, '.'));

    return $host === $root || substr($host, -strlen('.' . $root)) === '.' . $root;
}

/**
 * Витягує email із HTML та тексту.
 * Обробляє звичайні адреси, mailto та поширене маскування [at]/(at).
 */
function cmsf_contact_extract_emails($html) {
    $found = [];

    $text = html_entity_decode(
        strip_tags($html),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );

    // Повертаємо поширені текстові варіанти написання email.
    $text = preg_replace('/\s*(\[at\]|\(at\)|\{at\})\s*/i', '@', $text);
    $text = preg_replace('/\s*(\[dot\]|\(dot\)|\{dot\})\s*/i', '.', $text);

    // Звичайні адреси у видимому тексті та HTML.
    if (preg_match_all(
        '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i',
        $text . ' ' . $html,
        $matches
    )) {
        foreach ($matches[0] as $email) {
            $email = strtolower(trim($email, " \t\n\r\0\x0B.,;:<>[](){}\"'"));

            if (is_email($email)) {
                $found[$email] = true;
            }
        }
    }

    // Адреси у посиланнях mailto.
    if (preg_match_all('/mailto:([^?\"\'\s>]+)/i', $html, $matches)) {
        foreach ($matches[1] as $email) {
            $email = strtolower(trim(rawurldecode($email)));

            if (is_email($email)) {
                $found[$email] = true;
            }
        }
    }

    return array_keys($found);
}

/**
 * Знаходить внутрішні посилання та публічні піддомени.
 */
function cmsf_contact_extract_links($html, $current_url, $root_domain, $depth) {
    $links = [];

    if (!class_exists('DOMDocument')) {
        return $links;
    }

    $previous = libxml_use_internal_errors(true);

    $dom = new DOMDocument();

    $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html);

    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded) {
        return $links;
    }

    $base = $current_url;

    foreach ($dom->getElementsByTagName('base') as $base_tag) {
        if ($base_tag->getAttribute('href')) {
            $base = $base_tag->getAttribute('href');
            break;
        }
    }

    foreach ($dom->getElementsByTagName('a') as $a) {
        $href = trim($a->getAttribute('href'));

        if (
            $href === '' ||
            strpos($href, 'mailto:') === 0 ||
            strpos($href, 'tel:') === 0 ||
            strpos($href, 'javascript:') === 0 ||
            strpos($href, '#') === 0
        ) {
            continue;
        }

        $absolute = WP_Http::make_absolute_url($href, $base);

        if (!$absolute) {
            continue;
        }

        $parts = wp_parse_url($absolute);

        if (empty($parts['host'])) {
            continue;
        }

        if (!cmsf_contact_same_domain($parts['host'], $root_domain)) {
            continue;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');

        if (!in_array($scheme, ['http', 'https'], true)) {
            continue;
        }

        // Не скануємо файли, зображення та документи.
        $path = $parts['path'] ?? '';

        if (preg_match('/\.(jpg|jpeg|png|gif|webp|svg|pdf|zip|rar|mp4|mp3|css|ico)$/i', $path)) {
            continue;
        }

        // Прибираємо фрагмент і параметри, щоб уникати нескінченних URL.
        $clean = $scheme . '://' . strtolower($parts['host']) . $path;

        if (!empty($parts['query'])) {
            $clean .= '?' . $parts['query'];
        }

        $links[$clean] = true;
    }

    return array_slice(array_keys($links), 0, 100);
}

/* =========================================================
 * 5. СТВОРЕННЯ СПИСКУ ТА ЧЕРГИ СКАНУВАННЯ
 * ======================================================= */

/**
 * Створює новий список контактів і додає головну сторінку до черги.
 */
function cmsf_contact_create_list($domain, $name, $max_pages, $max_depth) {
    global $wpdb;

    $domain = strtolower(trim($domain));
    $domain = preg_replace('#^https?://#i', '', $domain);
    $domain = trim(explode('/', $domain)[0]);

    if (
        !$domain ||
        !preg_match('/^(?=.{3,190}$)[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)
    ) {
        return new WP_Error('invalid_domain', 'Введіть коректний домен.');
    }

    $url = 'https://' . $domain;

    // Пробуємо отримати назву сайту з головної сторінки.
    $html = cmsf_contact_fetch_page($url);

    if (!$html) {
        $url = 'http://' . $domain;
        $html = cmsf_contact_fetch_page($url);
    }

    if (!$html) {
        return new WP_Error('site_unavailable', 'Не вдалося відкрити сайт.');
    }

    $title = '';

    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        $title = sanitize_text_field(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    if (!$name) {
        $name = $domain . ($title ? ' — ' . $title : '');
    }

    $name = mb_substr(sanitize_text_field($name), 0, 190);

    $now = cmsf_now();

    $wpdb->insert(cmsf_t('contact_lists'), [
        'name' => $name,
        'domain' => $domain,
        'site_title' => mb_substr($title, 0, 255),
        'status' => 'running',
        'max_pages' => max(1, min(5000, (int)$max_pages)),
        'max_depth' => max(0, min(10, (int)$max_depth)),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $list_id = (int)$wpdb->insert_id;

    if (!$list_id) {
        return new WP_Error('database_error', 'Не вдалося створити список.');
    }

    $wpdb->insert(cmsf_t('contact_pages'), [
        'list_id' => $list_id,
        'url' => $url,
        'depth' => 0,
        'status' => 'pending',
    ]);

    return [
        'list_id' => $list_id,
        'name' => $name,
        'title' => $title,
        'domain' => $domain,
    ];
}

/* =========================================================
 * 6. ОДИН КРОК СКАНУВАННЯ
 * ======================================================= */

/**
 * Обробляє невелику кількість сторінок за один AJAX-запит.
 * Це дозволяє показувати прогрес і керувати процесом із телефону.
 */
function cmsf_contact_scan_step($list_id) {
    global $wpdb;

    $list = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM " . cmsf_t('contact_lists') . " WHERE id=%d",
            $list_id
        ),
        ARRAY_A
    );

    if (!$list) {
        return new WP_Error('missing_list', 'Список не знайдено.');
    }

    if ($list['status'] !== 'running') {
        return [
            'status' => $list['status'],
            'done' => true,
            'pages' => (int)$list['pages_done'],
            'emails' => (int)$list['emails_found'],
        ];
    }

    $pages_table = cmsf_t('contact_pages');
    $contacts_table = cmsf_t('contacts');

    // Беремо максимум дві сторінки за один запит.
    $pages = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $pages_table
             WHERE list_id=%d AND status='pending'
             ORDER BY id ASC LIMIT 2",
            $list_id
        ),
        ARRAY_A
    );

    if (!$pages) {
        $wpdb->update(
            cmsf_t('contact_lists'),
            ['status' => 'completed', 'updated_at' => cmsf_now()],
            ['id' => $list_id]
        );

        return [
            'status' => 'completed',
            'done' => true,
            'pages' => (int)$list['pages_done'],
            'emails' => (int)$list['emails_found'],
        ];
    }

    $new_emails = 0;

    foreach ($pages as $page) {
        $wpdb->update(
            $pages_table,
            ['status' => 'processing'],
            ['id' => $page['id']]
        );

        $html = cmsf_contact_fetch_page($page['url']);

        if ($html !== false) {
            // Зберігаємо тільки коректні адреси, які не заблоковані.
            $emails = cmsf_contact_extract_emails($html);

            foreach ($emails as $email) {
                if (cmsf_contact_is_blocked($email)) {
                    continue;
                }

                $wpdb->query(
                    $wpdb->prepare(
                        "INSERT IGNORE INTO $contacts_table
                         (list_id,email,source_url,created_at)
                         VALUES (%d,%s,%s,%s)",
                        $list_id,
                        $email,
                        esc_url_raw($page['url']),
                        cmsf_now()
                    )
                );

                if ($wpdb->rows_affected > 0) {
                    $new_emails++;
                }
            }

            // Додаємо внутрішні сторінки, якщо не перевищено глибину.
            if ((int)$page['depth'] < (int)$list['max_depth']) {
                $links = cmsf_contact_extract_links(
                    $html,
                    $page['url'],
                    $list['domain'],
                    (int)$page['depth']
                );

                foreach ($links as $link) {
                    $wpdb->query(
                        $wpdb->prepare(
                            "INSERT IGNORE INTO $pages_table
                             (list_id,url,depth,status)
                             VALUES (%d,%s,%d,'pending')",
                            $list_id,
                            $link,
                            (int)$page['depth'] + 1
                        )
                    );
                }
            }
        }

        $wpdb->update(
            $pages_table,
            ['status' => 'done'],
            ['id' => $page['id']]
        );

        $wpdb->update(
            cmsf_t('contact_lists'),
            [
                'pages_done' => (int)$list['pages_done'] + 1,
                'updated_at' => cmsf_now(),
            ],
            ['id' => $list_id]
        );

        $list['pages_done']++;
    }

    // Обмежуємо загальну кількість сторінок заданим користувачем лімітом.
    if ((int)$list['pages_done'] >= (int)$list['max_pages']) {
        $wpdb->update(
            cmsf_t('contact_lists'),
            ['status' => 'completed', 'updated_at' => cmsf_now()],
            ['id' => $list_id]
        );
    }

    $total_emails = (int)$wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM $contacts_table WHERE list_id=%d",
            $list_id
        )
    );

    $wpdb->update(
        cmsf_t('contact_lists'),
        ['emails_found' => $total_emails],
        ['id' => $list_id]
    );

    $remaining = (int)$wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM $pages_table
             WHERE list_id=%d AND status='pending'",
            $list_id
        )
    );

    $current = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT status FROM " . cmsf_t('contact_lists') . " WHERE id=%d",
            $list_id
        ),
        ARRAY_A
    );

    if ($remaining === 0 && $current['status'] === 'running') {
        $wpdb->update(
            cmsf_t('contact_lists'),
            ['status' => 'completed'],
            ['id' => $list_id]
        );

        $current['status'] = 'completed';
    }

    return [
        'status' => $current['status'],
        'done' => $current['status'] !== 'running',
        'pages' => (int)$list['pages_done'],
        'emails' => $total_emails,
        'new' => $new_emails,
        'remaining' => $remaining,
    ];
}

/* =========================================================
 * 7. КАМПАНІЇ ТА РОЗСИЛКА
 * ======================================================= */

/**
 * Створює кампанію та формує чергу одержувачів.
 */
function cmsf_contact_campaign_create($list_id, $template_id, $name) {
    global $wpdb;

    $list = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM " . cmsf_t('contact_lists') . " WHERE id=%d",
            $list_id
        ),
        ARRAY_A
    );

    $template = cmsf_template_get($template_id);

    if (!$list || !$template) {
        return new WP_Error('invalid_campaign', 'Список або шаблон не знайдено.');
    }

    $name = sanitize_text_field($name);

    if (!$name) {
        $name = $list['domain'] . ' — ' . $list['site_title'];
    }

    $wpdb->insert(cmsf_t('contact_campaigns'), [
        'name' => mb_substr($name, 0, 190),
        'list_id' => $list_id,
        'template_id' => $template_id,
        'status' => 'running',
        'created_at' => cmsf_now(),
        'updated_at' => cmsf_now(),
    ]);

    $campaign_id = (int)$wpdb->insert_id;

    if (!$campaign_id) {
        return new WP_Error('campaign_error', 'Не вдалося створити кампанію.');
    }

    $contacts = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT email FROM " . cmsf_t('contacts') . " WHERE list_id=%d",
            $list_id
        ),
        ARRAY_A
    );

    $total = 0;

    foreach ($contacts as $contact) {
        $email = strtolower(trim($contact['email']));

        // Перевіряємо блеклист перед додаванням до кампанії.
        if (!is_email($email) || cmsf_contact_is_blocked($email)) {
            continue;
        }

        $token = wp_generate_password(48, false, false);

        $wpdb->insert(cmsf_t('contact_recipients'), [
            'campaign_id' => $campaign_id,
            'email' => $email,
            'token' => $token,
            'status' => 'pending',
        ]);

        if ($wpdb->insert_id) {
            $total++;
        }
    }

    $wpdb->update(
        cmsf_t('contact_campaigns'),
        ['total' => $total],
        ['id' => $campaign_id]
    );

    return [
        'campaign_id' => $campaign_id,
        'total' => $total,
    ];
}

/**
 * Надсилає одну порцію кампанії.
 * Перед кожним листом повторно перевіряє глобальний блеклист.
 */
function cmsf_contact_campaign_step($campaign_id) {
    global $wpdb;

    $campaign = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM " . cmsf_t('contact_campaigns') . " WHERE id=%d",
            $campaign_id
        ),
        ARRAY_A
    );

    if (!$campaign) {
        return new WP_Error('missing_campaign', 'Кампанію не знайдено.');
    }

    if ($campaign['status'] !== 'running') {
        return [
            'done' => true,
            'status' => $campaign['status'],
            'sent' => (int)$campaign['sent'],
            'failed' => (int)$campaign['failed'],
        ];
    }

    $template = cmsf_template_get((int)$campaign['template_id']);

    if (!$template) {
        return new WP_Error('missing_template', 'Шаблон не знайдено.');
    }

    $recipients_table = cmsf_t('contact_recipients');

    // Невелика порція — менше навантаження на сервер.
    $recipients = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $recipients_table
             WHERE campaign_id=%d AND status='pending'
             ORDER BY id ASC LIMIT 3",
            $campaign_id
        ),
        ARRAY_A
    );

    if (!$recipients) {
        $wpdb->update(
            cmsf_t('contact_campaigns'),
            ['status' => 'completed', 'updated_at' => cmsf_now()],
            ['id' => $campaign_id]
        );

        return [
            'done' => true,
            'status' => 'completed',
            'sent' => (int)$campaign['sent'],
            'failed' => (int)$campaign['failed'],
        ];
    }

    $list = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM " . cmsf_t('contact_lists') . " WHERE id=%d",
            $campaign['list_id']
        ),
        ARRAY_A
    );

    $sent = 0;
    $failed = 0;

    foreach ($recipients as $recipient) {
        $email = strtolower(trim($recipient['email']));

        // Критично: блеклист перевіряється саме перед відправленням.
        if (cmsf_contact_is_blocked($email)) {
            $wpdb->update(
                $recipients_table,
                [
                    'status' => 'blocked',
                    'error' => 'Адреса перебуває у глобальному блеклисті',
                ],
                ['id' => $recipient['id']]
            );

            continue;
        }

        $site = [
            'url' => 'https://' . $list['domain'],
            'domain' => $list['domain'],
            'title' => $list['site_title'],
            'cms' => '',
            'version' => '',
            'latest' => '',
            'email' => $email,
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

        // Персональне посилання відписки.
        $unsubscribe = add_query_arg(
            ['cmsf_unsubscribe' => $recipient['token']],
            home_url('/')
        );

        $footer = 'Якщо ви не бажаєте отримувати подальші листи, відпишіться: ' . $unsubscribe;

        if (!empty($template['is_html'])) {
            $body .= '<p>' . esc_html($footer) . '</p>';
        } else {
            $body .= "\n\n" . $footer;
        }

        $headers = cmsf_mail_headers(!empty($template['is_html']));

        $ok = wp_mail($email, $subject, $body, $headers);

        if ($ok) {
            $wpdb->update(
                $recipients_table,
                [
                    'status' => 'sent',
                    'sent_at' => cmsf_now(),
                ],
                ['id' => $recipient['id']]
            );

            $sent++;
        } else {
            $wpdb->update(
                $recipients_table,
                [
                    'status' => 'failed',
                    'error' => 'wp_mail повернув помилку',
                ],
                ['id' => $recipient['id']]
            );

            $failed++;
        }
    }

    $wpdb->query(
        $wpdb->prepare(
            "UPDATE " . cmsf_t('contact_campaigns') . "
             SET sent=sent+%d, failed=failed+%d, updated_at=%s
             WHERE id=%d",
            $sent,
            $failed,
            cmsf_now(),
            $campaign_id
        )
    );

    $updated = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM " . cmsf_t('contact_campaigns') . " WHERE id=%d",
            $campaign_id
        ),
        ARRAY_A
    );

    $pending = (int)$wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM $recipients_table
             WHERE campaign_id=%d AND status='pending'",
            $campaign_id
        )
    );

    if (!$pending) {
        $wpdb->update(
            cmsf_t('contact_campaigns'),
            ['status' => 'completed'],
            ['id' => $campaign_id]
        );

        $updated['status'] = 'completed';
    }

    return [
        'done' => $pending === 0,
        'status' => $updated['status'],
        'sent' => (int)$updated['sent'],
        'failed' => (int)$updated['failed'],
        'pending' => $pending,
    ];
}

/* =========================================================
 * 8. AJAX: ЗАХИЩЕНІ ДІЇ АДМІНІСТРАТОРА
 * ======================================================= */

/**
 * Реєструє AJAX-обробник з перевіркою nonce та прав адміністратора.
 */
function cmsf_contacts_ajax($action, $callback) {
    add_action('wp_ajax_cmsfc_' . $action, function () use ($callback) {
        check_ajax_referer('cmsfc', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Недостатньо прав.');
        }

        $callback();
    });
}

/* Створення списку та старт сканування. */
cmsf_contacts_ajax('start', function () {
    $domain = sanitize_text_field(wp_unslash($_POST['domain'] ?? ''));
    $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
    $pages = max(1, min(5000, (int)($_POST['max_pages'] ?? 100)));
    $depth = max(0, min(10, (int)($_POST['max_depth'] ?? 3)));

    $result = cmsf_contact_create_list($domain, $name, $pages, $depth);

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    wp_send_json_success($result);
});

/* Обробка наступної порції сторінок. */
cmsf_contacts_ajax('step', function () {
    $result = cmsf_contact_scan_step((int)($_POST['list_id'] ?? 0));

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    wp_send_json_success($result);
});

/* Пауза, продовження або зупинка сканування. */
cmsf_contacts_ajax('control', function () {
    global $wpdb;

    $id = (int)($_POST['list_id'] ?? 0);
    $command = sanitize_key($_POST['command'] ?? '');

    $allowed = [
        'pause' => 'paused',
        'resume' => 'running',
        'stop' => 'stopped',
    ];

    if (!isset($allowed[$command])) {
        wp_send_json_error('Невідома команда.');
    }

    $wpdb->update(
        cmsf_t('contact_lists'),
        [
            'status' => $allowed[$command],
            'updated_at' => cmsf_now(),
        ],
        ['id' => $id]
    );

    wp_send_json_success(['status' => $allowed[$command]]);
});

/* Повертає списки контактів. */
cmsf_contacts_ajax('lists', function () {
    global $wpdb;

    $rows = $wpdb->get_results(
        "SELECT l.*,
        (SELECT COUNT(*) FROM " . cmsf_t('contacts') . " c WHERE c.list_id=l.id) AS contacts_count
        FROM " . cmsf_t('contact_lists') . " l
        ORDER BY l.id DESC",
        ARRAY_A
    );

    wp_send_json_success($rows);
});

/* Створення кампанії. */
cmsf_contacts_ajax('campaign_start', function () {
    $result = cmsf_contact_campaign_create(
        (int)($_POST['list_id'] ?? 0),
        (int)($_POST['template_id'] ?? 0),
        sanitize_text_field(wp_unslash($_POST['name'] ?? ''))
    );

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    wp_send_json_success($result);
});

/* Наступна порція листів. */
cmsf_contacts_ajax('campaign_step', function () {
    $result = cmsf_contact_campaign_step(
        (int)($_POST['campaign_id'] ?? 0)
    );

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    wp_send_json_success($result);
});

/* Додавання адреси до блеклиста вручну. */
cmsf_contacts_ajax('blacklist_add', function () {
    $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
    $reason = sanitize_text_field(wp_unslash($_POST['reason'] ?? ''));

    if (!cmsf_contact_blacklist_add($email, $reason, 'manual')) {
        wp_send_json_error('Не вдалося додати адресу. Перевірте email.');
    }

    wp_send_json_success('Адресу додано до глобального блеклиста.');
});

/* Перегляд глобального блеклиста. */
cmsf_contacts_ajax('blacklist', function () {
    global $wpdb;

    $rows = $wpdb->get_results(
        "SELECT * FROM " . cmsf_t('contact_blacklist') . " ORDER BY id DESC LIMIT 500",
        ARRAY_A
    );

    wp_send_json_success($rows);
});

/* Розблокування адреси адміністратором. */
cmsf_contacts_ajax('blacklist_remove', function () {
    global $wpdb;

    $id = (int)($_POST['id'] ?? 0);

    $wpdb->delete(
        cmsf_t('contact_blacklist'),
        ['id' => $id]
    );

    wp_send_json_success('Адресу розблоковано.');
});

/* =========================================================
 * 9. СТОРІНКА АДМІНІСТРУВАННЯ
 * ======================================================= */

/**
 * Додає нову сторінку в меню CMS Web Finder.
 */
add_action('admin_menu', function () {
    add_submenu_page(
        'cms-checker',
        'Збір контактів',
        'Контакти та розсилка',
        'manage_options',
        'cmsf-contacts',
        'cmsf_contacts_admin_page'
    );
});

/**
 * Підключає окремий JavaScript і CSS тільки на сторінці контактів.
 */
add_action('admin_enqueue_scripts', function ($hook) {
    if (empty($_GET['page']) || $_GET['page'] !== 'cmsf-contacts') {
        return;
    }

    wp_enqueue_style(
        'cmsf-contacts',
        CMSF_URL . 'assets/contacts.css',
        [],
        CMSF_VERSION
    );

    wp_enqueue_script(
        'cmsf-contacts',
        CMSF_URL . 'assets/contacts.js',
        [],
        CMSF_VERSION,
        true
    );

    wp_localize_script('cmsf-contacts', 'CMSFC', [
        'ajax' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('cmsfc'),
    ]);
});

/**
 * Виводить інтерфейс збору контактів і кампаній.
 */
function cmsf_contacts_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    global $wpdb;

    $lists = $wpdb->get_results(
        "SELECT * FROM " . cmsf_t('contact_lists') . " ORDER BY id DESC",
        ARRAY_A
    );

    $templates = cmsf_templates_all();

    $blacklist_count = (int)$wpdb->get_var(
        "SELECT COUNT(*) FROM " . cmsf_t('contact_blacklist')
    );
    ?>
    <div class="wrap cmsfc" id="cmsfc-app">

        <h1>Контакти та розсилка</h1>

        <div class="cmsfc-card">
            <h2>1. Збір публічних email</h2>

            <p>
                Вкажіть домен. Сканер перевірить відкриті сторінки сайту
                та знайдені на них внутрішні посилання й піддомени.
            </p>

            <div class="cmsfc-grid">
                <label>
                    Домен сайту
                    <input type="text" id="cmsfc-domain" placeholder="example.com.ua">
                </label>

                <label>
                    Назва списку
                    <input type="text" id="cmsfc-name" placeholder="Автоматично: домен і назва сайту">
                </label>

                <label>
                    Максимум сторінок
                    <input type="number" id="cmsfc-max-pages" value="100" min="1" max="5000">
                </label>

                <label>
                    Глибина переходів
                    <input type="number" id="cmsfc-max-depth" value="3" min="0" max="10">
                </label>
            </div>

            <p>
                <button class="button button-primary" id="cmsfc-start">
                    Почати сканування
                </button>
                <button class="button" id="cmsfc-pause" disabled>Пауза</button>
                <button class="button" id="cmsfc-resume" disabled>Продовжити</button>
                <button class="button" id="cmsfc-stop" disabled>Зупинити</button>
            </p>

            <div class="cmsfc-progress">
                <div id="cmsfc-progress-fill"></div>
            </div>

            <p id="cmsfc-status">Очікування запуску.</p>
            <p id="cmsfc-counters"></p>
        </div>

        <div class="cmsfc-card">
            <h2>2. Створені списки контактів</h2>

            <div class="cmsfc-table-wrap">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Назва</th>
                            <th>Домен</th>
                            <th>Сторінок</th>
                            <th>Email</th>
                            <th>Стан</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($lists): ?>
                        <?php foreach ($lists as $list): ?>
                            <tr>
                                <td><?php echo esc_html($list['name']); ?></td>
                                <td><?php echo esc_html($list['domain']); ?></td>
                                <td><?php echo (int)$list['pages_done']; ?></td>
                                <td><?php echo (int)$list['emails_found']; ?></td>
                                <td><?php echo esc_html($list['status']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5">Списків поки немає.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="cmsfc-card">
            <h2>3. Кампанія розсилки</h2>

            <div class="cmsfc-grid">
                <label>
                    Список контактів
                    <select id="cmsfc-campaign-list">
                        <option value="">Оберіть список</option>
                        <?php foreach ($lists as $list): ?>
                            <option value="<?php echo (int)$list['id']; ?>">
                                <?php echo esc_html($list['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Шаблон листа
                    <select id="cmsfc-template">
                        <option value="">Оберіть шаблон</option>
                        <?php foreach ($templates as $template): ?>
                            <option value="<?php echo (int)$template['id']; ?>">
                                <?php echo esc_html($template['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="cmsfc-wide">
                    Назва кампанії
                    <input type="text" id="cmsfc-campaign-name"
                        placeholder="Автоматично: домен і назва сайту">
                </label>
            </div>

            <p>
                <button class="button button-primary" id="cmsfc-campaign-start">
                    Створити та запустити кампанію
                </button>
            </p>

            <div class="cmsfc-progress">
                <div id="cmsfc-campaign-fill"></div>
            </div>

            <p id="cmsfc-campaign-status"></p>
        </div>

        <div class="cmsfc-card">
            <h2>4. Глобальний блеклист</h2>

            <p>
                Заблоковані адреси не повинні отримувати листи
                з жодного списку чи кампанії.
            </p>

            <p>
                Зараз у блеклисті:
                <strong><?php echo (int)$blacklist_count; ?></strong>
            </p>

            <div class="cmsfc-grid">
                <label>
                    Email для блокування
                    <input type="email" id="cmsfc-blacklist-email" placeholder="name@example.com">
                </label>

                <label>
                    Причина
                    <input type="text" id="cmsfc-blacklist-reason" placeholder="Попросили не надсилати">
                </label>
            </div>

            <p>
                <button class="button" id="cmsfc-blacklist-add">
                    Додати до блеклиста
                </button>
            </p>

            <div id="cmsfc-blacklist-result"></div>
        </div>

        <p class="description">
            Збір призначений для відкритих контактних адрес.
            Перед маркетинговою розсилкою переконайтеся, що маєте
            належну правову підставу для звернення до адресата.
            Кожен лист кампанії містить персональне посилання відписки.
        </p>

    </div>
    <?php
}
