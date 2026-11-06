<?php
if (!defined('ABSPATH')) exit;

// ============ ПОШУКОВІ ЗАПИТИ ============
function cmsf_engines() {
    return ['bing' => 'Bing', 'duckduckgo' => 'DuckDuckGo', 'mojeek' => 'Mojeek'];
}

function cmsf_split_terms($v) {
    $out = [];
    foreach (preg_split('/[,;\n]/', (string)$v) as $x) {
        $x = trim($x);
        if ($x !== '') $out[] = $x;
    }
    return array_slice($out, 0, 10);
}

function cmsf_region_suffixes($regions) {
    $s = [];
    if (!$regions || in_array('world', $regions, true)) $s[] = '';
    if (in_array('ua', $regions, true)) $s[] = 'site:.ua';
    if (in_array('eu', $regions, true)) $s[] = '(site:.eu OR site:.de OR site:.pl OR site:.fr OR site:.it OR site:.es)';
    if (in_array('us', $regions, true)) $s[] = '(site:.us OR "United States")';
    return $s ?: [''];
}

function cmsf_make_queries($p) {
    $cms = array_values(array_filter((array)($p['cms'] ?? [])));
    $langs = (array)($p['languages'] ?? []);
    $terms = array_values(array_unique(array_merge(cmsf_split_terms($p['categories'] ?? ''), cmsf_split_terms($p['tags'] ?? ''), cmsf_split_terms($p['query'] ?? ''))));
    $any = !$langs || in_array('any', $langs, true);
    $base = [];

    $fp = [
        'WordPress' => ['inurl:"wp-content"'],
        'Joomla' => ['inurl:"option=com_content"', 'inurl:"index.php/component"'],
        'Drupal' => ['"Powered by Drupal"'],
        'OpenCart' => ['inurl:"route=product/product"'],
        'PrestaShop' => ['inurl:"controller=product"'],
        'Magento' => ['inurl:"catalog/product/view"'],
        'Shopify' => ['inurl:"/collections/" inurl:"/products/"'],
        'Bitrix' => ['inurl:"/bitrix/"'],
        'DLE' => ['"DataLife Engine"'],
        'CS-Cart' => ['"Powered by CS-Cart"'],
        'Wix' => ['"Created with Wix"'],
        'Tilda' => ['"Made on Tilda"'],
    ];
    $default_terms = $any || in_array('uk', $langs, true) || in_array('ru', $langs, true) ? ['сайт компанії'] : ['company website'];

    foreach ($cms as $c) {
        $T = $terms ?: ($c === 'WooCommerce' ? array_filter([($any || in_array('uk', $langs, true) || in_array('ru', $langs, true)) ? 'інтернет-магазин' : '', ($any || in_array('en', $langs, true)) ? 'online store' : '']) : $default_terms);
        if ($c === 'WooCommerce') {
            $phr = [];
            if ($any || in_array('uk', $langs, true)) $phr[] = '"Додати в кошик"';
            if (in_array('ru', $langs, true)) $phr[] = '"В корзину"';
            if ($any || in_array('en', $langs, true)) $phr[] = '"Add to cart"';
            foreach ($T as $t) {
                foreach ($phr as $ph) $base[] = "\"$t\" $ph";
                if ($any || in_array('uk', $langs, true)) $base[] = "\"$t\" \"Додати в кошик\" \"Додаткова інформація\"";
                if ($any || in_array('en', $langs, true)) $base[] = "\"$t\" \"Add to cart\" \"Related products\"";
            }
        } elseif ($c === 'HivePress') {
            foreach ($terms ?: ['directory', 'classifieds', 'listings'] as $t) { $base[] = "\"HivePress\" \"$t\""; $base[] = "WordPress HivePress $t"; }
        } else {
            foreach ($T as $t) {
                foreach ($fp[$c] ?? ["\"$c\""] as $f) $base[] = "$f \"$t\"";
            }
        }
    }
    if (!$cms) foreach ($terms ?: $default_terms as $t) { $base[] = "\"$t\" контакти"; $base[] = "$t official website"; }

    $out = [];
    foreach ($base as $q) foreach (cmsf_region_suffixes((array)($p['regions'] ?? [])) as $s) $out[] = trim("$q $s");
    return array_slice(array_values(array_unique($out)), 0, 40);
}

