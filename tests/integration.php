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
$plain_id = cmsf_template_save(0, [
    'name' => 'Plain editor test',
    'topic' => 'CI',
    'subject' => 'Test',
    'body' => '<p>Hello</p><p>World</p>',
    'is_html' => 0,
]);
$plain = cmsf_template_get($plain_id);
if (!$plain || strpos($plain['body'], '<') !== false || strpos($plain['body'], "\n") === false) {
    $fail('Rich editor plain-text conversion failed');
}
$html_id = cmsf_template_save(0, [
    'name' => 'HTML editor test',
    'topic' => 'CI',
    'subject' => 'Test',
    'body' => '<p>Safe</p><script>alert(1)</script>',
    'is_html' => 1,
]);
$html = cmsf_template_get($html_id);
if (!$html || strpos($html['body'], '<script') !== false || strpos($html['body'], '<p>Safe</p>') === false) {
    $fail('HTML editor sanitization failed');
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
    ['state' => 'ok', 'version' => '5.9.0'],
    ['domain' => 'shared.example.ua']
);
$first_site_id = (int)$wpdb->get_var(
    $wpdb->prepare(
        'SELECT id FROM ' . cmsf_t('sites') . ' WHERE list_id=%d AND domain=%s',
        $list_a,
        'shared.example.ua'
    )
);
$saved_note = cmsf_note_save($first_site_id, 'shared note');
if ($saved_note === false || strpos($saved_note, 'shared note') === false) {
    $fail('Shared note save helper failed');
}
$notes = $wpdb->get_col(
    $wpdb->prepare(
        'SELECT note FROM ' . cmsf_t('sites') . ' WHERE domain=%s',
        'shared.example.ua'
    )
);
if (count($notes) !== 2 || count(array_unique($notes)) !== 1) {
    $fail('Shared notes across lists failed');
}
$sync_result = cmsfc_sync_site_emails_to_finder(
    'shared.example.ua',
    ['one@example.ua', 'two@example.ua', 'one@example.ua']
);
if ($sync_result === false) {
    $fail('Contact crawler email sync failed');
}
$stored_emails = $wpdb->get_col(
    $wpdb->prepare(
        'SELECT email FROM ' . cmsf_t('sites') . ' WHERE domain=%s',
        'shared.example.ua'
    )
);
if (count($stored_emails) !== 2 || count(array_unique($stored_emails)) !== 1 || $stored_emails[0] !== 'one@example.ua, two@example.ua') {
    $fail('All crawler emails were not mirrored as a comma-separated value');
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
