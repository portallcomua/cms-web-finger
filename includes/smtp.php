
<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CMS Web Finder — SMTP settings and mail transport
 * Version: 1.0
 */

function cmsf_smtp_defaults() {
    return [
        'enabled'    => 1,
        'host'       => 'mail.uaserver.pp.ua',
        'port'       => 465,
        'encryption' => 'ssl',
        'username'   => 'support@uaserver.pp.ua',
        'password'   => '',
    ];
}

function cmsf_smtp_settings() {
    $saved = get_option('cmsf_smtp_settings', []);
    if (!is_array($saved)) {
        $saved = [];
    }

    return wp_parse_args($saved, cmsf_smtp_defaults());
}

/**
 * Configure PHPMailer.
 */
add_action('phpmailer_init', function ($phpmailer) {
    $s = cmsf_smtp_settings();

    if (
        empty($s['enabled']) ||
        empty($s['host']) ||
        empty($s['username']) ||
        empty($s['password'])
    ) {
        return;
    }

    $phpmailer->isSMTP();
    $phpmailer->Host       = sanitize_text_field($s['host']);
    $phpmailer->SMTPAuth   = true;
    $phpmailer->Port       = (int) $s['port'];
    $phpmailer->Username   = sanitize_text_field($s['username']);
    $phpmailer->Password   = (string) $s['password'];
    $phpmailer->SMTPSecure = $s['encryption'] === 'none' ? '' : $s['encryption'];
    $phpmailer->SMTPAutoTLS = ($s['encryption'] === 'tls');
    $phpmailer->Timeout    = 25;
    $phpmailer->SMTPDebug  = 0;

    // Відправляємо саме з поштової скриньки Hyperhost.
    $phpmailer->setFrom(
        sanitize_email($s['username']),
        get_bloginfo('name'),
        false
    );

    $phpmailer->Sender = sanitize_email($s['username']);
}, 9999);

/**
 * Save SMTP settings.
 */
add_action('wp_ajax_cmsf_smtp_save', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Недостатньо прав.');
    }

    check_ajax_referer('cmsf', 'nonce');

    $old = cmsf_smtp_settings();

    $host = sanitize_text_field(wp_unslash($_POST['host'] ?? ''));
    $username = sanitize_email(wp_unslash($_POST['username'] ?? ''));
    $password = (string) wp_unslash($_POST['password'] ?? '');
    $port = absint($_POST['port'] ?? 465);
    $encryption = sanitize_text_field(wp_unslash($_POST['encryption'] ?? 'ssl'));

    if ($host === '' || !is_email($username)) {
        wp_send_json_error('Вкажіть SMTP-сервер і правильний email.');
    }

    if ($port < 1 || $port > 65535) {
        wp_send_json_error('Неправильний порт SMTP.');
    }

    if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
        wp_send_json_error('Неправильний тип захисту.');
    }

    // Порожнє поле пароля не стирає раніше збережений пароль.
    if ($password === '') {
        $password = $old['password'];
    }

    update_option('cmsf_smtp_settings', [
        'enabled'    => !empty($_POST['enabled']) ? 1 : 0,
        'host'       => $host,
        'port'       => $port,
        'encryption' => $encryption,
        'username'   => $username,
        'password'   => $password,
    ], false);

    wp_send_json_success('Налаштування SMTP збережено.');
});

/**
 * Send test email.
 */
add_action('wp_ajax_cmsf_smtp_test', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Недостатньо прав.');
    }

    check_ajax_referer('cmsf', 'nonce');

    $s = cmsf_smtp_settings();

    if (empty($s['enabled'])) {
        wp_send_json_error('SMTP вимкнено. Увімкніть SMTP і збережіть налаштування.');
    }

    if (empty($s['password'])) {
        wp_send_json_error('Введіть пароль поштової скриньки та збережіть налаштування.');
    }

    $to = sanitize_email(wp_unslash($_POST['to'] ?? ''));

    if (!is_email($to)) {
        wp_send_json_error('Вкажіть правильну адресу отримувача.');
    }

    $GLOBALS['cmsf_smtp_test_error'] = '';

    $error_callback = function ($error) {
        if (is_wp_error($error)) {
            $GLOBALS['cmsf_smtp_test_error'] = $error->get_error_message();
        }
    };

    add_action('wp_mail_failed', $error_callback);

    $subject = 'CMS Web Finder — тест SMTP';
    $body = "Це тестовий лист CMS Web Finder.\n\n";
    $body .= "Якщо ви отримали цей лист, SMTP передав його поштовому серверу.\n\n";
    $body .= home_url();

    $headers = [
        'Content-Type: text/plain; charset=UTF-8',
    ];

    $ok = wp_mail($to, $subject, $body, $headers);

    remove_action('wp_mail_failed', $error_callback);

    if ($ok) {
        wp_send_json_success(
            'Лист передано SMTP-серверу для ' . $to .
            '. Перевірте вхідні листи та папку «Спам».'
        );
    }

    $error = $GLOBALS['cmsf_smtp_test_error'];

    if ($error === '') {
        $error = 'WordPress не зміг відправити лист. Перевірте SMTP-сервер, порт, логін і пароль.';
    }

    wp_send_json_error($error);
});

/**
 * Add SMTP settings directly to CMS Web Finder settings page.
 */
