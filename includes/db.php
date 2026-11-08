<?php
if (!defined('ABSPATH')) exit;

function cmsf_t($name) {
    global $wpdb;
    return $wpdb->prefix . 'cmsf_' . $name;
}

function cmsf_now() {
    return current_time('mysql');
}

function cmsf_install() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $c = $wpdb->get_charset_collate();

    dbDelta("CREATE TABLE " . cmsf_t('lists') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  params longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id)
) $c;");

    dbDelta("CREATE TABLE " . cmsf_t('sites') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  list_id bigint(20) unsigned NOT NULL,
  domain varchar(190) NOT NULL,
  url varchar(255) NOT NULL DEFAULT '',
  state varchar(12) NOT NULL DEFAULT 'queued',
  cms varchar(60) NOT NULL DEFAULT '',
  version varchar(40) NOT NULL DEFAULT '',
  latest varchar(40) NOT NULL DEFAULT '',
  outdated tinyint(1) NOT NULL DEFAULT 0,
  http_status varchar(40) NOT NULL DEFAULT '',
  title varchar(255) NOT NULL DEFAULT '',
  country varchar(120) NOT NULL DEFAULT '',
  language varchar(120) NOT NULL DEFAULT '',
  site_type varchar(60) NOT NULL DEFAULT '',
  relevance int(11) NOT NULL DEFAULT 0,
  email varchar(255) NOT NULL DEFAULT '',
  emails text NULL,
  phone varchar(255) NOT NULL DEFAULT '',
  note text NULL,
  reject_reason varchar(255) NOT NULL DEFAULT '',
  checked_at datetime NULL,
  sent_at datetime NULL,
  send_ok tinyint(1) NULL,
  send_error varchar(255) NOT NULL DEFAULT '',
  send_count int(11) NOT NULL DEFAULT 0,
  last_template bigint(20) unsigned NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY list_domain (list_id,domain),
  KEY list_state (list_id,state)
) $c;");

    dbDelta("CREATE TABLE " . cmsf_t('templates') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  topic varchar(120) NOT NULL DEFAULT '',
  subject varchar(255) NOT NULL DEFAULT '',
  body longtext NULL,
  is_html tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id)
) $c;");

    dbDelta("CREATE TABLE " . cmsf_t('log') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  site_id bigint(20) unsigned NOT NULL,
  list_id bigint(20) unsigned NOT NULL,
  template_id bigint(20) unsigned NULL,
  email varchar(255) NOT NULL DEFAULT '',
  sent_at datetime NOT NULL,
  ok tinyint(1) NOT NULL DEFAULT 0,
  error varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY site_id (site_id)
) $c;");

    cmsf_seed_templates();
    cmsf_seed_presets();
    cmsf_migrate_old();
    update_option('cmsf_db_version', CMSF_VERSION);
}

function cmsf_seed_templates() {
    global $wpdb;
    if (get_option('cmsf_seeded')) return;
    $now = cmsf_now();
    $items = [
        ['Оновлення Joomla до останньої версії', 'Joomla',
         'Оновлення вашого сайту {domain} на Joomla',
         "Добрий день!\n\nМи звернули увагу, що сайт {url} працює на Joomla {version}, а актуальна версія — {latest_version}. Застарілі версії CMS не отримують виправлень безпеки, і саме такі сайти найчастіше стають мішенню для зламів.\n\nМи можемо безпечно оновити ваш сайт до останньої версії Joomla: із резервною копією, перевіркою розширень і шаблону та тестуванням після оновлення.\n\nЯкщо це цікаво — дайте відповідь на цей лист, і ми підготуємо пропозицію.\n\nЗ повагою."],
        ['Оновлення WordPress до останньої версії', 'WordPress',
         'Оновлення вашого сайту {domain} на WordPress',
         "Добрий день!\n\nМи звернули увагу, що сайт {url} працює на WordPress {version}, а актуальна версія — {latest_version}. Застарілі версії не отримують виправлень безпеки, а це ризик зламу та втрати даних.\n\nМи можемо безпечно оновити сайт: із резервною копією, перевіркою плагінів і теми та тестуванням після оновлення.\n\nЯкщо це цікаво — дайте відповідь на цей лист.\n\nЗ повагою."],
        ['Пропонуємо унікальний плагін', 'Плагіни',
         'Унікальний плагін для сайту {domain}',
         "Добрий день!\n\nПропонуємо унікальний плагін для вашого сайту {url}: [коротко опишіть, що він робить і яку користь дає].\n\nГотові показати демо та допомогти з налаштуванням.\n\nЗ повагою."],
    ];
    foreach ($items as $i) {
        $wpdb->insert(cmsf_t('templates'), ['name' => $i[0], 'topic' => $i[1], 'subject' => $i[2], 'body' => $i[3], 'is_html' => 0, 'created_at' => $now, 'updated_at' => $now]);
    }
    update_option('cmsf_seeded', 1);
}

