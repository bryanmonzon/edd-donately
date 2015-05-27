<?php
/*
Plugin Name: Easy Digital Downloads - Donately
Plugin URL: http://easydigitaldownloads.com/extension/donately
Description: Turn your shop into a Donation platform using Donately!
Version: 1.0
Author: Bryan Monzon
Author URI: http://mnzn.co
Contributors: bryanmonzon
*/

// Don't forget to load the text domain here. Sample text domain is dntly_edd


/**
 * Register Donately as a gateway
 * @param  [type] $gateways [description]
 * @return [type]           [description]
 */
function dntly_edd_register_gateway( $gateways ) {
	$gateways['donately_gateway'] = array( 'admin_label' => 'Donately', 'checkout_label' => __( 'Donately', 'dntly_edd' ) );
	return $gateways;
}
add_filter( 'edd_payment_gateways', 'dntly_edd_register_gateway' );


// Remove this if you want a credit card form
// add_action( 'edd_donately_gateway_cc_form', '__return_false' );


/**
 * Process the payment with Donately
 * 
 * @param  [type] $purchase_data [description]
 * @return [type]                [description]
 */
function dntly_edd_process_payment( $purchase_data ) {

	global $edd_options;


	/**********************************
	* set transaction mode
	**********************************/

	if ( edd_is_test_mode() ) {
		// set test credentials here
		$dntly_key          = $edd_options['dntly_api_key'];
		$dntly_subdomain    = 'demo';
		$dntly_api_domain   = 'dntly.com';
		$dntly_api_endpoint = "/api/v1/";
		$dntly_method       = 'accounts/'. $dntly_subdomain .'/donate_without_auth';
	} else {
		$dntly_key          = $edd_options['dntly_api_key'];
		$dntly_subdomain    = $edd_options['dntly_subdomain'];
		$dntly_api_domain   = 'dntly.com';
		$dntly_api_endpoint = "/api/v1/";
		$dntly_method       = 'accounts/'. $dntly_subdomain .'/donate_without_auth';

	}


	/**********************************
	* check for errors here
	**********************************/
	
	
	// errors can be set like this
	if( empty($_POST['card_number'] ) ) {
		// error code followed by error message
		edd_set_error('empty_card', __('You must enter a card number', 'donately_edd'));
	}

	if( empty($_POST['card_cvc'] ) ) {
		// error code followed by error message
		edd_set_error('empty_cvc', __('You must enter a CVC number', 'donately_edd'));
	}

	if( empty($_POST['card_name'] ) ) {
		// error code followed by error message
		edd_set_error('empty_name', __('You must enter a name associated with the credit card', 'donately_edd'));
	}

	if( empty($_POST['card_exp_month'] ) ) {
		// error code followed by error message
		edd_set_error('empty_exp_month', __('You must enter an expiry month', 'donately_edd'));
	}

	if( empty($_POST['card_exp_year'] ) ) {
		// error code followed by error message
		edd_set_error('empty_exp_year', __('You must enter an expiry year', 'donately_edd'));
	}
	

	// check for any stored errors
	$errors = edd_get_errors();
	if ( ! $errors ) {

		$url = 'https://';
		$url .= $dntly_subdomain;
		$url .= '.' . $dntly_api_domain;
		$url .= $dntly_api_endpoint;
		$url .= $dntly_method;

		
		$headers = array();

		$card = array(
			'number'    => $purchase_data['card_info']['card_number'],
			'exp_month' => $purchase_data['card_info']['card_exp_month'],
			'exp_year'  => $purchase_data['card_info']['card_exp_year'],
			'cvc'       => $purchase_data['card_info']['card_cvc'],
		);


		$response = wp_safe_remote_post( $url, array(
			'method'      => 'POST',
			'timeout'     => 45,
			'redirection' => 5,
			'httpversion' => '1.0',
			'blocking'    => true,
			'headers'     => $headers,
			'body'        => array( 
				'amount_in_cents' 	=> $purchase_data['price'] * 100,
				'email' 			=> $purchase_data['user_info']['email'],
				'card'              => $card,
				'recurring'         => false,
				'first_name'		=> $purchase_data['user_info']['first_name'],
				'last_name'			=> $purchase_data['user_info']['last_name'],
				'anonymous'			=> false,
				'campaign_id'		=> ''
			),
			'cookies'     => array()
			));

		$body     = wp_remote_retrieve_body( $response );
		
		$donation = json_decode( $body );

		// echo '<pre>';
		// print_r( $body );
		// echo '</pre>';
		// wp_die();

		if( $donation->success ){

			$first_time_donor = ( $donation->donation->first_time_donor ) ? 'Yes' : 'No';
			$donation_id      = ( $donation->donation->id ) ? $donation->donation->id : 'No ID was returned from Donately';

			$purchase_summary = edd_get_purchase_summary( $purchase_data );



			/****************************************
			* setup the payment details to be stored
			****************************************/

			$payment = array(
				'price'        => $purchase_data['price'],
				'date'         => $purchase_data['date'],
				'user_email'   => $purchase_data['user_email'],
				'purchase_key' => $purchase_data['purchase_key'],
				'currency'     => $edd_options['currency'],
				'downloads'    => $purchase_data['downloads'],
				'cart_details' => $purchase_data['cart_details'],
				'user_info'    => $purchase_data['user_info'],
				'status'       => 'pending'
			);

			// record the pending payment
			$payment = edd_insert_payment( $payment );


			// if the merchant payment is complete, set a flag
			$merchant_payment_confirmed = true;

			if ( $merchant_payment_confirmed ) { // this is used when processing credit cards on site

				// once a transaction is successful, set the purchase to complete
				edd_update_payment_status( $payment, 'complete' );

				// record transaction ID, or any other notes you need
				edd_insert_payment_note( $payment, 'Donately ID: ' . $donation->donation->id );
				edd_insert_payment_note( $payment, 'First Time Donor: ' . $first_time_donor );

				// go to the success page
				edd_send_to_success_page();

			} else {
				$fail = true; // payment wasn't recorded
			}
		}else{

			edd_set_error('empty_card', __( $donation->error->message, 'donately_edd'));
			edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
		}

	} else {
		$fail = true; // errors were detected
	}

	if ( $fail !== false ) {
		// if errors are present, send the user back to the purchase page so they can be corrected
		edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
	}
}
add_action( 'edd_gateway_donately_gateway', 'dntly_edd_process_payment' );


