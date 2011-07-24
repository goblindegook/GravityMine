<?php

class RGRedmineUpgrade{

    public static function set_version_info ($version_info)
    {
        if ( function_exists( 'set_site_transient' ) )
            set_site_transient( 'gforms_redmine_version', $version_info, 60*60*12 );
        else
            set_transient( 'gforms_redmine_version', $version_info, 60*60*12 );
    }

    public static function display_plugin_message($message, $is_error = false)
    {
        $style = "";
        
        if($is_error)
            $style = 'style="background-color: #ffebe8;"';

        printf( '</tr><tr class="plugin-update-tr"><td colspan="5" class="plugin-update"><div class="update-message" %s>%s</div></td>', $style, $message );
    }
    
}
?>