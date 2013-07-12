<?php 

/*
Plugin Name: Magnificent
Plugin URI: http://github.com/cftp/magnificent/
Description: A plugin to implement an issues and articles structure outside traditional WordPress posts
Version: 0.1
Author: Code for the People Ltd
Author URI: http://codeforthepeople.com/
*/
 
/*  Copyright 2013 Code for the People Ltd

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*/

if ( ! class_exists( 'ExtendedCPT' ) )
	require_once( dirname( __FILE__ ) . '/inc/extended-cpts.php' );
if ( ! class_exists( 'ExtendedTaxonomy' ) )
	require_once( dirname( __FILE__ ) . '/inc/extended-taxos.php' );


/**
 * Define the data structures.
 * 
 * @package 
 **/
class CFTP_Magnificent {

	/**
	 * A version integer.
	 *
	 * @var int
	 **/
	var $version;

	/**
	 * The permalink structure for an article
	 *
	 * @var string
	 **/
	var $article_permalink_structure;

	/**
	 * Singleton stuff.
	 * 
	 * @access @static
	 * 
	 * @return CFTP_Magnificent object
	 */
	static public function init() {
		static $instance = false;

		if ( ! $instance )
			$instance = new CFTP_Magnificent;

		return $instance;

	}

	/**
	 * Class constructor
	 *
	 * @return null
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'action_admin_init' ) );
		add_action( 'init', array( $this, 'action_init' ) );
		add_action( 'p2p_created_connection', array( $this, 'action_p2p_created_connection' ) );
		add_action( 'p2p_delete_connections', array( $this, 'action_p2p_delete_connections' ) );
		add_filter( 'page_row_actions', array( $this, 'filter_page_row_actions' ), 10, 2 );
		add_filter( 'post_type_link', array( $this, 'filter_post_type_link' ), 10, 2 );
		add_filter( 'posts_clauses', array( $this, 'filter_posts_clauses' ), 10, 2 );
		add_filter( 'query_vars', array( $this, 'filter_query_vars' ) );


		$this->version = 3;

		if ( ! is_a( $GLOBALS['wp_rewrite'], 'WP_Rewrite' ) )
			$GLOBALS['wp_rewrite'] = new WP_Rewrite();
		$this->article_permalink_structure = '/' . $GLOBALS['wp_rewrite']->root . 'issue/%issue%/%article%/';
	}

	// HOOKS
	// =====

	/**
	 * Hooks the WP action admin_init
	 *
	 * @action admin_init
	 *
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function action_admin_init() {
		$this->maybe_upgrade();
	}

	/**
	 * Hooks the WP action init to setup the data structures.
	 *
	 * @action init
	 *
	 * @return void
	 * @author Simon Wheatley
	 **/
	function action_init() {

		$issue = register_extended_post_type( 'issue', array(
			'capability_type' => 'cftp_magnificent',
			'map_meta_cap' => true,
			'cols' => array(
				'cover' => array(
					'title' => '',
					'featured_image' => 'thumbnail',
					'height' => 60
				),
				'title' => array(
					'title' => 'Issue'
				),
				'date' => array(
					'post_field' => 'post_date',
				),
			),
			'right_now' => true,
			// 'menu_icon' => $this->plugin_url( '/imgs/icon.png' ),
			'filters' => array(
				'issue_type' => array(
					'title'    => 'Type',
					'taxonomy' => 'issue_type',
				),
			),
			'supports' => array( 'title', 'thumbnail' ),
			'featured_image' => 'Cover Image',
			'enter_title_here' => 'Issue title',
		) );

		$issue_type = register_extended_taxonomy( 'issue_type', 'issue', array(
			'meta_box' => 'radio',
			'capabilities' => array(
				'assign_terms' => 'manage_options'
			),
		) );

		$article = register_extended_post_type( 'article', array(
			'capability_type' => 'cftp_magnificent',
			'map_meta_cap' => true,
			'cols' => array(
				'title' => array(
					'title' => 'Issue'
				),
				'article_type' => array(
					'title' => 'Type'
				),
				'date' => array(
					'post_field' => 'post_date',
				),
			),
			'right_now' => true,
			// 'menu_icon' => $this->plugin_url( '/imgs/icon.png' ),
			'filters' => array(
				'issue_type' => array(
					'title'    => 'Type',
					'taxonomy' => 'issue_type',
				),
			),
			'supports' => array( 'title', 'editor', 'thumbnail' ),
			'enter_title_here' => 'Article title',
		) );

		add_rewrite_rule( 'issue/([^/]+)/([^/]+)', 'index.php?post_parent_name=$matches[1]&article=$matches[2]', 'top' );


		$article_type = register_extended_taxonomy( 'article_type', 'article', array(
			'meta_box' => 'radio',
			'capabilities' => array(
				'assign_terms' => 'manage_options'
			),
		) );

		$article->add_taxonomy( 'category' );

		p2p_register_connection_type( array(
			'name'  => 'Issue',
			'from'  => 'article',
			'to'    => 'issue',
			'can_create_post' => false,
			'admin_box' => 'any',
			'title' => array(
				'from' => 'Issue',
				'to'   => 'Articles'
			),
			'from_labels' => array(
			),
			'sortable' => 'to',
			'cardinality' => 'many-to-one',
		) );
		// @TODO: Change the connection creation logo from "+ Create connections" to "Associate with an issue" and "Add articles to this issue"



	}

