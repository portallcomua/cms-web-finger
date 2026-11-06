<?php
if (!defined('ABSPATH')) exit;

// ============ ДОМЕНИ ТА HTTP ============
function cmsf_norm_domain($s) {
    $s = trim(mb_strtolower((string)$s));
    if ($s === '') return '';
    $s = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $s);
    $s = preg_replace('#[/?\#].*$#', '', $s);
    $s = preg_replace('#^.*@#', '', $s);
    $s = preg_replace('#:\d+$#', '', $s);
    $s = preg_replace('#^www\.#', '', $s);
    $s = trim($s, ". \t");
    if ($s === '' || !preg_match('/^[a-z0-9\x{0400}-\x{04FF}]([a-z0-9\x{0400}-\x{04FF}.-]*[a-z0-9\x{0400}-\x{04FF}])?$/u', $s)) return '';
    if (preg_match('/[^\x00-\x7F]/', $s) && function_exists('idn_to_ascii')) {
        $a = idn_to_ascii($s, 0, defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0);
        if ($a) $s = $a;
    }
    return substr($s, 0, 190);
}

function cmsf_http_get($url, $timeout = 10, $headers = []) {
    $args = [
        'timeout' => $timeout,
        'redirection' => 5,
        'sslverify' => true,
        'limit_response_size' => 1500000,
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'headers' => array_merge(['Accept-Language' => 'uk-UA,uk;q=0.9,en;q=0.6', 'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'], $headers),
    ];
    // safe-версія не дає звертатися до локальних/приватних адрес (захист від SSRF)
    $r = wp_safe_remote_get($url, $args);
    if (is_wp_error($r)) return ['ok' => false, 'code' => 0, 'body' => '', 'error' => $r->get_error_message(), 'url' => $url, 'ctype' => '', 'headers' => ''];
    $code = (int)wp_remote_retrieve_response_code($r);
    $hs = '';
    foreach (wp_remote_retrieve_headers($r) as $k => $v) $hs .= $k . ':' . (is_array($v) ? implode(',', $v) : $v) . "\n";
    $final = $url;
    if (!empty($r['http_response']) && is_object($r['http_response']) && method_exists($r['http_response'], 'get_response_object')) {
        $o = $r['http_response']->get_response_object();
        if ($o && !empty($o->url)) $final = $o->url;
    }
    $ctype = (string)wp_remote_retrieve_header($r, 'content-type');
    return ['ok' => $code >= 200 && $code < 400, 'code' => $code, 'body' => (string)wp_remote_retrieve_body($r), 'error' => $code >= 400 ? 'HTTP ' . $code : '', 'url' => $final, 'ctype' => $ctype, 'headers' => $hs];
}

function cmsf_to_utf8($body, $ctype) {
    $cs = '';
    if (preg_match('/charset=([\w-]+)/i', $ctype, $m)) $cs = $m[1];
    elseif (preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($body, 0, 4000), $m)) $cs = $m[1];
    $cs = strtolower($cs);
    if ($cs && $cs !== 'utf-8' && $cs !== 'utf8') {
        $conv = @mb_convert_encoding($body, 'UTF-8', $cs);
        if ($conv !== false && $conv !== '') return $conv;
    }
    return $body;
}

function cmsf_text($html) {
    $t = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html);
    $t = wp_strip_all_tags($t);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $t));
}

// ============ ПЛАТФОРМИ ============
function cmsf_platforms() {
    return ['WooCommerce', 'WordPress', 'HivePress', 'Joomla', 'Drupal', 'OpenCart', 'PrestaShop', 'Magento', 'Shopify', 'Bitrix', 'DLE', 'CS-Cart', 'Wix', 'Tilda', 'Squarespace', 'Webflow', 'BigCommerce', 'Shopware', 'MODX'];
}

function cmsf_is_joomla($h) {
    if (preg_match('#<meta[^>]+name=["\']generator["\'][^>]+content=["\']joomla#', $h) || preg_match('#<meta[^>]+content=["\']joomla[^>]+name=["\']generator#', $h)) return true;
    if (strpos($h, 'joomla-script-options') !== false) return true;
    return (bool)preg_match('#(?:src|href|action)=["\'][^"\']*(?:/media/system/js/|/media/jui/|/media/vendor/joomla|/components/com_[a-z0-9_]+/|index\.php\?option=com_)#', $h);
}

