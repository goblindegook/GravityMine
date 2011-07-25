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
     * Redmine API constructor.
     */
    function RedmineAPI ($data = array())
    {
        $this->api_params   = array();
        $this->api_version  = array(
            'min'   => '1.0.5',
            'max'   => null,
        );
    
        if (!empty( $data['_site'] ))
        {
            $this->site = substr( $data['_site'], -1 ) == '/' ? $data['_site'] : $data['_site'] . '/';
        }
        
        if (!empty( $data['_element'] ))
        {
            $this->element_name = $data['_element'];
        }
        
        if (!empty( $data['_key'] ))
        {
            $this->api_key              = $data['_key'];
            $this->api_params['key']    = $data['_key'];
        }
        
        unset( $data['_site'] );
        unset( $data['_element'] );
        unset( $data['_key'] );
        
        parent::__construct( $data );
    }
    
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