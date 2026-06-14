/* global ahxMail, jQuery */
(function ($) {
    'use strict';

    var state = {
        folder:  'INBOX',
        page:    1,
        loading: false,
        pendingReload: false,
        pendingReloadSilent: true,
        accountKey: '',
        debugColorTrace: false,
    };

    var folderList = [];
    var folderAliases = ahxMail.folderAliases || {};
    var accountTrashFolders = {};
    var folderStats = {};
    var recommendedMoveFolders = [];
    var userRules = [];

    function deleteCurrentMail() {
        if (!currentMail) { return; }
        var msg = getDeleteConfirmMessage();
        if (!confirm(msg)) { return; }
        var deletedUid = currentMail.uid;
        doAction('ahx_wp_mail_delete', {
            folder: state.openFolder || state.folder,
            uids:   [currentMail.uid],
        }, function () {
            removeMailRowFromList(deletedUid);
            currentMail = null;
            hideStickyActions();
            showListPanel();
            loadEmails();
        });
    }

    function archiveCurrentMail() {
        if (!currentMail) { return; }
        var archiveFolder = getArchiveFolderForCurrentMail();
        if (!archiveFolder) {
            setStatus('Archivordner konnte nicht bestimmt werden.');
            return;
        }

        var sourceFolder = (state.openFolder || state.folder || '').toString();
        if (sourceFolder.toLowerCase() === archiveFolder.toLowerCase()) {
            setStatus('E-Mail ist bereits im Zielarchiv.');
            return;
        }

        doAction('ahx_wp_mail_move', {
            folder:    state.openFolder || state.folder,
            uids:      [currentMail.uid],
            to_folder: archiveFolder,
        }, function () {
            setStatus('Nach ' + archiveFolder + ' archiviert.');
            removeMailRowFromList(currentMail.uid);
            currentMail = null;
            hideStickyActions();
            showListPanel();
            loadEmails();
        });
    }

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
        loadRules();

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
            syncRuleAccountSelection(true);
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
        $(document).on('click', '#ahx-mail-bulk-archive', function () {
            bulkArchive();
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
            deleteCurrentMail();
        });

        $(document).on('click', '#ahx-mail-detail-move', function () {
            if (!currentMail) { return; }
            var target = $('#ahx-mail-detail-move-select').val();
            if (!target || target === '__show_all__') { return; }
            var movedUid = currentMail.uid;
            doAction('ahx_wp_mail_move', {
                folder:    state.openFolder || state.folder,
                uids:      [currentMail.uid],
                to_folder: target,
            }, function () {
                removeMailRowFromList(movedUid);
                currentMail = null;
                hideStickyActions();
                showListPanel();
                loadEmails();
            });
        });

        $(document).on('click', '#ahx-mail-detail-archive', function () {
            archiveCurrentMail();
        });

        $(document).on('click', '#ahx-mail-sticky-delete', function () {
            deleteCurrentMail();
        });

        $(document).on('click', '#ahx-mail-sticky-archive', function () {
            archiveCurrentMail();
        });

        $(document).on('click', '#ahx-mail-sticky-prev', function () {
            navigateToAdjacentMail(-1);
        });

        $(document).on('click', '#ahx-mail-sticky-next', function () {
            navigateToAdjacentMail(1);
        });

        $('#ahx-mail-detail-panel').on('scroll', function () {
            updateStickyActionsVisibility();
        });

        // Quelltext anzeigen
        $(document).on('click', '#ahx-mail-detail-show-source', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (!currentMail) { return; }
            fetchMailSource(function (source) {
                $('#ahx-mail-source-pre').text(source || '');
                $('#ahx-mail-source-modal').show();
            });
        });

        // Quelltext herunterladen
        $(document).on('click', '#ahx-mail-detail-download-source', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (!currentMail) { return; }
            fetchMailSource(function (source) {
                var filename = 'mail-' + (currentMail.uid || 'source') + '.eml';
                var blob = new Blob([source || ''], { type: 'message/rfc822;charset=utf-8' });
                var url = URL.createObjectURL(blob);
                var $a = $('<a>').attr('href', url).attr('download', filename).hide();
                $('body').append($a);
                $a[0].click();
                $a.remove();
                URL.revokeObjectURL(url);
                setStatus('Quelltext heruntergeladen.');
            });
        });

        $(document).on('click', '#ahx-mail-source-close, #ahx-mail-source-close-backdrop', function () {
            $('#ahx-mail-source-modal').hide();
        });

        $(document).on('change', '#ahx-mail-rule-action', function () {
            toggleRuleMoveRow();
        });

        $(document).on('change', '#ahx-mail-detail-move-select', function () {
            var selected = ($(this).val() || '').toString();
            if (selected === '__show_all__') {
                fillDetailMoveSelectAll(folderList, true);
                setStatus('Alle Verzeichnisse geladen. Bitte Zielordner wählen.');
            }
        });

        $(document).on('click', '#ahx-mail-rule-fill-from-mail', function () {
            prefillRuleFromCurrentMail();
        });

        $(document).on('click', '#ahx-mail-rule-save', function () {
            saveRuleFromBuilder();
        });

        $(document).on('click', '.ahx-mail-rule-delete', function () {
            var index = parseInt($(this).data('index'), 10);
            if (isNaN(index)) { return; }
            deleteRule(index);
        });

        syncRuleAccountSelection(false);
    });

    // -----------------------------------------------------------------------
    // E-Mail-Liste laden
    // -----------------------------------------------------------------------
    function loadEmails(silent) {
        if (state.loading) {
            state.pendingReload = true;
            state.pendingReloadSilent = state.pendingReloadSilent && !!silent;
            return;
        }
        state.loading = true;
        state.pendingReload = false;
        state.pendingReloadSilent = true;
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
                folderStats = (resp.data.folder_stats && typeof resp.data.folder_stats === 'object') ? resp.data.folder_stats : {};
                renderFolders(resp.data.folders, folderStats);
                renderEmailList(resp.data.emails);
                renderPagination(resp.data.emails, resp.data.page);
                renderCurrentFolderStats(resp.data.current_folder_stats, resp.data.emails);
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

            if (state.pendingReload) {
                var queuedSilent = state.pendingReloadSilent;
                loadEmails(queuedSilent);
            }
        });
    }

    function getArchiveFolderForCurrentMail() {
        if (!currentMail) {
            return '';
        }

        var fallbackYear = new Date().getFullYear();
        var dateText = (currentMail.date || '').toString();
        var year = fallbackYear;

        var dotMatch = dateText.match(/\b\d{2}\.\d{2}\.(\d{4})\b/);
        if (dotMatch && dotMatch[1]) {
            year = parseInt(dotMatch[1], 10);
        } else {
            var yearMatch = dateText.match(/\b(19|20)\d{2}\b/);
            if (yearMatch && yearMatch[0]) {
                year = parseInt(yearMatch[0], 10);
            }
        }

        if (!year || year < 1900 || year > 3000) {
            year = fallbackYear;
        }

        return 'Archives/' + year;
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

    function renderFolders(folders, stats) {
        if (!Array.isArray(folders) || folders.length === 0) {
            return;
        }

        folders = sortFolders(folders.slice());

        var $list = $('#ahx-mail-folders');
        $list.empty();
        stats = stats && typeof stats === 'object' ? stats : {};

        folders.forEach(function (folder) {
            var label = folder === 'INBOX'
                ? 'Posteingang'
                : folder;
            var active = folder === state.folder ? ' ahx-mail-folder--active' : '';
            var folderStat = stats[folder] || {};
            var unread = parseInt(folderStat.unread || 0, 10);

            var $item = $('<li>')
                .addClass('ahx-mail-folder' + active)
                .attr('data-folder', folder);

            $item.append(
                $('<span>')
                    .addClass('ahx-mail-folder-label')
                    .text(label)
            );

            $item.append(
                $('<span>')
                    .addClass('ahx-mail-folder-count' + (active ? ' ahx-mail-folder-count--active' : ''))
                    .attr('title', 'Ungelesene Nachrichten')
                    .text(isNaN(unread) ? '0' : String(unread))
            );

            $list.append(
                $item
            );
        });

        // Ordner für Verschieben-Dropdowns merken und befüllen
        folderList = folders;
        populateMoveDropdowns(folders);

        if (currentMail) {
            loadDetailMoveRecommendations();
        }
    }

    function renderCurrentFolderStats(currentStats, emails) {
        var $target = $('#ahx-mail-folder-stats');
        if (!$target.length) {
            return;
        }

        var stats = currentStats && typeof currentStats === 'object' ? currentStats : {};
        var total = parseInt(stats.total, 10);
        var unread = parseInt(stats.unread, 10);

        if (isNaN(total)) {
            total = Array.isArray(emails) && emails[0] ? parseInt(emails[0].total || 0, 10) : 0;
        }
        if (isNaN(unread)) {
            unread = 0;
        }

        if (isNaN(total)) {
            total = 0;
        }

        $target.text('Nachrichten gesamt: ' + total + ' | Ungelesen: ' + unread);
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

        updateStickyNavigationState();
    }

    function removeMailRowFromList(uid) {
        var $tbody = $('#ahx-mail-tbody');
        $tbody.find('tr').filter(function () {
            return parseInt($(this).data('uid'), 10) === parseInt(uid, 10);
        }).remove();

        var hasMailRows = $tbody.find('tr').filter(function () {
            return typeof $(this).data('uid') !== 'undefined';
        }).length > 0;

        if (!hasMailRows) {
            $tbody.empty().append('<tr><td colspan="4">Keine E-Mails in diesem Ordner.</td></tr>');
        }
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
        .fail(function (xhr) {
            var serverMessage = '';
            if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                serverMessage = xhr.responseJSON.data.message;
            }

            if (serverMessage) {
                setStatus('Fehler: ' + serverMessage);
            } else {
                setStatus('Verbindungsfehler.');
            }
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
        renderMailBody(mail.body || '');

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
        updateStickyActionsVisibility();
        updateStickyNavigationState();

        // Verschieben-Dropdown im Detail-Panel befüllen
        fillDetailMoveSelectAll(folderList, false);
        loadDetailMoveRecommendations();

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

    function getCurrentMailRowIndex() {
        if (!currentMail) {
            return -1;
        }

        var rows = $('#ahx-mail-tbody tr').filter(function () {
            return typeof $(this).data('uid') !== 'undefined';
        });

        var currentUid = parseInt(currentMail.uid, 10);
        var foundIndex = -1;
        rows.each(function (index) {
            if (parseInt($(this).data('uid'), 10) === currentUid) {
                foundIndex = index;
                return false;
            }
        });

        return foundIndex;
    }

    function updateStickyNavigationState() {
        var $prev = $('#ahx-mail-sticky-prev');
        var $next = $('#ahx-mail-sticky-next');
        if (!$prev.length || !$next.length) {
            return;
        }

        var rows = $('#ahx-mail-tbody tr').filter(function () {
            return typeof $(this).data('uid') !== 'undefined';
        });
        var currentIndex = getCurrentMailRowIndex();
        var hasCurrent = currentIndex !== -1;

        $prev.prop('disabled', !hasCurrent || currentIndex <= 0);
        $next.prop('disabled', !hasCurrent || currentIndex >= rows.length - 1);
    }

    function navigateToAdjacentMail(direction) {
        if (!currentMail) {
            return;
        }

        var rows = $('#ahx-mail-tbody tr').filter(function () {
            return typeof $(this).data('uid') !== 'undefined';
        });
        var currentIndex = getCurrentMailRowIndex();
        if (currentIndex === -1) {
            return;
        }

        var targetIndex = currentIndex + direction;
        if (targetIndex < 0 || targetIndex >= rows.length) {
            return;
        }

        var $target = $(rows.get(targetIndex));
        state.openFolder = $target.data('folder');
        loadEmail($target.data('uid'), $target.data('folder'));
    }

    function updateStickyActionsVisibility() {
        var $panel = $('#ahx-mail-detail-panel');
        var $actions = $('.ahx-mail-detail-actions');
        var $sticky = $('#ahx-mail-sticky-actions');
        if (!$panel.length || !$actions.length || !$sticky.length || !$panel.is(':visible')) {
            return;
        }

        var panelTop = $panel.scrollTop();
        var actionsTop = $actions.position().top;
        var actionsBottom = actionsTop + $actions.outerHeight();
        var isVisible = actionsBottom > panelTop && actionsTop < (panelTop + $panel.innerHeight());

        if (isVisible) {
            hideStickyActions();
        } else {
            $sticky.show();
            updateStickyNavigationState();
        }
    }

    function hideStickyActions() {
        $('#ahx-mail-sticky-actions').hide();
    }

    function renderMailBody(bodyHtml) {
        var $container = $('#ahx-mail-detail-body');
        $container.empty();

        var iframe = document.createElement('iframe');
        iframe.className = 'ahx-mail-detail-iframe';
        iframe.setAttribute('title', 'E-Mail-Inhalt');
        iframe.setAttribute('scrolling', 'no');

        $container.append(iframe);

        var doc = iframe.contentWindow.document;
        doc.open();
        doc.write(buildMailIframeDocument(bodyHtml));
        doc.close();

        syncMailIframeHeight(iframe);

        $(iframe).on('load', function () {
            syncMailIframeHeight(iframe);
        });
    }

    function buildMailIframeDocument(bodyHtml) {
        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            + '<meta name="viewport" content="width=device-width, initial-scale=1">'
            + '<style>'
            + 'html,body{margin:0;padding:0;background:transparent;overflow-x:auto;}'
            + 'body{font-family:Arial,Helvetica,sans-serif;}'
            + 'img{max-width:100%;height:auto;}'
            + '</style>'
            + '</head><body>'
            + bodyHtml
            + '</body></html>';
    }

    function syncMailIframeHeight(iframe) {
        if (!iframe || !iframe.contentWindow || !iframe.contentWindow.document) {
            return;
        }

        var doc = iframe.contentWindow.document;
        var body = doc.body;
        var html = doc.documentElement;
        var height = Math.max(
            body ? body.scrollHeight : 0,
            html ? html.scrollHeight : 0,
            body ? body.offsetHeight : 0,
            html ? html.offsetHeight : 0
        );

        iframe.style.height = Math.max(200, height) + 'px';
    }

    // -----------------------------------------------------------------------
    // Bilder einmal anzeigen (client-seitig, kein AJAX)
    // -----------------------------------------------------------------------
    $(document).on('click', '#ahx-mail-show-once', function () {
        var iframe = $('#ahx-mail-detail-body .ahx-mail-detail-iframe').get(0);
        if (!iframe || !iframe.contentWindow || !iframe.contentWindow.document) {
            return;
        }

        var $body = $(iframe.contentWindow.document.body);

        // data-ahx-src → src
        $body.find('[data-ahx-src]').each(function () {
            $(this).attr('src', $(this).attr('data-ahx-src')).removeAttr('data-ahx-src');
        });

        syncMailIframeHeight(iframe);

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
        hideStickyActions();
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

    function bulkArchive() {
        var uids = getSelectedUids();
        if (!uids.length) { return; }

        doAction('ahx_wp_mail_archive', {
            folder: state.folder,
            uids: uids,
        }, function () {
            // Direkt visuell entfernen, danach vom Server synchronisieren.
            uids.forEach(function (uid) {
                removeMailRowFromList(uid);
            });
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
        var selectors = ['#ahx-mail-bulk-move-select'];
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

        // Rule-Builder Move-To synchron halten
        var $ruleMove = $('#ahx-mail-rule-move-to');
        if ($ruleMove.length) {
            var previous = $ruleMove.val();
            $ruleMove.empty().append($('<option>').val('').text('Verschieben nach…'));
            (folders || []).forEach(function (f) {
                $ruleMove.append($('<option>').val(f).text(f === 'INBOX' ? 'Posteingang' : f));
            });
            if (previous) {
                $ruleMove.val(previous);
            }
        }
    }

    function fillDetailMoveSelectAll(folders, preservePlaceholder) {
        var $sel = $('#ahx-mail-detail-move-select');
        if (!$sel.length) {
            return;
        }

        var first = preservePlaceholder ? ($sel.find('option:first').text() || 'Verschieben nach…') : 'Verschieben nach…';
        var sourceFolder = (state.openFolder || state.folder || '').toString().toLowerCase();

        $sel.empty().append($('<option>').val('').text(first));
        $sel.append($('<option>').prop('disabled', true).text('--- Alle Verzeichnisse ---'));
        (folders || []).forEach(function (f) {
            if (!f) { return; }
            if (f.toLowerCase() === sourceFolder) { return; }
            $sel.append($('<option>').val(f).text(f === 'INBOX' ? 'Posteingang' : f));
        });
    }

    function fillDetailMoveSelectRecommended(recommended, allFolders) {
        if (!Array.isArray(recommended) || recommended.length === 0) {
            fillDetailMoveSelectAll(allFolders, false);
            return;
        }

        var $sel = $('#ahx-mail-detail-move-select');
        if (!$sel.length) {
            return;
        }

        var sourceFolder = (state.openFolder || state.folder || '').toString().toLowerCase();
        var seen = {};
        var appended = 0;
        var hadCurrentFolderHit = false;

        $sel.empty().append($('<option>').val('').text('Empfohlenes Verzeichnis wählen…'));
        $sel.append($('<option>').prop('disabled', true).text('--- Empfohlen ---'));
        recommended.forEach(function (row) {
            if (!row || !row.folder) { return; }
            var folder = String(row.folder);
            if (folder.toLowerCase() === sourceFolder) {
                hadCurrentFolderHit = true;
                return;
            }
            if (seen[folder]) { return; }
            seen[folder] = true;
            var cnt = parseInt(row.count || 0, 10);
            var label = folder === 'INBOX' ? 'Posteingang' : folder;
            if (!isNaN(cnt) && cnt > 0) {
                label += ' (' + cnt + ')';
            }
            $sel.append($('<option>').val(folder).text(label));
            appended++;
        });

        if (appended === 0 && hadCurrentFolderHit) {
            $sel.append($('<option>').prop('disabled', true).text('Treffer nur im aktuellen Ordner'));
        }

        $sel.append($('<option>').val('__show_all__').text('Anderes Verzeichnis wählen…'));
    }

    function loadDetailMoveRecommendations() {
        if (!currentMail) {
            fillDetailMoveSelectAll(folderList, false);
            return;
        }

        var senderEmail = (currentMail.sender_email || '').toString().trim().toLowerCase();
        if (!senderEmail) {
            fillDetailMoveSelectAll(folderList, false);
            return;
        }

        $.post(ahxMail.ajaxUrl, {
            action: 'ahx_wp_mail_move_recommendations',
            nonce: ahxMail.nonce,
            account_key: state.accountKey,
            folder: state.openFolder || state.folder,
            sender_email: senderEmail,
        })
        .done(function (resp) {
            if (!resp.success || !resp.data) {
                fillDetailMoveSelectAll(folderList, false);
                return;
            }

            var allFolders = Array.isArray(resp.data.folders) && resp.data.folders.length ? resp.data.folders : folderList;
            recommendedMoveFolders = Array.isArray(resp.data.recommended) ? resp.data.recommended : [];
            fillDetailMoveSelectRecommended(recommendedMoveFolders, allFolders);
        })
        .fail(function () {
            fillDetailMoveSelectAll(folderList, false);
        });
    }

    function toggleRuleMoveRow() {
        var action = $('#ahx-mail-rule-action').val();
        if (action === 'move') {
            $('#ahx-mail-rule-move-row').show();
        } else {
            $('#ahx-mail-rule-move-row').hide();
        }
    }

    function loadRules() {
        $.post(ahxMail.ajaxUrl, {
            action: 'ahx_wp_mail_get_rules',
            nonce: ahxMail.nonce,
        })
        .done(function (resp) {
            if (resp.success && resp.data && Array.isArray(resp.data.rules)) {
                userRules = resp.data.rules;
                renderRules();
            }
        });
    }

    function renderRules() {
        var $list = $('#ahx-mail-rules-list');
        if (!$list.length) {
            return;
        }
        $list.empty();

        if (!Array.isArray(userRules) || userRules.length === 0) {
            $list.append($('<li>').addClass('ahx-mail-rule-item').text('Noch keine Regeln gespeichert.'));
            return;
        }

        userRules.forEach(function (rule, index) {
            var text = '[' + (rule.folder || 'INBOX') + '] ';
            var accountLabel = getRuleAccountLabel(rule.account_key || '');
            if (accountLabel) {
                text = '[Konto: ' + accountLabel + '] ' + text;
            }
            if (rule.from_contains) {
                text += 'Von enthält "' + rule.from_contains + '" ';
            }
            if (rule.to_contains) {
                text += 'An enthält "' + rule.to_contains + '" ';
            }
            if (rule.subject_contains) {
                text += 'Betreff enthält "' + rule.subject_contains + '" ';
            }
            var actionLabel = rule.action;
            if (rule.action === 'archive') {
                actionLabel = 'archivieren (Archives/<Jahr>)';
            }
            text += '→ ' + actionLabel;
            if (rule.action === 'move' && rule.move_to_folder) {
                text += ' (' + rule.move_to_folder + ')';
            }

            var $item = $('<li>').addClass('ahx-mail-rule-item');
            $item.append($('<span>').addClass('ahx-mail-rule-text').text(text));
            $item.append(
                $('<button>')
                    .addClass('ahx-mail-btn ahx-mail-btn--sm ahx-mail-rule-delete')
                    .attr('type', 'button')
                    .attr('data-index', index)
                    .text('Löschen')
            );
            $list.append($item);
        });
    }

    function prefillRuleFromCurrentMail() {
        if (!currentMail) {
            setStatus('Keine geöffnete Mail zum Vorbelegen.');
            return;
        }

        var from = (currentMail.from || '').toString();
        var to = (currentMail.to || '').toString();
        var subject = (currentMail.subject || '').toString();

        // E-Mail-Adresse aus "Name <mail@domain>" extrahieren
        var match = from.match(/<([^>]+)>/);
        var fromValue = match ? match[1] : from;

        $('#ahx-mail-rule-folder').val(state.openFolder || state.folder || 'INBOX');
        syncRuleAccountSelection(true);
        $('#ahx-mail-rule-from').val(fromValue);
        $('#ahx-mail-rule-to').val(to);
        $('#ahx-mail-rule-subject').val(subject);
    }

    function syncRuleAccountSelection(force) {
        var $sel = $('#ahx-mail-rule-account');
        if (!$sel.length) {
            return;
        }

        if (force || !$sel.val()) {
            $sel.val(state.accountKey || $sel.find('option:first').val() || '');
        }
    }

    function getRuleAccountLabel(accountKey) {
        if (!accountKey) {
            return '';
        }

        var $sel = $('#ahx-mail-rule-account');
        if ($sel.length) {
            var $opt = $sel.find('option[value="' + accountKey.replace(/"/g, '\\"') + '"]');
            if ($opt.length) {
                return ($opt.text() || '').trim() || accountKey;
            }
        }

        return accountKey;
    }

    function saveRuleFromBuilder() {
        var action = ($('#ahx-mail-rule-action').val() || '').trim();
        var selectedAccountKey = ($('#ahx-mail-rule-account').val() || state.accountKey || '').trim();
        var rule = {
            enabled: true,
            account_key: selectedAccountKey,
            folder: ($('#ahx-mail-rule-folder').val() || 'INBOX').trim(),
            from_contains: ($('#ahx-mail-rule-from').val() || '').trim(),
            to_contains: ($('#ahx-mail-rule-to').val() || '').trim(),
            subject_contains: ($('#ahx-mail-rule-subject').val() || '').trim(),
            action: action,
            move_to_folder: ($('#ahx-mail-rule-move-to').val() || '').trim(),
        };

        if (!rule.account_key) {
            setStatus('Bitte ein Konto für die Regel auswählen.');
            return;
        }

        if (!rule.from_contains && !rule.to_contains && !rule.subject_contains) {
            setStatus('Bitte mindestens eines der Filterkriterien ausfüllen (Von, An oder Betreff).');
            return;
        }
        if (rule.action === 'move' && !rule.move_to_folder) {
            setStatus('Bitte Zielordner für "Verschieben" wählen.');
            return;
        }

        $.post(ahxMail.ajaxUrl, {
            action: 'ahx_wp_mail_add_rule',
            nonce: ahxMail.nonce,
            rule: JSON.stringify(rule),
        })
        .done(function (resp) {
            if (resp.success && resp.data && Array.isArray(resp.data.rules)) {
                userRules = resp.data.rules;
                renderRules();
                setStatus('Regel gespeichert.');
            } else {
                setStatus('Fehler: ' + (resp.data ? resp.data.message : 'Regel konnte nicht gespeichert werden.'));
            }
        })
        .fail(function () {
            setStatus('Verbindungsfehler beim Speichern der Regel.');
        });
    }

    function deleteRule(index) {
        $.post(ahxMail.ajaxUrl, {
            action: 'ahx_wp_mail_delete_rule',
            nonce: ahxMail.nonce,
            index: index,
        })
        .done(function (resp) {
            if (resp.success && resp.data && Array.isArray(resp.data.rules)) {
                userRules = resp.data.rules;
                renderRules();
                setStatus('Regel gelöscht.');
            } else {
                setStatus('Fehler: ' + (resp.data ? resp.data.message : 'Regel konnte nicht gelöscht werden.'));
            }
        })
        .fail(function () {
            setStatus('Verbindungsfehler beim Löschen der Regel.');
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

    function fetchMailSource(onSuccess) {
        if (!currentMail) {
            setStatus('Fehler: Mail nicht geladen.');
            return;
        }

        setStatus('Lade Quelltext...');
        $.post(ahxMail.ajaxUrl, {
            action:      'ahx_wp_mail_fetch_source',
            nonce:       ahxMail.nonce,
            account_key: state.accountKey,
            folder:      state.openFolder || state.folder,
            uid:         currentMail.uid,
        })
        .done(function (resp) {
            if (resp.success && resp.data && typeof resp.data.source === 'string') {
                if (resp.data.account_key) {
                    state.accountKey = resp.data.account_key;
                    $('#ahx-mail-account-switch').val(state.accountKey);
                }
                setStatus('');
                if (typeof onSuccess === 'function') {
                    onSuccess(resp.data.source);
                }
            } else {
                setStatus('Fehler: ' + (resp.data ? resp.data.message : 'Quelltext konnte nicht geladen werden.'));
            }
        })
        .fail(function () {
            setStatus('Verbindungsfehler beim Laden des Quelltexts.');
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
