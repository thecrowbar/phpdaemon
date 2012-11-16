<?php

/**
 * Description of VendorClient
 *
 * @author jcrow
 */
class VendorClient extends NetworkClient{
	public $subscribeCb = array();
	public $sqli;
	/**
	 * The jobs this object runs
	 * @var ComplexJob Object
	 */
	public $job;
	/**
	 * The AppInstance of our parent Vendor object
	 * @var Vendor Object
	 */
	public $appInstance = null;
	
	
	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// @todo add description strings
			'servers'				=> 'tcp://192.168.1.48,tcp://10.11.12.13',
			'port'					=> 1234,
			'maxconnperserv'		=> 1,
		);
	}

	
}

?>
