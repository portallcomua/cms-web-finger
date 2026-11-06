<?php
if (!defined('ABSPATH')) exit;

function cmsf_ajax($action, $fn) {
    add_action('wp_ajax_cmsf_' . $action, function () use ($fn) {
        check_ajax_referer('cmsf', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Немає прав');
        $fn();
    });
}

function cmsf_post($key, $default = '') {
    return isset($_POST[$key]) ? wp_unslash($_POST[$key]) : $default;
}

function cmsf_post_list($key, $allowed = null) {
    $v = isset($_POST[$key]) ? (array)wp_unslash($_POST[$key]) : [];
    $v = array_values(array_unique(array_filter(array_map('sanitize_text_field', $v), 'strlen')));
    return $allowed === null ? $v : array_values(array_intersect($v, $allowed));
}

function cmsf_params_from_post() {
    $sources = array_merge(array_keys(cmsf_engines()), ['zone', 'engines']);
    return [
        'cms' => cmsf_post_list('cms', cmsf_platforms()),
        'regions' => cmsf_post_list('regions', ['ua', 'eu', 'us', 'world']),
        'languages' => cmsf_post_list('languages', ['uk', 'ru', 'en', 'any']),
        'site_type' => in_array(cmsf_post('site_type'), ['any', 'shop', 'catalog'], true) ? cmsf_post('site_type') : 'any',
        'categories' => sanitize_text_field(cmsf_post('categories')),
        'tags' => sanitize_text_field(cmsf_post('tags')),
        'query' => sanitize_text_field(cmsf_post('query')),
        'sources' => cmsf_post_list('sources', $sources),
        'limit' => max(1, min(50000, (int)cmsf_post('limit', 500))),
        'require_terms' => !empty($_POST['require_terms']) ? 1 : 0,
        'terms' => array_values(array_unique(array_merge(cmsf_split_terms(sanitize_text_field(cmsf_post('categories'))), cmsf_split_terms(sanitize_text_field(cmsf_post('tags'))), cmsf_split_terms(sanitize_text_field(cmsf_post('query')))))),
    ];
}

function cmsf_row_out($r) {
    $keys = ['id', 'domain', 'url', 'state', 'cms', 'version', 'latest', 'outdated', 'http_status', 'title', 'country', 'language', 'site_type', 'relevance', 'email', 'emails', 'phone', 'note', 'reject_reason', 'sent_at', 'send_ok', 'send_error', 'send_count', 'last_template'];
    $o = [];
    foreach ($keys as $k) $o[$k] = $r[$k] ?? null;
    $o['id'] = (int)$o['id'];
    $o['outdated'] = (int)$o['outdated'];
    $o['relevance'] = (int)$o['relevance'];
    $o['send_count'] = (int)$o['send_count'];
    $o['last_template'] = $o['last_template'] === null ? null : (int)$o['last_template'];
    $o['send_ok'] = ($r['send_ok'] ?? null) === null ? null : (int)$r['send_ok'];
    $o['sent_label'] = !empty($r['sent_at']) ? mysql2date('d.m.Y H:i', $r['sent_at']) : '';
    return $o;
}

function cmsf_site_row($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . cmsf_t('sites') . " WHERE id=%d", $id), ARRAY_A);
}

function cmsf_filters_from_post() {
    return [
        'state' => in_array(cmsf_post('state', 'ok'), ['ok', 'queued', 'rejected', 'failed', 'all'], true) ? cmsf_post('state', 'ok') : 'ok',
        'q' => sanitize_text_field(cmsf_post('q')),
        'has_email' => !empty($_POST['has_email']) ? 1 : 0,
        'sent' => in_array(cmsf_post('sent'), ['unsent', 'sent', 'failed'], true) ? cmsf_post('sent') : '',
        'outdated' => !empty($_POST['outdated']) ? 1 : 0,
        'cms' => sanitize_text_field(cmsf_post('cms_filter')),
    ];
}