/**
 * Add the global settings
 * @param  [type] $settings [description]
 * @return [type]           [description]
 */
function dntly_edd_add_settings( $settings ) {


	$donately_gateway_settings = array(
		array(
			'id' => 'donately_gateway_settings',
			'name' => '<strong>' . __( 'Donately Settings', 'dntly_edd' ) . '</strong>',
			'desc' => 'You will need a Donately account. <a href="https://www.dntly.com/a#/npo/signup" target="_blank">Create an account</a>.',
			'type' => 'descriptive_text2'
		),
		array(
			'id' => 'dntly_api_key',
			'name' => __( 'Donately API Token', 'dntly_edd' ),
			'desc' => __( 'Enter your Donately API token, found in your profile - https://{YOUR_SUBDOMAIN}.dntly.com/settings#/profile', 'dntly_edd' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'dntly_subdomain',
			'name' => __( 'Subdomain', 'dntly_edd' ),
			'desc' => __( 'Enter your subdomain from Donately', 'dntly_edd' ),
			'type' => 'text',
			'size' => 'regular'
		)
	);

	return array_merge( $settings, $donately_gateway_settings );
}
add_filter( 'edd_settings_gateways', 'dntly_edd_add_settings' );


/**
 * Renders the campaigns metabox fields
 * 
 * @param  [type] $post_id [description]
 * @return [type]          [description]
 */
function dntly_edd_render_campaigns( $post_id ) {
	$dntly_edd_campaign = get_post_meta( $post_id, 'dntly_edd_campaign', true );
	
	$campaigns          = dntly_edd_get_campaigns();
	$campaigns          = $campaigns->campaigns;
?>

	<p><strong><?php _e( 'Donately Campaign:', 'edd-external-product' ); ?></strong></p>
	<label for="edd_external_url">
		<select name="dntly_edd_campaign" id="dntly_edd_campaign">
			<option value="">-- Select a Campaign --</option>
			<?php 
			foreach( $campaigns as $campaign ){
				$selected = ($campaign->id == $dntly_edd_campaign ) ? ' selected="selected" ' : '';

				echo '<option value="'. $campaign->id.'" '. $selected .'>'. $campaign->title . '</option>';
			}
			 ?>
		</select>
		<br/><?php _e( 'Select a campaign to associate this product with.', 'edd-external-product' ); ?>
	</label>
<?php
}
add_action( 'edd_meta_box_settings_fields', 'dntly_edd_render_campaigns', 90 );

/**
 * Save the Campaign ID
 * @param  [type] $fields [description]
 * @return [type]         [description]
 */
function dntly_edd_campaigns_save( $fields ) {

	// Add our field
	$fields[] = 'dntly_edd_campaign';

	// Return the fields array
	return $fields;
}
add_filter( 'edd_metabox_fields_save', 'dntly_edd_campaigns_save' );


/**
 * Get the Campaigns from Donately
 * @return [type] [description]
 */
function dntly_edd_get_campaigns()
{
	global $edd_options;

	if ( edd_is_test_mode() ) {
		// set test credentials here
		$dntly_key          = $edd_options['dntly_api_key'];
		$dntly_subdomain    = 'demo';
		$dntly_api_domain   = 'dntly.com';
		$dntly_api_endpoint = "/api/v1/";
		$dntly_method       = 'admin/campaigns';
	} else {
		$dntly_key          = $edd_options['dntly_api_key'];
		$dntly_subdomain    = $edd_options['dntly_subdomain'];
		$dntly_api_domain   = 'dntly.com';
		$dntly_api_endpoint = "/api/v1/";
		$dntly_method       = 'admin/campaigns';
	}

	$url = 'https://';
	$url .= $dntly_subdomain;
	$url .= '.' . $dntly_api_domain;
	$url .= $dntly_api_endpoint;
	$url .= $dntly_method;

	$token         = $dntly_key;
	$authorization = 'Basic ' . base64_encode("{$token}:");
	$headers       = array( 'Authorization' => $authorization, 'sslverify' => false );

	$response = wp_remote_get( $url, array(
		'timeout'     => 45,
		'redirection' => 5,
		'httpversion' => '1.0',
		'blocking'    => true,
		'headers'     => $headers,
		'body'        => null,
		'cookies'     => array()
		));

	$body     = wp_remote_retrieve_body( $response );
	
	$campaigns = json_decode( $body );

	if( $campaigns->success){
		return $campaigns;	
	}

	return false;

	

}


/**
 * Descriptive text callback.
 *
 * Renders descriptive text onto the settings field.
 *
 * @since 2.1.3
 * @param array $args Arguments passed by the setting
 * @return void
 */
function edd_descriptive_text2_callback( $args ) {
	echo  $args['desc'];
}