// Визначення CMS за розміткою (шляхи, класи, generator), а не за словом у тексті сторінки
function cmsf_detect_cms($html, $headers = '') {
    $scripts = [];
    if (preg_match_all('#<script\b[^>]*>.*?</script>#is', $html, $matches)) $scripts = $matches[0];
    $markup = preg_replace('#<!--.*?-->#s', ' ', $html);
    $markup = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $markup);
    $markup = preg_replace('#>([^<]+)<#s', '><', $markup);
    $h = strtolower($markup . "\n" . implode("\n", $scripts) . "\n" . $headers);
    $wp = preg_match('#wp-content/(?:plugins|themes)/|wp-includes/[^"\'\s>]+|wp-json(?:/|["\' ])|<meta[^>]+name=["\']generator["\'][^>]+wordpress#', $h);
    if ($wp) {
        if (preg_match('#wp-content/plugins/hivepress/|class=["\'][^"\']*\bhp-(?:listing|vendor|page|form|template)\b#', $h)) return 'HivePress';
        if (preg_match('#wp-content/plugins/woocommerce/|class=["\'][^"\']*\bwoocommerce(?:-[a-z0-9_-]+)?\b|(?:window\.)?wc_add_to_cart_params|wc-ajax(?:=|["\'])|<meta[^>]+generator[^>]+woocommerce#', $h)) return 'WooCommerce';
        return 'WordPress';
    }
    if (cmsf_is_joomla($h)) return 'Joomla';
    $tests = [
        ['Drupal', '#drupalsettings|sites/default/files|<meta[^>]+generator[^>]+drupal|/core/misc/drupal#'],
        ['OpenCart', '#index\.php\?route=|catalog/view/theme#'],
        ['CS-Cart', '#/design/themes/|tygh\.#'],
        ['Shopify', '#cdn\.shopify\.com|shopify\.theme|x-shopify#'],
        ['PrestaShop', '#/modules/ps_#'],
        ['Magento', '#mage/cookies|static/version\d+/frontend#'],
        ['Bitrix', '#/bitrix/(?:js|templates|cache|components)/|bx-core|bitrix/php_interface#'],
        ['DLE', '#<meta[^>]+generator[^>]+datalife engine|/engine/classes/#'],
        ['Wix', '#wixstatic\.com|wix\.com#'],
        ['Squarespace', '#squarespace\.com|static1\.squarespace#'],
        ['Webflow', '#webflow\.com|website-files\.com#'],
        ['BigCommerce', '#bigcommerce\.com|cdn\d+\.bigcommerce#'],
        ['Shopware', '#shopware#'],
        ['Tilda', '#tildacdn\.com|tilda\.ws#'],
        ['MODX', '#modx|assets/components/modx#'],
    ];
    foreach ($tests as $t) if (preg_match($t[1], $h)) return $t[0];
    return 'Не визначено';
}

// ============ ВЕРСІЇ ============
function cmsf_fetch_version($url, $pattern, $timeout = 6) {
    $r = cmsf_http_get($url, $timeout);
    if (!$r['ok'] || $r['body'] === '') return '';
    return preg_match($pattern, $r['body'], $m) ? $m[1] : '';
}

function cmsf_detect_version($cms, $base, $html) {
    if ($cms === 'Joomla') {
        foreach ([
            ['/administrator/manifests/files/joomla.xml', '#<version>([\d.]+)</version>#i'],
            ['/language/en-GB/langmetadata.xml', '#<version>([\d.]+)</version>#i'],
            ['/language/en-GB/en-GB.xml', '#<version>([\d.]+)</version>#i'],
        ] as $p) {
            $v = cmsf_fetch_version($base . $p[0], $p[1]);
            if ($v) return $v;
        }
        if (preg_match('#<meta[^>]+name=["\']generator["\'][^>]+content=["\']Joomla!?\s*([\d.]+)#i', $html, $m)) return $m[1];
        return '';
    }
    if (in_array($cms, ['WordPress', 'WooCommerce', 'HivePress'], true)) {
        if (preg_match('#<meta[^>]+name=["\']generator["\'][^>]+content=["\']WordPress\s*([\d.]+)#i', $html, $m)) return $m[1];
        if (preg_match('#/wp-includes/[^"\'\s>]+\?ver=(\d+\.\d+(?:\.\d+)?)#', $html, $m)) return $m[1];
        $v = cmsf_fetch_version($base . '/feed/', '#<generator>https?://wordpress\.org/\?v=([\d.]+)</generator>#i');
        if ($v) return $v;
        return cmsf_fetch_version($base . '/readme.html', '#Version\s*([\d.]+)#i');
    }
    if ($cms === 'Drupal') {
        if (preg_match('#<meta[^>]+name=["\']generator["\'][^>]+content=["\']Drupal\s*([\d.]+)#i', $html, $m)) return $m[1];
        return cmsf_fetch_version($base . '/CHANGELOG.txt', '#Drupal\s+(\d+\.\d+(?:\.\d+)?)#i');
    }
    return '';
}

