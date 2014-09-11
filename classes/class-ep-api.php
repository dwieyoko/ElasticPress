<?php

class EP_API {

	/**
	 * Placeholder method
	 *
	 * @since 0.1.0
	 */
	public function __construct() { }

	/**
	 * Return singleton instance of class
	 *
	 * @return EP_API
	 * @since 0.1.0
	 */
	public static function factory() {
		static $instance = false;

		if ( ! $instance  ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * Index a post under a given site index or the global index ($site_id = 0)
	 *
	 * @param array $post
	 * @since 0.1.0
	 * @return array|bool|mixed
	 */
	public function index_post( $post ) {

		$index_url = ep_get_index_url();

		$url = $index_url . '/post/' . $post['post_id'];

		$request = wp_remote_request( $url, array( 'body' => json_encode( $post ), 'method' => 'PUT' ) );

		if ( ! is_wp_error( $request ) ) {
			$response_body = wp_remote_retrieve_body( $request );

			return json_decode( $response_body );
		}

		return false;
	}

	/**
	 * Pull the site id from the index name
	 *
	 * @param string $index_name
	 * @since 0.9.0
	 * @return int
	 */
	public function parse_site_id( $index_name ) {
		return (int) preg_replace( '#^.*\-([0-9]+)$#', '$1', $index_name );
	}

	/**
	 * Refresh the current index
	 *
	 * @since 0.9.0
	 * @return bool
	 */
	public function refresh_index() {
		$request = wp_remote_request( ep_get_index_url() . '/_refresh', array( 'method' => 'POST' ) );

		if ( ! is_wp_error( $request ) ) {
			if ( isset( $request['response']['code'] ) && 200 === $request['response']['code'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Search for posts under a specific site index or the global index ($site_id = 0).
	 *
	 * @param array $args
	 * @param string $scope
	 * @since 0.1.0
	 * @return array
	 */
	public function search( $args, $scope = 'current' ) {
		$index = null;
		if ( 'all' === $scope ) {
			$index = ep_get_network_alias();
		} else if ( is_int( $scope ) ) {
			$index = ep_get_index_name( $scope );
		}

		$index_url = ep_get_index_url( $index );

		$url = $index_url . '/post/_search';

		$request = wp_remote_request( $url, array( 'body' => json_encode( $args ), 'method' => 'POST' ) );

		if ( ! is_wp_error( $request ) ) {

			// Allow for direct response retrieval
			do_action( 'ep_retrieve_raw_response', $request, $args, $scope );

			$response_body = wp_remote_retrieve_body( $request );

			$response = json_decode( $response_body, true );

			if ( $this->is_empty_search( $response ) ) {
				return array( 'found_posts' => 0, 'posts' => array() );
			}

			$hits = $response['hits']['hits'];

			// Check for and store aggregations
			if ( ! empty( $response['aggregations'] ) ) {
				do_action( 'ep_retrieve_aggregations', $response['aggregations'], $args, $scope );
			}

			$posts = array();

			foreach ( $hits as $hit ) {
				$post = $hit['_source'];
				$post['site_id'] = $this->parse_site_id( $hit['_index'] );
				$posts[] = $post;
			}

			return array( 'found_posts' => $response['hits']['total'], 'posts' => $posts );
		}

		return array( 'found_posts' => 0, 'posts' => array() );
	}

	/**
	 * Check if a response array contains results or not
	 *
	 * @param array $response
	 * @since 0.1.2
	 * @return bool
	 */
	public function is_empty_search( $response ) {

		if ( ! is_array( $response ) ) {
			return true;
		}

		if ( isset( $response['error'] ) ) {
			return true;
		}

		if ( empty( $response['hits'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Delete a post from the ES server given a site ID and a host site ID which
	 * is used to determine the index to delete from.
	 *
	 * @param int $post_id
	 * @since 0.1.0
	 * @return bool
	 */
	public function delete_post( $post_id  ) {
		$index_url = ep_get_index_url();

		$url = $index_url . '/post/' . $post_id;

		$request = wp_remote_request( $url, array( 'method' => 'DELETE' ) );

		if ( ! is_wp_error( $request ) ) {
			$response_body = wp_remote_retrieve_body( $request );

			$response = json_decode( $response_body, true );

			if ( ! empty( $response['found'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get a post from the index
	 *
	 * @param int $post_id
	 * @since 0.9.0
	 * @return bool
	 */
	public function get_post( $post_id ) {
		$index_url = ep_get_index_url();

		$url = $index_url . '/post/' . $post_id;

		$request = wp_remote_request( $url, array( 'method' => 'GET' ) );

		if ( ! is_wp_error( $request ) ) {
			$response_body = wp_remote_retrieve_body( $request );

			$response = json_decode( $response_body, true );

			if ( ! empty( $response['exists'] ) || ! empty( $response['found'] ) ) {
				return $response['_source'];
			}
		}

		return false;
	}

	/**
	 * Ping the server to ensure the Elasticsearch server is operating and the index exists
	 *
	 * @since 0.1.1
	 * @return bool
	 */
	public function is_alive() {

		$is_alive = false;

		$url = ep_get_index_url() . '/_status';

		$request = wp_remote_request( $url );

		if ( ! is_wp_error( $request ) ) {
			if ( isset( $request['response']['code'] ) && 200 === $request['response']['code'] ) {
				$is_alive = true;
			}
		}

		// Return our status and cache it
		return $is_alive;
	}

	/**
	 * Delete the network index alias
	 *
	 * @since 0.9.0
	 * @return bool|array
	 */
	public function delete_network_alias() {
		$url = untrailingslashit( EP_HOST ) . '/*/_alias/' . ep_get_network_alias();

		$request = wp_remote_request( $url, array( 'method' => 'DELETE' ) );

		if ( ! is_wp_error( $request ) && ( 200 >= wp_remote_retrieve_response_code( $request ) && 300 > wp_remote_retrieve_response_code( $request ) ) ) {
			$response_body = wp_remote_retrieve_body( $request );

			return json_decode( $response_body );
		}

		return false;
	}

	/**
	 * Create the network alias from an array of indexes
	 *
	 * @param array $indexes
	 * @since 0.9.0
	 * @return array|bool
	 */
	public function create_network_alias( $indexes ) {
		$url = untrailingslashit( EP_HOST ) . '/_aliases';

		$args = array(
			'actions' => array()
		);

		foreach ( $indexes as $index ) {
			$args['actions'][] = array(
				'add' => array(
					'index' => $index,
					'alias' => ep_get_network_alias(),
				)
			);
		}

		$request = wp_remote_request( $url, array( 'body' => json_encode( $args ), 'method' => 'POST' ) );

		if ( ! is_wp_error( $request ) && ( 200 >= wp_remote_retrieve_response_code( $request ) && 300 > wp_remote_retrieve_response_code( $request ) ) ) {
			$response_body = wp_remote_retrieve_body( $request );

			return json_decode( $response_body );
		}

		return false;
	}

	/**
	 * Send mapping to ES
	 *
	 * @since 0.9.0
	 * @return array|bool|mixed
	 */
	public function put_mapping() {
		$mapping = array(
			'settings' => array(
				'analysis' => array(
					'analyzer' => array(
						'default' => array(
							'tokenizer' => 'standard',
							'filter' => array( 'standard', 'ewp_word_delimiter', 'lowercase', 'stop', 'ewp_snowball' ),
							'language' => 'English'
						),
						'shingle_analyzer' => array(
							'type' => 'custom',
							'tokenizer' => 'standard',
							'filter' => array( 'lowercase', 'shingle_filter' )
						),
					),
					'filter' => array(
						'shingle_filter' => array(
							'type' => 'shingle',
							'min_shingle_size' => 2,
							'max_shingle_size' => 5
						),
						'ewp_word_delimiter' => array(
							'type' => 'word_delimiter',
							'preserve_original' => true
						),
						'ewp_snowball' => array(
							'type' => 'snowball',
							'language' => 'English'
						),
						'edge_ngram' => array(
							'side' => 'front',
							'max_gram' => 10,
							'min_gram' => 3,
							'type' => 'edgeNGram'
						)
					)
				)
			),
			'mappings' => array(
				'post' => array(
					"date_detection" => false,
					"dynamic_templates" => array(
						array(
							"template_meta" => array(
								"path_match" => "post_meta.*",
								"mapping" => array(
									"type" => "multi_field",
									"path" => "full",
									"fields" => array(
										"{name}" => array(
											"type" => "string",
											"index" => "analyzed"
										),
										"raw" => array(
											"type" => "string",
											"index" => "not_analyzed",
											'include_in_all' => false
										)
									)
								)
							)
						),
						array(
							"template_terms" => array(
								"path_match" => "terms.*",
								"mapping" => array(
									"type" => "object",
									"path" => "full",
									"properties" => array(
										"name" => array(
											"type" => "string"
										),
										"term_id" => array(
											"type" => "long"
										),
										"parent" => array(
											"type" => "long"
										),
										"slug" => array(
											"type" => "string",
											"index" => "not_analyzed"
										)
									)
								)
							)
						),
						array(
							"term_suggest" => array(
								"path_match" => "term_suggest_*",
								"mapping" => array(
									"type" => "completion",
									"analyzer" => "default",
								)
							)
						)
					),
					"_all" => array(
						"analyzer" => "simple"
					),
					'properties' => array(
						'post_id' => array(
							'type' => 'long',
							'index' => 'not_analyzed',
							'include_in_all' => false
						),
						'post_author' => array(
							'type' => 'object',
							'path' => 'full',
							'properties' => array(
								'display_name' => array(
									'type' => 'string'
								),
								'login' => array(
									'type' => 'string',
									'index' => 'not_analyzed'
								)
							)
						),
						'post_date' => array(
							'type' => 'date',
							'format' => 'YYYY-MM-dd HH:mm:ss',
							'include_in_all' => false
						),
						'post_date_gmt' => array(
							'type' => 'date',
							'format' => 'YYYY-MM-dd HH:mm:ss',
							'include_in_all' => false
						),
						'post_title' => array(
							'type' => 'string',
							'_boost'  => 3.0,
							'store'  => 'yes',
							'analyzer' => 'standard'
						),
						'post_excerpt' => array(
							'type' => 'string',
							'_boost'  => 2.0
						),
						'post_content' => array(
							'type' => 'string',
							'analyzer' => 'default'
						),
						'post_status' => array(
							'type' => 'string',
							'index' => 'no'
						),
						'post_name' => array(
							'type' => 'multi_field',
							'fields' => array(
								'post_name' => array(
									'type' => 'string'
								),
								'raw' => array(
									'type' => 'string',
									'index' => 'not_analyzed',
									'include_in_all' => false
								)
							)
						),
						'post_modified' => array(
							'type' => 'date',
							'format' => 'YYYY-MM-dd HH:mm:ss',
							'include_in_all' => false
						),
						'post_modified_gmt' => array(
							'type' => 'date',
							'format' => 'YYYY-MM-dd HH:mm:ss',
							'include_in_all' => false
						),
						'post_parent' => array(
							'type' => 'long',
							'index' => 'not_analyzed',
							'include_in_all' => false
						),
						'post_type' => array(
							'type' => 'multi_field',
							'fields' => array(
								'post_type' => array(
									'type' => 'string'
								),
								'raw' => array(
									'type' => 'string',
									'index' => 'not_analyzed',
									'include_in_all' => false
								)
							)
						),
						'post_mime_type' => array(
							'type' => 'string',
							'index' => 'not_analyzed',
							'include_in_all' => false
						),
						'permalink' => array(
							'type' => 'string'
						),
						'terms' => array(
							"type" => "object"
						),
						'post_meta' => array(
							'type' => 'object'
						)
					)
				)
			)
		);

		$mapping = apply_filters( 'ep_config_mapping', $mapping );

		$index_url = ep_get_index_url();

		$request = wp_remote_request( $index_url, array( 'body' => json_encode( $mapping ), 'method' => 'PUT' ) );

		if ( ! is_wp_error( $request ) && 200 === wp_remote_retrieve_response_code( $request ) ) {
			$response_body = wp_remote_retrieve_body( $request );

			return json_decode( $response_body );
		}

		return false;
	}

	/**
	 * Prepare a post for syncing
	 *
	 * @param int $post_id
	 * @since 0.9.1
	 * @return bool|array
	 */
	public function prepare_post( $post_id ) {
		$post = get_post( $post_id );

		$user = get_userdata( $post->post_author );

		if ( $user instanceof WP_User ) {
			$user_data = array(
				'login'        => $user->user_login,
				'display_name' => $user->display_name
			);
		} else {
			$user_data = array(
				'login'        => '',
				'display_name' => ''
			);
		}

		$post_args = array(
			'post_id'           => $post_id,
			'post_author'       => $user_data,
			'post_date'         => $post->post_date,
			'post_date_gmt'     => $post->post_date_gmt,
			'post_title'        => get_the_title( $post_id ),
			'post_excerpt'      => $post->post_excerpt,
			'post_content'      => apply_filters( 'the_content', $post->post_content ),
			'post_status'       => 'publish',
			'post_name'         => $post->post_name,
			'post_modified'     => $post->post_modified,
			'post_modified_gmt' => $post->post_modified_gmt,
			'post_parent'       => $post->post_parent,
			'post_type'         => $post->post_type,
			'post_mime_type'    => $post->post_mime_type,
			'permalink'         => get_permalink( $post_id ),
			'terms'             => $this->prepare_terms( $post ),
			'post_meta'         => $this->prepare_meta( $post ),
			//'site_id'         => get_current_blog_id(),
		);

		$post_args = apply_filters( 'ep_post_sync_args', $post_args, $post_id );

		return $post_args;
	}

	/**
	 * Prepare terms to send to ES.
	 *
	 * @param object $post
	 *
	 * @since 0.1.0
	 * @return array
	 */
	private function prepare_terms( $post ) {
		$taxonomies          = get_object_taxonomies( $post->post_type, 'objects' );
		$selected_taxonomies = array();

		foreach ( $taxonomies as $taxonomy ) {
			if ( $taxonomy->public ) {
				$selected_taxonomies[] = $taxonomy;
			}
		}

		$selected_taxonomies = apply_filters( 'ep_sync_taxonomies', $selected_taxonomies, $post );

		if ( empty( $selected_taxonomies ) ) {
			return array();
		}

		$terms = array();

		foreach ( $selected_taxonomies as $taxonomy ) {
			$object_terms = get_the_terms( $post->ID, $taxonomy->name );

			if ( ! $object_terms || is_wp_error( $object_terms ) ) {
				continue;
			}

			foreach ( $object_terms as $term ) {
				$terms[$term->taxonomy][] = array(
					'term_id' => $term->term_id,
					'slug'    => $term->slug,
					'name'    => $term->name,
					'parent'  => $term->parent
				);
			}
		}

		return $terms;
	}

	/**
	 * Prepare post meta to send to ES
	 *
	 * @param object $post
	 *
	 * @since 0.1.0
	 * @return array
	 */
	public function prepare_meta( $post ) {
		$meta = (array) get_post_meta( $post->ID );

		if ( empty( $meta ) ) {
			return array();
		}

		$prepared_meta = array();

		foreach ( $meta as $key => $value ) {
			if ( ! is_protected_meta( $key ) ) {
				$prepared_meta[$key] = maybe_unserialize( $value );
			}
		}

		return $prepared_meta;
	}

	/**
	 * Delete the current index
	 *
	 * @since 0.9.0
	 * @return array|bool
	 */
	public function delete_index( ) {
		$index_url = ep_get_index_url();

		$request = wp_remote_request( $index_url, array( 'method' => 'DELETE' ) );

		// 200 means the delete was successful
		// 404 means the index was non-existent, but we should still pass this through as we will occasionally want to delete an already deleted index
		if ( ! is_wp_error( $request ) && ( 200 === wp_remote_retrieve_response_code( $request ) || 404 === wp_remote_retrieve_response_code( $request ) ) ) {
			$response_body = wp_remote_retrieve_body( $request );

			return json_decode( $response_body );
		}

		return false;
	}

	/**
	 * Format WP query args for ES
	 *
	 * @param array $args
	 * @since 0.9.0
	 * @return array
	 */
	public function format_args( $args ) {
		$formatted_args = array(
			'from' => 0,
			'size' => get_option( 'posts_per_page' ),
			'sort' => array(
				array(
					'_score' => array(
						'order' => 'desc',
					),
				),
			),
		);

		$filter = array(
			'and' => array(),
		);

		$search_fields = array(
			'post_title',
			'post_excerpt',
			'post_content',
		);

		/**
		 * Tax Query support
		 *
		 * Support for the tax_query argument of WP_Query
		 * Currently only provides support for the 'AND' relation between taxonomies
		 *
		 * @use field = slug
		 *      terms array
		 * @since 0.9.1
		 */
		if ( ! empty( $args['tax_query'] ) ) {
			$tax_filter = array();

			foreach( $args['tax_query'] as $single_tax_query ) {
				if ( ! empty( $single_tax_query['terms'] ) && ! empty( $single_tax_query['field'] ) && 'slug' === $single_tax_query['field'] ) {
					$terms = (array) $single_tax_query['terms'];
					$tax_filter[]['terms'] = array(
						'terms.' . $single_tax_query['taxonomy'] . '.slug' => $terms,
					);
				}
			}

			if ( ! empty( $tax_filter ) ) {
				$filter['and'][]['bool']['must'] = $tax_filter;
			}
		}

		/**
		 * Allow for searching by taxonomy
		 *
		 * @since 0.9.0
		 */
		if ( ! empty( $args['search_tax'] ) ) {
			foreach ( $args['search_tax'] as $tax ) {
				$search_fields[] = 'terms.' . $tax . '.name';
			}
		}

		/**
		 * Allow for searching by meta
		 *
		 * @since 0.9.0
		 */
		if ( ! empty( $args['search_meta'] ) ) {
			foreach ( $args['search_meta'] as $key ) {
				$search_fields[] = 'post_meta.' . $key;
			}
		}

		$search_fields = apply_filters( 'ep_search_fields', $search_fields, $args );

		$query = array(
			'bool' => array(
				'must' => array(
					'fuzzy_like_this' => array(
						'fields' => $search_fields,
						'like_text' => '',
						'min_similarity' => apply_filters( 'ep_min_similarity', 0.75 )
					),
				),
			),
		);

		// @todo: Add param for only grabbing posts from specific sites

		/*if ( ! $cross_site ) {
			$formatted_args['filter']['and'][] = array(
				'term' => array(
					'site_id' => get_current_blog_id(),
				),
			);
		}*/

		if ( isset( $args['s'] ) ) {
			$query['bool']['must']['fuzzy_like_this']['like_text'] = $args['s'];
			$formatted_args['query'] = $query;
		}

		if ( isset( $args['post_type'] ) ) {
			$post_types = (array) $args['post_type'];
			$terms_map_name = 'terms';
			if ( count( $post_types ) < 2 ) {
				$terms_map_name = 'term';
			}

			$filter['and'][] = array(
				$terms_map_name => array(
					'post_type.raw' => $post_types,
				),
			);

			$formatted_args['filter'] = $filter;
		}

		if ( isset( $args['offset'] ) ) {
			$formatted_args['from'] = $args['offset'];
		}

		if ( isset( $args['posts_per_page'] ) ) {
			$formatted_args['size'] = $args['posts_per_page'];
		}

		if ( isset( $args['paged'] ) ) {
			$paged = ( $args['paged'] <= 1 ) ? 0 : $args['paged'] - 1;
			$formatted_args['from'] = $args['posts_per_page'] * $paged;
		}

		/**
		 * Aggregations
		 */
		if ( isset( $args['aggs'] ) && ! empty( $args['aggs']['aggs'] ) ) {
			$agg_obj = $args['aggs'];

			// Add a name to the aggregation if it was passed through
			if ( ! empty( $agg_obj['name'] ) ) {
				$agg_name = $agg_obj['name'];
			} else {
				$agg_name = 'aggregation_name';
			}

			// Add/use the filter if warranted
			if ( isset( $agg_obj['use-filter'] ) && false !== $agg_obj['use-filter'] && ! empty( $filter ) ) {

				// If a filter is being used, use it on the aggregation as well to receive relevant information to the query
				$formatted_args['aggs'][ $agg_name ]['filter'] = $filter;
				$formatted_args['aggs'][ $agg_name ]['aggs'] = $agg_obj['aggs'];
			} else {
				$formatted_args['aggs'][ $agg_name ] = $args['aggs'];
			}
		}

		return apply_filters( 'ep_formatted_args', $formatted_args );
	}

	/**
	 * Wrapper function for wp_get_sites - allows us to have one central place for the `ep_indexable_sites` filter
	 *
	 * @return mixed|void
	 */
	public function get_sites() {
		return apply_filters( 'ep_indexable_sites', wp_get_sites() );
	}

    /**
     * Decode the bulk index response
     *
     * @since 0.9.2
     * @param $body
     *
     * @return array|object
     */
    public function bulk_index_posts( $body ) {
        // create the url with index name and type so that we don't have to repeat it over and over in the request (thereby reducing the request size)
        $url     = trailingslashit( EP_HOST ) . trailingslashit( ep_get_index_name() ) . 'post/_bulk';
        $request = wp_remote_request( $url, array( 'method' => 'POST', 'body' => $body ) );

        return is_wp_error( $request ) ? $request : json_decode( wp_remote_retrieve_body( $request ), true );
    }
}

EP_API::factory();

/**
 * Accessor functions for methods in above class. See doc blocks above for function details.
 */

function ep_index_post( $post ) {
	return EP_API::factory()->index_post( $post );
}

function ep_search( $args, $cross_site = false ) {
	return EP_API::factory()->search( $args, $cross_site );
}

function ep_get_post( $post_id ) {
	return EP_API::factory()->get_post( $post_id );
}

function ep_delete_post( $post_id ) {
	return EP_API::factory()->delete_post( $post_id );
}

function ep_is_alive() {
	return EP_API::factory()->is_alive();
}

function ep_put_mapping() {
	return EP_API::factory()->put_mapping();
}

function ep_delete_index() {
	return EP_API::factory()->delete_index();
}

function ep_format_args( $args ) {
	return EP_API::factory()->format_args( $args );
}

function ep_create_network_alias( $indexes ) {
	return EP_API::factory()->create_network_alias( $indexes );
}

function ep_delete_network_alias() {
	return EP_API::factory()->delete_network_alias();
}

function ep_refresh_index() {
	return EP_API::factory()->refresh_index();
}

function ep_prepare_post( $post_id ) {
	return EP_API::factory()->prepare_post( $post_id );
}

function ep_get_sites() {
	return EP_API::factory()->get_sites();
}

function ep_bulk_index_posts( $body ) {
    return EP_API::factory()->bulk_index_posts( $body );
}