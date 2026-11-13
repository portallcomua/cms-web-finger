
<?php
/*
Plugin Name: CMS Web Finder
Description: Пошук сайтів за CMS, збір контактів із публічних сторінок, списки, кампанії та керування відписками.
Version: 2.3.1
Author: UAServer
Text Domain: cms-checker
*/

if (!defined('ABSPATH')) {
    exit;
}

// Версія плагіна та шляхи до його файлів.
define('CMSF_VERSION', '2.3.1');
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
