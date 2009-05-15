<?php
/*
Plugin Name: CF File Access 
Plugin URI: http://crowdfavorite.com 
Description: Process incoming /files/* requests for designated file extensions for pre-processing (ie: authentication, redirect, etc...) 
Version: 1 
Author: Crowd Favorite
Author URI: http://crowdfavorite.com
*/

// # Description
// This plugin allows file requests to be redirected to wordpress so that operations can be performed before delivering files. 
// This allows us to perform fun things like authentication and on the fly file generation.
// 
// ## .htaccess
// Files should be redirected here via a .htaccess rewrite. For example, if you want to redirect pdf files here use:
// 
//		RewriteRule ^(.*/)?files/(.*).pdf index.php [L]
// 
// That tells Apache to send anything with /files/ in the url (which is WordPress MUs way of controlling file distribution) that 
// has pdf at the end to redirect to index.php where we can get at it.
// Critical to this statement is the [L] modifier at the end - it tells apache that if this is a match that it should NOT try 
// to do any more matching and continue on with the request. Without this other rewrite rules will override our desired results.
//
// ## Filter: cfap_deliver_file
//
// Inspecting the requested file is done via the 'cfap_deliver_file' action. When called it passes in the path of the requested
// file (most likely something like: files/2008/10/my_file.pdf). This allows other plugins to determine wether the file should
// be shown or not. The filtering function should return false if access is denied, or the filepath if access is granted. You can
// modify the file path at this time but the result must point to a real file on the filesystem, or be relative to the active blog's
// uploads dir or else a 404 will be delivered.
//
// ## Filter: cfap_filepath
// 
// A way of modifying the file path before the file is delivered. This contains the full filesystem path to the file that is
// set to be delivered. Any modification should be a full file path to a readable file.
//
// ## Filter: cfap_denied_post
//
// Use this filter to control the vebiage output on the access denied page. The post contains the basics needed to display a page in
// "single" context. Data should be modified or added, but object items should not be removed. For example, to now show a title on
// the access denied page set the $post->post_title to '' instead of unsetting the object member.
// 
// ## Actions: cfap_passthru, cfap_denied, cfap_not_found
// 
// Actions on these functions are passed the $filepath. cfap_not_found defaults to the wordpress 404 page.
// cfap_denied generates a fake page telling the user access denied. This contents can be overidden through the filer cfap_denied_post.
// 
// # Tested on
//	WPMU 2.6.3


// 	ini_set('display_errors', '1'); ini_set('error_reporting', E_ALL);

load_plugin_textdomain('crowdfavorite');
add_action('init','cfap_flushRewriteRules');
add_action('generate_rewrite_rules','cfap_addRewriteRules');
add_action('query_vars','cfap_query_vars');
add_action('template_redirect','cfap_deliver_file'); // this is the earliest we can find the wp_rewrite result

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
		if(is_null($cfap_filepath)) { return false; }
		
		$deliver = true;		
		$deliver = apply_filters('cfap_deliver_file',$deliver,$cfap_filepath);

		if(!$deliver) { cfap_denied($cfap_filepath); }
		else { cfap_passthru($cfap_filepath); }
	}
	
	/**
	 * Deliver a 401 authentication error
	 * @TODO: wordpress page generation to show a "purty" page
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
		
		$post = new stdClass;
			$post->ID = -1;
			$post->post_author = 1;
			$post->post_name = 'access-denied';
			$post->guid = get_bloginfo('wpurl') . '/' . $post->name;
			$post->post_title = 'Access Denied';
			$post->post_content = '<p><strong>Access denied to file: '.basename($file).'</strong></p>';
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
		$wp_query->posts[] = apply_filters('cfap_denied_post',$post);
		
		remove_filter('the_content','wpautop');
		
		//die(__('401 &#8212; Access to file "'.basename($file).'" not allowed.'));	
	}
	
	/**
	 * Default action for denied is to treat it as an HTTP 404
	 * Default action is the WordPress 404 page
	 * @param string $file - name of file that was requested
	 */
	function cfap_not_found($file) {
		do_action('cfap_not_found',$file);
		return true;
	}

	/**
	 * Pass file through to browser
	 * @param string $file - name of file that was requested
	 */
	function cfap_passthru($file) {
		do_action('cfap_passthru',$file);
		
		$file = apply_filters('cfap_filepath',$file);

		// make sure our file exists
		if(!is_file($file)) {
			// path is not absolute, see if we have a file in the uploads dir
			$file = ABSPATH.UPLOADS.$file;
			// no file found, send 404
			if(!is_file($file)) {
				return cfap_not_found($file);
			}
		}
		
		status_header( 200 );
	
		// a lot of the rest of this is from blogs.php, but slightly modded where deemed necessary
		$mime = wp_check_filetype($file);
		if($mime['type'] === false && function_exists('mime_content_type')) {
			$mime['type'] = mime_content_type($file);
		}

		if($mime['type'] != false) {
			$mimetype = $mime[ 'type' ];
		} else {
			$ext = pathinfo($file,PATHINFO_EXTENSION);
			$mimetype = "image/$ext";
		}
				
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
		
		$client_last_modified = trim( $_SERVER['HTTP_IF_MODIFIED_SINCE']);
		// If string is empty, return 0. If not, attempt to parse into a timestamp
		$client_modified_timestamp = $client_last_modified ? strtotime($client_last_modified) : 0;
		
		// Make a timestamp for our most recent modification...	
		$modified_timestamp = strtotime($last_modified);
		
		if ( ($client_last_modified && $client_etag) ?
			 (($client_modified_timestamp >= $modified_timestamp) && ($client_etag == $etag)) :
			 (($client_modified_timestamp >= $modified_timestamp) || ($client_etag == $etag)) ) {
			status_header( 304 );
			exit;
		}
		
		// If we made it this far, just serve the file
		ob_clean();
		flush();
		readfile( $file );
		// forcefully exit, needed to stop the rest of wordpress from processing
		exit;
	}

// Rewrite links

	/**
	 * Make sure new rewrite rules are processed
	 */
	function cfap_flushRewriteRules() {
	   global $wp_rewrite;
	   $wp_rewrite->flush_rules();		
	}
	
	/**
	 * Default catch all redirected files
	 * Which files should be redirected should be caught in the .htaccess file
	 */
	function cfap_addRewriteRules() {
		global $wp_rewrite;
		$new_rules = array(
				'files/(.+)' => 'index.php?name='.$wp_rewrite->preg_index(1).'&cfap_filepath='.$wp_rewrite->preg_index(1),
			);
		$wp_rewrite->rules = $new_rules + $wp_rewrite->rules;		
	}
	
	/**
	 * Make our detection var public domain
	 * @return array - modified query vars array
	 */
	function cfap_query_vars($vars){
		array_push($vars,'cfap_filepath');
		return $vars;
	}
?>