jQuery(document).ready(function($) {
	$('#emaily-validate-emails-button').on('click', function() {
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
			},
			success: function(response) {
				if (response.success) {
					alert(response.data.message);
					// Refresh email list metabox
					$.ajax({
						url: emailyContactList.ajax_url,
						type: 'POST',
						data: {
							action: emailyContactList.actions.get_email_list,
							nonce: emailyContactList.nonce,
							post_id: emailyContactList.post_id
						},
						success: function(refreshResponse) {
							if (refreshResponse.success) {
								$('#emaily-email-list-content').html(refreshResponse.data.html);
							} else {
								alert(emailyContactList.strings.error_generic + ' ' + refreshResponse.data);
							}
						},
						error: function() {
							alert(emailyContactList.strings.error_generic);
						}
					});
				} else {
					alert(emailyContactList.strings.error_generic + ' ' + response.data);
				}
			},
			error: function() {
				alert(emailyContactList.strings.error_generic);
			}
		});
	});
});
