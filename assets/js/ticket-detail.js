(function (window, document) {
    'use strict';

    var runtime = window.FoxDeskTicketDetailRuntime;
    if (!runtime) throw new Error('FoxDesk ticket detail core was not loaded.');
    var config = runtime.config;
    var ticketId = runtime.ticketId;
    var csrfToken = runtime.csrfToken;
    var t = runtime.t;
    var escapeHtml = runtime.escapeHtml;
    var showToast = runtime.showToast;
    var showUndoToast = runtime.showUndoToast;
    var fadeRemove = runtime.fadeRemove;
    var fillTemplate = runtime.fillTemplate;
    var ready = runtime.ready;
    var pad2 = runtime.pad2;
    var formatDateInput = runtime.formatDateInput;
    var formatTimeInput = runtime.formatTimeInput;
    var formatDateTimeLocal = runtime.formatDateTimeLocal;

    function initTicketEditModalControls() {
        document.querySelectorAll('[data-ticket-edit-open]').forEach(function (button) {
            button.addEventListener('click', window.openEditTicketModal);
        });
        document.querySelectorAll('[data-ticket-edit-close]').forEach(function (button) {
            button.addEventListener('click', window.closeEditTicketModal);
        });
    }

    ready(function () {
        runtime.initUploadPreview();
        runtime.initShareCopy();
        runtime.initSubmitLabel();
        runtime.initManualTime();
        runtime.initCcSearch();
        runtime.initCommentMode();
        runtime.initTimer();
        runtime.initAgentCcDropdown();
        runtime.initEditTimeForm();
        runtime.initTags();
        initTicketEditModalControls();
        runtime.initQuillEditors();
        runtime.initImagePreview();
        runtime.initAutosave();
        runtime.initPermanentDelete();

        if (config.quickStart) {
            var quickModal = document.getElementById('edit-ticket-modal');
            if (quickModal) {
                quickModal.classList.add('is-quick-start');
                var quickHeading = quickModal.querySelector('[data-edit-ticket-title]');
                if (quickHeading) quickHeading.textContent = t('quickStartDetails', 'Name this work');
            }
            window.openEditTicketModal();
            var titleInput = document.querySelector('#edit-ticket-modal input[name="edit_title"]');
            if (titleInput) {
                titleInput.focus();
                titleInput.select();
            }
        }

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') return;
            window.closeEditCommentModal();
            window.closeEditTimeModal();
            window.closeEditTicketModal();
            var timeline = document.getElementById('timeline-overlay');
            if (timeline && timeline.classList.contains('is-open')) window.closeTimeline();
        });
    });
})(window, document);
