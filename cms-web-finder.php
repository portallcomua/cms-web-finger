<?php
/*
Plugin Name: UA Web Finder
Description: Пошук сайтів за CMS, збір контактів із публічних сторінок, списки, кампанії та керування відписками.
Version: 2.5.0
Requires at least: 5.8
Requires PHP: 7.4
Author: UAServer
Text Domain: ua-web-finder
*/

if (!defined('ABSPATH')) {
    exit;
}

// Версія плагіна та шляхи до його файлів.
define('CMSF_VERSION', '2.5.0');
define('CMSF_DIR', plugin_dir_path(__FILE__));
define('CMSF_URL', plugin_dir_url(__FILE__));

// Підключаємо основні модулі.
require_once CMSF_DIR . 'includes/db.php';
require_once CMSF_DIR . 'includes/detector.php';
require_once CMSF_DIR . 'includes/discover.php';
require_once CMSF_DIR . 'includes/smtp.php';
require_once CMSF_DIR . 'includes/mailer.php';
require_once CMSF_DIR . 'includes/ajax.php';
require_once CMSF_DIR . 'includes/admin.php';

// Підключаємо модуль збору контактів.
require_once CMSF_DIR . 'includes/contacts.php';


/* ---------------------------------------------------------
 * ПЕРЕВІРКА НАЯВНОСТІ ТАБЛИЦЬ КОНТАКТНОГО МОДУЛЯ
 * --------------------------------------------------------- */

/**
 * Перевіряє, чи існують усі п'ять таблиць контактного модуля.
 */
function cmsf_contacts_tables_exist() {
    global $wpdb;

    $table_names = [
        'lists',
        'contacts',
        'blacklist',
        'campaigns',
        'recipients'
    ];

    foreach ($table_names as $name) {

        $table = $wpdb->prefix . 'cmsfc_' . $name;

        // Перевіряємо реальну наявність таблиці в базі даних.
        $found = $wpdb->get_var(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like($table)
            )
        );

        if ($found !== $table) {
            return false;
        }
    }

    return true;
}


/* ---------------------------------------------------------
 * АВТОМАТИЧНЕ ВСТАНОВЛЕННЯ ТАБЛИЦЬ
 * --------------------------------------------------------- */

/**
 * Створює відсутні таблиці або оновлює їхню структуру.
 *
 * Не покладається тільки на записану версію бази:
 * перевіряє, чи таблиці дійсно існують.
 */
function cmsf_contacts_maybe_install() {

    if (!function_exists('cmsf_contacts_install')) {
        return;
    }

    $installed_version = get_option('cmsf_contacts_db_version', '');

    $tables_exist = cmsf_contacts_tables_exist();

    // Якщо версія застаріла або таблиць немає —
    // запускаємо встановлення повторно.
    if ($installed_version !== '1.1' || !$tables_exist) {

        cmsf_contacts_install();

        // Записуємо версію лише після успішної перевірки.
        if (cmsf_contacts_tables_exist()) {

            update_option(
                'cmsf_contacts_db_version',
                '1.1',
                false
            );

        }
    }
}


/* ---------------------------------------------------------
 * АКТИВАЦІЯ ПЛАГІНА
 * --------------------------------------------------------- */

register_activation_hook(__FILE__, function () {

    // Створюємо основні таблиці CMS Web Finder.
    cmsf_install();

    // Створюємо таблиці збору контактів.
    cmsf_contacts_maybe_install();

});


/* ---------------------------------------------------------
 * АВТОМАТИЧНА ПЕРЕВІРКА ПІСЛЯ ОНОВЛЕННЯ
 * --------------------------------------------------------- */

add_action('plugins_loaded', function () {

    // Оновлюємо основну базу, якщо змінилася версія плагіна.
    if (get_option('cmsf_db_version') !== CMSF_VERSION) {

        cmsf_install();

    }

    // Перевіряємо таблиці контактного модуля.
    // Якщо таблиці відсутні, створюємо їх повторно.
    cmsf_contacts_maybe_install();

});


/**
 * GitHub release updater.
 * Publishes updates only when a GitHub Release exists for this repository.
 */
function cmsf_github_release_info($force = false) {
    $cached = get_transient('cmsf_github_release_v2');
    if (!$force && is_array($cached)) return $cached;

    $response = wp_remote_get('https://api.github.com/repos/portallcomua/cms-web-finger/releases/latest', [
        'timeout' => 8,
        'headers' => [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'UA-Web-Finder-WordPress-Updater'
        ]
    ]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return is_array($cached) ? $cached : [];
    }

    $release = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($release) || !empty($release['draft']) || !empty($release['prerelease'])) return [];

    $tag = ltrim((string)($release['tag_name'] ?? ''), 'v');
    $package = '';
    foreach ((array)($release['assets'] ?? []) as $asset) {
        if (!empty($asset['name']) && $asset['name'] === 'ua-web-finder.zip' && !empty($asset['browser_download_url'])) {
            $package = esc_url_raw($asset['browser_download_url']);
            break;
        }
    }
    // Never use the repository zipball: it has a different root folder and breaks in-place WordPress updates.

    $data = [
        'version' => $tag,
        'package' => $package,
        'url' => esc_url_raw($release['html_url'] ?? ''),
        'requires' => '5.8',
        'tested' => '6.8',
        'requires_php' => '7.4',
    ];
    set_transient('cmsf_github_release_v2', $data, 6 * HOUR_IN_SECONDS);
    return $data;
}

add_filter('pre_set_site_transient_update_plugins', function ($transient) {
    if (!is_object($transient) || empty($transient->checked)) return $transient;
    $plugin_file = plugin_basename(__FILE__);
    if (empty($transient->checked[$plugin_file])) return $transient;

    $release = cmsf_github_release_info();
    if (empty($release['version']) || empty($release['package'])) return $transient;
    if (version_compare($release['version'], CMSF_VERSION, '<=')) return $transient;

    $transient->response[$plugin_file] = (object)[
        'slug' => 'cms-web-finder',
        'plugin' => $plugin_file,
        'new_version' => $release['version'],
        'url' => $release['url'],
        'package' => $release['package'],
        'requires' => $release['requires'],
        'tested' => $release['tested'],
        'requires_php' => $release['requires_php'],
    ];
    return $transient;
});

add_filter('plugins_api', function ($result, $action, $args) {
    if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'cms-web-finder') return $result;
    $release = cmsf_github_release_info();
    if (empty($release['version'])) return $result;
    return (object)[
        'name' => 'UA Web Finder',
        'slug' => 'cms-web-finder',
        'version' => $release['version'],
        'author' => 'UAServer',
        'homepage' => 'https://github.com/portallcomua/cms-web-finger',
        'download_link' => $release['package'] ?? '',
        'sections' => ['description' => 'Пошук українських сайтів, визначення CMS та збір контактів.'],
    ];
}, 10, 3);
