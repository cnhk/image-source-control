<?php

/**
 * Logic to get and store sources
 */
class ISC_Model {
	/**
	 * Instance of ISC_Model
	 *
	 * @var ISC_Model
	 */
	protected static $instance;

	/**
	 * Setup registers filters and actions.
	 */
	public function __construct() {
		// save image information in meta field after a post was saved
		add_action( 'wp_insert_post', array( $this, 'save_image_information_on_post_save' ), 10, 2 );
		// attachment field handling
		add_action( 'add_attachment', array( $this, 'attachment_added' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( $this, 'isc_fields_save' ), 10, 2 );
	}

	/**
	 * Get instance of ISC_Model
	 *
	 * @return ISC_Model
	 */
	public static function get_instance() {
		return self::$instance;
	}

	/**
	 * Save image information to a post when it is saved
	 *
	 * @since 1.1
	 * @param integer $post_id post id.
	 * @param WP_Post $post post object.
	 */
	public function save_image_information_on_post_save( $post_id, $post ) {

		// don’t run on autosave of AJAX requests
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			 || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}

		/**
		 * Don’t save meta data for non-public post types, since those shouldn’t be visible in the frontend
		 * ignore also attachment posts
		 * ignore revisions
		 */
		if ( ! isset( $post->post_type )
			|| ! in_array( $post->post_type, get_post_types( array( 'public' => true ), 'names' ), true ) // is the post type public
			|| 'attachment' === $post->post_type
			|| 'revision' === $post->post_type ) {
			return;
		}

		// check if this is a revision and if so, use parent post id
		// todo: check if this is really needed after we excluded revisions
		$id = wp_is_post_revision( $post_id );
		if ( $id ) {
			$post_id = $id;
		}

		$content = '';
		if ( ! empty( $post->post_content ) ) {
			$content = stripslashes( $post->post_content );
		} else {
			// retrieve content with Gutenberg, because no content included in $_POST object then
			// todo: check if this is really needed after we also have access to the $post object here.
			$_post = get_post( $post_id );
			if ( isset( $_post->post_content ) ) {
				$content = $_post->post_content;
			}
		}

		// Needs to be called before the 'isc_post_images' field is updated.
		$this->update_image_posts_meta( $post_id, $content );
		$this->save_image_information( $post_id, $content );
	}

	/**
	 * Update isc_image_posts meta field for all images found in a post with a given ID.
	 *
	 * @param integer $post_id ID of the target post.
	 * @param string  $content content of the target post.
	 * @updated 1.3.5 added images_in_posts_simple filter
	 */
	public function update_image_posts_meta( $post_id, $content ) {
		$content = apply_filters( 'the_content', $content );

		$image_ids      = ISC_Class::get_instance()->filter_image_ids( $content );
		$added_images   = array();
		$removed_images = array();

		// add thumbnail information
		$thumb_id = get_post_thumbnail_id( $post_id );
		if ( ! empty( $thumb_id ) ) {
			$image_ids[ $thumb_id ] = wp_get_attachment_url( $thumb_id );
		}

		// apply filter to image array, so other developers can add their own logic
		$image_ids = apply_filters( 'isc_images_in_posts_simple', $image_ids, $post_id );

		// check if image IDs refer to an attachment post type
		$valid_image_post_types = apply_filters( 'isc_valid_post_types', array( 'attachment' ) );
		foreach ( $image_ids as $_id => $_url ) {
			if ( ! in_array( get_post_type( $_id ), $valid_image_post_types, true ) ) {
				unset( $image_ids[ $_id ] );
			}
		}

		$isc_post_images = get_post_meta( $post_id, 'isc_post_images', true );
		// just needed in very rare cases, when updates comes from outside of isc and meta fields doesn’t exist yet
		if ( empty( $isc_post_images ) ) {
			$isc_post_images = array();
		}

		foreach ( $image_ids as $id => $url ) {
			if ( is_array( $isc_post_images ) && ! array_key_exists( $id, $isc_post_images ) ) {
				array_push( $added_images, $id );
			}
		}
		if ( is_array( $isc_post_images ) ) {
			foreach ( $isc_post_images as $old_id => $value ) {
				// if (!in_array($old_id, $image_ids)) {
				if ( ! array_key_exists( $old_id, $image_ids ) ) {
					array_push( $removed_images, $old_id );
				} else {
					if ( ! empty( $old_id ) ) {
						$meta = get_post_meta( $old_id, 'isc_image_posts', true );
						if ( empty( $meta ) ) {
							update_post_meta( $old_id, 'isc_image_posts', array( $post_id ) );
						} else {
							// In case the isc_image_posts is not up to date
							if ( is_array( $meta ) && ! in_array( $post_id, $meta ) ) {
								array_push( $meta, $post_id );
								$meta = array_unique( $meta );
								update_post_meta( $old_id, 'isc_image_posts', $meta );
							}
						}
					}
				}
			}
		}

		foreach ( $added_images as $id ) {
			$meta = get_post_meta( $id, 'isc_image_posts', true );
			if ( ! is_array( $meta ) || array() == $meta ) {
				update_post_meta( $id, 'isc_image_posts', array( $post_id ) );
			} else {
				array_push( $meta, $post_id );
				$meta = array_unique( $meta );
				update_post_meta( $id, 'isc_image_posts', $meta );
			}
		}

		foreach ( $removed_images as $id ) {
			$image_meta = get_post_meta( $id, 'isc_image_posts', true );
			if ( is_array( $image_meta ) ) {
				$offset = array_search( $post_id, $image_meta );
				if ( false !== $offset ) {
					array_splice( $image_meta, $offset, 1 );
					$image_meta = array_unique( $image_meta );
					update_post_meta( $id, 'isc_image_posts', $image_meta );
				}
			}
		}
	}

