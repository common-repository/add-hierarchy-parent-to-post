<?php
/*
 * Plugin Name:		Add Hierarchy (parent) to post
 * Description:		[DEPRECATED! plugin will not receive updates and we do not have any recommendations for alternative plugins] Plugin adds "parent & hierarchy" functionality to posts.
 * Text Domain:		add-hierarchy-parent-to-post
 * Domain Path:		/languages
 * Version:		    4.0
 * WordPress URI:	https://wordpress.org/plugins/add-hierarchy-parent-to-post/
 * Plugin URI:		https://puvox.software/software/wordpress-plugins/?plugin=add-hierarchy-parent-to-post
 * Contributors: 	puvoxsoftware,ttodua
 * Author:		    Puvox.software
 * Author URI:		https://puvox.software/
 * Donate Link:		https://paypal.me/Puvox
 * License:		    GPL-3.0
 * License URI:		https://www.gnu.org/licenses/gpl-3.0.html
 
 * @copyright:		Puvox.software
*/


namespace AddHierarchyParentToPost
{
  if (!defined('ABSPATH')) exit;
  require_once( __DIR__."/library.php" );
  require_once( __DIR__."/library_wp.php" );

  class PluginClass extends \PuvoxOld\wp_plugin
  {

	public function declare_settings()
	{
		
		$this->initial_static_options	= 
		[
			'has_pro_version'        => 0, 
            'show_opts'              => true, 
            'show_rating_message'    => true, 
            'show_donation_footer'   => true, 
            'show_donation_popup'    => true, 
            'menu_pages'             => [
                'first' =>[
                    'title'           => 'Add Hierarchy (parent) to post', 
                    'default_managed' => 'singlesite',            // network | singlesite
                    'required_role'   => 'install_plugins',
                    'level'           => 'submenu',
                    'page_title'      => 'Options page',
                    'tabs'            => [],
                ],
            ]
		];

		$this->initial_user_options		= 
		[	
			'hierarchy_permalinks_too'	=> 1,
			'custom_post_types'			=> "post,",
			'hierarchy_using' 			=> "query_post",  // "query_post"  or "modify_post_obj" or "rewrite" (worst case)
			'other_cpts_as_parent' 		=> "", 
			'other_cpts_as_parent_rest_too'=> false, 
		]; 
	}

	public function __construct_my()
	{
		add_action( 'registered_post_type', 				[$this, 'enable_hierarchy_fields'], 123, 2);
		add_filter( 'post_type_labels_'.$post_type='post',  [$this, 'enable_hierarchy_fields_for_js'], 11, 2);

		if($this->opts['hierarchy_permalinks_too'])  {  
			
			//just example funcs into init
			if(is_admin()) 
				add_action('init',	[$this, 'init_action'], 777 );
			
			// change permalinks on front-end source links
			add_filter('pre_post_link', [$this,'change_permalinks'], 8, 3 ); 
			
			// DIFFICULT PART:  making WP to recoginzed the hierarchied URL-STRUCTURE //
			
			// (modify_post_obj):  using "registered_post_type"
			// ** "register_post_type_args" almost same as  "registered_post_type", but worse (the labels are set as "page" (see output: pastebin(dot)com/raw/iVahbbLw ). also, 'rewrite' gives error.  (See more details at: pastebin(dot)com/raw/0ujkRzLE )  .  Also, similar results to "reregister_post" so, i use only this version  ( `reregister_post` is worse than `modify_post_obj`. see: pastebin(dot)com/raw/9ZabSn0E )
			if($this->opts['hierarchy_using'] == 'modify_post_obj'){
				add_action('registered_post_type',	[$this, 'method__modify_post_obj'], 150 , 2);
			}
			
			// (query_post):  using "pre_get_posts" 
			elseif($this->opts['hierarchy_using'] == 'query_post'){
				add_filter( 'pre_get_posts', [$this,'method__query'] , 888  ); 
				add_action( 'registered_post_type', [$this, 'hierarchy_for_custom_post'], 90 , 2 );
				//  $this->load_pro();
			}
			//(rewrite):  using "add_rewrite_rule" 
			elseif($this->opts['hierarchy_using'] == 'rewrite'){
				add_action('init', [$this, 'method__rewrite'], 150 );
			}

			//check if permalinks not enabled
			add_action('current_screen', function(){
				$screen = get_current_screen(); 
				if ( $screen->base == 'post' ) {
					$this->alert_if_not_pretty_permalinks();
				}

			} ); 
		}
		
		$this->cpt_as_parent_init();
	}
 
