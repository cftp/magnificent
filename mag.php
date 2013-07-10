<?php 

/*
Plugin Name: Magnificent
Plugin URI: http://github.com/cftp/magnificent/
Description: A plugin to implement an issues and articles structure outside traditional WordPress posts
Version: 0.1
Author: Code for the People Ltd
Author URI: http://codeforthepeople.com/
*/
 
/*  Copyright 2012 Code for the People Ltd

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
		add_filter( 'page_row_actions', array( $this, 'filter_page_row_actions' ), 10, 2 );

		$this->version = 1;
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
			'supports' => array(
				'title', 'author', 'thumbnail'
			),
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
			'supports' => array(
				'title', 'author', 'thumbnail'
			),
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
	function filter_page_row_actions( $actions, $post ) {
		// @TODO: Add a row action to view all articles in the Articles list table
		return $actions;
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

		// if ( $version < 1 ) {
		// 	error_log( ": …" );
		// }

		// N.B. Remember to increment $this->version above when you add a new IF

		update_option( $option_name, $this->version );
		error_log( ": Done upgrade, now at version " . $this->version );
	}
}


// Initiate the singleton
CFTP_Magnificent::init();


