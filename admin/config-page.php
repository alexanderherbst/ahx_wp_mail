<?php

if (!defined('ABSPATH')) {
    exit;
}

function ahx_wp_mail_config_page() {
    if (isset($_POST['ahx_wp_mail_config_save'])) {
        check_admin_referer('ahx_wp_mail_config_save');

        $mark_mode = sanitize_text_field(wp_unslash($_POST['ahx_wp_mail_mark_read_mode'] ?? 'open'));
        if (!in_array($mark_mode, array('open', 'manual', 'never'), true)) {
            $mark_mode = 'open';
        }

        update_option('ahx_wp_mail_imap_host',       sanitize_text_field(wp_unslash($_POST['ahx_wp_mail_imap_host'] ?? '')));
        update_option('ahx_wp_mail_imap_port',       (int) ($_POST['ahx_wp_mail_imap_port'] ?? 993));
        update_option('ahx_wp_mail_imap_encryption', sanitize_text_field(wp_unslash($_POST['ahx_wp_mail_imap_encryption'] ?? 'ssl')));
        update_option('ahx_wp_mail_emails_per_page', max(1, (int) ($_POST['ahx_wp_mail_emails_per_page'] ?? 20)));
        update_option('ahx_wp_mail_mark_read_mode',  $mark_mode);

        $folder_aliases_input = isset($_POST['ahx_wp_mail_folder_aliases']) ? wp_unslash($_POST['ahx_wp_mail_folder_aliases']) : '';
        $folder_aliases_decoded = json_decode((string) $folder_aliases_input, true);
        if (is_array($folder_aliases_decoded)) {
            update_option('ahx_wp_mail_folder_aliases', wp_json_encode($folder_aliases_decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        echo '<div class="notice notice-success"><p>' . esc_html__('Einstellungen gespeichert.', 'ahx_wp_mail') . '</p></div>';
    }

    $host       = get_option('ahx_wp_mail_imap_host', '');
    $port       = get_option('ahx_wp_mail_imap_port', '993');
    $encryption = get_option('ahx_wp_mail_imap_encryption', 'ssl');
    $per_page   = get_option('ahx_wp_mail_emails_per_page', '20');
    $mark_mode  = get_option('ahx_wp_mail_mark_read_mode', 'open');
    $aliases    = wp_json_encode(ahx_wp_mail_get_folder_aliases(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('AHX WP Mail – Einstellungen', 'ahx_wp_mail'); ?></h1>
        <form method="post">
            <?php wp_nonce_field('ahx_wp_mail_config_save'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="ahx_wp_mail_imap_host"><?php esc_html_e('IMAP-Server (Host)', 'ahx_wp_mail'); ?></label></th>
                    <td>
                        <input type="text"
                               id="ahx_wp_mail_imap_host"
                               name="ahx_wp_mail_imap_host"
                               value="<?php echo esc_attr($host); ?>"
                               class="regular-text"
                               placeholder="imap.example.com" />
                        <p class="description"><?php esc_html_e('Hostname des IMAP-Servers (gilt für alle Benutzer).', 'ahx_wp_mail'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="ahx_wp_mail_imap_port"><?php esc_html_e('IMAP-Port', 'ahx_wp_mail'); ?></label></th>
                    <td>
                        <input type="number"
                               id="ahx_wp_mail_imap_port"
                               name="ahx_wp_mail_imap_port"
                               value="<?php echo esc_attr($port); ?>"
                               class="small-text"
                               min="1" max="65535" />
                        <p class="description"><?php esc_html_e('Standard: 993 (SSL) oder 143 (STARTTLS/keine Verschlüsselung).', 'ahx_wp_mail'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="ahx_wp_mail_imap_encryption"><?php esc_html_e('Verschlüsselung', 'ahx_wp_mail'); ?></label></th>
                    <td>
                        <select id="ahx_wp_mail_imap_encryption" name="ahx_wp_mail_imap_encryption">
                            <option value="ssl"  <?php selected($encryption, 'ssl'); ?>>SSL/TLS (Port 993)</option>
                            <option value="tls"  <?php selected($encryption, 'tls'); ?>>STARTTLS (Port 143)</option>
                            <option value="none" <?php selected($encryption, 'none'); ?>><?php esc_html_e('Keine (nicht empfohlen)', 'ahx_wp_mail'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="ahx_wp_mail_emails_per_page"><?php esc_html_e('E-Mails pro Seite', 'ahx_wp_mail'); ?></label></th>
                    <td>
                        <input type="number"
                               id="ahx_wp_mail_emails_per_page"
                               name="ahx_wp_mail_emails_per_page"
                               value="<?php echo esc_attr($per_page); ?>"
                               class="small-text"
                               min="1" max="200" />
                    </td>
                </tr>
                <tr>
                    <th><label for="ahx_wp_mail_mark_read_mode"><?php esc_html_e('E-Mail als gelesen markieren', 'ahx_wp_mail'); ?></label></th>
                    <td>
                        <select id="ahx_wp_mail_mark_read_mode" name="ahx_wp_mail_mark_read_mode">
                            <option value="open"   <?php selected($mark_mode, 'open'); ?>><?php esc_html_e('Beim Öffnen der E-Mail', 'ahx_wp_mail'); ?></option>
                            <option value="manual" <?php selected($mark_mode, 'manual'); ?>><?php esc_html_e('Nur manuell per Aktion', 'ahx_wp_mail'); ?></option>
                            <option value="never"  <?php selected($mark_mode, 'never'); ?>><?php esc_html_e('Nie automatisch', 'ahx_wp_mail'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Steuert, wann eine Nachricht als gelesen markiert wird.', 'ahx_wp_mail'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Papierkorb-Ordner', 'ahx_wp_mail'); ?></th>
                    <td>
                        <p class="description"><?php esc_html_e('Der Papierkorb-Ordner wird pro Benutzerkonto im Profil des jeweiligen Benutzers konfiguriert.', 'ahx_wp_mail'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="ahx_wp_mail_folder_aliases"><?php esc_html_e('Ordner-Aliase', 'ahx_wp_mail'); ?></label></th>
                    <td>
                        <textarea id="ahx_wp_mail_folder_aliases"
                                  name="ahx_wp_mail_folder_aliases"
                                  rows="10"
                                  class="large-text code"><?php echo esc_textarea($aliases); ?></textarea>
                        <p class="description"><?php esc_html_e('Steuert die Zuordnung von IMAP-Ordnernamen zu Hauptordnern. Beispiel: "Gesendete Objekte" kann der Gruppe "sent" zugeordnet werden.', 'ahx_wp_mail'); ?></p>
                    </td>
                </tr>

            </table>
            <p class="submit">
                <button type="submit" name="ahx_wp_mail_config_save" class="button button-primary">
                    <?php esc_html_e('Einstellungen speichern', 'ahx_wp_mail'); ?>
                </button>
            </p>
        </form>
    </div>
    <?php
}
