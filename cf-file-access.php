<?php
/*
Plugin Name: CF File Access 
Plugin URI: http://crowdfavorite.com 
Description: Process incoming /files/* requests for designated file extensions for pre-processing (ie: authentication, redirect, etc...) 
Version: 1.2
Author: Crowd Favorite
Author URI: http://crowdfavorite.com
*/

// Plugin Setup
	
	// 	ini_set('display_errors', '1'); ini_set('error_reporting', E_ALL);

	add_action('generate_rewrite_rules','cfap_addRewriteRules');
	add_action('query_vars','cfap_query_vars');
	add_action('template_redirect','cfap_deliver_file'); // this is the earliest we can find the wp_rewrite result
	
	load_plugin_textdomain('cfap');

// Sample Overrides
	
	/**
	 * Disapprove access the file
	 */
	function cfap_test_action($deliver,$file) {
		return false;
	}
	//add_action('cfap_deliver_file','cfap_test_action',10,2);
	
	/**
	 * Override the denied output
	 */
	function cfap_denied_override($file) {
		die(htmlentities('<nelson>Ha ha!</nelson>'));
	}
	//add_action('cfap_denied','cfap_denied_override');
	
// Process file

	/**
	 * Determine wether file is to be delivered
	 */
	function cfap_deliver_file() {
		global $cfap_filepath;
		if (is_null($cfap_filepath)) { return false; }
		
		$deliver = true;		
		$deliver = apply_filters('cfap_deliver_file', $deliver, $cfap_filepath);
		
		if (!$deliver) { 
			do_action('cfap_denied', $cfap_filepath); 
		}
		else { 
			cfap_passthru($cfap_filepath); 
		}
	}
	
	/**
	 * Deliver a WordPress formatted page that displays a Filterable error message
	 * 
	 * @param string $file - name of file that was requested
	 */
	function cfap_denied($file) {
		do_action('cfap_denied',$file);
		
		status_header(401);

		// prevent caching of this page
		// 'Expires' in the past
		header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
		// Always modified
		header("Last-Modified: ".gmdate("D, d M Y H:i:s")." GMT");
		// HTTP/1.1
		header("Cache-Control: no-store, no-cache, must-revalidate");
		header("Cache-Control: post-check=0, pre-check=0", false);
		// HTTP/1.0
		header("Pragma: no-cache");
			
		global $wp_query;
		
		// Setup the new Post Data
		$post = new stdClass;
		$post->ID = -1;
		$post->post_author = 1;
		$post->post_name = 'access-denied';
		$post->guid = site_url($post->name);
		$post->post_title = 'Access Denied';
		$post->post_content = '<p><b>Access denied to file: '.basename($file).'</b></p>';
		$post->post_date = $post->post_date_gmt= date('Y-m-d H:i:s');
		$post->post_category = '';
		$post->post_excerpt = '';
		$post->post_status = 'post';
		$post->comment_status = 'closed';
		$post->ping_status = 'closed';
		$post->comment_count = 0;
			
		$wp_query->is_404 = false;
		$wp_query->is_home = false;
		$wp_query->is_single = false;
		$wp_query->is_category = false;
		$wp_query->is_attachment = false;
		$wp_query->is_archive = false;
		$wp_query->is_page = true;
		$wp_query->is_singular = true;
		$wp_query->post_type = 'page';
		$wp_query->post_count = 1;
		
		unset($wp_query->query['error']);
		$wp_query->query_vars['error'] = '';
		$wp_query->posts[] = apply_filters('cfap_denied_post', $post, $file);
		
		remove_filter('the_content', 'wpautop');
	}
	add_action('cfap_denied', 'cfap_denied');
	
	/**
	 * Default action for denied is to treat it as an HTTP 404
	 * Default action is the WordPress 404 page
	 * @param string $file - name of file that was requested
	 */
	function cfap_not_found($file) {
		do_action('cfap_not_found', $file);
		return true;
	}

	/**
	 * Pass file through to browser
	 * @param string $file - name of file that was requested
	 */
	function cfap_passthru($file) {
		do_action('cfap_passthru', $file);
		
		$file = apply_filters('cfap_filepath', $file);

		// make sure our file exists
		if (!is_file($file)) {
			// path is not absolute, see if we have a file in the uploads dir
			$file = ABSPATH.UPLOADS.$file;
			// no file found, send 404
			if (!is_file($file)) {
				return cfap_not_found($file);
			}
		}
		
		status_header( 200 );
	
		// a lot of the rest of this is from blogs.php, but slightly modded where deemed necessary
		$mime = wp_check_filetype($file);
		if ($mime['type'] === false && function_exists('mime_content_type')) {
			$mime['type'] = mime_content_type($file);
		}

		if ($mime['type'] != false) {
			$mimetype = $mime[ 'type' ];
		} else {
			$ext = pathinfo($file,PATHINFO_EXTENSION);
			$mimetype = "image/$ext";
		}
		
		// force close mysql link
		global $wpdb;
		// Logic copied from wpdb to determine whether it's using mysql or mysqli.
		$use_mysqli = false;
		if ( function_exists( 'mysqli_connect' ) ) {
			if ( defined( 'WP_USE_EXT_MYSQL' ) ) {
				$use_mysqli = ! WP_USE_EXT_MYSQL;
			} elseif ( version_compare( phpversion(), '5.5', '>=' ) || ! function_exists( 'mysql_connect' ) ) {
				$use_mysqli = true;
			} elseif ( false !== strpos( $GLOBALS['wp_version'], '-' ) ) {
				$use_mysqli = true;
			}
		}
		if ($use_mysqli) {
			mysqli_close($wpdb->dbh);
		}
		else {
			mysql_close($wpdb->dbh);
		}
		
		if ( is_resource($wpdb->dbh) ) {
			mysql_close($wpdb->dbh);
		}
		else {
			@mysqli_close($wpdb->dbh);
		}
		unset($wpdb);
		
		// the rest is unmodified form blogs.php
		@header( 'Content-type: ' . $mimetype ); // always send this
		@header( 'Content-Length: ' . filesize( $file ) );
		
		@header('Content-disposition: inline; filename='.basename($file));		
		
		$last_modified = gmdate('D, d M Y H:i:s', filemtime( $file ));
		$etag = '"' . md5($last_modified) . '"';
		@header( "Last-Modified: $last_modified GMT" );
		@header( 'ETag: ' . $etag );
		@header( 'Expires: ' . gmdate('D, d M Y H:i:s', time() + 100000000) . ' GMT' );

		// Support for Conditional GET
		if (isset($_SERVER['HTTP_IF_NONE_MATCH'])) 
			$client_etag = stripslashes($_SERVER['HTTP_IF_NONE_MATCH']);
		else
			$client_etag = false;
		
		$client_last_modified = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? trim( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) : '';
		// If string is empty, return 0. If not, attempt to parse into a timestamp
		$client_modified_timestamp = $client_last_modified ? strtotime($client_last_modified) : 0;
		
		// Make a timestamp for our most recent modification...	
		$modified_timestamp = strtotime($last_modified);
		
		if (($client_last_modified && $client_etag) ?
			 (($client_modified_timestamp >= $modified_timestamp) && ($client_etag == $etag)) :
			 (($client_modified_timestamp >= $modified_timestamp) || ($client_etag == $etag)) ) {
			status_header(304);
			exit;
		}
		
		// If we made it this far, just serve the file
		readfile($file);
		// forcefully exit, needed to stop the rest of WordPress from processing
		exit;
	}

