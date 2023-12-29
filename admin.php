<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Associate Attachment Admin.
 *
 * @package associate-attachment
 */

/**
 * Associate Attachment Admin class.
 */
class Associate_Attachment_Admin {
	/**
	 * Tools menu hook suffix.
	 *
	 * @var string
	 */
	private $tools_menu_id;

	/**
	 * Construction.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'setup' ) );
	}

	/**
	 * Set up processing in the administration panel.
	 *
	 * @since 1.0.0
	 */
	public function setup() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'wp_ajax_associate_attachment', array( $this, 'ajax_associate_attachment' ) );
		add_filter( 'plugin_action_links', array( $this, 'plugin_action_links' ), 10, 2 );
	}

	/**
	 * Add a menu to the administration panel.
	 *
	 * @since 1.0.0
	 */
	public function admin_menu() {
		$this->tools_menu_id = add_management_page(
			__( 'Associate Image Tool', 'associate-attachment' ),
			_x( 'Associate Image', 'setting', 'associate-attachment' ),
			'manage_options',
			'associate-attachment',
			array( $this, 'tools_page' )
		);
	}

	/**
	 * Enqueue styles and scripts in the administration panel.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Hook suffix.
	 */
	public function admin_enqueue_scripts( $hook_suffix ) {
		if ( $hook_suffix === $this->tools_menu_id ) {
			$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
			wp_enqueue_style( 'associate-attachment', plugins_url( '/admin-tools.css', __FILE__ ), false, ASSOCIATE_ATTACHMENT_VERSION );
			wp_enqueue_script( 'jquery-ui-progressbar' );
			wp_enqueue_script( 'associate-attachment', plugins_url( "/admin-tools{$min}.js", __FILE__ ), array( 'jquery-ui-progressbar' ), ASSOCIATE_ATTACHMENT_VERSION, false );
		}
	}

	/**
	 * Associates an attachment file with a post.
	 *
	 * @since 1.0.0
	 *
	 * @global wpdb $wpdb
	 * @param int $attachment_id Attachment ID.
	 * @param int $post_id Post ID.
	 * @return int|bool The number of rows affected on success. FALSE on failure.
	 */
	public function update_attachment_post_parent( $attachment_id, $post_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->update(
			$wpdb->posts,
			array( 'post_parent' => $post_id ),
			array(
				'post_type' => 'attachment',
				'ID'        => $attachment_id,
			),
			array( '%d' ),
			array( '%s', '%d' )
		);
	}

	/**
	 * Associate attachment image in AJAX.
	 *
	 * @since 1.0.0
	 */
	public function ajax_associate_attachment() {
		check_ajax_referer( 'associate-attachment-tool', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			die( -1 );
		}

		if ( ! isset( $_REQUEST['ids'] ) ) {
			die( -1 );
		}

		$enable_shortcode = ( isset( $_REQUEST['enable_shortcode'] ) && 'true' === $_REQUEST['enable_shortcode'] );
		$enable_acf       = ( isset( $_REQUEST['enable_acf'] ) && 'true' === $_REQUEST['enable_acf'] );
		$enable_scf       = ( isset( $_REQUEST['enable_scf'] ) && 'true' === $_REQUEST['enable_scf'] );

		set_time_limit( 600 );
		header( 'Content-type: application/json; charset=utf-8' );

		$post_ids = array_map( 'absint', (array) $_REQUEST['ids'] );

		$counter       = 0;
		$error_counter = 0;
		$messages      = array();

		try {
			foreach ( $post_ids as $post_id ) {
				$is_error = false;
				++$counter;

				$post = get_post( $post_id );
				if ( $post ) {
					$title   = ( mb_strlen( $post->post_title ) > 34 ) ? mb_substr( $post->post_title, 0, 32 ) . '&hellip;' : $post->post_title;
					$content = $post->post_content;

					// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
					// $content = function_exists( 'do_blocks' ) ? do_blocks( $content ) : $content;

					if ( $enable_shortcode ) {
						$content = do_shortcode( $content );
					}

					$matches = array();
					preg_match_all( '/<img .*?src\s*=\s*[\"|\'](.*?)[\"|\'].*?>/i', $content, $matches );
					foreach ( $matches[0] as $key => $img ) {

						$attachment_id = 0;

						// Get the ID from the wp-image-{$id} class.
						$class_matches = array();
						if ( preg_match( '/class\s*=\s*[\"|\'].*?wp-image-([0-9]*).*?[\"|\']/i', $img, $class_matches ) ) {
							$attachment_id = $class_matches[1];
						}

						// Get ID from URL.
						if ( ! $attachment_id ) {
							$url           = $matches[1][ $key ];
							$attachment_id = $this->attachment_url_to_postid( $url );
						}

						if ( $attachment_id ) {
							if ( false === $this->update_attachment_post_parent( $attachment_id, $post_id ) ) {
								$is_error = true;
							}
						}
					}

					// Gallery shortcode.
					if ( ! $enable_shortcode ) {
						$matches = array();
						if ( preg_match_all( '/\[gallery .*?ids\s*=\s*[\"|\']\s*([0-9]+([\s,]*[0-9]+)*).*?[\"|\'].*?\]/i', $content, $matches ) ) {
							foreach ( $matches[1] as $value ) {
								$ids = explode( ',', $value );
								foreach ( $ids as $id ) {
									if ( false === $this->update_attachment_post_parent( (int) $id, $post_id ) ) {
										$is_error = true;
										break;
									}
								}
							}
						}
					}

					// Featured Image.
					$thumbnail_id = get_post_thumbnail_id( $post_id );
					if ( $thumbnail_id ) {
						if ( false === $this->update_attachment_post_parent( $thumbnail_id, $post_id ) ) {
							$is_error = true;
						}
					}

					// For WooCommerce plugin.
					if ( 'product' === $post->post_type ) {
						if ( function_exists( 'wc_get_product' ) ) {
							$product = wc_get_product( $post_id );
							if ( method_exists( $product, 'get_gallery_image_ids' ) ) {
								$ids = $product->get_gallery_image_ids();
								if ( $ids ) {
									foreach ( $ids as $id ) {
										if ( false === $this->update_attachment_post_parent( $id, $post_id ) ) {
											$is_error = true;
										}
									}
								}
							}
						}
					}

					// For Advanced Custom Fields plugin.
					if ( $enable_acf ) {
						if ( ! $this->associate_attachment_for_acf( $post ) ) {
							$is_error = true;
						}
					}

					// For Smart Custom Fields plugin.
					if ( $enable_scf ) {
						if ( ! $this->associate_attachment_for_scf( $post ) ) {
							$is_error = true;
						}
					}

					if ( $is_error ) {
						/* translators: 1: Post title, 2: Post id. */
						$messages[] = esc_html( sprintf( __( '"%1$s" (ID %2$d) failed.', 'associate-attachment' ), $title, $post_id ) );
						++$error_counter;
					}
				}
			}
		} catch ( Exception $e ) {
			$messages[] = $e->getMessage();
		}

		die(
			wp_json_encode(
				array(
					'count'       => $counter,
					'error_count' => $error_counter,
					'messages'    => $messages,
				)
			)
		);
	}

	/**
	 * Associate attachment image for ACF.
	 *
	 * @since 1.5.0
	 *
	 * @param WP_Post $post Post.
	 */
	private function associate_attachment_for_acf( $post ) {
		if ( ! function_exists( 'get_field_objects' ) ) {
			return true;
		}

		$fields = get_field_objects( $post->ID );
		if ( $fields ) {
			$images = array();
			foreach ( $fields as $field ) {
				if ( ! isset( $field['type'] ) ) {
					break;
				}
				switch ( $field['type'] ) {
					case 'repeater':
						if ( isset( $field['sub_fields'] ) ) {
							foreach ( $field['sub_fields'] as $sub_field ) {
								if ( 'image' === $sub_field['type'] ) {
									$name = $sub_field['name'];
									foreach ( $field['value'] as $value ) {
										if ( isset( $value[ $name ] ) ) {
											if ( is_int( $value[ $name ] ) || is_string( $value[ $name ] ) ) {
												$images[] = $value[ $name ];
											} elseif ( is_array( $value[ $name ] ) ) {
												$images[] = $value[ $name ]['ID'];
											}
										}
									}
								}
							}
						}
						break;
					case 'image':
						if ( isset( $field['value'] ) ) {
							if ( is_int( $field['value'] ) || is_string( $field['value'] ) ) {
								$images[] = $field['value'];
							} elseif ( is_array( $field['value'] ) ) {
								$images[] = $field['value']['ID'];
							}
						}
						break;
				}
			}

			$images = array_unique( $images );

			foreach ( $images as $image ) {
				$id = null;
				if ( is_int( $image ) ) {
					$id = $image;
				} elseif ( is_string( $image ) ) {
					$id = attachment_url_to_postid( $image );
				}
				if ( $id ) {
					if ( false === $this->update_attachment_post_parent( $id, $post->ID ) ) {
						return false;
					}
				}
			}
		}

		return true;
	}

	/**
	 * Associate attachment image for SCF.
	 *
	 * @since 1.6.0
	 *
	 * @param WP_Post $post Post.
	 */
	private function associate_attachment_for_scf( $post ) {
		if ( ! class_exists( 'SCF' ) ) {
			return true;
		}

		$settings = SCF::get_settings( $post );
		if ( empty( $settings ) ) {
			return true;
		}

		$field_types = array();
		foreach ( $settings as $setting ) {
			$groups = $setting->get_groups();
			foreach ( $groups as $group ) {
				$is_repeatable = $group->is_repeatable();
				$group_name    = $group->get_name();
				if ( $is_repeatable && $group_name ) {
					$fields     = $group->get_fields();
					$sub_fields = array();
					foreach ( $fields as $key => $field ) {
						$sub_fields[ $field->get( 'name' ) ] = $field->get_attribute( 'type' );
					}
					$field_types[ $group_name ] = $sub_fields;
				} else {
					$fields = $group->get_fields();
					foreach ( $fields as $field ) {
						$field_types[ $field->get( 'name' ) ] = $field->get_attribute( 'type' );
					}
				}
			}
		}

		$fields = SCF::gets( $post->ID );
		if ( empty( $fields ) ) {
			return true;
		}

		$ids = array();
		foreach ( $fields as $name => $value ) {
			if ( isset( $field_types[ $name ] ) ) {
				if ( is_array( $field_types[ $name ] ) ) {
					foreach ( $value as $sub_fields ) {
						foreach ( $sub_fields as $sub_name => $sub_value ) {
							if ( 'image' === $field_types[ $name ][ $sub_name ] && ! empty( $sub_value ) ) {
								$ids[] = (int) $sub_value;
							}
						}
					}
				} elseif ( 'image' === $field_types[ $name ] && ! empty( $value ) ) {
					$ids[] = (int) $value;
				}
			}
		}

		if ( $ids ) {
			foreach ( $ids as $id ) {
				if ( false === $this->update_attachment_post_parent( $id, $post->ID ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Output Associate Attachment Tools page.
	 *
	 * @since 1.0.0
	 */
	public function tools_page() {
		global $wpdb;

		echo '<div id="message" class="fade notice" style="display:none;"></div>';
		echo '<div class="wrap associate-attachment">';
		echo '<h1>' . esc_html__( 'Associate Image Tool', 'associate-attachment' ) . '</h1>';

		if ( isset( $_POST['associate-attachment-detach-button'] ) ) {
			check_admin_referer( 'associate-attachment' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->query( "UPDATE $wpdb->posts SET post_parent = 0 WHERE post_type = 'attachment' AND post_parent <> 0;" );
			if ( false !== $result ) {
				echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'Detached.', 'associate-attachment' ) . '</strong></p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html__( 'Failed to detach.', 'associate-attachment' ) . '</strong></p></div>';
			}
		}

		if ( isset( $_POST['associate-attachment-attach-button'] ) && isset( $_REQUEST['associate-attachment-post-type'] ) ) {
			check_admin_referer( 'associate-attachment' );

			$post_type        = sanitize_text_field( wp_unslash( $_REQUEST['associate-attachment-post-type'] ) );
			$enabel_shortcode = isset( $_REQUEST['associate-attachment-attach-shortcode'] );
			$enabel_acf       = isset( $_REQUEST['associate-attachment-attach-acf'] );
			$enabel_scf       = isset( $_REQUEST['associate-attachment-attach-scf'] );
			$post_type_object = get_post_type_object( $post_type );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND (
					post_status = 'publish'
					OR post_status = 'private'
					OR post_status = 'future'
					OR post_status = 'draft'
					OR post_status = 'pending'
					) ORDER BY ID DESC",
					$post_type
				)
			);

			if ( ! $posts || count( $posts ) === 0 ) {
				/* translators: %s: Post type label. */
				echo '<p>' . esc_html( sprintf( __( '%s was not found.', 'associate-attachment' ), $post_type_object->label ) ) . '</p>';
				echo '<div id="associate-attachment-back-link">'
					. '<p><a href="' . esc_url( admin_url( 'tools.php?page=associate-attachment' ) ) . '">' . esc_html__( '&laquo; Back to Tools page', 'associate-attachment' ) . '</a></p>'
					. '</div>';
				echo '</div>'; // .wrap
				return;
			}

			$post_ids = array();
			foreach ( $posts as $post ) {
				$post_ids[] = $post->ID;
			}

			$post_count = count( $post_ids );

			/**
			 * Filters Count per step.
			 *
			 * @since 1.3.0
			 *
			 * @param int $count_per_step Count per step.
			 */
			$count_per_step = (int) apply_filters( 'associate_attachment_count_per_step', 100 );

			$associate_attachment_values = array(
				'nonce'               => wp_create_nonce( 'associate-attachment-tool' ),
				'post_ids'            => $post_ids,
				'post_count'          => $post_count,
				'count_per_step'      => $count_per_step,
				'stop_button_message' => __( 'Abort...', 'associate-attachment' ),
				'success_message'     => __( 'Completed. There is no failure.', 'associate-attachment' ),
				/* translators: %s: Failure count. */
				'failure_message'     => __( 'Completed. %s failed.', 'associate-attachment' ),
				'error_message'       => __( 'Aborted due to an error that cannot continue.', 'associate-attachment' ),
				'about_message'       => __( 'Aborted.', 'associate-attachment' ),
				'enable_acf'          => $enabel_acf,
				'enable_scf'          => $enabel_scf,
				'enable_shortcode'    => $enabel_shortcode,
			);

			echo '<div id="associate-attachment-back-link" style="display: none;">'
				. '<p><a href="' . esc_url( admin_url( 'tools.php?page=associate-attachment' ) ) . '">' . esc_html__( '&laquo; Back to Tools page', 'associate-attachment' ) . '</a></p>'
				. '</div>';

			echo '<p><span id="associate-attachment-message">' . esc_html__( 'It may take some time. Please do not move from this page until it is completed.', 'associate-attachment' ) . '</span></p>';
			echo '<div id="associate-attachment-bar" style="position:relative; height:25px;">';
			echo '<div id="associate-attachment-bar-percent" style="position:absolute; left:50%;top:50%; width:300px; margin-left:-150px; height:25px; margin-top:-9px; font-weight:bold; text-align:center;"></div>';
			echo '</div>';
			echo '<p><input type="button" class="button hide-if-no-js" name="associate-attachment-stop-bottun" id="associate-attachment-stop-bottun" value="' . esc_html__( 'Stop', 'associate-attachment' ) . '" /></p>';
			echo '<h3 class="title">' . esc_html__( 'Status', 'associate-attachment' ) . '</h3>';
			/* translators: %s: Post type. */
			echo '<p>' . esc_html( sprintf( __( 'Post Type: %s', 'associate-attachment' ), $post_type_object->label ) ) . '</p>';
			/* translators: %s: Total count. */
			echo '<p>' . esc_html( sprintf( __( 'Total: %s', 'associate-attachment' ), $post_count ) ) . '</p>';
			/* translators: %s: Success count. */
			echo '<p>' . sprintf( esc_html__( 'Success: %s', 'associate-attachment' ), '<span id="associate-attachment-success-count">0</span>' ) . '</p>';
			/* translators: %s: Failure count. */
			echo '<p>' . sprintf( esc_html__( 'Failure: %s', 'associate-attachment' ), '<span id="associate-attachment-error-count">0</span>' ) . '</p>';
			echo '<ol id="associate-attachment-msg"></ol>';

			echo '<script type="text/javascript">';
			echo 'new AssociateAttachmentTool(' . wp_json_encode( $associate_attachment_values, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ');';
			echo '</script>' . "\n";
		} else {
			echo '<form method="post" action="">';

			wp_nonce_field( 'associate-attachment' );

			echo '<h2>' . esc_html__( 'Bulk attach', 'associate-attachment' ) . '</h2>';

			echo '<p>' . esc_html__( 'Post Type: ', 'associate-attachment' );

			$post_types = get_post_types( array(), 'objects' );
			echo '<select id="associate-attachment-post-type" name="associate-attachment-post-type">';
			foreach ( $post_types as $post_type ) {
				if (
					( $post_type->public || 'wp_block' === $post_type->name )
					&& ( post_type_supports( $post_type->name, 'editor' ) || post_type_supports( $post_type->name, 'thumbnail' ) )
				) {
					echo '<option value="' . esc_attr( $post_type->name ) . '">' . esc_html( $post_type->label ) . '</option >';
				}
			}
			echo '</select>';
			echo '</p>';

			$enabel_shortcode = false;
			$enabel_acf       = false;
			$activate_acf     = function_exists( 'get_field_objects' );
			$enabel_scf       = false;
			$activate_scf     = class_exists( 'SCF' );

			echo '<p><label><input id="associate-attachment-attach-shortcode" name="associate-attachment-attach-shortcode" type="checkbox" value="1"'
				. checked( $enabel_shortcode, 1, false ) . '>'
				. esc_html__( 'Find shortcode images', 'associate-attachment' ) . '</label></p>';

			echo '<p><label><input id="associate-attachment-attach-acf" name="associate-attachment-attach-acf" type="checkbox" value="1"'
				. checked( $enabel_acf, 1, false ) . disabled( $activate_acf, false, false ) . '>'
				. esc_html__( 'Find image fields for Advanced Custom Fields plugin', 'associate-attachment' ) . '</label></p>';

			echo '<p><label><input id="associate-attachment-attach-scf" name="associate-attachment-attach-scf" type="checkbox" value="1"'
				. checked( $enabel_scf, 1, false ) . disabled( $activate_scf, false, false ) . '>'
				. esc_html__( 'Find image fields for Smart Custom Fields plugin', 'associate-attachment' ) . '</label></p>';

			echo '<p><input type="submit" class="button button-primary hide-if-no-js" name="associate-attachment-attach-button" id="associate-attachment-attach-button" value="' . esc_html__( 'Association', 'associate-attachment' ) . '" /></p>';

			echo '<h2>' . esc_html__( 'Bulk detach', 'associate-attachment' ) . '</h2>';

			echo '<p><label for="associate-attachment-detach-check"><input name="associate-attachment-detach-check" type="checkbox" id="associate-attachment-detach-check" value="1" onchange="document.getElementById(\'associate-attachment-detach-button\').disabled = !this.checked;">' .
				esc_html__( 'Detach all medias association.', 'associate-attachment' ) . '</label>';
			printf(
				'<p><input type="submit" class="button button-danger" name="associate-attachment-detach-button" id="associate-attachment-detach-button" value="%s" disabled onclick="return confirm( \'%s\' );" /></p>',
				esc_attr( __( 'Detach', 'associate-attachment' ) ),
				esc_js( __( "Detach all medias association.\nThis action cannot be undone.\nClick 'Cancel' to go back, 'OK' to confirm the detach.", 'associate-attachment' ) )
			);

			echo '</form>';
		}
		echo '</div>' . "\n";
	}

	/**
	 * The attachment_url_to_postid() subsize support version.
	 *
	 * @since 1.2.1
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param string $url The URL to resolve.
	 * @return int The found post ID, or 0 on failure.
	 */
	private function attachment_url_to_postid( $url ) {
		global $wpdb;

		$dir  = wp_get_upload_dir();
		$path = $url;

		$site_url   = wp_parse_url( $dir['url'] );
		$image_path = wp_parse_url( $path );

		// Force the protocols to match if needed.
		if ( isset( $image_path['scheme'] ) && ( $image_path['scheme'] !== $site_url['scheme'] ) ) {
			$path = str_replace( $image_path['scheme'], $site_url['scheme'], $path );
		}

		if ( 0 === strpos( $path, $dir['baseurl'] . '/' ) ) {
			$path = substr( $path, strlen( $dir['baseurl'] . '/' ) );
		}

		$mime_type = wp_check_filetype( $path );
		$is_image  = ( 0 === strpos( $mime_type['type'], 'image/' ) );

		if ( $is_image ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT pm.post_id FROM $wpdb->postmeta as pm INNER JOIN $wpdb->posts as p ON ( pm.post_id = p.ID ) " .
					"WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND pm.meta_key = '_wp_attachment_metadata' AND meta_value LIKE %s",
					'%' . basename( $path ) . '%'
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value = %s",
					$path
				)
			);
		}

		if ( $post_id && $is_image ) {
			$meta = wp_get_attachment_metadata( $post_id );
			if ( isset( $meta['file'], $meta['sizes'] ) ) {
				$path                = basename( $path );
				$original_file       = basename( $meta['file'] );
				$cropped_image_files = wp_list_pluck( $meta['sizes'], 'file' );
				if ( ! ( $original_file === $path || in_array( $path, $cropped_image_files, true ) ) ) {
					$post_id = null;
				}
			}
		}

		return (int) $post_id;
	}

	/**
	 * Filters the action links displayed for each plugin in the Plugins list table.
	 *
	 * @since 1.3.2
	 *
	 * @param string[] $actions     An array of plugin action links.
	 * @param string   $plugin_file Path to the plugin file relative to the plugins directory.
	 * @return array An array of plugin action links.
	 */
	public function plugin_action_links( $actions, $plugin_file ) {
		if ( 'associate-attachment.php' === basename( $plugin_file ) ) {
			$tools   = array( '<a href="' . admin_url( 'tools.php?page=associate-attachment' ) . '">' . esc_html__( 'Tools', 'associate-attachment' ) . '</a>' );
			$actions = array_merge( $tools, $actions );
		}
		return $actions;
	}
}
