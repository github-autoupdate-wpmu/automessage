<?php
if(!class_exists('Auto_User')) {

	class Auto_User extends WP_User {

		var $db;
		var $blog_id = false;
		var $site_id = false;

		function Auto_User( $id, $name = '' ) {

			global $wpdb, $blog_id, $site_id;

			if($id != 0) {
				parent::WP_User( $id, $name = '' );
			}


			$this->db =& $wpdb;
			$this->blog_id = $blog_id;
			$this->site_id = $site_id;

		}

		function set_blog_id( $blog_id ) {
			$this->blog_id = (int) $blog_id;
		}

		function set_site_id( $site_id ) {
			$this->site_id = (int) $site_id;
		}

		function send_message( $subject, $message ) {

			if(!empty($this->user_email)) {

				$replacements = array(	"/%blogname%/" 	=> 	get_option('blogname'),
										"/%blogurl%/"	=>	untrailingslashit(get_option('home')),
										"/%username%/"	=>	$this->user_login,
										"/%usernicename%/"	=>	$this->user_nicename
									);

				if(function_exists('get_site_details')) {
					$site = get_site_details($this->site_id);
					$replacements['/%sitename%/'] = $site->sitename;
					$replacements['/%siteurl%/'] = 'http://' . $site->domain . $site->path;
				} else {
					if($wp_version < '3.0') {
						$replacements['/%sitename%/'] = $replacements['/%blogname%/'];
						$replacements['/%siteurl%/'] = $replacements['/%blogurl%/'];
					} else {
						// Site exists
						$site = $this->db->get_row( $this->db->prepare("SELECT * FROM {$this->db->site} WHERE id = %d", $this->site_id));
						$replacements['/%sitename%/'] = $this->db->get_var( $this->db->prepare("SELECT meta_value FROM {$this->db->sitemeta} WHERE meta_key = 'site_name' AND site_id = %d", $this->site_id) );
						$replacements['/%siteurl%/'] = 'http://' . $site->domain . $site->path;
					}

				}
				$replacements['/%siteurl%/'] = untrailingslashit($replacements['/%siteurl%/']);

				$replacements = apply_filters('automessage_replacements', $replacements);

				if(!empty($message)) {
					$subject = stripslashes($subject);
					$msg = stripslashes($message);

					// Add in the unsubscribe text at the bottom of the message
					$msg .= "\n\n"; // Two blank lines
					$msg .= "-----\n"; // Footer marker
					$msg .= __('To stop receiving messages from %sitename% click on the following link: %siteurl%/unsubscribe/','automessage');
					// Add in the user id
					$msg .= md5($this->ID . '16224');

					$find = array_keys($replacements);
					$replace = array_values($replacements);

					$msg = preg_replace($find, $replace, $msg);
					$subject = preg_replace($find, $replace, $subject);

					// Set up the from address
					$header = 'From: "' . $replacements['/%sitename%/'] . '" <noreply@' . str_replace('http://', '', $replacements['/%siteurl%/']) . '>';
					$res = @wp_mail( $this->user_email, $subject, $msg, $header );

					do_action( 'automessage_sent_to', $this->ID);

				}

			}

		}

		function send_unsubscribe() {

			$replacements = array(	"/%blogname%/" 	=> 	get_option('blogname'),
									"/%blogurl%/"	=>	untrailingslashit(get_option('home')),
									"/%username%/"	=>	$this->user_login,
									"/%usernicename%/"	=>	$this->user_nicename
								);

			if(function_exists('get_site_details')) {
				$site = get_site_details($this->site_id);
				$replacements['/%sitename%/'] = $site->sitename;
				$replacements['/%siteurl%/'] = 'http://' . $site->domain . $site->path;
			} else {
				if($wp_version < '3.0') {
					$replacements['/%sitename%/'] = $replacements['/%blogname%/'];
					$replacements['/%siteurl%/'] = $replacements['/%blogurl%/'];
				} else {
					// Site exists
					$site = $this->db->get_row( $this->db->prepare("SELECT * FROM {$this->db->site} WHERE id = %d", $this->site_id));
					$replacements['/%sitename%/'] = $this->db->get_var( $this->db->prepare("SELECT meta_value FROM {$this->db->sitemeta} WHERE meta_key = 'site_name' AND site_id = %d", $this->site_id) );
					$replacements['/%siteurl%/'] = 'http://' . $site->domain . $site->path;
				}

			}
			$replacements['/%siteurl%/'] = untrailingslashit($replacements['/%siteurl%/']);

			$replacements = apply_filters('automessage_replacements', $replacements);

			$header = 'From: "' . $replacements['/%sitename%/'] . '" <noreply@' . str_replace('http://', '', $replacements['/%siteurl%/']) . '>';
			$res = @wp_mail( $this->user_email, "Unsubscribe request processed", "Your unsubscribe request has been processed and you have been removed from our mailing list.\n\nThank you\n\nThe BuddyPress Team.", $header );
			return $res;
		}

		function current_action() {
			if($wp_version < '3.0') {
				$action = get_usermeta( $this->ID, '_automessage_on_action');
			} else {
				$action = get_user_meta( $this->ID, '_automessage_on_action', true );
			}
			if(empty($action)) {
				return false;
			} else {
				if(is_array($action)) {
					return array_shift($action);
				} else {
					return $action;
				}
			}
		}

		function on_action() {
			if($wp_version < '3.0') {
				$action = get_usermeta( $this->ID, '_automessage_on_action');
			} else {
				$action = get_user_meta( $this->ID, '_automessage_on_action', true );
			}
			if(empty($action)) {
				return false;
			} else {
				return true;
			}
		}

		function schedule_message( $message_id, $timestamp ) {

			if($wp_version < '3.0') {
				update_usermeta($this->ID, '_automessage_on_action', (int) $message_id);
				update_usermeta($this->ID, '_automessage_run_action', (int) $timestamp);
			} else {
				update_user_meta($this->ID, '_automessage_on_action', (int) $message_id);
				update_user_meta($this->ID, '_automessage_run_action', (int) $timestamp);
			}

		}

		function clear_subscriptions() {

			if($this->current_action()) {
				if($wp_version < '3.0') {
					delete_usermeta($this->ID, '_automessage_on_action');
					delete_usermeta($this->ID, '_automessage_run_action');
				} else {
					delete_user_meta($this->ID, '_automessage_on_action');
					delete_user_meta($this->ID, '_automessage_run_action');
				}

			}

		}

		function has_message_scheduled( $message_id ) {
			//return !! get_usermeta($this->ID, '_automessage_on_action');
		}

		function on_message( $message_id ) {
			//return !! get_usermeta($this->ID, '_automessage_on_action');
		}

		function next_message() {

		}





	}


}
?>