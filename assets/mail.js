/* global ahxMail, jQuery */
(function ($) {
    'use strict';

    var state = {
        folder:  'INBOX',
        page:    1,
        loading: false,
        accountKey: '',
        debugColorTrace: false,
    };

    var folderList = [];
    var folderAliases = ahxMail.folderAliases || {};
    var accountTrashFolders = {};

    function getDeleteConfirmMessage() {
        var trashFolder = accountTrashFolders[state.accountKey] || '';
        if (trashFolder === '' || trashFolder.toLowerCase() === state.folder.toLowerCase()) {
            return 'Ausgewählte E-Mails und Ordner wirklich unwiederbringlich löschen?';
        }
        return 'Ausgewählte E-Mails wirklich in ' + trashFolder + ' verschieben?';
    }

    function isCurrentFolderTrash() {
        var trashFolder = accountTrashFolders[state.accountKey] || '';
        if (trashFolder === '') {
            return false;
        }
        return trashFolder.toLowerCase() === state.folder.toLowerCase();
    }

    function updateFolderToolbar() {
        if (isCurrentFolderTrash()) {
            $('#ahx-mail-empty-trash').show();
        } else {
            $('#ahx-mail-empty-trash').hide();
        }
    }

    // -----------------------------------------------------------------------
    // Init
    // -----------------------------------------------------------------------
    $(document).ready(function () {
        if (!$('#ahx-mail-app').length) {
            return;
        }

        state.accountKey = $('#ahx-mail-app').data('account-key') || '';
        state.debugColorTrace = window.location.search.indexOf('ahxMailDebugColor=1') !== -1;
        var trashData = $('#ahx-mail-app').data('account-trash');
        if (trashData && typeof trashData === 'object') {
            accountTrashFolders = trashData;
        }

        loadEmails();

        $('#ahx-mail-refresh').on('click', function () {
            state.page = 1;
            loadEmails();
        });

        $('#ahx-mail-back').on('click', function () {
            showListPanel();
        });

        $(document).on('click', '.ahx-mail-folder', function () {
            $('.ahx-mail-folder').removeClass('ahx-mail-folder--active');
            $(this).addClass('ahx-mail-folder--active');
            state.folder = $(this).data('folder');
            state.page   = 1;
            loadEmails();
            updateFolderToolbar();
        });

        $(document).on('change', '#ahx-mail-account-switch', function () {
            state.accountKey = $(this).val() || '';
            state.folder = 'INBOX';
            state.page = 1;
            showListPanel();
            loadEmails();
            updateFolderToolbar();
        });

        // Alle auswählen
        $(document).on('change', '#ahx-mail-check-all', function () {
            var checked = $(this).prop('checked');
            $('#ahx-mail-tbody .ahx-mail-row-check').prop('checked', checked);
            updateBulkToolbar();
        });

        $(document).on('change', '.ahx-mail-row-check', function () {
            updateBulkToolbar();
        });

        // Bulk-Aktionen
        $(document).on('click', '#ahx-mail-bulk-read', function () {
            bulkMark(true);
        });
        $(document).on('click', '#ahx-mail-bulk-unread', function () {
            bulkMark(false);
        });
        $(document).on('click', '#ahx-mail-bulk-delete', function () {
            var msg = getDeleteConfirmMessage();
            if (!confirm(msg)) { return; }
            bulkDelete();
        });
        $(document).on('click', '#ahx-mail-bulk-move', function () {
            var target = $('#ahx-mail-bulk-move-select').val();
            if (!target) { return; }
            bulkMove(target);
        });

        $(document).on('click', '#ahx-mail-empty-trash', function () {
            if (!confirm('Papierkorb wirklich leeren? Diese Aktion kann nicht rückgängig gemacht werden.')) { return; }
            emptyTrash();
        });

        // Detail-Aktionen
        $(document).on('click', '#ahx-mail-detail-delete', function () {
            if (!currentMail) { return; }
            var msg = getDeleteConfirmMessage();
            if (!confirm(msg)) { return; }
            doAction('ahx_wp_mail_delete', {
                folder: state.openFolder || state.folder,
                uids:   [currentMail.uid],
            }, function () {
                showListPanel();
                loadEmails();
            });
        });

        $(document).on('click', '#ahx-mail-detail-move', function () {
            if (!currentMail) { return; }
            var target = $('#ahx-mail-detail-move-select').val();
            if (!target) { return; }
            doAction('ahx_wp_mail_move', {
                folder:    state.openFolder || state.folder,
                uids:      [currentMail.uid],
                to_folder: target,
            }, function () {
                showListPanel();
                loadEmails();
            });
        });
    });

    // -----------------------------------------------------------------------
    // E-Mail-Liste laden
    // -----------------------------------------------------------------------
    function loadEmails(silent) {
        if (state.loading) {
            return;
        }
        state.loading = true;
        if (!silent) {
            setStatus('Lädt…');
        }

        $.post(ahxMail.ajaxUrl, {
            action: 'ahx_wp_mail_fetch_emails',
            nonce:  ahxMail.nonce,
            account_key: state.accountKey,
            folder: state.folder,
            page:   state.page,
        })
        .done(function (resp) {
            if (resp.success) {
                if (resp.data.account_key) {
                    state.accountKey = resp.data.account_key;
                    $('#ahx-mail-account-switch').val(state.accountKey);
                }
                renderFolders(resp.data.folders);
                renderEmailList(resp.data.emails);
                renderPagination(resp.data.emails, resp.data.page);
                if (!silent) {
                    setStatus('');
                }
            } else {
                setStatus('Fehler: ' + (resp.data ? resp.data.message : 'Unbekannter Fehler'));
            }
        })
        .fail(function () {
            if (!silent) {
                setStatus('Verbindungsfehler. Bitte Seite neu laden.');
            }
        })
        .always(function () {
            state.loading = false;
            updateFolderToolbar();
        });
    }

    // -----------------------------------------------------------------------
    // Ordner rendern
    // -----------------------------------------------------------------------
    function sortFolders(folders) {
        var primary = [];
        var secondary = [];

        folders.forEach(function (folder) {
            var isPrimary = /^INBOX$/i.test(folder) || getFolderPriority(folder) < 100;

            if (isPrimary) {
                primary.push(folder);
            } else {
                secondary.push(folder);
            }
        });

        primary.sort(function (left, right) {
            return getFolderPriority(left) - getFolderPriority(right) || left.localeCompare(right, undefined, { sensitivity: 'base' });
        });

        secondary.sort(function (left, right) {
            return left.localeCompare(right, undefined, { sensitivity: 'base' });
        });

        return primary.concat(secondary);
    }

    function getFolderPriority(folder) {
        if (/^INBOX$/i.test(folder)) {
            return 0;
        }
        if (matchesFolderAlias(folder, folderAliases.sent || [])) {
            return 1;
        }
        if (matchesFolderAlias(folder, folderAliases.drafts || [])) {
            return 2;
        }
        if (matchesFolderAlias(folder, folderAliases.trash || [])) {
            return 3;
        }
        if (matchesFolderAlias(folder, folderAliases.spam || [])) {
            return 4;
        }
        if (matchesFolderAlias(folder, folderAliases.archive || [])) {
            return 5;
        }
        return 100;
    }

    function matchesFolderAlias(folder, aliases) {
        return aliases.some(function (alias) {
            var escaped = alias.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            var pattern = new RegExp('(^|[\\/.])' + escaped + '$', 'i');
            return pattern.test(folder);
        });
    }

    function renderFolders(folders) {
        if (!Array.isArray(folders) || folders.length === 0) {
            return;
        }

        folders = sortFolders(folders.slice());

        var $list = $('#ahx-mail-folders');
        $list.empty();

        folders.forEach(function (folder) {
            var label = folder === 'INBOX'
                ? 'Posteingang'
                : folder;
            var active = folder === state.folder ? ' ahx-mail-folder--active' : '';
            $list.append(
                $('<li>')
                    .addClass('ahx-mail-folder' + active)
                    .attr('data-folder', folder)
                    .text(label)
            );
        });

        // Ordner für Verschieben-Dropdowns merken und befüllen
        folderList = folders;
        populateMoveDropdowns(folders);
    }

    // -----------------------------------------------------------------------
    // E-Mail-Liste rendern
    // -----------------------------------------------------------------------
    function renderEmailList(emails) {
        var $tbody = $('#ahx-mail-tbody');
        $tbody.empty();
        $('#ahx-mail-check-all').prop('checked', false);
        updateBulkToolbar();

        if (!Array.isArray(emails) || emails.length === 0) {
            $tbody.append('<tr><td colspan="4">Keine E-Mails in diesem Ordner.</td></tr>');
            return;
        }

        emails.forEach(function (mail) {
            var unreadClass = mail.seen ? '' : 'ahx-mail-unread';
            var $row = $('<tr>')
                .addClass(unreadClass)
                .data('uid', mail.uid)
                .data('folder', state.folder);

            var $check = $('<input>').attr('type', 'checkbox').addClass('ahx-mail-row-check').val(mail.uid);
            $row.append($('<td>').addClass('ahx-mail-col-check').append($check).on('click', function (e) { e.stopPropagation(); }));
            $row.append($('<td>').text(mail.from || '(unbekannt)'));
            var $subjectCell = $('<td>').addClass('ahx-mail-col-subject');
            if (mail.has_attachments) {
                $subjectCell.append(
                    $('<span>')
                        .addClass('ahx-mail-attachment-indicator')
                        .attr('title', 'Enthaelt Anhaenge')
                        .text('📎')
                );
            }
            $subjectCell.append(
                $('<span>')
                    .addClass('ahx-mail-subject-text')
                    .text(mail.subject || '(kein Betreff)')
            );
            $row.append($subjectCell);
            $row.append($('<td>').addClass('ahx-mail-col-date').text(mail.date || ''));

            $row.on('click', function () {
                loadEmail($(this).data('uid'), $(this).data('folder'));
                state.openFolder = $(this).data('folder');
            });

            $tbody.append($row);
        });
    }

    // -----------------------------------------------------------------------
    // Pagination
    // -----------------------------------------------------------------------
    function renderPagination(emails, currentPage) {
        var $pag = $('#ahx-mail-pagination');
        $pag.empty();

        if (!emails || emails.length === 0) {
            return;
        }

        var total    = emails[0] ? emails[0].total : 0;
        var perPage  = emails.length;
        var maxPage  = Math.ceil(total / (perPage || 1));

        var $prev = $('<button>').text('‹ Zurück').prop('disabled', currentPage <= 1);
        var $info = $('<span>').text(' Seite ' + currentPage + ' von ' + maxPage + ' ');
        var $next = $('<button>').text('Weiter ›').prop('disabled', currentPage >= maxPage);

        $prev.on('click', function () {
            if (state.page > 1) {
                state.page--;
                loadEmails();
            }
        });

        $next.on('click', function () {
            if (state.page < maxPage) {
                state.page++;
                loadEmails();
            }
        });

        $pag.append($prev).append($info).append($next);
    }

    // -----------------------------------------------------------------------
    // Einzelne E-Mail laden
    // -----------------------------------------------------------------------
    function loadEmail(uid, folder) {
        setStatus('Lädt Nachricht…');

        $.post(ahxMail.ajaxUrl, {
            action: 'ahx_wp_mail_fetch_email',
            nonce:  ahxMail.nonce,
            account_key: state.accountKey,
            folder: folder,
            uid:    uid,
            debug:  state.debugColorTrace ? 1 : 0,
        })
        .done(function (resp) {
            if (resp.success) {
                if (resp.data.account_key) {
                    state.accountKey = resp.data.account_key;
                    $('#ahx-mail-account-switch').val(state.accountKey);
                }

                if (state.debugColorTrace && resp.data.debug_trace) {
                    if (window.console && typeof window.console.groupCollapsed === 'function') {
                        console.groupCollapsed('[AHX Mail] Color debug trace UID ' + uid);
                        console.table(resp.data.debug_trace);
                        console.groupEnd();
                    } else if (window.console && typeof window.console.log === 'function') {
                        console.log('[AHX Mail] Color debug trace UID ' + uid, resp.data.debug_trace);
                    }
                }

                showDetailPanel(resp.data);
                if ((resp.data.mark_read_mode || 'open') === 'open') {
                    $('#ahx-mail-tbody tr').filter(function () {
                        return $(this).data('uid') === uid;
                    }).removeClass('ahx-mail-unread');

                    // Hintergrund-Sync: Liste mit Serverstatus aktualisieren, ohne UI-Status zu überschreiben.
                    loadEmails(true);
                }
                setStatus('');
            } else {
                setStatus('Fehler: ' + (resp.data ? resp.data.message : 'Unbekannter Fehler'));
            }
        })
        .fail(function () {
            setStatus('Verbindungsfehler.');
        });
    }

    // -----------------------------------------------------------------------
    // Detail-Panel anzeigen
    // -----------------------------------------------------------------------
    var currentMail = null;

    function showDetailPanel(mail) {
        currentMail = mail;

        $('#ahx-mail-detail-subject').text(mail.subject || '(kein Betreff)');
        $('#ahx-mail-detail-from').text(mail.from || '');
        $('#ahx-mail-detail-to').text(mail.to || '');
        $('#ahx-mail-detail-date').text(mail.date || '');
        $('#ahx-mail-detail-body').html(mail.body || '');
        applyBodyTextColorInheritance();

        // Anhänge anzeigen
        if (Array.isArray(mail.attachments) && mail.attachments.length > 0) {
            renderAttachments(mail.attachments);
            $('#ahx-mail-attachments').show();
        } else {
            $('#ahx-mail-attachments').hide();
        }

        if (mail.images_blocked) {
            var blockedCount = parseInt(mail.images_blocked_count || 0, 10);
            var imageBarText = 'Externe Bilder wurden blockiert.';
            if (blockedCount > 0) {
                imageBarText = blockedCount + ' externe Bildquelle' + (blockedCount === 1 ? '' : 'n') + ' wurden blockiert.';
            }
            $('#ahx-mail-image-bar .ahx-mail-image-bar__text').text(imageBarText);
            $('#ahx-mail-image-bar').show();
        } else {
            $('#ahx-mail-image-bar').hide();
        }

        $('#ahx-mail-list-panel').hide();
        $('#ahx-mail-detail-panel').show();

        // Beim Öffnen immer an den Anfang der Nachricht springen.
        scrollToMailTop();

        // Verschieben-Dropdown im Detail-Panel befüllen
        populateMoveDropdowns(folderList);

        // Gelesen-Schalter je nach Modus aktivieren/deaktivieren
        var mode = mail.mark_read_mode || 'open';
        if (mode === 'manual') {
            $('#ahx-mail-detail-mark-unread').text('✓ Als gelesen markieren').off('click').on('click', function () {
                if (!currentMail) { return; }
                doAction('ahx_wp_mail_mark', {
                    folder: state.openFolder || state.folder,
                    uid:    currentMail.uid,
                    read:   1
                }, function () {
                    setStatus('Als gelesen markiert.');
                    loadEmails();
                });
            });
        } else {
            $('#ahx-mail-detail-mark-unread').text('○ Als ungelesen markieren').off('click').on('click', function () {
                if (!currentMail) { return; }
                doAction('ahx_wp_mail_mark', {
                    folder: state.openFolder || state.folder,
                    uid:    currentMail.uid,
                    read:   0
                }, function () {
                    setStatus('Als ungelesen markiert.');
                    loadEmails();
                });
            });
        }
    }

    function applyBodyTextColorInheritance() {
        var $root = $('#ahx-mail-detail-body .ahx-mail-email-body');
        if (!$root.length) {
            return;
        }

        var rootColor = window.getComputedStyle($root[0]).color;
        if (!rootColor) {
            return;
        }

        var selectors = 'table,tbody,thead,tfoot,tr,td,th,div,span,p,li,a,strong,em,b,i,h1,h2,h3,h4,h5,h6,font';
        $root.find(selectors).each(function () {
            var node = this;
            var inlineStyle = (node.getAttribute('style') || '').toLowerCase();
            var hasInlineColor = /(^|;)\s*color\s*:/i.test(inlineStyle);
            var hasLegacyColor = node.hasAttribute('color');

            if (hasInlineColor || hasLegacyColor) {
                return;
            }

            var computed = window.getComputedStyle(node).color;
            if (!computed) {
                return;
            }

            // Nur überschreiben, wenn die aktuelle Farbe nicht bereits der Wrapper-Farbe entspricht.
            if (computed !== rootColor) {
                node.style.color = rootColor;
            }
        });
    }

    // -----------------------------------------------------------------------
    // Bilder einmal anzeigen (client-seitig, kein AJAX)
    // -----------------------------------------------------------------------
    $(document).on('click', '#ahx-mail-show-once', function () {
        var $body = $('#ahx-mail-detail-body');

        // data-ahx-src → src
        $body.find('[data-ahx-src]').each(function () {
            $(this).attr('src', $(this).attr('data-ahx-src')).removeAttr('data-ahx-src');
        });

        $('#ahx-mail-image-bar').hide();
    });

    // -----------------------------------------------------------------------
    // Absender dauerhaft freigeben
    // -----------------------------------------------------------------------
    $(document).on('click', '#ahx-mail-allow-sender', function () {
        if (!currentMail || !currentMail.sender_email) {
            return;
        }
        allowImages('sender', currentMail.sender_email);
    });

    $(document).on('click', '#ahx-mail-allow-domain', function () {
        if (!currentMail || !currentMail.sender_domain) {
            return;
        }
        allowImages('domain', currentMail.sender_domain);
    });

    function allowImages(type, value) {
        setStatus('Speichere…');
        $.post(ahxMail.ajaxUrl, {
            action: 'ahx_wp_mail_allow_images',
            nonce:  ahxMail.nonce,
            account_key: state.accountKey,
            type:   type,
            value:  value,
        })
        .done(function (resp) {
            if (resp.success) {
                // E-Mail neu laden – jetzt mit Bildern
                loadEmail(currentMail.uid, state.openFolder || state.folder);
            } else {
                setStatus('Fehler beim Speichern.');
            }
        })
        .fail(function () {
            setStatus('Verbindungsfehler.');
        });
    }

    function showListPanel() {
        $('#ahx-mail-detail-panel').hide();
        $('#ahx-mail-list-panel').show();
    }

    function scrollToMailTop() {
        var $panel = $('#ahx-mail-detail-panel');
        var $body = $('#ahx-mail-detail-body');
        var $app = $('#ahx-mail-app');

        // Sofortiger Reset für Panel/Body
        $panel.scrollTop(0);
        $body.scrollTop(0);

        // Falls das Browserfenster gescrollt ist: zum Beginn des Mail-Widgets springen
        if ($app.length) {
            $('html, body').scrollTop($app.offset().top || 0);
            $app[0].scrollIntoView({ block: 'start', inline: 'nearest' });
        }

        // Zweiter Durchlauf nach dem Rendern/Bild-Layout
        setTimeout(function () {
            $panel.scrollTop(0);
            $body.scrollTop(0);
            if ($app.length) {
                $('html, body').scrollTop($app.offset().top || 0);
            }
        }, 0);
    }

    // -----------------------------------------------------------------------
    // Status-Text
    // -----------------------------------------------------------------------
    function setStatus(text) {
        $('#ahx-mail-status').text(text);
    }

    // -----------------------------------------------------------------------
    // Bulk-Toolbar
    // -----------------------------------------------------------------------
    function getSelectedUids() {
        var uids = [];
        $('#ahx-mail-tbody .ahx-mail-row-check:checked').each(function () {
            uids.push(parseInt($(this).val(), 10));
        });
        return uids;
    }

    function updateBulkToolbar() {
        var uids = getSelectedUids();
        if (uids.length > 0) {
            $('#ahx-mail-bulk-toolbar').show();
            $('#ahx-mail-bulk-info').text(uids.length + ' ausgewählt');
        } else {
            $('#ahx-mail-bulk-toolbar').hide();
        }
    }

    function bulkMark(asRead) {
        var uids = getSelectedUids();
        if (!uids.length) { return; }
        var done = 0;
        setStatus('Wird markiert…');
        uids.forEach(function (uid) {
            doAction('ahx_wp_mail_mark', {
                folder: state.folder,
                uid:    uid,
                read:   asRead ? 1 : 0,
            }, function () {
                done++;
                if (done === uids.length) {
                    loadEmails();
                }
            });
        });
    }

    function bulkDelete() {
        var uids = getSelectedUids();
        if (!uids.length) { return; }
        doAction('ahx_wp_mail_delete', {
            folder: state.folder,
            uids:   uids,
        }, function () {
            loadEmails();
        });
    }

    function bulkMove(toFolder) {
        var uids = getSelectedUids();
        if (!uids.length || !toFolder) { return; }
        doAction('ahx_wp_mail_move', {
            folder:    state.folder,
            uids:      uids,
            to_folder: toFolder,
        }, function () {
            $('#ahx-mail-bulk-move-select').val('');
            loadEmails();
        });
    }

    function emptyTrash() {
        setStatus('Papierkorb wird geleert...');
        doAction('ahx_wp_mail_empty_trash', {
            account_key: state.accountKey,
            folder: state.folder,
        }, function (data) {
            var msg = data && data.message ? data.message : 'Papierkorb geleert.';
            setStatus(msg);
            loadEmails();
        });
    }

    function populateMoveDropdowns(folders) {
        var selectors = ['#ahx-mail-bulk-move-select', '#ahx-mail-detail-move-select'];
        selectors.forEach(function (sel) {
            var $sel = $(sel);
            var first = $sel.find('option:first').text();
            $sel.empty().append($('<option>').val('').text(first));
            (folders || []).forEach(function (f) {
                if (f !== state.folder) {
                    $sel.append($('<option>').val(f).text(f === 'INBOX' ? 'Posteingang' : f));
                }
            });
        });
    }

    // -----------------------------------------------------------------------
    // Anhänge rendern und herunterladen
    // -----------------------------------------------------------------------
    function renderAttachments(attachments) {
        var $list = $('#ahx-mail-attachments-list');
        $list.empty();

        if (!Array.isArray(attachments) || attachments.length === 0) {
            return;
        }

        attachments.forEach(function (att) {
            var sizeStr = formatBytes(att.size || 0);
            var $item = $('<li>').addClass('ahx-mail-attachment-item');
            
            var $link = $('<button>')
                .addClass('ahx-mail-attachment-download')
                .attr('data-attachment-id', att.id)
                .text('⬇ ' + att.name + ' (' + sizeStr + ')');

            $link.on('click', function (e) {
                e.preventDefault();
                downloadAttachment(att);
            });

            $item.append($link);
            $list.append($item);
        });
    }

    function formatBytes(bytes) {
        if (bytes === 0) return '0 B';
        var k = 1024;
        var sizes = ['B', 'KB', 'MB', 'GB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }

    function downloadAttachment(attachment) {
        if (!currentMail) {
            setStatus('Fehler: Mail nicht geladen.');
            return;
        }

        var $btn = $('[data-attachment-id="' + attachment.id + '"]');
        var originalText = $btn.text();
        $btn.disabled = true;
        $btn.text('⏳ Wird heruntergeladen…');

        $.post(ahxMail.ajaxUrl, {
            action:       'ahx_wp_mail_download_attachment',
            nonce:        ahxMail.nonce,
            account_key:  state.accountKey,
            folder:       state.openFolder || state.folder,
            uid:          currentMail.uid,
            part_id:      attachment.id,
            filename:     attachment.name,
        })
        .done(function (resp) {
            if (resp.success && resp.data.data) {
                // base64 dekodieren und download
                var binaryString = atob(resp.data.data);
                var bytes = new Uint8Array(binaryString.length);
                for (var i = 0; i < binaryString.length; i++) {
                    bytes[i] = binaryString.charCodeAt(i);
                }
                var blob = new Blob([bytes], { type: attachment.mime });
                var url = URL.createObjectURL(blob);
                
                var $a = $('<a>')
                    .attr('href', url)
                    .attr('download', resp.data.filename)
                    .hide();
                $('body').append($a);
                $a[0].click();
                $a.remove();
                
                URL.revokeObjectURL(url);
                setStatus('Anhang heruntergeladen.');
            } else {
                setStatus('Fehler: ' + (resp.data ? resp.data.message : 'Download fehlgeschlagen'));
            }
        })
        .fail(function () {
            setStatus('Verbindungsfehler beim Download.');
        })
        .always(function () {
            $btn.disabled = false;
            $btn.text(originalText);
        });
    }

    // -----------------------------------------------------------------------
    // Generische Aktion (AJAX POST)
    // -----------------------------------------------------------------------
    function doAction(action, params, onSuccess) {
        setStatus('Wird ausgeführt…');
        $.post(ahxMail.ajaxUrl, $.extend({ action: action, nonce: ahxMail.nonce, account_key: state.accountKey }, params))
            .done(function (resp) {
                if (resp.success) {
                    setStatus('');
                    if (typeof onSuccess === 'function') { onSuccess(); }
                } else {
                    setStatus('Fehler: ' + (resp.data ? resp.data.message : 'Unbekannter Fehler'));
                }
            })
            .fail(function () {
                setStatus('Verbindungsfehler.');
            });
    }

}(jQuery));