function cmsf_fetch_latest() {
    $out = ['Joomla' => '', 'WordPress' => '', 'Drupal' => ''];
    $r = wp_remote_get('https://api.wordpress.org/core/version-check/1.7/', ['timeout' => 8]);
    if (!is_wp_error($r)) {
        $j = json_decode(wp_remote_retrieve_body($r), true);
        if (!empty($j['offers'][0]['current'])) $out['WordPress'] = $j['offers'][0]['current'];
    }
    foreach (['Joomla' => 'joomla/joomla-cms', 'Drupal' => 'drupal/drupal'] as $k => $repo) {
        $r = wp_remote_get("https://api.github.com/repos/$repo/tags?per_page=100", ['timeout' => 8, 'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'cms-web-finder']]);
        if (is_wp_error($r)) continue;
        $j = json_decode(wp_remote_retrieve_body($r), true);
        $best = '';
        if (is_array($j)) foreach ($j as $t) {
            $n = ltrim((string)($t['name'] ?? ''), 'v');
            if (preg_match('/^\d+\.\d+(\.\d+)?$/', $n) && ($best === '' || version_compare($n, $best, '>'))) $best = $n;
        }
        $out[$k] = $best;
    }
    return $out;
}

// Остання версія: ручне значення з налаштувань має пріоритет, інакше кеш (12 год)
function cmsf_latest($cms) {
    $key = in_array($cms, ['WordPress', 'WooCommerce', 'HivePress'], true) ? 'WordPress' : $cms;
    if (!in_array($key, ['Joomla', 'WordPress', 'Drupal'], true)) return '';
    $s = cmsf_settings();
    $manual = trim((string)($s['latest_' . strtolower($key)] ?? ''));
    if ($manual !== '') return $manual;
    $cache = get_transient('cmsf_latest_cache');
    if ($cache === false) {
        $cache = cmsf_fetch_latest();
        set_transient('cmsf_latest_cache', $cache, array_filter($cache) ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);
    }
    return (string)($cache[$key] ?? '');
}

function cmsf_is_outdated($ver, $latest) {
    if (!preg_match('/^\d+\.\d+/', (string)$ver) || !preg_match('/^\d+\.\d+/', (string)$latest)) return false;
    return version_compare($ver, $latest, '<');
}

// ============ МОВА, КРАЇНА, ТИП ============
function cmsf_text_lang($text) {
    $s = mb_strtolower(mb_substr($text, 0, 8000));
    $uk = preg_match_all('/[іїєґ]/u', $s);
    $ru = preg_match_all('/[ыэъё]/u', $s);
    $en = preg_match_all('/\b(the|and|with|shop|contact|about|shipping|cart)\b/u', $s);
    if ($uk >= 3) return 'uk';
    if ($ru >= 3) return 'ru';
    if ($en >= 3) return 'en';
    return '';
}

function cmsf_detect_langs($html, $text) {
    $set = [];
    if (preg_match('/<html[^>]*\blang=["\']?([a-zA-Z-]+)/i', $html, $m)) $set[] = strtolower(substr($m[1], 0, 2));
    if (preg_match_all('/hreflang=["\']([a-zA-Z-]+)["\']/i', $html, $mm)) foreach ($mm[1] as $x) $set[] = strtolower(substr($x, 0, 2));
    return array_values(array_unique(array_filter($set, function ($x) { return preg_match('/^[a-z]{2}$/', $x); })));
}

function cmsf_lang_name($c) {
    return ['uk' => 'Українська', 'ru' => 'Російська', 'en' => 'Англійська'][$c] ?? $c;
}

function cmsf_detect_country($domain, $text, $phones, $html_lang, $langs) {
    $tld = strtolower(substr(strrchr($domain, '.') ?: '', 1));
    $sig = [];
    $code = '';
    if ($tld === 'ua') { $code = 'UA'; $sig[] = '.ua'; }
    foreach ($phones as $p) {
        $d = preg_replace('/\D/', '', $p);
        if (strpos($d, '380') === 0 && strlen($d) === 12) { $code = $code ?: 'UA'; $sig[] = '+380'; break; }
    }
    if (preg_match('/грн|₴|\buah\b/iu', mb_substr($text, 0, 30000))) { $code = $code ?: 'UA'; $sig[] = 'грн'; }
    $is_cc = (bool)preg_match('/^[a-z]{2}$/', $tld);
    $weak = (!$code && in_array('uk', $langs, true) && !$is_cc);
    if (!$code && ($tld === 'us' || preg_match('/^en-us/i', $html_lang))) $code = 'US';
    if (!$code && $is_cc) $code = $tld === 'uk' ? 'GB' : strtoupper($tld);
    $eu = ['at', 'be', 'bg', 'hr', 'cy', 'cz', 'dk', 'ee', 'fi', 'fr', 'de', 'gr', 'hu', 'ie', 'it', 'lv', 'lt', 'lu', 'mt', 'nl', 'pl', 'pt', 'ro', 'sk', 'si', 'es', 'se', 'eu', 'no', 'ch', 'is', 'li', 'gb', 'uk'];
    $europe = in_array($tld, $eu, true) || ($code && in_array(strtolower($code), $eu, true));
    if ($code === 'UA') $label = 'Україна (' . implode(', ', array_unique($sig)) . ')';
    elseif ($weak) $label = 'Невизначено (лише українська мова)';
    elseif ($code) $label = 'Інша (' . $code . ')';
    else $label = 'Невідомо';
    return ['code' => $code, 'europe' => $europe, 'ua_domain' => $tld === 'ua', 'label' => $label];
}

function cmsf_site_type($html, $title, $text, $cms) {
    $s = mb_strtolower($title . ' ' . mb_substr($text, 0, 12000));
    $h = strtolower(substr($html, 0, 250000));
    if (preg_match('/hivepress|hp-listing|directory|classifieds|оголошенн|каталог компан|каталог підприємств|довідник компан/u', $s)) return 'Каталог / оголошення';
    if (in_array($cms, ['WooCommerce', 'OpenCart', 'Shopify', 'PrestaShop', 'Magento', 'BigCommerce', 'Shopware', 'CS-Cart'], true)
        || preg_match('/add to cart|додати в кошик|додати до кошика|в корзину|shopping cart|product-category|add-to-cart/', $h)) return 'Інтернет-магазин';
    if (preg_match('/новини|новости|news|редакц|університет|university|коледж|college|розклад занять|навчальн/u', $s)) return 'Новини / освіта (можливо)';
    if (preg_match('/послуги|services|про компанію|about us|виробник|manufacturer/u', $s)) return 'Компанія / послуги (можливо)';
    return 'Не визначено';
}

function cmsf_stems($term) {
    $out = [];
    foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($term), -1, PREG_SPLIT_NO_EMPTY) as $w) {
        if (mb_strlen($w) >= 4) $out[] = mb_substr($w, 0, max(4, mb_strlen($w) - 2));
    }
    return $out;
}

