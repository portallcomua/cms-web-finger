<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_menu_page('CMS Web Finder', 'CMS Web Finder', 'manage_options', 'cms-checker', 'cmsf_page_search', 'dashicons-search', 30);
    add_submenu_page('cms-checker', 'Пошук сайтів', 'Пошук', 'manage_options', 'cms-checker', 'cmsf_page_search');
    add_submenu_page('cms-checker', 'Свій список доменів', 'Свій список', 'manage_options', 'cmsf-import', 'cmsf_page_import');
    add_submenu_page('cms-checker', 'Збережені списки', 'Списки', 'manage_options', 'cmsf-lists', 'cmsf_page_lists');
    add_submenu_page('cms-checker', 'Шаблони листів', 'Шаблони листів', 'manage_options', 'cmsf-templates', 'cmsf_page_templates');
    add_submenu_page('cms-checker', 'Налаштування', 'Налаштування', 'manage_options', 'cmsf-settings', 'cmsf_page_settings');
});

add_action('admin_enqueue_scripts', function () {
    $page = $_GET['page'] ?? '';
    if (!in_array($page, ['cms-checker', 'cmsf-import', 'cmsf-lists', 'cmsf-templates', 'cmsf-settings'], true)) return;
    wp_enqueue_style('cmsf-admin', CMSF_URL . 'assets/admin.css', [], CMSF_VERSION);
    wp_enqueue_script('cmsf-admin', CMSF_URL . 'assets/admin.js', [], CMSF_VERSION, true);
    $s = cmsf_settings();
    wp_localize_script('cmsf-admin', 'CMSF', [
        'ajax' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('cmsf'),
        'exportUrl' => admin_url('admin-post.php'),
        'exportNonce' => wp_create_nonce('cmsf_export'),
        'page' => $page,
        'listsUrl' => admin_url('admin.php?page=cmsf-lists'),
        'concurrency' => (int)$s['concurrency'],
        'delay' => (int)$s['delay'],
        'engines' => cmsf_engines(),
        'openList' => (int)($_GET['list'] ?? 0),
        'templatesUrl' => admin_url('admin.php?page=cmsf-templates'),
    ]);
});

function cmsf_pills($name, $items, $checked = []) {
    foreach ($items as $val => $label) {
        printf('<label class="cmsf-pill"><input type="%s" name="%s" value="%s"%s><span>%s</span></label>',
            $name === 'site_type' ? 'radio' : 'checkbox', esc_attr($name), esc_attr($val), in_array($val, $checked, true) ? ' checked' : '', esc_html($label));
    }
}

