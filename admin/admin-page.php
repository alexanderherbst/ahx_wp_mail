<?php

if (!defined('ABSPATH')) {
    exit;
}

function ahx_wp_mail_admin_page() {
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('AHX WP Mail – Übersicht', 'ahx_wp_mail'); ?></h1>
        <p><?php esc_html_e('Dieses Plugin ermöglicht Benutzern den Zugriff auf ihr IMAP-Postfach direkt im WordPress-Frontend.', 'ahx_wp_mail'); ?></p>

        <h2><?php esc_html_e('Schnellstart', 'ahx_wp_mail'); ?></h2>
        <ol>
            <li><?php printf(
                wp_kses(
                    /* translators: %s: link to config page */
                    __('IMAP-Server unter <a href="%s">Einstellungen</a> konfigurieren.', 'ahx_wp_mail'),
                    array('a' => array('href' => array()))
                ),
                esc_url(admin_url('admin.php?page=ahx_wp_mail-config'))
            ); ?></li>
            <li><?php esc_html_e('Benutzer hinterlegen ihre IMAP-Zugangsdaten im eigenen WordPress-Profil.', 'ahx_wp_mail'); ?></li>
            <li><?php printf(
                wp_kses(
                    __('Shortcode <code>[ahx_mail]</code> auf einer Seite oder in einem Widget einbinden.', 'ahx_wp_mail'),
                    array('code' => array())
                )
            ); ?></li>
        </ol>

        <h2><?php esc_html_e('PHP-IMAP-Extension', 'ahx_wp_mail'); ?></h2>
        <?php if (function_exists('imap_open')): ?>
            <p style="color:green;">&#10003; <?php esc_html_e('Die PHP-IMAP-Extension ist aktiv.', 'ahx_wp_mail'); ?></p>
        <?php else: ?>
            <p style="color:red;">&#10007; <?php esc_html_e('Die PHP-IMAP-Extension ist NICHT aktiv. Bitte in der php.ini aktivieren (extension=imap).', 'ahx_wp_mail'); ?></p>
        <?php endif; ?>

        <h2><?php esc_html_e('Shortcode-Verfügbarkeit', 'ahx_wp_mail'); ?></h2>
        <p><?php esc_html_e('Shortcode: ', 'ahx_wp_mail'); ?><code>[ahx_mail]</code></p>
    </div>
    <?php
}
