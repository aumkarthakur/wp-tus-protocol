<?php

class WP_Tus_Endpoint {

	public static $base_api_url = 'wp-tus/v1';
	public static $rest_route;
	public static $rest_endpoint;
	public static $rest_endpoint_url;

	private static $file_id;

	public function __construct() {

		self::$rest_route    = '/upload';
		self::$rest_endpoint = self::$base_api_url . self::$rest_route;

		add_filter( 'rest_api_init', array( $this, 'register_routes' ) );

	}

	/**
	 * register_routes
	 *
	 * @CALLED BY ACTION 'rest_api_init'
	 *
	 * Register rest api routes
	 *
	 * @access public
	 */
	public function register_routes() {

		register_rest_route(
			self::$base_api_url,
			self::$rest_route . '/(?P<fileID>[^/]*)/',
			array(
				'callback'            => array( $this, 'prepare_result' ),
				'methods'             => 'GET',
			)
		);

		register_rest_route(
			self::$base_api_url,
			self::$rest_route . '/(?P<fileID>[^/]*)/',
			array(
				'callback'            => array( $this, 'prepare_result' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'methods'             => 'HEAD, PATCH, DELETE',
			)
		);

		register_rest_route(
			self::$base_api_url,
			self::$rest_route,
			array(
				'callback'            => array( $this, 'prepare_result' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'methods'             => 'POST, OPTIONS',
			)
		);

		//Setup headers
		if( self::is_tus_upload_request() ) {
			remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
			add_filter( 'rest_pre_serve_request', array($this, 'setup_request_headers'));
		}

	}

	/**
	* is_tus_upload_request
	*
	* Helper to detect if current request is to the TUS upload endpoint
	*
	* @return bool
	* @access public static
	* @author Ben Moody
	*/
	public static function is_tus_upload_request() {

		$tus_request_endpoint = '/wp-json/' . self::$base_api_url . self::$rest_route;

		if( isset($_SERVER['REQUEST_URI']) ) {

			if( strpos($_SERVER['REQUEST_URI'], $tus_request_endpoint) !== false ) {
				return true;
			}

		}

		return false;
	}

	/**
	* setup_request_headers
	*
	* @CALLED BY FILTER 'rest_pre_serve_request'
	*
	* Filter the rest pre serve request headers and set CORS headers required for TUS access
	*
	* @access public
	* @author Ben Moody
	*/
	public function setup_request_headers() {

		$origin = get_http_origin();

		header( 'Access-Control-Allow-Origin: ' . esc_url_raw($origin) );
		header( 'Access-Control-Allow-Methods: OPTIONS, POST, HEAD, PATCH, DELETE, GET' );
		header( 'Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Content-Length, Upload-Key, Upload-Checksum, Upload-Length, Upload-Offset, Tus-Version, Tus-Resumable, Upload-Metadata' );
		header( 'Access-Control-Allow-Credentials: true' );
		header( 'Access-Control-Max-Age: 86400' );

	}

	/**
	 * permission_check
	 *
	 * @CALLED BY register_rest_route->permission_callback
	 *
	 * Permission callback for rest endpoint
	 *
	 * @return mixed bool/wp_error - true to pass. false/wp_error to fail
	 * @access public
	 * @author Ben Moody
	 */
	public function permission_check() {

		//vars
		$result = new WP_Error(
			'WP_Tus_Endpoint::permission_check',
			'Use the wp-tus_upload_permissions filter to perform a permissions check on upload requests',
			array( 'status' => 403 )
		);

		/**
		 * wp-tus_upload_permissions
		 *
		 * @since 1.0.0
		 *
		 * @see WP_Tus_Endpoint::permission_check()
		 *
		 * return true/false/wp_error
		 */
		$result = apply_filters( 'wp-tus_upload_permissions', $result );

		return $result;
	}

	/**
	 * prepare_result
	 *
	 * @CALLED BY register_rest_route()
	 *
	 * Prepare the rest api response for the gallery endpoint
	 *
	 * @param object $data
	 *
	 * @access public
	 * @author Ben Moody
	 */
	public function prepare_result( $request ) {

		//vars
		$uploads_dir_info  = wp_get_upload_dir();

		self::$file_id = $request->get_param('fileID');

		$request_method = $request->get_method();

		//Detect GET request as this should try to return the uploaded file
		if( 'GET' === $request_method ) {

			return $this->file_get_request_action();

		}

		/**
		 * wp-tus_inital-upload-dir
		 *
		 * Alter where uploads end up on server, defaults to uploads basedir
		 *
		 * @since 1.0.0
		 *
		 * @see WP_Tus_Endpoint::prepare_result()
		 *
		 * @param string basedir
		 * @param array $uploads_dir_info
		 * @param object REST Request object
		 */
		$tus_uploads_dir = apply_filters( 'wp-tus_inital-upload-dir',
			$uploads_dir_info['basedir'],
			$uploads_dir_info,
			$request
		);

		/**
		 * wp-tus_max_upload_size_in_mb
		 *
		 * Set server max file upload size in MB, defaults to 100MB
		 *
		 * @since 1.0.0
		 *
		 * @see WP_Tus_Endpoint::prepare_result()
		 *
		 * @param int file_size in MB
		 */
		$max_upload_size = apply_filters( 'wp-tus_max_upload_size_in_mb', 100 );

		//convert MB to bytes
		$max_upload_size = $max_upload_size * 1e+6;

		$server = new \TusPhp\Tus\Server(); // Pass `redis` as first argument if you are using redis.

		$server->setMaxUploadSize( $max_upload_size );

		//Listener actions - https://github.com/ankitpokhrel/tus-php#events
		$server->event()
		       ->addListener( 'tus-server.upload.created', array(
			       $this,
			       'listener_upload_created',
		       ) ); //after the upload is created during POST request.

		$server->event()
		       ->addListener( 'tus-server.upload.progress', array(
			       $this,
			       'listener_upload_progress',
		       ) ); //after a chunk is uploaded during PATCH request.

		$server->event()
		       ->addListener( 'tus-server.upload.complete', array(
			       $this,
			       'listener_upload_complete',
		       ) ); //after the upload is complete and checksum verification is done.

		$server->event()->addListener( 'tus-server.upload.merged', array(
			$this,
			'listener_upload_merged',
		) ); //after all partial uploads are merged during concatenation request.

		$server
			->setApiPath( '/wp-json/' . self::$rest_endpoint )// tus server endpoint.
			->setUploadDir( $tus_uploads_dir );

		$response = $server->serve();

		$response->send();

		exit();
	}

	/**
	* file_get_request_action
	*
	* @CALLED BY $this->prepare_result()
	*
	* Handle output for any GET requests, Normally this would be a good time to redirect the request to the final URL of the uploaded file,
	 * The method will try to do this by default, assuming you are using the default behaviour of the plugin.
	 * You can override the default behaviour with the following hooks:
	 *
	 * Action: 'wp-tus_file_get_request_action' - intercept the defaul redirect behaviour with your own, be dure to exit() after
	 * Filter: 'wp-tus_uploaded_file_url_by_tus_id' - get the file URL based on your own custom storage setup using the tus_file_id, see self::get_uploaded_file_url_by_tus_id()
	*
	* @access public
	* @author Ben Moody
	*/
	private function file_get_request_action() {

		/**
		 * wp-tus_file_get_request_action
		 *
		 * Hook in and perform your own action for file GET requests
		 *
		 * @since 1.0.0
		 *
		 * @see WP_Tus_Endpoint::file_get_request_action()
		 *
		 * @param $file_upload_id - Unique ID set by TUS server when file was uploaded, store this in file meta so that you can query file via this TUS ID
		 * @param object $event
		 */
		do_action( 'wp-tus_file_get_request_action', self::$file_id );

		$final_file_url = self::get_uploaded_file_url_by_tus_id( self::$file_id );

		if( is_wp_error($final_file_url) ) {
			return rest_ensure_response( $final_file_url );
		}

		//Default action to redirect to uploaded file
		wp_redirect( esc_url_raw($final_file_url) );
		exit();

	}

	/**
	* get_uploaded_file_url_by_tus_id
	*
	* Helper to get an uploaded file from it's final destination via it's tus file id created when it was first uploaded
	*
	* @param string $tus_file_id
	* @return mixed wp_error/string
	* @access public static
	* @author Ben Moody
	*/
	public static function get_uploaded_file_url_by_tus_id( $tus_file_id = '' ) {

		//vars
		$file_url = '';

		/**
		 * wp-tus_uploaded_file_url_by_tus_id
		 *
		 * Return the final destination URL for uploaded file, query it via it's tus_file_id you stored
		 * during the 'wp-tus_upload-complete' action hook when the file was first saved to the server during the TUS request
		 *
		 * @since 1.0.0
		 *
		 * @see WP_Tus_Endpoint::prepare_result()
		 *
		 * @param string basedir
		 * @param array $uploads_dir_info
		 */
		$file_url = apply_filters( 'wp-tus_uploaded_file_url_by_tus_id', $file_url, $tus_file_id);

		if( empty($file_url) ) {

			//Try default behaviour and see if we can get the file from the WP media library via the post_name
			$file_url = self::get_file_from_wp_library( $tus_file_id );

		}

		if( empty($file_url) ) {

			return new WP_Error(
				'File Not Found',
				'Could not find requested File',
				array('status' => 404)
			);

		}

		return $file_url;
	}

	/**
	* get_file_from_wp_library
	*
	* Try to get a file from the WP media library using it's tus file id based on the default plugin behaviour of
	* saving the tus id as the file post_name when adding the file to the WP media library during TUS upload
	*
	* @param string $tus_file_id
	* @return string $file_url
	* @access private static
	* @author Ben Moody
	*/
	private static function get_file_from_wp_library( $tus_file_id ) {

		//vars
		$file_url = '';
		$args = array(
		    'post_type' => 'attachment',
		    'posts_per_page' => 1,
			'name' => $tus_file_id,
			'post_status' => 'inherit',

			// Normal query goes here //
			'no_found_rows' => true, // counts posts, remove if pagination required
			'update_post_term_cache' => false, // grabs terms, remove if terms required (category, tag...)
			'update_post_meta_cache' => false, // grabs post meta, remove if post meta required
		);
		$results = new WP_Query( $args );

		if( $results->have_posts() ) {

			$file_url = wp_get_attachment_image_url( $results->post->ID, 'full' );

		}

		return $file_url;
	}

	/**
	 * listener_upload_created
	 *
	 * @CALLED BY $this->prepare_result()
	 *
	 * TUS PHP event listener for 'tus-server.upload.created' event
	 *
	 * https://github.com/ankitpokhrel/tus-php#events
	 *
	 * @param $file_upload_id - Unique ID set by TUS server when file was uploaded, store this in file meta so that you can query file via this TUS ID
	 * @param object $event
	 *
	 * @access public
	 * @author Ben Moody
	 */
	public function listener_upload_created( \TusPhp\Events\TusEvent $event ) {

		$file_upload_id = self::$file_id; //ID set by TUS when file was uploaded

		/**
		 * wp-tus_upload-created
		 *
		 * after the upload is created during POST request.
		 *
		 * @since 1.0.0
		 *
		 * @see WP_Tus_Endpoint::listener_upload_created()
		 *
		 * @param $file_upload_id - Unique ID set by TUS server when file was uploaded, store this in file meta so that you can query file via this TUS ID
		 * @param object $event
		 */
		do_action( 'wp-tus_upload-created', $file_upload_id, $event );

	}

	/**
	 * listener_upload_progress
	 *
	 * @CALLED BY $this->prepare_result()
	 *
	 * TUS PHP event listener for 'tus-server.upload.progress' event
	 *
	 * https://github.com/ankitpokhrel/tus-php#events
	 *
	 * @param object $event
	 *
	 * @access public
	 * @author Ben Moody
	 */
	public function listener_upload_progress( \TusPhp\Events\TusEvent $event ) {

		$file_upload_id = self::$file_id; //ID set by TUS when file was uploaded

		/**
		 * wp-tus_upload-progress
		 *
		 * after a chunk is uploaded during PATCH request.
		 *
		 * @since 1.0.0
		 *
		 * @see WP_Tus_Endpoint::listener_upload_progress()
		 *
		 * @param $file_upload_id - Unique ID set by TUS server when file was uploaded, store this in file meta so that you can query file via this TUS ID
		 * @param object $event
		 */
		do_action( 'wp-tus_upload-progress', $file_upload_id, $event );

	}

	/**
	 * listener_upload_complete
	 *
	 * @CALLED BY $this->prepare_result()
	 *
	 * TUS PHP event listener for 'tus-server.upload.complete' event
	 *
	 * https://github.com/ankitpokhrel/tus-php#events
	 *
	 * @param object $event
	 *
	 * @access public
	 * @author Ben Moody
	 */
	public function listener_upload_complete( \TusPhp\Events\TusEvent $event ) {

		$file_meta = $event->getFile()->details();
		$request   = $event->getRequest();
		$response  = $event->getResponse();
		$file_upload_id = self::$file_id; //ID set by TUS when file was uploaded

		/**
		 * wp-tus_upload-complete
		 *
		 * after the upload is complete and checksum verification is done.
		 *
		 * @since 1.0.0
		 *
		 * @see WP_Tus_Endpoint::listener_upload_complete()
		 *
		 * @param $default_action_override - return as true to override with your new function
		 * @param $file_upload_id - Unique ID set by TUS server when file was uploaded,
		 * store this in file meta so that you can query file via this TUS ID
		 * Maybe set it as post_name when using wp_insert_attachment() ?
		 * @param $file_meta
		 * @param $request
		 * @param $response
		 * @param $event
		 */
		$default_action_override = apply_filters( 'wp-tus_upload-complete', false, $file_upload_id, $file_meta, $request, $response, $event );

		if( false !== $default_action_override ) {
			//Overriden default action
			return;
		}

		//Default action to move file into WP media library
		$this->move_tus_uploaded_file_into_wp_library( $file_upload_id, $file_meta );
	}

	/**
	 * listener_upload_merged
	 *
	 * @CALLED BY $this->prepare_result()
	 *
	 * TUS PHP event listener for 'tus-server.upload.merged' event
	 *
	 * https://github.com/ankitpokhrel/tus-php#events
	 *
	 * @param object $event
	 *
	 * @access public
	 * @author Ben Moody
	 */
	public function listener_upload_merged( \TusPhp\Events\TusEvent $event ) {

		$file_upload_id = self::$file_id; //ID set by TUS when file was uploaded

		/**
		 * wp-tus_upload-merged
		 *
		 * after all partial uploads are merged during concatenation request.
		 *
		 * @since 1.0.0
		 *
		 * @see WP_Tus_Endpoint::listener_upload_merged()
		 * @param $file_upload_id - Unique ID set by TUS server when file was uploaded, store this in file meta so that you can query file via this TUS ID
		 * @param object $event
		 */
		do_action( 'wp-tus_upload-merged', $file_upload_id, $event );

	}

	/**
	* move_tus_uploaded_file_into_wp_library
	*
	* Moves a new TUS upload file into the WP media library
	*
	* @param string $file_upload_id
	* @param array $file_meta
	* @access public
	* @author Ben Moody
	*/
	public function move_tus_uploaded_file_into_wp_library( $file_upload_id, $file_meta ) {

		if( !isset($file_meta['file_path'],$file_meta['name']) ) {

			return new WP_Error(
				'move_tus_uploaded_file_into_wp_library',
				'missing require file meta',
				$file_meta
			);

		}

		$wp_filetype = wp_check_filetype( $file_meta['file_path'], null );

		$attachment = array(
			'post_mime_type' => $wp_filetype['type'],
			'post_title' => preg_replace('/\.[^.]+$/', '', $file_meta['name']),
			'post_content' => '',
			'post_status' => 'inherit',
			'post_name' => $file_upload_id,
		);

		$attachment_id = wp_insert_attachment( $attachment, $file_meta['file_path'] );

		if (!is_wp_error($attachment_id)) {

			require_once(ABSPATH . "wp-admin" . '/includes/image.php');

			$attachment_data = wp_generate_attachment_metadata( $attachment_id, $file_meta['file_path'] );

			wp_update_attachment_metadata( $attachment_id,  $attachment_data );

		}

	}

}

new WP_Tus_Endpoint();

/**
* wp_tus_get_upload_rest_endpoint
*
* Helper function to get the wp tus upload rest endpoint
*
* @return string
* @access public
* @author Ben Moody
*/
function wp_tus_get_upload_rest_endpoint() {

	return WP_Tus_Endpoint::$rest_endpoint;

}

/**
 * wp_tus_get_upload_rest_endpoint_url
 *
 * Helper function to get the wp tus upload rest endpoint URL
 *
 * @return string
 * @access public
 * @author Ben Moody
 */
function wp_tus_get_upload_rest_endpoint_url() {

	return rest_url( WP_Tus_Endpoint::$rest_endpoint );

}