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

add_action( 'init',  array( 'GFRedmine', 'init' ) );

register_activation_hook( __FILE__, array( 'GFRedmine', 'add_permissions' ) );

class GFRedmine
{
    private static $path                        = 'gravitymine/gravitymine.php';
    private static $url                         = 'http://log.pt';
    private static $slug                        = 'gravitymine';
    private static $textdomain                  = 'gravitymine';
    private static $version                     = '0.1';
    private static $min_gravityforms_version    = '1.5';
    private static $min_redmine_version         = '1.0.5';
    
    public static function init ()
    {
        if (RG_CURRENT_PAGE == "plugins.php")
        {
            //loading translations
            load_plugin_textdomain( self::$textdomain, FALSE, '/gravitymine/languages' );
            add_action( 'after_plugin_row_' . self::$path, array( 'GFRedmine', 'plugin_row' ) );

            //force new remote request for version info on the plugin page
            self::flush_version_info();
        }

        if (!self::is_gravityforms_supported())
           return;
           
        if (is_admin())
        {
            //loading translations
            load_plugin_textdomain( self::$textdomain, FALSE, '/gravitymine/languages' );

            //integrating with Members plugin
            if(function_exists('members_get_capabilities'))
                add_filter( 'members_get_capabilities', array( 'GFRedmine', 'members_get_capabilities' ) );

            //creates the subnav left menu
            add_filter( 'gform_addon_navigation', array( 'GFRedmine', 'create_menu') );
            
            if (self::is_redmine_page())
            {
                //enqueueing sack for AJAX requests
                wp_enqueue_script( array( "sack" ) );
                
                require_once( self::get_base_path() . "/data.php" );
                
                if (!class_exists( "RGRedmineUpgrade" ))
                    require_once( "plugin-upgrade.php" );
                
                require_once( GFCommon::get_base_path() . "/tooltips.php" );
                
                add_filter( 'gform_tooltips', array( 'GFRedmine', 'tooltips' ) );
                
                self::setup();
            }
            else if (in_array( RG_CURRENT_PAGE, array( "admin-ajax.php" ) ))
            {
                require_once( self::get_base_path() . "/data.php");
                
                add_action( 'wp_ajax_gf_redmine_update_feed_active'	, array( 'GFRedmine', 'update_feed_active' ) );
                add_action( 'wp_ajax_gf_select_redmine_form'		, array( 'GFRedmine', 'select_redmine_form' ) );
                add_action( 'wp_ajax_gf_redmine_confirm_settings'	, array( 'GFRedmine', 'confirm_settings' ) );
            }
            else if (RGForms::get( "page" ) == "gf_settings")
            {
                RGForms::add_settings_page( "Redmine", array( 'GFRedmine', 'settings_page' ), self::get_base_url() . "/images/redmine-icon-32.png");
            }
            
        }
        else
        {
            //loading data class
            require_once( self::get_base_path() . "/data.php" );

            // handling form submission
            add_action( 'gform_post_submission'  , array( 'GFRedmine', 'report_issue'    ), 10, 2 );
            add_filter( 'gform_validation'       , array( 'GFRedmine', 'issue_validation') );
        }
    }
    
    public static function update_feed_active ()
    {
        check_ajax_referer( 'gf_redmine_update_feed_active', 'gf_redmine_update_feed_active' );
        $id = RGForms::post( 'feed_id' );
        $feed = GFRedmineData::get_feed( $id );
        GFRedmineData::update_feed( $id, $feed['form_id'], RGForms::post( 'is_active' ), $feed["meta"] );
    }
    
    
    /* API METHODS */
    
    private static function is_valid_url ($url)
    {
        if (!empty( $url ))
        {
            if (!class_exists( "RedmineAPI" ))
            {
                require_once( 'api/RedmineAPI.class.php' );
            }
            
            $projects = new RedmineAPI( array( '_site' => $url, '_element' => 'project' ) );
            $projects->find( 'all' );
        }

        return (!$projects || $projects->errno == 6) ? false : true;
    }
    