function cmsf_page_search() {
    if (!current_user_can('manage_options')) return;
    $platforms = array_combine(cmsf_platforms(), cmsf_platforms());
    ?>
    <div class="wrap cmsf" data-page="search">
        <h1>CMS Web Finder — пошук сайтів</h1>
        <div class="cmsf-card">
            <div class="cmsf-presets">
                <label><span>Шаблон пошуку (збережені параметри)</span><select id="cmsf-preset"><option value="">— обрати збережений шаблон —</option></select></label>
                <button type="button" class="button" id="cmsf-preset-save">Зберегти поточні параметри як шаблон</button>
                <button type="button" class="button button-link-delete" id="cmsf-preset-del">Видалити шаблон</button>
                <span id="cmsf-preset-msg" class="cmsf-msg"></span>
            </div>
            <form id="cmsf-search-form" onsubmit="return false">
                <div class="cmsf-grid">
                    <label class="cmsf-wide"><span>Назва списку</span><input type="text" name="name" placeholder="Напр.: Joomla · Україна (залиште порожнім — назва буде автоматична)"></label>

                    <fieldset class="cmsf-wide"><legend>CMS / платформа / плагін (можна кілька; без вибору = будь-яка визначена CMS)</legend>
                        <div class="cmsf-pills"><?php cmsf_pills('cms', $platforms); ?></div>
                    </fieldset>
                    <fieldset><legend>Країна</legend>
                        <div class="cmsf-pills"><?php cmsf_pills('regions', ['ua' => 'Україна', 'eu' => 'Європа', 'us' => 'США', 'world' => 'Увесь світ'], ['ua']); ?></div>
                    </fieldset>
                    <fieldset><legend>Мова сайту</legend>
                        <div class="cmsf-pills"><?php cmsf_pills('languages', ['uk' => 'Українська', 'ru' => 'Російська', 'en' => 'Англійська', 'any' => 'Будь-яка'], ['uk']); ?></div>
                    </fieldset>
                    <label><span>Тип сайту</span>
                        <select name="site_type"><option value="any">Будь-який</option><option value="shop">Лише інтернет-магазини</option><option value="catalog">Лише каталоги / оголошення</option></select>
                    </label>
                    <label><span>Ліміт доменів для перевірки</span>
                        <select name="limit"><option>100</option><option selected>500</option><option>1000</option><option>5000</option><option>20000</option></select>
                    </label>
                    <label><span>Категорії (через кому)</span><input type="text" name="categories" placeholder="одяг, меблі, автозапчастини"></label>
                    <label><span>Теги / ключові слова</span><input type="text" name="tags" placeholder="опт, виробник"></label>
                    <label class="cmsf-wide"><span>Додатковий запит (для пошуковиків)</span><input type="text" name="query" placeholder="необов'язково"></label>

                    <label class="cmsf-wide cmsf-check"><input type="checkbox" name="require_terms" value="1"> Відсіювати сайти, на головній сторінці яких немає жодної з вказаних категорій / тегів</label>

                    <fieldset class="cmsf-wide"><legend>Де шукати кандидатів</legend>
                        <div class="cmsf-pills">
                            <label class="cmsf-pill"><input type="checkbox" name="sources" value="zone" checked><span>Список доменів зони (рекомендовано)</span></label>
                            <label class="cmsf-pill"><input type="checkbox" name="sources" value="engines" checked><span>Пошуковики (Bing, DuckDuckGo, Mojeek)</span></label>
                        </div>
                        <p class="description"><b>Список доменів зони</b> — безкоштовний рейтинг популярних сайтів (Majestic): потрібні зони беруться звідти, а кожен домен перевіряється по коду. Категорії/теги тут не використовуються. <b>Пошуковики</b> шукають за категоріями/тегами, але з сервера їх часто блокують; заблокований пошуковик плагін сам пропускає. Найкраще залишати обидва.</p>
                        <p id="cmsf-zone-note" class="description"></p>
                    </fieldset>
                </div>
                <div class="cmsf-actions">
                    <button type="button" class="button button-primary button-hero" id="cmsf-start">Почати пошук</button>
                    <button type="button" class="button" id="cmsf-stop" disabled>Зупинити</button>
                </div>
            </form>

            <div id="cmsf-progress" hidden>
                <div class="cmsf-stage" id="cmsf-stage"></div>
                <div class="cmsf-bar"><div id="cmsf-bar-fill"></div></div>
                <div class="cmsf-counters" id="cmsf-counters"></div>
                <details><summary>Журнал</summary><pre id="cmsf-log"></pre></details>
            </div>
        </div>
        <div id="cmsf-current" hidden>
            <h2>Знайдені сайти</h2>
            <div class="cmsf-listview" id="cmsf-listview"></div>
        </div>
    </div>
    <?php
}