	/**
	 * Hooks the WP filter page_row_actions
	 *
	 * @filter page_row_actions
	 * 
	 * @param array $actions An array of list table actions
	 * @param object $post A WP Post object
	 *
	 * @return array The actions
	 * @author Simon Wheatley
	 **/
	public function filter_page_row_actions( $actions, $post ) {
		// @TODO: Add a row action to view all articles in the Articles list table
		return $actions;
	}

	/**
	 * Hooks the WP redirect_canonical filter to stop project and
	 * event URLs from redirecting.
	 *
	 * @param string $redirect_url The redirected URL
	 * @param string $requested_url The requested URL
	 * @return string The URL to redirect to
	 * @author Simon Wheatley
	 **/
	public function filter_redirect_canonical( $redirect_url, $requested_url ) {
		// If this isn't a 404, send back the redirect URL
		if ( ! is_404() )
			return $redirect_url;

		if ( in_array( get_query_var( 'post_type' ), array( 'issue', 'article' ) ) )
			return $requested_url;
			
		return $redirect_url;
	}

	/**
	 * Hooks the WP query_vars filter to add various of our geo
	 * search specific query_vars.
	 *
	 * @param array $query_vars An array of the public query vars 
	 * @return array An array of the public query vars
	 * @author Simon Wheatley
	 **/
	public function filter_query_vars( $query_vars ) {
		return array_merge( $query_vars, array( 'post_parent_name' ) );
	}

	/**
	 * Amend the WHERE clause to additionally search for our post_parent_name
	 * query variable. This allows our fancy article URLs to work.
	 *
	 * @param array $clauses An array of SQL clauses from WP_Query
	 * @param object $query A WP_Query object 
	 * @return array An array of SQL clauses from WP_Query
	 * @author Simon Wheatley
	 **/
	public function filter_posts_clauses( $clauses, $query ) {
		global $wpdb;
		if ( ! $post_parent_name = $query->get( 'post_parent_name' ) )
			return $clauses;
		$sql = " AND $wpdb->posts.post_parent IN ( SELECT ID FROM $wpdb->posts WHERE post_name = %s AND post_type = 'issue' AND post_status = 'publish' ) ";
		$clauses[ 'where' ] .= $wpdb->prepare( $sql, $post_parent_name );
		return $clauses;
	}

	/**
	 * Hooks the WP post_type_link filter to provide a permalink for articles.
	 *
	 * @param string $permalink The currently constructed permalink 
	 * @param int $post_id The ID of the WP Post that the permalink is for
	 * @return string The currently constructed permalink 
	 * @author Simon Wheatley
	 **/
	public function filter_post_type_link( $permalink, $post_id ) {
		global $wp_rewrite;
		if ( ! is_object( $wp_rewrite ) || ! $wp_rewrite->using_permalinks() )
			return $permalink;
		$post = get_post( $post_id );
		if ( 'issue' == $post->post_type ) {
			// @TODO: Remove the below code if unnecessary
			// $permalink = home_url() . str_replace( '%issue%', $post->post_name, $this->issue_permalink_structure );
		} elseif ( 'article' == $post->post_type ) {
			$parent = get_post( $post->post_parent );
			$permalink = home_url() . str_replace( array( '%issue%', '%article%' ), array( $parent->post_name, $post->post_name ), $this->article_permalink_structure );
		}
		return $permalink;
	}