	// ============================================================================================================== //
	// ============================================================================================================== //

	public function deactivation_funcs($network_wide){ 	
		if ( is_multisite() ) {  // && $network_wide 
			global $wpdb;
			$blogs = $wpdb->get_col("SELECT blog_id FROM ". $wpdb->blogs);
			foreach ($blogs as $blog_id) {
				switch_to_blog($blog_id);
				$this->flush_rules_original(); 		
				restore_current_blog();
			} 
		} 
		else {
			$this->flush_rules_original(); 
		}
		//in activtion could have been: $this->add_rewrites(); 	
	}	
	
  	public function flush_rules_original()	{
		//$this->update_option_CHOSEN('rewrite_rules',  $this->get_option_CHOSEN('rewrite_rules_BACKUPED__AHPTP') );
		$this->helpers->flush_rules(false);
	}
	
	
	// ============================================================================================================== //
	// ============================================================================================================== //

	// register_post_type_args   //$args['rewrite']['slug']='/';


	public function init_action()	{
		$this->flush_rules_if_needed(__FILE__);
	}

	// note, with 'registered_post_type' argument $post_type_object is  same as  $GLOBALS['wp_post_types']['post'], but globalized one   (from:   wp-includes/post: 1120 ), however, they behave samely	


	// =====================================================================
	// ==============   Add PARENT FIELD to POST TYPE support    ===========

	public function enable_hierarchy_fields($post_type, $post_type_object){
		if($post_type== 'post' ){	
			$post_type_object->hierarchical = true;
			$GLOBALS['_wp_post_type_features']['post']['page-attributes']=true;
		}
	}

	public function enable_hierarchy_fields_for_js($labels){
		$labels->parent_item_colon='Parent Post';
		return $labels;
	}
	// =====================================================================






	// ===================================================
	// ==============   Start URL hierarchy    ===========

	// method 1 (seems useless): child posts  work, pages (or other things) go to 404
	public function method__modify_post_obj($post_type, $post_type_object){
		$Type = 'post';
		if($post_type==$Type){	
			$post_type_object->rewrite = ['with_front'=>false, 'slug'=>'/', 'feeds' => 1];    // otherwise bugs in class-wp-post-type.php 566 ;  [pages] => 1 [feeds] => 1 [ep_mask] => 1
			$post_type_object->query_var =  'post'; //'post' or true;  without this line, everything goes 404
			// at this moment, we cant call that function, so, call later
			add_action('init', function(){
				// this function causes exactly to finalize everything before. so, it makes hierarchied post to work, but break other post types (page or etc..) to 404
				$GLOBALS['wp_post_types']['post']->	add_rewrite_rules();   // ref: pastebin(dot)com/raw/3yVg8jXp
				// $this->add_rewrite_for_post();
			} ); 
		}
	}



