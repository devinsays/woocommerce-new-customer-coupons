<?php
/**
 * WooCommerce Coupon Restrictions - Validation.
 *
 * @class    WC_Coupon_Restrictions_Validation
 * @author   DevPress
 * @package  WooCommerce Coupon Restrictions
 * @license  GPL-2.0+
 * @since    1.3.0
 */

if ( ! defined('ABSPATH') ) {
	exit; // Exit if accessed directly.
}

class WC_Coupon_Restrictions_Validation {

	/**
	* Initialize the class.
	*/
	public static function init() {

		// Validates coupons before checkout if customer is logged in.
		add_filter( 'woocommerce_coupon_is_valid', __CLASS__ . '::validate_coupons_before_checkout', 10, 2 );

		// Validates coupons again during checkout validation.
		add_action( 'woocommerce_after_checkout_validation', __CLASS__ . '::validate_coupons_after_checkout', 1 );

	}

	/**
	 * Validates coupon if customer session data is available.
	 *
	 * @param boolean $valid
	 * @param object $coupon
	 * @return boolean
	 */
	public static function validate_coupons_before_checkout( $valid, $coupon ) {

		// If coupon already marked invalid, no sense in moving forward.
		if ( ! $valid ) {
			return false;
		}

		// Get the customer data from the session.
		$session = WC()->session->get( 'customer' );

		// If session data is not available, the coupon should remain valid.
		// We do additional validation at checkout.
		if ( ! $session ) {
			return true;
		}

		// Validate customer restrictions.
		$customer = self::session_validate_customer_restrictions( $coupon, $session );
		if ( false === $customer ) {
			return false;
		}

		// Validate location restrictions.
		$location = self::session_validate_location_restrictions( $coupon, $session );
		if ( false === $location ) {
			return false;
		}

		return true;
	}

	/**
	 * Validates customer restrictions.
	 * Returns true if customer meets $coupon criteria.
	 *
	 * @param object $coupon
	 * @param object $session
	 * @return boolean
	 */
	public static function session_validate_customer_restrictions( $coupon, $session ) {

		// If email address isn't available, coupon remains valid.
		if ( ! isset( $session['email'] ) ) {
			return true;
		}

		$email = esc_textarea( strtolower( $session['email'] ) );

		// Validate new customer restriction.
		if ( false === self::validate_new_customer_restriction( $coupon, $email ) ) {
			add_filter( 'woocommerce_coupon_error', __CLASS__ . '::validation_message_new_customer_restriction', 10, 3 );
			return false;
		}

		// Validate existing customer restriction.
		if ( false === self::validate_existing_customer_restriction( $coupon, $email ) ) {
			add_filter( 'woocommerce_coupon_error', __CLASS__ . '::validation_message_existing_customer_restriction', 10, 3 );
			return false;
		}

		return true;
	}

	/**
	 * Validates new customer restriction.
	 * Returns true if customer meets $coupon criteria.
	 *
	 * @param object $coupon
	 * @param string $email
	 * @return boolean
	 */
	public static function validate_new_customer_restriction( $coupon, $email ) {

		// If email address isn't valid, we'll wait to run the coupon validation.
		if ( ! is_email( $email ) ) {
			return true;
		}

		$customer_restriction_type = $coupon->get_meta( 'customer_restriction_type', true );
		if ( 'new' === $customer_restriction_type ) :
			if ( self::is_returning_customer( $email ) ) {
				return false;
			}
		endif;

		return true;
	}

	/**
	 * Validates existing customer restriction.
	 * Returns true if customer meets $coupon criteria.
	 *
	 * @param object $coupon
	 * @param string $email
	 * @return boolean
	 */
	public static function validate_existing_customer_restriction( $coupon, $email ) {
		$customer_restriction_type = $coupon->get_meta( 'customer_restriction_type', true );
		if ( 'existing' === $customer_restriction_type ) :
			if ( self::is_returning_customer( $email ) ) {
				return false;
			}
		endif;

		return true;
	}

