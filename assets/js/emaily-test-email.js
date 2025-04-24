jQuery(document).ready(function($) {
	$('#emaily-test-email-button').on('click', function() {
		var testEmail = prompt(emailyTestEmail.strings.prompt);
		if (!testEmail) {
			alert(emailyTestEmail.strings.required);
			return;
		}

		$.ajax({
			url: emailyTestEmail.ajax_url,
			type: 'POST',
			data: {
				action: 'emaily_send_test_email',
				nonce: emailyTestEmail.nonce,
				post_id: emailyTestEmail.post_id,
				test_email: testEmail
			},
			success: function(response) {
				if (response.success) {
					alert(response.data);
				} else {
					alert(emailyTestEmail.strings.error_prefix + response.data);
				}
			},
			error: function() {
				alert(emailyTestEmail.strings.error_generic);
			}
		});
	});
});