	// method 2 (also, independent)
	public function method__query( $query ) 
	{ 
		$pType = 'post';
		$q=$query;

		if( $q->is_main_query() && !is_admin() ) {
			//at first, check if it's attachment, because only attachment meet this rewrite like hierarchied post
			if( 
				true
									// needs to be attachement: wp-includes\class-wp-query.php, but everything happens in parse_request(), because of rewrite match, that is attachement
				 //&& ( (!is_multisite() && $q->is_attachment) || (is_multisite() && !$q->is_attachment) ) )
			){
				$possible_post_path = trailingslashit( preg_replace_callback('/(.*?)\/((page|feed|rdf|rss|rss2|atom)\/.*)/i', function($matches) { return $matches[1]; } ,  $this->path_after_blog() ) );

				//if seems hierarchied - 2 slashes at least, like:  parent/child/
				if(substr_count($possible_post_path, "/") >= 2) {
					$post=get_page_by_path($possible_post_path, OBJECT, $pType); 
					if ($post){
						// create query
						//no need of $q->init();	
						$q->parse_query( ['post_type'=>[$pType]  ] ) ;  //better than $q->set('post_type', 'post');
						$q->is_home		= false; 
						//$q->is_page		= $method_is_page ? true : false; 
						$q->is_single	= true; 
						$q->is_singular	= true; 
						$q->queried_object_id=$post->ID;  
						$q->set('page_id',$post->ID);
								//add_action('wp', function (){   v($GLOBALS['wp_query']);	});
						return $q;
					}
					//if parent was "custom post type" selected, like  /my-cpt/my-post
					else if ($this->cpt_parent_enabled())
					{
						return $this->cpt_parented_query($q, $possible_post_path, $pType); 
					}
				}
			}
		}
		return $q;
	} 

	public function hierarchy_for_custom_post($post_type, $post_type_object){
		$custom_posts = !empty( $this->opts["custom_post_types"] ) ? array_filter( explode(",", $this->opts["custom_post_types"] ) ) : ['post'];
		foreach ($custom_posts as $each_type){
			$each_type = trim($each_type);
			if($post_type==$each_type){
				$post_type_object->hierarchical = true;
			}
		}
	}
		














		/*

			// method 2: hierarchy posts work, pages break
		//	public function method__reregister_post()	{  
		//		$Type = 'post';
		//		$post_obj = get_post_type_object($Type);
		//		$args_existing = json_decode(json_encode($post_obj), true);
		//		$args_new = $args_existing;
		//		$args_new['has_archive']	= true; //
		//		$args_new['query_var']		= 'post'; // 'post' or true
		//		$args_new['rewrite']		= array('with_front'=>false, 'slug'=>'/'); //  /  'rewrite' => array("ep_mask"=>EP_PERMALINK ...) OR    'permalink_epmask'=>EP_PERMALINK, 
		//
		//		register_post_type( $Type, $args_new ); 
				// register_post_type function : pastebin(dot)com/raw/3fjqYHPj
		//		$this->add_rewrite_for_post();
		//	}


			public function method__rewrite(){

				add_rewrite_rule( $reg = '([^/]*)/(.*)' ,  $match='index.php?name=$matches[2]',	$priority='top' );
				$this->flush_rules_if_needed(__FILE__);
				// pastebin(dot)com/raw/kvEXCqKQ
				
				//
				//add_rewrite_rule($reg,  'index.php?name=$matches[2]',	'top' );
				
		//		'([^/]+)/?',   //([^/]+)          //([^/]*)/(.*)
		//		add_rewrite_rule(   '([^/]*)/(.*)',     'index.php?name=$matches[2]',     'top'     );
					
				//$rules = get_option( 'rewrite_rules' );
				// if(!is_admin()) {   var_dump($rules );  }
				
				
				// check if our rules are not yet included
				if ( false ){
					if (! isset( $rules[$reg] ) ) { 
						//	$this->update_option_CHOSEN('rewrite_rules_BACKUPED__AHPTP',  $rules );
						// https://developer.wordpress.org/reference/functions/add_rewrite_tag/
						add_rewrite_rule($reg,  'index.php?name=$matches[2]',	'top' );
						//
						//add_rewrite_rule('^\/(.*)(\/|?|#|)$', 'index.php?pagename=$matches[1]', 'top');
						//add_rewrite_tag( '%postname%', '(.?.+?)', 'pagename=$matches[1]' );
						flush_rewrite_rules();
					}
				}
			
					//these re defaults for new type
					// './?$' => string 'index.php?post_type=post' (length=24)
					//  './feed/(feed|rdf|rss|rss2|atom)/?$' => string 'index.php?post_type=post&feed=$matches[1]' (length=41)
					//  './(feed|rdf|rss|rss2|atom)/?$' => string 'index.php?post_type=post&feed=$matches[1]' (length=41)
					//  './page/([0-9]{1,})/?$' => string 'index.php?post_type=post&paged=$matches[1]' (length=42)
			}	
			
			public function add_rewrite_for_post(){
				add_rewrite_rule( $reg = '.+/([^/]+)/?$' ,  $match='index.php?name=$matches[1]',	$priority='top' );
			}
		*/

	
	
	
	
	
	// #############################################################
	// ########## show other cpt in Parent Page dropdown ###########
	// #############################################################
	public function cpt_parent_enabled()
	{
		return (!empty($this->opts['other_cpts_as_parent']));
	}

