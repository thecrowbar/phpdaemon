<?php

/**
 * Class the generate and process received messages from the vendor connection
 * @author jcrow
 */
class VendorMessage {
	public $ISO8583;
	
	public function __construct($db_row) {
		$this->ISO8583 = new ISO8583Trans($db_row);
	}
	/**
	 * Generates a string of bytes to send for a TCP keep alive
	 * @return String (binary)
	 */
	public static function getTCPKeepalive(){
		$ISO8583 = new ISO8583();
		$ISO8583->addMTI('0800');
		$ISO8583->addData(3, '990000'); // processing code
		$ISO8583->addData(11, '000001'); // system trace number
		$ISO8583->addData(24, '001'); // network international ID
		$ISO8583->addData(41, '0000000'); // terminal id for keep-alive is 0000000
		$ISO8583->addData(42, '000000000000'); // merchant number for keep-alive is 000000000000
		return $ISO8583->getTCPMessage();
	}
	
	public function getTCPMessage() {
		return $this->ISO8583->getTCPMessage();
	}
	
	
}

?>
