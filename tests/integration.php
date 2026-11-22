<?php
// Executed by the GitHub Actions WordPress integration workflow.
global $wpdb;

$fail = function ($message) {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

if (!get_option('cmsf_migrated')) {
    $fail('Legacy migration flag missing');
}

$legacy = $wpdb->get_row(
    "SELECT * FROM " . cmsf_t('sites') . " WHERE domain='legacy.example.ua'",
    ARRAY_A
);
if (!$legacy || strpos((string)$legacy['note'], 'legacy note') === false) {
    $fail('Legacy site or note was not migrated');
}
if (!cmsf_templates_all()) {
    $fail('Template table was not seeded/migrated');
}

$list_a = cmsf_list_create('Integration A', []);
$list_b = cmsf_list_create('Integration B', []);
if (cmsf_add_queued($list_a, ['shared.example.ua']) !== 1) {
    $fail('First list insert failed');
}
if (cmsf_add_queued($list_b, ['shared.example.ua']) !== 1) {
    $fail('Cross-list domain insert failed');
}

$wpdb->update(
    cmsf_t('sites'),
    ['state' => 'ok', 'version' => '5.9.0', 'note' => '[22.11.2026 00:00] shared note'],
    ['domain' => 'shared.example.ua']
);
$notes = $wpdb->get_col(
    $wpdb->prepare(
        'SELECT note FROM ' . cmsf_t('sites') . ' WHERE domain=%s',
        'shared.example.ua'
    )
);
if (count($notes) !== 2 || count(array_unique($notes)) !== 1) {
    $fail('Shared notes across lists failed');
}

list($where, $args) = cmsf_where($list_a, [
    'state' => 'all',
    'notes' => 'with',
    'version_min' => '1.5',
    'version_max' => '6.00',
]);
$count = (int)$wpdb->get_var(
    $wpdb->prepare('SELECT COUNT(*) FROM ' . cmsf_t('sites') . ' WHERE ' . $where, $args)
);
if ($count !== 1) {
    $fail('Version range / notes filter failed');
}

foreach (['shop.in.ua', 'site.kyiv.ua', 'site.укр'] as $domain) {
    if (!cmsf_is_ua_domain($domain)) {
        $fail('UA domain suffix detection failed: ' . $domain);
    }
}
$langs = cmsf_detect_langs(
    '<html><body></body></html>',
    'Ми пропонуємо якісні меблі, вироби та послуги для вашого дому і родини.'
);
if (!in_array('uk', $langs, true)) {
    $fail('Ukrainian language fallback failed');
}
$wp = cmsf_detect_cms(
    '<html><script src="/wp-includes/js/jquery.js"></script><link href="/wp-content/themes/test/style.css"></html>'
);
if ($wp !== 'WordPress') {
    $fail('WordPress detection failed: ' . $wp);
}
$joomla = cmsf_detect_cms('<html><script src="/media/system/js/core.js"></script></html>');
if ($joomla !== 'Joomla') {
    $fail('Joomla detection failed: ' . $joomla);
}
$contacts = cmsf_extract_contacts(
    '<a href="mailto:one@example.ua">one</a><p>two@example.ua</p>',
    'two@example.ua'
);
if (count($contacts['emails']) !== 2) {
    $fail('Multiple email extraction failed');
}

echo "PASS: WordPress activation, legacy migration, data retention, multi-list shared notes, version/note filters, UA domains, language/CMS detection and multi-email extraction" . PHP_EOL;
