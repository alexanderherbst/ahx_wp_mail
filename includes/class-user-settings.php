<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Verwaltet die benutzerspezifischen IMAP-Zugangsdaten im WordPress-Profil.
 */
class AHX_WP_Mail_User_Settings {

    const META_ACCOUNTS        = 'ahx_wp_mail_accounts'; // JSON-Array von Konten
    const META_ACTIVE_ACCOUNT  = 'ahx_wp_mail_active_account'; // aktiver Kontoschlüssel

    const META_IMAP_USER       = 'ahx_wp_mail_imap_user';
    const META_IMAP_PASSWORD   = 'ahx_wp_mail_imap_password'; // AES-256 verschlüsselt
    const META_IMAP_HOST       = 'ahx_wp_mail_imap_host';
    const META_IMAP_PORT       = 'ahx_wp_mail_imap_port';
    const META_IMAP_ENCRYPTION = 'ahx_wp_mail_imap_encryption';
    const META_EMAILS_PER_PAGE = 'ahx_wp_mail_emails_per_page';
    const META_DETAIL_ACTION_AFTER = 'ahx_wp_mail_detail_action_after';
    const META_ALLOWED_SENDERS = 'ahx_wp_mail_allowed_senders'; // JSON-Array von E-Mail-Adressen
    const META_ALLOWED_DOMAINS = 'ahx_wp_mail_allowed_domains'; // JSON-Array von Domains

    // -----------------------------------------------------------------------
    // Lesen / Schreiben
    // -----------------------------------------------------------------------

    /**
     * Gibt alle IMAP-Einstellungen eines Benutzers zurück.
     * Felder ohne Benutzerwert bleiben leer (Fallback auf globale Option im Aufrufer).
     *
     * @param int $user_id
     * @return array
     */
    public static function get($user_id) {
        $user_id = (int) $user_id;
        self::migrate_legacy_if_needed($user_id);

        $accounts = self::get_accounts($user_id);
        $active   = self::get_active_account_key($user_id, $accounts);

        $current = array(
            'imap_user'       => '',
            'imap_password'   => '',
            'imap_host'       => '',
            'imap_port'       => '',
            'imap_encryption' => '',
            'emails_per_page' => '',
            'trash_folder'    => '',
        );
        if ($active !== '' && isset($accounts[$active])) {
            $acc = $accounts[$active];
            $current = array(
                'imap_user'       => (string) ($acc['imap_user'] ?? ''),
                'imap_password'   => !empty($acc['imap_password']) ? self::decrypt($acc['imap_password']) : '',
                'imap_host'       => (string) ($acc['imap_host'] ?? ''),
                'imap_port'       => (string) ($acc['imap_port'] ?? ''),
                'imap_encryption' => (string) ($acc['imap_encryption'] ?? ''),
                'emails_per_page' => (string) ($acc['emails_per_page'] ?? ''),
                'trash_folder'    => (string) ($acc['trash_folder'] ?? ''),
            );
        }

        $list = array();
        foreach ($accounts as $key => $acc) {
            $list[] = array(
                'key'          => (string) $key,
                'label'        => (string) ($acc['label'] ?? $key),
                'imap_user'    => (string) ($acc['imap_user'] ?? ''),
                'trash_folder' => (string) ($acc['trash_folder'] ?? ''),
            );
        }

        return array_merge($current, array(
            'accounts'           => $list,
            'active_account_key' => $active,
        ));
    }

    /**
     * Gibt ein bestimmtes Konto zurück; ohne Key das aktive Konto.
     */
    public static function get_account($user_id, $account_key = '') {
        $user_id = (int) $user_id;
        self::migrate_legacy_if_needed($user_id);

        $accounts = self::get_accounts($user_id);
        $key = $account_key !== '' ? sanitize_key($account_key) : self::get_active_account_key($user_id, $accounts);

        if ($key === '' || !isset($accounts[$key])) {
            return array(
                'account_key'     => '',
                'label'           => '',
                'imap_user'       => '',
                'imap_password'   => '',
                'imap_host'       => '',
                'imap_port'       => '',
                'imap_encryption' => '',
                'emails_per_page' => '',
                'trash_folder'    => '',
            );
        }

        $acc = $accounts[$key];
        return array(
            'account_key'     => $key,
            'label'           => (string) ($acc['label'] ?? $key),
            'imap_user'       => (string) ($acc['imap_user'] ?? ''),
            'imap_password'   => !empty($acc['imap_password']) ? self::decrypt($acc['imap_password']) : '',
            'imap_host'       => (string) ($acc['imap_host'] ?? ''),
            'imap_port'       => (string) ($acc['imap_port'] ?? ''),
            'imap_encryption' => (string) ($acc['imap_encryption'] ?? ''),
            'emails_per_page' => (string) ($acc['emails_per_page'] ?? ''),
            'trash_folder'    => (string) ($acc['trash_folder'] ?? ''),
        );
    }

    /**
     * Setzt das aktive Konto für den Benutzer.
     */
    public static function set_active_account($user_id, $account_key) {
        $user_id = (int) $user_id;
        self::migrate_legacy_if_needed($user_id);

        $accounts = self::get_accounts($user_id);
        $key = sanitize_key($account_key);
        if ($key !== '' && isset($accounts[$key])) {
            update_user_meta($user_id, self::META_ACTIVE_ACCOUNT, $key);
            return true;
        }
        return false;
    }