	public function cpt_as_parent_init()
	{
		if ($this->cpt_parent_enabled())
		{
			add_filter('page_attributes_dropdown_pages_args', [$this,'page_attrs'], 10, 2);//$args['hierarchical'] = true; 
			
			//needed for gutenberg
			if ($this->opts['other_cpts_as_parent_rest_too'])
			{
				add_filter('register_post_type_args', [$this, 'register_cpt_args_gutenberg'], 10, 2);  
				add_filter( "rest_"."post"."_query", [$this, 'response_from_gutenberg'], 10, 2 );
			}
		}
	}


	public function page_attrs($dropdown_args, $post){
		//$dropdown_args['post_type'] = 'cpt';
		//add_filter('wp_dropdown_pages', 'func22', 10, 3);
		if ($post->post_type=="post")
			add_filter( 'get_pages', [$this, 'change_cpts'], 10, 2);
		return $dropdown_args;
	}
	
	public function change_cpts( $pages, $parsed_args){
		remove_filter('get_pages', [$this, 'change_cpts'], 10, 2);
		$posts = get_posts(['post_type'=>$this->post_types_group(), 'posts_per_page'=>-1 ]);
		return $posts;
	}
	public function post_types_group(){ return array_merge(['post'],  array_filter( explode(",", $this->opts['other_cpts_as_parent']))); }
	
	
	public function register_cpt_args_gutenberg($args, $post_type){ 
		if ($post_type == 'cpt'){ $args['show_in_rest'] = true; } return $args; 
	}
	public function response_from_gutenberg( $args, $request)
	{
		if (isset($_GET['parent_exclude']) && isset($_GET['context']) && $_GET['context']=='edit')
			$args['post_type']=$this->post_types_group() ;
		return $args;
	}
	
	public function cpt_parented_query($q, $possible_post_path, $pType)
	{	
		$slug = basename($possible_post_path);
		$parent_slug = basename(dirname($slug));
		$posts = get_posts(['name'=>$slug, 'post_type'=>$pType, 'post_status'=>'publish', 'numberposts'=>1]);
		$post = false;
		if(!empty($posts[0]))
		{
			$post = $posts[0];
			$iterated_post = $post;
			while( !empty($iterated_post->post_parent) )
			{
				$iterated_post = get_post($iterated_post->post_parent);
				if (!in_array($iterated_post->post_type, $this->post_types_group()))
					return $q;
			}
		}
		if ($post){
			// create query
			//no need of $q->init();	
			$q->parse_query( ['post_type'=>[$pType]  ] ) ;  //better than $q->set('post_type', 'post');
			$q->is_home		= false; 
			//$q->is_page		= $method_is_page ? true : false; 
			$q->is_single	= true; 
			$q->is_singular	= true; 
			$q->queried_object_id=$post->ID;  
			$q->set('page_id',$post->ID);
					//add_action('wp', function (){   v($GLOBALS['wp_query']);	});
			return $q;
		}
		return $q;
	}
	// ######################################################
	
	
	
	
	
	
	
	
	