function cmsf_where($list_id, $f) {
    global $wpdb;
    $w = ['list_id=%d'];
    $a = [$list_id];
    $state = $f['state'] ?? 'ok';
    if ($state === 'queued') { $w[] = "state IN ('queued','checking')"; }
    elseif ($state && $state !== 'all') { $w[] = 'state=%s'; $a[] = $state; }
    if (!empty($f['q'])) {
        $like = '%' . $wpdb->esc_like($f['q']) . '%';
        $w[] = '(domain LIKE %s OR title LIKE %s OR email LIKE %s OR note LIKE %s)';
        array_push($a, $like, $like, $like, $like);
    }
    if (!empty($f['has_email'])) $w[] = "email<>''";
    if (($f['sent'] ?? '') === 'unsent') $w[] = 'sent_at IS NULL';
    if (($f['sent'] ?? '') === 'sent') $w[] = 'send_ok=1';
    if (($f['sent'] ?? '') === 'failed') $w[] = 'send_ok=0';
    if (!empty($f['outdated'])) $w[] = 'outdated=1';
    if (!empty($f['cms'])) { $w[] = 'cms=%s'; $a[] = $f['cms']; }
    return [implode(' AND ', $w), $a];
}

// ============ СПИСКИ ============
cmsf_ajax('list_create', function () {
    $params = cmsf_params_from_post();
    $name = sanitize_text_field(cmsf_post('name'));
    if ($name === '') $name = 'Список ' . wp_date('d.m.Y H:i');
    wp_send_json_success(['list_id' => cmsf_list_create($name, $params)]);
});

cmsf_ajax('lists', function () {
    $out = [];
    foreach (cmsf_lists_all() as $l) {
        $out[] = ['id' => (int)$l['id'], 'name' => $l['name'], 'created' => mysql2date('d.m.Y H:i', $l['created_at']), 'updated' => mysql2date('d.m.Y H:i', $l['updated_at']),
            'cms' => (array)($l['params']['cms'] ?? []), 'regions' => (array)($l['params']['regions'] ?? []), 'stats' => $l['stats']];
    }
    wp_send_json_success($out);
});

cmsf_ajax('list_rename', function () {
    global $wpdb;
    $name = sanitize_text_field(cmsf_post('name'));
    if ($name === '') wp_send_json_error('Порожня назва');
    $wpdb->update(cmsf_t('lists'), ['name' => $name, 'updated_at' => cmsf_now()], ['id' => (int)cmsf_post('list_id')]);
    wp_send_json_success();
});

cmsf_ajax('list_delete', function () {
    cmsf_list_delete((int)cmsf_post('list_id'));
    wp_send_json_success();
});

cmsf_ajax('list_info', function () {
    $id = (int)cmsf_post('list_id');
    $l = cmsf_list_get($id);
    if (!$l) wp_send_json_error('Список не знайдено');
    wp_send_json_success(['id' => $id, 'name' => $l['name'], 'params' => $l['params'], 'stats' => cmsf_list_stats($id)]);
});

cmsf_ajax('rows', function () {
    global $wpdb;
    $id = (int)cmsf_post('list_id');
    $page = max(1, (int)cmsf_post('page', 1));
    $per = 50;
    list($where, $args) = cmsf_where($id, cmsf_filters_from_post());
    $t = cmsf_t('sites');
    $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE $where", $args));
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d", array_merge($args, [$per, ($page - 1) * $per])), ARRAY_A);
    $cms_list = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT cms FROM $t WHERE list_id=%d AND state='ok' AND cms<>'' ORDER BY cms", $id));
    wp_send_json_success(['rows' => array_map('cmsf_row_out', $rows), 'total' => $total, 'pages' => max(1, (int)ceil($total / $per)), 'page' => $page, 'stats' => cmsf_list_stats($id), 'cms_list' => $cms_list]);
});

