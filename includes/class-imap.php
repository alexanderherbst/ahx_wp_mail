<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * IMAP-Verbindungsklasse für AHX WP Mail.
 *
 * Setzt die PHP-IMAP-Extension voraus (php_imap).
 */
class AHX_WP_Mail_IMAP {

    /** @var string */
    private $host;

    /** @var int */
    private $port;

    /** @var string ssl|tls|none */
    private $encryption;

    /** @var resource|false */
    private $connection = false;

    /** @var string */
    private $last_error = '';

    public function __construct($host, $port = 993, $encryption = 'ssl') {
        $this->host       = (string) $host;
        $this->port       = (int) $port;
        $this->encryption = in_array($encryption, array('ssl', 'tls', 'none'), true) ? $encryption : 'ssl';
    }

    // Verbindung
    // -----------------------------------------------------------------------

    /**
     * Stellt eine IMAP-Verbindung her.
     *
     * @param string $username
     * @param string $password  Klartext – wird niemals persistiert.
     * @return true|WP_Error
     */
    public function connect($username, $password) {
        $this->last_error = '';

        if (!function_exists('imap_open')) {
            $this->last_error = 'PHP-IMAP-Extension ist nicht aktiviert.';
            return new WP_Error('imap_missing', 'PHP-IMAP-Extension ist nicht aktiviert.');
        }

        $mailbox = $this->build_mailbox_string('INBOX');

        // Fehler-Unterdrückung, da imap_open() PHP-Warnungen erzeugen kann.
        $this->connection = @imap_open($mailbox, $username, $password, 0, 1);

        if ($this->connection === false) {
            $errors = imap_errors();
            $msg    = is_array($errors) ? implode('; ', $errors) : 'Verbindung fehlgeschlagen.';
            $this->last_error = (string) $msg;
            return new WP_Error('imap_connect_failed', esc_html($msg));
        }

        return true;
    }

    public function disconnect() {
        if ($this->connection !== false) {
            imap_close($this->connection);
            $this->connection = false;
        }
    }

    /**
     * Letzte IMAP-Fehlermeldung der Instanz.
     *
     * @return string
     */
    public function get_last_error() {
        return (string) $this->last_error;
    }

    // -----------------------------------------------------------------------
    // Ordner
    // -----------------------------------------------------------------------

    /**
     * Gibt eine Liste aller Ordner zurück.
     *
     * @return string[]
     */
    public function get_folders() {
        if ($this->connection === false) {
            return array();
        }

        $server  = $this->build_mailbox_string('');
        $raw     = imap_list($this->connection, $server, '*');
        $folders = array();

        if (is_array($raw)) {
            foreach ($raw as $folder) {
                $name = str_replace($server, '', $folder);
                $folders[] = mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP');
            }
        }

        return $folders;
    }

    /**
     * Liefert pro Ordner die Gesamtzahl und Anzahl ungelesener Nachrichten.
     *
     * @param string[] $folders
     * @return array<string,array{total:int,unread:int}>
     */
    public function get_folder_counters($folders) {
        if ($this->connection === false || !is_array($folders) || empty($folders)) {
            return array();
        }

        $stats = array();
        foreach ($folders as $folder) {
            $folder = (string) $folder;
            if ($folder === '') {
                continue;
            }

            $mailbox = $this->build_mailbox_string($folder);
            $status = @imap_status($this->connection, $mailbox, SA_MESSAGES | SA_UNSEEN);
            if ($status === false) {
                continue;
            }

            $stats[$folder] = array(
                'total' => max(0, (int) ($status->messages ?? 0)),
                'unread' => max(0, (int) ($status->unseen ?? 0)),
            );
        }

        return $stats;
    }

    /**
     * Liefert Ordner-Empfehlungen fuer einen Absender anhand vorhandener Nachrichtenanzahl.
     *
     * @param string[] $folders
     * @param string   $sender_email
     * @param string   $exclude_folder
     * @param int      $limit
     * @return array[] Array von ['folder' => string, 'count' => int]
     */
    public function get_sender_folder_recommendations($folders, $sender_email, $exclude_folder = '', $limit = 5) {
        $sender_email = strtolower(trim((string) $sender_email));
        if ($this->connection === false || !is_array($folders) || empty($folders) || $sender_email === '') {
            return array();
        }

        $exclude_folder = strtolower(trim((string) $exclude_folder));
        $limit = max(1, (int) $limit);
        $rows = array();

        // Doppelte Quotes in IMAP-Suchstring escapen.
        $escaped_sender = str_replace('"', '\\"', $sender_email);
        $sender_domain = '';
        if (strpos($sender_email, '@') !== false) {
            $parts = explode('@', $sender_email, 2);
            $sender_domain = isset($parts[1]) ? trim((string) $parts[1]) : '';
        }

        $criteria_list = array(
            'FROM "' . $escaped_sender . '"',
            'HEADER FROM "' . $escaped_sender . '"',
        );
        if ($sender_domain !== '') {
            $escaped_domain = str_replace('"', '\\"', $sender_domain);
            $criteria_list[] = 'HEADER FROM "@' . $escaped_domain . '"';
        }

        foreach ($folders as $folder) {
            $folder = (string) $folder;
            if ($folder === '') {
                continue;
            }
            if ($exclude_folder !== '' && strtolower($folder) === $exclude_folder) {
                continue;
            }

            $mailbox = $this->build_mailbox_string($folder);
            try {
                $reopen_ok = @imap_reopen($this->connection, $mailbox);
            } catch (ValueError $e) {
                $reopen_ok = false;
            }
            if ($reopen_ok === false) {
                continue;
            }

            $uid_set = array();
            foreach ($criteria_list as $criteria) {
                $uids = @imap_search($this->connection, $criteria, SE_UID);
                if (!is_array($uids) || empty($uids)) {
                    continue;
                }

                foreach ($uids as $uid) {
                    $uid_int = (int) $uid;
                    if ($uid_int > 0) {
                        $uid_set[$uid_int] = 1;
                    }
                }
            }

            if (empty($uid_set)) {
                continue;
            }

            $rows[] = array(
                'folder' => $folder,
                'count' => count($uid_set),
            );
        }

        if (empty($rows)) {
            return array();
        }

        usort($rows, static function ($a, $b) {
            $ca = (int) ($a['count'] ?? 0);
            $cb = (int) ($b['count'] ?? 0);
            if ($ca === $cb) {
                return strcasecmp((string) ($a['folder'] ?? ''), (string) ($b['folder'] ?? ''));
            }
            return ($cb <=> $ca);
        });

        return array_slice($rows, 0, $limit);
    }

    // -----------------------------------------------------------------------
    // E-Mail-Liste
    // -----------------------------------------------------------------------

    /**
     * Oeffnet ein Postfach auf der bestehenden Verbindung sicher neu.
     *
     * Bei DNS-/Netzwerkproblemen oder bereits geschlossenem Stream liefert
     * imap_reopen() Warnungen bzw. in PHP 8 ValueError. Beides wird hier
     * abgefangen, damit aufrufender Code sauber abbrechen kann.
     *
     * @param string $folder
     * @return bool
     */
    private function reopen_mailbox($folder) {
        if ($this->connection === false) {
            $this->last_error = 'Keine aktive IMAP-Verbindung.';
            return false;
        }

        $mailbox = $this->build_mailbox_string($folder);

        try {
            $ok = @imap_reopen($this->connection, $mailbox);
        } catch (ValueError $e) {
            $this->last_error = $e->getMessage();
            $this->connection = false;
            return false;
        }

        if ($ok === false) {
            $errors = imap_errors();
            $this->last_error = is_array($errors) && !empty($errors)
                ? implode('; ', $errors)
                : 'imap_reopen fehlgeschlagen.';
            $this->connection = false;
            return false;
        }

        return true;
    }

