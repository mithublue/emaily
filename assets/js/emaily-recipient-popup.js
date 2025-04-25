jQuery(document).ready(function($) {
	var dialog = null;

	$('#emaily-view-recipients-button').on('click', function() {
		// Create dialog if not exists
		if (!dialog) {
			dialog = $('<div id="emaily-recipient-dialog"></div>').dialog({
				title: emailyViewRecipients.strings.title,
				autoOpen: false,
				modal: true,
				width: 600,
				buttons: [
					{
						text: emailyViewRecipients.strings.close,
						click: function() {
							$(this).dialog('close');
						}
					}
				]
			});
		}

		// Load initial page
		loadRecipients(1);

		// Open dialog
		dialog.dialog('open');
	});

	// Load recipients for a specific page
	function loadRecipients(page) {
		$.ajax({
			url: emailyViewRecipients.ajax_url,
			type: 'POST',
			data: {
				action: 'emaily_get_recipients',
				nonce: emailyViewRecipients.nonce,
				post_id: emailyViewRecipients.post_id,
				page: page
			},
			success: function(response) {
				if (response.success) {
					$('#emaily-recipient-dialog').html(response.data.html);
					// Bind pagination events
					bindPaginationEvents();
				} else {
					$('#emaily-recipient-dialog').html('<p>' + emailyViewRecipients.strings.error_generic + ' ' + response.data + '</p>');
				}
			},
			error: function() {
				$('#emaily-recipient-dialog').html('<p>' + emailyViewRecipients.strings.error_generic + '</p>');
			}
		});
	}

	// Bind pagination button events
	function bindPaginationEvents() {
		$('.emaily-prev-page, .emaily-next-page').off('click').on('click', function() {
			var page = $(this).data('page');
			loadRecipients(page);
		});
	}
});
