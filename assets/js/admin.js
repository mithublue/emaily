jQuery(document).ready(function($) {
	$('#emaily_add_users').on('click', function() {
		var fileInput = $('#emaily_user_file')[0];
		if (!fileInput.files.length) {
			$('#emaily-import-messages')
				.text('Please select a file to upload.')
				.addClass('error')
				.show();
			return;
		}

		var formData = new FormData();
		formData.append('action', 'emaily_import_users');
		formData.append('nonce', emailyAjax.nonce);
		formData.append('file', fileInput.files[0]);
		formData.append('post_id', $('#post_ID').val());

		$('#emaily-import-messages')
			.text('Processing...')
			.removeClass('success error')
			.show();

		$.ajax({
			url: emailyAjax.ajax_url,
			type: 'POST',
			data: formData,
			processData: false,
			contentType: false,
			success: function(response) { console.log(response);
				var $messages = $('#emaily-import-messages');
				if (response.success) {
					$messages
						.html(response.data.message)
						.addClass('success')
						.show();
				} else {
					$messages
						.html(response.data.message)
						.addClass('error')
						.show();
				}
			},
			error: function() {
				$('#emaily-import-messages')
					.text('An error occurred during the request.')
					.addClass('error')
					.show();
			}
		});
	});
});