add_action('admin_footer', function () {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (($_GET['page'] ?? '') !== 'cmsf-settings') {
        return;
    }

    $s = cmsf_smtp_settings();
    $nonce = wp_create_nonce('cmsf');
    ?>
    <style>
        #cmsf-smtp-box {
            margin-top: 20px;
            padding: 20px;
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 6px;
        }
        #cmsf-smtp-box h2 {
            margin-top: 0;
        }
        #cmsf-smtp-box .cmsf-smtp-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 15px;
        }
        #cmsf-smtp-box label {
            display: block;
            font-weight: 600;
        }
        #cmsf-smtp-box input,
        #cmsf-smtp-box select {
            display: block;
            width: 100%;
            max-width: 100%;
            margin-top: 6px;
        }
        #cmsf-smtp-box .cmsf-smtp-actions {
            margin-top: 18px;
        }
        #cmsf-smtp-message {
            display: inline-block;
            margin-left: 10px;
            font-weight: 600;
        }
        @media(max-width: 700px) {
            #cmsf-smtp-box .cmsf-smtp-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const card = document.querySelector(
            '.wrap.cmsf[data-page="settings"] .cmsf-card'
        );

        if (!card || document.getElementById('cmsf-smtp-box')) {
            return;
        }

        const box = document.createElement('div');
        box.id = 'cmsf-smtp-box';

        box.innerHTML = `
            <h2>Налаштування SMTP — відправлення через Hyperhost</h2>

            <p>
                Листи будуть відправлятися через вашу поштову скриньку
                support@uaserver.pp.ua.
                Переадресація на Gmail може залишатися увімкненою.
            </p>

            <p>
                <label>
                    <input type="checkbox" id="cmsf-smtp-enabled"
                        <?php checked($s['enabled'], 1); ?>
                        style="width:auto;display:inline-block">
                    Увімкнути SMTP
                </label>
            </p>

            <div class="cmsf-smtp-grid">
                <label>
                    SMTP-сервер
                    <input type="text" id="cmsf-smtp-host"
                        value="<?php echo esc_attr($s['host']); ?>">
                </label>

                <label>
                    Порт
                    <input type="number" id="cmsf-smtp-port"
                        value="<?php echo esc_attr($s['port']); ?>">
                </label>

                <label>
                    Тип захисту
                    <select id="cmsf-smtp-encryption">
                        <option value="ssl" <?php selected($s['encryption'], 'ssl'); ?>>SSL</option>
                        <option value="tls" <?php selected($s['encryption'], 'tls'); ?>>TLS</option>
                        <option value="none" <?php selected($s['encryption'], 'none'); ?>>Без шифрування</option>
                    </select>
                </label>

                <label>
                    Логін поштової скриньки
                    <input type="email" id="cmsf-smtp-username"
                        value="<?php echo esc_attr($s['username']); ?>">
                </label>

                <label>
                    Пароль поштової скриньки
                    <input type="password" id="cmsf-smtp-password"
                        value=""
                        autocomplete="new-password"
                        placeholder="<?php echo empty($s['password']) ? 'Введіть пароль' : 'Пароль уже збережено — залиште порожнім'; ?>">
                </label>
            </div>

            <div class="cmsf-smtp-actions">
                <button type="button" class="button button-primary"
                    id="cmsf-smtp-save">Зберегти SMTP</button>
            </div>

            <hr>

            <h3>Тестове відправлення через SMTP</h3>

            <p>Вкажіть адресу, на яку надіслати перевірочний лист.</p>

            <input type="email" id="cmsf-smtp-to"
                value="<?php echo esc_attr(get_option('admin_email')); ?>"
                style="max-width:350px">

            <button type="button" class="button"
                id="cmsf-smtp-test">Надіслати тестовий лист</button>

            <span id="cmsf-smtp-message"></span>
        `;

        card.appendChild(box);

        const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        const nonce = <?php echo wp_json_encode($nonce); ?>;
        const message = document.getElementById('cmsf-smtp-message');

        async function sendRequest(action, data) {
            const body = new URLSearchParams();
            body.append('action', action);
            body.append('nonce', nonce);

            Object.keys(data).forEach(function (key) {
                body.append(key, data[key]);
            });

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: body.toString()
            });

            return await response.json();
        }

        document.getElementById('cmsf-smtp-save').addEventListener('click', async function () {
            message.textContent = 'Збереження...';
            message.style.color = '#555';

            try {
                const result = await sendRequest('cmsf_smtp_save', {
                    enabled: document.getElementById('cmsf-smtp-enabled').checked ? '1' : '0',
                    host: document.getElementById('cmsf-smtp-host').value,
                    port: document.getElementById('cmsf-smtp-port').value,
                    encryption: document.getElementById('cmsf-smtp-encryption').value,
                    username: document.getElementById('cmsf-smtp-username').value,
                    password: document.getElementById('cmsf-smtp-password').value
                });

                if (result.success) {
                    document.getElementById('cmsf-smtp-password').value = '';
                    message.style.color = 'green';
                    message.textContent = result.data;
                } else {
                    message.style.color = 'red';
                    message.textContent = result.data || 'Помилка збереження.';
                }
            } catch (e) {
                message.style.color = 'red';
                message.textContent = 'Помилка з’єднання з WordPress.';
            }
        });

        document.getElementById('cmsf-smtp-test').addEventListener('click', async function () {
            message.textContent = 'Відправлення тестового листа...';
            message.style.color = '#555';

            try {
                const result = await sendRequest('cmsf_smtp_test', {
                    to: document.getElementById('cmsf-smtp-to').value
                });

                if (result.success) {
                    message.style.color = 'green';
                    message.textContent = result.data;
                } else {
                    message.style.color = 'red';
                    message.textContent = result.data || 'Помилка відправлення.';
                }
            } catch (e) {
                message.style.color = 'red';
                message.textContent = 'Помилка з’єднання з WordPress.';
            }
        });
    });
    </script>
    <?php
});
