<?php

/**
 * multiple connection handler
 * @author	Justin Sundquist
 * @copyright 2018
 * @notes: minimum php requirement: 7.X
 */
 
 
class multiconnections 
{
    private $connections;
    private $multi_handle;
    private $url_queue;
    private $url_queue_size;
    private $_proxies;
    
    /**
     * constructor
     *
     * @access public
     */
     
    public function __construct() 
    {
        $this->multi_handle = curl_multi_init();
    }
    
    /**
     * set
     *
     * @access public
     * 
     * @param string $variable local variable name being added to this class
     * @param mixed $value value of local variable
     * @return void
     */
     
    public function set($variable,$value) 
    {
        $local_var = '_'.$variable;
        $this->$local_var = $value;
    }
    
    /**
     * add_urls
     *
     * @access public
     * 
     * @param array list of urls we will be using
     * @return void
     */
     
    public function add_urls($url_list) 
    {
        unset($this->url_queue);
        $this->url_queue = [];
        
        $this->url_queue = $url_list;
        $this->url_queue_size = count($this->url_queue);
    }
    
    /**
     * process
     *
     * @access public
     * 
     * @param int	 $rolling_window rolling window of connections we will be processing at a given time
     * @param int 	 $buffer_count number of connections that will be processed before yielding back to call
     * @param array  $custom_options array of custom http options
     * @param bool 	 $gzip is gzip compression being used?
     * @param bool	 $debug should debugging info be returned?
     * 
     * @notes to use this function, it should be called like this: foreach ({instance}->process(5,1,array(),true,false) as $responses) {}
     * 			after the number of buffer_count connections has been processedm all responses can be returned for further processing.
     *
     * @return void
     */
     
    public function process($rolling_window,$buffer_count=5,$custom_options=array(),$gzip=true,$debug=false)
    {
        $rolling_window = ($this->url_queue_size < $rolling_window) ? $this->url_queue_size : $rolling_window;
        $final_url_responses = [];
        
        $master = curl_multi_init();
        $curl_arr = array();

        // add additional curl options here
        $std_options = array(CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_FOLLOWLOCATION => true,
                            CURLOPT_MAXREDIRS => 5,
                            CURLOPT_CONNECTTIMEOUT=>60,
                            CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13',
                            CURLOPT_VERBOSE=>$debug);
        
        if ($gzip) {
            $std_options[CURLOPT_ENCODING] = 'gzip';
        }
        
        $options = ($custom_options) ? ($std_options + $custom_options) : $std_options;

        // start the first batch of requests
        for ($i = 0; $i < $rolling_window; $i++) {
            $ch = curl_init();
            $options[CURLOPT_URL] = $this->url_queue[$i]['url'];
            $options[CURLOPT_PRIVATE] = serialize($this->url_queue[$i]['params']);
            
            if (!empty($this->_proxies)) {
                $options[CURLOPT_PROXY] = $this->_proxies[rand(0,count($this->_proxies)-1)];
            }
            
            if (!empty($this->url_queue[$i]['post_data'])) {
				$options[CURLOPT_POST] = true;
				$options[CURLOPT_POSTFIELDS] = $this->url_queue[$i]['post_data'];
			}
						
            curl_setopt_array($ch,$options);
            curl_multi_add_handle($master, $ch);
        }
        
        do {
            curl_multi_select($master);
            
            while(($execrun = curl_multi_exec($master, $running)) == CURLM_CALL_MULTI_PERFORM);
            
            if($execrun != CURLM_OK)
                break;
                    
            // a request was just completed -- find out which one
            while($done = curl_multi_info_read($master)) {
                $response_code = curl_getinfo($done['handle'],CURLINFO_HTTP_CODE);
                $response_params = unserialize(curl_getinfo($done['handle'],CURLINFO_PRIVATE));
               
                if ($response_code == 200)  {
                    $output = curl_multi_getcontent($done['handle']);
                    $final_url_responses['success'][] = array('params'=>$response_params,'response'=>$output);
                    
                    if ((count($final_url_responses['success']) == $buffer_count) || (($this->url_queue_size-$i) < $buffer_count)) {
                        yield $final_url_responses;
                        unset($final_url_responses);
                        $final_url_responses = [];
                    }
                    
                    // start a new request (it's important to do this before removing the old one)
                    if ($i < $this->url_queue_size) {
                        $ch = curl_init();
                        $options[CURLOPT_URL] = $this->url_queue[$i]['url'];
                        $options[CURLOPT_PRIVATE] = serialize($this->url_queue[$i]['params']); 
                        
                        if (!empty($this->_proxies)) {
							$options[CURLOPT_PROXY] = $this->_proxies[rand(0,count($this->_proxies)-1)];
						}
            
                        if (!empty($this->url_queue[$i]['post_data'])) {
							$options[CURLOPT_POST] = true;
							$options[CURLOPT_POSTFIELDS] = $this->url_queue[$i]['post_data'];
						}
        
                        curl_setopt_array($ch,$options);
                        curl_multi_add_handle($master, $ch);
                    }
                    
                    curl_multi_remove_handle($master, $done['handle']);
                    curl_close($done['handle']);
                } else {
                // request failed.  add error handling.
                    echo 'failed';
                }
                
                $i++;
            }
        } while ($running);
    
        curl_multi_close($master);
        return $final_url_responses;
    }
    
}
