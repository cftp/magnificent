<?php 

/*
Plugin Name: Magnificent
Plugin URI: http://github.com/cftp/magnificent/
Description: A plugin to implement an issues and articles structure outside traditional WordPress posts
Version: 0.5
Author: Code for the People Ltd
Author URI: http://codeforthepeople.com/
*/
 
/*  Copyright 2013 Code for the People Ltd

                _____________
               /      ____   \
         _____/       \   \   \
        /\    \        \___\   \
       /  \    \                \
      /   /    /          _______\
     /   /    /          \       /
    /   /    /            \     /
    \   \    \ _____    ___\   /
     \   \    /\    \  /       \
      \   \  /  \____\/    _____\
       \   \/        /    /    / \
        \           /____/    /___\
         \                        /
          \______________________/

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
	 * A boolean flag to indicate recursion.
	 *
	 * @var boolean
	 **/
	var $recursing;

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

		// Most of the hooks are registered in action_init
		add_action( 'init', array( $this, 'action_init' ) );
		add_action( 'admin_notices', array( $this, 'action_admin_notices' ) );

		$this->version = 3;

		if ( ! is_a( $GLOBALS['wp_rewrite'], 'WP_Rewrite' ) )
			$GLOBALS['wp_rewrite'] = new WP_Rewrite();
		$this->article_permalink_structure = '/' . $GLOBALS['wp_rewrite']->root . 'issue/%issue%/%article%/';
		$this->recursing = false;
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
	 * Hooks the WP action admin_notices to whinge if stuff isn't installed.
	 *
	 * @action admin_notices
	 *
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function action_admin_notices() {
		if ( ! $this->is_p2p_loaded() )
			$this->admin_notice_error( sprintf( __( 'Please install the <a href="%s">Posts to Posts plugin</a>, as the Magnificent plugin requires it.', 'magnificent' ), 'http://wordpress.org/plugins/posts-to-posts/' ) );
			
		if ( ! $this->is_extended_cpts_loaded() )
			$this->admin_notice_error( sprintf( __( 'Please make the <a href="%s">Extended CPTs library</a> available, as the Magnificent plugin requires it.', 'magnificent' ), 'https://github.com/johnbillion/ExtendedCPTs' ) );
			
		if ( ! $this->is_extended_taxos_loaded() )
			$this->admin_notice_error( sprintf( __( 'Please make the <a href="%s">Extended Taxos library</a> available, as the Magnificent plugin requires it.', 'magnificent' ), 'https://github.com/johnbillion/ExtendedTaxos' ) );
			
	}

	/**
	 * Hooks the WP action init to setup the data structures.
	 *
	 * @action init
	 *
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function action_init() {

		// Sanity checks
		if ( ! $this->is_p2p_loaded() )
			return;
		if ( ! $this->is_extended_cpts_loaded() )
			return;
		if ( ! $this->is_extended_taxos_loaded() )
			return;

		add_action( 'admin_init', array( $this, 'action_admin_init' ) );
		add_action( 'save_post', array( $this, 'action_save_post' ), 10, 2 );
		add_action( 'p2p_created_connection', array( $this, 'action_p2p_created_connection' ) );
		add_action( 'p2p_delete_connections', array( $this, 'action_p2p_delete_connections' ) );
		add_filter( 'page_row_actions', array( $this, 'filter_page_row_actions' ), 10, 2 );
		add_filter( 'post_type_link', array( $this, 'filter_post_type_link' ), 10, 2 );
		add_filter( 'posts_clauses', array( $this, 'filter_posts_clauses' ), 10, 2 );
		add_filter( 'query_vars', array( $this, 'filter_query_vars' ) );
		add_filter( 'coauthors_supported_post_types', array( $this, 'filter_coauthors_supported_post_types' ) );

		$this->register_cpts_taxos();
	}

	/**
	 * Hooks the WP filter coauthors_supported_post_types
	 *
	 * @filter coauthors_supported_post_types
	 * @param $post_types
	 *
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function filter_coauthors_supported_post_types( $post_types ) {
		$post_types[] = 'article';
		return $post_types;
	}

	/**
	 * Hooks the WP action save_post
	 *
	 * @action save_post
	 * @param $post_id
	 * @param $post
	 *
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function action_save_post( $post_id, $post ) {
		// @TODO: You shouldn't be able to publish an article with no issue
		// @TODO: You shouldn't be able to publish an issue with no publication, if publications are enabled
		if ( $this->recursing )
			return;
		$this->recursing = true;
		$this->process_article_relationships( $post );
		$this->process_issue_relationships( $post );
		$this->process_publication_relationships( $post );
		$this->recursing = false;
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
		if ( 'article' == $post->post_type ) {
			if ( $post->post_parent ) {
				$parent = get_post( $post->post_parent );
				$permalink = home_url( str_replace( array( '%issue%', '%article%' ), array( $parent->post_name, $post->post_name ), $this->article_permalink_structure ) );
			} else {
				$permalink = '';
			}
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
	public function action_p2p_created_connection( $p2p_id ) {
		
		$this->process_connection_change( $p2p_id );
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
	public function action_p2p_delete_connections( $p2p_ids ) {
		foreach ( $p2p_ids as $p2p_id )
			$this->process_connection_change( $p2p_id );
	}

	// CALLBACKS
	// =========

	/**
	 * 
	 *
	 *
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function callback_col_post_author(  ) {
		var_dump( func_get_args( ) );
	}

	// UTILITIES
	// =========

	/**
	 * 
	 *
	 * @param int $p2p_id The Posts 2 Posts Connection ID
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function process_connection_change( $p2p_id ) {
		if ( $this->recursing )
			return;

		$connection = p2p_get_connection( $p2p_id );
		$post = get_post( $connection->p2p_from );

		$this->recursing = true;
		$this->process_article_relationships( $post );
		$this->process_issue_relationships( $post );
		$this->process_publication_relationships( $post );
		$this->recursing = false;
	}

	/**
	 * Process the data for an article on save.
	 *
	 * @param object $post A WP_Post object
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function process_article_relationships( WP_Post $article ) {
		if ( 'article' != $article->post_type )
			return;

		// @FIXME: This is near duplicate code with the $publications search and set in process_issue_save
		// Get any connected issue and set it 
		// as the article post_parent
		$issue = new WP_Query( array(
			'connected_type'  => 'issue_to_article',
			'connected_items' => $article->ID,
			'fields'          => 'ids',
			'posts_per_page'  => 1,
		) );
		if ( $issue->posts ) {
			foreach ( $issue->posts as $issue_post_id ) {
				$article_post_data = array(
					'ID'          => $article->ID,
					'post_parent' => $issue_post_id,
				);
				wp_update_post( $article_post_data );
			}
		}

	}

	/**
	 * Process the data for an issue on save.
	 *
	 * @param object $post A WP_Post object
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function process_issue_relationships( WP_Post $issue ) {
		if ( 'issue' != $issue->post_type )
			return;

		// @FIXME: This is near duplicate code with the $issues search and set in process_publication_save
		// Get any connected article and set the article parent
		// to the issue.
		$articles = new WP_Query( array(
			'connected_type'  => 'issue_to_article',
			'connected_items' => $issue->ID,
			'fields'          => 'ids',
			'nopaging'        => true,
		) );
		if ( $articles->posts ) {
			foreach ( $articles->posts as $article_post_id ) {
				$article_post_data = array(
					'ID'          => $article_post_id,
					'post_parent' => $issue->ID,
				);
				wp_update_post( $article_post_data );
			}
		}

		// Get any connected publication and set it 
		// as the issue post_parent
		// N.B. Publications might not be enabled, in which
		// case this query returns nothing.
		$publications = new WP_Query( array(
			'connected_type' => 'publication_to_issue',
			'connected_items' => $issue->ID,
			'fields'          => 'ids',
			'posts_per_page'  => 1,
		) );
		if ( $publications->posts ) {
			foreach ( $publications->posts as $publication_post_id ) {
				$issue_post_data = array(
					'ID'          => $issue->ID,
					'post_parent' => $publication_post_id,
				);
				wp_update_post( $issue_post_data );
			}
		}

	}

	/**
	 * Process the data for a publication on save.
	 *
	 * @param object $post A WP_Post object
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function process_publication_relationships( WP_Post $publication ) {
		if ( 'publication' != $publication->post_type )
			return;

		// Get any connected issues and set this publication
		// as the issue post_parent
		$issues = new WP_Query( array(
			'connected_type'  => 'publication_to_issue',
			'connected_items' => $publication->ID,
			'fields'          => 'ids',
			'nopaging'        => true,
		) );
		if ( $issues->posts ) {
			foreach ( $issues->posts as $issue_post_id ) {
				$issue_post_data = array(
					'ID'          => $issue_post_id,
					'post_parent' => $publication->ID,
				);
				wp_update_post( $issue_post_data );
			}
		}
	}

	 *
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function register_cpts_taxos() {

		$issue = register_extended_post_type( 'issue', array(
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
			'menu_position' => 54,
			'filters' => array(
				'issue_type' => array(
					'title'    => __( 'Type', 'magnificent' ),
					'taxonomy' => 'issue_type',
				),
			),
			'labels' => array( 
				'parent_item_colon' => __( 'From Publication:', 'magnificent' ),
			),
			'supports' => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
			'featured_image' => __( 'Cover Image', 'magnificent' ),
			'enter_title_here' => __( 'Issue title', 'magnificent'),
		) );

		$issue_type = register_extended_taxonomy( 'issue_type', 'issue', array(
			'meta_box' => 'radio',
			'capabilities' => array(
				'assign_terms' => 'manage_options'
			),
			'rewrite' => array(
				'slug' => 'magazine/issue-type', // @TODO needs i18n
				'with_front' => false
			),
		) );

		do_action( 'mag_registered_issue', $article );

		$article = register_extended_post_type( 'article', array(
			'map_meta_cap' => true,
			'menu_position' => 53,
			'cols' => array(
				'title' => array(
					'title' => __( 'Issue', 'magnificent' ),
				),
				'author',
				'article_type' => array(
					'title' => __( 'Type', 'magnificent' ),
				),
				'date' => array(
					'post_field' => 'post_date',
				),
			),
			'right_now' => true,
			'filters' => array(
				'issue_type' => array(
					'title'    => __( 'Type', 'magnificent' ),
					'taxonomy' => 'issue_type',
				),
			),
			'labels' => array( 
				'parent_item_colon' => __( 'From Issue:', 'magnificent' ),
			),
			'supports' => array( 'title', 'editor', 'thumbnail' ),
			'enter_title_here' => __( 'Article title', 'magnificent' ),
		) );

		add_rewrite_rule( 'issue/([^/]+)/([^/]+)', 'index.php?post_parent_name=$matches[1]&article=$matches[2]', 'top' );


		if ( $this->are_publications_enabled() ) {

			$publication = register_extended_post_type( 'publication', array(
				'map_meta_cap' => true,
				'menu_position' => 53,
				'cols' => array(
					'cover' => array(
						'title' => '',
						'featured_image' => 'thumbnail',
						'height' => 60
					),
					'title' => array(
						'title' => __( 'Publication', 'magnificent' ),
					),
				),
				'right_now' => true,
				'filters' => array(
				),
				'supports' => array( 'title', 'editor', 'thumbnail' ),
				'enter_title_here' => __( 'Article title', 'magnificent' ),
			) );

			p2p_register_connection_type( array(
				'name'  => 'publication_to_issue',
				'from'  => 'issue',
				'to'    => 'publication',
				'can_create_post' => false,
				'admin_box' => 'any',
				'title' => array(
					'from' => __( 'Issue', 'magnificent'),
					'to'   => __( 'Publication', 'magnificent'),
				),
				'from_labels' => array(
					'create' => __( 'Add issues', 'magnificent' ),
				),
				'to_labels' => array(
					'create' => __( 'Associate with a publication', 'magnificent' ),
				),
				'sortable' => 'to',
				'cardinality' => 'many-to-one',
			) );

		}


		$article_type = register_extended_taxonomy( 'article_type', 'article', array(
			'meta_box' => 'radio',
			'capabilities' => array(
				'assign_terms' => 'manage_options'
			),
		) );

		/**
		 * Called when the article post type has been registered.
		 *
		 * @since 0.4
		 *
		 * @param object $article A WordPress PostType Object.
		 */
		do_action( 'mag_registered_article', $article );

		p2p_register_connection_type( array(
			'name'  => 'issue_to_article',
			'from'  => 'article',
			'to'    => 'issue',
			'can_create_post' => false,
			'admin_box' => 'any',
			'title' => array(
				'from' => __( 'Issue', 'magnificent'),
				'to'   => __( 'Articles', 'magnificent'),
			),
			'from_labels' => array(
				'create' => __( 'Add articles', 'magnificent' ),
			),
			'to_labels' => array(
				'create' => __( 'Associate with an issue', 'magnificent' ),
			),
			'sortable' => 'to',
			'cardinality' => 'many-to-one',
		) );

	}

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
	 * Output the HTML for an admin notice area error.
	 *
	 * @param sting $msg The error message to show
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function admin_notice_error( $msg ) {
		$allowed_html = array(
			'address' => array(),
			'a' => array(
				'href' => true,
				'name' => true,
				'target' => true,
			),
			'em' => array(),
			'strong' => array(),
		);
		?>
		<div class="fade error" id="message">
			<p><?php echo wp_kses( $msg, $allowed_html ); ?></p>
		</div>
		<?php
	}

	/**
	 * Checks whether to enable the Publications feature
	 * of this plugin, which adds the Publications CPT
	 * enabling the site to represent more than one brand
	 * to associate issues (and through them articles) with.
	 *
	 * @return bool True if Publications should be active.
	 * @author Simon Wheatley
	 **/
	public function are_publications_enabled() {
		$enabled = false;

		/**
		 * Filter the value returned to activate (or not) the 
		 * Publications feature of Magnificent.
		 *
		 * @since 0.5
		 * 
		 * @param bool $enabled Whether the Publication feature is enabled, defaults to false.
		 */
		return apply_filters( 'mag_are_publications_enabled', $enabled );
	}

	/**
	 * Checks Posts to Posts plugin is active, by checking for
	 * the P2P_PLUGIN_VERSION constant.
	 *
	 * @return bool True if Posts to Posts is active
	 * @author Simon Wheatley
	 **/
	public function is_p2p_loaded() {
		if ( ! defined( 'P2P_PLUGIN_VERSION' ) )
			return false;
		return true;
	}

	/**
	 * Checks John Blackbourn's Extended Taxonomies library is present,
	 * by checking for the register_extended_post_type function.
	 *
	 * @return bool True if Posts to Posts is active
	 * @author Simon Wheatley
	 **/
	public function is_extended_taxos_loaded() {
		if ( ! is_callable( 'register_extended_taxonomy' ) )
			return false;
		return true;
	}

	/**
	 * Checks John Blackbourn's Extended Taxonomies library is present,
	 * by checking for the register_extended_post_type function.
	 *
	 * @return bool True if Posts to Posts is active
	 * @author Simon Wheatley
	 **/
	public function is_extended_cpts_loaded() {
		if ( ! is_callable( 'register_extended_post_type' ) )
			return false;
		return true;
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

		if ( $version < 3 ) {
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

