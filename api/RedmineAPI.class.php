<?php

require_once( 'ActiveResource.php' );

class RedmineAPI {
    var $version = "1.3";
    var $errorMessage;
    var $errorCode;
    var $timeout = 300;
    var $chunkSize = 8192;
    var $apiUrl;
    var $apiKey;

    /**
     * Connect to the Redmine API.
     */
    function RedmineAPI ($apiurl, $apikey)
    {
        $this->apiUrl = parse_url( $apiurl );
        $this->apiKey = $apikey;
    }
    
    
    function setTimeout ($seconds)
    {
        if (is_int( $seconds )) {
            $this->timeout = $seconds;
            return true;
        }
        return false;
    }
    
    
    function getTimeout ()
    {
        return $this->timeout;
    }
    
    
    function __call ($method, $params)
    {
    	
    }
    
}

?>