cmsf_ajax('ids', function () {
    global $wpdb;
    list($where, $args) = cmsf_where((int)cmsf_post('list_id'), cmsf_filters_from_post());
    $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM " . cmsf_t('sites') . " WHERE $where ORDER BY id DESC LIMIT 20000", $args));
    wp_send_json_success(array_map('intval', $ids));
});

cmsf_ajax('row_update', function () {
    global $wpdb;
    $id = (int)cmsf_post('id');
    $field = cmsf_post('field');
    $val = cmsf_post('value');
    if ($field === 'note') $data = ['note' => sanitize_textarea_field($val)];
    elseif ($field === 'email') {
        $e = sanitize_email($val);
        if ($val !== '' && !is_email($e)) wp_send_json_error('Некоректний email');
        $data = ['email' => $e];
    } else wp_send_json_error('Невідоме поле');
    $wpdb->update(cmsf_t('sites'), $data, ['id' => $id]);
    wp_send_json_success();
});

cmsf_ajax('rows_delete', function () {
    global $wpdb;
    $ids = array_filter(array_map('intval', (array)cmsf_post('ids', [])));
    if ($ids) $wpdb->query("DELETE FROM " . cmsf_t('sites') . " WHERE id IN (" . implode(',', $ids) . ")");
    wp_send_json_success(count($ids));
});

// ============ ПОШУК: ДЖЕРЕЛА ============
cmsf_ajax('plan', function () {
    $l = cmsf_list_get((int)cmsf_post('list_id'));
    if (!$l) wp_send_json_error('Список не знайдено');
    $p = $l['params'];
    $src = (array)($p['sources'] ?? []);
    $engines = in_array('engines', $src, true) ? array_keys(cmsf_engines()) : array_values(array_intersect(array_keys(cmsf_engines()), $src));
    $regions = (array)($p['regions'] ?? []);
    wp_send_json_success([
        'queries' => $engines ? cmsf_make_queries($p) : [],
        'engines' => $engines,
        'pages' => array_map('cmsf_engine_pages', array_combine($engines, $engines) ?: []),
        'ua' => $regions === ['ua'],
        'zone' => in_array('zone', $src, true) ? cmsf_regions_to_zones($regions) : [],
        'limit' => (int)($p['limit'] ?? 500),
    ]);
});

cmsf_ajax('search', function () {
    @set_time_limit(60);
    $id = (int)cmsf_post('list_id');
    $engine = sanitize_key(cmsf_post('engine'));
    $res = cmsf_engine_search($engine, sanitize_text_field(cmsf_post('query')), !empty($_POST['ua']), (int)cmsf_post('page', 0));
    if (isset($res['error'])) wp_send_json_success(['error' => $res['error'], 'blocked' => !empty($res['blocked']), 'found' => 0, 'added' => 0]);
    wp_send_json_success(['found' => count($res['domains']), 'added' => cmsf_add_queued($id, $res['domains'])]);
});

cmsf_ajax('zone_info', function () {
    $out = [];
    foreach (['ua', 'eu', 'us', 'world'] as $z) $out[$z] = cmsf_zone_info($z);
    wp_send_json_success($out);
});

cmsf_ajax('zone_step', function () {
    $r = cmsf_zone_step(!empty($_POST['reset']));
    if (isset($r['error'])) wp_send_json_error($r['error']);
    wp_send_json_success($r);
});

cmsf_ajax('zone_take', function () {
    $zone = sanitize_key(cmsf_post('zone'));
    $n = max(1, min(1000, (int)cmsf_post('n', 500)));
    $r = cmsf_zone_take($zone, (int)cmsf_post('pos', 0), $n);
    if ($r === null) wp_send_json_error('Список зони ще не завантажено');
    wp_send_json_success(['taken' => count($r['domains']), 'added' => cmsf_add_queued((int)cmsf_post('list_id'), $r['domains']), 'pos' => $r['pos'], 'eof' => $r['eof']]);
});

