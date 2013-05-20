<?php
/**
 * Description of CreditCard
 *
 * @author jcrow
 */
class CreditCard {
	/**
	 * The plain text type of this credit card. Possible values are:
	 * Visa
	 * Master Card
	 * JCB
	 * EnRoute
	 * Discover
	 * Carte Blanche
	 * Diners Club
	 * American Express
	 * @var String
	 */
	private $card_type = "UNKNOWN";
	
	/**
	 * The credit card number to validate
	 * @var String
	 */
	private $card_no = '';
	
	public function __get($name) {
		if (property_exists($this, $name)) {
			return $this->$name;
		} else {
			throw new Exception("Unknown property: {$name} in class ".get_class($this));
		}
	}
	/**
	 * CreditCard() - validate the given account number and find the card type
	 * @param String $card_no - the card number to check
	 * @throws Exception
	 */
	public function __construct($card_no) {
		$this->card_no = str_replace("-", "", str_replace(" ", "", $card_no));
		if (strlen($this->card_no) < 14 || !is_numeric($this->card_no)) {
			throw new Exception("Card number ({$this->card_no})fails minimum checks!");
		}
		$card_type = $this->CreditCardType($this->card_no);
		if ($card_type !== 'UNKNOWN'){
			if ($this->luhn_check($this->card_no)) {
				$this->card_type = $card_type;
			} else {
				//echo "Card fails luhn check!\n";
				throw new Exception("Card fails LUHN check!");
			}
			
		} else {
			throw new Exception("Unable to determine card type!");
		}
	}
	
	/**
	 * CreditCardType() - find the card type for the given card number, or false
	 * @param String $CardNo - the card number to check
	 * @return boolean|string
	 */
	public function CreditCardType($CardNo, $short_name = false) {
		/*
		  '*CARD TYPES            *PREFIX           *WIDTH
		  'American Express       34, 37            15
		  'Diners Club            300 to 305, 36    14
		  'Carte Blanche          38                14
		  'Discover               6011              16
		  'EnRoute                2014, 2149        15
		  'JCB                    3                 16
		  'JCB                    2131, 1800        15
		  'Master Card            51 to 55          16
		  'Visa                   4                 13, 16
		 */
		
		$CreditCardType = $this->card_type;

		//Check that the minimum length of the string isn't less
		//than fourteen characters and -is- numeric
		If (strlen($CardNo) < 14 || !is_numeric($CardNo))
			return false;

		//Check the first two digits first
		switch (substr($CardNo, 0, 2)) {
			Case 34: Case 37:
				$CreditCardType = "American Express";
				break;
			Case 36:
				$CreditCardType = "Diners Club";
				break;
			Case 38:
				$CreditCardType = "Carte Blanche";
				break;
			Case 51: Case 52: Case 53: Case 54: Case 55: case 56: case 57: case 58:
				$CreditCardType = "Master Card";
				break;
		}

		//None of the above - so check the first four digits collectively
		if ($CreditCardType == "UNKNOWN") {
			switch (substr($CardNo, 0, 4)) {
				Case 2014:Case 2149:
					$CreditCardType = "EnRoute";
					break;
				Case 2131:Case 1800:
					$CreditCardType = "JCB";
					break;
				Case 6011: case 6012: // 6012 == China Union Pay
					$CreditCardType = "Discover";
					break;

			}
		}

		//None of the above - so check the first three digits collectively
		if ($CreditCardType == "UNKNOWN") {
			if (substr($CardNo, 0, 3) >= 300 && substr($CardNo, 0, 3) <= 305) {
				$CreditCardType = "American Diners Club";
			}
		}
		
		// None of the above; check the first two digits
		if ($CreditCardType == "UNKNOWN") {
			if (substr($CardNo,0,2) == 65 && strlen($CardNo) == 16) {
				$CreditCardType = "Discover";
			} else if((substr($CardNo,0,2) == 62 || substr($CardNo,0,2) == 60) 
					&& strlen($CardNo) == 16) {
				// China Union Pay; processes as Discover
				$CreditCardType = 'Discover';
			}
		}

		//None of the above - So simply check the first digit
		if ($CreditCardType == "UNKNOWN") {
			switch (substr($CardNo, 0, 1)) {
				Case 3:
					$CreditCardType = "JCB";
					break;
				Case 4:
					$CreditCardType = "Visa";
					break;
			}
		}
		if ($short_name) {
			switch($CreditCardType) {
				case 'Visa':
					$CreditCardType = 'VS';
					break;
				case 'American Express':
					$CreditCardType = 'AX';
					break;
				case 'Master Card':
					$CreditCardType = 'MC';
					break;
				case 'UNKNOWN':
					$CreditCardType = 'UK';
					break;
				default:
					$CreditCardType = 'DS';
			}
		}
		if ($CreditCardType === "UNKNOWN") {
			error_log('CardNo:'.$CardNo.' unable to determine type!');
		}

		return $CreditCardType;
	}
	
	/* Luhn algorithm number checker - (c) 2005-2008 shaman - www.planzero.org *
	* This code has been released into the public domain, however please      *
	* give credit to the original author where possible.                      */

   function luhn_check($number) {

	 // Strip any non-digits (useful for credit card numbers with spaces and hyphens)
	 $number=preg_replace('/\D/', '', $number);

	 // Set the string length and parity
	 $number_length=strlen($number);
	 $parity=$number_length % 2;

	 // Loop through each digit and do the maths
	 $total=0;
	 for ($i=0; $i<$number_length; $i++) {
	   $digit=$number[$i];
	   // Multiply alternate digits by two
	   if ($i % 2 == $parity) {
		 $digit*=2;
		 // If the sum is two digits, add them together (in effect)
		 if ($digit > 9) {
		   $digit-=9;
		 }
	   }
	   // Total up the digits
	   $total+=$digit;
	 }

	 // If the total mod 10 equals 0, the number is valid
	 if ($total % 10 == 0)
		 return TRUE;
	 else {
		 // test again with the last number trimmed
		 return $this->luhn_check2($number);
	 }
	 //return ($total % 10 == 0) ? TRUE : FALSE;

   }
   
   function luhn_check2($number) {
	   
	 // Strip any non-digits (useful for credit card numbers with spaces and hyphens)
	 $number=substr($number,0,-1);

	 // Set the string length and parity
	 $number_length=strlen($number);
	 $parity=$number_length % 2;

	 // Loop through each digit and do the maths
	 $total=0;
	 for ($i=0; $i<$number_length; $i++) {
	   $digit=$number[$i];
	   // Multiply alternate digits by two
	   if ($i % 2 == $parity) {
		 $digit*=2;
		 // If the sum is two digits, add them together (in effect)
		 if ($digit > 9) {
		   $digit-=9;
		 }
	   }
	   // Total up the digits
	   $total+=$digit;
	 }

	 // If the total mod 10 equals 0, the number is valid
	 return ($total % 10 == 0) ? TRUE : FALSE;
   }
}

?>
