<?php
/*
Plugin Name: Flickr Manager
Plugin URI: http://tgardner.net/
Description: Handles uploading, modifying images on Flickr, and insertion into posts.
Version: 2.1
Author: Trent Gardner
Author URI: http://tgardner.net/

Copyright 2007  Trent Gardner  (email : trent.gardner@gmail.com)

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
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/ 

if(version_compare(PHP_VERSION, '4.4.0') < 0) 
	die("You're currently running " . PHP_VERSION . " and you must have at least PHP 4.4.x in order to use Flickr Manager!");

if(class_exists('FlickrManager')) return;
require_once(dirname(__FILE__) . "/FlickrCore.php");
include_once(dirname(__FILE__) . "/MediaPanel.php");

class FlickrManager extends FlickrCore {
	
	var $db_table;
	var $plugin_directory;
	var $plugin_filename;
	var $plugin_option = 'wfm-settings';
	var $plugin_domain = 'flickr-manager';
	
	
	
	function FlickrManager() {
		global $wpdb;
		
		$this->db_table = $wpdb->prefix . "flickr";
		
		$this->plugin_directory = dirname(plugin_basename(__FILE__));
		$this->plugin_filename = basename(__FILE__);
		
		register_activation_hook( __FILE__, array(&$this, 'install') );
		
		add_action('admin_menu', array(&$this, 'add_menus'));
		add_action('init', array(&$this,'add_scripts'));
		add_action('wp_head', array(&$this, 'add_headers'));
		add_action('admin_head', array(&$this, 'add_admin_headers'));
		add_action('edit_page_form', array(&$this, 'add_flickr_panel'));
		add_action('edit_form_advanced', array(&$this, 'add_flickr_panel'));
		
		add_filter('the_content', array(&$this, 'filterContent'));
		
		/*
		 * Wordpress 2.5 - New media button support
		 */
		add_action('media_buttons', array($this, 'addMediaButton'), 20);
		add_action('media_upload_flickr', array($this, 'media_upload_flickr'));
		add_action('admin_head_media_upload_flickr_form', array($this, 'addMediaCss'));
			 
		/*
		 * Load locale settings
		 */
		load_plugin_textdomain($this->plugin_domain, PLUGINDIR . '/' . $this->plugin_directory . '/lang');
	}
	
	
	
	function install() {
		global $wpdb;
		
		if($wpdb->get_var("SHOW TABLES LIKE '$this->db_table'") == $this->db_table) {
			$results = $wpdb->get_results("select * from $this->db_table");
			$settings = array();
			foreach ($results as $setting) {
				$settings[$setting->name] = $setting->value;
			}
			
			if(get_option($this->plugin_option)) {
				update_option($this->plugin_option, $settings);
			} else {
				add_option($this->plugin_option, $settings);
			}
			
			$wpdb->query("drop table $this->db_table");
		} elseif (!get_option($this->plugin_option)) {
			add_option($this->plugin_option, array());
		}
	}
	
	
	
	function add_menus() {
		// Add a new submenu under Options
		add_options_page('Flickr Options', 'Flickr', 5, __FILE__, array(&$this, 'options_page'));
		
		// Add a new submenu under Manage
		add_management_page('Flickr Management', 'Flickr', 5, __FILE__, array(&$this, 'manage_page'));
	}
	
	
	
	function options_page() {
		global $flickr_settings;
		
		if(!empty($_REQUEST['action'])) : 
			switch ($_REQUEST['action']) :
				
				case 'token':
					if($frob = $flickr_settings->getSetting('frob')) {
						$token = $this->call('flickr.auth.getToken', array('frob' => $frob), true);
						if($token['stat'] == 'ok') {
							$flickr_settings->saveSetting('token', $token['auth']['token']['_content']);
							$flickr_settings->saveSetting('nsid', $token['auth']['user']['nsid']);
							$flickr_settings->saveSetting('username', $token['auth']['user']['username']);
						}
					}
					break;
				
				case 'logout':
					
					update_option($this->plugin_option, array());
					$flickr_settings = new FlickrSettings();
					
					break;
				
				case 'save':
					$_REQUEST['wfm-per_page'] = (!empty($_REQUEST['wfm-per_page']) && is_numeric($_REQUEST['wfm-per_page']) && 
												intval($_REQUEST['wfm-per_page']) > 0) ? intval($_REQUEST['wfm-per_page']) : 5;
					
					$flickr_settings->saveSetting('per_page', $_REQUEST['wfm-per_page']);
					$flickr_settings->saveSetting('new_window', $_REQUEST['wfm-new_window']);
					$flickr_settings->saveSetting('lightbox_default', $_REQUEST['wfm-lbox_default']);
					$flickr_settings->saveSetting('lightbox_enable', $_REQUEST['wfm-lbox_enable']);
					$flickr_settings->saveSetting('browse_check',$_REQUEST['wfm-limit']);
					$flickr_settings->saveSetting('browse_size',$_REQUEST['wfm-limit-size']);
					$flickr_settings->saveSetting('flickr_legacy', $_REQUEST['wfm-legacy-support']);
					$flickr_settings->saveSetting('image_viewer', $_REQUEST['wfm-js-viewer']);
					$flickr_settings->saveSetting('before_wrap', $_REQUEST['wfm-insert-before']);
					$flickr_settings->saveSetting('after_wrap', $_REQUEST['wfm-insert-after']);
					$flickr_settings->saveSetting('upload_level', $_REQUEST['wfm-upload-level']);
					
					break;
				
			endswitch;
		endif;
		
		if(($token = $flickr_settings->getSetting('token'))) {
			$auth_status = $this->call('flickr.auth.checkToken', array('auth_token' => $token), true);
		}
		?>
		
		<div class="wrap">
		
			<?php if($_REQUEST['action'] == 'save') : ?>
					
				<div id="message" class="updated fade">
					<p><strong><?php _e('Options Saved!', 'flickr-manager') ?></strong></p>
				</div>
			
			<?php endif; ?>
			
			<h2><?php _e('Flickr Manager Settings', 'flickr-manager') ?></h2>
			
			<?php if(empty($token) || $auth_status['stat'] != 'ok') : ?>
			
			<!-- Begin Authentication -->
			
			<?php
			$frob = $this->call('flickr.auth.getFrob', array(), true);
			$frob = $frob['frob']['_content'];
			$flickr_settings->saveSetting('frob', $frob);
			?>
			
			<div align="center">
				<h3><?php _e('Step', 'flickr-manager') ?> 1:</h3>
				<form>
					<input type="button" value="<?php _e('Authenticate', 'flickr-manager') ?>" onclick="window.open('<?php echo $this->getAuthUrl($frob,'delete'); ?>')" style="background: url( images/fade-butt.png ); border: 3px double #999; border-left-color: #ccc; border-top-color: #ccc; color: #333; padding: 0.25em; font-size: 1.5em;" />
				</form>
				
				<h3><?php _e('Step', 'flickr-manager') ?> 2:</h3>
				<form method="post" action="<?php echo str_replace( '%7E', '~', $_SERVER['REQUEST_URI']); ?>">
					<input type="hidden" name="action" value="token" />
					<input type="submit" name="Submit" value="<?php _e('Finish &raquo;', 'flickr-manager') ?>" style="background: url( images/fade-butt.png ); border: 3px double #999; border-left-color: #ccc; border-top-color: #ccc; color: #333; padding: 0.25em; font-size: 1.5em;" />
				</form>
			</div>
			
			<?php else : ?>
			
			<!-- Display options -->
			<div style="text-align: center;">
				<form method="post" action="<?php echo str_replace( '%7E', '~', $_SERVER['REQUEST_URI']); ?>">
					<input type="hidden" name="action" value="logout" />
					<p class="submit" style="text-align: center; border-top: none !important; margin-bottom: 20px !important; padding-top: 0px;">
						<input type="submit" name="Submit" value="<?php _e('Logout &raquo;', 'flickr-manager') ?>" class="button submit" style="font-size: 1.4em;" />
					</p>
				</form>
			</div>
			
			<?php
			$info = $this->call('flickr.people.getInfo',array('user_id' => $flickr_settings->getSetting('nsid')));
			
			$flickr_settings->saveSetting('is_pro', $info['person']['ispro']);
			if($info['stat'] == 'ok') :
			
				if(intval($info['person']['iconserver']) > 0) 
					$photo_url = "http://farm{$info['person']['iconfarm']}.static.flickr.com/{$info['person']['iconserver']}/buddyicons/{$info['person']['nsid']}.jpg";
				else $photo_url = 'http://www.flickr.com/images/buddyicon.jpg';
			?>
				
				<h3>
				<?php 
				_e('User Information', 'flickr-manager');
				 
				if($info['person']['ispro'] != 0) 
					echo ' <img src="' . $this->getAbsoluteUrl() . '/images/badge_pro.gif" alt="Pro" style="vertical-align: middle;" />'; 
				?>
				</h3>
				
				<?php echo "<img src=\"$photo_url\" alt=\"You\" />"; ?>
				
				<table border="0" class="text-left">
					<tr>
						<th width="130px" scope="row"><?php _e('Username', 'flickr-manager') ?>:</th>
						<td><?php echo $info['person']['username']['_content']; ?></td>
					</tr>
					<tr>
						<th scope="row"><?php _e('User ID', 'flickr-manager') ?>:</th>
						<td><?php echo $info['person']['nsid']; ?></td>
					</tr>
					<tr>
						<th scope="row"><?php _e('Real Name', 'flickr-manager') ?>:</th>
						<td><?php echo $info['person']['realname']['_content']; ?></td>
					</tr>
					<tr>
						<th scope="row"><?php _e('Photo URL', 'flickr-manager') ?>:</th>
						<td>
							<a href="<?php echo $info['person']['photosurl']['_content']; ?>">
								<?php echo $info['person']['photosurl']['_content']; ?>
							</a>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e('Profile URL', 'flickr-manager') ?>:</th>
						<td>
							<a href="<?php echo $info['person']['profileurl']['_content']; ?>">
								<?php echo $info['person']['profileurl']['_content']; ?>
							</a>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e('# Photos', 'flickr-manager') ?>:</th>
						<td><?php echo $info['person']['photos']['count']['_content']; ?></td>
					</tr>
				</table>
				
				<p>&nbsp;</p>
			
			<?php endif; ?>
			
			<!-- BEGIN OPTIONS -->
			<?php
			
			// Load Options
			$settings = $flickr_settings->getSettings();
			$_REQUEST['wfm-per_page'] = (!empty($_REQUEST['wfm-per_page']) && is_numeric($_REQUEST['wfm-per_page']) && 
										intval($_REQUEST['wfm-per_page']) > 0) ? intval($_REQUEST['wfm-per_page']) : 5;
			$_REQUEST['wfm-per_page'] = (!empty($settings['per_page'])) ? $settings['per_page'] : $_REQUEST['wfm-per_page'];
			$_REQUEST['wfm-new_window'] = $settings['new_window'];
			
			$_REQUEST['wfm-limit'] = $settings['browse_check'];
			$_REQUEST['wfm-limit-size'] = $settings['browse_size'];
			$_REQUEST['wfm-upload-level'] = (!empty($settings['upload_level'])) ? $settings['upload_level'] : "6";
			
			$_REQUEST['wfm-lbox_enable'] = $settings['lightbox_enable'];
			$_REQUEST['wfm-lbox_default'] = (!empty($settings['lightbox_default'])) ? $settings['lightbox_default'] : "medium";
			$_REQUEST['wfm-js-viewer'] = (!empty($settings['image_viewer'])) ? $settings['image_viewer'] : "medium";
			
			$_REQUEST['wfm-insert-before'] = $settings['before_wrap'];
			$_REQUEST['wfm-insert-after'] = $settings['after_wrap'];
			?>
			
			<form method="post" action="<?php echo str_replace( '%7E', '~', $_SERVER['REQUEST_URI']); ?>">
				<input type="hidden" name="action" value="save" />
				
				<h3 style="margin-bottom: 0px;"><?php _e('Miscellaneous', 'flickr-manager'); ?></h3>
				
				<table class="form-table">
					<tbody>
						<tr valign="top">
							<th scope="row">
								<label for="wfm-legacy-support">
									<?php _e('Enable legacy panel', 'flickr-manager') ?>
								</label>
							</th>
							<td>
								<input type="checkbox" name="wfm-legacy-support" id="wfm-legacy-support" value="true" style="margin: 5px 0px;" <?php if($settings['flickr_legacy'] == "true") echo 'checked="checked" '; ?>/>
								<br /><?php _e('Note: Wordpress &gt;=2.5 users can leave this option disabled and use the added media button.', 'flickr-manager') ?>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">
								<label for="wfm-per_page">
									<?php _e('Images per page', 'flickr-manager') ?>
								</label>
							</th>
							<td>
								<input type="text" name="wfm-per_page" id="wfm-per_page" value="<?php echo $_REQUEST['wfm-per_page']; ?>" style="padding: 3px; width: 50px;" />
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">
								<label for="wfm-new_window">
									<?php _e('Open Flickr pages in a new window', 'flickr-manager') ?>
								</label>
							</th>
							<td>
								<input type="checkbox" name="wfm-new_window" id="wfm-new_window" value="true" style="margin: 5px 0px;" <?php if($_REQUEST['wfm-new_window'] == "true") echo 'checked="checked" '; ?>/>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">
								<label for="wfm-limit-size">
									<?php _e('Limit browse image size to', 'flickr-manager') ?>
								</label>
							</th>
							<td>
								<input type="hidden" name="wfm-limit" id="wfm-limit" value="true" />
								<select name="wfm-limit-size" id="wfm-limit-size">
									<option value="square" <?php if($_REQUEST['wfm-limit-size'] == "square") echo 'selected="selected"'; ?>><?php _e('Square', 'flickr-manager'); ?></option>
									<option value="thumbnail" <?php if($_REQUEST['wfm-limit-size'] == "thumbnail") echo 'selected="selected"'; ?>><?php _e('Thumbnail', 'flickr-manager'); ?></option>
								</select>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">
								<label for="wfm-upload-level">
									<?php _e('User upload level', 'flickr-manager') ?>
								</label>
							</th>
							<td>
								<select name="wfm-upload-level" id="wfm-upload-level">
									<option value="10" <?php if($_REQUEST['wfm-upload-level'] == "10") echo 'selected="selected"'; ?>><?php _e('Administrator', 'flickr-manager'); ?></option>
									<option value="6" <?php if($_REQUEST['wfm-upload-level'] == "6") echo 'selected="selected"'; ?>><?php _e('Editor', 'flickr-manager'); ?></option>
									<option value="4" <?php if($_REQUEST['wfm-upload-level'] == "4") echo 'selected="selected"'; ?>><?php _e('Author', 'flickr-manager'); ?></option>
									<option value="2" <?php if($_REQUEST['wfm-upload-level'] == "2") echo 'selected="selected"'; ?>><?php _e('Contributer', 'flickr-manager'); ?></option>
								</select>
							</td>
						</tr>
					</tbody>
				</table>
				
				<h3 style="margin-bottom: 0px; margin-top: 30px;"><?php _e('Javascript Image Viewer', 'flickr-manager'); ?></h3>
				
				<table class="form-table">
					<tbody>
						<tr valign="top">
							<th scope="row">
								<label for="wfm-js-viewer">
									<?php _e('Image Viewer', 'flickr-manager'); ?>
								</label>
							</th>
							<td>
								<select name="wfm-js-viewer" id="wfm-js-viewer">
									<option value="lightbox" <?php if($_REQUEST['wfm-js-viewer'] == "lightbox") echo 'selected="selected"'; ?>>Lightbox</option>
									<option value="highslide" <?php if($_REQUEST['wfm-js-viewer'] == "highslide") echo 'selected="selected"'; ?>>Highslide</option>
								</select>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">
								<label for="wfm-lbox_enable"><?php _e('Enable image viewer by default', 'flickr-manager') ?></label>
							</th>
							<td>
								<input type="checkbox" name="wfm-lbox_enable" id="wfm-lbox_enable" value="true" <?php if($_REQUEST['wfm-lbox_enable'] == "true") echo 'checked="checked" '; ?>/>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">
								<label for="wfm-lbox_default"><?php _e('Default image viewer size', 'flickr-manager') ?></label>
							</th>
							<td>
								<select name="wfm-lbox_default" id="wfm-lbox_default">
								<?php
								$sizes = array(	"small" => __('Small', 'flickr-manager'), 
										"medium" => __('Medium', 'flickr-manager'), 
										"large" => __('Large', 'flickr-manager')
										);
										
								if($settings['is_pro'] == '1') $sizes = array_merge($sizes, array('original' => __("Original", 'flickr-manager')));
								
								foreach ($sizes as $k => $size) {
									echo "<option value=\"$k\"";
									if($_REQUEST['wfm-lbox_default'] == $k) echo ' selected="selected" ';
									echo ">" . ucfirst($size) . "</option>\n";
								}
								?>
								</select>
							</td>
						</tr>
					</tbody>
				</table>
				
				<h3 style="margin-bottom: 0px; margin-top: 30px;"><?php _e('Custom Wrappings', 'flickr-manager') ?></h3>
				
				<table class="form-table">
					<tbody>
						<tr valign="top">
							<th>
								<label for="wfm-insert-before"><?php _e('Before Image', 'flickr-manager') ?></label>
							</th>
							<td>
								<textarea name="wfm-insert-before" id="wfm-insert-before" style="width: 200px; height: 100px; overflow: auto;"><?php echo $_REQUEST['wfm-insert-before']; ?></textarea>
							</td>
							<th>
								<label for="wfm-insert-after"><?php _e('After Image', 'flickr-manager') ?></label>
							</th>
							<td>
								<textarea name="wfm-insert-after" id="wfm-insert-after" style="width: 200px; height: 100px; overflow: auto;"><?php echo $_REQUEST['wfm-insert-after']; ?></textarea>
							</td>
						</tr>
					</tbody>
				</table>
				
				
				<p class="submit">
					<input type="submit" name="Submit" value="<?php _e('Submit', 'flickr-manager') ?> &raquo;" style="font-size: 1.5em;" />
				</p>
				
			</form>
			<!-- END OPTIONS -->
			
			<?php endif; ?>
			
		</div>
		
		<?php
	}
	
	
	
	function manage_page() {
		global $flickr_settings;
		$token = $flickr_settings->getSetting('token');
		if(empty($token)) {
			echo '<div class="wrap"><h3>' . __('Error: Please authenticate through ', 'flickr-manager') . '<a href="'.get_option('siteurl')."/wp-admin/options-general.php?page=$this->plugin_directory/$this->plugin_filename\">Options->Flickr</a></h3></div>\n";
			return;
		} else {
			$auth_status = $this->call('flickr.auth.checkToken', array('auth_token' => $token), true);
			if($auth_status['stat'] != 'ok') {
				echo '<div class="wrap"><h3>' . __('Error: Please authenticate through ', 'flickr-manager') . '<a href="'.get_option('siteurl')."/wp-admin/options-general.php?page=$this->plugin_directory/$this->plugin_filename\">Options->Flickr</a></h3></div>\n";
				return;
			}
		}
		
		switch($_REQUEST['action']) {
			case 'upload':
				/* Perform file upload */
				if($_FILES['uploadPhoto']['error'] == 0) {
					
					$params = array('auth_token' => $token, 'photo' => '@'.$_FILES['uploadPhoto']['tmp_name']);
					$rsp = $this->upload($params);
					
					if($rsp !== false) {
					
						$xml_parser = xml_parser_create();
						xml_parse_into_struct($xml_parser, $rsp, $vals, $index);
						xml_parser_free($xml_parser);
						
						$pid = $vals[$index['PHOTOID'][0]]['value'];
						
						if(!empty($pid)) {
							$_REQUEST['pid'] = $pid;
							$_REQUEST['action'] = 'edit';
						}
						
					}
					
				}
				break;
			
			case 'modify':
				/* Perform modify */
				$params = array('photo_id' => $_REQUEST['pid'], 
								'title' => $_REQUEST['ftitle'],
								'description' => $_REQUEST['description'],
								'auth_token' => $token);
				
				$this->post('flickr.photos.setMeta', $params, true);
				
				$params = array('photo_id' => $_REQUEST['pid'], 
								'tags' => $_REQUEST['tags'],
								'auth_token' => $token);
				
				$this->post('flickr.photos.setTags', $params, true);
				
				$is_public = ($_REQUEST['public'] == '1') ? 1 : 0;
				$is_friend = ($_REQUEST['friend'] == '1') ? 1 : 0;
				$is_family = ($_REQUEST['family'] == '1') ? 1 : 0;
				$params = array('photo_id' => $_REQUEST['pid'], 
								'is_public' => $is_public,
								'is_friend' => $is_friend,
								'is_family' => $is_family,
								'perm_comment' => '3',
								'perm_addmeta' => '0',
								'auth_token' => $token);
				
				$this->post('flickr.photos.setPerms', $params, true);
				
				$_REQUEST['action'] = 'default';
				break;
				
			case 'delete': 
				/* Perform delete */
				$params = array('auth_token' => $token, 'photo_id' => $_REQUEST['pid']);
				$this->post('flickr.photos.delete', $params, true);
				
				$_REQUEST['action'] = 'default';
				break;
		}
		?>
		
		<div class="wrap">
	
			<h2><?php _e('Image Management', 'flickr-manager'); ?></h2>
			
			<form enctype="multipart/form-data" method="post" action="<?php echo str_replace( '%7E', '~', $_SERVER['REQUEST_URI']); ?>" style="padding: 0px 20px;">
				<h3><?php _e('Upload Photo', 'flickr-manager'); ?></h3>
				
				<p class="submit" style="text-align: left;">
					<label><?php _e('Upload Photo', 'flickr-manager'); ?>:
						<input type="file" name="uploadPhoto" id="uploadPhoto" />
					</label>
					<input type="submit" name="Submit" value="<?php _e('Upload &raquo;', 'flickr-manager') ?>" />
					<input type="hidden" name="action" value="upload" />
				</p>
			</form>
			
			<div style="padding: 0px 20px;">
				
				<?php
				switch($_REQUEST['action']) {
					case 'edit':
						$params = array('photo_id' => $_REQUEST['pid'], 'auth_token' => $token);
						$photo = $this->call('flickr.photos.getInfo',$params, true);
						?>
						
						<h3><?php _e('Modify Photo', 'flickr-manager'); ?></h3>
						<a href="<?php echo "{$_SERVER['PHP_SELF']}?page={$_REQUEST['page']}"; ?>" ><?php _e('&laquo; Back', 'flickr-manager'); ?></a><br /><br />
						
						<!-- Begin modification of inidividual photo -->
						
						<div align="center">
							<img src="<?php echo $this->getPhotoUrl($photo['photo'],"medium"); ?>" alt="<?php echo $photo['photo']['title']['_content']; ?>" /><br />
							
							<form method="post" action="<?php echo str_replace( '%7E', '~', $_SERVER['REQUEST_URI']); ?>" style="width: 650px;">
								<table>
									<tr>
										<td width="130px"><label for="ftitle"><?php _e('Title', 'flickr-manager'); ?>:</label></td>
										<td><input type="text" name="ftitle" id="ftitle" value="<?php echo $photo['photo']['title']['_content']; ?>" style="width:300px;" /></td>
									</tr>
									<tr>
										<td><?php _e('Permissions', 'flickr-manager'); ?>:</td>
										<td>
										<label><input name="public" type="checkbox" id="public" value="1" <?php if($photo['photo']['visibility']['ispublic'] == '1') echo 'checked="checked" '; ?>/> <?php _e('Public', 'flickr-manager'); ?></label>
										<label><input name="friend" type="checkbox" id="friend" value="1" <?php if($photo['photo']['visibility']['isfriend'] == '1') echo 'checked="checked" '; ?>/> <?php _e('Friends', 'flickr-manager'); ?></label>
										<label><input name="family" type="checkbox" id="family" value="1" <?php if($photo['photo']['visibility']['isfamily'] == '1') echo 'checked="checked" '; ?>/> <?php _e('Family', 'flickr-manager'); ?></label>
										</td>
									</tr>
									<tr>
										<td><label for="tags"><?php _e('Tags', 'flickr-manager'); ?>:</label></td>
										<td><input type="text" name="tags" id="tags" value="<?php 
										foreach($photo['photo']['tags']['tag'] as $tag) {
											echo "{$tag['raw']} ";
										}
										?>" style="width:500px;" /></td>
									</tr>
									<tr>
										<td valign="top"><label for="description"><?php _e('Description', 'flickr-manager'); ?>:</label></td>
										<td><textarea name="description" id="description" style="width:500px; height:100px;"><?php echo $photo['photo']['description']['_content']; ?></textarea></td>
									</tr>
								</table>
								<input type="hidden" name="action" value="modify" />
								<input type="hidden" name="pid" value="<?php echo $_REQUEST['pid']; ?>" />
								<input type="submit" name="submit" value="Submit" />
								<input type="reset" name="reset" value="Reset" />
							</form>
						</div>
						
						<?php
						break;
						
					default:
						$page = (isset($_REQUEST['fpage'])) ? $_REQUEST['fpage'] : '1';
						$per_page = (isset($_REQUEST['fper_page'])) ? $_REQUEST['fper_page'] : '10';
						$nsid = $flickr_settings->getSetting('nsid');
						$params = array('user_id' => $nsid, 'per_page' => $per_page, 'page' => $page, 'auth_token' => $token);
						$photos = $this->call('flickr.photos.search', $params, true);
						$pages = $photos['photos']['pages'];
						?>
						
						<h3><?php _e('Manage Photos', 'flickr-manager'); ?>:</h3>
						<p><b><?php _e('Add images to your posts with', 'flickr-manager'); ?> [img:&lt;flickr-id&gt;,&lt;size&gt;]</b></p>
						<!-- Default management section -->
						
						<div style="text-align: center;">
						<table style="margin-left: auto; margin-right: auto;" class="widefat">
							<thead>
								<tr>
									<th width="130px" style="text-align: center;">ID</th>
									<th width="100px" style="text-align: center;"><?php _e('Thumbnail', 'flickr-manager'); ?></th>
									<th width="200px" style="text-align: center;"><?php _e('Title', 'flickr-manager'); ?></th>
									<th width="170px" style="text-align: center;"><?php _e('Action', 'flickr-manager'); ?></th>
								</tr>
							</thead>
							
							<tbody id="the-list">
							
							<?php 
							$count = 0;
							foreach ($photos['photos']['photo'] as $photo) : 
								$count++;
							?>
							
							<tr <?php if($count % 2 > 0) echo "class='alternate'"; ?>>
								<td align="center"><?php echo $photo['id']; ?></td>
								<td align="center"><img src="<?php echo $this->getPhotoUrl($photo,"square"); ?>" alt="<?php echo $photo['title']; ?>" /></td>
								<td align="center"><?php echo $photo['title']; ?></td>
								<td align="center"><a href="http://www.flickr.com/photos/<?php echo "$nsid/{$photo['id']}/"; ?>" target="_blank"><?php _e('View', 'flickr-manager'); ?></a> / 
								<a href="<?php echo "{$_SERVER['PHP_SELF']}?page={$_REQUEST['page']}&amp;action=edit&amp;pid={$photo['id']}"; ?>"><?php _e('Modify', 'flickr-manager'); ?></a> / 
								<a href="<?php echo "{$_SERVER['PHP_SELF']}?page={$_REQUEST['page']}&amp;action=delete&amp;pid={$photo['id']}"; ?>" onclick="return confirm('<?php _e('Are you sure you want to delete this?', 'flickr-manager'); ?>');"><?php _e('Delete', 'flickr-manager'); ?></a>
								</td>
							</tr>
							
							<?php endforeach; ?>
							
							</tbody>
							
						</table>
						
						<?php if (intval($page) > 1) : ?>
				
							<a href="<?php echo "{$_SERVER['PHP_SELF']}?page={$_REQUEST['page']}&amp;fpage=".(intval($page) - 1)."&amp;fper_page=$per_page"; ?>"><?php _e('&laquo; Previous', 'flickr-manager'); ?></a>
							
						<?php endif; ?>
						
						<?php for($i=1; $i<=$pages; $i++) : ?>
							
							<?php if($i != intval($page)) : ?>
							
							<a href="<?php echo "{$_SERVER['PHP_SELF']}?page={$_REQUEST['page']}&amp;fpage=$i&amp;fper_page=$per_page"; ?>"><?php echo $i; ?></a>
							
							<?php else : 
								echo "<b>$i</b>";
								
							endif; ?>
							
						<?php endfor; ?>
						
						<?php if (intval($page) < $pages) : ?>
						
							<a href="<?php echo "{$_SERVER['PHP_SELF']}?page={$_REQUEST['page']}&amp;fpage=".(intval($page) + 1)."&amp;fper_page=$per_page"; ?>"><?php _e('Next &raquo;', 'flickr-manager'); ?></a>
							
						<?php endif; ?>
						
						</div>
						
						<?php
						break;
				}
				?>
			</div>
			
		</div>
		
		<?php
	}
	
	
	
	function filterContent($content) {
		$content = preg_replace_callback("/\[img\:(\d+),(.+)\]/", array(&$this, 'filterCallback'), $content);
		$content = preg_replace_callback("/\[imgset\:(\d+),(.+),(.+)\]/", array(&$this, 'filterSets'), $content);
		return $content;
	}
	
	
	
	function filterSets($match) {
		global $flickr_settings;
		$setid = $match[1];
		$size = $match[2];
		$lightbox = $match[3];
		$lightbox = ($lightbox == "true") ? true : false;
		$token = $flickr_settings->getSetting('token');
		$params = array('photoset_id' => $setid, 'auth_token' => $token, 'extras' => 'original_format');
		$photoset = $this->call('flickr.photosets.getPhotos',$params, true);
		
		foreach ($photoset['photoset']['photo'] as $photo) {
			$replace .= $flickr_settings->getSetting('before_wrap') . "<a href=\"http://www.flickr.com/photos/{$photoset['photoset']['owner']}/{$photo['id']}/\" title=\"{$photo['title']}\" ";
			if($lightbox) $replace .= "rel=\"flickr-mgr[$setid]\" ";
			$replace .= "class=\"flickr-image\" >\n";
			$replace .= '	<img src="' . $this->getPhotoUrl($photo,$size) . "\" alt=\"{$photo['title']}\" ";
			if($lightbox) $replace .= 'class="flickr-medium" ';
			$replace .= "/>\n";
			$replace .= "</a>\n" . $flickr_settings->getSetting('after_wrap');
		}
		return $replace;
	}
	
	
	
	function filterCallback($match) {
		global $flickr_settings;
		$pid = $match[1];
		$size = $match[2];
		$token = $flickr_settings->getSetting('token');
		$params = array('photo_id' => $pid, 'auth_token' => $token);
		$photo = $this->call('flickr.photos.getInfo',$params, true);
		$url = $this->getPhotoUrl($photo['photo'],$size);
		return $flickr_settings->getSetting('before_wrap') . "<a href=\"{$photo['photo']['urls']['url'][0]['_content']}\">
					<img src=\"$url\" alt=\"{$photo['photo']['title']['_content']}\" />
				</a>" . $flickr_settings->getSetting('after_wrap');
	}
	
	
	
	function add_scripts() {
		global $flickr_settings;
		$image_viewer = $flickr_settings->getSetting('image_viewer');
		$image_viewer = (!empty($image_viewer)) ? $image_viewer : 'lightbox';
		
		switch($image_viewer){
			case 'highslide':	
				wp_enqueue_script('highslide',$this->getAbsoluteUrl(). '/js/highslide.packed.js', array('jquery'));
				wp_enqueue_script('wfm-hs',$this->getAbsoluteUrl(). '/js/wfm-hs.php');			
			break;			
			default:		
				wp_enqueue_script('jquery-lightbox',$this->getAbsoluteUrl(). '/js/jquery.lightbox.js', array('jquery'));
				wp_enqueue_script('wfm-lightbox',$this->getAbsoluteUrl(). '/js/wfm-lightbox.php');					
			break;
		}
		$GLOBALS['image_viewer'] = $image_viewer;
	}
	
	
	
	function add_headers() { 
		switch($GLOBALS['image_viewer']){
			case 'highslide':
			?>

<!-- WFM INSERT HIGHSLIDE FILES -->
<link rel="stylesheet" href="<?php echo $this->getAbsoluteUrl(); ?>/css/highslide.css" type="text/css" />
<!-- WFM END INSERT -->

			<?php			
			break;
			default:
			?>

<!-- WFM INSERT LIGHTBOX FILES -->
<link rel="stylesheet" href="<?php echo $this->getAbsoluteUrl(); ?>/css/lightbox.css" type="text/css" />
<!-- WFM END INSERT -->

			<?php
			break;
		}
	}
	
	
	
	function getAbsoluteUrl() {
		return get_option('siteurl') . "/wp-content/plugins/" . $this->plugin_directory;
	}
	
	
	
	function add_admin_headers() {
		?>
		<style type="text/css">
			table.text-left th {
				text-align: left;
			}
		</style>
		<?php 
		
		global $flickr_settings;
		
		$filename = array_shift(explode('?', basename($_SERVER['REQUEST_URI'])));
		
		if($filename != "post.php" && $filename != "page.php" && $filename != "post-new.php" && $filename != "page-new.php") return;
		
		$settings = $flickr_settings->getSettings();
		$legacy = (isset($settings['flickr_legacy'])) ? $settings['flickr_legacy'] : 'true';
		
		if($legacy == "true") : ?>
		
		<link rel="stylesheet" href="<?php echo $this->getAbsoluteUrl(); ?>/css/admin_style.css" type="text/css" />
		<script type="text/javascript" src="<?php echo $this->getAbsoluteUrl(); ?>/js/flickr-js.php"></script>
	
		<?php endif; ?>
		
	<?php
	}
	
	
	
	function add_flickr_panel() {
		global $flickr_settings;
		
		if($flickr_settings->getSetting('flickr_legacy') == "true") : ?>

		<div class="dbx-box postbox" id="flickr-insert-widget">
		
			<h3 class="dbx-handle">Flickr Manager</h3>
			
			<div id="flickr-content" class="dbx-content inside">
			
				<div id="flickr-menu">
					<a href="#?faction=upload" title="<?php _e('Upload Photo', 'flickr-manager') ?>"><?php _e('Upload Photo', 'flickr-manager') ?></a>
					<a href="#?faction=browse" id="fbrowse-photos" title="<?php _e('Browse Photos', 'flickr-manager') ?>"><?php _e('Browse Photos', 'flickr-manager') ?></a>
					<div id="scope-block">
					<label><input type="radio" name="fscope" id="flickr-personal" value="Personal" checked="checked" onchange="executeLink(document.getElementById('fbrowse-photos'),'flickr-ajax');" /> Personal</label>
					<label><input type="radio" name="fscope" id="flickr-public" value="Public" onchange="executeLink(document.getElementById('fbrowse-photos'),'flickr-ajax');" /> Public</label>
					</div>
					<div style="clear: both; height: 1%;"></div>
				</div>
				<div id="flickr-ajax"></div>
				
			</div>
			
		</div>
		
		<div style="clear: both;">&nbsp;</div>
		
		<?php endif;
	}
	
	
	
	/********************************************************************
	 *********** NEW WORDPRESS 2.5 MEDIA BUTTON IMPLEMENTATION **********
	 ********************************************************************/
	function addMediaButton() {
		global $post_ID, $temp_ID;
		$uploading_iframe_ID = (int) (0 == $post_ID ? $temp_ID : $post_ID);
		$media_upload_iframe_src = "media-upload.php?post_id=$uploading_iframe_ID";

		$flickr_upload_iframe_src = apply_filters('media_flickr_iframe_src', "$media_upload_iframe_src&amp;type=flickr");
		$flickr_title = __('Add Flickr Photo', 'flickr-manager');

		$link_markup = "<a href=\"{$flickr_upload_iframe_src}&amp;tab=flickr&amp;TB_iframe=true&amp;height=500&amp;width=640\" class=\"thickbox\" title=\"$flickr_title\"><img src=\"".$this->getAbsoluteUrl()."/images/flickr-media.gif\" alt=\"$flickr_title\" /></a>\n";

		echo $link_markup;
        
	}
	
	function media_upload_flickr() {
		wp_iframe('media_upload_flickr_form');
	}
	
	function modifyMediaTab($tabs) {
        return array(
            'flickr' =>  __('Flickr Photos', 'flickr-manager')
        );
    }
    
    function addMediaCss() { 
    	
    	wp_admin_css('css/media');
    	?>
    	
    	<link rel="stylesheet" href="<?php echo $this->getAbsoluteUrl(); ?>/css/media_panel.css" type="text/css" media="screen" />
    	<script type="text/javascript" src="<?php echo $this->getAbsoluteUrl(); ?>/js/media-panel.php"></script>
    
    <?php }
    
}

global $flickr_manager, $flickr_settings;
$flickr_manager = new FlickrManager();
$flickr_settings = new FlickrSettings();
?>
