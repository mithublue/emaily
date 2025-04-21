jQuery(document).ready(function($) {
	// Initialize jQuery UI selectmenu for contact lists with delay
	setTimeout(function() {
		try {
			if ($('#emaily_form_lists').length) {
				$('#emaily_form_lists').selectmenu({
					width: '100%'
				});
			}
		} catch (e) {
			console.error('Error initializing Contact Lists selectmenu:', e);
		}
	}, 100);

	// Initialize jQuery UI selectmenu for form type with delay
	setTimeout(function() {
		try {
			if ($('#emaily_form_type').length) {
				$('#emaily_form_type').selectmenu({
					width: '100%'
				});
			}
		} catch (e) {
			console.error('Error initializing Form Type selectmenu:', e);
		}
	}, 100);

	// Ensure Email field is always checked and disabled
	$('input[name="emaily_form_fields[]"][value="Email"]').prop('checked', true).prop('disabled', true);

	// Add hidden input for field order
	$('#emaily-form-builder').append('<input type="hidden" name="emaily_form_fields_order" id="emaily_form_fields_order" />');

	// Handle field checkbox changes
	$('.emaily-field-checkbox').on('change', function() {
		var field = $(this).data('field');
		var $builder = $('#emaily-form-builder-fields');

		if ($(this).is(':checked')) {
			// Add field block
			var blockHtml = `
                <div class="emaily-field-block" data-field="${field}">
                    <span class="emaily-field-handle dashicons dashicons-move"></span>
                    <label class="emaily-field-label">${field}</label>
                    <input type="text" class="emaily-field-input" disabled />
                    ${field !== 'Email' ? '<span class="emaily-field-remove dashicons dashicons-no-alt" title="Remove field"></span>' : ''}
                </div>
            `;
			$builder.append(blockHtml);
		} else {
			// Remove field block
			$builder.find(`.emaily-field-block[data-field="${field}"]`).remove();
		}

		// Update field order
		updateFieldOrder();
	});

	// Handle remove button clicks
	$('#emaily-form-builder-fields').on('click', '.emaily-field-remove', function() {
		var $block = $(this).closest('.emaily-field-block');
		var field = $block.data('field');

		// Remove block
		$block.remove();

		// Uncheck corresponding checkbox
		$(`.emaily-field-checkbox[data-field="${field}"]`).prop('checked', false);

		// Update field order
		updateFieldOrder();
	});

	// Make field blocks sortable
	$('#emaily-form-builder-fields').sortable({
		handle: '.emaily-field-handle',
		update: function() {
			updateFieldOrder();
		}
	});

	// Update hidden input with field order
	function updateFieldOrder() {
		var fields = [];
		$('#emaily-form-builder-fields .emaily-field-block').each(function() {
			fields.push($(this).data('field'));
		});
		if (!fields.includes('Email')) {
			fields.unshift('Email');
			// Ensure Email block is present
			if (!$('#emaily-form-builder-fields .emaily-field-block[data-field="Email"]').length) {
				$('#emaily-form-builder-fields').prepend(`
                    <div class="emaily-field-block" data-field="Email">
                        <span class="emaily-field-handle dashicons dashicons-move"></span>
                        <label class="emaily-field-label">Email</label>
                        <input type="text" class="emaily-field-input" disabled />
                    </div>
                `);
			}
		}
		$('#emaily_form_fields_order').val(JSON.stringify(fields));
	}

	// Initialize field order on load
	updateFieldOrder();
});
