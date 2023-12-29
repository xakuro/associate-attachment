<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Associate Attachment plugin for WordPress
 *
 * @package associate-attachment
 * @author  ishitaka
 * @license GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Associate Attachment
 * Plugin URI:        https://xakuro.com/wordpress/associate-attachment/
 * Description:       Associate the media library image with the post.
 * Version:           1.7.1
 * Requires at least: 4.9
 * Requires PHP:      5.6
 * Author:            Xakuro
 * Author URI:        https://xakuro.com/
 * License:           GPL v2 or later
 * Text Domain:       associate-attachment
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ASSOCIATE_ATTACHMENT_VERSION', '1.7.1' );

/**
 * Associate Attachment Main class.
 */
class Associate_Attachment {
	/**
	 * Associate Attachment Admin.
	 *
	 * @var Associate_Attachment_Admin
	 */
	public $admin;

	/**
	 * Construction.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		load_plugin_textdomain( 'associate-attachment' );

		if ( is_admin() ) {
			require_once __DIR__ . '/admin.php';
			$this->admin = new Associate_Attachment_Admin();
		}
	}
}

global $associate_attachment;
$associate_attachment = new Associate_Attachment();