    /**
     * Gibt eine paginierte Liste von E-Mails zurück.
     *
     * @param string $folder
     * @param int    $page     1-basiert
     * @param int    $per_page
    * @param bool   $include_attachments
    * @param bool   $precise_addresses
     * @return array
     */
    public function get_emails($folder, $page = 1, $per_page = 20, $include_attachments = true, $precise_addresses = false) {
        if ($this->connection === false) {
            return array();
        }

        $page = max(1, (int) $page);
        $per_page = max(1, (int) $per_page);

        if (!$this->reopen_mailbox($folder)) {
            return array();
        }

        try {
            $total = imap_num_msg($this->connection);
        } catch (ValueError $e) {
            $this->last_error = $e->getMessage();
            $this->connection = false;
            return array();
        }
        if ($total === 0) {
            return array();
        }

        // Neueste E-Mails zuerst
        $start = $total - (($page - 1) * $per_page);
        $end   = max(1, $start - $per_page + 1);
        if ($start < 1) {
            return array();
        }

        $overview = imap_fetch_overview($this->connection, $end . ':' . $start, 0);
        if (!is_array($overview) || empty($overview)) {
            return array();
        }

        $overview_by_seq = array();
        foreach ($overview as $item) {
            $seq = isset($item->msgno) ? (int) $item->msgno : 0;
            if ($seq > 0) {
                $overview_by_seq[$seq] = $item;
            }
        }

        $emails = array();
        for ($i = $start; $i >= $end; $i--) {
            if (!isset($overview_by_seq[$i])) {
                continue;
            }

            $item = $overview_by_seq[$i];

            $has_attachments = false;
            if ($include_attachments) {
                $struct = imap_fetchstructure($this->connection, $i);
                $has_attachments = $this->has_attachments($struct);
            }

            $uid = isset($item->uid) ? (int) $item->uid : 0;
            if ($uid <= 0) {
                $uid = (int) imap_uid($this->connection, $i);
            }

            $subject_raw = isset($item->subject) ? (string) $item->subject : '(kein Betreff)';
            $from_value = isset($item->from) ? $this->decode_header((string) $item->from) : '';
            $to_value = isset($item->to) ? $this->decode_header((string) $item->to) : '';
            $date_raw = isset($item->date) ? (string) $item->date : '';

            if ($precise_addresses) {
                $header = imap_headerinfo($this->connection, $i);
                if ($header) {
                    $from_parts = array();
                    $to_parts = array();

                    $formatted_from = $this->format_address($header->from ?? array());
                    $formatted_to = $this->format_address($header->to ?? array());
                    if ($formatted_from !== '') {
                        $from_parts[] = $formatted_from;
                    }
                    if ($formatted_to !== '') {
                        $to_parts[] = $formatted_to;
                    }

                    if (isset($header->fromaddress) && (string) $header->fromaddress !== '') {
                        $from_parts[] = $this->decode_header((string) $header->fromaddress);
                    }
                    if (isset($header->toaddress) && (string) $header->toaddress !== '') {
                        $to_parts[] = $this->decode_header((string) $header->toaddress);
                    }

                    $raw_header = @imap_fetchheader($this->connection, $i, FT_PREFETCHTEXT);
                    if (is_string($raw_header) && $raw_header !== '') {
                        $unfolded = preg_replace("/\r?\n[ \t]+/", ' ', $raw_header);
                        if (preg_match('/^From:\s*(.+)$/im', $unfolded, $fm)) {
                            $from_parts[] = $this->decode_header(trim((string) $fm[1]));
                        }
                        if (preg_match('/^To:\s*(.+)$/im', $unfolded, $tm)) {
                            $to_parts[] = $this->decode_header(trim((string) $tm[1]));
                        }
                    }

                    $from_parts = array_values(array_unique(array_filter(array_map('trim', $from_parts))));
                    $to_parts = array_values(array_unique(array_filter(array_map('trim', $to_parts))));

                    $from_value = implode(' | ', $from_parts);
                    $to_value = implode(' | ', $to_parts);

                    if (isset($header->subject) && (string) $header->subject !== '') {
                        $subject_raw = (string) $header->subject;
                    }
                    if (isset($header->date) && (string) $header->date !== '') {
                        $date_raw = (string) $header->date;
                    }
                }
            }

            $emails[] = array(
                'uid'     => $uid,
                'seq'     => $i,
                'subject' => $this->decode_header($subject_raw),
                'from'    => $from_value,
                'to'      => $to_value,
                'date'    => $date_raw !== '' ? date('d.m.Y H:i', strtotime($date_raw)) : '',
                'seen'    => !empty($item->seen),
                'flagged' => !empty($item->flagged),
                'has_attachments' => $has_attachments,
                'total'   => $total,
            );
        }

        return $emails;
    }

    /**
     * Liefert Attachment-Flags fuer eine Liste von UIDs in einem Ordner.
     *
     * @param string $folder
     * @param int[]  $uids
     * @return array<string,bool>
     */
    public function get_attachment_flags_for_uids($folder, $uids) {
        if ($this->connection === false || !is_array($uids) || empty($uids)) {
            return array();
        }

        if (!$this->reopen_mailbox($folder)) {
            return array();
        }

        $flags = array();
        foreach ($uids as $uid) {
            $uid = (int) $uid;
            if ($uid <= 0) {
                continue;
            }

            $seq = (int) @imap_msgno($this->connection, $uid);
            if ($seq <= 0) {
                $flags[(string) $uid] = false;
                continue;
            }

            $struct = @imap_fetchstructure($this->connection, $seq);
            $flags[(string) $uid] = $this->has_attachments($struct);
        }

        return $flags;
    }

    // -----------------------------------------------------------------------
    // E-Mail-Inhalt
    // -----------------------------------------------------------------------

    // -----------------------------------------------------------------------
    // Aktionen
    // -----------------------------------------------------------------------

    /**
     * Markiert eine Nachricht als gelesen.
     */
    public function mark_read($folder, $uid) {
        return $this->set_flag($folder, $uid, '\\Seen', true);
    }

    /**
     * Markiert eine Nachricht als ungelesen.
     */
    public function mark_unread($folder, $uid) {
        return $this->set_flag($folder, $uid, '\\Seen', false);
    }

    /**
     * Markiert eine Nachricht als von Regeln bearbeitet.
     */
    public function mark_rule_processed($folder, $uid) {
        return $this->set_flag($folder, $uid, '\\Flagged', true);
    }

    /**
     * Entfernt die Markierung "von Regeln bearbeitet".
     */
    public function unmark_rule_processed($folder, $uid) {
        return $this->set_flag($folder, $uid, '\\Flagged', false);
    }

    /**
     * Löscht eine Nachricht (verschiebt in Trash oder löscht sofort).
     *
     * @param string $folder
     * @param int    $uid
     * @param string $trash_folder  Papierkorb-Ordner; leer = sofort löschen
     * @return true|WP_Error
     */
    public function delete_email($folder, $uid, $trash_folder = '') {
        if ($this->connection === false) {
            return new WP_Error('imap_not_connected', 'Keine IMAP-Verbindung.');
        }

        if ($trash_folder !== '' && $trash_folder !== $folder) {
            return $this->move_email($folder, $uid, $trash_folder);
        }

        $mailbox = $this->build_mailbox_string($folder);
        imap_reopen($this->connection, $mailbox);

        $seq = imap_msgno($this->connection, $uid);
        if ($seq === 0) {
            return new WP_Error('imap_not_found', 'Nachricht nicht gefunden.');
        }

        imap_delete($this->connection, (string) $seq);
        imap_expunge($this->connection);

        return true;
    }