function cmsf_relevance($terms, $hay) {
    $hay = mb_strtolower($hay);
    $n = 0;
    foreach ($terms as $t) foreach (cmsf_stems($t) as $st) if (mb_strpos($hay, $st) !== false) { $n++; break; }
    return $n;
}

// ============ КОНТАКТИ ============
function cmsf_extract_contacts($html, $text) {
    $emails = [];
    if (preg_match_all('/mailto:([^"\'?\s>]+)/i', $html, $m)) foreach ($m[1] as $e) $emails[] = rawurldecode($e);
    if (preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,10}/', $html . ' ' . $text, $m)) foreach ($m[0] as $e) $emails[] = $e;
    $clean = [];
    foreach ($emails as $e) {
        $e = strtolower(trim($e));
        if (!is_email($e)) continue;
        if (preg_match('/\.(png|jpe?g|gif|webp|svg|css|js)$/', $e)) continue;
        if (preg_match('/(example\.com|sentry\.io|wixpress\.com|domain\.com|email\.com)$/', $e)) continue;
        if (preg_match('/^(noreply|no-reply|donotreply|name|user|your)@/', $e)) continue;
        $clean[$e] = true;
    }
    $phones = [];
    if (preg_match_all('/href=["\']tel:([^"\']+)/i', $html, $m)) foreach ($m[1] as $p) $phones[] = trim(rawurldecode($p));
    if (preg_match_all('/(?:\+?\d[\d\s().-]{7,}\d)/', $text, $m)) foreach ($m[0] as $p) {
        $p = trim(preg_replace('/\s+/', ' ', $p));
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $p)) continue;
        $phones[] = $p;
    }
    $ph = [];
    foreach ($phones as $p) {
        $n = strlen(preg_replace('/\D/', '', $p));
        if ($n >= 9 && $n <= 15) $ph[$p] = true;
    }
    return ['emails' => array_slice(array_keys($clean), 0, 10), 'phones' => array_slice(array_keys($ph), 0, 6)];
}

