<?php
/**
 * Description of Transaction
 *
 * @author jcrow
 */
class Transaction {
	public $id = -1;
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
	private $process_server = '';
	private $decrypt_url = '/decrypt_cc_number.php';
	/**
	 * Name, HTML input details
	 * if the name is an array, the pieces are separated by / and the input
	 * details should be an array of arrays
	 * @var Array - array of the lines to output for the HTML detail view
	 */
	protected $elems = array(
		array(array('SX User name', 'Site Id','Trans Date'), array(array('user_name','text',10), array('site_id','text', 4), array('trans_date','text',10))),
		array(array('CC Number', 'Exp(YYMM)'), array(array('cc_number', 'text',20), array('cc_exp', 'text',4))),
		array(array('AVS Data', 'Response'), array(array('avs_data','text', 30), array('avs_response','text', 2))),
		array('Transaction Type', array('type_name', 'text',30)),
		array('Package Description', array('pkg_description', 'text',30)),
		array(array('UV', 'Frozen'), array(array('uv', 'text',2), array('frozen', 'text',2))),
		array(array('Pkg Amt', 'Tan Tax'), array(array('draft_amount', 'text',6),array('tan_tax_amount', 'text',6))),
		array('Processing Code', array('processing_code', 'text',7)),
		array(array('POS Entry Pin', 'Condition Code'), array(array('pos_entry_pin', 'text',4),array('pos_condition_code', 'text',4))),
		array(array('Response Code', 'Text', 'Refunded'), array(
				array('response_code', 'text',2), array('response_text', 'text',22),array('refunded', null, 2)
			)
		)
	);
	
