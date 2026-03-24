jQuery(document).ready(function ($) {
    function refreshEmailList() {
        $.ajax({
            url: emailyContactList.ajax_url,
            type: 'POST',
            data: {
                action: emailyContactList.actions.get_email_list,
                nonce: emailyContactList.nonce,
                post_id: emailyContactList.post_id
            }
        }).done(function (response) {
            if (response.success) {
                $('#emaily-email-list-content').html(response.data.html);
            } else if (response.data) {
                alert(emailyContactList.strings.error_generic + ' ' + response.data);
            } else {
                alert(emailyContactList.strings.error_generic);
            }
        }).fail(function () {
            alert(emailyContactList.strings.error_generic);
        });
    }

    $('#emaily-validate-emails-button').on('click', function () {
        if (!confirm(emailyContactList.strings.confirm)) {
            return;
        }

        $.ajax({
            url: emailyContactList.ajax_url,
            type: 'POST',
            data: {
                action: 'emaily_validate_contact_list',
                nonce: emailyContactList.nonce,
                post_id: emailyContactList.post_id
            }
        }).done(function (response) {
            if (response.success) {
                alert(response.data.message);
                refreshEmailList();
            } else if (response.data) {
                alert(emailyContactList.strings.error_generic + ' ' + response.data);
            } else {
                alert(emailyContactList.strings.error_generic);
            }
        }).fail(function () {
            alert(emailyContactList.strings.error_generic);
        });
    });

    $(document).on('click', '.emaily-remove-email', function (e) {
        e.preventDefault();
        var email = $(this).data('email');
        if (!email) {
            return;
        }

        var confirmation = emailyContactList.strings.confirm_remove.replace('%s', email);
        if (!confirm(confirmation)) {
            return;
        }

        $.ajax({
            url: emailyContactList.ajax_url,
            type: 'POST',
            data: {
                action: emailyContactList.actions.remove_email,
                nonce: emailyContactList.nonce,
                post_id: emailyContactList.post_id,
                email: email
            }
        }).done(function (response) {
            if (response.success) {
                refreshEmailList();
            } else if (response.data) {
                alert(emailyContactList.strings.error_remove + ' ' + response.data);
            } else {
                alert(emailyContactList.strings.error_remove);
            }
        }).fail(function () {
            alert(emailyContactList.strings.error_remove);
        });
    });

    $(document).on('input', '#emaily-email-search', function () {
        var searchTerm = $(this).val().toLowerCase();
        var hasVisible = false;

        $('#emaily-email-table tbody tr').each(function () {
            var $row = $(this);
            if ($row.hasClass('emaily-no-results')) {
                return;
            }

            var emailText = $row.find('.emaily-email-column').text().toLowerCase();
            var nameText = $row.find('.emaily-name-column').text().toLowerCase();
            var matches = emailText.indexOf(searchTerm) !== -1 || nameText.indexOf(searchTerm) !== -1;

            $row.toggle(matches);
            if (matches) {
                hasVisible = true;
            }
        });

        $('#emaily-email-table .emaily-no-results').toggle(!hasVisible);
    });

    // Select All Checkbox Logic
    $(document).on('change', '#cb-select-all-1', function () {
        var isChecked = $(this).prop('checked');
        $('#emaily-email-table tbody .check-column input[type="checkbox"]:visible').prop('checked', isChecked);
    });

    $(document).on('change', '#emaily-email-table tbody .check-column input[type="checkbox"]', function () {
        if (!$(this).prop('checked')) {
            $('#cb-select-all-1').prop('checked', false);
        } else {
            var allChecked = $('#emaily-email-table tbody .check-column input[type="checkbox"]:visible').length === $('#emaily-email-table tbody .check-column input[type="checkbox"]:visible:checked').length;
            $('#cb-select-all-1').prop('checked', allChecked);
        }
    });

    // Bulk Action Handler
    $(document).on('click', '#emaily-bulk-action-submit', function (e) {
        e.preventDefault();
        var action = $('#emaily-bulk-action-selector').val();
        if (action === '-1') {
            alert('Please select a bulk action.');
            return;
        }

        var selectedEmails = [];
        $('#emaily-email-table tbody .check-column input[type="checkbox"]:checked').each(function () {
            selectedEmails.push($(this).val());
        });

        if (selectedEmails.length === 0) {
            alert('Please select at least one email.');
            return;
        }

        var confirmationMessage = '';
        if (action === 'remove_from_list') {
            confirmationMessage = 'Are you sure you want to remove the selected emails from THIS contact list?';
        } else if (action === 'delete_from_all') {
            confirmationMessage = 'WARNING: This will remove the selected emails from ALL contact lists AND delete their user accounts from the database. Are you sure?';
        }

        if (!confirm(confirmationMessage)) {
            return;
        }

        $.ajax({
            url: emailyContactList.ajax_url,
            type: 'POST',
            data: {
                action: emailyContactList.actions.bulk_remove,
                nonce: emailyContactList.nonce,
                post_id: emailyContactList.post_id,
                emails: selectedEmails,
                bulk_action: action
            },
            beforeSend: function () {

            }
        }).done(function (response) {
            if (response.success) {
                alert(response.data.message);
                refreshEmailList();
            } else {
                alert(response.data || 'An error occurred.');
            }
        }).fail(function () {
            alert('An error occurred while processing the bulk action.');
        });
    });
});