    /**
     * Speichert alle IMAP-Einstellungen eines Benutzers.
     *
     * @param int    $user_id
     * @param array  $data  Keys: imap_user, imap_password, imap_host, imap_port, imap_encryption, emails_per_page
     */
    public static function save($user_id, $data) {
        $user_id = (int) $user_id;
        self::migrate_legacy_if_needed($user_id);

        $accounts = self::get_accounts($user_id);
        $key = isset($data['account_key']) ? sanitize_key($data['account_key']) : '';

        if ($key === '') {
            $key = sanitize_key(isset($data['label']) && $data['label'] !== '' ? $data['label'] : (isset($data['imap_user']) ? $data['imap_user'] : 'account'));
        }
        if ($key === '') {
            $key = 'account_' . substr(md5((string) microtime(true)), 0, 8);
        }

        if (!isset($accounts[$key])) {
            $accounts[$key] = array(
                'label'           => isset($data['label']) && $data['label'] !== '' ? sanitize_text_field($data['label']) : $key,
                'imap_user'       => '',
                'imap_password'   => '',
                'imap_host'       => '',
                'imap_port'       => '',
                'imap_encryption' => '',
                'emails_per_page' => '',
                'trash_folder'    => '',
            );
        }

        if (array_key_exists('label', $data)) {
            $accounts[$key]['label'] = sanitize_text_field($data['label']);
        }
        if (array_key_exists('imap_user', $data)) {
            $accounts[$key]['imap_user'] = sanitize_email($data['imap_user']);
        }
        if (!empty($data['imap_password'])) {
            $accounts[$key]['imap_password'] = self::encrypt($data['imap_password']);
        }
        if (array_key_exists('imap_host', $data)) {
            $accounts[$key]['imap_host'] = sanitize_text_field($data['imap_host']);
        }
        if (array_key_exists('imap_port', $data)) {
            $port = (int) $data['imap_port'];
            $accounts[$key]['imap_port'] = $port > 0 ? (string) $port : '';
        }
        if (array_key_exists('imap_encryption', $data)) {
            $enc = in_array($data['imap_encryption'], array('ssl', 'tls', 'none'), true)
                ? $data['imap_encryption'] : '';
            $accounts[$key]['imap_encryption'] = $enc;
        }
        if (array_key_exists('emails_per_page', $data)) {
            $per = (int) $data['emails_per_page'];
            $accounts[$key]['emails_per_page'] = $per > 0 ? (string) $per : '';
        }
        if (array_key_exists('trash_folder', $data)) {
            $accounts[$key]['trash_folder'] = sanitize_text_field($data['trash_folder']);
        }

        update_user_meta($user_id, self::META_ACCOUNTS, wp_json_encode($accounts));
        update_user_meta($user_id, self::META_ACTIVE_ACCOUNT, $key);
    }

    /**
     * Löscht ein einzelnes Konto.
     */
    public static function delete_account($user_id, $account_key) {
        $user_id = (int) $user_id;
        self::migrate_legacy_if_needed($user_id);

        $accounts = self::get_accounts($user_id);
        $key = sanitize_key($account_key);
        if ($key !== '' && isset($accounts[$key])) {
            unset($accounts[$key]);
            update_user_meta($user_id, self::META_ACCOUNTS, wp_json_encode($accounts));

            $active = get_user_meta($user_id, self::META_ACTIVE_ACCOUNT, true);
            if ($active === $key) {
                $keys = array_keys($accounts);
                update_user_meta($user_id, self::META_ACTIVE_ACCOUNT, !empty($keys) ? $keys[0] : '');
            }
        }
    }

    /**
     * Dupliziert ein bestehendes Konto.
     */
    public static function duplicate_account($user_id, $account_key) {
        $user_id = (int) $user_id;
        self::migrate_legacy_if_needed($user_id);

        $accounts = self::get_accounts($user_id);
        $key = sanitize_key($account_key);
        if ($key === '' || !isset($accounts[$key])) {
            return false;
        }

        $source = $accounts[$key];
        $base = sanitize_key($key . '_copy');
        if ($base === '') {
            $base = 'account_copy';
        }

        $new_key = $base;
        $i = 2;
        while (isset($accounts[$new_key])) {
            $new_key = $base . '_' . $i;
            $i++;
        }

        $source['label'] = (string) ($source['label'] ?? $key) . ' (Kopie)';
        $accounts[$new_key] = $source;

        update_user_meta($user_id, self::META_ACCOUNTS, wp_json_encode($accounts));
        update_user_meta($user_id, self::META_ACTIVE_ACCOUNT, $new_key);
        return true;
    }

    /**
     * Löscht die IMAP-Zugangsdaten eines Benutzers.
     *
     * @param int $user_id
     */
    public static function delete($user_id) {
        $user_id = (int) $user_id;
        delete_user_meta($user_id, self::META_ACCOUNTS);
        delete_user_meta($user_id, self::META_ACTIVE_ACCOUNT);
        delete_user_meta($user_id, self::META_IMAP_USER);
        delete_user_meta($user_id, self::META_IMAP_PASSWORD);
        delete_user_meta($user_id, self::META_IMAP_HOST);
        delete_user_meta($user_id, self::META_IMAP_PORT);
        delete_user_meta($user_id, self::META_IMAP_ENCRYPTION);
        delete_user_meta($user_id, self::META_EMAILS_PER_PAGE);
        delete_user_meta($user_id, self::META_DETAIL_ACTION_AFTER);
        delete_user_meta($user_id, self::META_ALLOWED_SENDERS);
        delete_user_meta($user_id, self::META_ALLOWED_DOMAINS);
    }

