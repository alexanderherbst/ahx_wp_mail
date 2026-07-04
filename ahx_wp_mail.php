<?php
/**
 * Plugin Name: AHX WP Mail
 * Description: IMAP-Postfach-Viewer im Frontend mit benutzerspezifischen Zugangsdaten.
 * Version: v0.5.0
 * Author: Alexander Herbst
 * Author URI: https://familie-herbst.de/ahx
 * License: GPL2
 * Text Domain: ahx_wp_mail
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AHX_WP_MAIL_VERSION', 'v0.5.0');
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
 * Dekodiert Regel-Meta robust aus JSON/String/serialisiertem Array.
 *
 * @param mixed $raw
 * @return array
 */
function ahx_wp_mail_decode_rules_meta($raw) {
    if (is_array($raw)) {
        return $raw;
    }

    $raw_string = (string) $raw;
    $decoded = json_decode($raw_string, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    if ($raw_string !== '') {
        $maybe_unserialized = maybe_unserialize($raw);
        if (is_array($maybe_unserialized)) {
            return $maybe_unserialized;
        }
    }

    return array();
}

/**
 * Liefert benutzerspezifische Regeln.
 *
 * @param int $user_id
 * @return array[]
 */
function ahx_wp_mail_get_user_rules($user_id) {
    $raw = get_user_meta((int) $user_id, 'ahx_wp_mail_rules', true);
    $decoded = ahx_wp_mail_decode_rules_meta($raw);

    if (empty($decoded)) {
        return array();
    }

    // Legacy-Regeln ohne account_key auf das aktive Konto des Benutzers migrieren.
    $settings = AHX_WP_Mail_User_Settings::get((int) $user_id);
    $active_account_key = sanitize_key((string) ($settings['active_account_key'] ?? ''));
    if ($active_account_key === '' && !empty($settings['accounts']) && is_array($settings['accounts'])) {
        $first_account = reset($settings['accounts']);
        if (is_array($first_account)) {
            $active_account_key = sanitize_key((string) ($first_account['key'] ?? ''));
        }
    }
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
        ahx_wp_mail_save_user_rules((int) $user_id, $normalized);
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
    update_user_meta((int) $user_id, 'ahx_wp_mail_rules', $normalized);

    $verify_raw = get_user_meta((int) $user_id, 'ahx_wp_mail_rules', true);
    $verify_decoded = ahx_wp_mail_decode_rules_meta($verify_raw);
    if (!is_array($verify_decoded)) {
        return new WP_Error('rules_verify_failed', 'Regeln konnten nicht verifiziert werden.');
    }

    return true;
}

/**
 * Erzeugt eine Regel-ID.
 *
 * @return string
 */
function ahx_wp_mail_generate_rule_id() {
    if (function_exists('wp_generate_uuid4')) {
        return wp_generate_uuid4();
    }

    return uniqid('ahx_rule_', true);
}

/**
 * Normalisiert eine Bedingungsgruppe.
 *
 * @param array $group
 * @return array
 */
function ahx_wp_mail_normalize_rule_group($group) {
    if (!is_array($group)) {
        $group = array();
    }

    return array(
        'from_contains' => sanitize_text_field((string) ($group['from_contains'] ?? '')),
        'to_contains' => sanitize_text_field((string) ($group['to_contains'] ?? '')),
        'subject_contains' => sanitize_text_field((string) ($group['subject_contains'] ?? '')),
    );
}

/**
 * Prüft, ob eine Bedingungsgruppe mindestens einen Filter enthält.
 *
 * @param array $group
 * @return bool
 */
function ahx_wp_mail_rule_group_has_filters($group) {
    return trim((string) ($group['from_contains'] ?? '')) !== ''
        || trim((string) ($group['to_contains'] ?? '')) !== ''
        || trim((string) ($group['subject_contains'] ?? '')) !== '';
}

/**
 * Liefert die Label-Zusammenfassung einer Bedingungsgruppe.
 *
 * @param array $group
 * @return string
 */
function ahx_wp_mail_rule_group_label($group) {
    $parts = array();
    $from = trim((string) ($group['from_contains'] ?? ''));
    $to = trim((string) ($group['to_contains'] ?? ''));
    $subject = trim((string) ($group['subject_contains'] ?? ''));

    if ($from !== '') {
        $parts[] = 'Von enthält „' . $from . '“';
    }
    if ($to !== '') {
        $parts[] = 'An enthält „' . $to . '“';
    }
    if ($subject !== '') {
        $parts[] = 'Betreff enthält „' . $subject . '“';
    }

    return implode(' · ', $parts);
}

/**
 * Erzeugt eine Default-Beschreibung für eine Regel.
 *
 * @param array $rule
 * @return string
 */
function ahx_wp_mail_rule_default_name($rule) {
    $groups = isset($rule['match_any']) && is_array($rule['match_any']) ? $rule['match_any'] : array();
    $group_labels = array();
    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }
        $label = ahx_wp_mail_rule_group_label($group);
        if ($label !== '') {
            $group_labels[] = $label;
        }
    }

    if (empty($group_labels)) {
        $fallback = ahx_wp_mail_rule_group_label($rule);
        if ($fallback !== '') {
            $group_labels[] = $fallback;
        }
    }

    if (empty($group_labels)) {
        $group_labels[] = 'Unbenannte Regel';
    }

    $action = sanitize_key((string) ($rule['action'] ?? ''));
    $action_label = $action;
    if ($action === 'mark_read') {
        $action_label = 'als gelesen markieren';
    } elseif ($action === 'mark_unread') {
        $action_label = 'als ungelesen markieren';
    } elseif ($action === 'delete') {
        $action_label = 'löschen';
    } elseif ($action === 'move') {
        $action_label = 'verschieben';
    } elseif ($action === 'archive') {
        $action_label = 'archivieren';
    }

    return implode(' ODER ', $group_labels) . ' → ' . $action_label;
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

        $match_any = array();
        if (isset($rule['match_any']) && is_array($rule['match_any'])) {
            foreach ($rule['match_any'] as $group) {
                if (!is_array($group)) {
                    continue;
                }
                $normalized_group = ahx_wp_mail_normalize_rule_group($group);
                if (ahx_wp_mail_rule_group_has_filters($normalized_group)) {
                    $match_any[] = $normalized_group;
                }
            }
        }

        if (empty($match_any)) {
            $legacy_group = ahx_wp_mail_normalize_rule_group($rule);
            if (ahx_wp_mail_rule_group_has_filters($legacy_group)) {
                $match_any[] = $legacy_group;
            }
        }

        if (empty($match_any)) {
            continue;
        }

        $rule_id = sanitize_text_field((string) ($rule['id'] ?? ''));
        if ($rule_id === '') {
            $rule_id = ahx_wp_mail_generate_rule_id();
        }

        $item = array(
            'id' => $rule_id,
            'name' => sanitize_text_field((string) ($rule['name'] ?? '')),
            'enabled' => !isset($rule['enabled']) ? true : (bool) $rule['enabled'],
            'account_key' => sanitize_key((string) ($rule['account_key'] ?? '')),
            'folder' => sanitize_text_field((string) ($rule['folder'] ?? 'INBOX')),
            'from_contains' => sanitize_text_field((string) ($rule['from_contains'] ?? '')),
            'to_contains' => sanitize_text_field((string) ($rule['to_contains'] ?? '')),
            'subject_contains' => sanitize_text_field((string) ($rule['subject_contains'] ?? '')),
            'action' => $action,
            'move_to_folder' => sanitize_text_field((string) ($rule['move_to_folder'] ?? '')),
            'match_any' => $match_any,
            'match_count' => max(0, (int) ($rule['match_count'] ?? 0)),
            'handled_count' => max(0, (int) ($rule['handled_count'] ?? 0)),
            'last_matched_at' => sanitize_text_field((string) ($rule['last_matched_at'] ?? '')),
            'last_handled_at' => sanitize_text_field((string) ($rule['last_handled_at'] ?? '')),
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

        if (empty($item['name'])) {
            $item['name'] = ahx_wp_mail_rule_default_name($item);
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
function ahx_wp_mail_rule_group_matches($mail, $group) {
    $from = strtolower((string) ($mail['from'] ?? ''));
    $to = strtolower((string) ($mail['to'] ?? ''));
    $subject = strtolower((string) ($mail['subject'] ?? ''));

    $from_needle = strtolower((string) ($group['from_contains'] ?? ''));
    $to_needle = strtolower((string) ($group['to_contains'] ?? ''));
    $subject_needle = strtolower((string) ($group['subject_contains'] ?? ''));

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
 * Prüft, ob eine E-Mail auf eine Regel passt.
 *
 * @param array $mail
 * @param array $rule
 * @return bool
 */
function ahx_wp_mail_rule_matches($mail, $rule) {
    $groups = isset($rule['match_any']) && is_array($rule['match_any']) ? $rule['match_any'] : array();
    if (empty($groups)) {
        $groups = array($rule);
    }

    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }

        if (ahx_wp_mail_rule_group_matches($mail, $group)) {
            return true;
        }
    }

    return false;
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
    $lock_time = (int) get_transient($lock_timestamp_key);
    $current_time = time();
    $lock_age = $current_time - $lock_time;

    if (get_transient($lock_key) && $lock_age < 300) {
        $report['errors'][] = 'Runner bereits aktiv (seit ' . $lock_age . 's).';
        return $report;
    }

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

    try {
        $users = $user_query->get_results();
        foreach ($users as $user) {
            $user_id = (int) $user->ID;
            $report['users_checked']++;

            $rules = ahx_wp_mail_get_user_rules($user_id);
            $use_global_rules = false;
            if (empty($rules)) {
                $rules = $global_rules;
                $use_global_rules = true;
            }

            if (empty($rules)) {
                continue;
            }

            $raw_accounts = get_user_meta($user_id, AHX_WP_Mail_User_Settings::META_ACCOUNTS, true);
            $accounts = json_decode((string) $raw_accounts, true);
            if (!is_array($accounts) || empty($accounts)) {
                continue;
            }

            $rules_changed = false;

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

                foreach ($rules as $rule_index => $rule) {
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

                            $rules[$rule_index]['match_count'] = max(0, (int) ($rules[$rule_index]['match_count'] ?? 0)) + 1;
                            $rules[$rule_index]['last_matched_at'] = gmdate('c');
                            $rules_changed = true;

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
                                $rules[$rule_index]['handled_count'] = max(0, (int) ($rules[$rule_index]['handled_count'] ?? 0)) + 1;
                                $rules[$rule_index]['last_handled_at'] = gmdate('c');
                                $rules_changed = true;
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

            if ($rules_changed) {
                if ($use_global_rules) {
                    $global_rules = $rules;
                    update_option('ahx_wp_mail_rules', wp_json_encode($global_rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                } else {
                    ahx_wp_mail_save_user_rules($user_id, $rules);
                }
            }
        }
    } finally {
        delete_transient($lock_key);
        delete_transient($lock_timestamp_key);
    }

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
 * AJAX: Einzelne Regel für aktuellen Benutzer speichern oder aktualisieren.
 */
function ahx_wp_mail_ajax_add_rule() {
    check_ajax_referer('ahx_wp_mail_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Nicht eingeloggt.'), 403);
    }

    $user_id = get_current_user_id();

    $raw_rule = isset($_POST['rule']) ? wp_unslash($_POST['rule']) : '';
    $decoded = json_decode((string) $raw_rule, true);
    if (!is_array($decoded)) {
        wp_send_json_error(array('message' => 'Ungültige Regel.'), 400);
    }

    $incoming_account_key = sanitize_key((string) ($decoded['account_key'] ?? ''));
    if ($incoming_account_key === '') {
        $settings = AHX_WP_Mail_User_Settings::get($user_id);
        $fallback_account_key = sanitize_key((string) ($settings['active_account_key'] ?? ''));

        if ($fallback_account_key === '' && !empty($settings['accounts']) && is_array($settings['accounts'])) {
            $first_account = reset($settings['accounts']);
            if (is_array($first_account)) {
                $fallback_account_key = sanitize_key((string) ($first_account['key'] ?? ''));
            }
        }

        if ($fallback_account_key !== '') {
            $decoded['account_key'] = $fallback_account_key;
        }
    }

    $index = isset($_POST['index']) ? (int) $_POST['index'] : -1;
    $normalized = ahx_wp_mail_normalize_rules(array($decoded));
    if (empty($normalized)) {
        wp_send_json_error(array('message' => 'Regel unvollständig oder ungültig (Konto/Bedingungen/Aktion prüfen).'), 400);
    }

    $rules = ahx_wp_mail_get_user_rules($user_id);
    $is_update = false;
    $merged_rule = $normalized[0];
    $incoming_id = sanitize_text_field((string) ($merged_rule['id'] ?? ''));

    if ($index >= 0 && isset($rules[$index])) {
        $existing_rule = is_array($rules[$index]) ? $rules[$index] : array();
        $existing_id = sanitize_text_field((string) ($existing_rule['id'] ?? ''));

        if ($existing_id !== '' && $incoming_id !== '' && $existing_id === $incoming_id) {
            $merged_rule = array_merge($existing_rule, $merged_rule);
            if (empty($merged_rule['id'])) {
                $merged_rule['id'] = $existing_id !== '' ? $existing_id : ahx_wp_mail_generate_rule_id();
            }
            $merged_rule['match_count'] = max(0, (int) ($existing_rule['match_count'] ?? 0));
            $merged_rule['handled_count'] = max(0, (int) ($existing_rule['handled_count'] ?? 0));
            $merged_rule['last_matched_at'] = (string) ($existing_rule['last_matched_at'] ?? '');
            $merged_rule['last_handled_at'] = (string) ($existing_rule['last_handled_at'] ?? '');
            $rules[$index] = $merged_rule;
            $is_update = true;
        } else {
            $rules[] = $merged_rule;
        }
    } else {
        $rules[] = $merged_rule;
    }

    $save_result = ahx_wp_mail_save_user_rules($user_id, $rules);
    if (is_wp_error($save_result)) {
        wp_send_json_error(array('message' => $save_result->get_error_message()), 500);
    }

    $saved_rules = ahx_wp_mail_get_user_rules($user_id);
    $saved_id = sanitize_text_field((string) ($merged_rule['id'] ?? ''));
    $found_saved_id = false;
    if ($saved_id !== '') {
        foreach ($saved_rules as $saved_rule) {
            if (!is_array($saved_rule)) {
                continue;
            }
            if (sanitize_text_field((string) ($saved_rule['id'] ?? '')) === $saved_id) {
                $found_saved_id = true;
                break;
            }
        }
    }

    if (!$found_saved_id) {
        wp_send_json_error(array('message' => 'Regel konnte nicht gespeichert werden.'), 500);
    }

    wp_send_json_success(array('rules' => $saved_rules));
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

/**
 * Liefert eine kompakte Scope-ID fuer benutzer-/kontospezifische Caches.
 *
 * @param int    $user_id
 * @param string $account_key
 * @return string
 */
function ahx_wp_mail_cache_scope($user_id, $account_key) {
    return (string) ((int) $user_id) . ':' . sanitize_key((string) $account_key);
}

/**
 * Liefert die aktuelle Cache-Version fuer einen Scope.
 *
 * @param int    $user_id
 * @param string $account_key
 * @return int
 */
function ahx_wp_mail_get_cache_version($user_id, $account_key) {
    $scope = ahx_wp_mail_cache_scope($user_id, $account_key);
    $key = 'ahx_wp_mail_cache_v_' . md5($scope);
    $version = (int) get_transient($key);
    return $version > 0 ? $version : 1;
}

/**
 * Erhoeht die Cache-Version eines Scopes, um alle Schluessel logisch zu invalidieren.
 *
 * @param int    $user_id
 * @param string $account_key
 * @return int
 */
function ahx_wp_mail_bump_cache_version($user_id, $account_key) {
    $scope = ahx_wp_mail_cache_scope($user_id, $account_key);
    $key = 'ahx_wp_mail_cache_v_' . md5($scope);
    $version = ahx_wp_mail_get_cache_version($user_id, $account_key) + 1;
    set_transient($key, $version, DAY_IN_SECONDS * 7);
    return $version;
}

/**
 * Erstellt einen stabilen Cache-Key fuer Mail-AJAX-Payloads.
 *
 * @param int    $user_id
 * @param string $account_key
 * @param string $bucket
 * @param array  $parts
 * @return string
 */
function ahx_wp_mail_cache_key($user_id, $account_key, $bucket, $parts = array()) {
    $version = ahx_wp_mail_get_cache_version($user_id, $account_key);
    $payload = wp_json_encode(array(
        'u' => (int) $user_id,
        'a' => sanitize_key((string) $account_key),
        'b' => (string) $bucket,
        'v' => $version,
        'p' => is_array($parts) ? $parts : array(),
    ));
    return 'ahx_wp_mail_' . md5((string) $payload);
}

/**
 * Liefert eine passende TTL fuer Listen-Caches je nach Ordner und Seitentiefe.
 *
 * @param string $folder
 * @param int    $page
 * @param int    $total_messages
 * @return int
 */
function ahx_wp_mail_get_list_cache_ttl($folder, $page, $total_messages = 0) {
    $folder = strtolower(trim((string) $folder));
    $page = max(1, (int) $page);
    $total_messages = max(0, (int) $total_messages);

    $volume_bonus = 0;
    if ($total_messages >= 5000) {
        $volume_bonus = 90;
    } elseif ($total_messages >= 1000) {
        $volume_bonus = 45;
    } elseif ($total_messages >= 250) {
        $volume_bonus = 15;
    }

    if ($folder === 'inbox') {
        return $page === 1 ? 10 : (($page <= 3 ? 20 : 45) + min(30, $volume_bonus));
    }

    if (strpos($folder, 'archive') === 0 || strpos($folder, 'archives/') === 0) {
        return ($page === 1 ? 60 : 180) + $volume_bonus;
    }

    if (preg_match('/trash|papierkorb|spam|junk/i', $folder)) {
        return ($page === 1 ? 30 : 90) + min(60, $volume_bonus);
    }

    return ($page === 1 ? 20 : 60) + min(45, $volume_bonus);
}

/**
 * Liefert eine passende TTL fuer Ordnerzaehler.
 *
 * @param string $folder
 * @return int
 */
function ahx_wp_mail_get_folder_stats_cache_ttl($folder) {
    $folder = strtolower(trim((string) $folder));

    if ($folder === 'inbox') {
        return 12;
    }

    if (strpos($folder, 'archive') === 0 || strpos($folder, 'archives/') === 0) {
        return 120;
    }

    if (preg_match('/trash|papierkorb|spam|junk/i', $folder)) {
        return 45;
    }

    return 30;
}

/**
 * Liefert eine passende TTL fuer Attachment-Flags.
 *
 * @param string $folder
 * @return int
 */
function ahx_wp_mail_get_attachment_flag_cache_ttl($folder) {
    $folder = strtolower(trim((string) $folder));

    if (strpos($folder, 'archive') === 0 || strpos($folder, 'archives/') === 0) {
        return 1800;
    }

    return 600;
}

/**
 * Liefert einen Cache-Key fuer die Statistiken eines einzelnen Ordners.
 *
 * @param int    $user_id
 * @param string $account_key
 * @param string $folder
 * @return string
 */
function ahx_wp_mail_get_single_folder_stats_cache_key($user_id, $account_key, $folder) {
    return ahx_wp_mail_cache_key($user_id, $account_key, 'folder_stats_single', array(
        'folder' => (string) $folder,
    ));
}

/**
 * Liefert priorisierte Ordner fuer den initialen Stats-Load.
 *
 * @param string[] $folders
 * @param string   $current_folder
 * @param int      $limit
 * @return string[]
 */
function ahx_wp_mail_get_prioritized_stats_folders($folders, $current_folder, $limit = 10) {
    if (!is_array($folders) || empty($folders)) {
        return array();
    }

    $limit = max(1, (int) $limit);
    $current_folder = (string) $current_folder;
    $priority_terms = array('INBOX', 'Sent', 'Drafts', 'Trash', 'Spam', 'Archive', 'Archives');

    $scored = array();
    foreach ($folders as $folder) {
        $folder = (string) $folder;
        if ($folder === '') {
            continue;
        }

        $score = 0;
        if (strcasecmp($folder, $current_folder) === 0) {
            $score += 1000;
        }
        if (strcasecmp($folder, 'INBOX') === 0) {
            $score += 800;
        }

        foreach ($priority_terms as $term) {
            if (stripos($folder, $term) !== false) {
                $score += 120;
                break;
            }
        }

        $depth = substr_count(str_replace('\\', '/', $folder), '/');
        $score -= min(50, $depth * 5);

        $scored[] = array('folder' => $folder, 'score' => $score);
    }

    usort($scored, static function ($a, $b) {
        $sa = (int) ($a['score'] ?? 0);
        $sb = (int) ($b['score'] ?? 0);
        if ($sa === $sb) {
            return strcasecmp((string) ($a['folder'] ?? ''), (string) ($b['folder'] ?? ''));
        }
        return ($sb <=> $sa);
    });

    $result = array();
    foreach ($scored as $row) {
        $folder = (string) ($row['folder'] ?? '');
        if ($folder === '') {
            continue;
        }
        $result[] = $folder;
        if (count($result) >= $limit) {
            break;
        }
    }

    return $result;
}

/**
 * Liefert eine passende TTL fuer den Detail-Cache.
 *
 * @param string $folder
 * @return int
 */
function ahx_wp_mail_get_detail_cache_ttl($folder) {
    $folder = strtolower(trim((string) $folder));

    if ($folder === 'inbox') {
        return 120;
    }
    if (strpos($folder, 'archive') === 0 || strpos($folder, 'archives/') === 0) {
        return 1800;
    }
    return 600;
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

    $effective_account_key = (string) $settings['account_key'];
    $list_cache_key = ahx_wp_mail_cache_key($user_id, $effective_account_key, 'list', array(
        'folder' => $folder,
        'page' => $page,
        'per_page' => $per_page,
    ));
    $cached_list = get_transient($list_cache_key);

    if (is_array($cached_list) && isset($cached_list['emails'])) {
        wp_send_json_success(array(
            'emails'  => is_array($cached_list['emails']) ? $cached_list['emails'] : array(),
            'folders' => isset($cached_list['folders']) && is_array($cached_list['folders']) ? $cached_list['folders'] : array(),
            'folder_stats' => isset($cached_list['folder_stats']) && is_array($cached_list['folder_stats']) ? $cached_list['folder_stats'] : array(),
            'current_folder_stats' => isset($cached_list['current_folder_stats']) && is_array($cached_list['current_folder_stats']) ? $cached_list['current_folder_stats'] : array('total' => 0, 'unread' => 0),
            'deferred_stats_folders' => isset($cached_list['deferred_stats_folders']) && is_array($cached_list['deferred_stats_folders']) ? $cached_list['deferred_stats_folders'] : array(),
            'attachment_flags' => isset($cached_list['attachment_flags']) && is_array($cached_list['attachment_flags']) ? $cached_list['attachment_flags'] : array(),
            'page'    => $page,
            'account_key' => $effective_account_key,
            'cached' => true,
        ));
    }

    $imap = new AHX_WP_Mail_IMAP($host, $port, $encryption);
    $result = $imap->connect($settings['imap_user'], $settings['imap_password']);

    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()), 500);
    }

    // Fast-Path: keine Attachment-Analyse und keine Header-Vertiefung in der Listenansicht.
    $emails = $imap->get_emails($folder, $page, $per_page, false, false);

    $folders_cache_key = ahx_wp_mail_cache_key($user_id, $effective_account_key, 'folders', array('host' => $host));
    $folders = get_transient($folders_cache_key);
    $folder_stats = array();

    if (!is_array($folders) || empty($folders)) {
        $folders = $imap->get_folders();
        if (!is_array($folders)) {
            $folders = array();
        }
        set_transient($folders_cache_key, $folders, 600);
    }

    if (!in_array($folder, $folders, true)) {
        $folders[] = $folder;
    }

    $folders_to_fetch = array();
    foreach ($folders as $folder_name) {
        $folder_name = (string) $folder_name;
        if ($folder_name === '') {
            continue;
        }

        $single_stats_key = ahx_wp_mail_get_single_folder_stats_cache_key($user_id, $effective_account_key, $folder_name);
        $cached_stats = get_transient($single_stats_key);
        if (is_array($cached_stats) && isset($cached_stats['total']) && isset($cached_stats['unread'])) {
            $folder_stats[$folder_name] = array(
                'total' => max(0, (int) $cached_stats['total']),
                'unread' => max(0, (int) $cached_stats['unread']),
            );
            continue;
        }

        $folders_to_fetch[] = $folder_name;
    }

    $prioritized = ahx_wp_mail_get_prioritized_stats_folders($folders_to_fetch, $folder, 12);
    $prioritized_set = array_fill_keys($prioritized, 1);
    $deferred_stats_folders = array();

    foreach ($folders_to_fetch as $folder_name) {
        if (!isset($prioritized_set[$folder_name])) {
            $deferred_stats_folders[] = (string) $folder_name;
        }
    }

    if (!empty($prioritized)) {
        $fetched_stats = $imap->get_folder_counters($prioritized);
        if (!is_array($fetched_stats)) {
            $fetched_stats = array();
        }

        foreach ($prioritized as $folder_name) {
            $folder_name = (string) $folder_name;
            $folder_stat = isset($fetched_stats[$folder_name]) && is_array($fetched_stats[$folder_name])
                ? $fetched_stats[$folder_name]
                : array('total' => 0, 'unread' => 0);

            $folder_stats[$folder_name] = array(
                'total' => max(0, (int) ($folder_stat['total'] ?? 0)),
                'unread' => max(0, (int) ($folder_stat['unread'] ?? 0)),
            );

            set_transient(
                ahx_wp_mail_get_single_folder_stats_cache_key($user_id, $effective_account_key, $folder_name),
                $folder_stats[$folder_name],
                ahx_wp_mail_get_folder_stats_cache_ttl($folder_name)
            );
        }
    }

    $current_folder_stats = isset($folder_stats[$folder]) && is_array($folder_stats[$folder])
        ? $folder_stats[$folder]
        : array('total' => 0, 'unread' => 0);

    $current_total = (int) ($current_folder_stats['total'] ?? 0);
    if ($current_total <= 0 && !empty($emails) && isset($emails[0]['total'])) {
        $current_total = max(0, (int) $emails[0]['total']);
    }
    $list_cache_ttl = ahx_wp_mail_get_list_cache_ttl($folder, $page, $current_total);

    $attachment_flags = array();
    if ($page === 1 && !empty($emails)) {
        $uids = array();
        foreach ($emails as $email_row) {
            $uid = isset($email_row['uid']) ? (int) $email_row['uid'] : 0;
            if ($uid > 0) {
                $uids[] = $uid;
            }
        }

        if (!empty($uids)) {
            $uids = array_values(array_unique($uids));
            if (count($uids) > 100) {
                $uids = array_slice($uids, 0, 100);
            }

            $att_key = ahx_wp_mail_cache_key($user_id, $effective_account_key, 'att_flags', array(
                'folder' => $folder,
                'uids' => $uids,
            ));
            $cached_att = get_transient($att_key);
            if (is_array($cached_att)) {
                $attachment_flags = $cached_att;
            } else {
                $attachment_flags = $imap->get_attachment_flags_for_uids($folder, $uids);
                if (!is_array($attachment_flags)) {
                    $attachment_flags = array();
                }
                set_transient($att_key, $attachment_flags, ahx_wp_mail_get_attachment_flag_cache_ttl($folder));
            }
        }
    }

    $imap->disconnect();

    $payload = array(
        'emails'  => is_array($emails) ? $emails : array(),
        'folders' => $folders,
        'folder_stats' => $folder_stats,
        'current_folder_stats' => $current_folder_stats,
        'deferred_stats_folders' => $deferred_stats_folders,
        'attachment_flags' => $attachment_flags,
    );
    set_transient($list_cache_key, $payload, $list_cache_ttl);

    wp_send_json_success(array(
        'emails'  => $payload['emails'],
        'folders' => $payload['folders'],
        'folder_stats' => $payload['folder_stats'],
        'current_folder_stats' => $payload['current_folder_stats'],
        'deferred_stats_folders' => $payload['deferred_stats_folders'],
        'attachment_flags' => $payload['attachment_flags'],
        'page'    => $page,
        'account_key' => $effective_account_key,
        'cached' => false,
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

    $detail_cache_key = ahx_wp_mail_cache_key($user_id, (string) $settings['account_key'], 'email_detail', array(
        'folder' => $folder,
        'uid' => $uid,
        'allow_images' => $allow_images ? 1 : 0,
    ));

    $email = (!$debug_mode) ? get_transient($detail_cache_key) : false;
    if (!is_array($email)) {
        $email = $imap->get_email($folder, $uid, $allow_images, $mark_as_read, $debug_mode);
        if (!is_wp_error($email) && !$debug_mode) {
            set_transient($detail_cache_key, $email, ahx_wp_mail_get_detail_cache_ttl($folder));
        }
    } elseif ($mark_as_read) {
        $imap->mark_read($folder, $uid);
    }

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

    ahx_wp_mail_bump_cache_version($user_id, (string) $settings['account_key']);

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

    ahx_wp_mail_bump_cache_version($user_id, (string) $settings['account_key']);

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

    ahx_wp_mail_bump_cache_version($user_id, (string) $settings['account_key']);

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

    ahx_wp_mail_bump_cache_version($user_id, (string) $settings['account_key']);

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

    ahx_wp_mail_bump_cache_version($user_id, (string) $settings['account_key']);

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

function ahx_wp_mail_ajax_fetch_folder_stats() {
    check_ajax_referer('ahx_wp_mail_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Nicht eingeloggt.'), 403);
    }

    $user_id = get_current_user_id();
    $account_key = isset($_POST['account_key']) ? sanitize_key(wp_unslash($_POST['account_key'])) : '';
    $folders_raw = isset($_POST['folders']) ? (array) $_POST['folders'] : array();
    $folders = array_values(array_filter(array_map(static function ($folder) {
        return sanitize_text_field(wp_unslash((string) $folder));
    }, $folders_raw), static function ($folder) {
        return $folder !== '';
    }));

    if (empty($folders)) {
        wp_send_json_success(array('folder_stats' => array()));
    }

    if (count($folders) > 50) {
        $folders = array_slice($folders, 0, 50);
    }

    $imap_settings = ahx_wp_mail_get_effective_imap_settings_for_user($user_id, $account_key);
    if (empty($imap_settings['imap_user']) || empty($imap_settings['imap_password'])) {
        wp_send_json_error(array('message' => 'Keine IMAP-Zugangsdaten hinterlegt.'), 422);
    }
    if ($imap_settings['host'] === '') {
        wp_send_json_error(array('message' => 'IMAP-Server nicht konfiguriert.'), 500);
    }

    $effective_account_key = (string) $imap_settings['account_key'];
    $folder_stats = array();
    $missing = array();

    foreach ($folders as $folder_name) {
        $cache_key = ahx_wp_mail_get_single_folder_stats_cache_key($user_id, $effective_account_key, $folder_name);
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['total']) && isset($cached['unread'])) {
            $folder_stats[$folder_name] = array(
                'total' => max(0, (int) $cached['total']),
                'unread' => max(0, (int) $cached['unread']),
            );
        } else {
            $missing[] = $folder_name;
        }
    }

    if (!empty($missing)) {
        $imap = new AHX_WP_Mail_IMAP($imap_settings['host'], $imap_settings['port'], $imap_settings['encryption']);
        $result = $imap->connect($imap_settings['imap_user'], $imap_settings['imap_password']);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), 500);
        }

        $fetched = $imap->get_folder_counters($missing);
        $imap->disconnect();
        if (!is_array($fetched)) {
            $fetched = array();
        }

        foreach ($missing as $folder_name) {
            $stat = isset($fetched[$folder_name]) && is_array($fetched[$folder_name])
                ? $fetched[$folder_name]
                : array('total' => 0, 'unread' => 0);

            $folder_stats[$folder_name] = array(
                'total' => max(0, (int) ($stat['total'] ?? 0)),
                'unread' => max(0, (int) ($stat['unread'] ?? 0)),
            );

            set_transient(
                ahx_wp_mail_get_single_folder_stats_cache_key($user_id, $effective_account_key, $folder_name),
                $folder_stats[$folder_name],
                ahx_wp_mail_get_folder_stats_cache_ttl($folder_name)
            );
        }
    }

    wp_send_json_success(array('folder_stats' => $folder_stats));
}
add_action('wp_ajax_ahx_wp_mail_fetch_folder_stats', 'ahx_wp_mail_ajax_fetch_folder_stats');

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
// AJAX: Attachment-Flags fuer Listenbatch nachladen
// ---------------------------------------------------------------------------