	// ====================  POST_LINK 		(WHEN  %postname% tags not available yet {after 10'th priority it's available} ) ======================// 
	//i.e:	http://example.com/post_name   OR http://example.com/%if_any_additinonal_custom_structural_tag%/post_name
	public function change_permalinks( $permalink, $post=false, $leavename=false ) { 
		$postTypes = !empty($this->opts["custom_post_types"]) ? array_filter(explode(",", $this->opts["custom_post_types"] )) : ['post'];
		foreach ($postTypes  as $each_post_type){
			if($post->post_type == $each_post_type){
				// return if %postname% tag is not present in the url:
				if ( false === strpos( $permalink, '%postname%'))  { 		return $permalink;			}
				$permalink = $this->helpers->remove_extra_slashes('/'. $this->helpers->get_parent_slugs_path($post). '/'. '%postname%' );
			}
		}
		return $permalink;
	}
	

	// =========================================================================================================================================== //	
 

	public function alert_if_not_pretty_permalinks()
	{
		if( $this->opts['hierarchy_permalinks_too'] &&  !get_option('permalink_structure') ){
			echo '<script>alert("'. __( 'You have chosen to have hierarchied permalinks for your posts, but at first you have to set correct permalinks in Settings > Permalinks, otherwise it will not work!', 'add-hierarchy-parent-to-post' ).'");</script>';
		}
	}
 
 