// Rewrite links

	/**
	 * Check to make sure the Rewrite rules are in place for us to function.  If not reset the rules so they get rebuilt
	 *
	 * @param array $value 
	 * @return array
	 */
	function cfap_get_rewrite_option($value) {
		$rules = cfap_get_rewrite_rules();
		foreach (array_keys($rules) as $rule) {
			if (!array_key_exists($rule,$value)) {
				$value = '';
			}			
		}
		return $value;
	}
	add_filter('option_rewrite_rules', 'cfap_get_rewrite_option', 9999);
	
	/**
	 * Add the Rewrite rules to the global $wp_rewrite object
	 *
	 * @return void
	 */
	function cfap_addRewriteRules() {
		global $wp_rewrite;
		$new_rules = cfap_get_rewrite_rules();
		$wp_rewrite->rules = $new_rules + $wp_rewrite->rules;		
	}
	
	/**
	 * Rewrite rules that need to be in place for plugin to load properly
	 *
	 * @return array
	 */
	function cfap_get_rewrite_rules() {
		global $wp_rewrite;
		return array(
			'files/(.+)' => 'index.php?name='.$wp_rewrite->preg_index(1).'&cfap_filepath='.$wp_rewrite->preg_index(1),
			'(wp-content/uploads/(.+))' => 'index.php?name='.$wp_rewrite->preg_index(2).'&cfap_filepath='.$wp_rewrite->preg_index(1)			
		);
	}
	
	/**
	 * Make our detection var public domain
	 * 
	 * @return array - modified query vars array
	 */
	function cfap_query_vars($vars){
		array_push($vars,'cfap_filepath');
		return $vars;
	}
?>
