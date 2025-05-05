jQuery(document).ready(function($) {
	// Handle popup button click
	$('.emaily-popup-button').on('click', function() {
		$(this).siblings('.emaily-popup-overlay').fadeIn();
	});

	// Handle popup close
	$('.emaily-popup-close').on('click', function() {
		$(this).closest('.emaily-popup-overlay').fadeOut();
	});

	// Handle AJAX form submission
	$('.emaily-ajax-form').on('submit', function(e) {
		e.preventDefault();

		var $form = $(this);
		var $message = $form.find('.emaily-form-message');
		var $submitButton = $form.find('.emaily-submit-button');
		var siteKey = emailyAjax.recaptcha_site_key;

		// Disable submit button to prevent multiple submissions
		$submitButton.prop('disabled', true).text('Subscribing...');

		// Clear previous messages
		$message.removeClass('success error').hide();

		// Prepare form data
		var formData = {
			action: 'emaily_form_submit',
			nonce: emailyAjax.nonce,
			form_id: $form.data('form-id'),
			emaily_email: $form.find('#emaily_email').val(),
			emaily_name: $form.find('#emaily_name').val(),
			emaily_lastname: $form.find('#emaily_lastname').val(),
			emaily_middlename: $form.find('#emaily_middlename').val(),
			emaily_phone: $form.find('#emaily_phone').val(),
			emaily_date_of_birth: $form.find('#emaily_date_of_birth').val(),
			emaily_company_name: $form.find('#emaily_company_name').val(),
			emaily_industry: $form.find('#emaily_industry').val(),
			emaily_department: $form.find('#emaily_department').val(),
			emaily_job_title: $form.find('#emaily_job_title').val(),
			emaily_state: $form.find('#emaily_state').val(),
			emaily_postal_code: $form.find('#emaily_postal_code').val(),
			emaily_lead_source: $form.find('#emaily_lead_source').val(),
			emaily_salary: $form.find('#emaily_salary').val(),
			emaily_country: $form.find('#emaily_country').val(),
			emaily_city: $form.find('#emaily_city').val(),
			emaily_tags: $form.find('#emaily_tags').val(),
			emaily_gender: $form.find('#emaily_gender [name="emaily_gender"]:checked').val(),
			emaily_honeypot: $form.find('.emaily-honeypot').val()
		};

		// Function to submit the form via AJAX
		function submitForm(data) {
			$.ajax({
				url: emailyAjax.ajax_url,
				type: 'POST',
				data: data,
				success: function(response) {
					if (response.success) {
						$message.addClass('success').text(response.data.message).fadeIn();
						$form[0].reset();
						// Close popup if it's a popup form
						if ($form.closest('.emaily-popup-overlay').length) {
							setTimeout(function() {
								$form.closest('.emaily-popup-overlay').fadeOut();
							}, 2000);
						}
					} else {
						$message.addClass('error').text(response.data.message).fadeIn();
					}
				},
				error: function() {
					$message.addClass('error').text('An error occurred. Please try again.').fadeIn();
				},
				complete: function() {
					$submitButton.prop('disabled', false).text('Subscribe');
				}
			});
		}

		// Check if reCAPTCHA is enabled
		if (siteKey && $form.find('.g-recaptcha-response').length) {
			grecaptcha.ready(function() {
				grecaptcha.execute(siteKey, {action: 'submit'}).then(function(token) {
					// Add reCAPTCHA token to form data
					formData['g-recaptcha-response'] = token;
					submitForm(formData);
				}).catch(function() {
					$message.addClass('error').text('reCAPTCHA failed to load. Please try again.').fadeIn();
					$submitButton.prop('disabled', false).text('Subscribe');
				});
			});
		} else {
			// Submit without reCAPTCHA
			submitForm(formData);
		}
	});
});