// ============ ПОШУКОВІ СИСТЕМИ ============
function cmsf_engine_url($engine, $q, $ua, $page) {
    $e = rawurlencode($q);
    if ($engine === 'bing') return "https://www.bing.com/search?q=$e&count=50" . ($page ? '&first=' . ($page * 50 + 1) : '') . ($ua ? '&cc=UA&setlang=uk' : '');
    if ($engine === 'duckduckgo') return "https://html.duckduckgo.com/html/?q=$e" . ($ua ? '&kl=ua-uk' : '');
    if ($engine === 'mojeek') return "https://www.mojeek.com/search?q=$e" . ($page ? '&s=' . ($page * 10 + 1) : '');
    return '';
}

function cmsf_engine_pages($engine) {
    return $engine === 'duckduckgo' ? 1 : 2;
}

// Лише органічні результати, а не всі посилання сторінки (меню, реклама, «схожі запити»)
function cmsf_result_hrefs($html, $engine) {
    $hs = [];
    if ($engine === 'bing') {
        if (preg_match_all('#<li[^>]+class=["\'][^"\']*\bb_algo\b[^"\']*["\'].*?<h2[^>]*>\s*<a[^>]+href=["\']([^"\']+)["\']#is', $html, $m)) $hs = $m[1];
    } elseif ($engine === 'duckduckgo') {
        if (preg_match_all('#<a\b[^>]*class=["\'][^"\']*result__a[^"\']*["\'][^>]*>#i', $html, $m)) foreach ($m[0] as $tag) if (preg_match('#href=["\']([^"\']+)#i', $tag, $h)) $hs[] = $h[1];
    } elseif ($engine === 'mojeek') {
        if (preg_match_all('#<a\b[^>]*class=["\'][^"\']*\b(?:title|ob)\b[^"\']*["\'][^>]*>#i', $html, $m)) foreach ($m[0] as $tag) if (preg_match('#href=["\']([^"\']+)#i', $tag, $h)) $hs[] = $h[1];
    }
    if ($hs) return $hs;
    return preg_match_all('#href\s*=\s*["\']([^"\']+)["\']#i', $html, $m) ? $m[1] : [];
}

function cmsf_blocked_host($host) {
    return (bool)preg_match('#(^|\.)(duckduckgo|google|bing|mojeek|facebook|instagram|youtube|linkedin|wikipedia|reddit|pinterest|tiktok|x|twitter|microsoft|apple|amazon|yahoo|wordpress|woocommerce|joomla|drupal|hivepress|github|stackoverflow|medium|quora|telegram|whatsapp|vk|w3|schema|cloudflare|trustpilot)\.#i', $host);
}

function cmsf_extract_domains($html, $engine) {
    $out = [];
    foreach (cmsf_result_hrefs($html, $engine) as $href) {
        $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (strpos($href, '//') === 0) $href = 'https:' . $href;
        if ($href !== '' && $href[0] === '/') $href = 'https://engine.invalid' . $href;
        $u = wp_parse_url($href);
        if (empty($u['host'])) continue;
        $q = [];
        if (!empty($u['query'])) parse_str($u['query'], $q);
        if ($engine === 'duckduckgo' && !empty($q['uddg'])) $u = wp_parse_url($q['uddg']);
        elseif ($engine === 'bing' && substr($u['host'], -8) === 'bing.com' && !empty($q['u'])) {
            $t = (string)$q['u'];
            if (strpos($t, 'a1') === 0) $t = base64_decode(strtr(substr($t, 2), '-_', '+/'));
            if (preg_match('#^https?://#i', $t)) $u = wp_parse_url($t);
        }
        if (empty($u['host']) || empty($u['scheme']) || !in_array($u['scheme'], ['http', 'https'], true)) continue;
        $host = cmsf_norm_domain($u['host']);
        if (!$host || strpos($host, '.') === false || cmsf_blocked_host($host)) continue;
        if (!empty($u['path']) && preg_match('#\.(css|js|png|jpe?g|gif|svg|webp|pdf|ico|woff2?)$#i', $u['path'])) continue;
        $out[$host] = true;
    }
    return array_keys($out);
}

