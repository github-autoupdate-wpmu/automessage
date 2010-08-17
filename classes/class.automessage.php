<?php

class automessage {

	var $build = 5;

	// Our own link to the database class - using this means we can easily switch db libraries in just this class if required
	var $db;

	// The tables used by this plugin
	var $tables = array('am_actions', 'am_schedule', 'am_queue');

	// Table links
	var $am_actions;
	var $am_schedule;
	var $am_queue;

	// Change this to increase or decrease the number of messages to process in any run
	var $processlimit = 250;

	function __construct() {

		global $wpdb, $blog_id;

		// Link to the database class
		$this->db =& $wpdb;

		// Set up the table variables
		foreach($this->tables as $table) {
			$this->$table = automessage_db_prefix($this->db, $table);
		}

		// Installation functions
		$installed = get_automessage_option('automessage_installed', false);
		if($installed != $this->build) {
			$this->install();
		}

		add_action( 'init', array($this, 'initialise_plugin'));

		add_action('admin_menu', array(&$this,'setup_menu'), 100);

		add_action('load-toplevel_page_automessage', array(&$this, 'add_admin_header_automessage_dash'));
		add_action('load-automessages_page_automessage_blogadmin', array(&$this, 'add_admin_header_automessage_blogadmin'));
		add_action('load-automessages_page_automessage_useradmin', array(&$this, 'add_admin_header_automessage_useradmin'));

		add_action('init', array(&$this,'setup_listeners'));

		add_action( 'automessage_dashboard_left', array(&$this, 'dashboard_news') );

		if($blog_id == 1 || !is_multisite()) {
			// All the following actions we only want on the main blog

			// Cron actions
			add_filter( 'cron_schedules', array(&$this, 'add_schedules') );
			// Cron actions
			add_action('process_automessage_hook', 'process_automessage');

			// Rewrites
			add_action('generate_rewrite_rules', array(&$this, 'add_rewrite'));
			add_filter('query_vars', array(&$this, 'add_queryvars'));

			// Set up api object to enable processing by other plugins
			add_action('pre_get_posts', array(&$this, 'process_unsubscribe_action') );

		}

	}

	function __destruct() {
		return true;
	}

	function automessage() {
		$this->__construct();
	}

	function initialise_plugin() {

		$role = get_role( 'administrator' );
		if( !$role->has_cap( 'read_automessage' ) ) {
			// Administrator
			$role->add_cap( 'read_automessage' );
			$role->add_cap( 'edit_automessage' );
			$role->add_cap( 'delete_automessage' );
			$role->add_cap( 'publish_automessages' );
			$role->add_cap( 'edit_automessages' );
			$role->add_cap( 'edit_others_automessages' );
		}

		// Register the property post type
		register_post_type('automessage', array(	'singular_label' => __('Messages','automessage'),
													'label' => __('Messages', 'automessage'),
													'public' => false,
													'show_ui' => false,
													'publicly_queryable' => false,
													'exclude_from_search' => true,
													'hierarchical' => true,
													'capability_type' => 'automessage',
													'edit_cap' => 'edit_automessage',
													'edit_type_cap' => 'edit_automessages',
													'edit_others_cap' => 'edit_others_automessages',
													'publish_others_cap' => 'publish_automessages',
													'read_cap' => 'read_automessage',
													'delete_cap' => 'delete_automessage'
													)
												);
	}

	function setup_menu() {

		global $menu, $admin_page_hooks;

		add_menu_page(__('Automessage','automessage'), __('Automessage','automessage'), 'edit_automessage',  'automessage', array(&$this,'handle_dash_panel'));

		// Fix WP translation hook issue
		if(isset($admin_page_hooks['automessages'])) {
			$admin_page_hooks['automessages'] = 'automessages';
		}

		// Add the sub menu
		if(function_exists('is_site_admin') && is_site_admin()) {
			add_submenu_page('automessage', __('Edit Blog Messages','automessage'), __('Blog Level Messages','automessage'), 'edit_automessage', "automessage_blogadmin", array(&$this,'handle_blogmessageadmin_panel'));
		}

		add_submenu_page('automessage', __('Edit User Messages','automessage'), __('User Level Messages','automessage'), 'edit_automessage', "automessage_useradmin", array(&$this,'handle_usermessageadmin_panel'));

	}

	function install($install = false) {

		if($install == false) {

			// Create or update the tables

			$sql = "CREATE TABLE $this->am_actions (
			  id bigint(20) NOT NULL auto_increment,
			  level varchar(10) default NULL,
			  action varchar(150) default NULL,
			  title varchar(250) default NULL,
			  description text,
			  PRIMARY KEY  (id),
			  KEY level (level),
			  KEY action (action)
			)";
			$this->db->query($sql);

			$sql = "CREATE TABLE $this->am_schedule (
			  id bigint(20) NOT NULL auto_increment,
			  system_id bigint(20) NOT NULL default '0',
			  site_id bigint(20) NOT NULL default '0',
			  blog_id bigint(20) NOT NULL default '0',
			  action_id bigint(20) default NULL,
			  subject varchar(250) default NULL,
			  message text,
			  period int(11) default '0',
			  timeperiod varchar(50) default 'day',
			  pause tinyint(4) default '0',
			  PRIMARY KEY  (id),
			  KEY system_id (system_id),
			  KEY site_id (site_id),
			  KEY blog_id (blog_id),
			  KEY action_id (action_id)
			)";
			$this->db->query($sql);