cmsf_ajax('add_domains', function () {
    $raw = preg_split('/[\s,;]+/', (string)cmsf_post('text'), -1, PREG_SPLIT_NO_EMPTY);
    wp_send_json_success(['added' => cmsf_add_queued((int)cmsf_post('list_id'), array_slice($raw, 0, 50000)), 'total' => count($raw)]);
});

// ============ ПЕРЕВІРКА ============
cmsf_ajax('next_queued', function () {
    global $wpdb;
    $id = (int)cmsf_post('list_id');
    $n = max(1, min(10, (int)cmsf_post('n', 3)));
    $t = cmsf_t('sites');
    $now = cmsf_now();
    // Записи «захоплюються» (state=checking), щоб збій однієї перевірки не змушував брати той самий домен знову.
    // Захоплені, але не завершені понад 3 хв, повертаються в чергу.
    $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM $t WHERE list_id=%d AND (state='queued' OR (state='checking' AND checked_at < DATE_SUB(%s, INTERVAL 3 MINUTE))) ORDER BY id ASC LIMIT %d", $id, $now, $n)));
    if ($ids) $wpdb->query($wpdb->prepare("UPDATE $t SET state='checking', checked_at=%s WHERE id IN (" . implode(',', $ids) . ")", $now));
    $left = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE list_id=%d AND state IN ('queued','checking')", $id));
    wp_send_json_success(['ids' => $ids, 'left' => $left]);
});

function cmsf_clean_value($v) {
    global $wpdb;
    if (!is_string($v)) return $v;
    $v = function_exists('mb_convert_encoding') ? mb_convert_encoding($v, 'UTF-8', 'UTF-8') : $v;
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string)$v);
    if ($v === null) return '';
    if (!$wpdb->has_cap('utf8mb4')) $v = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $v);
    return $v;
}

function cmsf_mark_failed($id, $reason) {
    global $wpdb;
    $wpdb->update(cmsf_t('sites'), ['state' => 'failed', 'http_status' => 'Помилка', 'reject_reason' => mb_substr($reason, 0, 250), 'checked_at' => cmsf_now()], ['id' => $id]);
}

cmsf_ajax('inspect', function () {
    global $wpdb;
    @set_time_limit(60);
    $id = (int)cmsf_post('id');
    $site = cmsf_site_row($id);
    if (!$site) wp_send_json_error('Запис не знайдено');
    $l = cmsf_list_get((int)$site['list_id']);
    try {
        $rec = cmsf_inspect($site['domain'], $l ? $l['params'] : []);
        unset($rec['domain']);
        $rec = array_map('cmsf_clean_value', $rec);
        $ok = $wpdb->update(cmsf_t('sites'), $rec, ['id' => $id]);
        if ($ok === false) {
            $err = $wpdb->last_error ?: 'не вдалося зберегти результат';
            cmsf_mark_failed($id, 'Помилка збереження: ' . $err);
        }
    } catch (Throwable $e) {
        cmsf_mark_failed($id, 'Помилка перевірки: ' . $e->getMessage());
    }
    wp_send_json_success(cmsf_row_out(cmsf_site_row($id)));
});

cmsf_ajax('row_failed', function () {
    global $wpdb;
    $wpdb->update(cmsf_t('sites'), ['state' => 'failed', 'http_status' => 'Помилка перевірки', 'reject_reason' => 'Помилка перевірки (тайм-аут або збій сервера)', 'checked_at' => cmsf_now()], ['id' => (int)cmsf_post('id')]);
    wp_send_json_success();
});

cmsf_ajax('requeue', function () {
    global $wpdb;
    $wpdb->query($wpdb->prepare("UPDATE " . cmsf_t('sites') . " SET state='queued' WHERE list_id=%d AND state='failed'", (int)cmsf_post('list_id')));
    wp_send_json_success();
});