	/**
	 * Hooks the P2P action p2p_created_connection when a connection is created
	 *
	 * @action p2p_created_connection
	 *
	 * @param int $p2p_id A P2P connection ID
	 * @return void
	 * @author Simon Wheatley
	 **/
	function action_p2p_created_connection( $p2p_id ) {
		error_log( "SW: Created a connection ID " . print_r( $p2p_id , true ) );
		// @TODO: Set post_parent for any articles
	}

	/**
	 * Hooks the P2P action p2p_delete_connections when connection(s) are deleted
	 *
	 * @action p2p_delete_connections
	 *
	 * @param array $p2p_ids An array of P2P connection IDs as integers
	 * @return void
	 * @author Simon Wheatley
	 **/
	function action_p2p_delete_connections( $p2p_ids ) {
		foreach ( $p2p_ids as $p2p_id )
			error_log( "SW: Deleted a connection ID " . print_r( $p2p_id , true ) );
		// @TODO: Remove post_parent for any now orphaned articles
	}

	// CALLBACKS
	// =========

	// UTILITIES
	// =========

	/**
	 * Wrapper for wp_enqueue_script which takes care of the version checks
	 *
	 * @param string $handle Script name
	 * @param string $src Script url
	 * @param array $deps (optional) Array of script names on which this script depends
	 * @param bool $in_footer (optional) Whether to enqueue the script before </head> or before </body>
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function enqueue_script( $handle, $path = false, $deps = array(), $in_footer = false ) {
		// If the minified version of the script isn't referenced,
		// AND the SCRIPT_DEBUG isn't set, 
		// AND the minified version exists
		// then enqueue the minified version
		if ( ! preg_match( '/\.min\.js$/i', $path ) && ( ! defined( 'SCRIPT_DEBUG' ) || ! SCRIPT_DEBUG ) ) {
			$_path = preg_replace( '/\.js$/', '.min.js', $path );
			if ( file_exists( get_template_directory() . $_path ) )
				$path = $_path;
		}

		$url = get_template_directory_uri() . $path;
		$version = filemtime( get_template_directory() . $path );
		return wp_enqueue_script( $handle, $url, $deps, $version, $in_footer );
	}
		
	/**
	 * Wrapper for wp_enqueue_style which takes care of the version checks
	 *
	 * @param string $handle Style name
	 * @param string $src Stylesheet url
	 * @param array $deps (optional) Array of style names on which this style depends
	 * @param string $media The media for which this stylesheet has been defined.
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function enqueue_style( $handle, $path = false, $deps = array(), $media = 'all' ) {
		// If the minified version of the style isn't referenced,
		// AND the SCRIPT_DEBUG isn't set, 
		// AND the minified version exists
		// then enqueue the minified version
		if ( ! preg_match( '/\.min\.css$/i', $path ) && ( ! defined( 'SCRIPT_DEBUG' ) || ! SCRIPT_DEBUG ) ) {
			$_path = preg_replace( '/\.css$/', '.min.css', $path );
			if ( file_exists( get_template_directory() . $_path ) )
				$path = $_path;
		}

		$url = get_template_directory_uri() . $path;
		$version = filemtime( get_template_directory() . $path );
		return wp_enqueue_style( $handle, $url, $deps, $version, $media );
	}

	/**
	 * Checks the DB structure is up to date, rewrite rules, 
	 * theme image size options are set, etc.
	 *
	 * @return void
	 **/
	public function maybe_upgrade() {
		global $wpdb;
		$option_name = 'cftp_magnificent_version';
		$version = get_option( $option_name, 0 );

		if ( $version == $this->version )
			return;

		if ( $version < 2 ) {
			flush_rewrite_rules();
			error_log( "CFTP Magnificent: Flush rewrite rules" );
		}

		// N.B. Remember to increment $this->version above when you add a new IF

		update_option( $option_name, $this->version );
		error_log( "CFTP Magnificent: Done upgrade, now at version " . $this->version );
	}
}


// Initiate the singleton
CFTP_Magnificent::init();