    /**
     * Verschiebt eine Nachricht in einen anderen Ordner.
     *
     * @param string $from_folder
     * @param int    $uid
     * @param string $to_folder
     * @return true|WP_Error
     */
    public function move_email($from_folder, $uid, $to_folder) {
        if ($this->connection === false) {
            return new WP_Error('imap_not_connected', 'Keine IMAP-Verbindung.');
        }

        $mailbox = $this->build_mailbox_string($from_folder);
        imap_reopen($this->connection, $mailbox);

        $seq = imap_msgno($this->connection, $uid);
        if ($seq === 0) {
            return new WP_Error('imap_not_found', 'Nachricht nicht gefunden.');
        }

        $dest = mb_convert_encoding($to_folder, 'UTF7-IMAP', 'UTF-8');
        $ok   = imap_mail_move($this->connection, (string) $seq, $dest);

        if (!$ok) {
            $this->ensure_folder_path_exists($to_folder);
            $ok = imap_mail_move($this->connection, (string) $seq, $dest);
        }

        imap_expunge($this->connection);

        return $ok ? true : new WP_Error('imap_move_failed', 'Verschieben fehlgeschlagen.');
    }

    /**
     * Stellt sicher, dass ein verschachtelter Zielordner (z. B. Archives/2026)
     * serverseitig existiert.
     *
     * @param string $folder_path
     * @return void
     */
    private function ensure_folder_path_exists($folder_path) {
        if ($this->connection === false) {
            return;
        }

        $folder_path = trim((string) $folder_path);
        if ($folder_path === '') {
            return;
        }

        $parts = array_values(array_filter(array_map('trim', explode('/', str_replace('\\\\', '/', $folder_path))), static function ($part) {
            return $part !== '';
        }));

        if (empty($parts)) {
            return;
        }

        $server = $this->build_mailbox_string('');
        $current = '';

        foreach ($parts as $part) {
            $current = $current === '' ? $part : ($current . '/' . $part);
            $mailbox = $server . mb_convert_encoding($current, 'UTF7-IMAP', 'UTF-8');
            if (!@imap_createmailbox($this->connection, imap_utf7_encode($mailbox))) {
                // Ordner existiert ggf. bereits oder darf nicht erstellt werden.
                // In beiden Fällen still weitermachen und finalen move() erneut versuchen.
            }
        }
    }

    /**
     * Leert einen Ordner effizient per Bulk-Delete und einmaligem Expunge.
     *
     * @param string $folder
     * @return int|WP_Error Anzahl geloeschter Nachrichten
     */
    public function empty_folder($folder) {
        if ($this->connection === false) {
            return new WP_Error('imap_not_connected', 'Keine IMAP-Verbindung.');
        }

        $mailbox = $this->build_mailbox_string($folder);
        imap_reopen($this->connection, $mailbox);

        $total = imap_num_msg($this->connection);
        if ($total <= 0) {
            return 0;
        }

        // Bulk-Markierung zum Loeschen, dann genau ein Expunge.
        $ok = imap_delete($this->connection, '1:*');
        if (!$ok) {
            return new WP_Error('imap_delete_failed', 'Papierkorb konnte nicht geleert werden.');
        }

        $expunge_ok = imap_expunge($this->connection);
        if (!$expunge_ok) {
            return new WP_Error('imap_expunge_failed', 'Loeschvorgang konnte nicht abgeschlossen werden.');
        }

        return (int) $total;
    }

    /**
     * Setzt oder entfernt ein IMAP-Flag.
     */
    private function set_flag($folder, $uid, $flag, $set) {
        if ($this->connection === false) {
            return new WP_Error('imap_not_connected', 'Keine IMAP-Verbindung.');
        }

        $mailbox = $this->build_mailbox_string($folder);
        imap_reopen($this->connection, $mailbox);

        $seq = imap_msgno($this->connection, $uid);
        if ($seq === 0) {
            return new WP_Error('imap_not_found', 'Nachricht nicht gefunden.');
        }

        if ($set) {
            imap_setflag_full($this->connection, (string) $seq, $flag);
        } else {
            imap_clearflag_full($this->connection, (string) $seq, $flag);
        }

        return true;
    }

    /**
     * Gibt Absender-E-Mail einer Nachricht zurück (nur Header, kein Body-Download).
     *
     * @param string $folder
     * @param int    $uid
     * @return string  E-Mail-Adresse des Absenders oder leer
     */
    public function peek_sender($folder, $uid) {
        if ($this->connection === false) {
            return '';
        }
        $mailbox = $this->build_mailbox_string($folder);
        imap_reopen($this->connection, $mailbox);
        $seq = imap_msgno($this->connection, $uid);
        if ($seq === 0) {
            return '';
        }
        $header = imap_headerinfo($this->connection, $seq);
        if (!$header || empty($header->from[0])) {
            return '';
        }
        $from = $header->from[0];
        return strtolower(($from->mailbox ?? '') . '@' . ($from->host ?? ''));
    }

    /**
     * Gibt den vollständigen Inhalt einer einzelnen E-Mail zurück.
     *
     * @param string $folder
     * @param int    $uid          UID der Nachricht
     * @param bool   $allow_images Externe Bilder zulassen?
    * @param bool   $mark_as_read Soll beim Öffnen als gelesen markiert werden?
    * @param bool   $debug_mode   Liefert Verarbeitungsspuren fuer Debugging?
     * @return array|WP_Error
     */
    public function get_email($folder, $uid, $allow_images = false, $mark_as_read = true, $debug_mode = false) {
        if ($this->connection === false) {
            return new WP_Error('imap_not_connected', 'Keine IMAP-Verbindung.');
        }

        $mailbox = $this->build_mailbox_string($folder);
        imap_reopen($this->connection, $mailbox);

        $seq = imap_msgno($this->connection, $uid);
        if ($seq === 0) {
            return new WP_Error('imap_not_found', 'Nachricht nicht gefunden.');
        }

        $header = imap_headerinfo($this->connection, $seq);
        $struct = imap_fetchstructure($this->connection, $seq);
        $body   = $this->fetch_body($seq, $struct);

        $debug_trace = array();
        if ($debug_mode) {
            $debug_trace[] = $this->collect_color_debug_snapshot('after_fetch_body', $body);
        }

        // Eingebettete CID-Bilder in data:-URLs auflösen, damit sie im Browser darstellbar sind.
        $inline_images = $this->collect_inline_images($seq, $struct);
        if (!empty($inline_images)) {
            $body = $this->replace_cid_sources($body, $inline_images);
            if ($debug_mode) {
                $debug_trace[] = $this->collect_color_debug_snapshot('after_replace_cid', $body);
            }
        }

        // Absender-Infos
        $sender_email  = '';
        $sender_domain = '';
        if (!empty($header->from[0])) {
            $from          = $header->from[0];
            $sender_email  = strtolower(($from->mailbox ?? '') . '@' . ($from->host ?? ''));
            $sender_domain = strtolower($from->host ?? '');
        }

        // Externe Bilder blockieren
        $images_blocked = false;
        $images_blocked_count = 0;
        if (!$allow_images) {
            list($body, $images_blocked, $images_blocked_count) = $this->block_external_images($body);
            if ($debug_mode) {
                $debug_trace[] = $this->collect_color_debug_snapshot('after_block_external_images', $body);
            }
        }

        // Optional als gelesen markieren
        if ($mark_as_read) {
            imap_setflag_full($this->connection, (string) $seq, '\\Seen');
        }

        // Anhänge extrahieren
        $attachments = $this->get_attachments($seq, $struct);

        $result = array(
            'uid'            => $uid,
            'subject'        => $this->decode_header($header->subject ?? '(kein Betreff)'),
            'from'           => $this->format_address($header->from ?? array()),
            'to'             => $this->format_address($header->to ?? array()),
            'date'           => isset($header->date) ? date('d.m.Y H:i', strtotime($header->date)) : '',
            'body'           => $body,
            'images_blocked' => $images_blocked,
            'images_blocked_count' => (int) $images_blocked_count,
            'sender_email'   => $sender_email,
            'sender_domain'  => $sender_domain,
            'attachments'    => $attachments,
        );

        if ($debug_mode) {
            $debug_trace[] = $this->collect_color_debug_snapshot('final_output', $body);
            $result['debug_trace'] = $debug_trace;
        }

        return $result;
    }