	public function __construct($db_row) {
		$this->db_row = $db_row;
		$this->id = $db_row['id'];
		$this->user_name = $db_row['user_name'];
		$this->site_id = $db_row['site_id'];
		$this->pkg_description = $db_row['pkg_description'];
		$this->uv = ($db_row['uv']) ? 'Y':'N';
		$this->frozen = ($db_row['frozen']) ? 'Y':'N';
		$this->draft_amount = $db_row['draft_amount'];
		$this->tan_tax_amount = $db_row['tan_tax_amount'];
		$this->trans_date = date('Y-m-d', strtotime($db_row['schedule_date']));
		$this->trans_amount = $db_row['trans_amount'];
		$this->response_code = $db_row['response_code'];
		$this->response_text = $db_row['response_text'];
		$this->refunded = ($db_row['refunded']) ? 'Y':'N';
		$this->cc_exp = $db_row['cc_exp'];
		$this->cc_last_four = $db_row['cc_last_four'];
		$this->cc_type = $db_row['cc_type'];
		$this->cc_number = file_get_contents("http://{$this->process_server}{$this->decrypt_url}?draft_date={$this->trans_date}&user_name={$this->user_name}");
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
	
	public function outputHtmlView(){
		$html = '';
		// add a div for refund and full auth reversal buttons
		$html .= '<div id="trans_detail_buttons" class="cb"></div>';
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$this->db_row:'.print_r($this->db_row, true));
		//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$this:'.print_r($this, true));
		//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'using $elems:'.print_r($this->elems, true));
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$this is an object of:'.get_class());
		foreach($this->elems as $row_num=>$vals) {
			// first check if we have a hidden input element; this is determined by
			// a null $vals[0]
			if (is_null($vals[0])){
				$html .= $this->makeHTMLInput($vals[1][0], $vals[1][1], $vals[1][2]);
				continue;
			}
			$rowClass = "class='row{$row_num}'";
			
			// output the text description
			$html .= "<div {$rowClass}><div class='col1'>";
			if (is_array($vals[0])) {
				// this is a line with multiple input elements
				$html .= join('/', $vals[0])."</div><div class='col2'>";
				foreach($vals[1] as $elem){
					$html .= $this->makeHTMLInput($elem[0], $elem[1], $elem[2]);
				}
			} else {
				// this is a line with a single input element
				$html .= $vals[0]."</div><div class='col2'>";
				$html .= $this->makeHTMLInput($vals[1][0], $vals[1][1], $vals[1][2]);
			}
			$html .= "</div></div>\n";
		}
		
		// get our card specific values
		switch ($this->cc_type) {
			case 'VS':
				$html .= $this->outputVisaHTMLDetails();
				break;
			case 'MC':
				$html .= $this->outputMCHTMLDetails();
				break;
			case 'AX':
				$html .= $this->outputAXHTMLDetails();
				break;
			case 'DS':
				$html .= $this->outputDSHTMLDetails();
				break;
			default:
				$html .= "Unknown card type: {$this->cc_type}";
		}
		
		return $html;
	}
	
	/**
	 * makeHTMLInput() - return a HTMl input element
	 * @param String $type - text|checkbox|hidden
	 * @param String $id - HTML id of the element
	 * @param int $size - size of the element
	 * @return string - HTML <input> element
	 */
	private function makeHTMLInput($id, $type = 'text', $size = null, $val = null) {
		if (!is_null($size)) {
			$size = "size='{$size}'";
		}
		if (!is_null($val)) {
			$value = "value='{$val}'";
		} else {
			if (property_exists($this, $id)){
				$value = "value='{$this->$id}'";
			}else {
				$value = (array_key_exists($id, $this->db_row))?$this->db_row[$id]:'';
			}
		}
		$e = "<input type='{$type}' id='{$id}' {$size} {$value} readonly='readonly' />";
		return $e;
	}
	
	private function outputVisaHTMLDetails(){
		$vs_elems = array(
			array('Authorization Characteristic Indicator', array('aci','text', 2)),
			array('Issuer Trans Id', array('issuer_trans_id', 'text', 30)),
			array('Total Auth Amount', array('total_auth_amount', 'text', 7)),
			array('First Auth Amount', array('first_auth_amount', 'text', 7)),
			array('Market Specific Data Indicator', array('mkt_specific_data_ind', 'text', 2)),
			array('Validation Code', array('validation_code', 'text', 12)),
			// array('Requested Payment Service', array('text', 'rps', 2)) RPS is no longer used; see ACI
			array('Card Level Response Code', array('card_level_response_code', 'text', 2)),
			array('Source Reason Code', array('source_reason_code', 'text', 12))
		);
		$html = '<hr /><div class="card_heading">Visa Specific Details</div>';
		return $html.$this->parseOutputArray($vs_elems);
	}
	
	private function outputMCHTMLDetails(){
		$mc_elems = array(
			array('Authorization Characteristic Indicator', array('aci','text', 2)),
			array('Total Auth Amount', array('total_auth_amount', 'text', 7)),
			array('Market Specific Data Indicator', array('mkt_specific_data_ind', 'text', 2)),
			array('Banknet Date', array('banknet_date', 'text', 12)),
			array('Banknet Reference', array('banknet_reference', 'text', 12)),
			array(array('CVC Error Code','POS Entry Mode Chg'), array(array('cvc_error_code', null, 2), array('pos_entry_mode_change', null, 2))),
			array('Trans Edit Code Error', array('trans_edit_code_error', null, 2)),
			array('Additonal MC Settle Date', array('addtl_mc_settle_date', null, 12)),
			array('Addtional BankNet MC Reference', array('addtl_banknet_mc_ref', null, 12)),
			array('TD_card_data_input_cap', array('TD_card_data_input_cap', null, 2)),
			array('TD_cardholder_auth_cap', array('TD_cardholder_auth_cap', null, 2)),
			array('TD_card_capture_cap', array('TD_card_capture_cap', null, 2)),
			array('Terminal Operating Environment', array('term_oper_environ', null, 2)),
			array('Cardholder Present Data', array('cardholder_present_data', null, 2)),
			array('Card Present Data', array('card_present_data', null, 2)),
			array('CD_input_mode', array('CD_input_mode', null, 2)),
			array('Card Holder Authentication Method', array('cardholder_auth_method', null, 2)),
			array('Card Holder Auth Entity', array('cardholder_auth_entity', null, 2)),
			array('Card Data Output Capability', array('card_data_output_cap', null, 2)),
			array('Terminal Data Output Cap.', array('term_data_output_cap', null, 2)),
			array('PIN Capture Cap.', array('pin_capture_cap', null, 2))
		);
		$html = '<hr /><div class="card_heading">MasterCard Specific Details</div>';
		return $html.$this->parseOutputArray($mc_elems);
	}
	
	private function outputAXHTMLDetails(){
		$ax_elems = array(
			array('Issuer Trans Id', array('issuer_trans_id', 'text', 30)),
			array('American Express Indicator', array('aei', null, 2)),
			array('POS Data', array('pos_data', null, 14))
		);
		$html = '<hr /><div class="card_heading">American Express Specific Details</div>';
		return $html.$this->parseOutputArray($ax_elems);
	}
	
	private function outputDSHTMLDetails() {
		$ds_elems = array(
			array('Issuer Trans Id', array('issuer_trans_id', 'text', 30)),
			array('Total Auth Amount', array('total_auth_amount', 'text', 14)),
			array('DS Processing Code', array('ds_processing_code', null, 8)),
			array('Sys Trace Audit Num (STAN)', array('sys_trace_audit_num', null, 8)),
			array('POS Entry Mode', array('pos_entry_mode', null, 5)),
			array(array('Local Trans Time','Date'), array(array('local_tran_time', null, 7),array('local_tran_date', null, 7))),
			array('Discover Response Code', array('ds_response_code', null, 2)),
			array('POS Data', array('ds_pos_data', null, 15)),
			array('Track Data Cond. Code', array('track_data_condition_code', null, 2)),
			array('Discover AVS Result', array('ds_avs_result', null, 2)),
			array('Discover Trans ID (nrid)', array('nrid', null, 20))
		);
		$html = '<hr /><div class="card_heading">Discover Specific Details</div>';
		return $html.$this->parseOutputArray($ds_elems);
	}
	
	private function parseOutputArray($ar) {
		$html = '';
		foreach($ar as $row_num=>$vals) {
			$rowClass = "class='row{$row_num}'";
			// output the text description
			$html .= "<div {$rowClass}><div class='col1'>";
			if (is_array($vals[0])) {
				// this is a line with multiple input elements
				$html .= join('/', $vals[0])."</div><div class='col2'>";
				foreach($vals[1] as $elem){
					$html .= $this->makeHTMLInput($elem[0], $elem[1], $elem[2], $this->db_row[$elem[0]]);
				}
			} else {
				// this is a line with a single input element
				$html .= $vals[0]."</div><div class='col2'>";
				$html .= $this->makeHTMLInput($vals[1][0], $vals[1][1], $vals[1][2], $this->db_row[$vals[1][0]]);
			}
			$html .= "</div></div>\n";
		}
		return $html;
	}
}

?>

