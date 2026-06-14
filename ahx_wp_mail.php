<?php
/**
 * Plugin Name: AHX WP Mail
 * Description: IMAP-Postfach-Viewer im Frontend mit benutzerspezifischen Zugangsdaten.
 * Version: v0.2.1
 * Author: Alexander Herbst
 * Author URI: https://familie-herbst.de/ahx
 * License: GPL2
 * Text Domain: ahx_wp_mail
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AHX_WP_MAIL_VERSION', 'v0.2.1');
define('AHX_WP_MAIL_FILE', __FILE__);
define('AHX_WP_MAIL_DIR', plugin_dir_path(__FILE__));
define('AHX_WP_MAIL_URL', plugin_dir_url(__FILE__));

require_once AHX_WP_MAIL_DIR . 'includes/class-imap.php';
require_once AHX_WP_MAIL_DIR . 'includes/class-user-settings.php';
require_once AHX_WP_MAIL_DIR . 'frontend/shortcode.php';
require_once AHX_WP_MAIL_DIR . 'admin/admin-page.php';
require_once AHX_WP_MAIL_DIR . 'admin/config-page.php';
require_once AHX_WP_MAIL_DIR . 'admin/rules-page.php';

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
    add_option('ahx_wp_mail_rules', '[]');
    add_option('ahx_wp_mail_rules_cron_enabled', '0');
    add_option('ahx_wp_mail_rules_cron_interval_minutes', '15');
    add_option('ahx_wp_mail_rules_cron_token', wp_generate_password(40, false, false));

    ahx_wp_mail_schedule_rules_cron();
}
register_activation_hook(__FILE__, 'ahx_wp_mail_activate');

function ahx_wp_mail_deactivate() {
    // Options bleiben erhalten; werden bei Deinstallation entfernt.
    wp_clear_scheduled_hook('ahx_wp_mail_rules_cron_event');
}
register_deactivation_hook(__FILE__, 'ahx_wp_mail_deactivate');

/**
 * Liefert die gespeicherten Regeln als Array.
 *
 * @return array[]
 */
function ahx_wp_mail_get_rules() {
    $raw = get_option('ahx_wp_mail_rules', '[]');
    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : array();
}

/**
 * Liefert benutzerspezifische Regeln.
 *
 * @param int $user_id
 * @return array[]
 */
