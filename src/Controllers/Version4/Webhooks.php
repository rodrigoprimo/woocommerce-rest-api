<?php
/**
 * REST API Webhooks controller
 *
 * Handles requests to the /webhooks endpoint.
 *
 * @package Automattic/WooCommerce/RestApi
 */

namespace Automattic\WooCommerce\RestApi\Controllers\Version4;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\RestApi\Controllers\Version4\Utilities\Pagination;

/**
 * REST API Webhooks controller class.
 */
class Webhooks extends AbstractController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'webhooks';

	/**
	 * Permission to check.
	 *
	 * @var string
	 */
	protected $resource_type = 'webhooks';

	/**
	 * Post type.
	 *
	 * @var string
	 */
	protected $post_type = 'shop_webhook';

	/**
	 * Register the routes for webhooks.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array_merge(
						$this->get_endpoint_args_for_item_schema( \WP_REST_Server::CREATABLE ),
						array(
							'topic'        => array(
								'required'    => true,
								'type'        => 'string',
								'description' => __( 'Webhook topic.', 'woocommerce-rest-api' ),
							),
							'delivery_url' => array(
								'required'    => true,
								'type'        => 'string',
								'description' => __( 'Webhook delivery URL.', 'woocommerce-rest-api' ),
							),
						)
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			),
			true
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'args'   => array(
					'id' => array(
						'description' => __( 'Unique identifier for the resource.', 'woocommerce-rest-api' ),
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'context' => $this->get_context_param( array( 'default' => 'view' ) ),
					),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( \WP_REST_Server::EDITABLE ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
					'args'                => array(
						'force' => array(
							'default'     => false,
							'type'        => 'boolean',
							'description' => __( 'Required to be true, as resource does not support trashing.', 'woocommerce-rest-api' ),
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			),
			true
		);

		$this->register_batch_route();
	}

	/**
	 * Get the default REST API version.
	 *
	 * @since  3.0.0
	 * @return string
	 */
	protected function get_default_api_version() {
		return 'wp_api_v4';
	}

	/**
	 * Get all webhooks.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_items( $request ) {
		$args            = array();
		$args['order']   = $request['order'];
		$args['orderby'] = $request['orderby'];
		$args['status']  = 'all' === $request['status'] ? '' : $request['status'];
		$args['include'] = implode( ',', $request['include'] );
		$args['exclude'] = implode( ',', $request['exclude'] );
		$args['limit']   = $request['per_page'];
		$args['search']  = $request['search'];
		$args['before']  = $request['before'];
		$args['after']   = $request['after'];

		if ( empty( $request['offset'] ) ) {
			$args['offset'] = 1 < $request['page'] ? ( $request['page'] - 1 ) * $args['limit'] : 0;
		}

		/**
		 * Filter arguments, before passing to WC_Webhook_Data_Store->search_webhooks, when querying webhooks via the REST API.
		 *
		 * @param array           $args    Array of arguments for $wpdb->get_results().
		 * @param \WP_REST_Request $request The current request.
		 */
		$prepared_args = apply_filters( 'woocommerce_rest_webhook_query', $args, $request );
		unset( $prepared_args['page'] );
		$prepared_args['paginate'] = true;

		// Get the webhooks.
		$webhooks    = array();
		$data_store  = \WC_Data_Store::load( 'webhook' );
		$results     = $data_store->search_webhooks( $prepared_args );
		$webhook_ids = $results->webhooks;

		foreach ( $webhook_ids as $webhook_id ) {
			$object = $this->get_object( $webhook_id );

			if ( ! $object || 0 === $object->get_id() ) {
				continue;
			}

			$data       = $this->prepare_item_for_response( $object, $request );
			$webhooks[] = $this->prepare_response_for_collection( $data );
		}


		$total_webhooks = $results->total;
		$max_pages      = $results->max_num_pages;
		$response       = rest_ensure_response( $webhooks );
		$response       = Pagination::add_pagination_headers( $response, $request, $total_webhooks, $max_pages );

		return $response;
	}

	/**
	 * Get object.
	 *
	 * @param  int $id Object ID.
	 * @return \WC_Webhook|bool
	 */
	protected function get_object( $id ) {
		$webhook = wc_get_webhook( $id );

		if ( empty( $webhook ) || is_null( $webhook ) ) {
			return false;
		}

		return $webhook;
	}

	/**
	 * Get a single item.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_item( $request ) {
		$object = $this->get_object( (int) $request['id'] );

		if ( ! $object || 0 === $object->get_id() ) {
			return new \WP_Error( "woocommerce_rest_{$this->post_type}_invalid_id", __( 'Invalid ID.', 'woocommerce-rest-api' ), array( 'status' => 404 ) );
		}

		return $this->prepare_item_for_response( $object, $request );
	}

	/**
	 * Create a single webhook.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function create_item( $request ) {
		if ( ! empty( $request['id'] ) ) {
			/* translators: %s: post type */
			return new \WP_Error( "woocommerce_rest_{$this->post_type}_exists", sprintf( __( 'Cannot create existing %s.', 'woocommerce-rest-api' ), $this->post_type ), array( 'status' => 400 ) );
		}

		// Validate topic.
		if ( empty( $request['topic'] ) || ! wc_is_webhook_valid_topic( strtolower( $request['topic'] ) ) ) {
			return new \WP_Error( "woocommerce_rest_{$this->post_type}_invalid_topic", __( 'Webhook topic is required and must be valid.', 'woocommerce-rest-api' ), array( 'status' => 400 ) );
		}

		// Validate delivery URL.
		if ( empty( $request['delivery_url'] ) || ! wc_is_valid_url( $request['delivery_url'] ) ) {
			return new \WP_Error( "woocommerce_rest_{$this->post_type}_invalid_delivery_url", __( 'Webhook delivery URL must be a valid URL starting with http:// or https://.', 'woocommerce-rest-api' ), array( 'status' => 400 ) );
		}

		$post = $this->prepare_item_for_database( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$webhook = new \WC_Webhook();
		$webhook->set_name( $post->post_title );
		$webhook->set_user_id( $post->post_author );
		$webhook->set_status( 'publish' === $post->post_status ? 'active' : 'disabled' );
		$webhook->set_topic( $request['topic'] );
		$webhook->set_delivery_url( $request['delivery_url'] );
		$webhook->set_secret( ! empty( $request['secret'] ) ? $request['secret'] : wp_generate_password( 50, true, true ) );
		$webhook->set_api_version( $this->get_default_api_version() );
		$webhook->save();

		$this->update_additional_fields_for_object( $webhook, $request );

		/**
		 * Fires after a single item is created or updated via the REST API.
		 *
		 * @param WC_Webhook      $webhook  Webhook data.
		 * @param \WP_REST_Request $request  Request object.
		 * @param bool            $creating True when creating item, false when updating.
		 */
		do_action( 'woocommerce_rest_insert_webhook_object', $webhook, $request, true );

		$request->set_param( 'context', 'edit' );
		$response = $this->prepare_item_for_response( $webhook, $request );
		$response = rest_ensure_response( $response );
		$response->set_status( 201 );
		$response->header( 'Location', rest_url( sprintf( '/%s/%s/%d', $this->namespace, $this->rest_base, $webhook->get_id() ) ) );

		// Send ping.
		$webhook->deliver_ping();

		return $response;
	}

	/**
	 * Update a single webhook.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function update_item( $request ) {
		$id      = (int) $request['id'];
		$webhook = wc_get_webhook( $id );

		if ( empty( $webhook ) || is_null( $webhook ) ) {
			return new \WP_Error( "woocommerce_rest_{$this->post_type}_invalid_id", __( 'ID is invalid.', 'woocommerce-rest-api' ), array( 'status' => 400 ) );
		}

		// Update topic.
		if ( ! empty( $request['topic'] ) ) {
			if ( wc_is_webhook_valid_topic( strtolower( $request['topic'] ) ) ) {
				$webhook->set_topic( $request['topic'] );
			} else {
				return new \WP_Error( "woocommerce_rest_{$this->post_type}_invalid_topic", __( 'Webhook topic must be valid.', 'woocommerce-rest-api' ), array( 'status' => 400 ) );
			}
		}

		// Update delivery URL.
		if ( ! empty( $request['delivery_url'] ) ) {
			if ( wc_is_valid_url( $request['delivery_url'] ) ) {
				$webhook->set_delivery_url( $request['delivery_url'] );
			} else {
				return new \WP_Error( "woocommerce_rest_{$this->post_type}_invalid_delivery_url", __( 'Webhook delivery URL must be a valid URL starting with http:// or https://.', 'woocommerce-rest-api' ), array( 'status' => 400 ) );
			}
		}

		// Update secret.
		if ( ! empty( $request['secret'] ) ) {
			$webhook->set_secret( $request['secret'] );
		}

		// Update status.
		if ( ! empty( $request['status'] ) ) {
			if ( wc_is_webhook_valid_status( strtolower( $request['status'] ) ) ) {
				$webhook->set_status( $request['status'] );
			} else {
				return new \WP_Error( "woocommerce_rest_{$this->post_type}_invalid_status", __( 'Webhook status must be valid.', 'woocommerce-rest-api' ), array( 'status' => 400 ) );
			}
		}

		$post = $this->prepare_item_for_database( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( isset( $post->post_title ) ) {
			$webhook->set_name( $post->post_title );
		}

		$webhook->save();

		$this->update_additional_fields_for_object( $webhook, $request );

		/**
		 * Fires after a single item is created or updated via the REST API.
		 *
		 * @param WC_Webhook      $webhook  Webhook data.
		 * @param \WP_REST_Request $request  Request object.
		 * @param bool            $creating True when creating item, false when updating.
		 */
		do_action( 'woocommerce_rest_insert_webhook_object', $webhook, $request, false );

		$request->set_param( 'context', 'edit' );
		$response = $this->prepare_item_for_response( $webhook, $request );

		return rest_ensure_response( $response );
	}

	/**
	 * Delete a single webhook.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$id    = (int) $request['id'];
		$force = isset( $request['force'] ) ? (bool) $request['force'] : false;

		// We don't support trashing for this type, error out.
		if ( ! $force ) {
			return new \WP_Error( 'woocommerce_rest_trash_not_supported', __( 'Webhooks do not support trashing.', 'woocommerce-rest-api' ), array( 'status' => 501 ) );
		}

		$webhook = wc_get_webhook( $id );

		if ( empty( $webhook ) || is_null( $webhook ) ) {
			return new \WP_Error( "woocommerce_rest_{$this->post_type}_invalid_id", __( 'Invalid ID.', 'woocommerce-rest-api' ), array( 'status' => 404 ) );
		}

		$request->set_param( 'context', 'edit' );
		$previous = $this->prepare_item_for_response( $webhook, $request );
		$result   = $webhook->delete( true );
		if ( ! $result ) {
			/* translators: %s: post type */
			return new WP_Error( 'woocommerce_rest_cannot_delete', sprintf( __( 'The %s cannot be deleted.', 'woocommerce-rest-api' ), $this->post_type ), array( 'status' => 500 ) );
		}
		$response = new \WP_REST_Response();
		$response->set_data(
			array(
				'deleted'  => true,
				'previous' => $previous->get_data(),
			)
		);

		/**
		 * Fires after a single item is deleted or trashed via the REST API.
		 *
		 * @param WC_Webhook       $webhook     The deleted or trashed item.
		 * @param \WP_REST_Response $response The response data.
		 * @param \WP_REST_Request  $request  The request sent to the API.
		 */
		do_action( 'woocommerce_rest_delete_webhook_object', $webhook, $response, $request );

		return $response;
	}

	/**
	 * Prepare a single webhook for create or update.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_Error|stdClass $data Post object.
	 */
	protected function prepare_item_for_database( $request ) {
		$data = new \stdClass();

		// Post ID.
		if ( isset( $request['id'] ) ) {
			$data->ID = absint( $request['id'] );
		}

		// Validate required POST fields.
		if ( 'POST' === $request->get_method() && empty( $data->ID ) ) {
			$data->post_title = ! empty( $request['name'] ) ? $request['name'] : sprintf( __( 'Webhook created on %s', 'woocommerce-rest-api' ), strftime( _x( '%b %d, %Y @ %I:%M %p', 'Webhook created on date parsed by strftime', 'woocommerce-rest-api' ) ) ); // @codingStandardsIgnoreLine

			// Post author.
			$data->post_author = get_current_user_id();

			// Post password.
			$data->post_password = 'webhook_' . wp_generate_password();

			// Post status.
			$data->post_status = 'publish';
		} else {

			// Allow edit post title.
			if ( ! empty( $request['name'] ) ) {
				$data->post_title = $request['name'];
			}
		}

		// Comment status.
		$data->comment_status = 'closed';

		// Ping status.
		$data->ping_status = 'closed';

		/**
		 * Filter the query_vars used in `get_items` for the constructed query.
		 *
		 * The dynamic portion of the hook name, $this->post_type, refers to post_type of the post being
		 * prepared for insertion.
		 *
		 * @param \stdClass        $data An object representing a single item prepared
		 *                                       for inserting or updating the database.
		 * @param \WP_REST_Request $request       Request object.
		 */
		return apply_filters( "woocommerce_rest_pre_insert_{$this->post_type}", $data, $request );
	}

	/**
	 * Get data for this object in the format of this endpoint's schema.
	 *
	 * @param \WC_Webhook      $object Object to prepare.
	 * @param \WP_REST_Request $request Request object.
	 * @return array Array of data in the correct format.
	 */
	protected function get_data_for_response( $object, $request ) {
		return array(
			'id'                => $object->get_id(),
			'name'              => $object->get_name(),
			'status'            => $object->get_status(),
			'topic'             => $object->get_topic(),
			'resource'          => $object->get_resource(),
			'event'             => $object->get_event(),
			'hooks'             => $object->get_hooks(),
			'delivery_url'      => $object->get_delivery_url(),
			'date_created'      => wc_rest_prepare_date_response( $object->get_date_created(), false ),
			'date_created_gmt'  => wc_rest_prepare_date_response( $object->get_date_created() ),
			'date_modified'     => wc_rest_prepare_date_response( $object->get_date_modified(), false ),
			'date_modified_gmt' => wc_rest_prepare_date_response( $object->get_date_modified() ),
		);
	}

	/**
	 * Prepare links for the request.
	 *
	 * @param mixed            $item Object to prepare.
	 * @param \WP_REST_Request $request Request object.
	 * @return array
	 */
	protected function prepare_links( $item, $request ) {
		$links = array(
			'self'       => array(
				'href' => rest_url( sprintf( '/%s/%s/%d', $this->namespace, $this->rest_base, $item->get_id() ) ),
			),
			'collection' => array(
				'href' => rest_url( sprintf( '/%s/%s', $this->namespace, $this->rest_base ) ),
			),
		);

		return $links;
	}

	/**
	 * Get the Webhook's schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'webhook',
			'type'       => 'object',
			'properties' => array(
				'id'                => array(
					'description' => __( 'Unique identifier for the resource.', 'woocommerce-rest-api' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'name'              => array(
					'description' => __( 'A friendly name for the webhook.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'status'            => array(
					'description' => __( 'Webhook status.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'default'     => 'active',
					'enum'        => array_keys( wc_get_webhook_statuses() ),
					'context'     => array( 'view', 'edit' ),
				),
				'topic'             => array(
					'description' => __( 'Webhook topic.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'resource'          => array(
					'description' => __( 'Webhook resource.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'event'             => array(
					'description' => __( 'Webhook event.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'hooks'             => array(
					'description' => __( 'WooCommerce action names associated with the webhook.', 'woocommerce-rest-api' ),
					'type'        => 'array',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
					'items'       => array(
						'type' => 'string',
					),
				),
				'delivery_url'      => array(
					'description' => __( 'The URL where the webhook payload is delivered.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'format'      => 'uri',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'secret'            => array(
					'description' => __( "Secret key used to generate a hash of the delivered webhook and provided in the request headers. This will default to a MD5 hash from the current user's ID|username if not provided.", 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'edit' ),
				),
				'date_created'      => array(
					'description' => __( "The date the webhook was created, in the site's timezone.", 'woocommerce-rest-api' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_created_gmt'  => array(
					'description' => __( 'The date the webhook was created, as GMT.', 'woocommerce-rest-api' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_modified'     => array(
					'description' => __( "The date the webhook was last modified, in the site's timezone.", 'woocommerce-rest-api' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_modified_gmt' => array(
					'description' => __( 'The date the webhook was last modified, as GMT.', 'woocommerce-rest-api' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $schema );
	}

	/**
	 * Get the query params for collections of attachments.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();

		$params['context']['default'] = 'view';

		$params['after']   = array(
			'description'       => __( 'Limit response to resources published after a given ISO8601 compliant date.', 'woocommerce-rest-api' ),
			'type'              => 'string',
			'format'            => 'date-time',
			'validate_callback' => 'rest_validate_request_arg',
		);
		$params['before']  = array(
			'description'       => __( 'Limit response to resources published before a given ISO8601 compliant date.', 'woocommerce-rest-api' ),
			'type'              => 'string',
			'format'            => 'date-time',
			'validate_callback' => 'rest_validate_request_arg',
		);
		$params['exclude'] = array(
			'description'       => __( 'Ensure result set excludes specific IDs.', 'woocommerce-rest-api' ),
			'type'              => 'array',
			'items'             => array(
				'type' => 'integer',
			),
			'default'           => array(),
			'sanitize_callback' => 'wp_parse_id_list',
		);
		$params['include'] = array(
			'description'       => __( 'Limit result set to specific ids.', 'woocommerce-rest-api' ),
			'type'              => 'array',
			'items'             => array(
				'type' > 'integer',
			),
			'default'           => array(),
			'sanitize_callback' => 'wp_parse_id_list',
		);
		$params['offset']  = array(
			'description'       => __( 'Offset the result set by a specific number of items.', 'woocommerce-rest-api' ),
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
		);
		$params['order']   = array(
			'description'       => __( 'Order sort attribute ascending or descending.', 'woocommerce-rest-api' ),
			'type'              => 'string',
			'default'           => 'desc',
			'enum'              => array( 'asc', 'desc' ),
			'validate_callback' => 'rest_validate_request_arg',
		);
		$params['orderby'] = array(
			'description'       => __( 'Sort collection by object attribute.', 'woocommerce-rest-api' ),
			'type'              => 'string',
			'default'           => 'date',
			'enum'              => array(
				'date',
				'id',
				'title',
			),
			'validate_callback' => 'rest_validate_request_arg',
		);
		$params['status']  = array(
			'default'           => 'all',
			'description'       => __( 'Limit result set to webhooks assigned a specific status.', 'woocommerce-rest-api' ),
			'type'              => 'string',
			'enum'              => array( 'all', 'active', 'paused', 'disabled' ),
			'sanitize_callback' => 'sanitize_key',
			'validate_callback' => 'rest_validate_request_arg',
		);

		return $params;
	}

	/**
	 * Return suffix for item action hooks.
	 *
	 * @return string
	 */
	protected function get_hook_suffix() {
		return $this->post_type;
	}
}
