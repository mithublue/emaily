jQuery(document).ready(function($) {
	$('#emaily_campaign_date').datepicker({
		dateFormat: 'yy-mm-dd',
		minDate: 1 // Allow only future dates (excludes today)
	});

	// Handle pause campaign
	$('#emaily-pause-campaign').on('click', function() {
		var postId = $(this).data('post-id');
		$.ajax({
			url: emailyCampaignAjax.ajax_url,
			type: 'POST',
			data: {
				action: 'emaily_toggle_campaign_status',
				nonce: emailyCampaignAjax.nonce,
				post_id: postId,
				action_type: 'pause'
			},
			success: function(response) {
				if (response.success) {
					$('#emaily-campaign-status-metabox strong:contains("Status:")').next().text(response.data.status);
					$('#emaily-pause-campaign').replaceWith('<button type="button" class="button button-primary" id="emaily-resume-campaign" data-post-id="' + postId + '">Resume Campaign</button>');
					$('#emaily-status-message').text(response.data.message).addClass('success').show();
				} else {
					$('#emaily-status-message').text(response.data.message).addClass('error').show();
				}
			},
			error: function() {
				$('#emaily-status-message').text(emailyCampaignAjax.error_message).addClass('error').show();
			}
		});
	});

	// Handle resume campaign
	$('#emaily-resume-campaign').on('click', function() {
		var postId = $(this).data('post-id');
		$.ajax({
			url: emailyCampaignAjax.ajax_url,
			type: 'POST',
			data: {
				action: 'emaily_toggle_campaign_status',
				nonce: emailyCampaignAjax.nonce,
				post_id: postId,
				action_type: 'resume'
			},
			success: function(response) {
				if (response.success) {
					$('#emaily-campaign-status-metabox strong:contains("Status:")').next().text(response.data.status);
					$('#emaily-resume-campaign').replaceWith('<button type="button" class="button button-secondary" id="emaily-pause-campaign" data-post-id="' + postId + '">Pause Campaign</button>');
					$('#emaily-status-message').text(response.data.message).addClass('success').show();
				} else {
					$('#emaily-status-message').text(response.data.message).addClass('error').show();
				}
			},
			error: function() {
				$('#emaily-status-message').text(emailyCampaignAjax.error_message).addClass('error').show();
			}
		});
	});
});
