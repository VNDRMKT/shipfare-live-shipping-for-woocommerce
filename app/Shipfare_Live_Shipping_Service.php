<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shipfare_Live_Shipping_Service extends WC_Shipping_Method
{
	const RETRY_CNT = 1;
	const RETRY_SLP = 5;//sleep seconds before next retry

	private $_callback_url = '';
	private $_secret = '';
	private $_config;

	public function __construct( $instance_id = 0)
	{
		parent::__construct($instance_id);
		$settings = get_option($this->id . '_settings');

		$this->supports = array(
			'settings',
			'shipping-zones',
		);

		$this->init_settings();

		$this->title              = isset( $settings['title'] ) ? $settings['title'] : 'Shipfare Live Shipping';
		$this->method_title       = isset( $settings['title'] ) ? $settings['title'] : 'Shipfare Live Shipping';
		$this->method_description = isset( $settings['description'] ) ? $settings['description'] : '';
		$this->_callback_url      = isset( $settings['callback_url'] ) ? $settings['callback_url'] : '';
		$this->_secret            = isset( $settings['secret'] ) ? $settings['secret'] : '';
		$this->_config            = isset( $settings['config'] ) ? $settings['config'] : '';
		$this->enabled            = isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : 'yes';

		if ( empty( $this->settings ) ) {
			$this->settings = null;
			$this->init_form_fields();
			$this->init_settings();
			$this->_updateSettings();

		} elseif ( is_admin() ) {

			$this->init_form_fields();
			add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
			$this->_updateSettings();
		}
	}

	public function init_form_fields()
	{
		$countries = WC()->countries;

		if ( isset( $countries ) ) {
			$countries_and_states = array();

			foreach ( $countries->get_countries() as $key => $value ) {
				$states = $countries->get_states( $key );

				if ( $states ) {
					foreach ( $states as $state_key => $state_value ) {
						$countries_and_states[ $key . ':' . $state_key ] = $value . ' - ' . $state_value;
					}
				} else {
					$countries_and_states[ $key ] = $value;
				}
			}
		} else {
			$countries_and_states = array();
		}

		if ( method_exists( $countries, 'get_base_address' ) ) {
			$default_country_and_state = $countries->get_base_country();
			if ($state = $countries->get_base_state()) {
				$default_country_and_state .= ':' . $state;
			}

			$default_address_1 = $countries->get_base_address();
			$default_address_2 = $countries->get_base_address_2();
			$default_city = $countries->get_base_city();
			$default_code = $countries->get_base_postcode();

		} else {
			reset( $countries_and_states );

			$default_country_and_state = key( $countries_and_states );
			$default_address_1 = '';
			$default_address_2 = '';
			$default_city = '';
			$default_code = '';
		}

		$this->form_fields = array(
			'enabled' => array(
				'title'       => __( 'Enable Live Shipping Rate Calculations', 'shipfare_ls' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'yes',
			),
			'display_errors' => array(
				'title'       => __( 'Display errors on the storefront', 'shipfare_ls' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'yes',
			),
			'origin_country_and_state'   => array(
				'title'   => __( 'Origin Country', 'shipfare_ls' ),
				'type'    => 'select',
				'options' => $countries_and_states,
				'default' => $default_country_and_state
			),
			'origin_city'      => array(
				'title'             => __( 'Origin City', 'shipfare_ls' ),
				'type'              => 'text',
				'custom_attributes' => array(
					'required' => 'required',
				),
				'default' => $default_city
			),
			'origin_address_1'   => array(
				'title'             => __( 'Origin Address Line 1', 'shipfare_ls' ),
				'type'              => 'text',
				'custom_attributes' => array(
					'required' => 'required',
				),
				'default' => $default_address_1
			),
			'origin_address_2'   => array(
				'title'             => __( 'Origin Address Line 2', 'shipfare_ls' ),
				'type'              => 'text',
				'default' => $default_address_2
			),
			'origin_postcode'  => array(
				'title'             => __( 'Origin Postcode', 'shipfare_ls' ),
				'type'              => 'text',
				'custom_attributes' => array(
					'required' => 'required',
				),
				'default' => $default_code
			),
		);
	}

	private function _get_origin()
	{
		$country_and_state = explode(':', $this->settings['origin_country_and_state'], 2);

		return array(
			'country' => $country_and_state[0],
			'state' => isset( $country_and_state[1] ) ? $country_and_state[1] : '',
			'city' => $this->settings['origin_city'],
			'address_1' => $this->settings['origin_address_1'],
			'address_2' => $this->settings['origin_address_2'],
			'postcode' => $this->settings['origin_postcode'],
		);
	}

	public function get_rates_for_package($package)
	{
		if (empty( $package['destination'] ) || $this->settings['enabled'] == 'no') {
			return $this->rates;
		}

		$package['origin'] = $this->_get_origin();

		if ( empty( $package['origin']['city'] )
		     || empty( $package['origin']['address_1'] )
		     || empty( $package['destination'] )
		     || ! $this->is_enabled()
		) {
			$this->_error(__( 'Shipping origin address is not specified.', 'shipfare_ls' ));
		}

		$package['currency'] = get_woocommerce_currency();

		$package['items'] = array();
		foreach ($package['contents'] as $item) {
			$package['items'][] = $this->_prepare_item_data($item);
		}

		$package['destination']['first_name'] = WC()->customer->get_shipping_first_name();
		$package['destination']['last_name'] = WC()->customer->get_shipping_last_name();
		$package['destination']['company'] = WC()->customer->get_shipping_company();

		$package_to_hash = $package;

		// Remove data objects so hashes are consistent.
		foreach ( $package_to_hash['contents'] as $item_id => $item ) {
			unset( $package_to_hash['contents'][ $item_id ]['data'] );
		}

		// Get rates stored in the WC session data for this package.
		$wc_session_key = 'rates_for_package_' . $this->id;
		$stored_rates   = WC()->session->get( $wc_session_key );
		$package_hash = 'wc_ship_' . $this->id . md5( wp_json_encode( $package_to_hash ));
		if ( is_array( $stored_rates ) && $package_hash === $stored_rates['package_hash'] ) {
			return $stored_rates['rates'];
		}

		if ( empty( $this->_config['allowPreEstimate'] ) ) {
			$countryLocales = WC()->countries->get_country_locale();

			if ( isset( $countryLocales[ $package['destination']['country'] ]['state']['required'] )
			     && $countryLocales[ $package['destination']['country'] ]['state']['required'] === false
			) {
				$destinationStateRequired = false;
				$package['destination']['state'] = '';
			} else {
				$destinationStateRequired = true;
			}

			if ( isset( $this->_config['requiredDestAddrFields'] ) ) {
				foreach ( $this->_config['requiredDestAddrFields'] as $field ) {
					if ( empty( $package['destination'][ $field ] ) ) {
						if ( $field === 'state' && ! $destinationStateRequired ) {
							continue;
						}

						return $this->rates;
					}
				}
			} elseif ( empty( $package['destination']['country'] )
			           || empty( $package['destination']['postcode'] )
			          //  || empty( $package['destination']['city'] )
			          //  || ( empty( $package['destination']['address_1'] ) && empty( $package['destination']['address'] ) )
			           || ($destinationStateRequired && empty( $package['destination']['state'] ))
			) {
				return $this->rates;
			}
		} elseif ( empty( $package['destination']['country'] ) || empty( $package['destination']['postcode'] ) ) {
			$this->_error( __( 'Shipping address is not complete.', 'shipfare_ls') );

			return $this->rates;
		}

		unset( $package['contents'] );
		unset( $package['rates'] );
		$suffixByRateId = true;

		// if ( defined( 'ICONIC_WDS_BASENAME' ) && function_exists( 'is_plugin_active' )) {
		// 	$suffixByRateId = !is_plugin_active( ICONIC_WDS_BASENAME );
		// }

		try {
			foreach ($this->_requestRates($package) as $rate) {
				$rateId = $suffixByRateId ? $rate['id'] : '';

				$this->add_rate(
					array(
						'id'        => $this->get_rate_id( $rateId ),
						'label'     => $rate['label'],
						'cost'      => $rate['cost'],
						'taxes'     => $rate['taxes'],
						'calc_tax'  => $rate['calc_tax'],
						'meta_data' => $rate['meta_data'],
					)
				);
			}
		} catch (Shipfare_Live_Shipping_Exception $e) {
			update_post_meta(
				str_replace( 'live_shipping_', '', $this->id ),
				'live_shipping_service_last_error',
				$e->getMessage() . PHP_EOL . $e->getTraceAsString()
			);

			$this->_error($e->getMessage());
		}

		// Store in session to avoid recalculation.
		WC()->session->set(
			$wc_session_key,
			array(
				'package_hash' => $package_hash,
				'rates'        => $this->rates,
			)
		);

		return $this->rates;
	}

	private function _error($message, $messageType = "error")
	{
		if ($this->settings['display_errors'] === 'yes' && ! wc_has_notice( $message, $messageType ) ) {
			wc_add_notice( $message, $messageType );
		}
	}

	private function _prepare_item_data($item)
	{
		$itemData = $item['data'];
		/**
		 * @var WC_Product $itemData
		 */

		$data = array(
			'id'           => $itemData->get_id(),
			'sku'          => $itemData->get_sku(),
			'name'         => $itemData->get_name(),
			'variant_id'   => $item['variation_id'] ?: null,
			'weight'       => $itemData->get_weight(),
			'length'       => $itemData->get_length(),
			'width'        => $itemData->get_width(),
			'height'       => $itemData->get_height(),
			'quantity'     => $item['quantity'],
			'price'        => $itemData->get_price(),
			'subtotal'     => $item['line_subtotal'],
			'subtotal_tax' => $item['line_subtotal_tax'],
			'total'        => $item['line_total'],
			'total_tax'    => $item['line_tax'],
		);

		return $data;
	}

	/**
	 * @param $data
	 *
	 * @return array|void
	 * @throws Shipfare_Live_Shipping_Exception
	 */
	private function _requestRates($data)
	{
		$error_msg = __( 'Can\'t retrieve shipping rates.', 'shipfare_ls' );
		$retry_count = 0;

		if (empty($this->_callback_url)) {
			$this->settings['enabled'] = 'no';//disable service
			$this->_updateSettings();

			return;
		}

		while ($retry_count++ < self::RETRY_CNT) {
			$time = (string)time();
			$args = array(
				'sslverify' => true,
				'httpversion' => '1.1',
				'timeout' => 30,
				'redirection' => 0,
				'compress' => true,
				'body' => json_encode( $data ),
				'headers' => array(
					'content-type' => 'application/json'
				)
			);

			$headersToSign = array(
				'x-live-shipping-service-timestamp' => $time,
				'x-live-shipping-service-id' => $this->id
			);

			ksort($headersToSign);

			$args['headers']['x-live-shipping-service-sign'] = base64_encode(
				hash_hmac('sha256', json_encode($headersToSign) . $args['body'], $this->_secret, true)
			);

			$args['headers'] = $args['headers'] + $headersToSign;

			$res = wp_remote_post( $this->_callback_url, $args );

			$code = wp_remote_retrieve_response_code( $res );
			$body = wp_remote_retrieve_body( $res );

			switch ( $code ) {
				case 200:
					if ( strpos(wp_remote_retrieve_header( $res, 'content-type' ), 'application/json') !== false
					     && ( ( $response = json_decode( $body, true ) ) || $response == array() )
					) {
						return $response;
					}
					break;
				case 400:
					$error_msg = __( 'Can\'t retrieve shipping rates. Unauthorized.', 'shipfare_ls' );
					break;
				case 410:
				case 404:
				case 403:
					$error_msg = __( 'Live shipping rates provider is not available.', 'shipfare_ls' );
					$this->settings['enabled'] = 'no';//disable service
					$this->_updateSettings();
					break 2;
			}

			sleep(self::RETRY_SLP);
		}

		throw new Shipfare_Live_Shipping_Exception($error_msg);
	}

	private function _updateSettings()
	{
		update_option( $this->get_option_key(), apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings ), 'yes' );
		$this->init_settings();
	}

	/**
	 * Returns a rate ID based on this methods ID and instance, with an optional
	 * suffix if distinguishing between multiple rates.
	 *
	 * @param string $suffix Suffix.
	 * @return string
	 */
	public function get_rate_id( $suffix = '' ) {
		$rate_id = array( strtolower( get_class( $this ) ) );

		if ( $this->instance_id ) {
			$rate_id[] = $this->instance_id;
		}

		if ( $suffix ) {
			$rate_id[] = $suffix;
		}

		return implode( ':', $rate_id );
	}

}