function ahx_wp_mail_get_user_rules($user_id) {
    $raw = get_user_meta((int) $user_id, 'ahx_wp_mail_rules', true);
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        return array();
    }

    // Legacy-Regeln ohne account_key auf das aktive Konto des Benutzers migrieren.
    $settings = AHX_WP_Mail_User_Settings::get((int) $user_id);
    $active_account_key = sanitize_key((string) ($settings['active_account_key'] ?? ''));
    $did_migrate = false;

    if ($active_account_key !== '') {
        foreach ($decoded as $idx => $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $rule_account_key = sanitize_key((string) ($rule['account_key'] ?? ''));
            if ($rule_account_key !== '') {
                continue;
            }
            $decoded[$idx]['account_key'] = $active_account_key;
            $did_migrate = true;
        }
    }

    $normalized = ahx_wp_mail_normalize_rules($decoded);
    if ($did_migrate) {
        update_user_meta((int) $user_id, 'ahx_wp_mail_rules', wp_json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    return $normalized;
}

/**
 * Speichert benutzerspezifische Regeln.
 *
 * @param int   $user_id
 * @param array $rules
 */
function ahx_wp_mail_save_user_rules($user_id, $rules) {
    $normalized = ahx_wp_mail_normalize_rules($rules);
    update_user_meta((int) $user_id, 'ahx_wp_mail_rules', wp_json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * Gültige Regeldefinitionen filtern/normalisieren.
 *
 * @param array $rules
 * @return array[]
 */
function ahx_wp_mail_normalize_rules($rules) {
    if (!is_array($rules)) {
        return array();
    }

    $normalized = array();
    foreach ($rules as $rule) {
        if (!is_array($rule)) {
            continue;
        }

        $action = isset($rule['action']) ? sanitize_key((string) $rule['action']) : '';
        if (!in_array($action, array('mark_read', 'mark_unread', 'delete', 'move', 'archive'), true)) {
            continue;
        }

        $item = array(
            'enabled' => !isset($rule['enabled']) ? true : (bool) $rule['enabled'],
            'account_key' => sanitize_key((string) ($rule['account_key'] ?? '')),
            'folder' => sanitize_text_field((string) ($rule['folder'] ?? 'INBOX')),
            'from_contains' => sanitize_text_field((string) ($rule['from_contains'] ?? '')),
            'to_contains' => sanitize_text_field((string) ($rule['to_contains'] ?? '')),
            'subject_contains' => sanitize_text_field((string) ($rule['subject_contains'] ?? '')),
            'action' => $action,
            'move_to_folder' => sanitize_text_field((string) ($rule['move_to_folder'] ?? '')),
        );

        if ($item['folder'] === '') {
            $item['folder'] = 'INBOX';
        }

        if ($item['account_key'] === '') {
            continue;
        }

        if ($item['action'] === 'move' && $item['move_to_folder'] === '') {
            continue;
        }

        if ($item['from_contains'] === '' && $item['to_contains'] === '' && $item['subject_contains'] === '') {
            continue;
        }

        $normalized[] = $item;
    }

    return $normalized;
}

/**
 * Plant oder entfernt den WP-Cron Event für Regeln.
 */
function ahx_wp_mail_schedule_rules_cron() {
    $enabled = get_option('ahx_wp_mail_rules_cron_enabled', '0') === '1';
    $hook = 'ahx_wp_mail_rules_cron_event';

    if (!$enabled) {
        wp_clear_scheduled_hook($hook);
        update_option('ahx_wp_mail_rules_cron_schedule', '');
        return;
    }

    $interval = max(1, (int) get_option('ahx_wp_mail_rules_cron_interval_minutes', 15));
    $schedule = 'ahx_wp_mail_rules_every_' . $interval . '_minutes';
    $stored_schedule = (string) get_option('ahx_wp_mail_rules_cron_schedule', '');

    // Nur bei Intervallwechsel alte Planung entfernen.
    if ($stored_schedule !== '' && $stored_schedule !== $schedule) {
        wp_clear_scheduled_hook($hook);
    }

    if (!wp_next_scheduled($hook)) {
        wp_schedule_event(time() + 60, $schedule, $hook);
    }

    if ($stored_schedule !== $schedule) {
        update_option('ahx_wp_mail_rules_cron_schedule', $schedule);
    }
}

/**
 * Zusätzliche WP-Cron Schedules für das Regel-Intervall.
 */
function ahx_wp_mail_rules_cron_schedules($schedules) {
    $interval = max(1, (int) get_option('ahx_wp_mail_rules_cron_interval_minutes', 15));
    $key = 'ahx_wp_mail_rules_every_' . $interval . '_minutes';
    $schedules[$key] = array(
        'interval' => $interval * 60,
        'display'  => sprintf('AHX Mail Rules alle %d Minuten', $interval),
    );
    return $schedules;
}
add_filter('cron_schedules', 'ahx_wp_mail_rules_cron_schedules');

/**
 * Prüft, ob eine E-Mail auf eine Regel passt.
 *
 * @param array $mail
 * @param array $rule
 * @return bool
 */
function ahx_wp_mail_rule_matches($mail, $rule) {
    $from = strtolower((string) ($mail['from'] ?? ''));
    $to = strtolower((string) ($mail['to'] ?? ''));
    $subject = strtolower((string) ($mail['subject'] ?? ''));

    $from_needle = strtolower((string) ($rule['from_contains'] ?? ''));
    $to_needle = strtolower((string) ($rule['to_contains'] ?? ''));
    $subject_needle = strtolower((string) ($rule['subject_contains'] ?? ''));

    if ($from_needle !== '' && strpos($from, $from_needle) === false) {
        return false;
    }
    if ($to_needle !== '' && strpos($to, $to_needle) === false) {
        return false;
    }
    if ($subject_needle !== '' && strpos($subject, $subject_needle) === false) {
        return false;
    }

    return true;
}

/**
 * Führt die konfigurierten Regeln aus.
 *
 * @param string $trigger manual|wp-cron|webhook
 * @return array
 */
function ahx_wp_mail_run_rules($trigger = 'manual') {
    $global_rules = ahx_wp_mail_normalize_rules(ahx_wp_mail_get_rules());

    $report = array(
        'trigger' => (string) $trigger,
        'rules_total' => count($global_rules),
        'users_checked' => 0,
        'accounts_checked' => 0,
        'emails_checked' => 0,
        'actions_applied' => 0,
        'errors' => array(),
    );

    if (empty($global_rules)) {
        $report['rules_total'] = 0;
    }

    $lock_key = 'ahx_wp_mail_rules_runner_lock';
    $lock_timestamp_key = 'ahx_wp_mail_rules_runner_lock_time';
    
    // Prüfe, ob Lock existiert und ob er zu alt ist (älter als 5 Minuten = 300 Sekunden)
    $lock_time = (int) get_transient($lock_timestamp_key);
    $current_time = time();
    $lock_age = $current_time - $lock_time;
    
    if (get_transient($lock_key) && $lock_age < 300) {
        $report['errors'][] = 'Runner bereits aktiv (seit ' . $lock_age . 's).';
        return $report;
    }
    
    // Setze einen neuen Lock mit kurzerer TTL (60 Sekunden)
    set_transient($lock_key, 1, 60);
    set_transient($lock_timestamp_key, $current_time, 60);

    $user_query = new WP_User_Query(array(
        'number' => 500,
        'fields' => array('ID'),
        'meta_query' => array(
            array(
                'key' => AHX_WP_Mail_User_Settings::META_ACCOUNTS,
                'compare' => 'EXISTS',
            ),
        ),
    ));

    $users = $user_query->get_results();
    foreach ($users as $user) {
        $user_id = (int) $user->ID;
        $report['users_checked']++;

        $rules = ahx_wp_mail_get_user_rules($user_id);
        if (empty($rules)) {
            $rules = $global_rules;
        }
        if (empty($rules)) {
            continue;
        }

        $raw_accounts = get_user_meta($user_id, AHX_WP_Mail_User_Settings::META_ACCOUNTS, true);
        $accounts = json_decode((string) $raw_accounts, true);
        if (!is_array($accounts) || empty($accounts)) {
            continue;
        }

        foreach (array_keys($accounts) as $account_key) {
            $settings = AHX_WP_Mail_User_Settings::get_account($user_id, (string) $account_key);
            if (empty($settings['imap_user']) || empty($settings['imap_password'])) {
                continue;
            }

            $host = $settings['imap_host'] ?: get_option('ahx_wp_mail_imap_host', '');
            if ($host === '') {
                continue;
            }

            $port = (int) ($settings['imap_port'] ?: get_option('ahx_wp_mail_imap_port', 993));
            $encryption = $settings['imap_encryption'] ?: get_option('ahx_wp_mail_imap_encryption', 'ssl');

            $imap = new AHX_WP_Mail_IMAP($host, $port, $encryption);
            $conn = $imap->connect($settings['imap_user'], $settings['imap_password']);
            if (is_wp_error($conn)) {
                $report['errors'][] = sprintf('User %d / Konto %s: %s', $user_id, $account_key, $conn->get_error_message());
                continue;
            }

            $report['accounts_checked']++;

            foreach ($rules as $rule) {
                if (empty($rule['enabled'])) {
                    continue;
                }

                $rule_account_key = sanitize_key((string) ($rule['account_key'] ?? ''));
                if ($rule_account_key === '' || $rule_account_key !== (string) $account_key) {
                    continue;
                }

                $folder = (string) $rule['folder'];
                $page = 1;
                $per_page = 50;
                $max_pages = 4;
                $emails_checked_in_rule = 0;
                $max_emails_per_rule = 200;

                while ($page <= $max_pages && $emails_checked_in_rule < $max_emails_per_rule) {
                    $emails = $imap->get_emails($folder, $page, $per_page, false, true);
                    if (!is_array($emails) || empty($emails)) {
                        if ($page === 1) {
                            $imap_error = trim((string) $imap->get_last_error());
                            if ($imap_error !== '') {
                                $report['errors'][] = sprintf(
                                    'User %d / Konto %s / Ordner %s: %s',
                                    $user_id,
                                    $account_key,
                                    $folder,
                                    $imap_error
                                );
                            }
                        }
                        break;
                    }

                    foreach ($emails as $mail) {
                        if ($emails_checked_in_rule >= $max_emails_per_rule) {
                            break 2;
                        }

                        $report['emails_checked']++;
                        $emails_checked_in_rule++;

                        if (!ahx_wp_mail_rule_matches($mail, $rule)) {
                            continue;
                        }

                        $uid = (int) ($mail['uid'] ?? 0);
                        if ($uid <= 0) {
                            continue;
                        }

                        $action = $rule['action'];
                        $result = true;
                        if ($action === 'mark_read') {
                            $result = $imap->mark_read($folder, $uid);
                        } elseif ($action === 'mark_unread') {
                            $result = $imap->mark_unread($folder, $uid);
                        } elseif ($action === 'delete') {
                            $result = $imap->delete_email($folder, $uid, (string) ($settings['trash_folder'] ?? ''));
                        } elseif ($action === 'move') {
                            $target_folder = (string) $rule['move_to_folder'];
                            if ($target_folder === '' || $target_folder === $folder) {
                                continue;
                            }
                            $result = $imap->move_email($folder, $uid, $target_folder);
                        } elseif ($action === 'archive') {
                            $year = (int) gmdate('Y');
                            $mail_date = (string) ($mail['date'] ?? '');

                            if (preg_match('/\b(\d{2})\.(\d{2})\.(\d{4})\b/', $mail_date, $dm)) {
                                $year = (int) $dm[3];
                            } elseif (preg_match('/\b((?:19|20)\d{2})\b/', $mail_date, $ym)) {
                                $year = (int) $ym[1];
                            }

                            if ($year < 1900 || $year > 3000) {
                                $year = (int) gmdate('Y');
                            }

                            $target_folder = 'Archives/' . $year;
                            if (strcasecmp($target_folder, $folder) === 0) {
                                continue;
                            }
                            $result = $imap->move_email($folder, $uid, $target_folder);
                        }

                        if (is_wp_error($result)) {
                            $report['errors'][] = sprintf('User %d / Konto %s / UID %d: %s', $user_id, $account_key, $uid, $result->get_error_message());
                        } else {
                            $report['actions_applied']++;
                        }
                    }

                    if (count($emails) < $per_page) {
                        break;
                    }

                    $page++;
                }
            }

            $imap->disconnect();
        }
    }

    delete_transient($lock_key);
    delete_transient($lock_timestamp_key);
    return $report;
}

/**
 * WP-Cron Handler für Regeln.
 */
function ahx_wp_mail_rules_cron_event_handler() {
    ahx_wp_mail_run_rules('wp-cron');
}
add_action('ahx_wp_mail_rules_cron_event', 'ahx_wp_mail_rules_cron_event_handler');

/**
 * AJAX: Regeln des aktuellen Benutzers laden.
 */
function ahx_wp_mail_ajax_get_rules() {
    check_ajax_referer('ahx_wp_mail_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Nicht eingeloggt.'), 403);
    }

    $rules = ahx_wp_mail_get_user_rules(get_current_user_id());
    wp_send_json_success(array('rules' => $rules));
}
add_action('wp_ajax_ahx_wp_mail_get_rules', 'ahx_wp_mail_ajax_get_rules');

/**
 * AJAX: Einzelne Regel fuer aktuellen Benutzer speichern (append).
 */
function ahx_wp_mail_ajax_add_rule() {
    check_ajax_referer('ahx_wp_mail_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Nicht eingeloggt.'), 403);
    }

    $raw_rule = isset($_POST['rule']) ? wp_unslash($_POST['rule']) : '';
    $decoded = json_decode((string) $raw_rule, true);
    if (!is_array($decoded)) {
        wp_send_json_error(array('message' => 'Ungültige Regel.'), 400);
    }

    $normalized = ahx_wp_mail_normalize_rules(array($decoded));
    if (empty($normalized)) {
        wp_send_json_error(array('message' => 'Regel unvollständig oder ungültig.'), 400);
    }

    $user_id = get_current_user_id();
    $rules = ahx_wp_mail_get_user_rules($user_id);
    $rules[] = $normalized[0];
    ahx_wp_mail_save_user_rules($user_id, $rules);

    wp_send_json_success(array('rules' => ahx_wp_mail_get_user_rules($user_id)));
}
add_action('wp_ajax_ahx_wp_mail_add_rule', 'ahx_wp_mail_ajax_add_rule');