function cmsf_presets() {
    $p = get_option('cmsf_presets', []);
    return is_array($p) ? $p : [];
}

function cmsf_seed_presets() {
    if (get_option('cmsf_presets') !== false) return;
    $mk = function ($name, $cms) {
        return [substr(md5(mb_strtolower($name)), 0, 10) => ['name' => $name, 'params' => [
            'cms' => $cms, 'regions' => ['ua'], 'languages' => ['uk', 'ru'], 'site_type' => 'any', 'categories' => '', 'tags' => '', 'query' => '',
            'sources' => ['zone', 'engines'], 'limit' => 5000, 'require_terms' => 0]]];
    };
    update_option('cmsf_presets', $mk('WooCommerce · Україна', ['WooCommerce']) + $mk('Joomla · Україна', ['Joomla']) + $mk('WordPress · Україна', ['WordPress']), false);
}

// Переносимо дані зі старої версії плагіна (CMS Site Checker 1.x), якщо вони є
function cmsf_migrate_old() {
    global $wpdb;
    if (get_option('cmsf_migrated')) return;
    update_option('cmsf_migrated', 1);
    $old = get_option('cms_checker_results', []);
    $notes = get_option('cms_checker_notes', []);
    $tpl = get_option('cms_checker_template', '');
    $now = cmsf_now();
    if (!empty($old) && is_array($old)) {
        $wpdb->insert(cmsf_t('lists'), ['name' => 'Імпорт зі старої версії', 'params' => wp_json_encode(['imported' => true]), 'created_at' => $now, 'updated_at' => $now]);
        $lid = (int)$wpdb->insert_id;
        foreach ($old as $r) {
            if (empty($r['url'])) continue;
            $domain = cmsf_norm_domain($r['url']);
            if (!$domain) continue;
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO " . cmsf_t('sites') . " (list_id,domain,url,state,cms,version,http_status,email,emails,phone,note,checked_at) VALUES (%d,%s,%s,'ok',%s,%s,%s,%s,%s,%s,%s,%s)",
                $lid, $domain, 'https://' . $domain, (string)($r['cms'] ?? ''), (string)($r['version'] ?? ''), (string)($r['status'] ?? ''),
                (string)($r['email'] ?? ''), (string)($r['email'] ?? ''), (string)($r['phone'] ?? ''), (string)($notes[$r['url']] ?? ''), $now
            ));
        }
    }
    if ($tpl) {
        $wpdb->insert(cmsf_t('templates'), ['name' => 'Шаблон зі старої версії', 'topic' => 'Імпорт', 'subject' => 'Пропозиція щодо сайту {domain}', 'body' => $tpl, 'is_html' => 0, 'created_at' => $now, 'updated_at' => $now]);
    }
}

function cmsf_defaults() {
    return [
        'from_name' => get_bloginfo('name'),
        'from_email' => get_option('admin_email'),
        'reply_to' => '',
        'delay' => 3,
        'footer' => "--\nЯкщо ця пропозиція вам не цікава — просто дайте відповідь на цей лист, і ми більше не писатимемо.",
        'delimiter' => ';',
        'concurrency' => 3,
        'latest_joomla' => '',
        'latest_wordpress' => '',
        'latest_drupal' => '',
    ];
}

function cmsf_settings() {
    return wp_parse_args(get_option('cmsf_settings', []), cmsf_defaults());
}

