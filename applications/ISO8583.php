<?php
// +------------------------------------------------------------------------+
// | ISO8583.php		                                                    |
// +------------------------------------------------------------------------+
// | Copyright (c) Jimmi Kembaren 2009. All rights reserved.                |
// | Version       0.8                                                      |
// |	Add support for Binary Coded Decimal values and size prefixes	    |
// | Last modified 2012-10-31 by James Crow (crow.jamesm@gmail.com)                                               |
// | Email         jimmi.kembaren@gmail.com                                 |
// | Web           http://www.iso8583online.com                             |
// +------------------------------------------------------------------------+
// | This program is free software; you can redistribute it and/or modify   |
// | it under the terms of the GNU General Public License version 2 as      |
// | published by the Free Software Foundation.                             |
// |                                                                        |
// | This program is distributed in the hope that it will be useful,        |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of         |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the          |
// | GNU General Public License for more details.                           |
// |                                                                        |
// | You should have received a copy of the GNU General Public License      |
// | along with this program; if not, write to the                          |
// |   Free Software Foundation, Inc., 59 Temple Place, Suite 330,          |
// |   Boston, MA 02111-1307 USA                                            |
// |                                                                        |
// | Please give credit on sites that use class.upload and submit changes   |
// | of the script so other people can use them as well.                    |
// | This script is free to use, don't abuse.                               |
// +------------------------------------------------------------------------+
//

   
class ISO8583 {
    // <editor-fold defaultstate="collapsed" desc="Class Constants">
	const MSG_TYPE_AUTH_REQ = 1;
	const MSG_TYPE_AUTH_RESP = 2;
	const MSG_TYPE_REV_REQ = 3;
	const MSG_TYPE_REV_RESP = 4;
	
	const TCP_MESSAGE_PREFIX = "\x02\x46\x44\x02";
	const TCP_MESSAGE_SUFFIX = "\x03\x46\x44\x03";

	// </editor-fold>
    // <editor-fold defaultstate="open" desc="Class properties">
	
	// <editor-fold defaultstate="collapsed" desc="Class Data Maps">
    /**
     * This is the map for the data bit values
     * Each element is an array that describes the actual data sent
     * format is array((String)type, (int)maxlength, (bool)fixed_length, (String)prefix_type, (int)prefix_size, (String)pad_character), (String)pad_modifier
     * Valid values for type are:
     * b   => bit
     * n   => numeric (decimal)
     * a   => alphabetic string
     * an  => alphanumeric string
     * x   => ??? string
     * ans => ??? string
     * bcd => Binary Coded Decimal (2 integers per byte)
     * @var Array[][]
     */
    protected $DATA_ELEMENT	= array (
		// bit => array(type, size, variable size, prefix type, prefix length, pad character, padding_modifier
        1	=> array('b',	64,		0,	'',		0,	'0'),
        2	=> array('bcd', 19,		1,	'bcd',	1,	'0'),
        3	=> array('bcd', 6,		0,	'',		0,	'0'),
        4	=> array('bcd', 12,		0,	'',		0,	'0'),
        5	=> array('n',	12,		0,	'',		0,	'0'),
        6	=> array('n',	12,		0,	'',		0,	'0'),
        7	=> array('bcd', 10,		0,	'',		0,	'0'),
        8	=> array('n',	8,		0,	'',		0,	'0'),
        9	=> array('n',	8,		0,	'',		0,	'0'),
        10	=> array('n',	8,		0,	'',		0,	'0'),
        11	=> array('bcd', 6,		0,	'',		0,	'0'),
        12	=> array('bcd', 6,		0,	'',		0,	'0'),
        13	=> array('bcd', 4,		0,	'',		0,	'0'),
        14	=> array('bcd', 4,		0,	'',		0,	'0'),
        15	=> array('n',	4,		0,	'',		0,	'0'),
        16	=> array('n',	4,		0,	'',		0,	'0'),
        17	=> array('n',	4,		0,	'',		0,	'0'),
        18	=> array('bcd', 4,		0,	'',		0,	'0'),
        19	=> array('n',	3,		0,	'',		0,	'0'),
        20	=> array('n',	3,		0,	'',		0,	'0'),
        21	=> array('n',	3,		0,	'',		0,	'0'),
        22	=> array('bcd', 3,		0,	'',		0,	'0'),
        23	=> array('bcd',	4,		0,	'',		0,	'0'),
        24	=> array('bcd', 3,		0,	'',		0,	'0'),
        25	=> array('bcd', 2,		0,	'',		0,	'0'),
        26	=> array('n',	2,		0,	'',		0,	'0'),
        27	=> array('n',	1,		0,	'',		0,	'0'),
        28	=> array('n',	8,		0,	'',		0,	'0'),
        29	=> array('an',	9,		0,	'',		0,	'0'),
        30	=> array('n',	8,		0,	'',		0,	'0'),
		// bit => array(type, size, variable size, prefix type, prefix length, pad character, padding_modifier
        31	=> array('an',	99,		1,	'bcd',	1,	'0'),
        32	=> array('bcd', 12,		1,	'bcd',	1,	'0'),
        33	=> array('n',	11,		1,	'',		0,	'0'),
        34	=> array('an',	28,		1,	'',		0,	'0'),
        35	=> array('bcd',	37,		1,	'bcd',	1,	'0'),
        36	=> array('n',	104,	1,	'',		0,	'0'),
        37	=> array('an',	12,		0,	'',		0,	'0'),
        38	=> array('an',	6,		0,	'',		0,	'0'),
        39	=> array('an',	2,		0,	'',		0,	'0'),
        40	=> array('an',	3,		0,	'',		0,	'0'),
        41	=> array('ans', 8,		0,	'',		0,	'0'),
        42	=> array('ans', 15,		0,	'',		0,	'0'),
        43	=> array('ans', 107,	0,	'',		0,	'0'),
        44	=> array('an',	1,		1,	'bcd',	2,	'0'),
        45	=> array('an',	76,		1,	'bcd',	1,	'0'),
        46	=> array('an',	999,	1,	'',		0,	'0'),
        47	=> array('an',	999,	1,	'',		0,	'0'),
		// bit 48 is special because it has a 2 byte BCD prefix but is a fixed length
        48	=> array('ans', 31,		1,	'bcd',	2,	' '),
        49	=> array('bcd',	3,		0,	'',		0,	'0'),
        50	=> array('an',	3,		0,	'',		0,	'0'),
        51	=> array('a',	3,		0,	'',		0,	'0'),
        52	=> array('an',	16,		0,	'',		0,	'0'),
        53	=> array('an',	18,		0,	'',		0,	'0'),
        54	=> array('an',	120,	0,	'byte', 2,	'0'),
        55	=> array('ans', 999,	1,	'byte', 2,	'0'),
        56	=> array('ans', 999,	1,	'',		0,	'0'),
        57	=> array('ans', 999,	1,	'',		0,	'0'),
        58	=> array('ans', 999,	1,	'',		0,	'0'),
        59	=> array('ans', 9,		1,	'bcd',	1,	'0'),
        60	=> array('bcd', 2,		0,	'',		0,	'0'),
		// bit => array(type, size, variable size, prefix type, prefix length, pad character, padding_modifier
        61	=> array('ans', 99,		1,	'',		0,	'0'),
        62	=> array('ans', 999,	1,	'',		0,	'0'),
        63	=> array('ans', 999,	1,	'bcd',	2,	'0'),
        64	=> array('b',	16,		0,	'',		0,	'0'),
        65	=> array('b',	16,		0, '',		0,	'0'),
        66	=> array('n',	1,		0, '',		0,	'0'),
        67	=> array('n',	2,		0),
        68	=> array('n',	3, 0),
        69	=> array('n',	3, 0),
        70	=> array('bcd',	3,		0,	'',		0,	'0'),
        71	=> array('n',	4, 0),
        72	=> array('ans', 999, 1),
        73	=> array('n',	6, 0),
        74	=> array('n',	10, 0),
        75	=> array('n',	10, 0),
        76	=> array('n',	10, 0),
        77	=> array('n',	10, 0),
        78	=> array('n',	10, 0),
        79	=> array('n',	10, 0),
        80	=> array('n',	10, 0),
        81	=> array('n',	10, 0),
        82	=> array('n',	12, 0),
        83	=> array('n',	12, 0),
        84	=> array('n',	12, 0),
        85	=> array('n',	12, 0),
        86	=> array('n',	15, 0),
        87	=> array('an',	16, 0),
        88	=> array('n',	16, 0),
        89	=> array('n',	16, 0),
        90	=> array('an',	42, 0),
        91	=> array('an',	1, 0),
        92	=> array('n',	2, 0),
        93	=> array('ans',	5,		0,	'',		0,	'0'),
        94	=> array('an',	7, 0),
        95	=> array('an',	42, 0),
        96	=> array('an',	18,		1, 'byte',	1,	'0'),
        97	=> array('an',	17, 0),
        98	=> array('ans', 25, 0),
        99	=> array('n',	11, 1),
        100	=> array('bcd',	11,		1, 'bcd',	1,	'0'),
        101	=> array('ans', 17, 0),
        102	=> array('ans', 28, 1),
        103	=> array('ans', 28, 1),
        104	=> array('an',	99, 1),
        105	=> array('ans', 999, 1),
        106	=> array('ans', 999, 1),
        107	=> array('ans', 999, 1),
        108	=> array('ans', 999, 1),
        109	=> array('ans', 999, 1),
        110	=> array('ans', 999, 1),
        111	=> array('ans', 999, 1),
        112	=> array('ans', 999, 1),
        113	=> array('n',	11, 1),
        114	=> array('ans', 999, 1),
        115	=> array('ans', 999, 1),
        116	=> array('ans', 999, 1),
        117	=> array('ans', 999, 1),
        118	=> array('ans', 999, 1),
        119	=> array('ans', 999, 1),
        120	=> array('ans', 999, 1),
        121	=> array('ans', 999, 1),
        122	=> array('ans', 999, 1),
        123	=> array('ans', 999, 1),
        124	=> array('ans', 255, 1),
        125	=> array('ans', 50, 1),
        126	=> array('ans', 6, 1),
        127	=> array('ans', 999, 1),
        128	=> array('b',	16, 0)
    );
    
