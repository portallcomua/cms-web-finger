<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Формує змінні, які можна використовувати в шаблонах листів.
 */
function cmsf_template_vars($site) {
    $latest = $site['latest'] ?? '';

    if ($latest === '' && !empty($site['cms'])) {
        $latest = cmsf_latest($site['cms']);
    }

    return [
        '{url}'            => (string)($site['url'] ?? ''),
        '{domain}'         => (string)($site['domain'] ?? ''),
        '{site_title}'     => (string)($site['title'] ?? ''),
        '{cms}'            => (string)($site['cms'] ?? ''),
        '{version}'        => !empty($site['version']) ? $site['version'] : 'невизначена',
        '{latest_version}' => $latest !== '' ? $latest : 'остання стабільна',
        '{email}'          => (string)($site['email'] ?? ''),
    ];
}

/**
 * Підставляє значення сайту замість змінних у шаблоні.
 */
function cmsf_render($str, $site, $html = false) {
    $vars = cmsf_template_vars($site);

    if ($html) {
        $vars = array_map('esc_html', $vars);
    }

    return strtr((string)$str, $vars);
}

/**
 * Формує заголовки листа з налаштувань CMS Web Finder.
 * SMTP-передавання залишається на чинному механізмі PHPMailer.
 */
function cmsf_mail_headers($is_html) {
    $settings = cmsf_settings();

    $headers = [
        'Content-Type: ' . ($is_html ? 'text/html' : 'text/plain') . '; charset=UTF-8'
    ];

    $from = sanitize_email($settings['from_email']);

    if ($from) {
        $from_name = str_replace(
            ['<', '>', "\r", "\n"],
            '',
            (string)$settings['from_name']
        );

        $headers[] = 'From: ' . $from_name . ' <' . $from . '>';
    }

    $reply_to = sanitize_email($settings['reply_to']);

    if ($reply_to) {
        $headers[] = 'Reply-To: ' . $reply_to;
    }

    return $headers;
}

/**
 * Додає стандартний підпис із налаштувань до тіла листа.
 */
function cmsf_mail_body($body, $is_html) {
    $settings = cmsf_settings();
    $footer = trim((string)$settings['footer']);

    if ($footer === '') {
        return $body;
    }

    if ($is_html) {
        return $body . '<p>' . nl2br(esc_html($footer)) . '</p>';
    }

    return $body . "\n\n" . $footer;
}

/**
 * Надсилає лист одному сайту зі старого списку.
 *
 * Перед відправленням перевіряє:
 * - чи існує сайт;
 * - чи існує шаблон;
 * - чи є коректний email;
 * - чи не перебуває email у глобальному блеклисті.
 *
 * Результат записується до старої історії розсилок.
 */
function cmsf_send_site($site_id, $template_id) {
    global $wpdb, $cmsf_mail_error;

    $sites_table = cmsf_t('sites');

    // Завантажуємо сайт зі списку.
    $site = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $sites_table WHERE id = %d",
            $site_id
        ),
        ARRAY_A
    );

    if (!$site) {
        return [
            'ok'      => false,
            'skipped' => true,
            'error'   => 'Сайт не знайдено'
        ];
    }

    // Завантажуємо шаблон листа.
    $template = cmsf_template_get($template_id);

    if (!$template) {
        return [
            'ok'      => false,
            'skipped' => true,
            'error'   => 'Шаблон не знайдено'
        ];
    }

    // Перевіряємо адресу отримувача.
    $to = sanitize_email($site['email']);

    if (!is_email($to)) {
        return [
            'ok'      => false,
            'skipped' => true,
            'error'   => 'Немає коректного email'
        ];
    }

    // Глобальний блеклист: адреса, яку заблокували, не отримує лист.
    if (
        function_exists('cmsf_contact_is_blocked') &&
        cmsf_contact_is_blocked($to)
    ) {
        return [
            'ok'      => false,
            'skipped' => true,
            'blocked' => true,
            'error'   => 'Адреса перебуває у глобальному блеклисті'
        ];
    }

    // Підготовлюємо тему та текст листа.
    $is_html = !empty($template['is_html']);

    $subject = preg_replace(
        '/[\r\n]+/',
        ' ',
        cmsf_render($template['subject'], $site)
    );

    $body = cmsf_mail_body(
        cmsf_render($template['body'], $site, $is_html),
        $is_html
    );

    // Фіксуємо помилку PHPMailer, якщо WordPress повідомить про неї.
    $cmsf_mail_error = '';

    $mail_error_callback = function ($error) {
        global $cmsf_mail_error;

        if (is_wp_error($error)) {
            $cmsf_mail_error = $error->get_error_message();
        }
    };

    add_action('wp_mail_failed', $mail_error_callback);

    // Відправляємо лист через стандартний механізм WordPress і чинний SMTP.
    $ok = (bool)wp_mail(
        $to,
        $subject,
        $body,
        cmsf_mail_headers($is_html)
    );

    remove_action('wp_mail_failed', $mail_error_callback);

    // Формуємо результат відправлення.
    $error = $ok
        ? ''
        : mb_substr(
            $cmsf_mail_error ?: 'wp_mail повернув false. Перевірте SMTP.',
            0,
            250
        );

    $now = cmsf_now();

    // Оновлюємо статус сайту.
    $wpdb->update(
        $sites_table,
        [
            'sent_at'      => $now,
            'send_ok'      => $ok ? 1 : 0,
            'send_error'   => $error,
            'send_count'   => (int)$site['send_count'] + 1,
            'last_template'=> $template_id,
        ],
        ['id' => $site_id]
    );

    // Зберігаємо результат у журналі.
    $wpdb->insert(
        cmsf_t('log'),
        [
            'site_id'     => $site_id,
            'list_id'     => (int)$site['list_id'],
            'template_id' => $template_id,
            'email'       => $to,
            'sent_at'     => $now,
            'ok'          => $ok ? 1 : 0,
            'error'       => $error,
        ]
    );

    return [
        'ok'      => $ok,
        'error'   => $error,
        'sent_at' => $now
    ];
}