	// =================================== Options page ================================ //
	public function opts_page_output()
	{ 
		$this->settings_page_part("start", "first"); 
		?>

		<style> 
		body .disabled_notice{color:red; font-size: 1.1em;} 
		.checkboxes_disabled {background: pink; }
		.mylength-text {width:70px; }
		.clearboth {clear:both;}
		.displaced	{text-align:left; float: left; background:#e7e7e7; }
		body .MyLabelGroup label{display:block!important;}
		</style>

		<?php if ($this->active_tab=="Options") 
		{ 
			//if form updated
			if( $this->checkSubmission() ) 
			{ 
				$this->opts['hierarchy_permalinks_too']	= !empty($_POST[ $this->plugin_slug ]['hierarchy_permalinks_too']) ; 
				$this->opts['hierarchy_using']			= sanitize_key($_POST[ $this->plugin_slug ]['hierarchy_using']) ; 
				$this->opts['custom_post_types']		= trim(sanitize_text_field($_POST[ $this->plugin_slug ]['custom_post_types'])) ; 
				$this->opts['other_cpts_as_parent']		= trim(sanitize_text_field($_POST[ $this->plugin_slug ]['other_cpts_as_parent'])) ; 
				$this->opts['other_cpts_as_parent_rest_too']= !empty($_POST[ $this->plugin_slug ]['other_cpts_as_parent_rest_too']) ; 
				$this->opts['last_update_time']			= time() ;  // need only for flush rules
				$this->update_opts(); 
				$this->flush_rules_checkmark(true);
			}
			?>

			<?php //_e('(Note: in most cases, who want to have a hierarchy structure for the site, it\'s better to use <code>Custom Post Type</code> (there are other plugins for it), because <code>Custom Post</code> Type has native support for hierarchy and ideally, it\s better then using default <code>Post</code> Type as hierarchy. However, if you are sure you need this our plugin, go on... )', 'add-hierarchy-parent-to-post'); ?>
			<i><?php _e('(Note: This plugin is experimental in it\'s nature, as it modifies the WordPress query for posts and is not thoroughly integrated in core behavior. So, take this plugin as an experimental plugin.)', 'add-hierarchy-parent-to-post'); ?></i>

			<form class="mainForm" method="post" action="">

			<table class="form-table">
				<tr class="def hierarchical">
					<th scope="row">
						<?php _e('Add Dropdown in Post-Editor', 'add-hierarchy-parent-to-post'); ?>
					</th>
					<td>
						<fieldset>
							<p class="checkboxes_disabled">
								<label>
									<input disabled="disabled" type="radio" value="0" ><?php _e( 'No', 'add-hierarchy-parent-to-post' );?>
								</label>
								<label>
									<input disabled="disabled" type="radio" value="1" checked="checked"><?php _e( 'Yes', 'add-hierarchy-parent-to-post' );?>
								</label>
							</p>
							<p class="description">
							<?php _e('Ability to choose parent page, like on <a href="'.$this->helpers->baseURL.'/assets/media/parent-choose.png" title="sreenshot" target="_blank">this screenshot</a>. Without this field, plugin can\'t work at all, so the option is not changeable. This option guarantees that wordpress native functions can correctly determine the child-parent post relations (So, posts can have "parent" field set in database). However, that doesnt guarentee the HIERARCHIED URLS will work - that is another subject (see below).', 'add-hierarchy-parent-to-post'); ?>
							</p>
						</fieldset>
					</td>
				</tr>
				
				<tr class="def hierarchical">
					<th scope="row">
						<?php _e('Make post links hierarchied', 'add-hierarchy-parent-to-post'); ?>
					</th>
					<td>
						<fieldset>
							<div class="">
								<p>
									<label>
										<input name="<?php echo $this->plugin_slug;?>[hierarchy_permalinks_too]" type="radio" value="0" <?php checked(!$this->opts['hierarchy_permalinks_too']); ?>><?php _e( 'No', 'add-hierarchy-parent-to-post' );?>
									</label>
									<label>
										<input name="<?php echo $this->plugin_slug;?>[hierarchy_permalinks_too]" type="radio" value="1" <?php checked($this->opts['hierarchy_permalinks_too']); ?>><?php _e( 'Yes', 'add-hierarchy-parent-to-post' );?>
									</label>
								</p>
								<p class="description">
								<?php _e('Do you also want that hierarchied posts had hierarchied URL also? i.e. <code>site.com/parent/child</code> instead of <code>site.com/child</code>  (Note, in "Settings&gt;Permalinks" the structure should contain <code>%postname%</code>, otherwise this won\'t work).', 'add-hierarchy-parent-to-post'); ?>
								</p>
								<p class="displaced hierarchyMethodDesc">
									<legend>
										<?php _e( 'Which method should be used for that:', 'add-hierarchy-parent-to-post' );?> 
									</legend>
									<span class="MyLabelGroup">
										<label <?php //$this->pro_field();?>><input name="<?php echo $this->plugin_slug;?>[hierarchy_using]" type="radio" value="query_post" <?php checked($this->opts['hierarchy_using'], 'query_post');?> /> <?php _e('Post Load', 'add-hierarchy-parent-to-post');?> <?php _e( '<b>(Recommended method)</b> - preferred way.', 'add-hierarchy-parent-to-post' );?></label>
										<label> <input name="<?php echo $this->plugin_slug;?>[hierarchy_using]" type="radio" value="modify_post_obj" <?php checked($this->opts['hierarchy_using'], 'modify_post_obj');?> /> <?php _e('Query & Object Modification', 'add-hierarchy-parent-to-post');?> <?php _e( '<b>(Non-Recommended method)</b> This method is experimental and unstable, because "Rewrite Rules" are modified for post, which migh cause other links to break (like pages or categories, or their feed, rss, attachements or etc... All of side-affects are undefined at this moments).', 'add-hierarchy-parent-to-post' );?></label>
									</span>
								</p>
							</div>
							<div class="clearboth"></div>
							<p class="description disabled_notice">
								<?php //_e('UPDATE 12/01/2018<br/>Note,you see how limited capabilities does this plugin have. I couldn\'t find any better solution yet. So, for more reliability, you might also want to check <a href="https://wordpress.org/plugins/remove-base-slug-from-custom-post-type-url/" target="_blank">Remove Base Slug from Custom Post Type</a>, where you should create a new custom post type (name whatever you want) and then remove slug from that custom-post-type, and they will function like native "posts", but with better flexibility for hierarchy  (the only drawback of using custom post types instead of native "post" types, is that some kind of 3rd party themes and plugins developers doesnt always (unfortunately!) include the support for custom post types and they only support native "page" or "post". However, if that\s not a problem for your case, then that plugin will help you).', 'add-hierarchy-parent-to-post'); ?> 
							</p>
						</fieldset>
					</td>
				</tr>
				
				
				
				
				
				
				
				<tr class="def hierarchical">
					<th scope="row">
						<?php _e('Enable "Parent Page" capability for other Custom-Post-Types too (if they does not have support already)', 'add-hierarchy-parent-to-post'); ?>
					</th>
					<td <?php //$this->pro_field();?>>
						<fieldset>
							<div class="clearboth"></div>
							<div class="">
								<label for="custom_post_types">
									<?php //_e('Add hierarchy to <b>Custom Post Types</b>:', 'add-hierarchy-parent-to-post');?>
								</label>
								<input name="<?php echo $this->plugin_slug;?>[custom_post_types]" id="custom_post_types" class="regular-text" type="text" placeholder="<?php _e('book, fruits, news ...', 'add-hierarchy-parent-to-post');?>" value="<?php echo $this->opts['custom_post_types']; ?>" > 
								<p class="description">
								<?php _e('(You can insert multiple CPT base names, comma delimeted)', 'add-hierarchy-parent-to-post');?>
								</p>
							</div>
							
						</fieldset>
					</td>
				</tr>
				
				
				
				<tr class="def hierarchical">
					<td colspan=2></td>
				</tr>
				
				<tr class="def hierarchical">
					<th scope="row">
						<span style="color:red; font-weight:bold;"><?php _e('EXPERIMENTAL!', 'add-hierarchy-parent-to-post');?></span> <br/><?php _e('Use other Custom-Post-Type items in "Parent Page" list on posts edit page (note, that Custom-Post-Type should have "hierarchical" mode)', 'add-hierarchy-parent-to-post'); ?>
					</th>
					<td <?php //$this->pro_field();?>>
						<fieldset>
							<div class="clearboth"></div>
							<div class="">
								<label for="other_cpts_as_parent">
									<?php //_e('Add hierarchy to <b>Custom Post Types</b>:', 'add-hierarchy-parent-to-post');?>
								</label>
								<input name="<?php echo $this->plugin_slug;?>[other_cpts_as_parent]"  class="regular-text" type="text" placeholder="<?php _e('book, fruit, ...', 'add-hierarchy-parent-to-post');?>" value="<?php echo $this->opts['other_cpts_as_parent']; ?>" > 
								<p class="description">
								<?php _e('(You can insert multiple CPT base names, comma delimeted. To disable, leave empty)', 'add-hierarchy-parent-to-post');?>
								</p>
									
									
								<p class="description">
								<br/><?php _e('Enable in gutenberg-editor too', 'add-hierarchy-parent-to-post');?> <input name="<?php echo $this->plugin_slug;?>[other_cpts_as_parent_rest_too]" class="regular-text" type="checkbox" value="1" <?php checked($this->opts['other_cpts_as_parent_rest_too']);?> /> 
								<?php _e('(This enables Custom-Post-Type to be used in wp-rest-api. This option has not been thoroughly tested if it conflicts any other wp-rest-api query, so use at your own responsibility.)', 'add-hierarchy-parent-to-post');?> 
								</p>
							</div>
							
						</fieldset>
					</td>
				</tr>
				
				
				<tr class="def"> 
					<td colspan="2">
						<p class="description">
							<?php echo sprintf( __( 'Note! Everytime you update settings on this page, you have then to click once "Save" button in the <a href="%s" target="_blank">Permalinks</a> page! Then on the front-end, refresh page once (with clicking <code>Ctrl+F5</code>) and new rules will start work. After that, also check if your link works correctly (feed,rss,attachments and categories).', 'add-hierarchy-parent-to-post' ), (is_network_admin() ? 'javascript:alert(\'You should go to specific sub-site permalink settings\'); void(0);' : admin_url("options-permalink.php"))  ) ;?> 
						</p>
					</td>
				</tr>
			</table>
			
			<?php $this->nonceSubmit(); ?>

			</form>

			<script>
			PuvoxLibrary.radiobox_onchange_hider('input[name=add-hierarchy-parent-to-post\\[hierarchy_permalinks_too\\]]',  '0',  '.hierarchyMethodDesc');
			</script>
		<?php 
		}
		

		$this->settings_page_part("end", "");
	} 





  } // End Of Class

  $GLOBALS[__NAMESPACE__] = new PluginClass();

} // End Of NameSpace


 
?>