			$sql = "CREATE TABLE $this->am_queue (
			  id bigint(20) NOT NULL auto_increment,
			  schedule_id bigint(20) default NULL,
			  runon bigint(20) default NULL,
			  sendtoemail varchar(150) default NULL,
			  user_id bigint(20) NOT NULL default '0',
			  blog_id bigint(20) default '0',
			  site_id bigint(20) default '0',
			  PRIMARY KEY  (id),
			  KEY action_id (schedule_id),
			  KEY runon (runon),
			  KEY user_id (user_id),
			  KEY blog_id (blog_id),
			  KEY site_id (site_id)
			)";
			$this->db->query($sql);

			$this->db->insert($this->am_actions, array("level" => "site", "action" => "wpmu_new_blog", "title" => "Create new blog"));
			$this->db->insert($this->am_actions, array("level" => "blog", "action" => "wpmu_new_user", "title" => "Create new user"));

		}

		update_automessage_option('automessage_installed', $this->build);

		$this->flush_rewrite();

	}

	function uninstall() {

	}

	function add_admin_header_automessage_core() {

		global $action, $page;

		wp_reset_vars( array('action', 'page') );

		wp_enqueue_style( 'automessageadmincss', automessage_url('css/automessage.css'), array(), $this->build );
	}

	function add_admin_header_automessage_dash() {

		global $action, $page;

		$this->add_admin_header_automessage_core();
	}

	function add_admin_header_automessage_blogadmin() {

		global $action, $page;

		$this->add_admin_header_automessage_core();

		switch($action) {

			case 'addaction':
						check_admin_referer('add-action');
						if($this->add_action()) {
							wp_safe_redirect( add_query_arg( 'msg', 1, wp_get_original_referer() ) );
						} else {
							wp_safe_redirect( add_query_arg( 'msg', 2, wp_get_original_referer() ) );
						}
						break;
			case 'pauseaction':
						$id = addslashes($_GET['id']);
						$this->set_pause($id, true);
						wp_safe_redirect( add_query_arg( 'msg', 3, wp_get_original_referer() ) );
						break;
			case 'unpauseaction':
						$id = addslashes($_GET['id']);
						$this->set_pause($id, false);
						wp_safe_redirect( add_query_arg( 'msg', 4, wp_get_original_referer() ) );
						break;
			case 'allmessages':
						check_admin_referer($_POST['actioncheck']);
						if(isset($_POST['allaction_delete'])) {
							if(isset($_POST['allschedules'])) {
								$allsscheds = $_POST['allschedules'];
								foreach ($allsscheds as $as) {
									$this->delete_action($as);
								}
								wp_safe_redirect( add_query_arg( 'msg', 12, wp_get_original_referer() ) );
							} else {
								wp_safe_redirect( add_query_arg( 'msg', 5, wp_get_original_referer() ) );
							}
						}
						if(isset($_POST['allaction_pause'])) {
							if(isset($_POST['allschedules'])) {
								$allsscheds = $_POST['allschedules'];
								foreach ($allsscheds as $as) {
									$this->set_pause($as, true);
								}
								wp_safe_redirect( add_query_arg( 'msg', 6, wp_get_original_referer() ) );
							} else {
								wp_safe_redirect( add_query_arg( 'msg', 7, wp_get_original_referer() ) );
							}
						}
						if(isset($_POST['allaction_unpause'])) {
							if(isset($_POST['allschedules'])) {
								$allsscheds = $_POST['allschedules'];
								foreach ($allsscheds as $as) {
									$this->set_pause($as, false);
								}
								wp_safe_redirect( add_query_arg( 'msg', 8, wp_get_original_referer() ) );
							} else {
								wp_safe_redirect( add_query_arg( 'msg', 9, wp_get_original_referer() ) );
							}
						}
						if(isset($_POST['allaction_process'])) {
							if(isset($_POST['allschedules'])) {
								$allsscheds = $_POST['allschedules'];
								foreach ($allsscheds as $as) {
									$this->force_process($as);
								}
								wp_safe_redirect( add_query_arg( 'msg', 10, wp_get_original_referer() ) );
							} else {
								wp_safe_redirect( add_query_arg( 'msg', 11, wp_get_original_referer() ) );
							}
						}
						$this->handle_messageadmin_panel();
						break;
			case 'deleteaction':
						$id = addslashes($_GET['id']);
						$this->delete_action($id);
						wp_safe_redirect( add_query_arg( 'msg', 12, wp_get_original_referer() ) );
						break;
			case 'editaction':
						$id = addslashes($_GET['id']);
						$this->edit_action_form($id);
						break;
			case 'updateaction':
						check_admin_referer('update-action');
						$this->update_action();
						wp_safe_redirect( add_query_arg( 'msg', 13, wp_get_original_referer() ) );
						break;
			case 'processaction':
						$id = addslashes($_GET['id']);
						$this->force_process($id);
						wp_safe_redirect( add_query_arg( 'msg', 14, wp_get_original_referer() ) );
						break;

			default:	// do nothing and carry on
						break;

		}


	}

	function add_admin_header_automessage_useradmin() {

		global $action, $page;

		$this->add_admin_header_automessage_core();

		switch($action) {

			case 'addaction':
						check_admin_referer('add-action');
						if($this->add_action()) {
							wp_safe_redirect( add_query_arg( 'msg', 1, wp_get_original_referer() ) );
						} else {
							wp_safe_redirect( add_query_arg( 'msg', 2, wp_get_original_referer() ) );
						}
						break;
			case 'pauseaction':
						$id = addslashes($_GET['id']);
						$this->set_pause($id, true);
						wp_safe_redirect( add_query_arg( 'msg', 3, wp_get_original_referer() ) );
						break;
			case 'unpauseaction':
						$id = addslashes($_GET['id']);
						$this->set_pause($id, false);
						wp_safe_redirect( add_query_arg( 'msg', 4, wp_get_original_referer() ) );
						break;
			case 'allmessages':
						check_admin_referer($_POST['actioncheck']);
						if(isset($_POST['allaction_delete'])) {
							if(isset($_POST['allschedules'])) {
								$allsscheds = $_POST['allschedules'];
								foreach ($allsscheds as $as) {
									$this->delete_action($as);
								}
								wp_safe_redirect( add_query_arg( 'msg', 12, wp_get_original_referer() ) );
							} else {
								wp_safe_redirect( add_query_arg( 'msg', 5, wp_get_original_referer() ) );
							}
						}
						if(isset($_POST['allaction_pause'])) {
							if(isset($_POST['allschedules'])) {
								$allsscheds = $_POST['allschedules'];
								foreach ($allsscheds as $as) {
									$this->set_pause($as, true);
								}
								wp_safe_redirect( add_query_arg( 'msg', 6, wp_get_original_referer() ) );
							} else {
								wp_safe_redirect( add_query_arg( 'msg', 7, wp_get_original_referer() ) );
							}
						}
						if(isset($_POST['allaction_unpause'])) {
							if(isset($_POST['allschedules'])) {
								$allsscheds = $_POST['allschedules'];
								foreach ($allsscheds as $as) {
									$this->set_pause($as, false);
								}
								wp_safe_redirect( add_query_arg( 'msg', 8, wp_get_original_referer() ) );
							} else {
								wp_safe_redirect( add_query_arg( 'msg', 9, wp_get_original_referer() ) );
							}
						}
						if(isset($_POST['allaction_process'])) {
							if(isset($_POST['allschedules'])) {
								$allsscheds = $_POST['allschedules'];
								foreach ($allsscheds as $as) {
									$this->force_process($as);
								}
								wp_safe_redirect( add_query_arg( 'msg', 10, wp_get_original_referer() ) );
							} else {
								wp_safe_redirect( add_query_arg( 'msg', 11, wp_get_original_referer() ) );
							}
						}
						$this->handle_messageadmin_panel();
						break;
			case 'deleteaction':
						$id = addslashes($_GET['id']);
						$this->delete_action($id);
						wp_safe_redirect( add_query_arg( 'msg', 12, wp_get_original_referer() ) );
						break;
			case 'editaction':
						$id = addslashes($_GET['id']);
						$this->edit_action_form($id);
						break;
			case 'updateaction':
						check_admin_referer('update-action');
						$this->update_action();
						wp_safe_redirect( add_query_arg( 'msg', 13, wp_get_original_referer() ) );
						break;
			case 'processaction':
						$id = addslashes($_GET['id']);
						$this->force_process($id);
						wp_safe_redirect( add_query_arg( 'msg', 14, wp_get_original_referer() ) );
						break;

			default:	// do nothing and carry on
						break;

		}


	}

	function show_admin_messages() {

		global $action, $page, $msg;

		$this->add_admin_header_automessage_core();

		if(isset($_GET['msg'])) {

			$msg = (int) $_GET['msg'];

			switch($msg) {
				case 1:		echo '<div id="message" class="updated fade"><p>' . __('Your action has been added to the schedule.', 'automessage') . '</p></div>';
							break;

				case 2:		echo '<div id="message" class="updated fade"><p>' . __('Your action could not be added.', 'automessage') . '</p></div>';
							break;

				case 3:		echo '<div id="message" class="updated fade"><p>' . __('The scheduled action has been paused', 'automessage') . '</p></div>';
							break;

				case 4:		echo '<div id="message" class="updated fade"><p>' . __('The scheduled action has been unpaused', 'automessage') . '</p></div>';
							break;

				case 5:		echo '<div id="message" class="updated fade"><p>' . __('Please select an action to delete', 'automessage') . '</p></div>';
							break;

				case 6:		echo '<div id="message" class="updated fade"><p>' . __('The scheduled actions have been paused', 'automessage') . '</p></div>';
							break;

				case 7:		echo '<div id="message" class="updated fade"><p>' . __('Please select an action to pause', 'automessage') . '</p></div>';
							break;

				case 8:		echo '<div id="message" class="updated fade"><p>' . __('The scheduled actions have been unpaused', 'automessage') . '</p></div>';
							break;

				case 9:		echo '<div id="message" class="updated fade"><p>' . __('Please select an action to unpause', 'automessage') . '</p></div>';
							break;

				case 10:	echo '<div id="message" class="updated fade"><p>' . __('The scheduled actions have been processed', 'automessage') . '</p></div>';
							break;

				case 11:	echo '<div id="message" class="updated fade"><p>' . __('Please select an action to process', 'automessage') . '</p></div>';
							break;

				case 12:	echo '<div id="message" class="updated fade"><p>' . __('The scheduled action has been deleted', 'automessage') . '</p></div>';
							break;

				case 13:	echo '<div id="message" class="updated fade"><p>' . __('The scheduled action has been updated', 'automessage') . '</p></div>';
							break;

				case 14:	echo '<div id="message" class="updated fade"><p>' . __('The scheduled action has been processed', 'automessage') . '</p></div>';
							break;

			}

			$_SERVER['REQUEST_URI'] = remove_query_arg(array('msg'), $_SERVER['REQUEST_URI']);
		}
	}

	function setup_listeners() {

		global $blog_id;

		// This function will add all of the actions that are setup

		if(is_multisite()) {
			add_action('wpmu_new_blog',array(&$this,'add_blog_message'), 1, 2);
			add_action('wpmu_new_user',array(&$this,'add_user_message'), 1, 1);
		} else {
			add_action('user_register', array(&$this,'add_user_message'), 1, 1);
		}

		do_action('automessage_addlisteners');

		// Cron action
		if($blog_id == 1) {
			// Only shedule the events IF we want a global cron, or we are on the specified blog
			if ( !wp_next_scheduled('process_automessage_hook')) {
				wp_schedule_event(time(), 'fourdaily', 'process_automessage_hook');
			}
		}

	}

	function send_message($message, $user, $blog_id = 0, $site_id = 0) {

		if(!empty($user->user_email) && validate_email($user->user_email, false)) {

			$replacements = array(	"/%blogname%/" 	=> 	get_blog_option($blog_id, 'blogname'),
									"/%blogurl%/"	=>	get_blog_option($blog_id, 'home'),
									"/%username%/"	=>	$user->user_login,
									"/%usernicename%/"	=>	$user->user_nicename
								);

			if(function_exists('get_site_details')) {
				$site = get_site_details($site_id);
				$replacements['/%sitename%/'] = $site->sitename;
				$replacements['/%siteurl%/'] = 'http://' . $site->domain . $site->path;
			} else {
				$site = $this->db->get_row( $this->db->prepare("SELECT * FROM {$this->db->site} WHERE id = %d", $site_id));
				$replacements['/%sitename%/'] = $this->db->get_var( $this->db->prepare("SELECT meta_value FROM {$this->db->sitemeta} WHERE meta_key = 'site_name' AND site_id = %d", $site_id) );
				$replacements['/%siteurl%/'] = 'http://' . $site->domain . $site->path;
			}

			$replacements = apply_filters('automessage_replacements', $replacements);

			if(!empty($message->message)) {
				$subject = stripslashes($message->subject);
				$msg = stripslashes($message->message);

				// Add in the unsubscribe text at the bottom of the message
				$msg .= "\n\n"; // Two blank lines
				$msg .= "-----\n"; // Footer marker
				$msg .= __('To stop receiving messages from %sitename% click on the following link: %siteurl%unsubscribe/','automessage');
				// Add in the user id
				$msg .= md5($message->user_id . '16224');

				$find = array_keys($replacements);
				$replace = array_values($replacements);

				$msg = preg_replace($find, $replace, $msg);
				$subject = preg_replace($find, $replace, $subject);

				// Set up the from address
				$header = 'From: "' . $replacements['/%sitename%/'] . '" <noreply@' . $site->domain . '>';
				$res = @wp_mail( $user->user_email, $subject, $msg, $header );

			}

		}

	}

	function schedule_message($action, $user_id, $blog_id = 0, $site_id = 0) {

		// Get the lowest day scheduled action for add site
		$sql = "select s.* from {$this->am_schedule} as s, {$this->am_actions} as a WHERE
		s.action_id = a.id AND a.action = %s AND s.pause = 0 ORDER BY period, timeperiod ASC
		LIMIT 0,2";

		$sched = $this->db->get_results( $this->db->prepare($sql, $action), OBJECT );

		if($sched) {
			$user = get_userdata($user_id);

			foreach($sched as $s) {
				if($s->period == 0) {
					// If the timeperiod is 0 - then we need to send this immediately and
					// get the next one for the schedule
					$this->send_message($s, $user, $blog_id, $site_id);
				} else {
					// Otherwise we add the person to the schedule for later processing
					$runon = strtotime("+ $s->period $s->timeperiod");
					$this->db->insert($this->am_queue, array("schedule_id" => $s->id, "runon" => $runon, "user_id" => $user->ID, "site_id" => $site_id, "blog_id" => $blog_id, "sendtoemail" => $user->user_email));
					break;
				}
			}
		}

	}

	function add_blog_message($blog_id, $user_id) {
		// This function will add a scheduled item to the blog actions
		global $current_site;

		if(is_numeric($user_id)) {
			$action = 'wpmu_new_blog';

			$this->schedule_message($action, $user_id, $blog_id, $current_site->id);
		}
	}

	function add_user_message($user_id) {
		// This function will add a scheduled item to the user actions
		global $blog_id;

		if(!empty($user_id)) {

			$user_id = (int) $user_id;

			$action = 'wpmu_new_user';

			$this->schedule_message($action, $user_id, $blog_id, 1);
		}
	}




	function get_sitelevel_schedule() {

		global $current_site;

		$sql = $this->db->prepare("SELECT s.*, a.title FROM {$this->am_schedule} AS s, {$this->am_actions} AS a WHERE s.action_id = a.id AND a.level = %s AND s.site_id = %d ORDER BY action_id, timeperiod, period", 'site', $current_site->id);

		$results = $this->db->get_results($sql, OBJECT);

		if($results) {

			foreach($results as $key => $value) {
				$results[$key]->queued = $this->db->get_var( $this->db->prepare("SELECT count(*) FROM {$this->am_queue} as q WHERE q.schedule_id = %d", $value->id) );
			}

			return $results;
		} else {
			return false;
		}

	}

	function get_bloglevel_schedule() {

		global $current_site;

		$sql = $this->db->prepare("SELECT s.*, a.title FROM {$this->am_schedule} AS s, {$this->am_actions} AS a WHERE s.action_id = a.id AND a.level = %s AND s.blog_id = %d ORDER BY action_id, timeperiod, period", 'blog', $current_site->blog_id);

		$results = $this->db->get_results($sql, OBJECT);

		if($results) {

			foreach($results as $key => $value) {
				$results[$key]->queued = $this->db->get_var( $this->db->prepare("SELECT count(*) FROM {$this->am_queue} as q WHERE q.schedule_id = %d", $value->id) );
			}

			return $results;
		} else {
			return false;
		}

	}

	function get_available_actions($levels = array('site', 'blog')) {

		if(!is_array($levels)) {
			return false;
		}

		$sql = $this->db->prepare("SELECT * FROM {$this->am_actions} WHERE level IN ('" . implode("','", $levels) . "')");

		$actions = $this->db->get_results($sql, OBJECT);

		if($actions) {
			return $actions;
		} else {
			return false;
		}

	}

	function get_action($id) {

		$sql = $this->db->prepare("SELECT * FROM {$this->am_schedule} WHERE id = %d", $id);

		$results = $this->db->get_row($sql);

		if($results) {
			return $results;
		} else {
			return false;
		}

	}

	function add_action() {

		global $current_site;

		$system_id = apply_filters('get_system_id', 1);
		$site_id = $current_site->id;
		$blog_id = $current_site->blog_id;


		$action = $_POST['action'];
		$subject = $_POST['subject'];
		$message = $_POST['message'];

		$period = $_POST['period'];
		$timeperiod = $_POST['timeperiod'];

		$this->db->insert($this->am_schedule, array("system_id" => $system_id, "site_id" => $site_id, "blog_id" => $blog_id, "action_id" => $action, "subject" => $subject, "message" => $message, "period" => $period, "timeperiod" => $timeperiod));

		return $this->db->insert_id;
	}

	function delete_action($scheduleid) {

		if($scheduleid) {
			$this->db->query( $this->db->prepare("DELETE FROM {$this->am_schedule} WHERE id = %d", $scheduleid));
		}

	}

	function update_action() {

		$id = $_POST['id'];

		$system_id = $_POST['system_id'];
		$site_id = $_POST['site_id'];
		$blog_id = $_POST['blog_id'];


		$action = $_POST['action'];
		$subject = $_POST['subject'];
		$message = $_POST['message'];

		$period = $_POST['period'];
		$timeperiod = $_POST['timeperiod'];

		$this->db->update($this->am_schedule, array("system_id" => $system_id, "site_id" => $site_id, "blog_id" => $blog_id, "action_id" => $action, "subject" => $subject, "message" => $message, "period" => $period, "timeperiod" => $timeperiod), array("id" => $id));

	}

	function set_pause($scheduleid, $pause = true) {

		if($pause) {
			$this->db->update($this->am_schedule, array("pause" => 1), array("id" => $scheduleid));
		} else {
			$this->db->update($this->am_schedule, array("pause" => 0), array("id" => $scheduleid));
		}


	}

	function edit_action_form($id) {

		$page = addslashes($_GET['page']);

		$editing = $this->get_action($id);

		if(!$editing) {
			echo __('Could not find the action, please check the available message list.','automessage');
		}

		echo "<div class='wrap'>";
		echo "<h2>" . __('Edit Action', 'automessage') . "</h2>";

		echo '<form method="post" action="?page=' . $page . '&amp;action=updateaction">';
		echo '<input type="hidden" name="id" value="' . $editing->id . '" />';

		echo '<input type="hidden" name="system_id" value="' . $editing->system_id . '" />';
		echo '<input type="hidden" name="site_id" value="' . $editing->site_id . '" />';
		echo '<input type="hidden" name="blog_id" value="' . $editing->blog_id . '" />';

		wp_nonce_field('update-action');
		echo '<table class="form-table">';
		echo '<tr class="form-field form-required">';
		echo '<th style="" scope="row" valign="top">' . __('Action','automessage') . '</th>';
		echo '<td valign="top">';

		$filter = array();
		if(function_exists('is_site_admin') && is_site_admin()) {
			$filter[] = 'site';
		}
		$filter[] = 'blog';

		$actions = $this->get_available_actions($filter);

		if($actions) {

			echo '<select name="action" style="width: 40%;">';

			$lastlevel = "";

			foreach($actions as $action) {
				if($lastlevel != $action->level) {
					if($lastlevel != "") {
						echo '</optgroup>';
					}
					$lastlevel = $action->level;
					echo '<optgroup label="';
					switch($lastlevel) {
						case "site": 	echo "Site level actions";
										break;
						case "blog": 	echo "Blog level actions";
										break;
					}
					echo '">';
				}
				echo '<option value="' . $action->id . '"';
				if($editing->action_id == $action->id) echo ' selected="selected" ';
				echo '>';
				echo wp_specialchars($action->title);
				echo '</option>';
			}

			echo '</select>';

		}

		echo '</td>';
		echo '</tr>';

		echo '<tr class="form-field form-required">';
		echo '<th style="" scope="row" valign="top">' . __('Message delay','automessage') . '</th>';
		echo '<td valign="top">';

		echo '<select name="period" style="width: 40%;">';
		for($n = 0; $n <= 31; $n++) {
			echo "<option value='$n'";
			if($editing->period == $n)  echo ' selected="selected" ';
			echo ">";
			switch($n) {
				case 0: 	echo __("Send immediately", 'automessage');
							break;
				case 1: 	echo __("1 day", 'automessage');
							break;
				default:	echo sprintf(__('%d days','automessage'),$n);
			}
			echo "</option>";
		}
		echo '</select>';
		echo '<input type="hidden" name="timeperiod" value="' . $editing->timeperiod . '" />';
		echo '</td>';
		echo '</tr>';

		echo '<tr class="form-field form-required">';
		echo '<th style="" scope="row" valign="top">' . __('Message Subject','automessage') . '</th>';
		echo '<td valign="top"><input name="subject" type="text" size="50" title="' . __('Message subject') . '" style="width: 50%;" value="' . htmlentities(stripslashes($editing->subject),ENT_QUOTES, 'UTF-8') . '" /></td>';
		echo '</tr>';

		echo '<tr class="form-field form-required">';
		echo '<th style="" scope="row" valign="top">' . __('Message','automessage') . '</th>';
		echo '<td valign="top"><textarea name="message" style="width: 50%; float: left;" rows="15" cols="40">' . htmlentities(stripslashes($editing->message),ENT_QUOTES, 'UTF-8') . '</textarea>';
		// Display some instructions for the message.
		echo '<div class="instructions" style="float: left; width: 40%; margin-left: 10px;">';
		echo __('You can use the following constants within the message body to embed database information.','automessage');
		echo '<br /><br />';
		echo '%blogname%<br />';
		echo '%blogurl%<br />';
		echo '%username%<br />';
		echo '%usernicename%<br/>';
		echo '%sitename%<br/>';
		echo "%siteurl%<br/>";

		echo '</div>';
		echo '</td>';
		echo '</tr>';

		echo '</table>';

		echo '<p class="submit">';
		echo '<input class="button" type="submit" name="go" value="' . __('Update action', 'automessage') . '" /></p>';
		echo '</form>';

		echo "</div>";
	}

	function add_action_form() {

		$page = addslashes($_GET['page']);

		echo "<div class='wrap'>";

		echo "<h2>" . __('Add Action', 'automessage') . "</h2>";

		echo "<a name='form-add-action' ></a>\n";

		echo '<form method="post" action="?page=' . $page . '&amp;action=addaction">';
		wp_nonce_field('add-action');
		echo '<table class="form-table">';
		echo '<tr class="form-field form-required" valign="top">';
		echo '<th style="" scope="row" valign="top">' . __('Action','automessage') . '</th>';
		echo '<td>';

		$filter = array();
		if(function_exists('is_site_admin') && is_site_admin()) {
			$filter[] = 'site';
		}
		$filter[] = 'blog';

		$actions = $this->get_available_actions($filter);

		if($actions) {

			echo '<select name="action" style="width: 40%;">';

			$lastlevel = "";

			foreach($actions as $action) {
				if($lastlevel != $action->level) {
					if($lastlevel != "") {
						echo '</optgroup>';
					}
					$lastlevel = $action->level;
					echo '<optgroup label="';
					switch($lastlevel) {
						case "site": 	echo "Site level actions";
										break;
						case "blog": 	echo "Blog level actions";
										break;
					}
					echo '">';
				}
				echo '<option value="' . $action->id . '">';
				echo wp_specialchars($action->title);
				echo '</option>';
			}
			echo '</select>';
		}

		echo '</td>';
		echo '</tr>';

		echo '<tr class="form-field form-required">';
		echo '<th style="" scope="row" valign="top">' . __('Message delay','automessage') . '</th>';
		echo '<td valign="top">';

		echo '<select name="period" style="width: 40%;">';
		for($n = 0; $n <= 31; $n++) {
			echo "<option value='$n'>";
			switch($n) {
				case 0: 	echo __("Send immediately", 'automessage');
							break;
				case 1: 	echo __("1 day", 'automessage');
							break;
				default:	echo sprintf(__('%d days','automessage'),$n);
			}
			echo "</option>";
		}
		echo '</select>';
		echo '<input type="hidden" name="timeperiod" value="day" />';
		echo '</td>';
		echo '</tr>';

		echo '<tr class="form-field form-required">';
		echo '<th style="" scope="row" valign="top">' . __('Message Subject','automessage') . '</th>';
		echo '<td valign="top"><input name="subject" type="text" size="50" title="' . __('Message subject') . '" style="width: 50%;" /></td>';
		echo '</tr>';

		echo '<tr class="form-field form-required">';
		echo '<th style="" scope="row" valign="top">' . __('Message','automessage') . '</th>';
		echo '<td valign="top"><textarea name="message" style="width: 50%; float: left;" rows="15" cols="40"></textarea>';
		// Display some instructions for the message.
		echo '<div class="instructions" style="float: left; width: 40%; margin-left: 10px;">';
		echo __('You can use the following constants within the message body to embed database information.','automessage');
		echo '<br /><br />';
		echo '%blogname%<br />';
		echo '%blogurl%<br />';
		echo '%username%<br />';
		echo '%usernicename%<br/>';
		echo '%sitename%<br/>';
		echo "%siteurl%<br/>";



		echo '</div>';
		echo '</td>';
		echo '</tr>';

		echo '</table>';

		echo '<p class="submit">';
		echo '<input class="button" type="submit" name="go" value="' . __('Add action', 'automessage') . '" /></p>';
		echo '</form>';



		echo "</div>";

	}

	function show_actions_list($results = false) {

		$page = addslashes($_GET['page']);

		echo '<table width="100%" cellpadding="3" cellspacing="3" class="widefat">';
		echo '<thead>';
		echo '<tr>';
		echo '<th scope="col" class="check-column"></th>';

		echo '<th scope="col">';
		echo __('Action','automessage');
		echo '</th>';

		echo '<th scope="col">';
		echo __('Time delay','automessage');
		echo '</th>';

		echo '<th scope="col">';
		echo __('Subject','automessage');
		echo '</th>';

		echo '<th scope="col">';
		echo __('Queued','automessage');
		echo '</th>';

		echo '</tr>';
		echo '</thead>';

		echo '<tbody id="the-list">';

		if($results) {
			$bgcolor = $class = '';
			$action = '';

			foreach($results as $result) {
				$class = ('alternate' == $class) ? '' : 'alternate';
				if($action != $result->action_id) {
					$title = stripslashes($result->title);
					$action = $result->action_id;
				} else {
					$title = '&nbsp;';
				}
				echo '<tr>';
				echo '<th scope="row" class="check-column">';
				echo '<input type="checkbox" id="schedule_' . $result->id . '" name="allschedules[]" value="' . $result->id .'" />';
				echo '</th>';

				echo '<th scope="row">';
				if($result->pause != 0) {
					echo __('[Paused] ','automessage');
				}
				echo $title;

				$actions = array();

				$actions[] = '<a href="?page=' . $page . '&amp;action=editaction&amp;id=' . $result->id . '" title="' . __('Edit this message','automessage') . '">' . __('Edit','automessage') . '</a>';
				if($result->pause == 0) {
					$actions[] = '<a href="?page=' . $page . '&amp;action=pauseaction&amp;id=' . $result->id . '" title="' . __('Pause this message','automessage') . '">' . __('Pause','automessage') . '</a>';
				} else {
					$actions[] = '<a href="?page=' . $page . '&amp;action=unpauseaction&amp;id=' . $result->id . '" title="' . __('Unpause this message','automessage') . '">' . __('Unpause','automessage') . '</a>';
				}
				$actions[] = '<a href="?page=' . $page . '&amp;action=processaction&amp;id=' . $result->id . '" title="' . __('Process this message','automessage') . '">' . __('Process','automessage') . '</a>';
				$actions[] = '<a href="?page=' . $page . '&amp;action=deleteaction&amp;id=' . $result->id . '" title="' . __('Delete this message','automessage') . '">' . __('Delete','automessage') . '</a>';

				echo '<div class="row-actions">';
				echo implode(' | ', $actions);
				echo '</div>';

				echo '</th>';

				echo '<th scope="row">';

				if($result->period == 0) {
					echo __('Immediate','automessage');
				} elseif($result->period == 1) {
					echo sprintf(__('%d %s','automessage'), $result->period, $result->timeperiod);
				} else {
					echo sprintf(__('%d %ss','automessage'), $result->period, $result->timeperiod);
				}

				echo '</th>';

				echo '<th scope="row">';
				echo stripslashes($result->subject);
				echo '</th>';

				echo '<th scope="row">';
				echo intval($result->queued);
				echo '</th>';

				echo '</tr>' . "\n";

			}
		} else {
			echo '<tr style="background-color: ' . $bgcolor . '">';
			echo '<td colspan="5">' . __('No actions set for this level.') . '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';

	}

	function dashboard_news() {
		global $page, $action;

		$plugin = get_plugin_data(automessage_dir('automessage.php'));

		$debug = get_automessage_option('automessage_debug', false);

		?>
		<div class="postbox " id="dashboard_right_now">
			<h3 class="hndle"><span><?php _e('Automessage','automessage'); ?></span></h3>
			<div class="inside">
				<?php
				echo "<p>";
				echo __('You are running Automessage version ','automessage') . "<strong>" . $plugin['Version'] . '</strong>';
				echo "</p>";

				echo "<p>";
				echo __('Debug mode is ','automessage') . "<strong>";
				if($debug) {
					echo __('Enabled','automessage');
				} else {
					echo __('Disabled','automessage');
				}
				echo '</strong>';
				echo "</p>";
				?>
				<br class="clear">
			</div>
		</div>
		<?php
	}

	function handle_dash_panel() {
		?>
		<div class='wrap nosubsub'>
			<div class="icon32" id="icon-index"><br></div>
			<h2><?php _e('Automessage dashboard','automessage'); ?></h2>

			<div id="dashboard-widgets-wrap">

			<div class="metabox-holder" id="dashboard-widgets">
				<div style="width: 49%;" class="postbox-container">
					<div class="meta-box-sortables ui-sortable" id="normal-sortables">
						<?php
						do_action( 'automessage_dashboard_left' );
						?>
					</div>
				</div>

				<div style="width: 49%;" class="postbox-container">
					<div class="meta-box-sortables ui-sortable" id="side-sortables">
						<?php
						do_action( 'automessage_dashboard_right' );
						?>
					</div>
				</div>

				<div style="display: none; width: 49%;" class="postbox-container">
					<div class="meta-box-sortables ui-sortable" id="column3-sortables" style="">
					</div>
				</div>

				<div style="display: none; width: 49%;" class="postbox-container">
					<div class="meta-box-sortables ui-sortable" id="column4-sortables" style="">
					</div>
				</div>
			</div>

			<div class="clear"></div>
			</div>

		</div> <!-- wrap -->
		<?php
	}

	function handle_blogmessageadmin_panel() {

		global $action, $page;

		echo "<div class='wrap'  style='position:relative;'>";
		echo "<h2>" . __('Message responses','automessage') . "</h2>";

		$this->show_admin_messages();

		echo '<ul class="subsubsub">';
		echo '<li><a href="#form-add-action" class="rbutton"><strong>' . __('Add a new action', 'automessage') . '</strong></a></li>';
		echo '</ul>';
		echo '<br clear="all" />';

		// Site level messages - if we are at a site level
		if(function_exists('is_site_admin') && is_site_admin()) {

			echo "<h3>" . __('Site level actions','automessage') . "</h3>";

			$results = $this->get_sitelevel_schedule();

			echo '<form id="form-site-list" action="?page=' . $page . '&amp;action=allmessages" method="post">';
			echo '<input type="hidden" name="page" value="' . $page . '" />';
			echo '<input type="hidden" name="actioncheck" value="allsiteactions" />';
			echo '<div class="tablenav">';
			echo '<div class="alignleft">';

			echo '<input type="submit" value="' . __('Delete') . '" name="allaction_delete" class="button-secondary delete" />';
			echo '<input type="submit" value="' . __('Pause') . '" name="allaction_pause" class="button-secondary" />';
			echo '<input type="submit" value="' . __('Unpause') . '" name="allaction_unpause" class="button-secondary" />';
			echo '&nbsp;&nbsp;<input type="submit" value="' . __('Process now') . '" name="allaction_process" class="button-secondary" />';
			wp_nonce_field( 'allsiteactions' );
			echo '<br class="clear" />';
			echo '</div>';
			echo '</div>';

			$this->show_actions_list($results);

			echo "</form>";
		}

		// Blog level messages
		echo "<h3>" . __('Blog level actions','automessage') . "</h3>";

		$results = $this->get_bloglevel_schedule();

		echo '<form id="form-site-list" action="?page=' . $page . '&amp;action=allmessages" method="post">';
		echo '<input type="hidden" name="page" value="' . $page . '" />';
		echo '<input type="hidden" name="actioncheck" value="allblogactions" />';
		echo '<div class="tablenav">';
		echo '<div class="alignleft">';

		echo '<input type="submit" value="' . __('Delete') . '" name="allaction_delete" class="button-secondary delete" />';
		echo '<input type="submit" value="' . __('Pause') . '" name="allaction_pause" class="button-secondary" />';
		echo '<input type="submit" value="' . __('Unpause') . '" name="allaction_unpause" class="button-secondary" />';
		echo '&nbsp;&nbsp;<input type="submit" value="' . __('Process now') . '" name="allaction_process" class="button-secondary" />';
		wp_nonce_field( 'allblogactions' );
		echo '<br class="clear" />';
		echo '</div>';
		echo '</div>';

		if(apply_filters('automessage_add_action', true))
			$this->show_actions_list($results);

		echo "</form>";

		echo "</div>";

		$this->add_action_form();

	}

	function handle_usermessageadmin_panel() {

		global $action, $page;

		echo "<div class='wrap'  style='position:relative;'>";
		echo "<h2>" . __('Message responses','automessage') . "</h2>";

		$this->show_admin_messages();

		echo '<ul class="subsubsub">';
		echo '<li><a href="#form-add-action" class="rbutton"><strong>' . __('Add a new action', 'automessage') . '</strong></a></li>';
		echo '</ul>';
		echo '<br clear="all" />';

		// Site level messages - if we are at a site level
		if(function_exists('is_site_admin') && is_site_admin()) {

			echo "<h3>" . __('Site level actions','automessage') . "</h3>";

			$results = $this->get_sitelevel_schedule();

			echo '<form id="form-site-list" action="?page=' . $page . '&amp;action=allmessages" method="post">';
			echo '<input type="hidden" name="page" value="' . $page . '" />';
			echo '<input type="hidden" name="actioncheck" value="allsiteactions" />';
			echo '<div class="tablenav">';
			echo '<div class="alignleft">';

			echo '<input type="submit" value="' . __('Delete') . '" name="allaction_delete" class="button-secondary delete" />';
			echo '<input type="submit" value="' . __('Pause') . '" name="allaction_pause" class="button-secondary" />';
			echo '<input type="submit" value="' . __('Unpause') . '" name="allaction_unpause" class="button-secondary" />';
			echo '&nbsp;&nbsp;<input type="submit" value="' . __('Process now') . '" name="allaction_process" class="button-secondary" />';
			wp_nonce_field( 'allsiteactions' );
			echo '<br class="clear" />';
			echo '</div>';
			echo '</div>';

			$this->show_actions_list($results);

			echo "</form>";
		}

		// Blog level messages
		echo "<h3>" . __('Blog level actions','automessage') . "</h3>";

		$results = $this->get_bloglevel_schedule();

		echo '<form id="form-site-list" action="?page=' . $page . '&amp;action=allmessages" method="post">';
		echo '<input type="hidden" name="page" value="' . $page . '" />';
		echo '<input type="hidden" name="actioncheck" value="allblogactions" />';
		echo '<div class="tablenav">';
		echo '<div class="alignleft">';

		echo '<input type="submit" value="' . __('Delete') . '" name="allaction_delete" class="button-secondary delete" />';
		echo '<input type="submit" value="' . __('Pause') . '" name="allaction_pause" class="button-secondary" />';
		echo '<input type="submit" value="' . __('Unpause') . '" name="allaction_unpause" class="button-secondary" />';
		echo '&nbsp;&nbsp;<input type="submit" value="' . __('Process now') . '" name="allaction_process" class="button-secondary" />';
		wp_nonce_field( 'allblogactions' );
		echo '<br class="clear" />';
		echo '</div>';
		echo '</div>';

		if(apply_filters('automessage_add_action', true))
			$this->show_actions_list($results);

		echo "</form>";

		echo "</div>";

		$this->add_action_form();

	}

	// Cron functions

	function queue_next_message($q) {

		$sql = "select s.* from {$this->am_schedule} as s, {$this->am_actions} as a WHERE
		s.action_id = a.id AND s.action_id = %d AND s.period > %d AND s.pause = 0 ORDER BY period, timeperiod ASC
		LIMIT 0,1";

		$sched = $this->db->get_row( $this->db->prepare($sql, $q->action_id, $q->period), OBJECT );

		if($sched) {
			$gapperiod = intval($sched->period - $q->period);

			$runon = strtotime("+ $gapperiod $sched->timeperiod");
			$this->db->insert($this->am_queue, array("schedule_id" => $sched->id, "runon" => $runon, "user_id" => $q->user_id, "site_id" => $q->site_id, "blog_id" => $q->blog_id, "sendtoemail" => $q->sendtoemail));
		}

	}

	function add_schedules($scheds) {

		if(!is_array($scheds)) {
			$scheds = array();
		}

		$scheds['fourdaily'] = array( 'interval' => 21600, 'display' => __('Four times daily') );

		return $scheds;
	}

	function process_schedule() {
		global $wpdb;

		$tstamp = time();

		$lastrun = get_automessage_option('automessage_lastrunon', 1);

		// Get the queued items that should have been processed by now
		$sql = $this->db->prepare( "SELECT q.*, s.subject, s.message, s.period, s.timeperiod, s.action_id  FROM {$this->am_queue} AS q, {$this->am_schedule} AS s, {$this->am_actions} AS a
		WHERE q.schedule_id = s.id AND a.id = s.action_id
		AND s.pause = 0 AND runon <= $tstamp AND runon >= $lastrun
		ORDER BY runon LIMIT 0, " . $this->processlimit );

		$queue = $this->db->get_results($sql, OBJECT);

		if($queue) {
			// We have items to process

			// Set last processed
			foreach($queue as $key => $q) {
				// Store the timestamp
				$lastrun = $q->runon;

				// Send the email
				$user = get_userdata($q->user_id);
				$this->send_message($q, $user, $q->blog_id, $q->site_id);

				// Find if there is another message to schedule and add it to the queue
				$this->queue_next_message($q);

				// delete the now processed item
				$this->db->query($this->db->prepare("DELETE FROM {$this->am_queue} WHERE id = %d", $q->id));
			}
			update_automessage_option('automessage_lastrunon', $lastrun);
		}
	}

	function force_process($schedule_id) {

		$lastrun = get_automessage_option('automessage_lastrunon', 1);

		$sql = $this->db->prepare( "SELECT q.*, s.subject, s.message, s.period, s.timeperiod, s.action_id  FROM {$this->am_queue} AS q, {$this->am_schedule} AS s, {$this->am_actions} AS a
		WHERE q.schedule_id = s.id AND a.id = s.action_id
		AND s.pause = 0 AND q.schedule_id <= %d AND runon >= $lastrun
		ORDER BY runon LIMIT 0, " . $this->processlimit, $schedule_id );

		$queue = $this->db->get_results($sql, OBJECT);

		if($queue) {
			// We have items to process
			foreach($queue as $key => $q) {
				// Store the timestamp
				$lastrun = $q->runon;

				// Send the email
				$user = get_userdata($q->user_id);
				$this->send_message($q, $user, $q->blog_id, $q->site_id);

				// Find if there is another message to schedule and add it to the queue
				$this->queue_next_message($q);

				// delete the now processed item
				$this->db->query($this->db->prepare("DELETE FROM {$this->am_queue} WHERE id = %d", $q->id));
			}
			update_automessage_option('automessage_lastrunon', $lastrun);
		}

	}

	// Unsubscribe actions
	function flush_rewrite() {
		// This function clears the rewrite rules and forces them to be regenerated

		global $wp_rewrite;

		//$wp_rewrite->flush_rules();

	}

	function add_queryvars($vars) {
		// This function add the namespace (if it hasn't already been added) and the
		// eventperiod queryvars to the list that WordPress is looking for.
		// Note: Namespace provides a means to do a quick check to see if we should be doing anything

		if(!in_array('namespace',$vars)) $vars[] = 'namespace';
		$vars[] = 'unsubscribe';

		return $vars;
	}

	function add_rewrite($wp_rewrite ) {

		$new_rules = array(
							'unsubscribe' . '/(.+)$' => 'index.php?namespace=automessage&unsubscribe=' . $wp_rewrite->preg_index(1)
							);

		$wp_rewrite->rules = $new_rules + $wp_rewrite->rules;
	}

	function process_unsubscribe_action() {
		global $wpdb, $wp_query;

		if(isset($wp_query->query_vars['namespace']) && $wp_query->query_vars['namespace'] == 'automessage') {

			// Set up the property query variables
			if(isset($wp_query->query_vars['unsubscribe'])) $unsub = $wp_query->query_vars['unsubscribe'];

			// Handle unsubscribe functionality
			if(isset($unsub)) {
				$sql = $this->db->prepare( "DELETE FROM {$this->am_queue} WHERE MD5(CONCAT(user_id,'16224')) = %s", $unsub);

				$this->db->query($sql);

				$this->output_unsubscribe_message();
			}

		}
	}

	function output_unsubscribe_message() {
		global $wp_query;

		if (file_exists(TEMPLATEPATH . '/' . 'page.php')) {

			/**
			 * What we are going to do here, is create a fake post.  A post
			 * that doesn't actually exist. We're gonna fill it up with
			 * whatever values you want.  The content of the post will be
			 * the output from your plugin.  The questions and answers.
			 */
			/**
			 * Clear out any posts already stored in the $wp_query->posts array.
			 */
			$wp_query->posts = array();
			$wp_query->post_count = 0;

			/**
			 * Create a fake post.
			 */
			$post = new stdClass;
			$post->post_author = 1;
			$post->post_name = 'unsubscribe';

			add_filter('the_permalink',create_function('$permalink', 'return "' . get_option('home') . '";'));
			$post->guid = get_bloginfo('wpurl') . '/' . 'unsubscribe';
			$post->post_title = 'Unsubscription request';
			$post->post_content = '<p>Your unsubscription request has been processed successfully.</p>';
			$post->post_excerpt = 'Your unsubscription request has been processed successfully.';
			$post->ID = -1;
			$post->post_status = 'publish';
			$post->post_type = 'post';
			$post->comment_status = 'closed';
			$post->ping_status = 'open';
			$post->comment_count = 0;
			$post->post_date = current_time('mysql');
			$post->post_date_gmt = current_time('mysql', 1);

			$wp_query->posts[] = $post;
			$wp_query->post_count = 1;
			$wp_query->is_home = false;

			ob_start('template');
			load_template(TEMPLATEPATH . '/' . 'page.php');
			ob_end_flush();

			die();
		}

		return $post;
	}

}

?>