/**
 * AJAX: Regel per Index loeschen.
 */
function ahx_wp_mail_ajax_delete_rule() {
    check_ajax_referer('ahx_wp_mail_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Nicht eingeloggt.'), 403);
    }

    $index = isset($_POST['index']) ? (int) $_POST['index'] : -1;
    if ($index < 0) {
        wp_send_json_error(array('message' => 'Ungültiger Index.'), 400);
    }

    $user_id = get_current_user_id();
    $rules = ahx_wp_mail_get_user_rules($user_id);
    if (!isset($rules[$index])) {
        wp_send_json_error(array('message' => 'Regel nicht gefunden.'), 404);
    }

    unset($rules[$index]);
    $rules = array_values($rules);
    ahx_wp_mail_save_user_rules($user_id, $rules);

    wp_send_json_success(array('rules' => $rules));
}
add_action('wp_ajax_ahx_wp_mail_delete_rule', 'ahx_wp_mail_ajax_delete_rule');

/**
 * Öffentlicher Webhook-Endpoint für externe Cron-Dienste.
 * Aufruf: /?ahx_wp_mail_cron_hook=1&token=...
 */
function ahx_wp_mail_rules_webhook_endpoint() {
    if (!isset($_GET['ahx_wp_mail_cron_hook'])) {
        return;
    }

    $token = isset($_REQUEST['token']) ? sanitize_text_field(wp_unslash($_REQUEST['token'])) : '';
    $expected = (string) get_option('ahx_wp_mail_rules_cron_token', '');

    if ($expected === '' || !hash_equals($expected, $token)) {
        status_header(403);
        wp_send_json_error(array('message' => 'Ungültiger Token.'), 403);
    }

    $report = ahx_wp_mail_run_rules('webhook');
    wp_send_json_success($report);
}
add_action('init', 'ahx_wp_mail_rules_webhook_endpoint');

