<?php

require_once( 'ActiveResource.php' );


class RedmineAPI extends ActiveResource {
    var $site           = false;
    var $element_name   = false;
    var $request_format = 'xml';
    
    var $api_version    = false;
    var $api_key        = false;
    var $api_params     = false;
    
    /**
     * Connect to the Redmine API.
     */
    function RedmineAPI ($site, $key, $element, $data = array())
    {
        $this->api_version      = array(
            'min'   => '1.0.5',
            'max'   => null,
        );
    
        $this->site         = $site;
        $this->element_name = $element;
        $this->api_params   = array();
        
        if (!empty( $key ))
        {
            $this->api_key              = $key;
            $this->api_params['key']    = $key;
        }
        
        parent::__construct( $data );
    }
    
    
    /*
    function find ($id = false, $options = array())
    {
        return parent::find( $id, $options );
    }
    */
    
    
	function _send_and_receive ($url, $method, $data = array()) {
	
		if (!empty( $this->api_params ))
		{
		    $separator = (strpos( $url, '?' ) === false) ? '?' : '&';
    		$url = $url . $separator . http_build_query( $this->api_params );
		}
		
		return parent::_send_and_receive( $url, $method, $data );
	}
    
}

?>