function ahx_wp_mail_ajax_fetch_attachment_flags() {
    check_ajax_referer('ahx_wp_mail_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Nicht eingeloggt.'), 403);
    }

    $user_id = get_current_user_id();
    $account_key = isset($_POST['account_key']) ? sanitize_key(wp_unslash($_POST['account_key'])) : '';
    $folder = isset($_POST['folder']) ? sanitize_text_field(wp_unslash($_POST['folder'])) : 'INBOX';
    $uids_raw = isset($_POST['uids']) ? (array) $_POST['uids'] : array();
    $uids = array_values(array_filter(array_map('intval', $uids_raw), static function ($uid) {
        return $uid > 0;
    }));

    if (empty($uids)) {
        wp_send_json_success(array('flags' => array()));
    }

    if (count($uids) > 200) {
        $uids = array_slice($uids, 0, 200);
    }

    $imap_settings = ahx_wp_mail_get_effective_imap_settings_for_user($user_id, $account_key);
    if (empty($imap_settings['imap_user']) || empty($imap_settings['imap_password'])) {
        wp_send_json_error(array('message' => 'Keine IMAP-Zugangsdaten hinterlegt.'), 422);
    }
    if ($imap_settings['host'] === '') {
        wp_send_json_error(array('message' => 'IMAP-Server nicht konfiguriert.'), 500);
    }

    $cache_key = ahx_wp_mail_cache_key($user_id, (string) $imap_settings['account_key'], 'att_flags', array(
        'folder' => $folder,
        'uids' => $uids,
    ));
    $cached_flags = get_transient($cache_key);
    if (is_array($cached_flags)) {
        wp_send_json_success(array(
            'flags' => $cached_flags,
            'cached' => true,
        ));
    }

    $imap = new AHX_WP_Mail_IMAP($imap_settings['host'], $imap_settings['port'], $imap_settings['encryption']);
    $result = $imap->connect($imap_settings['imap_user'], $imap_settings['imap_password']);
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()), 500);
    }

    $flags = $imap->get_attachment_flags_for_uids($folder, $uids);
    $imap->disconnect();

    if (!is_array($flags)) {
        $flags = array();
    }

    set_transient($cache_key, $flags, ahx_wp_mail_get_attachment_flag_cache_ttl($folder));

    wp_send_json_success(array(
        'flags' => $flags,
        'cached' => false,
    ));
}
add_action('wp_ajax_ahx_wp_mail_fetch_attachment_flags', 'ahx_wp_mail_ajax_fetch_attachment_flags');

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