	/**
	 * Validates location restrictions.
	 * Returns true if customer meets $coupon criteria.
	 *
	 * @param object $coupon
	 * @param object $session
	 * @return boolean
	 */
	public static function session_validate_location_restrictions( $coupon, $session ) {

		// If location restrictions aren't set, coupon is valid.
		if ( 'yes' != $coupon->get_meta( 'location_restrictions' ) ) {
			return true;
		}

		// Get the address type used for location restrictions (billing or shipping).
		$address = self::get_address_type_for_restriction( $coupon );

		if ( 'shipping' === $address && isset( $session['shipping_country'] ) ) {
			$country = esc_textarea( $session['shipping_country'] );
			$country_validation = self::validate_country_restriction( $coupon, $country );
		}

		if ( 'shipping' === $address && isset( $session['shipping_postcode'] ) ) {
			$zipcode = esc_textarea( $session['shipping_postcode'] );
			$zipcode_validation = self::validate_postcode_restriction( $coupon, $zipcode );
		}

		if ( 'billing' === $address && isset( $session['billing_country'] ) ) {
			$country = esc_textarea( $session['billing_country'] );
			$country_validation = self::validate_country_restriction( $coupon, $country );
		}

		if ( 'billing' === $address && isset( $session['billing_postcode'] ) ) {
			$zipcode = esc_textarea( $session['billing_postcode'] );
			$country_validation = self::validate_postcode_restriction( $coupon, $zipcode );
		}

		if ( false === $country_validation ) {
			add_filter( 'woocommerce_coupon_error', __CLASS__ . '::validation_message_country_restriction', 10, 2 );
		}

		if ( false === $zipcode_validation ) {
			add_filter( 'woocommerce_coupon_error', __CLASS__ . '::validation_message_zipcode_restriction', 10, 2 );
		}

		// Coupon is not valid if country or postcode validation failed.
		if ( false === $country_validation || false === $zipcode_validation ) {
			return false;
		}

		// Coupon passed all validation, return true.
		return true;

	}

