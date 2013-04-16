<?php

/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of Transaction
 *
 * @author jcrow
 */
class RealTimeTransaction extends Transaction {
	protected $transID;
	public $user_name = '';
	private $site_id = 'U00';
	public $pkg_description = '';
	public $uv = '';
	public $frozen = '';
	public $trans_date = '';
	public $draft_amount = 0.00;
	public $tan_tax_amount = 0.00;
	public $trans_amount = 0.00;
	public $response_code = '';
	public $response_text = '';
	public $cc_exp = '';
	public $cc_last_four = '';
	public $cc_number = '';
	public $cc_type = '';
	public $avs_data = '';
	public $avs_resp = '';
	public $pos_entry_pin = '';
	public $pos_condition_code = '';
	public $processing_code = '';
	public $refunded = '';
	public $db_row;
	public $trans_type = -1;
	/**
	 * Transaction Type as a string. From the trans_type_list table in DB
	 * @var String
	 */
	protected $type_name = '';
	
	/**
	 * Name, HTML input details
	 * if the name is an array, the pieces are separated by / and the input
	 * details should be an array of arrays
	 * @var Array - array of the lines to output for the HTML detail view
	 */
	protected $elems = array(
		array(null, array('transID', 'hidden', 10)),
		array('Trans Date', array('trans_date', 'text', 10)),
		array(array('CC Number', 'Exp(YYMM)'), array(array('cc_number', 'text',20), array('cc_exp', 'text',4))),
		array(array('AVS Data', 'Response'), array(array('avs_data','text', 30), array('avs_response','text', 2))),
		array(array('Transaction Type','Processing Code'), array(array('type_name', 'text',20),array('processing_code', 'text',7))),
		array(array('POS Entry Pin', 'Condition Code'), array(array('pos_entry_pin', 'text',4),array('pos_condition_code', 'text',4))),
		array(array('Response Code', 'Text', 'Refunded'), array(
				array('response_code', 'text',2), array('response_text', 'text',22),array('refunded', null, 2)
			)
		)
	);
	
	public function __construct($db_row) {
		$this->db_row = $db_row;
		$this->id = $db_row['id'];
		$this->transID = $this->id;
		$this->trans_date = date('Y-m-d', strtotime($db_row['submit_dt']));
		$this->trans_amount = $db_row['trans_amount'];
		$this->response_code = $db_row['response_code'];
		$this->response_text = $db_row['response_text'];
		$this->cc_exp = $db_row['cc_exp'];
		$this->cc_last_four = $db_row['cc_last_four'];
		$this->cc_type = $db_row['cc_type'];
		$this->cc_number = Vendor::decrypt_data($db_row['pri_acct_no']);
		$this->trans_type = $db_row['trans_type'];
		if (array_key_exists('type_name', $db_row)) {
			$this->type_name = $db_row['type_name'];
		}
		$this->avs_data = $db_row['avs_data'];
		$this->avs_response = $db_row['avs_response'];
		$this->pos_entry_pin = $db_row['pos_entry_pin'];
		$this->pos_condition_code = $db_row['pos_condition_code'];
		$this->processing_code = $db_row['processing_code'];
	}
	
}

?>

