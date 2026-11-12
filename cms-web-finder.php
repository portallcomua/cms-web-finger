
<?php
/*
Plugin Name: CMS Web Finder
Description: Пошук сайтів за CMS, країною та мовою (з перевіркою по коду), збережені списки, шаблони листів і розсилка з прогресом та історією відправлень
Version: 2.2.2
Author: UAServer
Text Domain: cms-checker
*/

if (!defined('ABSPATH')) exit;

define('CMSF_VERSION', '2.2.2');
define('CMSF_DIR', plugin_dir_path(__FILE__));
define('CMSF_URL', plugin_dir_url(__FILE__));

require_once CMSF_DIR . 'includes/db.php';
require_once CMSF_DIR . 'includes/detector.php';
require_once CMSF_DIR . 'includes/discover.php';
require_once CMSF_DIR . 'includes/smtp.php';
require_once CMSF_DIR . 'includes/mailer.php';
require_once CMSF_DIR . 'includes/ajax.php';
require_once CMSF_DIR . 'includes/admin.php';

register_activation_hook(__FILE__, 'cmsf_install');

add_action('plugins_loaded', function () {
    if (get_option('cmsf_db_version') !== CMSF_VERSION) {
        cmsf_install();
    }
});