// Основний email: спершу з домену самого сайту, потім решта
function cmsf_best_email($emails, $domain) {
    foreach ($emails as $e) {
        $d = substr(strrchr($e, '@'), 1);
        if ($d === $domain || substr($d, -strlen($domain) - 1) === '.' . $domain) return $e;
    }
    return $emails[0] ?? '';
}

// ============ ВІДПОВІДНІСТЬ ПАРАМЕТРАМ ============
function cmsf_match_platform($selected, $cms) {
    if (!$selected) return $cms !== 'Не визначено';
    foreach ($selected as $p) {
        if ($p === $cms) return true;
        if ($p === 'WordPress' && in_array($cms, ['WordPress', 'WooCommerce', 'HivePress'], true)) return true;
    }
    return false;
}

function cmsf_match_region($regions, $c) {
    if (!$regions || in_array('world', $regions, true)) return true;
    return (in_array('ua', $regions, true) && !empty($c['ua_domain']))
        || (in_array('eu', $regions, true) && $c['europe'])
        || (in_array('us', $regions, true) && $c['code'] === 'US');
}

function cmsf_match_lang($selected, $langs) {
    if (!$selected || in_array('any', $selected, true)) return true;
    if (!$langs) return false;
    return (bool)array_intersect($selected, $langs);
}

function cmsf_match_type($want, $type) {
    if (!$want || $want === 'any') return true;
    if ($want === 'shop') return strpos($type, 'Інтернет-магазин') === 0;
    if ($want === 'catalog') return strpos($type, 'Каталог') === 0;
    return true;
}

// ============ ПЕРЕВІРКА ОДНОГО САЙТУ ============
function cmsf_woo_store_api($origin) {
    foreach (['/wp-json/wc/store/v1/products?per_page=1', '/?rest_route=/wc/store/v1/products&per_page=1'] as $p) {
        $r = cmsf_http_get($origin . $p, 6, ['Accept' => 'application/json']);
        if ($r['ok'] && is_array(json_decode($r['body'], true))) return true;
    }
    return false;
}

