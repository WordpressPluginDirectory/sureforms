<?php
/**
 * Sureforms Admin Ajax Class.
 *
 * Class file for public functions.
 *
 * @package sureforms
 */

namespace SRFM\Inc;

use BSF_UTM_Analytics;
use SRFM\Inc\Traits\Get_Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! function_exists( 'get_plugins' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

/**
 * Public Class
 *
 * @since 0.0.1
 */
class Admin_Ajax {
	use Get_Instance;

	/**
	 * Constructor
	 *
	 * @since  0.0.1
	 */
	public function __construct() {
		add_action( 'wp_ajax_sureforms_recommended_plugin_activate', [ $this, 'required_plugin_activate' ] );
		add_action( 'wp_ajax_sureforms_recommended_plugin_install', 'wp_ajax_install_plugin' );
		add_action( 'wp_ajax_sureforms_integration', [ $this, 'generate_data_for_suretriggers_integration' ] );

		add_filter( SRFM_SLUG . '_admin_filter', [ $this, 'localize_script_integration' ] );

		// adding support for rest-nonce endpoint. To regenerate latest nonce incase of form submission failure.
		add_action( 'wp_ajax_rest-nonce', [ $this, 'print_rest_nonce' ], 999 );
		add_action( 'wp_ajax_nopriv_rest-nonce', [ $this, 'print_rest_nonce' ], 999 );
	}

	/**
	 * Required Plugin Activate
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function required_plugin_activate() {

		$response_data = [ 'message' => $this->get_error_msg( 'permission' ) ];

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( $response_data );
		}

		if ( empty( $_POST ) ) {
			$response_data = [ 'message' => $this->get_error_msg( 'invalid' ) ];
			wp_send_json_error( $response_data );
		}

		/**
		 * Nonce verification.
		 */
		if ( ! check_ajax_referer( 'sf_plugin_manager_nonce', 'security', false ) ) {
			$response_data = [ 'message' => $this->get_error_msg( 'nonce' ) ];
			wp_send_json_error( $response_data );
		}

		if ( ! current_user_can( 'install_plugins' ) || ! isset( $_POST['init'] ) || ! sanitize_text_field( wp_unslash( $_POST['init'] ) ) ) {
			wp_send_json_error(
				[
					'success' => false,
					'message' => __( 'No plugin specified', 'sureforms' ),
				]
			);
		}

		$plugin_init = isset( $_POST['init'] ) ? sanitize_text_field( wp_unslash( $_POST['init'] ) ) : '';

		$plugin_slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';

		$activate = activate_plugin( $plugin_init, '', false, true );

		if ( is_wp_error( $activate ) ) {
			wp_send_json_error(
				[
					'success' => false,
					'message' => $activate->get_error_message(),
				]
			);
		}

		if ( class_exists( 'BSF_UTM_Analytics' ) && is_callable( 'BSF_UTM_Analytics::update_referer' ) ) {
			$plugin_slug = pathinfo( $plugin_slug, PATHINFO_FILENAME );
			BSF_UTM_Analytics::update_referer( 'sureforms', $plugin_slug );
		}

		wp_send_json_success(
			[
				'success' => true,
				'message' => __( 'Plugin Successfully Activated', 'sureforms' ),
			]
		);
	}

	/**
	 * Get ajax error message.
	 *
	 * @param string $type Message type.
	 * @return string
	 * @since 0.0.2
	 */
	public function get_error_msg( $type ) {

		if ( ! isset( $this->errors[ $type ] ) ) {
			$type = 'default';
		}
		if ( ! isset( $this->errors ) ) {
			return '';
		}
		return $this->errors[ $type ];
	}

	/**
	 * Localize the variables required for integration plugins.
	 *
	 * @param array<mixed> $values localized values.
	 * @return array<mixed>
	 * @since 0.0.1
	 */
	public function localize_script_integration( $values ) {
		$is_screen_sureforms_menu = Helper::validate_request_context( 'sureforms_menu', 'page' );
		return array_merge(
			$values,
			[
				'ajax_url'               => admin_url( 'admin-ajax.php' ),
				'sfPluginManagerNonce'   => wp_create_nonce( 'sf_plugin_manager_nonce' ),
				'plugin_installer_nonce' => wp_create_nonce( 'updates' ),
				'isRTL'                  => is_rtl(),
				'current_screen_id'      => $is_screen_sureforms_menu ? 'sureforms_menu' : '',
				'form_id'                => get_post() ? get_post()->ID : '',
				'suretriggers_nonce'     => wp_create_nonce( 'suretriggers_nonce' ),
			]
		);
	}

	/**
	 * Generates data required for suretriggers integration
	 *
	 * @since 0.0.8
	 * @return void
	 */
	public function generate_data_for_suretriggers_integration() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to access this page.', 'sureforms' ) ] );
		}

		if ( ! check_ajax_referer( 'suretriggers_nonce', 'security', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'sureforms' ) ] );
		}

		if ( empty( $_POST['formId'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Form ID is required.', 'sureforms' ) ] );
		}

		if ( ! Helper::is_suretriggers_ready() ) {
			wp_send_json_error(
				[
					'code'    => 'invalid_secret_key',
					'message' => __( 'OttoKit is not configured properly.', 'sureforms' ),
				]
			);
		}

		$form_id = Helper::get_integer_value( sanitize_text_field( wp_unslash( $_POST['formId'] ) ) );
		$form    = get_post( $form_id );

		if ( is_null( $form ) || SRFM_FORMS_POST_TYPE !== $form->post_type ) {
			wp_send_json_error( [ 'message' => __( 'Invalid form ID.', 'sureforms' ) ] );
		}

		// Translators: %s: Form ID.
		$form_name = ! empty( $form->post_title ) ? $form->post_title : sprintf( __( 'SureForms id: %s', 'sureforms' ), $form_id );
		$api_url   = apply_filters( 'suretriggers_get_iframe_url', SRFM_SURETRIGGERS_INTEGRATION_BASE_URL );

		// This is the format of data required by SureTriggers for adding iframe in target id.
		$body = [
			'client_id'           => 'SureForms',
			'st_embed_url'        => $api_url,
			'embedded_identifier' => $form_id,
			'target'              => 'suretriggers-iframe-wrapper', // div where we want SureTriggers to add iframe should have this target id.
			'event'               => [
				'label'       => __( 'Form Submitted', 'sureforms' ),
				'value'       => 'sureforms_form_submitted',
				'description' => __( 'Runs when a form is submitted', 'sureforms' ),
			],
			'summary'             => $form_name,
			'selected_options'    => [
				'form_id' => [
					'value' => $form_id,
					'label' => $form_name,
				],
			],
			'integration'         => 'SureForms',
			'sample_response'     => [
				'form_id'   => $form_id,
				'to_emails' => [
					'dev-email@wpengine.local',
				],
				'form_name' => $form_name,
				'data'      => $this->get_form_fields( $form_id ),
			],
		];

		// Adding entry_id in body sample response if do_not_store_entries is not enabled.
		$compliance           = get_post_meta( $form_id, '_srfm_compliance', true );
		$do_not_store_entries = is_array( $compliance ) && isset( $compliance[0]['do_not_store_entries'] )
		? $compliance[0]['do_not_store_entries']
		: null;

		if ( ! $do_not_store_entries ) {
			$body['sample_response']['entry_id'] = 12;
		}

		wp_send_json_success(
			[
				'message' => 'success',
				'data'    => apply_filters( 'srfm_suretriggers_integration_data_filter', $body, $form_id ),
			]
		);
	}

	/**
	 * This function populates data for particular form.
	 *
	 * @param  int $form_id Form ID.
	 * @since 0.0.8
	 * @return array<mixed>
	 */
	public function get_form_fields( $form_id ) {
		if ( empty( $form_id ) || ! is_int( $form_id ) ) {
			return [];
		}

		if ( SRFM_FORMS_POST_TYPE !== get_post_type( $form_id ) ) {
			return [];
		}

		$post = get_post( $form_id );

		if ( is_null( $post ) ) {
			return [];
		}

		$blocks = parse_blocks( $post->post_content );

		if ( empty( $blocks ) ) {
			return [];
		}

		$data = [];

		foreach ( $blocks as $block ) {
			if ( ! empty( $block['blockName'] ) && 0 === strpos( $block['blockName'], 'srfm/' ) ) {
				if ( ! empty( $block['attrs']['slug'] ) ) {
					$data[ $block['attrs']['slug'] ] = $this->get_sample_data( $block['blockName'] );
				}
			}
		}

		if ( empty( $data ) ) {
			return [];
		}

		return $data;
	}

	/**
	 * Returns sample data for a block.
	 *
	 * @param  string $block_name Block name.
	 * @since 0.0.8
	 * @return mixed
	 */
	public function get_sample_data( $block_name ) {
		if ( empty( $block_name ) ) {
			return __( 'Sample data', 'sureforms' );
		}

		$dummy_data = [
			'srfm/input'            => __( 'Sample input data', 'sureforms' ),
			'srfm/email'            => 'noreply@sureforms.com',
			'srfm/textarea'         => __( 'Sample textarea data', 'sureforms' ),
			'srfm/number'           => 123,
			'srfm/checkbox'         => 'checkbox value',
			'srfm/gdpr'             => 'GDPR value',
			'srfm/phone'            => '1234567890',
			'srfm/address'          => __( 'Address data', 'sureforms' ),
			'srfm/address-compact'  => __( 'Address data', 'sureforms' ),
			'srfm/dropdown'         => __( 'Selected dropdown option', 'sureforms' ),
			'srfm/multi-choice'     => __( 'Selected Multichoice option', 'sureforms' ),
			'srfm/radio'            => __( 'Selected radio option', 'sureforms' ),
			'srfm/submit'           => __( 'Submit', 'sureforms' ),
			'srfm/url'              => 'https://example.com',
			'srfm/date-time-picker' => '2022-01-01 12:00:00',
			'srfm/hidden'           => __( 'Hidden Value', 'sureforms' ),
			'srfm/slider'           => 50,
			'srfm/password'         => 'DummyPassword123',
			'srfm/rating'           => 4,
			'srfm/upload'           => 'https://example.com/uploads/file.pdf',
		];

		if ( ! empty( $dummy_data[ $block_name ] ) ) {
			return $dummy_data[ $block_name ];
		}
			return __( 'Sample data', 'sureforms' );
	}

	/**
	 * This function will echo wp_rest nonce
	 * was required to provide nonce for fallback of wp.apiFetch
	 *
	 * @since 1.2.4
	 * @return void
	 */
	public function print_rest_nonce() {
		echo esc_js( wp_create_nonce( 'wp_rest' ) );
		exit;
	}
}
