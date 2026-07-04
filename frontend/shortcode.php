<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shortcode [ahx_mail]
 *
 * Rendert den IMAP-Postfach-Viewer für eingeloggte Benutzer.
 */
function ahx_wp_mail_shortcode($atts) {
    if (!is_user_logged_in()) {
        return '<p class="ahx-mail-notice">' .
            esc_html__('Bitte melde dich an, um dein Postfach zu sehen.', 'ahx_wp_mail') .
            '</p>';
    }

    $user_id  = get_current_user_id();
    $settings = AHX_WP_Mail_User_Settings::get($user_id);
    $accounts = isset($settings['accounts']) && is_array($settings['accounts']) ? $settings['accounts'] : array();
    $active_account = isset($settings['active_account_key']) ? (string) $settings['active_account_key'] : '';

    if (empty($settings['imap_user']) || empty($settings['imap_password'])) {
        $profile_url = get_edit_profile_url($user_id);
        return '<p class="ahx-mail-notice">' .
            wp_kses(
                sprintf(
                    /* translators: %s: link to profile */
                    __('Bitte hinterlege deine <a href="%s">IMAP-Zugangsdaten im Profil</a>.', 'ahx_wp_mail'),
                    esc_url($profile_url)
                ),
                array('a' => array('href' => array()))
            ) .
            '</p>';
    }

    // Hole alle Accounts mit ihren Trash-Folders für das Frontend
    $account_trash_folders = array();
    if (!empty($accounts)) {
        foreach ($accounts as $acc) {
            $account_trash_folders[$acc['key']] = isset($acc['trash_folder']) ? (string) $acc['trash_folder'] : '';
        }
    }

    ob_start();
    ?>
    <div id="ahx-mail-app" class="ahx-mail-app" data-nonce="<?php echo esc_attr(wp_create_nonce('ahx_wp_mail_nonce')); ?>" data-account-key="<?php echo esc_attr($active_account); ?>" data-account-trash="<?php echo esc_attr(wp_json_encode($account_trash_folders)); ?>">
        <div class="ahx-mail-sidebar">
            <h3 class="ahx-mail-sidebar__title"><?php esc_html_e('Ordner', 'ahx_wp_mail'); ?></h3>
            <div class="ahx-mail-sidebar__search">
                <input
                    type="search"
                    id="ahx-mail-folder-search"
                    class="ahx-mail-sidebar__search-input"
                    placeholder="<?php esc_attr_e('Ordner suchen…', 'ahx_wp_mail'); ?>"
                    aria-label="<?php esc_attr_e('Ordner suchen', 'ahx_wp_mail'); ?>"
                />
            </div>
            <div class="ahx-mail-sidebar__scroll">
                <ul class="ahx-mail-folder-list" id="ahx-mail-folders">
                    <li class="ahx-mail-folder ahx-mail-folder--active" data-folder="INBOX">
                        <?php esc_html_e('Posteingang', 'ahx_wp_mail'); ?>
                    </li>
                </ul>

                <h3 class="ahx-mail-sidebar__title ahx-mail-sidebar__title--spaced"><?php esc_html_e('Bereiche', 'ahx_wp_mail'); ?></h3>
                <ul class="ahx-mail-nav-list" id="ahx-mail-nav-list">
                    <li class="ahx-mail-nav-group ahx-mail-nav-group--rules">
                        <button class="ahx-mail-nav-group__header" id="ahx-mail-nav-rules" type="button">
                            <span class="ahx-mail-nav-group__label"><?php esc_html_e('Regeln', 'ahx_wp_mail'); ?></span>
                            <span class="ahx-mail-nav-group__badge" id="ahx-mail-rules-count-badge">0</span>
                        </button>
                        <ul class="ahx-mail-nav-sublist">
                            <li>
                                <button class="ahx-mail-nav-subitem" id="ahx-mail-nav-rules-overview" type="button">
                                    <?php esc_html_e('Alle Regeln', 'ahx_wp_mail'); ?>
                                </button>
                            </li>
                            <li>
                                <button class="ahx-mail-nav-subitem" id="ahx-mail-nav-rules-quick" type="button">
                                    <?php esc_html_e('Aus aktueller Mail', 'ahx_wp_mail'); ?>
                                </button>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>

        <div class="ahx-mail-main">
            <div class="ahx-mail-toolbar">
                <?php if (count($accounts) > 1): ?>
                    <label for="ahx-mail-account-switch" class="ahx-mail-toolbar__label"><?php esc_html_e('Konto:', 'ahx_wp_mail'); ?></label>
                    <select id="ahx-mail-account-switch" class="ahx-mail-select">
                        <?php foreach ($accounts as $acc): ?>
                            <option value="<?php echo esc_attr($acc['key']); ?>" <?php selected($active_account, $acc['key']); ?>>
                                <?php echo esc_html($acc['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <button class="ahx-mail-btn" id="ahx-mail-refresh">
                    &#x21BB; <?php esc_html_e('Aktualisieren', 'ahx_wp_mail'); ?>
                </button>
                <button class="ahx-mail-btn ahx-mail-btn--danger" id="ahx-mail-empty-trash" style="display:none;">
                    &#128465; <?php esc_html_e('Papierkorb leeren', 'ahx_wp_mail'); ?>
                </button>
                <span class="ahx-mail-status" id="ahx-mail-status"></span>
                <span class="ahx-mail-stale-indicator" id="ahx-mail-stale-indicator" style="display:none;">
                    <?php esc_html_e('Zwischengespeicherte Liste, Aktualisierung läuft…', 'ahx_wp_mail'); ?>
                </span>
                <span class="ahx-mail-folder-stats" id="ahx-mail-folder-stats"></span>
            </div>

            <div id="ahx-mail-list-panel" class="ahx-mail-list-panel">

                <div class="ahx-mail-bulk-toolbar" id="ahx-mail-bulk-toolbar" style="display:none;">
                    <span class="ahx-mail-bulk-info" id="ahx-mail-bulk-info"></span>
                    <button class="ahx-mail-btn ahx-mail-btn--sm" id="ahx-mail-bulk-read">
                        &#10003; <?php esc_html_e('Gelesen', 'ahx_wp_mail'); ?>
                    </button>
                    <button class="ahx-mail-btn ahx-mail-btn--sm" id="ahx-mail-bulk-unread">
                        &#9675; <?php esc_html_e('Ungelesen', 'ahx_wp_mail'); ?>
                    </button>
                    <button class="ahx-mail-btn ahx-mail-btn--sm" id="ahx-mail-bulk-archive">
                        &#128451; <?php esc_html_e('Archivieren', 'ahx_wp_mail'); ?>
                    </button>
                    <button class="ahx-mail-btn ahx-mail-btn--sm ahx-mail-btn--danger" id="ahx-mail-bulk-delete">
                        &#128465; <?php esc_html_e('Löschen', 'ahx_wp_mail'); ?>
                    </button>
                    <select id="ahx-mail-bulk-move-select" class="ahx-mail-select">
                        <option value=""><?php esc_html_e('Verschieben nach…', 'ahx_wp_mail'); ?></option>
                    </select>
                    <button class="ahx-mail-btn ahx-mail-btn--sm" id="ahx-mail-bulk-move">
                        &#x2192; <?php esc_html_e('Verschieben', 'ahx_wp_mail'); ?>
                    </button>
                </div>

                <table class="ahx-mail-list ahx-mail-list-head" aria-hidden="true">
                    <thead>
                        <tr>
                            <th class="ahx-mail-col-check"><input type="checkbox" id="ahx-mail-check-all" title="<?php esc_attr_e('Alle auswählen', 'ahx_wp_mail'); ?>"></th>
                            <th><?php esc_html_e('Von', 'ahx_wp_mail'); ?></th>
                            <th><?php esc_html_e('Betreff', 'ahx_wp_mail'); ?></th>
                            <th><?php esc_html_e('Datum', 'ahx_wp_mail'); ?></th>
                        </tr>
                    </thead>
                </table>
                <div class="ahx-mail-list-scroll">
                    <table class="ahx-mail-list" id="ahx-mail-list">
                        <tbody id="ahx-mail-tbody">
                            <tr><td colspan="4"><?php esc_html_e('Lädt…', 'ahx_wp_mail'); ?></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="ahx-mail-pagination" id="ahx-mail-pagination"></div>
            </div>

            <div class="ahx-mail-rules-panel" id="ahx-mail-rules-panel" style="display:none;">
                <div class="ahx-mail-rules-panel__header">
                    <div>
                        <h2><?php esc_html_e('Regeln', 'ahx_wp_mail'); ?></h2>
                        <p class="ahx-mail-rules-panel__hint"><?php esc_html_e('Regeln können direkt aus der aktuellen Mail vorbelegt werden.', 'ahx_wp_mail'); ?></p>
                    </div>
                    <div class="ahx-mail-rules-panel__header-actions">
                        <button class="ahx-mail-btn ahx-mail-btn--sm" id="ahx-mail-rule-new" type="button">
                            <?php esc_html_e('Neue Regel', 'ahx_wp_mail'); ?>
                        </button>
                        <button class="ahx-mail-btn ahx-mail-btn--sm" id="ahx-mail-rules-panel-close" type="button">
                            <?php esc_html_e('Zurück zu den Mails', 'ahx_wp_mail'); ?>
                        </button>
                    </div>
                </div>

                <div class="ahx-mail-rules-builder" id="ahx-mail-rules-builder" style="display:none;">
                    <div class="ahx-mail-rules-builder__row">
                        <label for="ahx-mail-rule-account"><?php esc_html_e('Konto', 'ahx_wp_mail'); ?></label>
                        <select id="ahx-mail-rule-account" class="ahx-mail-select">
                            <?php foreach ($accounts as $acc): ?>
                                <option value="<?php echo esc_attr($acc['key']); ?>" <?php selected($active_account, $acc['key']); ?>>
                                    <?php echo esc_html($acc['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ahx-mail-rules-builder__row">
                        <label for="ahx-mail-rule-name"><?php esc_html_e('Name', 'ahx_wp_mail'); ?></label>
                        <input type="text" id="ahx-mail-rule-name" class="regular-text" placeholder="<?php esc_attr_e('Wird automatisch aus der Regel abgeleitet', 'ahx_wp_mail'); ?>" />
                    </div>
                    <div class="ahx-mail-rules-builder__row">
                        <label for="ahx-mail-rule-folder"><?php esc_html_e('Ordner', 'ahx_wp_mail'); ?></label>
                        <input type="text" id="ahx-mail-rule-folder" class="regular-text" value="INBOX" />
                    </div>
                    <div class="ahx-mail-rules-builder__row">
                        <label for="ahx-mail-rule-from"><?php esc_html_e('Von enthält', 'ahx_wp_mail'); ?></label>
                        <input type="text" id="ahx-mail-rule-from" class="regular-text" />
                    </div>
                    <div class="ahx-mail-rules-builder__row">
                        <label for="ahx-mail-rule-to"><?php esc_html_e('An enthält', 'ahx_wp_mail'); ?></label>
                        <input type="text" id="ahx-mail-rule-to" class="regular-text" />
                    </div>
                    <div class="ahx-mail-rules-builder__row">
                        <label for="ahx-mail-rule-subject"><?php esc_html_e('Betreff enthält', 'ahx_wp_mail'); ?></label>
                        <input type="text" id="ahx-mail-rule-subject" class="regular-text" />
                    </div>
                    <div class="ahx-mail-rules-builder__hint">
                        <?php esc_html_e('Weitere Bedingungen werden als ODER-Gruppen hinzugefügt.', 'ahx_wp_mail'); ?>
                    </div>
                    <div id="ahx-mail-rule-groups" class="ahx-mail-rule-groups"></div>
                    <div class="ahx-mail-rules-builder__actions ahx-mail-rules-builder__actions--stacked">
                        <button class="ahx-mail-btn ahx-mail-btn--sm" id="ahx-mail-rule-add-group" type="button">
                            <?php esc_html_e('ODER-Bedingung hinzufügen', 'ahx_wp_mail'); ?>
                        </button>
                    </div>
                    <div class="ahx-mail-rules-builder__row">
                        <label for="ahx-mail-rule-action"><?php esc_html_e('Aktion', 'ahx_wp_mail'); ?></label>
                        <select id="ahx-mail-rule-action" class="ahx-mail-select">
                            <option value="mark_read"><?php esc_html_e('Als gelesen markieren', 'ahx_wp_mail'); ?></option>
                            <option value="mark_unread"><?php esc_html_e('Als ungelesen markieren', 'ahx_wp_mail'); ?></option>
                            <option value="delete"><?php esc_html_e('Löschen', 'ahx_wp_mail'); ?></option>
                            <option value="move"><?php esc_html_e('Verschieben', 'ahx_wp_mail'); ?></option>
                            <option value="archive"><?php esc_html_e('Archivieren', 'ahx_wp_mail'); ?></option>
                        </select>
                    </div>
                    <div class="ahx-mail-rules-builder__row" id="ahx-mail-rule-move-row" style="display:none;">
                        <label for="ahx-mail-rule-move-to"><?php esc_html_e('Zielordner', 'ahx_wp_mail'); ?></label>
                        <select id="ahx-mail-rule-move-to" class="ahx-mail-select">
                            <option value=""><?php esc_html_e('Verschieben nach…', 'ahx_wp_mail'); ?></option>
                        </select>
                    </div>
                    <div class="ahx-mail-rules-builder__actions">
                        <button class="ahx-mail-btn ahx-mail-btn--sm" id="ahx-mail-rule-fill-from-mail" type="button">
                            <?php esc_html_e('Aus aktueller Mail vorbelegen', 'ahx_wp_mail'); ?>
                        </button>
                        <button class="ahx-mail-btn ahx-mail-btn--sm" id="ahx-mail-rule-save" type="button">
                            <?php esc_html_e('Regel speichern', 'ahx_wp_mail'); ?>
                        </button>
                        <button class="ahx-mail-btn ahx-mail-btn--sm" id="ahx-mail-rule-cancel" type="button" style="display:none;">
                            <?php esc_html_e('Bearbeitung abbrechen', 'ahx_wp_mail'); ?>
                        </button>
                    </div>
                </div>

                <ul id="ahx-mail-rules-list" class="ahx-mail-rules-list"></ul>
            </div>

            <div id="ahx-mail-detail-panel" class="ahx-mail-detail-panel" style="display:none;">
                <button class="ahx-mail-btn ahx-mail-btn--back" id="ahx-mail-back">
                    &larr; <?php esc_html_e('Zurück zur Liste', 'ahx_wp_mail'); ?>
                </button>
                <div class="ahx-mail-detail-header">
                    <h2 id="ahx-mail-detail-subject"></h2>
                    <p><strong><?php esc_html_e('Von:', 'ahx_wp_mail'); ?></strong> <span id="ahx-mail-detail-from"></span></p>
                    <p><strong><?php esc_html_e('An:', 'ahx_wp_mail'); ?></strong> <span id="ahx-mail-detail-to"></span></p>
                    <p><strong><?php esc_html_e('Datum:', 'ahx_wp_mail'); ?></strong> <span id="ahx-mail-detail-date"></span></p>
                </div>

                <div class="ahx-mail-detail-actions">
                    <button class="ahx-mail-btn ahx-mail-btn--sm" id="ahx-mail-detail-mark-unread">
                        &#9675; <?php esc_html_e('Als ungelesen markieren', 'ahx_wp_mail'); ?>
                    </button>
                    <button class="ahx-mail-btn ahx-mail-btn--sm" id="ahx-mail-detail-archive">
                        &#128451; <?php esc_html_e('Archivieren', 'ahx_wp_mail'); ?>
                    </button>
                    <button class="ahx-mail-btn ahx-mail-btn--sm" id="ahx-mail-detail-show-source">
                        &lt;/&gt; <?php esc_html_e('Quelltext anzeigen', 'ahx_wp_mail'); ?>
                    </button>
                    <button class="ahx-mail-btn ahx-mail-btn--sm" id="ahx-mail-detail-download-source">
                        &#128190; <?php esc_html_e('Quelltext herunterladen', 'ahx_wp_mail'); ?>
                    </button>
                    <button class="ahx-mail-btn ahx-mail-btn--sm ahx-mail-btn--danger" id="ahx-mail-detail-delete">
                        &#128465; <?php esc_html_e('Löschen', 'ahx_wp_mail'); ?>
                    </button>
                    <select id="ahx-mail-detail-move-select" class="ahx-mail-select">
                        <option value=""><?php esc_html_e('Verschieben nach…', 'ahx_wp_mail'); ?></option>
                    </select>
                    <button class="ahx-mail-btn ahx-mail-btn--sm" id="ahx-mail-detail-move">
                        &#x2192; <?php esc_html_e('Verschieben', 'ahx_wp_mail'); ?>
                    </button>
                    <button class="ahx-mail-btn ahx-mail-btn--sm" id="ahx-mail-detail-rules-toggle" type="button">
                        <?php esc_html_e('Quick-Regeln', 'ahx_wp_mail'); ?>
                    </button>
                </div>

                <div class="ahx-mail-sticky-actions" id="ahx-mail-sticky-actions" style="display:none;">
                    <button class="ahx-mail-btn ahx-mail-btn--sm" id="ahx-mail-sticky-prev" type="button">
                        &#x2190; <?php esc_html_e('Eine Nachricht vor', 'ahx_wp_mail'); ?>
                    </button>
                    <button class="ahx-mail-btn ahx-mail-btn--sm" id="ahx-mail-sticky-next" type="button">
                        <?php esc_html_e('Eine Nachricht weiter', 'ahx_wp_mail'); ?> &#x2192;
                    </button>
                    <button class="ahx-mail-btn ahx-mail-btn--sm" id="ahx-mail-sticky-archive" type="button">
                        &#128451; <?php esc_html_e('Archivieren', 'ahx_wp_mail'); ?>
                    </button>
                    <button class="ahx-mail-btn ahx-mail-btn--sm ahx-mail-btn--danger" id="ahx-mail-sticky-delete" type="button">
                        &#128465; <?php esc_html_e('Löschen', 'ahx_wp_mail'); ?>
                    </button>
                </div>

                <div id="ahx-mail-image-bar" class="ahx-mail-image-bar" style="display:none;">
                    <span class="ahx-mail-image-bar__icon">&#128247;</span>
                    <span class="ahx-mail-image-bar__text"><?php esc_html_e('Externe Bilder wurden blockiert.', 'ahx_wp_mail'); ?></span>
                    <div class="ahx-mail-image-bar__actions">
                        <button class="ahx-mail-btn ahx-mail-btn--sm" id="ahx-mail-show-once">
                            <?php esc_html_e('Einmal anzeigen', 'ahx_wp_mail'); ?>
                        </button>
                        <button class="ahx-mail-btn ahx-mail-btn--sm" id="ahx-mail-allow-sender">
                            <?php esc_html_e('Immer von diesem Absender', 'ahx_wp_mail'); ?>
                        </button>
                        <button class="ahx-mail-btn ahx-mail-btn--sm" id="ahx-mail-allow-domain">
                            <?php esc_html_e('Immer von dieser Domain', 'ahx_wp_mail'); ?>
                        </button>
                    </div>
                </div>

                <div class="ahx-mail-detail-body" id="ahx-mail-detail-body"></div>

                <div class="ahx-mail-attachments" id="ahx-mail-attachments" style="display:none;">
                    <h3><?php esc_html_e('Anhänge', 'ahx_wp_mail'); ?></h3>
                    <ul id="ahx-mail-attachments-list" class="ahx-mail-attachments-list"></ul>
                </div>

                <div id="ahx-mail-source-modal" class="ahx-mail-source-modal" style="display:none;">
                    <div class="ahx-mail-source-modal__backdrop" id="ahx-mail-source-close-backdrop"></div>
                    <div class="ahx-mail-source-modal__dialog" role="dialog" aria-modal="true" aria-label="E-Mail Quelltext">
                        <div class="ahx-mail-source-modal__header">
                            <strong><?php esc_html_e('E-Mail Quelltext', 'ahx_wp_mail'); ?></strong>
                            <button class="ahx-mail-btn ahx-mail-btn--sm" id="ahx-mail-source-close">&times;</button>
                        </div>
                        <pre id="ahx-mail-source-pre" class="ahx-mail-source-pre"></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('ahx_mail', 'ahx_wp_mail_shortcode');