function cmsf_page_import() {
    if (!current_user_can('manage_options')) return;
    $platforms = array_combine(cmsf_platforms(), cmsf_platforms());
    ?>
    <div class="wrap cmsf" data-page="import">
        <h1>Свій список доменів</h1>
        <div class="cmsf-card">
            <p class="description">Вставте домени або завантажте файл TXT/CSV. Плагін відвідає кожен сайт і визначить CMS, версію, країну, мову та контакти. Результат зберігається як окремий список.</p>
            <form id="cmsf-search-form" onsubmit="return false">
                <div class="cmsf-grid">
                    <label class="cmsf-wide"><span>Назва списку</span><input type="text" name="name" placeholder="Напр.: Клієнти, База 2026 (порожньо — назва автоматична)"></label>
                    <label class="cmsf-wide"><span>Домени (по одному в рядок, через кому чи пробіл)</span>
                        <textarea name="domains" rows="8" placeholder="example.com.ua&#10;https://site2.com"></textarea>
                        <input type="file" id="cmsf-file" accept=".txt,.csv,text/plain">
                    </label>
                    <label class="cmsf-wide cmsf-check"><input type="checkbox" id="cmsf-use-filters"> Відсіяти за параметрами (інакше збережуться всі доступні сайти з визначеною CMS)</label>
                </div>
                <div id="cmsf-filters" hidden>
                    <div class="cmsf-grid">
                        <fieldset class="cmsf-wide"><legend>CMS / платформа / плагін (без вибору = будь-яка визначена CMS)</legend><div class="cmsf-pills"><?php cmsf_pills('cms', $platforms); ?></div></fieldset>
                        <fieldset><legend>Країна</legend><div class="cmsf-pills"><?php cmsf_pills('regions', ['ua' => 'Україна', 'eu' => 'Європа', 'us' => 'США', 'world' => 'Увесь світ'], ['world']); ?></div></fieldset>
                        <fieldset><legend>Мова сайту</legend><div class="cmsf-pills"><?php cmsf_pills('languages', ['uk' => 'Українська', 'ru' => 'Російська', 'en' => 'Англійська', 'any' => 'Будь-яка'], ['any']); ?></div></fieldset>
                        <label><span>Тип сайту</span><select name="site_type"><option value="any">Будь-який</option><option value="shop">Лише інтернет-магазини</option><option value="catalog">Лише каталоги / оголошення</option></select></label>
                    </div>
                </div>
                <div class="cmsf-actions">
                    <button type="button" class="button button-primary button-hero" id="cmsf-start">Перевірити домени</button>
                    <button type="button" class="button" id="cmsf-stop" disabled>Зупинити</button>
                </div>
            </form>
            <div id="cmsf-progress" hidden>
                <div class="cmsf-stage" id="cmsf-stage"></div>
                <div class="cmsf-bar"><div id="cmsf-bar-fill"></div></div>
                <div class="cmsf-counters" id="cmsf-counters"></div>
                <details><summary>Журнал</summary><pre id="cmsf-log"></pre></details>
            </div>
        </div>
        <div id="cmsf-current" hidden>
            <h2>Результат</h2>
            <div class="cmsf-listview" id="cmsf-listview"></div>
        </div>
    </div>
    <?php
}

function cmsf_page_lists() {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap cmsf" data-page="lists">
        <h1>Збережені списки</h1>
        <div id="cmsf-lists"></div>
        <div id="cmsf-open" hidden>
            <p><button class="button" id="cmsf-back">← До всіх списків</button></p>
            <h2 id="cmsf-open-title"></h2>
            <div class="cmsf-listview" id="cmsf-listview"></div>
        </div>
    </div>
    <?php
}

function cmsf_page_templates() {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap cmsf" data-page="templates">
        <h1>Шаблони листів</h1>
        <div class="cmsf-twocol">
            <div class="cmsf-card cmsf-tpl-list">
                <p><button class="button button-primary" id="cmsf-tpl-new">+ Новий шаблон</button></p>
                <div id="cmsf-tpl-groups"></div>
            </div>
            <div class="cmsf-card">
                <form id="cmsf-tpl-form" onsubmit="return false">
                    <input type="hidden" name="id" value="0">
                    <div class="cmsf-grid">
                        <label><span>Назва шаблону</span><input type="text" name="name" required></label>
                        <label><span>Тема (група)</span><input type="text" name="topic" list="cmsf-topics" placeholder="Joomla, WordPress, Плагіни…"><datalist id="cmsf-topics"></datalist></label>
                        <label class="cmsf-wide"><span>Тема листа (Subject)</span><input type="text" name="subject"></label>
                        <label class="cmsf-wide"><span>Текст листа</span><textarea name="body" rows="12"></textarea></label>
                        <label class="cmsf-wide cmsf-check"><input type="checkbox" name="is_html" value="1"> Надсилати як HTML (інакше — звичайний текст)</label>
                    </div>
                    <p class="description">Змінні: <code>{url}</code> <code>{domain}</code> <code>{site_title}</code> <code>{cms}</code> <code>{version}</code> <code>{latest_version}</code> <code>{email}</code></p>
                    <div class="cmsf-actions">
                        <button type="button" class="button button-primary" id="cmsf-tpl-save">Зберегти</button>
                        <button type="button" class="button" id="cmsf-tpl-saveas">Зберегти як новий</button>
                        <button type="button" class="button" id="cmsf-tpl-preview">Попередній перегляд</button>
                        <button type="button" class="button button-link-delete" id="cmsf-tpl-delete">Видалити</button>
                        <span id="cmsf-tpl-msg" class="cmsf-msg"></span>
                    </div>
                </form>
                <div id="cmsf-tpl-prev" class="cmsf-preview" hidden></div>
            </div>
        </div>
    </div>
    <?php
}

