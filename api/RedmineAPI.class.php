<?php

require_once( 'ActiveResource.php' );


class RedmineAPI extends ActiveResource {
    var $site               = false;
    var $element_name       = false;
    var $request_format     = 'xml';
    
    var $min_api_version    = '1.0.5';
    var $api_key            = false;
    
    /**
     * Connect to the Redmine API.
     */
    function RedmineAPI ($site, $key, $element, $data = array())
    {
        $this->site         = $site;
        $this->element_name = $element;
        $this->api_key      = $key;
        
        parent::__construct( $data );
    }
    
    
    function find ($id = false, $options = array())
    {
        if ($this->api_key)
        {
            $options = array_merge( $options, array( 'key' => $this->api_key ) );
        }
        
        return parent::find( $id, $options );
    }
        
}

?>