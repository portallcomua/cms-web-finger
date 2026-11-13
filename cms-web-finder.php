<?php
/*
Plugin Name: CMS Web Finder
Description: Пошук сайтів за CMS, збір контактів із публічних сторінок, списки, кампанії та керування відписками.
Version: 2.3.0
Author: UAServer
Developer: ChatGPT
Last Updated: 2026-11-13
Text Domain: cms-checker
*/

if (!defined('ABSPATH')) {
    exit;
}

// Версія, шляхи до файлів плагіна та адреса його ресурсів.
define('CMSF_VERSION', '2.3.0');
define('CMSF_DIR', plugin_dir_path(__FILE__));
define('CMSF_URL', plugin_dir_url(__FILE__));

// Основні модулі пошуку, бази даних та відправлення листів.
require_once CMSF_DIR . 'includes/db.php';
require_once CMSF_DIR . 'includes/detector.php';
require_once CMSF_DIR . 'includes/discover.php';
require_once CMSF_DIR . 'includes/smtp.php';
require_once CMSF_DIR . 'includes/mailer.php';
require_once CMSF_DIR . 'includes/ajax.php';
require_once CMSF_DIR . 'includes/admin.php';

// Новий модуль збору контактів, кампаній та глобального блеклиста.
require_once CMSF_DIR . 'includes/contacts.php';

/**
 * Окрема перевірка встановлення таблиць модуля контактів.
 * Виконується один раз після активації або оновлення схеми.
 */
function cmsf_contacts_maybe_install() {
    if (
        function_exists('cmsf_contacts_install') &&
        get_option('cmsf_contacts_db_version') !== '1.0'
    ) {
        cmsf_contacts_install();
        update_option('cmsf_contacts_db_version', '1.0', false);
    }
}

// Під час активації створюємо основні таблиці та таблиці контактного модуля.
register_activation_hook(__FILE__, function () {
    cmsf_install();
    cmsf_contacts_maybe_install();
});

// Під час оновлення версії оновлюємо основну базу та перевіряємо модуль контактів.
add_action('plugins_loaded', function () {
    if (get_option('cmsf_db_version') !== CMSF_VERSION) {
        cmsf_install();
    }

    cmsf_contacts_maybe_install();
});