function cmsf_inspect($domain, $params) {
    $t0 = microtime(true);
    $left = function () use ($t0) { return 24 - (microtime(true) - $t0); };
    $rec = [
        'domain' => $domain, 'url' => 'https://' . $domain, 'state' => 'failed', 'cms' => '', 'version' => '', 'latest' => '', 'outdated' => 0,
        'http_status' => '', 'title' => '', 'country' => '', 'language' => '', 'site_type' => '', 'relevance' => 0,
        'email' => '', 'emails' => '', 'phone' => '', 'reject_reason' => '', 'checked_at' => cmsf_now(),
    ];

    $res = cmsf_http_get('https://' . $domain . '/', 8);
    if ($res['code'] === 0) $res = cmsf_http_get('http://' . $domain . '/', 5);
    if (!$res['ok'] || $res['body'] === '') {
        $rec['http_status'] = $res['error'] ?: 'Недоступний';
        $rec['reject_reason'] = $rec['http_status'];
        return $rec;
    }
    if ($res['ctype'] && stripos($res['ctype'], 'html') === false) {
        $rec['http_status'] = 'Не HTML';
        $rec['reject_reason'] = 'Не HTML';
        return $rec;
    }
    $rec['http_status'] = 'Працює';
    $rec['url'] = preg_match('#^https?://#i', $res['url']) ? esc_url_raw(preg_replace('#^(https?://[^/]+).*$#i', '$1', $res['url'])) : $rec['url'];
    $base = rtrim($rec['url'], '/');
    $html = cmsf_to_utf8($res['body'], $res['ctype']);
    $text = cmsf_text($html);

    $selected_cms = array_values(array_filter((array)($params['cms'] ?? [])));
    $cms = cmsf_detect_cms($html, $res['headers']);
    // «Голий» WordPress, а шукаємо WooCommerce — підтверджуємо через публічний Store API магазину
    if ($cms === 'WordPress' && in_array('WooCommerce', $selected_cms, true) && $left() > 10) {
        if (cmsf_woo_store_api($base)) $cms = 'WooCommerce';
    }
    $rec['cms'] = $cms;
    $reasons = [];
    if (!cmsf_match_platform($selected_cms, $cms)) $reasons[] = 'CMS: ' . $cms;

    $title = preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) ? trim(html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : $domain;
    $rec['title'] = mb_substr(preg_replace('/\s+/u', ' ', $title), 0, 250);

    if ($reasons) { // ранній відсів — зайвих запитів до сайту не робимо
        $rec['state'] = 'rejected';
        $rec['reject_reason'] = implode('; ', $reasons);
        return $rec;
    }

    $contacts = cmsf_extract_contacts($html, $text);
    $langs = cmsf_detect_langs($html, $text);
    $html_lang = preg_match('/<html[^>]*\blang=["\']?([a-zA-Z-]+)/i', $html, $m) ? $m[1] : '';
    $country = cmsf_detect_country($domain, $text, $contacts['phones'], $html_lang, $langs);
    $type = cmsf_site_type($html, $rec['title'], $text, $cms);

    if (!cmsf_match_region((array)($params['regions'] ?? []), $country)) $reasons[] = 'країна: ' . $country['label'];
    if (!cmsf_match_lang((array)($params['languages'] ?? []), $langs)) $reasons[] = 'мова: ' . ($langs ? implode('/', array_map('cmsf_lang_name', $langs)) : 'невідомо');
    if (!cmsf_match_type($params['site_type'] ?? 'any', $type)) $reasons[] = 'тип: ' . $type;

    $rec['country'] = $country['label'];
    $rec['language'] = $langs ? implode(', ', array_map('cmsf_lang_name', $langs)) : 'Невідомо';
    $rec['site_type'] = $type;
    $rec['relevance'] = cmsf_relevance((array)($params['terms'] ?? []), $rec['title'] . ' ' . $text);
    if (!empty($params['require_terms']) && !empty($params['terms']) && $rec['relevance'] === 0) $reasons[] = 'немає збігу з категоріями/тегами';

    if ($reasons) {
        $rec['state'] = 'rejected';
        $rec['reject_reason'] = implode('; ', $reasons);
        return $rec;
    }

    // Сайт підходить: докладаємо версію та контакти
    if ($left() > 12) {
        $rec['version'] = cmsf_detect_version($cms, $base, $html);
        $rec['latest'] = cmsf_latest($cms);
        $rec['outdated'] = cmsf_is_outdated($rec['version'], $rec['latest']) ? 1 : 0;
    }
    if (!$contacts['emails'] && !$contacts['phones']) {
        $paths = [];
        if (preg_match_all('#<a\b[^>]*href=["\']([^"\'\#]+)["\'][^>]*>(.*?)</a>#is', $html, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $a) {
                if (preg_match('/contact|контакт|зв\'яз|про нас|about/iu', wp_strip_all_tags($a[2]) . ' ' . $a[1])) {
                    $href = $a[1];
                    if (preg_match('#^https?://#i', $href)) { if (cmsf_norm_domain($href) !== $domain) continue; $paths[] = $href; }
                    elseif (strpos($href, '//') !== 0 && strpos($href, 'mailto:') !== 0 && strpos($href, 'tel:') !== 0 && strpos($href, 'javascript:') !== 0) $paths[] = $base . '/' . ltrim($href, '/');
                    break;
                }
            }
        }
        foreach (array_merge($paths, [$base . '/contacts', $base . '/contact', $base . '/kontakty']) as $u) {
            if ($left() < 7) break;
            $r2 = cmsf_http_get($u, 5);
            if (!$r2['ok'] || $r2['body'] === '') continue;
            $h2 = cmsf_to_utf8($r2['body'], $r2['ctype']);
            $c2 = cmsf_extract_contacts($h2, cmsf_text($h2));
            if ($c2['emails'] || $c2['phones']) { $contacts = $c2; break; }
        }
    }
    $rec['email'] = cmsf_best_email($contacts['emails'], $domain);
    $rec['emails'] = implode('; ', $contacts['emails']);
    $rec['phone'] = implode('; ', $contacts['phones']);
    $rec['state'] = 'ok';
    return $rec;
}