// ============ ШАБЛОНИ ПОШУКУ (збережені набори параметрів) ============
cmsf_ajax('presets', function () {
    $out = [];
    foreach (cmsf_presets() as $id => $p) $out[] = ['id' => $id, 'name' => $p['name'], 'params' => $p['params']];
    wp_send_json_success($out);
});

cmsf_ajax('preset_save', function () {
    $name = mb_substr(sanitize_text_field(cmsf_post('name')), 0, 120);
    if ($name === '') wp_send_json_error('Вкажіть назву шаблону');
    $p = cmsf_params_from_post();
    unset($p['terms']);
    $all = cmsf_presets();
    $id = substr(md5(mb_strtolower($name)), 0, 10);   // та сама назва = оновлення існуючого шаблону
    $all[$id] = ['name' => $name, 'params' => $p];
    update_option('cmsf_presets', $all, false);
    wp_send_json_success(['id' => $id]);
});

cmsf_ajax('preset_delete', function () {
    $all = cmsf_presets();
    unset($all[sanitize_key(cmsf_post('id'))]);
    update_option('cmsf_presets', $all, false);
    wp_send_json_success();
});

// ============ ШАБЛОНИ ============
cmsf_ajax('tpls', function () {
    wp_send_json_success(cmsf_templates_all());
});

cmsf_ajax('tpl_save', function () {
    $id = (int)cmsf_post('id');
    $new = cmsf_template_save($id, wp_unslash($_POST));
    wp_send_json_success(['id' => $new, 'tpl' => cmsf_template_get($new)]);
});

cmsf_ajax('tpl_delete', function () {
    global $wpdb;
    $wpdb->delete(cmsf_t('templates'), ['id' => (int)cmsf_post('id')]);
    wp_send_json_success();
});

cmsf_ajax('tpl_preview', function () {
    $site = ['url' => 'https://example.com.ua', 'domain' => 'example.com.ua', 'title' => 'Назва сайту', 'cms' => cmsf_post('cms', 'Joomla'), 'version' => '3.10.12', 'latest' => '', 'email' => 'info@example.com.ua'];
    $html = !empty($_POST['is_html']);
    wp_send_json_success(['subject' => cmsf_render(cmsf_post('subject'), $site), 'body' => cmsf_mail_body(cmsf_render(cmsf_post('body'), $site, $html), $html), 'is_html' => $html]);
});

// ============ ВІДПРАВКА ============
cmsf_ajax('send', function () {
    @set_time_limit(60);
    $sid = (int)cmsf_post('id');
    $tid = (int)cmsf_post('template_id');
    $cur = cmsf_site_row($sid);
    if ($cur && !empty($_POST['skip_sent']) && (int)$cur['send_ok'] === 1 && (int)$cur['last_template'] === $tid) {
        wp_send_json_success(['ok' => false, 'skipped' => true, 'error' => 'Цей шаблон уже надсилався', 'row' => cmsf_row_out($cur)]);
    }
    $res = cmsf_send_site($sid, $tid);
    $res['row'] = cmsf_row_out(cmsf_site_row($sid) ?: []);
    wp_send_json_success($res);
});

cmsf_ajax('test_mail', function () {
    $to = sanitize_email(cmsf_post('to'));
    if (!is_email($to)) wp_send_json_error('Вкажіть коректний email');
    global $cmsf_mail_error;
    $cmsf_mail_error = '';
    add_action('wp_mail_failed', function ($e) { global $cmsf_mail_error; $cmsf_mail_error = $e->get_error_message(); });
    $ok = wp_mail($to, 'Тестовий лист CMS Web Finder', "Якщо ви бачите цей лист — розсилка працює.\n\n" . home_url(), cmsf_mail_headers(false));
    if ($ok) wp_send_json_success('Тестовий лист надіслано на ' . $to . '. Перевірте також папку «Спам».');
    wp_send_json_error($cmsf_mail_error ?: 'wp_mail повернув false. Налаштуйте SMTP (наприклад, плагін WP Mail SMTP).');
});

