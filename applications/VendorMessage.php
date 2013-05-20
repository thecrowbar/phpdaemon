<?php

/**
 * Class the generate and process received messages from the vendor connection
 * @author jcrow
 */
class VendorMessage {
	/**
	 * The object that creates and validates a TCP message
	 * @var ISO8583Trans
	 */
	public $ISO8583;
	
	/**
	 * VendorMessage() - create a VendorMessge object from a DB record
	 * @param Assoc[] $trans_row - record of this transaction in the DB
	 * @throws Exception
	 */
	public function __construct($trans_row) {
		if (Vendor::$decrypt_data) {
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Attempting to decrypt our account number!');
			$result = Vendor::decrypt_data($trans_row['pri_acct_no']);
			if (is_array($result)) {
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$trans_row used:'.print_r($trans_row, true));
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Vendor::decrypt_data():'.print_r($result, true));
				// there was some sort of error
				throw new Exception('Unable to decrypt account number! Error:'.$result['error_msg']);
			} else {
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Decypted card number:'.print_r($result, true));
				$trans_row['pri_acct_no'] = $result;
			}
		}
		// check if this a refund
		if ($trans_row['processing_code'] === '200000') {
			$this->ISO8583 = new ISO8583Trans($trans_row, ISO8583Trans::TRANS_TYPE_REFUND);
		} else if($trans_row['processing_code'] === '000000'){
			$this->ISO8583 = new ISO8583Trans($trans_row, ISO8583Trans::TRANS_TYPE_RETAIL);
		}else {
			$this->ISO8583 = new ISO8583Trans($trans_row);
		}
			
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