// ---------- Списки ----------
function cmsf_list_create($name, $params) {
    global $wpdb;
    $now = cmsf_now();
    $wpdb->insert(cmsf_t('lists'), ['name' => $name, 'params' => wp_json_encode($params), 'created_at' => $now, 'updated_at' => $now]);
    return (int)$wpdb->insert_id;
}

function cmsf_list_get($id) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . cmsf_t('lists') . " WHERE id=%d", $id), ARRAY_A);
    if ($row) $row['params'] = json_decode($row['params'], true) ?: [];
    return $row;
}

function cmsf_list_stats($id) {
    global $wpdb;
    $t = cmsf_t('sites');
    $s = ['ok' => 0, 'queued' => 0, 'rejected' => 0, 'failed' => 0, 'sent' => 0, 'sent_fail' => 0];
    foreach ($wpdb->get_results($wpdb->prepare("SELECT state, COUNT(*) c FROM $t WHERE list_id=%d GROUP BY state", $id), ARRAY_A) as $r) {
        $k = $r['state'] === 'checking' ? 'queued' : $r['state'];
        $s[$k] = ($s[$k] ?? 0) + (int)$r['c'];
    }
    $s['sent'] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE list_id=%d AND send_ok=1", $id));
    $s['sent_fail'] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE list_id=%d AND send_ok=0", $id));
    return $s;
}

function cmsf_lists_all() {
    global $wpdb;
    $rows = $wpdb->get_results("SELECT * FROM " . cmsf_t('lists') . " ORDER BY id DESC", ARRAY_A);
    foreach ($rows as &$r) {
        $r['params'] = json_decode($r['params'], true) ?: [];
        $r['stats'] = cmsf_list_stats((int)$r['id']);
    }
    return $rows;
}

function cmsf_list_delete($id) {
    global $wpdb;
    $wpdb->delete(cmsf_t('sites'), ['list_id' => $id]);
    $wpdb->delete(cmsf_t('log'), ['list_id' => $id]);
    $wpdb->delete(cmsf_t('lists'), ['id' => $id]);
}

// Додає домени в чергу списку. Повертає кількість нових.
function cmsf_add_queued($list_id, $domains) {
    global $wpdb;
    $t = cmsf_t('sites');
    $values = [];
    foreach (array_unique($domains) as $d) {
        $d = cmsf_norm_domain($d);
        if (!$d || strpos($d, '.') === false) continue;
        $values[] = $wpdb->prepare("(%d,%s,%s,'queued')", $list_id, $d, 'https://' . $d);
    }
    $added = 0;
    foreach (array_chunk($values, 300) as $chunk) {
        $wpdb->query("INSERT IGNORE INTO $t (list_id,domain,url,state) VALUES " . implode(',', $chunk));
        $added += (int)$wpdb->rows_affected;
    }
    $wpdb->update(cmsf_t('lists'), ['updated_at' => cmsf_now()], ['id' => $list_id]);
    return $added;
}

// ---------- Шаблони ----------
function cmsf_templates_all() {
    global $wpdb;
    return $wpdb->get_results("SELECT * FROM " . cmsf_t('templates') . " ORDER BY topic ASC, name ASC", ARRAY_A);
}

function cmsf_template_get($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . cmsf_t('templates') . " WHERE id=%d", $id), ARRAY_A);
}

function cmsf_template_save($id, $data) {
    global $wpdb;
    $row = [
        'name' => mb_substr(sanitize_text_field($data['name'] ?? ''), 0, 190),
        'topic' => mb_substr(sanitize_text_field($data['topic'] ?? ''), 0, 120),
        'subject' => mb_substr(sanitize_text_field($data['subject'] ?? ''), 0, 255),
        'body' => (string)($data['body'] ?? ''),
        'is_html' => !empty($data['is_html']) ? 1 : 0,
        'updated_at' => cmsf_now(),
    ];
    if ($row['name'] === '') $row['name'] = 'Без назви';
    if (!empty($row['is_html'])) $row['body'] = wp_kses_post($row['body']);
    if ($id) {
        $wpdb->update(cmsf_t('templates'), $row, ['id' => $id]);
        return $id;
    }
    $row['created_at'] = cmsf_now();
    $wpdb->insert(cmsf_t('templates'), $row);
    return (int)$wpdb->insert_id;
}