    // -----------------------------------------------------------------------
    // Hilfsmethoden
    // -----------------------------------------------------------------------

    /**
     * Blockiert externe Bilder in einem HTML-String.
     *
     * src="https://..." wird zu data-ahx-src="..." + 1×1-Platzhalter.
     * Liefert [modifizierter HTML-String, true/false ob externe Ressourcen gefunden].
     *
     * @param string $html
     * @return array [string $html, bool $has_external, int $blocked_count]
     */
    private function block_external_images($html) {
        if ($html === '') {
            return array('', false, 0);
        }

        $has_external = false;
        $blocked_count = 0;
        $placeholder  = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

        // Externe Bilder nur dann blockieren, wenn sie wie Tracking-Pixel aussehen.
        // Normale Content-/Layout-Bilder bleiben erhalten, damit Farbdarstellung stabil bleibt.
        $html = preg_replace_callback(
            '/<img\b[^>]*>/i',
            function ($m) use (&$has_external, &$blocked_count, $placeholder) {
                $tag = $m[0];

                if (!preg_match('/\bsrc=(["\'])(https?:\/\/[^"\'>\s]+)\1/i', $tag, $srcMatch)) {
                    return $tag;
                }

                $srcUrl = $srcMatch[2];
                $has_external = true;

                $width = null;
                $height = null;
                if (preg_match('/\bwidth=(["\']?)(\d+)\1/i', $tag, $wm)) {
                    $width = (int) $wm[2];
                }
                if (preg_match('/\bheight=(["\']?)(\d+)\1/i', $tag, $hm)) {
                    $height = (int) $hm[2];
                }

                $looksLikeTracker = false;
                if ($width !== null && $height !== null && $width <= 5 && $height <= 5) {
                    $looksLikeTracker = true;
                }
                if (!$looksLikeTracker && preg_match('/\bstyle=(["\']).*?(width\s*:\s*[0-5]px|height\s*:\s*[0-5]px).*?\1/i', $tag)) {
                    $looksLikeTracker = true;
                }
                if (!$looksLikeTracker && preg_match('/(pixel|tracking|beacon)/i', $srcUrl)) {
                    $looksLikeTracker = true;
                }

                if (!$looksLikeTracker) {
                    return $tag;
                }

                $blocked_count++;
                $tag = preg_replace(
                    '/\bsrc=(["\'])https?:\/\/[^"\'>\s]+\1/i',
                    'data-ahx-src=$1' . esc_attr($srcUrl) . '$1 src=$1' . $placeholder . '$1',
                    $tag,
                    1
                );

                return $tag;
            },
            $html
        );

        // NOTE:
        // Externe Background-Bilder in style/background werden nicht entfernt,
        // da dies bei einigen Templates zu falschen Fallback-Farben (z.B. rot/gruen)
        // fuehrt und das Layout stark verfälscht.

        $has_external = $blocked_count > 0;
        return array($html, $has_external, $blocked_count);
    }

    /**
     * Baut den IMAP-Mailbox-String auf.
     */
    private function build_mailbox_string($folder) {
        switch ($this->encryption) {
            case 'ssl':
                $flags = '/ssl';
                break;
            case 'tls':
                $flags = '/tls';
                break;
            default:
                $flags = '/notls';
                break;
        }

        $folder_encoded = mb_convert_encoding($folder, 'UTF7-IMAP', 'UTF-8');
        return '{' . $this->host . ':' . $this->port . '/imap' . $flags . '}' . $folder_encoded;
    }

    /**
     * Liest den Body einer Nachricht (Text/HTML bevorzugt).
     */
    private function fetch_body($seq, $struct) {
        if (!isset($struct->parts) || empty($struct->parts)) {
            // Einteilige Nachricht
            $raw = imap_body($this->connection, $seq);
            if ($raw === false) {
                $raw = imap_fetchbody($this->connection, $seq, '1');
            }
            return $this->decode_body($raw ?: '', $struct->encoding ?? 0, $struct->subtype ?? 'PLAIN');
        }

        $html  = '';
        $plain = '';
        foreach ($struct->parts as $index => $part) {
            $part_num = (string) ($index + 1);
            $this->collect_body_parts($seq, $part, $part_num, $html, $plain);
            if ($html !== '') {
                break;
            }
        }

        return $html !== '' ? $html : $plain;
    }

    /**
     * Sucht rekursiv den eigentlichen Mail-Body in MIME-Parts.
     *
     * @param int    $seq
     * @param object $part
     * @param string $part_num
     * @param string $html
     * @param string $plain
     * @return void
     */
    private function collect_body_parts($seq, $part, $part_num, &$html, &$plain) {
        if (!is_object($part)) {
            return;
        }

        if (!empty($part->parts) && is_array($part->parts)) {
            foreach ($part->parts as $child_index => $child_part) {
                $child_num = $part_num . '.' . ($child_index + 1);
                $this->collect_body_parts($seq, $child_part, $child_num, $html, $plain);
                if ($html !== '') {
                    return;
                }
            }
            return;
        }

        if ($this->is_attachment_part($part)) {
            return;
        }

        $subtype = strtoupper((string) ($part->subtype ?? ''));
        if ($subtype !== 'HTML' && $subtype !== 'PLAIN') {
            return;
        }

        $raw = imap_fetchbody($this->connection, $seq, $part_num);
        if ($raw === false || $raw === '') {
            return;
        }

        if ($subtype === 'HTML' && $html === '') {
            $html = $this->decode_body($raw, $part->encoding ?? 0, 'HTML');
            return;
        }

        if ($subtype === 'PLAIN' && $plain === '') {
            $plain = $this->decode_body($raw, $part->encoding ?? 0, 'PLAIN');
        }
    }