// ============ НАЛАШТУВАННЯ ============
cmsf_ajax('save_settings', function () {
    $s = [
        'from_name' => sanitize_text_field(cmsf_post('from_name')),
        'from_email' => sanitize_email(cmsf_post('from_email')),
        'reply_to' => sanitize_email(cmsf_post('reply_to')),
        'delay' => max(0, min(120, (int)cmsf_post('delay', 3))),
        'footer' => sanitize_textarea_field(cmsf_post('footer')),
        'delimiter' => cmsf_post('delimiter') === ',' ? ',' : ';',
        'concurrency' => max(1, min(8, (int)cmsf_post('concurrency', 3))),
        'latest_joomla' => preg_replace('/[^0-9.]/', '', cmsf_post('latest_joomla')),
        'latest_wordpress' => preg_replace('/[^0-9.]/', '', cmsf_post('latest_wordpress')),
        'latest_drupal' => preg_replace('/[^0-9.]/', '', cmsf_post('latest_drupal')),
    ];
    update_option('cmsf_settings', $s);
    wp_send_json_success('Збережено');
});

cmsf_ajax('refresh_latest', function () {
    delete_transient('cmsf_latest_cache');
    $c = cmsf_fetch_latest();
    set_transient('cmsf_latest_cache', $c, array_filter($c) ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);
    wp_send_json_success($c);
});

// ============ ЕКСПОРТ CSV ============
// Раніше експорт працював усередині сторінки адмінки, коли HTML уже було виведено, тому файл ламався.
// Тепер це окремий admin-post запит, який віддає лише CSV.
function cmsf_csv_safe($v) {
    $v = (string)$v;
    if (preg_match('/^\+?[\d\s().;-]+$/', $v)) return $v; // телефони не чіпаємо
    return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
}

add_action('admin_post_cmsf_export', function () {
    if (!current_user_can('manage_options')) wp_die('Немає прав');
    check_admin_referer('cmsf_export');
    global $wpdb;
    $list_id = (int)($_POST['list_id'] ?? 0);
    $t = cmsf_t('sites');
    $ids = array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))));
    if ($ids) {
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE list_id=%d AND id IN (" . implode(',', $ids) . ") ORDER BY id DESC", $list_id), ARRAY_A);
    } else {
        list($where, $args) = cmsf_where($list_id, cmsf_filters_from_post());
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE $where ORDER BY id DESC", $args), ARRAY_A);
    }
    $l = cmsf_list_get($list_id);
    $name = sanitize_file_name(($l['name'] ?? 'list') . '-' . wp_date('Y-m-d')) ?: 'list';
    $d = cmsf_settings()['delimiter'];

    while (ob_get_level()) ob_end_clean();
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Сайт', 'CMS', 'Версія', 'Остання версія', 'Застаріла', 'Статус', 'Країна', 'Мова', 'Тип', 'Email', 'Усі email', 'Телефон', 'Нотатка', 'Надіслано', 'Результат відправки', 'Помилка', 'Кількість листів'], $d);
    foreach ($rows as $r) {
        fputcsv($out, array_map('cmsf_csv_safe', [
            $r['url'], $r['cms'], $r['version'], $r['latest'], $r['outdated'] ? 'так' : '', $r['http_status'], $r['country'], $r['language'], $r['site_type'],
            $r['email'], $r['emails'], $r['phone'], $r['note'],
            $r['sent_at'] ? mysql2date('d.m.Y H:i', $r['sent_at']) : '',
            $r['send_ok'] === null ? '' : ($r['send_ok'] ? 'успішно' : 'помилка'),
            $r['send_error'], $r['send_count'],
        ]), $d);
    }
    fclose($out);
    exit;
});