    // -----------------------------------------------------------------------
    // Bild-Freigabeliste
    // -----------------------------------------------------------------------

    /**
     * Prüft, ob externe Bilder für einen Absender oder dessen Domain freigegeben sind.
     *
     * @param int    $user_id
     * @param string $sender_email  z.B. "noreply@example.com"
     * @return bool
     */
    public static function is_sender_allowed($user_id, $sender_email) {
        $email  = strtolower(trim($sender_email));
        $domain = ltrim(strrchr($email, '@'), '@');

        $senders = self::get_allowlist($user_id, self::META_ALLOWED_SENDERS);
        if (in_array($email, $senders, true)) {
            return true;
        }

        if ($domain !== '') {
            $domains = self::get_allowlist($user_id, self::META_ALLOWED_DOMAINS);
            if (in_array($domain, $domains, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fügt eine E-Mail-Adresse oder Domain zur Freigabeliste hinzu.
     *
     * @param int    $user_id
     * @param string $type   'sender' oder 'domain'
     * @param string $value  E-Mail-Adresse oder Domain
     * @return bool
     */
    public static function add_to_allowlist($user_id, $type, $value) {
        $user_id  = (int) $user_id;
        $value    = strtolower(trim($value));
        $meta_key = ($type === 'domain') ? self::META_ALLOWED_DOMAINS : self::META_ALLOWED_SENDERS;

        if ($value === '') {
            return false;
        }

        $list = self::get_allowlist($user_id, $meta_key);
        if (!in_array($value, $list, true)) {
            $list[] = $value;
            update_user_meta($user_id, $meta_key, wp_json_encode($list));
        }

        return true;
    }

    /**
     * Entfernt einen Eintrag aus der Freigabeliste.
     *
     * @param int    $user_id
     * @param string $type   'sender' oder 'domain'
     * @param string $value
     */
    public static function remove_from_allowlist($user_id, $type, $value) {
        $user_id  = (int) $user_id;
        $value    = strtolower(trim($value));
        $meta_key = ($type === 'domain') ? self::META_ALLOWED_DOMAINS : self::META_ALLOWED_SENDERS;

        $list = self::get_allowlist($user_id, $meta_key);
        $list = array_values(array_filter($list, function ($v) use ($value) {
            return $v !== $value;
        }));
        update_user_meta($user_id, $meta_key, wp_json_encode($list));
    }

    /**
     * Liest eine gespeicherte JSON-Liste aus user_meta.
     *
     * @param int    $user_id
     * @param string $meta_key
     * @return string[]
     */
    private static function get_allowlist($user_id, $meta_key) {
        $raw  = get_user_meta((int) $user_id, $meta_key, true);
        if (empty($raw)) {
            return array();
        }
        $list = json_decode($raw, true);
        return is_array($list) ? $list : array();
    }

    /**
     * Liest alle gespeicherten Konten.
     */
    private static function get_accounts($user_id) {
        $raw = get_user_meta((int) $user_id, self::META_ACCOUNTS, true);
        $accounts = json_decode((string) $raw, true);
        return is_array($accounts) ? $accounts : array();
    }

    /**
     * Ermittelt aktiven Schlüssel, mit Fallback auf erstes Konto.
     */
    private static function get_active_account_key($user_id, $accounts = null) {
        if (!is_array($accounts)) {
            $accounts = self::get_accounts($user_id);
        }
        $active = sanitize_key((string) get_user_meta((int) $user_id, self::META_ACTIVE_ACCOUNT, true));
        if ($active !== '' && isset($accounts[$active])) {
            return $active;
        }
        $keys = array_keys($accounts);
        return !empty($keys) ? (string) $keys[0] : '';
    }

    /**
     * Migriert altes Einzelkonto in neues Mehrkontenformat.
     */
    private static function migrate_legacy_if_needed($user_id) {
        $user_id = (int) $user_id;
        $existing = self::get_accounts($user_id);
        if (!empty($existing)) {
            return;
        }

        $imap_user = (string) get_user_meta($user_id, self::META_IMAP_USER, true);
        $imap_pass = (string) get_user_meta($user_id, self::META_IMAP_PASSWORD, true);
        $imap_host = (string) get_user_meta($user_id, self::META_IMAP_HOST, true);
        $imap_port = (string) get_user_meta($user_id, self::META_IMAP_PORT, true);
        $imap_enc  = (string) get_user_meta($user_id, self::META_IMAP_ENCRYPTION, true);
        $per_page  = (string) get_user_meta($user_id, self::META_EMAILS_PER_PAGE, true);

        if ($imap_user === '' && $imap_pass === '' && $imap_host === '' && $imap_port === '' && $imap_enc === '' && $per_page === '') {
            return;
        }

        $key = sanitize_key($imap_user !== '' ? $imap_user : 'konto_1');
        if ($key === '') {
            $key = 'konto_1';
        }

        $accounts = array(
            $key => array(
                'label'           => $imap_user !== '' ? $imap_user : 'Konto 1',
                'imap_user'       => $imap_user,
                'imap_password'   => $imap_pass,
                'imap_host'       => $imap_host,
                'imap_port'       => $imap_port,
                'imap_encryption' => $imap_enc,
                'emails_per_page' => $per_page,
            ),
        );
        update_user_meta($user_id, self::META_ACCOUNTS, wp_json_encode($accounts));
        update_user_meta($user_id, self::META_ACTIVE_ACCOUNT, $key);
    }

    // -----------------------------------------------------------------------
    // Verschlüsselung (AES-256-CBC via OpenSSL)
    // -----------------------------------------------------------------------

    /**
     * Verschlüsselt einen Klartext-String mit dem WordPress-Auth-Key als Schlüsselbasis.
     */
    private static function encrypt($plaintext) {
        if (!function_exists('openssl_encrypt')) {
            // Fallback: Base64 (kein echter Schutz, aber besser als Klartext in DB)
            return base64_encode($plaintext);
        }

        $key    = substr(hash('sha256', self::get_secret_key(), true), 0, 32);
        $iv_len = openssl_cipher_iv_length('AES-256-CBC');
        $iv     = openssl_random_pseudo_bytes($iv_len);
        $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        return base64_encode($iv . $cipher);
    }

    /**
     * Entschlüsselt einen zuvor mit encrypt() gespeicherten String.
     */
    private static function decrypt($encoded) {
        if (!function_exists('openssl_decrypt')) {
            return base64_decode($encoded);
        }

        $data   = base64_decode($encoded);
        $key    = substr(hash('sha256', self::get_secret_key(), true), 0, 32);
        $iv_len = openssl_cipher_iv_length('AES-256-CBC');
        $iv     = substr($data, 0, $iv_len);
        $cipher = substr($data, $iv_len);

        $result = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        return $result !== false ? $result : '';
    }

    /**
     * Liefert den geheimen Schlüssel aus den WordPress-Konstanten.
     */
    private static function get_secret_key() {
        if (defined('LOGGED_IN_KEY') && LOGGED_IN_KEY !== '') {
            return LOGGED_IN_KEY;
        }
        if (defined('AUTH_KEY') && AUTH_KEY !== '') {
            return AUTH_KEY;
        }
        // Letzter Fallback – sollte nie eintreten bei korrekter wp-config.php
        return wp_salt('logged_in');
    }

    // -----------------------------------------------------------------------
    // Profil-Felder (WordPress-Benutzer-Profil)
    // -----------------------------------------------------------------------

    /**
     * Zeigt die IMAP-Felder im Benutzerprofil an.
     *
     * @param WP_User $user
     */
    public static function render_profile_fields($user) {
        if (!current_user_can('edit_user', $user->ID)) {
            return;
        }

        self::migrate_legacy_if_needed($user->ID);
        $raw_accounts = self::get_accounts($user->ID);
        $active_key   = self::get_active_account_key($user->ID, $raw_accounts);
        $active       = self::get_account($user->ID, $active_key);

        $accounts = array();
        $accounts_js = array();
        foreach ($raw_accounts as $key => $acc) {
            $label = (string) ($acc['label'] ?? $key);
            $has_password = !empty($acc['imap_password']);
            $accounts[] = array('key' => (string) $key, 'label' => $label);
            $accounts_js[(string) $key] = array(
                'label'           => $label,
                'imap_user'       => (string) ($acc['imap_user'] ?? ''),
                'imap_host'       => (string) ($acc['imap_host'] ?? ''),
                'imap_port'       => (string) ($acc['imap_port'] ?? ''),
                'imap_encryption' => (string) ($acc['imap_encryption'] ?? ''),
                'emails_per_page' => (string) ($acc['emails_per_page'] ?? ''),
                'trash_folder'    => (string) ($acc['trash_folder'] ?? ''),
                'has_password'    => (bool) $has_password,
            );
        }

        $imap_user  = esc_attr($active['imap_user']);
        $has_pass   = $active['imap_password'] !== '';
        $has_any    = $has_pass || $imap_user !== '' || $active['imap_host'] !== '';

        // Globale Standardwerte als Platzhalter
        $global_host     = get_option('ahx_wp_mail_imap_host', '');
        $global_port     = get_option('ahx_wp_mail_imap_port', '993');
        $global_enc      = get_option('ahx_wp_mail_imap_encryption', 'ssl');
        $global_per_page = get_option('ahx_wp_mail_emails_per_page', '20');

        $user_host     = esc_attr($active['imap_host']);
        $user_port     = esc_attr($active['imap_port']);
        $user_enc      = $active['imap_encryption'];
        $user_per_page = esc_attr($active['emails_per_page']);
        $user_trash    = esc_attr($active['trash_folder']);
        $detail_action_after = sanitize_key((string) get_user_meta((int) $user->ID, self::META_DETAIL_ACTION_AFTER, true));
        if (!in_array($detail_action_after, array('list', 'next'), true)) {
            $detail_action_after = 'list';
        }
        ?>
        <h2><?php esc_html_e('E-Mail-Konto (IMAP)', 'ahx_wp_mail'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="ahx_mail_account_select"><?php esc_html_e('Konto', 'ahx_wp_mail'); ?></label></th>
                <td>
                    <select name="ahx_mail_account_select" id="ahx_mail_account_select">
                        <?php foreach ($accounts as $acc): ?>
                            <option value="<?php echo esc_attr($acc['key']); ?>" <?php selected($active_key, $acc['key']); ?>>
                                <?php echo esc_html($acc['label']); ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="__new__"><?php esc_html_e('+ Neues Konto anlegen', 'ahx_wp_mail'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('Aktives Konto auswählen. Änderungen unten werden für dieses Konto gespeichert.', 'ahx_wp_mail'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="ahx_mail_account_label"><?php esc_html_e('Kontoname', 'ahx_wp_mail'); ?></label></th>
                <td>
                    <input type="text"
                           name="ahx_mail_account_label"
                           id="ahx_mail_account_label"
                           value="<?php
                               $active_label = '';
                               foreach ($accounts as $acc) {
                                   if ($acc['key'] === $active_key) {
                                       $active_label = $acc['label'];
                                       break;
                                   }
                               }
                               echo esc_attr($active_label);
                           ?>"
                           class="regular-text"
                           placeholder="<?php esc_attr_e('z.B. Privat, Arbeit', 'ahx_wp_mail'); ?>" />
                    <p class="description"><?php esc_html_e('Freier Name für dieses Konto.', 'ahx_wp_mail'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="ahx_mail_imap_user"><?php esc_html_e('IMAP-Benutzername / E-Mail-Adresse', 'ahx_wp_mail'); ?></label></th>
                <td>
                    <input type="email"
                           name="ahx_mail_imap_user"
                           id="ahx_mail_imap_user"
                           value="<?php echo $imap_user; ?>"
                           class="regular-text"
                           autocomplete="off" />
                    <p class="description"><?php esc_html_e('Die E-Mail-Adresse, mit der du dich beim IMAP-Server anmeldest.', 'ahx_wp_mail'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="ahx_mail_imap_password"><?php esc_html_e('IMAP-Passwort', 'ahx_wp_mail'); ?></label></th>
                <td>
                    <input type="password"
                           name="ahx_mail_imap_password"
                           id="ahx_mail_imap_password"
                           value=""
                           class="regular-text"
                           autocomplete="new-password"
                           placeholder="<?php echo $has_pass ? esc_attr__('(gespeichert – zum Ändern neu eingeben)', 'ahx_wp_mail') : ''; ?>" />
                    <p class="description"><?php esc_html_e('Leer lassen, um das gespeicherte Passwort beizubehalten.', 'ahx_wp_mail'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="ahx_mail_imap_host"><?php esc_html_e('IMAP-Server (Host)', 'ahx_wp_mail'); ?></label></th>
                <td>
                    <input type="text"
                           name="ahx_mail_imap_host"
                           id="ahx_mail_imap_host"
                           value="<?php echo $user_host; ?>"
                           class="regular-text"
                           placeholder="<?php echo esc_attr($global_host ?: 'imap.example.com'); ?>" />
                    <p class="description"><?php esc_html_e('Leer lassen, um den globalen Standard zu verwenden.', 'ahx_wp_mail'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="ahx_mail_imap_port"><?php esc_html_e('IMAP-Port', 'ahx_wp_mail'); ?></label></th>
                <td>
                    <input type="number"
                           name="ahx_mail_imap_port"
                           id="ahx_mail_imap_port"
                           value="<?php echo $user_port; ?>"
                           class="small-text"
                           min="1" max="65535"
                           placeholder="<?php echo esc_attr($global_port); ?>" />
                    <p class="description"><?php esc_html_e('Leer lassen, um den globalen Standard zu verwenden.', 'ahx_wp_mail'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="ahx_mail_imap_encryption"><?php esc_html_e('Verschlüsselung', 'ahx_wp_mail'); ?></label></th>
                <td>
                    <select name="ahx_mail_imap_encryption" id="ahx_mail_imap_encryption">
                        <option value=""><?php printf(esc_html__('— Globaler Standard (%s) —', 'ahx_wp_mail'), esc_html($global_enc)); ?></option>
                        <option value="ssl"  <?php selected($user_enc, 'ssl'); ?>>SSL/TLS (Port 993)</option>
                        <option value="tls"  <?php selected($user_enc, 'tls'); ?>>STARTTLS (Port 143)</option>
                        <option value="none" <?php selected($user_enc, 'none'); ?>><?php esc_html_e('Keine (nicht empfohlen)', 'ahx_wp_mail'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="ahx_mail_emails_per_page"><?php esc_html_e('E-Mails pro Seite', 'ahx_wp_mail'); ?></label></th>
                <td>
                    <input type="number"
                           name="ahx_mail_emails_per_page"
                           id="ahx_mail_emails_per_page"
                           value="<?php echo $user_per_page; ?>"
                           class="small-text"
                           min="1" max="200"
                           placeholder="<?php echo esc_attr($global_per_page); ?>" />
                    <p class="description"><?php esc_html_e('Leer lassen, um den globalen Standard zu verwenden.', 'ahx_wp_mail'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="ahx_mail_detail_action_after"><?php esc_html_e('Nach Aktion in Detailansicht', 'ahx_wp_mail'); ?></label></th>
                <td>
                    <select name="ahx_mail_detail_action_after" id="ahx_mail_detail_action_after" class="regular-text">
                        <option value="list" <?php selected($detail_action_after, 'list'); ?>><?php esc_html_e('Zurück zur Liste', 'ahx_wp_mail'); ?></option>
                        <option value="next" <?php selected($detail_action_after, 'next'); ?>><?php esc_html_e('Zur nächsten Nachricht springen', 'ahx_wp_mail'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('Gilt für Archivieren, Löschen und Verschieben in der Detailansicht.', 'ahx_wp_mail'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="ahx_mail_trash_folder"><?php esc_html_e('Papierkorb-Ordner', 'ahx_wp_mail'); ?></label></th>
                <td>
                    <select name="ahx_mail_trash_folder" id="ahx_mail_trash_folder" class="regular-text">
                        <option value=""><?php esc_html_e('— Sofort löschen —', 'ahx_wp_mail'); ?></option>
                        <?php if ($user_trash !== '') : ?>
                            <option value="<?php echo esc_attr($user_trash); ?>" selected><?php echo esc_html($user_trash); ?></option>
                        <?php endif; ?>
                    </select>
                    <button type="button" id="ahx_mail_load_folders" class="button"><?php esc_html_e('Ordner laden', 'ahx_wp_mail'); ?></button>
                    <p class="description"><?php esc_html_e('Wird für das aktuell ausgewählte Konto gespeichert.', 'ahx_wp_mail'); ?></p>
                    <p class="description" id="ahx_mail_folder_status" style="display:none;"></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Verbindung testen', 'ahx_wp_mail'); ?></th>
                <td>
                    <button type="button" id="ahx_mail_test_connection" class="button">
                        <?php esc_html_e('Verbindung testen', 'ahx_wp_mail'); ?>
                    </button>
                    <span id="ahx_mail_test_status" style="margin-left: 10px;"></span>
                    <div id="ahx_mail_test_message" style="margin-top: 10px; display: none;"></div>
                </td>
            </tr>
            <?php if ($has_any): ?>
            <tr>
                <th><?php esc_html_e('Einstellungen entfernen', 'ahx_wp_mail'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="ahx_mail_imap_duplicate" value="1" />
                        <?php esc_html_e('Aktives Konto duplizieren', 'ahx_wp_mail'); ?>
                    </label>
                    <br>
                    <label>
                        <input type="checkbox" name="ahx_mail_imap_delete" value="1" />
                        <?php esc_html_e('Aktives Konto löschen', 'ahx_wp_mail'); ?>
                    </label>
                    <br>
                    <label>
                        <input type="checkbox" name="ahx_mail_imap_delete_all" value="1" />
                        <?php esc_html_e('Alle Konten und benutzerspezifischen IMAP-Einstellungen löschen', 'ahx_wp_mail'); ?>
                    </label>
                </td>
            </tr>
            <?php endif; ?>
        </table>
        <script type="text/javascript">
        (function () {
            var accountData = <?php echo wp_json_encode($accounts_js); ?>;
            var selectEl = document.getElementById('ahx_mail_account_select');
            if (!selectEl) {
                return;
            }

            var fields = {
                label: document.getElementById('ahx_mail_account_label'),
                user: document.getElementById('ahx_mail_imap_user'),
                pass: document.getElementById('ahx_mail_imap_password'),
                host: document.getElementById('ahx_mail_imap_host'),
                port: document.getElementById('ahx_mail_imap_port'),
                enc: document.getElementById('ahx_mail_imap_encryption'),
                per: document.getElementById('ahx_mail_emails_per_page'),
                trash: document.getElementById('ahx_mail_trash_folder')
            };

            var currentAccountKey = selectEl.value;

            function getSnapshot() {
                return {
                    label: fields.label ? fields.label.value : '',
                    user: fields.user ? fields.user.value : '',
                    pass: fields.pass ? fields.pass.value : '',
                    host: fields.host ? fields.host.value : '',
                    port: fields.port ? fields.port.value : '',
                    enc: fields.enc ? fields.enc.value : '',
                    per: fields.per ? fields.per.value : '',
                    trash: fields.trash ? fields.trash.value : ''
                };
            }

            function getBaselineFor(key) {
                if (key === '__new__' || !accountData[key]) {
                    return {
                        label: '', user: '', pass: '', host: '', port: '', enc: '', per: '', trash: ''
                    };
                }
                var a = accountData[key];
                return {
                    label: a.label || '',
                    user: a.imap_user || '',
                    pass: '',
                    host: a.imap_host || '',
                    port: a.imap_port || '',
                    enc: a.imap_encryption || '',
                    per: a.emails_per_page || '',
                    trash: a.trash_folder || ''
                };
            }

            function hasUnsavedChanges(key) {
                var now = getSnapshot();
                var base = getBaselineFor(key);
                return now.label !== base.label
                    || now.user !== base.user
                    || now.pass !== base.pass
                    || now.host !== base.host
                    || now.port !== base.port
                    || now.enc !== base.enc
                    || now.per !== base.per
                    || now.trash !== base.trash;
            }

            function applyAccount(key) {
                if (key === '__new__' || !accountData[key]) {
                    if (fields.label) { fields.label.value = ''; }
                    if (fields.user) { fields.user.value = ''; }
                    if (fields.pass) {
                        fields.pass.value = '';
                        fields.pass.placeholder = '';
                    }
                    if (fields.host) { fields.host.value = ''; }
                    if (fields.port) { fields.port.value = ''; }
                    if (fields.enc) { fields.enc.value = ''; }
                    if (fields.per) { fields.per.value = ''; }
                    if (fields.trash) {
                        fields.trash.innerHTML = '<option value=""><?php echo esc_js(__('— Sofort löschen —', 'ahx_wp_mail')); ?></option>';
                        fields.trash.value = '';
                    }
                    return;
                }

                var a = accountData[key];
                if (fields.label) { fields.label.value = a.label || ''; }
                if (fields.user) { fields.user.value = a.imap_user || ''; }
                if (fields.pass) {
                    fields.pass.value = '';
                    fields.pass.placeholder = a.has_password ? '(gespeichert – zum Ändern neu eingeben)' : '';
                }
                if (fields.host) { fields.host.value = a.imap_host || ''; }
                if (fields.port) { fields.port.value = a.imap_port || ''; }
                if (fields.enc) { fields.enc.value = a.imap_encryption || ''; }
                if (fields.per) { fields.per.value = a.emails_per_page || ''; }
                if (fields.trash) {
                    fields.trash.innerHTML = '<option value=""><?php echo esc_js(__('— Sofort löschen —', 'ahx_wp_mail')); ?></option>';
                    if (a.trash_folder) {
                        var option = document.createElement('option');
                        option.value = a.trash_folder;
                        option.textContent = a.trash_folder;
                        option.selected = true;
                        fields.trash.appendChild(option);
                    } else {
                        fields.trash.value = '';
                    }
                }
            }

            selectEl.addEventListener('change', function () {
                var nextKey = selectEl.value;
                if (hasUnsavedChanges(currentAccountKey)) {
                    var ok = window.confirm('Es gibt ungespeicherte Änderungen. Trotzdem Konto wechseln?');
                    if (!ok) {
                        selectEl.value = currentAccountKey;
                        return;
                    }
                }
                applyAccount(nextKey);
                currentAccountKey = nextKey;
            });

            // Test-Button
            var testBtn = document.getElementById('ahx_mail_test_connection');
            var testStatus = document.getElementById('ahx_mail_test_status');
            var testMsg = document.getElementById('ahx_mail_test_message');

            var loadFoldersBtn = document.getElementById('ahx_mail_load_folders');
            var folderStatus = document.getElementById('ahx_mail_folder_status');

            function loadFolders() {
                if (!loadFoldersBtn || !fields.trash || !folderStatus) {
                    return;
                }

                loadFoldersBtn.disabled = true;
                folderStatus.style.display = 'block';
                folderStatus.textContent = '<?php echo esc_js(__('Ordner werden geladen…', 'ahx_wp_mail')); ?>';

                var selectedValue = fields.trash.value;
                var xhr = new XMLHttpRequest();
                xhr.open('POST', '<?php echo esc_attr(admin_url('admin-ajax.php')); ?>', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

                xhr.onload = function () {
                    loadFoldersBtn.disabled = false;

                    try {
                        var resp = JSON.parse(xhr.responseText);
                        if (!resp.success) {
                            folderStatus.textContent = resp.data && resp.data.message
                                ? resp.data.message
                                : '<?php echo esc_js(__('Ordner konnten nicht geladen werden.', 'ahx_wp_mail')); ?>';
                            return;
                        }

                        var folders = Array.isArray(resp.data && resp.data.folders) ? resp.data.folders : [];
                        fields.trash.innerHTML = '<option value=""><?php echo esc_js(__('— Sofort löschen —', 'ahx_wp_mail')); ?></option>';

                        folders.forEach(function (folder) {
                            var option = document.createElement('option');
                            option.value = folder;
                            option.textContent = folder;
                            if (folder === selectedValue) {
                                option.selected = true;
                            }
                            fields.trash.appendChild(option);
                        });

                        if (selectedValue === '') {
                            fields.trash.value = '';
                        }

                        folderStatus.textContent = folders.length
                            ? '<?php echo esc_js(__('Ordner erfolgreich geladen.', 'ahx_wp_mail')); ?>'
                            : '<?php echo esc_js(__('Keine Ordner gefunden.', 'ahx_wp_mail')); ?>';
                    } catch (error) {
                        folderStatus.textContent = '<?php echo esc_js(__('Antwort konnte nicht verarbeitet werden.', 'ahx_wp_mail')); ?>';
                    }
                };

                xhr.onerror = function () {
                    loadFoldersBtn.disabled = false;
                    folderStatus.textContent = '<?php echo esc_js(__('Netzwerkfehler beim Laden der Ordner.', 'ahx_wp_mail')); ?>';
                };

                xhr.send(
                    'action=ahx_wp_mail_fetch_folders' +
                    '&nonce=<?php echo esc_js(wp_create_nonce('ahx_wp_mail_nonce')); ?>' +
                    '&user_id=<?php echo (int) $user->ID; ?>' +
                    '&account_key=' + encodeURIComponent(selectEl.value)
                );
            }

            if (loadFoldersBtn) {
                loadFoldersBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    loadFolders();
                });
            }

            if (testBtn) {
                testBtn.addEventListener('click', function (e) {
                    e.preventDefault();

                    testBtn.disabled = true;
                    testBtn.innerHTML = '<?php esc_html_e('Testen...', 'ahx_wp_mail'); ?>';
                    testStatus.textContent = '';
                    testMsg.style.display = 'none';
                    testMsg.innerHTML = '';

                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', '<?php echo esc_attr(admin_url('admin-ajax.php')); ?>', true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

                    var formData = 'action=ahx_wp_mail_test_connection' +
                                   '&nonce=<?php echo wp_create_nonce('ahx_wp_mail_nonce'); ?>' +
                                   '&user_id=<?php echo (int) $user->ID; ?>' +
                                   '&account_key=' + encodeURIComponent(selectEl.value);

                    xhr.onload = function () {
                        testBtn.disabled = false;
                        testBtn.innerHTML = '<?php esc_html_e('Verbindung testen', 'ahx_wp_mail'); ?>';

                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.success) {
                                testStatus.textContent = '✓ <?php esc_html_e('erfolgreich', 'ahx_wp_mail'); ?>';
                                testStatus.style.color = '#28a745';
                                testMsg.innerHTML = '<div style="color: #28a745; padding: 8px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px;">' +
                                                    '✓ ' + (resp.data.message || '<?php esc_html_e('Verbindung erfolgreich!', 'ahx_wp_mail'); ?>') +
                                                    '</div>';
                                testMsg.style.display = 'block';
                            } else {
                                var errMsg = resp.data ? (resp.data.message || resp.data) : '<?php esc_html_e('Unbekannter Fehler', 'ahx_wp_mail'); ?>';
                                testStatus.textContent = '✗ <?php esc_html_e('Fehler', 'ahx_wp_mail'); ?>';
                                testStatus.style.color = '#dc3545';
                                testMsg.innerHTML = '<div style="color: #721c24; padding: 8px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;">' +
                                                    '✗ ' + errMsg +
                                                    '</div>';
                                testMsg.style.display = 'block';
                            }
                        } catch (e) {
                            testStatus.textContent = '✗ <?php esc_html_e('Fehler', 'ahx_wp_mail'); ?>';
                            testStatus.style.color = '#dc3545';
                            testMsg.innerHTML = '<div style="color: #721c24; padding: 8px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;">' +
                                                '✗ <?php esc_html_e('Fehler beim Testen der Verbindung.', 'ahx_wp_mail'); ?>' +
                                                '</div>';
                            testMsg.style.display = 'block';
                        }
                    };

                    xhr.onerror = function () {
                        testBtn.disabled = false;
                        testBtn.innerHTML = '<?php esc_html_e('Verbindung testen', 'ahx_wp_mail'); ?>';
                        testStatus.textContent = '✗ <?php esc_html_e('Fehler', 'ahx_wp_mail'); ?>';
                        testStatus.style.color = '#dc3545';
                        testMsg.innerHTML = '<div style="color: #721c24; padding: 8px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;">' +
                                            '✗ <?php esc_html_e('Netzwerkfehler beim Testen der Verbindung.', 'ahx_wp_mail'); ?>' +
                                            '</div>';
                        testMsg.style.display = 'block';
                    };

                    xhr.send(formData);
                });
            }
        })();
        </script>
        <?php
        wp_nonce_field('ahx_wp_mail_save_profile_' . $user->ID, 'ahx_wp_mail_profile_nonce');
    }

    /**
     * Speichert die Profil-Felder beim Speichern des Profils.
     *
     * @param int $user_id
     */
    public static function save_profile_fields($user_id) {
        if (!isset($_POST['ahx_wp_mail_profile_nonce'])) {
            return;
        }

        if (!wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['ahx_wp_mail_profile_nonce'])),
            'ahx_wp_mail_save_profile_' . $user_id
        )) {
            return;
        }

        if (!current_user_can('edit_user', $user_id)) {
            return;
        }

        if (!empty($_POST['ahx_mail_imap_delete_all'])) {
            self::delete($user_id);
            return;
        }

        $selected_account = isset($_POST['ahx_mail_account_select'])
            ? sanitize_key(wp_unslash($_POST['ahx_mail_account_select']))
            : '';

        if ($selected_account === 'new') {
            // sanitize_key('__new__') wird zu 'new'
            $selected_account = '';
        }

        if ($selected_account !== '') {
            self::set_active_account($user_id, $selected_account);
        }

        if (!empty($_POST['ahx_mail_imap_duplicate'])) {
            $active = self::get($user_id);
            if (!empty($active['active_account_key'])) {
                self::duplicate_account($user_id, $active['active_account_key']);
            }
            return;
        }

        if (!empty($_POST['ahx_mail_imap_delete'])) {
            $active = self::get($user_id);
            if (!empty($active['active_account_key'])) {
                self::delete_account($user_id, $active['active_account_key']);
            }
            return;
        }

        self::save($user_id, array(
            'account_key'     => $selected_account,
            'label'           => isset($_POST['ahx_mail_account_label'])
                                    ? sanitize_text_field(wp_unslash($_POST['ahx_mail_account_label']))
                                    : '',
            'imap_user'       => isset($_POST['ahx_mail_imap_user'])
                                    ? sanitize_email(wp_unslash($_POST['ahx_mail_imap_user']))
                                    : '',
            'imap_password'   => isset($_POST['ahx_mail_imap_password'])
                                    ? wp_unslash($_POST['ahx_mail_imap_password'])
                                    : '',
            'imap_host'       => isset($_POST['ahx_mail_imap_host'])
                                    ? sanitize_text_field(wp_unslash($_POST['ahx_mail_imap_host']))
                                    : '',
            'imap_port'       => isset($_POST['ahx_mail_imap_port'])
                                    ? (int) $_POST['ahx_mail_imap_port']
                                    : 0,
            'imap_encryption' => isset($_POST['ahx_mail_imap_encryption'])
                                    ? sanitize_text_field(wp_unslash($_POST['ahx_mail_imap_encryption']))
                                    : '',
            'emails_per_page' => isset($_POST['ahx_mail_emails_per_page'])
                                    ? (int) $_POST['ahx_mail_emails_per_page']
                                    : 0,
            'trash_folder'    => isset($_POST['ahx_mail_trash_folder'])
                                    ? sanitize_text_field(wp_unslash($_POST['ahx_mail_trash_folder']))
                                    : '',
        ));

        $detail_action_after = isset($_POST['ahx_mail_detail_action_after'])
            ? sanitize_key(wp_unslash($_POST['ahx_mail_detail_action_after']))
            : 'list';
        if (!in_array($detail_action_after, array('list', 'next'), true)) {
            $detail_action_after = 'list';
        }
        update_user_meta($user_id, self::META_DETAIL_ACTION_AFTER, $detail_action_after);
    }
}

// Profil-Hooks registrieren
add_action('show_user_profile', array('AHX_WP_Mail_User_Settings', 'render_profile_fields'));
add_action('edit_user_profile', array('AHX_WP_Mail_User_Settings', 'render_profile_fields'));
add_action('personal_options_update', array('AHX_WP_Mail_User_Settings', 'save_profile_fields'));
add_action('edit_user_profile_update', array('AHX_WP_Mail_User_Settings', 'save_profile_fields'));