    /**
     * Describe what each bit value represents
     * @var String[]
     */
    protected $DATA_DESC = array(
        1       => "BitMap",
        2       => "Primary Account Number",
        3       => "Processing Code",
        4       => "Amount of Transaction",
        7       => "Transmission Date/Time (GMT)",
        11      => "System Trace/Debit Reg E Receipt Number",
        12      => "Time, Local Transmission",
        13      => "Date, Local Transmission (Debit)/Sales Date (Credit)",
        14      => "Expiration Date (YYMM)",
        18      => "Merchant Category Code",
        22      => "POS Entry Mode + PIN Capability",
        23      => "Card Sequence Number",
        24      => "Network International ID (NII)",
        25      => "Point of Service (POS) Condition Code",
        31      => "Acquirer Reference Data",
        32      => "Acquiring ID",
        35      => "Track 2 Data",
        37      => "Retrieval Reference Number",
        38      => "Authorization Identification Response",
        39      => "Response Code",
        41      => "Terinal ID",
        42      => "Merchant ID",
        43      => "Alternative Merchant Name/Location",
        44      => "Additional Response Data",
        45      => "Track 1 Data",
		48		=> "Private Use (Check Verify, AVS)",
        49      => "Transaction Currency Code",
        52      => "Encrypted PIN Data",
        54      => "Additional Amounts",
        55      => "EMV Data",
        59      => "Merchant ZIP/Postal Code",
        60      => "Additional POS Information",
        62      => "",
        63      => "Private Use Data Element",
        70      => "Network Management Information Code",
        93      => "Response Indicator",
        100     => "Receiving Institution Code"
    );
	
	/**
	 * The offset (from message start, position 0) of each data element
	 * @var Integer[]
	 */
	protected $DATA_OFFSET = array(
		2		=> -1,
		3		=> -1,
		4		=> -1,
		7		=> -1,
		11		=> -1,
		12		=> -1,
		13		=> -1,
		14		=> -1,
		18		=> -1,
		22		=> -1,
		23		=> -1,
		24		=> -1,
		25		=> -1,
		31		=> -1,
		32		=> -1,
		35		=> -1,
		37		=> -1,
		38		=> -1,
		39		=> -1,
		41		=> -1,
		42		=> -1,
		43		=> -1,
		44		=> -1,
		45		=> -1,
		48		=> -1,
		49		=> -1,
		52		=> -1,
		54		=> -1,
		55		=> -1,
		59		=> -1,
		60		=> -1,
		62		=> -1,
		63		=> -1,
		70		=> -1,
		93		=> -1,
		96		=> -1,
		100		=> -1
	);
	// </editor-fold>
	
