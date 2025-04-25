jQuery(document).ready(function($) {
	$('#emaily-update-recipients-button').on('click', function() {
		if (!confirm(emailyUpdateRecipients.strings.confirm)) {
			return;
		}

		$.ajax({
			url: emailyUpdateRecipients.ajax_url,
			type: 'POST',
			data: {
				action: 'emaily_update_recipients',
				nonce: emailyUpdateRecipients.nonce,
				post_id: emailyUpdateRecipients.post_id
			},
			success: function(response) {
				if (response.success) {
					alert(response.data.message);
				} else {
					alert(emailyUpdateRecipients.strings.error_generic + ' ' + response.data);
				}
			},
			error: function() {
				alert(emailyUpdateRecipients.strings.error_generic);
			}
		});
	});
});
