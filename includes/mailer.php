<?php
if (!defined('ABSPATH')) exit;

function cmsf_template_vars($site) {
    $latest = $site['latest'] ?? '';
    if ($latest === '' && !empty($site['cms'])) $latest = cmsf_latest($site['cms']);
    return [
        '{url}' => (string)($site['url'] ?? ''),
        '{domain}' => (string)($site['domain'] ?? ''),
        '{site_title}' => (string)($site['title'] ?? ''),
        '{cms}' => (string)($site['cms'] ?? ''),
        '{version}' => !empty($site['version']) ? $site['version'] : 'невизначена',
        '{latest_version}' => $latest !== '' ? $latest : 'остання стабільна',
        '{email}' => (string)($site['email'] ?? ''),
    ];
}

function cmsf_render($str, $site, $html = false) {
    $v = cmsf_template_vars($site);
    if ($html) $v = array_map('esc_html', $v);
    return strtr((string)$str, $v);
}

function cmsf_mail_headers($is_html) {
    $s = cmsf_settings();
    $h = ['Content-Type: ' . ($is_html ? 'text/html' : 'text/plain') . '; charset=UTF-8'];
    $from = sanitize_email($s['from_email']);
    if ($from) $h[] = 'From: ' . str_replace(['<', '>', "\r", "\n"], '', $s['from_name']) . ' <' . $from . '>';
    $rt = sanitize_email($s['reply_to']);
    if ($rt) $h[] = 'Reply-To: ' . $rt;
    return $h;
}

function cmsf_mail_body($body, $is_html) {
    $s = cmsf_settings();
    $footer = trim((string)$s['footer']);
    if ($footer === '') return $body;
    return $is_html ? $body . '<p>' . nl2br(esc_html($footer)) . '</p>' : $body . "\n\n" . $footer;
}

// Надсилає лист одному сайту, оновлює відмітку «надіслано» та пише історію
function cmsf_send_site($site_id, $template_id) {
    global $wpdb, $cmsf_mail_error;
    $st = cmsf_t('sites');
    $site = $wpdb->get_row($wpdb->prepare("SELECT * FROM $st WHERE id=%d", $site_id), ARRAY_A);
    if (!$site) return ['ok' => false, 'skipped' => true, 'error' => 'Сайт не знайдено'];
    $tpl = cmsf_template_get($template_id);
    if (!$tpl) return ['ok' => false, 'skipped' => true, 'error' => 'Шаблон не знайдено'];
    $to = sanitize_email($site['email']);
    if (!is_email($to)) return ['ok' => false, 'skipped' => true, 'error' => 'Немає коректного email'];

    $is_html = !empty($tpl['is_html']);
    $subject = preg_replace('/[\r\n]+/', ' ', cmsf_render($tpl['subject'], $site));
    $body = cmsf_mail_body(cmsf_render($tpl['body'], $site, $is_html), $is_html);

    $cmsf_mail_error = '';
    add_action('wp_mail_failed', function ($e) { global $cmsf_mail_error; $cmsf_mail_error = $e->get_error_message(); });
    $ok = (bool)wp_mail($to, $subject, $body, cmsf_mail_headers($is_html));
    $err = $ok ? '' : mb_substr($cmsf_mail_error ?: 'wp_mail повернув false. Перевірте налаштування пошти сервера (SMTP).', 0, 250);
    $now = cmsf_now();

    $wpdb->update($st, [
        'sent_at' => $now, 'send_ok' => $ok ? 1 : 0, 'send_error' => $err,
        'send_count' => (int)$site['send_count'] + 1, 'last_template' => $template_id,
    ], ['id' => $site_id]);
    $wpdb->insert(cmsf_t('log'), ['site_id' => $site_id, 'list_id' => (int)$site['list_id'], 'template_id' => $template_id, 'email' => $to, 'sent_at' => $now, 'ok' => $ok ? 1 : 0, 'error' => $err]);
    return ['ok' => $ok, 'error' => $err, 'sent_at' => $now];
}
