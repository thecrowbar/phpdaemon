<?php
/**
 * Description of ISO8583Trans
 *
 * @author jcrow
 */
class ISO8583Trans extends ISO8583{
	const MTI_0100 = "\x01\x00"; // MTI == 0100 Auth or Auth&Capture
	const MTI_0110 = "\x01\x10"; // MTI == 0110 100 Response
	const MTI_0400 = "\x04\x00"; // MTI == 0400 Reversal
	const MTI_0410 = "\x04\x10"; // MTI == 0410 Reversal Response
	const USE_AMEX_SELLER_ID = false;
	
	// these are message types
	const TRANS_TYPE_RECURRING_BILLING		= 1;
	const TRANS_TYPE_RETAIL					= 2;
	const TRANS_TYPE_REFUND					= 3;
	const TRANS_TYPE_DIRECT_MARKETING		= 4;
	const TRANS_TYPE_REVERSAL				= 5;
	const TRANS_TYPE_TIMEOUT_REVERSAL		= 6;
	const TRANS_TYPE_ZERO_DOLLAR_AVS_CHECK	= 7;
	const TRANS_TYPE_ZERO_DOLLAR_CVC_CHECK	= 8; // not supported for AX
	const TRANS_TYPE_ZERO_DOLLAR_AVS_N_CVC	= 9; // only supported for MC and DS
	
	// these are messages classes Auth, Auth&Cap, Cap only
	const MSG_CLASS_AUTH_N_CAP				= 1;
	const MSG_CLASS_AUTH_ONLY				= 2;
	const MSG_CLASS_CAP_ONLY				= 3;
	
	// This is used for bit63 table 36
	const CUST_SVC_PHONE					= '87723550054';
	
	/**
	 * Transaction type. Use the const values form this class
	 * @var Int
	 */
	private $_trans_type = -1;
	
	/**
	 * Message Class; use the const values from this class
	 * @var Int
	 */
	private $_msg_class = -1;
	
	private $_trans_row;
	
	/**
	 * The id of the transaction record in the database
	 * @var int
	 */
	private $_trans_id = -1;
	/**
	 * The id of the original transaction. Used for 0400 reversal messages
	 * @var int
	 */
	private $_original_trans_id = -1;
	
	/**
	 * For followup transactions (0400, 0100 incremental auth) this is the ID
	 * of the original transaction
	 * @var Int
	 */
	public $master_trans_id = -1;
	
	/**
	 * The account number for this transaction
	 * @var String
	 */
	private $_pri_acct_no = '';
	
	/**
	 * The RSA encrypted account number. This is used to create a MD5 hash for
	 * AVS response logging
	 * @var String
	 */
	public $encrypted_acct_no;
	
	public $test_num = '';
	
	/**
	 * Flag to indicate if this transaction should include AVS data.
	 * There are 3 instances where AVS is required:
	 * 1) Any DirectMarketing sale transactions
	 * 2) Initial recurring billing transactions
	 * 3) Subsequent recurring billing if last AVS date was 12 months ago
	 * @var bool
	 * @default - false
	 */
	public $avs_required = false;
	
	/**
	 * The last date an AVS response was received for this card number
	 * @var Date (String)
	 */
	public $last_avs_date = '1999-01-01';
	
	/**
	 * If we should include AVS, but none is available do not throw an exception
	 * This is only for testing
	 * @var bool - default false
	 */
	private $loose_avs_check = true;
	
	/**
	 * CreditCard object used to validate the card number and determine its type
	 * @var Object
	 */
	public $credit_card;
	
	/**
	 * Flag to indicate if this is the initial recurring billing transaction or not
	 * @var bool
	 * @default false
	 */
	public $initial_recurring = false;
	
	/**
	 * Object to validate and parse track 2 data
	 * @var Track2Data
	 */
	public $tk2;
	
	public function __set($name, $val) {
		$this->$name = $val;
	}
	
	public function __get($name) {
		return $this->$name;
	}
	
