<?php
/*
Plugin Name: Gravity Forms Redmine Add-On
Plugin URI: http://www.gravityforms.com
Description: Integrates Gravity Forms with Redmine, enabling end users to report new issues through Gravity Forms.
Version: 0.1
Author: log.pt
Author URI: http://log.pt

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/

if (!defined("LOGPT_UPGRADE_URL"))
	define( "LOGPT_UPGRADE_URL", "http://log.pt/wp-content/plugins/upgrade/" );

add_action( 'init',  array( 'GFRedmine', 'init' ) );

register_activation_hook( __FILE__, array( 'GFRedmine', 'add_permissions' ) );

class GFRedmine
{
    private static $path = "gravitymine/gravitymine.php";
    private static $url = "http://log.pt";
    private static $slug = "gravitymine";
    private static $version = "0.1";
    private static $min_gravityforms_version = "1.5";
    private static $min_redmine_version = "1.1";

    public static function init ()
    {
        if (RG_CURRENT_PAGE == "plugins.php")
        {
            //loading translations
            load_plugin_textdomain( 'gravitymine', FALSE, '/gravitymine/languages' );
            add_action(' after_plugin_row_' . self::$path, array('GFRedmine', 'plugin_row') );

            //force new remote request for version info on the plugin page
            self::flush_version_info();
        }

        if (!self::is_gravityforms_supported())
           return;
           
        if (is_admin())
        {
            //loading translations
            load_plugin_textdomain( 'gravitymine', FALSE, '/gravitymine/languages' );

            //automatic upgrade hooks
            add_filter( 'transient_update_plugins'				, array( 'GFRedmine', 'check_update' ) );
            add_filter( 'site_transient_update_plugins'			, array( 'GFRedmine', 'check_update' ) );
            add_action( 'install_plugins_pre_plugin-information', array( 'GFRedmine', 'display_changelog' ) );

            //integrating with Members plugin
            if(function_exists('members_get_capabilities'))
                add_filter( 'members_get_capabilities', array( 'GFRedmine', 'members_get_capabilities' ) );

            //creates the subnav left menu
            add_filter( 'gform_addon_navigation', array( 'GFRedmine', 'create_menu') );

            if (self::is_redmine_page())
            {
                //enqueueing sack for AJAX requests
                wp_enqueue_script( array( "sack" ) );

                //loading data lib
                require_once( self::get_base_path() . "/data.php" );

                //loading upgrade lib
                if (!class_exists( "RGRedmineUpgrade" ))
                    require_once( "plugin-upgrade.php" );

                //loading Gravity Forms tooltips
                require_once( GFCommon::get_base_path() . "/tooltips.php" );
                add_filter( 'gform_tooltips', array( 'GFRedmine', 'tooltips' ) );

                //runs the setup when version changes
                self::setup();

            }
            else if (in_array( RG_CURRENT_PAGE, array( "admin-ajax.php" ) ))
            {
                //loading data class
                require_once( self::get_base_path() . "/data.php");

                add_action( 'wp_ajax_gf_redmine_update_feed_active'	, array( 'GFRedmine', 'update_feed_active' ) );
                add_action( 'wp_ajax_gf_select_redmine_form'		, array( 'GFRedmine', 'select_redmine_form' ) );
                add_action( 'wp_ajax_gf_redmine_confirm_settings'	, array( 'GFRedmine', 'confirm_settings' ) );

            }
            else if (RGForms::get( "page" ) == "gf_settings")
            {
                RGForms::add_settings_page( "Redmine", array( 'GFRedmine', 'settings_page' ), self::get_base_url() . "/images/redmine_wordpress_icon_32.png");
            }
            
        }
        else
        {
            //loading data class
            require_once( self::get_base_path() . "/data.php" );

            //handling post submission.
            add_filter( 'gform_confirmation'				, array( 'GFRedmine', 'send_to_redmine' ), 1000, 4 );
            add_filter( 'gform_disable_post_creation'		, array( 'GFRedmine', 'delay_post' ), 10, 3 );
            add_filter( 'gform_disable_user_notification'	, array( 'GFRedmine', 'delay_autoresponder' ), 10, 3 );
            add_filter( 'gform_disable_admin_notification'	, array( 'GFRedmine', 'delay_notification' ), 10, 3 );
        }
    }
    
    
    private static function is_valid_url ($url)
    {
        if (!empty( $url ))
        {
            if (!class_exists( "RedmineAPI" ))
            {
                require_once("api/RedmineAPI.class.php");
            }
            
            $projects = new RedmineAPI( $url, false, 'project' );
            $projects->find( 'all' );
        }

        return (!projects || $projects->errno == 6) ? false : true;
    }
    
    
    private static function is_valid_login ($url, $apikey)
    {
        if (!empty( $url ) && !empty( $apikey ))
        {
            if (!class_exists( "RedmineAPI" ))
            {
                require_once("api/RedmineAPI.class.php");
            }
            
            $projects = new RedmineAPI( $url, $apikey, 'project' );
            $projects->find( 'all' );
        }
        
        return (!projects || $projects->errno) ? false : true;
    }
    

    private static function get_api ()
    {
        $settings = get_option( "gf_redmine_settings" );

        if (!empty( $settings["url"] ) && !empty( $settings["apikey"] ))
        {
            if (!class_exists( "RedmineAPI" ))
            {
                require_once("api/RedmineAPI.class.php");
            }

            $api = new RedmineAPI( $settings["url"], $settings["apikey"], 'issue' );
        }

        if (!$api || $api->errno)
            return null;

        return $api;
    }
    
    
    public static function settings_page ()
    {
        if (!class_exists("RGRedmineUpgrade"))
            require_once("plugin-upgrade.php");

        if (rgpost( "uninstall" ))
        {
            check_admin_referer( "uninstall", "gf_redmine_uninstall" );
            self::uninstall();

            ?>
            <div class="updated fade" style="padding:20px;"><?php _e(sprintf("Gravity Forms Redmine Add-On has been successfully uninstalled. It can be reactivated from the %splugins page%s.", "<a href='plugins.php'>","</a>"), "gravitymine")?></div>
            <?php
            return;
        }
        else if (rgpost( "gf_redmine_submit" ))
        {
            check_admin_referer("update", "gf_redmine_update");
            
            $settings = array(
            	"url" 		=> $_POST["gf_redmine_url"],
            	"apikey"	=> $_POST["gf_redmine_apikey"]
            );
            
            update_option( "gf_redmine_settings", $settings );
        }
        else
        {
            $settings = get_option("gf_redmine_settings");
        }

        //feedback
        $feedback_image_url 	= "";
        $feedback_image_apikey 	= "";
        $is_valid_url			= false;
        $is_valid_apikey 		= false;
        
        if (!empty( $settings["url"] ))
        {
            $is_valid_url		= self::is_valid_url( $settings["url"] );
            
            $icon_url			= $is_valid_url ? "tick.png" : "stop.png";
            $feedback_image_url = sprintf( '<img src="%s/images/%s" />', self::get_base_url(), $icon_url );
            
            if ($is_valid_url)
            {
				$is_valid_apikey = (!empty( $settings["apikey"] ))
					? self::is_valid_login( $settings["url"], $settings["apikey"] )
					: false;
				
				$icon_apikey			= $is_valid_apikey ? "tick.png" : "stop.png";
				$feedback_image_apikey 	= sprintf( '<img src="%s/images/%s" />', self::get_base_url(), $icon_apikey );
			}
        }
        
        ?>
        <style>
            .valid_credentials { color:green; }
            .invalid_credentials { color:red; }
        </style>

        <form method="post" action="">
            <?php wp_nonce_field("update", "gf_redmine_update") ?>
            <h3><?php _e("Redmine Account Information", "gravitymine") ?></h3>
            <p style="text-align: left;">
                <?php _e( '<a href="http://www.redmine.org/" target="_blank">Redmine</a> description. Use Gravity Forms to collect customer information and automatically report an issue.', "gravitymine" ) ?>
            </p>

            <table class="form-table">
                <tr class="<?php echo $hidden_class ?>">
                    <th scope="row"><label for="gf_redmine_url"><?php _e("Redmine URL", "gravitymine"); ?></label> </th>
                    <td>
                    	<input type="text" id="gf_redmine_url" name="gf_redmine_url" value="<?php echo esc_attr($settings["url"]) ?>" size="50" />
                    	<?php echo $feedback_image_url ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="gf_redmine_apikey"><?php _e("Redmine API Key", "gravitymine"); ?></label> </th>
                    <td>
                        <input type="password" id="gf_redmine_apikey" name="gf_redmine_apikey" value="<?php echo esc_attr($settings["apikey"]) ?>" size="50"/>
                        <?php echo $feedback_image_apikey ?>
                    </td>
                </tr>
                
                <tr class="<?php echo $hidden_class ?>">
                    <td colspan="2" class="<?php echo $class ?>"><?php echo $message ?></td>
                </tr>
                
                <tr>
                    <td colspan="2" ><input type="submit" name="gf_redmine_submit" class="button-primary" value="<?php _e("Save Settings", "gravitymine") ?>" /></td>
                </tr>
            </table>
        </form>

        <form action="" method="post">
            <?php wp_nonce_field("uninstall", "gf_redmine_uninstall") ?>
            <?php if(GFCommon::current_user_can_any("gravityforms_redmine_uninstall")){ ?>
                <div class="hr-divider"></div>

                <h3><?php _e("Uninstall Redmine Add-On", "gravitymine") ?></h3>
                <div class="delete-alert"><?php _e("Warning: This operation deletes ALL Redmine Feeds.", "gravitymine") ?>
                    <?php
                    $uninstall_button = '<input type="submit" name="uninstall" value="' . __("Uninstall Redmine Add-On", "gravitymine") . '" class="button" onclick="return confirm(\'' . __("Warning: ALL Redmine Feeds will be deleted. This cannot be undone. \'OK\' to delete, \'Cancel\' to stop", "gravitymine") . '\');"/>';
                    echo apply_filters("gform_redmine_uninstall_button", $uninstall_button);
                    ?>
                </div>
            <?php } ?>
        </form>
        <?php
    }
    
    
    public static function redmine_page(){
        $view = rgar($_GET,"view");
        if($view == "edit")
            self::edit_page($_GET["id"]);
        else
            self::list_page();
    }
    
    
    //Returns true if the current page is a Feed page. Returns false if not
    private static function is_redmine_page ()
    {
        $current_page = trim( strtolower( rgget( "page" ) ) );
        $redmine_pages = array( "gf_redmine" );

        return in_array( $current_page, $redmine_pages );
    }
    
    
    private static function is_gravityforms_installed(){
        return class_exists("RGForms");
    }

    private static function is_gravityforms_supported ()
    {
        if (class_exists( "GFCommon" )) {
            $is_correct_version = version_compare( GFCommon::$version, self::$min_gravityforms_version, ">=" );
            return $is_correct_version;
        }
        else
        {
            return false;
        }
    }

    protected static function has_access ($required_permission)
    {
        $has_members_plugin = function_exists( 'members_get_capabilities' );
        $has_access = $has_members_plugin ? current_user_can( $required_permission ) : current_user_can("level_7");
        if($has_access)
            return $has_members_plugin ? $required_permission : "level_7";
        else
            return false;
    }

    //Returns the url of the plugin's root folder
    protected function get_base_url ()
    {
        return plugins_url( null, __FILE__ );
    }

    //Returns the physical path of the plugin's root folder
    protected function get_base_path ()
    {
        $folder = basename( dirname( __FILE__ ) );
        return WP_PLUGIN_DIR . "/" . $folder;
    }

    
    /*
     * PLUGIN UPGRADE
     */
    
	public static function flush_version_info(){
        if (!class_exists( 'RGRedmineUpgrade' ))
            require_once("plugin-upgrade.php");

        RGRedmineUpgrade::set_version_info( false );
    }
    
    public static function plugin_row(){
        if (!self::is_gravityforms_supported()) {
            $message = sprintf( __("Gravity Forms " . self::$min_gravityforms_version . " is required. Activate it now or %spurchase it today!%s"), "<a href='http://www.gravityforms.com'>", "</a>" );
            RGRedmineUpgrade::display_plugin_message( $message, true );
        }
        else
        {
            $version_info = RGRedmineUpgrade::get_version_info(self::$slug, self::get_key(), self::$version);

            if(!$version_info["is_valid_key"]){
                $new_version = version_compare(self::$version, $version_info["version"], '<')
                	? __('There is a new version of the Gravity Mine Add-On available.', 'gravitymine') .' <a class="thickbox" title="Gravity Forms Redmine Add-On" href="plugin-install.php?tab=plugin-information&plugin=' . self::$slug . '&TB_iframe=true&width=640&height=808">'. sprintf(__('View version %s Details', 'gravitymine'), $version_info["version"]) . '</a>. '
                	: '';
                $message = $new_version . sprintf(__('%sRegister%s your copy of Gravity Forms to receive access to automatic upgrades and support. Need a license key? %sPurchase one now%s.', 'gravitymine'), '<a href="admin.php?page=gf_settings">', '</a>', '<a href="http://www.gravityforms.com">', '</a>') . '</div></td>';
                RGRedmineUpgrade::display_plugin_message($message);
            }
        }
    }
    
    //Creates or updates database tables. Will only run when version changes
    private static function setup ()
    {
        if (get_option( "gf_redmine_version" ) != self::$version)
            GFRedmineData::update_table();
            
        update_option( "gf_redmine_version", self::$version );
    }
    
    
    /*
     * PLUGIN ACTIVATION
     */
    
    public static function add_permissions(){
        global $wp_roles;
        $wp_roles->add_cap( "administrator", "gravityforms_redmine" );
        $wp_roles->add_cap( "administrator", "gravityforms_redmine_uninstall" );
    }
    
}

?>