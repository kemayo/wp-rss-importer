<?php
/*
Plugin Name: RSS Importer
Plugin URI: http://wordpress.org/extend/plugins/rss-importer/
Description: Import posts from an RSS feed.
Author: wordpressdotorg, David Lynch
Author URI: http://wordpress.org/
Version: 0.3
Stable tag: 0.3
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

if ( !defined('WP_LOAD_IMPORTERS') )
	return;

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( !class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require_once $class_wp_importer;
}

/**
 * RSS Importer
 *
 * @package WordPress
 * @subpackage Importer
 */

/**
 * RSS Importer
 *
 * Will process a RSS feed for importing posts into WordPress. This is a very
 * limited importer and should only be used as the last resort, when no other
 * importer is available.
 *
 * @since unknown
 */
if ( class_exists( 'WP_Importer' ) ) {
class RSS_Import extends WP_Importer {

	var $posts = array ();
	var $file;

	function header() {
		echo '<div class="wrap">';
		screen_icon();
		echo '<h2>'.__('Import RSS', 'rss-importer').'</h2>';
	}

	function footer() {
		echo '</div>';
	}

	function greet() {
		echo '<div class="narrow">';
		echo '<p>'.__('Howdy! This importer allows you to extract posts from an RSS 2.0 file into your WordPress site. This is useful if you want to import your posts from a system that is not handled by a custom import tool. Pick an RSS file to upload and click Import.', 'rss-importer').'</p>';
		wp_import_upload_form("admin.php?import=rss&amp;step=1");
		echo '</div>';
	}

	function _normalize_tag( $matches ) {
		return '<' . strtolower( $matches[1] );
	}

	function get_posts() {
		global $wpdb;

		if (function_exists('set_magic_quotes_runtime')) {
			// PHP7: removes this. Retain compatibility.
			set_magic_quotes_runtime(0);
		}

		$rss = simplexml_load_file($this->file)->channel;
		$this->posts = array();
		foreach ($rss->item as $item) {
			$namespaces = $item->getNameSpaces(true);
			$dc = false;
			if (!empty($namespaces['dc'])) {
				$dc = $item->children($namespaces['dc']);
			}

			$post_title = $item->title;
			$post_date_gmt = $item->pubDate;
			if ($post_date_gmt) {
				$post_date_gmt = strtotime($post_date_gmt);
			} else if ($dc) {
				// if we don't already have something from pubDate
				$post_date_gmt = $dc->date;
				$post_date_gmt = preg_replace('|([-+])([0-9]+):([0-9]+)$|', '\1\2\3', $post_date_gmt);
				$post_date_gmt = str_replace('T', ' ', $post_date_gmt);
				$post_date_gmt = strtotime($post_date_gmt);
			}
			$post_date_gmt = gmdate('Y-m-d H:i:s', $post_date_gmt);
			$post_date = get_date_from_gmt( $post_date_gmt );

			$categories = array();
			if ($item->category) {
				foreach ($item->category as $category) {
					$categories[] = (string)$category;
				}
			} else if ($dc) {
				foreach ($dc->subject as $category) {
					$categories[] = (string)$category;
				}
			}

			foreach ($categories as $cat_index => $category) {
				$categories[$cat_index] = $wpdb->escape( html_entity_decode( $category ) );
			}

			$guid = '';
			if ($item->guid) {
				$guid = $wpdb->escape(trim($item->guid));
			}

			$post_content = false;
			if (!empty($namespaces['content'])) {
				$content = $item->children($namespaces['content']);
				if ($content->encoded) {
					$post_content = $wpdb->escape(trim($content->encoded));
				}
			}
			if (!$post_content) {
				// This is for feeds that put content in description
				$post_content = $wpdb->escape(html_entity_decode(trim($item->description)));
			}

			// Clean up content
			$post_content = preg_replace_callback('|<(/?[A-Z]+)|', array( &$this, '_normalize_tag' ), $post_content);
			$post_content = str_replace('<br>', '<br />', $post_content);
			$post_content = str_replace('<hr>', '<hr />', $post_content);

			$post_author = 1;
			$post_status = 'publish';
			$this->posts[] = compact('post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_status', 'guid', 'categories');
		}
	}

	function import_posts() {
		echo '<ol>';

		foreach ($this->posts as $post) {
			echo "<li>".__('Importing post...', 'rss-importer');

			extract($post);

			if ($post_id = post_exists($post_title, $post_content, $post_date)) {
				_e('Post already imported', 'rss-importer');
			} else {
				$post_id = wp_insert_post($post);
				if ( is_wp_error( $post_id ) )
					return $post_id;
				if (!$post_id) {
					_e('Couldn&#8217;t get post ID', 'rss-importer');
					return;
				}

				if (0 != count($categories))
					wp_create_categories($categories, $post_id);
				_e('Done!', 'rss-importer');
			}
			echo '</li>';
		}

		echo '</ol>';

	}

	function import() {
		$file = wp_import_handle_upload();
		if ( isset($file['error']) ) {
			echo $file['error'];
			return;
		}

		$this->file = $file['file'];
		$this->get_posts();
		$result = $this->import_posts();
		if ( is_wp_error( $result ) )
			return $result;
		wp_import_cleanup($file['id']);
		do_action('import_done', 'rss');

		echo '<h3>';
		printf(__('All done. <a href="%s">Have fun!</a>', 'rss-importer'), get_option('home'));
		echo '</h3>';
	}

	function dispatch() {
		if (empty ($_GET['step']))
			$step = 0;
		else
			$step = (int) $_GET['step'];

		$this->header();

		switch ($step) {
			case 0 :
				$this->greet();
				break;
			case 1 :
				check_admin_referer('import-upload');
				$result = $this->import();
				if ( is_wp_error( $result ) )
					echo $result->get_error_message();
				break;
		}

		$this->footer();
	}

	function RSS_Import() {
		// Nothing.
	}
}

$rss_import = new RSS_Import();

register_importer('rss', __('RSS', 'rss-importer'), __('Import posts from an RSS feed.', 'rss-importer'), array ($rss_import, 'dispatch'));

} // class_exists( 'WP_Importer' )

function rss_importer_init() {
    load_plugin_textdomain( 'rss-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'rss_importer_init' );