function cmsf_page_settings() {
    if (!current_user_can('manage_options')) return;
    $s = cmsf_settings();
    ?>
    <div class="wrap cmsf" data-page="settings">
        <h1>Налаштування</h1>
        <div class="cmsf-card">
            <form id="cmsf-settings-form" onsubmit="return false">
                <h2>Відправка листів</h2>
                <div class="cmsf-grid">
                    <label><span>Ім'я відправника</span><input type="text" name="from_name" value="<?php echo esc_attr($s['from_name']); ?>"></label>
                    <label><span>Email відправника</span><input type="email" name="from_email" value="<?php echo esc_attr($s['from_email']); ?>"></label>
                    <label><span>Reply-To (необов'язково)</span><input type="email" name="reply_to" value="<?php echo esc_attr($s['reply_to']); ?>"></label>
                    <label><span>Пауза між листами, сек.</span><input type="number" min="0" max="120" name="delay" value="<?php echo (int)$s['delay']; ?>"></label>
                    <label class="cmsf-wide"><span>Підпис / футер (додається до кожного листа)</span><textarea name="footer" rows="3"><?php echo esc_textarea($s['footer']); ?></textarea></label>
                </div>
                <h2>Перевірка сайтів</h2>
                <div class="cmsf-grid">
                    <label><span>Паралельних перевірок (1–8)</span><input type="number" min="1" max="8" name="concurrency" value="<?php echo (int)$s['concurrency']; ?>"></label>
                    <label><span>Роздільник у CSV</span><select name="delimiter"><option value=";"<?php selected($s['delimiter'], ';'); ?>>; (для українського Excel)</option><option value=","<?php selected($s['delimiter'], ','); ?>>, (кома)</option></select></label>
                </div>
                <h2>Остання версія CMS</h2>
                <p class="description">Визначається автоматично (WordPress API та GitHub). Якщо автоматика помиляється або недоступна — впишіть версію вручну, вона матиме пріоритет.</p>
                <div class="cmsf-grid">
                    <label><span>Joomla</span><input type="text" name="latest_joomla" value="<?php echo esc_attr($s['latest_joomla']); ?>" placeholder="авто"></label>
                    <label><span>WordPress</span><input type="text" name="latest_wordpress" value="<?php echo esc_attr($s['latest_wordpress']); ?>" placeholder="авто"></label>
                    <label><span>Drupal</span><input type="text" name="latest_drupal" value="<?php echo esc_attr($s['latest_drupal']); ?>" placeholder="авто"></label>
                </div>
                <p><button type="button" class="button" id="cmsf-refresh-latest">Визначити зараз автоматично</button> <span id="cmsf-latest-msg" class="cmsf-msg"></span></p>
                <div class="cmsf-actions">
                    <button type="button" class="button button-primary" id="cmsf-settings-save">Зберегти налаштування</button>
                    <span id="cmsf-settings-msg" class="cmsf-msg"></span>
                </div>
            </form>
            <hr>
            <h2>Тестовий лист</h2>
            <p class="description">Перевірте, що пошта сервера взагалі відправляє листи.</p>
            <div class="cmsf-actions">
                <input type="email" id="cmsf-test-to" value="<?php echo esc_attr(get_option('admin_email')); ?>">
                <button type="button" class="button" id="cmsf-test-send">Надіслати тестовий лист</button>
                <span id="cmsf-test-msg" class="cmsf-msg"></span>
            </div>
        </div>
    </div>
    <?php
}