	/**
	 * Validates country restriction.
	 * Returns true if customer meets $coupon criteria.
	 *
	 * @param object $coupon
	 * @param string $country
	 * @return boolean
	 */
	public static function validate_country_restriction( $coupon, $country ) {
		// Get the allowed countries from coupon meta.
		$allowed_countries = $coupon->get_meta( 'country_restriction', true );

		if ( ! in_array( $country, $allowed_countries ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Validates postcode restriction.
	 * Returns true if customer meets $coupon criteria.
	 *
	 * @param object $coupon
	 * @param string $country
	 * @return boolean
	 */
	public static function validate_postcode_restriction( $coupon, $postcode ) {

		// Get the allowed postcodes from coupon meta.
		$postcode_restriction = $coupon->get_meta( 'postcode_restriction', true );
		$postcode_array = explode( ',', $postcode_restriction );
		$postcode_array = array_map( 'trim', $postcode_array );

		// Converting the string to uppercase so postcode comparison is not case sensitive.
		$postcode_array = array_map( 'strtoupper', $postcode_array );

		if ( ! in_array( strtoupper( $postcode ), $postcode_array ) ) {
			return false;
		}

		return true;

	}

	/**
	 * Applies new customer coupon error message.
	 *
	 * @return string $err
	 */
	public static function validation_message_new_customer_restriction( $err, $err_code, $coupon ) {
		$err = self::coupon_error_message( 'new-customer', $err, $err_code, $coupon );
		return $err;
	}

	/**
	 * Applies existing customer coupon error message.
	 *
	 * @return string $err
	 */
	public static function validation_message_existing_customer_restriction( $err, $err_code, $coupon ) {
		$err = self::coupon_error_message( 'existing-customer', $err, $err_code, $coupon );
		return $err;
	}

	/**
	 * Applies country restriction error message.
	 *
	 * @return string $err
	 */
	public static function validation_message_country_restriction( $err, $err_code, $coupon ) {
		$err = self::coupon_error_message( 'country', $err, $err_code, $coupon );
		return $err;
	}

	/**
	 * Applies zip code restriction error message.
	 *
	 * @return string $err
	 */
	public static function validation_message_zipcode_restriction($err, $err_code, $coupon ) {
		$err = self::coupon_error_message( 'zipcode', $err, $err_code, $coupon );
		return $err;
	}

	/**
	 * Validation message helper.
	 *
	 * @return string
	 */
	public static function coupon_error_message( $key, $err, $err_code, $coupon ) {

		// Alter the validation message if coupon has been removed.
		if ( 100 === $err_code ) {
			$msg = self::get_validation_message( $key, $coupon );
			$err = apply_filters( 'woocommerce-coupon-restrictions-removed-message', $msg );
		}

		// Return validation message.
		return $err;

	}

	/**
	 * Additional validation at checkout ensures coupon is valid with $posted checkout data.
	 *
	 * @param array $posted
	 */
	public static function validate_coupons_after_checkout( $posted ) {

		if ( ! empty( WC()->cart->applied_coupons ) ) :
			foreach ( WC()->cart->applied_coupons as $code ) :

				$coupon = new WC_Coupon( $code );

				if ( $coupon->is_valid() ) :
					self::checkout_validate_new_customer_restriction( $coupon, $code, $posted );
					self::checkout_existing_customer_coupon_checkout( $coupon, $code, $posted );
					self::checkout_location_restrictions( $coupon, $code, $posted );
				endif;

			endforeach;
		endif;
	}

	/**
	 * Validates new customer coupon on checkout.
	 *
	 * @param object $coupon
	 * @param string $code
	 * @return void
	 */
	public static function checkout_validate_new_customer_restriction( $coupon, $code, $posted ) {

		$email = strtolower( $posted['billing_email'] );
		$valid = self::validate_new_customer_restriction( $coupon, $email );

		if ( false === $valid ) {
			$msg = self::get_validation_message( 'new-customer', $coupon );
			self::remove_coupon( $coupon, $code, $msg );
		}

	}

	/**
	 * Validates existing customer coupon on checkout.
	 *
	 * @param object $coupon
	 * @param string $code
	 * @param array $posted
	 * @return void
	 */
	public static function checkout_validate_existing_customer_restriction( $coupon, $code, $posted ) {

		$email = strtolower( $posted['billing_email'] );
		$valid = self::validate_existing_customer_restriction( $coupon, $email );

		if ( false === $valid ) {
			$msg = self::get_validation_message( 'existing-customer', $coupon );
			self::remove_coupon( $coupon, $code, $msg );
		}

	}

	/**
	 * Validates location restrictions.
	 * Returns true if customer meets $coupon criteria.
	 *
	 * @param object $coupon
	 * @param string $code
	 * @param array $posted
	 * @return void
	 */
	public static function checkout_validate_location_restrictions( $coupon, $code, $posted ) {

		// If location restrictions aren't set, coupon is valid.
		if ( 'yes' != $coupon->get_meta( 'location_restrictions' ) ) {
			return true;
		}

		// Get the address type used for location restrictions (billing or shipping).
		$address = self::get_address_type_for_restriction( $coupon );

		if ( 'shipping' === $address && isset( $posted['shipping_country'] ) ) {
			$country_validation = self::validate_country_restriction( $coupon, $posted['shipping_country'] );
		}

		if ( 'shipping' === $address && isset( $posted['shipping_postcode'] ) ) {
			$zipcode_validation = self::validate_postcode_restriction( $coupon, $posted['shipping_postcode'] );
		}

		if ( 'billing' === $address && isset( $posted['billing_country'] ) ) {
			$country_validation = self::validate_country_restriction( $coupon, $posted['billing_country'] );
		}

		if ( 'billing' === $address && isset( $posted['billing_postcode'] ) ) {
			$zipcode_validation = self::validate_postcode_restriction( $coupon, $posted['billing_postcode'] );
		}

		if ( false === $country_validation ) {
			$msg = self::get_validation_message( 'country', $coupon );
			self::remove_coupon( $coupon, $code, $msg );
		}

		if ( false === $zipcode_validation ) {
			$msg = self::get_validation_message( 'zipcode', $coupon );
			self::remove_coupon( $coupon, $code, $msg );
		}

	}

	/**
	 * Validation message helper.
	 *
	 * @param string $key
	 * @param object $coupon
	 * @return string
	 */
	public static function get_validation_message( $key, $coupon ) {

		$i8n_address = array(
			'shipping' => __( 'shipping', 'woocommerce-coupon-restrictions' ),
			'billing' => __( 'billing', 'woocommerce-coupon-restrictions' )
		);

		if ( $key === 'new-customer' ) {
			return sprintf( __( 'Sorry, coupon code "%s" is only valid for new customers.', 'woocommerce-coupon-restrictions' ), $coupon->code );
		}

		if ( $key === 'existing-customer' ) {
			return sprintf( __( 'Sorry, coupon code "%s" is only valid for existing customers.', 'woocommerce-coupon-restrictions' ), $coupon->code );
		}

		if ( $key === 'country' ) {
			$address_type = self::get_address_type_for_restriction( $coupon );
			$i8n_address_type = $i8n_address[$address_type];
			return sprintf( __( 'Sorry, coupon code "%s" is not valid in your %s country.', 'woocommerce-coupon-restrictions' ), $coupon->code, $i8n_address_type );
		}

		if ( $key === 'zipcode' ) {
			$address_type = self::get_address_type_for_restriction( $coupon );
			$i8n_address_type = $i8n_address[$address_type];
			return sprintf( __( 'Sorry, coupon code "%s" is not valid in your %s zip code.', 'woocommerce-coupon-restrictions' ), $coupon->code, $i8n_address_type );
		}

	}

	/**
	 * Returns whether coupon address restriction applies to 'shipping' or 'billing'.
	 *
	 * @param object $coupon
	 * @return string
	 */
	public static function get_address_type_for_restriction( $coupon ) {
		$address_type = $coupon->get_meta( 'address_for_location_restrictions', true );
		if ( ! in_array( $address_type, array( 'billing', 'shipping' ) ) ) {
			return 'shipping';
		}
		return $address_type;
	}

	/**
	 * Removes coupon and displays validation message.
	 *
	 * @param object $coupon
	 * @param string $code
	 * @param string $msg
	 * @return void
	 */
	public static function remove_coupon( $coupon, $code, $msg ) {

		// Filter to change validation text.
		$msg = apply_filters( 'woocommerce-coupon-restrictions-removed-message-with-code', $msg, $code, $coupon );

		// Remove the coupon.
		WC()->cart->remove_coupon( $code );

		// Throw a notice to stop checkout.
		wc_add_notice( $msg, 'error' );

		// Flag totals for refresh.
		WC()->session->set( 'refresh_totals', true );

	}

	/**
	 * Checks if e-mail address has been used previously for a purchase.
	 *
	 * @param string $email of customer
	 * @return boolean
	 */
	public static function is_returning_customer( $email ) {

		// Checks if there is an account associated with the $email.
		$user = get_user_by( 'email', $email );

		// If there is a user account, we can check if customer is_paying_customer.
		if ( $user ) :
			$customer = new WC_Customer( $user->ID );
			if ( $customer->get_is_paying_customer() ) {
				return true;
			}
		endif;

		// If there isn't a user account, we can check against orders.
		$customer_orders = wc_get_orders( array(
			'status' => array( 'wc-processing', 'wc-completed' ),
			'email'  => $email,
			'limit'  => 1
		) );

		// If there is at least one order, customer is returning.
		if ( 1 === count( $customer_orders ) ) {
			return true;
		}

		// If we've gotten to this point, the customer must be new.
		return false;
	}

}

WC_Coupon_Restrictions_Validation::init();