	/**
	 * The tables of data obtained from the Bit63 data. These are vendor specific
	 * @var Array[Int][]
	 */
	protected $BIT63_TABLES = array();
	
	/**
	 * The HEX value of the bit 63 data
	 * @var String
	 */
	public $b63_hex = '';
	
	/**
	 * The size of each data element in bytes. Use this with $DATA_OFFSET to pull
	 * each element from the ISO string of the message
	 * @var Int
	 */
	protected $DATA_SIZE = array();
    
	/**
	 * The values for each data element in the ISO message
	 * @var String[]
	 */
    protected $_data	= array();
	
	/**
	 * First 6 bytes of the raw data when importing an ISO
	 * @var String
	 */
	public $data_first_6 = '';
	
	/**
	 * The bitmap for the message. Either Binary or Hex depending on the value
	 * of $this->bitmap_as_hex
	 * @var String
	 */
    protected $_bitmap	= '';
	/**
	 * The Message Type Indicator (MTI). Either BCD or ASCII depending on the 
	 * value of $this->mti_use_bcd
	 * @var String
	 */
    protected $_mti	= '';
    protected $_iso	= '';
	
	/**
	 * First 12 binary bytes of the raw iso
	 * @var String
	 */
	public $iso_first_12 = '';
	
	protected $_valid	= array();
	
	/**
	 * flag to determine if the MTI value is stored as BCD or HEX
	 * @default true
	 * @var bool
	 */
	protected $mti_use_bcd = true;
	/**
	 * flag to determine if the bitmap is stored as HEX values
	 * @default false
	 * @var bool
	 */
	protected $bitmap_as_hex = false;
	/**
	 * Offset of the start of the bitmap from beginning of the ISO message
	 * @var Int
	 */
	protected $_bitmapOffset = -1;
	
	/**
	 * Binary string representing the bitmap
	 * @var String
	 */
	public $bitmapString = '';
	
	/**
	 * This is the size of the decoded mesage in bytes
	 * @var Int
	 */
	protected $_msg_size = 0;
	
	/**
	 * Credit card object used to validate the card number
	 * @var Credit_Card Object
	 */
	public $credit_card;
	/**
	 * The type of credit card in this transaction
	 * @var String
	 */
	public $card_type = '';
	
	/**
	 * Receipt number (system trace number) bit 11 
	 * @var int
	 */
	public $receipt_number = -1;
	
	/**
	 * Bit 37 data. This is the DB record ID for this transaction
	 * @var Int
	 */
	public $retrieval_reference_number = -1;
	
	/**
	 * Terminal ID that generated this message. Bit 41
	 * @var int
	 */
	public $terminal_id = -1;
	
	/**
	 *
	 * @var Merchant ID that generated this message. Bit 42
	 */
	public $merchant_id = -1;
	
	/**
	 * One of the class constants that describes what type of message (MTI) this is
	 * @var int
	 */
	public $msg_type = -1;
	
	/**
	 * The account number for this transaction
	 * @var String
	 */
	public $pri_acct_no;
	
	/**
	 * Database ID of the record for the original transaction
	 * This only applies to 0110 and 0410 messages
	 * @var int
	 */
	public $original_trans_id = -1;
	
	/**
	 * Transaction amount of the original transaction
	 * This only applies to 0110 and 0410 messages
	 * @var float
	 */
	public $original_trans_amount;
	
	/**
	 * One character response to AVS request.
	 * @var String
	 */
	public $avs_response = null;
	
	/**
	 * One character response to the presented CVC/CVV2 code
	 * @var String
	 */
	public $cvc_response = null;

    // </editor-fold>
    
    // <editor-fold defaultstate="collapsed" desc="Class Private Methods">
    
    //return data element in correct format
    protected function _packElement($data_element, $data, $bit = -1) {
        $prefix = "";
        $result	= "";

		// bit 35 requires special handling
		if ($bit === 35) {
			$result = $this->_packBit35Data($data);
		} else {
			//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Bit '.$bit.', $data_element:'.print_r($data_element, true).', $data:'.$data);
			//numeric value
			if ($data_element[0]=='n' && is_numeric($data) && strlen($data)<=$data_element[1]) {
				$data	= str_replace(".", "", $data);

				//fix length
				if ($data_element[2]==0) {
					// check data length
					if (strlen($data) > $data_element[1]) {
						throw new Exception("Data is longer than max! Data:{$data}, element:".print_r($data_element, true));
					}
					$result	= sprintf("%0". $data_element[1] ."s", $data);
				}
				//dynamic length
				else {
					if (strlen($data) <= $data_element[1]) {                
						$result	= sprintf("%0". strlen($data_element[1])."d", strlen($data)). $data;
					} else {
						throw new Exception("Data is longer than max! Data:{$data}, element:".print_r($data_element, true));
					}
				}
			}

			// bcd value (Binary Coded Data)
			if ($data_element[0]=='bcd' && is_numeric($data) && strlen($data)<=$data_element[1]) {
				// remove all decimal points
				$data       = str_replace(".", "", $data);

				// fixed length value
				if ($data_element[2]==0) {
					// extend our number to match the correct length
					$data = str_pad($data,$data_element[1],"0", STR_PAD_LEFT);
				} 
				// dynamic length value, dynamic lengths require BCD prefix
				else {
					$prefix = $this->_calculateBCDPrefix($data_element, $data);
					//echo "\$prefix (hex): ".bin2hex($prefix)." for data of length".strlen($data)."\n";
					// BCD values must be a multiple of 2 in length. pad on right
					if (strlen($data)%2 == 1) {
						$data .= "0";
					}
				}

				//echo "\$data: {$data}\n";
				//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Bit:'.$bit.' BCD data using prefix:'.$prefix.', and data:'.$data);

				// create our BCD data value
				$result = $prefix.$this->convertDEC2BCD($data);
			}

			//alpha value
			if (($data_element[0]=='a' && ctype_alpha($data) && strlen($data)<=$data_element[1]) ||
				($data_element[0]=='an' && ctype_alnum($data) && strlen($data)<=$data_element[1]) ||
				($data_element[0]=='z' && strlen($data)<=$data_element[1]) ||
				($data_element[0]=='ans' && strlen($data)<=$data_element[1])) {
				//fixed length
				if ($data_element[2]==0) {
					// check data length
					if (strlen($data) > $data_element[1]) {
						throw new Exception("Data is longer than max! Data:{$data}, element:".print_r($data_element, true));
					}
					$ts = sprintf("%". $data_element[5].$data_element[1] ."s", $data);
					$data	= $ts;
					//echo __LINE__."\$result: {$result}\n";
				} 
				//dynamic length
				else {
					// prepend a size prefix, if required
					if (array_key_exists(3, $data_element) && strlen($data_element[3]) > 0) {
						switch($data_element[3]) {
							case 'bcd':
								// create our prefix bytes using the size for this data
								$prefix = $this->_calculateBCDPrefix($data_element, $data);
								//$prefix = $this->convertDEC2BCD(str_pad(strlen($data),$data_element[4],'0', STR_PAD_LEFT));
								//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Bit '.$bit.' using BCD size prefix (HEX):'.bin2hex($prefix));
								break;
							default:
								//Vendor::logger(Vendor::LOG_LEVEL_ERROR, 'Bit '.$bit.' has unknown size prefix type:'.$data_element[3]);
						}
					}
					if (strlen($prefix.$data) <= ($data_element[1]+$data_element[4])) {
						//$result	= $prefix . sprintf("%0". strlen($data_element[1])."s", strlen($prefix.$data)). $data;
						// This should pad the value out to the proper length
						// an => left pad with zero
						// ans => right pad with space
						//$result = $prefix.$data;
					} else {
						throw new Exception("Data is longer than max! Data:{$data}, element:".print_r($data_element, true));
					}
				}
				$result = $prefix.$data;
				//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Bit:'.$bit.' ALPHA data using prefix:'.$prefix.', and data:'.$data);
			} else {
				//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Bit '.$bit.' not of alpha type! '.print_r($data_element, true));
			}

			//bit value
			if ($data_element[0]=='b' && strlen($data)<=$data_element[1]) {
				//fix length
				if ($data_element[2]==0) {
					$tmp	= sprintf("%0". $data_element[1] ."d", $data);

					while ($tmp!='') {
						$result	.= base_convert(substr($tmp, 0, 4), 2, 16);
						$tmp	= substr($tmp, 4, strlen($tmp)-4);
					}
				}
			}
		}
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Bit:'.$bit.' _packElement() returns (HEX):'.bin2hex($result));
        return $result;
    }
	