	/**
	 * Create a new ISO8583Trans object. Can be filled with data from DB
	 * @param array $db_result - DB Row of transaction 
	 */
	function __construct($db_result=null, $trans_type = self::TRANS_TYPE_RECURRING_BILLING) {
		
		// see if we have any arguments
		if ($db_result === null) {
			// we were called without any options just create an empty object
		}else if (is_array($db_result)) {
			//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Creating ISO8583Trans object with data:'.print_r($db_result, true));
			// FIXME this needs to be changed for real-time auth
			$this->_trans_type = $trans_type;
			$this->_trans_row = $db_result;
			
			// 2013-04-17 we should always have a trans_type in the db_row.
			// The values come from the trans_type_list table
			// check if we have a trans_type in our db_row values
			if (array_key_exists('trans_type', $this->_trans_row)) {
				$this->_trans_type = (int)$this->_trans_row['trans_type'];
			}

			$this->_trans_id = $this->_trans_row['id'];
			if (!array_key_exists('id', $this->_trans_row)) {
				Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'missing id in $this->_trans_row:'.print_r($this->_trans_row, true));
			}
			if ($this->_original_trans_id === -1 && array_key_exists('original_trans_id',$this->_trans_row)) {
				$this->_original_trans_id = (int)$this->_trans_row['original_trans_id'];
			}
			
			if ($this->master_trans_id === -1 && array_key_exists('master_trans_id',$this->_trans_row)) {
				$this->master_trans_id = (int)$this->_trans_row['master_trans_id'];
			}
			
			$this->_pri_acct_no = $this->_trans_row['pri_acct_no'];
			$this->encrypted_acct_no = $this->pri_acct_no;
			if ($this->test_num === -1 && array_key_exists('test_num', $this->_trans_row)) {
				$this->test_num = $this->_trans_row['test_num'];
			}
			try{
				$this->credit_card = new CreditCard($this->_pri_acct_no);
			}catch(Exception $e) {
				Vendor::logger(Vendor::LOG_LEVEL_ERROR, 'Caught exception with card number! $this->_pri_acct_no:'.$this->_pri_acct_no.', $this->pri_acct_no:'.$this->pri_acct_no.', $this->encrypted_acct_no:'.$this->encrypted_acct_no);
			}
			// save our AVS response and/or date
			if (array_key_exists('last_avs_date', $this->_trans_row)) {
				$this->last_avs_date = $this->_trans_row['last_avs_date'];
			}
			
			// check if this is an intial recurring transaction
			if ($this->_trans_type === self::TRANS_TYPE_RECURRING_BILLING
					&& array_key_exists('initial_recurring', $this->_trans_row)
					&& $this->_trans_row['initial_recurring'] == true) {
				$this->initial_recurring = true;
			}

			// set and check the type of card we are processing
			$this->setCCType($this->credit_card->card_type);
			if ($this->credit_card->card_type === 'UNKNOWN') {
				Vendor::logger(Vendor::LOG_LEVEL_CRITICAL, 'Unknown card type for card:'.$this->_pri_acct_no);
				throw new Exception('Unknown card type! Card Number:'.$this->_pri_acct_no);
			} else {
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Card ('.$this->_pri_acct_no.') is of type:'.$this->card_type);
			}
			$this->_addDataToISO();
			
			// add our tables in the bit 63 data
			// Refund and timeout reversal transactions have no bit 63 tables
			// reversal transactions have only bit63->table14
			if ($this->_trans_type !== self::TRANS_TYPE_REFUND && 
					$this->_trans_type !== self::TRANS_TYPE_TIMEOUT_REVERSAL) {
				// refunds have no bit 63 tables
				$this->_addBit63Data();
			}
		}
	}
	

	private function _addDataToISO() {
		$this->addMTI($this->_trans_row['msg_type']);
		$this->addData(2, $this->_trans_row['pri_acct_no']);
		
		//<editor-fold defaultstate="collapsed" desc="Bit3 Processing Code">
		// Bit 3 must be 20000 for REFUND type transactions
		if ($this->_trans_type === self::TRANS_TYPE_REFUND){
			if ($this->_trans_row['processing_code'] !== '200000') {
				Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'Trans ('.$this->_trans_id.') has wrong processing code ('.$this->_trans_row['processing_code'].'). Correcting');
				$this->_trans_row['processing_code'] = '200000';
			}
		} else if ($this->_trans_type === self::TRANS_TYPE_RECURRING_BILLING) {
			if ($this->_trans_row['processing_code'] !== '500000') {
				Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'Trans ('.$this->_trans_id.') has wrong processing code ('.$this->_trans_row['processing_code'].'). Correcting');
				$this->_trans_row['processing_code'] = '500000';
			}
		} else if ($this->_trans_type === self::TRANS_TYPE_ZERO_DOLLAR_AVS_CHECK ||
				$this->_trans_type === self::TRANS_TYPE_ZERO_DOLLAR_CVC_CHECK ||
				$this->_trans_type === self::TRANS_TYPE_ZERO_DOLLAR_AVS_N_CVC ) {
			if ($this->_trans_row['processing_code'] !== '000000') {
				Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'Trans ('.$this->_trans_id.') has wrong processing code ('.$this->_trans_row['processing_code'].'). Correcting');
				$this->_trans_row['processing_code'] = '000000';
			}
		} else {
			if ($this->_trans_row['processing_code'] !== '000000') {
				Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'Trans ('.$this->_trans_id.') has wrong processing code ('.$this->_trans_row['processing_code'].'). Correcting');
				$this->_trans_row['processing_code'] = '000000';
			}
		}
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Transaction ('.$this->_trans_id.') using processing code:'.$this->_trans_row['processing_code']);
		$this->addData(3, $this->_trans_row['processing_code']);
		//</editor-fold>
		
		//<editor-fold defaultstate="collapsed" desc="Bit4 Amount (only zero dollar checked)">
		
		if ($this->_trans_row['trans_amount'] === '0.00' &&
				($this->_trans_type !== self::TRANS_TYPE_ZERO_DOLLAR_AVS_CHECK ||
				$this->_trans_type !== self::TRANS_TYPE_ZERO_DOLLAR_CVC_CHECK ||
				$this->_trans_type !== self::TRANS_TYPE_ZERO_DOLLAR_AVS_N_CVC )) {
			Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'Trans ('.$this->_trans_id.') has wrong trans_amount ('.$this->_trans_row['trans_amount'].').');
		} 
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Transaction ('.$this->_trans_id.') using amount:'.$this->_trans_row['trans_amount']);
		$this->addData(4, $this->_trans_row['trans_amount']*100);
		//</editor-fold>
		
		$this->addData(11, $this->_trans_row['receipt_number']);
		// The submit_dt field in the database is set just before the data
		// is passed to this class for constructing the ISO message. It is
		// very close to being "now"
		$this->addData(12, date('His',strtotime($this->_trans_row['submit_dt']))); // Ex 221047
		$this->addData(13, date('md',strtotime($this->_trans_row['submit_dt']))); // Ex 1003 for Oct 3rd
		$this->addData(14, $this->_trans_row['cc_exp']); // this is YYMM format
		$this->addData(18, $this->_trans_row['merchant_category_code']);
		
		//<editor-fold defaultstate="collapsed" desc="Bit22 POS Entry Mode + PIN Capability">
		// Bit 22 must be set to a known value for retail transactions
		if($this->_trans_type === self::TRANS_TYPE_RETAIL 
				&& substr($this->_trans_row['pos_entry_pin'],-1) === '0') {
			$msg = 'Retail transactions require a know PIN capability';
			Vendor::logger(Vendor::LOG_LEVEL_ERROR, $msg);
			throw new Exception($msg);
		} else if ($this->_trans_type === self::TRANS_TYPE_REFUND &&
				$this->_trans_row['pos_entry_pin'] !== '011') {
			Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'Trans ('.$this->_trans_id.') has wrong POS entry pin ('.$this->_trans_row['pos_entry_pin'].'). Correcting');
			$this->_trans_row['pos_entry_pin'] = '011';
		}
		$this->addData(22, $this->_trans_row['pos_entry_pin']);
		//</editor-fold>
		
		// NII is always 001
		$this->addData(24, $this->_trans_row['network_international_id']);
		
		//<editor-fold defaultstate="collapsed" desc="Bit25 POS Condition Code">
		// Bit 25 needs to be '04' for recurring transaction
		if ($this->_trans_type == self::TRANS_TYPE_RECURRING_BILLING) {
			if ($this->_trans_row['pos_condition_code'] !== '04') {
				Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'Trans ('.$this->_trans_id.') has wrong POS condition code ('.$this->_trans_row['pos_condition_code'].'). Correcting');
				$this->_trans_row['pos_condition_code'] = '04';
			}
		} else if ($this->_trans_type === self::TRANS_TYPE_ZERO_DOLLAR_AVS_CHECK){
			if ($this->_trans_row['pos_condition_code'] !== '52') {
				Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'Trans ('.$this->_trans_id.') has wrong POS condition code ('.$this->_trans_row['pos_condition_code'].'). Correcting');
				$this->_trans_row['pos_condition_code'] = '52';
			}
		}else if($this->_trans_type === self::TRANS_TYPE_ZERO_DOLLAR_CVC_CHECK){
			if ($this->_trans_row['pos_condition_code'] !== '51') {
				Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'Trans ('.$this->_trans_id.') has wrong POS condition code ('.$this->_trans_row['pos_condition_code'].'). Correcting');
				$this->_trans_row['pos_condition_code'] = '51';
			}
		} else if ($this->_trans_type === self::TRANS_TYPE_ZERO_DOLLAR_AVS_N_CVC) {
			// only supported for MC and DS
			//throw new Exception('What processing code to use here?');
			if ($this->_trans_row['pos_condition_code'] !== '51') {
				Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'Trans ('.$this->_trans_id.') has wrong POS condition code ('.$this->_trans_row['pos_condition_code'].'). Correcting');
				$this->_trans_row['pos_condition_code'] = '51';
			}
		}
		$this->addData(25, $this->_trans_row['pos_condition_code']);
		//</editor-fold>
		
		//<editor-fold defaultstate="collapsed" desc="Bit31 Acquirer Reference Data (only for Host Draft Capture)">
		// bit 31 is set to 1 if the merchant is host_draft_capture (our recurring billing are set this way)
		// the query to pull in the data for a single transaction uses the fd_merchant_info.host_capture
		// boolean field for the acquirer_reference_data value
		// and the trans type is not refund
		if ($this->_trans_type === self::TRANS_TYPE_REFUND ){
			if ($this->_trans_row['acquirer_reference_data'] !== '2') {
				Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'Trans ('.$this->_trans_id.') has wrong acquirer_reference_data code ('.$this->_trans_row['acquirer_reference_data'].'). Correcting');
				$this->_trans_row['acquirer_reference_data'] = '2';
			}
		}else if ($this->_trans_type === self::TRANS_TYPE_ZERO_DOLLAR_AVS_CHECK ||
				$this->_trans_type === self::TRANS_TYPE_ZERO_DOLLAR_CVC_CHECK ||
				$this->_trans_type === self::TRANS_TYPE_ZERO_DOLLAR_AVS_N_CVC){
			if ($this->_trans_row['acquirer_reference_data'] !== '0') {
				Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'Trans ('.$this->_trans_id.') has wrong acquirer_reference_data code ('.$this->_trans_row['acquirer_reference_data'].'). Correcting');
				$this->_trans_row['acquirer_reference_data'] = '0';
			}
			// Zero Dollar AVS & CVV/CVC2 checks require host_capture flag to be auth only
		} else if ($this->_trans_type === self::TRANS_TYPE_TIMEOUT_REVERSAL ||
				$this->_trans_type === self::TRANS_TYPE_REVERSAL ){
			//Vendor::logger(Vendor::LOG_LEVEL_NOTICE, )
			
		}
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Transaction ('.$this->_trans_id.') using bit31 data:'.$this->_trans_row['acquirer_reference_data']);
		$this->addData(31, $this->_trans_row['acquirer_reference_data']);
		//</editor-fold>

		//<editor-fold defaultstate="collapsed" desc="Bit35 Track 2 Data">
		// Bit 35 is the track2 data. It is only used for real-time transactions with swipe
		if ($this->_trans_type === self::TRANS_TYPE_RETAIL &&
				array_key_exists('track2', $this->_trans_row) &&
				strlen($this->_trans_row['track2']) > 0) {
			// track2 start sentinel (;), end sentinel (?) and LRC should be excluded
			$t = new Track2Data($this->_trans_row['track2']);
			$this->addData(35, $t->pan.'='.$t->exp.$t->svc_code.$t->dd);
		}
		//</editor-fold>

		//<editor-fold defaultstate="collapsed" desc="Bit37 Retrieval Reference Number (DB Record id)">
		// 2013-04-17 Bit 37 is now the DB record ID for the original 0100
		// transaction. 0400 and followup 0100 (we don't use these) use the 
		// bit 37 data from the first transaction. The original bit37 data
		// comes in 'master_trans_id' field
		if ($this->master_trans_id !== -1) {
			$this->addData(37, $this->_trans_row['master_trans_id']);
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Using master_trans_id:'.$this->master_trans_id.' for Bit 37 value!');
		} else {
			// use the id of the record from the DB
			$this->addData(37, $this->_trans_id);
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Using _trans_id:'.$this->_trans_id.' for Bit 37 value!');
		}
		//</editor-fold>
		

		//<editor-fold defaultstate="collapsed" desc="Bit38 Authorization Identification Response">
		// bit 38 is authorization identification response; submitted for reversals
		// bit 39 is the response code; submitted only on reversal
		if ($this->_trans_type === self::TRANS_TYPE_REVERSAL) {
			$this->addData(38, $this->_trans_row['auth_iden_response']);
			// 2013-04-19 per Manana bit39 is not included in FULL AUTH REVERSAL
			//$this->addData(39, $this->_trans_row['response_code']);
		}
		//</editor-fold>
		
		//<editor-fold defaultstate="collapsed" desc="Bit39 Response Code">
		if($this->getDataForBit(31) === "\x012" &&
				$this->_trans_type !== self::TRANS_TYPE_REFUND){
			// bit39 is required for capture only transactions, except refunds
			$this->addData(39, $this->_trans_row['response_code']);
		}
		//</editor-fold>
		
		
		
		$this->addData(41, $this->_trans_row['terminal_id']);
		$this->addData(42, $this->_trans_row['merchant_id']);
		
		//<editor-fold defaultstate="collapsed" desc="Bit44 AVS Response (only for reversals)">
		// bit 44 is only sent with a refund transaction
		if($this->_trans_type == self::TRANS_TYPE_REFUND 
				&& array_key_exists('avs_response', $this->_trans_row)
				&& strlen($this->_trans_row['avs_response']) > 0) {
			$this->addData(44, $this->_trans_row['avs_response']);
		}
		//</editor-fold>
		
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'ISO with db ID:'.$this->_trans_id.' is of type:'.$this->_trans_type);
		
		//<editor-fold defaultstate="collapsed" desc="Bit48 Address Verification Service (AVS) data">
		// bit 48 is AVS data
		if ($this->_trans_type === self::TRANS_TYPE_DIRECT_MARKETING) {
			$this->avs_required = true;
		} else if($this->_trans_type === self::TRANS_TYPE_RECURRING_BILLING) {
			// check if this is the first reecurring billing transaction
			// or if the last avs_date was >= 12 months ago
			if ($this->initial_recurring || date('Y-m-d', strtotime('-12 months')) >= $this->last_avs_date) {
				// we need to send AVS data
				$this->avs_required = true;
			}
		} else if($this->_trans_type === self::TRANS_TYPE_REVERSAL){
			// 2013-04-18 Per Manana Bit48 on Reversal is only used for Debit testing
			// for reversal transactions we include the reason for the reversal
			// either customer/cashier initiated or system generated
//			$this->DATA_ELEMENT[48] = array('ans', 6,		1,	'bcd',	2,	' ');
//			$this->addData(48,254000); // this is a customer/cashier initiated
			// 2013-04-17 Bit 52 is only for debit
			//$this->addData(52, "\x00\x00\x00\x00\x00\x00\x00\x00");
		}else if($this->_trans_type === self::TRANS_TYPE_TIMEOUT_REVERSAL){
			// 2013-04-18 Per Manana Bit48 on Reversal is only used for Debit testing
			// this is an auto-generated reversal due to timeout
//			$this->DATA_ELEMENT[48] = array('ans', 6,		1,	'bcd',	2,	' ');
//			$this->addData(48,254021); // this is system initiated due to timeout
			// 2013-04-17 Bit 52 is only for debit
			//$this->addData(52, "\x00\x00\x00\x00\x00\x00\x00\x00");
		}else if($this->_trans_type === self::TRANS_TYPE_ZERO_DOLLAR_AVS_CHECK){
			$this->avs_required = true;
		}else {
			// if this a real time (retail) transaction and AVS data is present,
			// then we send it along
			if ($this->_trans_type === self::TRANS_TYPE_RETAIL && strlen($this->_trans_row['avs_data']) > 0) {
				$this->avs_required = true;
			}
		}
		if ($this->avs_required){
			if (strlen($this->_trans_row['avs_data']) > 0) {
				// AVS data is unique because it acts like a variable length field
				// (has BCD size prefix) but it must always be 29 characters with '99'
				// prefix.
				// AVS data should be prefixed with 99 and have 29 characters of
				// 9 digit zip (zero padded) and street address
				if (substr($this->_trans_row['avs_data'],0,2) !== '99') {
					$this->_trans_row['avs_data'] = '99'.$this->_trans_row['avs_data'];
				}
				// pad our address with space to 31 bytes long
				$this->_trans_row['avs_data'] = strtoupper(substr(str_pad($this->_trans_row['avs_data'], 31, ' ', STR_PAD_RIGHT),0,31));
				$this->addData(48, $this->_trans_row['avs_data']);

				// trim to proper length
			} else {
				$msg = 'AVS Required for this ISO message, but no AVS data available!';
				Vendor::logger(Vendor::LOG_LEVEL_ERROR, 'ISO with db ID:'.$this->_trans_id.' '.$msg);
				if (!$this->loose_avs_check) {
					throw new Exception($msg);
				}
			}
			
		}
		//</editor-fold>
		
		$this->addData(59, $this->_trans_row['merchant_zip_code']);
		
		//<editor-fold defaultstate="collapsed" desc="Bit60 Additional POS Information">
		// FIXME This needs to be clarified. Swiped transactions need bit60==42
		// what about manual keyed transaction?
		// 2013-05-16 AVS and CVC Zero $ trans need bit60
		// bit 60 is required for any card present transactions
		if ($this->dataExistsForBit(25) && 
				($this->getDataForBit(25) === "\x00" || $this->getDataForBit(25) === "\x51" ||
				$this->getDataForBit(25) === "\x52")) {
			$this->addData(60, '42');
		} else {
			Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'Not adding data for bit 60! Bit 25:'.bin2hex($this->getDataForBit(25)));
		}
		//</editor-fold>
		
	}
	
	private function _addBit63Data() {
		// calculate our tables for bit 63 data
		$bit63_length = 0;
		$bit63_data = "";
		$tbl14 = "";
		$tbl33 = "";
		$tbl36 = "";
		$tbl49 = "";
		$tbl55 = "";
		$tbl60 = "";
		$tblVI = "";
		$tblMC = "";
		$tblDS = "";
		if (strlen($this->_calculateBit63Table14()) > 0) {
			//echo "Adding Table 14 data!\n";
			// include table 14 data length is always 48
			$tbl14 .= $this->convertDEC2BCD('0048');
			$tbl14 .= '14';
			//$tbl14 .= 'Y';
			$tbl14 .= $this->_calculateBit63Table14();
			if (strlen($tbl14) != 50) {
				die("Table 14 data is wrong length! MTI:".$this->getMTI(true)." Table 14 length:".strlen($tbl14).", Table 14 data: {$tbl14}");
			}
		} else {
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Table 14 not added. Trans_type:'.$this->_trans_type.' Card Type:'.$this->card_type);
		}
		
		// 2013-04-17 per Eileen's newest email, we do not need table 33
//		// calculate our table33 data. In normal Debig transactions this is DUKPT
//		// (Derivied Unique Key Per Transactions). For reversals we send in 
//		// null values
//		if ($this->_trans_type === self::TRANS_TYPE_REVERSAL ||
//				$this->_trans_type === self::TRANS_TYPE_TIMEOUT_REVERSAL) {
//			$tbl33 .= $this->convertDEC2BCD('0022'); // table length in BCD
//			$tbl33 .= 33; // table number as ASCII
//			$tbl33 .= "F"; // 1 byte pad character
//			$tbl33 .= "FFFFFFFFF"; // 9 byte Base Detrivation Key ID (pad on left with 'F'
//			$tbl33 .= "00000"; // 5 bytes device ID
//			$tbl33 .= "00000"; // 5 byte transaction counter
//		}

		// calculate our table 36 data
		// Table 36 is Additional Addendum Data
		// only used for direct marketing and retail with host capture and MTI 0100
		if ( ($this->getDataForBit(31) === "\x011" || $this->getDataForBit(31) === "\x012")
				&& $this->_mti === self::MTI_0100
				&& ($this->_trans_type === self::TRANS_TYPE_RECURRING_BILLING 
					|| $this->_trans_type === self::TRANS_TYPE_RETAIL
					|| $this->_trans_type === self::TRANS_TYPE_DIRECT_MARKETING)) {
			$tbl36 .= $this->convertDEC2BCD('0060'); // BCD length of data == 60
			$tbl36 .= 36; // table number 
			$tbl36 .= 1; // table version 1 (1 is only option)
			if ($this->_trans_type === self::TRANS_TYPE_RECURRING_BILLING ||
					$this->_trans_type === self::TRANS_TYPE_DIRECT_MARKETING) {
				$tbl36 .= self::CUST_SVC_PHONE; // let the cust know how to contact us
				// per my email with Manana (Sr Certification Analyst) if we require
				// customer service phone, then we should send it and space fill order number
				// the order number is the transaction id from our database.
				// space-filled, left justified
			} else {
				$tbl36 .= '          '; // 10 digits (phone number for direct marketing
				// the order number is sale site_id * 10,000,000 + sx order_number
				// Spec says 15 bytes, but Eileen (Sr Cert Specialist) stated that only 12 bytes
				// will appear on cust account
				// 2013-04-19 The order number can contain only A-Z0-9 characters
				$order_num = substr($this->_trans_row['sale_site_id'].$this->_trans_row['sx_order_number'],0,12);
				$tbl36 .= str_pad($order_num, 12, ' ', STR_PAD_RIGHT);
			}
				//$tbl36 .= strpad($this->_trans_row['id'], 15, ' ', STR_PAD_LEFT);
				//$tbl36 .= $this->_trans_row['addendum_data_tbl_36'];
				// pad with spaces to proper length
			$tbl36 .= str_pad('',(62 - strlen($tbl36)),' ');
			if (strlen($tbl36) != 62) {
				die("Table 36 data is not correct length!");
			}
		} else {
			Vendor::logger(Vendor::LOG_LEVEL_NOTICE, "Table36 not added. Bit31 data (hex):".bin2hex($this->getDataForBit(31)));
		} 
			
		// table 49 is the CVC/CVS/CID code from the card. Because all of our
		// recurring billing is from information stored in a DB, we never use
		// table 49 with recurring billing. Only manually entered transactions
		// will have table49 data
		// authorization and auth+capture only
		// calculate our table 49 data
		if ($this->dataExistsForBit(35)){
			Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'Not adding table 49 data! Track2 exists! This is a swiped transaction');
		}else if (array_key_exists('table49', $this->_trans_row) 
				&& strlen($this->_trans_row['table49']) > 0
				&& ($this->getDataForBit(31) === "\x010" || $this->getDataForBit(31) === "\x011")) {
			//echo "Adding Table 49 data!\n";
			$tbl49 .= $this->convertDEC2BCD('0007');
			$tbl49 .= 49;
			$tbl49 .= 1; // presence indicator see pg4-95 1==value present
			$tbl49 .= str_pad($this->_trans_row['table49'], 4, ' ', STR_PAD_LEFT);
			if (strlen($tbl49)!= 9) {
				die("Table 49 data is wrong length! \$tbl49:{$tbl49}");
			}
		} else {
			Vendor::logger(Vendor::LOG_LEVEL_INFO, 'Missing table 49 data or wrong length. Not adding.');
		}
			

		// calculate our table 55 data
		// table 55 is only present for Visa/MC AND recurring billing
		if ((strtoupper($this->card_type) === 'VISA' 
				|| strtoupper($this->card_type) === 'MASTER CARD') 
				&& $this->_trans_type === self::TRANS_TYPE_RECURRING_BILLING
				&& ($this->getDataForBit(31) === "\x010" || $this->getDataForBit(31) === "\x011")) {
			//echo "Adding Table 55 data!\n";
			$tbl55 .= $this->convertDEC2BCD('0005');
			$tbl55 .= 55;
			$tbl55 .= '1  ';
			if (strlen($tbl55) != 7) {
				die("Table 55 data is wrong length");
			}
		} else {
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Table 55 not added. Trans_type:'.
					$this->_trans_type.' Card Type:'.$this->card_type.
					' bit32:'.$this->getDataForBit(31, true));
		}

		// calculate our table 60 data
		// Table 60 is used for MOTO/Bill Payment/Electronic Commerce
		if ($this->_trans_type === self::TRANS_TYPE_RECURRING_BILLING) {
			//echo "Adding Table 60 data!\n";
			$tbl60 .= $this->convertDEC2BCD('0004');
			$tbl60 .= '60';
			$tbl60 .= '02';
			if (strlen($tbl60) != 6) {
				die("Table 60 data is of wrong length!");
			}
		} else {
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Table 60 not added. Trans_type:'.$this->_trans_type);
		}
		
		// Card specific tables are not used for timeout reversal transactions (MTI = 0400)
		// they are present in all other 0100 and 0400 messages
		if ($this->_trans_type !== self::TRANS_TYPE_TIMEOUT_REVERSAL) {
			// calculate the card specific tables VI, MC, DS
			if (strtoupper($this->card_type) === 'VISA') {
				if ($this->getMTI() === self::MTI_0100){
					if ($this->dataExistsForBit(31) && // or host_draft_capture is set
							($this->getDataForBit(31) == "\x011" || $this->getDataForBit(31) == "\x010")) { 
						$tblVI .= $this->convertDEC2BCD('0002');
						$tblVI .= 'VI';
					} else if($this->dataExistsForBit(31) && $this->getDataForBit(31) == "\x012"){
						// this is a capture only
						$vi_fields = array(
							array('CR', 'card_level_response_code',2,' ')
						);						
						$tblVI .= $this->_buildBit63FieldIdentifierTable('VI', $vi_fields);
					}
				}
			} else if (strtoupper($this->card_type) == 'MASTER CARD'){
				if ($this->getMTI() === self::MTI_0100){
					if ($this->dataExistsForBit(31) && // or host_draft_capture is set
							($this->getDataForBit(31) === "\x011" || $this->getDataForBit(31) == "\x010")) { 
						// this is an auth only or auth+capture trans
						$tblMC .= $this->convertDEC2BCD('0002');
						$tblMC .= 'MC';
					}else  if($this->dataExistsForBit(31) && $this->getDataForBit(31) == "\x012"){
						// this is a capture only trans
						$mc_fields = array(
							array('01', 'TD_card_data_input_cap', 1, '0'),
							array('02', 'TD_cardholder_auth_cap', 1, '9'),
							array('03', 'TD_card_capture_cap', 1, '9'),
							array('04', 'term_oper_environ', 1, '0'),
							array('05', 'cardholder_present_data', 1, '9'),
							array('06', 'card_present_data', 1, '9'),
							array('07', 'CD_input_mode', 1, '0'),
							array('08', 'cardholder_auth_method', 1, '9'),
							array('09', 'cardholder_auth_entity', 1, '9'),
							array('10', 'card_data_output_cap', 1, '0'),
							array('11', 'term_data_output_cap', 1, '0'),
							array('12', 'pin_capture_cap', 1, '1')
						);
						$tblMC = $this->_buildBit63FieldIdentifierTable('MC', $mc_fields);
						//Vendor::logger(Vendor::LOG_LEVEL_NOTICE,'MC Table not yet implemented for capture transactions');
					}
				}
			} else if (strtoupper($this->card_type) === 'DISCOVER' ||
					strtoupper($this->card_type) === 'JCB' ||
					strtoupper($this->card_type) === 'DINERS CLUB') {
				if ($this->getMTI() == self::MTI_0100){
					if ($this->dataExistsForBit(31) && // or host_draft_capture is set
							($this->getDataForBit(31) === "\x011" || $this->getDataForBit(31) == "\x010")) { 
						$tblDS .= $this->convertDEC2BCD('0002');
						$tblDS .= 'DS';
					} else if ($this->dataExistsForBit(31) && $this->getDataForBit(31) == "\x012"){
						// this is a capture only
						$ds_fields = array(
							array('01','ds_processing_code', 6, ' '),
							array('02','sys_trace_audit_num',6, ' '),
							array('03','pos_entry_mode', 3, ' '),
							array('04','local_tran_time', 6, '0'),
							array('05','local_tran_date', 4, '0'),
							array('06','ds_response_code', 2, '0'),
							array('07','ds_pos_data', 13, ' '),
							array('08','track_data_condition_code',2,' '),
							array('09','ds_avs_result', 1, ' '), 
							array('10','nrid', 15, ' ')
						);
						$tblDS = $this->_buildBit63FieldIdentifierTable('DS', $ds_fields);
					}
				}
			} else if (strtoupper($this->card_type) === 'AMERICAN EXPRESS'){
				Vendor::logger(Vendor::LOG_LEVEL_INFO, 'Not adding card specific tables for card type:'.$this->card_type);
			}else {
				//throw new Exception('Unknown card type:'.$this->card_type.' card number:'.$this->);
				Vendor::logger(Vendor::LOG_LEVEL_ERROR, 'Pri acct number ('.$this->_pri_acct_no.') has unknown card type:'.$this->card_type);
			}
		} else {
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Not adding card specific tables. Trans_type:'.$this->_trans_type);
		}

		// combine all our bitmap 63 data
		$bit63_data = $tbl14 . $tbl33 . $tbl36 . $tbl49 . $tbl55 . $tbl60. $tblVI. $tblMC. $tblDS;
		$bit63_length += strlen($bit63_data);

		$this->addData(63, $bit63_data);
		
	}
	
	/**
	 * _calculateBit63Table14() - use the CC type and MTI to create the correct 
	 * table 14 data for bitmap 63
	 * @return string
	 */
	private function _calculateBit63Table14() {
		$tbl14 = '';
		$card_type = strtoupper($this->card_type);
		switch ($card_type) {
			case "VISA":
				$tbl14 = $this->_calculateBit63Table14Visa();
				break;
			case "MASTER CARD":
				$tbl14 = $this->_calculateBit63Table14MC();
				break;
			case "AMERICAN EXPRESS":
				$tbl14 = $this->_calculateBit63Table14AMEX();
				break;
			case "DISCOVER":
			case "DINERS CLUB":
			case "JCB":
				$tbl14 = $this->_calculateBit63Table14DS();
				break;
			default:
				throw new Exception("Table14 Not Implemented for card type: {$card_type}, card_no:{$this->_pri_acct_no}");
		}
		return $tbl14;
	}
	
	private function _calculateBit63Table14Visa() {
		
		// grab any record from the table14_visa table in the DB, if this transaction
		// is a reversal of a previous authorization then we need to get the table14
		// for the original transaction
		$tbl14_visa = '';
		$tbl14_fields = array(
			array('aci', 1, 'Y'),
			array('issuer_trans_id', 15, ' '),
			array('validation_code', 4, ' '),
			array('mkt_specific_data_ind', 1, ' '),
			array('rps', 1, ' '),
			array('first_auth_amount', 12, '0', STR_PAD_LEFT),
			array('total_auth_amount', 12, '0', STR_PAD_LEFT)
		);
		$tbl14_visa = $this->_calculateBit63Table14MsgString('table14_visa', $tbl14_fields);
		return $tbl14_visa;
	}
	
	private function _calculateBit63Table14MC(){
		// $tbl14_fields is a map of each field name, length, fill character, pad
		$tbl14_fields = array(
			array('aci'						,	1,	'Y'),
			array('banknet_date'			,	4,	' '),
			array('banknet_reference'		,	9,	' '),
			array('filler'					,	2,	' '),
			array('cvc_error_code'			,	1,	' '),
			array('pos_entry_mode_change'	,	1,	' '),
			array('trans_edit_code_error'	,	1,	' '),
			array('filler2'					,	1,	' '),
			array('mkt_specific_data_ind'	,	1,	' '),
			array('filler3'					,	13,	' '),
			array('total_auth_amount'		,	12,	'0', STR_PAD_LEFT) //,
			//array('addtl_mc_settle_date'	,	4,	' '),
			//array('addtl_banknet_mc_ref'	,	9,	' ')
		);
		$tbl14_mc = $this->_calculateBit63Table14MsgString('table14_mc', $tbl14_fields);
		return $tbl14_mc;
	}
	
	private function _calculateBit63Table14AMEX() {
		// $tbl14_fields is a map of each field name, lengthm and fill character
		$tbl14_fields = array(
			array('aei'						,	1,	'X'),
			array('issuer_trans_id'			,	15,	' '),
			array('filler'					,	6,	' '),
			array('pos_data'				,	12,	' '),
			array('filler2'					,	12,	' ')
		);
		
		if (ISO8583Trans::USE_AMEX_SELLER_ID) {
			$tbl14_fields[] = array('seller_id'				,	20,	' ');
		}
		
		$tbl14_amex = $this->_calculateBit63Table14MsgString('table14_amex', $tbl14_fields);
		return $tbl14_amex;
	}
	
	private function _calculateBit63Table14DS() {
		// $tbl14_fields is a map of each field name, lengthm and fill character
		$tbl14_fields = array(
			array('di'						,	1, 'X'),
			array('issuer_trans_id'			,	15,	' '),
			array('filler'					,	6,	' '),
			array('filler2'					,	12,	'0'),
			array('total_auth_amount'		,	12,	'0', STR_PAD_LEFT)
		);
		
		$tbl14_ds = $this->_calculateBit63Table14MsgString('table14_ds', $tbl14_fields);
		return $tbl14_ds;
	}
	
	private function _calculateBit63Table14MsgString($table, $tbl14_fields) {
		$tbl14_str = '';
		// grab any record from the table14_visa table in the DB
		$trans_id = -1;
		if ($this->getMTI() == ISO8583Trans::MTI_0100) {
			$trans_id = $this->_trans_id;
		} else if ($this->getMTI() == ISO8583Trans::MTI_0400) {
			$trans_id = $this->_original_trans_id;
		}
		//$sqlV = "SELECT * FROM {$table} WHERE trans_id = {$trans_id}";
		//$sqlVR = $this->_sqli->query($sqlV);
		$sqlVR = $this->_trans_row;
		if ($sqlVR) {
			$tbl14_row = $this->_trans_row;
			// ACI is Y for 0100, copied from 0110 response for other
			// loop over our array of fields 
			// pad them with the data from our array to the correct length
			// field[0] = field_name in db
			// field[1] = length to pad to
			// field[2] = pad character
			// field[3] = pad direction (if given)
			foreach($tbl14_fields as $field) {
				if ($this->getMTI() == self::MTI_0100) {
					// for the initial message, we just fill the field with the correct value
					$$field[0] = str_pad('',$field[1],$field[2]);
				} else {
					if (strlen($tbl14_row[$field[0]]) < $field[1]){ //compares length of data from database against the passed in value
						$str_pad = (array_key_exists(3,$field)) ? $field[3]:STR_PAD_RIGHT;
						$$field[0] = str_pad($tbl14_row[$field[0]], $field[1], $field[2], $str_pad);
						//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$table14_row['.$field[0].'] is shorted than $field[1]('.$field[1].')');
					} else	if (strlen($tbl14_row[$field[0]]) == $field[1]) {
						$$field[0] = $tbl14_row[$field[0]];
						//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$table14_row['.$field[0].']('.$tbl14_row[$field[0]].') length matches $field[1]('.$field[1].')');
					} else {
						throw new Exception("Table 14 {$field[0]} value wrong length! value:{$tbl14_row[$field[0]]}, trans_id:'{$this->_trans_id}");
					}
				}
			}
			
			// VISA recurring billing needs Market Specific Data Indicator to be 'B'
			// space for all others
			if ($this->_trans_type === self::TRANS_TYPE_RECURRING_BILLING) {
				if (strtoupper($this->card_type) === 'VISA') {
					$mkt_specific_data_ind[0] = 'B';
				} else {
					$mkt_specific_data_ind[0] = ' ';
				}
				
			}
			
			// collect our table 14 parts into a string
			foreach($tbl14_fields as $field){
				$tbl14_str .= $$field[0];
			}
			//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$table14_row:'.print_r($tbl14_row, true));
			//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Table14 String:'.$tbl14_str);
			return $tbl14_str;
			
		}
	}
	
	/**
	 * _buildBit63FieldIdentifierTables() - create the required FIT table
	 * @param type $tname - the table name
	 * @param Array $fields - each element is an array that contains a single field
	 *  consists of array('fld iden', 'db_row field', length, 'pad char')
	 * @return string
	 */
	protected function _buildBit63FieldIdentifierTable($tname, $fields) {
		$table = $tname;
		
		foreach($fields as $f) {
			$table .= $f[0].str_pad(substr($this->_trans_row[$f[1]],0,$f[2]), $f[2], $f[3], STR_PAD_LEFT)."\x1C";
		}
		// now set our length
		$table = $this->convertDEC2BCD(str_pad(strlen($table),4,'0',STR_PAD_LEFT)).$table;
		return $table;
	}
	
	
	/**
	 * _parseBit63Data() - parse the data tables from the bit 63 data and
	 * reassemble the data
	 * @return Array[] - Bit63 tables
	 */
	protected function _parseBit63Data() {
		// Bit63 Data has 2 byte BCD size header, followed by:
		// 2 byte (BCD) size header + 2 byte table number (ASCII 31-39)
		// 2012-10-31 BCD size header is not longer included with data
		if (array_key_exists(63, $this->_data)){
			$b63_data = $this->_data[63];
			//$data_len = $this->convertBCDByte2DEC(substr($b63_data,0,2));
			$data_len = strlen($b63_data);
			$b63_hex = '';
			for($i = 0; $i < strlen($b63_data); $i++) {
				$b63_hex .= bin2hex($b63_data[$i])." ";
			}
			$this->b63_hex = $b63_hex;

			// first two bytes are table size in BCD
			// next two bytes are table number (raw decimal value)
			$offset = 0;
			$tsize = 0;
			while ($offset < strlen($b63_data)) {
				$tsize = $this->convertBCDByte2DEC(substr($b63_data,$offset,2));
				$tnum = substr($b63_data,$offset+2,2);
				// store our data in the bit63 data map
				$this->BIT63_TABLES[$tnum]=substr($b63_data,$offset+4,$tsize-2);
				$offset += $tsize+2;
			}

			// parse the individual tables within bit63 data
			$tables = array (14, 22, 'VI', 'MC', 'DS' );
			foreach($tables as $tbl) {
				if (array_key_exists($tbl, $this->BIT63_TABLES)) {

				}
			}			
		}
			
		return $this->BIT63_TABLES;
	}
	
	/**
	 * Returns a formatted string representing the Bit63 tables (with data)
	 * @return string
	 */
	public function getBit63Tables() {
		$table_str = '';
		foreach($this->BIT63_TABLES as $tbl_num=>$data) {
			$table_str .= "Table {$tbl_num} (".strlen($data)."):  {$data}\n";
		}
		return $table_str;
	}
	
	/**
	 * Return the raw value from a given table
	 * @param String $table_num - the table number to return
	 * @return String - the tables contents
	 */
	public function getParsedBit63TableData($table_num) {
		if (array_key_exists($table_num, $this->BIT63_TABLES)) {
			return $this->BIT63_TABLES[$table_num];
		}
	}
	
	public function getParsedBit63Data() {
		
	}
	
	// <editor-fold defaultstate="collapsed" desc="Bit63 Table 14 Parsing functions">
	public function parseBit63Table14() {
		if ($this->dataExistsForBit(63) && $this->dataExistsForTable(14)) {
			return $this->getParsedBit63Table14();
		}
	}
	
	public function getParsedBit63Table14() {
		return $this->_parseBit63Table14();
	}
	
	private function _parseBit63Table14() {
		$tbl14 = '';
		if (array_key_exists(14,$this->BIT63_TABLES)) {
			// check our card type
			if ($this->card_type === 'UNKNOWN' || $this->card_type === '') {
				// check for tables VI, MC, or DS
				if ($this->dataExistsForTable('VI')) {
					$this->card_type = 'VISA';
				} else if ($this->dataExistsForTable('MC')) {
					$this->card_type = 'MASTER CARD';
				} else if ($this->dataExistsForTable('DS')) {
					$this->card_type = 'DISCOVER';
				}
			}
			switch(strtoupper($this->card_type)) {
				case "VISA":
					return $this->_parseBit63Table14Visa();
					break;
				case "MASTER CARD":
					return $this->_parseBit63Table14MC();
					break;
				case "AMERICAN EXPRESS":
					return $this->_parseBit63Table14AMEX();
					break;
				case "DINERS CLUB":
				case "DISCOVER":
				case "JCB":
					return $this->_parseBit63Table14DS();
					break;
				default:
					throw new Exception("Bit63 Table14 Parse function not implemented for card type: ".strtoupper($this->card_type));
			}
		}
	}
	
	private function _parseBit63Table14Visa() {
		$fields = array(
			'aci' => 1, 
			'issuer_trans_id' => 15, 
			'validation_code' => 4, 
			'mkt_specific_data_ind' => 1,
			'rps' => 1,
			'first_auth_amount' => 12,
			'total_auth_amount' => 12);
		$offset = 0;
		$table14_visa = array();
		foreach($fields as $fname=>$flength){
			$table14_visa[$fname] = substr($this->BIT63_TABLES[14],$offset,$flength);
			$offset += $flength;
		}
		
//		$table14_visa = array(
//			'aci' => $aci,
//			'issuer_trans_id' => $issuer_trans_id,
//			'validation_code' => $validation_code,
//			'mkt_specific_data_ind' => $mkt_specific_data_ind,
//			'rps' => $rps,
//			'first_auth_amount' => $first_auth_amount,
//			'total_auth_amount' => $total_auth_amount
//		);
		return $table14_visa;
//		$ret_val = "\$auth_char_ind:{$table14_visa['auth_char_ind']}, \$trans_id:{$trans_id},";
//		$ret_val .= "\$validation_code:{$table14_visa['validation_code']}, \$msdi:{$msdi},";
//		$ret_val .= "\$rps:{$rps}, \$first_auth_amt:{$table14_visa['first_auth_amt']},";
//		$ret_val .= "\$total_auth_amt:{$table14_visa['total_auth_amt']}\n";
//		return $ret_val;
	}
	
	private function _parseBit63Table14MC() {
		$fields = array(
			'aci' => 1,
			'banknet_date' => 4,
			'banknet_reference' => 9,
			'filler' => 2,
			'cvc_error_code' => 1,
			'pos_entry_mode_change' => 1,
			'trans_edit_code_error' => 1,
			'filler2' => 1,
			'mkt_specific_data_ind' => 1,
			'filler3' => 13,
			'total_auth_amount' => 12,
			'addtl_mc_settle_date' => 4,
			'addtl_banknet_mc_ref' => 9
		);
		$table14_mc = array();
		$offset = 0;
		foreach($fields as $fname=>$flength) {
			$table14_mc[$fname] = substr($this->BIT63_TABLES[14],$offset,$flength);
			$offset += $flength;
		}
		return $table14_mc;
//		$aci = substr($this->BIT63_TABLES[14],0,1);
//		$banknet_date = substr($this->BIT63_TABLES[14],1,4);
//		$banknet_reference = substr($this->BIT63_TABLES[14],5,9);
//		$filler = substr($this->BIT63_TABLES[14],14,2);
//		$cvc_error_code = substr($this->BIT63_TABLES[14],16,1);
//		$pos_entry_mode_change = substr($this->BIT63_TABLES[14],17,1);
//		$trans_edit_error_code = substr($this->BIT63_TABLES[14],18,1);
//		$filler2 = substr($this->BIT63_TABLES[14],19,1);
//		$mkt_specific_data_ind = substr($this->BIT63_TABLES[14],20,1);
//		$filler3 = substr($this->BIT63_TABLES[14],21,13);
//		$total_auth_amount = substr($this->BIT63_TABLES[14],34,12);
//		$table14_mc = array(
//			'aci' => $aci,
//			'banknet_date' => $banknet_date,
//			'banknet_reference' => $banknet_reference,
//			'filler' => $filler,
//			'cvc_error_code' => $cvc_error_code,
//			'pos_entry_mode_change' => $pos_entry_mode_change,
//			'trans_edit_code_error' => $trans_edit_error_code,
//			'filler2' => $filler2,
//			'mkt_specific_data_ind' => $mkt_specific_data_ind,
//			'filler3' => $filler3,
//			'total_auth_amount' => $total_auth_amount
//		);
//		return $table14_mc;
//		//throw new Exception("_parseBit63Table14MC() function not yet implemented!");
	}
	
	private function _parseBit63Table14AMEX() {
		$aei = substr($this->BIT63_TABLES[14],0,1);
		$issuer_trans_id = substr($this->BIT63_TABLES[14],1,15);
		$filler = substr($this->BIT63_TABLES[14],16,6);
		$pos_data = substr($this->BIT63_TABLES[14],22,12);
		$filler2 = substr($this->BIT63_TABLES[14],34,12);
		$table14_amex = array(
			'aei' => $aei,
			'issuer_trans_id' => $issuer_trans_id,
			'filler' => $filler,
			'pos_data' => $pos_data,
			'filler2' => $filler2
		);
		
		$seller_id = '';
		if (ISO8583Trans::USE_AMEX_SELLER_ID) {
			$seller_id = substr($this->BIT63_TABLES[14],46,20);
		} 
		$table14_amex['seller_id'] = $seller_id;
		return $table14_amex;
		//throw new Exception("_parseBit63Table14AMEX() function not yet implemented!");
	}
	
	private function _parseBit63Table14DS() {
		$di = substr($this->BIT63_TABLES[14],0,1);
		$issuer_trans_id = substr($this->BIT63_TABLES[14],1,15);
		$filler = substr($this->BIT63_TABLES[14],16,6);
		$filler2 = substr($this->BIT63_TABLES[14],22,12);
		$total_auth_amount = substr($this->BIT63_TABLES[14],34,12);
		
		$table14_ds = array(
			'di' => $di,
			'issuer_trans_id' => $issuer_trans_id,
			'filler' => $filler,
			'filler2' => $filler2,
			'total_auth_amount' => $total_auth_amount
		);
		return $table14_ds;
	}
	
	// </editor-fold>
	
	// <editor-fold defaultstate="collapsed" desc="Bit63 Table22 Parsing functions">
	public function getParsedBit63Table22() {
		return $this->parseBit63Table22();
	}
	public function parseBit63Table22(){
		if ($this->dataExistsForBit(63) && $this->dataExistsForTable(22)) {
			return $this->getParsedBit63TableData(22);
		}
	}
	// </editor-fold>
	
	// <editor-fold defaultstate="collapsed" desc="Bit63 Table49 Parsing functions">
	public function getParsedBit63Table49(){
		return $this->parseBit63Table49();
	}
	public function parseBit63Table49(){
		if ($this->dataExistsForBit(63) && $this->dataExistsForTable(49)) {
			return $this->getParsedBit63TableData(49);
		}
	}
	//</editor-fold>
	
	// <editor-fold defaultstate="collapsed" desc="Bit63 TableVI Parsing functions">
	public function getParsedBit63TableVI() {
		return $this->parseBit63TableVI();
	}
	public function parseBit63TableVI(){
		if ($this->dataExistsForBit(63) && $this->dataExistsForTable('VI')) {
			// pg 4-140 of spec
			// I populate the result array with the key=>default val so when I
			// create the insert query there is no need to check if each key 
			// exists
			$result = array(
				'CR' => '',
				'RS' => '',
				'UF' =>''
			);
			$ta = explode("\x1c", $this->getParsedBit63TableData('VI'));
			foreach($ta as $field) {
				$result[substr($field,0,2)] = substr($field,2);
			}
			return $result;
		}
	}
	
	
	// </editor-fold>
	
	// <editor-fold defaultstate="collapsed" desc="Bit63 TableMC Parsing functions">
	public function getParsedBit63TableMC() {
		return $this->parseBit63TableMC();
	}
	public function parseBit63TableMC(){
		if ($this->dataExistsForBit(63) && $this->dataExistsForTable('MC')) {
			// pg 4-150 of spec
			//return "TODO: This code must be completed for table MC. pg 4-150";
			$fields = array(
				'card_data_input_cap',
				'cardholder_auth_cap',
				'card_capture_cap',
				'term_oper_environ',
				'cardholder_present',
				'card_present_data',
				'card_data_input_mode',
				'cardholder_auth_method',
				'cardholder_auth_entity',
				'card_data_output_cap',
				'terminal_data_out_cap',
				'pin_capture_cap'
			);
			// make sure each key exists in the result array
			$result = array();
			foreach($fields as $f) {
				$result[$f] = '';
			}
			
			$ta = explode("\x1c", $this->getParsedBit63TableData('MC'));
			foreach($ta as $index=>$field) {
				$field_num = (int)substr($field,0,2);
				$result[$fields[($field_num-1)]] = substr($field,2);
			}
			return $result;
		}
	}
	// </editor-fold>
	
	// <editor-fold defaultstate="collapsed" desc="Bit63 TableDS Parsing functions">
	public function getParsedBit63TableDS() {
		return $this->parseBit63TableDS();
	}
	public function parseBit63TableDS(){
		if ($this->dataExistsForBit(63) && $this->dataExistsForTable('DS')) {
			// This code must be completed for table DS. pg 4-142
			$fields = array(
				array('processing_code', 6),
				array('sys_trc_audit_num', 6),
				array('pos_entry_mode', 4),
				array('local_tran_time', 6),
				array('local_tran_date', 6),
				array('response_code', 2),
				array('pos_data', 13),
				array('trk_data_cond', 2),
				array('avs', 1),
				array('nrid', 15),
				'UF' => array('unknown')
			);
			// prefill each key with a default value so the insert query does
			// not need to check for the existence of each key=>value pair
			$result = array();
			foreach($fields as $fnum=>$f) {
				$result[$f[0]] = '';
			}
			$ta = explode("\x1c", $this->getParsedBit63TableData('DS'));
			//Vendor::log(Vendor::LOG_LEVEL_DEBUG, "We have ".count($ta)." elements of table DS data");
			//echo "About to start foreach loop! Line:".__LINE__."\n";
			foreach($ta as $field_data) {
				// the first two characters of the field data is the field number
				if (substr($field_data,0,2) === 'UF') {
					$field_id = 'UF';
				} else {
					$field_id = (int)substr($field_data,0,2)-1;
				}
				$field_name = $fields[$field_id][0];
				$result[$field_name] = substr($field_data,2);
			}
			// Because each field in table DS is optional we need to ensure their is a default value
			// for each field
			if (count($result) < count($fields)) {
				foreach($fields as $key => $fdata) {
					if (!array_key_exists($fdata[0], $result)) {
						$result[$fdata[0]] = '';
					}
				}
			}
			return $result;
		} else {
			return 'Table DS not found!';
		}
	}
	// </editor-fold>
	
	public function getBit63DebugData() {
		$debug = '';
		//output the bit63 tables (Vendor specific information)
		if ($this->dataExistsForBit(63)){
			$debug .= "\n".$this->getBit63Tables();
			try {
				$debug .= "Bit63 Table14 parsed data:\n";
				$debug .= print_r($this->getParsedBit63Table14(), true);
				$debug .= "Bit63 Table22 parsed data:\n";
				$debug .= print_r($this->getParsedBit63Table22(), true)."\n";
				$debug .= 'Card type:'.$this->card_type."\n";
				
				if ($this->dataExistsForTable('VI')) {
					$debug .= "Bit63 Table VI parsed data:\n";
					$debug .= print_r($this->getParsedBit63TableVI(), true);
				} else if ($this->dataExistsForTable('MC')) {
					$debug .= "Bit63 Table MC parsed data:\n";
					$debug .= print_r($this->getParsedBit63TableMC(), true);
				} else if ($this->dataExistsForTable('DS')) {
					$debug .= "Bit63 Table DS parsed data:\n";
					$debug .= print_r($this->getParsedBit63TableDS(), true);
				} else {
					$debug .= "Bit63 VI/MC/DS Tables not found and \n American Express cards do not have a compliance table\n";
				}
			}catch(Exception $e) {
				$debug .= 'Caught exception trying to get Bit 63 Table 14 Data:'.$e;
			}
		}
		return $debug;
	}
	
	public function getCardType(){
		return $this->card_type;
	}
	
}

?>
