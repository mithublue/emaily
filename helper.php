<?php
//this file is used to
// add the helper functions for the emaily plugin
//returns the form fields
function emaily_available_form_fields() {
	$available_fields = array(
		'Email', 'Name', 'Lastname', 'Middlename', 'Phone', 'Date of birth',
		'Company name', 'Industry', 'Department', 'Job title', 'State',
		'Postal code', 'Lead source', 'Salary', 'Country', 'City', 'Tags', 'Gender'
	);
	return $available_fields;
}

function emaily_get_user_info ( $user, $fieldname ) {
	switch ( $fieldname ) {
		case 'email':
			return $user->user_email;
		case 'name':
			return $user->display_name;
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

function emaily_get_recipients_from_lists( $campaign_id ) {
	//get recipients from the contact lists
	$contact_lists = carbon_get_post_meta( $campaign_id, 'emaily_campaign_lists' );
	logger('$recipients',$contact_lists);
	$contact_lists = is_array( $contact_lists ) ? $contact_lists : array();
	$recipients = array();
	foreach ( $contact_lists as $list_id ) {
		$list_recipients = get_post_meta( $list_id, 'email_contact_list_users', true );
		$list_recipients = is_array( $list_recipients ) ? $list_recipients : array();
		$recipients = array_merge( $recipients, $list_recipients );
	}
	$recipients = array_values( array_unique( $recipients ) );
	emaily_log( $campaign_id, "Fetched " . count( $recipients ) . " recipients from contact lists for initial queue." );
	return $recipients;
}