	private function _packBit35Data($d) {
		// split on our = 
		$da = explode('=', $d);
		if (count($da) !== 2) {
			throw new Exception('Bit35 Data not valid data:'.$d);
		}
		/**
		 * Array of 4 bit strings representing each character in the data
		 * @var String[] 
		 */
		$bs = array();
		// loop over the first part of our data and convert to 4 bit string
		for($i = 0, $stop = strlen($da[0]); $i < $stop; $i++) {
			$bs[] = str_pad(decbin($da[0][$i]), 4, '0', STR_PAD_LEFT);
		}
		// add our special 1101 bit string (represents 0xd, or "=")
		$bs[] = '1101';
		// loop over the rest of our data and add it
		for($i = 0, $stop = strlen($da[1]); $i < $stop; $i++) {
			$bs[] = str_pad(decbin($da[1][$i]), 4, '0', STR_PAD_LEFT);
		}
		/* This is how many bytes of data should be in the decoded value. BCD packed is half this size */
		$data_len = count($bs);
		// make sure we have an even number of 1/2 byte values
		if (count($bs)%2) {
			$bs[] = '0000';
		}
		// convert our 1/2 byte binary strings into bytes
		$return = "";
		for($i = 0, $stop = count($bs); $i < $stop; $i+=2) {
			$return .= pack('C', bindec($bs[$i].$bs[$i+1]));
		}
		//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$bs[]:'.print_r($bs, true));
		//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$da[]:'.print_r($da, true));
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, "Creating BCD prefix for data of length:".strlen($return));
		$prefix = $this->_calculateBCDPrefix($this->DATA_ELEMENT[35], $d);
		return $prefix.$return;
	}
    
    /**
     * _calculateBCDPrefix() - create a BCD prefix for variable length data elements
     * @param Array[] $data_element - the data value description
     * @param String $data data element for message (always ASCII data)
     * @return Byte String
     */
    protected function _calculateBCDPrefix($data_element, $data) {
        
        // the prefix length is max data length divided by 10 and rounded up, or
		// the size specified in $data_element[4]; whichever is greater
		$max_elem_len = strlen($data_element[1]); 
        $pfx_len = max($max_elem_len,($data_element[4]*2));
        $pfx = str_pad(strlen($data), $pfx_len, "0", STR_PAD_LEFT);
        return $this->convertDEC2BCD($pfx);
    }

    //calculate bitmap from data element    
    protected function _calculateBitmap() {	
        $tmp	= sprintf("%064d", 0);    
        $tmp2	= sprintf("%064d", 0);    
        foreach ($this->_data as $key=>$val) {
            if ($key<65) {
                $tmp[$key-1]	= 1;
            }
            else {
                $tmp[0]	= 1;
                $tmp2[$key-65]	= 1;
            }
        }
        
        $result	= "";
        if ($tmp[0]==1) {
            while ($tmp2!='') {
                $result	.= base_convert(substr($tmp2, 0, 4), 2, 16);
                $tmp2	= substr($tmp2, 4, strlen($tmp2)-4);
            }
        }
        $main	= "";
        // convert our $tmp bit string into HEX values on nibble at a time
//        while ($tmp!='') {
//            $main	.= base_convert(substr($tmp, 0, 4), 2, 16);
//            $tmp	= substr($tmp, 4, strlen($tmp)-4);
//        }
//        $this->_bitmap	= strtoupper($main. $result);
        
        // convert out $tmp bit string into bytes
        for($i =0; $i < strlen($tmp); $i+=8){
            $main .= pack('C',bindec(substr($tmp,$i,8)));
        }
        $this->_bitmap = $main;
        
        return $this->_bitmap;
    }
    
    
    //parse iso string and retrieve mti 
	/**
	 * _parseMTI()- grab the MTI from an ISO string and validate it
	 */
    protected function _parseMTI() {
		if ($this->mti_use_bcd) {
			$mti = str_pad($this->convertBCDByte2DEC(substr($this->_iso,0,2)), 4, '0', STR_PAD_LEFT);
			$this->addMTI($mti);
			if (strlen($this->_mti) == 2 && $this->_mti[1] != 0) {
				$this->_valid['mti'] = true;
			}
		} else {
			$this->addMTI(substr($this->_iso, 0, 4));
			if (strlen($this->_mti)==4 && $this->_mti[1]!=0) {
				$this->_valid['mti'] = true;
			}
		}
		$this->_msg_size += strlen($this->_mti);
		
		// set the message type based on the MTI
		if (strlen($this->_mti) == 2) {
			$mti = bin2hex($this->_mti);
		} else {
			$mti = $this->_mti;
		}
		switch($mti) {
			case "0100":
				$this->msg_type = self::MSG_TYPE_AUTH_REQ;
				break;
			case "0110":
				$this->msg_type = self::MSG_TYPE_AUTH_RESP;
				break;
			case "0400":
				$this->msg_type = self::MSG_TYPE_REV_REQ;
				break;
			case "0410":
				$this->msg_type = self::MSG_TYPE_REV_RESP;
				break;
			default:
				$this->msg_type = -1;
		}
    }

    //clear all data
    protected function _clear() {
        $this->_mti	= '';
        $this->_bitmap	= '';
        $this->_data	= '';
        $this->_iso	= '';
    }

    //parse iso string and retrieve bitmap    
	/**
	 * _parseBitmap()- read the bitmap value from the ISO message 
	 * @return String (HEX or Byte) based on value of $bitmap_as_hex
	 */
    protected function _parseBitmap() {
        $this->_valid['bitmap']	= false;
		
		// define our bitmap start
		$bitMapStartPos = 4;
		if ($this->mti_use_bcd) {
			$bitMapStartPos = 2;
		}
		$this->_bitmapOffset = $bitMapStartPos;
		
		// define our bitmap length
		$bitMapLength = 16;
		if ($this->bitmap_as_hex) {
			$bitMapLength = 32;
		}
        
		// get the bitmap data
		$inp	= substr($this->_iso, $bitMapStartPos, $bitMapLength);
		$tmp = '';
		
		if ($this->bitmap_as_hex) {
			if (strlen($inp)>=16) {
				$primary	= '';
				$secondary	= '';
				for ($i=0; $i<16; $i++) {
					$primary	.= sprintf("%04d", base_convert($inp[$i], 16, 2));
				}
				if ($primary[0]==1 && strlen($inp)>=32) {
					for ($i=16; $i<32; $i++) {
						$secondary	.= sprintf("%04d", base_convert($inp[$i], 16, 2));
					}
					$this->_valid['bitmap'] = true;
				}
				if ($secondary=='') $this->_valid['bitmap']	= true;
			}
			//save to data element with ? character
			$tmp	= $primary. $secondary;
			for ($i=0; $i<strlen($tmp); $i++) {
				if ($tmp[$i]==1) {
					$this->_data[$i+1]	= '?';
				}
			}
		} else {
			// bitmap is 8 or 16 bytes
			// read in first byte to see if we need next 8 bytes
			if(bindec($inp[0]) < 128) {
				// we have only the first 8 byte bitmap
				$inp = substr($inp,0,8);
			} else {
				// our bitmap is 16 bytes
				$inp = substr($inp,0,16);
			}
			$bitmapString = '';
			for($i=0;$i<strlen($inp);$i++) {
				$ta = unpack('C', $inp[$i]);
				$bitmapString .= str_pad(decbin($ta[1]),8,'0', STR_PAD_LEFT);
			}
			$tmp = $inp;
			$this->_valid['bitmap'] = true;
			// traverse our bitmap string and look for which data pieces should exist in this message
			for($i=0; $i<strlen($bitmapString);$i++) {
				if($bitmapString[$i] == 1){
					$this->_data[$i+1] = '?';
				}
			}
			
		}
		
		$this->bitmapString = $bitmapString;
		
        $this->_bitmap	= $tmp;
		$this->_msg_size += strlen($this->_bitmap);
        return $tmp;
    }

    //parse iso string and retrieve data element
    protected function _parseData() {
		// get a string of just our data element
		$data = substr($this->_iso,($this->_bitmapOffset+strlen($this->_bitmap)));
		$this->data_first_6 = substr($data,0,6);
		// loop over our bitmap and find the offsets for each data element
		$lastOffset = $this->_bitmapOffset+strlen($this->_bitmap);
		$lastSize = 0;
		$lastKey = -1;
		for($i=0; $i <= max(array_keys($this->_data)); $i++ ){
			if (array_key_exists($i, $this->_data)) {
				
				// find our data type
				$elem_config = $this->DATA_ELEMENT[$i];
				$elem_size = 0;
				$elem_prefix = '';
				
				// look at elem config to find variable length and length prefix type
				if ($elem_config[2] === 1) {
					// variable length data element, read prefix type and get length
					if ($elem_config[3] === 'bcd') {

						// bcd length prefix
						$elem_prefix = substr($this->_iso,($lastOffset+$lastSize), $elem_config[4]);
						$elem_size = $this->convertBCDByte2DEC($elem_prefix);
						$this->DATA_OFFSET[$i] = $lastOffset + $lastSize;
						$lastOffset = $this->DATA_OFFSET[$i];
						// BCD values are stored two integers per byte
						// check the actual value storage type...BCD is two int per byte, ASCII is 1 char per byte
						if ($elem_config[0] === 'bcd') {
							$lastSize = ceil($elem_size/2) + strlen($elem_prefix);
						} else {
							$lastSize = (int)$elem_size + strlen($elem_prefix);
						}
						//$lastSize = ceil($elem_size/2) + strlen($elem_prefix);
					} else if ($elem_config[3] == 'byte') {
						// raw decimal value of byte length prefix
						$elem_prefix = substr($this->_iso,($lastOffset+$lastSize), $elem_config[4]);
						$elem_size = bindec($elem_prefix);
						$this->DATA_OFFSET[$i] = $lastOffset + $lastSize;
						$lastOffset = $this->DATA_OFFSET[$i];
						$lastSize = $elem_size + strlen($elem_prefix);
						//throw new Exception("Not implemented! elem_config prefix type==byte");
					} else {
						throw new Exception("Not implemented! elem_config prefix unknown! bit:{$i}");
					}
					
					
				} else {
					// fixed length data element
					$this->DATA_OFFSET[$i] = $lastOffset + $lastSize;
					if ($elem_config[0] == 'bcd') {
						$elem_size = ceil($elem_config[1]/2);
					} else {
						$elem_size = $elem_config[1];
					}
					$lastOffset = $this->DATA_OFFSET[$i];
					$lastSize = $elem_size;
				}
				$this->DATA_SIZE[$i] = $lastSize;
				$lastKey = $i;
				
				// extract this data element and place in the _data array
				$this->_data[$lastKey] = substr($this->_iso,$lastOffset+strlen($elem_prefix), $lastSize-strlen($elem_prefix));
				// add size of this element to our _msg_size
				$this->_msg_size += $lastSize;
				
			}
		}
		
		return $this->_data;
		
		/*
		 * All code below this is ignored...
		 */

        if (is_array($this->_data)) {
          $this->_valid['data']	= true;
          foreach ($this->_data as $key=>$val) {
            $this->_valid['de'][$key]	= false;
            if ($this->DATA_ELEMENT[$key][0]!='b') {
                //fix length
                if ($this->DATA_ELEMENT[$key][2]==0) {
                    $tmp	= substr($inp, 0, $this->DATA_ELEMENT[$key][1]);
                    if (strlen($tmp)==$this->DATA_ELEMENT[$key][1]) {
                        if ($this->DATA_ELEMENT[$key][0]=='n') {
                            $this->_data[$key]	= substr($inp, 0, $this->DATA_ELEMENT[$key][1]);
                        }
                        else {
                            $this->_data[$key]	= ltrim(substr($inp, 0, $this->DATA_ELEMENT[$key][1]));
                        }
                        $this->_valid['de'][$key]	= true;
                        $inp	= substr($inp, $this->DATA_ELEMENT[$key][1], strlen($inp)-$this->DATA_ELEMENT[$key][1]);
                    }
                }
                //dynamic length
                else {
                    $len	= strlen($this->DATA_ELEMENT[$key][1]);
                    $tmp	= substr($inp, 0, $len);
                    if (strlen($tmp)==$len ) {
                        $num	= (integer) $tmp;
                        $inp	= substr($inp, $len, strlen($inp)-$len);
                    
                        $tmp2	= substr($inp, 0, $num);
                        if (strlen($tmp2)==$num) {
                            if ($this->DATA_ELEMENT[$key][0]=='n') {
                                $this->_data[$key]	= (double) $tmp2;
                            }
                            else {
                                $this->_data[$key]	= ltrim($tmp2);
                            }
                            $inp	= substr($inp, $num, strlen($inp)-$num);
                            $this->_valid['de'][$key]	= true;
                        }
                    }
                    
                }
            }
            else {
                if ($key>1) {
                    //fix length
                    if ($this->DATA_ELEMENT[$key][2]==0) {
                        $start	= false;
                        for ($i=0; $i<$this->DATA_ELEMENT[$key][1]/4; $i++) {                        
                            $bit	= base_convert($inp[$i], 16, 2);
                            
                            if ($bit!=0) $start	= true;
                            if ($start) $this->_data[$key]	.= $bit;
                        }
                        $this->_data[$key]	= $bit;
                    }
                }
                else {
                    $tmp	= substr($this->_iso, 4+16, 16);
                    if (strlen($tmp)==16) {
                        $this->_data[$key]	= substr($this->_iso, 4+16, 16);
                        $this->_valid['de'][$key]	= true;
                    }
                }
            }
            if (!$this->_valid['de'][$key]) $this->_valid['data']	= false;
          }
        }

        return $this->_data;
    }
	
	
    
    /**
     * Convert from a bit string representation of a Binary Coded Number to its
     * decimal equivalent
     * @param Bit String $binary - the binary string (bit values) of the BCD number
     * @return Int 
     * @throws Exception - invalid BCD value
     */
    public function convertBCD2DEC($binary) { 
		$res = '';
        $len=strlen($binary); 
        $rows=($len/4)-1; 
        if (($len%4)>0) { 
            $pad=$len+(4-($len%4)); 
            $binary=str_pad($binary,$pad,"0",STR_PAD_LEFT); 
            $len=strlen($binary); 
            $rows=($len/4)-1; 
        } 
        $x=0; 
        for ($x=0;$x<=$rows;$x++) { 
            $s=($x*4); 
            $bins=$binary[$s].$binary[$s+1].$binary[$s+2].$binary[$s+3]; 
            $num=base_convert($bins,2,10); 
            if ($num>9) { 
                throw new Exception("the string is not a proper binary coded decimal  bit string:{$binary}\n"); 
            } else { 
                $res.=$num; 
            } 
        } 
        return (int)$res; 
    } 
	
	/**
	 * convertBCDByte2DEC() - convert a byte string encoded as Binary Coded Decimal
	 * into its equivalent integer representation
	 * @param String $bytes
	 * @return String of integers
	 */
	public function convertBCDByte2DEC($bytes){
		$bitstring = '';
		for($i=0; $i < strlen($bytes); $i++) {
			$ta = unpack('C',$bytes[$i]);
			$bitstring .= str_pad(decbin($ta[1]), 8, "0", STR_PAD_LEFT);
		}
		//echo "\$bitstring: {$bitstring}\n";
		return $this->convertBCD2DEC($bitstring);
	}
    
    /**
     * convertDEC2BCD() - convert a string of integers to their BCD representation in bytes
     * @param string (int) $i - string of integers to convert
     * @return Byte String
     */
    public function convertDEC2BCD($i){
        $bitString = "";
        $byteString = "";
        // make sure we only have integer values
        if (!is_numeric($i)) {
            throw new Exception("Value passed is not numeric! value:{$i}");
        }
        $i = str_replace(".", "", $i);
        
        // make sure our integer string has an even number of values
        if (strlen($i)%2) {
            $i = "0".$i;
        }
        
        // loop over our integers and create the bit string
        for($j = 0; $j < strlen($i); $j++) {
            $bitString .= str_pad(decbin(substr($i,$j,1)), 4, 0, STR_PAD_LEFT);
        }
        
        // convert every 8 bits into a byte
        for($j = 0; $j < strlen($bitString); $j+=8){
            $byteString .= pack('C',bindec(substr($bitString, $j,8)));
        }
        return $byteString;
    }
	/**
	 * Special ASCII to BCD function for bit 35 data (track 2 data)
	 * @param String $d - PAN+"D"+EXP+DisData
	 * @return String 
	 * @throws Exception
	 */
	public function convertBit35DataToBCD($d) {
		// split the string on ASCII "D" (stand in for hex 0xD, dec 13)
		$bitString = '';
		$byteString = '';
		$s = explode('D', $d);
		if (count($s) !== 2) {
			throw new Exception('Incorrect values passed for bit 35');
		}
		
		// the needs to be packed into a decimal string
		// loop over our PAN and create the bitString
		for($i=0; $i<strlen($s[0]); $i++) {
			$bitString .= str_pad(decbin($s[0][$j]), 4, 0, STR_PAD_LEFT);
		}
		// add our 0xD 1/2 bit
		$bitString .= '1101';
		// add the rest of our bit 35 data
		for($i=0; $i<strlen($s[1]); $i++) {
			$bitString .= str_pad(decbin($s[1][$j]), 4, 0, STR_PAD_LEFT);
		}
		
		// convert each 8 bit value into a byte
		for ($j=0; $j < strlen($bitString); $j+=8) {
			$byteString .= pack('C', dindec(substr($bitString,$j, 8)));
		}
		
		return $byteString;
	}
    // </editor-fold>
    
    // <editor-fold defaultstate="collpased" desc="Class Public Methods">

    //method: add data element
    public function addData($bit, $data) {
        if ($bit>1 && $bit<129) {
            $this->_data[$bit]	= $this->_packElement($this->DATA_ELEMENT[$bit], $data, $bit);
            ksort($this->_data);
            $this->_calculateBitmap();
        }
    }

    //method: add MTI
	/**
	 * addMTI() - adds the 4 digit integer MTI value
	 * @param String $mti - 4 digit integer string
	 * @throws Exception
	 */
    public function addMTI($mti) {
        if (strlen($mti)==4 && ctype_digit($mti)) {
			if ($this->mti_use_bcd) {
				$this->_mti = $this->convertDEC2BCD($mti);
			} else {
				$this->_mti = $mti;
			}
        } else {
            throw new Exception("Invalid MTI! Wrong length or non numeric! mti: {$mti}");
        }
    }	 
    

    //method: retrieve data element
    public function getData() {
        return $this->_data;
    }
	
	/**
	 * Returns the raw data for a given bit
	 * @param int $bit - the bit number to get data for
	 * @param bool $ascii - convert the data from its raw state to ASCII
	 * @return String
	 * @throws Exception
	 */
	public function getDataForBit($bit, $ascii=false) {
		if (array_key_exists($bit, $this->_data)) {
			if ($ascii) {
				// parse the bits data and convert from BCD, etc to ascii
				if ($this->DATA_ELEMENT[$bit][0] === 'bcd') {
					// convert from BCD to decimal and trim to maximum length
					$length = $this->DATA_ELEMENT[$bit][1];
					Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Trimming data to '.$length.' characters');
					return substr($this->convertBCDByte2DEC($this->_data[$bit]),0,$length);
				} else if ($bit == 41 || $bit == 42) {
					// this bits do not conform to normal ASCII/Numeric. They are zero filled on the left
					return ltrim($this->_data[$bit], '0');
					//else if ($this->DATA_ELEMENT[$bit][0] === 'ans') {
				}
				else {
					return $this->_data[$bit];
				}
			} else {
				return $this->_data[$bit];
			}
		} else {
			throw new Exception("Data for bit: {$bit}, not found");
		}
	}
	
	/**
	 * Determine if data value exists for the given bit
	 * @param int $bit The bit to check for data
	 * @return boolean
	 */
	public function dataExistsForBit($bit) {
		if (array_key_exists($bit, $this->_data)) {
			return true;
		} else {
			return false;
		}
	}
	
	/**
	 * dataExistsForTable() - check if a bit 63 table exists
	 * @param Int $table
	 * @return boolean
	 */
	public function dataExistsForTable($table) {
		if (array_key_exists($table, $this->BIT63_TABLES)) {
			return true;
		} else {
			return false;
		}
	}

    //method: retrieve bitmap
    public function getBitmap() {
        return $this->_bitmap;
    }

	/**
	 * getMTI() - return the MTI value for this ISO8583 object
	 * @param Bool $hex - should we return a HEX of binary version of the MTI
	 * @return String
	 */
    public function getMTI($hex = false) {
		if (!$hex) {
			return $this->_mti;
		} else {
			return bin2hex($this->_mti);
		}
    }

    //method: retrieve iso with all complete data
	/**
	 * getISO() - retrieve the entire ISO8583 message
	 * @return String
	 */
    public function getISO() {
		$this->_iso	= $this->_mti. $this->_bitmap. implode($this->_data);
		
        
        return $this->_iso;
    }
	
	/**
	 * getTCPMessage() - retrieve the ISO8583 message with appropriate header/size/trailer
	 * bytes for TCP transmission
	 * @return String
	 */
	public function getTCPMessage() {
		$iso = $this->getISO();
		$header = pack('C*', 0x02, 0x46, 0x44,0x02);
		$trailer = pack('C*', 0x03, 0x46, 0x44,0x03);
		$size = pack('n', strlen($iso));
		
		return $header.$size.$iso.$trailer;
	}
	
	public function getDataOffset() {
		return $this->DATA_OFFSET;
	}
	
	public function getDataSize() {
		return $this->DATA_SIZE;
	}
	
	public function getBit63Data() {
		return $this->BIT63_TABLES;
	}
	
	public function getISOSize(){
		if ($this->_msg_size > 0) {
			return $this->_msg_size;
		} else {
			return strlen($this->_iso);
		}
	}
         
    //method: add ISO string
	/**
	 * addISO() - import an ISO message string and parse its contents
	 * @param String (binary) $iso
	 */
    public function addISO($iso) {
        $this->_clear();
        if ($iso!='') {
            $this->_iso	= $iso;    
            $this->_parseMTI();
            $this->_parseBitmap();
            $this->_parseData();
			
			// set several key values so we can uniquely identify this transaction
			$fields = array(
				11 => 'receipt_number',
				37 => 'retrieval_reference_number',
				41 => 'terminal_id',
				42 => 'merchant_id',
				44 => 'avs_response'
			);

			foreach ($fields as $bit=>$fname) {
				if ($this->dataExistsForBit($bit)) {
					// update our class properties
					$this->$fname = $this->getDataForBit($bit, true);
				}
			}
			
			// determine our credit card type
			if (array_key_exists(14,$this->BIT63_TABLES)) {
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
			}
			// parse our bit63 data if present
			if (array_key_exists(63, $this->_data) && $this->_data[63] != '?' 
					&& method_exists($this, '_parseBit63Data')) {
				$this->_parseBit63Data();
				// set our CVV2/CVC response if available
				if (strlen($this->getParsedBit63Table49()) > 0) {
					$this->cvc_response = $this->getParsedBit63Table49();
				}
			}
			
			
			
			// set our PAN
			if($this->dataExistsForBit(2)){
				if(!is_object($this->credit_card)){
					$this->credit_card = new CreditCard(bin2hex($this->getDataForBit(2)));
				}
			}
			// determine our card type for 0100 messages
			if ($this->_mti == "\x01\x00" || $this->_mti == '0100') {
				$this->card_type = $this->credit_card->CreditCardType($this->_data[2]);
			}

			
        }
    }
	
	/**
	 * addTCPMessage() - Parse and validate a TCP Message containing an ISO8583 transaction
	 * @param String $msg
	 * @throws Exception
	 */
	public function addTCPMessage($msg) {
		$this->_clear();
		//echo "Received ".strlen($msg)." bytes of TCP data to process!\n";
		// check message header
		if (substr($msg,0,strlen(self::TCP_MESSAGE_PREFIX)) !== self::TCP_MESSAGE_PREFIX) {
			throw new Exception('Invalid message start prefix! Prefix found:'.bin2hex(substr($msg,0,strlen(self::TCP_MESSAGE_PREFIX))));
		}
		
		// check size
		$ta = unpack('n',substr($msg,strlen(self::TCP_MESSAGE_PREFIX),2));
		$size = $ta[1];
		if (!is_numeric($size)) {
			throw new Exception('Message size format incorrect! size:'.bin2hex(substr($msg,strlen(self::TCP_MESSAGE_PREFIX),2)));
		}
		if (strlen(substr($msg,strlen(self::TCP_MESSAGE_PREFIX)+2)) < $size) {
			throw new Exception('Not enough bytes for this message! Message length:'.strlen($msg).' size required:'.$size);
		}
		
		// check our trailer
		if (substr($msg,($size+strlen(self::TCP_MESSAGE_PREFIX)+2), strlen(self::TCP_MESSAGE_SUFFIX)) !== self::TCP_MESSAGE_SUFFIX) {
			throw new Exception('Message trailer not correct! Trailer:'.bin2hex(substr($msg,($size+strlen(self::TCP_MESSAGE_PREFIX)+2), strlen(self::TCP_MESSAGE_SUFFIX))));
		}
		
		// try to parse our message
		$this->_iso = substr($msg,strlen(self::TCP_MESSAGE_PREFIX)+2,$size);
		$this->addISO($this->_iso);
		
	}
    
    //method: return true if iso string is a valid 8583 format or false if not
    public function validateISO() {
        return $this->_valid['mti'] && $this->_valid['bitmap'] && $this->_valid['data'];
    }
    
    //method: remove existing data element
    public function removeData($bit) {
        if ($bit>1 && $bit<129) {
            unset($this->_data[$bit]);
            ksort($this->_data);            
            $this->_calculateBitmap();
        }
    }
    
    /**
     * get the data map with correspondng text description
     * @param String $ls - line separator to use, default \n
     * @return String - very long string with description->data
     */
    public function getDataDetail($ls = "\n") {
        $max_line_length = 0;
        foreach($this->DATA_DESC as $val) {
            $max_line_length = max($max_line_length, strlen($val));
        }
        $text = "";
        foreach($this->_data as $bit=>$val) {
            // grab the description from the $DATA_DESC array
            $desc = str_pad("({$this->DATA_DESC[$bit]})", $max_line_length);
			if ($this->DATA_ELEMENT[$bit][0] == 'bcd') {
				if ($this->_data[$bit] == "?") {
					// the _data map has not yet been populated with values
					$text .= "[{$bit}] {$desc}:{$val}{$ls}";
				} else {
					if ($bit === 35) {
						// this is for the special Bit35 bcd value
						// it contains HEX 0xd which is not valid BCD
						$ta = explode('d', bin2hex($val));
						$text .= "[{$bit}] {$desc}:".join('=', $ta).$ls;
					} else {
						$text .= "[{$bit}] {$desc}:".$this->convertBCDByte2DEC($val).$ls;
					}
				}
				
			} else {
				$text .= "[{$bit}] {$desc}:".$val.$ls;
			}
        }
        return $text;
    }
	
	/**
	 * getDecodedBitmap() - print the bit string representation of the bitmap.
	 * @return String - the bitmap as individual bits, one byte per line
	 */
	public function getDecodedBitmap() {
		$bitmap_string = '';
		for ($i=0;$i<strlen($this->_bitmap);$i++) {
			$ta = unpack('C',$this->_bitmap[$i]);
			//$dec_val = array_pop();
			//echo decbin($dec_val)."\n";
			$start_pos = ($i * 8)+1;
			$start_str = str_pad($start_pos, 3, ' ',STR_PAD_LEFT);
			$end_pos = $start_pos + 7;
			$end_str = str_pad($end_pos, 3, ' ', STR_PAD_LEFT);
			$bitmap_string .= "{$start_str} to {$end_str}:  ".str_pad(decbin($ta[1]),8,'0',STR_PAD_LEFT)."\n";
		}
		return $bitmap_string;
	}
	
	
	
	/**
	 * setCCType() - set the CreditCard type for this transaction
	 * @param String $type - the type of CreditCard for this transaction
	 */
	public function setCCType($type) {
		$this->card_type = $type;
	}
	
	/**
	 * Output basic transaction details as ASCII rows
	 */
	public function getBasicDebugData() {
		$debug = "";
		if ($this->mti_use_bcd) {
			$debug .= "MTI:			".bin2hex($this->_mti)."\n";
		} else {
			$debug .= "MTI:			{$this->_mti}\n";
		}
		$debug .=	  "original_trans_id:		".$this->original_trans_id."\n";
		// this array is the bits to output, the desc, and whether we should bin2hex the output
		$bits = array(
			3  => array('processng code', true),
			11 => array('  trace number', true),
			25 => array('condition code', true),
			31 => array('auth capt flag', true),
			38 => array('auth iden resp', false),
			39 => array('     resp code', false),
			44 => array('      avs resp', false)
		);
		foreach ($bits as $bit=>$desc){
			if ($this->dataExistsForBit($bit)) {
				if ($desc[1]) {
					$debug .= "\tbit {$bit}, {$desc[0]}:".bin2hex($this->getDataForBit($bit))."\n";
				} else {
					$debug .= "\tbit {$bit}, {$desc[0]}:".$this->getDataForBit($bit)."\n";
				}
			}
		}
		
		
		return $debug;
	}
    
    // </editor-fold>
    
}

?>