	/**
	 * Retrieve images added to a post or page and save all information as a post meta value for the post
	 *
	 * @since 1.1
	 * @updated 1.3.5 added isc_images_in_posts filter
	 * @todo check for more post types that maybe should not be parsed here
	 *
	 * @param integer $post_id ID of a post.
	 * @param string  $content post content.
	 */
	public function save_image_information( $post_id, $content = '' ) {
		// creates an infinite loop if not secured, see ISC_Public::list_post_attachments_with_sources()
		$content = apply_filters( 'the_content', $content );

		/*
		$_image_urls = $this->filter_src_attributes($_content);
		$_imgs = array();

		foreach ($_image_urls as $_image_url) {
			// get ID of images by url
			$img_id = $this->get_image_by_url($_image_url);
			$_imgs[$img_id] = array(
				'src' => $_image_url
			);
		}*/

		$_imgs = ISC_Class::get_instance()->filter_image_ids( $content );

		// add thumbnail information
		$thumb_id = get_post_thumbnail_id( $post_id );

		/**
		 * If an image is used both inside the post and as post thumbnail, the thumbnail entry overrides the regular image.
		 */
		if ( ! empty( $thumb_id ) ) {
			$_imgs[ $thumb_id ] = array(
				'src'       => wp_get_attachment_url( $thumb_id ),
				'thumbnail' => true,
			);
		}

		// apply filter to image array, so other developers can add their own logic
		$_imgs = apply_filters( 'isc_images_in_posts', $_imgs, $post_id );

		if ( empty( $_imgs ) ) {
			$_imgs = array();
		}
		update_post_meta( $post_id, 'isc_post_images', $_imgs );
	}

	/**
	 * Add meta values to all attachments
	 *
	 * @todo probably need to fix this when more fields are added along the way
	 * @todo use compare => 'NOT EXISTS' when WP 3.5 is up to retrieve only values where it is not set
	 * @todo this currently updates all empty fields; empty in this context is empty string, 0, false or not existing; add check if meta field already existed before
	 */
	public function add_meta_values_to_attachments() {
		// retrieve all attachments
		$args = array(
			'post_type'   => 'attachment',
			'numberposts' => -1,
			'post_status' => null,
			'post_parent' => null,
		);

		$attachments = get_posts( $args );
		if ( empty( $attachments ) || ! is_array( $attachments ) ) {
			return;
		}

		$count = 0;
		foreach ( $attachments as $_attachment ) {
			$set = false;
			setup_postdata( $_attachment );
			foreach ( $this->fields as $_field ) {
				$meta = get_post_meta( $_attachment->ID, $_field['id'], true );
				if ( empty( $meta ) ) {
					update_post_meta( $_attachment->ID, $_field['id'], $_field['default'] );
					$set = true;
				}
			}
			if ( $set ) {
				$count++;
			}
		}
	}

	/**
	 * Update attachment meta field
	 *
	 * @param integer $att_id attachment post ID.
	 */
	public function attachment_added( $att_id ) {
		foreach ( $this->fields as $field ) {
			update_post_meta( $att_id, $field['id'], $field['default'] );
		}
	}

	/**
	 * Save image source to post_meta
	 *
	 * @updated 1.5 added field for url
	 *
	 * @param array $post post data.
	 * @param array $attachment attachment data.
	 * @return array $post updated post data
	 */
	public function isc_fields_save( $post, $attachment ) {
		if ( isset( $attachment['isc_image_source'] ) ) {
			update_post_meta( $post['ID'], 'isc_image_source', trim( $attachment['isc_image_source'] ) );
		}
		if ( isset( $attachment['isc_image_source_url'] ) ) {
			$url = esc_url_raw( $attachment['isc_image_source_url'] );
			update_post_meta( $post['ID'], 'isc_image_source_url', $url );
		}
		$own = ( isset( $attachment['isc_image_source_own'] ) ) ? $attachment['isc_image_source_own'] : '';
		update_post_meta( $post['ID'], 'isc_image_source_own', $own );
		if ( isset( $attachment['isc_image_licence'] ) ) {
			update_post_meta( $post['ID'], 'isc_image_licence', $attachment['isc_image_licence'] );
		}

		// remove transient that shows the warning, if true, to re-check image source warning with next call to admin_notices()
		$options = ISC_Class::get_instance()->get_isc_options();
		if ( isset( $options['warning_onesource_missing'] )
			 && $options['warning_onesource_missing']
			 && get_transient( 'isc-show-missing-sources-warning' ) ) {

			delete_transient( 'isc-show-missing-sources-warning' );
		};

		return $post;
	}

}