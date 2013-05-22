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
	 * Setting default config options
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// listen to
			'listen'         => 'tcp://0.0.0.0',
			// default port
			'port'     => 22825,
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
	
	protected function attachEventHandlers(){
		
	}
	
}

/**
 * VendorServerConnection handles listening for and sending data over the TCP socket
 */
class VendorServerConnection extends Connection{
	/**
	 * Bytes that indicate the start of a message in the TCP data
	 * @var String
	 */
	public $pkt_start_bytes = "\x02\x46\x44\x02";
	
	/**
	 * Bytes that indicate the end of a TCP message
	 * @var String
	 */
	public $pkt_end_bytes = "\x03\x46\x44\x03";
	
	/**
	 * Number of bytes to use as the packet size
	 * @var int
	 */
	public $pkt_size_bytes = 2;
	
	/**
	 * Smallest possible packet in bytes. Add pkt_start, pkt_size, & pkt_end bytes
	 * @var Int
	 */
	public $min_pkt_size = 10;
	
	/**
	 * Buffer to hold incoming data.
	 * @var String
	 */
	protected $buf = "";
	
	public function onRead(){
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' starting');
		/**
		 * Get our length of data to read
		 */
		$l = $this->bev->input->length;
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$this->bev:'.print_r($this->bev, true));
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'About to read '.$l.' bytes of data');
		$this->buf .= $this->read($l);
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Read '.$l.' bytes of data');
		
		$startsize = strlen($this->pkt_start_bytes);
		$endsize = strlen($this->pkt_end_bytes);
		
		// process our bytes to find packet start/end (code from VendorClientConnection.php
		start:

		// check if we have enough bytes for a packet
		if (strlen($this->buf) < $this->min_pkt_size) {
			return;
		}

		// find our packet start
		if (($pkt_start = strpos($this->buf, $this->pkt_start_bytes)) === false) {
			return;
		}
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Found packet start at offset: ' . $pkt_start);
		// good we found a packet, get our size
		list (, $payload_size) = unpack('n', binarySubstr($this->buf, $pkt_start + $startsize, $this->pkt_size_bytes));
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$payload_size:'.$payload_size);
		
		// calculate the entire pkt_size once, we use it several times
		$pkt_size = $startsize + $this->pkt_size_bytes + $payload_size + $endsize;
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$startsize:'.$startsize);
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$this->pkt_size_bytes:'.$this->pkt_size_bytes);
		//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$pkt_size:'.$pkt_size);
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$endsize:'.$endsize);
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$pkt_size:'.$pkt_size);

		// check our buffer to make sure we have the entire packet
		if (strlen($this->buf) < $pkt_start + $pkt_size) {
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Buffer does not contain enough bytes for this packet! $buf length:'.sytlen($this->buf));
			return;
		}
		if (binarySubstr($this->buf, $pkt_start + $startsize + $this->pkt_size_bytes + $payload_size, $endsize) !== $this->pkt_end_bytes) {
			Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'Wrong End Bytes.');
			Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'pkt hex:'.bin2hex(binarySubstr($this->buf, $pkt_start + $startsize + $this->pkt_size_bytes + $payload_size, $endsize)));
			//$this->finish();
			return;
		}

		// Fire an event to process the packet
		$this->processPacket(binarySubstr($this->buf, $pkt_start, $pkt_size));
		
		// REmove packet from our buffer
		$this->buf = binarySubstr($this->buf, $pkt_start + $pkt_size);

		goto start;
	}
	
	/**
	 * processPacket() - parse a binary string into an ISO8583 object
	 * @param String $pkt - the binary string received on the socket
	 */
	protected function processPacket($pkt) {
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Processing packet of length: '.strlen($pkt));
		
		$iso = new ISO8583Trans();
		$iso->addTCPMessage($pkt);
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG,'ISO8385Trans is of type:'.$iso->getMTI(true));
		//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'ISO8583Trans:'.print_r($iso, true));
		switch($iso->getMTI(true)) {
			case '0100':
				// this is an auth, auth_n_capture, or capture only packet
				$this->ISO8583_0100_Detail($iso);
				$this->write($this->ISO8583_0110_Response($iso));
				break;
			case '0400':
				// this is a reversal packet
				break;
			case '0200':
				// this is a debit transaction packet; not used
				break;
			case '0800':
				// this is an ECHO message used to keep the port open
				$this->ISO8583_0800_Detail($iso);
				$this->write($this->getTCPKeepaliveResponse());
				break;
			default:
				Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'No method to handle packet! MTI:'.$iso->getMTI(true));
		}
	}
	
	/**
	 * ISO8583_0100_Detail() - log the details of a 0100 ISO8583 message
	 * @param ISO8583Trans $iso - the parsed message
	 */
	protected function ISO8583_0100_Detail($iso) {
		$bits = $iso->DATA_SIZE;
		foreach($bits as $b=>$len){
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$iso bit:'.$b.', has value:'.$iso->getDataForBit($b, true));
		}
		if (array_key_exists(63, $bits)) {
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$iso, bit63:'.print_r($iso->getParsedBit63Data(), true));
		}
	}
	
	/**
	 * ISO8583_0110_Response() - create and return a response to an ISO8583 0100 Message
	 * @param ISO8583Trans $iso - the received ISO8583 object
	 * @return String - the binary string to send over the TCP socket
	 */
	protected function ISO8583_0110_Response($iso){
		$approved = true;
		$resp = new ISO8583Trans();
		$resp->addMTI('0110');
		// these bits are just echoed back
		$echo_return = array(3,4,11,24,25,37,41);
		foreach($echo_return as $bit) {
			$resp->addData($bit, $iso->getDataForBit($bit, true));
		}
		// these bits change with each message
		$resp->addData(7, gmdate('mdGis'));
		if ($iso->dataExistsForBit(48)) {
			$resp->addData(44, 'A');
		}
		if(rand(0,100)>93) {
			// this is a decline
			$resp->addData(39, '51');
			$approved = false;
		} else {
			$resp->addData(38, 'OK1234');
			$resp->addData(39, '00');
		}
		// check for the special bit63 part of the message
		$b63_data = '';
		if($iso->dataExistsForBit(63)) {
			$b63 = $iso->getParsedBit63Data();
			foreach($b63 as $table=>$data) {
				$card_type = null;
				if ($table === 14) {
					if ($iso->dataExistsForBit(2)) {
						$card_type = $this->findCardType($iso->getDataForBit(2, true));
					}
				}else {
					VEndor::logger(Vendor::LOG_LEVEL_DEBUG, '$table not 14! $table:'.$table);
				}
				$b63_data .= $this->createBit63Response($table, $data, $card_type);
			}
		}
		$b63_data .= $this->createBit63Response(22, 'TEST MESSAGE');
		if (strlen($b63_data) > 0){
			$resp->addData(63, $b63_data);
		}
		$respTCP = $resp->getTCPMessage();
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '0110 Response is '.strlen($respTCP).' bytes!');
		return $respTCP;
	}
	
	/**
	 * ISO8583_0800_Detail() - log the details of a 0800 ISO8583 message
	 * @param ISO8583Trans $iso - the parsed message
	 */
	protected function ISO8583_0800_Detail($iso) {
		$bits = array(3,11,24,41,42);
		foreach($bits as $b){
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$iso bit:'.$b.', has value:'.$iso->getDataForBit($b, true));
		}
	}
	
	/**
	 * getTCPKeepAliveResponse() - generate and return the binary string response
	 * to a 0800 keep alive message
	 * @return String
	 */
	protected function getTCPKeepaliveResponse(){
		$ISO8583 = new ISO8583();
		$ISO8583->addMTI('0810');
		$ISO8583->addData(3, '990000'); // processing code
		$ISO8583->addData(11, '000001'); // system trace number
		$ISO8583->addData(12, date('Gis')); // Local Time HHMMSS
		$ISO8583->addData(13, date('md')); // local date MMDD
		$ISO8583->addData(24, '001'); // network international ID
		$ISO8583->addData(39, '00'); // response code
		$ISO8583->addData(41, '0000000'); // terminal id for keep-alive is 0000000
		$ISO8583->addData(42, '000000000000'); // merchant number for keep-alive is 000000000000
		$ISO8583->addData(63, 'APPROVAL        ');
		return $ISO8583->getTCPMessage();
	}
	
	/**
	 * createBit63Response() - create a proper respsone for a Bit63 table
	 * @param String $tnum - the table to create a response for
	 * @param String $data - the received data for this table (not currently used)
	 * @param String $card_type - VS|MC|DS|AX
	 * @return string (binary)
	 */
	protected function createBit63Response($tnum, $data, $card_type=null) {
		$resp = '';
		switch($tnum) {
			case 14:
				// almost all messages have this table
				$hdr = "\x00\x4814";
				switch($card_type){
					case 'VS':
						return $hdr."A15CHARTRANSID  VAL1  000000000000000000000000";
						break;
					case 'MC':
						return $hdr."A0521BKNETREF    Y G  000000000000000000000000";
						break;
					case 'DS':
						return $hdr."Y NRIDTRANSID         000000000000000000000000";
						break;
					case 'AX':
						return $hdr."Y AMEXTRANSID         012345678901000000000000";
						break;
				}
				break;
			case 22:
				// text response message
				return "\x00\x18\x32\x32 TEST RESP      ";
				break;
			case 49:
				// Card Code Value (CVC2/CVV2/CID, etc) RESPONSE
				return "\x00\x0349M";
				break;
			case 'VI':
				// Card Specific Table for Visa
				return "\x00\x06VICRC ";
				break;
			case 'MC':
				// create the MC table response
				$tblMC = "010\x1C029\x1C039\x1C049\x1C059\x1C069\x1C070\x1C089\x1C";
				$tblMC .= "099\x1C100\x1C110\x1C121\x1C";
				$tblMC = hex2bin(str_pad(strlen($tblMC), 4, '0', STR_PAD_LEFT)).$tblMC;
				return $tblMC;
				break;
			case 'DS':
				// create the DS table response
				$tblDS = "01DFGHJK\x1C02654321\x1C031234\x1C04234515\x1C050521\x1C";
				$tblDS .= "0600\x1C07DISCOVERFLD07\x1C08NA\x1C09E\x1C";
				$tblDS .= "10DISCOVERJSBNRID\x1C";
				$tblDS = hex2bin(str_pad(strlen($tblDS),4, '0', STR_PAD_LEFT)).$tblDS;
				return $tblDS;
				break;
			default:
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'No method to create table '.$tnum.' response!');
				return "";
		}
		return $resp;
	}
	
	/**
	 * findCardType() - very simple checker of card type
	 * @param String $card_no - the card number to check
	 * @return string AX|VS|MC|DS|UK
	 */
	private function findCardType($card_no){
		switch(substr($card_no, 0,1)) {
			case 3:
				return 'AX';
				break;
			case 4:
				return 'VS';
				break;
			case 5:
				return 'MC';
				break;
			case 6:
				return 'DS';
				break;
			default:
				return 'UK';
		}
	}
	
}

?>
