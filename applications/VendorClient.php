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
			//'servers'				=> 'tcp://127.0.0.1,tcp://192.168.1.48',
			'servers'				=> 'tcp://167.16.0.125',
			'port'					=> 22825,
			'maxconnperserv'		=> 1,
		);
	}

	
}

?>
