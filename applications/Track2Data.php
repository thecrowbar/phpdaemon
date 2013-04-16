<?php
/**
 * Track2Data - wrapper to hold the values encoded in Track2 of a bank card
 *
 * @author jcrow@daemon.io
 */
class Track2Data {
	/**
	 * Primary Account Number
	 * @var String
	 */
	public $pan = '';
	
	/**
	 * Last 4 digits of the card number (PAN)
	 * @var String
	 */
	public $cc_last_four = '';
	
	/**
	 * Expiration Date of the card (YYMM) or "=" if not present
	 * @var String
	 */
	public $exp = '';
	
	/**
	 * Service Code, 3 digits, or "=" if not present
	 * @var String
	 */
	public $svc_code = '';
	
	/**
	 * Discretionary data
	 * @var String
	 */
	public $dd = '';
	
	/**
	 * CreditCard object to validate the PAN
	 * @var CreditCard
	 */
	private $credit_card;
	
	/**
	 * The Type of CreditCard; VS, MC, AX, or DS
	 * @var String
	 */
	public $cc_type = '';
	
	public function __construct($tk2) {
		// check for start and end characters
		if (substr($tk2, 0,1) !== ';') {
			throw new Exception('Invalid STX character');
		} else if (substr($tk2, -1) !== '?') {
			throw new exception('Invalid ETX character');
		}
		
		// split data on "=" separator
		$da = explode('=',substr($tk2, 1, -1),2);
		
		// validate the PAN
		$this->pan = $da[0];
		if (!$this->validatePAN()) {
			throw new Exception('PAN does not validate');
		}
		
		$this->cc_last_four = substr($this->pan,-4);
		
		// check for an expiration
		if (count($da) > 1 && substr($da[1],0,1) !== '=') {
			$this->exp = substr($da[1],0,4);
			$month = substr($this->exp, -2);
			$year = substr($this->exp,0,2);
			if ($year > date('y') ||
					($year == date('y') && $month >= date('m'))) {
				// CC exp valid
			} else {
				throw new Exception('Card is expired');
			}
			// now try to get the service_code value, it is the next 3 characters or = if not present
			if (substr($da[1],4,1) === '=') {
				// no svc_code present
				$this->dd = substr($da[1],5);
			} else {
				// pull svc_code and dis_data
				$this->svc_code = substr($da[1],4,3);
				$this->dd = substr($da[1],7);
			}
		} else {
			// we have a "=" in place of the expiration date check for Service code
			if (strpos($da[1], '=', 1)) {
				// we have
			}
		}
		
		// we do nothing with the rest of the data
	}
	
	private function validatePAN() {
		$this->credit_card = new CreditCard($this->pan);
		$this->cc_type = $this->credit_card->CreditCardType($this->pan);
		$this->shortenCCType();
		if ($this->cc_type !== false) {
			// PAN is a valid credit card number
			return true;
		} else {
			return false;
		}
		
	}
	
	/**
	 * Change the full CC name to the 2 character short version
	 */
	private function shortenCCType() {
		switch($this->cc_type) {
			case "Visa":
				$this->cc_type = 'VS';
				break;
			case "Master Card":
				$this->cc_type = "MC";
				break;
			case "American Express":
				$this->cc_type = 'AX';
				break;
			case "Diners Club":
			case "Carte Blanche":
			case "JCB":
			case "Discover":
				$this->cc_type='DS';
				break;
		}
	}
}

?>
