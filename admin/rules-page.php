<?php

if (!defined('ABSPATH')) {
    exit;
}

function ahx_wp_mail_rules_page() {
    if (isset($_POST['ahx_wp_mail_rules_save'])) {
        check_admin_referer('ahx_wp_mail_rules_save');

        $rules_json_raw = isset($_POST['ahx_wp_mail_rules']) ? wp_unslash($_POST['ahx_wp_mail_rules']) : '[]';
        $rules_decoded = json_decode((string) $rules_json_raw, true);
        if (!is_array($rules_decoded)) {
            $rules_decoded = array();
        }
        $rules_normalized = ahx_wp_mail_normalize_rules($rules_decoded);
        update_option('ahx_wp_mail_rules', wp_json_encode($rules_normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $cron_enabled = !empty($_POST['ahx_wp_mail_rules_cron_enabled']) ? '1' : '0';
        $cron_interval = max(1, (int) ($_POST['ahx_wp_mail_rules_cron_interval_minutes'] ?? 15));
        update_option('ahx_wp_mail_rules_cron_enabled', $cron_enabled);
        update_option('ahx_wp_mail_rules_cron_interval_minutes', (string) $cron_interval);

        if (!empty($_POST['ahx_wp_mail_rules_regenerate_token'])) {
            update_option('ahx_wp_mail_rules_cron_token', wp_generate_password(40, false, false));
        }

        ahx_wp_mail_schedule_rules_cron();

        echo '<div class="notice notice-success"><p>' . esc_html__('Regeln gespeichert.', 'ahx_wp_mail') . '</p></div>';
    }

    if (isset($_POST['ahx_wp_mail_rules_run_now'])) {
        check_admin_referer('ahx_wp_mail_rules_save');
        set_time_limit(300);
        $report = ahx_wp_mail_run_rules('manual');
        $error_display = '';
        if (!empty($report['errors'])) {
            $error_sample = implode('; ', array_slice($report['errors'], 0, 5));
            $error_display = '<br><small style="color:#666;">' . esc_html($error_sample) . '</small>';
        }
        echo '<div class="notice notice-info"><p>'
            . esc_html(sprintf(
                'Regeln ausgeführt: %d Aktion(en), %d E-Mails geprüft, %d Fehler.',
                (int) ($report['actions_applied'] ?? 0),
                (int) ($report['emails_checked'] ?? 0),
                count((array) ($report['errors'] ?? array()))
            ))
            . $error_display
            . '</p></div>';
    }

    $rules = get_option('ahx_wp_mail_rules', '[]');
    $rules_pretty = wp_json_encode(json_decode((string) $rules, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($rules_pretty) || $rules_pretty === '') {
        $rules_pretty = '[]';
    }

    $rules_cron_enabled = get_option('ahx_wp_mail_rules_cron_enabled', '0') === '1';
    $rules_cron_interval = (int) get_option('ahx_wp_mail_rules_cron_interval_minutes', 15);
    $rules_cron_token = (string) get_option('ahx_wp_mail_rules_cron_token', '');
    if ($rules_cron_token === '') {
        $rules_cron_token = wp_generate_password(40, false, false);
        update_option('ahx_wp_mail_rules_cron_token', $rules_cron_token);
    }

    $hook_url = add_query_arg(array(
        'ahx_wp_mail_cron_hook' => '1',
        'token' => $rules_cron_token,
    ), home_url('/'));
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('AHX WP Mail - Regeln', 'ahx_wp_mail'); ?></h1>

        <form method="post">
            <?php wp_nonce_field('ahx_wp_mail_rules_save'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="ahx_wp_mail_rules"><?php esc_html_e('E-Mail-Regeln (JSON)', 'ahx_wp_mail'); ?></label></th>
                    <td>
                        <textarea id="ahx_wp_mail_rules" name="ahx_wp_mail_rules" rows="12" class="large-text code"><?php echo esc_textarea($rules_pretty); ?></textarea>
                        <p class="description">
                            <?php esc_html_e('Regelform: enabled, account_key, folder, from_contains, to_contains, subject_contains, action(mark_read|mark_unread|delete|move|archive), move_to_folder.', 'ahx_wp_mail'); ?>
                        </p>
                        <p class="description"><strong>Beispiel:</strong></p>
                        <pre class="code" style="padding:8px;background:#f6f7f7;">[
  {
    "enabled": true,
    "account_key": "alexander_familie_herbst_net",
    "folder": "INBOX",
    "from_contains": "newsletter@",
    "to_contains": "",
    "subject_contains": "",
    "action": "move",
    "move_to_folder": "Archive"
  }
]</pre>
                    </td>
                </tr>

                <tr>
                    <th><?php esc_html_e('Regeln automatisch per WP-Cron', 'ahx_wp_mail'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="ahx_wp_mail_rules_cron_enabled" value="1" <?php checked($rules_cron_enabled); ?> />
                            <?php esc_html_e('Aktivieren', 'ahx_wp_mail'); ?>
                        </label>
                        <p>
                            <label for="ahx_wp_mail_rules_cron_interval_minutes"><?php esc_html_e('Intervall (Minuten):', 'ahx_wp_mail'); ?></label>
                            <input type="number" min="1" id="ahx_wp_mail_rules_cron_interval_minutes" name="ahx_wp_mail_rules_cron_interval_minutes" value="<?php echo esc_attr((string) $rules_cron_interval); ?>" class="small-text" />
                        </p>
                    </td>
                </tr>

                <tr>
                    <th><?php esc_html_e('Externer Cron-Hook (cron-job.org)', 'ahx_wp_mail'); ?></th>
                    <td>
                        <input type="text" readonly class="large-text code" value="<?php echo esc_attr($hook_url); ?>" />
                        <p>
                            <label>
                                <input type="checkbox" name="ahx_wp_mail_rules_regenerate_token" value="1" />
                                <?php esc_html_e('Token neu erzeugen (alte URL wird ungültig)', 'ahx_wp_mail'); ?>
                            </label>
                        </p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="ahx_wp_mail_rules_save" class="button button-primary">
                    <?php esc_html_e('Regeln speichern', 'ahx_wp_mail'); ?>
                </button>
                <button type="submit" name="ahx_wp_mail_rules_run_now" class="button">
                    <?php esc_html_e('Regeln jetzt manuell ausführen', 'ahx_wp_mail'); ?>
                </button>
            </p>
        </form>
    </div>
    <?php
}