// Повертає ['domains'=>[...]] або ['error'=>'...']
function cmsf_engine_search($engine, $q, $ua, $page) {
    $url = cmsf_engine_url($engine, $q, $ua, $page);
    if (!$url) return ['error' => 'Невідоме джерело'];
    $r = cmsf_http_get($url, 15, ['Accept-Language' => $ua ? 'uk-UA,uk;q=0.9,en;q=0.6' : 'en-US,en;q=0.9,uk;q=0.8']);
    if ($r['code'] === 202) return ['error' => 'джерело показало перевірку або блокування (HTTP 202)', 'blocked' => true];
    if (!$r['ok']) return ['error' => $r['error'] ?: 'Немає відповіді', 'blocked' => in_array($r['code'], [403, 429], true)];
    if (preg_match('/unusual traffic|captcha|verify you are human|access denied|anomaly-modal|are you a robot/i', substr($r['body'], 0, 50000))) return ['error' => 'джерело показало перевірку або блокування', 'blocked' => true];
    $domains = cmsf_extract_domains($r['body'], $engine);
    if ($ua) $domains = array_values(array_filter($domains, function ($d) { return substr($d, -3) === '.ua'; }));
    return ['domains' => $domains];
}

// ============ СПИСКИ ДОМЕНІВ ЗОНИ (Majestic Million) ============
const CMSF_MAJESTIC_URL = 'https://downloads.majestic.com/majestic_million.csv';

function cmsf_zones() {
    return [
        'ua' => ['ua'],
        'eu' => ['at', 'be', 'bg', 'hr', 'cy', 'cz', 'dk', 'ee', 'fi', 'fr', 'de', 'gr', 'hu', 'ie', 'it', 'lv', 'lt', 'lu', 'mt', 'nl', 'pl', 'pt', 'ro', 'sk', 'si', 'es', 'se', 'eu', 'no', 'ch', 'is', 'li'],
        'us' => ['us'],
        'world' => null,
    ];
}

function cmsf_zone_dir() {
    $u = wp_upload_dir();
    $d = trailingslashit($u['basedir']) . 'cms-web-finder/';
    if (!is_dir($d)) {
        wp_mkdir_p($d);
        @file_put_contents($d . 'index.html', '');
        @file_put_contents($d . '.htaccess', "Deny from all\n");
    }
    return $d;
}

function cmsf_zone_file($zone) {
    return cmsf_zone_dir() . 'zone-' . preg_replace('/[^a-z]/', '', $zone) . '.txt';
}

function cmsf_zone_info($zone) {
    $f = cmsf_zone_file($zone);
    $meta = get_option('cmsf_zone_meta', []);
    if (!file_exists($f)) return ['ready' => false, 'count' => 0, 'built' => 0];
    return ['ready' => true, 'count' => (int)($meta[$zone]['count'] ?? 0), 'built' => (int)($meta[$zone]['built'] ?? filemtime($f))];
}

