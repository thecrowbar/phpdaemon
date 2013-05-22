<?php
/**
 * VendorServer simply listens on a TCP port and responds to ISO8583 messages
 */
class VendorServer extends NetworkServer{
	/**
	 * The raw byte string to respond to a ISO8583 echo message
	 * @var String (binary)
	 */
	public $echo_resp_tcp = "";
	
	/**
	 * TCP Port to listen on
	 * @var Int
	 */
	public $listenPort = 28275;
	
//	public function init(){
//		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' start');
//		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' config:',print_r($this->config, true));
//		
//		// set our config values for easier access
//		$this->Listenport = $this->config->port->value;
//		
//		// get an intial connection
//		//$svr_config = new Daemon_ConfigSection(array('servers'=>"{$this->vendorhost}", 'port'=>"{$this->vendorport}"));
//		//$this->vendorclient = VendorClient::getInstance($svr_config, $this);
//	}
	
	/**
	 * Setting default config options
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// listen to
			'listen'         => 'tcp://0.0.0.0',
			// default port
			'port'     => 28275,
			// authentication required
			'auth'           => 0,
			// user name
			'username'       => 'User',
			// password
			'password'       => 'Password',
			// allowed clients ip list
			'allowedclients' => null,
		);
	}
	
}

/**
 * VendorServerConnection handles listening for and sending data over the TCP socket
 */
class VendorServerConnection extends Connection{
	
	public function onRead(){
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' starting');
	}
	
}

?>
