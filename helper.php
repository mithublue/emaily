<?php
//this file is used to
// add the helper functions for the emaily plugin
//returns the form fields
function emaily_available_form_fields() {
	$available_fields = array(
		'Email', 'Name', 'Lastname', 'Middlename', 'Phone', 'Date of birth',
		'Company name', 'Industry', 'Department', 'Job title', 'State',
		'Postal code', 'Lead source', 'Salary', 'Country', 'City', 'Tags'
	);
	return $available_fields;
}

function emaily_get_user_info ( $user, $fieldname ) {
	switch ( $fieldname ) {
		case 'Email':
			return $user->user_email;
		default:
			return get_user_meta( $user->ID, 'emaily_'.$fieldname, true );
	}
}

//generate placeholders that will be matched in content and will be replaced by user data
function emaily_generate_placeholders() {
	$form_fields = emaily_available_form_fields();
	$field_names = [];
	foreach ( $form_fields as $label ) {
		$fieldname = str_replace( ' ', '_', strtolower( $label ) );
		$field_names[] = $fieldname;
	}
	return $field_names;
}
