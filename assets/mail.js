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
        ruleProcessedOnly: false,
    };

    var folderList = [];
    var folderAliases = ahxMail.folderAliases || {};
    var accountTrashFolders = {};
    var folderStats = {};
    var recommendedMoveFolders = [];
    var userRules = [];
    var attachmentFlagsByUid = {};
    var attachmentFlagsOrder = [];
    var detailCacheByKey = {};
    var detailCacheOrder = [];
    var rulesReturnToDetail = false;
    var editingRuleIndex = -1;
    var folderSearchTerm = '';
    var favoriteFolders = [];
    var recentFolders = [];
    var expandedFolderNodes = {};
    var stickyActionsInBody = false;
    var currentPageEmails = [];

    var CACHE_LIMITS = {
        detailItems: 100,
        attachmentFlagItems: 2000
    };

    var LIST_CACHE_PREFIX = 'ahx_wp_mail:list:';

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
        loadFolderSidebarPreferences();

        loadEmails();
        loadRules();

        $('#ahx-mail-refresh').on('click', function () {
            state.page = 1;
            loadEmails();
        });

        $(document).on('change', '#ahx-mail-filter-rule-processed', function () {
            state.ruleProcessedOnly = $(this).prop('checked');
            renderCurrentEmailView();
        });

        $('#ahx-mail-back').on('click', function () {
            showListPanel(true);
        });

        $(document).on('click', '#ahx-mail-nav-rules', function (e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            showRulesPanel(false);
        });

        $(document).on('click', '#ahx-mail-nav-rules-overview', function (e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            showRulesPanel(false);
        });

        $(document).on('click', '#ahx-mail-nav-rules-quick', function (e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            showRuleBuilder();
            showRulesPanel(true);
        });

        $(document).on('click', '.ahx-mail-folder-row', function () {
            var folderName = $(this).data('folder');
            if (typeof folderName === 'undefined' || folderName === null || folderName === '') {
                return;
            }
            showListPanel(true);
            state.folder = folderName;
            state.page   = 1;
            rememberRecentFolder(folderName);
            loadEmails();
            updateFolderToolbar();
        });

        $(document).on('click', '.ahx-mail-folder-toggle', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var nodeKey = ($(this).data('nodeKey') || '').toString();
            if (!nodeKey) {
                return;
            }
            toggleFolderNode(nodeKey);
            renderFolders(folderList, folderStats);
        });

        $(document).on('click', '.ahx-mail-folder-favorite', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var folderName = ($(this).data('folder') || '').toString();
            if (!folderName) {
                return;
            }
            toggleFavoriteFolder(folderName);
            renderFolders(folderList, folderStats);
        });

        $(document).on('change', '#ahx-mail-account-switch', function () {
            state.accountKey = $(this).val() || '';
            state.folder = 'INBOX';
            state.page = 1;
            folderSearchTerm = '';
            $('#ahx-mail-folder-search').val('');
            loadFolderSidebarPreferences();
            syncRuleAccountSelection(true);
            showListPanel(true);
            loadEmails();
            updateFolderToolbar();
        });

        $(document).on('input', '#ahx-mail-folder-search', function () {
            folderSearchTerm = ($(this).val() || '').toString().trim().toLowerCase();
            renderFolders(folderList, folderStats);
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

        $(window).on('scroll', function () {
            updateStickyActionsVisibility();
        });

        $(window).on('resize orientationchange', function () {
            ensureStickyActionsPlacement();
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

        $(document).on('click', '#ahx-mail-detail-rules-toggle', function () {
            showRuleBuilder();
            showRulesPanel(true);
        });

        $(document).on('click', '#ahx-mail-rules-panel-close', function () {
            hideRulesPanel();
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
            showRuleBuilder();
            prefillRuleFromCurrentMail();
        });

        $(document).on('click', '#ahx-mail-rule-add-group', function () {
            addRuleConditionGroup();
        });

        $(document).on('click', '.ahx-mail-rule-group-remove', function () {
            $(this).closest('.ahx-mail-rule-group').remove();
        });

        $(document).on('click', '#ahx-mail-rule-cancel', function () {
            resetRuleBuilder();
        });

        $(document).on('click', '#ahx-mail-rule-new', function () {
            resetRuleBuilder();
            showRuleBuilder();
        });

        $(document).on('click', '#ahx-mail-rule-save', function () {
            saveRuleFromBuilder();
        });

        $(document).on('click', '.ahx-mail-rule-edit', function () {
            var index = parseInt($(this).data('index'), 10);
            if (isNaN(index)) { return; }
            editRule(index);
        });

        $(document).on('click', '.ahx-mail-rule-delete', function () {
            var index = parseInt($(this).data('index'), 10);
            if (isNaN(index)) { return; }
            deleteRule(index);
        });

        resetRuleBuilder();
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

        var cacheKey = getListCacheKey();
        if (!silent) {
            var cachedPayload = readListCache(cacheKey);
            if (cachedPayload) {
                applyListPayload(cachedPayload, true);
                setStaleIndicator(true);
            } else {
                setStaleIndicator(false);
            }
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
                applyListPayload(resp.data, false);
                writeListCache(cacheKey, resp.data);
                setStaleIndicator(false);
                if (!silent) {
                    setStatus('');
                }
            } else {
                setStaleIndicator(false);
                setStatus('Fehler: ' + (resp.data ? resp.data.message : 'Unbekannter Fehler'));
            }
        })
        .fail(function () {
            setStaleIndicator(false);
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

    function applyListPayload(data, fromCache) {
        if (!data || typeof data !== 'object') {
            return;
        }

        if (data.account_key) {
            state.accountKey = data.account_key;
            $('#ahx-mail-account-switch').val(state.accountKey);
        }

        folderStats = (data.folder_stats && typeof data.folder_stats === 'object') ? data.folder_stats : {};
        currentPageEmails = Array.isArray(data.emails) ? data.emails : [];
        renderFolders(data.folders, folderStats);
        renderCurrentEmailView(data.page || state.page);
        renderCurrentFolderStats(data.current_folder_stats, data.emails);

        if (data.attachment_flags && typeof data.attachment_flags === 'object') {
            Object.keys(data.attachment_flags).forEach(function (uid) {
                rememberAttachmentFlag(uid, data.attachment_flags[uid]);
            });
            applyAttachmentFlagsToRows(data.attachment_flags);
        }

        fetchDeferredFolderStats(data.deferred_stats_folders || []);
        fetchAttachmentFlagsForVisibleRows(data.emails, fromCache);
    }

    function getVisibleEmailsFromCurrentPage() {
        if (!Array.isArray(currentPageEmails)) {
            return [];
        }

        if (!state.ruleProcessedOnly) {
            return currentPageEmails;
        }

        return currentPageEmails.filter(function (mail) {
            return !!(mail && mail.flagged);
        });
    }

    function renderCurrentEmailView(currentPage) {
        var allEmails = Array.isArray(currentPageEmails) ? currentPageEmails : [];
        var visibleEmails = getVisibleEmailsFromCurrentPage();
        var emptyMessage = 'Keine E-Mails in diesem Ordner.';

        if (state.ruleProcessedOnly && allEmails.length > 0 && visibleEmails.length === 0) {
            emptyMessage = 'Keine regelbearbeiteten E-Mails auf dieser Seite.';
        }

        renderEmailList(visibleEmails, emptyMessage);
        renderPagination(allEmails, currentPage || state.page);
    }

    function fetchDeferredFolderStats(folders) {
        if (!Array.isArray(folders) || folders.length === 0) {
            return;
        }

        var missing = folders.filter(function (folder) {
            return folder && (!folderStats[folder] || typeof folderStats[folder] !== 'object');
        });

        if (missing.length === 0) {
            return;
        }

        $.post(ahxMail.ajaxUrl, {
            action: 'ahx_wp_mail_fetch_folder_stats',
            nonce: ahxMail.nonce,
            account_key: state.accountKey,
            folders: missing,
        })
        .done(function (resp) {
            if (!resp.success || !resp.data || typeof resp.data.folder_stats !== 'object') {
                return;
            }

            Object.keys(resp.data.folder_stats).forEach(function (folder) {
                folderStats[folder] = resp.data.folder_stats[folder];
            });

            if (folderList && folderList.length) {
                renderFolders(folderList, folderStats);
            }
            renderCurrentFolderStats(folderStats[state.folder] || {}, null);
        });
    }

    function getListCacheKey() {
        return LIST_CACHE_PREFIX + [
            state.accountKey || '',
            state.folder || 'INBOX',
            state.page || 1
        ].join(':');
    }

    function getListCacheMaxAgeMs() {
        var folder = String(state.folder || 'INBOX').toLowerCase();
        var page = parseInt(state.page || 1, 10);
        var total = getKnownCurrentFolderTotal();
        if (isNaN(page) || page < 1) {
            page = 1;
        }

        var volumeBonus = 0;
        if (total >= 5000) {
            volumeBonus = 90000;
        } else if (total >= 1000) {
            volumeBonus = 45000;
        } else if (total >= 250) {
            volumeBonus = 15000;
        }

        if (folder === 'inbox') {
            return page === 1 ? 10000 : ((page <= 3 ? 20000 : 45000) + Math.min(30000, volumeBonus));
        }

        if (folder.indexOf('archive') === 0 || folder.indexOf('archives/') === 0) {
            return (page === 1 ? 60000 : 180000) + volumeBonus;
        }

        if (/(trash|papierkorb|spam|junk)/i.test(folder)) {
            return (page === 1 ? 30000 : 90000) + Math.min(60000, volumeBonus);
        }

        return (page === 1 ? 20000 : 60000) + Math.min(45000, volumeBonus);
    }

    function getKnownCurrentFolderTotal() {
        var stats = folderStats && folderStats[state.folder] ? folderStats[state.folder] : null;
        var total = stats ? parseInt(stats.total || 0, 10) : 0;
        if (!isNaN(total) && total > 0) {
            return total;
        }

        var text = ($('#ahx-mail-folder-stats').text() || '').match(/Nachrichten gesamt:\s*(\d+)/i);
        if (text && text[1]) {
            total = parseInt(text[1], 10);
            if (!isNaN(total) && total > 0) {
                return total;
            }
        }

        var firstRowTotal = $('#ahx-mail-tbody tr').first().data('total');
        total = parseInt(firstRowTotal || 0, 10);
        return isNaN(total) ? 0 : total;
    }

    function setStaleIndicator(show) {
        var $indicator = $('#ahx-mail-stale-indicator');
        if (!$indicator.length) {
            return;
        }

        if (show) {
            $indicator.show();
        } else {
            $indicator.hide();
        }
    }

    function readListCache(cacheKey) {
        try {
            var raw = sessionStorage.getItem(cacheKey);
            if (!raw) {
                return null;
            }
            var parsed = JSON.parse(raw);
            if (!parsed || !parsed.ts || !parsed.payload) {
                return null;
            }
            if ((Date.now() - parsed.ts) > getListCacheMaxAgeMs()) {
                sessionStorage.removeItem(cacheKey);
                return null;
            }
            return parsed.payload;
        } catch (e) {
            return null;
        }
    }

    function writeListCache(cacheKey, payload) {
        try {
            sessionStorage.setItem(cacheKey, JSON.stringify({
                ts: Date.now(),
                payload: payload
            }));
        } catch (e) {
            // Ignore storage quota / private mode errors.
        }
    }

    function getRememberedAttachmentFlag(uid) {
        uid = String(uid || '');
        if (!uid || !attachmentFlagsByUid.hasOwnProperty(uid)) {
            return null;
        }

        var idx = attachmentFlagsOrder.indexOf(uid);
        if (idx !== -1) {
            attachmentFlagsOrder.splice(idx, 1);
        }
        attachmentFlagsOrder.push(uid);

        return !!attachmentFlagsByUid[uid];
    }

    function rememberAttachmentFlag(uid, value) {
        uid = String(uid || '');
        if (!uid) {
            return;
        }

        if (attachmentFlagsByUid.hasOwnProperty(uid)) {
            var idx = attachmentFlagsOrder.indexOf(uid);
            if (idx !== -1) {
                attachmentFlagsOrder.splice(idx, 1);
            }
        }

        attachmentFlagsByUid[uid] = !!value;
        attachmentFlagsOrder.push(uid);

        while (attachmentFlagsOrder.length > CACHE_LIMITS.attachmentFlagItems) {
            var oldestUid = attachmentFlagsOrder.shift();
            if (oldestUid && attachmentFlagsByUid.hasOwnProperty(oldestUid)) {
                delete attachmentFlagsByUid[oldestUid];
            }
        }
    }

    function fetchAttachmentFlagsForVisibleRows(emails, fromCache) {
        if (!Array.isArray(emails) || emails.length === 0) {
            return;
        }

        var uids = [];
        emails.forEach(function (mail) {
            if (!mail || !mail.uid) {
                return;
            }
            var uid = String(mail.uid);
            var rememberedFlag = getRememberedAttachmentFlag(uid);
            if (rememberedFlag !== null) {
                mail.has_attachments = rememberedFlag;
                return;
            }
            uids.push(parseInt(uid, 10));
        });

        if (uids.length === 0) {
            return;
        }

        $.post(ahxMail.ajaxUrl, {
            action: 'ahx_wp_mail_fetch_attachment_flags',
            nonce: ahxMail.nonce,
            account_key: state.accountKey,
            folder: state.folder,
            uids: uids,
        })
        .done(function (resp) {
            if (!resp.success || !resp.data || typeof resp.data.flags !== 'object') {
                return;
            }

            Object.keys(resp.data.flags).forEach(function (uid) {
                rememberAttachmentFlag(uid, resp.data.flags[uid]);
            });
            applyAttachmentFlagsToRows(resp.data.flags);
        });
    }

    function getCachedDetail(cacheKey) {
        if (!cacheKey || !detailCacheByKey.hasOwnProperty(cacheKey)) {
            return null;
        }

        var idx = detailCacheOrder.indexOf(cacheKey);
        if (idx !== -1) {
            detailCacheOrder.splice(idx, 1);
        }
        detailCacheOrder.push(cacheKey);

        return detailCacheByKey[cacheKey];
    }

    function putCachedDetail(cacheKey, value) {
        if (!cacheKey || !value || typeof value !== 'object') {
            return;
        }

        if (detailCacheByKey.hasOwnProperty(cacheKey)) {
            var idx = detailCacheOrder.indexOf(cacheKey);
            if (idx !== -1) {
                detailCacheOrder.splice(idx, 1);
            }
        }

        detailCacheByKey[cacheKey] = value;
        detailCacheOrder.push(cacheKey);

        while (detailCacheOrder.length > CACHE_LIMITS.detailItems) {
            var oldestKey = detailCacheOrder.shift();
            if (oldestKey && detailCacheByKey.hasOwnProperty(oldestKey)) {
                delete detailCacheByKey[oldestKey];
            }
        }
    }

    function evictCachedDetail(cacheKey) {
        if (!cacheKey || !detailCacheByKey.hasOwnProperty(cacheKey)) {
            return;
        }

        delete detailCacheByKey[cacheKey];
        var idx = detailCacheOrder.indexOf(cacheKey);
        if (idx !== -1) {
            detailCacheOrder.splice(idx, 1);
        }
    }

    function applyAttachmentFlagsToRows(flags) {
        var map = flags || {};
        $('#ahx-mail-tbody tr').each(function () {
            var $row = $(this);
            var uid = String($row.data('uid') || '');
            if (!uid || !map.hasOwnProperty(uid)) {
                return;
            }

            var has = !!map[uid];
            var $subjectCell = $row.find('.ahx-mail-col-subject');
            $subjectCell.find('.ahx-mail-attachment-indicator').remove();
            if (has) {
                $subjectCell.prepend(
                    $('<span>')
                        .addClass('ahx-mail-attachment-indicator')
                        .attr('title', 'Enthaelt Anhaenge')
                        .text('📎')
                );
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

    function getFolderStorageKey(suffix) {
        return 'ahx_wp_mail:' + suffix + ':' + (state.accountKey || 'default');
    }

    function readFolderStorageArray(key) {
        try {
            var raw = window.localStorage.getItem(key);
            var decoded = JSON.parse(raw || '[]');
            return Array.isArray(decoded) ? decoded : [];
        } catch (error) {
            return [];
        }
    }

    function readFolderStorageObject(key) {
        try {
            var raw = window.localStorage.getItem(key);
            var decoded = JSON.parse(raw || '{}');
            return decoded && typeof decoded === 'object' ? decoded : {};
        } catch (error) {
            return {};
        }
    }

    function persistFolderSidebarPreferences() {
        try {
            window.localStorage.setItem(getFolderStorageKey('favorite-folders'), JSON.stringify(favoriteFolders));
            window.localStorage.setItem(getFolderStorageKey('recent-folders'), JSON.stringify(recentFolders));
            window.localStorage.setItem(getFolderStorageKey('expanded-folders'), JSON.stringify(expandedFolderNodes));
        } catch (error) {
            // Ignore storage errors.
        }
    }

    function loadFolderSidebarPreferences() {
        favoriteFolders = readFolderStorageArray(getFolderStorageKey('favorite-folders'));
        recentFolders = readFolderStorageArray(getFolderStorageKey('recent-folders'));
        expandedFolderNodes = readFolderStorageObject(getFolderStorageKey('expanded-folders'));
    }

    function rememberRecentFolder(folderName) {
        recentFolders = recentFolders.filter(function (folder) {
            return folder !== folderName;
        });
        recentFolders.unshift(folderName);
        recentFolders = recentFolders.slice(0, 8);
        persistFolderSidebarPreferences();
    }

    function toggleFavoriteFolder(folderName) {
        if (favoriteFolders.indexOf(folderName) !== -1) {
            favoriteFolders = favoriteFolders.filter(function (folder) {
                return folder !== folderName;
            });
        } else {
            favoriteFolders.unshift(folderName);
        }
        persistFolderSidebarPreferences();
    }

    function toggleFolderNode(nodeKey) {
        expandedFolderNodes[nodeKey] = !expandedFolderNodes[nodeKey];
        persistFolderSidebarPreferences();
    }

    function isFolderFavorite(folderName) {
        return favoriteFolders.indexOf(folderName) !== -1;
    }

    function getFolderSegments(folder) {
        if (folder.indexOf('/') !== -1) {
            return folder.split('/');
        }
        if (folder.indexOf('.') !== -1) {
            return folder.split('.');
        }
        return [folder];
    }

    function getFolderDelimiter(folder) {
        if (folder.indexOf('/') !== -1) {
            return '/';
        }
        if (folder.indexOf('.') !== -1) {
            return '.';
        }
        return '/';
    }

    function getFolderLabel(folderOrSegment, fullPath) {
        var target = typeof fullPath === 'string' ? fullPath : folderOrSegment;
        if (/^INBOX$/i.test(target)) {
            return 'Posteingang';
        }
        return folderOrSegment;
    }

    function getFolderLeafLabel(folder) {
        var segments = getFolderSegments(folder).filter(function (segment) {
            return !!segment;
        });
        var leaf = segments.length ? segments[segments.length - 1] : folder;
        return getFolderLabel(leaf, folder);
    }

    function getFolderAliasGroupKey(folder) {
        if (/^INBOX$/i.test(folder)) {
            return 'inbox';
        }

        var aliasKeys = Object.keys(folderAliases || {});
        for (var i = 0; i < aliasKeys.length; i += 1) {
            var aliasKey = aliasKeys[i];
            var aliases = folderAliases[aliasKey];
            if (Array.isArray(aliases) && matchesFolderAlias(folder, aliases)) {
                return String(aliasKey).toLowerCase();
            }
        }

        return '';
    }

    function dedupeImportantFolders(folders) {
        var seen = {};
        return (folders || []).filter(function (folder) {
            var aliasKey = getFolderAliasGroupKey(folder);
            var dedupeKey = aliasKey ? ('alias:' + aliasKey) : ('folder:' + String(folder).toLowerCase());

            if (seen[dedupeKey]) {
                return false;
            }

            seen[dedupeKey] = true;
            return true;
        });
    }

    function collectFoldersByNames(names, availableFolders) {
        var lookup = {};
        (availableFolders || []).forEach(function (folder) {
            lookup[folder] = true;
        });

        return (names || []).filter(function (folder) {
            return !!lookup[folder];
        });
    }

    function buildFolderTree(folders) {
        var root = { key: '__root__', children: [] };
        var nodeMap = { '__root__': root };

        folders.forEach(function (folder) {
            var segments = getFolderSegments(folder).filter(function (segment) { return !!segment; });
            var delimiter = getFolderDelimiter(folder);
            var currentKey = '';
            var parent = root;

            segments.forEach(function (segment, index) {
                currentKey = currentKey ? (currentKey + delimiter + segment) : segment;
                if (!nodeMap[currentKey]) {
                    nodeMap[currentKey] = {
                        key: currentKey,
                        folder: currentKey,
                        label: getFolderLabel(segment, currentKey),
                        children: [],
                        exists: false,
                        depth: index,
                    };
                    parent.children.push(nodeMap[currentKey]);
                }

                parent = nodeMap[currentKey];
                if (index === segments.length - 1) {
                    parent.exists = true;
                    parent.folder = folder;
                }
            });
        });

        sortFolderTreeChildren(root.children);
        return root;
    }

    function sortFolderTreeChildren(children) {
        children.sort(function (left, right) {
            var leftFolder = left.folder || left.key || '';
            var rightFolder = right.folder || right.key || '';
            return getFolderPriority(leftFolder) - getFolderPriority(rightFolder)
                || left.label.localeCompare(right.label, undefined, { sensitivity: 'base' });
        });

        children.forEach(function (child) {
            if (child.children && child.children.length) {
                sortFolderTreeChildren(child.children);
            }
        });
    }

    function filterFolderTreeNodes(nodes, searchTerm) {
        if (!searchTerm) {
            return nodes;
        }

        var filtered = [];
        nodes.forEach(function (node) {
            var selfMatch = (node.folder || '').toLowerCase().indexOf(searchTerm) !== -1
                || (node.label || '').toLowerCase().indexOf(searchTerm) !== -1;
            var filteredChildren = filterFolderTreeNodes(node.children || [], searchTerm);
            if (selfMatch || filteredChildren.length) {
                filtered.push($.extend({}, node, {
                    children: filteredChildren,
                }));
            }
        });

        return filtered;
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

    function renderFolderSection($list, title, items) {
        if (!Array.isArray(items) || items.length === 0) {
            return;
        }

        var $section = $('<li>').addClass('ahx-mail-folder-section');
        $section.append($('<div>').addClass('ahx-mail-folder-section__title').text(title));
        var $sectionList = $('<ul>').addClass('ahx-mail-folder-section__list');
        items.forEach(function (item) {
            $sectionList.append(item);
        });
        $section.append($sectionList);
        $list.append($section);
    }

    function getFolderCountText(folderStat) {
        var unread = parseInt(folderStat && folderStat.unread ? folderStat.unread : 0, 10);
        var total = parseInt(folderStat && folderStat.total ? folderStat.total : 0, 10);

        if (isNaN(unread) || unread < 0) {
            unread = 0;
        }
        if (isNaN(total) || total < unread) {
            total = unread;
        }

        return unread + ' / ' + total;
    }

    function createFolderRow(folder, stats, options) {
        var folderStat = stats[folder] || {};
        var active = folder === state.folder;
        var favorite = isFolderFavorite(folder);
        var useLeafLabel = !!(options && options.leafLabel);
        var folderLabel = useLeafLabel ? getFolderLeafLabel(folder) : getFolderLabel(folder, folder);
        var $item = $('<li>').addClass('ahx-mail-folder-item');
        var $row = $('<div>')
            .addClass('ahx-mail-folder ahx-mail-folder-row' + (active ? ' ahx-mail-folder--active' : ''))
            .attr('data-folder', folder);

        if (options && options.spacer) {
            $row.append($('<span>').addClass('ahx-mail-folder-toggle-spacer'));
        }

        $row.append($('<span>').addClass('ahx-mail-folder-label').text(folderLabel));
        if (active) {
            $row.append(
                $('<button>')
                    .addClass('ahx-mail-folder-favorite' + (favorite ? ' ahx-mail-folder-favorite--active' : ''))
                    .attr('type', 'button')
                    .attr('data-folder', folder)
                    .attr('title', favorite ? 'Favorit entfernen' : 'Als Favorit markieren')
                    .text(favorite ? '★' : '☆')
            );
        }
        $row.append(
            $('<span>')
                .addClass('ahx-mail-folder-count' + (active ? ' ahx-mail-folder-count--active' : ''))
                .attr('title', 'Ungelesene / gesamte Nachrichten')
                .text(getFolderCountText(folderStat))
        );

        $item.append($row);
        return $item;
    }

    function createFolderTreeItem(node, stats, searchTerm) {
        var folder = node.folder || node.key;
        var hasChildren = Array.isArray(node.children) && node.children.length > 0;
        var active = node.exists && folder === state.folder;
        var expanded = searchTerm ? true : (node.depth === 0 ? expandedFolderNodes[node.key] !== false : !!expandedFolderNodes[node.key]);
        var favorite = node.exists && isFolderFavorite(folder);
        var $item = $('<li>').addClass('ahx-mail-folder-tree__item ahx-mail-folder-tree__item--depth-' + Math.min(5, node.depth || 0));
        var $row = $('<div>').addClass('ahx-mail-folder ahx-mail-folder-row ahx-mail-folder-tree__row' + (active ? ' ahx-mail-folder--active' : '') + (!node.exists ? ' ahx-mail-folder-row--branch' : ''));

        if (node.exists) {
            $row.attr('data-folder', folder);
        }

        if (hasChildren) {
            $row.append(
                $('<button>')
                    .addClass('ahx-mail-folder-toggle')
                    .attr('type', 'button')
                    .attr('data-node-key', node.key)
                    .attr('title', expanded ? 'Zweig einklappen' : 'Zweig ausklappen')
                    .text(expanded ? '▾' : '▸')
            );
        } else {
            $row.append($('<span>').addClass('ahx-mail-folder-toggle-spacer'));
        }

        $row.append($('<span>').addClass('ahx-mail-folder-label').text(node.label));

        if (node.exists) {
            var folderStat = stats[folder] || {};
            if (active) {
                $row.append(
                    $('<button>')
                        .addClass('ahx-mail-folder-favorite' + (favorite ? ' ahx-mail-folder-favorite--active' : ''))
                        .attr('type', 'button')
                        .attr('data-folder', folder)
                        .attr('title', favorite ? 'Favorit entfernen' : 'Als Favorit markieren')
                        .text(favorite ? '★' : '☆')
                );
            }
            $row.append(
                $('<span>')
                    .addClass('ahx-mail-folder-count' + (active ? ' ahx-mail-folder-count--active' : ''))
                    .attr('title', 'Ungelesene / gesamte Nachrichten')
                    .text(getFolderCountText(folderStat))
            );
        } else {
            $row.append(
                $('<span>')
                    .addClass('ahx-mail-folder-tree__branch-meta')
                    .text((node.children || []).length)
            );
        }

        $item.append($row);

        if (hasChildren && expanded) {
            var $children = $('<ul>').addClass('ahx-mail-folder-tree');
            node.children.forEach(function (child) {
                $children.append(createFolderTreeItem(child, stats, searchTerm));
            });
            $item.append($children);
        }

        return $item;
    }

    function renderFolders(folders, stats) {
        if (!Array.isArray(folders) || folders.length === 0) {
            return;
        }

        folders = sortFolders(folders.slice());

        var $list = $('#ahx-mail-folders');
        $list.empty();
        stats = stats && typeof stats === 'object' ? stats : {};

        var searchTerm = folderSearchTerm;
        var favorites = collectFoldersByNames(favoriteFolders, folders).filter(function (folder) {
            return !searchTerm || folder.toLowerCase().indexOf(searchTerm) !== -1 || getFolderLabel(folder, folder).toLowerCase().indexOf(searchTerm) !== -1;
        });
        var important = folders.filter(function (folder) {
            return getFolderPriority(folder) < 100 && favorites.indexOf(folder) === -1;
        }).filter(function (folder) {
            return !searchTerm || folder.toLowerCase().indexOf(searchTerm) !== -1 || getFolderLabel(folder, folder).toLowerCase().indexOf(searchTerm) !== -1;
        });
        important = dedupeImportantFolders(important).slice(0, 6);
        var recent = collectFoldersByNames(recentFolders, folders).filter(function (folder) {
            return favorites.indexOf(folder) === -1 && important.indexOf(folder) === -1;
        }).filter(function (folder) {
            return !searchTerm || folder.toLowerCase().indexOf(searchTerm) !== -1 || getFolderLabel(folder, folder).toLowerCase().indexOf(searchTerm) !== -1;
        });

        renderFolderSection($list, 'Favoriten', favorites.map(function (folder) {
            return createFolderRow(folder, stats, { spacer: true, leafLabel: true });
        }));

        if (!searchTerm) {
            renderFolderSection($list, 'Wichtig', important.map(function (folder) {
                return createFolderRow(folder, stats, { spacer: true });
            }));

            renderFolderSection($list, 'Zuletzt verwendet', recent.map(function (folder) {
                return createFolderRow(folder, stats, { spacer: true, leafLabel: true });
            }));
        }

        var treeRoot = buildFolderTree(folders);
        var treeNodes = filterFolderTreeNodes(treeRoot.children || [], searchTerm);
        if (treeNodes.length) {
            var $treeSection = $('<li>').addClass('ahx-mail-folder-section ahx-mail-folder-section--tree');
            $treeSection.append($('<div>').addClass('ahx-mail-folder-section__title').text(searchTerm ? 'Suchtreffer' : 'Alle Ordner'));
            var $treeList = $('<ul>').addClass('ahx-mail-folder-tree');
            treeNodes.forEach(function (node) {
                $treeList.append(createFolderTreeItem(node, stats, searchTerm));
            });
            $treeSection.append($treeList);
            $list.append($treeSection);
        }

        if (!$list.children().length) {
            $list.append(
                $('<li>')
                    .addClass('ahx-mail-folder-empty')
                    .text('Keine Ordner zur aktuellen Suche gefunden.')
            );
        }

        // Ordner für Verschieben-Dropdowns merken und befüllen
        folderList = folders;
        populateMoveDropdowns(folders);

        if (currentMail && $('#ahx-mail-detail-panel').is(':visible')) {
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
    function renderEmailList(emails, emptyMessage) {
        var $tbody = $('#ahx-mail-tbody');
        $tbody.empty();
        $('#ahx-mail-check-all').prop('checked', false);
        updateBulkToolbar();

        if (!Array.isArray(emails) || emails.length === 0) {
            $tbody.append('<tr><td colspan="4">' + (emptyMessage || 'Keine E-Mails in diesem Ordner.') + '</td></tr>');
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
            if (mail.flagged) {
                $subjectCell.append(
                    $('<span>')
                        .addClass('ahx-mail-rule-indicator')
                        .attr('title', 'Von einer Regel bearbeitet')
                        .text('Regel')
                );
            }
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
    function loadEmail(uid, folder, forceReload, forceAllowImages) {
        forceReload = !!forceReload;
        forceAllowImages = !!forceAllowImages;
        setStatus('Lädt Nachricht…');

        var cacheKey = [state.accountKey || '', folder || state.folder || '', String(uid || '')].join('::');
        if (forceReload) {
            evictCachedDetail(cacheKey);
        }

        var cachedDetail = forceReload ? null : getCachedDetail(cacheKey);
        if (cachedDetail) {
            showDetailPanel(cachedDetail);
            setStatus('');
            return;
        }

        $.post(ahxMail.ajaxUrl, {
            action: 'ahx_wp_mail_fetch_email',
            nonce:  ahxMail.nonce,
            account_key: state.accountKey,
            folder: folder,
            uid:    uid,
            debug:  state.debugColorTrace ? 1 : 0,
            force_allow_images: forceAllowImages ? 1 : 0,
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

                putCachedDetail(cacheKey, resp.data);
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
        rulesReturnToDetail = false;

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
        $('#ahx-mail-rules-panel').hide();
        $('#ahx-mail-detail-panel').show();
        setSidebarView('list');

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

    function isMobileActionsMode() {
        var appWidth = $('#ahx-mail-app').outerWidth() || 0;

        if (appWidth > 0 && appWidth <= 1024) {
            return true;
        }

        if (window.matchMedia && window.matchMedia('(max-width: 1024px)').matches) {
            return true;
        }

        if (window.matchMedia && window.matchMedia('(hover: none) and (pointer: coarse)').matches) {
            return true;
        }

        return false;
    }

    function ensureStickyActionsPlacement() {
        var $sticky = $('#ahx-mail-sticky-actions');
        if (!$sticky.length) {
            return;
        }

        if (isMobileActionsMode()) {
            $sticky.addClass('ahx-mail-sticky-actions--mobile');
            if (!stickyActionsInBody) {
                $('body').append($sticky);
                stickyActionsInBody = true;
            }
            return;
        }

        $sticky.removeClass('ahx-mail-sticky-actions--mobile ahx-mail-sticky-actions--visible');
        if (stickyActionsInBody) {
            $sticky.insertAfter('.ahx-mail-detail-actions');
            stickyActionsInBody = false;
        }
    }

    function showStickyActions() {
        var $sticky = $('#ahx-mail-sticky-actions');
        if (!$sticky.length) {
            return;
        }

        if ($sticky.hasClass('ahx-mail-sticky-actions--mobile')) {
            $sticky.addClass('ahx-mail-sticky-actions--visible');
            return;
        }

        $sticky.show();
    }

    function updateStickyActionsVisibility() {
        ensureStickyActionsPlacement();

        var $panel = $('#ahx-mail-detail-panel');
        var $actions = $('.ahx-mail-detail-actions');
        var $sticky = $('#ahx-mail-sticky-actions');
        if (!$panel.length || !$actions.length || !$sticky.length || !$panel.is(':visible')) {
            hideStickyActions();
            return;
        }

        if (isMobileActionsMode()) {
            if (!currentMail) {
                hideStickyActions();
                return;
            }

            var actionsEl = $actions.get(0);
            if (!actionsEl) {
                hideStickyActions();
                return;
            }

            // Mobile layouts may scroll either inside the panel or on the page.
            // Use viewport position of the native action row as the source of truth.
            var actionsRect = actionsEl.getBoundingClientRect();
            var shouldShowSticky = actionsRect.bottom < 8;

            if (shouldShowSticky) {
                showStickyActions();
                updateStickyNavigationState();
            } else {
                hideStickyActions();
            }
            return;
        }

        var panelTop = $panel.scrollTop();
        var actionsTop = $actions.position().top;
        var actionsBottom = actionsTop + $actions.outerHeight();
        var isVisible = actionsBottom > panelTop && actionsTop < (panelTop + $panel.innerHeight());

        if (isVisible) {
            hideStickyActions();
        } else {
            showStickyActions();
            updateStickyNavigationState();
        }
    }

    function hideStickyActions() {
        var $sticky = $('#ahx-mail-sticky-actions');
        if (!$sticky.length) {
            return;
        }

        if ($sticky.hasClass('ahx-mail-sticky-actions--mobile')) {
            $sticky.removeClass('ahx-mail-sticky-actions--visible');
            return;
        }

        $sticky.hide();
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
        if (!currentMail || !currentMail.uid) {
            setStatus('Keine geöffnete Nachricht zum Neuladen.');
            return;
        }

        var reloadUid = currentMail.uid;
        var reloadFolder = state.openFolder || state.folder;

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
                revealBlockedImagesInCurrentView();
                setStatus('Freigabe gespeichert. Lade neu…');
                // E-Mail neu laden – jetzt mit Bildern
                loadEmail(reloadUid, reloadFolder, true, true);
            } else {
                setStatus('Fehler beim Speichern.');
            }
        })
        .fail(function () {
            setStatus('Verbindungsfehler.');
        });
    }

    function revealBlockedImagesInCurrentView() {
        var iframe = $('#ahx-mail-detail-body .ahx-mail-detail-iframe').get(0);
        if (!iframe || !iframe.contentWindow || !iframe.contentWindow.document) {
            return;
        }

        var $body = $(iframe.contentWindow.document.body);
        $body.find('[data-ahx-src]').each(function () {
            $(this).attr('src', $(this).attr('data-ahx-src')).removeAttr('data-ahx-src');
        });

        syncMailIframeHeight(iframe);
        $('#ahx-mail-image-bar').hide();
    }

    function showListPanel(resetDetailState) {
        if (typeof resetDetailState === 'undefined') {
            resetDetailState = true;
        }
        if (resetDetailState) {
            currentMail = null;
            state.openFolder = '';
            rulesReturnToDetail = false;
        }
        hideStickyActions();
        ensureStickyActionsPlacement();
        $('#ahx-mail-detail-panel').hide();
        $('#ahx-mail-rules-panel').hide();
        $('#ahx-mail-list-panel').show();
        setSidebarView('list');
    }

    function showRulesPanel(prefillFromCurrentMail) {
        var $panel = $('#ahx-mail-rules-panel');
        if (!$panel.length) {
            return;
        }

        loadRules();

        rulesReturnToDetail = $('#ahx-mail-detail-panel').is(':visible');
        if (prefillFromCurrentMail !== false && currentMail) {
            // Quick-Regeln should always start as a new rule, not reuse a stale edit index.
            resetRuleBuilder();
            showRuleBuilder();
            prefillRuleFromCurrentMail();
        } else if (editingRuleIndex < 0) {
            hideRuleBuilder();
        }

        hideStickyActions();
        $('#ahx-mail-list-panel').hide();
        $('#ahx-mail-detail-panel').hide();
        $panel.show();
        setSidebarView('rules');
        scrollToRulesTop();
    }

    function hideRulesPanel() {
        $('#ahx-mail-rules-panel').hide();
        if (rulesReturnToDetail && currentMail) {
            showDetailPanel(currentMail);
            return;
        }
        showListPanel(true);
    }

    function setSidebarView(view) {
        var isRules = view === 'rules';
        $('#ahx-mail-nav-rules').toggleClass('ahx-mail-nav-group__header--active', isRules);
        $('#ahx-mail-nav-rules-overview').toggleClass('ahx-mail-nav-subitem--active', isRules);
        $('#ahx-mail-nav-rules-quick')
            .toggleClass('ahx-mail-nav-subitem--active', isRules && !!currentMail)
            .prop('disabled', !currentMail);
    }

    function scrollToRulesTop() {
        var $panel = $('#ahx-mail-rules-panel');
        var $app = $('#ahx-mail-app');

        if ($panel.length) {
            $panel.scrollTop(0);
        }

        if ($app.length) {
            $('html, body').scrollTop($app.offset().top || 0);
            $app[0].scrollIntoView({ block: 'start', inline: 'nearest' });
        }
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

    function getRuleConditionGroupsFromBuilder() {
        var groups = [];

        groups.push({
            from_contains: ($('#ahx-mail-rule-from').val() || '').trim(),
            to_contains: ($('#ahx-mail-rule-to').val() || '').trim(),
            subject_contains: ($('#ahx-mail-rule-subject').val() || '').trim(),
        });

        $('#ahx-mail-rule-groups .ahx-mail-rule-group').each(function () {
            var $group = $(this);
            groups.push({
                from_contains: ($group.find('.ahx-mail-rule-group__from').val() || '').trim(),
                to_contains: ($group.find('.ahx-mail-rule-group__to').val() || '').trim(),
                subject_contains: ($group.find('.ahx-mail-rule-group__subject').val() || '').trim(),
            });
        });

        return groups.filter(function (group) {
            return group.from_contains || group.to_contains || group.subject_contains;
        });
    }

    function createRuleConditionGroupElement(group, groupNumber) {
        var $group = $('<div>').addClass('ahx-mail-rule-group');
        var title = 'ODER-Bedingung ' + groupNumber;

        $group.append(
            $('<div>').addClass('ahx-mail-rule-group__header')
                .append($('<span>').addClass('ahx-mail-rule-group__title').text(title))
                .append(
                    $('<button>')
                        .addClass('ahx-mail-btn ahx-mail-btn--sm ahx-mail-rule-group-remove')
                        .attr('type', 'button')
                        .text('Entfernen')
                )
        );

        $group.append(
            $('<div>').addClass('ahx-mail-rules-builder__row')
                .append($('<label>').text('Von enthält'))
                .append($('<input>').addClass('regular-text ahx-mail-rule-group__from').val((group && group.from_contains) || ''))
        );
        $group.append(
            $('<div>').addClass('ahx-mail-rules-builder__row')
                .append($('<label>').text('An enthält'))
                .append($('<input>').addClass('regular-text ahx-mail-rule-group__to').val((group && group.to_contains) || ''))
        );
        $group.append(
            $('<div>').addClass('ahx-mail-rules-builder__row')
                .append($('<label>').text('Betreff enthält'))
                .append($('<input>').addClass('regular-text ahx-mail-rule-group__subject').val((group && group.subject_contains) || ''))
        );

        return $group;
    }

    function addRuleConditionGroup(group) {
        showRuleBuilder();
        var $container = $('#ahx-mail-rule-groups');
        if (!$container.length) {
            return;
        }

        var groupCount = $container.children('.ahx-mail-rule-group').length + 2;
        $container.append(createRuleConditionGroupElement(group || {}, groupCount));
    }

    function renderRuleConditionGroups(rule) {
        var $container = $('#ahx-mail-rule-groups');
        if (!$container.length) {
            return;
        }

        $container.empty();
        var groups = Array.isArray(rule && rule.match_any) ? rule.match_any : [];
        if (groups.length > 1) {
            groups.slice(1).forEach(function (group, index) {
                $container.append(createRuleConditionGroupElement(group || {}, index + 2));
            });
        }
    }

    function resetRuleBuilder() {
        editingRuleIndex = -1;
        $('#ahx-mail-rule-name').val('');
        $('#ahx-mail-rule-folder').val('INBOX');
        $('#ahx-mail-rule-from').val('');
        $('#ahx-mail-rule-to').val('');
        $('#ahx-mail-rule-subject').val('');
        $('#ahx-mail-rule-action').val('mark_read');
        $('#ahx-mail-rule-move-to').val('');
        $('#ahx-mail-rule-groups').empty();
        $('#ahx-mail-rule-save').text('Regel speichern');
        $('#ahx-mail-rule-cancel').hide();
        toggleRuleMoveRow();
        hideRuleBuilder();
    }

    function showRuleBuilder() {
        var $builder = $('#ahx-mail-rules-builder');
        if ($builder.length) {
            $builder.show();
        }
    }

    function hideRuleBuilder() {
        var $builder = $('#ahx-mail-rules-builder');
        if ($builder.length && editingRuleIndex < 0) {
            $builder.hide();
        }
    }

    function editRule(index) {
        if (!Array.isArray(userRules) || !userRules[index]) {
            return;
        }

        var rule = userRules[index] || {};
        editingRuleIndex = index;
        $('#ahx-mail-rule-name').val(rule.name || getRuleDisplayName(rule));
        $('#ahx-mail-rule-folder').val(rule.folder || 'INBOX');
        $('#ahx-mail-rule-from').val(rule.from_contains || '');
        $('#ahx-mail-rule-to').val(rule.to_contains || '');
        $('#ahx-mail-rule-subject').val(rule.subject_contains || '');
        $('#ahx-mail-rule-action').val(rule.action || 'mark_read');
        $('#ahx-mail-rule-move-to').val(rule.move_to_folder || '');
        renderRuleConditionGroups(rule);
        $('#ahx-mail-rule-save').text('Regel aktualisieren');
        $('#ahx-mail-rule-cancel').show();
        toggleRuleMoveRow();
        showRuleBuilder();
        setStatus('Regel #' + (index + 1) + ' wird bearbeitet.');
    }

    function getRuleDisplayName(rule) {
        if (!rule) {
            return 'Unbenannte Regel';
        }

        if (rule.name && String(rule.name).trim()) {
            return String(rule.name).trim();
        }

        var groups = Array.isArray(rule.match_any) && rule.match_any.length ? rule.match_any : [rule];
        var parts = [];
        groups.forEach(function (group) {
            var labels = [];
            if (group && group.from_contains) { labels.push('Von enthält "' + group.from_contains + '"'); }
            if (group && group.to_contains) { labels.push('An enthält "' + group.to_contains + '"'); }
            if (group && group.subject_contains) { labels.push('Betreff enthält "' + group.subject_contains + '"'); }
            if (labels.length) {
                parts.push(labels.join(' · '));
            }
        });

        if (!parts.length) {
            parts.push('Unbenannte Regel');
        }

        return parts.join(' ODER ');
    }

    function getRuleActionLabel(action, moveToFolder) {
        if (action === 'mark_read') {
            return 'gelesen';
        }
        if (action === 'mark_unread') {
            return 'ungelesen';
        }
        if (action === 'delete') {
            return 'gelöscht';
        }
        if (action === 'archive') {
            return 'archiviert';
        }
        if (action === 'move') {
            return 'verschoben' + (moveToFolder ? ' nach ' + moveToFolder : '');
        }

        return action || '';
    }

    function getRuleSummary(rule) {
        var groups = Array.isArray(rule.match_any) && rule.match_any.length ? rule.match_any : [rule];
        var parts = [];

        groups.forEach(function (group) {
            if (!group) { return; }
            var labels = [];
            if (group.from_contains) { labels.push('Von: ' + group.from_contains); }
            if (group.to_contains) { labels.push('An: ' + group.to_contains); }
            if (group.subject_contains) { labels.push('Betreff: ' + group.subject_contains); }
            if (labels.length) {
                parts.push(labels.join(', '));
            }
        });

        if (!parts.length) {
            parts.push('Ohne Bedingungen');
        }

        return parts.join(' ODER ') + ' → ' + getRuleActionLabel(rule.action, rule.move_to_folder);
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
        updateRulesCountBadge();
        if (!$list.length) {
            return;
        }
        $list.empty();

        if (!Array.isArray(userRules) || userRules.length === 0) {
            $list.append($('<li>').addClass('ahx-mail-rule-item').text('Noch keine Regeln gespeichert.'));
            return;
        }

        userRules.forEach(function (rule, index) {
            var $item = $('<li>').addClass('ahx-mail-rule-item');
            var $body = $('<div>').addClass('ahx-mail-rule-item__body');
            $body.append($('<div>').addClass('ahx-mail-rule-item__title').text(rule.name || getRuleDisplayName(rule)));
            $body.append($('<div>').addClass('ahx-mail-rule-item__summary').text(getRuleSummary(rule)));
            $body.append($('<div>').addClass('ahx-mail-rule-item__meta').text('Treffer: ' + (parseInt(rule.match_count || 0, 10) || 0) + ' · Ausgeführt: ' + (parseInt(rule.handled_count || 0, 10) || 0)));
            $item.append($body);
            $item.append(
                $('<div>').addClass('ahx-mail-rule-item__actions')
                    .append(
                        $('<button>')
                            .addClass('ahx-mail-btn ahx-mail-btn--sm ahx-mail-rule-edit')
                            .attr('type', 'button')
                            .attr('data-index', index)
                            .text('Bearbeiten')
                    )
                    .append(
                        $('<button>')
                            .addClass('ahx-mail-btn ahx-mail-btn--sm ahx-mail-rule-delete')
                            .attr('type', 'button')
                            .attr('data-index', index)
                            .text('Löschen')
                    )
            );
            $list.append($item);
        });
    }

    function updateRulesCountBadge() {
        var $badge = $('#ahx-mail-rules-count-badge');
        if (!$badge.length) {
            return;
        }

        var count = Array.isArray(userRules) ? userRules.length : 0;
        $badge.text(String(count));
        $badge.toggleClass('ahx-mail-nav-group__badge--empty', count === 0);
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

        if (!$('#ahx-mail-rule-name').val()) {
            $('#ahx-mail-rule-name').val(getRuleDisplayName({
                from_contains: fromValue,
                to_contains: to,
                subject_contains: subject,
                action: $('#ahx-mail-rule-action').val() || 'mark_read'
            }));
        }

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
        var previousRules = Array.isArray(userRules) ? userRules.slice() : [];
        var action = ($('#ahx-mail-rule-action').val() || '').trim();
        var selectedAccountKey = ($('#ahx-mail-rule-account').val() || state.accountKey || '').trim();
        var groups = getRuleConditionGroupsFromBuilder();
        var rule = {
            id: editingRuleIndex >= 0 && userRules[editingRuleIndex] ? (userRules[editingRuleIndex].id || '') : '',
            enabled: true,
            name: ($('#ahx-mail-rule-name').val() || '').trim(),
            account_key: selectedAccountKey,
            folder: ($('#ahx-mail-rule-folder').val() || 'INBOX').trim(),
            from_contains: ($('#ahx-mail-rule-from').val() || '').trim(),
            to_contains: ($('#ahx-mail-rule-to').val() || '').trim(),
            subject_contains: ($('#ahx-mail-rule-subject').val() || '').trim(),
            action: action,
            move_to_folder: ($('#ahx-mail-rule-move-to').val() || '').trim(),
            match_any: groups,
        };

        if (!rule.account_key) {
            setStatus('Bitte ein Konto für die Regel auswählen.');
            return;
        }

        if (!groups.length) {
            setStatus('Bitte mindestens ein Filterkriterium ausfüllen (Von, An oder Betreff).');
            return;
        }
        if (rule.action === 'move' && !rule.move_to_folder) {
            setStatus('Bitte Zielordner für "Verschieben" wählen.');
            return;
        }

        if (!rule.name) {
            rule.name = getRuleDisplayName(rule);
        }

        var canUpdateExisting = editingRuleIndex >= 0
            && Array.isArray(userRules)
            && !!userRules[editingRuleIndex]
            && !!(rule.id || '').trim();

        if (canUpdateExisting) {
            rule.index = editingRuleIndex;
        } else {
            rule.id = '';
        }

        $.post(ahxMail.ajaxUrl, {
            action: 'ahx_wp_mail_add_rule',
            nonce: ahxMail.nonce,
            rule: JSON.stringify(rule),
            index: canUpdateExisting ? editingRuleIndex : -1,
        })
        .done(function (resp) {
            if (resp.success && resp.data && Array.isArray(resp.data.rules)) {
                if (resp.data.rules.length === 0) {
                    setStatus('Fehler: Regel konnte nicht gespeichert werden.');
                    loadRules();
                    return;
                }

                userRules = resp.data.rules;
                renderRules();
                resetRuleBuilder();
                hideRuleBuilder();
                setStatus('Regel gespeichert.');
            } else {
                setStatus('Fehler: ' + (resp.data ? resp.data.message : 'Regel konnte nicht gespeichert werden.'));
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
                setStatus('Verbindungsfehler beim Speichern der Regel.');
            }
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
                if (editingRuleIndex >= 0) {
                    resetRuleBuilder();
                }
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