    private static function is_valid_login ($url, $apikey)
    {
        if (!empty( $url ) && !empty( $apikey ))
        {
            if (!class_exists( "RedmineAPI" ))
            {
                require_once( 'api/RedmineAPI.class.php' );
            }
            
            $projects = new RedmineAPI( array( '_site' => $url, '_key' => $apikey, '_element' => 'project' ) );
            $projects->find( 'all' );
        }
        
        return (!$projects || $projects->errno) ? false : true;
    }
    
    private static function get_api ( $element, $data = array() )
    {
        $settings = get_option( "gf_redmine_settings" );

        if (!empty( $element ) && !empty( $settings["url"] ) && !empty( $settings["apikey"] ))
        {
            if (!class_exists( "RedmineAPI" ))
            {
                require_once( "api/RedmineAPI.class.php" );
            }
            
            $data = array_merge( $data, array(
                '_site'     => $settings['url'],
                '_key'      => $settings['apikey'],
                '_element'  => $element
            ) );

            $api = new RedmineAPI( $data );
        }
        
        if (!$api || $api->errno)
            return null;

        return $api;
    }
    
    
    /* BACKEND INTERFACE */
    
    public static function create_menu ($menus)
    {
        // Adding submenu if user has access
        $permission = self::has_access( 'gravityforms_redmine' );
        if(!empty( $permission ))
            $menus[] = array(
                'name'          => 'gf_redmine',
                'label'         => __( 'Redmine', self::$textdomain),
                'callback'      =>  array( 'GFRedmine', 'redmine_page' ),
                'permission'    => $permission
            );
        return $menus;
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
            <div class="updated fade" style="padding:20px;"><?php _e( 'Gravity Forms Redmine Add-On has been successfully uninstalled. It can be reactivated from the <a href="plugins.php">plugins page</a>.', self::$textdomain ) ?></div>
            <?php
            return;
        }
        else if (rgpost( "gf_redmine_submit" ))
        {
            check_admin_referer( "update", "gf_redmine_update" );
            
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
            <h3><?php _e( "Redmine Account Information", self::$textdomain ) ?></h3>
            <p style="text-align: left;">
                <?php _e( '<a href="http://www.redmine.org/" target="_blank">Redmine</a> is a free and open source, web-based project management and bug-tracking tool. Use Gravity Forms to collect customer information and automatically report an issue.', self::$textdomain ) ?>
            </p>

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="gf_redmine_url"><?php _e( "Redmine URL", self::$textdomain ); ?></label> </th>
                    <td>
                    	<input type="text" id="gf_redmine_url" name="gf_redmine_url" value="<?php echo esc_attr($settings["url"]) ?>" size="50" />
                    	<?php echo $feedback_image_url ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="gf_redmine_apikey"><?php _e("Redmine API Key", self::$textdomain); ?></label> </th>
                    <td>
                        <input type="password" id="gf_redmine_apikey" name="gf_redmine_apikey" value="<?php echo esc_attr($settings["apikey"]) ?>" size="50"/>
                        <?php echo $feedback_image_apikey ?>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" ><input type="submit" name="gf_redmine_submit" class="button-primary" value="<?php _e( "Save Settings", self::$textdomain ) ?>" /></td>
                </tr>
            </table>
        </form>

        <form action="" method="post">
            <?php wp_nonce_field( "uninstall", "gf_redmine_uninstall" ) ?>
            <?php
                if (GFCommon::current_user_can_any( "gravityforms_redmine_uninstall" ))
                {
            ?>
                <div class="hr-divider"></div>

                <h3><?php _e("Uninstall Redmine Add-On", self::$textdomain) ?></h3>
                <div class="delete-alert"><?php _e("Warning: This operation deletes ALL Redmine Feeds.", self::$textdomain) ?>
                    <?php
                    $uninstall_button = '<input type="submit" name="uninstall" value="' . __( "Uninstall Redmine Add-On", self::$textdomain ) . '" class="button" onclick="return confirm(\'' . __( "Warning: ALL Redmine feeds will be deleted. This cannot be undone.", self::$textdomain ) . '\');"/>';
                    echo apply_filters( 'gform_redmine_uninstall_button', $uninstall_button);
                    ?>
                </div>
            <?php } ?>
        </form>
        <?php
    }
    
    
    public static function redmine_page ()
    {
        $view   = RGForms::get( 'view' );
        $id     = RGForms::get( 'id' );
        
        if ('edit' == $view)
            self::edit_page( $id );
        else
            self::list_page();
    }
    
    
    private static function list_page ()
    {
        if (!self::is_gravityforms_supported())
        {
            die( sprintf( __( 'The Redmine Add-On requires Gravity Forms %s. Upgrade automatically on the <a href="plugins.php">Plugin page</a>.', self::$textdomain), self::$min_gravityforms_version ) );
        }

        $action         = RGForms::post( 'action' );
        $bulk_action    = RGForms::post( 'bulk_action' );

        if ('delete' == $action)
        {
            check_admin_referer( 'list_action', 'gf_redmine_list' );
            $id = absint( $_POST["action_argument"] );
            
            GFRedmineData::delete_feed( $id );
            
            ?><div class="updated fade" style="padding:6px"><?php _e( "Feed deleted.", self::$textdomain ) ?></div><?php
        }
        else if (!empty( $bulk_action ))
        {
            check_admin_referer( 'list_action', 'gf_redmine_list' );
            $selected_feeds = RGForms::post( 'feed' );
            
            if (is_array( $selected_feeds ))
            {
                foreach ($selected_feeds as $feed_id)
                    GFRedmineData::delete_feed( $feed_id );
            }
            
            ?><div class="updated fade" style="padding:6px"><?php _e("Feeds deleted.", self::$textdomain) ?></div><?php
        }

        ?>
        <div class="wrap">
            <img alt="<?php _e( 'Redmine', self::$textdomain ) ?>" src="<?php echo self::get_base_url() ?>/images/redmine-icon-32.png" style="float: left; margin: 15px 7px 0 0;" />
            <h2><?php _e( 'Redmine Forms', self::$textdomain); ?>
                <a class="button add-new-h2" href="admin.php?page=gf_redmine&view=edit&id=0"><?php _e( 'Add New', self::$textdomain ) ?></a>
            </h2>

            <form id="feed_form" method="post">
                <?php wp_nonce_field( 'list_action', 'gf_redmine_list' ) ?>
                <input type="hidden" id="action" name="action"/>
                <input type="hidden" id="action_argument" name="action_argument"/>

                <div class="tablenav">
                    <div class="alignleft actions" style="padding:8px 0 7px;">
                        <label class="hidden" for="bulk_action"><?php _e( 'Bulk action', self::$textdomain ) ?></label>
                        <select name="bulk_action" id="bulk_action">
                            <option value=''><?php _e( 'Bulk action', self::$textdomain ) ?></option>
                            <option value='delete'><?php _e( 'Delete', self::$textdomain ) ?></option>
                        </select>
                        <input type="submit" class="button" value="<?php _e( 'Apply', self::$textdomain ) ?>" onclick="if (jQuery('#bulk_action').val() == 'delete' && !confirm('<?php _e( 'Delete selected feeds?', self::$textdomain ) ?>')) { return false; } return true;" />
                    </div>
                </div>
                <table class="widefat fixed" cellspacing="0">
                    <thead>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e( 'Form', self::$textdomain ) ?></th>
                        </tr>
                    </thead>
                    <tfoot>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e( 'Form', self::$textdomain ) ?></th>
                        </tr>
                    </tfoot>

                    <tbody class="list:user user-list">
                        <?php
                        
                        $settings = GFRedmineData::get_feeds();
                        
                        if (is_array( $settings ) && sizeof( $settings ) > 0)
                        {
                            foreach ($settings as $setting)
                            {
                                $feed_status = $setting['is_active'] ? __( 'Active', self::$textdomain ) : __( 'Inactive', self::$textdomain );
                                ?>
                                <tr class='author-self status-inherit' valign="top">
                                    <th scope="row" class="check-column"><input type="checkbox" name="feed[]" value="<?php echo $setting['id'] ?>" /></th>
                                    <td><img src="<?php echo self::get_base_url() ?>/images/active<?php echo intval( $setting['is_active'] ) ?>.png" alt="<?php echo $feed_status; ?>" title="<?php echo $feed_status; ?>" onclick="ToggleActive(this, <?php echo $setting['id'] ?>);" /></td>
                                    <td class="column-title">
                                        <a href="admin.php?page=gf_redmine&view=edit&id=<?php echo $setting['id'] ?>" title="<?php _e( 'Edit', self::$textdomain ) ?>"><?php echo $setting['form_title'] ?></a>
                                        <div class="row-actions">
                                            <span class="edit">
                                                <a title="<?php _e( 'Edit', self::$textdomain) ?>" href="admin.php?page=gf_redmine&view=edit&id=<?php echo $setting['id'] ?>" title="<?php _e( 'Edit', self::$textdomain ) ?>"><?php _e( 'Edit', self::$textdomain ) ?></a>
                                            </span> | <span class="edit">
                                                <a title="<?php _e( 'Delete', self::$textdomain ) ?>" href="javascript: if (confirm('<?php _e( 'Delete this feed?', self::$textdomain ) ?>')) { DeleteSetting(<?php echo $setting['id'] ?>); }"><?php _e( 'Delete', self::$textdomain) ?></a>
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                                <?php
                            }
                        }
                        else{
                            ?>
                            <tr>
                                <td colspan="3" style="padding: 20px;">
                                    <?php _e( 'No Redmine feeds configured. <a href="admin.php?page=gf_redmine&view=edit&id=0">Create one.</a>', self::$textdomain ); ?>
                                </td>
                            </tr>
                            <?php
                        }
                        ?>
                    </tbody>
                </table>
            </form>
        </div>
        <script type="text/javascript">
        
            function DeleteSetting (id) {
                jQuery("#action_argument").val(id);
                jQuery("#action").val("delete");
                jQuery("#feed_form")[0].submit();
            }

            function ToggleActive (img, feed_id) {
                var is_active = img.src.indexOf('active1.png') >=0
                if (is_active)
                {
                    img.src = img.src.replace('active1.png', 'active0.png');
                    jQuery(img).attr('title', '<?php _e( 'Inactive', self::$textdomain ) ?>').attr('alt', '<?php _e( 'Inactive', self::$textdomain ) ?>');
                }
                else
                {
                    img.src = img.src.replace('active0.png', 'active1.png');
                    jQuery(img).attr('title', '<?php _e( 'Active', self::$textdomain ) ?>').attr('alt', '<?php _e( 'Active', self::$textdomain ) ?>');
                }

                var mysack = new sack( '<?php echo admin_url( 'admin-ajax.php' ) ?>' );
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar( 'action'                         , 'gf_redmine_update_feed_active' );
                mysack.setVar( 'gf_redmine_update_feed_active'  , '<?php echo wp_create_nonce( 'gf_redmine_update_feed_active' ) ?>' );
                mysack.setVar( 'feed_id'                        , feed_id );
                mysack.setVar( 'is_active'                      , is_active ? 0 : 1 );
                mysack.encVar( 'cookie'                         , document.cookie, false );
                
                mysack.onError = function() { alert( '<?php echo esc_js( __( "Error while updating feed", self::$textdomain ) ) ?>' ) };
                
                mysack.runAJAX();
                
                return true;
            }
        </script>
        <?php
    }
    
    
    private static function edit_page ()
    {
        // TODO
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
    
	public static function flush_version_info ()
	{
        if (!class_exists( 'RGRedmineUpgrade' ))
            require_once("plugin-upgrade.php");

        RGRedmineUpgrade::set_version_info( false );
    }
    
    public static function plugin_row ()
    {
        if (!self::is_gravityforms_supported())
        {
            $message = sprintf( __('Gravity Forms %s is required. Activate it now or <a href="http://www.gravityforms.com">purchase it today!</a>'), self::$min_gravityforms_version );
            RGRedmineUpgrade::display_plugin_message( $message, true );
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