// Крок завантаження списку: 8 МБ за запит (Range), щоб вкладатися в ліміти хостингу
function cmsf_zone_step($reset = false) {
    @set_time_limit(120);
    $dir = cmsf_zone_dir();
    $tmp = $dir . 'majestic.part';
    $chunkf = $dir . 'chunk.tmp';
    $st = get_option('cmsf_zone_dl', []);
    if ($reset || empty($st) || !file_exists($tmp)) {
        @unlink($tmp);
        $st = ['pos' => 0, 'total' => 0];
    }
    $size = 8 * 1024 * 1024;
    $r = wp_remote_get(CMSF_MAJESTIC_URL, [
        'timeout' => 100, 'stream' => true, 'filename' => $chunkf,
        'headers' => ['Range' => 'bytes=' . $st['pos'] . '-' . ($st['pos'] + $size - 1)],
    ]);
    if (is_wp_error($r)) { @unlink($chunkf); return ['error' => 'Не вдалося завантажити список: ' . $r->get_error_message()]; }
    $code = (int)wp_remote_retrieve_response_code($r);
    if ($code === 416) { // діапазон за межами файлу — все завантажено
        @unlink($chunkf);
    } elseif ($code === 206) {
        $cr = (string)wp_remote_retrieve_header($r, 'content-range');
        if (preg_match('#/(\d+)$#', $cr, $m)) $st['total'] = (int)$m[1];
        $data = @file_get_contents($chunkf);
        @unlink($chunkf);
        if ($data === false) return ['error' => 'Не вдалося прочитати завантажений фрагмент'];
        file_put_contents($tmp, $data, $st['pos'] === 0 ? 0 : FILE_APPEND);
        $st['pos'] += strlen($data);
    } elseif ($code === 200) { // сервер ігнорує Range і віддав файл повністю
        @unlink($tmp);
        if (!@rename($chunkf, $tmp)) return ['error' => 'Не вдалося зберегти файл у uploads'];
        $st['pos'] = $st['total'] = (int)filesize($tmp);
    } else {
        @unlink($chunkf);
        return ['error' => 'Сервер Majestic відповів HTTP ' . $code];
    }
    update_option('cmsf_zone_dl', $st, false);
    $done = $code === 416 || ($st['total'] > 0 && $st['pos'] >= $st['total']) || $code === 200;
    if (!$done) return ['done' => false, 'pct' => $st['total'] ? (int)round($st['pos'] / $st['total'] * 100) : 0];
    $res = cmsf_zone_build($tmp);
    @unlink($tmp);
    delete_option('cmsf_zone_dl');
    return $res;
}

function cmsf_zone_build($csv) {
    @set_time_limit(300);
    $zones = cmsf_zones();
    $outs = [];
    $counts = [];
    foreach ($zones as $z => $t) { $outs[$z] = fopen(cmsf_zone_file($z) . '.new', 'w'); $counts[$z] = 0; }
    $fh = fopen($csv, 'r');
    if (!$fh) return ['error' => 'Не вдалося відкрити файл списку'];
    $di = -1;
    while (($line = fgets($fh)) !== false) {
        if ($di < 0) {
            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
            $di = array_search('Domain', explode(',', rtrim($line, "\r\n")), true);
            if ($di === false) { fclose($fh); return ['error' => 'Невідомий формат списку Majestic']; }
            continue;
        }
        $c = explode(',', rtrim($line, "\r\n"));
        $d = strtolower($c[$di] ?? '');
        if ($d === '') continue;
        $tld = substr(strrchr($d, '.'), 1);
        foreach ($zones as $z => $t) {
            if ($t === null) { if ($counts[$z] >= 100000) continue; }
            elseif (!in_array($tld, $t, true)) continue;
            fwrite($outs[$z], $d . "\n");
            $counts[$z]++;
        }
    }
    fclose($fh);
    $meta = get_option('cmsf_zone_meta', []);
    foreach ($zones as $z => $t) {
        fclose($outs[$z]);
        @rename(cmsf_zone_file($z) . '.new', cmsf_zone_file($z));
        $meta[$z] = ['count' => $counts[$z], 'built' => time()];
    }
    update_option('cmsf_zone_meta', $meta, false);
    return ['done' => true, 'pct' => 100, 'counts' => $counts];
}

// Читає n доменів із зони, починаючи з байтової позиції
function cmsf_zone_take($zone, $pos, $n) {
    $f = cmsf_zone_file($zone);
    if (!file_exists($f)) return null;
    $fh = fopen($f, 'r');
    fseek($fh, (int)$pos);
    $out = [];
    while (count($out) < $n && ($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line !== '') $out[] = $line;
    }
    $newpos = ftell($fh);
    $eof = feof($fh) || fgets($fh) === false;
    fclose($fh);
    return ['domains' => $out, 'pos' => $newpos, 'eof' => $eof];
}

function cmsf_regions_to_zones($regions) {
    $z = [];
    foreach ((array)$regions as $r) if (in_array($r, ['ua', 'eu', 'us', 'world'], true)) $z[] = $r;
    return $z ?: ['world'];
}