/**
 * Beim Laden die Cron-Planung synchron halten.
 */
function ahx_wp_mail_rules_maybe_sync_schedule() {
    ahx_wp_mail_schedule_rules_cron();
}
add_action('init', 'ahx_wp_mail_rules_maybe_sync_schedule', 20);

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

    add_submenu_page(
        'ahx_wp_mail-main',
        'Regeln',
        'Regeln',
        'manage_options',
        'ahx_wp_mail-rules',
        'ahx_wp_mail_rules_page'
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

    $base_path = plugin_dir_path(__FILE__);
    $css_path  = $base_path . 'assets/mail.css';
    $js_path   = $base_path . 'assets/mail.js';
    $css_ver  = file_exists($css_path) ? (string) filemtime($css_path) : AHX_WP_MAIL_VERSION;
    $js_ver   = file_exists($js_path) ? (string) filemtime($js_path) : AHX_WP_MAIL_VERSION;

    wp_enqueue_style(
        'ahx-wp-mail-style',
        AHX_WP_MAIL_URL . 'assets/mail.css',
        array(),
        $css_ver
    );
    wp_enqueue_script(
        'ahx-wp-mail-script',
        AHX_WP_MAIL_URL . 'assets/mail.js',
        array('jquery'),
        $js_ver,
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

    if (!in_array($folder, $folders, true)) {
        $folders[] = $folder;
    }

    $folder_stats = $imap->get_folder_counters($folders);
    $current_folder_stats = isset($folder_stats[$folder]) && is_array($folder_stats[$folder])
        ? $folder_stats[$folder]
        : array('total' => 0, 'unread' => 0);

    $imap->disconnect();

    wp_send_json_success(array(
        'emails'  => $emails,
        'folders' => $folders,
        'folder_stats' => $folder_stats,
        'current_folder_stats' => $current_folder_stats,
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
// AJAX: E-Mail-Quelltext laden (raw RFC822)
// ---------------------------------------------------------------------------

function ahx_wp_mail_ajax_fetch_source() {
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
    $uid        = isset($_POST['uid']) ? (int) $_POST['uid'] : 0;

    if (empty($host) || $uid <= 0) {
        wp_send_json_error(array('message' => 'Ungültige Parameter.'), 400);
    }

    $imap   = new AHX_WP_Mail_IMAP($host, $port, $encryption);
    $result = $imap->connect($settings['imap_user'], $settings['imap_password']);

    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()), 500);
    }

    $source = $imap->get_raw_email_source($folder, $uid);
    $imap->disconnect();

    if (is_wp_error($source)) {
        wp_send_json_error(array('message' => $source->get_error_message()), 500);
    }

    wp_send_json_success(array(
        'uid'         => $uid,
        'folder'      => $folder,
        'source'      => (string) $source,
        'account_key' => $settings['account_key'],
    ));
}
add_action('wp_ajax_ahx_wp_mail_fetch_source', 'ahx_wp_mail_ajax_fetch_source');

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
// AJAX: E-Mails archivieren (Archives/<Jahr>)
// ---------------------------------------------------------------------------

function ahx_wp_mail_ajax_archive() {
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
    $uids       = isset($_POST['uids']) ? array_map('intval', (array) $_POST['uids']) : array();

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
        $email = $imap->get_email($folder, $uid, false, false, false);
        if (is_wp_error($email)) {
            $errors[] = $email->get_error_message();
            continue;
        }

        $mail_date = (string) ($email['date'] ?? '');
        $year = (int) gmdate('Y');
        if (preg_match('/\b(\d{2})\.(\d{2})\.(\d{4})\b/', $mail_date, $dm)) {
            $year = (int) $dm[3];
        } elseif (preg_match('/\b((?:19|20)\d{2})\b/', $mail_date, $ym)) {
            $year = (int) $ym[1];
        }
        if ($year < 1900 || $year > 3000) {
            $year = (int) gmdate('Y');
        }

        $target_folder = 'Archives/' . $year;
        if (strcasecmp($target_folder, $folder) === 0) {
            continue;
        }

        $r = $imap->move_email($folder, $uid, $target_folder);
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
add_action('wp_ajax_ahx_wp_mail_archive', 'ahx_wp_mail_ajax_archive');

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

function ahx_wp_mail_ajax_move_recommendations() {
    check_ajax_referer('ahx_wp_mail_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Nicht eingeloggt.'), 403);
    }

    $user_id = get_current_user_id();
    $account_key = isset($_POST['account_key']) ? sanitize_key(wp_unslash($_POST['account_key'])) : '';
    $folder = isset($_POST['folder']) ? sanitize_text_field(wp_unslash($_POST['folder'])) : 'INBOX';
    $sender_email = isset($_POST['sender_email']) ? sanitize_email(wp_unslash($_POST['sender_email'])) : '';

    if ($sender_email === '') {
        wp_send_json_success(array(
            'recommended' => array(),
            'folders' => array(),
        ));
    }

    $imap_settings = ahx_wp_mail_get_effective_imap_settings_for_user($user_id, $account_key);
    if (empty($imap_settings['imap_user']) || empty($imap_settings['imap_password'])) {
        wp_send_json_error(array('message' => 'Keine IMAP-Zugangsdaten hinterlegt.'), 422);
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
    $recommended = $imap->get_sender_folder_recommendations($folders, $sender_email, '', 8);
    $imap->disconnect();

    wp_send_json_success(array(
        'recommended' => is_array($recommended) ? $recommended : array(),
        'folders' => is_array($folders) ? array_values($folders) : array(),
    ));
}
add_action('wp_ajax_ahx_wp_mail_move_recommendations', 'ahx_wp_mail_ajax_move_recommendations');

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
