<?php
/**
 * Plugin Name: AHX WP Mail
 * Description: IMAP-Postfach-Viewer im Frontend mit benutzerspezifischen Zugangsdaten.
 * Version: v0.2.0
 * Author: AHX
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AHX_WP_MAIL_VERSION', 'v0.2.0');
define('AHX_WP_MAIL_FILE', __FILE__);
define('AHX_WP_MAIL_DIR', plugin_dir_path(__FILE__));
define('AHX_WP_MAIL_URL', plugin_dir_url(__FILE__));

require_once AHX_WP_MAIL_DIR . 'includes/class-imap.php';
require_once AHX_WP_MAIL_DIR . 'includes/class-user-settings.php';
require_once AHX_WP_MAIL_DIR . 'frontend/shortcode.php';
require_once AHX_WP_MAIL_DIR . 'admin/admin-page.php';
require_once AHX_WP_MAIL_DIR . 'admin/config-page.php';

// ---------------------------------------------------------------------------
// Activation / Deactivation
// ---------------------------------------------------------------------------

function ahx_wp_mail_activate() {
    add_option('ahx_wp_mail_imap_host', '');
    add_option('ahx_wp_mail_imap_port', '993');
    add_option('ahx_wp_mail_imap_encryption', 'ssl');
    add_option('ahx_wp_mail_emails_per_page', '20');
    add_option('ahx_wp_mail_mark_read_mode', 'open');
    add_option('ahx_wp_mail_folder_aliases', wp_json_encode(ahx_wp_mail_get_default_folder_aliases(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
register_activation_hook(__FILE__, 'ahx_wp_mail_activate');

function ahx_wp_mail_deactivate() {
    // Options bleiben erhalten; werden bei Deinstallation entfernt.
}
register_deactivation_hook(__FILE__, 'ahx_wp_mail_deactivate');

function ahx_wp_mail_get_default_folder_aliases() {
    return array(
        'sent' => array('Sent', 'Sent Items', 'Gesendet', 'Gesendete Elemente', 'Gesendete Objekte'),
        'drafts' => array('Drafts', 'Entwuerfe', 'Entwürfe'),
        'trash' => array('Trash', 'Deleted Items', 'Papierkorb', 'Gelöscht'),
        'spam' => array('Spam', 'Junk'),
        'archive' => array('Archive', 'Archiv'),
    );
}

function ahx_wp_mail_get_folder_aliases() {
    $defaults = ahx_wp_mail_get_default_folder_aliases();
    $raw = get_option('ahx_wp_mail_folder_aliases', '');

    if (!is_string($raw) || trim($raw) === '') {
        return $defaults;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    $clean = array();
    foreach ($defaults as $group => $default_aliases) {
        $aliases = isset($decoded[$group]) && is_array($decoded[$group]) ? $decoded[$group] : $default_aliases;
        $aliases = array_values(array_filter(array_map(static function ($value) {
            return is_string($value) ? trim($value) : '';
        }, $aliases), static function ($value) {
            return $value !== '';
        }));

        $clean[$group] = !empty($aliases) ? array_values(array_unique($aliases)) : $default_aliases;
    }

    return $clean;
}

// ---------------------------------------------------------------------------
// Admin-Menü
// ---------------------------------------------------------------------------

function ahx_wp_mail_admin_menu() {
    add_menu_page(
        'AHX WP Mail',
        'AHX Mail',
        'manage_options',
        'ahx_wp_mail-main',
        'ahx_wp_mail_admin_page',
        'dashicons-email-alt',
        82
    );

    add_submenu_page(
        'ahx_wp_mail-main',
        'Übersicht',
        'Übersicht',
        'manage_options',
        'ahx_wp_mail-main',
        'ahx_wp_mail_admin_page'
    );

    add_submenu_page(
        'ahx_wp_mail-main',
        'Einstellungen',
        'Einstellungen',
        'manage_options',
        'ahx_wp_mail-config',
        'ahx_wp_mail_config_page'
    );
}
add_action('admin_menu', 'ahx_wp_mail_admin_menu');

// ---------------------------------------------------------------------------
// Assets
// ---------------------------------------------------------------------------

function ahx_wp_mail_enqueue_assets() {
    if (!is_user_logged_in()) {
        return;
    }
    wp_enqueue_style(
        'ahx-wp-mail-style',
        AHX_WP_MAIL_URL . 'assets/mail.css',
        array(),
        AHX_WP_MAIL_VERSION
    );
    wp_enqueue_script(
        'ahx-wp-mail-script',
        AHX_WP_MAIL_URL . 'assets/mail.js',
        array('jquery'),
        AHX_WP_MAIL_VERSION,
        true
    );
    wp_localize_script('ahx-wp-mail-script', 'ahxMail', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('ahx_wp_mail_nonce'),
        'folderAliases' => ahx_wp_mail_get_folder_aliases(),
    ));
}
add_action('wp_enqueue_scripts', 'ahx_wp_mail_enqueue_assets');

function ahx_wp_mail_get_effective_imap_settings_for_user($user_id, $account_key = '') {
    $user_id = (int) $user_id;
    $settings = AHX_WP_Mail_User_Settings::get_account($user_id, $account_key);

    if ($settings['account_key'] !== '') {
        AHX_WP_Mail_User_Settings::set_active_account($user_id, $settings['account_key']);
    }

    return array(
        'account_key' => (string) $settings['account_key'],
        'imap_user' => (string) $settings['imap_user'],
        'imap_password' => (string) $settings['imap_password'],
        'host' => (string) ($settings['imap_host'] ?: get_option('ahx_wp_mail_imap_host', '')),
        'port' => (int) ($settings['imap_port'] ?: get_option('ahx_wp_mail_imap_port', 993)),
        'encryption' => (string) ($settings['imap_encryption'] ?: get_option('ahx_wp_mail_imap_encryption', 'ssl')),
        'trash_folder' => (string) ($settings['trash_folder'] ?? ''),
    );
}

// ---------------------------------------------------------------------------
// AJAX: E-Mails laden
// ---------------------------------------------------------------------------

function ahx_wp_mail_ajax_fetch_emails() {
    check_ajax_referer('ahx_wp_mail_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Nicht eingeloggt.'), 403);
    }

    $user_id      = get_current_user_id();
    $account_key  = isset($_POST['account_key']) ? sanitize_key(wp_unslash($_POST['account_key'])) : '';
    $settings     = AHX_WP_Mail_User_Settings::get_account($user_id, $account_key);

    if ($settings['account_key'] !== '') {
        AHX_WP_Mail_User_Settings::set_active_account($user_id, $settings['account_key']);
    }

    if (empty($settings['imap_user']) || empty($settings['imap_password'])) {
        wp_send_json_error(array('message' => 'Keine IMAP-Zugangsdaten hinterlegt. Bitte unter Profil > E-Mail-Konto ausfüllen.'), 422);
    }

    // Benutzerspezifische Einstellung hat Vorrang vor globaler Option
    $host       = $settings['imap_host']       ?: get_option('ahx_wp_mail_imap_host', '');
    $port       = (int) ($settings['imap_port']       ?: get_option('ahx_wp_mail_imap_port', 993));
    $encryption = $settings['imap_encryption'] ?: get_option('ahx_wp_mail_imap_encryption', 'ssl');
    $per_page   = (int) ($settings['emails_per_page'] ?: get_option('ahx_wp_mail_emails_per_page', 20));
    $folder     = isset($_POST['folder']) ? sanitize_text_field(wp_unslash($_POST['folder'])) : 'INBOX';
    $page       = max(1, (int) (isset($_POST['page']) ? $_POST['page'] : 1));

    if (empty($host)) {
        wp_send_json_error(array('message' => 'IMAP-Server nicht konfiguriert (Admin-Einstellungen oder Profil).'), 500);
    }

    $imap = new AHX_WP_Mail_IMAP($host, $port, $encryption);
    $result = $imap->connect($settings['imap_user'], $settings['imap_password']);

    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()), 500);
    }

    $emails  = $imap->get_emails($folder, $page, $per_page);
    $folders = $imap->get_folders();
    $imap->disconnect();

    wp_send_json_success(array(
        'emails'  => $emails,
        'folders' => $folders,
        'page'    => $page,
        'account_key' => $settings['account_key'],
    ));
}
add_action('wp_ajax_ahx_wp_mail_fetch_emails', 'ahx_wp_mail_ajax_fetch_emails');

// ---------------------------------------------------------------------------
// AJAX: E-Mail-Inhalt laden
// ---------------------------------------------------------------------------

function ahx_wp_mail_ajax_fetch_email() {
    check_ajax_referer('ahx_wp_mail_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Nicht eingeloggt.'), 403);
    }

    $user_id      = get_current_user_id();
    $account_key  = isset($_POST['account_key']) ? sanitize_key(wp_unslash($_POST['account_key'])) : '';
    $settings     = AHX_WP_Mail_User_Settings::get_account($user_id, $account_key);

    if ($settings['account_key'] !== '') {
        AHX_WP_Mail_User_Settings::set_active_account($user_id, $settings['account_key']);
    }

    if (empty($settings['imap_user']) || empty($settings['imap_password'])) {
        wp_send_json_error(array('message' => 'Keine IMAP-Zugangsdaten hinterlegt.'), 422);
    }

    // Benutzerspezifische Einstellung hat Vorrang vor globaler Option
    $host       = $settings['imap_host']       ?: get_option('ahx_wp_mail_imap_host', '');
    $port       = (int) ($settings['imap_port']       ?: get_option('ahx_wp_mail_imap_port', 993));
    $encryption = $settings['imap_encryption'] ?: get_option('ahx_wp_mail_imap_encryption', 'ssl');
    $folder     = isset($_POST['folder']) ? sanitize_text_field(wp_unslash($_POST['folder'])) : 'INBOX';
    $uid        = isset($_POST['uid']) ? (int) $_POST['uid'] : 0;
    $debug_mode = !empty($_POST['debug']) && current_user_can('manage_options');

    if (empty($host) || $uid <= 0) {
        wp_send_json_error(array('message' => 'Ungültige Parameter.'), 400);
    }

    $imap   = new AHX_WP_Mail_IMAP($host, $port, $encryption);
    $result = $imap->connect($settings['imap_user'], $settings['imap_password']);

    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()), 500);
    }

    // Absender prüfen bevor Body geladen wird (nur Header, kein Download)
    $sender_email = $imap->peek_sender($folder, $uid);
    $allow_images = AHX_WP_Mail_User_Settings::is_sender_allowed($user_id, $sender_email);
    $mark_mode    = get_option('ahx_wp_mail_mark_read_mode', 'open');
    $mark_as_read = ($mark_mode === 'open');

    $email = $imap->get_email($folder, $uid, $allow_images, $mark_as_read, $debug_mode);
    $imap->disconnect();

    if (is_wp_error($email)) {
        wp_send_json_error(array('message' => $email->get_error_message()), 500);
    }

    $email['mark_read_mode'] = $mark_mode;
    $email['account_key'] = $settings['account_key'];

    // Debug-Trace nur fuer Admins ausgeben.
    if (!$debug_mode && isset($email['debug_trace'])) {
        unset($email['debug_trace']);
    }

    wp_send_json_success($email);
}
add_action('wp_ajax_ahx_wp_mail_fetch_email', 'ahx_wp_mail_ajax_fetch_email');

// ---------------------------------------------------------------------------
// AJAX: Absender / Domain zur Bild-Freigabeliste hinzufügen
// ---------------------------------------------------------------------------

function ahx_wp_mail_ajax_allow_images() {
    check_ajax_referer('ahx_wp_mail_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Nicht eingeloggt.'), 403);
    }

    $type  = isset($_POST['type'])  ? sanitize_text_field(wp_unslash($_POST['type']))  : '';
    $value = isset($_POST['value']) ? sanitize_text_field(wp_unslash($_POST['value'])) : '';

    if (!in_array($type, array('sender', 'domain'), true) || $value === '') {
        wp_send_json_error(array('message' => 'Ungültige Parameter.'), 400);
    }

    AHX_WP_Mail_User_Settings::add_to_allowlist(get_current_user_id(), $type, $value);
    wp_send_json_success();
}
add_action('wp_ajax_ahx_wp_mail_allow_images', 'ahx_wp_mail_ajax_allow_images');

function ahx_wp_mail_ajax_revoke_images() {
    check_ajax_referer('ahx_wp_mail_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Nicht eingeloggt.'), 403);
    }

    $type  = isset($_POST['type'])  ? sanitize_text_field(wp_unslash($_POST['type']))  : '';
    $value = isset($_POST['value']) ? sanitize_text_field(wp_unslash($_POST['value'])) : '';

    if (!in_array($type, array('sender', 'domain'), true) || $value === '') {
        wp_send_json_error(array('message' => 'Ungültige Parameter.'), 400);
    }

    AHX_WP_Mail_User_Settings::remove_from_allowlist(get_current_user_id(), $type, $value);
    wp_send_json_success();
}
add_action('wp_ajax_ahx_wp_mail_revoke_images', 'ahx_wp_mail_ajax_revoke_images');

// ---------------------------------------------------------------------------
// AJAX: E-Mail als gelesen / ungelesen markieren
// ---------------------------------------------------------------------------

function ahx_wp_mail_ajax_mark() {
    check_ajax_referer('ahx_wp_mail_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Nicht eingeloggt.'), 403);
    }

    $user_id      = get_current_user_id();
    $account_key  = isset($_POST['account_key']) ? sanitize_key(wp_unslash($_POST['account_key'])) : '';
    $settings     = AHX_WP_Mail_User_Settings::get_account($user_id, $account_key);

    if ($settings['account_key'] !== '') {
        AHX_WP_Mail_User_Settings::set_active_account($user_id, $settings['account_key']);
    }

    if (empty($settings['imap_user']) || empty($settings['imap_password'])) {
        wp_send_json_error(array('message' => 'Keine IMAP-Zugangsdaten hinterlegt.'), 422);
    }

    $host       = $settings['imap_host']       ?: get_option('ahx_wp_mail_imap_host', '');
    $port       = (int) ($settings['imap_port']       ?: get_option('ahx_wp_mail_imap_port', 993));
    $encryption = $settings['imap_encryption'] ?: get_option('ahx_wp_mail_imap_encryption', 'ssl');
    $folder     = isset($_POST['folder']) ? sanitize_text_field(wp_unslash($_POST['folder'])) : 'INBOX';
    $uid        = isset($_POST['uid'])    ? (int) $_POST['uid'] : 0;
    $as_read    = isset($_POST['read'])   ? (bool) $_POST['read'] : true;

    if (empty($host) || $uid <= 0) {
        wp_send_json_error(array('message' => 'Ungültige Parameter.'), 400);
    }

    $imap   = new AHX_WP_Mail_IMAP($host, $port, $encryption);
    $result = $imap->connect($settings['imap_user'], $settings['imap_password']);

    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()), 500);
    }

    $r = $as_read ? $imap->mark_read($folder, $uid) : $imap->mark_unread($folder, $uid);
    $imap->disconnect();

    if (is_wp_error($r)) {
        wp_send_json_error(array('message' => $r->get_error_message()), 500);
    }

    wp_send_json_success();
}
add_action('wp_ajax_ahx_wp_mail_mark', 'ahx_wp_mail_ajax_mark');

// ---------------------------------------------------------------------------
// AJAX: E-Mail löschen
// ---------------------------------------------------------------------------

function ahx_wp_mail_ajax_delete() {
    check_ajax_referer('ahx_wp_mail_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Nicht eingeloggt.'), 403);
    }

    $user_id      = get_current_user_id();
    $account_key  = isset($_POST['account_key']) ? sanitize_key(wp_unslash($_POST['account_key'])) : '';
    $settings     = AHX_WP_Mail_User_Settings::get_account($user_id, $account_key);

    if ($settings['account_key'] !== '') {
        AHX_WP_Mail_User_Settings::set_active_account($user_id, $settings['account_key']);
    }

    if (empty($settings['imap_user']) || empty($settings['imap_password'])) {
        wp_send_json_error(array('message' => 'Keine IMAP-Zugangsdaten hinterlegt.'), 422);
    }

    $host         = $settings['imap_host']       ?: get_option('ahx_wp_mail_imap_host', '');
    $port         = (int) ($settings['imap_port']       ?: get_option('ahx_wp_mail_imap_port', 993));
    $encryption   = $settings['imap_encryption'] ?: get_option('ahx_wp_mail_imap_encryption', 'ssl');
    $folder       = isset($_POST['folder']) ? sanitize_text_field(wp_unslash($_POST['folder'])) : 'INBOX';
    $trash_folder = $settings['trash_folder'];
    $uids         = isset($_POST['uids']) ? array_map('intval', (array) $_POST['uids']) : array();

    if (empty($host) || empty($uids)) {
        wp_send_json_error(array('message' => 'Ungültige Parameter.'), 400);
    }

    $imap   = new AHX_WP_Mail_IMAP($host, $port, $encryption);
    $result = $imap->connect($settings['imap_user'], $settings['imap_password']);

    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()), 500);
    }

    $errors = array();
    foreach ($uids as $uid) {
        $r = $imap->delete_email($folder, $uid, $trash_folder);
        if (is_wp_error($r)) {
            $errors[] = $r->get_error_message();
        }
    }
    $imap->disconnect();

    if (!empty($errors)) {
        wp_send_json_error(array('message' => implode('; ', $errors)), 500);
    }

    wp_send_json_success();
}
add_action('wp_ajax_ahx_wp_mail_delete', 'ahx_wp_mail_ajax_delete');

// ---------------------------------------------------------------------------
// AJAX: E-Mail verschieben
// ---------------------------------------------------------------------------

function ahx_wp_mail_ajax_move() {
    check_ajax_referer('ahx_wp_mail_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Nicht eingeloggt.'), 403);
    }

    $user_id      = get_current_user_id();
    $account_key  = isset($_POST['account_key']) ? sanitize_key(wp_unslash($_POST['account_key'])) : '';
    $settings     = AHX_WP_Mail_User_Settings::get_account($user_id, $account_key);

    if ($settings['account_key'] !== '') {
        AHX_WP_Mail_User_Settings::set_active_account($user_id, $settings['account_key']);
    }

    if (empty($settings['imap_user']) || empty($settings['imap_password'])) {
        wp_send_json_error(array('message' => 'Keine IMAP-Zugangsdaten hinterlegt.'), 422);
    }

    $host       = $settings['imap_host']       ?: get_option('ahx_wp_mail_imap_host', '');
    $port       = (int) ($settings['imap_port']       ?: get_option('ahx_wp_mail_imap_port', 993));
    $encryption = $settings['imap_encryption'] ?: get_option('ahx_wp_mail_imap_encryption', 'ssl');
    $folder     = isset($_POST['folder'])     ? sanitize_text_field(wp_unslash($_POST['folder']))     : 'INBOX';
    $to_folder  = isset($_POST['to_folder'])  ? sanitize_text_field(wp_unslash($_POST['to_folder']))  : '';
    $uids       = isset($_POST['uids'])       ? array_map('intval', (array) $_POST['uids'])           : array();

    if (empty($host) || empty($uids) || $to_folder === '') {
        wp_send_json_error(array('message' => 'Ungültige Parameter.'), 400);
    }

    $imap   = new AHX_WP_Mail_IMAP($host, $port, $encryption);
    $result = $imap->connect($settings['imap_user'], $settings['imap_password']);

    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()), 500);
    }

    $errors = array();
    foreach ($uids as $uid) {
        $r = $imap->move_email($folder, $uid, $to_folder);
        if (is_wp_error($r)) {
            $errors[] = $r->get_error_message();
        }
    }
    $imap->disconnect();

    if (!empty($errors)) {
        wp_send_json_error(array('message' => implode('; ', $errors)), 500);
    }

    wp_send_json_success();
}
add_action('wp_ajax_ahx_wp_mail_move', 'ahx_wp_mail_ajax_move');

// ---------------------------------------------------------------------------
// AJAX: Papierkorb leeren
// ---------------------------------------------------------------------------

function ahx_wp_mail_ajax_empty_trash() {
    check_ajax_referer('ahx_wp_mail_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Nicht eingeloggt.'), 403);
    }

    $user_id      = get_current_user_id();
    $account_key  = isset($_POST['account_key']) ? sanitize_key(wp_unslash($_POST['account_key'])) : '';
    $folder       = isset($_POST['folder']) ? sanitize_text_field(wp_unslash($_POST['folder'])) : '';
    $settings     = AHX_WP_Mail_User_Settings::get_account($user_id, $account_key);

    if ($settings['account_key'] !== '') {
        AHX_WP_Mail_User_Settings::set_active_account($user_id, $settings['account_key']);
    }

    if (empty($settings['imap_user']) || empty($settings['imap_password'])) {
        wp_send_json_error(array('message' => 'Keine IMAP-Zugangsdaten hinterlegt.'), 422);
    }

    if (empty($folder)) {
        wp_send_json_error(array('message' => 'Papierkorb nicht konfiguriert.'), 400);
    }

    $host       = $settings['imap_host']       ?: get_option('ahx_wp_mail_imap_host', '');
    $port       = (int) ($settings['imap_port']       ?: get_option('ahx_wp_mail_imap_port', 993));
    $encryption = $settings['imap_encryption'] ?: get_option('ahx_wp_mail_imap_encryption', 'ssl');

    if (empty($host)) {
        wp_send_json_error(array('message' => 'IMAP-Server nicht konfiguriert.'), 500);
    }

    $imap   = new AHX_WP_Mail_IMAP($host, $port, $encryption);
    $result = $imap->connect($settings['imap_user'], $settings['imap_password']);

    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()), 500);
    }

    $deleted_count = $imap->empty_folder($folder);

    $imap->disconnect();

    if (is_wp_error($deleted_count)) {
        wp_send_json_error(array('message' => $deleted_count->get_error_message()), 500);
    }

    wp_send_json_success(array(
        'message' => sprintf('%d E-Mails geloescht.', (int) $deleted_count),
        'deleted' => (int) $deleted_count,
    ));
}
add_action('wp_ajax_ahx_wp_mail_empty_trash', 'ahx_wp_mail_ajax_empty_trash');

// ---------------------------------------------------------------------------
// AJAX: IMAP-Verbindung testen
// ---------------------------------------------------------------------------

function ahx_wp_mail_ajax_test_connection() {
    check_ajax_referer('ahx_wp_mail_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Nicht eingeloggt.'), 403);
    }

    $current_user_id = get_current_user_id();
    $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : $current_user_id;
    $account_key = isset($_POST['account_key']) ? sanitize_key(wp_unslash($_POST['account_key'])) : '';

    if ($user_id <= 0 || !current_user_can('edit_user', $user_id)) {
        wp_send_json_error(array('message' => 'Keine Berechtigung.'), 403);
    }

    $imap_settings = ahx_wp_mail_get_effective_imap_settings_for_user($user_id, $account_key);

    if (empty($imap_settings['imap_user']) || empty($imap_settings['imap_password'])) {
        wp_send_json_error(array('message' => 'Keine IMAP-Zugangsdaten hinterlegt.'), 422);
    }

    if ($imap_settings['host'] === '') {
        wp_send_json_error(array('message' => 'IMAP-Server nicht konfiguriert.'), 500);
    }

    // Test-Verbindung durchführen
    $imap = new AHX_WP_Mail_IMAP($imap_settings['host'], $imap_settings['port'], $imap_settings['encryption']);
    $result = $imap->connect($imap_settings['imap_user'], $imap_settings['imap_password']);
    
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()), 500);
    }

    // Verbindung erfolgreich - Ordneranzahl zur Bestätigung abrufen
    $folders = $imap->get_folders();
    $imap->disconnect();

    wp_send_json_success(array(
        'message' => 'Verbindung erfolgreich! ' . count($folders) . ' Ordner gefunden.',
        'folders_count' => count($folders),
    ));
}
add_action('wp_ajax_ahx_wp_mail_test_connection', 'ahx_wp_mail_ajax_test_connection');

function ahx_wp_mail_ajax_fetch_folders() {
    check_ajax_referer('ahx_wp_mail_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Nicht eingeloggt.'), 403);
    }

    $current_user_id = get_current_user_id();
    $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : $current_user_id;
    $account_key = isset($_POST['account_key']) ? sanitize_key(wp_unslash($_POST['account_key'])) : '';

    if ($user_id <= 0 || !current_user_can('edit_user', $user_id)) {
        wp_send_json_error(array('message' => 'Keine Berechtigung.'), 403);
    }

    $imap_settings = ahx_wp_mail_get_effective_imap_settings_for_user($user_id, $account_key);

    if (empty($imap_settings['imap_user']) || empty($imap_settings['imap_password'])) {
        wp_send_json_error(array('message' => 'Keine IMAP-Zugangsdaten im ausgewählten Konto hinterlegt.'), 422);
    }

    if ($imap_settings['host'] === '') {
        wp_send_json_error(array('message' => 'IMAP-Server nicht konfiguriert.'), 500);
    }

    $imap = new AHX_WP_Mail_IMAP($imap_settings['host'], $imap_settings['port'], $imap_settings['encryption']);
    $result = $imap->connect($imap_settings['imap_user'], $imap_settings['imap_password']);

    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()), 500);
    }

    $folders = $imap->get_folders();
    $imap->disconnect();

    wp_send_json_success(array(
        'folders' => is_array($folders) ? array_values($folders) : array(),
    ));
}
add_action('wp_ajax_ahx_wp_mail_fetch_folders', 'ahx_wp_mail_ajax_fetch_folders');

// ---------------------------------------------------------------------------
// AJAX: Anhang herunterladen
// ---------------------------------------------------------------------------

function ahx_wp_mail_ajax_download_attachment() {
    check_ajax_referer('ahx_wp_mail_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Nicht eingeloggt.'), 403);
    }

    $user_id      = get_current_user_id();
    $account_key  = isset($_POST['account_key']) ? sanitize_key(wp_unslash($_POST['account_key'])) : '';
    $settings     = AHX_WP_Mail_User_Settings::get_account($user_id, $account_key);

    if ($settings['account_key'] !== '') {
        AHX_WP_Mail_User_Settings::set_active_account($user_id, $settings['account_key']);
    }

    if (empty($settings['imap_user']) || empty($settings['imap_password'])) {
        wp_send_json_error(array('message' => 'Keine IMAP-Zugangsdaten hinterlegt.'), 422);
    }

    $host         = $settings['imap_host']       ?: get_option('ahx_wp_mail_imap_host', '');
    $port         = (int) ($settings['imap_port']       ?: get_option('ahx_wp_mail_imap_port', 993));
    $encryption   = $settings['imap_encryption'] ?: get_option('ahx_wp_mail_imap_encryption', 'ssl');
    $folder       = isset($_POST['folder']) ? sanitize_text_field(wp_unslash($_POST['folder'])) : 'INBOX';
    $uid          = isset($_POST['uid']) ? (int) $_POST['uid'] : 0;
    $part_id      = isset($_POST['part_id']) ? sanitize_text_field(wp_unslash($_POST['part_id'])) : '';
    $filename     = isset($_POST['filename']) ? sanitize_file_name(wp_unslash($_POST['filename'])) : 'attachment';

    if (empty($host) || $uid <= 0 || $part_id === '') {
        wp_send_json_error(array('message' => 'Ungültige Parameter.'), 400);
    }

    // Größenlimit: 50MB
    $max_size = (int) apply_filters('ahx_wp_mail_max_attachment_size', 50 * 1024 * 1024);

    $imap = new AHX_WP_Mail_IMAP($host, $port, $encryption);
    $result = $imap->connect($settings['imap_user'], $settings['imap_password']);

    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()), 500);
    }

    $attachment_body = $imap->get_attachment_body($folder, $uid, $part_id);
    $imap->disconnect();

    if (is_wp_error($attachment_body)) {
        wp_send_json_error(array('message' => $attachment_body->get_error_message()), 500);
    }

    $size = strlen($attachment_body);
    if ($size > $max_size) {
        wp_send_json_error(array('message' => 'Anhang zu groß zum Herunterladen (Maximum: ' . size_format($max_size) . ').'), 413);
    }

    wp_send_json_success(array(
        'data' => base64_encode($attachment_body),
        'filename' => $filename,
        'size' => $size,
    ));
}
add_action('wp_ajax_ahx_wp_mail_download_attachment', 'ahx_wp_mail_ajax_download_attachment');