    /**
     * Prüft, ob ein MIME-Part als Anhang zu behandeln ist.
     *
     * @param object $part
     * @return bool
     */
    private function is_attachment_part($part) {
        if (!is_object($part)) {
            return false;
        }

        $disposition = strtoupper((string) ($part->disposition ?? ''));
        if (in_array($disposition, array('ATTACHMENT', 'INLINE'), true)) {
            return true;
        }

        foreach (array_merge((array) ($part->dparameters ?? array()), (array) ($part->parameters ?? array())) as $param) {
            $attr = strtoupper((string) ($param->attribute ?? ''));
            if (in_array($attr, array('FILENAME', 'NAME'), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prüft rekursiv, ob eine Nachrichtenstruktur Anhänge enthält.
     *
     * @param object|null $part
     * @return bool
     */
    private function has_attachments($part) {
        if (!$part || !is_object($part)) {
            return false;
        }

        $disposition = strtoupper((string) ($part->disposition ?? ''));
        if (in_array($disposition, array('ATTACHMENT', 'INLINE'), true)) {
            if (!empty($part->dparameters) || !empty($part->parameters)) {
                foreach (array_merge((array) ($part->dparameters ?? array()), (array) ($part->parameters ?? array())) as $param) {
                    $attr = strtoupper((string) ($param->attribute ?? ''));
                    if (in_array($attr, array('FILENAME', 'NAME'), true)) {
                        return true;
                    }
                }
            }
        }

        if (!empty($part->parts) && is_array($part->parts)) {
            foreach ($part->parts as $child) {
                if ($this->has_attachments($child)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Dekodiert einen Body-Teil entsprechend dem Transfer-Encoding.
     */
    private function decode_body($raw, $encoding, $subtype) {
        switch ($encoding) {
            case 1: // 8BIT
                $decoded = $raw;
                break;
            case 2: // BINARY
                $decoded = $raw;
                break;
            case 3: // BASE64
                $decoded = base64_decode($raw);
                break;
            case 4: // QUOTED-PRINTABLE
                $decoded = quoted_printable_decode($raw);
                break;
            default: // 7BIT / OTHER
                $decoded = $raw;
                break;
        }

        $decoded = mb_convert_encoding($decoded, 'UTF-8', mb_detect_encoding($decoded, 'UTF-8, ISO-8859-1, windows-1252', true) ?: 'UTF-8');

        if (strtoupper($subtype) === 'HTML') {
            return $this->sanitize_html_body($decoded);
        }

        // Einige Absender liefern in text/plain pseudo-HTML-Zeilenumbrueche.
        // Diese sicher in echte Newlines umwandeln, bevor escaped wird.
        $decoded = preg_replace('/<br\s*\/?\s*>/i', "\n", $decoded);
        $decoded = preg_replace('/&lt;br\s*\/?\s*&gt;/i', "\n", $decoded);

        return nl2br(esc_html($decoded));
    }

    /**
     * Bereinigt einen HTML-E-Mail-Body für die sichere Darstellung im Frontend.
     *
     * – Extrahiert nur den <body>-Inhalt (falls vorhanden)
     * – Entfernt <head>, <style>, <script>, HTML-Kommentare und bedingte Kommentare
     * – Entfernt on*-Event-Handler-Attribute
     * – Lässt Layout-relevante inline-styles stehen
     *
     * @param string $html
     * @return string
     */
    private function sanitize_html_body($html) {
        $style_blocks = '';
        $wrapper_styles = array();

        // FIRST: Extract inline colors BEFORE wp_kses() strips them!
        // Use separate patterns for double- and single-quoted attributes so single quotes
        // inside double-quoted styles (e.g. font-family: 'Netflix Sans') don't truncate the match.
        $inline_text_colors = array();
        $all_style_contents = array();
        if (preg_match_all('/\bstyle\s*=\s*"([^"]*)"/is', $html, $dq_matches)) {
            $all_style_contents = array_merge($all_style_contents, $dq_matches[1]);
        }
        if (preg_match_all("/\\bstyle\\s*=\\s*'([^']*)'\\s/is", $html, $sq_matches)) {
            $all_style_contents = array_merge($all_style_contents, $sq_matches[1]);
        }
        foreach ($all_style_contents as $style_content) {
            // Extract only text color, not background-color
            if (preg_match('/(?:^|;)\s*color\s*:\s*([^;}]+)/i', $style_content, $color_match)) {
                $color_raw = trim($color_match[1]);
                $normalized = $this->normalize_color_to_hex($color_raw);
                if ($normalized !== '') {
                    $inline_text_colors[] = $normalized;
                }
            }
        }
        
        // If we found inline colors, use the most common color.
        // Do not force white just because a CTA/button uses white text.
        if (!empty($inline_text_colors)) {
            $value_counts = array_count_values($inline_text_colors);
            arsort($value_counts);
            $most_common = key($value_counts);
            $wrapper_styles['color'] = $most_common;
        }

        // Style-Bloecke extrahieren, damit Layout-/Farbdefinitionen erhalten bleiben.
        if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $style_matches) && !empty($style_matches[0])) {
            $style_blocks = $this->sanitize_style_blocks(implode("\n", $style_matches[0]));
            
            // Fallback: if no inline color found, check CSS rules for a text color.
            if (empty($inline_text_colors) && preg_match_all('/(?:^|;)\s*color\s*:\s*([^;}]+)/im', $style_blocks, $css_color_matches)) {
                foreach ($css_color_matches[1] as $color_raw) {
                    $trimmed = trim($color_raw);
                    $normalized = $this->normalize_color_to_hex($trimmed);
                    if ($normalized === '#ffffff' || stripos($trimmed, 'white') !== false) {
                        $wrapper_styles['color'] = '#ffffff';
                        break;
                    }
                }
            }
        }

        // 1. body-Hintergrund sichern und nur <body>-Inhalt verwenden.
        if (preg_match('/<body\b([^>]*)>/is', $html, $body_open)) {
            $body_attrs = (string) ($body_open[1] ?? '');

            if (preg_match('/\sbgcolor=("|\')([^"\']+)\1/i', $body_attrs, $bgm)) {
                $normalized = $this->normalize_color_to_hex($bgm[2]);
                if ($normalized !== '') {
                    $wrapper_styles['background-color'] = $normalized;
                }
            }

            if (preg_match('/\stext=("|\')([^"\']+)\1/i', $body_attrs, $tm)) {
                $normalized = $this->normalize_color_to_hex($tm[2]);
                if ($normalized !== '') {
                    $wrapper_styles['color'] = $normalized;
                }
            }

            if (preg_match('/\sstyle=("|\')(.*?)\1/is', $body_attrs, $sm)) {
                if (preg_match('/(?:^|;)\s*background-color\s*:\s*([^;]+)/i', $sm[2], $bcm)) {
                    $normalized = $this->normalize_color_to_hex(trim($bcm[1]));
                    if ($normalized !== '') {
                        $wrapper_styles['background-color'] = $normalized;
                    }
                }

                if (preg_match('/(?:^|;)\s*color\s*:\s*([^;]+)/i', $sm[2], $cm)) {
                    $normalized = $this->normalize_color_to_hex(trim($cm[1]));
                    if ($normalized !== '') {
                        $wrapper_styles['color'] = $normalized;
                    }
                }
            }
        }

        // 2. Nur <body>-Inhalt verwenden, falls ein vollständiges HTML-Dokument vorliegt
        if (preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $m)) {
            $html = $m[1];
        }

        // 3. Bedingte Outlook-Kommentare entfernen (<!--[if ...]>...</[endif]-->)
        $html = preg_replace('/<!--\[if[^\]]*\]>.*?<!\[endif\]-->/is', '', $html);

        // 4. Alle HTML-Kommentare entfernen
        $html = preg_replace('/<!--.*?-->/s', '', $html);

        // 5. <head>-Block entfernen (falls noch vorhanden)
        $html = preg_replace('/<head[^>]*>.*?<\/head>/is', '', $html);

        // 6. <style>-Blöcke im Body entfernen (werden oben extrahiert und spaeter kontrolliert eingefuegt)
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);

        // 7. <script>-Blöcke entfernen
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);

        // 8. on*-Eventhandler-Attribute entfernen (XSS-Schutz)
        $html = preg_replace('/\s+on\w+\s*=\s*(["\'])[^"\']*\1/i', '', $html);
        $html = preg_replace('/\s+on\w+\s*=\s*[^\s>]+/i', '', $html);

        // 9. javascript:-URLs entfernen
        $html = preg_replace('/\bhref\s*=\s*(["\'])\s*javascript:[^"\']*\1/i', '', $html);

        // 10. Legacy-bgcolor normalisieren (z. B. bgcolor="rgb(15, 15, 15)").
        $html = $this->normalize_legacy_bgcolor($html);
        $html = $this->normalize_legacy_font_color($html);

        // 10a. EXTRACT ALL INLINE COLORS BEFORE wp_kses() strips them!
        $inline_text_colors = $this->extract_all_inline_text_colors($html);
        if (!empty($inline_text_colors) && empty($wrapper_styles['color'])) {
            $value_counts = array_count_values($inline_text_colors);
            arsort($value_counts);
            $wrapper_styles['color'] = key($value_counts);
        }

        // 9. wp_kses mit erweiterter Whitelist für E-Mail-Layout
        $allowed = wp_kses_allowed_html('post');

        // Tabellen-Attribute für E-Mail-Layouts erlauben
        $table_attrs = array(
            'width'       => array(),
            'height'      => array(),
            'cellpadding' => array(),
            'cellspacing' => array(),
            'border'      => array(),
            'align'       => array(),
            'valign'      => array(),
            'bgcolor'     => array(),
            'background'  => array(),
            'style'       => array(),
            'class'       => array(),
            'id'          => array(),
            'colspan'     => array(),
            'rowspan'     => array(),
        );
        foreach (array('table', 'tr', 'td', 'th', 'tbody', 'thead', 'tfoot') as $tag) {
            $allowed[$tag] = $table_attrs;
        }

        // Weitere Layout-Tags
        $layout_attrs = array('style' => array(), 'class' => array(), 'id' => array(),
                      'background' => array(),
                      'width' => array(), 'height' => array(), 'align' => array());
        foreach (array('div', 'span', 'p', 'a', 'img', 'h1', 'h2', 'h3', 'h4',
                       'strong', 'em', 'b', 'i', 'ul', 'ol', 'li', 'br', 'hr', 'font') as $tag) {
            if (!isset($allowed[$tag])) {
                $allowed[$tag] = $layout_attrs;
            } else {
                $allowed[$tag] = array_merge($allowed[$tag], $layout_attrs);
            }
        }

        // <a> darf href und target haben
        $allowed['a']['href']   = array();
        $allowed['a']['target'] = array();
        $allowed['a']['rel']    = array();

        // <img> braucht src, alt, width, height (und data-ahx-src für Bild-Blocking)
        $allowed['img']['src']         = array();
        $allowed['img']['srcset']      = array();
        $allowed['img']['data-ahx-src'] = array();
        $allowed['img']['alt']         = array();
        $allowed['img']['width']       = array();
        $allowed['img']['height']      = array();
        $allowed['img']['style']       = array();
        $allowed['img']['class']       = array();

        $allowed['font']['color']      = array();
        $allowed['font']['face']       = array();
        $allowed['font']['size']       = array();

        $protocols = wp_allowed_protocols();
        if (!in_array('data', $protocols, true)) {
            $protocols[] = 'data';
        }
        if (!in_array('cid', $protocols, true)) {
            $protocols[] = 'cid';
        }

        // Style-Tag explizit erlauben, damit E-Mail-CSS erhalten bleibt.
        $allowed['style'] = array(
            'type' => array(),
            'media' => array(),
        );

        $sanitized_html = wp_kses($html, $allowed, $protocols);
        $sanitized_styles = $style_blocks !== '' ? wp_kses($style_blocks, $allowed, $protocols) : '';
        $inherit_color_style = '';

        // E-Mail-Body kapseln, damit remappte CSS-Selektoren sicher greifen.
        $wrapper_style = '';
        foreach ($wrapper_styles as $property => $value) {
            $wrapper_style .= $property . ': ' . $value . ' !important; ';
        }

        $wrapper_attr = $wrapper_style !== ''
            ? ' style="' . esc_attr(trim($wrapper_style)) . '"'
            : '';

        if (!empty($wrapper_styles['color'])) {
            $inherit_color_style = '<style>'
                . '.ahx-mail-email-body,'
                . '.ahx-mail-email-body table,'
                . '.ahx-mail-email-body tbody,'
                . '.ahx-mail-email-body thead,'
                . '.ahx-mail-email-body tfoot,'
                . '.ahx-mail-email-body tr,'
                . '.ahx-mail-email-body td,'
                . '.ahx-mail-email-body th,'
                . '.ahx-mail-email-body div,'
                . '.ahx-mail-email-body span,'
                . '.ahx-mail-email-body p,'
                . '.ahx-mail-email-body li,'
                . '.ahx-mail-email-body a,'
                . '.ahx-mail-email-body strong,'
                . '.ahx-mail-email-body em,'
                . '.ahx-mail-email-body b,'
                . '.ahx-mail-email-body i,'
                . '.ahx-mail-email-body h1,'
                . '.ahx-mail-email-body h2,'
                . '.ahx-mail-email-body h3,'
                . '.ahx-mail-email-body h4,'
                . '.ahx-mail-email-body h5,'
                . '.ahx-mail-email-body h6,'
                . '.ahx-mail-email-body font'
                . '{color:inherit;}'
                . '</style>';
        }

        return $sanitized_styles . $inherit_color_style . '<div class="ahx-mail-email-body"' . $wrapper_attr . '>' . $sanitized_html . '</div>';
    }

    /**
     * Normalisiert bgcolor-Attribute mit rgb()/rgba() auf hex und sichert
     * die Farbe zusaetzlich als inline background-color ab.
     *
     * @param string $html
     * @return string
     */
    private function normalize_legacy_bgcolor($html) {
        if ($html === '') {
            return '';
        }

        return preg_replace_callback(
            '/<([a-z0-9]+)([^>]*)\sbgcolor=(["\'])([^"\']+)\3([^>]*)>/i',
            function ($m) {
                $tag = $m[1];
                $before = $m[2];
                $quote = $m[3];
                $color = trim($m[4]);
                $after = $m[5];

                $normalized = $this->normalize_color_to_hex($color);
                if ($normalized === '') {
                    return $m[0];
                }

                $attrs = $before . ' bgcolor=' . $quote . $normalized . $quote . $after;

                if (preg_match('/\sstyle=("\')(.*?)\1/is', $attrs, $sm)) {
                    $style_quote = $sm[1];
                    $style = $sm[2];
                    if (!preg_match('/(?:^|;)\s*background-color\s*:/i', $style)) {
                        $style = rtrim($style);
                        if ($style !== '' && substr($style, -1) !== ';') {
                            $style .= ';';
                        }
                        $style .= ' background-color: ' . $normalized . ';';
                    }
                    $attrs = preg_replace('/\sstyle=("\')(.*?)\1/is', ' style=' . $style_quote . $style . $style_quote, $attrs, 1);
                } else {
                    $attrs .= ' style="background-color: ' . esc_attr($normalized) . ';"';
                }

                return '<' . $tag . $attrs . '>';
            },
            $html
        );
    }

    /**
     * Normalisiert Legacy-Fontfarben wie <font color="rgb(...)"> auf Hex.
     *
     * @param string $html
     * @return string
     */
    private function normalize_legacy_font_color($html) {
        if ($html === '') {
            return '';
        }

        return preg_replace_callback(
            '/<font\b([^>]*)\scolor=("|\')([^"\']+)\2([^>]*)>/i',
            function ($m) {
                $normalized = $this->normalize_color_to_hex($m[3]);
                if ($normalized === '') {
                    return $m[0];
                }

                return '<font' . $m[1] . ' color=' . $m[2] . $normalized . $m[2] . $m[4] . '>';
            },
            $html
        );
    }

    /**
     * Konvertiert rgb()/rgba() oder #rgb/#rrggbb nach #rrggbb.
     *
     * @param string $color
     * @return string
     */
    private function normalize_color_to_hex($color) {
        $value = strtolower(trim($color));

        // Remove !important and trailing semicolon
        $value = preg_replace('/\s*!important\s*;?\s*$/', '', $value);
        $value = trim($value);

        if (preg_match('/^#([0-9a-f]{3})$/i', $value, $m)) {
            return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }

        if (preg_match('/^#([0-9a-f]{6})$/i', $value, $m)) {
            return '#' . strtolower($m[1]);
        }

        // Match rgb/rgba with flexible spacing: rgb(255,255,255) or rgb( 255, 255, 255 ) or rgb(255, 255, 255)
        if (preg_match('/^rgba?\s*\(\s*(\d{1,3})\s*,?\s*(\d{1,3})\s*,?\s*(\d{1,3})(?:\s*,?\s*([0-9.]+))?\s*\)$/i', $value, $m)) {
            $r = max(0, min(255, (int) $m[1]));
            $g = max(0, min(255, (int) $m[2]));
            $b = max(0, min(255, (int) $m[3]));
            return sprintf('#%02x%02x%02x', $r, $g, $b);
        }

        return '';
    }

    /**
     * Extrahiert alle Text-Farben aus inline style-Attributen BEVOR wp_kses() diese entfernt.
     *
     * Sucht nach style="...color: VALUE..." in allen Tags und sammelt die Farbwerte.
     *
     * @param string $html
     * @return array Array von Farbwerten [#ffffff, #000000, ...]
     */
    private function extract_all_inline_text_colors($html) {
        $colors = array();
        
        if ($html === '') {
            return $colors;
        }

        // Use separate patterns for double- and single-quoted attributes so single quotes
        // inside double-quoted styles (e.g. font-family: 'Netflix Sans') don't truncate the match.
        $all_style_contents = array();
        if (preg_match_all('/\bstyle\s*=\s*"([^"]*)"/is', $html, $dq_matches)) {
            $all_style_contents = array_merge($all_style_contents, $dq_matches[1]);
        }
        if (preg_match_all("/\\bstyle\\s*=\\s*'([^']*)'\\s/is", $html, $sq_matches)) {
            $all_style_contents = array_merge($all_style_contents, $sq_matches[1]);
        }

        foreach ($all_style_contents as $style_content) {
            if (preg_match('/(?:^|;)\s*color\s*:\s*([^;}]+)/i', $style_content, $color_match)) {
                $normalized = $this->normalize_color_to_hex(trim($color_match[1]));
                if ($normalized !== '') {
                    $colors[] = $normalized;
                }
            }
        }

        return $colors;
    }

    /**
     * Erstellt einen kompakten Debug-Snapshot zur Farbanalyse.
     *
     * @param string $stage
     * @param string $html
     * @return array
     */
    private function collect_color_debug_snapshot($stage, $html) {
        $snapshot = array(
            'stage'            => (string) $stage,
            'length'           => strlen((string) $html),
            'wrapper_color'    => 'NOT SET',
            'white_color_refs' => 0,
            'black_color_refs' => 0,
            'sample_colors'    => array(),
        );

        if ($html === '') {
            return $snapshot;
        }

        if (preg_match('/class="ahx-mail-email-body"[^>]*style="([^"]*)"/', $html, $wm)) {
            if (preg_match('/(?:^|;)\s*color\s*:\s*([^;!]+)/i', $wm[1], $cm)) {
                $snapshot['wrapper_color'] = trim($cm[1]);
            }
        }

        if (preg_match_all('/\bcolor\s*:\s*([^;"\']+)/i', $html, $cm)) {
            $sample = array_slice(array_map('trim', $cm[1]), 0, 8);
            $snapshot['sample_colors'] = array_values(array_unique($sample));
        }

        if (preg_match_all('/(#000\b|#000000\b|rgb\(\s*0\s*,?\s*0\s*,?\s*0\s*\)|\bblack\b)/i', $html, $bm)) {
            $snapshot['black_color_refs'] = count($bm[0]);
        }
        if (preg_match_all('/(#fff\b|#ffffff\b|rgb\(\s*255\s*,?\s*255\s*,?\s*255\s*\)|\bwhite\b)/i', $html, $wm)) {
            $snapshot['white_color_refs'] = count($wm[0]);
        }

        return $snapshot;
    }

    /**
     * Bereinigt Style-Bloecke auf offensichtliche unsichere CSS-Konstrukte.
     *
     * @param string $styles_html
     * @return string
     */
    private function sanitize_style_blocks($styles_html) {
        if ($styles_html === '') {
            return '';
        }

        // CSS-Kommentare entfernen
        $styles_html = preg_replace('/\/\*.*?\*\//s', '', $styles_html);

        // Unsichere CSS-Konstrukte entfernen
        $styles_html = preg_replace('/expression\s*\(/i', '(', $styles_html);
        $styles_html = preg_replace('/javascript\s*:/i', '', $styles_html);
        $styles_html = preg_replace('/-moz-binding\s*:/i', '', $styles_html);
        $styles_html = preg_replace('/behavior\s*:/i', '', $styles_html);

        return $styles_html;
    }

    /**
     * Dekodiert MIME-kodierte Header-Werte.
     */
    private function decode_header($value) {
        $decoded = imap_mime_header_decode($value);
        $result  = '';
        foreach ($decoded as $part) {
            $charset  = ($part->charset === 'default') ? 'UTF-8' : $part->charset;
            $result  .= mb_convert_encoding($part->text, 'UTF-8', $charset);
        }
        return $result;
    }

    /**
     * Formatiert eine Adress-Objekt-Liste zu einem lesbaren String.
     *
     * @param object[] $addresses
     * @return string
     */
    private function format_address($addresses) {
        if (empty($addresses)) {
            return '';
        }
        $parts = array();
        foreach ($addresses as $addr) {
            $name  = isset($addr->personal) ? $this->decode_header($addr->personal) : '';
            $email = isset($addr->mailbox, $addr->host) ? $addr->mailbox . '@' . $addr->host : '';
            $parts[] = $name !== '' ? $name . ' <' . $email . '>' : $email;
        }
        return implode(', ', $parts);
    }

    /**
     * Sammelt eingebettete Bilder (Content-ID) rekursiv aus der MIME-Struktur.
     *
     * @param int         $seq
     * @param object|null $part
     * @param string      $part_num
     * @param array       $map
     * @return array
     */
    private function collect_inline_images($seq, $part, $part_num = '', $map = array()) {
        if (!$part || !is_object($part)) {
            return $map;
        }

        if (!empty($part->parts) && is_array($part->parts)) {
            foreach ($part->parts as $idx => $child) {
                $child_num = ($part_num === '') ? (string) ($idx + 1) : ($part_num . '.' . ($idx + 1));
                $map = $this->collect_inline_images($seq, $child, $child_num, $map);
            }
            return $map;
        }

        $cid_raw = isset($part->id) ? trim((string) $part->id) : '';
        $cid = trim($cid_raw, "<> \t\n\r\0\x0B");
        if ($cid === '' || $part_num === '') {
            return $map;
        }

        $type = (int) ($part->type ?? -1);
        if ($type !== 5) {
            return $map;
        }

        $subtype = strtolower((string) ($part->subtype ?? 'octet-stream'));
        $mime = 'image/' . ($subtype !== '' ? $subtype : 'png');

        $raw = imap_fetchbody($this->connection, $seq, $part_num);
        if ($raw === false || $raw === '') {
            return $map;
        }

        $decoded = $this->decode_body_raw($raw, (int) ($part->encoding ?? 0));
        if ($decoded === '') {
            return $map;
        }

        $map[strtolower($cid)] = 'data:' . $mime . ';base64,' . base64_encode($decoded);
        return $map;
    }

    /**
     * Ersetzt img src="cid:..." durch data:-URLs aus der Inline-Map.
     *
     * @param string $html
     * @param array  $inline_images [cid => data-url]
     * @return string
     */
    private function replace_cid_sources($html, $inline_images) {
        if ($html === '' || empty($inline_images)) {
            return $html;
        }

        $replace_cid = function ($cid_raw) use ($inline_images) {
            $cid = strtolower(trim(rawurldecode($cid_raw), "<> \t\n\r\0\x0B"));
            return isset($inline_images[$cid]) ? $inline_images[$cid] : '';
        };

        // img src="cid:..."
        $html = preg_replace_callback(
            '/\bsrc=("|\')cid:([^"\']+)\1/i',
            function ($m) use ($inline_images) {
                $quote = $m[1];
                $cid = strtolower(trim(rawurldecode($m[2]), "<> \t\n\r\0\x0B"));
                if (!isset($inline_images[$cid])) {
                    return $m[0];
                }
                return 'src=' . $quote . esc_attr($inline_images[$cid]) . $quote;
            },
            $html
        );

        // background="cid:..."
        $html = preg_replace_callback(
            '/\bbackground=("|\')cid:([^"\']+)\1/i',
            function ($m) use ($replace_cid) {
                $quote = $m[1];
                $resolved = $replace_cid($m[2]);
                if ($resolved === '') {
                    return $m[0];
                }
                return 'background=' . $quote . esc_attr($resolved) . $quote;
            },
            $html
        );

        // srcset="cid:... 1x, cid:... 2x"
        $html = preg_replace_callback(
            '/\bsrcset=("|\')(.*?)\1/is',
            function ($m) use ($replace_cid) {
                $quote = $m[1];
                $srcset = $m[2];
                $items = array_map('trim', explode(',', $srcset));
                $out = array();

                foreach ($items as $item) {
                    if ($item === '') {
                        continue;
                    }

                    if (!preg_match('/^cid:([^\s,]+)(\s+.+)?$/i', $item, $parts)) {
                        $out[] = $item;
                        continue;
                    }

                    $resolved = $replace_cid($parts[1]);
                    if ($resolved === '') {
                        $out[] = $item;
                        continue;
                    }

                    $descriptor = isset($parts[2]) ? $parts[2] : '';
                    $out[] = esc_url_raw($resolved) . $descriptor;
                }

                return 'srcset=' . $quote . implode(', ', $out) . $quote;
            },
            $html
        );

        // style="...url(cid:...)..."
        $html = preg_replace_callback(
            '/\bstyle=("|\')(.*?)\1/is',
            function ($m) use ($replace_cid) {
                $quote = $m[1];
                $style = $m[2];

                $style = preg_replace_callback(
                    '/url\(\s*("|\')?cid:([^\)"\']+)\1?\s*\)/i',
                    function ($u) use ($replace_cid) {
                        $resolved = $replace_cid($u[2]);
                        if ($resolved === '') {
                            return $u[0];
                        }
                        return 'url(' . esc_url_raw($resolved) . ')';
                    },
                    $style
                );

                return 'style=' . $quote . $style . $quote;
            },
            $html
        );

        return $html;
    }
    // -----------------------------------------------------------------------
    // Anhänge
    // -----------------------------------------------------------------------

    /**
     * Extrahiert Anhänge aus der E-Mail-Struktur.
     *
     * @param int $seq
     * @param object $struct
     * @return array Array von ['id' => string, 'name' => string, 'size' => int, 'mime' => string]
     */
    private function get_attachments($seq, $struct) {
        $attachments = array();

        if (!isset($struct->parts) || empty($struct->parts)) {
            return $attachments;
        }

        foreach ($struct->parts as $index => $part) {
            $part_id = (string) ($index + 1);

            // Anhang, wenn Filename vorhanden ist
            if (isset($part->dparameters) || isset($part->parameters)) {
                $params = $part->dparameters ?? $part->parameters;
                $filename = '';

                foreach ((array) $params as $param) {
                    if (strtoupper($param->attribute) === 'FILENAME') {
                        $filename = $param->value;
                        break;
                    }
                }

                if ($filename !== '') {
                    $filename = $this->decode_header($filename);
                    $size = (int) ($part->bytes ?? 0);
                    $mime = strtoupper(($part->type ?? 0)) . '/' . strtoupper(($part->subtype ?? 'application'));

                    if ($mime === '0/APPLICATION') {
                        $mime = 'application/octet-stream';
                    }

                    $attachments[] = array(
                        'id'   => $part_id,
                        'name' => $filename,
                        'size' => $size,
                        'mime' => $mime,
                    );
                }
            }
        }

        return $attachments;
    }

    /**
     * Lädt den Body eines Anhangs herunter.
     *
     * @param string $folder
     * @param int $uid
     * @param string $part_id z.B. "2" oder "2.1"
     * @return string|WP_Error Raw body or error
     */
    public function get_attachment_body($folder, $uid, $part_id) {
        if ($this->connection === false) {
            return new WP_Error('imap_not_connected', 'Keine IMAP-Verbindung.');
        }

        $mailbox = $this->build_mailbox_string($folder);
        @imap_reopen($this->connection, $mailbox);

        $seq = imap_msgno($this->connection, $uid);
        if ($seq === 0) {
            return new WP_Error('imap_not_found', 'Nachricht nicht gefunden.');
        }

        $struct = imap_fetchstructure($this->connection, $seq);
        $raw = @imap_fetchbody($this->connection, $seq, $part_id);

        if ($raw === false) {
            return new WP_Error('imap_fetch_failed', 'Anhang konnte nicht geladen werden.');
        }

        // Dekodierung basierend auf Struktur
        if (!isset($struct->parts)) {
            $encoding = $struct->encoding ?? 0;
        } else {
            $parts = explode('.', $part_id);
            $part = $struct->parts[(int) $parts[0] - 1] ?? $struct;
            $encoding = $part->encoding ?? 0;
        }

        return $this->decode_body_raw($raw, $encoding);
    }

    /**
     * Liefert den Rohquelltext einer E-Mail (Header + Body) als RFC822-Text.
     *
     * @param string $folder
     * @param int    $uid
     * @return string|WP_Error
     */
    public function get_raw_email_source($folder, $uid) {
        if ($this->connection === false) {
            return new WP_Error('imap_not_connected', 'Keine IMAP-Verbindung.');
        }

        $mailbox = $this->build_mailbox_string($folder);
        @imap_reopen($this->connection, $mailbox);

        $seq = imap_msgno($this->connection, $uid);
        if ($seq === 0) {
            return new WP_Error('imap_not_found', 'Nachricht nicht gefunden.');
        }

        $header = @imap_fetchheader($this->connection, $seq, FT_PREFETCHTEXT);
        if ($header === false) {
            return new WP_Error('imap_fetch_failed', 'Nachrichtenkopf konnte nicht geladen werden.');
        }

        // Mit FT_PEEK, damit das reine Anzeigen des Quelltexts keine Statusaenderung ausloest.
        $body = @imap_body($this->connection, $seq, FT_PEEK);
        if ($body === false) {
            $body = '';
        }

        if ($body !== '' && strpos($header, "\r\n\r\n") === false && strpos($header, "\n\n") === false) {
            return $header . "\r\n" . $body;
        }

        return $header . $body;
    }

    /**
     * Dekodiert raw body nach Transfer-Encoding.
     *
     * @param string $raw
     * @param int $encoding
     * @return string
     */
    private function decode_body_raw($raw, $encoding) {
        switch ($encoding) {
            case 1: // 8BIT
                return $raw;
            case 2: // BINARY
                return $raw;
            case 3: // BASE64
                return base64_decode($raw);
            case 4: // QUOTED-PRINTABLE
                return quoted_printable_decode($raw);
            default: // 7BIT / OTHER
                return $raw;
        }
    }}
