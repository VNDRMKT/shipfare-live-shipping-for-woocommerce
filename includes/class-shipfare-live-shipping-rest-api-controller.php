<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * REST API controller.
 *
 * @since 1.3.0
 */
class Shipfare_Live_Shipping_V1_REST_API_Controller extends WP_REST_Controller {

  /**
   * Endpoint namespace.
   *
   * @var string
   */
  protected $namespace = 'wc-shipfare/v3';

  /**
   * Route base.
   *
   * @var string
   */
  protected $rest_base = 'live-shipping-rates';

  /**
   * Post type.
   *
   * @var string
   */
  protected $post_type = 'live_shipping_method';

  /**
   * Register the routes.
   */
  public function register_routes() {
    register_rest_route( $this->namespace, '/' . $this->rest_base, array(
      array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => array( $this, 'create_item' ),
        'permission_callback' => array( $this, 'create_item_permissions_check' )
      ),
      array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => array( $this, 'get_items' ),
        'permission_callback' => array( $this, 'get_items_permissions_check' )
      ),
      array(
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => array( $this, 'delete_item' ),
        'permission_callback' => array( $this, 'create_item_permissions_check' )
      )
    ));
    register_rest_route( $this->namespace, '/' . $this->rest_base . '/configs', array(
      array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => array( $this, 'get_configs' ),
        'permission_callback' => array( $this, 'get_items_permissions_check' )
      )
    ));
  }

  /**
   * Check whether a given request has permission to read order live shipping service.
   *
   * @param  WP_REST_Request $request Full details about the request.
   * @return WP_Error|boolean
   */
  public function get_items_permissions_check( $request ) {
    if ( ! wc_rest_check_post_permissions( $this->post_type, 'read' ) ) {
      return new WP_Error( 'woocommerce_rest_cannot_view', __( 'Sorry, you cannot list resources.', $this->rest_base ), array( 'status' => rest_authorization_required_code() ) );
    }

    return true;
  }

  /**
   * Check whether a given request has permission to create live shipping service.
   *
   * @param  WP_REST_Request $request Full details about the request.
   * @return WP_Error|boolean
   */
  public function create_item_permissions_check( $request ) {
    if ( ! wc_rest_check_post_permissions( $this->post_type, 'create' ) ) {
      return new WP_Error( 'woocommerce_rest_cannot_view', __( 'Sorry, you cannot list resources.', $this->rest_base ), array( 'status' => rest_authorization_required_code() ) );
    }

    return true;
  }

  /**
   * Delete item
   *
   * @param WP_REST_Request $request Full data about the request.
   * @return WP_Error|WP_REST_Response
   */
  public function delete_item( $request ) {
    global $wpdb;

    $postid = $request->get_param( 'id' );
    wp_delete_post( $postid, true );
    delete_option( "woocommerce_live_shipping_" . $postid . "_settings" );
    $wpdb->delete( 'postmeta', [ 'post_id' => $postid ] );

    return new WP_REST_Response( array(), 200 );
  }


  /**
   * Create live_shippig config
   *
   * @param WP_REST_Request $request Full data about the request.
   * @return WP_Error|WP_REST_Response
   */
  public function get_configs( $request ) {
    return new WP_REST_Response( [ 'live_shipping_service_secret' => get_option( "live_shipping_service_secret", "" ) ], 200 );
  }

  /**
   * Create item
   *
   * @param WP_REST_Request $request Full data about the request.
   * @return WP_Error|WP_REST_Response
   */
  public function create_item( $request ) {
    $params = $request->get_params();
    $data = array(
      "post_title"        => $params["name"],
      "post_name"         => $params["name"],
      "to_ping"           => '',
      "pinged"            => '',
      "post_date_gmt"     => $params["date"],
      "post_modified_gmt" => $params["date"],
      "post_date"         => $params["date"],
      "post_modified"     => $params["date"],
      "post_excerpt"      => '',
      "post_content"      => addslashes( $params["content"] ),
      "post_type"         => LIVE_SHIPPING_SERVICE_POST_TYPE,
      "post_status"       => "publish",
      "comment_status"    => "open",
      "ping_status"       => "open"
    );

    try {
      $postId = wp_insert_post( $data );
    } catch ( WP_Error $e ) {
      return new WP_Error( 'Error', $e->get_error_message(), [
        'status' => 500
      ] );
    }

    return new WP_REST_Response( array( "postId" => $postId ), 200 );
  }

  /**
   * Get a collection of items
   *
   * @param WP_REST_Request $request Full data about the request.
   * @return WP_Error|WP_REST_Response
   */
  public function get_items( $request ) {
    global $wpdb;

    $params = $request->get_params();
    $data = array();
    $where = " post_type = '" . LIVE_SHIPPING_SERVICE_POST_TYPE . "'";

    if ( ! empty( $params['name'] ) ) {
      $where .= " AND post_title = '" . $params['name'] . "'";
    }

    $posts = $wpdb->get_results( "
      SELECT ID
      FROM {$wpdb->prefix}posts
      WHERE {$where}
    " );

    foreach ( $posts as $shipping_service_post ) {
      $data[$shipping_service_post->ID] = get_option( "woocommerce_live_shipping_" . $shipping_service_post->ID . "_settings" );
    }

    return new WP_REST_Response( $data, 200 );
  }

}